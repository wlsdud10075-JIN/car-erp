# 📅 회의록: 매입-검차-경매 업무보드(purchase-board) 설계 방향 확정
- 일시: 2026-06-02
- 강도: 풀회의 (/회의 명령어 호출)
- 안건 유형: 신규 시스템 아키텍처 + 권한/role + 신규 스키마 + car-erp 연동 경계
- 자동발동 여부: yes (/회의 슬래시)
- 발동 부서: PO + Engineer + QA + Security + Ops + Specialist[A.UX·E.승인권한·B.데이터무결성]
- 사외이사: Codex ✓ / Gemini ✓ (양쪽 응답 성공)

> ⚠️ 본 안건은 **car-erp 본체가 아닌 신규 별도 앱(purchase-board)** 설계 결정이다. car-erp 코드는 (push API 1개 예외 외) 무수정.

---

## 0. 안건 요약

매입 *확정 전* 워크플로우를 디지털화하는 신규 업무보드:
```
영업(개인) 당일 매입예정 리스트(차량+제시가) 작성 → 10:00 hard lock 마감
 → 검차팀 현장 검수 → 13:00 검차완료(합/불) → 합격차량만 경매
 → 경매팀 영업 제시가 그대로 집행(낙찰/유찰) → (2차) 낙찰차량만 car-erp 단방향 push = 영업 재고
```
가시성: 영업=본인만(SQL/서버레벨 격리) / 검차·경매=전체. RBAC 4역할(sales/inspection/auction/manager).
사용자 사전 확정 3건: ①별도 앱+영업사원 계정 공유 ②hard lock+관리자 override ③경매 제시가 그대로(낙찰/유찰).

---

## 💬 부서별 발언 (Sonnet 4.6)

### 📋 PO — 조건부 GO
- 푸는 문제: 매입 확정 전(제시가→검차→경매)이 카톡/종이로 돌아 낙찰 후 car-erp 재입력 이중수작업 발생. 이걸 흡수.
- **아키텍처: 사용자 1차결정(별도 앱) 유지.** Gemini 내부모듈안은 전제 붕괴 — 검차·경매팀은 car-erp 계정이 없고 role(영업/수출통관/재무/관리)에 끼우면 미들웨어 대규모 수정 필요. "car-erp 코드 무수정"을 지키는 유일한 길이 별도 앱. Codex안(같은 서버 별도 모듈)은 실질적으로 별도 앱과 동일.
- **선행 순서: 안정화 5건 + 도메인+HTTPS → purchase-board MVP.** HTTP 계정공유는 즉시 보안 리스크. 별건3(사이드바)은 병렬 가능.
- 업무 영향 role: 영업(본인 리스트) + 신규 검차/경매팀. 막힘 정도: 불편(카톡/종이→개선). 운영 전 필수: yes(도메인+HTTPS 선행).

### ⚙️ Engineer — 조건부 GO
- 계정 공유: 같은 car_erp DB의 users 테이블 직접 공유(추가 connection)가 최단. Passport/Sanctum SSO는 car-erp 무수정 위반, 별도 DB+동기화는 drift 위험. SESSION_DOMAIN·APP_KEY 동일 시 쿠키 공유로 SSO 가능.
- 낙찰→push: 공유 DB면 Eloquent 직접 insert 가능하나 `Vehicle::saved` 훅(progress_status_cache 등) 누락 위험 → **car-erp 내부 API 1개 추가가 현실적·불가피**(무수정 제약과 충돌, 이 1개만 예외 결정 필요).
- 시간게이트: `TimeGateMiddleware`(서버 미들웨어가 유일 신뢰 위치), `.env LOCK_TIME` 비교 403. override=`canApprove()` 재사용.
- S3: `heysellcar-erp-docs` 버킷 + prefix `purchase-board/inspections/` 재사용.
- **공수 19~27h**: 스캐폴딩+공유인증 2~3h / 데이터모델 3~4h / Volt 4컴포넌트 5~7h / 시간게이트 2~3h / push 4~5h / 권한검증 3~5h.
- 구현 전 결정요청: (a)push 방식 (b)SESSION_DOMAIN 전략 (c)APP_KEY 공유 여부.

### 🧪 QA & Domain Integrity — 조건부 GO
- car-erp 도메인 공식(VAT 9%·progress_status·미수율) 직접 영향 **없음**(별도 앱, 연동은 INSERT만).
- 조건부 GO 충족 3건:
  - **(a) VIN(차대번호) 1차 MVP 필수** — 안건에 "출품번호"만 있고 VIN 누락. car-erp b1dc0a2 VIN 우선매칭 정책상 VIN 없으면 2차 push 중복 INSERT 막을 수단 0.
  - **(b) TimeGate는 DB `lock_at`(datetime, Asia/Seoul) 서버측 단일 판정** — 클라 시각 신뢰 금지. 경계값(< vs <=), "12:59 진입→13:01 저장" 정책 명문화. 주말/공휴일은 `lock_at=NULL`.
  - **(c) 상태머신 전이는 모델 이벤트(saving) 차단** — 불합격→경매/마감전 진입/bulk·seed 우회 가드. override 플래그(`unpaid_export_overrides` 패턴)로만 우회.
- 회귀 25분(lock 경계·13:00 race·불합격 경매진입·IDOR·bulk 우회). Unit Test: TimeGate 경계, StateMachine 전이행렬, SalesmanScope, Idempotency.

### 🔒 Security & Compliance — 조건부 GO (공유 DB는 사실상 NO-GO급)
- **[1] DB 격리 — NO-GO급:** car_erp DB 직접 공유 시 검차·경매팀 계정 ORM이 `vehicles.nice_reg_owner_rrn`(APP_KEY 암호화 RRN)·`purchase_seller_account`(encrypted)·`nice_reg_owner_name/addr`(평문 개인정보)에 SQL 레벨 직접 접근. 미들웨어 가드 우회. 개인정보보호법 §29 위반. **별도 DB(purchase_board)+영업명단만 단방향 동기화가 최소권한 충족.** Gemini 내부모듈안도 같은 이유로 위험.
- **[2] 영업 본인격리(IDOR):** car-erp `vehicles/index` L368 `$restrictToOwnSalesman` + openEdit L953 `abort_unless` 2-레이어 패턴. 신규 앱은 **Global Scope(`SalesmanScope`)** 로 자동 강제(수동 when()보다 깜빡 누락 차단). 검차·경매는 scope 내 role 분기로 전체 노출.
- **[3] 돈 데이터 audit:** `offered_price`·`inspection_deduction`·`auction_price` 변경 → **별도 `purchase_board_audit_logs`**(car-erp audit_logs 폴리모픽 충돌 회피). Service 레이어 단일 경로 강제.
- **[4] 관리자 override 감사:** 마감 해제=`manager-admin`+미들웨어+audit(`action='deadline_override'`). SoD: 요청자=실행자 차단(`canConfirmFinanceTransfer` 패턴).
- **APP_KEY: 새 앱은 car-erp와 다른 키 필수**(동일 시 RRN 복호화 경로). NICE_PROVIDE_TOKEN 불필요.
- 운영 전 필수: yes(Global Scope·별도 DB·audit 누락 시 운영 금지).

### 🚀 Ops & Deploy — 조건부 GO (같은 Lightsail vhost)
- Lightsail(4GB/2vCPU/80GB)은 Laravel 2앱 충분. `/var/www/purchase-board` 별도 디렉터리+Nginx vhost. 단 **자동배포(deploy.yml)·GitHub Secrets 별도 구성 오버헤드**(car-erp deploy.yml은 경로 하드코딩 재활용 불가). 내부모듈이면 이 부담 0.
- 시간게이트: hard lock은 cron 불필요(서버 시각 비교). 자동 상태전환 필요 시 `* * * * * php artisan schedule:run` 1줄 추가(기존 03:00 백업 cron과 공존). **서버 UTC→KST 확인 필수**(APP_TIMEZONE=Asia/Seoul).
- S3: `heysellcar-erp-docs` prefix 재사용(IAM 키 추가 불필요).
- 별도 DB면 03:00 백업 cron에 `purchase_board` 추가.
- **순수 운영부담만 보면 내부모듈 유리. 별도앱 정당화는 "도메인 분리/팀 접근 제어"가 명확할 때.**

### 🔧 Specialist [A. UX 설계자] — 조건부 GO
- 검차 작업창은 **모바일 전용·입력 위주** → 페어렌더 그대로 못 씀. **단일 카드 + 하단 슬라이딩 드로어**(카드=요약, "검수 시작" 탭→드로어 풀스크린 체크리스트·사진·합/불 토글).
- 카메라 직접 촬영 `<input type=file accept=image/* capture=environment>`(후면 강제), Livewire `$upload`+S3.
- 보드 3종: 영업 대시보드(데스크탑, 읽기+제출1) / 검차 작업창(모바일 현장) / 경매 컨트롤창(데스크탑, 낙찰유찰).
- 상태 뱃지 §10 재사용: 작성중=blue/마감=gray/검차대기=blue/검차중=amber/검차완료=green/불가=red/경매대기=purple/경매중=amber/낙찰=green/유찰=gray/보류=red. 마감 카운트다운=red blink+5분내 urgent.
- 운영 전 필수: yes(보드 3종 상태 단일출처=state machine table 선확정).

### 🔧 Specialist [E. 승인·권한 정책] — 조건부 GO
- **Global Scope 모델레이어 격리 필수**(컴포넌트 추가마다 IDOR 생기는 것 구조적 차단).
- SoD 게이트 3종 시스템 강제: 10:00 마감(`submitted_at` 뮤텍스+트랜잭션), 검차완료 게이트(`status='inspection_passed'` CHECK), 13:00 마감. override=manager-admin+audit.
- 영업이 본인 검차/경매 결과 수정 차단(role enum 체크).
- **car-erp `User::ROLES`(영업/수출통관/재무/관리)에 검차/경매 추가는 미들웨어 전체 영향 → 즉시 확장 NO-GO. 별도 앱 자체 role 테이블 + car-erp User.id FK 참조만 권장.**
- 운영 전 필수: yes(Global Scope 없이 배포 시 URL 변조 IDOR).

### 🔧 Specialist [B. 데이터 무결성] — 조건부 GO
- **VIN(`vehicles.nice_reg_vin` 대응)+`(venue,lot)` 복합 유니크를 1차 MVP에 박아야** 2차 push 중복 INSERT 가드 작동. 나중 추가 시 기존 row VIN null로 검증 뚫림.
- `car_erp_vehicle_id` 역참조 컬럼 1차 포함. push: `status='won'` 가드 + VIN 중복조회 + DB 트랜잭션 + audit. `status!='won'` 누락 시 검차중·유찰 차량이 car-erp 재고로 등록되는 phantom(커밋 5ef0c85 패턴 위험).
- **Codex "ERP 강결합 위험" 타당** — 공유 DB면 실수 join으로 정산 공식 오염. 별도 테이블만 쓰고 `vehicles` 미접근 규약 코드리뷰 강제 시 공유도 수용가능하나 **별도 DB가 더 안전**.
- 운영 전 필수: yes.

---

## 🧩 중간 회의 결과 (Opus 1차 취합)

8개 발언 전부 **조건부 GO, NO-GO 0건**. 핵심 충돌 4건을 사외이사에 회부:
1. **공유 DB vs 별도 DB** — Engineer(공유=편의) vs Security(별도 필수, RRN 노출) vs B(별도 안전).
2. **계정공유 의미** — Engineer(APP_KEY 공유 SSO) vs Security(APP_KEY 분리, 별도 로그인).
3. **car-erp 무수정 범위** — PO(무수정) vs Engineer(push에 내부 API 1개 불가피).
4. **운영부담 vs 보안격리** — Ops(내부모듈 부담 적음) vs Security+E(별도앱 격리 우월).

---

## 🌐 사외이사 의견 (Codex / Gemini)

### [Codex] — 조건부 GO (공수 과소평가 지적)
- **놓친 리스크 3:** ①"제시가 그대로 집행"=오입력·담합·시세급변 대응 장치 부재 → 금액 정정 권한·사유·이중승인 필요 ②hard lock 실패 시 업무중단(서버시간·휴일·장애·override 남용 감사) ③VIN+출품번호만으로 추적 약함 → 원본 등록증/성능지 이미지 또는 외부조회 스냅샷 첨부.
- **충돌 판정:** 1)**별도 DB**(코드리뷰 규약은 통제수단이지 격리 아님) 2)**APP_KEY 분리·별도 로그인** 3)**초기 CSV/artisan bridge → 정식 운영 전 내부 API 1개 명시 승인(계약형 연동)** 4)**별도앱 자체 role 테이블**, 운영부담은 문서화·백업 스크립트로 완화.
- **시장 패턴:** ERP/경매/딜러 SaaS도 사전 워크플로우는 별도 DB, 확정 이벤트만 API로 ERP 적재. **ERP 원장 직접 공유 안 함.**
- **PO 선행 판단(안정화·HTTPS) 타당.**
- **NO-GO 사유=개인정보 DB 공유 + APP_KEY 공유.** 최소조건=별도 DB·키 분리·audit·장애 시 수기대체 절차. 대안=오프라인 파일 MVP→API 연동 단계 출시.

### [Gemini] — 조건부 GO (1차 내부모듈안에서 별도앱 지지로 선회)
- **놓친 리스크 3:** ①VIN 중복 검증 부재(보드 등록 차량이 이미 car-erp 재고인지 사전 체크 누락 → 중복 매집 회계 꼬임) ②동기화 지연(낙찰 push 시점에 영업이 car-erp 수동등록 시도 충돌) ③사용자 생명주기 파편화(퇴사자 양쪽 계정 정지 부하·보안홀).
- **충돌 판정:** 1)**공유 DB + `purchase_` 접두어 + DB User 권한 분리**(검차팀 DB유저가 ERP 테이블 물리 차단) — 1인 운영서 DB 2개는 백업/마이그 치명적 2)**별도 APP_KEY + 명단 해시 동기화** 3)**내부 API 1개 허용 + HMAC 서명**(`POST /api/v1/purchase-sync`) 4)**별도 앱 유지**(Role 오염 방지).
- **시장 패턴:** 대형 경매장=인프라 분리 API 연동 표준, 중소형 딜러/수출입=단일 DB 논리격리(Tenant ID). SSANCAR 규모엔 '공유 DB+논리격리'가 가성비 최고.
- **PO 인프라 선행 판단 "절대적 타당"**(보안격리 외치며 기본 인프라 보안 미비는 어불성설).
- **NO-GO 사유=RRN 암호화 키가 보드 앱 메모리에 노출되는 구조면 차단.** 최소조건=RRN 로직은 car-erp 내부에서만, 보드는 비식별 식별자만.

---

## 🚨 NO-GO 상세 (사외이사 2건 — 모두 (a)(b)(c) 충족 → 조건으로 수용)
- **Codex NO-GO:** (a)개인정보 car_erp DB 공유 + APP_KEY 공유 (b)별도 DB·키 분리·audit·장애 수기대체 (c)오프라인 파일 MVP→API 단계 출시.
- **Gemini NO-GO:** (a)RRN 키가 보드 앱 메모리 노출 구조 (b)RRN은 car-erp 내부 전용·보드는 비식별 식별자만 (c)공유 DB+DB User 권한분리로 ERP 테이블 물리 차단.
- → 두 NO-GO 모두 "**보드가 RRN/개인정보에 닿지 않게 격리**"하면 해소되는 조건부. 아래 최종 권고가 충족.

---

## 🏁 최종 권고 (Opus 최종 취합)

**판정: 조건부 GO**

**근거(1줄):** 8부서 전부 + 사외이사 2인 별도 앱 방향 합의. 유일 쟁점인 DB 격리는 "별도 DB(같은 인스턴스) + 전용 MySQL user"로 Security·Codex·Gemini를 동시 수렴. 단 car-erp 인프라(도메인+HTTPS)·안정화 선행이 만장 조건.

### 확정 설계 (수렴안)
1. **별도 앱 유지** (만장일치). `C:/xampp/htdocs/purchase-board`, 포트 8002. car-erp `User::ROLES` 미오염 — **purchase-board 자체 role 테이블**(sales/inspection/auction/manager).
2. **DB = 별도 DB(`purchase_board`) + 전용 MySQL user** ← 핵심 수렴.
   - 같은 Lightsail MySQL 인스턴스에 별도 스키마. purchase-board 전용 user는 `purchase_board` DB에만 GRANT, **`car_erp` DB 접근권한 0** → RRN/개인정보 ORM 노출 물리 차단(Security·Codex 만족).
   - 같은 인스턴스라 백업 cron 1줄 추가로 1인 운영부담 최소(Gemini·Ops 우려 흡수). → 별도 DB(Codex) + 권한분리(Gemini)의 교집합.
3. **APP_KEY 분리**(만장). 계정은 SSO 아님 — car_erp `salesmen` 명단을 단방향 동기화(artisan)해 purchase-board 계정 생성. 비번은 보드 자체 발급(해시 동기화는 2차 검토). **보드는 RRN/개인정보 절대 미보유**(Gemini 최소조건).
4. **push = car-erp 내부 API 1개**(`POST /api/internal/purchase-sync`, Sanctum/HMAC 서명) — 충돌3 해소. **이 1개만 car-erp 수정 예외 승인.** 1차 MVP는 push 없이(또는 CSV/수동) 출시 → 정식 연동 시 API 추가(Codex 단계론).
5. **인프라 선행:** car-erp 도메인+HTTPS+안정화 5건 완료 → purchase-board MVP 착수(PO+Codex+Gemini 만장).

### 필수 선행 작업 (운영 전)
- car-erp 도메인+HTTPS 전환 (HTTP 계정공유 차단).
- purchase-board: 별도 DB + 전용 MySQL user GRANT(car_erp 접근 0) 구성.
- 영업 본인격리 = Global Scope 모델레이어 강제 + Unit Test.
- VIN(차대번호) + `(venue,lot)` 복합 유니크 1차 데이터모델 포함.
- TimeGate = DB `lock_at` 서버측 판정 + 경계/주말 정책 명문화 + 장애 시 수기대체 절차(Codex).
- 상태머신 모델 이벤트 가드(불합격→경매/bulk 우회 차단).
- 돈 데이터 audit(`purchase_board_audit_logs`) + override 감사.
- APP_KEY car-erp와 분리. 보드 RRN/개인정보 미보유.

### 조건 (조건부 GO)
- 위 필수 선행 미충족 시 운영 투입 금지(Security 양보 불가 라인).
- push API 1개 추가는 사용자 명시 승인 필요(무수정 제약 예외).

---

## 🛠 car-erp 영향 분석

### 취약점 (Vulnerabilities)
- car_erp DB 직접 공유 시 검차·경매팀 ORM이 RRN·개인정보 무가드 접근(개인정보보호법 §29) → **별도 DB+전용 user로 차단**.
- APP_KEY 공유 시 RRN 복호화 경로 개방 → **분리로 차단**.
- 영업 본인격리 수동 when() 누락형 IDOR(car-erp 2026-05-19 scopeAction confirmed_at 누락 전례) → **Global Scope로 구조적 차단**.

### 보완사항 (Improvements)
- 제시가 정정 권한·사유·audit(Codex 담합/오입력 대응).
- hard lock 장애 시 수기대체 절차 문서화(Codex).
- VIN 중복 = 이미 car-erp 재고 존재 여부 사전 체크(Gemini, 2차 push 시).
- 퇴사자 계정 생명주기 — 양쪽 정지 절차(Gemini).
- 검차 원본 등록증/성능지 이미지 첨부(Codex 추적성).

### 코드 수정 (Code Changes)
- **car-erp**: `routes/api.php` + `app/Http/Controllers/Api/PurchaseSyncController.php` — 내부 push API 1개 (2차, 명시 승인 후). **그 외 car-erp 무수정.**
- **purchase-board(신규 앱)**: PurchaseListing/PbInspection/AuctionResult 모델, SalesmanScope Global Scope, TimeGateMiddleware, purchase_board_audit_logs, Volt 4컴포넌트, 자체 roles 테이블.

### 신규 추가 (New Additions)
- Lightsail: `purchase_board` DB + 전용 MySQL user(GRANT 제한) + Nginx vhost + deploy.yml + schedule cron 1줄 + 백업 cron 항목.
- S3: `purchase-board/inspections/` prefix(기존 버킷·IAM 재사용).
- 데이터모델 1차 필수: `vin`, `auction_venue`, `lot_number`, `car_erp_vehicle_id`, `created_by_user_id`, `status`, `lock_at`.

### 모순·NO-GO 처리 로그
- 충돌1(공유 vs 별도 DB): Security NO-GO급 + Codex 별도DB vs Gemini 공유+권한분리 → **"별도 DB + 같은 인스턴스 + 전용 user"** 로 양측 수렴(격리는 Codex/Security, 운영부담 최소는 Gemini/Ops).
- 사외이사 NO-GO 2건 모두 (a)(b)(c) 충족 → 무효화 아님, 최종 권고 조건으로 흡수.
- Engineer의 "공유 DB 권장"은 보안 NO-GO급 무게에 밀려 별도 DB로 정정(코드 우선 원칙 — 단 여기선 보안 정책 우선).

---

## 🔗 참조
- 관련 과거 회의록: 2026-05-12 RRN 암호화·문서권한 / 2026-05-26 IDOR 스코핑·문서 다운로드 정책 D
- CLAUDE.md: 권한 3단계·role 4종·미들웨어 6종·APP_KEY RRN 경고·S3·AWS Lightsail
- SKILLS.md §7(사이드바)·§10(디자인시스템)·§11(모바일 반응형)·§13(식별키)
- 커밋 b1dc0a2(VIN 우선매칭)·5ef0c85(phantom PBP 제거)
