# 📅 회의록: 외부 AI 리뷰(Codex·Gemini) 적합성 검증 + 새 시각 발굴
- 일시: 2026-05-26
- 강도: 풀회의 (/회의 명령어 호출)
- 안건 유형: 메타 감사 (권한·개인정보 + 배포 + 회계무결성 복합)
- 자동발동 여부: yes (/회의 슬래시)
- 사용자 산출물: 바탕화면 `claudereview.md`

## 안건
바탕화면 `codexreview.md`(Codex)·`geminireview.md`(Gemini) 두 외부 AI 종합리뷰가 현재 `dev` 코드에 유효한지 실측 검증, 배포 준비도(Codex 신중 vs Gemini 낙관) 판정, 두 AI가 못 본 새 시각 발굴.

## 진행자 사전 검증 (코드 실측 ground truth)
- #1 문서 스코핑: `VehicleDocumentController::show/showMulti` findOrFail만 → **VALID**
- #2 S3 URL: `app/Support/VehicleDocUrl` temporaryUrl(15분) 분기 존재 → **이미 해결, Codex 인용 stale**
- #3 npm audit: 정확히 9건(critical1/high5/mod3)
- #4 동시성: `PaymentConfirmationService` lockForUpdate 부재 → **VALID**
- #5 openEdit: L760 영업만 차단 → **VALID**
- #6 forceDelete: 로컬만 백업 → **VALID**
- #7 loop plan: segment4a/4b "B/L 100%" vs 코드 "50%" → **VALID+스펙충돌**

## 💬 부서별 발언 (Sonnet 4.6)

### 📋 PO — 조건부 GO
배포 즉시 차단은 없으나 **B/L 50%/100% 표류**가 현장 회계 충돌(수출통관이 50%에 B/L 발급 → 바이어 잔금 미회수 시나리오). 사용자 확인 필수. 신규: DocumentAccessLog 화면은 있으나 alert 워크플로우 없음 / v4 bl_document 단독 거래완료가 신규 통관담당 온보딩 혼란.

### ⚙️ Engineer — 조건부 GO (공수 7~9h)
#4는 `lockForUpdate`보다 **`WHERE confirmed_at IS NULL` atomic update** 권장(Eloquent updating 훅 충돌 회피 + SKILLS §2 DB::table 원칙). #1=`User::canAccessVehicle()` 신규로 컨트롤러 2곳. #5=`getSubordinateSalesmanIds()` 재사용 0.5h. #6=disk 분기+photos. 신규: **DocumentAccessLog 동기 기록 순서버그**(streamDownload 실패 시 누락) + **InterVehicleTransferService 동일 race**.

### 🧪 QA & Domain Integrity — HOLD
**B/L 50/100 스펙 표류**가 핵심: INDEX.md 2026-05-14 "unpaid_ratio>0.5 단일게이트" 확정 vs segment4a(2026-05-20) "100% 완납". G1BlLockTest 7건이 50% 기준 green. loop plan 그대로 실행 시 코드를 100%로 교체 유도 → 477 green 붕괴. 동시성→Settlement 자동생성 cascade 중복 위험. xlsx 셀값 검증 테스트 부재(`VehicleDocumentControllerTest`는 HTTP 200만).

### 🔒 Security & Compliance — NO-GO (조건부)
#1 **개인정보보호법 §29 배포차단급**: erp 미들웨어 통과 전 role이 임의 vehicle ID로 말소신청서·위임장(소유자 RRN) 다운로드. **showMulti도 동일 결손(두 AI 미발견)**. /admin/ports 라우트 auth/verified만(미들웨어 부재). DocumentAccessLog 모니터링 부재. Gemini "즉시 배포" 반대.

### 🚀 Ops & Deploy — 조건부 GO (다운타임 0초)
npm 9건은 빌드체인(esbuild/axios/lodash) — 운영은 `npm run build`만이라 런타임 노출 없음, 단 배포 전 fix 권고. forceDelete S3 orphan은 배포 후 1주. 신규: **db:backup S3 실패해도 SUCCESS 반환**(무음 백업실패) + **MySQL8 vs SQLite lockForUpdate 갭**(동시성 테스트 SQLite 미검출).

### 🔧 Specialist E (승인·권한 정책) — HOLD
**정책 D(2026-05-12 모든 user 허용+감사)는 manager_user_id·관리 scoping 도입 이전 결정** — 2026-05-21 #11 이후 정합성 사후 검토 없음. 목록은 관리가 본인 팀만(L349~355), 문서는 전 차량. P1(문서 scoping 정책)·P2(openEdit 가드)·P3(temporaryUrl) 사용자 결정 필요. 신규: **temporaryUrl 15분 = 미들웨어·DocumentAccessLog 완전 우회 경로**.

### 🔧 Specialist F (회계·정산 감사) — 조건부 GO
동시성 실재하나 **미수 분자 이중계산은 없음**(FinalPayment row 1개, `whereNotNull('confirmed_at')->sum` 단일 합산). 진짜 위협 = **confirmed_by/confirmed_snapshot 감사오염**(race 시 확정자·타임스탬프 덮어쓰기) + 일시 캐시 stale. `FinalPayment::updating` 훅은 첫 동시 SET을 못 막음(getOriginal=null). 수정=atomic update. `wire:loading.attr=disabled`로 단일 더블클릭은 방어되나 2탭 동시요청은 미방어.

## 🧩 중간 회의 결과 (Opus 4.7 1차 취합)
- 7부서 판정: 조건부GO 4 / HOLD 2(QA·E) / NO-GO 1(Security, 조건부)
- findings: Codex 8건 중 #2만 stale, 7건 valid. Gemini 핵심 잡고 총평 과낙관.
- 충돌: 배포 readiness(NO-GO vs 조건부GO) → 전부 "수정 후 GO" → 조건부 GO 수렴.
- 새 시각 9건 발굴(showMulti·감사오염 본질·InterTransfer race·DocAccessLog 탐지부재·temporaryUrl 우회·백업 무음실패·MySQL/SQLite 갭·로그 순서버그·정책D 정합성).

## 🌐 사외이사 의견 (Codex / Gemini — 둘 다 응답 성공)

### [Codex] — 조건부 NO-GO
부서 평가 대체로 정확. **과대평가=npm**(빌드체인이라 #1급 아님), **과소평가=#7**(코드 결함 아닌 "업무정의 미확정"). 새 시각 1·2·3·4·6·7·8 동의, #5 보정(temporaryUrl 자체는 올바른 개선이나 스코핑·로그 없이 발급되면 우회). 추가 사각지대: **①문서 ID 순차면 스코핑 전에도 대량열람 탐지 필요 ②개인정보 문서 보관기간·삭제정책이 forceDelete/S3 orphan과 연결된 법적 리스크**. 배포 전 3건 적정, 순서=①스코핑 ②B/L ③npm(장기화 시 lockfile 최소패치). (a)#1 권한결함 (b)모든 문서경로 담당/관리 검증+감사 (c)문서 임시비활성/admin만/S3 재키 재배포.

### [Gemini] — NO-GO
**본인 '즉시 배포' 총평이 낙관적 편향이었음 인정**(RRN=사업폐쇄급 재평가). #1 과소평가 인정. **#7(MySQL/SQLite 갭) 강력 동의**(시한폭탄). 추가 사각지대: **①IDOR 고쳐도 Rate Limiting 없으면 대규모 크롤링 무방비 ②audit log 동일 DB 평문이면 침해자 흔적 삭제 가능(로그 무결성)**. **npm보다 #6 forceDelete S3 + 백업 무음실패를 배포차단급으로 격상** 주장(데이터 유실 복구불가). (a)IDOR+B/L 충돌 (b)①Owner-only ②B/L 정책확정+동기화 (c)폴더 Private강제/RRN 마스킹/정산 잠금.

## 🚨 NO-GO 상세
- **차단 사유**: 문서 다운로드 권한 결손(#1) — RRN 포함 서류 cross-team IDOR. 개인정보보호법 §29. Security + Codex + Gemini 3주체 합의(전부 (a)(b)(c) 충족 → §4 유효).
- **수용 가능한 최소 조건**: show+showMulti 스코핑 패치(canAccessVehicle 단일출처) + B/L 50/100 사용자 결정 + npm audit fix.
- **대안**: 즉시 배포 필요 시 문서 라우트를 settlement 미들웨어로 일시 축소(수출통관·재무·admin만) + 영업 본인 차량만, 차기 세션 정식 스코핑 후 정책 D 복구.

## 🏁 최종 권고 (Opus 4.7 최종 취합)
**판정: 조건부 GO** (= 즉시 배포 불가, 핵심 3건 수정 후 배포)
**근거**: Codex 리뷰가 적합(신중 판정 정확). Gemini는 핵심 잡고 총평 과낙관 — 본인 철회. 모든 NO-GO가 "수정 후 GO" 조건부라 종합 조건부 GO.
**필수 선행 작업 (배포 전)**:
  - 문서 다운로드 스코핑 (show + **showMulti**) — `User::canAccessVehicle()` 단일출처
  - B/L 50% vs 100% 게이트 사용자 결정 → loop plan archive 또는 G1BlLockTest 재작성
  - npm audit fix + npm run build 재검증
**배포 후 1주**: 동시성 atomic update(+InterTransfer) / openEdit 관리 가드 / forceDelete S3+photos / db:backup 무음실패 fix
**별건 큐**: DocumentAccessLog 이상탐지+알림 / Rate Limiting+ID enumeration 방어 / 개인정보 보관·삭제정책 / XLSX 노란셀 헬스체크 / audit log 무결성

## 🛠 car-erp 영향 분석

### 취약점 (Vulnerabilities)
- [배포차단] 문서 다운로드 IDOR — `VehicleDocumentController::show/showMulti` (RRN, 개인정보보호법 §29)
- [높음] 재무확정 동시성 감사오염 — `PaymentConfirmationService` + `InterVehicleTransferService`
- [중간] openEdit 관리 미스코핑 / temporaryUrl 15분 우회

### 보완사항 (Improvements)
- forceDelete S3 orphan(+버저닝) / db:backup 무음실패 / DocumentAccessLog 이상탐지 / Rate Limiting / 개인정보 보관·삭제정책 / XLSX 헬스체크 / audit log 무결성

### 코드 수정 (Code Changes)
- `app/Http/Controllers/VehicleDocumentController.php` (show/showMulti 스코핑) + `app/Models/User.php`(canAccessVehicle)
- `app/Services/PaymentConfirmationService.php`(atomic update) + `app/Services/InterVehicleTransferService.php`
- `resources/views/livewire/erp/vehicles/index.blade.php` L760(openEdit 관리 가드)
- `app/Models/Vehicle.php` L493(forceDeleted S3 분기) + `app/Models/VehiclePhoto.php`(삭제 훅)
- `app/Console/Commands/BackupDatabase.php`(S3 실패 Log::critical)

### 신규 추가 (New Additions)
- B/L 50/100 결정 반영 / DocumentAccessLog 이상탐지+audit_logs UI(별건 3) / 문서 라우트 Rate Limiting / 개인정보 보관기간 정책 문서

### 모순·NO-GO 처리 로그
- Security·Codex·Gemini 조건부 NO-GO(#1) 전부 (a)(b)(c) 충족 → 유효, 단 조건부라 종합 조건부 GO.
- QA·Spec-E HOLD(B/L·정책 정합성) → 배포 전 필수 #2로 흡수.
- Codex/Gemini 사외이사가 자신들 리뷰의 #1 과소평가 인정 — 특히 Gemini "즉시 배포" 철회.

## ✅ 사용자 결정 (2026-05-26 회의 후)
1. **B/L 게이트 = 100% 완납 + 관리 승인 우회** — 선적/통관 진입은 50%(C5, 코드 일치 유지), **B/L 문서 발급은 잔금 100% 완납 필수**, 부족분(예 80%)은 [관리]/관리자 승인 후 발급. → `docs/loop-plans/segment4a·4b` 스펙 채택. **현재 `guardBlFiftyPercentRuleOnSaving`(50%)는 변경 대상** — `unpaid_ratio > 0` 차단 + 승인 우회 + `G1BlLockTest` 7건 재작성 + SKILLS §13 갱신. 도메인 공식 변경이라 구현 시 별도 검증 단계 권장.
2. **문서 다운로드 = 정책 D 유지** — 전 user 다운로드 + 감사로그(DocumentAccessLog). #1 스코핑 미구현(사용자 리스크 수용). → 감사로그 **실효화**(이상탐지·알림 + Rate Limiting)가 개인정보보호법 §29 충족 핵심으로 격상. show/showMulti 스코핑 패치는 배포 전 필수에서 제외.

→ 결정 반영: #1(문서 IDOR) "배포차단" → "수용된 설계 + 감사 실효화 보완". #7(B/L) "결정 대기" → "확정된 코드 변경(50→100)". 배포 전 필수 재정렬: ①B/L 50→100 변경 ②npm audit fix ③DocumentAccessLog 실효화(권장).

## 🔗 참조
- 원본 리뷰: 바탕화면 codexreview.md / geminireview.md / 산출물 claudereview.md
- 관련 과거 회의: 2026-05-12-rrn-encryption-document-permission(정책 D) / 2026-05-14-3way-workflow-policy(G1 50%) / 2026-05-21-extension-scene-decisions(#11 manager scoping)
- 코드: VehicleDocumentController / PaymentConfirmationService / Vehicle.php / VehicleDocUrl / docs/loop-plans/segment4a·4b
