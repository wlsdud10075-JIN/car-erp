# 📅 회의록: 선적·B/L 묶음(bundle) v2 — board↔car-erp

- 일시: 2026-06-30
- 강도: 풀회의 (/회의 명령어 호출)
- 안건 유형: 마이그레이션 + 외부연동(board HMAC API) + 권한/IDOR + 회계집계
- 자동발동 여부: yes (/회의 슬래시)
- 발동 부서: PO + Engineer + QA + Security + Ops + Specialist[B.데이터무결성·C.외부의존성·E.승인권한·F.회계감사]
- 권위 스펙: `docs/integration/board-portal-api.md §5` (v2 갱신) / 메모리 `project_bl_bundle_shipment`

## 안건 요지
1 묶음(batch_id) = 1 선적 = 1 B/L = 1 오리지널/써랜더. 묶음은 선적→B/L 단계까지 영속(board에서 안 사라짐), B/L요청으로 재사용. 새 테이블 없이 deployed `shipping_requests`에 컬럼 추가 + `vehicles.bl_type`. 재구성=선언형 sync(desired 전체→diff). 이중가드(bundle.bl_type vs vehicle.bl_type). 묶음 미수=Σ `sale_unpaid_amount_krw_cache`(NULL제외)+`fx_missing_count`+`fully_paid`, 미납 게이지 양쪽 표시. 신규 4엔드포인트(GET /bundles, POST /sync, /bundles/{batch}/bl-request, /change-request).

---

## 💬 부서별 발언 (Sonnet 4.6)

### 📋 PO — 조건부 GO
v2 묶음 모델은 **board화면배선의 prerequisite**(병렬 아님, v2 먼저). v1은 in_progress 진입 시 차가 목록서 안 사라지나 B/L 의도(오리지널/써랜더) 전달 경로가 없어 수출통관/관리가 구두 확인 의존. 3조건:
- **[조건1] 알람 target_role 모순 해소** — `ShippingRequestController::fireShippingAlarm() L135 = '수출통관'` vs 스펙 §5-3 B/L·변경요청 알람 '관리'. 분리/통합 jin 결정(안 하면 role 혼선).
- **[조건2] sync 부분전송 footgun** — 한 번이라도 부분 payload면 `requested` 묶음 의도치 않은 자동취소. handoff 문서 WARN + 응답 `cancelled:[]` 필수.
- **[조건3] v1 API 하위호환** — `POST /shipping-request`(deployed) vs `/sync`(breaking). 병존 or 동시 컷오버.
- 업무 role: 영업(board)/수출통관/관리, 재무는 미수 집계 읽기 수혜. 막힘=불편(구두 의존). 운영 전 필수: yes(board화면배선 블록커).

### ⚙️ Engineer — 조건부 GO (18~22h)
batch_id·STATUS_CANCELLED·batch단위 changeStatus 이미 구현돼 기반 단단. 4조건:
- **① vehicles.bl_type 스펙 갭** — §5-0은 "마이그 1개"인데 bulk-apply가 bl_type을 멤버 차량에 기입. Vehicle fillable(L22-57)에 bl_type 없음 → **마이그 2개** 필요(or 이중가드를 Volt state로만).
- **② 선언형 sync 트랜잭션** — `DB::transaction()` + `lockForUpdate()`로 "sync 중 관리 in_progress 전환" race 방지.
- **③ bulk B/L apply 모델이벤트 우회** — `Vehicle::whereIn->update()`는 saving 훅 미발동(SKILLS §2). 직후 `foreach $v->refreshCaches()` 루프 필수(L880-888 DB::table 패턴, 무한루프 없음).
- **④ shippable() breaking change** — 현재 open 묶음 차도 뱃지 반환(L29-54), v2는 "새로 묶을 차만". board 의존 확인.
- 영향: 신규 마이그 2 + ShippingRequest 상수/fillable/casts + 컨트롤러 4메서드 + routes 4 + shipping-requests/index.blade.php 확장. 캐시 rebuild: yes(bl_document 기입 시 거래완료 전환).

### 🧪 QA & Domain Integrity — 조건부 GO
설계 정합성은 올바름(NULL제외·fx_missing·G1 per-vehicle 유지 §5-4 명시). 구현 회귀 4지점:
- **① `.sum()` NULL coerce** — `whereNotNull` 없이 집계 시 가짜 완납(cash_audit 동일 패턴). `getSaleUnpaidAmountKrwAttribute` exchange_rate=0→null(Vehicle L1226-1233).
- **② ShippingRequest fillable/상수 미존재**(L26-29) → 마이그 누락 시 4엔드포인트 런타임 500.
- **③ target_role drift**(L135 수출통관 vs 스펙 관리).
- **④ vehicles.bl_type 미확인** → 이중가드가 없는 컬럼 참조 시 silent null 비교.
- **G1 vs 묶음 fully_paid = 의도적 이중방어**(불일치 아님). 멤버 1대라도 unpaid_ratio>0이면 G1이 B/L 차단.
- **환급 음수미수 엣지**: `fully_paid=(unpaid_total_krw<=0)`이 환급(최종입금>판매가, unpaid<0) 시 true — 실운영 발생 여부 확인.
- 회귀 25분/5케이스. Unit Test 신규: `VehicleBundleAggregateTest`(NULL coerce 방지 CI 필수) + /sync·/bundles. 깨질 테스트: `ShippingRequestsScreenTest`(sync 자동취소 미커버).

### 🔒 Security & Compliance — 조건부 GO
기존 base ✅ 코드검증(VerifyBoardReadHmac canonical+hash_equals+nonce / SalesmanResolver is_active 403 / InternalPortal/InternalDocumentController 화이트리스트·BOARD_ALLOWED_TYPES 4종·DocAccessLog source=board_api). v2 신규 IDOR 2건 선결:
- **(a) `/bundles/{batch}/bl-request`** — `ShippingRequest::where('batch_id',$batch)->whereHas('vehicle', fn($q)=>$q->where('salesman_id',$sid))->firstOrFail()` 불일치 403(404 금지). 누락 시 타 영업 묶음 bl_status 변조.
- **(b) `/sync` 자동취소** — 본인 차 한정(`whereHas vehicle salesman_id`) diff. 전체 open 조회 시 타 영업 requested 대량 자동취소.
- **(c) 단계 배포** + 타 salesman_id 403 테스트(ManagementWorkflowChecklistTest 패턴).
- 감사: bl_status 전이·sync 자동취소 audit_logs(현 store()는 TaskAlarm만, 추적 gap). PII: `/bundles` 응답 `toArray()` 금지(`change_request_meta` 내부메모 노출 경로) — 명시 화이트리스트 map.

### 🚀 Ops & Deploy — 조건부 GO
4컬럼(nullable/default) = MySQL 8 **INSTANT DDL**(행 재빌드 없음, 수십 ms, 락 없음). `bl_status='none'` DDL default 자동 → PHP backfill 불필요. **ssancarerp = 무중단 0초**(deploy.yml:116 추가형 마이그 무중단 잡), heymanerp/karabaerp 1~3분 정상. route:cache 자동(deploy.yml:50/86/119). 조건:
- **① /sync N+1**(현 store L80 개별 조회) → whereIn 벌크로드.
- **② /sync 트랜잭션** — board 재전송 중복행 위험.
- queue 무관, 환경의존성 없음(bcmath/zip/gd 그대로), 스토리지 없음(JSON은 DB).
- 백업: deploy.yml db:backup 비차단(실패해도 속행) → 롤백 전 수동 dump 권장. down()=dropColumn 4개(기존 batch_id/status 무결성 유지). 운영 전 필수: ShippingRequest $fillable/$casts 추가 + /sync 트랜잭션+벌크로드.

### 🔧 Specialist [데이터 무결성] — 조건부 GO
신규 컬럼 4종(shipping_requests) + bl_type(vehicles) **마이그 2개 필수**(이중가드 양쪽 컬럼 동시 존재). 기존 row backfill=nullable/default로 충분, retroactive 위험 없음. 선언형 sync 자동취소는 hard delete 금지(status='cancelled') + 전체 diff를 DB 트랜잭션(in_progress lock 판정→자동취소→생성 원자적). **bulk-apply 부분 G1 실패 시 bl_status 불일치(일부 issued, 일부 none) → DB::transaction 필수.** `InternalPortalController::finance() L199 ?? 0`이 NULL을 완납 coerce(deployed) — 묶음 재사용 시 전파.

### 🔧 Specialist [외부 의존성] — 조건부 GO
알람 target_role drift(L135 수출통관 vs §7 관리) — 관리 전환 시 `TaskAlarm::visibleToScope L62-65`·`User::canSeeAlarm L447` 연쇄수정(누락 시 관리가 알람 못 봄). sync 부분전송: 응답 `cancelled[]`에 취소 차량 목록 포함 → board 감지·경고. 5엔드포인트 **VerifyBoardReadHmac 그룹 내** 등록(밖이면 HMAC 우회). board no-op 안전밸브 전제 = car-erp가 5xx 아닌 표준 JSON 에러 — Laravel 기본 500은 HTML, `Handler.php` API 경로 JSON 강제 확인. car-erp 먼저 배포 → board 시크릿 순서.

### 🔧 Specialist [승인·권한 정책] — 조건부 GO
SoD 정합: 변경요청(in_progress)→관리 수락/거절 = `canApprove()`(User L183-185, isAdmin||role=관리)로 보호. board는 car-erp 계정 없어 직접실행 경로 없음. 자동취소(requested)=영업 단독 허용=정책 정합. **신규 /sync에 in_progress 잠금(store L92 skip 패턴) 복제 필수** — 누락 시 착수 묶음 자동취소 가능. **batch IDOR 이중검증**(requested_by_email==salesman_email AND batch내 모든 vehicle.salesman_id==본인). **B/L bulk-apply에 canApprove guard 명시**(erp 미들웨어만으론 영업/수출통관도 접근). 변경요청 처리자(누가·언제 수락/거절) audit_logs 기록 — change_request_meta만으론 빈틈.

### 🔧 Specialist [회계·정산 감사] — 조건부 GO
**`InternalPortalController::finance() L199 = `$v->sale_unpaid_amount_krw_cache ?? 0`이 NULL(환율미입력)을 0 완납 coerce → board가 지금도 틀린 미수금 표시 중(deployed, 긴급 수정).** §5-4 "NULL 0 합산 금지(cash_audit)" 위반. 묶음 재사용 시 가짜 fully_paid. 즉시 `?? 0` 제거 + fx_missing_count(L202 패턴). **G1 최종 방어선 작동**(가짜 fully_paid가 G1 자체는 우회 못 함 — 관리 bulk-apply 시 vehicles.saving 차단) — 단 board UI에서 영업이 "완납" 보고 B/L요청 잘못 누르는 UX 버그. bl_status→정산 무관(정산 트리거=vehicles.bl_document→거래완료). 써랜더×미완납 warning은 **car-erp가 직접 계산**(board 재현=drift).

---

## 🌐 사외이사 의견

### [Codex] (gpt-5.5)
1. 우선순위: ① Security GO(HMAC+IDOR+403 고정) ② sync 트랜잭션/락/취소·진행중 가드 ③ B/L bulk apply+caches+audit.
2. **`fully_paid`는 car-erp 원천값 기준으로 보내고, board는 표시/경고만. board 계산값을 진실로 두면 drift가 운영 장애.**
3. ERP/SaaS에서 desired-state reconcile 방식이 맞음. 단 **idempotency, row lock, partial failure audit, stale board diff 표시 필수.**
4. base 유지, 배포 DB 컬럼만 추가, **v1 호환 유지하며 v2 sync 안전 병행 출시.**
5. NO-GO: (a) 인증/IDOR 미완 (b) 금액 NULL/FX 누락 오판 (c) sync가 요청중/진행중/취소 상태를 덮어쓰는 경우.

### [Gemini]
❌ 호출 실패 — `IneligibleTierError: Gemini Code Assist 개인 무료티어 단종(UNSUPPORTED_CLIENT), Antigravity 이전 안내`. 사외이사 1/2(Codex)로 진행, 프로토콜상 회의 무효화 안 함. ⚠️ `/회의`·`/cross-verify`의 Gemini 슬롯은 CLI 재인증/교체 전까지 비가용.

---

## 🚨 NO-GO 상세
없음. 전 부서 + Codex 모두 **조건부 GO** (유효 NO-GO 0). 모든 우려는 (a)(b)(c) 동반 또는 조건으로 수렴.

## 🏁 최종 권고 (Opus 4.7 최종 취합)
**판정: 조건부 GO**
**근거**: 설계 자체(새 테이블 X·묶음 영속·이중가드·NULL-safe 미수)는 6부서+사외이사 만장 정합. deployed 자산 재사용이 핵심 강점이자 함정(`finance() L199` 기존 버그 동반 수정). 충돌 1건(fully_paid 계산 위치) = Codex+Specialist-F 수렴으로 **car-erp 권위** 종결.

**필수 선행 작업**:
- **A. 마이그 2개** — `shipping_requests`(bl_type·bl_status·change_requested_at·change_request_meta) + **`vehicles.bl_type`**. nullable/default(INSTANT DDL 무중단). ShippingRequest 상수(BL_TYPES/BL_STATUS)·$fillable·$casts(json) 추가. → **스펙 §5-0 "마이그 1개" 정정.**
- **B. 미수 집계 NULL-safe + 기존 버그 수정** — `whereNotNull`/`filter(!==null)` + `fx_missing_count`, `fully_paid=(unpaid_total_krw<=0 AND fx_missing_count==0)`. **+`InternalPortalController::finance() L199 ?? 0` 긴급 수정**(board 현재 오표시). fully_paid·써랜더×미완납 warning **모두 car-erp 계산**, board는 표시/경고만(Codex 판정).
- **C. /sync 안전** — `DB::transaction()`+`lockForUpdate()`+**idempotency**(Codex) + 자동취소 **본인차·requested만**(`whereHas vehicle salesman_id`, IDOR) + **in_progress 잠금**(store L92 복제) + whereIn 벌크로드(N+1) + 응답 `{created,updated,cancelled,skipped,locked}`. board handoff에 부분전송 WARN + `cancelled[]` 노출 + **stale diff 표시**(Codex).
- **D. IDOR batch 소유권** — `/bl-request`·`/change-request` 이중검증(`requested_by_email==salesman_email` AND batch내 모든 `vehicle.salesman_id==본인`), 불일치 403. 5엔드포인트 **VerifyBoardReadHmac 그룹 내**. `Handler.php` API 경로 표준 JSON 에러.
- **E. B/L bulk-apply** — `DB::transaction()`(부분 G1 실패 시 bl_status 불일치) + `refreshCaches()` 루프(bulk update 모델이벤트 우회) + **`canApprove()` guard**.
- **F. 감사** — sync 자동취소·bl_status 전이·변경요청 처리자(누가 수락/거절) `audit_logs`. PII: `toArray()` 금지, `change_request_meta` 화이트리스트.
- **G. 테스트** — `VehicleBundleAggregateTest`(NULL coerce 방지, CI) + /sync·/bundles·/bl-request IDOR 403.

**jin 결정 필요 (3건)**:
1. **알람 target_role** — 권고: 선적요청=수출통관(현행 유지), B/L요청·변경요청=관리로 **분리**. 분리 시 `TaskAlarm::visibleToScope`·`User::canSeeAlarm` 연쇄수정.
2. **v1 API 컷오버** — Codex 권고 = **v1 병존 유지** + v2 병행 출시(breaking 회피).
3. **shippable() 의미축소** — board 의존 확인 후 적용(현재 open 묶음 차도 반환).

## 🛠 car-erp 영향 분석

### 취약점 (Vulnerabilities)
- `InternalPortalController::finance() L199 ?? 0` — **deployed NULL-coerce 버그**, board 미수금 오표시(긴급).
- 신규 4엔드포인트 IDOR(batch 소유권·sync 자동취소 범위) 미설계 시 타 영업 데이터 변조/대량취소.
- sync 부분전송 → requested 묶음 의도치 않은 자동취소(board 클라 버그 전파).
- bulk-apply 부분 G1 실패 → bl_status 불일치(트랜잭션 부재 시).

### 보완사항 (Improvements)
- sync idempotency + stale board diff 표시(Codex).
- 응답 `cancelled[]`/`locked[]` 노출로 board 재동기화.
- VehicleBundleAggregate NULL-coerce 회귀 테스트 CI 자동화.

### 코드 수정 (Code Changes)
- `database/migrations/NEW_add_bl_columns_to_shipping_requests.php` (4컬럼)
- `database/migrations/NEW_add_bl_type_to_vehicles.php` (1컬럼)
- `app/Models/ShippingRequest.php` — BL_TYPES/BL_STATUS 상수·fillable·casts
- `app/Http/Controllers/Api/Internal/ShippingRequestController.php` — bundles()·sync()·blRequest()·changeRequest() + fireShippingAlarm target_role 분기
- `app/Http/Controllers/Api/Internal/InternalPortalController.php:199` — `?? 0` 제거(긴급)
- `routes/api.php` — 4라우트(VerifyBoardReadHmac 그룹 내)
- `resources/views/livewire/erp/shipping-requests/index.blade.php` — bl_status·bulk-apply(canApprove guard)·변경요청·미수 게이지
- (조건부) `app/Models/TaskAlarm.php`·`app/Models/User.php` — target_role 분리 시
- `app/Exceptions/Handler.php` — API 경로 JSON 에러 확인

### 신규 추가 (New Additions)
- `GET /bundles`(영속 묶음+미수집계)·`POST /shipping-requests/sync`·`POST /bundles/{batch}/bl-request`·`POST /shipping-requests/change-request`
- `VehicleBundleAggregateTest` + /sync·/bundles·/bl-request 테스트
- board handoff 갱신(`board/meetings/handoff-car-erp-board-portal.md` — board 세션에서)

### 모순·NO-GO 처리 로그
- 충돌(fully_paid 계산 위치): Codex+Specialist-F 수렴 → **car-erp 권위** 채택, QA(c)대안(board 표시계산) 기각.
- 유효 NO-GO 0 — 전부 조건부 GO. (a)(b)(c) 미충족 자동무효 격하 없음.
- Gemini 사외이사 부재(IneligibleTier) — Codex 단독 진행, 회의 유효.

## 🔗 참조
- 관련 과거: `2026-06-18-board-portal-api.md`(연동 base) / `2026-06-18-excel-export-import.md`·`2026-06-29-vehicle-import-export-ui-buttons.md`(NULL/PII 패턴)
- `docs/integration/board-portal-api.md §5`(권위 스펙) / 메모리 `project_bl_bundle_shipment`·`project_cash_audit_fix`·`project_shipping_request_batch`
- `SKILLS.md §13`(미수율 단일출처)·§2(progress_status_cache)·§8 #26(재인가)
