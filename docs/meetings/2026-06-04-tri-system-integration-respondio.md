# 📅 회의록: respond.io ↔ car-erp ↔ purchase-board 3자 연동 타당성·방향
- 일시: 2026-06-04
- 강도: 풀회의 (/회의 명령어 호출 — 대표 직접 소집)
- 안건 유형: 신규 외부 SaaS 연동 아키텍처 + 개인정보 국외이전 + 06-02 경계 확장
- 자동발동 여부: yes (대표 "/회의 돌려봐도 되지 않나" + "사외이사 호출")
- 발동 부서: PO + Engineer + QA + Security + Ops + Specialist[C.외부의존성·B.데이터무결성·A.UX]
- 사외이사: Codex ✓ (조건부 GO) / Gemini ✓ (현재 NO-GO→조건충족 시 조건부 GO, flash 모델 — pro 쿼터 소진)

> ⚠️ 본 안건은 **2026-06-02 purchase-board 회의의 후속·확장**이다. 06-02는 `purchase-board ↔ car-erp` 2자 경계만 확정했고, **respond.io(외부 클라우드 SaaS)는 그 회의에 없었다.** 이 회의는 세 번째 꼭짓점을 추가한다.
> ⚠️ 연동 B(낙찰→car-erp)는 06-02에서 확정. **재논의 금지.**

---

## 0. 안건 요약

세 시스템을 하나로 연결한다:
```
respond.io (수요·해외 바이어)  ──A──  purchase-board (공급·매입검차경매)  ──B──  car-erp (원장)
   New→Hot→Payment→Customer        영업제시가→검차→경매→낙찰              재고/정산/통관/BL/DHL
        ↑──────────────────────────── C ────────────────────────────────────┘
        (car-erp 판매/입금 → respond.io 라이프사이클 자동전진)
```

**핵심 통찰:** 목업(`docs/purchase-board-mockup.html` L384~406) 검차 드로어의 "바이어에게 사진 공유 → 구매의사(통과/미통과)"에서 그 **'바이어'가 곧 respond.io contact**다. 세 시스템은 이미 한 업무흐름인데 도구만 분리돼 있다.

**연동 삼각형:**
- **A.** respond.io 바이어 ↔ purchase-board 검차 "바이어 확인" (사진공유 + 구매의사 회수) [신규]
- **B.** purchase-board 낙찰 → car-erp 재고 [06-02 확정, 재논의 금지]
- **C.** car-erp 판매/입금 → respond.io 라이프사이클(Payment/Customer) 자동전진 [신규]

**respond.io API 표면(검증 완료, developers.respond.io / respond.io/help):**
| 기능 | 가능 | 제약 |
|---|---|---|
| Outbound Webhook (이벤트→외부 endpoint) | ✓ | endpoint가 **5초 내 200 OK** 미반환 시 실패/재시도/비활성화 |
| Send a Message API v2 (text+이미지, Bearer) | ✓ | Developer API 토큰 |
| Custom Contact Fields | ✓ | 조인키 저장처 |
| Workflow "HTTP Request" 모듈 | ✓ | **Advanced 플랜↑, 10초 timeout** |
| Developer API | ✓ | **Growth 플랜↑** |

---

## 💬 부서별 발언 (Sonnet 4.6)

### 📋 PO — HOLD
- 방향은 맞다(수요·공급·원장이 동일 업무흐름인데 도구만 고립). 그러나 **오늘 이 연동 부재로 막히는 role이 없다**(현재 카톡/수동으로 돌아감 = 불편이지 차단 아님).
- **A는 2겹 의존성:** purchase-board MVP 라이브가 선행 → 그 MVP는 06-02에서 도메인+HTTPS+안정화 이후로 이미 게이팅됨. 없는 앱 위에 연동 계약을 맺는 격.
- **C의 ROI 불분명:** respond.io Developer API=Growth↑, HTTP Request=Advanced↑. 절감하는 건 "영업이 라이프사이클 수동으로 넘기는 클릭 몇 번". 월정액 플랜 인상 대비 절감 시간 수치 없음. NICE처럼 건당 원가 API가 아니라 티어라 사용량 낮으면 ROI 더 나쁨.
- **신원 매핑 부재:** `Buyer.php`에 respond.io contact_id 필드 0(확인됨). 수동 입력 Buyer가 어느 contact인지 car-erp가 알 방법 없음.
- **채널 스코핑 모순(놓치기 쉬움):** respond.io contact = export 해외바이어 전용. `sales_channel` enum의 heyman/carpul(국내바이어)은 contact 없음 → C를 단순 구현하면 국내차량 판매완료 시 silent fail/오매핑. **C 발화 조건에 `export` 채널 필터 하드코딩 필수.**
- 우선순위: **나중.** purchase-board MVP 라이브 + 영업/재무가 수작업 부담을 실제 숫자로 보고한 시점에 재심.

### ⚙️ Engineer — 조건부 GO
- **A는 car-erp 코드 0 접촉**(purchase-board + respond.io Outbound Webhook + Send Message API). car-erp 공수 계상 밖.
- **C만 car-erp 신규(8~12h).** 최대 위험: **`Vehicle::saved` 훅 안에서 외부 HTTP 동기 호출 금지** — `DB::transaction()` 내 10초 timeout 대기 + rollback 시 라이프사이클 이미 전진 불일치. 올바른 패턴 = `dispatch(RespondIoLifecycleJob)->afterCommit()`.
- **선행 블록:** `QUEUE_CONNECTION=database`인데 **운영 queue worker 미가동**(2026-05-12 회의록 명시). job이 쌓이기만 하고 실행 안 됨 → Supervisor + `queue:work --daemon` 배포가 C의 선행 조건.
- **멱등성:** `Vehicle::saved`는 모든 save마다 발화 → `wasChanged()`로 라이프사이클 실질 전이만 push. `last_respond_lifecycle_stage` 컬럼으로 이전 stage 비교.
- **car-erp 수정범위 초과:** `respond_io_contact_id` 컬럼 마이그레이션 + queue worker 도입은 06-02 "API 1개 예외" 범위 밖 → **추가 명시 승인 필요.**
- C 호출 방향 = car-erp가 respond.io를 아웃바운드 단방향. respond.io→car-erp inbound webhook은 C 범위 불필요(라이프사이클 전진 권원이 car-erp 이벤트에 있음).
- 영향 파일: `routes/api.php`(신규) / `app/Services/RespondIoService.php` / `app/Jobs/RespondIoLifecycleJob.php` / `Vehicle.php`(saved 가드) / `Buyer.php`+마이그레이션 / Supervisor 설정.
- **놓치기 쉬움:** 검차 단계 '바이어'(A 시점 respond.io contact)와 최종 낙찰자(C 시점 car-erp Buyer)가 **동일인 보장 키 없음.** 제3자 낙찰 시 A에서 연결한 contact와 C에서 전진시킬 contact가 어긋남 → 동일인 전제를 업무 정책으로 명문화 필요.

### 🔒 Security & Compliance — 조건부 GO (NO-GO급 라인 존재)
- 핵심은 car-erp 내부통제가 아니라 **국경 넘는 PII egress 거버넌스.** 06-02의 "별도 DB+APP_KEY 분리"는 내부 격리 — respond.io 국외이전(개보법 §28/§28의8)엔 **직접 적용 안 됨.** 규제 레짐이 다름.
- **[R1] 판매자 PII가 respond.io 도달 — NO-GO급:** 검차 사진에 차량등록증·성능점검부·말소신청서 스캔이 섞이면 `nice_reg_owner_name/addr`(평문)·등록증상 RRN이 첨부로 국외이전. **공유 가능 이미지 = "차량 외관/상태" 만, 서류 클래스는 API 계층 차단.**
- **[R2] Inbound webhook 위조 — NO-GO급:** respond.io Outbound Webhook 수신 시 HMAC 서명검증 + replay 방지(timestamp 5분 window) 없으면 외부에서 상태 조작 가능.
- **[R3] C payload 최소화 + §28 위탁근거:** 허용 필드 = `{respond_contact_id, stage, vehicle_id(internal)}` 뿐. `nice_reg_owner_*`·`purchase_seller_account`·매입가·판매가·`buyer_id` 전송 금지. Bearer 토큰 `.env`→`config/services.php`(NICE 패턴), 로그 평문 금지. §28 = 위탁계약(DPA)/약관/정보주체 고지 중 하나 사전 확보돼야 API 호출 자체가 적법.
- **대안:** A 사진공유를 respond.io 직접 첨부 대신 **S3 presigned URL(15분 TTL)** — 차량외관 prefix(`purchase-board/inspections/vehicle-photos/`)만 허용, 서류 prefix(`.../documents/`) 완전 차단. respond.io는 URL 포인터만 보유.
- **놓치기 쉬움 — 파기 불이행(§36):** car-erp에서 바이어 삭제/보존기간 경과해도 respond.io 사본은 자동 삭제 안 됨. DPA에 "파기·반환 의무·기간·방법" 미명시 시 §28 위탁요건 자체 미충족. **C가 한 번이라도 PII push하면 소급 이행 불가** → 설계 단계에서 DPA 파기조항 선제 삽입이 유일 수단.

### 🧪 QA & Domain Integrity — 조건부 GO
- 연동 C는 **단방향 push**인 한 car-erp 진실원천 안 깸. **car-erp=읽기전용 소스, respond.io=하위 Projection** 으로 문서화 필수.
- **[R1] 진실원천 drift + krw_cache 오판:** 판매완료 게이트 = `sale_price>0 AND 판매미입금≤0`, 50% 입금→선적 / 100%→B/L 2단 게이트. respond.io "Payment" 하나로 어디 매핑? **외화 차량 환율0/누락 시 `sale_unpaid_amount_krw_cache=0`** → 미입금 차량이 완납 오판되어 Customer 자동전진. 파생 cache 아니라 **원화·외화 병렬 미수금 판단**을 발화 조건에. **respond.io→car-erp 역방향 쓰기 금지 명문화**(바이어 분쟁/환불로 운영자가 lifecycle 되돌릴 때 car-erp 건드리지 말 것).
- **[R2] 1바이어:N차량 vs lifecycle 단일 enum:** 같은 바이어가 차A(거래완료)+차B(미수금 잔존) 동시 보유 가능. 어느 차 완료가 Contact를 Customer로 전진시키나? 1:N 매핑 정책(차량별 tag vs contact lifecycle) 선결.
- **[R3] 멱등성:** 10초 timeout 재전송 → 중복 전진. set-state(현재 X면 Y) 방식 또는 idempotency key.
- **회귀 3종(25분):** ①외화차 환율0 변경 시 cache 오판 방지 ②1:N에서 미수금 차량 Customer 미전진 ③timeout 재전송 중복전진 차단.
- **놓치기 쉬움:** 2차 정산(기타비용 1개월 후 수정) 저장이 "판매 필드 변경"으로 묶여 webhook 오탐 발화 가능 → 정산수정 이벤트와 판매완료/입금 이벤트를 발화 조건에서 명시 분리.

### 🚀 Ops & Deploy — 조건부 GO
- **[R1] inbound webhook = 공인 HTTPS 필수 (현재 HARD BLOCK):** car-erp는 현재 `http://52.79.200.151` IP 직접(도메인+HTTPS 보류). respond.io Webhook은 공인 HTTPS 요구 → A·C 양방향 트리거 불가. 같은 인스턴스면 Nginx vhost+certbot으로 서브도메인 2개(`erp.…`, `purchase.…`) 대응. **HTTPS 선행 없이 착공 불가.**
- **[R2] queue worker / 자원:** 10초 timeout 회피 = webhook 수신→즉시 202→Job 처리. Supervisor+`queue:work` 상시 가동 필요(`queue:restart`는 재시작 신호일 뿐, 워커 미가동 시 무의미). **4GB/2vCPU에 car-erp+MySQL+purchase-board+전용DB+워커2개+webhook 트래픽** 경합 → 배포 전 `free -m`/`nproc` 실측, 필요시 4GB→8GB($20→$40/월) PO 합의.
- **[R3] 토큰 관리 / SaaS 장애:** Bearer 토큰·webhook secret 양쪽 `.env`. **respond.io 다운 = 검차 흐름 정지** → 사업 측에 명시 + purchase-board 수동 검차 입력 폴백 병행.
- 다운타임 1~3분(`artisan down`), 22:00 이후 권장. 배포 직전 수동 `db:backup`.
- **놓치기 쉬움 — webhook 멱등성:** respond.io 전달 실패 시 재시도 → `processed_webhook_ids` 테이블 + unique 체크를 **첫 설계에** 포함(나중 추가 시 중복 데이터 정리 별도 공수).

### 🔧 Specialist [C. 외부 의존성] — 조건부 GO
- **API fallback(SKILLS §14 NICE 패턴 재사용):** respond.io Send Message 실패(타임아웃·플랜다운·키만료) 시 **사진 전송만 실패 표기, 구매의사 저장(드로어)은 차단 금지.** 현장 검차팀이 10초+ 화면 멈춤 겪으면 안 됨 → 재시도 큐로 비동기화.
- 플랜 게이팅 `.env`에 티어 명시 후 배포 전 확인. Bearer는 `RESPOND_IO_API_KEY`로만, 로그 평문 금지. 바이어 답변 Outbound Webhook 수신엔 HMAC 또는 IP 화이트리스트.

### 🔧 Specialist [B. 데이터 무결성] — HOLD
- **조인키 "Custom Field에 단일 VIN" = 1:1 설계, 실무는 1:N**(새회의 항목3 group set: 바이어가 차1/차2/차3 복수구매). Custom Field에 VIN 쓰면 두 번째 차 조회 시 첫 VIN 덮어써져 추적 단절.
- **올바른 설계:** respond.io Contact엔 **고정 `ssancar_contact_id`(또는 contact_id)만**, VIN-contact 매핑은 전량 **purchase-board DB 별도 링크테이블**(`pb_buyer_vehicle_links: respond_io_contact_id, pb_listing_id, vin, car_erp_vehicle_id`). 이러면 1:N 정상 + respond.io에 차량 원장 데이터 미유출.
- 06-02 데이터모델에 `car_erp_vehicle_id` 역참조는 있으나 `pb_contact_id` 미포함 = 이 gap이 HOLD 사유.

### 🔧 Specialist [A. UX 설계자] — 조건부 GO
- 목업 드로어가 정적 → respond.io 연동 UX 미설계. 검차팀이 사진 찍어 바이어 WhatsApp 전송 → 답("살게요") 회수 → 구매의사 자동채움 흐름엔 **3상태 필요**: ①전송완료-응답대기 ②응답수신-통과/미통과 자동채움 ③자동채움 실패-수동기록.
- 바이어 입력이 자유텍스트(`placeholder="ABC Trading"`) → respond.io contact_id **Searchable Select**(SKILLS §10)로 교체. 사진 `<input capture=environment>`(후면카메라) 실구현. "전송중" 스피너 + "전송실패→수동기록 전환" 폴백 드로어 내 필수.
- **놓치기 쉬움:** 검차 현장사진에 **차량 번호판**이 찍히면 번호판도 국외이전 고지 대상 가능(§2 개인정보 해당여부 검토). S3 서명URL 만료 짧게.

---

## 🧩 중간 회의 결과 (Opus 1차 취합)

판정 분포: **조건부 GO 5 (Eng·Sec·QA·Ops·Spec-C/A) / HOLD 3 (PO·Spec-B)** · 영구 NO-GO 0. Security·Spec-B는 조건 강함. → 핵심 충돌 6건 사외이사 회부:
1. **타이밍** — PO(지금 아님, MVP·HTTPS·ROI 선행) vs 나머지(C부터 기술 가능)
2. **조인키 1:N** — Spec-B/QA(별도 링크테이블+contact_id만) vs 안건초안(Custom Field VIN)
3. **사진공유 PII 국외이전** — Security(서류·번호판 차단+S3 presigned+§28 DPA)
4. **car-erp 수정범위** — Engineer(contact_id 컬럼+queue worker가 06-02 예외 초과)
5. **인프라 선행** — Ops(HTTPS·queue worker·자원 HARD BLOCK)
6. **상태 drift** — QA(단방향 강제·역방향 금지·환율0 오판)

---

## 🌐 사외이사 의견 (Codex / Gemini)

### [Codex] — 조건부 GO (A=HOLD, C=제한 파일럿만 GO, B=06-02 유지)
- "지금 바로 3자 완전연동은 과하다."
- **놓친 리스크 3:** ①**contact 병합/중복** — WhatsApp번호·광고·이메일 섞이면 동일바이어가 다중 contact. phone 조인 장기 취약 → 내부 contact_id 고정매핑 필수 ②**메시지 발송 동의·채널정책** — 검차 사진공유가 "상담연장"인지 "마케팅성"인지 채널별 opt-in/템플릿 규정 ③**감사추적 부재** — 누가·언제·어느 사진을·어느 바이어에게·무슨 응답으로 통과 처리했는지. 원문 복사 말고 `message_id/contact_id/vehicle_id/sent_at/decision/operator_id`만 기록.
- **충돌 판정:** 1)PO 지지(A=MVP·HTTPS·사진정책 후, C=인프라 후 소범위) 2)Spec/QA 지지(단일VIN 금지, 별도 관계테이블) 3)Security 지지+더 강하게(서류·번호판·판매자정보·RRN·주소 도달=NO-GO, §28의8 별도동의·공개·보호조치) 4)Engineer 기술적 정당하나 거버넌스상 추가승인(06-02 "API 1개"는 B용, C는 별도변경 — 단 car-erp 핵심테이블 수정 최소화·별도 연동테이블/모듈) 5)Ops 지지(HTTPS·queue 선행, Webhook은 Advanced↑ + **5초 내 200 OK** 미반환 시 실패/재시도/비활성화) 6)QA 지지(역방향 금지, 파생값 아닌 실제 입금/정산확정 이벤트만).
- **시장 표준:** ERP/업무DB=진실원천, 메시징 SaaS=engagement layer. ERP→SaaS는 outbox/job/queue 비동기 발송, SaaS→내부는 webhook 수신 후 inbox/idempotency 기록, 식별자는 contact custom field에 업무상태 최소화·내부 링크테이블로 1:N 관리, 저장성공과 발송성공 분리(재시도·dead letter·수동발송), 외부엔 contact id·차량요약·외관사진 링크만.
- **NO-GO:** (a)판매자 RRN/이름/주소/서류·번호판 원본 respond.io 도달=국외이전·삭제권 대응 불가(PIPC 처리방침 공개·보호조치·제3국 재이전 준용 요구) (b)최소조건=DPA/국외이전 근거·HTTPS·HMAC·queue worker·idempotency·S3 presigned·서류 prefix 차단·번호판 마스킹·수동 fallback (c)대안=**당장은 C만 제한 파일럿**("입금/판매확정→lifecycle 전진"만), A 사진공유는 MVP 후 수동 링크전송부터. 소규모엔 이게 비용 대비 가장 현실적.

### [Gemini] — 현재 NO-GO → 조건충족 시 조건부 GO
- 현재상태 NO-GO 사유 = Security PII 국외이전 라인 + Ops HTTPS HARD BLOCK.
- **놓친 리스크 3:** ①**데이터 정합성 조정(reconciliation) 부재** — 양 시스템 상태 미스매치 탐지/해결 프로세스 없음(수동조정 비용·오류) ②**통합 모니터링/경고 부재** — API 성공/실패·응답시간·지연 추적 체계 없음 ③**유지보수/거버넌스** — car-erp 수정승인이 산발적, 데이터 거버넌스 모델 부재.
- **충돌 판정:** 1)HOLD(MVP·HTTPS 선행) 2)Spec 지지(별도 링크테이블) 3)Security 지지(NO-GO 확고, S3 presigned+DPA+삭제권) 4)Engineer 지지(06-02 예외 초과, 공식승인) 5)Ops 지지(HTTPS·queue 필수, 자원 업그레이드) 6)QA/Ops 지지(단방향·멱등·재시도·환율오판 방지).
- **시장 표준:** 경량 API Gateway/서버리스 미들웨어로 결합도↓ + 이벤트기반 비동기(SQS/SNS) + 진실원천 단방향 + 인증(HMAC/OAuth) + PII 최소화/토큰화 + 멱등·재시도(back-off).
- **NO-GO 최소조건:** PII 비식별화/제거(RRN·이름·주소·서류·번호판), S3 presigned, DPA+고지/동의, car-erp HTTPS, Supervisor queue worker. **대안:** 초기 수동/반자동(A=S3 URL 수동복사 전송·구매의사 수동기록, C=담당자 respond.io에서 수동 단계변경) → PII·인프라 해결 후 비식별 상태부터 단계 자동화.

---

## 🚨 NO-GO 상세 (사외이사 — 모두 (a)(b)(c) 충족 → 조건으로 수용)
- **Codex NO-GO:** (a)판매자 RRN/서류/번호판 원본 respond.io 도달 (b)DPA·HTTPS·HMAC·queue·멱등·S3 presigned·서류차단·번호판마스킹·수동fallback (c)C만 제한 파일럿 → A는 MVP 후 수동부터.
- **Gemini NO-GO:** (a)민감 PII 국외이전 + HTTPS 부재 inbound 불가 (b)PII 비식별화·S3 presigned·DPA·HTTPS·Supervisor queue (c)초기 수동/반자동 → 비식별부터 단계 자동화.
- → 두 NO-GO 모두 **"민감 PII가 respond.io에 닿지 않게 격리 + 인프라(HTTPS·queue) 선행"** 으로 해소되는 조건부. 아래 최종 권고가 충족.

---

## 🏁 최종 권고 (Opus 최종 취합)

**판정: 조건부 GO — 단, 단계적. 지금 즉시 3자 완전연동은 NO. B 유지 / C 우선(선행조건 후 제한 파일럿) / A 후행(purchase-board MVP 후).**

**근거(1줄):** 내부 8발언 + 사외이사 2인이 "방향 타당, 단방향 이벤트 연동이 정석, 그러나 민감 PII 국외이전 차단 + HTTPS·queue worker 인프라 선행이 절대조건"으로 만장 수렴. 영구 NO-GO 0, 그러나 선행 미충족 시 운영 투입 금지.

### 확정 수렴 설계 (8발언+사외이사 만장 또는 다수)
1. **진실원천 = car-erp(원장). respond.io = engagement layer.** 모든 연동 **단방향 push, respond.io→car-erp 역방향 쓰기 금지**(QA·Codex·Gemini 만장). 발화 트리거 = 파생 cache(`krw_cache=0`) 아니라 **실제 입금/정산확정 이벤트**.
2. **조인키 = respond.io Contact엔 고정 식별자(`ssancar_contact_id`)만.** VIN/차량 매핑은 **별도 링크테이블**(`pb_buyer_vehicle_links` 등), Custom Field 단일 VIN **금지**(Spec-B·QA·Codex·Gemini 만장, 1:N 지원). phone 조인 금지 → contact_id 고정매핑(Codex).
3. **민감 PII 국외이전 차단 (NO-GO 라인):** 판매자 RRN·이름·주소·서류이미지·번호판 원본 respond.io 도달 금지. 사진공유 = **S3 presigned URL(짧은 TTL) + 차량외관 prefix만 허용 + 서류 prefix API 차단 + 번호판 마스킹 검토**. C payload = `{contact_id, stage, vehicle_id(internal)}` 만.
4. **§28 국외이전 적법근거:** respond.io DPA(파기·반환·기간·방법 조항 포함) 또는 정보주체 고지·동의 — **코드 배포 전 확보.** §36 삭제권 대응 위해 파기조항 선제.
5. **인프라 선행 (HARD BLOCK):** car-erp 공인 도메인+HTTPS, Supervisor+`queue:work` 상시 가동, 자원 실측(4→8GB 검토). inbound webhook = 수신 즉시 202 + 큐 처리(10초 timeout / 5초 200 OK 회피) + **HMAC 서명검증 + replay 방지 + `processed_webhook_ids` 멱등**.
6. **degrade 우선:** respond.io 장애가 검차/판매 저장을 막지 않음(API 실패=실패표기+재시도큐, 수동 폴백 UI 병행).
7. **car-erp 수정범위:** `respond_io_contact_id` 컬럼 + queue worker 도입 = **06-02 "API 1개 예외" 초과 → 대표 추가 명시 승인 필요.** 단 핵심테이블 수정 최소화(별도 연동테이블/모듈).
8. **감사추적:** 사진전송·구매의사·lifecycle 전진 = `message_id/contact_id/vehicle_id/sent_at/decision/operator_id`만 기록(원문 복사 금지).
9. **채널 스코핑:** C 발화 조건에 `sales_channel=export` 필터 하드코딩(heyman/carpul 국내바이어 silent fail 방지).

### 권장 단계 로드맵
- **Phase 0 (지금):** 방향 기록(이 회의록). 선행 = ①purchase-board MVP ②car-erp 도메인+HTTPS ③queue worker(Supervisor) — **이미 기존 큐에 있는 항목.** + respond.io 플랜 티어 확인(Growth/Advanced), DPA 체결 검토 착수.
- **Phase 1 (인프라 후, 가장 먼저 가능):** **연동 C 제한 파일럿** — car-erp 판매/입금확정 → respond.io lifecycle 단방향 전진. export 채널만, payload 최소화, 멱등. 공수 8~12h + 컬럼/worker 승인.
- **Phase 2 (purchase-board MVP 후):** **연동 A** — 검차 사진공유. 처음엔 S3 presigned URL **수동 전송**(Gemini/Codex 대안) → 안정화 후 자동화. 서류/번호판 차단 필터 + 동일인 보장키 정책.

### 필수 선행 (운영 전 — 미충족 시 투입 금지, Security 양보불가 라인)
- car-erp 도메인+HTTPS / Supervisor queue worker 가동 / 자원 실측
- respond.io DPA(§28·§36 파기조항) 또는 고지·동의 적법근거
- 민감 PII 차단: S3 presigned(외관 prefix만)·서류 prefix 차단·payload 최소화
- inbound webhook HMAC+replay+멱등(`processed_webhook_ids`)
- 단방향 강제(역방향 쓰기 금지) + 환율0 오판 가드 + export 채널 필터
- 별도 링크테이블(1:N) + contact_id 고정매핑
- degrade 폴백(respond.io 장애 시 검차/판매 저장 지속)
- **대표 추가 승인:** car-erp에 `respond_io_contact_id` 컬럼 + queue worker 도입(06-02 예외 초과)

---

## 🛠 car-erp 영향 분석

### 취약점 (Vulnerabilities)
- 검차 사진/서류 이미지 경로로 `nice_reg_owner_name/addr`(평문)·등록증 RRN 국외이전(개보법 §28) → **S3 presigned 외관 prefix만 + 서류 prefix 차단**으로 차단.
- inbound webhook HMAC 미검증 시 상태 조작 → **HMAC+replay+멱등**으로 차단.
- 외화 환율0 → `sale_unpaid_amount_krw_cache=0` 미입금 완납 오판 → **병렬 미수금 판단** 발화조건.
- `Vehicle::saved` 동기 HTTP → 트랜잭션 내 10초 대기/롤백 불일치 → **afterCommit job**.

### 보완사항 (Improvements)
- contact 중복/병합 → contact_id 고정매핑(Codex). 메시지 발송 동의·채널정책 문구(Codex). 데이터 reconciliation·통합 모니터링(Gemini). 감사추적 메타만 기록(Codex). 검차바이어=낙찰자 동일인 정책 명문화(Engineer).

### 코드 수정 (Code Changes) — Phase 1(C) 기준, 대표 승인 후
- **car-erp**: `routes/api.php`(신규) + `app/Services/RespondIoService.php` + `app/Jobs/RespondIoLifecycleJob.php` + `Vehicle.php`(saved 멱등 가드) + `Buyer.php`+`respond_io_contact_id` 마이그레이션 + `last_respond_lifecycle_stage` 마이그레이션. **그 외 car-erp 무수정.**
- **purchase-board(신규앱)**: `pb_buyer_vehicle_links` 링크테이블, respond.io Send Message 연동(A), 검차 드로어 3상태 UX + Searchable Select, S3 presigned 외관 prefix.

### 신규 추가 (New Additions)
- Lightsail: 공인 도메인+HTTPS(certbot), Supervisor+`queue:work`, `processed_webhook_ids` 테이블, 자원 업그레이드(4→8GB 검토), 백업 cron에 연동 로그.
- respond.io: Custom Field `ssancar_contact_id`, Outbound Webhook(HMAC secret), Developer API 토큰(.env), 플랜 = Growth↑(Developer API)·Advanced↑(HTTP Request 모듈) 확인.
- 법무: respond.io DPA(§28 위탁·§36 파기).

### 모순·NO-GO 처리 로그
- 충돌1(타이밍): PO HOLD vs Eng 가능 → **단계적 GO**(C 우선·A 후행)로 수렴, 사외이사 양쪽 PO 지지.
- 충돌2(조인키): 단일VIN → 별도 링크테이블+contact_id(만장).
- 충돌3(PII): Security NO-GO급 → S3 presigned+서류차단+DPA로 조건 흡수(Codex/Gemini 더 강하게 지지).
- 충돌4(수정범위): Engineer 기술정당 + 거버넌스상 추가승인 필요(Codex/Gemini) → **대표 명시 승인 항목**으로 격상.
- 충돌5(인프라): HTTPS·queue worker HARD BLOCK → 필수 선행(만장).
- 충돌6(drift): 단방향+역방향 금지+환율0 가드(만장).
- Gemini "현재 NO-GO"는 (a)(b)(c) 충족 시 조건부 GO로 명시 전환 → 영구 NO-GO 아님.

---

## 🔗 참조
- 직전 회의: [2026-06-02-purchase-board-architecture.md](2026-06-02-purchase-board-architecture.md) (purchase-board ↔ car-erp 2자 경계 확정)
- 관련: 2026-05-12 RRN 암호화·문서권한 / 2026-05-26 IDOR·문서다운로드 정책 D(국외이전 시 §28 충돌 주의)
- 목업: `docs/purchase-board-mockup.html` (검차 드로어 L384~406 = 연동 A의 seam)
- CLAUDE.md: 권한·RRN·APP_KEY·S3·Lightsail / SKILLS §14(NICE fallback 패턴)·§10(디자인)
- respond.io: developers.respond.io / respond.io/help (Webhook 5초 200 OK·Advanced↑, Developer API Growth↑·10초 timeout)
