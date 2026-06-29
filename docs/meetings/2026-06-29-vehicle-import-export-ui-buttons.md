# 📅 회의록: 차량 데이터 import/export UI 버튼 (보류 2건 재검토)
- 일시: 2026-06-29
- 강도: 풀회의 (/회의 명령어 호출)
- 안건 유형: 개인정보(RRN) + 권한 + 데이터무결성/회계 + Excel + 과거 만장결정 변경
- 자동발동 여부: yes (/회의 슬래시)
- 발동 부서: PO + Engineer + QA + Security + Ops + Specialist[B.데이터무결성 + F.회계·정산감사 + E.승인·권한정책 (+A.UX)] + 사외이사 Codex

## 배경
jin 요청: car-erp에 차량 데이터 import/export **UI 버튼**. 1차 "빈 양식 export 버튼"은 이미 구현·배포(dev 395267b, super/admin, `erp/vehicles/import-template`, 데이터 0). 이번 재검토 2건은 2026-06-18 풀회의에서 보류·NO-GO였던 항목.
- **안건 A. Import 버튼**: 빈 양식 채워 올리면 자동 import + "양식 기재내용 다르면 에러". 핵심질문 = 만장 NO-GO였던 "UI 셀프 회계 import"를 jin(super) super/admin 마이그레이션 모드로 한정 시 허용 가능?
- **안건 B. 데이터 export(컬럼선택형)**: 원하는 컬럼 골라 export. PII 헤드라인 NO-GO 하에서 어디까지?

확정 사실: `vehicles:import`(artisan) = withoutEvents+forceFill로 paid 회계잠금까지 덮는 마이그레이션 도구. maatwebsite/excel 설치·미사용. queue worker(Supervisor) 미가동. ssancar 수천건 차량 마이그레이션이 실수요. heyman 양식 동일.

---

## 💬 부서별 발언 요약 (Sonnet 4.6)

### 📋 PO
- **A**: super단독(=jin 전용)=조건부 GO(우선순위 낮음, artisan `--dry-run`으로 이미 충족) / **admin 포함=NO-GO** — admin이 forceFill로 paid/confirmed 차량 덮으면 `Settlement::confirmed_snapshot`(Gemini Lock) 파괴, 2026-06-18 Security·B·F 만장 차단선 PO 단독 번복 불가. ssancar 7월 적재는 artisan(jin SSH)로 충분. (a)admin forceFill paid 우회 (b)5조건(paid skip·settlement_status 제한·RRN 차단·dry-run 3단·audit) (c)현행 artisan --dry-run + 딥링크.
- **B**: 고정 화이트리스트 export=조건부 GO(v1, 6/18 GO 이행) / 컬럼선택 UI=HOLD(v2). jin 실수요 70%는 고정 화이트리스트로 해결. PII 블랙리스트·canScopeVehicle·export_logs는 어느 버전이든 필수.
- 근거: ImportVehicles.php L31/L212/L310, routes L43-47, 2026-06-18 L36-39.

### ⚙️ Engineer
- **A**: 조건부 GO (~10h). `--with-payments` 경로 UI에서 완전 제거, 허용필드=VEHICLE_FIELDS 한정, withoutEvents **유지**(Vehicle::saved 4훅 L581/606/657/690 — L606 PBP draft·L657 Settlement 자동생성이 phantom #23 유발하므로 죽이는 게 맞음)+`refreshCaches()` 명시 호출, paid 차량 skip, 헤더 strict 검증(MAP label 대조)+formula injection 가드(`^[=+\-@]`). 신규 `VehicleImportWebService`+`VehicleImportController`+Volt 3단 UI. ImportVehicles/VehicleTemplateExporter 무수정(단일출처).
- **B**: 고정 화이트리스트 export=GO(~5h, maatwebsite FromQuery+WithMapping, accessor 경유, Eager Load) / 컬럼선택형=NO-GO((a)토글 UI 신규구현 필요 phantom (b)/admin/data-io+서버 블랙리스트+컬럼프로필 DB (c)고정 화이트리스트 먼저, 추가 컬럼은 명시 추가). 검증: maatwebsite app/ 0건, column toggle 0건, queue worker 미가동, Vehicle::saved 4훅.

### 🧪 QA & Domain Integrity
- **A**: NO-GO(현 설계 그대로 UI화) → 조건부 GO. (a) forceFill이 기존 paid 차량 sale_price·exchange_rate·cost_* 소급 갱신 → confirmed_snapshot 괴리(감사 영구불능) + FP 미생성 → sale_unpaid_amount_krw_cache 과산정 → G1(B/L 게이트) 오동작. (b) ①신규차량만(기존 overwrite 차단) ②refreshCaches 복제 ③chk_sale_required fallback(L275-290) ④admin 미들웨어+canScopeVehicle ⑤formula injection ⑥헤더 strict 검증. (c) import 결과에 `/erp/vehicles/{id}` 딥링크 컬럼 → 1대씩 편집. 신규 테스트 4종.
- **B**: 조건부 GO(고정 화이트리스트). progress_status·마진·cost_total은 **accessor 경유 필수**(raw SQL=drift, §13/§5 단일출처), 환율0 외화 `sale_unpaid_amount_krw_cache=NULL`→"완납" 오판 방지(NULL 명시 표시), PII 마스킹 테스트. 신규 테스트 3종(accessor 일치·PII 마스킹·IDOR 스코프).

### 🔒 Security & Compliance
- **A**: NO-GO → 조건부 GO(7조건). (a) ①`cell()`의 `getCalculatedValue()`(L669)가 **수식 실행**=formula injection 실재 ②withoutEvents+forceFill 웹노출=paid 소급수정 UI 가능 ③RRN 평문 임시파일 잔류. (b) ①formula injection 가드(리터럴만 저장) ②xlsm차단·xlsx전용·5MB(mime+확장자 병행) ③withoutEvents 관련 — paid 가드 막힌 행 skip ④**RRN 컬럼 UI import 영구 차단**(NICE 조회로만) ⑤import audit(파일명 해시·user·ip·건수, append-only) ⑥임시파일 **즉시 삭제** ⑦super 한정+Livewire 액션 재인가. (c) 양식 다운로드→jin artisan(개인PC 책임).
- **B**: NO-GO → 조건부 GO(5조건). (a) ①컬럼선택=블랙리스트 구조적 우회 → **서버 화이트리스트(opt-in) 강제** ②encrypted cast ORM 자동복호화→xlsx 평문 유출(§29) ③export_logs 미구현 ④canScopeVehicle 미적용(IDOR) ⑤export방향 formula injection(수신 PC). (b) 화이트리스트 서버상수+PII 영구차단목록(rrn·account·name·addr·tax_id·c_no)+export_logs+canScopeVehicle+`'` prefix injection 방어. (c) 화면 내 CSV 복사(파일 미생성).
- 검증: canScopeVehicle(User.php L348)·MASKED_COLUMNS(AuditLog L21) 실재 / export_logs·formula injection 가드 **미구현**.

### 🚀 Ops & Deploy
- **A**: 수천행 웹동기=NO-GO / artisan·소량캡=조건부 GO. (a) heymanerp Supervisor `queue:work` 기록 0건(미가동), ssancarerp 불확실, php-fpm 60s timeout + 512M OOM 100%(2026-06-18 실측). (b) ①대량=artisan(SSH)만 ②웹버튼=T1 참조 소량(≤100행) 동기만 ③초과 시 "artisan으로" 안내 ④import 전 DB 수동 스냅샷. (c) ssancar 수천행=`vehicles:import` SSH 유지, 웹은 consignee 패턴 소량, 중기 Supervisor worker(통합로드맵 1순위) 후 ShouldQueue. **"마이그레이션 1회성인데 굳이 웹 버튼? → 아니다, artisan(SSH)이 안전."**
- **B**: 조건부 GO. S3 presigned(인스턴스 디스크 0) 또는 ≤500행 동기캡. 다운타임 0. 운영서버 `php -m | grep -E 'zip|gd'` 1회 확인.
- 검증: gd/zip 활성, maatwebsite 설치, jobs 테이블 존재, worker 미가동.

### 🔧 Specialist [B.무결성 / F.회계감사 / E.권한 (+A.UX)]
- **A-F(회계)**: NO-GO→조건부(상한명확). `withoutEvents`→`Settlement::saving`(L170) 비발동→`confirmed_snapshot` **미캡처**. `--with-payments`로 `settlement_status='paid'` forceFill 시 snapshot=null paid 정산 생성=Gemini Lock 정면 위반. **권한과 무관하게 forceFill 웹노출 자체가 리스크**. 상한: `--with-payments` UI 영구금지 / confirmed/paid/closed 차량 코드레벨 hard skip(미들웨어 아님) / FP·PBP·Settlement 생성 UI 차단 / import_audit_logs.
- **A-B(무결성)**: 조건부 GO. withoutEvents+refreshCaches는 proven 패턴(progress·미수 캐시 복원), PBP phantom(#23)은 오히려 withoutEvents로 방지, SavingsStatus는 import이 안 건드림. 위험=잔금 N건 평면화(type 손실)·VIN 매칭 기존차량 갱신 → `updated_at` 비교 권장.
- **A-E(권한)**: 조건부 GO. import은 forceFill 포함이라 **`super-admin` 미들웨어(super only) 권장**(admin은 SoD 위반). dry-run→super 승인 2단. audit_logs `event=vehicle_bulk_import`.
- **B**: F=조건부GO(accessor 강제·snapshot 혼용 금지·PII 블랙리스트) / B=GO(read-only) / E=조건부GO(export_logs 선행, admin 미들웨어 충분).
- 검증: Settlement booted/deleting/saving L65/97/170, confirmed_snapshot withoutEvents 시 미캡처 실증.

---

## 🧩 중간 회의 결과 (Opus 4.7 1차 취합)
조건부GO 다수 / NO-GO(F·QA·Security·Ops 부분) — 모두 "회계/PII/인프라 무방비 도입"에 대한 차단, 범위 축소 시 전환. **수렴**: ① `--with-payments`(정산/FP/PBP/paid) UI import 영구금지(만장) ② confirmed/paid/closed 차량 코드레벨 hard skip ③ import=신규·non-confirmed·base field만, RRN 제외 ④ dry-run→미리보기→확정 3단 + 헤더검증 + formula injection + xlsx·5MB·즉시삭제 ⑤ export=서버 고정 화이트리스트(opt-in)·PII 차단·accessor·export_logs+ratelimit, 컬럼선택은 v2. **충돌 3건**: withoutEvents 끄나/유지 · 웹버튼 import 실효성(목적 vs 안전경로) · 컬럼선택 당길지.

## 🌐 사외이사 의견

### [Codex]
사각지대 3: ① **super 1인 = 단일 장애점**(승인·실행·감사 동일인) ② **import 실패 복구 설계 부재**(부분반영·중복VIN·멱등·백업/롤백 없으면 마이그 도구가 장애 도구) ③ **export는 컬럼보다 "행 범위"가 더 위험**(스코프 누락=대량유출). 판정: **A 웹버튼 NO-GO** — 신규차량 ≤100행 + queue/rollback/audit/preview/dry-run 갖춘 별도 v2면 재논의, 수천건은 artisan SSH 유지, withoutEvents 유지 가능하나 paid/confirmed **절대 skip + refreshCaches 필수**. **B 고정 화이트리스트만 GO, 컬럼선택 v2.** 타 ERP=import는 preview→validate→async job→error report→audit, 회계 확정 데이터는 UI import로 안 덮음. 우선순위: 빈양식 export → 고정 export → artisan 마이그 안정화 → UI import 후순위. 자체 NO-GO: forceFill 회계필드 웹노출, 해제 (a)회계필드 제거 (b)dry-run/rollback/audit (c)확정·지급 데이터 불변.

### [Gemini]
호출 실패 — CLI 티어 변경(IneligibleTierError: Gemini Code Assist for individuals 지원 종료). 사외이사 1인(Codex)으로 진행. (decision_protocol: 사외이사 부재로 회의 무효화 안 함.)

---

## 🚨 NO-GO 상세 (전부 (a)(b)(c) 충족 — 범위 축소로 해소)
- **차단 사유**: forceFill+withoutEvents 웹노출 = `confirmed_snapshot`(Gemini Lock) 미캡처/괴리로 회계감사 영구불능 + RRN 평문 임시파일·formula injection(getCalculatedValue 수식실행)·PII export 평문유출(§29) + 수천행 웹동기 OOM/worker미가동.
- **최소 조건**: `--with-payments` UI 영구금지 + confirmed/paid/closed 코드레벨 hard skip + 신규·non-confirmed·base field·RRN 제외 + super-only + dry-run 3단 + 헤더검증·formula injection·xlsx/5MB/즉시삭제 + import audit. export=서버 화이트리스트·PII 차단·accessor·export_logs+ratelimit+canScopeVehicle. **대량은 artisan SSH 유지(웹 아님).**
- **대안**: ssancar 수천건 = 현행 `vehicles:import`(SSH) + 빈양식 버튼. 소량 회계 수정 = 차량편집 딥링크.

---

## 🏁 최종 권고 (Opus 4.7 최종 취합)

### 안건 A — Import 버튼: **HOLD (지금 만들지 않음)**
**근거**: jin의 실수요(ssancar 수천건 적재)는 웹 import 버튼이 **안전 경로가 아니다** — Ops(OOM/worker 미가동)·PO·Codex 모두 "수천건은 artisan(SSH) `vehicles:import` 유지"로 일치. 회계 import(`--with-payments`)는 F·QA 만장으로 UI 영구금지. 즉 jin이 버튼으로 풀려던 문제는 **이미 있는 도구(artisan vehicles:import + 빈양식 export 버튼 + `--dry-run`)로 충족**되고, 웹 import 버튼은 회계 무결성·인프라·SoD(super 1인) 리스크가 이득을 초과. "양식 다르면 에러"(헤더 검증)는 좋은 요구지만 import 버튼이 없으면 적용 대상이 없음.
**필수 선행(추후 v2로 정말 필요해질 때만)**: 신규·non-confirmed·base field만 / confirmed·paid·closed 코드레벨 hard skip / `--with-payments`·FP·PBP·Settlement·RRN UI 영구금지 / super-only / dry-run 3단 미리보기·헤더 strict 검증·formula injection 가드·xlsx·5MB·임시파일 즉시삭제 / import audit(append-only) / refreshCaches 명시 / ≤100행 동기캡(대량은 artisan) / 롤백·백업.
**보류 사유**: 실수요-안전경로 불일치 + 회계무결성 영구금지선 + 1인 SoD 단일장애점. 우선순위 후순위(빈양식 export → 고정 데이터 export → artisan 안정화 → 그 다음).

### 안건 B — 데이터 export: **조건부 GO (고정 화이트리스트 export-only) / 컬럼선택형은 HOLD(v2)**
**근거**: read-only라 회계 변경 리스크 없음(Specialist B=GO). PII만 코드레벨로 막으면 안전. 고정 화이트리스트는 이미 6/18 GO였고 jin 실수요 대부분 충족(PO). 컬럼선택 UI는 토글 코드 부재(phantom)로 신규 구현이라 v2(Engineer NO-GO·만장).
**조건(고정 화이트리스트 export v1)**:
1. **서버 고정 화이트리스트(opt-in)** — `ExportProfile::VEHICLE_EXPORT_WHITELIST` 상수. 차량번호·brand·model·year·mileage·매입/판매일·통화·환율·매입가·판매가·진행상태·담당자명·바이어명·컨사이니명 등 비PII·비회계.
2. **PII 영구 차단**(화이트리스트 등재 자체 금지) — `nice_reg_owner_rrn`·`purchase_seller_account`·`nice_reg_owner_name`·`nice_reg_owner_addr`·consignee `tax_id`·`c_no`. super/admin도 export 불가(§29).
3. **accessor 경유**(raw SQL 금지) — progress_status·cost_total·sale_unpaid_amount 등 §13/§5 단일출처. 마진·snapshot은 admin 전용(또는 v1 제외). 환율0 외화 NULL은 "환율미입력" 명시.
4. **canScopeVehicle 재인가**(영업 본인만, IDOR §8 #26) + **export 라우트 admin 미들웨어**.
5. **export_logs**(append-only: user·ip·컬럼·행수·필터·sha256) + `RateLimiter::for('data-export')`(분3/일100).
6. **export 방향 formula injection** — 문자열 셀 `^[=+\-@]` → `setCellValueExplicit TYPE_STRING`/`'` prefix.
7. 대용량은 S3 presigned 또는 ≤500행 동기캡(Ops).
**공수**: ~5h(Engineer). 우선순위: 빈양식 export 다음 순위로 적절.

> **📌 후속 결정 (jin, 2026-06-29 회의 직후 — 조건 2 정정)**: PII를 **블랙리스트(아예 제외) → 마스킹해서 포함**으로 변경. RRN=`880717-*******`(표준 개인정보 마스킹), 주소=시/군/구까지만(이하 `***`), 성명=`김*희`, 계좌·tax_id=뒤자리 마스킹. jin(super/소유자)의 export PII 정책 조정이며, 회의가 이미 허용한 "성명·주소 마스킹" 범위의 확장이라 재회의 불요. **단 블랙리스트(fail-safe) → 마스킹(fail-open) 전환이므로 안전장치 3개 필수**: ① RRN은 encrypted cast(읽으면 평문 전체 복호화) → 마스킹은 **전용 accessor 1곳에서만**, export는 원본 컬럼 직접 접근 금지 ② "export 파일에 마스킹 안 된 뒤 7자리(`-\d{7}`)가 절대 안 나온다"를 **테스트로 강제** ③ 마스킹돼도 개인정보 → export_logs·rate limit·canScopeVehicle 동일 적용. (조건 2의 "PII 영구차단" → "PII 마스킹 포함"으로 대체.)

### 충돌 3건 최종 판정
1. **withoutEvents** → **유지 + refreshCaches 명시**(Engineer·Specialist·Codex 우세). Security의 "끄지마라"는 progress cache 우려였으나 refreshCaches로 해소됨 + 끄지 않으면 PBP phantom(#23)·auto-settlement 발동. 단 import 버튼 자체가 HOLD라 실구현은 v2로 이연.
2. **웹버튼 import 실효성** → **artisan SSH 유지가 정답**(Ops·PO·Codex 일치). jin 실수요(수천건)는 웹버튼 부적합 → 안건 A HOLD의 직접 근거.
3. **컬럼선택형 export** → **v2 이연**(phantom, 만장). v1은 고정 화이트리스트.

---

## 🛠 car-erp 영향 분석 (Opus 4.7 산출)

### 취약점 (Vulnerabilities)
- `vehicles:import`의 `cell()` `getCalculatedValue()`(L669) = formula injection 면(현 artisan도 해당 — jin 신뢰 파일이라 현 위험 낮으나 UI화 시 치명).
- forceFill+withoutEvents UI 노출 시 `confirmed_snapshot` 미캡처/괴리 = 회계감사 영구불능.
- export 시 encrypted cast ORM 자동복호화 → xlsx 평문 RRN/계좌 유출(§29).
- export canScopeVehicle 미적용 = 영업 대량 IDOR(Codex "행 범위" 리스크).
- export_logs·formula injection 가드 미구현(6/18 권고 미이행).

### 보완사항 (Improvements)
- 데이터 export 고정 화이트리스트 + PII 코드레벨 차단 + accessor 경유.
- ssancar 마이그는 artisan 경로 안정화(실패 복구·멱등·백업/롤백 — Codex 지적).
- (현 artisan import도) formula injection 가드를 ImportVehicles::cell()에 선제 추가 검토(저비용 방어).

### 코드 수정 (Code Changes)
- 신규(안건 B v1): `app/Services/VehicleExportService.php` + `app/Exports/VehicleExport.php`(maatwebsite FromQuery/WithHeadings/WithMapping), 마이그 `create_export_logs_table`, `app/Providers/AppServiceProvider.php`(RateLimiter::for('data-export')), `routes/web.php`(GET export, admin+canScopeVehicle), `resources/views/livewire/erp/vehicles/index.blade.php`(데이터 다운로드 버튼).
- (안건 A는 HOLD — 코드 변경 없음. v2 착수 시: VehicleImportWebService + VehicleImportController + Volt 3단 UI, ImportVehicles 무수정.)

### 신규 추가 (New Additions)
- `export_logs` 테이블(append-only).
- 테스트: export accessor 일치·PII 마스킹·IDOR 스코프·formula injection·rate limit.

### 모순·NO-GO 처리 로그
- NO-GO(F·QA·Security·Ops·PO admin포함) 전부 (a)(b)(c) 충족(유효) → "회계/PII/인프라 무방비"에 대한 차단이므로 범위 축소(A=HOLD, B=고정 화이트리스트)로 해소(무효화 아님).
- 충돌① withoutEvents: Security 의견은 refreshCaches로 해소 가능 + phantom 근거로 Engineer/Codex 우세 채택.
- 충돌② 웹버튼 실효성: Ops/PO/Codex 일치 → A HOLD 직접 근거.
- 사외이사: Codex 1인(유효), Gemini CLI 티어 변경으로 부재(회의 유효).
- 안건 전제 정정: "ssancar는 정형 현황표 없음"(과거 메모리) = 오류, jin 확인상 heyman 양식 동일. 단 본 결론(A HOLD)에는 영향 없음.

## 🔗 참조
- 직전: docs/meetings/2026-06-18-excel-export-import.md (동일 안건 v1 결정 — T1 import·비PII export·회계 import 영구금지·컬럼선택 v2)
- SKILLS §2(캐시)·§5/§5-6(정산·회계 lock)·§8 #23/#25/#26/#27·§13 / CLAUDE.md 권한·RRN·APP_KEY
- 메모리: project_excel_export_import · project_review_md_remediation(Gemini Lock) · project_db_tier_mismatch · 통합로드맵(queue worker 1순위)
- 코드: ImportVehicles.php(L212/310/364/669) · VehicleTemplateExporter.php · Settlement.php(L65/97/170) · User.php(L348) · AuditLog.php(L21)
