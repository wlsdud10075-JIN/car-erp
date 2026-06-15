# 중고차 수출 ERP (car-erp)

> ⚠️ **세션 시작 시 필수 로드 순서** (반드시 이 순서로 모두 읽은 후 작업 시작):
> 1. **이 파일** (`CLAUDE.md`) — 프로젝트 도메인/권한/환경
> 2. **`CLAUDE_1.md`** — LLM 코딩 가이드라인 (실수 방지 규칙)
> 3. **`SKILLS.md`** — 구현 패턴 / 재발 버그 / UI 디자인 시스템
>
> 아래 import도 함께 자동 로드됨:
> @CLAUDE_1.md
> @SKILLS.md

> 📋 **라운드테이블 회의** (의사결정 무게가 큰 안건 발생 시):
> - 프로토콜: `docs/archive/md-2026-05-29/decision_protocol.md` 참조 (자동 로드 안 함)
> - 부서별 프롬프트: `docs/meetings/departments/{po,engineer,qa,security,ops,specialist}.md`
> - 과거 결정 검색: `docs/meetings/INDEX.md`
> - 트리거 키워드: "회의 돌려줘" / "라운드테이블" / "/회의" / "부서별로 검토해줘". 마이그레이션·VAT 공식·RRN·`config/auth.php` 변경 등 무거운 안건은 자동 풀회의 제안.

> 📦 **2026-05-29 트림** — 완료된 큐 표·grandfather 코드·폐기된 dompdf 버그·구 기획안(role기획보안_수정.md)·1차 배포 day-by-day 플랜은 `docs/archive/md-2026-05-29/` 로 이동. `CLAUDE.md.full` / `SKILLS.md.full` 원본 백업 보존. 옛 결정 맥락 필요 시 grep.

> 🔗 **형제 앱 `board` + 연동** (별도 repo/DB/APP_KEY/배포):
> - `board` = 매입·검차·경매 **앞단** 앱 (`C:\xampp\htdocs\board`, 자체 CLAUDE.md/SKILLS.md, 포트 8002). 매입 *확정 전* 워크플로우 → **car-erp(heyman) 재고 전환**. 현재 **heyman만 연동**.
> - **연동 B**: `POST /api/internal/purchase-sync` (HMAC+멱등). 받는 스펙=`docs/integration/purchase-sync-receiver.md`(권위) ↔ 보내는 스펙=board `SKILLS.md §12`. 상호링크, **복사 금지(drift)**.
> - ⚠️ **크로스 레포 규칙**: 레포 X 관련 결정/변경은 **X의 *커밋된 파일*에 남기고 X 세션에서 커밋**한다. 메모리는 레포별·PC별이라 안 따라옴 — **git 커밋된 파일만** 모든 세션·PC에 전파. (board 수정 = board 세션·board repo에 커밋.)
> - **ERP 배포 명칭**: heyman(현 운영)·karaba(2번째 회사 `karaba-erp.com` live)·ssancar(미래 대물량). 단일 master → 서버별 .env 구분. 상세 = 메모리 `project-deployment-naming`.

SSANCAR LTD.의 중고차 해외수출 전 흐름(매입 → 말소 → 판매 → 수출통관 → 선적(B/L) → DHL → 거래완료)을 관리하는 Laravel ERP.

## 환경설정
- **프레임워크**: Laravel 12 + Livewire 4 (Volt) + Flux UI Free
- **프론트엔드**: Tailwind CSS v4, Alpine.js
- **DB**: MySQL/MariaDB (XAMPP), DB명 `car_erp` / 운영 = MySQL 8 (Lightsail)
- **경로**: `C:/xampp/htdocs/car-erp`
- **포트**: 개발 서버 `8001` (my-crm 8000과 분리)
- **GitHub**: `https://github.com/wlsdud10075-JIN/car-erp.git`
- **운영**: AWS Lightsail `52.79.200.151` (heysellcar용 NEW_CAR_ERP). dev→master 머지 시 자동 SSH 배포. 기록=`docs/operations/aws-deployment-record.md`
- **외부 연동 환경변수**:
  - `NICE_PROVIDE_URL` / `NICE_PROVIDE_TOKEN` — 차량정보 자동조회 (ssancar-erp 미들웨어 경유)

## 권한 시스템 (permission 3단계 + role)

**permission**:
- `super` 시스템관리자 — 개발사(진) 전용. 모든 메뉴 + 기능설정 on/off. 고객사 사용자 관리 목록에서 **은닉**
- `admin` 최고관리자 — 고객사 측. ERP 전체 + 기타관리 (기능설정 제외)
- `user` 일반사용자 — role에 따라 접근

**role**: `영업 / 수출통관 / 재무 / 관리` (2026-05-19 풀회의 안건 I — 정산→재무 / 통관→수출통관 명칭 확정). 기본값 `관리`. super/admin은 role 무관 전체 접근.

**미들웨어 매핑**:
| alias | 메서드 | 보호 대상 |
|---|---|---|
| `admin` | `canAccessAdmin()` = super+admin | /admin/* |
| `super-admin` | `isSuperAdmin()` = super만 | 기능설정 |
| `erp` | `canAccessErp()` = super/admin ∪ role 전체 | /erp/* |
| `sales` | role∈{전체,영업} | /erp/salesmen/{id}/cashflow (본인) |
| `clearance` | role∈{수출통관,관리} | /erp/forwardings, /erp/vehicles 통관 탭 |
| `settlement` | role∈{재무,관리} | /erp/settlements |

**리다이렉션**: `/dashboard` 진입 시 super/admin → `/admin/dashboard`, role=영업 → `/erp/salesmen/{id}/cashflow`(본인 ID), 그 외 → `/erp/dashboard`.

**비밀번호**: `password` 해시만 사용.

## 도메인 고정 용어

### 차량 진행상태 10단계 (computed property — DB 컬럼 X)
> `progress_status_rule_version` 분기. **v4=현재 default** (2026-05-21 회의확장씬 안건 1). v1·v2·v3=grandfather (운영 데이터 거의 없음, 상세 SKILLS §2).

**v4 워크플로우 순서**: 매입 → 판매 → **반입(선적) → 통관** → B/L → 거래완료.
'선적'의 도메인 의미 = 반입(`bl_loading_location` 입력). 단계명: 수출통관중/완료 → 통관중/완료.

**v4 cascade — 우선순위 높음 → 낮음으로 평가, 첫 번째 매칭 반환**:
1. `bl_document` → **`거래완료`** (단독, B/L 발급 = 거래완료)
2. `bl_document` AND `is_export_cleared` → **`통관완료`** (실질 도달 불가 — #1 우선)
3. `is_export_cleared` AND `bl_loading_location` → **`통관중`** (반입 후 통관 신청)
4. `bl_loading_location` AND `export_declaration_document` → **`선적완료`** (반입 + 수출신고서)
5. `bl_loading_location` → **`선적중`** (반입지 입력)
6. `sale_price > 0` AND 판매미입금 ≤ 0 → **`판매완료`**
7. `sale_price > 0` → **`판매중`**
8. `is_deregistered = true` AND `deregistration_document` 존재 → **`말소완료`**
9. `purchase_price > 0` AND 매입미지급 ≤ 0 → **`매입완료`**
10. 기본값 → **`매입중`**

### 판매채널 3종 (`vehicles.sales_channel` enum)
- `export` 수출 — 다중통화, B/L·면장·DHL 풀 흐름
- `heyman` 헤이맨 — 국내 바이어, 원화 정산
- `carpul` 카풀 — 국내 바이어, 원화+VAT, 대행수수료

### 입금 상태 (computed)
- `완납`: 총 입금액 ≥ 총 판매액
- `부분입금 (XX%)`: 일부 입금
- `미입금`: 입금 없음

### 다중통화
`vehicles.currency` enum: `USD / JPY / EUR / GBP / CNY / KRW`. `savings_statuses.currency` 동일.

### 정산 마진 공식 (엑셀 v2 — 수출차량현황표.xlsm 실측 / 2026-05-21 확정)

```
판매금원화        = (sale_price + commission + auto_loading - tax_dc) × exchange_rate
                                                              ← 면장(export_declaration_amount)은 매출 검증용 (정산 공식엔 미포함)
                                                              ← 운임비(transport_fee)는 sale_total_amount(미수율 분모) 에만 들어감
정산판매금원화    = 판매금원화 - cost_total
판매마진          = 정산판매금원화 - (purchase_price + selling_fee)   ← 매입합계 = 구입금액 + 매도비
부가세마진        = purchase_price × 0.09                              ← 매도비 제외, 엑셀 CG와 동일
총마진            = (판매마진 + 부가세마진) × 0.9                      ← × 0.9 = 부가세 10% 차감 (사용자 확정)

정산액 (Salesman.type 별 자동 분기):
  - 프리랜서 (settlement_type='ratio')    = 총마진 × (settlement_ratio / 100)
                                            기본값: settlement_ratio = 50  (Settlement::FREELANCE_RATIO_DEFAULT)
  - 사내직원 (settlement_type='per_unit') = per_unit_amount
                                            기본값: per_unit_amount = 100,000  (Settlement::EMPLOYEE_PER_UNIT_DEFAULT)

실지급액          = 정산액 - 서류비 - other_deduction
서류비:
  - 프리랜서 = 50,000  (Settlement::FREELANCE_DOCUMENT_FEE — 엑셀 CJ = CH/2 - 50000 의 -50000)
  - 사내직원 = 0
```

`cost_total` = `cost_deregistration + cost_license + cost_towing + cost_carry + cost_shoring + cost_insurance + cost_transfer + cost_extra1 + cost_extra2` (9개 항목 합, computed).

**자동 default 동작**: 거래완료 진입 시 `Vehicle::saved` 훅이 `Salesman.type` 보고 `settlement_ratio=50` 또는 `per_unit_amount=100000` 자동 채움. 재무가 override 필요 시 명시 입력 → H3 가드(confirmed/paid 전환 시 값>0) 통과.

## 뷰 경로 규칙
- **Volt 컴포넌트**: `resources/views/livewire/erp/...` 하위 (자동 인식)
- **컨트롤러 뷰**: `resources/views/admin/...` 하위 (관리자 대시보드 등)
- 예: 차량 목록 → `resources/views/livewire/erp/vehicles/index.blade.php`

## 핵심 주의사항 (재구현 시 반드시)

1. **Volt 컴포넌트는 `#[Layout('components.layouts.app')]` 필수** — 누락 시 `No hint path defined for [layouts]` 500. auth 페이지는 `components.layouts.auth`
2. **사이드바: 데스크탑(md+)은 `sticky top-0 h-screen`, 모바일(<768px)은 fixed drawer + backdrop** — Alpine `isMobile` 분기 (`SKILLS.md §7` 참조)
3. **`<x-layouts.app>` 슬롯 내 `@php` 블록에서 `use` 문 금지** — Blade가 슬롯을 if/elseif 컨텍스트로 wrap해서 파싱 에러. FQN 직접 호출 (`\App\Models\Vehicle::query()`)
4. **`.md` 파일은 dev 전용** — dev → master/demo 머지 시 `.md` 제외 (cherry-pick으로 코드만)
5. **커밋 전 `vendor/bin/pint --dirty` 필수** — ⚠️ **`.php`만. `.blade.php`엔 pint 돌리지 말 것** (Volt 단일파일 클래스를 대량 reformat + 깨짐: 실측 1356줄 변경·테스트 깨짐). blade 변경은 pint 제외하고 커밋 (상세 SKILLS §8 #22)
6. **뷰 캐시 문제 시** `php artisan view:clear && php artisan cache:clear`
7. **Tailwind v4 + Vite** — 새 유틸 클래스 미반영 시 `npm run build` 또는 `npm run dev`
8. **차량 진행상태는 computed property** — DB 저장 X. `Vehicle::progress_status` 접근 시마다 우선순위 평가
9. **vehicles 비용 컬럼은 9개 분리** — Python의 `other_costs(JSON)` 폐기. 합계는 computed (`cost_total`)
10. **부가세마진 = `purchase_price × 0.09`** — 매도비(selling_fee) 제외한 순 구입가 기준. 엑셀 CG = T × 0.09 와 일치
11. **판매금원화 산정은 `sale_price + commission + auto_loading - tax_dc` 기반** (2026-05-21 재구조). 면장(`export_declaration_amount`)은 매출 검증용 별도 항목. 운임비(`transport_fee`)는 정산엔 미포함, 미수율 분모(`sale_total_amount`) 에만 들어감
12. **총마진은 마지막에 × 0.9** — 부가세 10% 차감. 사용자 확정 (2026-05-21)
13. **정산 default 자동 채움** — `Vehicle::saved` 거래완료 진입 시 `Salesman.type` 보고 `settlement_ratio=50` (프리랜서) 또는 `per_unit_amount=100000` (사내직원) 자동. 코드 상수는 `Settlement::FREELANCE_RATIO_DEFAULT / EMPLOYEE_PER_UNIT_DEFAULT / FREELANCE_DOCUMENT_FEE`

## Git 브랜치 전략
- `dev` — 작업 브랜치 (기본)
- `master` — 프로덕션 (push 시 자동 SSH 배포 — `artisan down` 1~3분, 업무시간 외 권장)
- `demo` — 데모용 (필요 시)
- 머지 규칙: dev → 다른 브랜치 push 시 `.md` 파일 **제외** (modify/delete 충돌 → 삭제 유지로 해소)

**Claude 작업 규칙 (1인 개발 컨텍스트)**:
- **별도 feature 브랜치 만들지 않음** — 모든 변경은 `dev`에 직접 커밋·푸시
- PR 만들지 않음 (사용자가 명시적으로 "PR 만들어줘"라고 한 경우만 예외)
- 커밋 단위로 변경을 정리해서 추적성 확보 (한 커밋 = 한 논리적 변경)

### dev → master 배포 정확 절차 (cherry-pick 방식 — 권장)

> ⚠️ **이번 세션 혼란 4건 방지 (2026-06-10). 매번 이 순서를 따를 것.**

**① "dev가 master보다 N커밋 앞섬"은 거의 항상 정상 — 겁먹지 말 것.**
`.md` 제외 머지는 dev 원본 SHA를 master에 안 남기므로, 과거 배포된 커밋들이 영원히 "앞선 것"으로 카운트됨. **커밋 수가 아니라 실제 코드 diff로 판단**:
```bash
git fetch origin
git diff --stat origin/master..dev -- . ':(exclude)*.md'   # ← 이게 master에 갈 진짜 변경
git log --oneline origin/master..dev                       # 신규 코드 커밋만 골라냄
```

**② `--no-ff` 풀머지 대신 코드 커밋만 cherry-pick** (master 히스토리 깔끔, .md 자동 배제):
```bash
git checkout master
git merge --ff-only origin/master          # 로컬 master 최신화 (behind일 때 필수)
git cherry-pick <이번 코드 커밋 SHA…>       # dev에서 만든 코드 커밋만
git push origin master                      # ← 운영 자동배포 트리거 (artisan down 1~3분)
git checkout dev
```
(코드 커밋에 `.md`가 섞였으면 그 커밋만 `.md` 빼고 재정리 후 cherry-pick. 다건 .md 충돌 머지는 board CLAUDE.md의 `git rm '*.md'` 절차 참고.)

**③ 브랜치 전환 전 미커밋 변경 확인 — 막히면 stash.**
`.claude/*`·`docs/meetings/INDEX.md` 등 **세션 무관 기존 미커밋 파일**이 자주 떠 있어 checkout을 막음. `git stash push -m "..."` → 작업 → `git checkout dev && git stash pop`. (pop 시 내 작업 아닌 파일이라고 당황 말 것 — 원래 있던 것.)

**④ 커밋 메시지 — Bash 툴에선 PowerShell here-string(`@'…'@`) 금지.** Bash에선 `@`가 리터럴로 박혀 메시지가 깨짐(실측). heredoc 사용:
```bash
git commit -F - <<'EOF'
feat(...): 제목
본문
EOF
```
(PowerShell 툴에서 커밋할 때만 `@'…'@`. 둘을 섞지 말 것.)

## ⚠️ APP_KEY 영구 손실 경고

**`php artisan key:generate` 사용 시 RRN(주민등록번호) 전체 영구 손실 위험**

- `nice_reg_owner_rrn` 컬럼은 `APP_KEY`로 암호화 저장. 키가 바뀌면 **모든 RRN 데이터 복호화 불가**, DB 백업으로도 복구 안 됨.
- **집/회사 양쪽 PC**: 한 PC에서 발급한 APP_KEY 값을 1Password 등에 백업 → 다른 PC `.env`에 동일 값 직접 입력. `key:generate` 절대 실행 금지.
- **운영 배포 시**: 최초 1회만 `key:generate` → 즉시 백업 → 재배포 시 동일 키 유지.
- **DB 백업 시**: APP_KEY도 별도 위치에 함께 백업 (분리 보관, 한쪽 유실해도 다른 쪽 보존).
- 상세 가이드 + 사고 복구 절차: `docs/operations/key-rotation.md`

## 새 PC 세팅
```bash
# 1. PHP 확장 활성화 — XAMPP php.ini (C:\xampp\php\php.ini)에서 주석 제거 필수
#    extension=gd        # PhpSpreadsheet 의존성
#    extension=zip       # PhpSpreadsheet 의존성
#    (Apache 사용 시 Apache 재시작)

git clone https://github.com/wlsdud10075-JIN/car-erp.git
cd car-erp
composer install && npm install
cp .env.example .env && php artisan key:generate    # ⚠️ 다른 PC면 기존 키 복사 (위 경고 참조)
# .env DB_DATABASE=car_erp 확인
# MySQL: CREATE DATABASE car_erp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
php artisan migrate && php artisan db:seed
npm run build
php artisan serve --port=8001
# 다른 PC 접속: --host=0.0.0.0
# 테스트 계정: admin@car-erp.test / password (시스템관리자, permission=super)
```

### 다른 PC에서 작업 재개 절차

```powershell
# 1. dev 브랜치 최신화
git checkout dev && git pull origin dev

# 2. 마이그 누락 확인 (필수)
php artisan migrate:status
php artisan migrate     # Pending 있으면 실행

# 3. 의존성 + 자산 (composer.lock / package-lock.json 변경 있으면)
composer install
npm install
npm run build

# 4. 캐시 클리어
php artisan view:clear && php artisan cache:clear

# 5. 자동 테스트 회귀
php artisan test

# 6. 개발 서버
php artisan serve --port=8001
```

## 자주 쓰는 명령어
```bash
php artisan serve --port=8001       # 개발 서버 (my-crm 8000과 분리)
npm run build                       # 프론트 빌드
npm run dev                         # Vite 개발 모드 (HMR)
php artisan migrate                 # 마이그레이션
php artisan db:seed                 # 초기 데이터
php artisan view:clear              # 뷰 캐시 클리어
vendor/bin/pint --dirty             # 커밋 전 포매팅
```

## AI 크로스 체크 (/cross-verify 스킬)

Codex + Gemini + Claude 3-model 비교 시 각 CLI 호출 방법.

```powershell
# 가용성
Get-Command codex -ErrorAction SilentlyContinue   # C:\Users\User\AppData\Roaming\npm\codex.ps1
Get-Command gemini -ErrorAction SilentlyContinue  # C:\Users\User\AppData\Roaming\npm\gemini.ps1

# Codex (auth.json에 ChatGPT 계정. config model=gpt-5.5)
cmd /c 'echo. | codex exec "프롬프트" 2>&1'

# Gemini (GEMINI_API_KEY + GEMINI_CLI_TRUST_WORKSPACE=true 자동 주입)
gemini -p "프롬프트" --approval-mode yolo 2>&1
```
⚠️ Codex: ChatGPT 계정으론 `gpt-4o-mini` 등 일반 OpenAI 모델 미지원 → 반드시 기본 `gpt-5.5` 사용.

## 기능 토글 (Setting 모델 — 구현 예정)
- `heyman_channel_enabled` — 헤이맨 채널 on/off
- `carpul_channel_enabled` — 카풀 채널 on/off
- 변경 권한: `super`만 (`canToggleFeatures()`)

## 외부 연동 상태

| 연동 | 상태 | 위치 |
|---|---|---|
| **NICE API** | ✅ 완료 (`698f0c9`, 2026-05-25) — ssancar-erp 미들웨어 경유. 미구현 2건(기통수·검사종료)은 nice_raw 에서 서류 생성 시 파싱 | `app/Services/NiceApiService.php`, `docs/nice-followup-items.md` |
| **포워딩사 메일** | ❌ 영구 제거 (사용자 결정) | - |
| DHL API | ⏸️ 1단계 스코프 외 (수동 입력만) | - |
| **S3** | ✅ 완료 — 버킷 `heysellcar-erp-docs`, IAM, `league/flysystem-aws-s3-v3`, 서명URL | `config/filesystems.php` |

## 개발 진행 상황

대부분의 큐(0~13)는 완료 상태. 운영 배포(`52.79.200.151`)·자동 SSH 배포 검증·NICE 연동·S3·DB백업 cron 전부 dev/master 반영. 완료된 큐 표 상세는 `docs/archive/md-2026-05-29/CLAUDE.md.full` 참조.

**현재 시점 (2026-05-29)**:
- 통관 SET 다중차량 — ⏸️ 보류 (선적 4종 충분, 추후 인보이스 3시트만 N대)
- 별건 3 (사이드바 재구성 + 로그 화면 일괄 노출 + audit_logs UI) — 대기
- 도메인 + HTTPS — ✅ **완료 (2026-06-11, `https://heysellcar.com` + www 라이브)**. Lightsail 도메인 이전 + certbot + APP_URL https + config:cache.

## ⏭️ 다음 세션 작업 순서

> **세션 시작 시 읽을 메모리**: `project-deployment` (배포 현황 — 가장 중요) · `project-seeder-contract` · `project-db-tier-mismatch` · `project-document-mapping`. 전체 배포 기록 = `docs/operations/aws-deployment-record.md`.

**현재 상태**: AWS Lightsail 운영 배포 완료 + **도메인/HTTPS 라이브(`https://heysellcar.com`, 2026-06-11)** + 헤이맨 실데이터 149대 적재 완료. 560+ 테스트 통과.

**남은 작업**:
1. **기능 안정화 검증** (서버 브라우저): NICE 조회 숫자 정상(4840 등)·통관서류 기통수/검사종료·사진있는 차량 force-delete FK cascade·다중차량 선적 Excel 시각·cron 익일 03:00 백업 1건.
2. ✅ **도메인 + HTTPS 완료** (2026-06-11). 상세 = 메모리 `project-deployment` 2026-06-11 섹션 / 배포기록 §14-1.
3. **(선택)** 통관 SET 다중차량(인보이스 3시트만 N대) / extension-scene 2-2 잔금 layout 코스메틱 / 말소 시 주소 필수가드 / NICE키 env분리·교체.
4. **별건 3** — 사이드바 재구성 + 로그 화면군 일괄 노출(`/admin/document-access-logs` + `audit_logs` UI 신설). 5~7h.

## 대시보드 명칭 및 설계 원칙 (확정)

대시보드는 3종으로 구분. **대화·코드 주석 모두 아래 명칭으로 통일** (혼동 방지).

| 명칭 | URL | 대상 | 성격 |
|---|---|---|---|
| **업무 대시보드** | `/erp/dashboard` | admin/super | 담당자 드롭다운으로 인원별 업무 현황 조회 |
| **관리자 대시보드** | `/admin/dashboard` | admin/super | 결과 중심 — KPI·차트·통계 |
| **일반사용자 대시보드** | `/erp/dashboard` | 일반사용자 | role별 오늘의 할일 중심 |

- **업무 대시보드** (`/erp/dashboard`, admin 접근 시) — **action-oriented**: 담당자 선택기로 인원별 현황
  - 할일 목록: 매입미지급 / 판매미입금 / 수출통관필요 / 선적필요 / DHL발송 / 정산대기
  - 진행중 차량 + 다음 할일 컬럼
- **관리자 대시보드** (`/admin/dashboard`) — **result-oriented**: 날짜 범위 지정 + 결과 현황
  - 1~12월 연간 매출 차트, 월별 인원별 매출 그래프, 인원별 평균 판매량
  - 채널별 현황, 전체 진행단계 현황, role별 탭 전환
- **일반사용자 대시보드** (`/erp/dashboard`, 일반사용자 접근 시) — role별 오늘의 할일
  - 영업: 현재 유지 (매입미지급·판매미입금 등)
  - 수출통관: 수출통관·선적 관련 처리 필요 항목
  - 재무: 입금·출금·정산 관련 처리 필요 항목
  - 관리: 보류
