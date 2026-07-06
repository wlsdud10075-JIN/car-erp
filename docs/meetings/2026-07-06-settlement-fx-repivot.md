# 📅 회의록: 정산 회계 재설계 3건 (2차 환차 재피벗 / 판매탭 단순화+c-2 / 회사이익 대시보드)
- 일시: 2026-07-06
- 강도: 풀회의 (/회의 명령어 호출)
- 안건 유형: 정산공식 + 마이그레이션 + 대시보드 (복합)
- 자동발동 여부: yes (/회의 슬래시 + 풀회의 강제 키워드 vat_margin류·migration·정산공식)
- 발동 부서: PO + Engineer + QA + Security + Ops + Specialist[B.데이터무결성 + F.회계정산감사]
- 출처: 메모리 `project_settlement_v2_groupware_design` (jin 2026-07-06 생각정리, 설계확정/미착수). ⑤⑥⑦ quick win·③ 대표승인큐(파킹)는 회의 범위 제외.

## 안건 요약
1. **2차 정산 환차 공식 재피벗(실현손익)**: 현재 `(외화합×close_rate)−Σ실입금KRW` → 신모델 `Σ실입금KRW − (sale_total_amount×판매환율)`. close_rate(`settlements.exchange_rate_at_close`) 완전제거. baseline=sale_total_amount×판매시점환율. 실입금=Σ(잔금외화×잔금별환율). 환차반영=프리랜서만 유지.
2. **판매탭 입금 단순화 + 확정잔금 c-2**: 계약금/중도금/선수금1 입력칸 숨김, 잔금+/적립금/수수료만. 레거시 read-only 보존. 확정(confirmed_at) 잔금 환율/날짜=잠금해제토큰 수정.
3. **회사이익 대시보드(type별)**: 관리자 대시보드 회사순이익 + 인원별 회사기여. 회사몫=실현총마진−영업실지급. 프리랜서/사내직원 환차귀속 분리.

---

## 💬 부서별 발언 (Sonnet)

### 📋 PO — 조건부 GO
안건1·3은 재무 실무 정확도 향상 리스크제거성. 안건2 "레거시 read-only 보존"은 기존 100대+ 차량에서 계약금/중도금 숨김이 UX 회귀로 보일 위험 → "과거 방식" 라벨 명확화 필요. c-2는 §28 VehicleLedgerUnlockService 패턴 재사용 가능. **다음 작업 큐 영향**: 06-22 대규모 정산 재정리(`project_settlement_data_remediation`) 후 반년 내 재설계 반복 = "재무가 정산공식을 못 믿는다" 신뢰 문제 → 착수 전 재무 사전설명 1회 권고. 지급자동화·board포털·엑셀보다 우선순위 정당화 필요. **운영 전 필수**: no(급한 장애 아님, 07월 2차 마감 주기 전 하려면 일정조율). 근거: Settlement.php:27, Vehicle.php sale_total/unpaid accessor(§13 단일출처), FinalPayment.php:82-95($allowConfirmedMutation).

### ⚙️ Engineer — 조건부 GO
(1) `calculateExchangeDifference`(settlements/index:657) 재작성은 단순치환 아님 — 신 baseline `sale_total_amount×rate`(transport_fee 포함)는 1차 baseline `getSalesAmountKrwAttribute`(Settlement.php:267, transport_fee 제외)와 **다른 금액** → 환차의 경제적 의미 변질, jin 숫자 재확인 필요. 구현 자체는 단순화(ExchangeRateService 의존·환율미입력 마감차단 게이트 소멸). `exchange_rate_at_close`는 closed 102건 중 **1건만 값 존재**(tinker 실측) → 컬럼 **보존**(감사) 권장, DROP 불필요.
(2) `FinalPayment::updating`(75-88) confirmed lock이 `exchange_rate`를 **가드 밖에 둠(구멍)** → c-2 전에 선수정. 잠금해제는 VehicleLedgerUnlockService(vehicle-level) **재사용 불가** → FinalPayment row 단위 새 캐시 네임스페이스(`ledger_unlock:fp:{id}`)+updating훅 `Cache::pull` 소비 **신규 구현**.
(3) `total_margin`/`actual_payout` computed(비DB, §5-6) → 컬렉션 집계(현재 118건, 성능문제 없음, 캐시컬럼 불필요), `with('vehicle')` 필수. "실현" 범위(paid만 vs paid+closed) PO/jin 확인.
**공수**: 1=4~6h / 2=10~14h / 3=6~8h. **캐시 rebuild**: no(단 backfill 커맨드 raw UPDATE면 sale_unpaid_amount_krw_cache 재계산 필요). **깨질 테스트**: SecondarySettlementTest·SettlementExchangeRateInputTest·E2eSettlementWorkflowTest·SettlementLicenseFeeE2eTest·SettlementSoftDeleteCloseGuardTest.

### 🧪 QA & Domain Integrity — 조건부 GO
**결정적**: `sale_received_krw_accumulated`(Vehicle.php:1188)는 `finalPayments`만 합산, `receivableHistories`(method≠deposit) 누락 — §13 단일출처는 두 소스 모두 → 이 상태로 "실입금" 재사용하면 원금갭이 환차로 둔갑. baseline `sale_total_amount`(transport_fee 포함)와 1차 `sales_amount_krw`(제외) 스코프 어긋남 → 문서-코드 재drift(§8 #30 계열). **회귀 시나리오**: ① 부분입금+bl override 우회 거래완료 → 원금 미수분이 음수 "환차손" 오표시 ② receivableHistories 경유 입금 → 실입금 과소평가 → 허위 환차손 ③ exchange_rate 0/null 외화 → baseline 0 → 전액 "환차익" 오판 ④ 기존 closed 행 `exchange_difference_krw`가 이미 `carryover_out_krw`로 다음 정산에 흡수(§5-5)됨 → 재계산 시 소비된 이월체인 붕괴. **깨질 테스트(확정)**: E2eSettlementWorkflowTest(L107 EUR 500,000 환차·L286-300 carryover 2,205,000 하드코딩), SettlementKrwBreakdownTest(L73-74,113-116 400×1350/1380 하드코딩). **주석-코드 불일치 기존 존재**: Vehicle.php:1186 주석은 "sale_total×현재환율"인데 실 코드는 Σconfirmed FP 기준. **운영 전 필수**: yes.

### 🔒 Security & Compliance — NO-GO(안건1·2) / 조건부 GO(안건3)
`FinalPayment::updating`(L82-86) confirmed 락이 `amount/payment_date/confirmed_at/transfer_id`만 체크, **`exchange_rate` 잠금목록에서 빠져 지금 이미 무방비**. UI(vehicles/index:5350-5352)도 `$isConfirmed`여도 exchange_rate input readonly 안 바꿈 → 재무확정 후 환율 무단수정 → amount_krw 재계산. 안건1이 이 amount_krw에 의존 → 조작가능 입력 위에 섬. `grep AuditLog` 결과 **FinalPayment 필드변경은 어디서도 AuditLog 무기록**. c-2용 VehicleLedgerUnlockService는 Vehicle::saving에만 배선 → FinalPayment 토큰 소비지점 신설 필요. SoD: `canUnlockLedger`·`canApprove` 둘 다 role='관리' 단독 통과 → 동일인이 잠금해제→수정→paid승인 전부 자기손. 안건3은 `/admin/dashboard` 기존 canAccessAdmin 게이트 재사용이라 노출확대 없음 → 조건부 GO.
**개인정보**: RRN·계좌 미접촉. **감사로그**: (1) AUDITED_COLUMNS에 신규 환차필드 미포함 (2) confirmed_snapshot이 exchange_rate·amount_krw 누락 → drift 탐지불가 (3) FinalPayment 변경 AuditLog 전무.
**NO-GO (a)(b)(c)**:
- (a) `FinalPayment::updating` confirmed lock에 `exchange_rate`(+파생 amount_krw) 미포함 = 오늘 뚫린 구멍, 리팩터 전 선수정 필요.
- (b) c-2를 FinalPayment 전용 토큰(1회소비 cache `FinalPayment::ledgerUnlockCacheKey($id)`)+매건 AuditLog(`ledger_field_unlocked`, column/old/new, reason≥10자) 신설. 권한 canApprove로 열되 **동일인 잠금해제·수정·2차마감 3단계 전처리 건은 화면 하이라이트**(1인 운영상 SoD 완전분리 강제 안하되 흔적 남김).
- (c) confirmed_snapshot의 confirmed_final_payments에 exchange_rate/amount_krw 추가 + AUDITED_COLUMNS에 신규 환차필드 추가.

### 🚀 Ops & Deploy — 조건부 GO
컬럼(exchange_rate_at_close/carryover/secondary_status/exchange_difference_krw) 전부 미인덱스라 DROP 가벼우나, 3사 운영DB에 `secondary_status='closed'` 실데이터 존재 → 컬럼 삭제 시 과거 회계스냅샷 영구소실 → **DROP 대신 nullable 유지+deprecate**, 실제 DROP은 별도 후속 마이그(사람 확인). 레거시 변환 artisan 커맨드는 `.github/workflows/deploy.yml`에 없음 → **자동배포 안 돎, 3사 각각 수동 SSH**(`--dry-run`/`--apply` idempotent, SSH rate-limit 255연발 차단 주의). ssancar는 `artisan down` 미적용(NICE 게이트웨이 co-location 무중단 전제) → 컬럼 마이그 수동배포 검토. **다운타임**: heyman/karaba 1~3분(점검), ssancar 무중단·수초. **백업**: push 직전 3사 DB 스냅샷(exchange_rate_at_close/carryover 실데이터 있는 heyman·ssancar). **queue worker**: 무관(ShouldQueue 0건). **환경**: 신규확장 불필요. computed 집계는 admin 대시보드 병목 가능 → Cache::remember 서브쿼리 패턴 재사용. **테스트**: SQLite≠MySQL8, enum MODIFY·컬럼 DROP 문법차 → 로컬 MariaDB 재현 후 push. **운영 전 필수**: yes(백업+보존정책+수동실행계획 확정 전 master push 불가).

### 🔧 Specialist [데이터 무결성] — 조건부 GO
실입금 `getSaleReceivedKrwAccumulatedAttribute`(Vehicle.php:1188) 재사용만 하면 되나: (a) 레거시 FinalPayment(exchange_rate NULL)는 vehicle.exchange_rate fallback → baseline과 동일 rate → 환차 기여 강제 0 = "계산불가라 0으로 새는 것", 구분없이 섞이면 과거 2차분 조용히 과소평가. (b) 미완납 B/L 100% 우회(stage='bl')로 paid/closed 간 차량은 Σ잔금외화<sale_total_amount외화 → 원금 미수갭이 "환차손"으로 오염 — **완납 전제 가드 안건에 없음**. (c) `secondary_status='closed'` 건은 exchange_difference_krw/carryover_out_krw 저장(frozen)+closeSecondarySettlement가 `secondary_status!=='pending'` return → **공식만 바꿔도 과거 closed 안 건드림(안심)**. **retroactive**: closed 안전, 단 배포시점 pending 대기건 전부 신공식 일괄전환(선택 컷오프 아님) → jin 명시확인. exchange_rate_at_close 물리 DROP 금지. **운영 전 필수**: yes((b) 원금갭 가드 배포 전 필수).

### 🔧 Specialist [회계·정산 감사] — 조건부 GO
`confirmed_snapshot`은 paid(1차확정) 시점이라 2차 재피벗과 시점 안 겹침 → 직접충돌 없음(2차는 별개 컬럼에만 영향). 문제는 c-2: FinalPayment는 AUDITED_COLUMNS류 자동 AuditLog 전무(잠금은 $allowConfirmedMutation 정적플래그뿐, updated훅에 recordChange 없음) → 확정잔금 환율·날짜 사후수정을 감사로그 없이 통과시키면 §5-6 회계무결성 lock과 정면충돌. 레거시 변환 커맨드도 artisan(auth 없음)이라 updating 가드 우회 → 자동감사·수동승인 없이 대량 확정데이터 변경. 안건3은 exchange_difference_krw가 ratio/per_unit 구분없이 계산·저장되나 actual_payout엔 ratio만 반영되는 기존 비대칭(Settlement.php:452-456) 그대로 재사용해야 이중계상·누락 없음 → 대시보드 스펙에 명시 필요(안 하면 사내직원 환차손익 회사이익서 누락). **운영 전 필수**: yes(FinalPayment 감사로그 신설+레거시 커맨드 수동 감사기록).

---

## 🧩 중간 회의 결과 (Opus 1차 취합)
- 판정 분포: 조건부 GO 6 / NO-GO 1(Security, 안건1·2, (a)(b)(c) 충족 유효).
- **합의 GO 조건 6**: ① close_rate 코드사용중단 OK(값 1건뿐) ② 컬럼 물리 DROP 금지(nullable deprecate) ③ 기존 closed grandfather(frozen) ④ [선행] FinalPayment lock에 exchange_rate 추가 ⑤ [선행] FinalPayment 전용 AuditLog 신설 ⑥ 원금갭 오염 가드.
- **충돌 2 (사외이사 판정 필요)**: [1] baseline 스코프 = sale_total_amount(운임포함) vs sales_amount_krw(운임제외) → 순수 FX 의미 변질. [2] 실입금 정의 = finalPayments만 vs +receivableHistories.

## 🌐 사외이사 의견 (Codex / Gemini)
### [Codex] — 자체 NO-GO ((a)(b)(c) 충족, 선행조건형)
놓친 리스크 3: ① "총판매가 기준 FX"는 실현환차 아니라 회수성과/가격차/비용회수 섞인 KPI. ② 채권회수이력 누락은 버그 아닌 **원장-보조원장 불일치**. ③ unlock token 수정은 사유·전후값·승인자·만료시간 없으면 감사상 무효.
- **충돌1 판정**: SAP/Odoo 표준 = invoice/receivable 발생시점 장부금액 vs 실제 결제금액 차이 = 실현환차. **총판매가를 쓰려면 "회수성과 손익"으로 명명하고 FX와 분리하라.**
- **충돌2 판정**: 실입금 = finalPayments + receivableHistories 전체가 맞음. 누락 전환 시 허위 환차손.
- **1인 우선순위**: 선행결함1(lock) → 선행결함2(AuditLog) → 실입금 정의 수정 → 원금갭 가드 → 대시보드.
- (a) 현 모델 감사불능+허위손익 가능 (b) 잠금·AuditLog·채권회수 포함·closed frozen (c) FX계정과 회수성과 KPI 분리 병행표시.

### [Gemini]
호출 안 함 — 무료티어 단종(`reference_gemini_cli_dead`, IneligibleTier). 사외이사 Codex 단독으로 진행(회의 유효).

## 🚨 NO-GO 상세 종합
- **차단 사유**: 재무확정 잔금 환율이 현재 무방비(무단수정+amount_krw 재계산) + FinalPayment 변경 AuditLog 전무 → 신 공식이 조작가능·감사불능 입력 위에 섬.
- **수용 가능한 최소 조건**: ① FinalPayment lock에 exchange_rate/amount_krw 추가(선행) ② FinalPayment 전용 잠금해제 토큰+AuditLog 신설 ③ 실입금 accessor에 receivableHistories 포함 ④ 완납 전제 원금갭 가드 ⑤ 기존 closed frozen ⑥ confirmed_snapshot에 exchange_rate/amount_krw 추가.
- **대안**: (Codex) 순수 FX 계정과 "회수성과 KPI"를 분리해 병행 표시.

## 🏁 최종 권고 (Opus 최종 취합)
**판정: 조건부 GO**
**근거**: 모델 방향(실현손익 전환·close_rate 제거)은 전원+표준 지지. 단 재무확정 잔금 환율 무방비·FinalPayment AuditLog 부재라는 **기존 보안구멍이 신 공식의 신뢰 기반을 흔듦** → 선행결함 해소 후 진행. 회계 의미(순수 FX vs 회수성과)는 jin 확인+명명 정리 필요.

**필수 선행 작업 (Codex 우선순위 순)**:
1. **선행결함1** — `FinalPayment::updating` confirmed lock에 `exchange_rate`(+파생 amount_krw) 추가 + UI에서 confirmed 잔금 환율 input readonly. (안건 무관 오늘의 구멍)
2. **선행결함2** — FinalPayment 전용 잠금해제 토큰(`ledger_unlock:fp:{id}` 1회소비)+매건 AuditLog(`ledger_field_unlocked`, column/old/new, reason≥10자, 승인자). confirmed_snapshot·AUDITED_COLUMNS 확장.
3. **실입금 정의 수정** — `sale_received_krw_accumulated` accessor에 `receivableHistories`(method≠deposit) 포함(§13 단일출처 정합). Vehicle.php:1186 주석-코드 불일치 동반 해소.
4. **원금갭 가드** — 완납(Σ잔금외화 ≈ sale_total_amount외화, 허용오차 내) 시에만 2차분 확정. 미완납/과납·환율0/null 외화 차량은 마감 차단(기존 rate===null 분기 재사용). 이로써 2차분 = 순수 실현환차 보장.
5. **공식 재피벗** — calculateExchangeDifference를 `Σ실입금KRW − sale_total_amount×판매환율`로. krwBreakdown(미리보기)와 **공유 헬퍼로 추출**(재drift 방지). close_rate UI(exchange_rate_at_close_str/saveExchangeRate/마감모달 환율입력) 제거. 컬럼은 nullable 보존.
6. **대시보드(안건3)** — 회사몫 type별 분기(프리랜서 환차 영업흡수 / 사내직원 환차 회사흡수) 스펙 명시. 컬렉션 집계+with('vehicle'). "실현" 범위(paid만 vs paid+closed) jin 확정.

**jin 결정 필요 (배포 설계 확정 전)**:
- **D1 (충돌1)**: 2차분을 순수 FX로 볼 것인가("회수성과" 요소 배제) → 권장 = baseline은 sale_total_amount 유지하되 **완납 게이트로 순수 FX 보장**(운임 포함분도 바이어가 실제 치르는 청구액이라, 완납 시 rate delta만 남음). 명칭은 "2차 정산(환차)" 유지 가능.
- **D2**: 배포 시점 `secondary_status='pending'` 대기 정산 전부 신공식 일괄전환 동의? (기존 closed는 frozen 안전.)
- **D3**: 사전 재무 설명 세션 1회(PO 권고) — 07월 2차 마감 주기와 일정 조율.

**조건부 GO 근거**: NO-GO(Security·Codex) 둘 다 "도입 반대" 아닌 "선행결함 해소"형 + (a)(b)(c) 충족 → §4에 따라 필수 선행조건으로 편입. 나머지 6부서 조건부 GO.

## 🛠 car-erp 영향 분석 (Opus 산출)

### 취약점 (Vulnerabilities)
- **[P0] FinalPayment `exchange_rate` 무방비** — 재무확정 후 무단수정 → amount_krw·미수·환차 소급변동. 안건 무관 현재 라이브 구멍. (FinalPayment.php:82-86, vehicles/index:5350-5352)
- **[P0] FinalPayment 변경 AuditLog 전무** — 확정 잔금 수정 추적 불가.
- **[P1] SoD 결손** — canUnlockLedger·canApprove 둘 다 관리 단독 → 동일인 잠금해제·수정·마감 전처리.
- **[P1] 원장-보조원장 불일치** — sale_received_krw_accumulated가 receivableHistories 누락(Codex "불일치" 지적).

### 보완사항 (Improvements)
- close_rate 수기입력 제거 → 재무 부담·오차 소스 소멸.
- calculateExchangeDifference ↔ krwBreakdown 공유 헬퍼 추출(중복 로직 drift 제거).
- confirmed_snapshot에 exchange_rate/amount_krw 추가 → paid 이후 drift 사후 대조 가능.
- Vehicle.php:1186 주석-코드 불일치 해소.

### 코드 수정 (Code Changes)
- `app/Models/FinalPayment.php` — updating lock에 exchange_rate/amount_krw / 전용 잠금해제 토큰 소비 / AuditLog 기록.
- `app/Models/Vehicle.php` — sale_received_krw_accumulated에 receivableHistories 포함 / 주석 수정.
- `app/Models/Settlement.php` — confirmed_snapshot·AUDITED_COLUMNS 확장 / getActualPayout 환차 참조 유지(type별).
- `resources/views/livewire/erp/settlements/index.blade.php` — calculateExchangeDifference 재피벗 / close_rate UI 제거 / 공유 헬퍼.
- `resources/views/livewire/erp/vehicles/index.blade.php` — 판매탭 계약금/중도금/선수금1 숨김+레거시 read-only / 잔금행 confirmed 환율 readonly / c-2 잠금해제 UI.
- `resources/views/admin/dashboard*` — 회사이익 위젯 2개(경로 재확인 필요).
- 신규 artisan — 레거시 deposit/interim/advance → balance 변환(감사 기록 동반).
- 신규 마이그레이션 — 없음(컬럼 DROP 안 함, 보존). (backfill은 커맨드로.)

### 신규 추가 (New Additions)
- FinalPayment 전용 잠금해제 서비스/토큰(캐시 네임스페이스 `ledger_unlock:fp:{id}`).
- 회사이익 대시보드 위젯(회사순이익 + 인원별 회사기여, type별 환차귀속).
- 완납 게이트 + 원금갭 가드 로직.
- Unit Test: 원금갭 분리·receivableHistories 포함·환율0 차단·closed grandfather 불변·헬퍼 공유 검증.

### 모순·NO-GO 처리 로그
- Security NO-GO(안건1·2): (a)(b)(c) 충족 → 유효, "선행결함 필수조건"으로 편입(도입 반대 아님).
- Codex NO-GO: (a)(b)(c) 충족 → 유효, 충돌1 명명분리·충돌2 실입금정의 확정으로 조건 편입.
- 자동 무효화된 NO-GO: 없음.

## 🔗 참조
- 관련 과거 회의록: 2026-05-14 v5 워크플로우(sale_total_amount 분모 확정) / 2026-05-18 vehicle ledger field lock(VehicleLedgerUnlockService) / 2026-05-16 finance gate(SoD) / 2026-05-18 deposit-confirm-gate(confirmed_snapshot).
- CLAUDE.md 정산 마진 공식 / SKILLS.md §5·§5-4·§5-6·§13 / 메모리 project_settlement_v2_groupware_design.
