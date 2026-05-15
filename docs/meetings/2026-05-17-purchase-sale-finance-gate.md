# 📅 회의록: 큐 20 — SSANCAR 매입·판매 전 흐름 재무 확정 게이트 + 매입처 계좌 정보

- 일시: 2026-05-17
- 강도: 풀회의 (/회의 명령어 호출)
- 안건 유형: 마이그레이션 + 권한·role 변경 + 도메인 모델 재설계 (복합·대규모)
- 자동발동 여부: yes (/회의 슬래시)
- 사외이사: Codex (gpt-5.5) / Gemini — 둘 다 응답 성공
- 결정 상태: **GO — 사용자 4건 모두 확정 (2026-05-16 컨펌)**. P2 정석 패키지 채택, 단 19-F-D 선행은 PO 권장 채택.

---

## 0. 안건 요약

큐 19-F (자금 이체 재무 확정 게이트) 19-F-A/B/C 완료 후 사용자가 SSANCAR 실무 전체 흐름을 처음 명확하게 설명. 19-F가 "차량 간 자금 이체" 1개 케이스에만 게이트화한 것이 매우 좁은 범위였음이 드러남.

### 사용자가 명시한 SSANCAR 실무 시나리오 12단계
1. 바이어 → 영업 연락 (외부)
2. 영업 알아봄 + 차량 등록 + 매입가 결정
3. 영업 → 바이어 가격 통보 (외부 / G4)
4. 바이어 → 계약금 10% 또는 50% 입금
5. ★ **재무가 통장 확인 → 영업 알림** (부재)
6. ★ **영업 → 매입처 송금 요청 (계좌번호 필요)** (부재)
7. ★ **재무가 송금 + 마킹 → 영업 알림** (부재)
8. 매입 잔금 추적
9. 차량 등록 시 판매가 동시 기입
10. 판매 50%↑ 입금 후 통관·선적 (G1, 미구현)
11. B/L 인도 전 100% 잔금
12. 50% 미만 시 영업 → 바이어 추가 요청 (외부 / G4)

### 코드 검증 결과 (사전 확인)
- **Gap 1**: 매입처 계좌번호 컬럼 부재 (`vehicles.purchase_from` string만)
- **Gap 2**: 재무 확정 게이트 부재 — 계약금/중도금/선수금/잔금/매입송금 모두 즉시 ledger 기록
- **Gap 3**: G1 50% B/L 잠금 미구현 (큐 9 확장 안건)

---

## 💬 부서별 발언 (Sonnet 4.6)

### 📋 PO
판정: 조건부 GO

발언: 코드 검증 완료 — `vehicles` 테이블에 `purchase_from`(string, 이름만) + `purchase_remittance_memo`(text)만 실재. 19-F "관리≠재무 분리" 원칙은 본 안건의 전제. 사용자 막힘 3지점: ① 영업 — 매입처 계좌 ERP 추적 불가, 메모장 관리; ② 재무(김진영) — 입금/송금 마킹 위치 없음, 회계 무결성 공백; ③ 관리(박관리) — 자금 이동 확인 불가. 게이트 범위 확장 필요하지만 **1인 개발 공수 제약상 일괄 도입 비현실적**. 19-F 패턴이 `inter_vehicle_transfers` 1개 테이블이었으나 본 안건은 `final_payments + purchase_balance_payments + down_payment + interim_payment + advance_payment1/2`까지 = 적용 지점 폭발 위험. PO 권고: 매입처 계좌(Gap 1)는 vehicles 컬럼 확장(B1)으로 단기 처리, 재무 게이트(Gap 2)는 **매입 송금 먼저(A2) → 판매 입금(A1) 순** — 매입 실수가 판매 오기장보다 자금 손실 직결 리스크 높음. G1(Gap 3)은 큐 9 확장 별도 유지. 19-F-D(테스트) 본 안건 착수 전 완료 권장.

다음 작업 큐 영향: 19-F-D 우선 완료 후 본 안건 착수 권장. 큐 5 병렬 가능. 큐 10은 본 안건 재무 게이트 도입 후 최종 정합성 검증 필요 — 본 안건 → 큐 10 순서 유지.

업무 영향 role: 영업·정산·관리·admin
사용자 막힘 정도: 차단 (Gap 1·2) / 기능 (Gap 3)
우선순위 분류: 리스크 제거 (Gap 1·2) / 기능 (Gap 3)
근거 파일/라인: `database/migrations/2026_05_07_000006_create_vehicles_table.php` L57, `app/Models/Vehicle.php` L34·37, `docs/meetings/2026-05-16-finance-gate-roundtable.md`
운영 전 필수 여부: yes

**19-F 패턴 재사용 가능성 PO 판단**: 5상태 머신 재사용 설계는 맞지만 **1:1 복사는 금지** — `inter_vehicle_transfers` 단일 엔티티 vs 본 안건 9개 입력 포인트. 패턴 복사 시 코드 9벌. **PO 조건**: polymorphic 통합 테이블(옵션 iii) 또는 단일 `finance_confirmations` 서비스 추상화 필수. 단순 `confirmed_by_user_id` 컬럼 9개 박기(옵션 ii)는 유지보수 불가. 조건부 GO 조건 4건: ① 매입처 계좌(B1) 1단계 분리 즉시 구현; ② 재무 게이트 매입 송금(A2)부터 단계 도입; ③ polymorphic 또는 단일 서비스 추상화 합의; ④ 19-F-D 완료 후 착수.

---

### ⚙️ Engineer
판정: 조건부 GO

발언: 코드베이스 직접 확인 완료. 핵심 판단:

**[1] 19-F 패턴 재사용 가능성 — 조건부 YES, 단 "별도 Service" 권고**
`InterVehicleTransferService`는 Vehicle←→Vehicle 간 `FinalPayment` 페어 생성에 강하게 결합. `approve/confirmByFinance/approveVoid/confirmVoidByFinance` 내부가 `FinalPayment::create()` 2건(source/target)을 트랜잭션으로 묶는 구조 — 매입·판매 잔금에 일반화 시 source_vehicle_id / target_vehicle_id / buyer_id 동일 가드 3종 제거·교체 필요. **별도 `PaymentConfirmationService` 신설이 안전**. 5상태 머신 자체·SoD self-confirm 차단·`assertPaidSettlementGuard` 패턴은 그대로 복사.

**[2] 옵션 (ii) 단순 컬럼 권고** — 1인 개발 현실
`FinalPayment`: `vehicle_id, transfer_id, amount, payment_date, note` — 재무 확정 추가 시 컬럼 4개(`confirmed_by_user_id, confirmed_at, confirmed_status ENUM, finance_note`)를 final_payments + purchase_balance_payments 양쪽에 추가. polymorphic 통합(옵션 iii)은 `refreshCaches()` 내 `DB::table()` 직접 쿼리와 충돌 위험.

**[3] 매입처 계좌 — B1(컬럼 4개) 적정**
같은 매입처명 반복 입력 현실 미확인 + 1인 개발 + B2 마스터 시 공수 2~3배. `purchase_bank / purchase_account_number / purchase_account_holder / purchase_bank_memo` vehicles 직접 추가.

**[4] 안건 3 옵션 C(자동 pending) 권고**
잔금 row 저장 시 `confirmed_status='pending'`으로 자동 기록 → 재무 대기열 노출. "송금 요청" 버튼(옵션 B)은 영업 실수로 누락 시 운영 사고.

**[5] G1 50% B/L 잠금 — assertGuards에 이미 존재**
`InterVehicleTransferService::assertGuards()` L342 `$ratio > 0.5` 패턴 확인. `Vehicle::guardAttachmentDeps()` 또는 별도 가드 메서드에 추가, Volt save() 시점에 끼움.

**[6] 마이그레이션 backfill**
개발 DB 한정 가정 시 `confirmed_status='confirmed', confirmed_at=created_at`이 자연스럽다. 운영 DB 첫 배포 시 `confirmed_status=NULL`(미확인) 상태로 두고 재무가 직접 확인이 회계 무결성 보호.

**공수 추정**:
- 옵션 (i) 풀 ApprovalRequest 일반화: 18~25h
- **옵션 (ii) 단순 컬럼 + 19-F 패턴 부분 재사용: 8~12h** (권장)
- 옵션 (iii) polymorphic 통합 테이블: 14~18h

영향 파일: `app/Models/FinalPayment.php` + `app/Models/PurchaseBalancePayment.php` + `app/Models/Vehicle.php` + `app/Services/PaymentConfirmationService.php`(신규) + 마이그 3건(`add_finance_gate_to_final_payments` / `add_finance_gate_to_purchase_balance_payments` / `add_purchase_account_to_vehicles`) + `resources/views/livewire/erp/transfers/index.blade.php`(탭 추가)

권한 가드 위치: component method (Volt save() 내 guard 호출) + model event (`FinalPayment::booted` confirmed_status 게이트). 기존 settlement 미들웨어 충분.
캐시 rebuild 필요: no (confirmed 여부가 payment_date 필터 기준만 추가하면 progress_status에 영향 없음). 단 `confirmed=true`만 미지급 차감에 반영하기로 결정 시 `php artisan vehicles:rebuild-caches` 1회 실행.
운영 전 필수 여부: yes

**롤백 SQL** (3줄):
```sql
ALTER TABLE final_payments DROP COLUMN confirmed_by_user_id, DROP COLUMN confirmed_at, DROP COLUMN confirmed_status, DROP COLUMN finance_note;
ALTER TABLE purchase_balance_payments DROP COLUMN confirmed_by_user_id, DROP COLUMN confirmed_at, DROP COLUMN confirmed_status, DROP COLUMN finance_note;
ALTER TABLE vehicles DROP COLUMN purchase_bank, DROP COLUMN purchase_account_number, DROP COLUMN purchase_account_holder, DROP COLUMN purchase_bank_memo;
```

---

### 🧪 QA & Domain Integrity
판정: **HOLD**

발언: 사전 검증 완료. 19-F 게이트는 `inter_vehicle_transfers`에만 적용됨 — `final_payments` / `purchase_balance_payments` 자체에는 `confirmed_at` 등 컬럼 부재. "판매 잔금 직접 입력"·"매입 잔금 직접 입력"은 어떤 확정 게이트도 없이 즉시 ledger 반영. 이 상태에서 게이트 도입 시 `getSaleUnpaidAmountAttribute()` 분자(현재: `finalPayments->sum('amount')` 전량) 정의 흔들림. **SKILLS.md §13 단일 출처 5곳 정합성 동시 영향** + `progress_status_cache` / `sale_unpaid_amount_krw_cache` 갱신 트리거·기준 변경. 매입처 계좌 컬럼은 어떤 마이그레이션에도 미존재 — 신설 필요.

**미결 QA 핵심 쟁점 (HOLD 사유)**:

**안건 1 — 분모·분자 정의 (§13 단일 출처 파괴 리스크 최우선)**
- 현재 `getSaleUnpaidAmountAttribute()` 분자 = `finalPayments->sum('amount')` (전량, 확정 무관)
- 게이트 도입 시 선택지:
  - **A안**: confirmed만 차감 → "pending 잔금은 미수로 표시" — §13 5곳 전부 분자 변경 필요
  - **B안**: pending 포함 유지 → 분모·분자 무변경, 재무 확정은 UI 상태만 표시 (QA 권고)
  - C안: 두 값 병렬 → UI 혼란, 정합표 파괴
- **QA 권고: B안** — §13 단일 출처 파괴 비용이 A안 실무 이점보다 큼.

**안건 2 — progress_status 10단계**: A안 시 #6 판매완료 조건 변경. B안이면 무영향.
**안건 3 — 매입 게이트**: `getPurchaseUnpaidAmountAttribute()` 분자에 `confirmed_at` 필터 추가 필요(A안). VAT 9% / cost_total 무관.
**안건 4 — H4 paid Settlement**: B안이면 무영향. A안이면 paid 차량 미수율 재계산 결과 변동.
**안건 5 — FinalPayment ↔ ReceivableHistory 미러링**: 게이트 도입으로 미러링 트리거 연기. 19-F 2단계 분리 패턴 필요.
**안건 6 — G1 50% B/L**: A안이면 G1 기준 변경. B안이면 무관.
**안건 7 — 환율 KRW 환산**: QA 권고 입력 시점 환율 유지 (현행).

도메인 공식 영향: A안 시 §13 5곳 전부 / B안 시 분모·분자 무변경
회귀 시나리오: A안 60~80분 / B안 20~25분
Unit Test: B안이면 추가 1건 (`test_final_payment_confirmed_at_null_still_counted_in_sale_unpaid`). A안이면 4파일 재작성.
깨질 가능성 높은 테스트:
- A안: `DashboardActionCountsTest.php` / `InterVehicleTransferServiceTest.php` L180·212 / `InterVehicleTransferVoidTest.php` L111·141 / `AdminDashboardTest.php` L470·531·583
- **B안: 깨질 테스트 없음**

근거 파일/라인: `app/Models/Vehicle.php` L598~608, L612~620, L636~647, L405~413 + `app/Models/FinalPayment.php` L10, L29, L46~58 + `tests/Feature/*` 다수
운영 전 필수 여부: yes — 분모·분자 정의 결정 선행 없으면 구현 착수 불가. **HOLD**.

---

### 🔒 Security & Compliance
판정: 조건부 GO

발언: **계좌번호 개인정보 분류**: 매입처가 개인(차주 본인) 케이스 시 은행+계좌+예금주 조합은 개인정보보호법 제2조 제1호 "다른 정보와 쉽게 결합" 해당. 현재 `vehicles`에 `seller_bank_name`/`seller_account_number`/`seller_account_holder` 미존재 — 신설 시 `nice_reg_owner_rrn` 동일 `Crypt::encryptString()` + `seller_account_encrypted_at` 표식 패턴 **조건**. 법인 매입처도 구조 단일화 위해 동일 암호화 권고. **`AuditLog::MASKED_COLUMNS`에 `seller_account_number` 반드시 추가** (현재 `nice_reg_owner_rrn` 1개뿐).

**19-F SoD 패턴 일반화 가능성**: `canConfirmFinanceTransfer()`는 정산+admin/super, 관리 명시 차단으로 잘 분리됨. 큐 20 매입/판매 잔금 확정에도 동일 적용 → SoD 일관성. "영업이 입력한 잔금 row를 영업이 직접 확정"하는 경로 차단 핵심 — `FinalPayment::boot`의 `transfer_id` 가드 패턴 + `confirmed_by_user_id` 기록 + `canConfirmFinanceTransfer()` 체크 결합.

**audit_logs 영향**: `AuditLog::withApprovalRequest()` 패턴 두 actor 연결 구조 지원. `PurchaseBalancePayment` 모델에도 동일 패턴 적용 필요. 누락 시 회계 추적성 깨짐.

**영업의 pending UPDATE/DELETE 허용 범위**: 재무 확정 전 pending에서 영업 수정 허용 가능. confirmed 이후엔 `$allowTransferLinkedMutation` 플래그 패턴 그대로 복사해 append-only 강제.

근거 파일/라인: `app/Models/User.php` L110-117, L92-98 + `app/Models/AuditLog.php` L21-23, L46-55 + `app/Models/FinalPayment.php` L33-41 + `app/Models/InterVehicleTransfer.php` L49-59
개인정보·API키 영향: 매입처 계좌 = 개인정보 (개인 매입처) → 암호화 필수
감사로그 영향: `AuditLog::MASKED_COLUMNS`에 `seller_account_number` 추가 필수, `PurchaseBalancePayment` 확정 시점 `AuditLog::recordEvent()` 추가
운영 전 필수 여부: yes

---

### 🚀 Ops & Deploy
판정: 조건부 GO

발언: 현재 `inter_vehicle_transfers`에 이미 `2026_05_16_000001_add_finance_gate_to_inter_vehicle_transfers.php` 실재. 매입처 계좌 정보는 미존재 — 신규 마이그레이션 필요. `progress_status_cache`는 `confirmed_at` 기준 진행상태 변경 없는 한 rebuild 불필요. 3옵션 비교:
- 옵션 (i) ApprovalRequest 일반화: polymorphic 확장 + Service 리팩터링 → 공수 3~5일, 운영 부담 최대
- **옵션 (ii) 단순 컬럼 추가**: 19-F-A 패턴 그대로 적용, 각 테이블당 마이그 1개, ALTER TABLE 온라인 실행(MariaDB InnoDB), **다운타임 0초** (권장)
- 옵션 (iii) polymorphic 통합 테이블: 신규 테이블 1개 + morphs 인덱스, 다운타임 0초

다운타임: **옵션 (ii) 0초** (MariaDB InnoDB `ADD COLUMN NULL` 온라인 DDL)
백업 시점: DB `php artisan db:backup` (storage/backups/db/, mysqldump `--single-transaction`) / 파일 무관 / 코드 git tag `pre-queue20`
queue worker 영향: 무관
환경 의존성: 없음
테스트 실행 환경: Windows XAMPP PHP
스토리지 영향: 없음
근거 파일/라인: `database/migrations/2026_05_16_000001*` + `app/Console/Commands/BackupDatabase.php` + `app/Console/Commands/RebuildVehicleCaches.php` + `database/seeders/DatabaseSeeder.php` L36~52
운영 전 필수 여부: yes — `php artisan migrate --pretend` 사전 + `db:backup` 수동 + 옵션 (ii) 권장

---

### 🔧 Specialist [B. 데이터 무결성]
판정: 조건부 GO

발언: `FinalPayment` / `PurchaseBalancePayment` 양 테이블 모두 `pending/confirmed` 상태 컬럼 미존재. 게이트 도입 시 `finance_confirmed_at (nullable datetime)` 추가가 최소 변경.

**backfill 전략 — 옵션 α(전체 confirmed) 권장**. β(전체 pending)는 운영 중단 위험. γ(null = legacy)는 영구 부채. **α 선택 시 `UPDATE final_payments SET finance_confirmed_at = created_at WHERE transfer_id IS NULL` 명시**. transfer_id 행은 `InterVehicleTransfer.confirmed_at`으로 이미 추적.

**paid Settlement retroactive 위험**: `Settlement.confirmed_snapshot` `paid_at` 전환 시 캡처. 게이트 추가로 `finance_confirmed_at` 시점이 `payment_date`보다 늦어질 수 있어 gap 발생. α backfill로 gap 최소화. paid Settlement vehicle의 retroactive 변경은 별도 잠금 로직 불필요 (이미 snapshot 캡처).

**다중통화 환율 시점**: `sale_unpaid_amount_krw_cache`는 입력 시점 환율 고정. **환율 시점 이동 없이 confirmed 필터만 추가 안전**. confirmed 시점 환율 캡처 필요 시 `final_payment.confirmed_exchange_rate` 별도 컬럼 (audit 목적만, 계산 미사용).

**매입처 계좌 — B1 권장**: 시드 데이터 분석 결과 `purchase_from`은 비정형 텍스트(`경매 낙찰 / 개인 매입 / 법인 매입 / 현대직영`). 재사용 패턴 없음. **B1 vehicles 컬럼 3개**(`purchase_seller_bank` / `purchase_seller_account` / `purchase_seller_holder`) 충분. B2 마스터 테이블은 현 시점 과설계.

근거 파일/라인: `app/Models/FinalPayment.php` L10 + `app/Models/PurchaseBalancePayment.php` L10 + `app/Models/Settlement.php` L71~89 + `database/seeders/VehicleSeeder.php` L109~687
운영 전 필수 여부: yes

---

### 🔧 Specialist [E. 승인·권한 정책]
판정: 조건부 GO

발언: **19-F SoD 패턴 일반화 가능성 — 3단계로 분리 필요**. `canConfirmFinanceTransfer()`는 정확한 의미론을 이미 갖고 있지만 함수명이 Transfer 전용으로 읽혀 혼란 — **`canConfirmFinance(): bool` 범용 메서드로 alias 또는 rename 권장**.

**영업 입력 → pending → 재무 확정 워크플로우**: FinalPayment에 `finance_confirmed_at (nullable datetime)` 추가만으로 분기 가능. `transfer_id` 있는 행은 이미 `$allowTransferLinkedMutation` guard 있으므로 충돌 없음. 판매 잔금 + 매입 잔금 각각 동일 컬럼 추가.

**ApprovalRequest 일반화 여부 — "단순 confirmed_at 컬럼만" 권장**: 매입/판매 일반 잔금은 금액·건수 많아 ApprovalRequest 체계 붙이면 UI 복잡도 폭증. 재무가 마킹만 하는 단순 구조 충분. **action_type `FINANCE_CONFIRM_PAYMENT` 예약만, 미구현**.

**관리 role의 위치**: `canApprove()`는 관리=의사결정. 매입/판매 잔금 입력에는 의사결정 단계 불필요 — 영업의 잔금 입력 자체가 의사결정. 재무(정산 role)의 `finance_confirmed_at` 마킹이 실물 처리 확정. **관리 role이 `finance_confirmed_at` 마킹 경로 차단 필수**.

**self-confirm 차단**: 영업·정산 role이 달라 자동 분리. 별도 `requester_id ≠ confirmer_id` 체크 오버킬 — role 게이트만 충분.

**직접 실행 경로 차단**: FinalPayment::booted에 `saving` 훅 추가 — `finance_confirmed_at` 직접 SET 시 `canConfirmFinanceTransfer()` false면 DomainException. `$skipFinanceConfirmGuard` static flag로 서비스 레이어 우회.

근거 파일/라인: `app/Models/User.php` L110~117, L135~138 + `app/Models/FinalPayment.php` L25, L33~42 + `app/Services/InterVehicleTransferService.php` L160~163
운영 전 필수 여부: yes

---

### 🔧 Specialist [F. 회계·정산 감사]
판정: 조건부 GO

발언: **ledger 시점 정의 — 핵심 결정**: 현재 `FinalPayment.payment_date`가 ledger 시점. 게이트 후 `payment_date`(영업 입력) ≠ `finance_confirmed_at`(재무 확정) 이중 시점. **회계 장부 반영 = `finance_confirmed_at` 단일 출처 확정 권장**. `payment_date` = "이체 예상일"(영업 인식), `finance_confirmed_at` = "시스템 ledger 인정 시점". `sale_unpaid_amount` 계산에서 FinalPayment.sum은 confirmed 행만 포함하도록 필터 추가 필요 — `finalPayments()->whereNotNull('finance_confirmed_at')->sum('amount')`. **= A안 권고**.

**분모 정의 불변**: `sale_total_amount`는 게이트 무관. **분자(`sale_unpaid_amount`)만 confirmed 기준 필터로 변경**.

**paid Settlement 후 confirmed 입금 영향 — 가장 위험**: Settlement paid 전환 후 pending FinalPayment 뒤늦게 confirmed 전환 시 snapshot 고정. **처리 방법**: `confirmByFinance` 호출 시 H4 가드(`assertPaidSettlementGuard` 패턴) 동일하게 paid Settlement 존재 시 DomainException. pending → confirmed 전환 자체 차단.

**VAT 마진 / 정산 공식 영향 없음**: `vat_margin = purchase_price × 0.09` purchase_price 기준, 잔금 입금 무관. `sales_margin` 동일.

**회계 양립성**: `finance_confirmed_at` 도입 후 시스템 ledger = SSANCAR 실물 입금 1:1 대응. 회계감사 시 `final_payments WHERE finance_confirmed_at IS NOT NULL ORDER BY finance_confirmed_at`이 원장. `finance_note`(은행 거래번호)가 외부 증적 연결.

**retroactive 변경 차단**: 이미 paid Settlement vehicle의 pending → confirmed 차단. `finance_confirmed_at` SET 후 UPDATE/DELETE 불가 — `FinalPayment::updating` 훅.

근거 파일/라인: `app/Models/Settlement.php` L71~89, L26 + `app/Models/Vehicle.php` L598~609, L405~413 + `app/Services/InterVehicleTransferService.php` L361~368 + `app/Models/FinalPayment.php` L33~42
운영 전 필수 여부: yes

---

## 🧩 중간 회의 결과 (Opus 4.7 1차 취합)

### 🤝 부서 간 합의 영역 (10건)
1. 19-F SoD 패턴 재사용 (canConfirmFinanceTransfer → canConfirmFinance 범용화)
2. 옵션 (ii) 단순 컬럼 추가 (final_payments + purchase_balance_payments)
3. 별도 PaymentConfirmationService 신설 (Engineer 권고)
4. B1 vehicles 컬럼 3개 추가 (매입처 계좌)
5. 매입처 계좌번호 암호화 (Crypt::encryptString + MASKED_COLUMNS)
6. backfill α (기존 row `finance_confirmed_at = created_at`)
7. paid Settlement vehicle에서 pending → confirmed 전환 차단 (H4 유사 가드)
8. self-confirm 자동 차단 (영업/관리는 confirmed_at SET 불가, 정산만)
9. 다운타임 0초
10. 19-F-D 수동 회귀 먼저 완료

### ⚔️ 부서 간 충돌 영역 (4건) — 사외이사 의견도 분기

| 충돌 | A안 / 옵션 | B안 / 옵션 |
|---|---|---|
| **분자 정의** | Specialist[F] + Gemini: confirmed 필터 (ERP 정석) | QA + Codex: 분자 불변, UI 표시만 (§13 5곳 보존) |
| **우선순위** | Codex: 큐 20 먼저 | PO: 19-F-D 먼저 |
| **적용 순서** | Specialist[F] + Gemini: 전체 통합 | PO + Codex: 매입(A2) 단계화 |
| **구현 위치** | Specialist[E]: saving 훅 | Engineer + Codex + Gemini: 별도 Service |

---

## 🌐 사외이사 의견

### [Codex] (gpt-5.5)
1. **선택: B안.** `paid`는 현금/정산 확정 후만 반영, 그 전은 `pending/confirmed` 분리. A안처럼 sale_unpaid를 confirmed 기준으로 줄이면 장부-실입금 1:1 추적이 깨진다.
2. **업계 패턴**: SAP B1 — payment draft/approval 후 정식 문서화, NetSuite — `Pending Approval` 시 payment 처리 제한, Odoo — payment와 reconciliation 분리. "요청/승인/확정/정산" 상태 분리가 표준에 가깝다. (Sources: SAP Help, Oracle NetSuite Help, Odoo Docs)
3. **1순위**: 19-F-D가 아니라 큐 20 6개 합의 중 1~3번부터. SoD + finance_confirmed 메타 + PaymentConfirmationService 분리가 기반. 그 다음 B1 계좌 3필드 + 암호화 + backfill.
4. **PO vs Specialist[F]**: PO 우선. Finance는 확정 권한자이지 원천 데이터 입력자가 아니어야 한다.
5. **NO-GO 트리거**: (a) 자기확정 가능 (b) confirmed 없이 paid/settlement 반영 (c) ledger와 sale_unpaid_amount가 서로 다른 기준으로 움직임

### [Gemini]
1. **놓친 리스크**: '확정 후 수정(Lock) 부재'와 '이력 추적성'. 게이트 통과 후 원천 데이터가 수정되면 데이터 표류(Drift) 발생. 확정 시점 스냅샷 저장 또는 강력한 수정 제어 병행 필수.
2. **A vs B 판정**: **A안(분자 정의 변경)이 ERP 정석**. SAP/Odoo는 'Draft'/'Posted' 엄격 분리. B안처럼 UI만 가공하면 보고서 산출 쿼리 복잡도 폭증, API/외부 연동 시 정합성 깨지는 '그림자 회계' 영구 리스크.
3. **우선순위**: **전체 통합 적용 + 별도 Service** 추천. 1인 개발일수록 모델 훅에 로직 숨기면 부수 효과 추적 어려움. 명시적 `FinanceGateService` 횡단 관심사 SSOT 확보. 테스트 재작성 비용은 운영 안정성으로 상쇄.

### 사외이사 비교
- Codex: 보수 패키지 (B안 + 단계화 + 별도 Service)
- Gemini: 정석 패키지 (A안 + 전체 통합 + 별도 Service SSOT)
- 공통: **별도 Service 권고** (saving 훅 반대), self-confirm 차단

---

## 🚨 NO-GO 상세

### 🧪 QA HOLD — (a)(b)(c) 충족, 유효
- (a) 분모·분자 정의(A안/B안) 결정 선행 없으면 §13 5곳 정합 파괴 위험. progress_status #6·G1 50% B/L·채권 KPI 모두 영향.
- (b) 분모·분자 결정 + 5곳 정합표 재검증 + 깨질 테스트 4파일 영향 평가.
- (c) B안 권고 — 분자 공식 불변, finance_confirmed_at은 UI 표시만.

### Codex NO-GO 트리거 검증
- (a) 자기확정 → 합의 #8 self-confirm 차단으로 해소
- (b) confirmed 없이 paid 반영 → 합의 #7 paid Settlement 가드로 해소
- (c) ledger와 sale_unpaid 다른 기준 → **분자 정의 결정에 따라** (B안 시 = 두 기준 분리 의도, A안 시 = 단일 기준)

---

## 🎯 사용자 결정 — **4 안건 모두 확정 (2026-05-16 사용자 컨펌)**

| 결정 | 채택 | 출처 |
|---|---|---|
| 분자 정의 | **A안** — `finance_confirmed_at IS NOT NULL` 필터 | Specialist[F] + Gemini |
| 우선순위 | **19-F-D 먼저** → 큐 20 | PO |
| 적용 범위 | **전체 통합** (매입+판매 동시) | Specialist[F] + Gemini |
| 구현 위치 | **별도 Service** (`PaymentConfirmationService`) | Engineer + Codex + Gemini 3자 공통 |

→ **Gemini 정석 패키지 (P2) 채택, 단 19-F-D 선행은 PO 권장 채택**. 공수 12~18h + 19-F-D ~1h.

### 채택 근거
- A안: SAP/Odoo Draft/Posted 정석. ledger = `sale_unpaid` 단일 기준. 회계감사 단일 SoT. §13 5곳 분자 변경은 1회 비용, 운영 정합성으로 상쇄.
- 19-F-D 먼저: 19-F-A/B/C가 코드·자동 테스트만 완성. 실제 영업→관리→재무 브라우저 흐름 미검증 → 큐 20 패턴 확장 전 안전성 확보.
- 전체 통합: 매입 단계화는 두 번 마이그 + 두 번 정합 검증 비용. 1회에 매입+판매 동시 도입.
- 별도 Service: 3자 사외이사 + 부서 공통 권고. 부수 효과 추적 가능, 트랜잭션 경계 명시.

### 결정 시 참고한 두 패키지 — **P2 채택 (단 19-F-D 선행은 PO 권장)**

**패키지 P1 — 보수 (Codex 권장)**:
- B안 (분자 불변)
- 19-F-D 먼저
- 매입(A2) 먼저 단계화
- 별도 PaymentConfirmationService
- 공수 8~12h
- 회귀 25분, 깨질 테스트 0건
- 장점: §13 보존, 1인 개발 부담 ↓
- 단점: ledger vs sale_unpaid 기준 분리 (영업 시각 vs 회계 시각)

**패키지 P2 — 정석 (Gemini 권장)**:
- A안 (confirmed 필터)
- 큐 20 먼저 (또는 19-F-D 병행)
- 전체 통합 (매입+판매 동시)
- 별도 FinanceGateService SSOT
- 공수 12~18h
- 회귀 60~80분, 테스트 4파일 재작성
- 장점: SAP/Odoo Draft/Posted 정석, 회계감사 단일 SoT
- 단점: §13 5곳 전부 변경 + paid 후 snapshot lock 필요

---

## 🏁 최종 권고 (Opus 4.7) — **GO**

**판정**: **GO** (2026-05-16 사용자 4건 확정 후 격하)

**채택 패키지**: P2 정석 (A안 + 전체 통합 + 별도 Service) + PO 권장 19-F-D 선행.

**QA HOLD 해소**: 분자 정의 A안 확정으로 §13 5곳 정합 재설계 필수. 깨질 테스트 4파일 재작성 비용 수용.

**Codex NO-GO 트리거 잔여 (c) 해소 경로**: A안 채택 시 `ledger == sale_unpaid_amount` 단일 기준. 합의 #7·#8과 합쳐 (a)(b)(c) 모두 해소.

**Gemini 'Lock 부재' 지적 해소 경로**: 큐 20-D에서 `FinalPayment::updating` 훅 + `confirmed_at` SET 후 UPDATE/DELETE 차단 + paid Settlement snapshot lock 동시 도입.

### 구현 큐 분할 (20-A/B/C/D)

| 큐 | 작업 | 의존 |
|---|---|---|
| **19-F-D** | 자동 테스트 보강 + 수동 회귀 체크리스트 | 선행 (PO 권장) |
| **20-A** | 마이그 3건 + 모델 fillable·cast·MASKED_COLUMNS | 19-F-D |
| **20-B** | `PaymentConfirmationService` 신규 + `Vehicle::getSaleUnpaidAmountAttribute` / `getPurchaseUnpaidAmountAttribute` 분자 A안 필터 + `User::canConfirmFinance()` alias | 20-A |
| **20-C** | UI — `/erp/transfers` 매입·판매 잔금 확정 탭 또는 별도 페이지 + 차량 편집 패널 pending/confirmed row 색 분기 + 매입처 계좌 4컬럼 입력 + 사이드바 배지 합산 | 20-B |
| **20-D** | §13 5곳 정합 재검증 + 깨진 테스트 4파일 재작성 + paid Settlement snapshot lock + `FinalPayment::updating` 훅 + 신규 테스트(`PaymentConfirmationServiceTest` / `PurchaseAccountTest`) | 20-C |

**예상 공수**: 19-F-D ~1h + 20-A 2h + 20-B 4h + 20-C 4h + 20-D 4~5h = **총 15~16h**

---

## 🛠 car-erp 영향 분석 (결정 후 확정)

### 취약점 (Vulnerabilities) — 현재 상태
1. **매입처 계좌번호 ERP 미관리** — 영업이 외부 메모 의존, 운영 사고 risk
2. **재무 확정 게이트 부재 (전 흐름)** — 영업 입력 = 즉시 ledger, 회계 무결성 결손
3. **paid Settlement vehicle에 pending 입금 retroactive 변경 가능** — H4 가드 부재 (잔금 측에)
4. **AuditLog::MASKED_COLUMNS에 매입처 계좌 미등록** — 신설 시 함께 등록 필요
5. **G1 50% B/L 잠금 미구현** — 큐 9 확장 안건

### 보완사항 (Improvements) — A안/B안 공통
1. `canConfirmFinanceTransfer()` → `canConfirmFinance()` 범용 alias 추가
2. /erp/transfers 페이지에 매입·판매 잔금 확정 탭 추가 (또는 별도 페이지)
3. 차량 편집 패널 매입·판매 탭에 pending/confirmed 상태 시각화 (row 색상 분기)
4. 사이드바 배지 합산 (자금이체 + 매입 잔금 + 판매 잔금 + void)

### 코드 수정 (Code Changes) — **P2 (A안) 채택 확정**

| 파일 | 변경 |
|---|---|
| `app/Models/FinalPayment.php` | `confirmed_by_user_id` / `confirmed_at` / `finance_note` fillable + cast + `financeConfirmer()` 관계 + `updating` 훅 (confirmed 후 수정 차단) |
| `app/Models/PurchaseBalancePayment.php` | 동일 |
| `app/Models/Vehicle.php` | 매입처 계좌 4컬럼 fillable (`purchase_seller_bank` / `purchase_seller_account` encrypted / `purchase_seller_holder` / `purchase_bank_memo`) |
| `app/Models/Vehicle.php::getSaleUnpaidAmountAttribute()` | **A안 핵심** — `finalPayments()->whereNotNull('confirmed_at')->sum('amount')` 필터 |
| `app/Models/Vehicle.php::getPurchaseUnpaidAmountAttribute()` | 동일 — confirmed 필터 |
| `app/Models/Vehicle.php::refreshCaches()` | confirmed 기반 `sale_unpaid_amount_krw_cache` 재계산 |
| `app/Models/User.php` | `canConfirmFinance()` 범용 alias 추가 (기존 `canConfirmFinanceTransfer` 유지) |
| `app/Models/AuditLog.php` | `MASKED_COLUMNS`에 `purchase_seller_account` 추가 + `PurchaseBalancePayment` 확정 시점 `recordEvent` |
| `app/Services/PaymentConfirmationService.php` | **신규** — `confirmPayment(FinalPayment, financeUser, ?note)` / `confirmPurchasePayment(PBP, financeUser, ?note)` + DB::transaction + self-confirm 차단 + paid Settlement H4 가드 |
| `app/Models/Settlement.php` | paid 전환 시 confirmed_snapshot에 잔금 confirmed 상태 추가 캡처 (Gemini Lock 지적) |
| `tests/Feature/DashboardActionCountsTest.php` | 분자 A안 기준 재작성 |
| `tests/Feature/AdminDashboardTest.php` | `sale_unpaid_amount_krw_cache` 검증 재작성 |
| `tests/Feature/InterVehicleTransferServiceTest.php` | confirmed 필터 영향 라인 재작성 |
| `tests/Feature/InterVehicleTransferVoidTest.php` | 동일 |

### 신규 추가 (New Additions) — 결정 후 확정
- 마이그레이션 3건 (final_payments / purchase_balance_payments / vehicles 매입처 계좌)
- `app/Services/PaymentConfirmationService.php`
- 테스트: PaymentConfirmationServiceTest / PurchaseAccountTest
- /erp/transfers 페이지 탭 추가 또는 별도 페이지

### 모순·NO-GO 처리 로그 (2026-05-16 결정 후)
- QA HOLD: (a)(b)(c) 충족 → A안 채택으로 §13 5곳 재설계 비용 수용, **조건부 GO 격하 완료**
- Codex NO-GO 트리거: 합의 #7·#8로 (a)(b) 해소, **(c) A안 채택으로 ledger == sale_unpaid 단일 기준 → 해소**
- Gemini "Lock 부재" 지적: 큐 20-D에서 `FinalPayment::updating` 훅 + paid Settlement snapshot lock 동시 도입 → **구현 시 해소 예정**
- Codex 보수 권장(B안 / 단계화) vs Gemini 정석 권장(A안 / 통합) → 사용자가 Gemini 정석 P2 채택, 단 우선순위만 PO 권장(19-F-D 선행) 채택

---

## 🔗 참조
- 19-F 회의록: `docs/meetings/2026-05-16-finance-gate-roundtable.md`
- 19-F 구현: `app/Services/InterVehicleTransferService.php` + `app/Models/InterVehicleTransfer.php` + `resources/views/livewire/erp/transfers/index.blade.php`
- 사전 컨텍스트: `C:/Users/User/.claude/projects/C--xampp-htdocs-car-erp/memory/project_19f_finance_gate.md`
- 회의록 v5.1 §G1 50% B/L 잠금 / §G4 알림톡
- `CLAUDE.md` 권한 시스템 (3단계 + role)
- `SKILLS.md` §13 미수율 분모 단일 출처
- `decision_protocol.md` §6 마이그레이션·권한·정산공식 행
- 관련 코드:
  - `app/Models/Vehicle.php` L598~647 (sale/purchase unpaid 분자·분모)
  - `app/Models/FinalPayment.php` L10·L29·L33~42·L46~58
  - `app/Models/PurchaseBalancePayment.php` L10
  - `app/Models/User.php` L92~117·L135~138
  - `app/Models/Settlement.php` L71~89
  - `app/Models/AuditLog.php` L21~23·L46~55
  - `app/Services/InterVehicleTransferService.php` L160~163·L324~368

---

## 📋 부록 A — 19-F-D 수동 회귀 체크리스트

> 큐 20 착수 전 사용자가 브라우저에서 직접 5상태 머신·SoD·UI 분기를 클릭 흐름으로 검증.
> 자동 테스트(215 passed)와 별개로 운영 감각 확보용.
> 작업 환경: `php artisan serve --port=8001` 실행 후 `http://127.0.0.1:8001`.

### A. 사전 준비
- [ ] 테스트 계정 3개 확인 (DB seed 또는 수동 생성)
  - 영업: `permission=user`, `role=영업`
  - 관리: `permission=user`, `role=관리`
  - 재무: `permission=user`, `role=정산`
- [ ] 동일 바이어 차량 2대 시드. source는 sale_price 1억 KRW + 5천만 입금된 상태(미수율 50% 정확히), target은 sale_price 8천만 KRW + 입금 없음
- [ ] source/target 둘 다 paid Settlement 없는지 확인 (`/erp/settlements`)

### B. 5상태 머신 정상 흐름 (영업→관리→재무)
- [ ] **영업 로그인** → `/erp/vehicles` source 차량 편집 → 판매 탭 → "자금 이체 요청" 모달 → target 차량 선택 + 금액 2500만 입력 + 사유 입력 → 제출 → 토스트 "요청 완료"
- [ ] source 편집 패널 헤더에 violet 박스 "이체 요청 중" 표시
- [ ] **관리 로그인** → `/erp/approvals` → 요청 1건 visible → "승인" 클릭 → 모달에서 결정 사유 입력 → 승인
- [ ] `/erp/approvals` 동일 행 status 컬럼: **"관리 승인 (재무 처리 대기)"** (blue 라벨)
- [ ] **재무 로그인** → 사이드바에 "재무 처리" 메뉴 + amber 배지 "1" → `/erp/transfers` 진입 → 1건 표시 → "재무 처리 완료" 모달 → finance_note 입력(예: 시중은행 거래번호) → 처리
- [ ] `/erp/transfers` 행 사라짐(또는 executed 필터에서만 표시)
- [ ] **영업 다시 로그인** → source 편집 → 잔금 row 2건 추가됨 (-2500만 source / +2500만 target)
- [ ] source 편집 패널 헤더: 에메랄드 박스 "이체 완료 (재무 확정 정보)" + confirmer 이름 + confirmed_at + finance_note 표시
- [ ] source 미수율: 50% → 75% (즉 미수금 5천만 → 7500만)
- [ ] target 미수율: 100% → 68.75% (8천만 → 5500만)

### C. SoD 차단 (관리=재무 같은 user_id)
- [ ] **관리 계정 1명만** 사용해 새 이체 요청 → 관리 본인이 `/erp/approvals` 승인 → `/erp/transfers` 진입 → 행에 "**SoD 차단**" 라벨, "재무 처리 완료" 버튼 **비활성**
- [ ] 비활성 버튼 클릭 시도 → 모달 안 열림 + 토스트 안내 "관리 승인자와 재무 확정자는 다른 사용자여야 합니다 (SoD)"
- [ ] (옵션) 모달 우회 시도 (개발자 도구로 Livewire 직접 호출) → DomainException 토스트
- [ ] 다른 재무 계정 로그인 → 같은 행 정상 처리 가능

### D. 권한 가드 (페이지 접근)
- [ ] `/erp/transfers`: 영업 로그인 → 403 또는 리다이렉트
- [ ] `/erp/transfers`: 관리 로그인 → 403 (canConfirmFinanceTransfer가 명시 차단)
- [ ] `/erp/transfers`: 재무 로그인 → 200 ✓
- [ ] `/erp/transfers`: admin 로그인 → 200 ✓
- [ ] `/erp/transfers`: super 로그인 → 200 ✓
- [ ] 사이드바 "재무 처리" 메뉴 노출: 재무·admin·super만 보임

### E. void 흐름
- [ ] executed 상태 transfer 1건 준비 (B 흐름 결과)
- [ ] **영업 로그인** → source 편집 → 잔금 row에서 해당 transfer 잔금 클릭 → "이체 취소 요청" → 사유 입력 → 제출
- [ ] **모달 닫힘 즉시** violet 🔁 박스 → amber ⏳ "취소 요청 중" 라벨로 색 전환 (페이지 새로고침 없이) — 큐 19-E 버그 fix 검증
- [ ] **관리 로그인** → `/erp/approvals` → 이체 취소 요청 1건 → 승인
- [ ] `/erp/approvals` 동일 행 status: **"취소 승인 (재무 대기)"** (amber 라벨)
- [ ] **재무 로그인** → `/erp/transfers` 진입 → voided_awaiting_finance 행 표시 → "재무 처리 완료" → 처리
- [ ] **영업 재로그인** → source 편집 → 잔금 row 추가 2건 더 생성 (+2500만 source / -2500만 target = 역 페어)
- [ ] source 편집 패널 헤더: 회색 박스 "이체 취소 완료"
- [ ] 최종 미수율 확인: source 50% / target 100% (이체 전 상태 복귀)

### F. UI 5상태 색 분기 (편집 패널 헤더 lastDecided 박스)
| 상태 | 색 | 라벨 |
|---|---|---|
| pending | violet | "이체 요청 중" |
| approved_awaiting_finance | blue | "관리 승인 — 재무 대기" |
| executed | emerald | "이체 완료" (재무 확정 정보 표시) |
| voided_awaiting_finance | amber | "취소 승인 — 재무 대기" |
| voided | gray | "이체 취소 완료" |
| rejected | red | "거부됨" + 거부 사유 |
| cancelled | gray | "취소됨" |

- [ ] 위 7개 상태 박스 색·라벨이 코드 시나리오 진행하면서 정확히 분기되는지 확인

### G. 사이드바 / 승인 페이지 배지 합산
- [ ] `/erp/transfers` 대기 건수 = `/erp/approvals` 대기 건수가 아님 — transfers는 재무 대기 = approved_awaiting_finance + voided_awaiting_finance 합산. approvals는 관리 대기 = pending. 헷갈리지 않게 두 페이지 모두 방문.
- [ ] 재무 로그인 시 사이드바 "재무 처리" 배지: approved_awaiting_finance + voided_awaiting_finance 합산 카운트
- [ ] 모바일(<768px) drawer에서 "재무 처리" 메뉴도 동일하게 보이는지 (사이드바 모바일 분기)

### H. 추가 회귀 (선택)
- [ ] `npm run build` 후 production 자산으로 동일 흐름 1회 (`php artisan view:clear`)
- [ ] AuditLog `recordEvent` 확인: 각 5상태 전이마다 audit_logs row 생성됐는지 (`/admin/audit-logs`)
- [ ] paid Settlement 있는 차량에서 이체 요청 시도 → 차단 토스트 (H4 가드)

### 통과 기준
모든 ✅이면 큐 20-A 마이그레이션 착수 가능. 실패 발견 시 회의록 본문 `## 🛠 car-erp 영향 분석`에 발견 항목 기록 후 fix → 재 회귀.

### 자동 테스트 보강 항목 (큐 19-F-D 완료)
- `InterVehicleTransferServiceTest::test_e2e_5_state_lifecycle_creates_four_final_payments_and_preserves_metadata`
- `InterVehicleTransferServiceTest::test_confirm_by_finance_blocks_executed_status`
- `InterVehicleTransferServiceTest::test_confirm_by_finance_blocks_voided_awaiting_finance_status`
- `InterVehicleTransferServiceTest::test_confirm_void_by_finance_blocks_executed_status_without_approve_void`

총 215 passed (211 + 4).
