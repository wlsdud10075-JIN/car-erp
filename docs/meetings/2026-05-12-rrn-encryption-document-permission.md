# 📅 회의록: 문서 다운로드 admin 제한 + RRN 암호화

- **일시**: 2026-05-12
- **강도**: 풀 (6역할: 코어 5 + Specialist 슬롯 B 데이터 무결성)
- **안건 유형**: 권한 + 개인정보 컬럼 + 마이그레이션 + 채널 격리 복합
- **자동발동 여부**: 사용자 트리거("회의 돌려줘"). 안건 키워드 `RRN`·`config/auth.php` 영향 → 풀회의 강제
- **참여 모델**: Claude main + 6개 general-purpose subagent 병렬

---

## 💬 역할별 발언

### 📋 PO
**판정**: 조건부 GO
**발언**: SSANCAR는 중고차 수출 ERP — `nice_reg_owner_rrn`(소유자 주민번호)이 말소·등록증·양도 PDF 3종에 박혀 있고 현재 평문이라, role=영업/통관 user도 `/erp/vehicles/{id}/documents/{type}` URL만 알면 admin 결재 없이 RRN 포함 서류를 받을 수 있음. 이건 사용자 가치보다 **고객사(SSANCAR) 법적 리스크** 문제라 7단계 보류는 정당화 안 됨 — 1순위 격상 동의. 다만 큐 1번(role 분기)은 통관/정산 일상 업무 화면이라 미루면 안 됨 → **병렬 트랙**으로. 우려: ① 영업/통관 role도 본인 등록·진행 중인 차량의 PDF는 받아야 함(말소·양도는 영업 일상 업무) → **admin-only가 아니라 role별 화이트리스트 재검토 필요**, ② 소급 마이그레이션 시 기존 RRN 백업·롤백 절차 누락, ③ carpul/heyman 채널도 국문 3종 발급하므로 채널 분기 없이 전 채널 적용 확인.
**다음 작업 큐 영향**: 큐 1번(role 분기) 자리 유지, 본 안건은 큐 0번(긴급 핫픽스) 별도 병렬 트랙. 큐 2~6번 무영향.

---

### ⚙️ Engineer
**판정**: 조건부 GO
**발언**: `VehicleDocumentController::show()`는 현재 `erp` 미들웨어만 통과하면 누구든 7종 서류 받음 — `abort_unless($user->isAdmin(), 403)` 한 줄 삽입은 5분 작업, `User::isAdmin()` 이미 존재(L47). RRN `encrypted` cast는 `getProgressStatusAttribute()`와 무관(접근 안 함)하므로 N+1·캐시 영향 없음. 단 **컬럼 길이 varchar(20) → varchar(255) 필수** — 평문 13자 → AES+base64 후 약 180~220자. 마이그레이션은 (a) `change()` 컬럼 확장 + (b) 평문→`Crypt::encryptString()` bulk update + (c) cast 추가 3단계로 분리. 롤백 SQL은 `Crypt::decryptString()` 역변환 필요해 1줄 불가 → 별도 `down()` 메서드 작성 의무.
**공수 추정**: 90분 (컨트롤러 5분 + 마이그레이션 작성·테스트 40분 + 평문→암호 변환 30분 + 회귀 15분)
**영향 파일**:
- `app/Http/Controllers/VehicleDocumentController.php`
- `app/Models/Vehicle.php`
- `database/migrations/2026_05_08_135208_add_owner_rrn_to_vehicles_table.php`(컬럼 확장 신규 마이그레이션)
- `resources/views/documents/deregistration.blade.php`
- `resources/views/documents/registration-application.blade.php`
- `resources/views/livewire/erp/vehicles/index.blade.php` (RRN 표시 부분 검토)

**캐시 rebuild 필요**: no — `progress_status_cache`/`receivable_risk`/`sale_unpaid_amount_krw_cache` 어느 것도 RRN 미참조 확인. 단 평문→암호 bulk update는 `DB::table()->update()` 직접 권장 (Eloquent `save()` 쓰면 `saving` 이벤트 트리거)

---

### 🧪 QA & Domain Integrity
**판정**: 조건부 GO
**발언**: RRN은 `fillable`(L24)·blade 2종(`deregistration` L150, `registration-application`)·편집 폼 1곳만 참조 — `encrypted` cast 추가는 `booted::saving`의 캐시 갱신 체인과 **무관** (RRN은 11단계·VAT 9%·미수금 공식 어디에도 없음). 7종 서류 라우트(`web.php` L24)가 `erp` 미들웨어라 통관/정산/영업 role도 PDF·CIPL 7종 전부 다운로드 가능 — C1·C4·L2 실제 권한 누수 상태이므로 1순위 격상 정당. **C3(채널 분기)와 묶어 처리하려면 `erp/forwarding-companies`·`receivables`처럼 `admin` 미들웨어 별도 그룹으로 라우트만 이동시키면 컨트롤러 체크 없이 1줄 해결**.
**도메인 공식 영향**: 없음 — RRN은 11단계·VAT 9%·`cost_total`·`progress_status_cache`·`sale_unpaid_amount_krw_cache` 어디에도 미사용. 다중통화/환율 0과도 무관.
**회귀 시나리오** (수동 30분):
1. 평문 RRN 차량 1대 조회 → 편집 패널 RRN 정상 표시
2. RRN NULL·빈 문자열 차량 → 편집 저장 후 `?:null` 분기(L642) 작동
3. admin 7종 URL 200, role=영업·통관·정산 7종 URL 403
4. deregistration·registration_application PDF에서 RRN 정상 복호 출력
5. `where('nice_reg_owner_rrn', $plain)` 같은 SQL grep — 발견 시 차단 (암호화 후 검색 불가)

**Unit Test**: 없음 — `tests/Unit/`은 `ExampleTest.php`만, `Feature/`도 Dashboard/Auth/Settings만. **신규 작성 필수 2건**:
- `VehicleDocumentControllerTest` (admin GET 200, user GET 403 × 7종)
- `VehicleRrnEncryptionTest` (평문 저장→DB raw 조회 시 암호문 / 모델 조회 시 복호 일치 / NULL safe)

---

### 🔒 Security & Compliance
**판정**: **NO-GO (현재 배포 상태)**
**발언**: 현재 `VehicleDocumentController::show()`는 권한 체크 0줄, 라우트는 `erp` 미들웨어만 → role=영업/통관/정산 일반 user도 RRN(varchar(20) 평문) 포함 PDF 다운로드 가능 — **개인정보보호법 §24 고유식별정보 처리 위반 + §29 안전조치 의무(암호화) 위반 직결**. 안건 4개 모두 필수, `isAdmin()`이 super+admin 포함하므로 `abort_unless($user?->isAdmin(), 403)` 한 줄 충분. **추가 보호 3건 의무**: ① export 전용 4종(invoice/sales_contract/ro_cipl/con_cipl)은 `abort_unless($vehicle->sales_channel === 'export', 403)` 채널 격리 필수(C3 미해결), ② `NiceApiService::fetch()`·`Log::warning` 출력에 RRN 마스킹(`****-*******`), ③ 차량 편집 패널 기본정보 탭 RRN input은 `type="password"` 또는 토글 마스킹.

**(a) 차단 사유**: RRN 평문 + 권한 미체크 + 채널 미격리 3중 위반 — 망법·개인정보보호법 명백 위반, 배포 시 과징금 리스크

**(b) 수용 가능한 최소 조건**:
1. `VehicleDocumentController::show()` 첫 줄 `abort_unless(auth()->user()?->isAdmin(), 403)`
2. `Vehicle::$casts`에 `'nice_reg_owner_rrn' => 'encrypted'` + 컬럼 `varchar(20) → text`(또는 varchar(500))
3. 평문→암호화 변환은 트랜잭션 + 풀백업 선행, `cursor()` + `update()` 1건씩 + 예외 시 throw
4. export 4종 문서 라우트에 `sales_channel === 'export'` 체크 추가

**(c) 대안**: 한 커밋으로 묶기 어렵다면 **1순위 hot-patch**로 (b)1·(b)4 먼저 머지(권한·채널 차단, 즉시 노출 차단) → 다음 커밋에서 (b)2·(b)3(암호화 + 마이그레이션). 단 (b)2까지 미완 상태에서 신규 RRN 누적되므로 **48시간 내 (b)2 완료 데드라인 필수**

**개인정보·API키 영향**: `nice_reg_owner_rrn`(주민·법인등록번호) 평문 → 암호화 cast 전환 + 문서 라우트 권한·채널 격리. NICE API 응답 로그 RRN 마스킹 권장.

---

### 🚀 Ops & Deploy
**판정**: 조건부 GO
**발언**: car-erp는 아직 Lightsail 미배포 + XAMPP 로컬 단계라 다운타임 자체는 무의미. **`nice_reg_owner_rrn` varchar(20)** 정의(`2026_05_08_135208_add_owner_rrn_to_vehicles_table.php:17`) — encrypted cast(base64+IV ≈ 250자) 절대 못 담음. ALTER → 평문 백필 → cast 적용 → 일괄 암호화 순서로 단일 트랜잭션 마이그레이션. `.env.example` `APP_KEY=` 빈값 확인 — 운영 키는 별도 비밀저장소(Lightsail 환경변수) 분리하고 **APP_KEY 분실 = 전 RRN 영구 복호 불가** README 경고. Python ERP는 RRN 컬럼 다루지 않음(엑셀 기반 별도) → **데이터 충돌 없음**.
**다운타임**: 0초 — 무중단 (vehicles row 소량 + InnoDB online DDL `ALGORITHM=INPLACE, LOCK=NONE` 가능, < 1만 row면 INSTANT/INPLACE 어느 쪽도 무체감)
**백업 시점**:
1. DB: `mysqldump car_erp > backup_pre_rrn_encrypt_$(date).sql` 마이그레이션 직전
2. APP_KEY: `.env` 통째로 별도 안전 저장소 — 키 한 글자 변경 시 전 row 복호 실패
3. 코드: `git tag pre-rrn-encryption` 부여

**queue worker 영향**: 무관 — 동기 마이그레이션 1회성
**환경 의존성**: 없음 — `encrypted` cast는 OpenSSL(PHP 기본) 의존. XAMPP/Lightsail Ubuntu PHP 모두 기본 포함. 단 **컬럼 타입은 `text`(또는 최소 `varchar(500)`) 권장** — 향후 cipher 길이 변동 대비

---

### 🔧 Specialist [슬롯명: 데이터 무결성]
**판정**: 조건부 GO
**발언**:
- 현 컬럼 `nice_reg_owner_rrn` varchar(20) nullable 평문, `VehicleSeeder`에 RRN 시드 없음, **검색·정렬 코드 0건** (`LIKE`/`where`/`orderBy` 매칭 없음) → 암호화 후 기능 회귀 위험 매우 낮음. 다만 길이 20은 `Crypter::encryptString` 출력(200+ chars)을 못 담음 → 변환 마이그레이션과 **함께 컬럼 확장 필수**, 안 하면 chunk update 도중 `Data too long`으로 일부만 변환된 혼재 상태
- **버전 분기 가능**: `nice_reg_owner_rrn_encrypted_at` (timestamp nullable) 표식 컬럼 추가해서 "변환 완료된 row" 식별. Accessor에서 `encrypted_at IS NULL`이면 평문, NOT NULL이면 `decryptString`. **idempotent 마이그레이션 → 도중 실패해도 부분 진행 안전**
- **Python ERP 동기화**: 같은 `vehicles.nice_reg_owner_rrn`을 Python이 읽는지 **선결 확인 필요**. 읽고 있으면 Python에 Crypt 호환 복호 이식 또는 Laravel 단일 운영 전환 후에만 암호화 실행 — 둘 중 합의 전 마이그레이션 금지
- **APP_KEY 분실 = 영구 손실**: 변환 전 (1) APP_KEY를 별도 안전 저장소(1Password / vault) 백업, (2) **변환 전 DB 풀백업 1개 평문 상태로 별도 보관**(90일 격리 후 폐기) — 키 사고 시 복구 마지막 보루

**retroactive 영향**:
- 회계 무관 (RRN은 정산·캐시·11단계와 무관) → 회계감사 영향 0
- 컬럼 길이 미확장 시 chunk update 부분 실패 → 평문+암호 혼재 시 PDF 3종에서 깨진 문자 노출 → **컬럼 확장 + 변환을 단일 PR 2-step**으로
- 검색 코드 0건 확인 → LIKE 검색 회귀 없음

**(a) (b) (c) 조건**:
- (a) 컬럼 `text`로 확장 마이그레이션 선행
- (b) `encrypted_at` 표식 컬럼 추가 + Accessor 분기 (점진 전환)
- (c) Python ERP RRN 참조 여부 확인 후 합의

**대안**: **평문 유지 + 컬럼 레벨 권한 차단** — Vehicle 모델에 `$hidden = ['nice_reg_owner_rrn']` + PDF·폼에서 `canViewSensitive()`(admin only) 게이트 통과 시에만 노출 + 비-admin 화면 `***-*******` 마스킹. **APP_KEY 분실 risk 0, Python ERP 호환 유지, 마이그레이션 risk 0**. 단점은 DB 유출 시 보호 없음 → 차후 단일화 시점에 암호화 재검토

---

## 🚨 NO-GO 상세 종합

**Security NO-GO + Specialist B 조건부 조합**:

### 차단 사유
- 망법·개인정보보호법 §24·§29 위반 (RRN 평문 + 권한 미체크 + 채널 미격리 3중)
- 컬럼 길이 부족으로 마이그레이션 도중 부분 실패 시 혼재 상태 위험
- APP_KEY 분실 = 영구 복호 불가 시나리오 미대비
- Python ERP의 RRN 참조 여부 미확인 시 단독 암호화 → 시스템 깨짐

### 수용 가능한 최소 조건 (이 7개 모두 충족 시 조건부 GO 발효)
1. **권한**: `VehicleDocumentController::show()` 첫 줄 `abort_unless($user?->isAdmin(), 403)`
2. **채널 격리**: export 4종(invoice/sales_contract/ro_cipl/con_cipl)에 `sales_channel === 'export'` 체크
3. **컬럼 확장**: `nice_reg_owner_rrn` varchar(20) → `text` 또는 varchar(500) 마이그레이션 선행
4. **암호화 cast**: `Vehicle::$casts`에 `'nice_reg_owner_rrn' => 'encrypted'` + 변환 마이그레이션 (chunkById + 트랜잭션)
5. **표식 컬럼**: `nice_reg_owner_rrn_encrypted_at` 추가 (idempotent 재실행 가능)
6. **백업 3종**: DB 풀백업(평문, 90일 격리) + APP_KEY 별도 저장소 + `git tag pre-rrn-encryption`
7. **Python ERP 확인**: 같은 컬럼 참조 여부 사전 확인 — 참조 시 합의 전 마이그레이션 금지

### 대안 2가지
- **Security 대안** — 2단계 hot-patch: 조건 1·2 먼저 머지(노출 즉시 차단) → 48시간 내 3~7 완료
- **Specialist B 대안** — **평문 유지 + 컬럼 권한 차단**: `$hidden` + `canViewSensitive()` 게이트 + 비-admin 마스킹. APP_KEY/Python 의존 0. 단점은 DB 유출 시 무보호

---

## ⚠️ 사용자 결정 필요 사항 (역할 간 충돌)

| 충돌 | PO 입장 | Security 입장 |
|---|---|---|
| 문서 다운로드 권한 범위 | **role별 화이트리스트** — 영업/통관도 본인 차량 PDF 다운로드 필요 (현장 업무) | **admin only** — 망법 관점 양보 불가 |

→ NO-GO를 풀지 못하면 진짜 차단 트리거. 결정 옵션 3:

| 옵션 | 내용 | 트레이드오프 |
|---|---|---|
| A. admin only | Security 안 그대로 | 영업/통관 일상 업무 마비 → 모든 RRN 포함 서류 admin이 발급 |
| B. role 화이트리스트 + 본인 차량 제한 | 영업/통관은 본인이 담당한(`vehicles.salesman_id = auth()->id()`) 차량의 국문 3종만 | 코드 복잡도↑, Security 추가 검증 필요 |
| C. admin only + 대안(평문 유지 + 마스킹) | Specialist B 대안 채택 — 암호화 안 하고 화면 마스킹 + $hidden | 망법 §29 충족 불충분 — DB 유출 시 평문 노출. 단기적 안전, 장기적 risk |

---

## 🏁 최종 권고

**판정**: **HOLD** (현재 단계에서는 즉시 GO 불가)

**근거**: Security NO-GO + Specialist B 조건부 + PO/Security 충돌 1건이 동시에 발생. 6역할 모두 1순위 격상 정당성에는 동의했으나, **변경 범위·순서·role 권한 정의**에 대한 사용자 결정이 선행돼야 GO 발효.

**필수 선행 작업** (사용자가 결정해야 할 4가지):
1. 🔴 **role별 권한 정책 결정** — 옵션 A/B/C 중 선택. B 채택 시 영업/통관/정산 role의 본인 차량 정의(`salesman_id` 매칭 vs `purchase_user_id` 매칭) 명시
2. 🔴 **암호화 방식 결정** — Specialist B 대안(평문+마스킹) vs 본안(encrypted cast) 중 선택
3. 🟠 **Python ERP RRN 참조 확인** — 같은 `vehicles.nice_reg_owner_rrn`을 Python이 읽고 있는지 30초 grep으로 확인
4. 🟠 **2단계 hot-patch vs 일괄 처리** — Security 대안(c) 채택해서 권한+채널 격리 먼저 머지할지, 본안 7조건 한 PR에 묶을지

**조건이 결정되면 자동 발효 GO**:
- 결정 1·2 확정 → Security NO-GO → 조건부 GO 격상
- 결정 3·4 확정 → Specialist B 조건부 → GO 격상
- 작업 진행 가능 상태로 전환

**보류 사유**:
- 사용자가 결정해야 할 사항 4건이 코드 작업보다 선행
- 결정 없이 진행 시 PO/Security 충돌이 작업 중간에 폭발하거나, Python ERP 의존성으로 시스템이 깨질 위험

---

## 🔗 참조

- `CLAUDE.md` 권한 3단계 / role 5종 / 미들웨어 6종
- `role기획보안_수정.md` §10 7단계(원래 위치) / §11 보안 이슈 4건
- `최종결과보고.md` C1·C3·C4·L2 항목
- `SKILLS.md` §9 action 파라미터 (Vehicle 모델 영향 분석에 사용)
- `decision_protocol.md` §6 "개인정보 컬럼 추가/노출" 행 + "권한·role 모델 변경" 행

---

## 📊 프로토콜 자체 첫 운영 노트 (메타)

- **메커니즘**: 6개 general-purpose subagent 병렬 호출 (각자 `docs/meetings/departments/{role}.md` Read → 코드 Read → 응답)
- **실측 소요**: 가장 긴 응답(Specialist B) 68초. 병렬이라 전체 회의 약 1분 10초
- **각 subagent 토큰 사용**: 약 54k~64k (전체 약 360k)
- **포맷 준수**: 6개 모두 `### 부서명 / 판정 / 발언 / 추가 필드` 형식 정확히 출력. 부서 프롬프트 v1 작동 검증됨
- **발견된 v1 프롬프트 개선점**:
  - Engineer: "롤백 SQL 1줄로 쓸 수 있는가?" 질문이 암호화 시나리오엔 부적합 (역변환 down() 필요해서 1줄 불가) — 다음 버전에서 "롤백 절차 명시" 정도로 완화 검토
  - Specialist 슬롯 B: Python ERP 참조 여부 확인이 가장 큰 차단 요인 — 안건 진행 전 grep 1회로 확정 가능하므로 회의 발동 전 사전 점검 항목으로 격상 고려

---

## [2026-05-12 사후 확인 노트]

**검증 결과**: Python ERP는 실재하지 않음.
- `Desktop/CAR_ERP/` 폴더 없음 (CLAUDE.md L17이 참조하는 `NEW_ERP.md`도 무효 — 별도 정리 안건)
- `Desktop/ERP/` 폴더에 Django 모델·서비스·뷰 `.txt` export + `dbsqlite3_tables.txt` 덤프 존재 — **도메인 분석용 참고 자료**일 뿐, 운영 인스턴스 아님
- `C:/xampp/htdocs/`에 Python 프로젝트 없음

**회의 결정에 미친 영향**:
- NO-GO 차단 조건 #7 "Python ERP RRN 참조 확인" **무효화**
- 사용자 결정 4건 중 #3 **결정 불필요**
- 남은 사용자 결정: ① role 권한 정책(A/B/C) / ② 암호화 vs 평문+마스킹 / ③ 2단계 hot-patch 여부 — **3건으로 감소**

**문서 정리 (옵션 A 채택)**:
- `CLAUDE.md` L221·L249에서 "Python ERP 인스턴스 병행 운영·종료" 운영 가정 제거
- `SKILLS.md` L788·L800에서 "Python ERP와 동일 환경 / 전환 흐름" 운영 가정 제거
- 정산 공식 비교 주석(`× 0.09 vs Python의 × 0.1`)은 도메인 의사결정 맥락으로 유지

**프로토콜 자체 교훈 (v1.1로 반영됨)**:
6 subagent 모두 CLAUDE.md를 ground truth로 읽고 외부 시스템(Python ERP) 실재 여부를 검증하지 않음. 부서별 프롬프트 6종에 **"사전 검증 의무 (v1.1)"** 섹션 추가 — 외부 시스템·파일 가정 시 grep/ls 1회 검증.

---

## [2026-05-12 사용자 결정 결과 — 최종 GO]

3건 결정 확정으로 NO-GO 풀림. 회의 상태 **HOLD → GO**.

| 결정 | 선택 | 비고 |
|---|---|---|
| 1. role 권한 정책 | **D. 모든 인증 user 다운로드 + 감사 로그** | A/B/C 외 새 옵션. PO 우려(영업 일상 업무 마찰)와 Security §29 안전조치를 감사 로그로 양립 |
| 2. 암호화 방식 | **① encrypted cast** | DB 자체 암호화 + 감사 로그 이중 안전장치. §29 "암호화" + "감사 로그" 동시 충족 |
| 3. 작업 단위 | **ⓐ 2단계 hot-patch** | 1단계 즉시 + 2단계 48h 내. 위험 작업 격리 |

**판정**: **GO** (조건부 GO에서 GO로 격상 — 3건 결정 모두 충족)

### 작업 계획 — 1단계 PR (즉시 머지, ~60분)
1. `database/migrations/2026_05_12_create_document_access_logs_table.php` — `document_access_logs` 테이블 생성 (user_id, vehicle_id, document_type, ip_address, accessed_at)
2. `app/Models/DocumentAccessLog.php` — Eloquent 모델
3. `app/Http/Controllers/VehicleDocumentController.php` — `show()` 첫 부분에 로그 기록 추가
4. 채널 격리: `if (in_array($type, ['invoice','sales_contract','ro_cipl','con_cipl'])) { abort_unless($vehicle->sales_channel === 'export', 403); }`
5. `resources/views/livewire/admin/document-access-logs.blade.php` — admin 조회 화면 (Volt)
6. 라우트 `/admin/document-access-logs` 추가 (admin 미들웨어)
7. 수동 회귀: admin/user 각각 7종 다운로드 → 로그 기록 확인 / 카풀 차량에 영문 4종 요청 → 403

### 작업 계획 — 2단계 PR (48h 내, ~90분)
1. `database/migrations/2026_05_12_extend_nice_reg_owner_rrn_column.php` — `nice_reg_owner_rrn` `varchar(20) → text` 확장 + `nice_reg_owner_rrn_encrypted_at` 표식 컬럼 추가
2. `app/Models/Vehicle.php` — `$casts`에 `'nice_reg_owner_rrn' => 'encrypted'` + accessor 분기 (`encrypted_at IS NULL`이면 평문, NOT NULL이면 decrypt) — 점진 전환
3. `database/migrations/2026_05_12_encrypt_existing_rrn_data.php` — 백필 마이그레이션 (`chunkById(500)` + 트랜잭션 + `encrypted_at IS NULL` 필터 + idempotent)
4. APP_KEY 백업 절차 README (`docs/deploy/app_key_backup.md` 신규)
5. `tests/Unit/VehicleRrnEncryptionTest.php` — 평문 저장→DB raw 조회 시 암호문 / 모델 조회 시 복호 일치 / NULL safe
6. `tests/Feature/VehicleDocumentControllerTest.php` — admin/user 각각 7종 권한 회귀
7. 수동: 풀백업(`mysqldump` + APP_KEY .env 복사 + `git tag pre-rrn-encryption`) → 마이그레이션 실행 → 변환 row 수 검증

---

## [2026-05-12 작업 완료 — DONE]

### 1단계 PR (커밋 `2f05f89`)
- `document_access_logs` 테이블 + `DocumentAccessLog` 모델
- `VehicleDocumentController` 채널 격리(영문 4종 `sales_channel='export'`) + 다운로드 로깅
- `/admin/document-access-logs` Volt 조회 화면
- 수동 회귀 3종(로깅·채널 격리·권한) 사용자 확인 완료

### 2단계 PR (커밋 `dc98ed2`)
- 마이그레이션 A·B 실행 완료 (각 32ms / 7ms — 기존 row 없어 백필 즉시 통과)
- Vehicle 모델 accessor/mutator: `encrypted_at` 표식 기반 분기 (`$casts['encrypted']` 대신 직접 — 점진 전환 안전성)
- APP_KEY 백업 절차 문서: `docs/deploy/app_key_backup.md`
- Unit Test 5건 + Feature Test 5건 = **10/10 통과** (5.68s)
- 사전 백업: `storage/backups/pre_rrn_encrypt_20260512_133318.sql` (80KB) + `.env` 사본 + `git tag pre-rrn-encryption`

### 검증 결과
- ✅ DB 평문 저장 차단 (raw 조회 시 base64 암호문)
- ✅ 모델 조회 시 복호 정상 (`Vehicle::find()->nice_reg_owner_rrn` = 평문)
- ✅ NULL·빈 문자열 safe (`encrypted_at` NULL 유지)
- ✅ 레거시 평문 row 호환 (마이그레이션 전 상태 시뮬레이션 통과)
- ✅ 카풀 차량 영문 4종 → 403 차단 (실패한 요청은 access log 미기록)
- ✅ 모든 채널 국문 3종 정상 + 다운로드 후 access log 1행 추가
- ✅ 일반 user(role=영업)도 D 옵션대로 다운로드 가능 + 로깅

**판정**: GO → **DONE**. 망법·개인정보보호법 §24·§29 (고유식별정보 + 안전조치) 충족. 큐 7번(권한 세분화) 완료, 큐 1번(일반사용자 대시보드 role 분기)으로 이동 가능 상태.
