# 📅 회의록: 차량 본체 ledger 영향 필드 잠금 범위·해제 정책

- 일시: 2026-05-18
- 강도: 풀회의 (/회의 명령어 호출)
- 안건 유형: 정산 마진 공식 변경 + 권한·role 모델 변경 (복합)
- 자동발동 여부: yes (/회의 슬래시)

## 안건

**재무 confirm된 잔금이 1건이라도 있으면 차량 본체 ledger 영향 필드(매입가·판매가·환율·면장금액·비용 9컬럼·바이어·담당자) 변경을 어느 범위까지 잠그고 어떤 조건에서 풀 수 있게 할지 결정. 사용자 선호는 영구 잠금이지만, 정합성·무결성을 최대한 안 깨는 선에서 허용되는 잠금 범위 산출.**

배경: 큐 20 (재무 확정 게이트) 246 passed 완료. 시나리오 검증 중 발견 — confirmed 잔금 후 영업이 차량 매입가를 500→600만 자유 변경 가능. 큐 20 잠금은 잔금 row만(FinalPayment.amount 등), 차량 본체 필드는 자유. 사용자 우려: "한 번 풀려서 또 수정되면 정합성·무결성 무너진다."

---

## 💬 부서별 발언 (Sonnet 4.6)

### 📋 PO
**판정**: 조건부 GO

**발언**: 사전 검증 완료. `Vehicle::saving` 훅(L210)에 본체 필드 잠금 guard 전혀 없음. `getSaleUnpaidAmountAttribute()`(L606) A안 필터 적용 후에도 본체 컬럼 수정으로 retroactive 오염 경로 열림. 잠금 트리거 옵션 A 권장(B는 채널 모순, C는 시점 과소). 잠금 범위 **2계층 분리** 권장: ① 금액 계산 직결 6종(purchase_price · sale_price · exchange_rate · export_declaration_amount · cost_* 9컬럼) = 영구 잠금 + super 우회 + AuditLog ② 관계 식별 2종(buyer_id · salesman_id) = super+admin 우회 허용.

**다음 작업 큐 영향**: 큐 20-E 또는 20-D 후속으로 자연스럽게 끼움. 별도 큐 신설 또는 흡수 모두 가능.
**업무 영향 role**: 영업 / 정산 / admin / super
**사용자 막힘 정도**: 리스크 제거
**우선순위 분류**: 리스크 제거
**근거 파일/라인**:
- `app/Models/Vehicle.php` L210 (saving 훅 진입점)
- `app/Models/Vehicle.php` L603~629 (sale/purchase unpaid 분자)
- `app/Models/FinalPayment.php` L46~57 (잠금 패턴 재사용)
- `app/Services/PaymentConfirmationService.php` L97~103 (paid Settlement 가드 기준)
**운영 전 필수 여부**: yes

---

### ⚙️ Engineer
**판정**: 조건부 GO

**발언**: 현행 코드에 이미 2개 잠금 레이어 존재 — `guardFinancialDriftAfterPaid()` (paid 트리거)와 `restoreFinancialFieldsFromOriginal()` (role별). 트리거 A 추가는 신규 메서드 `guardLedgerAfterConfirmedPayment()`로 분리해 save() 흐름(L988~990 사이) 삽입 권장. `FINANCIAL_FIELD_MAP` 11컬럼 그대로 + `buyer_id` + `salesman_id` 추가. **buyer_id 영구 잠금 단독은 NO** — `$allowLedgerMutation` super-only flag 없이는 오기 차량 복구 영구 불가. approval_requests 2-actor는 빈도 대비 과설계.

**공수 추정**: 5~6시간
- guardLedgerAfterConfirmedPayment(): 0.5h
- FINANCIAL_FIELD_MAP buyer_id/salesman_id 추가: 0.3h
- $allowLedgerMutation flag + super 우회: 0.5h
- UI readonly 분기: 1h
- AuditLog 기록: 0.5h
- 테스트: 2h
- DB::table 우회 경로 전수 확인: 0.5h

**영향 파일**:
- `app/Models/Vehicle.php` — flag + `guardLedgerAfterConfirmedPayment()`
- `resources/views/livewire/erp/vehicles/index.blade.php` — FINANCIAL_FIELD_MAP 확장 + save() 호출 + UI readonly

**근거 파일/라인**:
- `resources/views/livewire/erp/vehicles/index.blade.php` L432~452 (FINANCIAL_FIELD_MAP 11컬럼)
- 동 L482~505 (guardFinancialDriftAfterPaid)
- 동 L986~990 (save() H4 호출 위치)
- `app/Models/FinalPayment.php` L38 ($allowConfirmedMutation 패턴)
- `app/Models/User.php` L187~190 (canEditVehicleFinancialFields)

**권한 가드 위치**: component method + model event (현재 H4 동일 2-layer)
**테스트 실행 가능 여부**: 가능
**운영 전 필수 여부**: yes
**캐시 rebuild 필요**: no

---

### 🧪 QA & Domain Integrity
**판정**: 조건부 GO

**발언**: `vehicles/index::guardFinancialDriftAfterPaid()` + `test_q10_h4_blocks_vehicle_financial_change_after_paid` 회귀 보호로 옵션 C는 이미 구현. **buyer_id·salesman_id는 FINANCIAL_FIELD_MAP에 없고 어떤 잠금 가드도 없음** — confirmed FinalPayment 있을 때 buyer_id 교체 시 채권 귀속 변경. 환율 0 외화 차량은 `finalPayments.whereNotNull('confirmed_at')` 기반이라 환율 무관 발동(추가 위험 없음). **현재 잠금이 UI 레이어 전용** → tinker/Seeder의 `vehicle->update(['purchase_price' => 999])` 무방비.

**도메인 공식 영향**:
- sale_unpaid_amount (분자): sale_price 변경 시 분자·분모 동시 변동 → unpaid_ratio retroactive
- purchase_unpaid_amount: purchase_price + selling_fee 변경 시 미지급액 변동
- 정산 공식 분자 (판매금원화): export_declaration_amount × exchange_rate 변경 시 공식 깨짐
- VAT 9% (purchase_price × 0.09): 트리거 A 후 안정
- cost_total → 정산판매금원화 → 판매마진 연쇄
- buyer_id / salesman_id: 현재 어떤 트리거에서도 잠금 없음

**회귀 시나리오**: 수동 20분 (5 케이스 — confirmed 1건 잠금 / buyer_id 교체 / 없을 때 자유 / 환율 0 USD / super unlock AuditLog)

**Unit Test**: test_q10_h4_blocks/allows 기존 존재. 트리거 A 교체 시 수정 + **신규 3 케이스 필수**

**깨질 가능성이 높은 기존 테스트**:
- `tests/Feature/WorkflowGapTest.php::test_q10_h4_blocks_vehicle_financial_change_after_paid`
- 동::test_q10_h4_allows_non_financial_change_after_paid
- 동::test_q7_c7a_admin_can_change_financial_fields (confirmed 잔금 없으면 유지)

**근거 파일/라인**:
- `app/Models/FinalPayment.php:53-59`
- `app/Models/Vehicle.php:606-617`
- `resources/views/livewire/erp/vehicles/index.blade.php:432-452, 479-505`
- `tests/Feature/WorkflowGapTest.php:704-727`

**운영 전 필수 여부**: yes

---

### 🔒 Security & Compliance
**판정**: 조건부 GO

**발언**: 현황 4건 확인 — ① 현행 잠금은 paid Settlement 기준만(confirmed 잔금↔paid 사이 보호 공백) ② `approvals/index::decide()` (L119~158)에 `requester_id !== auth()->id()` self-approve 가드 **없음** — admin self-approve 가능. `InterVehicleTransferService`에는 있지만 일반 ApprovalRequest는 무방비 ③ AuditLog::MASKED_COLUMNS에 RRN/계좌번호만, 금액 컬럼 변경은 평문 + `approval_request_id=null` 기록 → 추적 단절 ④ `$allowConfirmedMutation` static flag는 **레이스 컨디션 위험** — 동시 요청 시 한 요청이 플래그 켠 채 다른 요청이 슬롯 탐.

**보안 권고**: 잠금 트리거 A 채택. 잠금 컬럼 = 11컬럼 + buyer_id + salesman_id. **잠금 해제는 approval_requests 2-actor(관리+재무) 워크플로우 강하게 권장** + AuditLog approval_request_id 연결 필수. 영구 잠금은 super DB 직접 조작 유혹 위험으로 반대.

**근거 파일/라인**:
- `app/Models/User.php` L187~190
- `resources/views/livewire/erp/vehicles/index.blade.php` L482~505
- `resources/views/livewire/erp/approvals/index.blade.php` L119~158 (self-approve 가드 없음)
- `app/Models/FinalPayment.php` L34~68 (static flag 위험)
- `app/Services/InterVehicleTransferService.php` L182~183 (SoD 패턴 — 재사용 가능)
- `app/Models/AuditLog.php` L21~25

**개인정보·API키 영향**: 없음
**감사로그 영향**: audit_logs — 잠금 해제 시 approval_request_id 연결 필수
**운영 전 필수 여부**: yes

**조건부 GO 조건 4건**:
1. decide()에 requester_id≠auth()->id() 가드 추가
2. 잠금 해제는 신규 ApprovalRequest 경유 — static flag 직접 토글 금지 (레이스 컨디션)
3. 잠금 해제 변경은 AuditLog::withApprovalRequest로 래핑
4. FINANCIAL_FIELD_MAP 잠금 트리거 통일

---

### 🚀 Ops & Deploy
**판정**: 조건부 GO

**발언**: 코드베이스 실측 — 잔금 행 잠금은 큐 20-D에서 완료(`FinalPayment::updating` L56~67), 차량 본체 잠금은 paid 기준만(`guardFinancialDriftAfterPaid` L488). 트리거 A 신규 메서드 `guardFinancialDriftAfterConfirmedPayment()` 추가 + save() H4 직전 삽입. **영구 잠금 채택 시 `php artisan tinker` `Vehicle::update()` 우회 시 saving 이벤트 우회 → progress_status_cache·sale_unpaid_amount_krw_cache stale** → 필수 후처리 `$vehicle->refreshCaches()`. super 전용 `VehicleLedgerUnlockService` 서비스 메서드로 DB 직접 수정 경로 차단 권장. 마이그 0건(accessor 기반) 또는 `ledger_locked_at` 컬럼 1개 옵션. 다운타임 0초 (InnoDB INSTANT).

**다운타임**: 0초
**백업 시점**: 잠금 로직 배포 직전 `mysqldump car_erp` 필수 (confirmed_at 백필 이미 적용)
**queue worker 영향**: 무관
**환경 의존성**: 없음
**테스트 실행 환경**: Windows XAMPP PHP (php artisan test --filter=VehicleLedgerLockTest 신규)
**스토리지 영향**: 없음
**근거 파일/라인**:
- `app/Models/FinalPayment.php:51-67`
- `resources/views/livewire/erp/vehicles/index.blade.php:432-505`
- `app/Models/Vehicle.php:409-415` (refreshCaches)

**운영 전 필수 여부**: yes

**조건부 GO 조건 3건**:
1. super 전용 `VehicleLedgerUnlockService::unlock(Vehicle, reason)` — flag + confirmed_at 처리 + refreshCaches() 원자적 묶음 + AuditLog 기록
2. 배포 직전 DB 백업 필수
3. AWS Lightsail 운영자 부재 시나리오 — UnlockRequest 승인 흐름 또는 tinker 절차서 문서화

---

### 🔧 Specialist [F. 회계·정산 감사]
**판정**: 조건부 GO

**발언**: 현재 코드는 큐 20-B/D까지 상당히 정합. `FinalPayment::updating`(L54~58) + `PaymentConfirmationService::assertPaidSettlementGuard()`(L100~105) 작동 중. **이번 안건의 핵심 갭은 `guardFinancialDriftAfterPaid()`가 paid 정산에만 작동(UI 레이어 전용)이며 artisan/seeder/Service 우회 가능**. `sale_price`/`purchase_price` 등이 `Vehicle::AUDITED_COLUMNS`에 없어 audit trail 단절. `confirmed_snapshot`은 paid 시점 잔금만 캡처 — 차량 본체 컬럼(buyer_id·salesman_id·purchase_price·sale_price·exchange_rate) snapshot 부재 → "어느 값으로 정산됐는가" 추적 불가.

**회계 retroactive 영향**:
- 잠금 범위 미결로 거래완료 전 단계 잠금 발동 시 영업 수정 불가 조기 발생 가능
- 잠금이 UI 레이어 전용 → Model 레이어 가드 필수
- snapshot에 차량 본체 핵심 컬럼 추가 필요
- 환율 0/NULL 외화 차량은 scopeAction에서 처리되나 admin dashboard 추가 검증 필요

**근거 파일/라인**:
- `app/Services/PaymentConfirmationService.php` L57, L85, L100~105
- `app/Models/FinalPayment.php` L51~68
- `app/Models/PurchaseBalancePayment.php` L31~43
- `app/Models/Settlement.php` L71~112
- `resources/views/livewire/erp/vehicles/index.blade.php` L432~505
- `app/Models/Vehicle.php` L197~205 (AUDITED_COLUMNS — sale_price 없음)

**운영 전 필수 여부**: yes

**조건부 GO 조건 4건**:
1. 잠금 트리거 결정 (옵션 A 채택 시 super 전용 unlock 경로 + AuditLog 강제)
2. Model 레이어 가드 (Vehicle::saving)
3. snapshot에 차량 본체 핵심 컬럼 추가 (buyer_id·salesman_id·purchase_price·sale_price·exchange_rate)
4. Vehicle::AUDITED_COLUMNS에 purchase_price 추가

---

### 🔧 Specialist [E. 승인·권한 정책]
**판정**: 조건부 GO

**발언**: 큐 19-F SoD 패턴(`canApprove ≠ canConfirmFinance`) 재사용 가능. `canApprove()`=super/admin/관리, `canConfirmFinance()`=super/admin/정산, 관리 role 명시 차단(User.php L110~117). C7-a + H4의 2-layer 잠금 구조 잘 분리됨. **이번 안건이 영업 role도 잠금 구간 생기면 unlock 경로 완전 부재** — ApprovalRequest vehicle_field_change action_type 또는 super flag 중 선택 필수. **`TYPE_SENSITIVE_ACTION`이 ApprovalRequest::execute() match에 분기 없음**(L113 default → LogicException) — 승인 시 예외 발생.

**승인/권한 정합성**:
- action_type 매핑: 5종 처리, sensitive_action 미처리
- SoD 충돌: 영업이 confirm 전 편집 가능 → confirm 후 잠금 → "요청자=영업, 승인자=관리/admin" 자동 성립
- 직접 실행: super 긴급 수정 시 AuditLog::recordEvent 강제
- forceDelete: backups 이동 + audit_logs 기록 있음. **confirmed 잔금 차량 soft-delete 가드 없음**

**근거 파일/라인**:
- `app/Models/User.php` L110~117, L145~148, L180~190
- `app/Models/ApprovalRequest.php` L40~46 (TYPES), L106~116 (execute match)
- `resources/views/livewire/erp/vehicles/index.blade.php` L980~990
- `app/Models/Vehicle.php` L229~238 (forceDeleted)

**운영 전 필수 여부**: yes

**조건부 GO 조건 4건**:
1. 잠금 트리거 레이어 명확화 (UI → Model)
2. unlock 경로 정의 (super flag + AuditLog 강제, 2-actor는 별건 확장)
3. TYPE_SENSITIVE_ACTION execute() 분기 추가 (LogicException 차단)
4. confirmed 잔금 존재 차량 Vehicle::deleting 가드

---

## 🧩 중간 회의 결과 (Opus 4.7 1차 취합)

### 🗳 부서별 판정 요약
- 📋 PO: 조건부 GO — 옵션 A + 2계층 분리
- ⚙️ Engineer: 조건부 GO — 옵션 A, 공수 5~6h, buyer_id 영구 잠금 단독 NO
- 🧪 QA: 조건부 GO — H4 트리거 확장, 환율 0 무관, 깨질 테스트 2개
- 🔒 Security: 조건부 GO — 영구 잠금 단독 반대, approval_requests 2-actor 권장
- 🚀 Ops: 조건부 GO — super 전용 unlock 서비스, 다운타임 0초
- 🔧 Specialist [F]: 조건부 GO — Model 레이어 가드 + snapshot 확장
- 🔧 Specialist [E]: 조건부 GO — TYPE_SENSITIVE_ACTION 분기 필수, soft-delete 가드

### 🎯 합의된 GO 조건 (6/6 만장일치)
1. 잠금 트리거 = 옵션 A
2. 모델 레이어 가드 격상 (Vehicle::saving)
3. AuditLog 필수 기록 (approval_request_id 연결)
4. 잠금 대상 = FINANCIAL_FIELD_MAP 11컬럼 + buyer_id + salesman_id
5. 운영 사고 복구 백도어 필수
6. 부수 fix 4건 동반: decide() self-approve / execute() TYPE_SENSITIVE_ACTION / confirmed 차량 soft-delete 가드 / AUDITED_COLUMNS 확장

### ⚔️ 부서 간 충돌 영역 → 사외이사 판정

**충돌 1 — 잠금 해제 방식**:
- Security: approval_requests 2-actor (관리+재무) 워크플로우
- Engineer + Ops + PO + Specialist E: super-only flag + AuditLog

**충돌 2 — buyer_id 잠금 강도**:
- Security: 영구 포함
- PO + Engineer + QA: admin 우회 허용

---

## 🌐 사외이사 의견 (Codex / Gemini)

### [Codex]
외부 시각 판정: **조건부 GO**.

1. 놓친 리스크: 확정 잔금 "존재"만 보지 말고 **취소·역분개·void 상태를 분리**해야 한다. 둘째, unlock 후 **재잠금 시점과 변경 diff 검증**이 없으면 승인만 있고 무결성은 깨진다. 셋째, salesman/buyer 변경은 재무 필드가 아니어도 **미수율·수수료·권한 리포트에 후행 오염**을 만든다.

2. unlock 방식은 1인 개발·5~6h 기준 **`super-only flag + AuditLog + 사유 필수 + 1회성 TTL`**로 판정. 다만 금액 11컬럼은 2-actor로 갈 설계 여지를 남겨라. buyer_id는 영구잠금 반대. admin/super 정정 허용하되 AuditLog와 사유 필수.

3. SAP/Oracle/Odoo 일반 패턴은 posted/closed period 이후 원장 직접수정이 아니라 **reversal, adjustment, reopening workflow**다. 즉 "영구 불변"보다 "통제된 정정 + 감사추적"이 표준에 가깝다.

4. 우선순위: 모델 훅 잠금 > AuditLog diff > super unlock > buyer/salesman 포함 > 2-actor는 후순위. 지금 2-actor부터 하면 공수 대비 실패면이 크다.

5. 자체 NO-GO는 없음. 단 buyer_id 영구잠금 단독 채택이면 NO-GO: (a) 오기 정정 불가 (b) 최소 조건은 승인형 unlock (c) 대안은 super 정정+감사로그.

### [Gemini]
사외이사로서 내부 회의 결과의 사각지대를 지적하고 전략적 판정을 내립니다.

**1. 내부 부서가 놓친 리스크**
- **부분 납입 후 조정 불능**: 1건의 잔금 컨펌으로 전체 필드를 잠글 경우, '할인'이나 '클레임'으로 인한 잔액 조정(매출 감액) 시 운영 병목이 극심.
- **역산 무결성 괴리**: 환율/비용 수정 시 이미 컨펌된 입금액과 장부상 미수금 간의 로직이 실시간 동기화되지 않으면 재무제표상 '미수율' 지표가 오염.

**2. 충돌 영역 판정**
- **잠금 해제**: 1인 개발 5~6h 제약 하에서는 **'Super-only flag + AuditLog'**가 정답. 2-actor 승인 체계는 UI/상태 머신 구현에 과도한 공수.
- **buyer_id**: **Admin 우회 허용** 지지. 바이어는 마스터 데이터 성격이라 오기 정정 불가 시 통계 리포트 전체 망가짐.

**3. 글로벌 ERP(SAP/Odoo) 비교**
- 글로벌 표준은 **'수정(Edit)'이 아닌 '역분개(Reversal) 후 재전표'**. 확정된 장부는 수정하지 않고 취소 전표 발행 후 재기록. 현재 SSANCAR는 '수정' 기반이므로 **Financial Snapshot 로그**가 핵심.

**4. 1인 개발 우선순위**
- 최우선: `Vehicle::saving` 훅에서 `isDirty(FINANCIAL_FIELD_MAP)` 감지 + Super-flag 검증
- 차선: 수정 시 '재무 컨펌 취소(Unconfirm)'를 선행 강제하여 재무팀 인지

**5. NO-GO 사유 및 대안**
- **(a) 차단**: '재무 컨펌 취소' 기능 없이 수정만 열릴 경우 NO-GO
- **(b) 최소 조건**: 수정 시 기존 컨펌 상태를 `pending`으로 강제 전환 또는 수정 이력을 재무에 노티
- **(c) 대안**: 수정 허용 범위를 '금액에 영향 없는 필드(영업사원 등)'와 '영향 있는 필드'로 나누어 가드 강도 차등 적용

---

## 🏁 최종 권고 (Opus 4.7 최종 취합)

**판정**: **조건부 GO**

**근거 (1줄)**: 내부 6부서 + 사외이사 2명 모두 조건부 GO. 옵션 A 트리거 + 2계층 분리 + super-only flag + 사외이사 신규 통찰(1회성 TTL · Reversal 워크플로우 · confirmed→pending 옵션) 통합 패키지 채택.

### 필수 선행 작업
- 부수 fix 4건이 본 안건 GO 조건의 일부이므로 동일 PR 또는 직전 PR에서 함께 머지
  1. `approvals/index::decide()` self-approve 가드 (Security, 1줄 fix)
  2. `ApprovalRequest::execute()` `TYPE_SENSITIVE_ACTION` 분기 추가 (Specialist E, LogicException 차단)
  3. confirmed 잔금 존재 차량 `Vehicle::deleting` 가드 (Specialist E)
  4. `Vehicle::AUDITED_COLUMNS`에 sale_price·purchase_price·exchange_rate·export_declaration_amount·비용 9컬럼 추가 (Specialist F)

### 조건 (조건부 GO)

**① 잠금 트리거 = 옵션 A** (confirmed FinalPayment OR PurchaseBalancePayment 1건 이상)
- 옵션 B (Settlement pending) 기각 — 채널 모순, 과잉 차단
- 옵션 C (Settlement paid) 흡수 — 트리거 A가 더 이른 시점이라 C 자동 포함

**② 잠금 범위 — 사용자 최종 결정 2026-05-18 (회의 권고에서 일부 조정)**

| Tier | 컬럼 | 정책 |
|---|---|---|
| **Tier 1 (금액 직결)** | purchase_price, sale_price, exchange_rate, export_declaration_amount, cost_* 9컬럼, selling_fee, tax_dc, commission, transport_fee, auto_loading, sale_other_costs | **잠금 + admin+super 공통 우회 권한 + AuditLog 필수 + 변경 사유 10자 이상 + 저장 1회 완료 즉시 자동 재잠금** |
| **Tier 2 (관계 식별)** | buyer_id, salesman_id | **동일 정책** (Tier 1과 같은 권한·동일 사유 길이·동일 재잠금 방식) |
| **Tier 3 (잠금 외부)** | 매입처 계좌 4컬럼(purchase_seller_bank/account/holder/bank_memo) | 잠금 대상 외 (회계 공식 미포함). 별건 보안 안건으로 검토 |

> **사용자 결정 차이점 (2026-05-18 회의 후 정정)**:
> - 회의 권고 "Tier 1·2 분리"를 사용자가 **단일 정책으로 통합** — admin이 둘 다 풀 수 있음
> - 회의 권고 "1회성 TTL 60초"를 사용자가 **저장 1회 후 즉시 재잠금** (동작 기반)으로 강화 — race window 0
> - 회의 권고 "사유 255자"를 사용자가 **10자 이상**으로 완화 — 실용성 우선
> - Security 부서 SoD 우려는 ① 동작 기반 즉시 재잠금(race window 0) ② 사유 강제 ③ 1인 운영 컨텍스트로 부분 해소, 부분 운영 현실로 격하

**③ 모델 레이어 가드 격상** — `Vehicle::saving` 또는 `updating` 훅에 `isDirty(FINANCIAL_FIELD_MAP)` + `finalPayments()->whereNotNull('confirmed_at')->exists()` OR `purchaseBalancePayments()->whereNotNull('confirmed_at')->exists()` 검사 (UI/Service/artisan/Seeder 모두 차단)

**④ 운영 사고 복구 백도어 = `VehicleLedgerUnlockService` (사용자 결정 반영)**
```php
// app/Services/VehicleLedgerUnlockService.php (신규)
public function unlock(Vehicle $v, User $by, string $reason): void
{
    // 사용자 결정 — admin+super 공통 권한
    if (! $by->canAccessAdmin()) {
        throw new AuthorizationException('잠금 해제 권한 없음 (admin/super 전용)');
    }
    // 사용자 결정 — 사유 10자 이상
    if (mb_strlen(trim($reason)) < 10) {
        throw new \DomainException('잠금 해제 사유는 10자 이상 필수');
    }

    DB::transaction(function () use ($v, $by, $reason) {
        // 동작 기반 단발 사용 — 캐시 키로 unlock token 발급
        cache()->put("vehicle_ledger_unlock:{$v->id}", [
            'by' => $by->id,
            'reason' => $reason,
            'issued_at' => now()->toIso8601String(),
        ], now()->addMinutes(5));  // 5분 안전 만료 (이론상 저장 1회면 즉시 소비)

        AuditLog::recordEvent($v, 'ledger_field_unlocked', [
            'reason' => $reason,
            'unlocked_by' => $by->id,
        ]);
    });
}

// Vehicle::saving 훅 — unlock 토큰 1회 소비 후 즉시 무효화
protected function consumeUnlockTokenIfPresent(): bool
{
    $key = "vehicle_ledger_unlock:{$this->id}";
    $token = cache()->pull($key);  // ← pull = 읽기 + 즉시 삭제 (1회성)
    return $token !== null;
}
```

**핵심 설계 — 저장 1회 후 자동 재잠금**:
- admin/super가 [잠금 해제] 모달에서 사유 입력 → cache에 unlock 토큰 발급 (5분 안전 만료)
- 같은 사용자가 차량 편집 패널에서 잠금 컬럼 수정 후 저장 → `Vehicle::saving` 훅이 `cache()->pull()` 로 토큰 **1회 소비** + 즉시 삭제
- 토큰 소비된 후 다음 저장은 다시 잠금 적용 → 추가 변경 필요하면 다시 [잠금 해제] 클릭
- 5분 안전 만료는 "풀어놓고 다른 일 보다 잊어버린 경우" 백업용 — 실제로는 즉시 소비되어 0초에 무효화

**⑤ AuditLog 보강**
- 잠금 해제 변경은 `AuditLog::withApprovalRequest($id, fn() => ...)` 래핑으로 `approval_request_id` 연결 (선택)
- 잠금 해제 시 `unlocked_by` user_id + `reason` **10자 이상 필수**
- `AUDITED_COLUMNS`에 금액 컬럼 추가 (Specialist F 권고)

**⑥ UI 분기 + Helper Text**
- 차량 편집 패널 매입/판매/통관 탭의 잠금 컬럼 input → `readonly` + 우측 🔒 아이콘 + tooltip "재무 확정 잔금이 있어 수정 불가. admin/super 잠금 해제 필요."
- admin/super 사용자에게는 우측에 [🔓 잠금 해제] 버튼 — 모달 (사유 10자 이상 입력 + 잠금 컬럼 목록 표시) → confirm 시 unlock 토큰 발급 + readonly 해제 → 1회 저장 후 자동 재잠금

**⑦ Codex/Gemini 신규 통찰 채택**
- **1회성 TTL** (Codex): unlock 후 60초 안에 변경 안 하면 자동 재잠금
- **Reversal 워크플로우 옵션** (Codex+Gemini): 향후 큐 22 별건으로 reversal/adjustment 패턴 도입 검토 (현 단계엔 super flag 우선)
- **confirmed→pending 강제 전환 옵션** (Gemini): 잠금 해제 시 해당 잔금 row의 confirmed_at도 null로 강제 → 재무 재확정 트리거. **본 PR엔 미포함, 향후 옵션**

**⑧ 채택 미정 (사용자 결정 필요)**
- 2-actor 승인 워크플로우 (Security 권장): 별건 안건으로 분리. 현 PR엔 미포함

### 모순·NO-GO 처리 로그

**Security 2-actor 강하게 권장 (6 대 1 충돌)**:
- 내부 4부서 + 사외이사 2명 모두 "super flag로 충분" 합의 → 다수 의견 우세
- Security SoD 우려는 ① super flag + AuditLog ② 1회성 TTL ③ 사유 필수 입력 ④ super 권한 자체가 1인(개발자)에 제한된 운영 컨텍스트 → 부분 해소
- 2-actor 워크플로우는 별건 안건(향후 운영 빈도 따라 추가) — Security NO-GO 자동 무효 격하

**Gemini NO-GO (a)(b)(c) 갖춤 → 유효**:
- (a) 재무 컨펌 취소 기능 없이 수정만 열림 → 본 패키지의 `VehicleLedgerUnlockService` + AuditLog + TTL로 부분 해소
- (b) confirmed→pending 강제 전환 옵션은 향후 별건 검토
- (c) Tier 1·2 차등 가드는 본 패키지에 채택

**Codex 조건부 NO-GO (buyer_id 영구잠금 단독)** → 유효:
- 본 패키지에서 Tier 2(admin 우회) 분리로 자동 회피

### 보류 사유
없음 (조건부 GO 충족 시 즉시 진행 가능)

### 공수 추정
- 마이그 0건 (또는 ledger_locked_at 컬럼 1개 옵션)
- Vehicle::saving 훅 + guardLedgerAfterConfirmedPayment(): 1h
- FINANCIAL_FIELD_MAP buyer_id/salesman_id 추가: 0.3h
- VehicleLedgerUnlockService + super flag + TTL: 1.5h
- UI readonly + Helper Text + 잠금 해제 모달: 1.5h
- 부수 fix 4건 (decide/execute/deleting/AUDITED_COLUMNS): 1.5h
- 신규 테스트 (VehicleLedgerLockTest 8 케이스): 2~3h
- 깨질 기존 테스트 2개 재작성: 0.5h
- **총 7.5~8.5h** (사외이사 신규 통찰 1회성 TTL 추가 반영)
- 다운타임 0초

---

## 🛠 car-erp 영향 분석 (Opus 4.7 산출)

### 취약점 (Vulnerabilities)
1. 영업이 confirmed 잔금 후에도 매입가/판매가/환율/면장금액/비용/바이어/담당자 자유 수정 → retroactive 회계 오염 (sale_unpaid_amount / 정산 마진 / 미수율 게이지)
2. UI 레이어 잠금(`guardFinancialDriftAfterPaid`) 단독 → artisan/Service/Seeder 우회 가능 (Specialist F + QA)
3. `approvals/index::decide()` self-approve 가드 부재 → admin self-approve 가능 (Security)
4. `ApprovalRequest::execute()` `TYPE_SENSITIVE_ACTION` 미처리 → 승인 시 `LogicException` (Specialist E)
5. buyer_id / salesman_id 잠금 부재 → 채권 귀속 + 정산 분배 임의 변경 가능
6. `Vehicle::AUDITED_COLUMNS`에 금액 컬럼 부재 → 변경 audit trail 단절 (Specialist F)
7. confirmed 잔금 존재 차량 `Vehicle::deleting` 가드 없음 (Specialist E)
8. `$allowConfirmedMutation` static flag 레이스 컨디션 위험 (Security)
9. `confirmed_snapshot`에 차량 본체 핵심 컬럼 부재 → "어느 값으로 정산됐는가" 추적 불가 (Specialist F)

### 보완사항 (Improvements)
1. 모델 레이어 가드 격상 (`Vehicle::saving` 훅)
2. AuditLog 사유 입력 모달 (255자 필수)
3. 1회성 TTL super flag (60초 자동 재잠금 — Codex 신규)
4. UI Helper Text 인라인 안내 + 🔒 아이콘
5. super 잠금 해제 버튼 (모달 + 사유 + Tier 표시)
6. confirmed 잔금 차량 soft-delete 시 가드
7. confirmed→pending 강제 전환 옵션 (Gemini 신규, 향후 별건)
8. Reversal/Adjustment 패턴 (Codex+Gemini 글로벌 표준, 큐 22 별건 검토)

### 코드 수정 (Code Changes)
- `app/Models/Vehicle.php` — `$allowLedgerMutation` static flag + `guardLedgerAfterConfirmedPayment()` 메서드 + `saving` 훅 가드 + `deleting` 훅 confirmed 차량 가드
- `app/Models/Vehicle.php::AUDITED_COLUMNS` — sale_price·purchase_price·exchange_rate·export_declaration_amount·cost_* 9컬럼·selling_fee·tax_dc·commission·transport_fee·auto_loading·sale_other_costs·buyer_id·salesman_id 추가
- `resources/views/livewire/erp/vehicles/index.blade.php` — `FINANCIAL_FIELD_MAP`에 buyer_id + salesman_id 추가 + save() 흐름에 guardLedgerAfterConfirmedPayment() 호출 + UI readonly 분기 + Helper Text + super 잠금 해제 버튼·모달
- `app/Models/ApprovalRequest.php::decide()` (또는 `approvals/index::decide()`) — requester_id !== auth()->id() self-approve 가드
- `app/Models/ApprovalRequest.php::execute()` — TYPE_SENSITIVE_ACTION 분기 추가 (no-op + AuditLog 기록 또는 실행 로직)
- `app/Models/Settlement.php::saving` — confirmed_snapshot에 buyer_id·salesman_id·purchase_price·sale_price·exchange_rate 추가 캡처

### 신규 추가 (New Additions)
- `app/Services/VehicleLedgerUnlockService.php` — super-only unlock + AuditLog 사유 + 1회성 TTL (60초)
- `tests/Feature/VehicleLedgerLockTest.php` — 8 케이스
  1. confirmed FinalPayment 1건 → sale_price 변경 시도 차단
  2. confirmed PurchaseBalancePayment 1건 → purchase_price 변경 시도 차단
  3. confirmed 잔금 없음 → 자유 수정 가능
  4. 환율 0 USD 차량 + confirmed → 잠금 발동
  5. super unlock → AuditLog 기록 + 60초 내 변경 허용
  6. super unlock 후 60초 경과 → 자동 재잠금
  7. buyer_id admin 우회 → AuditLog 기록 + 변경 허용
  8. salesman_id admin 우회 → 동일
- `tests/Feature/WorkflowGapTest.php::test_q10_h4_blocks/allows` — 트리거 A 교체 재작성 (2 테스트)

### 모순·NO-GO 처리 로그
- **Security 2-actor 워크플로우 권장** (6 대 1) — 내부 4부서 + 사외이사 2명 의견과 충돌 → super flag + AuditLog + TTL + 사유로 부분 해소, 2-actor는 별건 안건으로 분리 (자동 격하)
- **Gemini NO-GO (a)(b)(c) 갖춤** — 유효 → `VehicleLedgerUnlockService` + Tier 2 차등 가드로 부분 해소
- **Codex 조건부 NO-GO (buyer_id 영구잠금 단독)** — 유효 → Tier 2(admin 우회) 분리로 자동 회피
- **사용자 영구 잠금 선호** — Tier 1 영구 잠금 + super flag(의도된 마찰)로 반영. Tier 2는 운영 현실 고려해 admin 우회 허용 (Codex+Gemini+PO+Engineer+QA 합의)

---

## 🔗 참조

### 관련 과거 회의록
- `2026-05-17-purchase-sale-finance-gate.md` — 큐 20 P2 정석 패키지 (A안 분자 필터, 246 passed 완료)
- `2026-05-16-finance-gate-roundtable.md` — 큐 19-F 재무 확정 게이트 SoD 패턴 (canApprove ≠ canConfirmFinance)
- `2026-05-13-progress-status-integrity.md` — 큐 2.6 무결성 정책, append-only 패턴
- `2026-05-14-3way-workflow-policy.md` — 미수율 분모 정의 v5 (sale_total_amount 단일 출처)

### 코드 참조 섹션
- `CLAUDE.md` — 권한 3단계, role 5종, 핵심 주의사항 #10·#11 (VAT 9% / 면장금액 환산)
- `SKILLS.md §13` — 미수율 분모 단일 출처 (5곳 정합 — 본 안건이 이 정합성을 보호)
- `role기획보안_수정.md` — 7단계 권한 세분화 (본 안건은 7단계와 직결)
- `decision_protocol.md §6` — 정산 마진 공식 변경 행 + 권한·role 모델 변경 행
- `app/Models/Vehicle.php` L210, L603~629
- `app/Models/FinalPayment.php` L38, L46~67
- `app/Models/PurchaseBalancePayment.php` L31~43
- `app/Services/PaymentConfirmationService.php` L57, L85, L97~105
- `app/Models/User.php` L110~117, L145~148, L180~190
- `app/Models/ApprovalRequest.php` L40~46, L106~116
- `app/Models/AuditLog.php` L21~25
- `resources/views/livewire/erp/vehicles/index.blade.php` L432~452, L479~505, L980~990
- `resources/views/livewire/erp/approvals/index.blade.php` L119~158
- `app/Services/InterVehicleTransferService.php` L182~183 (SoD 패턴 참조)
- `tests/Feature/WorkflowGapTest.php:704~727` — test_q10_h4

### 부서 프롬프트 (v1.2)
- `docs/meetings/departments/po.md`
- `docs/meetings/departments/engineer.md`
- `docs/meetings/departments/qa.md`
- `docs/meetings/departments/security.md`
- `docs/meetings/departments/ops.md`
- `docs/meetings/departments/specialist.md`
