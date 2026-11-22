## 🔒 락 · 게이트 (코드 대조 2026-11-22 — 독립 대조 1회 반영)

> 🚫 **판정식을 옮겨 적지 않는다.** 임계는 `LockThresholdResolver`(바이어별) 또는 `Setting::lockThreshold`(B/L·매입 지급), 켜짐은 `Setting::lockEnabled`, 매입 등록 판정은 `PurchaseRegistrationGate::decide`, 수출 진입은 `Vehicle::guardStageOrderForExport`, B/L 은 `Vehicle::blRatioBlocker` 만 쓴다.
> 미수·미수율의 정의는 돈 ③, 정산 잠금·회계 재조정은 돈 ④ 를 본다.

### 1. 한눈에 — 돈 흐름 락 4개
| 락(설정 키) | 무엇을 보나 | 어느 저장에서 막나 | 기본 | 여는 길 |
|---|---|---|---|---|
| 매입 등록 `purchase_registration` | **바이어** 미수율(선적 전 차만) 또는 무담보 잔액 | 차량 패널 저장 — 신규 등록 · 바이어 교체 | ON · 50% | 관리 이상이 모달에서 사유(5자) → **그 저장 1회** |
| 매입 지급 `purchase_payment` | **그 차** 판매 미수율 | 재무처리 「새 매입 지급」 — 그 차의 2번째 줄부터 | **OFF** · 50% | 관리 이상이 모달에서 사유 → 그 줄 1회 |
| 수출 진입 `shipping_entry` | **그 차** 판매 미수율 | 차량 패널 저장 · 반입지 일괄 기입 · 선적요청 묶음 「착수」(묶음 합산) | ON · 50% | 진입 우회 승인(영구) · 선적대기 허용 항로 |
| B/L 발행 `bl_issue` | **그 차** 판매 미수율 | B/L 파일을 **처음** 붙이는 차량 저장(모델 훅) · B/L 일괄 업로드 · 선적요청 묶음 B/L 번호 기입 | ON · 100% | `bl` 우회 승인(영구) |

- 「50%·100%」는 **코드 기본값**(`Setting::LOCK_PAID_DEFAULTS`)이다. 실제 값은 기능설정 「락 관제」에 회사별로 저장된 것 — 운영 수치로 읽지 않는다(§6).
- 판정은 전부 `미수율 > (100 − 필요입금률) ÷ 100` 이면 차단(같으면 통과).

### 2. 매입 등록 락 — 바이어 게이지
```
게이지 대상 = 그 바이어의 차 중  거래완료가 아니고  출고일(warehouse_out_date)이 빈 차
미수율      = Σ 원화 미수 캐시 ÷ Σ(총판매가 × 판매환율)          ← Buyer::computeReceivableGauge 의 ratio
락          = 미수율 > 임계                                        ← PurchaseRegistrationGate::decide
```
- ⚠️ **「선적 전」 = 출고일 하나**다. `departed()` 4신호(돈 ③)가 아니다. 선적일·B/L 발행일만 있는 차는 게이지에 남는다.
- 환율 없는 외화 차는 분자·분모 둘 다 0 으로 들어간다(대수에는 센다). 선적 전 차의 원화 총액 합이 0 이면(차가 없거나, 전부 판매가·환율이 없음) 게이지가 `null` = **통과**.
- **무담보 한도가 있는 바이어는 식이 바뀐다** — 미수율을 안 보고 `무담보 잔액 ≤ 0` 이면 락. 사용액 = 게이지 대상 차 가운데 「무담보로 지급」 체크된 차의 확정 계약금 중, 그 차가 아직 `isShippingEntryMet()` 가 아닌 것(한도까지). 대상 차가 0대여도 게이지가 만들어진다. 회사 토글 `unsecuredLimitEnabled`(기본 ON)가 꺼지면 전부 미수율 방식.
- 게이지의 `limit_krw`·`used_krw`·`available_krw`(보증금 여력)는 **표시용** — 락 판정에 안 쓰인다.

**어디서 막나**
- **ERP 차량 패널 `save()`** — 락 ON 이고, 바이어가 지정됐고, (신규 등록 **또는** 편집 중 바이어가 달라질 때 — 바이어 없던 차에 바이어를 넣는 것 포함)만. 같은 바이어 그대로인 편집은 미수가 넘어도 안 걸린다. 걸리면 저장을 멈추고 모달 → `canApproveUnpaidExport`(최고·시스템관리자·업무관리자·관리)가 사유를 쓰면 통과, 감사로그 `purchase_gate_override`(성공한 저장에만 남는다). ⚠️ 승인 표식은 **저장이 성공해야** 꺼진다(또는 모달 취소) — 승인 뒤 저장이 다른 사유(검증·진입 게이트·확인 모달)로 멈추면 같은 화면의 다음 저장(다른 차 포함)까지 살아 있다.
- **board 연동** — 🚨 **purchase-sync 수신은 이 락으로 거절하지 않는다**(판정 호출이 없다). board 는 `/internal/board/buyers` 가 내려주는 `purchase_locked`(같은 `PurchaseRegistrationGate`)를 보고 **자기 화면에서 미리** 막는다. 게이지 없는 바이어는 `purchase_locked=false`·`purchase_lock.basis.kind=null` 로 내려간다. (board 가 그 값으로 자기 화면에서 막는지는 board 레포 몫 — 여기서 확인하지 않았다.)
- 무담보 바이어 곁가지 2개(패널 `save()`): ①무담보 체크 **해제 거부** — 확정 계약금이 있고 `isShippingEntryMet()` 전이면 켜진 채 저장 ②체크 **강제** — 이번 저장에서 계약금이 늘고, 그 차가 진입 미달이고, 바이어 미수율이 임계를 넘으면 체크 없이는 저장 불가. 무담보 체크는 관리 이상(`canApprove`)만 바꾼다. 「계약금이 늘고」의 실제 식은 `폼 계약금 > DB 확정 계약금`이라 미확정 계약금만 있는 차는 매 저장 걸리고, 신규 차는 늘 진입 미달로 본다. 두 가드는 매입 등록 락 ON/OFF 를 안 본다(락을 꺼도 동작).

**board 발 매입 락 해제 승인**(`BoardPurchaseLockAllowance`) — 「이 바이어로 N대 더」
- 요청: board → `POST purchase-lock-requests`. 락이 아닌 바이어면 409 `not_locked`, 같은 바이어에 대기 건이 있으면 409 `already_pending`. 요청자 = 그 바이어의 활성 영업 또는 ERP 시스템관리자. 금액은 받지 않는다. 대기 건은 board 에서 거둘 수 있다.
- 승인: ERP 승인큐(`/erp/approvals`, `canApprove` = 관리 이상)에서 대수를 1~요청 대수로 정해 승인, 거부는 사유 5자. 요청을 누른 본인(계정 또는 이메일 일치)은 결정 못 한다. 알림 벨 「승인 대기」 구역에 바이어명·대수로 뜬다.
- 유효 = **승인 시각 + 7일**, 남은 대수 > 0. 승인 건마다 자기 7일이고 여러 건은 합산, 먼저 승인된 것부터 쓴다.
- 차감 = purchase-sync 가 **새 차량**을 바이어와 함께 만들 때 1대. 🔎 **락 여부를 안 본다**(락이 풀린 뒤 등록해도 줄어든다). 재전송(이미 있는 차량번호)·바이어 없는 차는 안 줄인다.
- 🔎 대수가 없어도 **등록은 막지 않는다** — 최근 7일 안에 승인이 있던 바이어면 감사로그 `board_purchase_lock_overshoot` 만 남는다. 막는 것은 board 화면이다.
- 🔎 ERP 차량 패널의 매입 등록 락은 이 대수를 **안 본다**(패널은 모달 승인만).

### 3. 일반재고 인원당 상한 (`GeneralStockCap::check`)
- 상한 = `Vehicle::GENERAL_STOCK_PRICE_CAP`(2억, 코드 상수 — 설정 없음). 세는 것 = 그 담당자의 `scopeGeneralStock`(재고 + 바이어 없음 + 판매가 없음) 매입가 합. 재고(`scopeInStock`)는 매입 완납 차만이라 **지급 전 차는 안 센다**.
- 막는 곳 = **purchase-sync 새 차량**이 바이어도 판매가도 없이 올 때만. `기존 합 + 이번 매입가 > 상한` 이면 차량을 만들지 않고 409 `general_stock_cap_exceeded`(정확히 2억은 통과). 재전송은 안 막는다.
- 담당자를 못 찾으면 적용 안 함(통과). ERP 화면 직접 등록·바이어 제거는 안 막는다. board 사전 조회(`GeneralStockCapController`)가 같은 함수를 쓴다.

### 4. 매입 지급 락 (재무처리 화면 한 곳)
- 위치 = `transfers/index` `createNewPbp()`. 조건 = 락 ON · 그 차에 매입 지급 줄이 **이미 1건 이상**(확정 여부 무관) · `unpaid_ratio > Setting::lockThreshold('purchase_payment')`. 첫 줄과 판매 전(미수율 null) 차는 통과.
- 바이어별 재정의 **없음**(전역 값만). 여는 길 = `canApproveUnpaidExport` 가 사유 입력 → 그 줄 1회, 감사로그 `purchase_payment_gate_override`.
- 🔎 **차량 패널 매입 탭에서 넣는 지급 줄에는 이 락이 없다.**

### 5. 수출 진입 게이트 (`Vehicle::guardStageOrderForExport`)
**모델 저장 훅이 아니다** — 호출하는 곳만 막힌다: ①차량 패널 `save()`(폼 값을 얹은 임시 차량으로 검사) ②선적일·ETA 일괄 기입이 **반입지를 바꾸는 차**(이미 반입지가 있는 미달 차에 선적일만 넣으면 통과 — 패널의 「있나」 기준과 다르다).
```
발동 = 선적일 · 수출신고서 파일 · 반입지 · B/L 파일 · DHL 신청  중 하나라도 「있는 상태」
① 말소 체크(is_deregistered) 없으면 차단                    ← 토글·우회 없음
② 반입지가 있는데 선적 컨사이니 없으면 차단                   ← 토글·우회 없음
③ 입금률 — 판매가 > 0 · 저장된 차 · 락 ON 일 때만:
     진입 우회 승인 있음 → 통과
     선적대기 허용 항로(RORO + 도착항 allow_shipping_wait) → 통과
     외화인데 판매환율 ≤ 0 → 차단
     unpaid_ratio > 임계(바이어 재정의 → 전역) → 차단
```
- ⚠️ **「이번에 바꿨나」가 아니라 「있나」**로 발동한다 — 이미 선적일이 있는 차는 판매 탭만 고쳐 저장해도 매번 다시 검사된다.
- 패널 검사는 **폼의 판매가·통화·환율** + DB 의 나머지 금액·확정 입금으로 미수율을 낸다. 신규 차(저장 전)·판매가 0 인 차는 ③을 통째로 건너뛴다. ⚠️ **바이어·선적 방식·도착항은 폼 값이 아니라 저장 전 DB 값**으로 본다 — 같은 저장에서 RORO + 허용 도착항을 반입지와 한 번에 넣으면 항로 예외가 안 서고, 바꾼 바이어의 재정의 임계도 다음 저장부터다.
- 환율 없는 외화 차: `unpaid_ratio` 자체는 환율과 무관해 null 이 아니다. 별도 검사가 막고, 우회 승인·선적대기 항로는 이 검사도 건너뛴다.
- 수출통관 체크·ETA·수출신고번호·통관/B/L 바이어 배정은 발동 신호가 아니다.
- 🔎 **안 타는 경로** — 서류 일괄 업로드(수출신고서·체크빌에는 게이트가 없다 — 다만 수출신고서가 붙은 뒤 그 차의 패널 저장은 매번 진입 검사를 받는다), 선적일·ETA 만 넣는 일괄 기입, board 선적요청 sync 등 API 저장, 콘솔.
- **선적요청 묶음 「착수」**(`shipping-requests` `changeStatus`)는 별도 게이트다 — 묶음 **합산** 미수율이 임계를 넘으면 착수 불가. 우회 승인 차·선적대기 항로 차는 집계에서 빼고, 바이어가 섞이면 가장 엄격한 임계를 쓴다. 판매가 0 인 차·원화 미수 캐시가 null 인 차도 집계에서 빠지고, 분모가 0 이면 통과한다. 합산을 통과해도 미달 차의 반입지 저장은 개별로 막힌다(착수는 차량 칸을 쓰지 않는다). ⚠️ 게이트는 「착수」 전환에만 있다 — 「완료처리」는 요청 상태에서 바로 눌리고 게이트가 없다. 권한 = `canAccessClearance`.
- 🚫 `isShippingEntryMet()` 은 **이 게이트가 아니다** — 원화 미수 캐시로 보는 별도 술어(무담보 해제·게이지용)이고, 판매가·환율이 없으면 false 다. 진입 우회·선적대기 항로도 안 본다.

### 6. B/L 100% 게이트 (`Vehicle::blRatioBlocker` — 모델 `saving` 훅)
- 발동 = 로그인한 저장에서 B/L 파일이 **빈 값 → 값**으로 바뀔 때. 이미 파일이 있던 차의 교체·삭제는 통과.
- 판정: 락 OFF → 통과 / 총판매가 ≤ 0(미수율 null) → 차단(판매가 필요) / `unpaid_ratio ≤ Setting::lockThreshold('bl_issue')` → 통과 / 아니면 `bl` 우회 승인이 있어야 통과.
- 진입 우회(`shipping`·`clearance`)로는 안 뚫린다. 바이어별 재정의 없음. 임계는 설정값이다(기본 100 — 「고정」이 아니다).
- 같은 판정을 쓰는 곳: B/L 일괄 업로드 미리보기·실행(`blUploadBlocker` = 반입지 선행 + 위 판정), 그리고 선적요청 묶음 **B/L 번호 기입**(`applyBlIssue`, 권한 `canApprove`)은 **별도 식**(`bundleBlockers`)이다 — 임계·`bl` 우회는 같고 한 대라도 미달이면 묶음 통째 차단이지만, **판매가 0 인 차는 건너뛴다**(`blRatioBlocker` 는 그런 차를 막는다).
- 반입지 없이 B/L 파일 · B/L 파일 없이 DHL 신청은 패널에서 따로 막는다(`guardAttachmentDeps`).
- 로그인 없는 저장(`auth()->check()` 가 false — 콘솔·시드)은 훅이 통과시킨다. 락 OFF 검사가 맨 앞이라 OFF 면 판매가 없는 차에도 B/L 이 붙는다.
- 🔎 **관리자 자동 통과는 없다** — 수출 진입·B/L·묶음 게이트 어디에도 시스템관리자·최고관리자 예외가 없다(여는 길은 우회 행뿐). 매입취소·내수·karaba 예외도 없다.

### 7. 기준값 — 「락 관제」와 바이어별 재정의
| 층 | 어디 | 누가 바꾸나 |
|---|---|---|
| 켜짐/꺼짐 | `lock_{락}_{회사세트}` → `Setting::lockEnabled` (기본 = 등록·진입·B/L ON, 지급 OFF) | 시스템관리자만 · 감사로그 `lock_toggle_changed` |
| 필요 입금률 % | `lock_threshold_{락}_{회사세트}` → `Setting::lockRequiredPaidPct` (0~100) — 4개 락 모두 입력칸이 있다 | 시스템관리자만 · 감사로그 `lock_threshold_changed` |
| 바이어별 재정의 | `buyers.lock_shipping_entry_pct` · `lock_purchase_registration_pct` → `LockThresholdResolver` | 시스템관리자만(바이어 편집) · 감사로그 |
| 무담보 한도 | `buyers.unsecured_limit_krw` | 업무관리자 이상(`canAccessAdmin`) · 감사로그 |

- 바이어 재정의·무담보 한도의 감사로그는 **편집 때만** 남는다(신규 생성 때는 없다).
- 우선순위 = 차량별 우회 승인(판정 자체를 건너뜀) → 바이어 재정의 → 전역 설정 → 코드 기본.
- 🚨 재정의는 **NULL 만 미설정**이다. `0` 은 「필요 입금 0% = 락 없음」.
- 바이어별이 되는 락은 등록·진입 둘뿐 — 다른 키로 `LockThresholdResolver` 를 부르면 예외. 지급·B/L 은 `Setting::lockThreshold`.

### 8. 우회 승인 3종 — 수명이 다르다
| 종류 | 기록 | 수명 | 누가 |
|---|---|---|---|
| 수출 진입·B/L 우회 | `unpaid_export_overrides` 행(stage = `clearance`·`shipping`·`bl`) + 감사로그 `unpaid_override_approved` | **영구** — 만료·소비 없음. 수정·삭제는 모델 이벤트가 막는다(query builder 삭제는 안 막힌다 — 표본 정리 콘솔 명령이 그렇게 지운다) | `canApproveUnpaidExport` 가 차량 패널에서 직접(사유 20자) |
| 매입 등록·매입 지급 게이트 승인 | 감사로그만 | 그 저장 1회 | 같은 권한, 모달 사유(등록 5자 · 지급은 빈 값만 거부) |
| board 매입 락 해제 | `approval_requests`(board_purchase_lock) | 승인 후 7일 · N대 | 승인큐 — 관리 이상, 본인 건 제외 |

- 🔎 `User::canForceStageJump()`(시스템관리자)는 정의만 있고 호출처가 없다 — 게이트를 건너뛰는 「강제 단계 이동」 경로는 없다.
- 진입 우회는 `clearance`·`shipping` 중 **하나만 있어도** 통관·선적 둘 다 통과(`hasEntryUnpaidOverride`). B/L 은 `bl` 만(`hasUnpaidOverride('bl')`). 미달 차의 B/L 발행은 두 건이 필요할 수 있다.
- 🔎 진입·B/L 우회에는 **승인 요청 흐름이 없다** — `ApprovalRequest::TYPE_UNPAID_EXPORT_OVERRIDE` 는 실행이 빈 동작이고 만드는 코드가 없다. 권한자가 자기 화면에서 바로 기록한다(본인 건 제외·팀 범위 검사 없음).

### 9. 정산 쪽 게이트 (상세 = 돈 ③·④·⑤)
- **정산 생성 블로커** `Vehicle::settlementBlockers()` — 7종을 **전부 모아** 돌려준다. 「예외 대상」으로 넘길 수 있는 것은 `unpaid`·`freight_unconfirmed` 둘뿐(`SettlementGateOverrideService::OVERRIDABLE`). 운임 확정 = 원화 차 · FOB · CFR+운임비>0. 예외 처리 = 재무 이상(`canConfirmFinance`) + 사유 10자, 정산 행에 `gate_override_*` 로 남는다. 회사 단위 ON/OFF 설정은 없다.
- **지급 보류(미수)** `Settlement::isPayoutHeldByUnpaid()` = 확정(confirmed) · 배치 미편입 · 예외 없음 · 차량 미수 > 0. 월정산 대상·개별 지급 승인에서 빠진다. 미수가 0 이 되거나 예외를 걸면 풀린다.
- 정산 지급·2차 마감 뒤의 회계 칸 잠금, 확정 입금 줄 잠금, 회계 재조정 토큰 → **돈 ④**.

### 10. 편집 잠금 (차량 패널)
- 동시 편집: 패널을 열면 캐시 키 `vehicle-edit-lock:{id}` 를 90초 잡고 하트비트로 갱신한다. 남이 잡고 있으면 읽기 전용이고 변경 액션(저장·우회 승인 등)은 423.
- 옛 폼 저장 거부: 저장 때 행을 잠그고 지문(`Vehicle::editFingerprint` = 수정 시각 + 판매 입금 줄 id + 매입 지급 줄 id)을 패널을 열 때 값과 대조 → 다르면 `StaleFormException`.

### 11. 🚫 하지 말 것
- 임계를 숫자(0.5·50%)로 코드·문구에 박지 않는다. 등록·진입에 `Setting::lockThreshold` 를 직접 쓰지 않는다(바이어 재정의가 조용히 무시된다).
- 수출 진입 판정을 `isShippingEntryMet()` 로 대신하지 않는다. 반대로 무담보 해제를 `unpaid_ratio` 로 다시 계산하지 않는다.
- 게이지의 「선적 전」을 `departed()` 로 「맞추지」 않는다 — 락 모수가 바뀐다.
- 새 일괄 도구·API 가 발동 신호 5칸을 채우면 `guardStageOrderForExport()` 를 직접 불러야 한다(모델 훅이 아니다).
- 재정의 값을 `?:`·`??` 로 거르지 않는다(0 이 사라진다). 컬럼을 줄여 읽은 Buyer 로 무담보 한도를 묻지 않는다(`effectiveUnsecuredLimit` 가 예외를 던진다).
- `unpaid_export_overrides` 를 고치거나 지우지 않는다. 우회에 만료·소비를 가정하지 않는다.
- board 대수 차감·「살아 있는 승인」 조건을 옮겨 적지 않는다(`BoardPurchaseLockAllowance::liveGrants`).

### 12. 가드 테스트 (파일)
매입 등록 `PurchaseReceivableGateTest` · `UnsecuredLimitGateTest` · `UnsecuredCheckRequiredTest` · `BoardPurchaseLockApiTest`(board `/buyers` 판정) / board 대수 `BoardPurchaseLockRequestTest` / 일반재고 `GeneralStockCapTest` / 매입 지급 `PurchasePaymentGateTest` / 진입 `LockToggleTest`(락 OFF 면 C5·G1 통과) · `PortShippingWaitGateTest` · `ExportGateUsesFormRateTest` · `ShippingBundleGateTest` · `ConsigneeGateTest` / B/L `G1BlLockTest` / 기준값 `LockToggleTest` · `LockThresholdConfigTest` · `BuyerLockThresholdTest` · `BuyerLockAdminOnlyTest` / 정산 `SettlementGateOverrideTest`(미수 지급 보류 포함) · `ClosedSettlementMoneyLockTest` / 편집 `VehicleEditLockTest` · `VehicleTabSyncTest`
