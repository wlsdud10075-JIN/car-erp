# 📅 회의록: board 영업 포털 ↔ car-erp 연동 (읽기 API + 선적요청 + 서류 다운로드)
- 일시: 2026-06-18
- 강도: 풀회의 (/회의)
- 안건 유형: 외부 API + 개인정보 + 권한(IDOR) + 문서 다운로드
- 발동 부서: PO + Engineer + QA + Security + Ops + Specialist [C.외부의존성 + E.승인·권한 + F.회계감사] + 사외이사 Codex/Gemini

## 배경
영업은 board만 씀(car-erp 계정 없음). 낙찰 후 car-erp로 넘어간 차의 선적·재무·서류를 영업이 board 한 곳에서 보고 선적 요청. car-erp 권위·계산, board 표시만. 인계문서 = `C:/xampp/htdocs/board/meetings/handoff-car-erp-board-portal.md`(board 세션 작성). 크로스레포 규칙대로 **car-erp가 자기 권위 스펙(`docs/integration/board-portal-api.md`) 만들고 구현**. 빌드순서 ④재무읽기 → ③선적요청 → ①②서류. 배송금액 매핑 확정(앞선 논의): 판매배송(바이어向)=`transport_fee`(미수율) / 매입배송(지급게이트웨이)=`cost_towing`(정산마진) 분리.

---

## 💬 부서별 발언 요약 (Sonnet 4.6 — 실측 근거)

### 📋 PO — 조건부 GO
영업 식별 인프라 이미 존재(board `users.car_erp_salesman_email`, 연동B `SyncWonListingToCarErp:63` 매핑 실작동). ④재무읽기 = 기존 `/erp/salesmen/{id}/cashflow`·`/receivables`·`/settlements`(routes/web.php:49,59,76) + `sale_unpaid_amount_krw_cache`·`receivable_risk` 캐시 그대로 → HMAC GET 래퍼만, 낮은 리스크. board 포털 = 통합 로드맵 별도 트랙(purchase-board MVP 이후 병렬). 흡수금지=board가 vehicles 직접쓰기·정산 재현·회계필드 POST.

### ⚙️ Engineer — 조건부 GO (9~12h)
**`VerifyPurchaseSyncHmac`(L37)는 `$request->getContent()` raw body 서명 → GET 빈바디면 무력** → `VerifyBoardReadHmac` 신규(서명=`METHOD\nPATH?SORTED_QUERY\nTIMESTAMP`). 시크릿 공유 가능하나 **별도 권장**. Volt 컴포넌트 재사용 불가 → `SalesmanResolver`(PurchaseSyncController:182 추출)+`InternalPortalController`(finance/receivables/settlements). **선적요청=`shipping_requests` 신규 테이블**(vehicle_id/buyer/consignee/method/email/status, 멱등 unique). 알람=신규 type `shipping_requested`(target_role=관리) **즉시발동**(scan 불필요). 서류=`InternalDocumentController` 프록시 스트림(DocumentFiller 재사용). 읽기라 캐시 rebuild 무관.

### 🧪 QA — 조건부 GO
accessor/cache 그대로 반환 강제(raw SQL 재계산=drift). **환율0 외화 `sale_unpaid_amount_krw_cache=NULL`**(Vehicle:1231,490) → board가 완납 오판 금지(currency+rate 동봉, NULL="환율 미입력"). Settlement 확정액 = `$settlement->settlement_amount` accessor 경유(환차·이월 runtime 분기). **선적요청을 `export_buyer_id`에 넣으면 C4/C5 게이트(guardStageOrderForExport)·ManagementWorkflowChecklistTest:375 회귀** → 별도 테이블 필수. 신규 테스트 4종(scope·NULL krw·멱등·already-active).

### 🔒 Security — NO-GO (5건, 전부 (a)(b)(c) 충족 → 선행조건화)
- **A. 말소서류 RRN 국외유출**: `DeregistrationMapping` D7=`nice_reg_owner_rrn`(복호화 평문)·D6=성명·D8=주소. board 다운로드 시 §29 위반. → **서류 type 화이트리스트=선적4종만**(말소·위임장·인보이스·통관SET 차단). 선적4종(roro/container invoice·contract)은 RRN 없음(VIN·sale_price·weight만) 실측.
- **B. replay 미방지**: GET 빈바디 서명 재사용 → timestamp(±300초)+서명 포함.
- **C. salesman_email 서명 미포함 IDOR**: 시크릿1개 탈취→쿼리 email 바꿔 전 영업 열람. → email을 서명대상에 포함 + salesman_id WHERE 강제.
- **D. board 유저 인증경로 미구현**: `canScopeVehicle`은 `auth()->user()` 기반→board 세션 없음. `DocumentAccessLog.user_id` null.
- **E. internal GET API 미존재**(routes/api.php POST 1개뿐) → **스펙 문서 먼저** 작성·커밋 후 구현.

### 🚀 Ops — 조건부 GO (다운타임 1~2분)
HMAC 인프라 존재(연동B, `CAR_ERP_HMAC_SECRET` 운영 .env 세팅 §20). car-erp HTTPS 라이브, 서버間 호출 CORS 무관. **별도 `CAR_ERP_READ_HMAC_SECRET` 권장**(쓰기/읽기 분리). **throttle by(IP)가 board 단일IP로 묶임→전 영업 30/분 공유** → by(salesman_email) or 300/1. 서류 프록시는 PhpSpreadsheet CPU/메모리 → throttle 필수. shipping_requests 신규테이블 mysql-check 통과 필수. queue worker 미가동이나 읽기·선적요청 **동기로 우회 가능**.

### 🔧 Specialist C.외부의존성 — 조건부 GO
GET 미들웨어 별도+replay(timestamp+nonce)+별도 시크릿. car-erp도 시크릿 미설정 시 401(silent no-op 금지). TaskAlarm 실재(`2026_06_18_000001`), 선적요청→알람 생성 경로 신규. board degrade(no-op)는 board 결정.

### 🔧 Specialist E.승인·권한 — HOLD
board 유저 auth 없어 `canScopeVehicle` 직접 불가 → **`InternalSalesmanScope`(email→salesman_id WHERE) 단일출처** 필수. board 임의 email 주입 차단은 car-erp 책임. **`DocumentAccessLog` user_id=null** → `source`/`actor_email` 컬럼 추가(§29 감사). 선적요청 SoD=영업 요청/관리 실행 자연분리.

### 🔧 Specialist F.회계감사 — 조건부 GO
읽기라 retroactive 무관(Settlement SoftDeletes+deleting 가드 존재). **마진 raw(sales/vat/total_margin) 영업 노출 = jin 정책 결정**(회사 마진 역산 가능). 환율 NULL 오해 방지. RRN 서류 차단(=Security A). transport_fee는 선적요청 payload에 없어 공식 정합 무영향.

---

## 🧩 중간 회의 결과 (Opus 4.7 1차)
조건부GO4 / Security NO-GO(5) / SpecE HOLD — 전부 "구현 전 보안 해소" 선행조건(도입 반대 아님). 7대 GO조건 수렴(HMAC GET 신규·IDOR 서명+scope·서류 선적4종·별도테이블·단일출처·감사·스펙먼저). 충돌4: 서류 전달방식·마진노출·replay방식·우선순위.

## 🌐 사외이사 의견 (Codex / Gemini — 2인 응답)

### [Codex]
놓친 리스크: ①**영업 퇴사·권한변경 동기화 지연**(email 서명만으로 계속 조회) → car-erp active/territory/status 재검증 ②금융정보 캐시·로그·엑셀에 PII 2차 유출 ③API 장애 시 board "0/완납/없음" 실패모드. 충돌판정: ①서류 **프록시스트림 v1**(감사·마스킹 우선) ②**마진 raw 노출 NO**(정산가능액/상태만) ③**timestamp+nonce**(Sanctum 과함) ④board포털 축소 v1만, queue/HTTPS/worker 없으면 POST 보류.

### [Gemini]
놓친 리스크: ①**email 가변 식별자 무결성**(이메일 변경 동기화 실패→권한 오남용. 불변 PK/UUID 연동 필수) ②rate limit 부족(스크래핑) ③**에러 메시지 정보유출**(DB구조·스택 노출→ERP 공격경로). 충돌판정: ①**프록시**(presigned는 만료·권위 분산) ②**마진 마스킹**(상태 위주, jin 정책 부합) ③**replay+nonce**(단기토큰은 1인개발 복잡도만) ④**GET 읽기 무결성 최우선**. v1 우선순위: ①InternalSalesmanScope(IDOR) ②GET API ③Audit Log. NO-GO: secret 관리·데이터 정합성·부하 분리(메인 DB lock 간섭).

---

## 🚨 NO-GO 상세 (Security 5 + SpecE HOLD — 전부 선행조건화)
- **차단 사유**: RRN 서류 국외유출 / GET HMAC replay·email 서명 미포함 IDOR / board 인증경로·감사 공백 / API·스펙 미존재.
- **최소 조건**: 아래 "필수 선행 작업" 전부 = 각 NO-GO의 (b).
- **대안**: 없음(보안은 양보불가, 조건 충족이 유일 경로).

---

## 🏁 최종 권고 (Opus 4.7 최종 취합)
**판정: 조건부 GO** — 도입 GO, 단 **보안 선행조건 충족 + 스펙 문서 선작성** 전제. 개념엔 6부서+사외이사 이견 없음(영업 생산성·bus factor 가치). 무게는 전부 "어떻게 안전하게"에 있고 (b) 최소조건이 전부 명확.

### 충돌 4건 최종 판정 (사외이사 2인 독립 수렴)
1. **서류 전달 = 프록시 스트림** (확정). presigned 부적합 — 서류는 DocumentFiller **동적 생성 xlsx라 S3에 미존재** + 감사/권위 분산. car-erp가 생성→스트림→`DocumentAccessLog` 기록. 대역폭은 throttle로.
2. **마진 raw 노출 = 기본 금지** (사외이사 2인 일치). 영업에겐 **미수금·정산 상태·정산가능액(actual_payout)**만, `sales_margin`·`vat_margin`·`total_margin` raw 제외. *jin이 명시적으로 원하면 override 가능하나 default=마스킹.*
3. **replay 방지 = timestamp(±300초) + nonce** (확정). Sanctum/단기토큰은 서버間 BFF에 과투자.
4. **우선순위 = ④재무읽기 GET 먼저**(읽기 무결성). 선적요청 POST는 동기 구현이면 즉시 가능(queue 불요), 단 알림 확장 시 Supervisor 선행. board 포털 = 통합 로드맵 별도 트랙, **축소 v1**.

### 신규 채택 조건 (사외이사)
- **Salesman active 재검증**: email→Salesman 매칭 시 **재직/active 상태 확인**(퇴사자 차단). 불변 식별자(UUID) 연동은 v2 검토.
- **장애 실패모드 명시**: API 장애 시 board는 "조회 불가" 표시(절대 "0원/완납" coerce 금지).
- **에러 응답 최소화**: 표준 에러 JSON(스택·DB구조·라라벨 내부 노출 금지).
- **부하 분리**: 읽기 API는 트랜잭션 lock 간섭 없는 단순 SELECT만.

### 필수 선행 작업 (배포 전 — 전원 충족)
1. **스펙 문서 먼저**: `docs/integration/board-portal-api.md`(엔드포인트·서명명세·응답 화이트리스트·서류 type 허용목록) 작성·커밋
2. **HMAC GET 미들웨어** `VerifyBoardReadHmac`(서명=method+path+sorted query+timestamp+salesman_email, replay ±300초+nonce, 별도 `CAR_ERP_READ_HMAC_SECRET`)
3. **IDOR**: `InternalSalesmanScope`(email→Salesman active 검증→salesman_id WHERE 강제 단일출처). email은 서명대상 포함
4. **서류 type 화이트리스트 = 선적 4종만**. 말소·위임장·인보이스·통관SET board 차단(RRN/PII)
5. **선적요청 = `shipping_requests` 신규 테이블**(vehicles 컬럼 금지). 멱등. 알람 신규 type `shipping_requested`(관리) 즉시발동
6. **단일출처**: accessor/cache 반환. 환율0 외화 krw NULL + currency·rate 동봉
7. **감사**: `DocumentAccessLog`에 `source='board_api'`+`actor_email`(마이그). throttle by(email)/상향
8. **마진 마스킹**(default) / 장애 실패모드 / 에러 최소화

---

## 🛠 car-erp 영향 분석 (Opus 4.7 산출)

### 취약점 (Vulnerabilities)
- 말소서류 RRN·성명·주소 board 국외유출(§29) — 헤드라인
- GET HMAC replay·email 서명 미포함 → 시크릿1개 탈취로 전 영업 IDOR
- 퇴사 영업 email로 계속 조회(active 재검증 부재)
- 선적요청 vehicles 컬럼 적재 시 C4/C5 게이트·테스트 회귀
- 환율0 외화 krw NULL → board 완납 오판 / API 장애 → "0/완납" 오표시
- DocumentAccessLog user_id null → 감사 공백 / 에러 응답 정보유출

### 보완사항 (Improvements)
- 별도 read 시크릿 / per-salesman throttle / accessor 단일출처 / 프록시 감사유지
- 스펙 문서 선행(board client가 맞춰 구현)

### 코드 수정 (Code Changes)
- 신규: `app/Http/Middleware/VerifyBoardReadHmac.php`, `app/Services/SalesmanResolver.php` + `InternalSalesmanScope`
- 신규: `app/Http/Controllers/Api/Internal/{InternalPortalController, ShippingRequestController, InternalDocumentController}.php`
- 신규: `database/migrations/..._create_shipping_requests_table.php`, `..._add_source_actor_to_document_access_logs.php`
- 신규: `app/Models/ShippingRequest.php`, `TaskAlarm` type `shipping_requested` 추가
- 수정: `routes/api.php`(internal GET 그룹), `PurchaseSyncController`(SalesmanResolver 사용), `VehicleDocumentController`(BOARD_ALLOWED_TYPES 상수)
- 신규: `docs/integration/board-portal-api.md`(권위 스펙)

### 신규 추가 (New Additions)
- 테스트: InternalBoardApiScope(IDOR·active)·NullKrw·ShippingRequestIdempotency·AlreadyActive·서류 type 화이트리스트·HMAC GET 서명/replay

### 모순·NO-GO 처리 로그
- Security NO-GO 5 + SpecE HOLD → 전부 (a)(b)(c) 충족(유효). "도입 반대"가 아니라 선행조건 → 충족 시 조건부 GO 수렴(무효화 아님).
- 충돌2(마진): SpecF가 "jin 정책"이라 했으나 사외이사 2인 모두 "기본 금지" → default 마스킹으로 확정, jin override 여지 명시.

## 🔗 참조
- 인계문서: board/meetings/handoff-car-erp-board-portal.md / 연동B 권위: docs/integration/purchase-sync-receiver.md
- 과거: 2026-06-04 PII 국외이전 NO-GO·queue worker / 2026-06-05 통합 로드맵 / 2026-06-18 notification 서브시스템·엑셀 export-import
- SKILLS §8 #26(IDOR)·§13 / CLAUDE.md 권한·개인정보 / 메모리 project_integration_roadmap·project_notification_alarm
