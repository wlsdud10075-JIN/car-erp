# 📅 회의록: 일반사용자 대시보드 role 분기 (큐 1번)

- 일시: 2026-05-12
- 강도: 풀회의
- 안건 유형: UI + 권한 + 도메인 분기 (대시보드 카운트·필터 변경)
- 자동발동 여부: no (사용자 명시적 안건 — `role기획보안_수정.md` §10 1단계)

## 1. 안건 요약

현재 `/erp/dashboard`(`resources/views/livewire/erp/dashboard.blade.php`, 377줄)는 admin/일반사용자 분기는 있지만, 일반사용자 내부에서 role(영업/통관/정산/관리)별 분기가 없다. 동일한 `actionCounts` 6종(매입미지급/판매미입금/통관/선적/DHL/정산)을 모든 role에 동일하게 노출.

`role기획보안_수정.md` §5에 영업/통관/정산 role별 KPI 카드 + 오늘의 할일을 다르게 정의. 본 안건은 그 기획을 구현하기 위한 풀회의 의사결정이다.

### 기획 요약 (role기획보안_수정.md §5)

- **영업 role**: KPI 4개(진행중·이달매입·이달판매·이달거래완료) / 할일 5종 / **본인 salesman 차량만**
- **통관 role**: KPI 4개(통관신청대기·수출신고서업로드대기·선적대기·DHL대기) / 할일 7종 / **전체 차량 (수출 채널 한정)**
- **정산 role**: KPI 4개(매입미지급총액·판매미입금총액·정산대기·환율미입력외화) / 할일 7종 / **전체 차량**
- **관리 role**: 보류

## 2. 역할별 발언

### 📋 PO
판정: **조건부 GO**
발언: 통관 user 1명 / 정산 user 0명 시드 확인. 통관 담당자가 영업용 "매입 미지급/판매 미입금" 카드를 보는 게 가장 큰 부적합. 본 큐 1번에서 role 분기 골격을 깔면 큐 2번(파이프라인 스트립)이 자연스럽게 합류. 정산 role은 사용자 부재라 admin이 role 토글로 dogfooding 필요.
다음 작업 큐 영향: 없음 — 큐 2번과 병렬·후속 가능, 큐 3·4·5 무관. 단 큐 1번이 role 분기 골격을 먼저 깔아야 큐 2번 스트립이 영업(본인) vs 통관/정산(전체) 범위 차이를 흡수.

### ⚙️ Engineer
판정: **조건부 GO**
발언:
- 단일 컴포넌트에 `auth()->user()->role` 기반 if/elseif 분기 수용 가능. 다만 `actionCounts` 6 → 13~14개로 늘어 현재 `$active->filter()` 컬렉션 방식(매번 active 차량 메모리 로드)이 N+1 + 메모리 부담.
- **role별로 필요한 액션만 계산**(영업 5종 / 통관 6종 / 정산 7종)하는 빌더 분기로 컴포넌트 메서드 쪼개기 권장.
- 대시보드 카운트와 `vehicles/index.blade.php::applyActionFilter()`가 동일한 `private static function actionQuery(string $action): Builder` 헬퍼를 공유하도록 추출 → §9 100% 일치 원칙.
- `receivable_risk` 캐시 컬럼 이미 존재 → `where('receivable_risk', in, ['danger','critical'])` SQL 한 줄로 N+1 없이 처리.
- my-crm에 role별 대시보드 분기 패턴 **없음** (위젯 분할만 존재) → car-erp가 첫 사례, SKILLS.md §9에 패턴 등록 권장.

공수 추정: **4~5시간** (분기 로직 1.5h + 신규 actionCounts 7종 SQL 1h + applyActionFilter match 추가 1h + tinker 검증 0.5h + role별 UI 마이크로 조정 1h)
영향 파일:
- `resources/views/livewire/erp/dashboard.blade.php`
- `resources/views/livewire/erp/vehicles/index.blade.php` (applyActionFilter)
- 신규 partial 3종 선택사항 (`_role_sales/_role_clearance/_role_settlement.blade.php`) — Blade include 전용, Volt 컴포넌트 X
캐시 rebuild 필요: **no** — `progress_status_cache` / `sale_unpaid_amount_krw_cache` / `receivable_risk` 3개 캐시 컬럼 모두 활용만, 갱신 트리거 변경 없음. 마이그레이션 없음.

### 🧪 QA & Domain Integrity
판정: **조건부 GO**
발언:
1. **채널 분기 누락이 핵심 차단점.** 현재 `clearanceNeeded`/`shippingNeeded`/`dhlNeeded`와 `applyActionFilter()` 모두 `sales_channel='export'` 필터 없음(`dashboard.blade.php:72-74`, `vehicles/index.blade.php:270-279`). 통관 role 7개 할일은 전부 수출 흐름이라 헤이맨/카풀 차량이 `export_declaration_document` 컬럼만 비어있으면 "수출통관 신청 필요"에 잡힘.
2. **salesman 엣지케이스 2종.** (a) 영업 role인데 `User::salesman()` 미배정 user → 현재 mount()는 `selectedSalesmanId=0`이라 전체 차량 노출 (의도와 반대). (b) `vehicles.salesman_id IS NULL` 차량 → 본인 필터 시 누구도 못 봄 → 영업 미배정 차량이 영원히 처리 큐에서 누락.
3. **환율 0 외화 차량의 정산 KPI.** `sale_unpaid_amount_krw_cache=0`이 "판매 미입금 총액" SUM에서 누락 → 외화 미입금 보유 차량 누락. 산식에 "환율 입력 외화 + 원화만 합산, 환율 미입력은 별도 카운트" 명시 필요.
4. **신규 액션 키 ↔ `applyActionFilter()` 동기화 부채.** 통관 7 + 정산 7 = 14개. 누락 시 `default => $q`로 빠져 카운트 ≠ 목록 정합성 파괴.
5. **Unit Test 자산 부재.** `tests/Unit/`에 actionCounts/VAT/cost_total/미수금/11단계 산식 테스트 전무. `tests/Feature/DashboardActionCountsTest.php` 신규 작성 강력 권장.

도메인 공식 영향: 11단계(채널 미분기 시 헤이맨/카풀에서 수출 단계 진입 가능), 다중통화+환율 0(정산 KPI 합산식). VAT 9%/cost_total은 무관.
회귀 시나리오: **수동 30분**
- 영업 user 본인 차량만 노출 확인 (salesman 미배정 user 별도 케이스)
- 통관 user 로그인 → 헤이맨/카풀 차량 1대 만들어 통관 카운트에서 제외 확인
- 정산 user 로그인 → 환율 0 외화 판매 차량 1대 만들어 "환율 미입력" 카운트엔 잡히고 "판매 미입금 총액"에서는 제외되는지 확인
- 신규 액션 14개 대시보드 카드 클릭 → vehicles 목록 카운트 일치 확인 (action 파라미터 round-trip)
- 카운트 0 액션은 카드 비노출 (현행 `@if($action['count'] > 0)` 유지)

Unit Test: **없음 / 신규 필요** — `tests/Feature/DashboardActionCountsTest.php` 14케이스 + 영업/통관/정산 role별 actionCounts 결과 = 동일 액션 키 vehicles 목록 SQL count.

### 🔒 Security & Compliance
판정: **조건부 GO**
발언:
- `$selectedSalesmanId`가 public Livewire property → 비-admin도 `updateProperty` 페이로드로 임의 ID 주입 가능 → 다른 담당자 차량 조회 우회.
- 영업 role의 "본인 차량만" 분기는 **SQL `where('salesman_id', $self)` 서버측 강제 필수**, view 분기만으로는 불충분.
- 정산 role 신규 KPI "판매 미입금 총액 / 매입 미지급 총액 / 환율 미입력 외화 차량"은 회사 전체 재무 노출. 라우트는 `/erp/dashboard`(erp 미들웨어 — role 전체/영업/통관/정산/관리 모두 통과). 컴포넌트 내 `$user->role === '정산' || isAdmin()` 분기 강제 필요.
- 신규 라우트·미들웨어 추가 없음 (action 키만 추가, `route('erp.vehicles.index')` 재사용).
- RRN·문서 다운로드·API 키 영향 없음.

수용 가능한 최소 조건:
- (a) 비-admin이 `selectedSalesmanId` 변경 시도 시 본인 ID로 강제 복귀 (Livewire `updating` 훅 또는 `#[Locked]` attribute)
- (b) `activeVehicles`/`actionCounts` 쿼리에서 **비-admin AND role=영업**이면 `where('salesman_id', $self_salesman_id)` 무조건 SQL 강제 (view 분기 아닌 SQL)
- (c) 정산 role 전용 KPI 블록은 `@if($user->role === '정산' || $user->isAdmin())` 가드

대안: 비-admin에게는 담당자 selector 자체를 렌더 안 하는 게 아니라 **`#[Locked]` property** (Livewire 3) — 클라이언트 변경 시 예외 발생, 가장 안전.
개인정보·API키 영향: 없음. 단 회사 재무 집계(미입금 총액)가 role별 분기 누락 시 권한 외 노출 가능.

### 🚀 Ops & Deploy
판정: **GO**
발언:
- Volt 컴포넌트 한 파일 + vehicles `applyActionFilter()` 분기 추가만으로 끝나는 변경. XAMPP·Lightsail 양쪽 모두 무중단 배포 가능 (`php artisan view:clear` 한 번으로 캐시 갱신).
- `receivable_risk` 이미 DB 컬럼이라 SQL where 한 줄로 N+1 없이 산정.
- "환율 미입력" 카운트도 `currency != KRW + sale_price > 0 + (exchange_rate IS NULL OR =0)` SQL 1줄.
- Python ERP 미실재(메모리 `project_python_erp_status.md`) — 병행 운영·데이터 충돌 없음.

다운타임: **0초** — 무중단 (코드만, 마이그레이션·캐시 rebuild 없음)
백업 시점: 코드 git revert 즉시 롤백 (커밋 단위 1개). DB·파일 변경 없음 → DB 백업 무관, storage/ 무관.
queue worker 영향: 무관 (현재 `.env.example`에만 `QUEUE_CONNECTION` 흔적, 운영 queue worker 미가동 — 본 안건 메일/PDF 트리거 없음)
환경 의존성: **없음** — 새 PHP 확장·Composer/npm 패키지 추가 없음.

### 🔧 Specialist [슬롯: UX 설계자]
판정: **조건부 GO**
발언: 기존 KPI 4카드 + 할일 리스트 + 차량 페어 렌더 UI는 그대로 두고, role 분기는 컴포넌트 안에서 `kpis`/`actions` 배열을 role별 빌더로 갈아끼우는 방식이면 페어 렌더·`.card`·뱃지 매핑 전부 재사용 가능. **신규 화면이 아니라 데이터 슬롯 교체**라 디자인 리스크는 낮음.

UX 설계자 → 모바일 분기 검증:
- **KPI 카드**: 기존 `grid grid-cols-2 gap-3 lg:grid-cols-4` 4개 패턴 유지. 정산 KPI "매입 미지급 총액"/"판매 미입금 총액"은 ₩금액이라 모바일 2-col(폭 ~160px)에서 줄바꿈 우려 → `text-xl md:text-2xl` 다운시프트 + `truncate` + `title` 속성 풀텍스트.
- **할일 리스트**: 1줄 리스트 구조라 5~7종 모바일 세로 스크롤 무리 없음. 카운트=0 행은 이미 `@if($action['count'] > 0)`로 숨겨져서 노출량 동적. "더보기" 토글 불필요.
- **role별 컬럼 변형 (선택)**: 정산 role 데스크탑 테이블에서 "다음 할일" 컬럼을 "환율/통화"로 스왑 권장 (모바일 카드는 공통 유지).

추가 짚는 항목:
1. **카드 UI 시스템 통일**: 4종 role 모두 `.card` 유틸 기반 유지 + 금액 KPI에만 `<p class="mt-0.5 text-xs text-amber-600">예: 위험 차량 3대</p>` 보조 라인 추가. `.summary-card`와 혼용 금지(시각적 분열 회피).
2. **할일 dot 색은 "긴급도 기준"으로 통일**: red(금액·회수 차단) / amber(정보 누락) / blue·green·teal(일상 흐름) / violet(정산). `SKILLS.md §10`에 "할일 dot 색 매핑(긴급도 기준)" 1단락 추가 필요.
3. **role 미선택/관리 fallback 매트릭스**:
   | 사용자 | 분기 |
   |---|---|
   | permission=super/admin | 기존 업무 대시보드(담당자 드롭다운) — role 무시 |
   | permission=user, role=영업 | 영업 KPI/할일 + 본인 salesman 필터 |
   | permission=user, role=통관 | 통관 KPI/할일 + 전체 차량 (export 한정) |
   | permission=user, role=정산 | 정산 KPI/할일 + 전체 차량 |
   | permission=user, role=전체 | **role 토글 pill 노출**(영업/통관/정산), 기본=영업, localStorage 저장 |
   | permission=user, role=관리 | role=전체와 동일 + 안내 뱃지("관리 role 전용 화면 준비 중") |
4. **빈 상태 강화**: 영업="모든 매입/판매 정상", 통관="처리 대기중인 통관/선적 건 없음", 정산="회수·정산 대기 항목 없음". 0차량 vs 0할일 구분: `Vehicle::count()==0`이면 "차량 등록부터 시작하세요". salesman 미연결 영업 user → "담당자 정보 미연결, 관리자에게 문의" 풀스크린 empty state(차량 목록 자체 숨김).
5. **헤더 메타**: "내 영업 업무"/"내 통관 업무"/"내 정산 업무"로 동적 변경 + 부제에 role 뱃지(badge-purple/amber/green) 1개.

**대안 (강력 권장)**: role 분기를 컴포넌트 분할 없이 **`actions`/`kpis` 배열 빌더만 role별 메서드**(`buildSalesActions()` / `buildClearanceActions()` / `buildSettlementActions()`)로 분리. 뷰 1개, 데이터만 4종.

## 3. NO-GO 상세

해당 없음 — QA가 가장 강한 우려(채널 분기 누락)를 제시했으나 "조건부 GO + 채널 필터 추가" 조건으로 격하 가능. 단일 NO-GO 거부권 발동 없음.

## 4. 🏁 최종 권고

### 판정: **조건부 GO**

**근거**: Engineer·QA·Security·UX 4역할 조건부 GO, Ops 단독 GO. 채널 분기·권한 우회·KPI 합산식 3대 우려를 구현 단계에서 해결하면 진행 가능. role 분기는 신규 데이터·신규 라우트 없이 기존 골격에 역할별 빌더만 갈아끼우는 변경(공수 4~5시간).

### 필수 조건 (MUST — 구현 전 확정)

| # | 조건 | 출처 |
|---|---|---|
| M1 | **채널 분기** — 통관 role 7할일 + 기존 `clearanceNeeded/shippingNeeded/dhlNeeded`에 `sales_channel='export'` 필터 추가. `applyActionFilter()`에도 동일 적용 | QA #1, Engineer |
| M2 | **`selectedSalesmanId` 권한 우회 차단** — `#[Locked]` 또는 `updating` 훅으로 비-admin 변경 차단 + `actionCounts`/`activeVehicles` SQL에 `where('salesman_id', $self)` 서버측 강제 | Security #1·#2 |
| M3 | **정산 KPI 권한 가드** — `@if($user->role==='정산' || isAdmin())` 블록으로 영업/통관 role에 정산 재무 KPI 비노출 | Security #3 |
| M4 | **환율 0 외화 차량 KPI 합산 명시** — "판매 미입금 총액"은 환율 입력 차량만 합산, 환율 미입력은 별도 "환율 미입력 외화 차량" KPI에서만 카운트 | QA #3 |
| M5 | **`applyActionFilter()` 14케이스 추가** — 통관 7 + 정산 7 액션 키 모두 vehicles match에 추가, 누락 시 카운트↔목록 정합성 파괴 | QA #4, Engineer |
| M6 | **`private static function actionQuery(string $action): Builder` 정적 헬퍼** — 대시보드 카운트와 vehicles `applyActionFilter()`가 동일 헬퍼 공유 (§9 100% 일치 원칙) | Engineer |

### 권장 조건 (SHOULD — 구현 시 함께 처리)

| # | 조건 | 출처 |
|---|---|---|
| S1 | **role=전체/관리 fallback 토글 pill** (영업/통관/정산 3개, 기본 영업, localStorage 저장) | UX #3 |
| S2 | **KPI 카드 UI 통일** — `.card` 유틸 + `text-xl md:text-2xl` + truncate, 보조라인은 `<p class="mt-0.5 text-xs text-amber-600">` | UX #1·#2 |
| S3 | **할일 dot 색 긴급도 매핑** — `SKILLS.md §10`에 "할일 dot 색(긴급도 기준)" 1단락 추가 | UX #4 |
| S4 | **salesman 미연결 영업 user empty state** — "담당자 정보 미연결, 관리자 문의" 풀스크린 | UX #5, QA #2 |
| S5 | **`tests/Feature/DashboardActionCountsTest.php` 신규** — 14액션 × role 3종 카운트↔SQL 일치 검증, 채널/환율0/salesman_id NULL 엣지 fixture | QA #5 |

### 구현 권장 구조 (Specialist 대안 채택)

```php
// dashboard.blade.php (Volt 단일 컴포넌트 유지)
#[Computed]
public function roleView(): string {
    $u = auth()->user();
    if ($u->isAdmin()) return 'admin';
    // role=전체/관리는 토글 pill 선택값(localStorage) 따름, 기본 영업
    return in_array($u->role, ['영업','통관','정산'], true) ? $u->role : ($this->roleToggle ?: '영업');
}

#[Computed]
public function kpis(): array { return match($this->roleView) {
    '영업'  => $this->buildSalesKpis(),
    '통관'  => $this->buildClearanceKpis(),
    '정산'  => $this->buildSettlementKpis(),
    default => $this->buildSalesKpis(),
};}

#[Computed]
public function actions(): array { /* 동일 패턴 */ }

private static function actionQuery(string $action): \Illuminate\Database\Eloquent\Builder
{
    // M6 — 대시보드와 vehicles index가 공유
}
```

## 5. 사용자 결정 (회의 직후 확정)

회의 권고안 중 사용자 판단이 필요했던 3개 분기에 대해 회의 직후 확정.

### 결정 1 — `role=전체` 일반 user 처리: **토글 pill 채택**
- 일반 user가 `role=전체`로 등록된 경우, 헤더 우측에 영업/통관/정산 토글 pill을 노출하고 본인이 어떤 업무 뷰로 볼지 선택.
- 선택값은 **localStorage 저장** (`erp_dashboard_role_view`), 새로고침 후에도 유지.
- 기본값: **영업** (가장 흔한 케이스). 토글 변경 시 `kpis`/`actions` 빌더 재호출.
- 채택 이유: UX 권고. 잘못 배정된 role도 사용자가 우회 가능, role 마스터 데이터 품질 문제와 분리.

### 결정 2 — `role=관리` 처리: **결정 1과 동일 처리 + 안내 배지**
- `role=관리`도 결정 1의 토글 pill 노출 (영업/통관/정산 선택).
- 추가로 헤더 부제에 `<span class="badge badge-amber">관리 role 전용 화면 준비 중</span>` 1개 노출.
- 기획 보류 사항이라는 사실을 사용자에게 명시.
- 채택 이유: 결정 1과 일관성 유지, 관리 role도 임시로 다른 업무 뷰 활용 가능.

### 결정 3 — 정산 role 데스크탑 컬럼 변형 + **정산 user dogfooding**
- **정산 user 1명을 시드/마이그레이션으로 생성**해 풀 검증 환경 마련 (PO 발언의 "정산 0명" 우려 해소).
- 정산 role 풀 KPI/할일 구현 (M3 가드 포함).
- 컬럼 변형(`다음 할일` → `환율/통화`)은 **정산 user dogfooding 후 결정** — 필요 시 별도 마이크로 PR.
- 채택 이유: 정산 검증 부재 우려가 가장 큰 회의 리스크라 시드 만들고 직접 써보는 게 가장 확실.

### MUST/SHOULD 추가 항목 (결정 반영)

| 추가 # | 항목 | 출처 |
|---|---|---|
| **M7** | **토글 pill 컴포넌트** — `role=전체/관리` 사용자에게 영업/통관/정산 3개 pill 노출, localStorage 저장, 기본 영업 | 결정 1·2 |
| **M8** | **`role=관리` 안내 배지** — 헤더 우측 `badge-amber` 1개 ("관리 role 전용 화면 준비 중") | 결정 2 |
| **M9** | **정산 user 시드 추가** — `DatabaseSeeder.php` 또는 별도 마이그레이션으로 정산 user 1명 생성 (테스트 계정, role=정산) | 결정 3 |
| **M10** | **정산 user dogfooding 회귀** — 회의록 회귀 시나리오에 "정산 user로 로그인 → 4 KPI 노출 + 7할일 동작" 추가 | 결정 3 |

→ MUST 총 **10개**, SHOULD 5개 유지.

### 보류 항목

- 정산 role 데스크탑 vehicles 컬럼 변형 (`다음 할일` → `환율/통화`): dogfooding 후 필요성 확인 시 별도 안건.

## 🔗 참조

- **회의 프로토콜**: `decision_protocol.md` §6 (대시보드 카운트·필터 변경 행, 채널별 분기 행)
- **직전 회의**: `docs/meetings/2026-05-12-rrn-encryption-document-permission.md` (RRN 암호화 DONE)
- **기획 기준**: `role기획보안_수정.md` §5 (role별 분기), §1 (코드 이슈 4건 중 환율 0 캐시 수정 완료)
- **구현 패턴**: `SKILLS.md` §9 (action 파라미터 100% 일치), §10 (디자인 시스템), §11 (모바일 반응형)
- **도메인 공식**: `CLAUDE.md` 차량 11단계 + 정산 공식 + 권한 3단계/role 5종/미들웨어 6종
- **메모리**: `project_python_erp_status.md` (Python ERP 미실재 확인), `project_dashboard_naming.md` (대시보드 3종 명칭)

## 다음 작업

본 회의는 의사결정 자산화만 진행. 실제 구현은 **다음 세션**에서 MUST 6 + SHOULD 5 순서로 착수. 시작 시점에 본 회의록 + `role기획보안_수정.md` §5 다시 참조.
