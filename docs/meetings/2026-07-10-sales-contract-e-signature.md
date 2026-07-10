# 📅 회의록: 판매계약서 자체 전자서명 board 연동 (증거력 보강 + 서명본 별도보관)
- 일시: 2026-07-10
- 강도: 풀회의 (/회의 명령어 호출)
- 안건 유형: 개인정보 컬럼/문서 + 마이그레이션 + board 연동 (복합)
- 자동발동 여부: yes (/회의 슬래시)
- 발동 부서: PO + Engineer + QA + Security + Ops + Specialist[E.승인·권한 + B.데이터 무결성]

## 배경
- 대표(jin) 요구: ERP 판매계약서(`sales_contract`) 원본 → board 창구로 바이어에게 서명 링크 → 바이어 웹 전자서명 → 서명본 ERP 별도 보관.
- **확정 방향**: 외부 전자계약 API(도누 donue.co.kr 등) 전면 배제, 100% 자체구현. 서명본은 원본과 별도 파일·별도 보관.
- **무역보험공사 오주현 전문위원 자문(2026-07-10)**: 자체 웹서명은 "위조 아닌 진위만 확인되면" 법적 가능. 단 분쟁 시 진위 증명이 약함 → 보강책 = 서명 후 서명본을 바이어 이메일로 자동 재발송(이메일 송·수신 기록이 증거) + "전자서명 확인" 문구. 2단계(웹서명 → 이메일 회신).

## 💬 부서별 발언 요약 (Sonnet 4.6)

### 📋 PO — 조건부 GO
차단 아닌 리스크 제거형. `sales_contract`·자체 메일발송은 이미 master 배포·작동. 카라바 커스터마이징(다음세션 착수 예정)·7월정산검증(8/10 데드라인)과 큐 경쟁 → jin이 순서 지정 필요. 이번 세션은 "설계 확정"까지. board 분담분은 board repo에 별도 커밋해야 전파(크로스레포 규칙).

### ⚙️ Engineer — 조건부 GO (공수 10~12h, ERP측만)
수신부는 `PurchaseSyncController::syncAttachments`(md5 dedup·서버사이드 복사) 패턴 재사용. 3개 공백: (a) **원본 스냅샷** — `DocumentFiller`는 GET 시점 라이브 렌더 후 폐기라 "원본"이 DB에 없음 (b) **회신 멱등키** 미정 (c) `BOARD_ALLOWED_TYPES`에 `sales_contract`(passport_id 포함) 추가는 §29 PII 정책 충돌 → 보안 승인 필요. 서명 상태를 `progress_status`에 끼우지 말 것(캐시 rebuild no).

### 🧪 QA — 조건부 GO (운영 전 필수)
**서명 상태를 `progress_status`(computed v4 cascade, `Vehicle.php:1094`)에 절대 끼우지 말 것.** board 왕복은 비동기·지연 가능해 단조 순서 깨지고 `progress_status_cache`·대시보드 groupBy 카운트·C5(50%)/G1(100%) 게이트 단일출처 오염. 완전 분리 테이블 필수. 잘못 얽으면 `DashboardActionCountsTest`·`AdminDashboardTest`·`PipelineStripTest`·`WorkflowGapTest` 회귀. **부분서명(다중차량 계약서 일부만 서명) 정책 코드 전 확정 필요.**

### 🔒 Security — 조건부 GO (운영 전 필수)
`buyers.passport_id`(여권번호=고유식별정보)가 **평문 저장**(`Buyer.php:17` 캐스트 없음, RRN `nice_reg_owner_rrn`은 `Crypt::encryptString`). `SalesContractMapping.php:47-53`에 여권ID·주소·연락처 인쇄. 노출 경로 3개 신설(board프록시·공개링크·바이어이메일). **서명 페이지는 ERP가 직접 호스팅 + `BuyerDocumentController`의 Laravel `signed` URL 선례(만료·vehicle_id 고정·경로조작 불가) 재사용 강력 권고.** 기존 `VerifyBoardReadHmac`(salesman 쿼리서명)는 바이어 익명 세션에 구조 부적합.

### 🚀 Ops — 조건부 GO (운영 전 필수)
`signed_contracts` 테이블·S3 prefix는 안전한 additive. 단 **queue worker가 운영(heyman 52.79.200.151·karaba)엔 실제 미가동**(supervisor는 ssancar runbook에만 문서화, 배포기록엔 cron=db:backup만). 기존 `VehicleDocumentMail`도 `CompanyMailConfig::send()`로 **동기 발송**. 서명본 재발송을 `ShouldQueue`로 만들면 heyman·karaba에서 무음 실패 → **동기 발송 권장**. 서명본은 법적 증거물이라 S3 버킷 버저닝 활성화 권장. 마이그 MySQL8 실측 검증(3중 tier 불일치).

### 🔧 Specialist[승인·권한] — 조건부 GO
안건이 가정한 `InternalSalesmanScope` 클래스는 **phantom(코드에 없음)** — 실제는 `InternalDocumentController:41-47` 인라인 `salesman_id` 체크(그나마 **buyer_id 스코프는 없음** → 같은 담당자면 타 바이어 계약서도 통과). HMAC 미들웨어는 **서버-서버용이라 바이어 브라우저 공개 URL엔 재사용 불가**(시크릿 유출). `ApprovalRequest`는 requester/approver가 User FK 고정이라 바이어 서명에 부적합. 서명 완료는 승인이 아니라 `signed_contracts.status`로 추적.

### 🔧 Specialist[데이터 무결성] — 조건부 GO
전용 `signed_contracts` 테이블 GO(vehicle_photos 재활용 반대: `2026_05_24_180848` 스키마가 `vehicle_id/path/sort_order`뿐, 서명자·해시·동의문구 자리 없음). **가장 중요**: sales_contract는 매 GET마다 즉석 생성(영속 row 없음) → 발송 시점 **스냅샷 해시 캡처 필수**(안 그러면 서명 후 sale_price/바이어 변경 시 "무엇에 서명했나" 재현 불가). 서명 완료는 `progress_status`/정산/캐시에 절대 손대면 안 됨. **하드삭제 가드**(§27 논리, 법적 증거물이라 confirmed Settlement보다 엄격).

## 🧩 중간 회의 결과 (Opus 1차 취합)

**합의된 GO 조건(전 부서 수렴)**:
1. 서명 상태 = 완전 분리 전용 테이블 `signed_contracts`. `vehicles`·`progress_status`·`vehicle_photos` 오염 금지.
2. 발송 시점 문서 스냅샷/해시 고정(증거력 핵심).
3. 회신/발급 = HMAC + 멱등키.
4. `signed_contracts` 하드삭제 가드.
5. 감사로그 3전이(발송/열람/서명완료).

**핵심 충돌(사외이사 판정 요청)**: 서명 페이지 호스팅 주체 — Security=ERP 직접 호스팅 vs Specialist[E]=board 호스팅.

## 🌐 사외이사 의견

### [Codex] (성공)
**판정: ERP 직접호스팅이 맞다.** 서명은 "판매계약 원본을 가진 법적 원장 시스템"에서 통제해야 증거 체인이 짧다. board호스팅은 노출면은 줄여도 DB/앱 간 원본·상태·파일 동기화가 늘어 분쟁 시 설명 체인이 길어진다. board는 링크 전달·알림 창구만.

**놓친 리스크**: ①여권번호 평문은 MVP 전 차단/마스킹/암호화 없이 GO 불가 ②**이메일 회신은 증거 보강일 뿐 본인확인 수단이 아님** ③공개링크 만료·1회성·재발급·철회 정책 부재.

**더 나은 대안**: "스냅샷 파일 보관"보다 **서명 패키지를 원자화**하라. 발송 시 원본 XLSX/PDF 해시·생성시각·계약ID·buyer_id·수신 이메일·IP/UA·열람·서명 이벤트를 감사 JSON으로 만들고, **서명 완료 PDF 마지막 페이지/메타데이터에 원본해시+타임스탬프+서명메타+감사로그 해시를 임베드**한다. = DocuSign류 **Certificate of Completion 축소판**.

**MVP**: ERP signed URL + 동기 이메일 + signed_contracts + 파일해시 + 3전이 로그 + 하드삭제 금지 + 여권번호 노출 제거까지만. board호스팅·복잡 캐스케이드는 2차.

### [Gemini] (성공 — CLI는 단종이나 REST API `gemini-flash-latest`로 호출)
**Codex 권고(직접호스팅+감사증명서)에 적극 동의.**

**1. 호스팅 판정: ERP 직접 호스팅** — board 분리 시 분산 트랜잭션·데이터 동기화 지연·API 보안 관리에 자원 낭비. 서명본 생성·HMAC 검증·감사로그 적재는 원본 DB 있는 ERP에서 직접 처리, board는 단순 알림 전달자로 한정해야 1인 개발·관리 가능.

**2. 놓친 리스크**: ①`passport_id` 평문 = 즉각적 개인정보보호법 위반 ②동기 발송 병목 — SMTP 지연 시 웹서버 타임아웃 + 바이어 중복 클릭으로 멱등성 깨짐 ③**부인방지(Non-repudiation) 취약** — 단순 IP/UA 기록만으로는 서명 부인 완벽 방어 어려움.

**3. 더 나은 대안**: ①Laravel `encrypted` 캐스팅으로 여권번호 즉시 암호화 ②**경량 큐** — Redis 없이 database 드라이버 + 단순 Cron(`schedule:run`)으로 이메일 비동기 안전 발송(Ops 동기발송 딜레마 해소) ③**서명 링크 진입 시 이메일 OTP 발송·검증** 추가로 법적 증거력(본인확인) 확보.

**4. MVP**: `passport_id` 암호화 + 서명 링크 HMAC 1회성 / Canvas 서명패드 + 이미지 저장 / PDF 끝페이지에 [감사증명서(계약서해시·서명시점·IP/UA·OTP기록)] 강제 임베드해 단일 PDF 보관 / 메일 큐(미적용 시 중복클릭 방지 UI + 동기).

### 사외이사 수렴
**Codex·Gemini 독립 수렴**: ①서명 페이지 = **ERP 직접 호스팅**(board는 알림 전달자만) ②서명본 = **Certificate of Completion**(원본해시·타임스탬프·IP/UA·감사해시 임베드, 단일 PDF 봉인). Specialist[E] board 호스팅안은 사외이사 2인이 독립적으로 반대 → 격하 확정.
**Gemini 신규 기여**: ①**OTP 본인확인**(Codex "이메일 회신은 본인확인 아님" 리스크의 해법) ②**database queue + Cron `schedule:run` 경량 큐**(Ops 동기발송 딜레마 해소 — supervisor 없이 비동기).

## 🏁 최종 권고 (Opus 최종 취합)

**판정: 조건부 GO — 아키텍처 재정의 (Codex "더 나은 대안" 채택)**

**근거**: Security + Codex가 "ERP 직접 호스팅"으로 독립 수렴 → 충돌 해소, Specialist[E] board 호스팅안은 소수 의견으로 격하. ERP 직접 호스팅 채택 시 크로스레포 복잡성(board 서명페이지·PDF합성·회신 HMAC·화이트리스트 확장·queue)이 **대부분 소멸** = 더 단순하고 견고.

### 확정 아키텍처 (재정의)
```
[ERP] 판매계약서 원본 생성(기존) + 발송 시점 스냅샷(해시·데이터 동결) → signed_contracts row(status=pending)
  ↓ ERP가 Laravel signed URL 발급 (만료·1회성·철회)
[board] 그 URL을 바이어에게 전달만 (카톡/SNS/이메일 창구) — board는 서명 페이지 호스팅 안 함
[바이어] ERP가 호스팅하는 서명 페이지(heysellcar.com/sign/{signed})에서 서명 + 이메일 입력
  ↓ ERP가 직접 처리 (board 회신 엔드포인트 불필요)
[ERP] 서명본 생성 = 원본 + 서명이미지 + Certificate of Completion(원본해시·타임스탬프·IP/UA·이벤트·감사해시 임베드)
  → signed_contracts(status=signed) 별도 디스크/S3 prefix 보관, 하드삭제 가드
  → 서명본을 바이어 이메일로 동기 자동 재발송 + "전자서명 확인" 문구 (무보 자문 증거체인)
  → document_access_logs + audit_logs 3전이 기록
```
**핵심**: 서명·보관·증거가 전부 ERP 내부에서 완결. board는 링크 전달 창구만 → 서명본 회신 HMAC·board 프록시 PII 노출·queue worker 문제 전부 회피.

### 필수 선행 작업 (운영 전, 전 부서 수렴)
1. **`signed_contracts` 전용 테이블** — `progress_status`/`vehicles`/`vehicle_photos` 완전 분리. status(pending/viewed/signed) 자체 추적. 하드삭제 가드(`deleting` 훅, status=signed 차단).
2. **발송 시점 스냅샷** — 발급 시 계약서 파일 바이트 해시 + 서명 대상 차량 데이터 동결(스냅샷 JSON 또는 파일 영속). 서명 후 차량 변경돼도 "무엇에 서명했나" 재현 가능.
3. **Certificate of Completion** — 서명 완료본에 원본해시·계약ID·buyer_id·수신이메일·IP/UA·열람·서명 타임스탬프·감사로그 해시 임베드(self-contained 증거).
4. **`buyers.passport_id` 처리** — RRN과 동일 `Crypt::encryptString` 암호화, 또는 서명 대상 문서에서 마스킹. (Security 절대조건)
5. **서명 링크 = Laravel `signed` URL** — `BuyerDocumentController` 선례. 짧은 만료 + 1회성 + 철회 + 경로조작 불가. 서명본 재발송은 **동기 발송** 또는 **경량 큐**(Gemini: database 드라이버 + Cron `schedule:run`, supervisor 없이 비동기 — Ops 동기발송 딜레마 해소). MVP는 동기 + 중복클릭 방지 UI로 시작 가능.
6. **감사** — `document_access_logs`(source='board_signing' 등) + `audit_logs` 3전이. 서명본 내부 조회는 전 user + 로그.
7. **부분서명 정책** — 다중차량 1계약서 일부만 서명 케이스 처리 방식 코드 전 확정.

### 미결 사항 (jin 결정 필요)
- **우선순위**: 카라바 커스터마이징·7월정산검증(8/10)과의 순서.
- **board 분담**: "URL 전달"만 board가 하는데, board 세션·repo에서 별도 구현·커밋 필요(크로스레포).
- **본인확인 강도**(Codex+Gemini 지적): 이메일 회신은 증거 보강일 뿐 본인확인이 아님(부인방지 취약). **이메일 OTP 1회용 인증코드**(서명 링크 진입 시 발송·검증)를 MVP에 넣을지 여부 — 사외이사 2인 모두 법적 증거력 위해 권장. jin 결정.

## 🛠 car-erp 영향 분석

### 취약점 (Vulnerabilities)
- `buyers.passport_id` 평문 저장(이미 존재하는 갭, 이 기능이 노출 경로 3개 확대) — `Buyer.php:17`.
- `InternalDocumentController:41-47` IDOR가 salesman_id만 보고 buyer_id 미검증(별건이나 서명 발급 확장 시 반드시 buyer 스코프 추가).
- 서명 상태를 `progress_status`에 얽으면 대시보드·게이트 캐시 오염(설계로 회피).

### 보완사항 (Improvements)
- 서명본 S3 버킷 버저닝 활성화(법적 증거물 내구성).
- 마이그레이션 MySQL8 실측(3중 tier 불일치).

### 코드 수정 (Code Changes)
- 신규: `database/migrations/xxxx_create_signed_contracts_table.php` — vehicle 묶음·원본해시·스냅샷·status·서명이미지 경로·수신이메일·IP·UA·타임스탬프.
- 신규: `app/Models/SignedContract.php` — 하드삭제 가드 `deleting` 훅(§27 패턴).
- 신규: 서명 페이지 라우트 + 컨트롤러(`signed` 미들웨어, `BuyerDocumentController` 선례).
- 신규: 서명본 생성 서비스(원본 + 서명이미지 합성 + Certificate of Completion 임베드).
- 신규: 서명 요청 발급 API(board가 URL 얻는 용도) — HMAC.
- 수정: `app/Models/Buyer.php` — `passport_id` 암호화 캐스트(또는 문서 마스킹).
- 수정: `app/Services/Documents/DocumentFiller.php` — 발급 시점 스냅샷/해시 persist 훅.
- 수정: 차량 서류 탭 blade — 「전자서명 요청」 버튼.

### 신규 추가 (New Additions)
- `signed_contracts` 테이블 + 전용 S3 prefix(`signed-contracts/`).
- 서명 상태 추적(progress_status와 독립).
- Certificate of Completion 생성 로직.

### 모순·NO-GO 처리 로그
- NO-GO 없음(전 부서 조건부 GO, 사외이사 조건부 GO).
- 안건 가정 `InternalSalesmanScope` = phantom 정정(실제 인라인 salesman_id 체크).
- 충돌(서명 페이지 호스팅) = Security+Codex 수렴으로 ERP 호스팅 확정, Specialist[E] board안 격하.

## ✅ jin 최종 결정 (2026-07-10) — 계획 확정, 구현은 추후(주말/다음주 몰아서)

1. **OTP 본인확인 = 넣지 않음.** 서명 링크는 바이어와의 1:1 채널(카톡 등)에 보내므로 제3자 개입 여지가 없음. 본인확인은 그 채널의 신뢰에 의존. → 사외이사 권고(OTP) 기각, jin 운영 맥락 우선.
2. **`buyers.passport_id` 암호화 = 넣지 않음.** 이 필드는 실제 바이어의 여권/개인정보가 아니라 **우리가 부여하는 내부 구별용 식별자**(jin). 고유식별정보 아님 → 개인정보보호법 대상 아님. Security 절대조건 해제. ⚠️ 안전장치: 만약 향후 이 칸에 **실제 여권번호를 입력하는 운영**으로 바뀌면 그때 `Crypt` 암호화 재검토(현 계획은 평문 유지).
3. **우선순위 = 지금은 계획만.** 서명 기능·karaba 커스터마이징 둘 다 착수 안 함. 구현은 주말 또는 다음주 몰아서. 7월정산검증(8/10)이 먼저.

## ✅ jin 추가 결정 (2026-07-10 이어서) — 착수 전 미결 기술이슈 2건 해소

4. **서명본 파일 포맷 = 옵션 C (LibreOffice 없이).** 3사 운영 서버(heyman·karaba·ssancar 최소사양, queue worker 미가동)에 soffice(~400MB) 설치 비용을 피한다. 구성:
   - **서명 페이지** = 계약 핵심조건 요약(스냅샷 데이터 HTML 렌더) + **원본 스냅샷 xlsx 다운로드 링크**(바이어가 정확히 뭘 서명하는지 원본 그대로 확인) + Canvas 서명패드 + 이메일칸.
   - **서명본** = ① 스냅샷 xlsx **원본 그대로**(DocumentFiller 미접촉) + ② **서명+CoC PDF 1장**(서명이미지·원본해시·계약no·buyer·수신이메일·서명시각·IP/UA·감사해시 임베드). 두 파일을 `signed-contracts/` prefix 별도 보관.
   - 옵션 A(soffice 전체 PDF 렌더)는 "전체 계약서 픽셀 그대로 화면 미리보기"가 더 강하나 Ops 비용으로 기각. 무보 오주현 자문의 실제 핵심(①서명 대상 해시 고정 ②서명본 이메일 재발송)은 C로 충분히 성립. "화면에서 봤나" 약점은 다운로드+스냅샷 해시+이메일 재발송(정확한 스냅샷 첨부)으로 보강.
   - **CoC PDF 생성기 = mPDF + 나눔폰트 임베드**(단일 통제 페이지). 기존 dompdf는 한글 폐기 이력(SKILLS §8 #16~18)이라 배제. soffice 같은 시스템 의존성 아닌 composer 라이브러리 1개 추가. xlsx 전체가 아니라 CoC 1장만 렌더라 한글 리스크 최소.

5. **부분서명 = 전부-아니면-무.** `signed_contracts` 1 row = 계약 스냅샷 전체. 30대든 1대든 계약 통째로 1회 서명. 차량 구성 변경 시 **revoke + 재발급**(종이 계약과 동일). 차량별 개별 서명 미지원(상태·증거·CoC 복잡도 회피, 도메인상 불필요).

> 이로써 착수 전 미결 전부 해소 — 남은 건 jin의 "언제 착수" 신호뿐. 우선순위(계획만, 주말/다음주)는 3번 그대로 유지.

## 🗺 실행 계획 (ERP측 — 구현 착수 시 이 순서)

> 서명 엔진 = 자체구현 / 서명 페이지 = ERP 직접 호스팅 / board = 링크 전달 창구만 / 서명본 = 별도 보관 + Certificate of Completion.

**Phase 0 — 사전 확인 (30분)**
- `buyers.passport_id` 운영 실태 = 내부 식별자임 확인(jin 확정, 암호화 skip).
- 서명본 파일 포맷 결정(아래 ⚠️ 기술 이슈) — 이게 Phase 4 좌우.

**Phase 1 — 데이터 계층 (약 2.5h)**
- 마이그 `create_signed_contracts_table`: `id`·`buyer_id`·`vehicle_ids`(json, 다중차량)·`contract_no`·`snapshot_path`(발송시점 원본 동결)·`source_hash`(원본 해시)·`signed_path`(서명본)·`signed_hash`·`status`(pending/viewed/signed/revoked)·`sign_token`·`token_expires_at`·`recipient_email`·`signer_ip`·`signer_ua`·`sent_at`·`viewed_at`·`signed_at`. `vehicles`/`progress_status` 절대 미접촉(QA·Spec-B 만장).
- `App\Models\SignedContract` + `deleting` 훅 하드삭제 가드(status=signed 차단, §27 패턴).
- 롤백 = `drop table` 1줄, 기존 row 영향 0(additive).

**Phase 2 — 서명 세션 발급 (약 2h)**
- 서류탭 「전자서명 요청」 버튼(export·저장된 차량, `canScopeVehicle` 재인가) → 발송 시점 **원본 계약서 렌더 → snapshot 파일 저장 + 해시** → `signed_contracts`(pending) + `sign_token`(랜덤·만료) 생성.
- Laravel `signed` URL 발급(`BuyerDocumentController` 선례). board가 이 URL을 가져갈 내부 API(HMAC) 또는 화면에서 복사.

**Phase 3 — 바이어 서명 페이지 (ERP 호스팅, C 확정, 약 3h)**
- 공개 라우트 `/sign/{token}` + `signed` 미들웨어(만료·1회성·경로조작 불가). 인증세션 없음.
- 페이지 구성(C) = **계약 핵심조건 요약**(스냅샷 데이터 HTML 렌더 — 바이어·차량 N대·통화·금액) + **원본 스냅샷 xlsx 다운로드 버튼**(정확히 뭘 서명하는지 원본 확인) + Canvas 서명패드(모바일 대응) + 바이어 이메일 입력칸. (전체 xlsx 픽셀 렌더 안 함 = soffice 불필요.)
- 제출 시 `signer_ip`·`signer_ua`·`viewed_at`/`signed_at` 캡처. status=signed. **멱등**(이미 signed면 재제출 no-op).

**Phase 4 — 서명본 + Certificate of Completion (C 확정, 약 2.5h)**
- 서명본 = **① 스냅샷 xlsx 원본 그대로**(DocumentFiller 미접촉) + **② 서명+CoC PDF 1장**.
- CoC PDF(`mPDF` + 나눔폰트 임베드) = 서명 이미지 + 원본해시·계약no·buyer·수신이메일·서명시각·IP/UA·감사로그 해시 임베드(self-contained 증거, DocuSign 축소판). 단일 통제 페이지라 한글 리스크 최소.
- 두 파일 전용 S3 prefix(`signed-contracts/`) 저장. S3 버킷 버저닝 권장.

**Phase 5 — 증거 이메일 + 감사 (약 1.5h)**
- 서명본 **2파일(스냅샷 xlsx + 서명·CoC PDF)** 을 바이어 입력 이메일로 **동기 발송**(기존 `CompanyMailConfig`, queue 미가동) + "전자서명 확인" 문구(무역보험공사 자문). 중복클릭 방지 UI.
- `document_access_logs`(source='signing') + `audit_logs` 3전이(발송/열람/서명완료) 기록.

**Phase 6 — board 분담 (board repo·board 세션, ERP 세션 밖)**
- board가 ERP에서 서명 URL 받아 바이어 1:1 채널(카톡/SNS)로 전달. board는 서명 페이지·서명본 미보유.

**✅ 착수 전 기술이슈 = 해소됨(jin 2026-07-10 이어서)**: 포맷 = **옵션 C**(원본 xlsx 유지 + 서명면·CoC만 별도 PDF, soffice 불필요). CoC PDF = **mPDF + 나눔폰트**. 부분서명 = **전부-아니면-무**. 상세 = 위 「jin 추가 결정 4·5」.

**총 공수 ERP측 ≈ 11~12h** (C 채택으로 Phase 4 약간 단축, board 링크 전달 별도). 마이그 MySQL8 실측 필요. 신규 composer 의존성 = `mpdf/mpdf` 1개.

## 🔗 참조
- 과거 회의: [2026-06-18-board-portal-api.md](2026-06-18-board-portal-api.md)(HMAC·§29 PII·선적4종 화이트리스트), [2026-06-30-bl-shipment-bundle-v2.md](2026-06-30-bl-shipment-bundle-v2.md)(board↔ERP 묶음), [2026-06-04-tri-system-integration-respondio.md](2026-06-04-tri-system-integration-respondio.md)(PII 국외이전 §29).
- CLAUDE.md: 권한 미들웨어, §29 국외이전, 크로스레포 규칙 / SKILLS.md §2(progress_status_cache)·§8 #26·#27(IDOR·회계잠금)·§12(서류)·§13(공식).
- 코드: `InternalDocumentController.php`·`BuyerDocumentController.php`·`SalesContractMapping.php`·`Buyer.php`·`PurchaseSyncController.php`.
