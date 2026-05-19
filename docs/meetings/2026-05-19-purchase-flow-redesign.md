# 📅 회의록: 매입 흐름 재설계 (큐 22-C 범위 확장)

- 일시: 2026-05-19
- 강도: 풀회의 (/회의 슬래시)
- 안건 유형: 권한·role 모델 변경 + 마이그레이션 + 개인정보 노출 변경
- 자동발동 여부: yes (사용자 명령어 호출)
- 발동 부서: PO + Engineer + QA + Security + Ops + Specialist [E 승인·권한 정책 + F 회계·정산 감사]

## 안건

사용자 명세 (새회의.txt 6번 후속 + 사용자 추가 요청):
> "매입탭에서 영업이 매입가를 지정하고 계좌정보를 입력하면 이것의 정보가 매입잔금으로 넘어가게되고, 이걸 보고 재무가 돈 보내고 매입탭에서 매입지금란에 내가 얼마를 보냈다, 매입건은 완료되었다 하는 씬으로 해야할것같아."

**변경 요청 4건**:
1. 매입처 계좌 입력자: 재무 → 영업 (큐 20-C 결정 번복)
2. 매입 잔금 자동 생성 (`purchase_balance_payments` 1건 auto-INSERT)
3. 송금액 입력 UI: 재무가 차량 [매입] 탭 직접 입력 vs `/erp/transfers` 통합
4. 매입건 완료 자동 트리거 (미지급=0 도달 시)

알림톡(G4·별건 1)은 사용자 명시 분리 — 본 회의 제외.

---

## 💬 부서별 발언 (Sonnet 4.6)

### 📋 PO
**판정**: 조건부 GO (변경 1·4 GO — 이미 구현 / 변경 2 HOLD / 변경 3 NO-GO → transfers 통합 UI 재해석 시 GO)

**발언**: 코드 검증 결과 `purchase_seller_bank/account/holder/purchase_bank_memo` 4컬럼은 `vehicles/index.blade.php` [매입] 탭 L2608~2630에 **이미 영업 입력 구조로 존재** — workflow-checklist B-1 L117 "재무 입력" 기술은 stale. 사용자 요청과 코드 현실 일치. `Vehicle::progress_status` L869 `purchase_price > 0 AND purchase_unpaid_amount <= 0` → '매입완료' 자동 전환도 이미 구현. **신규 코드 작업 불필요** (변경 1·4).

변경 2 (자동 잔금): 매입가가 곧 단일 잔금이 아니라 down_payment + N건 분할 구조. "매입가 저장 시 자동 1건 INSERT"는 분할 시나리오 충돌 위험 + 큐 22-C type enum 마이그와 동시 변경 시 롤백 난이도 ↑. **HOLD — 큐 22-C 설계 완료 후 + 운영 분할 지급 패턴 확인 후 판단**.

변경 3: 차량 [매입] 탭 직접 입력은 해석 B 정면 충돌 — `PurchaseBalancePayment::updating` 잠금 훅이 transfers `canConfirmFinance` 가드와 결합된 SoD 구조 붕괴. **`/erp/transfers` 매입 잔금 탭에 "입력+확정 통합 UI"로 재해석 시 큐 22-C 범위 내 조건부 GO 전환 가능** (+2~3h).

근거: `vehicles/index.blade.php` L106~110·L2608~2630, `Vehicle::getProgressStatusAttribute` L811~813, `docs/workflow-checklist.md` L117

---

### ⚙️ Engineer
**판정**: 조건부 GO (변경 1·4 즉시 / 변경 2·3 구조 조건 충족 필요)

**발언**:
- 변경 1: `vehicles/index.blade.php` L2617~2632 매입처 계좌 4컬럼 이미 영업 입력 구조 + 권한 가드 없음. 큐 20-C "재무 입력" 결정은 코드 미구현 — 코드 정답, 회의록 정정 필요. `canAccessSettlement()` 가드 추가 여부만 결정.
- 변경 2 (자동 PBP 생성): `Vehicle::saving` → `PBP::create()` → `PBP::saved → vehicle->refreshCaches()` 흐름. `refreshCaches()`는 `DB::table()->update()` 우회 패턴(`FinalPayment` 동일) → **무한루프 없음**. 단 매입가 변경 시 PBP 재생성 정책 결정 필요. `confirmed_at SET` 후 mutation 차단(L32~38) → 재생성 불가.
- 변경 3: 매입 탭 read-only 격하 + `/erp/transfers` 입력 UI 신설은 큐 22-C 기존 범위 내. 별도 추가 아님.
- 변경 4: `Vehicle.php` L811~813 + `refreshCaches()` L640~646 이미 구현. **추가 작업 0**.

**공수 추정**: 큐 22-C 기존 7~9h + 변경 1 가드 결정 0.5h + 변경 2 자동생성+정책 2~3h + 변경 3 기존 범위 포함 + 변경 4 0h = **9~13h**

영향 파일: `app/Models/Vehicle.php`, `app/Models/PurchaseBalancePayment.php`, `resources/views/livewire/erp/vehicles/index.blade.php` L2608~2693, `resources/views/livewire/erp/transfers/index.blade.php` L125~134, `app/Services/PaymentConfirmationService.php`

권한 가드 위치: 모델 layer(creating 훅) + Volt save() layer Defense-in-depth
캐시 rebuild 필요: yes — 변경 2 배포 후 `php artisan vehicles:rebuild-progress-cache` 1회

---

### 🧪 QA & Domain Integrity
**판정**: 조건부 GO

**발언**: 변경 2 (자동 PBP)의 `amount`·`payment_date`·`confirmed_at` 3-tuple 정책에 따라 SKILLS §13 단일 출처 위반 여부가 결정됨. **유일하게 안전한 선택은 `amount=전액·payment_date=NULL·confirmed_at=NULL` (Draft form)**. 동시에 `scopeAction('purchase_unpaid')` SQL(L986~990)에 `AND confirmed_at IS NOT NULL` 추가 필수 — 현재 `getPurchaseUnpaidAmountAttribute` L852~860 분자 A안 필터와 **비대칭** 발견 (큐 22-C 패치에서 함께 수정 의무).

| amount | payment_date | confirmed_at | SQL scope | Accessor | 결과 |
|---|---|---|---|---|---|
| 전액 | today | NULL | 미지급 0 표시 | 분자 양수 | **불일치 — §13 위반** |
| 전액 | NULL | NULL | 제외 | 제외 | **Draft 안전** |
| 0 | NULL | NULL | 무의미 | — | — |

변경 4: `Vehicle.php` L812 + `PurchaseBalancePayment::saved` L27 + `refreshCaches()` L640~646 → 이미 구현. **사용자가 "별도 이벤트 발행 또는 알림톡 트리거"를 의도했는지 PO 재확인 필요** — 만약 단순 동작 재확인이면 신규 코드 0.

**도메인 공식 영향**: `purchase_unpaid_amount` 분자 A안, `progress_status_cache` 10단계 (변경 없음), VAT 9% 영향 없음.

**깨질 자동 테스트**:
- `DashboardActionCountsTest.php` L43~49 — 자동 PBP `payment_date != NULL` 정책 선택 시 즉시 깨짐
- `PaymentConfirmationServiceTest.php` L157~180 — 자동 PBP 픽스처 이중 PBP 충돌

**신규 필요 테스트 4건**: Draft PBP SQL 제외 / 매입처 계좌 영업 입력 audit_logs 기록 / canConfirmFinance 우회 차단 / Draft form retroactive 검증.

**회귀 시나리오**: 수동 15~20분 (큐 22-A·B 통합 회귀 포함 권장)

---

### 🔒 Security & Compliance
**판정**: **NO-GO** (변경 1·3 차단) → 4 해소조건 충족 시 조건부 GO 전환

**3 공격 시나리오**:
- **시나리오 A 자가 계좌 대체 횡령**: 영업이 매입처 계좌를 본인 계좌로 변경 → 재무가 ERP 화면 그대로 복사해 송금 → 횡령. 재무 측 "입력자 검증" 부재
- **시나리오 B AuditLog 우회**: `AUDITED_COLUMNS` L381~397에 `purchase_seller_bank`·`purchase_seller_account`·`purchase_seller_holder`·`purchase_bank_memo` 4컬럼 **미포함** → 변경 추적 불가. 마이그(2026_05_17_120003) 주석은 `MASKED_COLUMNS` 등록만 지시, `AUDITED_COLUMNS`는 별건
- **시나리오 C 자가 잔금 조작 + 계좌 변경 연동**: 차량 [매입] 탭에 자금 영역 영업 접근 가능 + role guard 부재(`canAccessSettlement` 분기 grep 0건)

**NO-GO (a)(b)(c)**:
- (a) 차단: 시나리오 A·B·C 3경로 동시 노출. 해석 B 정면 위반
- (b) 최소조건 4건:
  1. `purchase_seller_bank/account/holder/bank_memo` 4컬럼 `AUDITED_COLUMNS` 추가
  2. 매입처 계좌 입력 권한 `canAccessSettlement()` 한정 (UI + save() 이중 잠금) **또는** 영업 입력 유지 + audit 완비
  3. 매입 탭 자금 영역 read-only 격하 (해석 B 이행)
  4. 위 3건 구현 후에만 큐 22-C 착수
- (c) 대안: 매입처 계좌 입력을 `/erp/transfers` 매입 탭에 통합. 차량 [매입] 탭에는 read-only 표시만 — FINANCIAL_FIELD_MAP silent-restore 패턴 재활용

**개인정보 영향**: `purchase_seller_account` (계좌번호 = 개인정보보호법 §2 제1호 금융정보). 영업 role 입력 권한 시 최소 권한 원칙 위반. 3컬럼 조합 시 완전한 금융정보.

운영 전 필수: **yes**

---

### 🚀 Ops & Deploy
**판정**: 조건부 GO

**발언**:
- 변경 1·4: 코드 이미 배포됨 — **운영 영향 0, 무중단**
- 큐 22-C 마이그 2건: ① `purchase_balance_payments.type` ENUM 추가 — InnoDB INSTANT 0초 ② `vehicles.down_payment` / `selling_fee_payment` 2컬럼 DROP — INSTANT 불가, 운영 점검창 권장 (< 1초)
- 큐 22-A → 22-B → 22-C 한 번에 배포 가능 (서로 다른 테이블 — 충돌 없음). 22-C는 2단계 분리(INSERT 검증 → DROP 별도 파일) 권장
- `vehicles:rebuild-caches` 1회 필수 (`purchase_unpaid_amount` 분자 재계산)
- `queue:restart` 마이그 후 1회 (직렬화 안전)
- **Python ERP 미실재** (메모리 일치) — 충돌 가정 stale

**다운타임**: 22-C ENUM 0초 / 컬럼 DROP < 1초 / 변경 1·4 0초
**백업**: mysqldump 필수 (vehicles 컬럼 DROP 롤백 불가)

---

### 🔧 Specialist [E. 승인·권한 정책]
**판정**: 조건부 GO (변경 1·4) / HOLD (변경 2·3 — 4 해소조건 충족 시 GO 전환)

**발언**:

**변경 1**: 큐 20-C 회의록 L348 "매입처 계좌 4컬럼 입력" 위치 기술이 **차량 편집 패널**에 명시되어 있음 + 2026-05-19 해석 B 매핑표 권한 분리에 매입처 계좌는 재무 영역에 없음 + 글로벌 ERP SoD = 계좌정보는 거래 가격·계약 일부(영업), 송금 집행은 재무. **큐 20-C 회의록의 "재무 입력"은 "재무 처리 UI 탭에 노출"의 의미였을 가능성. 코드 정답, 회의록 정정 필요** (Spec-E 발견).

**변경 2**: `PurchaseBalancePayment.fillable`에 `created_by_user_id` 컬럼 부재 — 자동 생성 row의 actor 추적 불가. **`created_by_user_id (nullable FK → users)` 컬럼 마이그 추가 + 자동 생성 시 null(시스템) or 현재 auth user id 기록 정책 명시 후 GO**.

**변경 3**: 영업이 PBP row 추가·삭제 가능한 현재 구조를 **read-only 격하**가 핵심. Defense-in-depth:
- **모델 레이어(권장)**: `PurchaseBalancePayment::creating` 훅에 `canConfirmFinance()` 체크 → UI 우회·Livewire 직접 호출 시 DomainException
- **Volt save() 레이어**: `savePurchaseBalancePayments()` 진입 시 `canConfirmFinance()` 확인 → 403 abort
- **둘 다 적용**: 모델 훅 완전 차단선 + Volt save() UI 사용자 경험

**변경 4**: `progress_status_cache` 자동 갱신 + `AUDITED_COLUMNS`에 포함 → audit_logs 기록 이미 동작. 자동 트리거 구별을 위해 `AuditLog::recordEvent()` metadata에 `"trigger": "auto"` 키 추가 권장.

**self-confirm 차단 재확인**: `canConfirmFinance()` = 정산 + admin/super (관리 role 차단). role 게이트만으로 충분.

**HOLD 해소 조건 4건**:
1. `created_by_user_id` 컬럼 추가 마이그 (변경 2)
2. `PurchaseBalancePayment::creating` 훅 + Volt save() 이중 가드 (변경 3)
3. `/erp/transfers`에 매입 잔금 입력+확정 통합 UI 신설 (변경 3 순서 의존성)
4. `AUDITED_COLUMNS`에 계좌 4컬럼 추가 — `purchase_seller_account`는 마스킹 처리

---

### 🔧 Specialist [F. 회계·정산 감사]
**판정**: 조건부 GO

**발언**:

**자동 잔금 amount 정책 (변경 2)**: `purchase_price + selling_fee` 전액 자동 채움은 **회계 감사 관점 위험** — Draft 행에서 UI상 "잔금 입력 완료"로 보여 영업이 송금 요청 생략하는 운영 혼선. 분할 잔금 시나리오(계약금 별도, 잔금 여러 번)와 충돌. paid Settlement 이후 자동 생성 시 `assertPaidSettlementGuard`(L100~104) 차단으로 retroactive 차단되지만 UX 파손.

**회계 감사 권고: amount=0 또는 NULL 생성, UI에서 영업이 직접 입력·저장 후 재무 확정 흐름**.

**변경 4 의도 확인**: `Vehicle.php` L852~860 분자 A안 (`confirmed_at IS NOT NULL`) — "재무 확정 후에만 매입완료" 동작은 **큐 20 P2 정석(SAP Draft/Posted) 의도된 흐름**. 변경 불필요.

**paid Settlement snapshot 완전성**: `Settlement.php` L76~110 `confirmed_purchase_payments` 캡처 완비 (큐 20-D Gemini Lock). 자동 생성 row가 `confirmed_at NULL` 상태면 snapshot 대상 자동 제외 — **정합**.

**`PurchaseBalancePayment::creating` 훅 필요성**: `FinalPayment.php` L46~69 `updating/deleting` 잠금은 `PurchaseBalancePayment.php` L31~43에 동일 구현. **그러나 `creating` 훅(paid Settlement 후 신규 차단)은 PBP에 부재** — `PaymentConfirmationService::assertPaidSettlementGuard`가 Service 레이어 차단하지만 영업이 paid 차량에 새 PBP INSERT 시도하는 경우는 미차단. **운영 전 `PurchaseBalancePayment::creating` 훅 추가 권고**.

**VAT 9% 공식**: `Settlement.php` L160~162 `purchase_price × 0.09` 그대로. 영향 없음.

**조건부 GO 조건 3건**:
1. **자동 잔금 `amount=0` 또는 NULL**. 전액 자동 채움 금지 (사외이사 의견과 충돌 — 사외이사 검토 후 결정)
2. `PurchaseBalancePayment::creating` 훅 추가 (paid Settlement 차단)
3. 자동 생성 row 명시적 Draft 라벨 UI 노출 — `confirmed_at NULL` 행은 "재무 미확정" 표시

---

## 🧩 중간 회의 결과 (Opus 4.7 1차 취합)

### 부서별 판정 매트릭스

| 변경 | 📋 PO | ⚙️ Engineer | 🧪 QA | 🔒 Security | 🚀 Ops | 🔧 E 권한 | 🔧 F 회계 |
|---|---|---|---|---|---|---|---|
| **#1** 매입처 계좌 영업 | GO (이미 구현) | GO+가드 | 조건부 (AUDITED) | NO-GO | GO (0초) | GO (회의록 정정) | (영향 없음) |
| **#2** 자동 잔금 생성 | HOLD | 조건부 | 조건부 (Draft) | 조건부 (audit) | 무관 | HOLD (created_by) | 조건부 (amount=0) |
| **#3** 송금 UI 매입 탭 직접 | NO-GO | 조건부 (transfers) | 조건부 | NO-GO | 무관 | HOLD (creating 훅) | (영향 없음) |
| **#4** 매입완료 자동 트리거 | GO (이미 구현) | GO (이미 구현) | HOLD (의도 재확인) | 조건부 (metadata) | 무관 | GO | GO (의도된 흐름) |

### 합의된 GO 조건 (다수 합의)
1. **큐 20-C 회의록 정정** — 매입처 계좌 입력자 "재무 → 영업" (Spec-E 발견)
2. **`AUDITED_COLUMNS` 계좌 4컬럼 추가** (Security·QA·Spec-E)
3. **자동 잔금 Draft form** — `amount` 정책은 부서 충돌 → 사외이사 시각 필요
4. **`scopeAction('purchase_unpaid')` SQL `confirmed_at IS NOT NULL` 추가** (QA 기존 결함 발견)
5. **`PurchaseBalancePayment::creating` 훅 신설** — paid 차단 + canConfirmFinance Defense-in-depth (Spec-E + Spec-F)
6. **차량 [매입] 탭 자금 영역 read-only 격하 + `/erp/transfers` 입력+확정 통합 UI**
7. **`purchase_balance_payments.created_by_user_id` 컬럼 추가** (Spec-E)

### 부서 간 충돌 영역
1. **자동 잔금 amount 기본값**: Spec-F amount=0 vs QA amount=전액 Draft → 사외이사 시각 필요
2. **변경 4 의도**: 이미 구현 vs 추가 이벤트 발행 → 사용자 확인 필요
3. **매입 잔금 자동 생성 트리거 조건**: 매입가 변경 시마다 vs 최초만 → 정책 미명시

### 안건의 본질 3줄
- 사용자가 인식한 워크플로우 변경 = 코드 1·4는 이미 그렇게 동작. 큐 20-C 회의록·workflow-checklist 문서가 stale
- 신규 핵심 작업: 자동 잔금 생성 정책 결정 + AUDITED_COLUMNS 확장 + 매입 탭 자금 영역 read-only 격하 + transfers 입력+확정 통합 UI
- 남은 불확실성: 자동 잔금 amount 정책(0 vs 전액), 매입가 변경 시 PBP 재생성 정책

---

## 🌐 사외이사 의견 (Codex / Gemini)

### [Codex]

**외부 판단: QA안 채택**. `amount=full price`, `payment_date=NULL`, `confirmed_at=NULL` **Draft가 ERP 표준에 가깝다**. SAP는 park/hold 후 post, NetSuite는 Pending Approval bill 결제 차단, Odoo는 draft/posted 후 payment register, QuickBooks도 bill 입력 후 unpaid/paid 분리. `amount=0`은 UI 혼란은 줄여도 AP 미지급 SQL·aging·검증 의미를 깨기 쉽다.

출처: [SAP Park/Hold](https://learning.sap.com/courses/explaining-payables-invoice-processing/managing-invoice-holding-and-parking_a6556206-6ef1-48bb-bcb8-bdb5d742f88e), [NetSuite Pending Approval](https://docs.oracle.com/en/cloud/saas/netsuite/ns-online-help/section_N2373552.html), [Odoo Vendor Bills](https://www.odoo.com/documentation/16.0/applications/finance/accounting/vendor_bills.html), [QuickBooks Bills](https://quickbooks.intuit.com/learn-support/en-ca/help-article/pay-bills/enter-bills-record-bill-payments-quickbooks-online/L1e9Ce5J7_CA_en_CA)

**놓친 리스크**: ① Sales 입력 전환 후 문서 불일치로 현장 역행 ② 자동 row 중복 생성/수정 권한 불명확 ③ direct+transfer 통합 UI에서 승인 전 금액이 지급처럼 보임

**우선순위 (1인/1개월)**: A/B/C NO-GO 먼저 → unpaid SQL 패치 + 자동 Draft row → 마지막 UI 통합. **#1 문서 정정, #2 최소 구현, #4 확인, #3은 범위 축소**.

### [Gemini]

**1. 놓친 핵심 리스크**:
- **직무 분리(SoD)의 붕괴**: 영업이 매입가 결정과 입금 계좌 입력을 동시 수행 = "횡령의 고속도로". 재무의 '계좌 검증' 단계 생략한 권한 환원은 내부 통제 측면 치명적 결함
- **후행 비용 처리의 경직성**: '미지급=0' 시 자동 완료 트리거는 탁송비·수리비 등 매입 확정 후 발생 추가 비용에 대한 수정 워크플로우가 고려되지 않음

**2. 자동 잔금 amount**: **글로벌 표준(SAP, NetSuite)은 전액(Remaining Balance) 자동 기입 후 Draft 저장**. 0원 기본값은 사용자의 재입력을 강제하여 휴먼 에러를 유도. Odoo·QuickBooks도 PO 기반 잔액 제안 + 확정(Confirm) 전까지 장부 미반영. **전액 채움 + Draft 정책이 타당**.

**3. 1인 개발 우선순위 (정산 무결성 우선)**:
- 1순위: QA SQL 결함 수정 + AUDITED_COLUMNS 보안 보강 (가장 위험)
- 2순위: Transfers UI 통합 + read-only 격하 (관리 일원화)
- 3순위: 자동 잔금 생성 + 알림톡 (편의 기능)

**4. NO-GO (절대 불가)**:
- (a) 재무의 최종 '계좌 승인' 혹은 '화이트리스트' 체크 없는 영업 단독 계좌 변경
- (b) 감사 로그(Audit) 비활성화 상태에서의 자금 관련 컬럼 수정
- (c) 취소/반품/추가비용 시나리오가 없는 매입 자동 완료 처리

### 사외이사 합의
- **자동 잔금 amount**: 양쪽 **QA안 채택** (amount=전액 + Draft, SAP/NetSuite/Odoo/QuickBooks 모두 표준). **Spec-F amount=0 권고는 외부 시각 기각**
- **변경 1·3 우선 처리**: Security NO-GO 4 해소조건 먼저 + SoD 보강
- **변경 4 후행 비용 처리**: Gemini 신규 우려 (c) — 별도 안건으로 분리 권장 (큐 22-C 범위 외)

---

## 🚨 NO-GO 상세 (Security 변경 1·3 — 4 해소조건 충족 시 조건부 GO)

**차단 사유** (Security + Gemini 합의):
- 시나리오 A 자가 계좌 대체 횡령 + 시나리오 B AUDITED_COLUMNS 미포함 + 시나리오 C 매입 탭 role guard 부재. 3 경로 동시 노출 + 해석 B 정면 위반

**수용 가능한 최소 조건 4건**:
1. `purchase_seller_bank/account/holder/bank_memo` 4컬럼 `AUDITED_COLUMNS` 추가 (`Vehicle.php` L397 이후) — 계좌번호 마스킹 처리 유지
2. 매입처 계좌 입력 권한 영업 유지 + audit 완비 (사용자 명세 영업 입력 의도와 일치) **또는** `canAccessSettlement()` 한정으로 재무 전용화 (사용자 의도 검토 필요)
3. 매입 [매입] 탭 자금 영역 read-only 격하 + `/erp/transfers` 매입 잔금 탭에 입력+확정 통합 UI 신설
4. 위 3건 구현 후에만 큐 22-C 착수 (필수 선행)

**대안**: 매입처 계좌 입력 위치를 `/erp/transfers` 매입 탭에 통합. 차량 [매입] 탭은 **read-only 표시만** — FINANCIAL_FIELD_MAP silent-restore 패턴 재활용

---

## 🏁 최종 권고 (Opus 4.7 최종 취합)

### 판정: **조건부 GO 패키지** (변경 1·4 GO + 변경 2·3 조건부 GO + 후행 비용 처리 별도 큐)

### 근거 (1줄)
**Codex+Gemini 양쪽 QA안(amount=전액 Draft)+ SoD 우선 + AUDITED_COLUMNS 보강 합의**. SAP/NetSuite/Odoo/QuickBooks Vendor Bill Draft 표준 일치. 변경 1·4는 이미 코드 구현 — 문서 정정 + 보안 보강이 본 회의 핵심 작업.

### 필수 선행 작업
1. **큐 22-A → 22-B 완료** (메모리 §1·2 큐 진행 순서 유지)
2. **큐 20-C 회의록 + workflow-checklist B-1 정정** (매입처 계좌 입력자 "재무" → "영업")
3. **사용자 의도 확정** — 매입처 계좌 입력자: 영업 유지(사용자 명세 일치) vs 재무 전용화(SoD 강화) 결정

### 조건 (조건부 GO — 큐 22-C 통합)

**A. 보안 4 해소조건 (운영 전 차단 요건)**:
1. `AUDITED_COLUMNS`에 `purchase_seller_bank/account/holder/bank_memo` 4컬럼 추가
2. 매입처 계좌 입력 권한 결정 (영업 유지 + audit 완비 권장)
3. 차량 [매입] 탭 자금 영역 read-only 격하
4. `/erp/transfers` 매입 잔금 탭 입력+확정 통합 UI 신설

**B. 자동 잔금 생성 (변경 2)**:
1. **amount = purchase_price + selling_fee (잔액 자동 채움), payment_date=NULL, confirmed_at=NULL** (QA안, 사외이사 양쪽 채택 — SAP/NetSuite 표준)
2. 트리거: `Vehicle::saving`에서 `$vehicle->exists && $vehicle->isDirty('purchase_price') && PBP::count==0` 가드 (최초 1회만)
3. `purchase_balance_payments.created_by_user_id` 컬럼 추가 (auto-INSERT 시 null = "시스템 생성")
4. UI에 "재무 미확정 Draft" 라벨 명시 (Spec-F 권고 일부 채택)

**C. SQL 결함 동시 패치 (QA 기존 결함 발견)**:
- `Vehicle::scopeAction('purchase_unpaid')` SQL(L986~990)에 `AND confirmed_at IS NOT NULL` 추가

**D. Defense-in-depth 가드**:
1. `PurchaseBalancePayment::creating` 훅 신설:
   - (a) paid Settlement 존재 시 신규 행 차단 (Spec-F, FinalPayment 쌍 미러링)
   - (b) `canConfirmFinance()` 체크 (Spec-E, UI 우회 차단 — `$skipPBPCreatingGuard` static flag로 자동 생성 시 우회)
2. Volt `savePurchaseBalancePayments()` 진입 시 `canConfirmFinance()` 추가 (UX 403 메시지)

**E. 매입완료 자동 트리거 (변경 4)**:
- 코드 이미 구현 (`Vehicle.php` L811~813 + `refreshCaches()` L640~646). 추가 작업 0
- `AuditLog::recordEvent()` metadata에 `"trigger": "auto"` 키 추가 권장 (시스템 자동 vs 사용자 액션 구별)

**F. 후행 비용 처리 (Gemini 신규 우려) — 별도 큐 분리**:
- 매입 완료 후 탁송비·수리비 추가 시 큐 21 ledger lock과 충돌 검토
- 본 회의 범위 외, 큐 22 시리즈 완료 후 별도 안건

### 보류 사유 (HOLD — 후행 비용 처리만)
- 큐 21 ledger lock과 충돌 가능성 — 별도 큐로 분리 결정. 본 회의 결과에 포함 안 함

### 공수 재산정 (큐 22-C 통합)

| 구분 | 작업 | 공수 |
|---|---|---|
| 기존 22-C | type enum + 컬럼 DROP + 모델 분자 단순화 + UI 이전 + 테스트 | 7~9h |
| 본 안건 추가 | 회의록·문서 정정 (0.5h) + AUDITED_COLUMNS (0.5h) + 자동 PBP 생성 (1h) + scopeAction SQL fix (0.5h) + creating 훅 (1h) + created_by_user_id 컬럼 (0.5h) + 신규 테스트 4건 (2~3h) | **6~7h** |
| **총 22-C** | — | **13~16h** |

---

## 🛠 car-erp 영향 분석 (Opus 4.7 산출)

### 취약점 (Vulnerabilities) — 운영 전 차단 요건
1. **`AUDITED_COLUMNS` 계좌 4컬럼 미포함**: 영업 또는 재무가 계좌를 수정해도 audit_logs에 기록 안 됨 (`Vehicle.php` L381~397 grep 0건 확인)
2. **`Vehicle::scopeAction('purchase_unpaid')` SQL `confirmed_at IS NOT NULL` 누락** (`Vehicle.php` L986~990 vs L856~860 비대칭) — 큐 22-C 패치에서 동시 수정 의무
3. **차량 [매입] 탭 자금 영역 role guard 부재** (`canAccessSettlement` 분기 grep 0건) — 영업이 PBP row 직접 추가·삭제 가능 (해석 B 위반)
4. **`PurchaseBalancePayment::creating` 훅 부재** — paid Settlement 후 영업이 새 PBP INSERT 시도 시 차단 안 됨 (Service 레이어만 차단, 모델 레이어 미차단)
5. **큐 20-C 회의록 ↔ 코드 불일치** — 매입처 계좌 입력자 회의록 "재무"이나 코드는 "영업" 입력 가능

### 보완사항 (Improvements)
1. **자동 PBP Draft 생성 시 `amount=purchase_price+selling_fee`, `payment_date=NULL`, `confirmed_at=NULL`** — SAP/NetSuite/Odoo/QuickBooks 표준
2. **PBP `created_by_user_id` 컬럼 추가** + auto-INSERT 시 null = "시스템 생성"
3. **Defense-in-depth 가드**: 모델 creating 훅 + Volt save() 이중 잠금
4. **UI에 "재무 미확정 Draft" 라벨** 명시 (Spec-F)
5. **AuditLog metadata `"trigger": "auto"` 키** — 시스템 vs 사용자 액션 구별
6. **사용자 의도 명확화** — 매입처 계좌 입력자 영업 유지 vs 재무 전용화 (Gemini SoD 우려 반영 가능성)

### 코드 수정 (Code Changes) — 큐 22-A·22-B 완료 후 큐 22-C에서 통합

**파일별 변경**:
- `app/Models/Vehicle.php` — `AUDITED_COLUMNS` L397 후 4컬럼 추가, `scopeAction('purchase_unpaid')` L986~990 `AND confirmed_at IS NOT NULL` 추가, `saving` 훅에 자동 PBP Draft 생성 가드 (최초 1회 + isDirty 체크)
- `app/Models/PurchaseBalancePayment.php` — `creating` 훅 신설 (paid 차단 + canConfirmFinance Defense-in-depth + `$skipCreatingGuard` static flag)
- `resources/views/livewire/erp/vehicles/index.blade.php` — 매입 탭 자금 영역 read-only 격하 (`@if(canConfirmFinance())` 분기 또는 `disabled` 속성)
- `resources/views/livewire/erp/transfers/index.blade.php` — 매입 잔금 탭에 "입력+확정 통합 UI" 신설 (`amount` 입력 필드 추가 + 같은 화면에서 [확정] 클릭)
- `app/Services/PaymentConfirmationService.php` — 변경 없음 (기존 패턴 재사용)
- `docs/meetings/2026-05-17-purchase-sale-finance-gate.md` — 큐 20-C "매입처 계좌 입력자" 정정 ("재무" → "영업")
- `docs/workflow-checklist.md` L117 B-1 정정 (큐 22-C 완료 시점에 일괄)

### 신규 추가 (New Additions)
- **마이그**: `add_created_by_user_id_to_purchase_balance_payments` — nullable FK → users
- **마이그**: `purchase_balance_payments.type` enum (큐 22-C 기존 작업) — `'down'`, `'selling_fee'`, `'balance'`
- **마이그**: `vehicles.down_payment` + `selling_fee_payment` 2컬럼 DROP (큐 22-C 기존 작업, 별도 마이그 파일 분리)
- **신규 테스트 4건**:
  - `PBP_draft_excluded_from_purchase_unpaid_sql` — Draft PBP가 scopeAction에서 제외 검증
  - `purchase_account_audit_log_recorded_by_sales_role` — 영업의 계좌 수정 audit 기록
  - `purchase_balance_creating_blocked_when_paid_settlement_exists` — paid 후 신규 PBP 차단
  - `purchase_balance_creating_requires_canConfirmFinance` — 영업의 직접 INSERT 시도 차단

### 모순·NO-GO 처리 로그
- **PO "변경 3 NO-GO"** → "transfers 통합 UI 재해석 시 GO" 자체 제시 → 조건부 GO 채택
- **Security NO-GO (1·3)** → 4 해소조건 충족 시 조건부 GO 전환 — 큐 22-C 진입 전 처리 필수
- **Spec-F "amount=0" vs QA "amount=전액 Draft"** → **Codex+Gemini 양쪽 QA안 채택** (SAP/NetSuite/Odoo/QuickBooks 표준). Spec-F amount=0 권고 기각, UI 라벨 명시 부분만 채택
- **QA "변경 4 HOLD (의도 재확인)"** → PO+Engineer+Spec-F "이미 구현" 합의 + 사용자 의도와 코드 동작 일치 → HOLD 해제
- **Gemini NO-GO (c) 후행 비용 처리** → 별도 안건 분리 (큐 22-C 범위 외)
- **Engineer "변경 2 정책 결정 필요"** → 사외이사 양쪽 amount=전액 Draft 합의로 해소

---

## 🔗 참조

### 관련 과거 회의록
- `2026-05-19-group-revenue-progress-redesign.md` — 새회의 6번 해석 B 확정 (입금 입력 자체를 재무 전용)
- `2026-05-18-deposit-confirm-gate.md` — 큐 22 옵션 B 단계적 채택 (큐 22-C 범위)
- `2026-05-18-vehicle-ledger-field-lock.md` — 큐 21 confirmed 후 잠금
- `2026-05-17-purchase-sale-finance-gate.md` — **큐 20-C 매입처 계좌 입력자 결정 (정정 대상)**
- `2026-05-16-finance-gate-roundtable.md` — 큐 19-F SoD self-confirm 차단

### 코드 참조
- `app/Models/Vehicle.php` L381~397 (AUDITED_COLUMNS — 계좌 4컬럼 미포함), L811~813 (매입완료 자동 트리거 이미 구현), L852~860 (분자 A안), L986~990 (scopeAction confirmed_at 누락 결함), L639~645 (refreshCaches DB::table 우회)
- `app/Models/PurchaseBalancePayment.php` L10~13 (fillable created_by_user_id 부재), L25~43 (booted 훅 — creating 부재)
- `app/Models/AuditLog.php` L21~25 (MASKED_COLUMNS purchase_seller_account 있음, AUDITED와 별개)
- `app/Models/Settlement.php` L76~110 (confirmed_purchase_payments 캡처 완비)
- `app/Services/PaymentConfirmationService.php` L97~104 (assertPaidSettlementGuard)
- `resources/views/livewire/erp/vehicles/index.blade.php` L2608~2634 (매입처 계좌 영업 입력 — 이미 구현, 권한 가드 없음), L447~467 (FINANCIAL_FIELD_MAP 계좌 미포함)
- `resources/views/livewire/erp/transfers/index.blade.php` L125~134 (조회·확정만, 입력 UI 없음)
- `database/migrations/2026_05_17_120002_add_finance_gate_to_purchase_balance_payments.php` (PBP confirmed_at)
- `database/migrations/2026_05_17_120003_add_purchase_account_to_vehicles.php` L17~21 (계좌 컬럼 + 암호화)
- `docs/workflow-checklist.md` L117 (B-1 stale)

### 부서 프롬프트 (v1.2)
- `docs/meetings/departments/{po,engineer,qa,security,ops,specialist}.md`
