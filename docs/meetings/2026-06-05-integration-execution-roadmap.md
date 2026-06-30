# 📅 회의록: 4시스템 통합 실행 로드맵 — 착수 순서·첫 스프린트 확정
- 일시: 2026-06-05
- 강도: 풀회의 (/회의 명령어 호출 — 대표 직접 소집)
- 안건 유형: 외부API + 마이그레이션 + 배포 + 개인정보 (복합 / **실행계획**)
- 자동발동 여부: yes (/회의 슬래시)
- 발동 부서: PO + Engineer + QA + Security + Ops + Specialist[C.외부의존성·B.데이터무결성·A.UX]
- 사외이사: Codex ✓ (조건부 GO) / Gemini ✓ (조건부 GO)

> ⚠️ 본 회의는 **타당성 재논의가 아니다.** 2026-06-02(purchase-board 아키텍처)·06-04(3자 연동) 두 풀회의에서 방향·타당성은 이미 "조건부 GO·단계적"으로 확정됐다. 이번 회의의 유일한 목적 = **확정안을 1인 개발 작업 큐·첫 스프린트로 번역**(착수 순서 결정).

---

## 0. 안건 요약

SSANCAR 4시스템 통합:
```
ssancar.com(매물 카탈로그) ─ respond.io(채팅/AI) ──A── purchase-board(매입검차경매) ──B── car-erp(원장)
                                  └────────────────── C ──────────────────────────────────┘
                                  (car-erp 입금/판매확정 → respond.io lifecycle 단방향 전진)
```
- **연동 C** = car-erp → respond.io 단방향 push (아웃바운드). [신규, 우선]
- **연동 B** = purchase-board 낙찰 → car-erp 자동등록 (06-02 확정, 카톡 수동등록 대체). [후행]
- **연동 A** = 검차 사진 → respond.io 바이어 (purchase-board MVP 후). [후행]
- **c_no(매물번호)** = 채팅에 자동 도착하는 조인키 (2026-06-04 실측).

**핵심 질문**: 현재 car-erp 잔여작업(도메인+HTTPS·queue worker·별건3) + 신규 통합(연동 C·purchase-board MVP·A·B·AI)을 한 줄 작업 큐로 배열. **진짜 첫 삽은?**

---

## 💬 부서별 발언 (Sonnet 4.6)

### 📋 PO — 조건부 GO·단계적
- 두 회의(06-02·06-04)가 확정한 단계적 순서는 이미 car-erp 잔여작업과 정합. 오늘 할 일은 순서에 숫자를 붙이는 것.
- 전제: HTTPS 없으면 purchase-board MVP·연동 C·webhook 기술적 착수 불가(라고 PO는 판단 — Engineer가 반박, 충돌1)이고 queue worker 없으면 연동 C job이 쌓이기만. 별건3(사이드바+audit UI)은 어느 role도 차단 안 함 → 인프라 후 병렬. `respond_io_contact_id`+queue worker는 06-02 "API 1개 예외" 명시 초과 → 코드 전 Jin 추가 승인 필수.
- **우선순위 큐 (PO 제안)**: ①도메인+HTTPS(2~3h) ②queue worker(2~4h) / 별건3 병렬(5~7h) ③안정화검증5건(2~3h) ④Jin 추가승인 ⑤연동C 제한파일럿(8~12h) ⑥purchase-board MVP(19~27h) ⑦연동B(4~5h) ⑧연동A(3~5h) ⑨AI 레이어(Phase3).
- **막힘 정도**: 1~3번=차단(인프라) / 5~9번=불편(현재 카톡·수동으로 돌아감, 차단 아님).
- **첫 스프린트(2주)**: 순위1 도메인+HTTPS 단독 동결.
- **Jin 결정대기 5건**: D-1 도메인 결정 / D-2 respond_io_contact_id 컬럼+worker 추가승인 / D-3 DPA 착수 / D-4 4→8GB 업그레이드 / D-5 purchase-board MVP 일정.
- 다음 작업 큐 영향: 도메인+HTTPS가 선두, 나머지 직렬. 별건3만 병렬.

### ⚙️ Engineer — 조건부 GO
- **코드 실측**: `routes/api.php` 미존재, `app/Jobs/` 미존재, `Buyer.php`/`Vehicle.php`에 `respond_io_contact_id`/`c_no`/`last_respond_lifecycle_stage` 0개, `QUEUE_CONNECTION=database` 확정, `jobs`/`failed_jobs` 마이그(`0001_01_01_000002`) 존재.
- **★ 핵심 발견**: 연동 C는 car-erp가 HTTP **클라이언트**로만 동작하는 아웃바운드 단방향 → **car-erp에 공인 HTTPS 인증서 불필요.** HTTPS/HMAC/`processed_webhook_ids`는 연동 A(inbound)의 조건이지 C의 조건이 아님. 이 구분이 C 착수 시점을 앞당기는 핵심.
- **C의 유일 HARD BLOCK = Supervisor + `queue:work --daemon`.** 미가동 시 `dispatch(RespondIoLifecycleJob)->afterCommit()`이 `jobs` 테이블에만 쌓이고 절대 실행 안 됨.
- **★ 미검증 HARD BLOCK**: respond.io Developer API(Growth tier)가 contact lifecycle stage를 **직접 세팅** 가능한지 미검증. Advanced tier "HTTP Request 모듈"과 완전히 다른 Developer API 엔드포인트. Growth에 없으면 → Custom Field(`current_erp_stage`) 쓰기 + respond.io측 Workflow 분기로 **구조 변경**. Jin이 docs 직접 확인(0.5h).
- **lifecycle 매핑(제안)**: `FinalPayment::saved`→`payment_received`(1차 입금은 progress_status_cache 안 바꿔서 Vehicle::saved로 못 잡음) / `Vehicle::saved`(4번째 훅) `판매완료`→`sale_completed`, `거래완료`→`customer`.
- **발화 3단 가드** (Vehicle::saved 내): `sales_channel='export'` + `buyer.respond_io_contact_id` 존재 + `exchange_rate>0`, + `progress_status_cache` 실제 전이(`getOriginal` 비교) + `last_respond_lifecycle_stage` 단조증가(역진 방지).
- **c_no 판정**: c_no는 **listing↔vehicle 조인키이지 person-identity 보장키 아님.** 제3자 낙찰 시 A단계 contact ≠ C단계 contact. 링크테이블 + 동일인 전제 업무정책 명문화로 닫아야 함.
- **컬럼 배치**: `vehicles.c_no`(varchar32 null), `buyers.respond_io_contact_id`(varchar64 null), `buyers.last_respond_lifecycle_stage`(varchar32 null). 롤백 SQL 3줄, default 전부 NULL, **캐시 rebuild 불필요**(progress_status_cache 무관).
- **queue worker 설치**: `apt install supervisor` → `/etc/supervisor/conf.d/car-erp-worker.conf`(`queue:work --daemon --tries=3 --max-time=3600`) → `supervisorctl`. deploy.yml에 `queue:restart`. 공수 0.5~1h, car-erp 코드 무변경.
- **착수 순서**: ⓪대표 승인 ①Growth API 능력 확인 ②Supervisor 설치 ③마이그3컬럼 ④RespondIoService ⑤RespondIoLifecycleJob ⑥Vehicle/FinalPayment saved 가드 ⑦Buyer fillable ⑧테스트/tinker. **총 8~11h**(worker 제외).
- 영향 파일: `app/Services/RespondIoService.php`(신규)·`app/Jobs/RespondIoLifecycleJob.php`(신규)·`Vehicle.php`(4번째 saved)·`FinalPayment.php`(saved)·`Buyer.php`(fillable2)·마이그3·`config/services.php`·Supervisor conf·`.env RESPOND_IO_API_KEY`. **routes/api.php는 C에서 제거**(아웃바운드 전용).

### 🧪 QA & Domain Integrity — 조건부 GO (운영 투입 전 4함정 미해결 시 HOLD 격상)
- 단방향 push 자체는 진실원천 안 깸. 발화 조건을 어디 거느냐가 3지점에서 터짐.
- **함정1 — krw_cache=null 완납 오판**: `getSaleUnpaidAmountKrwAttribute`(Vehicle.php:1187~1197)는 `exchange_rate` 0/NULL이면 `null` 반환, saving 훅(476~477)이 그대로 `sale_unpaid_amount_krw_cache=null` 저장. 발화 조건을 `<=0`으로만 짜면 NULL 분기 누락 시 미입금 차량이 Customer 전진. → **`sale_unpaid_amount_krw_cache IS NOT NULL AND <=0`** + 외화/KRW 명시 분기.
- **함정2 — 1바이어:N차량**: 같은 buyer_id에 차A(거래완료·완납)+차B(판매중·미수금) 정상 공존. 차A 거래완료가 Contact를 Customer로 전진시키면 차B 미수금 잔존에도 Customer. "Vehicle tag" vs "Contact lifecycle" 정책 선결.
- **함정3 — 2차 정산 오탐 발화**: `secondary_status='pending'` 1개월 구간 cost_extra1/2 등 수정마다 `Vehicle::saved` 발화. `progress_status_cache='거래완료'`만으로 짜면 비용 수정마다 push. → `wasChanged()`로 실질 전이 컬럼만 감시.
- **함정4 — 채널 필터 누락**: `sales_channel='export'`를 발화 조건 최상단에 안 두면 heyman/carpul 국내차량 판매완료가 respond.io로 push. 코드 없는 지금이 박을 타이밍.
- **회귀 30분 4케이스** + **신규 Unit Test 4건** (`RespondIoLifecycleDispatchTest`). ⚠️ **기존 E2E 테스트(VehicleLifecycleE2ETest 등)는 Bus::fake() 없으면 실제 HTTP 시도로 터짐** — afterCommit job 격리 필수.
- 운영 전 필수: yes. 사후 패치 불가(단방향이라 잘못 전진된 Contact를 car-erp가 못 되돌림).

### 🔒 Security & Compliance — 조건부 GO (4 게이트 미충족 시 production 금지)
- 연동 코드 0개 = 최초 설계에 whitelist 강제 가능, 기존 leak 없음(유리). 단 respond.io는 이미 메시징 가동(85/1000) → 채팅 PII 이미 국외이전 중 → **DPA는 연동 C와 무관하게 지금 즉시 착수가 맞음.**
- **Q1 DPA 절차(Jin, 변호사 불요)**: ①respond.io Trust Center 표준 DPA 수락/다운로드(30분, 스크린샷 `docs/legal/`) ②파기·반환 §36 조항 확인(누락 시 보완요청 or 약관 데이터처리 URL 문서화) ③개인정보처리방침 §28의8 국외이전 고지(respond.io·싱가포르/미국·이전항목·시점·DPA) 갱신(1h). **DPA·고지 = 지금 병행, production push는 완료 후.**
- **Q2 payload 최소화 강제점 = Job 생성자 입구.** `QUEUE_CONNECTION=database`라 Job 생성자 인자가 `jobs` 테이블에 **평문 JSON 직렬화 저장**. `RespondIoLifecycleJob($vehicle)`로 Vehicle 넘기면 `nice_reg_owner_name/addr`·`purchase_seller_account` at-rest 노출. → **생성자 = 스칼라 3필드(`contactId, stage, vehicleId`)만**, outbound payload = `{contact_id, stage}` 2필드. Bearer `.env RESPOND_IO_API_KEY`→`config/services.php`(NICE 패턴), 로그 평문 금지. 테스트 `assertArrayNotHasKey('nice_reg_owner_rrn', $payload)`.
- **Q3 inbound webhook(연동 A, HTTPS 선행에 묶임)**: HMAC raw body 검증 + replay 5분 window + `processed_webhook_ids` 멱등 테이블을 **첫 설계에 포함**(나중 추가 시 중복정리 별도 공수). secret `.env`→`config/services.php`.
- **Q4 연동 A 사진(purchase-board MVP 스펙 구속)**: S3 presigned allowlist(외관 prefix `purchase-board/inspections/vehicle-photos/`만, 서류 prefix 서명 자체 거부)·TTL 15분·번호판 마스킹(서버사이드 권장+체크박스 폴백)·inbound 번호판(매물 공개분)과 outbound 구분 명문화.
- 근거: `AuditLog.php:21-22`(MASKED), `Vehicle.php:85-125`(RRN 암호화), `config/services.php:34-35`(NICE 패턴).

### 🚀 Ops & Deploy — 조건부 GO
- **서버 실측(2026-06-05)**: RAM 총 3834MB/사용 1045MB/**가용 2788MB**, 2 vCPU, DISK 77GB(6%), **Swap 0**, loadavg 0.08. **Phase1(연동C만) 업그레이드 불필요.** 4→8GB는 purchase-board+전용DB 합류하는 Phase2 전 재평가(available<600MB 또는 loadavg>1.5 지속 시).
- **supervisord 미설치 확인 = queue worker HARD BLOCK.** deploy.yml L44 `queue:restart`는 현재 no-op(워커 없어 신호 전달 불가). `jobs` 테이블 0건(stale 백로그 없음), `ShouldQueue`/`dispatch()` 레포 0건 → 첫 기동 flush 위험 없음. 설치 다운타임 0초.
- **HTTPS**: heysellcar.com apex 이전(구 HEYMAN_ERP)과 **디커플 가능** — `erp.heysellcar.com` 서브도메인 A레코드만으로 충분. certbot --nginx, nginx reload(다운타임 0초), `.env APP_URL` 교체+config:cache(5초 미만), `SESSION_SECURE_COOKIE=true`(전원 재로그인). **유일 게이트 = DNS 존 접근권 확인.** APP_KEY 미변경(RRN 위험 0).
- **착수 순서**: [즉시·DNS무관·다운타임0] ①실측(완료) ②supervisor+worker / [DNS 확인 후·22:00 권장] ③서브도메인+certbot ④APP_URL+config:cache ⑤SESSION_SECURE_COOKIE / [Phase2 전] ⑥swap 추가 ⑦4→8GB(10~30분 다운타임).
- 백업: 각 단계 직전 `php artisan db:backup` + `cp .env .env.bak`, nginx conf 백업, ⑦전 Lightsail 스냅샷. PHP 확장(bcmath/zip/gd 등) 전부 설치 완료, 신규 0.
- 운영 전 필수: yes (worker 없으면 C job 미실행, HTTPS 없으면 webhook 등록 불가).

### 🔧 Specialist [C. 외부 의존성] — 조건부 GO
- NICE fallback 패턴(`NiceApiService.php`) 재사용: `RespondIoLifecycleJob` 실패 시 lifecycle 수동 전진 폴백, **차량 저장 절대 차단 안 함.** `dispatch()->afterCommit()` 필수(없으면 트랜잭션 내 동기 HTTP 10초가 다른 saved 훅 블록).
- 플랜 게이팅: Developer API=Growth↑(현재 Growth 확인), HTTP Request 모듈=Advanced↑(C는 미사용). `.env RESPOND_IO_API_KEY`/`RESPOND_IO_WEBHOOK_SECRET`→`config/services.php`.
- contact 병합/중복(phone 조인 취약): `respond_io_contact_id` 고정 컬럼이 유일 안정 식별자 = C 착수 전제. fallback 분기: retry 3회 backoff + dead letter + 관리자 알림 + 운영자 수동 전진.

### 🔧 Specialist [B. 데이터 무결성] — 조건부 GO (링크테이블 1차 MVP 포함 조건)
- 06-04 HOLD 사유(단일 VIN=1:1, 실무 1:N) 해소: respond.io Contact엔 고정 `ssancar_contact_id`(Custom Field)만, 매핑은 purchase-board DB 별도 링크테이블 `pb_buyer_vehicle_links(respond_io_contact_id, pb_listing_id, c_no, vin, car_erp_vehicle_id)`. **이 테이블은 MVP 1차 선행**(없으면 연동 A 조인키 부재로 착수 불가).
- `vehicles.c_no` 컬럼: 연동 B push payload에 `c_no` 포함→기록. c_no는 "어느 문의에서 온 차"의 역추적 + C가 "어느 contact 전진"의 `c_no→링크테이블→respond_io_contact_id` 경로.
- 환율0 가드: `getSaleUnpaidAmountKrwAttribute`(L1193~1194) null 반환→캐시 null(L853). 발화 조건 `IS NOT NULL AND <=0` AND 구성. `IS NULL`은 "환율 미입력 경고" 별도 액션.
- 멱등성: `wasChanged(['sale_price','exchange_rate'])` 또는 `last_respond_lifecycle_stage`로 실질 전이 비교(2차 정산 비용수정 오탐 차단).
- 운영 전 필수: yes — 링크테이블 없이 연동 A 불가, c_no 없이 1:N 매핑 불가.

### 🔧 Specialist [A. UX 설계자] — 조건부 GO (3상태 드로어 확정 후 착수)
- 목업 드로어(mockup.html L384~406)는 정적. respond.io 연동 시 **3상태 필요**: ①전송완료-응답대기(스피너→badge-blue, 구매의사 비활성) ②응답수신-자동채움(통과/미통과 badge-green/red) ③자동채움실패-수동기록(toast+버튼 재활성, SKILLS §14 fallback).
- 바이어 자유텍스트 → Searchable Select(소스=링크테이블/Buyer×`respond_io_contact_id`). 초기엔 contact_id 수동입력+확인 → 안정화 후 API-driven.
- 사진 `<input capture=environment>`(후면강제), S3 업로드→presigned(외관 prefix만)→Send Message. 서류 prefix 차단.
- 모바일 풀스크린 단일 시트(사진→바이어→구매의사)+하단 고정버튼.
- **MVP 1차 최소셋**: ①사진촬영·S3업로드 ②contact_id 수동입력 ③드로어 상태1(스피너·완료뱃지) + **수동 구매의사 토글**(Webhook 전 기간 업무 공백 방지). 상태2·3은 연동 A Webhook 후 2차. `pb_inspections.send_status`(sent/responded/failed) 컬럼이 MVP 데이터모델에 없으면 3상태 렌더 불가.

---

## 🧩 중간 회의 결과 (Opus 1차 취합)

판정 분포: **조건부 GO 6/6** · NO-GO 0. 핵심 충돌 3건 사외이사 회부:
1. **첫 삽 정체성** — PO/Ops "도메인+HTTPS 만능 1순위 HARD BLOCK" vs **Engineer "연동 C는 아웃바운드라 HTTPS 불필요, 유일 선행은 queue worker"**.
2. **연동 C vs purchase-board MVP 우선순위** — 둘 다 독립 착수 가능, 1인 개발 컨텍스트.
3. **c_no 효능 과대평가** — Fullworkflow "동일인 보장 공짜 해결" vs Engineer/Spec-B "listing↔car 조인일 뿐, person-identity 아님".

미검증 블로커: respond.io Growth tier가 Developer API로 lifecycle stage 직접 set 가능한지(불가 시 구조 변경).

---

## 🌐 사외이사 의견 (Codex / Gemini) — **3충돌 전부 독립 수렴**

### [Codex] — 조건부 GO
- **첫 삽 = 연동 C** (HTTPS 아님). C는 inbound 아니므로 Supervisor queue worker+재시도/로그/실패큐가 HARD BLOCK, 8~11h로 가장 작고 회계 이벤트 원장화를 먼저 검증.
- 놓친 리스크: ①respond.io rate limit/장애 시 재전송 멱등키 ②stage 오발송 롤백/감사로그 ③contact_id 매핑 누락·중복.
- 충돌판정: 1)**HTTPS 1순위는 오판** — HTTPS는 연동 A 착수조건, C 조건 아님. 2)**1인개발이면 연동 C 먼저**, purchase-board MVP 다음(C는 범위 작고 ERP 원장 이벤트라 실패 시 통합 전체 운영성 검증). 3)**c_no는 동일인 보장 못 푼다** — 별도 buyer identity/consent/claim 흐름 필요.
- 표준비교: 일반 ERP/SaaS는 webhook보다 먼저 queue·idempotency·audit·최소 payload·DPA. 현 방향은 표준 일치, **HTTPS 만능론만 비표준.**
- NO-GO: (a)Supervisor 없음 (b)DPA 미착수 (c)환율0·완납오판 QA 미해결. → 전부 본 회의 조건에 이미 포함 = 조건부.

### [Gemini] — 조건부 GO
- 놓친 리스크: ①worker 중단 시 **사일런트 페일 방지 모니터링(Horizon 등)+재시도** 부재 ②API 멱등성(중복 입금/수정 시 중복 알림 dedup) 누락 ③**c_no 오매칭 시 타인 정산상태가 챗봇 노출 = 단순 버그 넘어 보안/신뢰 사고.**
- 충돌판정: 1)**첫 삽 = Queue Worker** — HTTPS는 인프라 설정일 뿐, 연동 C는 비동기 아키텍처를 결정. 큐가 먼저 돌아야 로직 검증 가능. 2)**연동 C 우선** — 매입보드(신규)보다 공수 대비 Ops 효율(바이어 응대 자동화) 압도적. 3)**c_no 미해결** — 단순 참조값, `Buyer_Phone+c_no` 복합키 또는 contact_id↔ERP_id 명시 매핑 테이블 없이 자동화 승인 불가.
- 표준비교: Source-of-Truth(ERP)가 이벤트 쏘는 Event-Driven이 정석. 아웃바운드 먼저 구축해 제어권 확보는 타당.
- 재평가 순서: ①Supervisor/Queue 확보 → ②연동 C(단방향) → ③HTTPS/서브도메인 → ④연동 A/B·매입보드 MVP.
- NO-GO: (a)c_no 단독 키 기반 완전 자동발송 (b)최초 1회 상담원 수동 매핑 or 전화번호 1차 검증 (c)매칭 불확실 시 자동발송 대신 'CS 매니저 발송 대기 알림' **Human-in-the-loop**.

---

## 🚨 NO-GO 상세 (사외이사 2건 — 모두 (a)(b)(c) 충족 → 조건으로 수용)
- **Codex NO-GO**: (a)Supervisor 없음+DPA 미착수+환율0 오판 (b)셋 다 해결 (c)순서대로 선행. → 본 회의 필수선행에 이미 전부 포함 = 조건부 GO.
- **Gemini NO-GO**: (a)c_no 단독 자동발송 (b)최초 1회 수동 매핑/전화번호 검증 (c)Human-in-the-loop 발송대기. → **본 회의 신규 채택 조건**(contact_id 최초 매핑은 Buyer 편집패널 수동, 자동발송은 매핑 확정 시에만).
- → 영구 NO-GO 0. 모두 조건으로 흡수.

---

## 🏁 최종 권고 (Opus 최종 취합)

**판정: 조건부 GO — 단계적. 첫 삽은 HTTPS가 아니라 ① DPA 즉시 착수(코드무관 병행) + ② Supervisor queue worker → ③ 연동 C 제한 파일럿. HTTPS는 연동 A/purchase-board의 선행으로 재배치(C에는 불필요).**

**근거(1줄):** 내부 6부서 전원 조건부 GO + 사외이사 2인이 **3개 충돌 전부 독립 수렴** — "연동 C는 아웃바운드라 HTTPS 불필요(만능론은 비표준/오판), 첫 삽은 queue worker+연동 C, c_no는 동일인 보장 못 함(Human-in-the-loop 필요)". Engineer의 아웃바운드 발견이 PO/Ops의 HTTPS-우선 로드맵을 단축.

### 확정 수렴 설계 (내부+사외이사 만장/다수)
1. **첫 삽 = queue worker + 연동 C** (HTTPS는 연동 A 전까지 불필요). respond.io = engagement layer, car-erp = 진실원천, **단방향 push·역방향 쓰기 금지.**
2. **발화 = 실제 입금 입력 이벤트.** ⚠️ **현 운영 현실(2026-06-05 Jin 확인): role 분리(영업/재무/통관)는 아주 나중 일이고, 지금은 [관리]가 car-erp 전부 사용 + 입금액 입력 = 자동 확정(별도 확정 버튼 없음).** → 따라서 방아쇠 = **[관리]가 입금액을 적는 순간**(누가 누르냐가 아니라 "입금 confirmed 기록이 생겼냐"이므로 role 분리 없어도 그대로 작동, 오히려 SoD 핸드오프 없어 단순). **트리거 매핑 A안 확정(Jin)**: respond.io New/Hot(respond.io 자체) → **부분입금(계약금)이라도 들어오면 `Payment`** → 거래완료 시 `Customer`. 구현 = `FinalPayment::saved`→Payment(첫 입금 행), `Vehicle::saved` 거래완료 전이→Customer. 파생 cache 단독 신뢰 금지. (입금 N건이라 매번 발화 → #4 `last_respond_lifecycle_stage` 단조증가로 중복 전송 차단이 필수.)
3. **발화 4중 가드**: `sales_channel='export'` + `respond_io_contact_id` 존재 + `exchange_rate>0` + `sale_unpaid_amount_krw_cache IS NOT NULL AND <=0`. 멱등 = `wasChanged()` + `last_respond_lifecycle_stage` 단조증가.
4. **PII 최소화**: Job 생성자 = 스칼라 3필드(Vehicle 모델 금지, jobs 테이블 평문 직렬화 회피). payload = `{contact_id, stage}` 2필드.
5. **Human-in-the-loop 매핑(Gemini 신규 조건)**: contact_id↔Buyer 최초 매핑은 Buyer 편집패널 수동 입력. **자동 발송은 `respond_io_contact_id` 확정된 차량만.** c_no는 보조 추적키(`vehicles.c_no`)일 뿐 동일인 보장 아님 → 업무정책 명문화.
6. **DPA(§28)+처리방침 국외이전 고지** = production push 전 완료, 착수는 지금 병행(respond.io 이미 메시징 가동 중이라 무관하게 필요).
   - **[2026-06-05 웹 확인] DPA 수령 방법 = 자동 수락 아님, `privacy@respond.io` 이메일 요청 방식.** 공식 페이지(respond.io/data-processing-agreement)는 "이메일 보내라" 안내만.
   - **법인 정정**: respond.io = **ROCKETBOTS LIMITED (홍콩 등기)** — 회의 초안의 "싱가포르/미국" 가정 오류. 처리방침 국외이전 고지의 "받는 자/국가" = 홍콩으로 기재.
   - GDPR 준수 + ISO 27001 + EU SCC 사용. 보안문서 = trust.respond.io(SafeBase).
   - **DPA 수령 후 확인 4종**: ①계약종료 시 파기/반환 조항+기간(§36 대응) ②데이터 저장 국가/리전 ③국외이전 보호장치(SCC 명시) ④하위 처리자(sub-processor) 목록.
7. **c_no/`pb_buyer_vehicle_links`/`pb_inspections.send_status`** = purchase-board MVP 1차 스키마 선행.
8. **degrade**: respond.io 장애가 차량 저장/정산을 막지 않음(실패표기+재시도큐+수동 폴백).
9. **모니터링(Gemini 신규)**: worker 사일런트 페일 방지 — `failed_jobs` 점검 또는 Horizon 검토. 연동 감사 = `message_id/contact_id/vehicle_id/stage/sent_at/operator_id`만 기록.

### 확정 작업 큐 (착수 순서 — 본 회의 핵심 산출물)

| 순위 | 작업 | 선행 | 공수 | 비고 |
|---|---|---|---|---|
| **0** | **[지금·코드무관·병행] DPA 착수**(Jin, Trust Center 30분+처리방침 1h) + **respond.io Growth tier lifecycle API 능력 확인**(Jin, 0.5h) + **대표 추가승인**(컬럼3+worker) | — | Jin 결정 | 미충족 시 5번 HOLD |
| **1** | **[첫 삽] Supervisor + `queue:work --daemon`** (Ops, 서버) | SSH | 0.5~1h | 다운타임 0초, HARD BLOCK 해소 |
| **2** | **연동 C 제한 파일럿** (마이그3컬럼 + RespondIoService + Job + Vehicle/FinalPayment saved 4중가드 + Buyer 편집패널 contact_id 수동매핑 + Bus::fake 테스트) | 0·1 | 8~11h | export 채널만, payload 최소화 |
| **2-병렬** | **별건3** (사이드바 재구성 + `/admin/document-access-logs` + audit_logs UI) | 없음 | 5~7h | HTTPS·서버 무관, 로컬 |
| **3** | **도메인+HTTPS** (`erp.heysellcar.com` 서브도메인 + certbot + APP_URL + config:cache + SESSION_SECURE_COOKIE) | DNS 접근권 | 2~3h | **이제 연동 A/purchase-board의 선행으로 위치** (C 이후) |
| **4** | **purchase-board MVP** (별도DB+전용user+Global Scope+VIN/venue유니크+TimeGate+audit + **링크테이블 + pb_inspections.send_status 1차 포함**) | 3 | 19~27h | 포트 8002 |
| **5** | **연동 B** (낙찰→`POST /api/internal/purchase-sync`, c_no 전파) | 4 | 4~5h | car-erp API 1개(HMAC) |
| **6** | **연동 A** (검차사진, S3 presigned 외관 prefix 수동전송→자동, 드로어 상태2·3) | 4 + DPA 파기조항 | 3~5h | inbound webhook HMAC+멱등 |
| **7** | **AI 레이어** (respond.io AI + car-erp 읽기 통로) | 2·5·6 안정화 | Phase3 별도설계 | 가드레일 3 선행 |

### 필수 선행 (운영 전 — 미충족 시 투입 금지)
- Supervisor queue worker 가동 (연동 C 절대선행).
- respond.io DPA(§28·§36 파기) + 처리방침 국외이전 고지 (production push 전).
- respond.io Growth tier lifecycle API 능력 확인 (불가 시 Custom Field+Workflow 구조 변경).
- 4중 발화 가드(export·contact_id·환율0·krw_cache NOT NULL) + 멱등(last_respond_lifecycle_stage).
- Job 생성자 스칼라 3필드 + payload 2필드 (RRN at-rest 차단).
- Human-in-the-loop: contact_id 최초 수동 매핑, 자동발송은 확정 차량만.
- Bus::fake() 테스트 격리 (기존 E2E 보호).
- **대표 추가 승인**: `respond_io_contact_id`+`last_respond_lifecycle_stage`+`c_no` 컬럼 + queue worker (06-02 "API 1개 예외" 초과).

### 첫 스프린트 (다음 2주 동결)
- **순위 0(Jin 결정 3건) + 순위 1(queue worker) + 순위 2(연동 C 제한 파일럿)**. 별건3 병렬 가능.
- 완료 기준: export 채널 차량 입금확정 → respond.io contact lifecycle 전진(수동 매핑된 차량), heyman/carpul·환율0 차량 미발화, Bus::fake 테스트 4건 통과.
- **HTTPS·purchase-board MVP는 이 스프린트에서 제외**(연동 C에 불필요, 다음 스프린트).

---

## 🛠 car-erp 영향 분석

### 취약점 (Vulnerabilities)
- Job 생성자에 Vehicle 모델 전달 시 `jobs` 테이블 평문 직렬화로 `nice_reg_owner_*`·`purchase_seller_account` at-rest 노출 → **스칼라 3필드 생성자**.
- 환율0 외화 차량 `sale_unpaid_amount_krw_cache=null` → 발화 조건 `<=0`만이면 완납 오판·Customer 오전진 → **IS NOT NULL AND <=0**.
- `Vehicle::saved` 모든 저장 발화 → 2차 정산 비용수정 오탐 push → **wasChanged 화이트리스트**.
- c_no 오매칭 시 타인 정산상태 챗봇 노출(Gemini, 보안사고급) → **Human-in-the-loop 매핑 + 자동발송 확정 차량 한정**.
- 동기 HTTP in saved 훅 → 트랜잭션 내 10초 블록 → **dispatch afterCommit**.

### 보완사항 (Improvements)
- worker 사일런트 페일 모니터링(`failed_jobs`/Horizon, Gemini)·재전송 멱등키(Codex)·stage 오발송 롤백 감사로그(Codex)·contact 병합/중복 phone 조인 취약(Spec-C/Codex)·reconciliation(06-04 Gemini).
- respond.io Growth tier API 능력 사전 확인(불가 시 구조 변경 — Engineer).
- 검차바이어=낙찰자 동일인 업무정책 명문화.

### 코드 수정 (Code Changes) — 순위 2(연동 C), 대표 승인 후
- 신규: `app/Services/RespondIoService.php`, `app/Jobs/RespondIoLifecycleJob.php`(`app/Jobs/` 디렉터리 신규), 마이그3(`vehicles.c_no`·`buyers.respond_io_contact_id`·`buyers.last_respond_lifecycle_stage`).
- 수정: `Vehicle.php`(4번째 saved 4중가드), `FinalPayment.php`(payment stage saved), `Buyer.php`(fillable2 + 편집패널 contact_id 수동입력 UI), `config/services.php`(respondio 키), `.env`(`RESPOND_IO_API_KEY`).
- 테스트: `RespondIoLifecycleDispatchTest` 4건 + 기존 E2E에 Bus::fake().
- **routes/api.php는 연동 C에서 불필요**(아웃바운드). 연동 A(순위6)에서 inbound webhook용으로 신규.
- 캐시 rebuild: **no** (신규 3컬럼은 progress_status_cache/krw_cache 무관).

### 신규 추가 (New Additions)
- Lightsail: Supervisor + `car-erp-worker.conf`(numprocs=2) / (순위3) `erp.heysellcar.com` certbot vhost / (Phase2 전) swap 1G·4→8GB 검토.
- respond.io: Custom Field `ssancar_contact_id`, Developer API 토큰(.env), (순위6) Outbound Webhook HMAC secret.
- 법무: respond.io DPA(§28·§36) + 처리방침 국외이전 고지. **수령=`privacy@respond.io` 이메일 요청**(자동수락X). 법인=ROCKETBOTS LIMITED(홍콩). 수령 후 파기조항·저장국가·SCC·sub-processor 확인.
- purchase-board(신규앱): `pb_buyer_vehicle_links` 링크테이블 + `pb_inspections.send_status` (MVP 1차).

### 모순·NO-GO 처리 로그
- 충돌1(첫 삽): PO/Ops HTTPS-우선 vs Engineer 아웃바운드 → **사외이사 2인 만장으로 Engineer 지지**(HTTPS 만능론 비표준) → queue worker+연동 C가 첫 삽, HTTPS는 연동 A 선행으로 재배치.
- 충돌2(C vs MVP): **사외이사 2인 만장 연동 C 우선**(공수 작고 ERP 이벤트 검증) → 큐 순위 2 vs 4.
- 충돌3(c_no): Fullworkflow "공짜 해결" vs Engineer/Spec-B "보장 아님" → **사외이사 2인 만장 후자 지지** → c_no=보조 추적키, 동일인은 Human-in-the-loop+정책 명문화.
- Codex/Gemini NO-GO 2건 모두 (a)(b)(c) 충족 → 조건으로 흡수(영구 NO-GO 0). Gemini Human-in-the-loop는 신규 조건 채택.

---

## 🔗 참조
- 직전 회의: [2026-06-04-tri-system-integration-respondio.md](2026-06-04-tri-system-integration-respondio.md)(3자 연동 타당성) / [2026-06-02-purchase-board-architecture.md](2026-06-02-purchase-board-architecture.md)(purchase-board 설계)
- 자료: `C:\Users\User\Desktop\Fullworkflow.md` / `docs/integration-scenario.html` / `docs/purchase-board-mockup.html`
- CLAUDE.md: 권한·RRN·APP_KEY·S3·Lightsail·"다음 세션 작업" / SKILLS §13(미수율 단일출처)·§14(NICE fallback)·§10(디자인)
- 코드 앵커: `Vehicle.php:1187-1197`(krw null)·`:474-477`(saving cache)·`config/services.php:34-35`(NICE 패턴)·`.env QUEUE_CONNECTION=database`·deploy.yml L44(queue:restart no-op)
