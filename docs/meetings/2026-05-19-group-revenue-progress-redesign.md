# 📅 회의록: 새회의 3건 묶음 — 그룹셋·매출/현금흐름·진행상태 축소

- 일시: 2026-05-19
- 강도: 풀회의 (/회의 슬래시)
- 안건 유형: 마이그레이션 + 정산 마진 공식 + 진행상태 10단계 + 대시보드 카운트
- 자동발동 여부: yes (사용자 명령어 호출)
- 발동 부서: PO + Engineer + QA + Security + Ops + Specialist [B 데이터 무결성 + F 회계·정산 감사]

## 안건

새회의.txt에서 도출된 도메인 모델 변경 3건. car-erp는 testing 단계 + 배포까지 한 달 여유.

**3-A 그룹셋 (바이어 그룹 + 차량 그룹화)**
사용자 명세: "바이어가 차1·차2·차3 동시 구매 시 그룹화. 매입은 각각, 판매는 그룹 일괄. 그룹 보기는 차량별 판매가/미수금/입금 + 그룹 합계도 함께. 서류(통관·선적 포함)도 그룹으로 묶인 내용으로 출력."
- 신규 `vehicle_groups` 테이블 + `Vehicle.group_id` FK
- 서류 5종(말소·Invoice·Sales Contract·CIPL 2종) 단일 차량 vs 그룹 분기
- transfers/receivables/dashboard 집계 영향

**3-B 매출 vs 현금흐름 정의 분리**
사용자 우려: "판매중·미수 500만 상태에서 관리자 대시보드 '이달 판매가 1000만' 표시 — 실제 현금흐름과 다른 양상."
- AdminDashboardTest KPI 정의 + ErpDashboard '이달 판매' 카운트 + 채권 KPI
- SKILLS §13 분모 단일 출처 박스와 충돌 가능성
- 큐 22-A 옵션 B(payment transaction 기반)와 사상 일치

**3-C 진행상태 10단계 → 축소 (선적 = 거래완료, DHL/씬 별도)**
사용자 명세: "선적까지이고, DHL은 별도의 문서인거 같고, 씬(신용보험 추정)도 별도. 선적에서 B/L 문서 업로드되면 거래완료."
- 현행 v2: `거래완료 = dhl_request && bl_document`. 사용자 요청: `bl_document` 단독
- `Vehicle::progress_status()` 우선순위 재정의, `progress_status_cache` rebuild
- DHL/씬 별도 entity, pipeline-strip 노드 수 변경

---

## 💬 부서별 발언 (Sonnet 4.6)

### 📋 PO
**판정**: 3-A HOLD / 3-B 조건부 GO / 3-C NO-GO — **종합 NO-GO** (3-C 설계 확정 전 3-A 연동 불가)

**3-A HOLD 발언**: `vehicle_groups` 테이블 grep 0건 — 완전 신규 도메인 (Python ERP 흔적 없음, 메모리 검증). 범위 폭발 위험 — `final_payments`·`receivable_histories`·서류 5종·`Vehicle::scopeAction()` 14액션·pipeline-strip 모두 "차량 1건 단위" 전제. 큐 22-A(`final_payments.type` enum 통합) 진행 중 모델 2회 변경 위험.
- (a) 차단: 큐 22-A `final_payments` 모델 확정 전 group_id FK 설계 불가
- (b) 최소조건: 큐 22-A~C 완료 + 현장 그룹 판매 월 빈도 확인 + 서류 그룹 분기 정책
- (c) 대안: `vehicles.group_memo varchar(255)` 컬럼 1개 추가 → 영업이 수동 그룹 식별자 기재. 공수 30분

**3-B 조건부 GO 발언**: `/admin/dashboard kpis()` L77~80 현재 `sale_price > 0` 전체를 "판매가 KRW 합계"로 계상 — 미수 차량 포함. confirmed finalPayments 합계를 **신규 KPI 카드 1개**로만 추가, 기존 "판매가 합계" 카드 제거는 별개 안건으로 분리. 큐 22-A 완료 후 진행.

**3-C NO-GO 발언**: `Vehicle.php` L1042~1044 `settlement_create_needed`, L967/977 active 필터, L786 v1 grandfather, L1071 `receivable_after_shipping`, L577 H1 gate — 5곳이 `dhl_request=true`를 거래완료 기준으로 의존. 1줄 변경 시 대시보드 카운트 ↔ vehicles SQL where 불일치 즉시 발생.
- (a) 차단: 5곳 일괄 재정의 없이 강행 시 SKILLS §9 정합성 위반
- (b) 최소조건: DHL·씬 entity 스키마 확정 + 5곳 연쇄 변경 QA 서명 + v1 grandfather 정책 + 정산 role 트리거 확인
- (c) 대안: 진행상태 현행 10단계 유지. UI에서 "DHL 발송 신청" 라벨을 "거래완료 처리"로 변경 또는 pipeline-strip에서 두 노드를 묶어 표시. 코드 변경 0, 마이그 0

---

### ⚙️ Engineer
**판정**: 3-A 조건부 GO / 3-B 조건부 GO / 3-C NO-GO → 조건부 GO 전환 가능

**3-A 조건부 GO**: `vehicle_groups` + `vehicles.group_id` FK 신규 nullable. 롤백 SQL `ALTER TABLE vehicles DROP COLUMN group_id` 1줄 가능. **단 서류 5종 분기가 핵심 위험**: `VehicleDocumentController::show()` L17~47이 단일 Vehicle만 받음. 그룹 CIPL은 `VehicleCiplGenerator` 시그니처 변경 + Excel 다행 삽입 신규. **공수 10~12h** (마이그 0.5h + 모델 1h + 서류 분기 6~8h + dashboard/transfers 집계 3h).
- 조건: 서류 그룹 발급은 별도 큐, `with()` eager load 명시 후 N+1 검증, 큐 22-A type enum 통합 완료 후

**3-B 조건부 GO**: `dashboard.blade.php` L76~79 `saleKrw` 계산은 `sale_price × exchange_rate` 단순 합산. **신규 KPI 박스 추가**(`FinalPayment::whereNotNull('confirmed_at')->...->sum('amount')`)로 기존 `sale_total_krw` 교체가 아닌 병행. 월별 차트 `salesBuckets` (L161 `chunk(1000)`) 내부 per-row 관계 접근 금지. **공수 2~5h**.
- 조건: 기존 `sale_total_krw` KPI 반드시 유지. 교체는 SKILLS §13 단일 출처 위반.

**3-C NO-GO → 조건부 GO 전환 가능**:
- (a) 차단: L769 v2 거래완료 조건 + L786 v1 grandfather 모두 수정 필요. **L967 `active = dhl_request=false`** — `거래완료 = bl_document`로 바꾸면 L976~978 12개 액션 active 필터 전체 깨짐. L1042 `settlement_create_needed` 조건도 변경. L577 H1 gate 역할 모호해짐.
- (b) 최소조건: 거래완료 정의 + active 재정의 + settlement_create_needed SQL 묶음 마이그 + **v3 rule_version 신설** + `php artisan vehicles:rebuild-progress-cache` 전체 + DHL 별도 entity 또는 상태 컬럼 결정. **공수 8~12h**
- (c) 대안: 거래완료 정의 변경 없이 `dhl_needed` 액션을 "bl_document=true AND dhl_request=false"로 좁히고 대시보드 라벨만 "DHL 발송 대기"로 변경. **공수 1~2h**

---

### 🧪 QA & Domain Integrity
**판정**: 3-A 조건부 GO / 3-B **HOLD** / 3-C **NO-GO**

**3-A 조건부 GO**: `progress_status_cache` / `receivable_risk` / `sale_unpaid_amount_krw_cache` 3종 캐시가 1대 단위 설계라 그룹 집계와 구조 충돌. `scopeAction('receivable_risk')`의 `whereIn(...)` 개별 필터로는 그룹 표현 불가. **깨질 테스트**: `G3ReceivableClassificationTest.php` L127~158, `VehicleDocumentControllerTest.php`. **회귀 시나리오 20분**.

**3-B HOLD**:
- (a) 차단: SKILLS §13 분모 단일 출처 박스에 `confirmed_final_payments` 별도 분모를 6번째로 추가하면 `sale_total_amount`와 다른 분모를 가진 두 미수율이 병존 → 운영 의사결정 오독.
- (b) 최소조건: "매출 KPI = `sale_total_amount × exchange_rate`(유지)"와 "현금흐름 KPI = `Σ confirmed_final_payments × exchange_rate`"를 UI 명확 라벨 분리 + §13 5곳 정합 표에 6번째 항목 명시 후 구현
- (c) 대안: 별도 KPI 신설 대신 기존 `sale_total_krw` 박스 아래 "(미수금 `sale_unpaid_amount_krw_cache` 합산)" 서브라인 추가. 분모·분자 정의 현행 유지
- **깨질 테스트**: `AdminDashboardTest.php` L66~93, L199~228

**3-C NO-GO**:
- (a) 차단: `settlement_create_needed`(L1043) `where('dhl_request', true)` 고정 + `activeOnly` 목록(L976~978) `dhl_request=false` 기준. 거래완료 = bl_document만으로 변경 시 17개 액션 SQL과 `progress_status_cache` 판정이 즉시 불일치. SKILLS §9 100% 일치 위반.
- (b) 최소조건: `settlement_create_needed` SQL → `where('progress_status_cache', '거래완료')` 교체, `activeOnly` 기준 → `progress_status_cache != '거래완료'`, 17개 액션 전수 재검토, `rebuild-progress-cache` 실행
- (c) 대안: `dhl_request && bl_document` 현행 유지 + DHL 별도 entity 신설하되 `dhl_request` 플래그를 DHL entity 생성 시 자동 set
- **깨질 테스트 5~8건**: `WorkflowGapTest` L431·L416, `DashboardActionCountsTest` L74, `G3ReceivableClassificationTest` L102, `G1BlLockTest` 전체

---

### 🔒 Security & Compliance
**판정**: 3-A HOLD / 3-B GO / 3-C 조건부 GO

**3-A HOLD**: `vehicle_groups` 그룹 서류 라우트 신설 시 **1회 호출로 N대 RRN 묶음 노출 새 공격 표면**. 현재 `VehicleDocumentController::show()` `erp` 미들웨어만 통과(`routes/web.php` L24), `DocumentAccessLog::create()`는 단일 `vehicle_id` 기준. 그룹 라우트에 미들웨어 누락 시 기존 단건 통제 전부 우회. **SoD 결손**: `Vehicle::$fillable`에 `buyer_id`·`salesman_id` 있어 영업 본인이 group_id 자가 배정 시 C7-b(본인 격리) 위반 경로. `AUDITED_COLUMNS` L381~397에 `group_id` 미포함 → 그룹 배정 변경 audit 미기록.
- (a) 차단: 그룹 묶음 서류 라우트 신설 → N대 RRN 묶음 노출 + `document_access_logs` 단건 구조라 N대 로그 누락 가능
- (b) 최소조건: 그룹 라우트 `admin` 미들웨어 강제, `DocumentAccessLog::insert(array)` N행 일괄 기록, `AUDITED_COLUMNS`에 `group_id` 추가, 그룹 PDF Blade에 `nice_reg_owner_rrn` 하드-마스킹(`substr+`-*******`)
- (c) 대안: **그룹 화면에서 차량별 기존 단건 링크 나열** — 신규 라우트·컨트롤러 없이 기존 통제 그대로 재활용, 묶음 RRN 노출 표면 제로

**3-B GO**: `/admin/dashboard` `admin-dashboard` 미들웨어 적용, 안건은 KPI 추가만이므로 새 라우트 불필요. `final_payments`·`purchase_balance_payments`에 개인정보 컬럼 없음. **특이사항 없음**.

**3-C 조건부 GO**: `getProgressStatusAttribute()` 1줄 변경은 권한 영향 없음. 위험은 **DHL·신용보험 신규 entity 분리** — 신규 모델 전용 라우트 미들웨어 매핑 누락 시 무인증 접근. 조건: 신규 `dhl_shipments`·`credit_insurances` 라우트에 반드시 `['auth','verified','erp']` 이상 + 민감 필드(보험 수익자 계좌·증권번호) `AUDITED_COLUMNS` 확장 + 암호화 cast 검토 + `LEDGER_LOCK_FIELDS`에 연동 금액 컬럼 추가.

---

### 🚀 Ops & Deploy
**판정**: 3-A 조건부 GO / 3-B GO / 3-C HOLD

**3-A 조건부 GO**: 최신 마이그 패턴(2026-05-18)과 동일 구조 안전. 롤백 SQL 2줄 가능. **Python ERP 미실재** 확인 (메모리 일치) — 데이터 충돌 가정 자체가 stale. 그룹 일괄 PDF 추가 시 Queue Job 분리 필수 (동기 dompdf 호출 PHP 프로세스 점유). `progress_status_cache` 무관. **다운타임 1~3초** (`ADD COLUMN group_id` + `ADD FOREIGN KEY` InnoDB metadata lock).

**3-B GO**: 마이그 없음 — computed accessor + SQL 집계만. **다운타임 0초**. `vehicles:rebuild-caches` 불필요. 큐 22-A 완료 의존성만.

**3-C HOLD**: `getProgressStatusAttribute()` 변경 = **자동 풀회의 강제 키워드**. DHL 신설 마이그 시 vehicles 컬럼 변경 + 데이터 이관 → **다운타임 5~15초**. `rebuild-progress-cache` 전 차량 chunk(200) 재계산 필수. `applyActionFilter()` `dhl_needed` 액션 + 대시보드 카운트 SQL where 전면 재정합 → QA 검증 선행 없으면 Ops GO 불가.

---

### 🔧 Specialist [B. 데이터 무결성]
**판정**: 3-A HOLD / 3-B 조건부 GO / 3-C HOLD

**3-A HOLD**:
- (a) 차단: 큐 22-A `final_payments.type` enum과 동시 마이그 시 롤백 SQL 1줄 불가 + 그룹별 미수금 집계 단일 출처 미정의
- (b) 최소조건: 큐 22-A 완료·안정화 후 별도 마이그. 그룹 집계는 `refreshCaches()` 패턴과 분리된 별도 read-only 집계 쿼리 + SKILLS §13 명시
- (c) 대안: `group_id` 대신 **`group_tag varchar(50)` 비정규화 컬럼**으로 1단계 — FK 마이그 없이 INSTANT ADD, `WHERE group_tag = ?` groupBy on-the-fly 계산

**3-B 조건부 GO**: 큐 22-A 옵션 B의 분자 단순화(`finalPayments->whereNotNull('confirmed_at')->sum('amount')`)와 동일 SQL — **단일 출처 통합 가능**. SKILLS §13 박스에 "매출(`sale_total_amount`) / 현금수령(confirmed finalPayments) / 미수(매출-현금수령)" 명칭 분리 기재. `confirmed_snapshot`에 `confirmed_cash_received` 키 1줄 추가. 기존 paid retroactive 없음.

**3-C HOLD**:
- (a) 차단: v1 분기(L764~801) 수정 시 `dhl_request=true + bl_document=NULL` row 자동 강등 → Settlement paid·receivable 집계 오염
- (b) 최소조건: 3-C 변경 범위를 v2 분기 내부로 한정. v1 분기 완전 무변경. `php artisan vehicles:backfill-progress-rule-v2 --dry-run` 영향 row 사전 확인
- (c) 대안: 진행상태 "축소"가 특정 단계 병합이라면 v3-only 신규 단계 정의로 구현 — v1 row는 v1 분기가 보호

---

### 🔧 Specialist [F. 회계·정산 감사]
**판정**: 3-A 조건부 GO / 3-B 조건부 GO / 3-C **HOLD**

**3-A 조건부 GO**: Settlement `confirmed_snapshot` L93~110은 차량 1대 단위 캡처. 그룹 합계는 `group_snapshot` 별도 신설 필요. 정산 공식(판매금원화·VAT 9%)은 차량별 계산이므로 그룹 단위 적용 시 환율·면장금액 가중합산 신규 구현. 기존 paid retroactive 없음.

**3-B 조건부 GO**: Vehicle.php L836~846 분자가 큐 22-A 옵션 B 단순화(`finalPayments->whereNotNull('confirmed_at')->sum('amount')` type 무관)와 **동일 SQL** — 중복. **단일 출처 통합**으로 해소. 정산 공식 §5의 판매금원화(면장 기반)와 `sale_price` 매출이 다른 정의임을 §13에 주석 명시. `confirmed_snapshot`에 `confirmed_cash_received` 키 추가(1줄). VAT 9% 불변 — 회계 공식 변경 아님.

**3-C HOLD**:
- (a) 차단: `settlement_create_needed`(L1043) `where('dhl_request', true)` 트리거를 `bl_document`로 당기면 DHL 이후 비용 미확정 상태로 `confirmed_snapshot` 캡처 → paid 후 cost_total 수정 차단(L62 H4 guard)으로 **회계 오류 잠금**
- (b) 최소조건: DHL 이후 발생 가능한 비용 항목 목록 확인 + "정산 생성 시점 bl_document 시 미포함 비용 없음" 운영 확인 + 정산 role 담당자 워크플로우 검토
- (c) 대안: `settlement_create_needed` 트리거 변경은 진행상태 축소와 **별도 큐 분리**. 진행상태 축소만 별도 적용

---

## 🧩 중간 회의 결과 (Opus 4.7 1차 취합)

### 부서별 판정 매트릭스

| 안건 | 📋 PO | ⚙️ Engineer | 🧪 QA | 🔒 Security | 🚀 Ops | 🔧 B 데이터 | 🔧 F 회계 |
|---|---|---|---|---|---|---|---|
| **3-A 그룹셋** | HOLD | 조건부 GO | 조건부 GO | HOLD | 조건부 GO | HOLD | 조건부 GO |
| **3-B 매출/현금흐름** | 조건부 GO | 조건부 GO | HOLD | GO | GO | 조건부 GO | 조건부 GO |
| **3-C 진행상태 축소** | NO-GO | 조건부 GO (전환) | NO-GO | 조건부 GO | HOLD | HOLD | HOLD |

### 합의 영역
1. **큐 22-A(옵션 B 판매 입금 4컬럼 통합) 완료가 3건 전체 선행 조건** (7부서 중 5부서 명시)
2. **3-B 신규 KPI 박스 추가** 방식이 분모 단일 출처 보존 — 기존 KPI 교체 만장일치 NO
3. **3-C는 단순 1줄 변경이 아닌 17개 액션 + 정산 트리거 + grandfather 정책 패키지** — 7부서 모두 인정
4. **Python ERP 실재하지 않음** — 7부서 사전 검증 일치

### 충돌 영역
1. **3-A 그룹 서류**: 신규 라우트 신설 vs 기존 단건 링크 재활용 (Security 대안)
2. **3-B 매출 정의**: SKILLS §13에 6번째 분모 추가 vs 큐 22-A 분자와 단일 출처 통합
3. **3-C DHL entity**: 신규 entity 분리 vs v2 상태모델만 변경

---

## 🌐 사외이사 의견 (Codex / Gemini)

### [Codex]
1) **놓친 리스크**: RRN 대량노출이 그룹 문서뿐 아니라 권한/감사로그/다운로드까지 확장. 3-C v1/v2 혼재 시 검색·정산·DHL 상태 불일치가 고객 CS로 터질 수 있음. KPI 추가는 같은 매출을 다른 이름으로 중복 집계할 위험.

2) **충돌 판정**: 3-A는 **새 route 금지**, 기존 single-link 재사용 + group_id만 조건부. 3-B는 **22-A option B에 붙이고 6번째 분모 신설 보류**. 3-C는 DHL entity 신설보다 **내부 v2 상태모델 선행**.

3) **글로벌 ERP 패턴**: party/grouping은 master data, revenue와 cash/bank는 분리, shipment/insurance는 거래 부속 entity, status는 **최소 핵심 단계 + derived flag**.

4) **우선순위**: **3-B GO → 3-A HOLD after 22-A → 3-C NO-GO**.

5) **3-C 자체 NO-GO**:
- (a) 상태 재정의 패키지 부재
- (b) 5개 H1 진입점 영향표 부재
- (c) v1 grandfather + active filter 마이그레이션안 부재

### [Gemini]
1) **놓친 리스크**: 3-A 그룹화 시 **'부분 취소' 예외 처리 부재** (그룹 중 1대만 선적 불가 시 전체 블로킹). 3-C는 단순 상태값 수정을 넘어 **v1 데이터 정합성 검증이 개발 공수 50%+ 점유**.

2) **충돌 판정**: 3-A는 기존 단건 링크를 **Group Mode Flag로 확장**하여 유지보수 일원화. 3-B는 **'발생주의(매출)' + '현금주의(수금)' 병행 표기**가 글로벌 표준 — 교체 아닌 오버레이. 3-C는 DHL을 별도 Entity로 분리하여 도메인 파편화 방지.

3) **글로벌 ERP 패턴**: SAP/Odoo 'Parent-Child' 또는 'Project Code', 매출(Accrual)과 자금(Cash Flow) 보고서 엄격 분리. 3-B는 단순 KPI 추가가 아닌 **회계 모듈의 기초**.

4) **우선순위**: **3-B(경영 가시성) > 3-A(물류 효율) > 3-C(상태 최적화)**. 3-C는 시스템 전반 Gate를 건드리는 '심장 수술' — 한 달 내 가장 위험.

5) **3-C 자체 NO-GO**:
- (a) 상태 축소가 기존 Settlement 필터 + H1 Gate 5곳과 논리적 충돌
- (b) 한 달 내 v1 데이터 하위호환성(Grandfathering) 완전 확보 불가
- (c) 트리거 변경 시 실시간 연동된 외부 API(신용보험 등) 정합성 파괴 위험

### 사외이사 합의
- **우선순위 일치**: 3-B GO 우선 → 3-A 22-A 후 → 3-C 가장 위험 (양쪽 동일)
- **3-A 방식**: 양쪽 모두 "새 라우트/엔티티 신설 자제, 기존 단건 통제 재활용 또는 확장"
- **3-B 방식**: 양쪽 모두 "교체 NO, 오버레이/병행"
- **3-C 판정**: Codex NO-GO, Gemini "심장 수술 가장 위험" → 사실상 동일

### 사외이사 차이
- **3-C DHL entity**: Codex(v2 상태모델 선행, entity 후순위) vs Gemini(별도 entity로 도메인 파편화 방지) → 다수 부서(Engineer, Spec-B)는 진행 시 v3 rule_version 권고 → **Codex 손** (entity 분리는 별도 큐로 이연)

---

## 🚨 NO-GO 상세 (3-C)

**차단 사유 (4-actor 합의 — PO + QA + Codex + Gemini)**:
- `Vehicle.php` L1042~1044 `settlement_create_needed`, L967/977 active 필터, L786 v1 grandfather, L1071 `receivable_after_shipping`, L577 H1 gate — **5곳이 `dhl_request=true`를 거래완료 기준으로 의존**. 일괄 재정의 없이 1줄 변경 시 SKILLS §9 정합성 즉시 위반.
- DHL 이후 비용 미확정 상태로 `confirmed_snapshot` 캡처 → paid 후 cost_total 수정 차단으로 **회계 오류 잠금** (Spec-F).
- v1 grandfather row 자동 강등 위험 — `progress_status_rule_version=1` 분기 수정 시 retroactive 강등 (Spec-B).
- 한 달 내 v1 데이터 하위호환성 완전 확보 불가 + 신용보험 외부 API 정합성 파괴 위험 (Gemini).

**수용 가능한 최소 조건**:
1. 5곳 SQL 일괄 재정의 패키지 마이그
2. v3 rule_version 신설 + v1 grandfather 분기 완전 무변경
3. `php artisan vehicles:rebuild-progress-cache --dry-run` 영향 row 사전 확인
4. `settlement_create_needed` 트리거 변경은 별도 큐 분리 — DHL 이후 비용 확정 운영 정책 성문화 선행
5. DHL·신용보험 entity 분리 결정은 별도 큐로 이연 (Codex 권고)

**대안 (만장일치 권장)**:
- **3-C-light**: 진행상태 정의 변경 없이 UI 라벨 조정만. `dhl_needed` 액션 라벨을 "DHL 발송 대기"로, pipeline-strip에서 "선적완료 → 거래완료" 두 노드 묶어 표시. 코드 변경 0, 마이그 0, 공수 1~2h.
- **DHL/씬 별도 entity**는 큐 24+ 별도 안건으로 분리 (배포 후 진행)

---

## 🏁 최종 권고 (Opus 4.7 최종 취합)

| 안건 | 최종 판정 | 우선순위 |
|---|---|---|
| **3-B 매출 vs 현금흐름** | **GO (조건부)** | 1순위 — 큐 22-A 완료 직후 |
| **3-A 그룹셋** | **HOLD** | 2순위 — 큐 22-A~C 완료 후 + 현장 빈도 확인 + interim 대안 가능 |
| **3-C 진행상태 축소** | **NO-GO** → 3-C-light 대안 채택 | 라벨 조정만 즉시 가능 / 본격 변경은 배포 후 별도 큐 |

### 근거 (1줄)
**사외이사 양쪽 + 부서 7명 중 6명이 3-B GO → 3-A HOLD → 3-C NO-GO 순서에 합의**. SAP/Odoo 표준이 revenue/cash 분리 + master data grouping + status 최소화 + shipment/insurance 부속 entity를 권장하므로 SSANCAR도 동일 사상으로 진행 가능하나, 1인 개발 한 달 컨텍스트에서 3-C 패키지는 단독 처리 불가.

### 필수 선행 작업
1. **큐 22-A(옵션 B 판매 입금 4컬럼 통합) 완료** — 메모리 §22-A-1~8 단계 (총 11~14h)
2. 3-B 진입 전 SKILLS §13 단일 출처 박스 통합 기재안 사전 합의
3. 3-A 진입 전 현장 영업 담당자 인터뷰 — "그룹 판매 월 N건 발생" 확인 (월 1건 미만이면 `group_memo varchar(50)` interim 대안)

### 조건 (조건부 GO — 3-B)
1. 기존 `sale_total_krw` KPI 카드 유지 — 교체 X, **신규 박스 1개 추가만**
2. 신규 KPI 정의가 큐 22-A 옵션 B 분자(`finalPayments->whereNotNull('confirmed_at')->sum('amount')`)와 **단일 출처 통합** — SKILLS §13에 "매출(`sale_total_amount`) / 현금수령(confirmed finalPayments) / 미수(매출-현금수령)" 명칭 분리 기재
3. `Settlement::confirmed_snapshot`에 `confirmed_cash_received` 키 1줄 추가
4. `AdminDashboardTest.php` L66~93·L199~228 기존 케이스 유지 + 신규 KPI 박스 검증 1건 추가
5. 외화 차량 환율 0 케이스 null 가드 (Spec-F)

### 보류 사유 (HOLD — 3-A)
1. 큐 22-A `final_payments.type` enum 통합 미완료 — group_id FK가 분자 모델에 동시 영향 시 롤백 불가
2. 현장 그룹 판매 빈도 미확인 — 비즈니스 필요성 정량화 부재
3. 그룹 서류 라우트 신설 시 N대 RRN 묶음 노출 통제 정책 미합의

### 보류 사유 (NO-GO — 3-C)
1. 5곳 SQL(`settlement_create_needed`·active·v1 grandfather·`receivable_after_shipping`·H1 gate) 일괄 재정의 패키지 부재
2. v1 grandfather row 자동 강등 위험 (Spec-B + Codex)
3. DHL 이후 비용 확정 운영 정책 미성문화 → `confirmed_snapshot` 회계 오류 잠금 위험 (Spec-F)
4. 한 달 내 v1 데이터 하위호환성 완전 확보 불가 + 외부 API 정합성 파괴 위험 (Gemini)

---

## 🛠 car-erp 영향 분석 (Opus 4.7 산출)

### 취약점 (Vulnerabilities)
1. **3-C 시도 시**: `Vehicle.php` L769·L786·L967·L977·L1042·L577 5곳이 `dhl_request=true`를 거래완료 기준으로 의존 — 1줄 변경 시 대시보드 카운트 ↔ vehicles SQL where 불일치 즉시 발생 (SKILLS §9 위반)
2. **3-C v1 grandfather**: `progress_status_rule_version=1` row 자동 강등 → paid Settlement 연결 차량 진행상태 사후 변경
3. **3-A 그룹 서류 라우트 신설 시**: 1회 호출 N대 RRN 묶음 노출 새 공격 표면 + `document_access_logs` 단건 vehicle_id 구조 N대 로그 누락
4. **3-B SKILLS §13 분모 6번째 신설 시**: 두 미수율 병존 → 운영 의사결정 오독
5. **3-A `group_id` `AUDITED_COLUMNS` 미포함**: 그룹 배정 변경 audit 미기록 (SoD 결손)

### 보완사항 (Improvements)
1. **3-A interim**: `vehicles.group_memo varchar(50)` 또는 `group_tag varchar(50)` 비정규화 컬럼 — 영업 수동 그룹 식별자 기재, FK 마이그 없이 INSTANT ADD (PO + Spec-B 합의)
2. **3-B 신규 KPI 박스**: 큐 22-A 분자와 단일 출처 통합, `confirmed_snapshot.confirmed_cash_received` 1줄 추가
3. **3-C-light**: UI 라벨 조정만 — `dhl_needed` 라벨 "DHL 발송 대기"로 변경, pipeline-strip 두 노드 묶어 표시
4. **3-C 본격 변경 시(별도 큐)**: v3 rule_version 신설, v1 분기 완전 무변경, DHL/씬 entity 분리는 큐 24+ 이연
5. **그룹 도입 시(별도 큐)**: 그룹 라우트 `admin` 미들웨어 강제, `DocumentAccessLog::insert(array)` N행, `AUDITED_COLUMNS`에 `group_id` 추가, 그룹 PDF RRN 하드-마스킹

### 코드 수정 (Code Changes) — 큐 22-A 완료 후 진행
**3-B (즉시 진행 가능, 큐 22-A 완료 직후)**:
- `resources/views/livewire/admin/dashboard.blade.php` L66~95 — `kpis()` 반환값에 `confirmed_cash_received_krw` 항목 추가
- `app/Models/Settlement.php` L93~110 — `confirmed_snapshot` 캡처 블록에 `confirmed_cash_received` 키 추가
- `SKILLS.md §13` — 단일 출처 박스에 "매출(`sale_total_amount`) / 현금수령(confirmed finalPayments) / 미수" 명칭 분리 기재
- `tests/Feature/AdminDashboardTest.php` — 신규 KPI 검증 케이스 1건 추가
- 공수: 2~5h

**3-A interim (선택)**:
- 마이그: `add_group_memo_to_vehicles` — `vehicles.group_memo varchar(50) nullable`
- `app/Models/Vehicle.php` `$fillable`에 `group_memo` 추가
- `resources/views/livewire/erp/vehicles/index.blade.php` 매입 탭에 입력 필드 추가
- 공수: 30분~1h

**3-C-light (선택)**:
- `resources/views/livewire/admin/dashboard.blade.php` `dhl_needed` 라벨 변경
- `app/View/Components/erp/pipeline-strip.blade.php` 또는 동등 컴포넌트 — "선적완료 → 거래완료" 두 노드 시각적 묶음
- 공수: 1~2h

### 신규 추가 (New Additions) — 별도 큐로 분리
**큐 23(가칭) — 3-A 그룹셋 정식 도입** (큐 22-A~C 완료 후 + 현장 빈도 확인):
- 마이그: `create_vehicle_groups_table` + `add_group_id_to_vehicles`
- 모델: `VehicleGroup` + `Vehicle::group()` 관계
- `Vehicle::$fillable`에 `group_id`, `AUDITED_COLUMNS`에 `group_id` 추가
- `VehicleDocumentController` 그룹 라우트는 **별도 큐 분리** (그룹 PDF Blade RRN 마스킹 사전 합의 후)
- 공수: 10~12h (서류 그룹 분기 제외)

**큐 24(가칭) — 3-C 진행상태 패키지** (배포 후 별도 큐):
- v3 `progress_status_rule_version` 신설
- 5곳 SQL 일괄 재정의 (`settlement_create_needed`·active·v1 grandfather·`receivable_after_shipping`·H1 gate)
- `rebuild-progress-cache --dry-run` 영향 row 사전 확인
- 17개 액션 active 기준 재정의
- DHL/씬 entity 분리는 다시 별도 큐 25(가칭)로 이연 — 신용보험 외부 API 정합성 사전 확인 필요
- 공수: 8~12h + 신규 entity 별도

### 모순·NO-GO 처리 로그
- **PO 종합 NO-GO** → 3-C 단독 NO-GO로 격하 (3-B/3-A는 분리 진행 가능 — Codex 우선순위 권고 채택)
- **Engineer "3-C NO-GO → 조건부 GO 전환 가능"** → 다수 부서(Spec-F·Spec-B·QA·Codex·Gemini) HOLD/NO-GO 우세로 **현 시점 NO-GO** 유지. 큐 24로 이연 후 조건부 GO 전환 검토
- **Gemini "DHL 별도 Entity로 도메인 파편화 방지"** vs **Codex "v2 상태모델 선행"** → Codex 우선 채택. DHL entity 분리는 큐 25+로 이연
- **QA "3-B HOLD (§13 6번째 분모 신설)"** → Spec-F + Spec-B + Codex 합의로 **단일 출처 통합** 방식 채택 → HOLD 해제, 조건부 GO 전환
- **Security "그룹 라우트 신설 4조건"** vs **Codex/Gemini "단건 링크 재사용/확장"** → 사외이사 합의 채택, 그룹 서류 라우트는 별도 큐 이연

---

## 📝 사용자 정정 (2026-05-19 후속)

회의록 정독 후 사용자가 워크플로우 체크리스트(`docs/workflow-checklist.md`)와의 정합성 점검 요청. 새회의 6번 "입금현황 재무 전용" 해석 모호성을 확정.

### 새회의 6번 해석 확정 — 해석 B 채택

**사용자 원문 (새회의.txt 6번)**:
> "입금현황은 매입도 그렇고 판매도 그렇고 재무만 손댈 수 있는 게 맞을 것 같아. 어차피 돈 입금된 거 확인은 재무에서 하는 건데 재무가 바로 적으면 되는데 영업이 다시 그걸 받아적는 건 두 번 일하는 거잖아."

**확정 해석**:
- **해석 A** (현재 구조 그대로 — 영업 입력 + 재무 확정 2단계): **기각**
- **해석 B** (입금 row 입력 자체를 재무 전용): **채택** ✅

**근거**:
- 사용자 의도 명확화 — "영업이 받아적는 건 두 번 일"
- 글로벌 ERP SoD(Segregation of Duties) 표준과 일치 — 영업은 가격·계약(Sales/Quote), 재무는 자금 흐름(Cash Application)
- SAP/NetSuite/Odoo 모두 동일 패턴

### 권한 분리 매핑

| 작업자 | 입력 권한 영역 |
|---|---|
| **[영]** 영업 | 매입가·매도비·비용 9종 / 판매가·환율·통화·바이어·세금할인·수수료·운송비 (= 거래 가격·계약) |
| **[재]** 재무 | 매입 선금·매입 잔금 / 판매 계약금·중도금·선수금·잔금·적립금 사용 (= 자금 흐름) |

영업 뷰에서 입금 row는 **read-only 표시**로 격하. 입력 시도 시 `canAccessSettlement()` 미들웨어가 403 반환.

### 큐 22 시리즈 작업 범위 확장

| 큐 | 기존 작업 | 추가 작업 (해석 B 권한 분리) | 공수 변화 |
|---|---|---|---|
| **22-A** | `final_payments.type` enum 통합 + 분자 단순화 | 차량 [판매] 탭 입금 영역 read-only 격하 + `/erp/transfers`에 입력+확정 통합 화면 | 11~14h → **14~18h** (+3~4h) |
| **22-B** | `Vehicle::saved` H6 훅 → `FinalPayment::saved` 이전 + REFUND 정책 | "적립금 사용" 입력도 재무 화면으로 이전 | 6~8h → **8~10h** (+2h) |
| **22-C** | `purchase_balance_payments.type` enum + 매입 4컬럼 통합 | 차량 [매입] 탭 선금·잔금 입력 영역을 재무 화면으로 이전 | 5~6h → **7~9h** (+2~3h) |
| **총** | — | — | 22~28h → **29~37h** |

### 워크플로우 영향 (큐 22 완료 후 `docs/workflow-checklist.md` 갱신 필요)

- **A-2 매입 정보**: "매입 선금·매입 잔금 추가" → 재무 화면으로 이동. 영업은 가격(`purchase_price`, `selling_fee`, 비용 9종)만
- **A-4 판매 정보**: "[+ 잔금 추가] / 선수금 1·2 / 적립금 사용" → 재무 화면으로 이동. 영업은 가격(`sale_price`, `exchange_rate`, `currency`, 세금/할인/수수료/운송비)만
- **B-1·B-2 재무 처리**: 입력 + 확정 한 화면에 통합 (현재 "영업 입력 → 재무 확정" 2단계 구조에서 "재무 입력+확정 1단계"로)

### 미들웨어·권한 (기존 패턴 재사용)

- 입금 입력 권한 = `canAccessSettlement()` (정산 role + admin/super)
- 영업 role은 입력 차단 — 새 미들웨어 신설 불필요
- self-confirm 차단(큐 19-F·20-D 패턴) 그대로 유지
- audit_logs 기록 — 입금 row 입력자(`finance_user_id`) 컬럼 기존 활용

### 다음 작업 큐 영향

- **큐 22-A 다음 세션 첫 작업** — 메모리 `project_queue_status.md` §2 큐 22-A 작업 분할 표 갱신됨 (9단계, 14~18h)
- **새회의 4번 (sales role 본인 것만 + 거래완료=정산)** — 큐 22 시리즈와 별개. 권한 분리 안건이라 일관성 점검 후 큐 22 완료 후 별도 처리
- **워크플로우 체크리스트 갱신** — 큐 22-A·22-B·22-C 각 완료 시 해당 섹션 단계적 갱신 (한 번에 X)

---

## 🔗 참조

### 관련 과거 회의록
- `2026-05-18-deposit-confirm-gate.md` — 큐 22 옵션 B 단계적 채택 (선행 조건)
- `2026-05-18-vehicle-ledger-field-lock.md` — 큐 21 ledger lock + AUDITED_COLUMNS
- `2026-05-17-purchase-sale-finance-gate.md` — 큐 20 A안 분자 정의 (현금흐름 정의 기반)
- `2026-05-14-3way-workflow-policy.md` — G1·G2·G3 분류, role 재설계
- `2026-05-13-progress-status-integrity.md` — 큐 2.6 grandfather 원칙 + v1/v2 분기 (3-C 영향)

### 코드 참조
- `app/Models/Vehicle.php` L769 (v2 거래완료), L786 (v1 grandfather), L967·977 (active 필터), L1042~1044 (settlement_create_needed), L577~581 (H1 gate), L836~847 (sale_unpaid 분자), L866~872 (sale_total_amount)
- `app/Models/Settlement.php` L93~110 (confirmed_snapshot), L139~180 (정산 공식)
- `app/Models/FinalPayment.php` L11~13 (fillable confirmed_at)
- `app/Http/Controllers/VehicleDocumentController.php` L17~58 (단건 ID 라우트, DocumentAccessLog 기록)
- `resources/views/livewire/admin/dashboard.blade.php` L66~95 (kpis), L161 (salesBuckets chunk), L199~228 (월별 차트)
- `database/migrations/2026_05_13_000003_grandfather_existing_vehicles_to_rule_v1.php` (3-tier 이관)
- `tests/Feature/WorkflowGapTest.php` L431·L416, `DashboardActionCountsTest.php` L74, `G3ReceivableClassificationTest.php` L102, `G1BlLockTest.php` 전체, `AdminDashboardTest.php` L66~93·L199~228

### 새회의.txt 다른 안건 (분리 추적)
- 1번 에러/승인 메시지 한글화 — 직접 작업 (2~3h)
- 2번 사이드바 바이어 탭 미수금 게이지 — 큐 9 확장 G1 후속
- 4번 sales role 본인 것 + 거래완료=정산 분류 — 짧은 회의 또는 직접
- 5번 영업 1/2 분리 (헤이맨/프리랜서) — Salesman 모델 확장
- 6번 매입 미지급 직관 + 입금현황 재무 전용 — 큐 20-C 후속
- 8번 미입금 잔존 관리자 승인 통과 안 되는 버그 — **fix 완료 커밋 `f26b46c` (2026-05-19)**

### 부서 프롬프트 (v1.2)
- `docs/meetings/departments/{po,engineer,qa,security,ops,specialist}.md`
