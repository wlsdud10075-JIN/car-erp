# 📅 회의록: 엑셀 Export/Import 사용자 셀프 데이터 관리 기능 도입
- 일시: 2026-06-18
- 강도: 풀회의 (/회의 명령어 호출)
- 안건 유형: PDF·Excel + 개인정보 + 권한 + 데이터무결성/회계
- 자동발동 여부: yes (/회의 슬래시)
- 발동 부서: PO + Engineer + QA + Security + Ops + Specialist [A.UX + B.데이터무결성 + F.회계·정산감사] + 사외이사 Codex/Gemini

## 배경
jin 제안: "개발자(우리)가 대량 적재하지 말고, 양식을 export로 내주고 사용자가 채워 import하면 자동 반영." Export=고정 양식(열 엄격)+컬럼선택형, Import=채워서 자동반영. 목적=대량 업로드 부담 이양(bus factor=1 해소). jin 본인이 "보안은 회의 필요" 명시.

현재 사실(검증): `vehicles:import`·`consignees:import` = **artisan(개발자) 전용** 존재 / **데이터 export 없음** / **`maatwebsite/excel ^3.1` composer 설치돼 있으나 미사용** / **"차량목록 표시컬럼 설정"은 코드에 없음(grep 0건 — 안건 전제가 phantom)** / `config/column_labels.php`(ColumnLabel) 라벨정의는 존재 / `nice_reg_owner_rrn`·`purchase_seller_account` 암호화+MASKED_COLUMNS / `User::canScopeVehicle` IDOR 단일출처 / paid 회계잠금(confirmed_snapshot/Gemini Lock) / queue worker(Supervisor) **미가동**.

---

## 💬 부서별 발언 요약 (Sonnet 4.6)

### 📋 PO — 조건부 GO
컨사이니 양식 import는 이미 jin이 artisan으로 운영중(실측 7건). **`/erp/consignees` 상단 "양식 다운로드+업로드" UI로 승격 = v1**(관리/admin 셀프, jin 호출 불요). 차량 `vehicles:import`는 헤이맨 이관용 1회성 마이그(withoutEvents·paid 직접박기·RRN 평문) → **UI로 풀지 말 것**. Export는 표시컬럼+필터 그대로 "조회결과 다운로드"만, RRN은 마스킹+감사. **지급자동화(payment_automation) 뒤로**, 별건3과 병렬(사이드바 1회 작업). 흡수금지: 차량 import UI화·정산/잔금 import·RRN export.

### ⚙️ Engineer — 조건부 GO (18~24h)
**`maatwebsite/excel` 이미 설치·미사용(composer.json L19)** → raw PhpSpreadsheet 재발명 말고 이 패키지 채택(FromQuery·WithHeadings·WithValidation·WithChunkReading·검증/드롭다운/chunk 기본). ImportVehicles/ImportConsignees가 dry-run·검증·lookup-or-create·DB::transaction 동일 골격 → `ImportSchema`/`ExportProfile` 추상화 자연. **표시컬럼 토글은 코드에 없음** → 컬럼선택 export는 서버 컬럼프로필 신규. bulk 적재 시 `Vehicle::saved` 훅 4개 N발동 → `withoutEvents`+사후 `refreshCaches()`(ImportVehicles 패턴) 강제. queue=database+jobs 있으나 **운영 worker 미확인**, >1000행은 ShouldQueue.

### 🧪 QA — 조건부 GO
import 후 **refreshCaches 누락 시** progress_status_cache·sale_unpaid_amount_krw_cache stale → 대시보드 5카드·미수율 게이지 거짓. export는 **accessor 경유 필수**(raw SQL=drift 100%). `chk_sale_required`(sale_date+환율>0, §8 #25) → 엑셀 환율 누락 시 4025 폭주, 환율0 외화 krw_cache NULL="미수0" 오판→G1 게이트 우회. 채널 enum오타·비용9 음수·잔금N건(평면 표현불가, 5칸 한정) 행별 검증. 신규 테스트 4종 필수(컬럼검증·캐시갱신·export 공식일치·멱등).

### 🔒 Security — NO-GO (→조건부, (a)(b)(c) 충족)
**Export PII 평문 유출=헤드라인**. RRN·계좌·tax_id·c_no·성명/주소가 xlsx 셀에 쓰이는 순간 암호화·MASKED_COLUMNS 무력화→USB/메일 유출(§29). **블랙리스트(RRN·계좌·tax_id reject)+화이트리스트 외 금지+성명/주소 마스킹** 양보불가. export/import 라우트 `canScopeVehicle` 재인가(영업 본인만, §8 #26 IDOR), import=admin/super 한정. **신규 `export_logs`**(append-only)+rate limit(분3/일100). import=xlsx만(xlsm차단)·5MB·**formula injection 가드(셀 `^[=+\-@]` reject)**·임시저장 1h 삭제·dry-run→confirm.

### 🚀 Ops — 조건부 GO (다운타임 0)
PhpSpreadsheet zip/gd 활성. 단 **현 풀로드+단일트랜잭션은 수백~수천행 PHP memory_limit(512M) 초과 100%**, nginx/php-fpm 30s timeout hit. **queue worker 미가동**(.env database+jobs 있으나 Supervisor `queue:work` 안 뜸 — 통합로드맵 2026-06-05 1순위 잔여). worker 없이 Job만 푸시=jobs에 영원히 쌓임. export는 S3 presigned(인스턴스 디스크 안 남김). 순서: ①Supervisor 선행 ②Import Job+chunk ③Export Job+S3 ④소량(≤100행) 동기 fallback.

### 🔧 A.UX — 조건부 GO
**별도 `/admin/data-io`(admin/super)** — 차량목록에 묻으면 영업 오트리거+IDOR 표면. **표시컬럼 설정 코드 없음=phantom** → 컬럼선택 export는 신규 구현 선행(큰 작업). import 3단계(업로드+dry-run→행별 OK/WARN/ERROR 미리보기 diff→[확정]). 잠긴 차량(paid) 회색 잠금뱃지+skip. 모바일은 "PC에서" 안내.

### 🔧 B.데이터무결성 — NO-GO ((a)(b)(c) 충족)
**2티어 분리 전제**. T1 참조(countries/forwardings/**buyers/consignees**)=v1 허용(consignee-import 실측). **T2 거래/회계(vehicles·PBP·FP·settlements·savings_statuses)=v1 차단** — SavingsStatus 잔액 자동누적(직전balance 가감+lockForUpdate+CHECK)·잔금N건 평면화 불가·캐시 정합·3DB CHECK. 대안: T2는 "엑셀→차량편집 1대씩 딥링크"(saving훅·paid가드·IDOR 정상). savings_statuses import 영구금지.

### 🔧 F.회계·정산감사 — NO-GO ((a)(b)(c) 충족)
회계 import는 **Gemini Lock/confirmed_snapshot 정면 우회**. ImportVehicles가 이미 `forceFill(paid)`로 가드 우회중(시드의도) — 사용자에 주면 paid 정산 7필드 retroactive=감사 영구불능. **paid/confirmed/closed 차량·정산·잔금·snapshot·RRN import 영구금지**. export는 마진·snapshot·RRN admin전용 화이트리스트, 영업은 차량번호·매입가·판매가까지 마스킹. import 통과조건=`settlement_status∈{pending,calculating}`만.

---

## 🧩 중간 회의 결과 (Opus 4.7 1차 취합)
조건부GO 5 / NO-GO 3(Security·B·F — 전부 "회계/PII 무방비 도입"에 대한 차단, v1 축소 시 전환). 수렴: 데이터 2티어 / Export PII 화이트리스트 / 회계 import 영구금지 / maatwebsite 채택 + 미리보기→확정 / queue 인프라. 충돌3: 컬럼선택(phantom 전제)·queue선행 vs 소량동기·차량export 범위.

## 🌐 사외이사 의견 (Codex / Gemini — 2인 응답)

### [Codex]
놓친 리스크: ①**셀프관리는 권한보다 책임소재**(누가 어떤 원본파일로 뭘 덮었나 — 롤백·승인·보관 없으면 분쟁 시 운영자가 짐) ②**T1도 안 가볍다**(컨사이니/국가/포워딩사 오염→선적·통관 오류→T2 피해) ③**export=유출면**(다운로드 후 파일 보관·재공유 통제 빠짐). 충돌판정: ①컬럼선택 export **NO**(phantom 제거, 고정 템플릿부터) ②queue는 대량 선행조건, **단 500행 이하 T1 동기 허용** ③차량 export는 비PII·비회계 **고정 화이트리스트만 GO, 컬럼선택 v2**.

### [Gemini]
놓친 리스크: ①**동시성 Lost Update**(사용자가 엑셀 수정 중 DB 변경→과거 엑셀로 덮어쓰기. Checksum/Version 방어 없음) ②**참조무결성 도미노**(T1 코드 변경→연결된 T2 과거 정산 통계 꼬임. Soft Delete+Unique ID 필수) ③**그림자 CS**(검증 UI 부실→결국 개발자에 문의 몰림=셀프관리 아닌 개발자 대행). 충돌판정: ①컬럼선택 **필수**(기존 ColumnLabel 재활용) ②V1 **동기(50행 제한)** ③차량 export='현재보유·미결제'한정. 타ERP=**실패 행만 따로 엑셀 추출→수정 재업로드**(SAP/Oracle). 우선순위: ①전체 export(PII 마스킹) → ②T1 동기 import(50행)+중복체크. 대안: Google Sheets API / Web Grid(Handsontable) / 데이터수정요청 폼.

---

## 🚨 NO-GO 상세 (3건 — 전부 (a)(b)(c) 충족, v1 축소로 전환)
- **차단 사유**: 회계컬럼·paid차량·정산·잔금·적립금 import = Gemini Lock/confirmed_snapshot 우회 + 캐시/정합 silent corruption + RRN·계좌 평문 export 유출(§29).
- **최소 조건**: 데이터 2티어(T1만 import) + Export PII 화이트리스트·canScopeVehicle 재인가·export_logs·rate limit + 회계 import 영구금지 + 미리보기→확정 + refreshCaches 강제.
- **대안**: T2는 import 대신 "엑셀→차량편집 1대씩 딥링크". PII export는 super 승인큐.

---

## 🏁 최종 권고 (Opus 4.7 최종 취합)
**판정: 조건부 GO — v1 범위를 강하게 좁힘.** "도입 자체"는 GO이나, 안건 원안(차량·회계 포함 셀프 import + 컬럼선택 export)이 아니라 **T1 참조 import + 비PII·비회계 차량 export-only**로 한정. 사외이사 2인·내부 6부서 모두 이 축소선에 수렴.

### 충돌 3건 최종 판정
1. **컬럼선택형 export → v2 이연**: 전제("표시컬럼 재활용")가 phantom(코드 없음). v1은 **고정 템플릿**. (Codex 지지 / Gemini는 ColumnLabel 재활용 필수라 했으나 — `config/column_labels.php`는 라벨정의일 뿐 사용자 화면 토글 부재 → 신규 구현이라 v2가 맞음. Gemini 의견은 v2 설계 시 ColumnLabel을 라벨소스로 채택하는 것으로 반영.)
2. **queue 선행 vs 동기 → 소량 동기캡으로 v1 출시**: v1은 **동기 + 100행 하드캡**(Ops NO-GO 대안 (c) + Codex 500행 + Gemini 50행의 보수 중간값). 대량·queue worker(Supervisor)는 통합 로드맵 1순위와 묶어 v2. v1에 queue 강제 안 함.
3. **차량 export 범위 → 비PII·비회계 고정 화이트리스트**: 차량번호·진행상태·매입/판매일·통화·환율·매입가·판매가·담당자·바이어명 등. 마진·snapshot·RRN·계좌·tax_id·성명/주소 제외. accessor 경유.

### 신규 채택 조건 (사외이사 발)
- **동시성 Lost Update 방어**(Gemini): import는 **신규 추가 우선**, 기존 머지는 자연키 기반 + `updated_at` 비교(엑셀이 DB보다 오래됐으면 경고/skip). 식별자(buyer id) 변경 금지.
- **참조무결성 도미노**(Codex/Gemini): T1 import는 **soft delete + 자연키**, 기존 레코드 hard 변경 금지(T2 과거 통계 보존).
- **책임소재·보관**(Codex): export_logs + import는 원본파일·결과를 audit_logs에 행별 기록(누가 뭘 덮었나).
- **그림자 CS 방지**(Gemini): **실패 행만 따로 엑셀로 추출 → 수정 재업로드**(SAP 패턴). 검증 UI 부실하면 셀프관리가 개발자 대행이 됨.

### 필수 선행 작업 (v1)
1. `maatwebsite/excel` 채택(이미 설치) — raw PhpSpreadsheet 재발명 금지
2. T1 참조(바이어·컨사이니) import UI 승격(`ImportConsignees` 재호출) — 동기 100행캡·행별 검증·dry-run 미리보기→확정·실패행 추출·멱등(자연키)·신규우선
3. 차량 export-only — 고정 화이트리스트·accessor 경유·canScopeVehicle 재인가·PII 블랙리스트/마스킹
4. `export_logs` 마이그(append-only) + RateLimiter + import audit_logs 행별
5. import 보안: xlsx만(xlsm차단)·5MB·formula injection 가드·임시저장 1h삭제·admin/super 한정
6. import 후 refreshCaches 강제(차량 무관 — T1은 캐시 영향 적으나 패턴 확립)
7. 위치 = 별도 `/admin/data-io`(또는 `/erp/consignees` 상단 + `/erp/vehicles` 상단 다운로드)

### 보류(HOLD) → v2 이후
컬럼선택형 export / 대량 queue(Supervisor 세트) / T2 차량·회계 import("1대씩 딥링크"로 대체) / PII export(super 승인큐) / 차량 신규생성 import.

---

## 🛠 car-erp 영향 분석 (Opus 4.7 산출)

### 취약점 (Vulnerabilities)
- Export PII 평문 유출(RRN·계좌·tax_id·c_no·성명/주소) — §29, 암호화 무력화
- 회계 import = paid/confirmed/closed 차량 retroactive(Gemini Lock 우회) + ImportVehicles forceFill(paid) 선례
- import 후 refreshCaches 누락 = 대시보드 5카드·미수율 silent corruption
- 영업 대량 export IDOR(§8 #26) / formula injection(2차 공격)
- 동시성 Lost Update / T1 식별자 변경 → T2 통계 도미노
- queue worker 없이 동기 풀로드 = Lightsail OOM(공유 인스턴스 동반사망)

### 보완사항 (Improvements)
- 데이터 2티어 / 화이트리스트 export / 미리보기→확정 / 실패행 추출 재업로드
- export_logs + import audit + rate limit(책임소재)
- 자연키 멱등 + soft delete + updated_at 비교(동시성)

### 코드 수정 (Code Changes)
- 신규: `app/Services/Excel/{ExportProfile,ImportSchema}.php`, `app/Models/ExportLog.php` + 마이그
- 신규: `app/Console/Commands/ExportVehicles.php`, `ExportConsignees.php` + 라우트(`erp`/`admin` 미들웨어 + canScopeVehicle 재인가)
- 신규: `resources/views/livewire/admin/data-io/*` 또는 `/erp/consignees`·`/erp/vehicles` 상단 import/export UI
- 수정: `app/Console/Commands/ImportConsignees.php`·`ImportVehicles.php` — parse/MAP를 ImportSchema로 추출
- 수정: `app/Providers/AppServiceProvider.php` — `RateLimiter::for('data-export')`

### 신규 추가 (New Additions)
- `export_logs` 테이블(user/ip/대상/행수/컬럼/스코프, append-only)
- 테스트: 컬럼검증·캐시갱신·export 공식일치(accessor)·멱등·IDOR·PII 마스킹·formula injection·실패행 추출

### 모순·NO-GO 처리 로그
- Security·B·F NO-GO 3건 → 모두 (a)(b)(c) 충족(유효). "전체 도입"이 아닌 "회계/PII 무방비"에 대한 차단이므로, v1을 T1참조+비PII export로 축소해 조건부 GO로 수렴(무효화 아님 — 범위 제한으로 해소).
- 충돌① 컬럼선택: Codex(NO)·A(phantom) 우세 → v2. Gemini(필수·ColumnLabel) 의견은 v2 라벨소스로 반영.
- 안건 stale 사실 정정: "차량목록 표시컬럼 설정"은 코드에 없음(A슬롯 grep 0건) — 안건 전제 phantom 명시.

## 🔗 참조
- 과거: 2026-05-28 consignee 일괄 import(project_consignee_bulk_import), vehicles:import Option A(project_vehicle_import), 2026-05-12 RRN·문서권한, 2026-06-05 통합 로드맵(queue worker 1순위)
- SKILLS §2(캐시)·§5(정산)·§6(적립금)·§8 #25/#26/#27·§13 / CLAUDE.md 권한·APP_KEY 경고
- 메모리: project_db_tier_mismatch·project_cash_audit_fix·project_payment_automation
