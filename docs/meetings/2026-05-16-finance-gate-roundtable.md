# 📅 회의록: 큐 19-F 자금 이체 재무 확정 게이트 (관리≠재무 분리)

- 일시: 2026-05-16
- 강도: 풀회의 (/회의 명령어 호출)
- 안건 유형: 마이그레이션 + 권한·role 모델 변경 (복합)
- 자동발동 여부: yes (/회의 슬래시)
- 사외이사: Codex (gpt-5.5) / Gemini — 둘 다 응답 성공
- 사전 컨텍스트: `C:/Users/User/.claude/projects/C--xampp-htdocs-car-erp/memory/project_19f_finance_gate.md`

---

## 0. 안건 요약

큐 19-A~E (자금 이체 시스템 — `inter_vehicle_transfers` + `final_payment` 페어) 완료 후 사용자가 도메인 결손 발견. 현재 구조는 "관리 승인 = 자동 ledger 기록" (`final_payment` 즉시 생성)이지만 SSANCAR 실무는 관리(박관리) ≠ 재무(김진영 정산 role) 분리. 박관리는 의사결정만, 김진영이 실물 자금 처리 + 시스템 마킹해야 회계 무결성 확보.

**상태 머신 변경**: 4상태 → 5상태
- 현재: pending → approved(=즉시 executed) → executed / voided
- 19-F: pending → approved(의사결정만) → **confirmed_by_finance**(NEW) → executed / voided

**사용자 사전 확정** (회의에서 뒤집을 안건 아님):
- 권한 모델: 관리 ≠ 재무 (분리)
- 처리 담당: 김진영(정산 role)이 실물 자금 처리 + 시스템 마킹
- 시점: 19-E 완료 후 → 큐 19-F (지금)

---

## 💬 부서별 발언 (Sonnet 4.6)

### 📋 PO
판정: 조건부 GO

발언: 이 안건은 SSANCAR 실무 도메인의 핵심 결손이다. 현재 `ApprovalRequest::executeInterVehicleTransfer()`가 관리 승인 즉시 `final_payment` 페어를 생성하는 구조는 박관리(관리 role)와 김진영(정산 role)의 업무 분리를 시스템이 반영하지 못하는 상태이며, 이는 정산 role이 "처리했다고 기록된 자금"을 실제로 처리한 적 없는 상태에서 채권관리(`/erp/receivables`) 잔액이 갱신되는 데이터 오염을 발생시킨다. PO 관점 조건: ① 재무 권한은 `settlement` 미들웨어 재사용 — `finance` role 신설은 사용자 관리 부담 증가, 당장 필요 없음; ② `/erp/transfers` 신규 페이지 대신 `/erp/receivables`에 "재무 처리 대기" 탭 추가가 정산 role 동선상 자연스러움 (사외이사 Codex와 충돌, 사용자 결정 필요); ③ `finance_note`(은행 거래 번호)는 **선택 필드**로 시작 — 필수화 시 흐름 차단 위험.

다음 작업 큐 영향: 큐 3 병렬 가능. 큐 10(H3·H5·H6 정산 무결성)은 19-F 완료 후 진행 권장 — `final_payment` 생성 시점 변경이 H5 미러링 로직에 직접 영향. **19-F → 큐 10 순서 유지**.

업무 영향 role: 정산(김진영 직접), 영업(대기 시각화), 관리(처리 여부 확인)
사용자 막힘 정도: 차단 (회계 무결성 결손)
우선순위 분류: 리스크 제거
근거 파일/라인: `app/Models/InterVehicleTransfer.php` L41~51, `app/Models/User.php` L92·98, `app/Http/Middleware/SettlementMiddleware.php`
운영 전 필수 여부: yes

---

### ⚙️ Engineer
판정: 조건부 GO

발언: 사전 검증 완료 — 4개 파일 모두 실재. `InterVehicleTransfer` 상태 상수 4종 (`STATUS_PENDING / STATUS_APPROVED / STATUS_EXECUTED / STATUS_VOIDED`). `InterVehicleTransferService::execute()`는 승인 즉시 final_payment 페어를 INSERT (`L128~154`). `ApprovalRequest::executeInterVehicleTransfer()` (`L133~138`)는 `service->execute($transfer, $approver)` 단일 호출 — 19-F에서는 `service->approve($transfer, $approver)`로 교체하면 변경 범위 격리. `canAccessSettlement()` (`User.php L92~98`)는 role∈{정산, 관리} — 재무 접근 가드 재사용 가능, 신규 `finance` role 불필요. `/erp/transfers` Volt 컴포넌트 현재 미존재 — 신규 생성 + `#[Layout('components.layouts.app')]` + `settlement` 미들웨어 필수.

**조건 (수용 전 결정)**:
1. 네이밍: `STATUS_APPROVED_AWAITING_FINANCE = 'approved_awaiting_finance'` 신설 권장. status 컬럼 `string(20)` (`migration L37`) → ALTER로 늘려야 함.
2. 재무 거부: 구현 단순도 기준 "거부 불가(단순 통과)" 권장.
3. TTL 만료: 보류 → 별건 분리.

**공수 추정**: 합계 약 9.5~10h
- 마이그레이션 (컬럼 3개): 0.5h
- Service 분리 (`execute()` → `approve()` + `confirmByFinance()`, void 동일): 2h
- `ApprovalRequest::executeInterVehicleTransfer()` 교체: 0.5h
- `/erp/transfers` Volt 컴포넌트: 3h
- 차량 편집 패널 5상태 분기 UI: 1.5h
- 테스트 22건 수정 + 신규: 2h

**롤백 SQL**:
```sql
-- 1단계: 신규 상태 row 정리
UPDATE inter_vehicle_transfers
SET status='approved'
WHERE status='approved_awaiting_finance';

-- 2단계: 컬럼 drop
ALTER TABLE inter_vehicle_transfers
  DROP COLUMN confirmed_by_user_id,
  DROP COLUMN confirmed_at,
  DROP COLUMN finance_note;
```

**기존 row backfill** (개발 환경 한정 — Python ERP 미실재):
```sql
UPDATE inter_vehicle_transfers
SET confirmed_by_user_id = approver_id, confirmed_at = executed_at
WHERE status = 'executed';
```

영향 파일:
- `app/Models/InterVehicleTransfer.php` — STATUS_APPROVED_AWAITING_FINANCE 상수, fillable 3개, `financeConfirmer()` 관계, `getStatusBadgeAttribute()` 분기
- `app/Services/InterVehicleTransferService.php` — `execute()` → `approve()` + `confirmByFinance()`, `void()` 동일 분리
- `app/Models/ApprovalRequest.php` L133~138 — 1줄 교체
- `database/migrations/2026_05_16_*_add_finance_gate_to_inter_vehicle_transfers.php` — 신규
- `resources/views/livewire/erp/transfers/index.blade.php` — 신규 Volt
- `resources/views/livewire/erp/vehicles/index.blade.php` — lastDecided 박스 + 잔금 row 분기
- `routes/web.php` — `/erp/transfers` + `settlement` 미들웨어
- `tests/Feature/InterVehicleTransferServiceTest.php` — 10건+ 영향
- `tests/Feature/InterVehicleTransferVoidTest.php` — 9건 전반

권한 가드 위치: route middleware (`settlement`) + component method (`auth()->user()->canAccessSettlement()`) 이중 배치
테스트 실행 가능: 가능 (Windows XAMPP PHP, 현재 198 passed)
운영 전 필수 여부: yes
캐시 rebuild: yes — `sale_unpaid_amount_krw_cache` (Eloquent `$transfer->update()` 사용 강제, `DB::table()` 금지 — SKILLS.md §2 규칙 준수)

---

### 🧪 QA & Domain Integrity
판정: 조건부 GO

발언: 사전 검증 완료. InterVehicleTransfer 테스트 5개 파일 실재 (총 42건). 핵심 위험 2곳. ① `execute()`가 지금은 `approve → final_payment 즉시 생성 → executed` 단일 경로인데 19-F는 `approve()`(final_payment 없음) + `confirmByFinance()`(final_payment 생성)로 쪼갬. `test_execute_creates_paired_final_payments_and_updates_caches`(ServiceTest L157)와 `test_approval_request_execute_triggers_transfer`(L260)이 즉시 실패. ② `sale_unpaid_amount_krw_cache` 갱신 타이밍이 `FinalPayment::created` 훅(서비스 L114)에 묶여 있어 approved_awaiting_finance 단계에서는 캐시 미갱신 — 의도된 변경이지만 대시보드 action filter(`sale_unpaid`/`clearance_needed`) SQL where 정합성 재검증 필수.

**도메인 공식 영향**:
- `sale_unpaid_amount_krw_cache` — `FinalPayment::created` 훅 연동. approved_awaiting_finance 동안 캐시 이체 전 값 유지 (사실이므로 정합)
- `unpaid_ratio` — approved_awaiting_finance 중 변동 없음. G1 50% B/L 잠금 영향 없음
- VAT 9% / 10단계 `progress_status_cache` — 무관

**회귀 시나리오** (수동 25~30분):
1. 영업 요청 → 관리 승인 → 잔금 row violet 박스 미노출 확인
2. 재무 confirmByFinance → final_payment 2건 + 양 차량 캐시 갱신
3. approve 시점 G1/G5 가드 + confirmByFinance 시점 H4 재검증
4. approve와 confirmByFinance 사이 paid Settlement 발생 시 DomainException 확인
5. void 흐름 동일 5단계 적용 확인

**Unit Test 신규 필수**:
- `test_approve_does_not_create_final_payments()`
- `test_confirm_by_finance_creates_paired_final_payments_and_updates_caches()`
- `test_h4_guard_fires_on_confirm_when_settlement_paid_between_approve_and_confirm()`
- `test_finance_role_required_for_confirm()`

**깨질 가능성이 높은 기존 테스트**:
- `tests/Feature/InterVehicleTransferServiceTest.php` L157, L260
- `tests/Feature/InterVehicleTransferVoidTest.php` L140 (`test_approval_request_execute_triggers_void`)
- `tests/Feature/InterVehicleTransferApprovalPageTest.php` — 2건 전체

근거 파일/라인:
- `app/Services/InterVehicleTransferService.php` L114 (FinalPayment::created 훅), L118~154
- `app/Models/Vehicle.php` L218 (`sale_unpaid_amount_krw_cache` 갱신), L386 (C5 캐시 참조)

운영 전 필수 여부: yes

---

### 🔒 Security & Compliance
판정: 조건부 GO

발언: 코드 실재 검증 완료. `ApproveMiddleware`(canApprove = super/admin/관리)와 `SettlementMiddleware`(canAccessSettlement = super/admin/정산/관리)가 각각 별도 파일로 실재. **SoD 충돌 경로 확인**: `User::canAccessSettlement()`의 허용 조건이 `['정산', '관리']`이고 `canApprove()`도 `'관리'` 포함 → **관리 role 사용자는 현재 코드 그대로라면 /erp/approvals(승인)과 /erp/settlements(정산·재무 확정) 양쪽 모두 접근 가능**. 즉 박관리가 승인자면서 동시에 재무 처리자가 될 수 있는 경로가 열려 있다. 19-F에서 신규 `/erp/transfers` 라우트는 `settlement` 미들웨어로 막되, Volt 컴포넌트에서 `role === '관리'` 명시적 차단 + admin/super self-confirm 차단까지 이중 가드 필요.

**재무 권한 판정**: `settlement` 재사용이 옳다. 신규 `finance` role 신설은 과도. **단 `canAccessSettlement()`에 관리 role 포함이 현재 버그 씨앗** — `/erp/transfers`에서 관리 role 추가 차단 필수.

**감사 추적**: `AuditLog::withApprovalRequest()` 패턴 실재. `confirmByFinance()` 시 `approval_request_id` 연결 의무. `finance_note`는 별도 컬럼만, audit_logs new_value에 노출 금지.

근거 파일/라인:
- `app/Models/User.php` L92~99 (`canAccessSettlement()` — 관리 포함)
- `app/Models/User.php` L117~120 (`canApprove()` — 관리 포함) → **SoD 취약점**
- `app/Http/Middleware/ApproveMiddleware.php` L19 / `SettlementMiddleware.php` L13
- `routes/web.php` L35~42
- `app/Models/AuditLog.php` L21~23 (`MASKED_COLUMNS`), L44~55 (`withApprovalRequest`)

개인정보·API키 영향: 없음 (`finance_note`는 개인정보 아님)
감사로그 영향: **audit_logs 직접 영향** — `confirmByFinance()` 내 `AuditLog::withApprovalRequest($approvalRequestId, ...)` 래퍼 의무
운영 전 필수 여부: yes

**조건부 GO 조건 (3개 모두 충족 시 GO)**:
- (a) 관리 role의 `/erp/transfers` 이중 차단 (미들웨어 + 컴포넌트 mount)
- (b) `confirmByFinance()` 내 `AuditLog::withApprovalRequest` 래퍼 의무
- (c) 재무 거부 시 status 분기 명시 또는 "재무 거부 없음" 회의록 명시

---

### 🚀 Ops & Deploy
판정: 조건부 GO

발언: 신규 마이그레이션 컬럼 3개(`confirmed_by_user_id` FK / `confirmed_at` timestamp / `finance_note` text) 모두 nullable → 기존 row 무영향. **다운타임 0초 확정**. 핵심 리스크는 status enum 처리. 기존 마이그레이션 L37 `string(20)` 선언이라 PHP-side 상수 추가로 충분 — 단 `approved_awaiting_finance`(25자) 채택 시 ALTER로 늘려야 함 (Engineer 지적 반영). seeder에 InterVehicleTransfer 고정 fixture 없음 — backfill 개발 환경 한정 (Python ERP 미실재 확인, INDEX.md 2026-05-12). `progress_status_cache` rebuild는 자금 이체 컬럼이 진행상태 계산 무관이라 불필요. 단 final_payment 생성 시점 이동이 `sale_unpaid_amount_krw_cache` 갱신 훅 시점과 일치하는지 Engineer 교차 확인 (Engineer L114 훅 확인 완료).

다운타임: 0초 — 무중단
백업 시점:
- DB: `mysqldump` 1회 (개발 환경이지만 의무화 권장)
- 파일: git tag `v19-f-pre`
- 코드: `down()` 메서드에 `dropColumn(['confirmed_by_user_id', 'confirmed_at', 'finance_note'])` 명시 + status length 롤백

queue worker 영향: 무관 (동기 처리)
환경 의존성: 없음 (bcmath/zip/gd 무관)
테스트 실행 환경: Windows XAMPP PHP (현재 198 passed)
스토리지 영향: 없음
근거 파일/라인:
- `database/migrations/2026_05_15_000002_create_inter_vehicle_transfers_table.php` L37
- `app/Models/InterVehicleTransfer.php` L41~53
- `database/seeders/` — InterVehicleTransfer 시드 없음 확인

운영 전 필수 여부: yes — `migrate --pretend` 사전 실행 + `down()` 확인 필수

---

### 🔧 Specialist [E. 승인·권한 정책]
판정: 조건부 GO

발언: 코드 검증 결과, `ApprovalRequest::executeInterVehicleTransfer()` (L133~138)는 현재 `InterVehicleTransferService::execute()` 직접 호출 — 관리 승인 시점에 final_payment 페어 즉시 생성. 19-F 적용 후 이 라인을 `service->approve()`(의사결정만, final_payment 생성 X)로 교체. `ApprovalRequest::STATUS_APPROVED`의 UI 라벨이 현재 "승인"(L83) — "의사결정 통과 — 재무 대기" 의미로 label 갱신 필요.

**권한 설계 — `settlement` 재사용 권장**: `User::canAccessSettlement()` (L93~98)는 이미 `role = '정산' || isAdmin()` 또는 `'관리'` 포함. 김진영(정산 role)이 실물 자금 처리 책임자임 도메인 확정. 신규 `finance` role 신설은 `users` enum + role 픽스처 198건 영향 — 과도. settlement 재사용이 옳다.

**3-actor SoD 충돌 — Security 지적 정정**: 회의 진행자 코드 직접 검증으로 `canAccessSettlement()` L92~98이 `['정산','관리']` 포함 확인. **Security 보고가 정확. 본 슬롯의 초기 "관리는 이미 미통과" 진술은 부정확 — 정정**. 관리 role 차단 + admin/super self-confirm 차단 이중 가드 필요.

**void 흐름 동일 적용**: `InterVehicleTransferService::void()` (L208~248)도 현재 단일 실행. `approveVoid` / `confirmVoidByFinance` 분리 필수.

**FinalPayment.allowTransferLinkedMutation boot guard**: `confirmByFinance()` 내 신규 INSERT는 guard와 무관 (UPDATE/DELETE만 가드). 충돌 없음.

근거 파일/라인:
- `app/Models/ApprovalRequest.php` L133~138, L79~96
- `app/Models/User.php` L93~119
- `app/Services/InterVehicleTransferService.php` L118~155, L208~248
- `app/Models/FinalPayment.php` L25~39

운영 전 필수 여부: yes

---

### 🔧 Specialist [F. 회계·정산 감사]
판정: 조건부 GO

발언: 19-F의 회계 무결성 본질은 "실물 자금 이동 시점 = 시스템 ledger 시점" 동기화. 현재 `InterVehicleTransferService::execute()` (L128~154)가 관리 승인 직후 `FinalPayment::create()` 페어를 즉시 생성 — 실물 자금 이동 전에 ledger가 기록되는 구조가 코드 레벨에서 확인됨. 19-F가 이 시점 분리를 해결하는 것이 옳다.

**final_payment 생성 시점 변경의 retroactive 영향 — 안전**: 기존 executed transfer는 backfill로 `confirmed_by_user_id = approver_id`, `confirmed_at = executed_at` 채우면 회계 retroactive 없이 5상태 모델 전환 가능. `FinalPayment` 스키마 변경 최소화 권장.

**H4 재검증 — approve+confirm 양쪽 모두 필요**: `assertGuards()` (L257~292) paid Settlement 차단은 현재 `request()` 및 `execute()` 시점 호출. 19-F에서는 `approve()`와 `confirmByFinance()` 두 단계 분리되면 양쪽 모두 호출 필수. approve 후 confirmByFinance 사이 paid Settlement 발생 가능 (드물지만 명시적 위험).

**`sale_unpaid_amount_krw_cache` 타이밍 — 문제없음**: `FinalPayment::saved` 훅(L29)이 CREATE 시점 트리거. 19-F에서 final_payment 생성이 `confirmByFinance()` 시점으로 미뤄지므로 approved 상태에서는 캐시 미갱신 — **올바른 동작** (실물 자금 이동 전 미수 게이지 변경 X).

**TTL 자동 만료 — 별건 분리 권장**: 1인 개발 + dev 환경에서 cron job 도입 과도. `/erp/transfers` 목록에 "승인 후 경과일" 컬럼 표시로 운영자 수동 확인 충분.

**finance_note 필수 여부 — 선택 권장**: nullable + UI 안내 텍스트로 선택 입력 유도. 입력 시 audit_logs에 포함.

**void 흐름 회계 동일 적용**: `void()` (L208~248) 동일 "재무 확정 후 반대 부호 페어 생성" 패턴 분리. `confirmVoidByFinance()` 시점 H4 재검증 유지.

근거 파일/라인:
- `app/Services/InterVehicleTransferService.php` L118~155, L208~248, L257~292
- `app/Models/FinalPayment.php` L29, L33~42, L46~58

운영 전 필수 여부: yes

---

## 🧩 중간 회의 결과 (Opus 4.7 1차 취합)

### 🗳 부서별 판정 요약 — 6/6 모두 조건부 GO

- 📋 PO: settlement 재사용 / 채권관리 탭 권장 / finance_note 선택. 큐 10보다 19-F 선행.
- ⚙️ Engineer: 공수 9.5~10h. `STATUS_APPROVED_AWAITING_FINANCE` 신설. status 컬럼 ALTER 필요.
- 🧪 QA: 기존 22건 중 최소 5건 즉시 실패. 신규 4건 필수.
- 🔒 Security: `canAccessSettlement()`가 관리 role 포함 → 관리 role 이중 차단 + AuditLog 래퍼 의무.
- 🚀 Ops: 다운타임 0초. nullable 3컬럼. backfill 개발 환경 한정.
- 🔧 Specialist [E]: `executeInterVehicleTransfer()` L137 1줄 교체로 격리. settlement 재사용.
- 🔧 Specialist [F]: H4 재검증 양쪽 필수. TTL 별건. void 동일 4단계. retroactive 안전.

### 🤝 부서 간 합의 영역 (7건)
1. **재무 권한 = `settlement` 재사용** (신규 `finance` role 신설 NO)
2. **`finance_note` = 선택(nullable)**
3. **`/erp/transfers` 라우트 + `settlement` 미들웨어 + 컴포넌트 mount() 이중 가드**
4. **H4 paid Settlement 재검증을 approve 시점 + confirmByFinance 시점 양쪽 모두 실행**
5. **void 흐름도 4단계 동일 적용**
6. **TTL 자동 만료는 별건 분리** (G5 백로그)
7. **다운타임 0초 무중단 마이그레이션** (모든 신규 컬럼 nullable)

### ⚔️ 부서 간 충돌 영역 (4건) — 사외이사 의견 분기
| 충돌 | Codex | Gemini | 내부 다수 |
|---|---|---|---|
| UI 위치 | 분리 화면 | 채권관리 탭 | PO 탭 / Engineer 신규 (50:50) |
| 재무 거부 | void 유지(거부 불가) | finance_rejected 가능 | Engineer 불가 / Security·Specialist F 가능 |
| status 네이밍 | `approved_awaiting_finance` (ALTER) | `awaiting_finance` (짧음) | Engineer `APPROVED_AWAITING_FINANCE` |
| self-confirm | 금지 | 허용 + audit | Specialist E 허용 / Security 차단 |

### 📊 안건의 본질
- **문제**: 현재 자금 이체는 "관리 승인 = 자동 ledger" 단일 단계. SSANCAR 실무는 관리(의사결정) ≠ 재무(실물 처리)로 분리. 시스템이 실물 자금 이동 전에 final_payment를 기록 → 회계 무결성 결손.
- **해결**: status 4 → 5단계 확장. `execute()`를 `approve()` + `confirmByFinance()` 2개 분리. final_payment 생성은 후자 시점. settlement role 재사용.
- **결정된 불확실성**: UI 위치, 재무 거부 권한, status 네이밍, self-confirm — 사용자 결정 완료 (아래 §사용자 결정).

---

## 🌐 사외이사 의견 (Codex / Gemini)

### [Codex] (gpt-5.5)
결론: **조건부 GO**. 핵심은 "출고/정산 승인"과 "재무 확정"을 분리하는 SoD.

1. 1~3번 선택: **3번 전부 필수**. `settlement role`, `finance_note`, `paid Settlement의 approve+confirm`은 같은 흐름.

2. 4개 쟁점:
- UI: **분리 화면** 추천. 정산 담당은 승인, 재무는 확정.
- 반려: 1일 범위면 **void 유지**, `finance_rejected`는 후순위.
- status: **`approved_awaiting_finance`** 추천. 길이 ALTER 필요.
- self-confirm: **금지**. admin 예외도 audit 없으면 위험.

3. ERP 관행: SAP/Oracle은 결제 승인/재무 확정/SoD를 역할로 분리. Odoo도 결제와 회계 반영/조정이 분리. SSANCAR 방향은 정석에 가까움.

4. 1일 가능 여부: **가능하나 9.5~10h 빡빡**. 새 반려 상태, 복잡 UI 재구성, 예외 권한까지 넣으면 NO-GO.

5. NO-GO 트리거:
   - (a) `status` 길이 ALTER 불가
   - (b) 정산자와 재무확정자가 동일해도 되는 요구
   - (c) `final_payment` ledger 반영 시점이 재무확정 전이어야 한다는 요구

### [Gemini]
사외이사 시각에서 실무 리스크와 충돌 안건에 대해 제언.

**[간과된 리스크]**
1. **데이터 스냅샷 부재**: 승인(Step 4)과 재무 확정(Step 5) 사이의 시차 동안 차량 가격이나 채권 상태가 변동될 수 있음. 확정 시점에 승인 당시의 정산 금액을 재검증하는 '데이터 정합성 잠금' 필요. *(→ 내부 QA + Specialist F가 이미 H4 양쪽 재검증으로 지적. 보강됨.)*
2. **원자성(Atomicity) 장애**: `final_payment` 생성과 이체 확정은 하나의 트랜잭션으로 묶여야 함. 부분 성공 시 정산서가 꼬이는 '고스트 데이터' 발생 위험 방어 필요. *(→ 신규 지적. confirmByFinance() 내부 DB::transaction() 의무화 회의록 반영.)*

**[4대 충돌 외부 판정]**
1. **UI**: 채권관리 탭. 재무 역할자의 업무 집중도와 자금 흐름 가시성을 위해 통합 관리가 효율적.
2. **재무 거부**: 가능(finance_rejected). 실물 자금 부족이나 계좌 오류 등 재무 단계의 반려 사유는 반드시 존재.
3. **네이밍**: awaiting_finance. 스키마 변경 비용 최소화, 상태 머신 간결성, 1인 개발 환경 적합.
4. **self-confirm**: 허용하되 감사 로그 강제. 소규모 조직 유연성, '교차 확인 로그' 사후 통제 방식.

본 개편은 SAP 등 글로벌 ERP의 **Treasury(자금관리) 통제 패턴**과 일치하며, 관리와 집행을 분리하는 올바른 방향.

### 사외이사 비교
두 사외이사가 **4 충돌 모두 정반대 답** — 균형 잡힌 외부 시각. Codex는 "정석 SoD + 회계 안전 보수", Gemini는 "실무 유연성 + 1인 개발 비용 최소화". 결정은 사용자.

---

## 🎯 사용자 결정 (2026-05-16, 4 안건 모두 Recommended 채택)

| 충돌 | 결정 | 근거 |
|---|---|---|
| UI 위치 | **/erp/transfers 신규 페이지** | Codex 권장. 라우트 격리로 권한·감사 흐름 분리 명확. 자금 이체 도메인을 단일 페이지로 응집 |
| 재무 거부 | **거부 불가 (단순 통과, void로 처리)** | Codex + Engineer 권장. 9.5~10h 공수 압박 회피. 거부 케이스는 void 흐름 재사용 |
| status 네이밍 | **`approved_awaiting_finance` (25자, string(20) → string(30) ALTER)** | Codex + Engineer 권장. 상태 의미 자명. ALTER 1줄로 처리. 롤백·diff 최소화 |
| self-confirm | **동일 user_id 차단** | Codex + Security 권장. SoD 정석. 1인 개발 테스트는 별도 계정 운용 |

**부가 결정 (Gemini 신규 지적 수용)**:
- `confirmByFinance()` 내부 `DB::transaction()` 명시 의무 (final_payment 2건 생성 + status update + audit_logs 1건 모두 원자성 보장)

---

## 🚨 NO-GO 상세

NO-GO 발동 없음. Security 우려는 조건부 GO로 격하 — (a)(b)(c) 충족 완료.

- **(a) 차단 사유**: `canAccessSettlement()`가 관리 role 포함 → 박관리가 자기 승인을 직접 재무 확정 가능한 SoD 결손 경로 실재
- **(b) 수용 가능한 최소 조건**:
  - `/erp/transfers` 컴포넌트 mount()에서 `$user->role === '관리' && !$user->isAdmin()` 차단 + admin/super self-confirm 차단 (동일 user_id 차단 결정 반영)
  - `confirmByFinance()` 내 `AuditLog::withApprovalRequest` 래퍼 의무
  - 재무 거부 불가 결정 회의록 명시 (이 회의록 §사용자 결정 항목)
- **(c) 대안**: `canAccessSettlement()` 자체를 `['정산']`으로 좁히는 안 (메서드 의미 충돌 risk로 미채택 — 컴포넌트 차단이 더 안전)

→ 3가지 모두 충족 → Security 조건부 GO 격상.

---

## 🏁 최종 권고 (Opus 4.7 최종 취합)

**판정**: **조건부 GO**

**근거**: 6부서 + 사외이사 2명 모두 조건부 GO. 사용자 사전 확정(관리≠재무 분리)이 SAP/Oracle/Odoo Treasury 통제 패턴 정석과 일치. SoD 결손 + 회계 무결성 결손 직결 안건이라 운영 전 필수 진행. 사용자 결정 4건으로 충돌 영역 모두 해소.

**필수 선행 작업**:
1. status 컬럼 `string(20) → string(30)` ALTER 마이그레이션 사전 검증 (`migrate --pretend`)
2. 기존 22건 테스트 영향 매핑 (Engineer · QA 협업)
3. `/erp/transfers` Volt 컴포넌트 권한 가드 3중 (settlement 미들웨어 + mount 관리 role 차단 + mount self-confirm 차단)

**조건 (조건부 GO 7건)**:
1. `confirmByFinance()` 내부 `DB::transaction()` 의무 (Gemini 원자성 지적)
2. `confirmByFinance()` 내 `AuditLog::withApprovalRequest($approvalRequestId, ...)` 래퍼 의무
3. `/erp/transfers` 컴포넌트 mount()에서 관리 role + self-confirm 동시 차단
4. H4 paid Settlement 재검증을 `approve()` 시점 + `confirmByFinance()` 시점 양쪽 모두 실행
5. void 흐름도 동일 5단계 적용 (`approveVoid()` + `confirmVoidByFinance()`)
6. `sale_unpaid_amount_krw_cache` 갱신 타이밍 변경에 따른 KPI 대시보드 회귀 시나리오 통과 (수동 25~30분)
7. 모든 status 전환 시 Eloquent `$transfer->update()` 사용 (DB::table() 금지 — SKILLS.md §2)

**TTL 자동 만료**: 별건 분리 (G5 백로그). `/erp/transfers` 목록에 "승인 후 경과일" 컬럼 표시로 수동 모니터링.

---

## 🛠 car-erp 영향 분석 (Opus 4.7 산출)

### 취약점 (Vulnerabilities)
1. **SoD 결손 (현재 운영 상태)**: `canApprove()` ∩ `canAccessSettlement()`에 관리 role + admin 포함 → 동일 user가 승인 + 재무 확정 가능. 회계 통제 결손.
2. **회계 무결성 결손**: `execute()` 시점에 `FinalPayment::create()` 페어 생성 → 실물 자금 이동 전 ledger 기록.
3. **status 컬럼 길이 제약**: `string(20)`에 25자 상수 저장 불가 — 사전 검증 없이 운영 시 SQL 에러.
4. **승인-확정 시차 데이터 변동**: Gemini 지적. paid Settlement 발생 시 정합성 깨짐 가능 (조건 4번으로 보강).

### 보완사항 (Improvements)
1. ApprovalRequest `STATUS_APPROVED` UI 라벨을 "승인" → "의사결정 통과 — 재무 대기" 의미로 갱신
2. `/erp/transfers` 목록에 "승인 후 경과일" 컬럼 추가 (TTL 별건 대체)
3. 차량 편집 패널 lastDecided 박스 5상태 분기 (approved_awaiting_finance → 파랑 박스 신설)
4. 잔금 row violet 박스 표시 시점을 `confirmed_by_finance` 후로 이동 (final_payment 존재 시점과 일치)
5. `/erp/approvals` 결과 라벨: "승인" → "관리 승인 (재무 처리 대기)"

### 코드 수정 (Code Changes)
| 파일 | 변경 |
|---|---|
| `app/Models/InterVehicleTransfer.php` | `STATUS_APPROVED_AWAITING_FINANCE = 'approved_awaiting_finance'` 상수 + `STATUSES` 라벨 + `getStatusBadgeAttribute()` 분기 + fillable 3개 + `financeConfirmer(): BelongsTo` 관계 |
| `app/Services/InterVehicleTransferService.php` | `execute()` → `approve()`(L118~155 분리) + `confirmByFinance()`(신규, `DB::transaction()` 내부) / `void()` → `approveVoid()` + `confirmVoidByFinance()` (L208~248) / `assertGuards()` (L257~292)를 approve·confirm 양쪽 호출 |
| `app/Models/ApprovalRequest.php` | `executeInterVehicleTransfer()` L137 — `service->execute()` → `service->approve()` 교체. UI 라벨(L83) 갱신 |
| `app/Models/User.php` | `canAccessSettlement()` 의미 명확화 주석 추가 (코드 변경 X — 컴포넌트 차단이 SoD 가드 담당) |
| `resources/views/livewire/erp/vehicles/index.blade.php` | lastDecided 박스 5상태 분기 + 잔금 row violet 박스 표시 시점 confirmed_by_finance 후로 변경 |
| `resources/views/livewire/erp/approvals/index.blade.php` | 결과 라벨 "관리 승인 (재무 처리 대기)"로 갱신 |

### 신규 추가 (New Additions)
| 항목 | 위치 |
|---|---|
| 마이그레이션 | `database/migrations/2026_05_16_*_add_finance_gate_to_inter_vehicle_transfers.php` (컬럼 3개 + status length ALTER) |
| Volt 컴포넌트 | `resources/views/livewire/erp/transfers/index.blade.php` (재무 처리 대기 목록 + 확정 모달) |
| 라우트 | `/erp/transfers` (settlement 미들웨어) |
| Service 메서드 | `InterVehicleTransferService::approve()` / `confirmByFinance()` / `approveVoid()` / `confirmVoidByFinance()` |
| 테스트 4건 신규 | `test_approve_does_not_create_final_payments` / `test_confirm_by_finance_creates_paired_final_payments_and_updates_caches` / `test_h4_guard_fires_on_confirm_when_settlement_paid_between_approve_and_confirm` / `test_finance_role_required_for_confirm` |
| 테스트 수정 | `InterVehicleTransferServiceTest` L157·L260, `VoidTest` L140, `ApprovalPageTest` 2건 |

### 모순·NO-GO 처리 로그
- **Specialist E vs Security**: `canAccessSettlement()` 관리 role 포함 여부 — 회의 진행자 코드 직접 검증으로 Security 정확. Specialist E 진술 정정 (회의록 본문 §Specialist E에 정정 표기).
- **Engineer vs Ops**: status 컬럼 ALTER 필요성 — Engineer 정확 (`string(20)`에 25자 저장 불가). Ops "ALTER 불필요" 진술 정정.
- **NO-GO 자동 무효 처리 없음** — 모든 NO-GO 우려가 (a)(b)(c) 동반하여 조건부 GO로 적법 격하.

---

## 🛠 19-F 구현 큐 분할 (Opus 4.7 산출)

총 공수 9.5~10h. 4단계 분할 권장.

### 19-F-A — 마이그레이션 + 모델 (1.5h)
- 마이그레이션: 컬럼 3개 + status length ALTER + down() dropColumn + status 롤백 SQL
- `InterVehicleTransfer` 모델: STATUS_APPROVED_AWAITING_FINANCE 상수 + STATUSES + fillable + financeConfirmer 관계 + badge accessor
- 기존 executed transfer backfill 마이그레이션 (개발 환경 한정)

### 19-F-B — Service 분리 (2.5h)
- `InterVehicleTransferService`: `execute()` → `approve()` + `confirmByFinance()` 분리 (`DB::transaction()` + H4 재검증 + 동일 user_id 차단)
- `void()` → `approveVoid()` + `confirmVoidByFinance()` 동일 패턴
- `ApprovalRequest::executeInterVehicleTransfer()` L137 1줄 교체

### 19-F-C — UI 신규 페이지 (3h)
- `/erp/transfers` 라우트 + settlement 미들웨어
- Volt 컴포넌트 `transfers/index.blade.php`:
  - mount()에서 관리 role 차단 (`role === '관리' && !isAdmin()`)
  - 재무 처리 대기 목록 (status = approved_awaiting_finance)
  - "승인 후 경과일" 컬럼 표시
  - 행 클릭 → 모달 (finance_note 선택 입력 + [재무 처리 완료] 버튼)
  - 모달에서 self-confirm 차단 (`confirm_by_user_id = approver_id` 시 abort 403)
- 차량 편집 패널 lastDecided 박스 5상태 분기
- 잔금 row violet 박스 표시 시점 변경
- `/erp/approvals` 결과 라벨 갱신

### 19-F-D — 테스트 (2.5h)
- 기존 5건 테스트 수정 (ServiceTest L157·L260, VoidTest L140, ApprovalPageTest 2건)
- 신규 4건 (위 §신규 추가 참조)
- 수동 회귀 25~30분 (영업→관리→재무 전체 흐름 + paid Settlement 사이 발생 케이스 + void 흐름)
- 198 → 202+ passed 목표

### 별건 분리 (G5 백로그)
- TTL 자동 만료 cron job — 큐 13 배포 시점 검토

---

## 🔗 참조

- 사전 컨텍스트: `C:/Users/User/.claude/projects/C--xampp-htdocs-car-erp/memory/project_19f_finance_gate.md`
- 큐 19-A~E 원본 명세: `docs/meetings/2026-05-14-3way-workflow-policy.md` §13 자금 이체 모델
- 회의록 v5.1 §G4 — 별건 1 "재무팀 권한 분리" (19-F 본격 구현)
- `CLAUDE.md` 권한 시스템 (3단계 + role 5종)
- `SKILLS.md` §2 progress_status_cache 갱신 규칙 / §13 미수율 분모 단일 출처
- `decision_protocol.md` §6 마이그레이션 + 권한·role 행
- 관련 코드:
  - `app/Models/InterVehicleTransfer.php`
  - `app/Services/InterVehicleTransferService.php`
  - `app/Models/ApprovalRequest.php` L133~138
  - `app/Models/User.php` L92~120
  - `app/Models/FinalPayment.php` L25~58
  - `database/migrations/2026_05_15_000002_create_inter_vehicle_transfers_table.php` L37
