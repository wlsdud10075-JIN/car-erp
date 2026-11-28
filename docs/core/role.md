## 👥 역할 · 권한 · 알림톡 수신자 (코드 대조 2026-11-22, 독립 대조 1회 반영 — `app/Models/User.php` · `routes/web.php` · `app/Support/AlimtalkRecipients.php`)

> 🚫 **권한 조건을 화면에 옮겨 적지 않는다.** `User::can*()` 하나를 부른다. 차량 범위는 `canScopeVehicle()`, 담당 영업 묶음은 `getSubordinateSalesmanIds()`, 알림톡 수신자는 `AlimtalkRecipients` 만 쓴다.
> 정산·락의 규칙 자체는 돈 ①~⑤ 편에 있다. 여기는 **「누가」**만 다룬다.

### 1. 권한 단계
| `users.permission` | 화면 이름 | `approvalRank()` | 비고 |
|---|---|---|---|
| `super` | 시스템관리자 | 4 | 개발사. 사용자 목록에서 super 가 아닌 사람에게는 안 보인다 |
| `admin` | 최고관리자 | 3 | `approval_title`(부장·상무·대표 — `User::APPROVAL_TITLES`)은 **admin 에게만** 저장된다. null = 직급 없음 |
| `manager` | 업무관리자 | 2 | `role` 칸과 무관하게 넓은 권한. **`isAdmin()` 에는 안 들어간다** |
| `user` | 일반사용자 | role=관리면 1, 그 밖 0 | `role` 로 갈린다 |

- `role`(일반사용자만 의미) = `User::ROLES` `영업·수출통관·재무·관리`. 로그인 안 하는 역할 `검차`·`내수`(`NO_LOGIN_ROLES`)는 ROLES 밖이라 ERP 가 안 열린다. `내수` 선택지는 내수직원 월정산이 켜진 회사에만 나온다(`roleOptions()`).
- DB 기본값 `role='전체'` 도 ROLES 밖 → 기본값 그대로인 일반사용자는 `canAccessErp()` 가 false. 🚫 기본값을 유효 role 로 바꾸지 않는다.
- `approvalRank()` 사용처 = 월정산(제출 자격 · 결재선 없는 배치의 결재 차례 · 서명 기록) + 사용자 관리의 휴가 위임(위임 블록 rank ≥ 1, 남의 팀 대신 걸기·끄기 rank ≥ 2).
- 사용자 관리 저장은 일반사용자가 아닌 계정의 `role` 을 전부 `관리` 로 강제한다 — 그래서 화면으로 만든 업무관리자·최고관리자의 role 칸은 `관리` 다.
- **「관리 이상」 = rank ≥ 1 = `canApprove()`**(관리·업무관리자·최고관리자·시스템관리자). **「재무 이상」 = 여기에 재무를 더한 것 = `canConfirmFinance()`**.
- `verified` 미들웨어는 방어가 아니다 — `User` 가 `MustVerifyEmail` 을 구현하지 않는다.

### 2. 라우트 미들웨어 (`bootstrap/app.php` alias → `routes/web.php`)
| alias | `User` 메서드 | 일반사용자 중 통과 role | 실제로 물린 라우트 |
|---|---|---|---|
| `erp` | `canAccessErp` | ROLES 4개 | `/erp` 공용: 대시보드·차량·바이어·컨사이니·재고·예치가수금·카톡·알람·선적요청·서류·엑셀 |
| `sales` | `canAccessSales` | 영업·관리 | `/erp/salesmen/{id}/cashflow` |
| `approve` | `canApprove` | 관리 | `/erp/approvals` |
| `settlement` | `canAccessSettlement` | 재무·관리 | 정산관리·사내직원관리·월정산(사내·내수)·재무처리·정산 엑셀 |
| `receivable` | `canViewReceivables` | 재무·관리 | 채권관리·바이어 정산현황 |
| `admin-dashboard` | `canViewAdminDashboard` | 없음(**업무관리자도 불가**) | `/admin/dashboard` |
| `manage-users` | `canManageUsers` | 관리 | `/admin/users` |
| `operation-logs` | `canViewOperationLogs` | 관리 | 문서접근로그·감사로그·메일발송로그 |
| `super-admin` | `isSuperAdmin` | 없음 | 기능설정·알림톡 로그·알림톡 안내·관제센터 |
| `admin` | `canAccessAdmin` | 없음 | 차량 적재 빈 양식 다운로드 **하나뿐** |
| `clearance` | `canAccessClearance` | 수출통관·관리 | **물린 라우트 없음**(alias 만 등록) |

- 위 메서드는 전부 super·admin·manager 를 먼저 통과시킨다(`admin-dashboard`·`super-admin` 제외).
- **화면 `mount()` 가 다시 좁히는 곳**(라우트는 `erp`·`settlement`·`auth` 까지만): 정산 처리(`canSeeSettlementScreen` — 기능설정 지정자, 정산 엑셀도 같은 판정) · 포워딩사 · 항구 · 예치가수금 · 알람함 · 선적요청(관리 이상 + 수출통관 + 재무 — 재무는 비용 탭만) · 카톡 · 월정산(지정자 또는 자기 차례 결재자) · 관제센터(`isOpsOwner`) · 월정산 엑셀(컨트롤러). mount 가드를 빠뜨리면 라우트 통과자 전원에게 열린다.
- 재무처리 mount(`canConfirmFinanceTransfer`)는 `settlement` 미들웨어와 같은 집합이라 좁히지 않는다.
- 로그인 없이 열리는 것 = 월정산 승인 링크(`a/payout/{batch}`, 서명 URL + 결재자 id) — 승인·반려·인센티브 추가·엑셀. 판정은 `canDecide`·`canEditAdjustments`.
- 개발용 사용자 전환(`config('app.dev_user_switch')`, 기본 = 로컬만) — 원래 계정이 super 일 때만, 검차·내수는 대상에서 뺀다.
- 담당자 현금흐름 화면은 `sales` 를 지난 뒤 mount 가 **본인 Salesman · 업무관리자 이상 · 담당 영업이 맞는 관리**만 통과시킨다(jin 11-22 «넓혀줘» — 구: 최고·시스템관리자만). 이월 [청산] 버튼은 `canApprove`.

### 3. `User` 권한 메서드 — 본문이 같은 것끼리
| 통과하는 사람 | 메서드 (괄호 = 대표 사용처) |
|---|---|
| super 만 | `isSuperAdmin` · `canToggleFeatures`(호출처 0) · `canForceStageJump`(호출처 0) |
| super + 지정 이메일 | `isOpsOwner`(관제센터) |
| super·admin | `isAdmin` · `canViewAdminDashboard` · `canViewCapital`(대시보드 자금 손익) |
| + 업무관리자 | `canAccessAdmin` · `canAccessDeposits`(예치가수금) |
| + 관리 (**관리 이상**) | `canApprove`(승인큐·바이어 삭제·B/L 발급·이월 청산·발송 일괄 기입·담당자 승계) · `canApproveUnpaidExport` · `canManageUsers` · `canManagePorts` · `canManageForwarding` · `canViewOperationLogs` |
| 관리 이상 + 재무 (**재무 이상**) | `canConfirmFinance`=`canConfirmFinanceTransfer`(정산 확정·입금 확정·게이트 예외·과입금 전환·입금완료 알림톡) · `canManagePaymentBreakdown` · `canCorrectSecondaryCosts`(2차 대기 비용 수정 · **2차 마감** · 명세서 일괄 기입) · `canViewReceivables` · `canAccessSettlement` · `canEditPayroll` |
| 관리 이상 + 수출통관 | `canAccessClearance`(통관·선적 일괄, 선적요청) |
| 관리 이상 + 수출통관·재무 | `canSeePriceAlarms` · `canSeeAlarms` |
| 관리 이상 + 영업 | `canAccessSales` · `canEditVehicleFinancialFields`(없는 사람이 편집 저장하면 회계 25칸(`FINANCIAL_FIELD_MAP`)이 **원값으로 되돌아간다** — 재무는 비용 10칸만 예외, 수출통관은 전부. 주민번호 칸은 재무만 되돌린다) |
| 관리 이상 + 영업·재무 | `canEditVehicleCostFields`(비용 10칸) |
| 관리 이상 + 영업·수출통관 | `canHandleDeregistration`(말소 — 재무 제외) |
| 업무관리자 이상 + 재무 (**관리 제외**) | `canEnterCashBalance`(통장 마감잔액) |
| super · 관리 · 업무관리자 (**최고관리자 제외**) | `canSubmitPayoutBatch`(월정산·퇴사 정산 제출, 재전송은 super 도 제외) |
| 범위가 붙는 것 | `canScopeVehicle`(§4) · `canUnlockLedger`(업무관리자 이상 전체 / 관리는 본인 팀 차) · `canManageUserAccount`(§4) · `canSeeAlarm` |
| 기능설정에서 사람을 고르는 것 | `canSeeSettlementScreen`(「정산 처리」 화면 — 미저장이면 `canAccessSettlement` 전원, 저장 뒤엔 그중 체크한 사람) · `canSeeStaffPayout`(미저장이면 관리 이상) · `canSeeDomesticPayout`(회사 토글 + 지정자) · `canSeePayrollCategory`/`canSeePayrollOf`(급여 칸별) · `canUseKakaoAssistant`(토글 + 미저장이면 전원). super 는 지정 없이 통과하지만 **회사 토글이 꺼진 기능은 super 도 불가** |
| 챗봇 등급 5개(`canUse*Assistant`) | 재무 이상 / 관리 이상 / super·admin / super 로 나뉜다(`canUseAssistant` 는 설정에 따라 ERP 전원 또는 재무 이상) — 고칠 때 `User.php` 를 본다 |
| 위 표에 없는 것 | `canAccessErp`·`canViewAdminDashboard` 등 라우트용은 §2 · `isManager` = permission 이 manager |

**이름과 범위가 어긋나는 것**
- `isAdmin()` = super·admin 뿐. 업무관리자까지는 `canAccessAdmin()`. `isUser()` 는 호출처 0.
- `canSubmitPayoutBatch` 에 최고관리자가 없는 것 · 선적요청 B/L 번호 기입이 관리 이상(수출통관 제외)인 것은 **jin 확인(11-22) — 그대로**. B/L 기입은 나중에 넓힐 수 있게 `canApprove` 한 곳에서만 본다.
- `canApprove` · `canApproveUnpaidExport` · `canManageUsers` · `canManagePorts` · `canManageForwarding` 은 본문이 같다. 묶어 고칠 때 하나만 고치지 않는다.
- 🧮 **「정산 처리」 화면은 역할이 아니라 지정이다**(jin 2026-11-28 — 기능설정 공개 범위 표 「정산 처리」 칸, 설정 키 `settlement_viewers`). `canSeeSettlementScreen` 이 메뉴 · 화면 mount · 정산 엑셀 · 대시보드에서 넘어가는 링크 · 차량 관리 「데이터 내려받기」의 정산 항목(고르는 칸과 서버 허용 목록 둘 다)을 가른다. `canAccessSettlement`(라우트 — 사내직원관리 · 재무 처리 · 월정산)와 버튼 권한(`canConfirmFinance` · `canSubmitPayoutBatch` · `canCorrectSecondaryCosts` · `canUnlockLedger`)은 그대로다 — 다만 정산 확정 · 2차 마감 · 월정산 제출 · 회계 재조정 시작이 전부 이 화면 안에 있어, **지정에서 빠진 사람은 권한이 있어도 할 길이 없다.** 지정은 저장하는 순간 고정된다(나중에 만든 계정은 체크해 줘야 보인다). 이미 열어 둔 탭은 새로 고칠 때까지 그대로다. 관리가 전체를 보는 돈 화면(재무 처리 · 채권관리 · 바이어 정산현황 · 사내직원관리 · 승인 큐)에는 담당 범위가 걸려 있지 않다 — 일부러가 아니라 지금 상태(11-28 확인). 가드 `SettlementScreenVisibilityTest`.

### 4. 담당 범위
| 누구 | `canScopeVehicle()` | 목록(차량·바이어·컨사이니·재고·엑셀) |
|---|---|---|
| super·admin·업무관리자·수출통관·재무 | 전 차량 | 전체 |
| 영업 | `vehicle.salesman_id === 내 Salesman.id` | 내 Salesman 차만 |
| 관리 | 담당 영업의 차 | `whereIn(salesman_id, getSubordinateSalesmanIds())` |

- **관리의 담당 영업** = `manager_salesman` 피벗 ∪ 옛 `users.manager_user_id` 로 묶인 영업 계정의 Salesman.id. 여기에 **휴가 위임**(`UserDelegation`, 효력 있는 것만 — 사용자 관리 화면에서 걸고 끈다, 복귀일 필수)을 준 사람의 팀이 1단만 합쳐진다. 위임은 범위만 넘기고 권한 등급·결재 차례는 안 넘긴다. 담당 영업이 0명인 관리는 목록이 빈다.
- 🚪 **Salesman 이 연결 안 된 영업 계정은 아무것도 못 본다**(jin 11-22 «없다면 막는 게 맞음») — 목록·엑셀은 `User::ownSalesmanScopeId()`(연결 없으면 0)로 걸고, `canScopeVehicle`·편집 진입도 연결이 없으면 false 다. 구: 조건에 `&& $user->salesman` 이 붙어 목록이 전체로 열렸다. 사용자 관리 저장은 영업 계정의 Salesman 을 만들어 준다(`Salesman::mirrorTypeFor`).
- 단건 재인가는 `canScopeVehicle` 로 한다(편집·삭제·서류·일괄 도구·알림톡 스코프). 목록 조건을 새로 적지 않는다.
- **사용자 관리**: super 가 아니면 super 계정이 목록에 없고 열 수도 없다. 업무관리자 = super·admin 계정 불가, 그 밖은 전부(다른 업무관리자 포함). 관리 = 본인 팀 영업 계정만(`canManageUserAccount`, 위임받은 팀 포함) — 관리가 저장하면 `permission=user`·`role=영업` 으로 강제된다. 계정 **삭제**는 업무관리자 이상만. ⚠️ 담당 [관리] 선택지와 위임자·대타 후보 목록에는 super 은닉이 없다.

### 5. 승인 — 누가
승인큐 공통 — **본인이 올린 건은 승인·거부할 수 없다.**

| 동작 | 누가 | 판정 |
|---|---|---|
| 정산 확정(행·월 일괄) · 귀속월 수정 | 재무 이상 | `canConfirmFinance` |
| 정산 → 지급 직접 전환 | super·admin (미수가 남은 정산은 막힌다 — 게이트 예외 건은 통과, 11-22) | `Settlement::saving` 의 `isAdmin()` |
| 개별 지급 승인(승인큐) | 승인큐는 관리 이상이 열지만 **실행은 super·admin 만** | `ApprovalRequest::executeSettlementPay` |
| 월정산·퇴사 정산 제출 | 관리·업무관리자(+super) | `canSubmitPayoutBatch` |
| 내수직원 월정산 제출 | 지정자 | `canSeeDomesticPayout` |
| 월정산 결재 | 결재선 칸의 그 사람(직급 있는 admin) / 결재선 없으면 rank = 현재 단계인 전원 / super 는 차례와 무관하게 대행할 수 있다 — **그 단계만** 넘어간다(11-22) | `SettlementPayoutBatch::canDecide` |
| 결재 중 조정·지급보류·지급추가·최종 확정 | 제출 권한자 + super·admin | `canEditAdjustments` |
| 2차 마감 | 재무 이상(업무관리자 포함, 11-22) | `canCorrectSecondaryCosts` |
| 회계 재조정 토큰 | 업무관리자 이상 / 관리는 본인 팀 차 | `canUnlockLedger` |
| 정산 게이트 예외 · 과입금 회사이익 전환 | 재무 이상. 과입금 전환은 2차 마감 뒤엔 관리 이상 + 사유 | `canConfirmFinance` |
| 미입금 우회(진입·B/L) · 차량 패널의 매입 락 통과 | 관리 이상 | `canApproveUnpaidExport` |
| board 매입 락 해제 요청 | 관리 이상(대리로 요청을 누른 사람도 제외) | 승인큐(`canApprove`) |
| 선적요청 B/L 발급 | 관리 이상 | `canApprove` |
| 바이어 무담보 한도 | 업무관리자 이상 | `canAccessAdmin`(🚫 `canApprove` 로 넓히지 않는다) |
| 바이어별 락 기준선 · 락 관제 · 기능설정 전부 | super | `isSuperAdmin` · `super-admin` 라우트 |
| 바이어 삭제·정산 방식(karaba 는 정산 방식 칸 없음) | 관리 이상 | `canApprove` |

### 6. 로그인 뒤 화면
- `/dashboard` 는 **전원을 `/erp/dashboard` 로 보낸다**(분기 없음). 관리자 대시보드는 메뉴로 간다.
- 업무 대시보드 역할 뷰(`allowedRoleViews`): super·admin·업무관리자 = 영업·수출통관·재무·관리·업무관리자 5개(첫 화면 「업무관리자」) / 관리 = 4개 / 그 밖 = 본인 role 하나. 허용 밖 값을 넣으면 허용 목록의 첫 항목으로 되돌린다(관리자급은 「영업」 뷰). 영업은 「영업」 뷰에서 본인 Salesman 범위만 본다 — 현금흐름 화면으로 보내지 않는다.

### 7. 알림톡 수신자 (`AlimtalkRecipients`)
**구조** — 템플릿 코드마다 수신 방식이 정해져 있다. `AlimtalkTemplates::TEMPLATES[..]['recipient']` 는 화면 라벨(안내 화면·기능설정 목록)일 뿐 발송에 안 쓰인다.

| 방식 | 판정 | 누가 받나 |
|---|---|---|
| 역할 선택형 | `DEFAULT_ROLES` 에 키가 있음(`isBroadcast`) | 저장된 토큰(`selectedRoles`) — 역할 키 · `user:{id}` · 전화번호. 미저장이면 기본값, **빈 값으로 저장하면 0명** |
| ↳ 전체 집계 | `forBroadcast()` | 토큰이 가리키는 사람 전원이 같은 내용 |
| ↳ 차량 스코프 | `SCOPED_CODES` → `scopedFor()` | 토큰의 각 사람이 `canScopeVehicle` 로 **볼 수 있는 차만** |
| ↳ 단계별 확대 | `ESCALATION_DEFAULTS` → `scopedForTiered()` | 역할별 「며칠째부터」(회사별 저장, 경매 차는 따로) |
| 시각 규칙형 | `TIME_RULE_CODES` → `forTimeRules()` | 지금 시각·요일에 걸린 규칙 행의 수신자 합. 공휴일 = 일요일 취급. 0명이면 `admins()` |
| 자동 대상 | `TARGETED_LABELS` | 코드가 정한다(딜러 번호 · 결재 차례) |

- 역할 그룹(`groupQuery`): `admin`·`manager`·`super` 는 permission, `관리`·`영업`·`수출통관`·`재무` 는 **`permission='user'` 인 그 role**. role 칸이 관리인 최고관리자는 「관리」 그룹이 아니다. super 는 `super` 를 직접 골랐을 때만 받는다.
- **표에 없으면 발송 0** — `forBroadcast`·`scopedFor` 로 보내는 코드가 `DEFAULT_ROLES` 에 없으면 안내 화면에 체크박스가 안 뜨고 수신자가 빈 배열이다(예외·로그 없음).
- **후속본 공유** — `AlimtalkTemplates::SUCCESSOR_OF`(`*_v2` 4종 → 구 코드, `erp_purchase_paid_v3` → `_v2`). `selectedRoles()` 가 `baseCode()` 로 바꿔 읽으므로 수신 설정·기본값은 **구 코드 한 곳**이다. 후속본의 `DEFAULT_ROLES` 키는 안내 화면 분류(`isBroadcast`)에만 쓰인다. 대표 4종의 발송 코드는 `activeCode()`(후속본이 `canSend` 면 후속본). 입금완료는 화면이 따로 고른다 — v2 가 켜져 있으면 v2, 이체확인증이 붙었고 v3 가 `canSend` 면 v3.
- **전화번호** — `forBroadcast`·시각 규칙은 `users.phone` 만 본다(없으면 빠진다). 스코프형은 `users.phone` 이 비면 연결된 `salesmen.phone` 을 쓴다. 번호가 빈 채 서비스에 가면 `skipped: no_phone`.
- **계정 없는 영업담당자**(`salesmen.user_id` null, 번호 있음) — 스코프형에서 **「영업」 역할 전체가 켜져 있을 때만** 자기 담당 차를 받는다. 개별 지정(`user:{id}`)으로 바꾸면 빠진다.
- 직접 적은 전화번호 토큰은 스코프형에서 무시된다.
- `admins()` = `alimtalk_recipients_admin_{set}` 설정값이 있으면 그것, 없으면 `permission='admin'` 의 번호(이 설정을 쓰는 화면은 없다). 쓰는 곳은 시각 규칙의 0명 폴백과 대표 직행(`CEO_DIRECT_TYPES` = board 대표계약금, 시각 규칙 표를 안 본다)뿐 — 역할 선택형의 `admin` 그룹은 이 설정을 안 본다.
- 발송 조건(`AlimtalkConfig::canSend`) = 마스터 ON + 계정 설정 + 그 코드 토글 ON + tmplId. 하나라도 빠지면 `skipped` 로그만 남는다.
- **설정 화면** = `/admin/alimtalk-catalog`(시스템관리자 전용). 회사별 키: `alimtalk_roles_{code}_{set}` · `alimtalk_timerules_{code}_{set}` · `alimtalk_escalate_{code}_{group}_{set}` · `alimtalk_escalate_auction_{code}_{group}_{set}` · `alimtalk_holidays_{set}` · `alimtalk_ceo_direct_{type}_{set}`.
- `managers()` · `payoutApprovers()` 는 호출처 0. 쓰지 않는다.

**코드별 수신자** (기본값 — 회사가 안내 화면에서 바꿨으면 그 값)
| 코드 | 방식 | 기본 수신 |
|---|---|---|
| `erp_daily_summary` · `erp_receivable_status` · `erp_weekly_summary` · `erp_monthly_closing`(+`_v2`) · `erp_capital_weekly` · `erp_deposit_cash_overdue` | 전체 집계 | 최고관리자 |
| `erp_vehicle_new` · `erp_purchase_unpaid` · `erp_sale_unpaid` · `erp_settle_pending` · `erp_eta_balance_due` · `erp_shipping_due` · `erp_arrived_unpaid_weekly` | 차량 스코프 | 관리 + 업무관리자 |
| `erp_deposit_cash_due` | 차량 스코프 | 관리 + 업무관리자 + 영업 |
| `erp_pickup_reminder` | 차량 스코프 | 영업 |
| `erp_deregistration_reminder` | 단계별 확대 | 영업. 시작 일수 = `ESCALATION_DEFAULTS` · 경매 차 `AUCTION_ESCALATION_DEFAULTS` |
| `erp_purchase_paid_v2`·`_v3` | 딜러 번호(버튼) + 차량 스코프 | 딜러 + 그 차 담당 영업(안내 화면에서 다른 역할을 켜면 각자 범위로). 버튼 = 그 차를 볼 수 있는 사람(확정된 지급 줄에만 뜬다). 딜러 번호가 비면 사내 수신자에게만 나간다(v1 은 막힌다) |
| `erp_board_request` | 시각 규칙 | 평일 09:00~17:30 관리·업무관리자 / 그 밖·주말·공휴일 최고관리자 |
| `erp_payout_request` | 자동 | 지금 결재할 사람(`currentApprovers`) |
| `erp_payout_done` · `erp_payout_rejected` | 자동 + 선택(결재선 전원·재무·관리만) | 제출자(항상) + 결재선 전원 |
| `erp_deregistration_notice` · `erp_purchase_paid` · `erp_tax_invoice_request` | 자동(수동 발송) | 차량 패널의 딜러 번호 칸(**저장 전 화면 값 그대로**). 버튼 권한은 **나누지 않는다** — 세 종류 모두 그 차를 볼 수 있으면(`canScopeVehicle`) 보낸다(jin 11-22 «누구나로 변경») |

### 8. 회사별 차이
- 권한 코드(`User` · 미들웨어 · 사이드바 · `AlimtalkRecipients`)에 `Setting::isKaraba()` 분기는 없다. 화면 한 곳 — 바이어 「정산 방식」 칸이 karaba 에는 없다.
- 회사마다 달라지는 것은 **설정값**이다: 내수직원 월정산 토글 · 카톡자동화 토글과 공개 범위 · 월정산·급여 칸 볼 사람 · 알림톡 수신 설정(`{set}` 키) · `approval_title` 입력 여부(결재선 선택지).

### 9. 🚫 하지 말 것
- 「관리 이상」을 `isAdmin()` 으로 적지 않는다. 반대로 `isAdmin()` 자리(지급 직접 전환·자금 손익·관리자 대시보드)를 `canApprove()` 로 넓히지 않는다.
- 화면에 `role === '…'` 비교·목록 범위 조건을 새로 적지 않는다 — 업무관리자는 role 칸이 무엇이든 될 수 있다. 지금 남은 인라인 판정 = 차량 신규 등록(`isAdmin` 또는 role ∈ 영업·관리) · 개별 지급 승인 실행(`isAdmin`).
- 알림톡에서 역할 이름을 직접 비교하거나(`in_array('영업', selectedRoles())` — 개별 지정이면 false) `salesmen.phone` 을 직접 읽어 보내지 않는다. super 를 `DEFAULT_ROLES` 에 넣지 않는다.
