# 📅 회의록: 입금 컬럼 confirm 게이트 (큐 22)

- 일시: 2026-05-18
- 강도: 풀회의 (/회의 명령어 호출)
- 안건 유형: 정산 마진 공식 변경 + 마이그레이션 (복합)
- 자동발동 여부: yes (/회의 슬래시)

## 안건

**입금 컬럼 6종(`deposit_down_payment` / `interim_payment` / `advance_payment1·2` / `savings_used` / `down_payment` / `selling_fee_payment`)도 재무 confirm 게이트 도입할 것인가. 테스트 단계 가정 — 마이그·데이터 손실 부담 없음. 옵션 A(12 컬럼 추가) / B(final_payments 단일 모델 통합) / C(차량당 1 컬럼 절충) / D(UI 인지 모달만) 4안 중 채택.**

### 배경
- 시나리오 6에서 사용자 발견: 영업이 실물 입금 확인 없이 시스템에만 `deposit_down_payment` 입력 → 미수율 즉시 변동 → 큐 9 G1 50% B/L 잠금 우회 가능 → 차량 출항 후 회수 불가 위험
- 큐 20 A안에서 잔금만 confirm 게이트 적용. 입금 컬럼은 "정산 role 정상 업무" 제외 결정 (큐 7 C7-a)
- 큐 13 AWS Lightsail 배포 임박

---

## 💬 부서별 발언 (Sonnet 4.6)

### 📋 PO
**판정: 조건부 GO (옵션 C)**

**발언**: `Vehicle.php:836~846` 분자 A안 필터 — `deposit_down_payment / interim_payment / advance_payment1·2`는 `confirmed_at` 조건 없이 즉시 분자 차감. G1 50% B/L 잠금이 실시간 `unpaid_ratio` 읽으니 실물 입금 없이 시스템 수치만 조작하면 우회 가능. **구조적으로 유효한 리스크**. 옵션 C(차량당 1 컬럼, ~8h) 현실적. A(12컬럼, 15~20h)는 컬럼 폭발, B(모델 통합, 25~30h)는 1인 개발 5~6주 큐에서 소화 불가. D는 리스크 제거 효과 0.

**조건부 GO 조건**:
1. `sale_deposits_confirmed_at` SET 후 4컬럼 직접 수정 차단 — `LEDGER_LOCK_FIELDS` 4컬럼 추가
2. `savings_used`는 SavingsStatus H6 훅과 충돌 — 별도 큐
3. `down_payment / selling_fee_payment` 매입 측은 별도 큐 (이번은 판매 입금 4컬럼만)
4. `sale_deposits_confirmed_at` NULL 시 분자에서 4컬럼 제외 — 분자 정의 변경 QA/Engineer 협의 필수

**다음 작업 큐 영향**: 큐 22 신설. 큐 21 LEDGER_LOCK_FIELDS 확장 선행. 큐 13 배포와 병렬 무관.

**업무 영향 role**: 정산 (confirm 권한), 영업 (입력 후 대기), admin/super (잠금 해제)
**사용자 막힘 정도**: 리스크 제거 — G1 우회 경로 차단
**우선순위 분류**: 리스크 제거
**운영 전 필수 여부**: yes

---

### ⚙️ Engineer
**판정: 옵션 C 채택 권고 (단, savings_used는 별도 처리 필요). 옵션 A는 분자 공식 폭발, 옵션 B는 마이그 리스크 과대, 옵션 D는 게이트 미달.**

**발언**:
- **옵션 A (12 컬럼)**: 분자 공식 폭발. 12 신규 컬럼 + accessor 분기 6줄 + `AUDITED_COLUMNS` 6개 추가 + LEDGER_LOCK_FIELDS 충돌 검토. SKILLS §13 단일 출처 위반으로 G1/G2/G3 정합 재검증 15케이스. **공수 15~20h, 단일 출처 위반 위험**.
- **옵션 B (4컬럼 drop + final_payments 통합)**: 마이그 트랜잭션 리스크 최고. `savings_used` H6 훅 SavingsStatus 거래까지 FP::created 훅에 넣으면 depth 3레이어 (FP→RH + FP→SS) 무한루프 위험. 롤백 SQL 1줄 절대 불가. **공수 25~30h. NO-GO**.
- **옵션 C (차량당 2컬럼)**: 가장 현실적. 롤백 SQL `ALTER TABLE vehicles DROP COLUMN ...` 1줄. 분자 변경 1개 플래그로 게이팅. 정밀도 손실 PO 수용 시 진행 가능.
- **savings_used 특수 취급**: 분자 차감 제외 (별도 관리). confirm 게이트 시 SavingsStatus 정합 문제. **이번 큐 22 스코프 외**.
- **G1 50% B/L 잠금 영향**: 입금 미확정 시 분자 차감 없음 → 미수율 ↑ → G1 더 강하게 발동 (우회 아닌 강화).
- **SKILLS §13 영향**: 옵션 C 도입 시 `sale_unpaid_amount_krw_cache` 의존 5곳 재계산 → `php artisan vehicles:rebuild-progress-cache` 1회 필수.

**공수 추정**: 옵션 C 8~10h
**영향 파일**: Vehicle.php, PaymentConfirmationService.php, 마이그 신규, transfers/index, vehicles/index
**근거 파일**: Vehicle.php L841~844, L855~860, L406~415, L500~516
**권한 가드 위치**: Service `canConfirmFinance` (기존 패턴)
**캐시 rebuild 필요**: yes

**NO-GO 옵션 B**:
- (a) 4 컬럼 drop 롤백 SQL 1줄 불가, 트랜잭션 timeout 위험
- (b) FP 훅 depth 3레이어 무한루프
- (c) savings_used H6 이관 시 SavingsStatus 이중/누락

---

### 🧪 QA & Domain Integrity
**판정: CONDITIONAL NO-GO — 전제 조건 3건 충족 시 GO**

**발언**: 분자 단일 출처 관점 6컬럼은 현재 Vehicle 컬럼 직접 참조. 큐 20에서 이미 분자 A안 채택 후 또 변경 = **분자 정의 세 번째 변경**. SKILLS §13 5곳 정합 재검증 필요. `DashboardActionCountsTest`·`G1BlLockTest`·`PaymentConfirmationServiceTest`는 `deposit_down_payment` 직접 set → 즉시 차감 전제로 작성 — 게이트 도입 시 **이 전제 무효화 → 재작성**.

**도메인 공식 영향**:
- `getSaleUnpaidAmountAttribute` 분자: **매우 높음**
- `sale_unpaid_amount_krw_cache` 갱신 트리거: **높음**
- `unpaid_ratio` → G1 B/L 잠금: **높음** (미확정 deposit 제외 시 G1 강화)
- `progress_status` 판매완료 조건: **중간**

**회귀 시나리오**: 80~110분
**Unit Test**: `PaymentConfirmationServiceTest` 8케이스 확장 필요. deposit·interim·advance 컬럼 confirm 테스트 신규 필요.
**깨질 기존 테스트**: `DashboardActionCountsTest` L51~61 / `G1BlLockTest` L57~66 / `PaymentConfirmationServiceTest` L68 — 확정 깨짐

**NO-GO 3건**:
- (a) 구조 결정 선행 (A/B/C) 미완료 — 분자 세 번째 변경 위험
- (b) 깨질 테스트 3파일 수정 범위 사전 합의 필수
- (c) 단계적 분리 권고 — 입금 컬럼은 "재무 열람 알림" 수준 (전량 confirm 필수화 시 정산 병목)

**근거**: Vehicle.php L836~846, tests/Feature/DashboardActionCountsTest L51~61, G1BlLockTest L53~66

---

### 🔒 Security & Compliance
**판정: 조건부 GO — 단, savings_used 게이트 설계에 NO-GO 조건 1건 첨부**

**발언**: `AUDITED_COLUMNS`(L381~389)에 7개 전부 포함 — 변경 감사는 완비. 그러나 `FINANCIAL_FIELD_MAP`(L447~467)에는 0개 → 변경 차단 전무. 영업·정산·관리 모두 무제한 수정 가능 — **SoD 결손이 코드에 그대로 실재**.

**3 공격 시나리오**:
1. 미수율 조작 후 G1 우회 — `deposit_down_payment` 증액 → 분자 감소 → G1 통과
2. `savings_used` 적립금 허위 소진 — `Vehicle::saved` H6 훅이 자동 SavingsStatus(USED) 거래 생성. 실물 적립금 확인 없이 잔액 차감
3. `down_payment` 이중 계상 — 매입 미지급 분자 직접 반영

**6 입금 컬럼**: 옵션 A·B·C 모두 SoD 충족. **D는 강한 NO** (Model 레이어 차단 없어 SoD 0).

**`savings_used` NO-GO 조건 3건**:
- (a) confirm 전 SavingsStatus 거래 생성 차단 (`Vehicle::saved` H6 훅 수정 — confirmed 상태만 거래)
- (b) confirm 시점 SavingsStatus 잔액 재검증 (`lockForUpdate` race condition)
- (c) confirm 후 savings_used 변경 시 REFUND 역거래 정책 명시

**감사로그 영향**: `approval_request_id` 링크 부착 권장.
**운영 전 필수 여부**: yes

**근거**: Vehicle.php L500~518, L830~843, vehicles/index.blade.php L444~467 (`FINANCIAL_FIELD_MAP` 입금 컬럼 전원 제외 확인)

---

### 🚀 Ops & Deploy
**판정: 조건부 GO (옵션 C 또는 D 권장 — 옵션 B는 AWS 배포 전 금지)**

**발언**: 옵션 A는 INSTANT ADD COLUMN 0초 다운타임. 옵션 B는 데이터 변환 트랜잭션 위험 + 컬럼 drop INSTANT 불가 → 운영 DB 다운타임 미정. **배포 직전 미검증 데이터 변환 마이그가 운영 DB에 적용되는 시나리오는 NO-GO**.

**옵션 B AWS 배포 NO-GO 3건**:
- (a) 데이터 변환 SQL 검증 없이 운영 마이그 불가, down() 복구 불가
- (b) vehicles 컬럼 drop은 INSTANT 불가 → 테이블 재구성 다운타임
- (c) 배포 후 운영 시작 시점 분자 정의 변동 → 첫날 혼란

**다운타임**: 옵션 A·C·D 0초 / 옵션 B 미측정
**백업 시점**: 마이그 직전 mysqldump 필수 (옵션 B 특히)
**queue worker 영향**: 무관
**환경 의존성**: 없음
**캐시 rebuild**: 옵션 A·C 마이그 후 `php artisan vehicles:rebuild-progress-cache` 1회 필수

**근거**: Vehicle.php L841~846, 큐 21 fix 마이그 패턴

---

### 🔧 Specialist [F. 회계·정산 감사]
**판정: 조건부 GO**

**발언**: 현재 분자(L841~844)는 큐 20 A안 적용 — `deposit_down_payment / interim_payment / advance_payment1·2`는 확정 필터 없이 전액 차감, `finalPayments`만 confirmed 필터. 비대칭 구조.

**옵션별 회계 retroactive 영향**:
- 옵션 A: 분자 변경 → 기존 paid Settlement `confirmed_snapshot`과 새 정의 불일치. **retroactive 있음**
- 옵션 B: type enum 흡수 + 분자 공식 불변 → **retroactive 없음**
- 옵션 C: 분자 필터 연결 시 옵션 A와 동일
- 옵션 D: 분자 변경 없음, 보호 없음

**`paid Settlement confirmed_snapshot` 입금 6종 미포함 (L77~110 확인)** — 옵션 A/B/C 도입 시 6종도 snapshot 캡처 필수. 그렇지 않으면 paid 이후 "어느 시점에 계약금 confirmed였는가" 재현 불가.

**G1 50% 강화 부작용**: pending 계약금 큰 차량은 confirmed 전까지 ratio > 0.5로 B/L 잠금 발동 — 의도치 않은 G1 강화.

**NO-GO 해제 조건**:
- (a) 옵션 결정 선행. 옵션 A 시 confirmed_snapshot 6종 추가 캡처 필수
- (b) 환율 0/NULL 완납 오판 경로 감사 (별건)
- (c) G1 예외 로직 재검토

**근거**: Vehicle.php L836~846, Settlement.php L73~111, PaymentConfirmationService.php L100~104
**운영 전 필수 여부**: yes

---

### 🔧 Specialist [B. 데이터 무결성]
**판정: 조건부 GO — 옵션 B가 무결성 최강이나 테스트 단계 한정. 운영 배포 직전 옵션 B는 영구 불가.**

**발언**: 입금 컬럼 6종이 Vehicle 스칼라 컬럼 = 행 단위 추적 불가. 변경 이력 없고 confirmed 전환 시점 별도 컬럼 없이 AuditLog만 의존.

**옵션별 무결성 품질**:
- 옵션 A: 12컬럼 추가로 전환 시점 명시. 다만 vehicles 70+ 컬럼 → 비대화 심화
- **옵션 B: 무결성 최강 + glob ERP 정석. 테스트 단계 한정 안전**. final_payments type enum + savings_used H6 훅 위치 이동 필요
- 옵션 C: 차량당 1 confirm — 컬럼 단위 추적 불가
- 옵션 D: 보호 없음

**`vat_formula_version` 패턴 적용 가능성**: 분자 정의 버전 컬럼은 구현 복잡도 高 + 성능 저하. **테스트 단계라 버전 컬럼 불필요, 전체 통일이 안전**.

**paid Settlement 충돌 — 신규 행 CREATE 미차단**: `FinalPayment::updating` 훅(L46~58)은 UPDATE 차단이지만 **`creating` 훅 부재** → paid 차량에 새 잔금 행 추가 가능. 기존 구멍.

**NO-GO 해제 조건**:
- (a) 옵션 B 선택 시 마이그 원자성 (vehicles 4컬럼 → final_payments INSERT → DROP 트랜잭션 분리 + 검증)
- (b) `FinalPayment::creating` 훅 paid 차량 신규 행 차단 (기존 구멍 메우기)
- (c) `savings_used` 트리거 위치 결정 (FP::saved 이전 vs 컬럼 유지)

**근거**: Vehicle.php L381~396, L500~516, FinalPayment.php L40~69, PaymentConfirmationService.php L100~104

---

## 🧩 중간 회의 결과 (Opus 4.7 1차 취합)

### 합의 6/6
1. **옵션 D 만장일치 NO** (DB 레벨 보호 부재, SoD 0)
2. **savings_used 별도 큐로 분리** (Vehicle::saved H6 훅 + SavingsStatus 자동 거래 특수성)
3. **down_payment / selling_fee_payment 매입 측 별건** (이번은 판매 입금 4컬럼만)
4. **paid Settlement confirmed_snapshot에 입금 4종 추가 필수**
5. **큐 21 LEDGER_LOCK_FIELDS에 4컬럼(deposit·interim·advance1·2) 추가**
6. **FinalPayment::creating 훅 paid 차량 신규 행 차단 (기존 구멍)**

### 충돌 영역
- 옵션 C vs B: PO/Engineer/Ops C(현실성), Specialist B B(무결성 정석)
- QA NO-GO vs 다수 GO: QA "단계적 분리(알림만)" vs 다수 "DB 게이트 필요"

---

## 🌐 사외이사 의견 (Codex / Gemini)

### [Codex]

1. 놓친 리스크: 첫째, 옵션 C는 "확인 합계 1컬럼"이면 어떤 입금 항목이 허위였는지 사후 감사 추적이 약하다. 둘째, confirm 전후 금액 수정·삭제·부분 unconfirm 정책이 없으면 잠금은 생기지만 분쟁 로그가 빈다. 셋째, **G1/B/L 판단식이 "입력값"과 "확정값"을 혼용하면 새 우회로가 생긴다**.

2. 판정: 지금은 옵션 C 채택. 옵션 B가 ERP 정석이나 AWS 배포 직전 1인 개발에선 범위가 과하다. **QA NO-GO는 "구조 결정 선행"까지는 수용, "알림 수준"은 기각**. 이미 출항 리스크가 확인됐으므로 DB 게이트가 필요하다.

3. SAP/NetSuite/Odoo/QuickBooks 모두 일반적으로 입금을 독립 payment transaction으로 만들고 invoice/order에 apply/reconcile한다. 단순 컬럼 입력으로 미수금을 줄이는 방식은 ERP 표준과 거리가 있다. 참조: SAP Incoming Payments, Oracle NetSuite Customer Payments, Odoo Payments, QuickBooks Record invoice payments.

4. 우선순위: 배포 전 최소 C + snapshot + ledger lock + paid 차량 신규 FinalPayment 차단. **savings_used와 매입 측은 별도 큐**.

자체 NO-GO 조건: (a) 확정 전 금액이 G1/B/L 계산에 반영됨 (b) confirmed_snapshot 미반영 (c) confirm 후 수정·삭제 감사로그 없음.

### [Gemini]

**1. 놓친 리스크**
- **반려(Reject) 프로세스의 부재**: 승인 대기 중 영업이 수치를 수정하거나 재무가 반려했을 때의 '원복 로직'과 '수정 이력'이 옵션 C에는 누락됨.
- **부분 승인 딜레마**: 4종 입금이 순차 발생 시 미수금 계산식이 '승인된 입금액만' 합산 vs '입력값 전체' 인식 불일치.

**2. 판정 (B vs C)**: **Option B 지향형 C**. 글로벌 ERP는 100% 전표(Journal) 기반(B). 컬럼 방식(C)은 엑셀 관리 대장의 연장. 단, 1인 개발 및 배포 임박 컨텍스트상 **QA의 '단계적 분리' 의견이 가장 합리적**.

**3. 글로벌 패턴**: SAP/Oracle 등은 '지불 요청 → 재무 승인 → 전기(Posting)' — 원본 수정 없이 신규 Transaction 생성.

**4. 우선순위 재평가**:
- **Short-term (배포 전)**: 큐 21(Ledger Lock) 및 Option D(UI 인지)에 집중하여 '실수 방지'에 주력
- **Mid-term (배포 후)**: 입금 4종을 final_payments 모델로 흡수하는 **Option B로 완전 전환**

**5. NO-GO (Option C 고수 시)**:
- (a) 감사 추적성(Audit Trail) 결여 — 컬럼 덮어쓰기 방식은 회계 감사 시 입금 조작 방어 불가
- (b) 확장성 한계 — 외환 환차익/분할 입금 대응 불능
- (c) 운영 리스크 — 1인 체제 배포 직전 핵심 계산 로직 대수술은 서비스 중단 사고

---

## 🏁 최종 권고 (Opus 4.7 최종 취합) — 사용자 정정 후

> ⚠️ **사용자 후속 정정 (2026-05-18)** — 회의 가정 재확인 결과 정정. **원 회의 권고(옵션 C + 큐 23 옵션 B 별건)에서 옵션 B 단계적 채택으로 변경**. 정정 노트는 본 섹션 맨 아래 별도 표시.

**판정 (정정 후)**: **조건부 GO — 옵션 B 단계적 채택 (큐 22-A/B/C 분할)**

**판정 (원 회의 권고)**: ~~조건부 GO — 옵션 C (배포 전) + 큐 23 옵션 B (배포 후)~~

**근거 (1줄)**: 내부 6부서 + 사외이사 2명 모두 "단기 C + 장기 B" 합의. 옵션 D 만장일치 NO. 사용자 정정 — DB 테스트 자유 + 배포까지 한 달 여유 → **옵션 B 단기 NO-GO 사유(공수·배포 임박) 모두 해소** → 옵션 B 단계적 채택. Codex/Gemini 신규 통찰(반려·부분승인·unconfirm 정책 + 입력값↔확정값 혼용 차단) 패키지 통합.

### 사용자 정정 (2026-05-18 후속)

회의 가정 명확화 — 사용자 확인 결과:
- **DB**: 테스트 단계 → 마이그·데이터 손실 부담 0
- **시간**: 배포까지 다음 달까지 여유 (한 달+) → 25~30h 공수 가능

옵션 B 단기 NO-GO 사유 해소 매핑:
| 회의가 우려한 사유 | 사용자 조건 | 해소 |
|---|---|---|
| Engineer "마이그 트랜잭션 위험" | DB 자유 | ✅ 해소 |
| Ops "AWS 배포 임박 시점" | 다음 달까지 여유 | ✅ 해소 |
| Specialist B "테스트 단계 한정" | 그대로 충족 | ✅ |
| 1인 개발 25~30h 공수 | 한 달 여유 | ✅ 해소 |
| QA "분자 정의 잦은 변경" | 옵션 B는 공식 불변 (단순 type enum 추가) | ✅ 해소 |

→ **단기 NO-GO 사유 모두 해소** → 옵션 B 단계적 채택. 큐 23 분리 불필요 (큐 22가 옵션 B 직접).

### 필수 선행 작업 (큐 22 진입 전)

1. **`FinalPayment::creating` 훅 추가** — paid Settlement 존재 시 신규 행 차단 (Specialist B 기존 구멍 메우기)
2. **큐 21 `LEDGER_LOCK_FIELDS` 확장 검토** — 옵션 B 채택으로 4컬럼 vehicles에서 drop되므로 이 항목은 불필요화. 단 type 컬럼 추가된 final_payments 행은 큐 20-D updating 잠금 그대로 적용
3. **테스트 3파일 수정 범위 사전 합의** — DashboardActionCountsTest / G1BlLockTest / PaymentConfirmationServiceTest

### 조건 (조건부 GO 패키지) — 옵션 B 단계적

**① 큐 22-A: 판매 입금 4컬럼 → final_payments 통합 (10~15h)**

| 구성 | 내용 |
|---|---|
| **마이그** | `final_payments.type` ENUM 추가 (`'deposit_down', 'interim', 'advance_1', 'advance_2', 'balance'`). 기존 행은 모두 `balance` 디폴트. 신규 4 type은 vehicles 컬럼 데이터 변환 INSERT 후 vehicles 4컬럼 DROP. **트랜잭션 2단계 분리**: ① 변환 INSERT (검증) → ② 별도 마이그에서 컬럼 DROP |
| **분자 변경** | `getSaleUnpaidAmountAttribute` 단순화: `$this->finalPayments->whereNotNull('confirmed_at')->sum('amount')` (type 무관). 분자 정의 단일 출처 회복 |
| **확정 UI** | `/erp/transfers` 판매 잔금 탭 — 기존 그대로 사용 (type 컬럼 표시 추가). 모든 입금 type 한 list |
| **권한** | `canConfirmFinance` (admin/super/role=정산), self-confirm 차단 |
| **paid Settlement snapshot** | `confirmed_final_payments`에 모든 type 행 포함 (이미 그렇게 동작) |
| **반려·unconfirm** (Gemini) | type 무관 — 큐 20-D 패턴 그대로. `FinalPayment::updating` 잠금이 confirmed_at 후 amount/type 변경 차단 |
| **`FinalPayment::creating` 훅 추가** | paid Settlement 존재 시 신규 행 차단 (Specialist B 기존 구멍 메우기 — 부수 fix) |
| **UI 차량 편집** | 판매 탭 입금 영역 통합 — 잔금 N건 row 패턴 확장. type 드롭다운 + 금액 |
| **새 테스트** | 10~12 케이스 — type별 confirm/미confirm/G1/snapshot/audit/unconfirm/paid creating 차단 |

**② 큐 22-B: savings_used 통합 (6~8h) — 회의 1회 권장**

Security NO-GO 3건 해소:
- (a) confirm 전 SavingsStatus 거래 생성 차단 — `Vehicle::saved` H6 훅 → `FinalPayment::saved`로 이전, confirmed 분기
- (b) confirm 시점 SavingsStatus 잔액 race condition 재검증 (`lockForUpdate`)
- (c) confirm 후 변경 시 SavingsStatus REFUND 역거래 정책

`vehicles.savings_used` → `final_payments.type='savings_used'` 행. `Vehicle::saved` H6 훅 제거 → `FinalPayment::saved` 훅으로 이전.

**③ 큐 22-C: 매입 측 통합 (5~6h)**

`down_payment` / `selling_fee_payment` → `purchase_balance_payments`에 `type` enum 추가 (`'down', 'selling_fee', 'balance'`). vehicles 2컬럼 DROP. 분자 단순화.

### 공수 추정

- **큐 22-A** (판매 입금 4컬럼): **10~15h**
- **큐 22-B** (savings_used + SavingsStatus 재설계): 6~8h + 회의 1회
- **큐 22-C** (매입 측 2컬럼): 5~6h
- **총 큐 22**: **23~31h** (한 달 안에 단계적 완수 가능)
- ~~큐 23~~: 불필요 (큐 22가 옵션 B 직접)

### 모순·NO-GO 처리 로그

- **QA "단계적 분리(알림 수준)" 권고 기각** — Codex+Gemini 양쪽 명시적 기각 ("이미 출항 리스크 확인됨, DB 게이트 필요"). 다만 "구조 결정 선행" 수용 — 본 회의록이 구조 결정.
- **Engineer 옵션 B NO-GO** — 유효 (배포 전 한정). 큐 23 별건으로 이연 — Engineer 우려 해소 (배포 안정화 후).
- **Security savings_used NO-GO 3건** — 유효 — 큐 22-별건1로 분리.
- **Specialist F retroactive 우려** — paid snapshot에 4종 추가 캡처로 부분 해소.

---

## 🛠 car-erp 영향 분석 (Opus 4.7 산출)

### 취약점 (Vulnerabilities)
1. 영업이 `deposit_down_payment` / `interim_payment` / `advance_payment1·2` 자유 수정 → 미수율 즉시 변동 → 큐 9 G1 50% B/L 잠금 우회 가능
2. `savings_used` 변경 시 `Vehicle::saved` H6 훅이 SavingsStatus 거래 자동 생성 → 실물 적립금 확인 없이 잔액 차감
3. `FinalPayment::creating` 훅 부재 → paid 차량에 신규 잔금 행 추가 가능 (기존 구멍)
4. `paid Settlement confirmed_snapshot`에 입금 4종 미캡처 → paid 이후 "어느 시점에 confirmed였는가" 재현 불가
5. `LEDGER_LOCK_FIELDS`에 입금 4종 미포함 → confirmed 후 직접 수정 차단 없음

### 보완사항 (Improvements)
1. 옵션 C — `sale_deposits_confirmed_at` 1 컬럼 + 분자 필터
2. paid Settlement snapshot에 입금 4종 + confirmed_at 추가
3. `LEDGER_LOCK_FIELDS` 4컬럼 확장
4. `FinalPayment::creating` 훅 paid 차량 신규 행 차단
5. unconfirm / 반려 정책 + audit (Codex+Gemini)
6. G1 우회로 차단 — 분자에서 미확정 분 제외 단일 출처 (Codex)
7. 큐 22-별건1 — savings_used confirm 게이트 (Security 3건 해소)
8. 큐 22-별건2 — 매입 측 (down_payment/selling_fee_payment) 게이트
9. 큐 23 — 옵션 B 전환 (final_payments type enum 통합, 배포 후)

### 코드 수정 (Code Changes) — 큐 22 (옵션 C)

- `app/Models/Vehicle.php`
  - `getSaleUnpaidAmountAttribute` 분자: `sale_deposits_confirmed_at IS NULL` 시 4컬럼 제외
  - `LEDGER_LOCK_FIELDS`에 `deposit_down_payment`, `interim_payment`, `advance_payment1`, `advance_payment2` 추가
  - `refreshCaches()` 분자 영향 확인
- `app/Models/Settlement.php::saving` — `confirmed_snapshot`에 입금 4종 + `sale_deposits_confirmed_at` 추가 캡처
- `app/Models/FinalPayment.php::creating` — paid Settlement 존재 시 신규 행 차단
- `app/Services/PaymentConfirmationService.php`
  - `confirmSaleDeposits(Vehicle, User)` 신규
  - `unconfirmSaleDeposits(Vehicle, User, reason)` 신규 (Gemini 반려·unconfirm 정책)
  - `canConfirmFinance` 권한 가드, self-confirm 차단
- `resources/views/livewire/erp/transfers/index.blade.php` — 5번째 탭 "입금 확정"
- `resources/views/livewire/erp/vehicles/index.blade.php` — 판매 탭 입금 영역 confirmed/pending 표시

### 신규 추가 (New Additions)

- 마이그: `vehicles`에 `sale_deposits_confirmed_at` (nullable timestamp) + `sale_deposits_confirmed_by_user_id` (nullable FK users)
- 테스트: `DepositConfirmGateTest` 신규 8~10 케이스
  - confirm 전 분자 미반영 / confirm 후 반영
  - G1 강화 케이스
  - paid snapshot 캡처
  - SoD (self-confirm 차단)
  - unconfirm + audit
  - LEDGER_LOCK_FIELDS 확장 후 직접 수정 차단

### 모순·NO-GO 처리 로그
- QA "알림 수준" 권고 → Codex+Gemini 양쪽 기각 → 자동 무효, "구조 결정 선행"만 수용
- Engineer 옵션 B NO-GO → 큐 23 이연으로 해소
- Security savings_used NO-GO 3건 → 큐 22-별건1 분리
- Specialist F retroactive 우려 → paid snapshot 보강으로 부분 해소

---

## 🔗 참조

### 관련 과거 회의록
- `2026-05-17-purchase-sale-finance-gate.md` — 큐 20 A안 분자 정의 (잔금 confirm 게이트)
- `2026-05-18-vehicle-ledger-field-lock.md` — 큐 21 ledger lock + 사용자 정정 3건
- `2026-05-16-finance-gate-roundtable.md` — 큐 19-F SoD 패턴 (canConfirmFinance)
- `2026-05-14-3way-workflow-policy.md` — G1·G2·G3 분류 정의

### 코드 참조
- `app/Models/Vehicle.php` L406~415 (LEDGER_LOCK_FIELDS), L500~516 (savings_used H6 훅), L836~846 (분자 A안)
- `app/Models/FinalPayment.php` L40~69 (updating 잠금, creating 부재)
- `app/Models/Settlement.php` L73~111 (paid snapshot — 입금 6종 미포함)
- `app/Services/PaymentConfirmationService.php` L41~65, L100~104
- `SKILLS.md §13` 미수율 분모 단일 출처
- `tests/Feature/DashboardActionCountsTest.php` L51~61
- `tests/Feature/G1BlLockTest.php` L57~66
- `tests/Feature/PaymentConfirmationServiceTest.php` L68

### 부서 프롬프트 (v1.2)
- `docs/meetings/departments/{po,engineer,qa,security,ops,specialist}.md`
