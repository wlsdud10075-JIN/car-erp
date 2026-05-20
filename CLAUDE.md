# 중고차 수출 ERP (car-erp)

> ⚠️ **세션 시작 시 필수 로드 순서** (반드시 이 순서로 모두 읽은 후 작업 시작):
> 1. **이 파일** (`CLAUDE.md`) — 프로젝트 도메인/권한/환경
> 2. **`CLAUDE_1.md`** — LLM 코딩 가이드라인 (실수 방지 규칙)
> 3. **`SKILLS.md`** — 구현 패턴 / 재발 버그 / UI 디자인 시스템
> 4. **`role기획보안_수정.md`** (프로젝트 루트) — 대시보드 3종 + role별 기획 확정본
>
> 아래 import도 함께 자동 로드됨:
> @CLAUDE_1.md
> @SKILLS.md
> @role기획보안_수정.md

> 📋 **라운드테이블 회의** (의사결정 무게가 큰 안건 발생 시):
> - 프로토콜: `decision_protocol.md` 참조 (자동 로드 안 함 — 회의 가이드라인이라 코드 컨텍스트와 분리)
> - 부서별 프롬프트: `docs/meetings/departments/{po,engineer,qa,security,ops,specialist}.md`
> - 과거 결정 검색: `docs/meetings/INDEX.md`
> - 트리거 키워드: "회의 돌려줘" / "라운드테이블" / "/회의" / "부서별로 검토해줘". 마이그레이션·VAT 공식·RRN·`config/auth.php` 변경 등 무거운 안건은 자동 풀회의 제안.

SSANCAR LTD.의 중고차 해외수출 전 흐름(매입 → 말소 → 판매 → 수출통관 → 선적(B/L) → DHL → 거래완료)을 관리하는 Laravel ERP.

## 환경설정
- **프레임워크**: Laravel 12 + Livewire 4 (Volt) + Flux UI Free
- **프론트엔드**: Tailwind CSS v4, Alpine.js
- **DB**: MySQL/MariaDB (XAMPP), DB명 `car_erp`
- **경로**: `C:/xampp/htdocs/car-erp`
- **포트**: 개발 서버 `8001` (my-crm 8000과 분리)
- **GitHub**: `https://github.com/wlsdud10075-JIN/car-erp.git`
- **참조 프로젝트**: GPU CRM (`C:/xampp/htdocs/my-crm`) — 권한/사이드바/UI/반응형 패턴 재사용
- **외부 연동 환경변수** (Phase B 시점에 추가):
  - `NICE_API_KEY` / `NICE_API_SECRET` — 차량정보 자동조회 (NICE 자동차정보 서비스)
  - `MAIL_HOST` / `MAIL_PORT` / `MAIL_USERNAME` / `MAIL_PASSWORD` / `MAIL_FROM_ADDRESS` — 포워딩사 이메일 자동 발송 (SMTP)

## 권한 시스템 (permission 3단계 + role)

GPU CRM과 동일 구조. **role 종류만 ERP 도메인에 맞춰 조정** (구현 시점에 employeesapp 분석 후 최종 확정).

**permission**:
- `super` 시스템관리자 — 개발사(진) 전용. 모든 메뉴 + 기능설정 on/off. 고객사 사용자 관리 목록에서 **은닉**
- `admin` 최고관리자 — 고객사 측. ERP 전체 + 기타관리 (기능설정 제외)
- `user` 일반사용자 — role에 따라 접근

**role 초안** (⚠️ 미확정): `전체 / 영업 / 통관 / 정산 / 관리`. 기본값 `전체`. super/admin은 role 무관 전체 접근.

**미들웨어 매핑 초안**:
| alias | 메서드 | 보호 대상 |
|---|---|---|
| `admin` | `canAccessAdmin()` = super+admin | /admin/* |
| `super-admin` | `isSuperAdmin()` = super만 | 기능설정 |
| `erp` | `canAccessErp()` = super/admin ∪ role 전체 | /erp/* |
| `sales` | role∈{전체,영업} | /erp/salesmen/{id}/cashflow (본인) |
| `clearance` | role∈{전체,통관} | /erp/forwardings, /erp/vehicles 통관 탭 |
| `settlement` | role∈{전체,정산} | /erp/settlements |

**리다이렉션**: `/dashboard` 진입 시 super/admin → `/admin/dashboard`, role=영업 → `/erp/salesmen/{id}/cashflow`(본인 ID), 그 외 → `/erp/dashboard`.

**비밀번호**: `password` 해시만 사용 (my-crm `plain_password` 운영 부담을 피해 처음부터 단일 컬럼).

## 도메인 고정 용어

### 차량 진행상태 10단계 (computed property — DB 컬럼 X)
> 큐 17 — 폐기 컨셉 제거 (운영상 없음). 11단계 → 10단계.
> 큐 2.6 — `progress_status_rule_version` 분기. v1=단일 트리거(grandfather) / **v2=이중 트리거** (기본값, 신규 row).

**v2 이중 트리거 — 캐스케이드 (다음 단계 = 이전 단계 트리거 AND 현재 단계 트리거)**.
우선순위 높음 → 낮음으로 평가, 첫 번째 매칭 반환:
1. `dhl_request` AND `bl_document` → **`거래완료`**
2. `bl_document` AND `bl_loading_location` → **`선적완료`**
3. `bl_loading_location` AND `is_export_cleared` → **`선적중`**
4. `is_export_cleared` AND `export_declaration_document` → **`수출통관완료`**
5. `export_buyer_id` AND `shipping_date` → **`수출통관중`**
6. `sale_price > 0` AND 판매미입금 ≤ 0 → **`판매완료`**
7. `sale_price > 0` → **`판매중`**
8. `is_deregistered = true` AND `deregistration_document` 존재 → **`말소완료`**
9. `purchase_price > 0` AND 매입미지급 ≤ 0 → **`매입완료`**
10. 기본값 → **`매입중`**

**v1 grandfather** (`progress_status_rule_version < 2` row): 5단계까지 단일 트리거 — `dhl_request`만으로 거래완료, `bl_document`만으로 선적완료 등. 마이그 이전 row 호환용. 신규 row는 v2.

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

### 정산 마진 공식 (엑셀 실측 — Python ERP와 다름)
```
판매금원화        = (export_declaration_amount - transport_fee_usd) × exchange_rate
정산판매금원화    = 판매금원화 - cost_total
판매마진          = 정산판매금원화 - purchase_price          ← 매도비 제외한 순 매입가 기준
부가세마진        = purchase_price × 0.09                    ← Python의 sales_margin × 0.1 아님
총마진            = 판매마진 + 부가세마진
정산액(비율)      = 총마진 × (settlement_ratio / 100)
정산액(건당)      = per_unit_amount
실지급액          = 정산액 - other_deduction
```

`cost_total` = `cost_deregistration + cost_license + cost_towing + cost_carry + cost_shoring + cost_insurance + cost_transfer + cost_extra1 + cost_extra2` (9개 항목 합, computed).

## 뷰 경로 규칙
- **Volt 컴포넌트**: `resources/views/livewire/erp/...` 하위 (자동 인식)
- **컨트롤러 뷰**: `resources/views/admin/...` 하위 (관리자 대시보드 등)
- 예: 차량 목록 → `resources/views/livewire/erp/vehicles/index.blade.php`

## 핵심 주의사항 (재구현 시 반드시)

1. **Volt 컴포넌트는 `#[Layout('components.layouts.app')]` 필수** — 누락 시 `No hint path defined for [layouts]` 500. auth 페이지는 `components.layouts.auth`
2. **사이드바: 데스크탑(md+)은 `sticky top-0 h-screen`, 모바일(<768px)은 fixed drawer + backdrop** — Alpine `isMobile` 분기 (`SKILLS.md §6` 참조)
3. **`<x-layouts.app>` 슬롯 내 `@php` 블록에서 `use` 문 금지** — Blade가 슬롯을 if/elseif 컨텍스트로 wrap해서 파싱 에러. FQN 직접 호출 (`\App\Models\Vehicle::query()`)
4. **`.md` 파일은 dev 전용** — dev → master/demo 머지 시 `.md` 제외 (cherry-pick으로 코드만)
5. **커밋 전 `vendor/bin/pint --dirty` 필수**
6. **뷰 캐시 문제 시** `php artisan view:clear && php artisan cache:clear`
7. **Tailwind v4 + Vite** — 새 유틸 클래스 미반영 시 `npm run build` 또는 `npm run dev`
8. **차량 진행상태는 computed property** — DB 저장 X. `Vehicle::progress_status` 접근 시마다 우선순위 평가
9. **vehicles 비용 컬럼은 9개 분리** — Python의 `other_costs(JSON)` 폐기. 합계는 computed (`cost_total`)
10. **부가세마진 = `purchase_price × 0.09`** — Python ERP의 `sales_margin × 0.1`과 다름. 엑셀 실측 검증된 공식이므로 변경 금지
11. **판매금원화 산정은 면장금액 기반** — `(export_declaration_amount - transport_fee_usd) × exchange_rate`. `sale_price` 직접 환산 아님

## Git 브랜치 전략
- `dev` — 작업 브랜치 (기본)
- `master` — 프로덕션
- `demo` — 데모용 (필요 시)
- 머지 규칙: dev → 다른 브랜치 push 시 `.md` 파일 **제외**

**Claude 작업 규칙 (1인 개발 컨텍스트)**:
- **별도 feature 브랜치 만들지 않음** — 모든 변경은 `dev`에 직접 커밋·푸시
- PR 만들지 않음 (사용자가 명시적으로 "PR 만들어줘"라고 한 경우만 예외)
- 커밋 단위로 변경을 정리해서 추적성 확보 (한 커밋 = 한 논리적 변경)

## ⚠️ APP_KEY 영구 손실 경고 (큐 2.5번 C8)

**`php artisan key:generate` 사용 시 RRN(주민등록번호) 전체 영구 손실 위험**

- `nice_reg_owner_rrn` 컬럼은 `APP_KEY`로 암호화 저장(큐 7번 완료). 키가 바뀌면 **모든 RRN 데이터 복호화 불가**, DB 백업으로도 복구 안 됨.
- **집/회사 양쪽 PC**: 한 PC에서 발급한 APP_KEY 값을 1Password 등에 백업 → 다른 PC `.env`에 동일 값 직접 입력. `key:generate` 절대 실행 금지.
- **운영 배포 시**: 최초 1회만 `key:generate` → 즉시 백업 → 재배포 시 동일 키 유지.
- **DB 백업 시**: APP_KEY도 별도 위치에 함께 백업 (분리 보관, 한쪽 유실해도 다른 쪽 보존).
- 상세 가이드 + 사고 복구 절차: `docs/operations/key-rotation.md`

## 새 PC 세팅
```bash
# 1. PHP 확장 활성화 — XAMPP php.ini (C:\xampp\php\php.ini)에서 주석 제거 필수
#    extension=gd        # PhpSpreadsheet (Excel CIPL) 의존성
#    extension=zip       # PhpSpreadsheet · barryvdh/laravel-dompdf 의존성
#    (Apache 사용 시 Apache 재시작)

git clone https://github.com/wlsdud10075-JIN/car-erp.git
cd car-erp
composer install && npm install
cp .env.example .env && php artisan key:generate
# .env DB_DATABASE=car_erp 확인
# MySQL: CREATE DATABASE car_erp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
php artisan migrate && php artisan db:seed
npm run build
php artisan serve --port=8001
# 다른 PC 접속: --host=0.0.0.0
# 테스트 계정: admin@car-erp.test / password (시스템관리자, permission=super)
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

### 가용성 확인
```powershell
Get-Command codex -ErrorAction SilentlyContinue   # C:\Users\User\AppData\Roaming\npm\codex.ps1
Get-Command gemini -ErrorAction SilentlyContinue  # C:\Users\User\AppData\Roaming\npm\gemini.ps1
```

### Codex 호출
```powershell
# auth.json 에 ChatGPT 계정 인증 저장됨 (C:\Users\User\.codex\auth.json)
# config: model = "gpt-5.5" (C:\Users\User\.codex\config.toml)
# ⚠️ gpt-4o-mini 등 일반 OpenAI 모델은 ChatGPT 계정으로 지원 안 됨 → 반드시 기본 모델(gpt-5.5) 사용
cmd /c 'echo. | codex exec "프롬프트" 2>&1'
```

### Gemini 호출
```powershell
# GEMINI_API_KEY + GEMINI_CLI_TRUST_WORKSPACE=true → ~/.claude/settings.json env 블록에 등록됨
# 세션 시작 시 자동 주입 → WSL 경유 불필요
gemini -p "프롬프트" --approval-mode yolo 2>&1
```

### 크로스 체크 실행 패턴
```powershell
# Codex + Gemini 병렬 호출 후 Claude가 종합
$codexResult = cmd /c 'echo. | codex exec "질문" 2>&1'
$geminiResult = gemini -p "질문" --approval-mode yolo 2>&1
```

## 기능 토글 (Setting 모델 — 구현 예정)
- `heyman_channel_enabled` — 헤이맨 채널 on/off
- `carpul_channel_enabled` — 카풀 채널 on/off
- 변경 권한: `super`만 (`canToggleFeatures()`)

## 외부 연동

| 연동 | 용도 | 구현 위치 | 우선순위 |
|---|---|---|---|
| **NICE API** | 차량번호 → 차량정보 24개 필드 자동조회 (Registration 12 + Spec 12) | `app/Services/NiceApiService.php` | 🟡 **보류** — API 키 발급 후 진행 (현재 스텁만, 수동 입력 가능) |
| **포워딩사 메일** | 수출통관 완료 시 자동 발송 (차량정보 + 수출신고서) | `app/Mail/ForwardingNoticeMail.php` + Listener | 🟡 **보류** — 운영 SMTP 확정 시 NICE와 함께 진행 |
| DHL API | 운송장 자동 생성 | (1단계 스코프 외 — 차후 검토) | - |

**NICE API 핵심 규칙**:
- 차량번호 입력 후 트리거 (버튼 클릭 또는 `wire:model.live` blur)
- API 실패해도 **모든 필드는 수동 입력 가능 유지** (필수 fallback)
- 캐싱: 동일 차량번호 5분 정도 캐시 (불필요한 외부 호출 방지)

**포워딩 메일 트리거**:
- `is_export_cleared = true` 저장 시 + `forwarding_email_sent = false` AND `forwarding_company_id` 존재
- queue 사용 (DB job table) — 발송 실패가 저장 트랜잭션 영향 X
- 발송 후 `forwarding_email_sent = true` 자동 갱신 (재발송 방지)

**배포**: AWS Lightsail 권장. 상세 패턴은 `SKILLS.md §14` 참조.

상세 구현 패턴은 `SKILLS.md §14` 참조.

## 개발 진행 상황

| 단계 | 내용 | 상태 |
|---|---|---|
| 0 | Laravel 12 + Livewire/Volt/Flux/Tailwind 셋업 | ✅ 완료 |
| 1 | 인증 + 권한 구조 (User permission/role + 미들웨어 5종 + 사이드바 골격) | ✅ 완료 |
| 2 | DB 마이그레이션 11개 (countries → vehicles → settlements) | ✅ 완료 |
| 3 | Seeder (테스트 더미) | ✅ 완료 |
| 4 | 차량 목록 + 등록/수정 (탭형 슬라이드 패널 + NICE API + 파일업로드 + 바이어→컨사이니 연동) | ✅ 완료 |
| 5 | 바이어 / 컨사이니 / 적립금 | ✅ 완료 |
| 6 | 포워딩사 / 영업담당자 / 캐시플로우 | ✅ 완료 |
| 사용자관리 | /admin/users (9단계 선행) | ✅ 완료 |
| 7 | 정산 | ✅ 완료 |
| 8 | ERP 대시보드 KPI | ✅ 완료 |
| 8.5 | 운영 안정성 1차: 설정 Layout / 차량 validation / 라우트 권한 (PR #2) | ✅ 완료 |
| 8.6 | 진행상태 캐시 컬럼 + 잔금 트리거 (성능) | ✅ 완료 |
| 8.7 | 파일 교체/삭제 정리 (orphan 방지 + UI 삭제 버튼) | ✅ 완료 |
| 9 | **채권관리 대시보드 + 관리자 대시보드 분리** (상세는 아래) | ✅ 완료 |
| 9.5 | 대시보드 카운트 ↔ vehicles 목록 정합성 (action 파라미터 패턴 — `SKILLS.md §9` 참조) | ✅ 완료 |
| 9.6 | 공용 UI 패턴 1차 — my-crm 기능 이식 (브랜드 텍스트 설정·관리자 대시보드 위젯 토글 패널·perPage 드롭다운 8개 페이지·Flux 사이드바 collapse 토글) | ✅ 완료 |
| 10 | **모바일 반응형 + my-crm UI 풀-이식** — 10-A 사이드바 자체 Alpine 교체(220↔48 + drawer 3-state) / 10-B 디자인 시스템 풀-이식 / 10-C 페어 렌더 (8개 페이지 중 receivables만 신규 추가, 7개는 기 적용) / 10-D 슬라이드 패널 모바일 분기 (8개 모두 기 적용 — 무변경 검증) | ✅ 완료 |
| 11 | **서류 자동 생성 5종 PDF + 2종 Excel** — 11-A dompdf 셋업 / 11-B 한국어 PDF 3종 (말소·등록증재발급·양도증명서) + Noto Sans KR 서브셋 / 11-C 영문 PDF 2종 (Proforma Invoice·Sales Contract) / 11-D Excel CIPL 2종 (RO/con) maatwebsite/excel + 템플릿 추출 / 11-E 차량 편집 패널 "서류" 탭 + 채널 분기 (수출만 영문서류 노출) | ✅ 완료 |
| 12 | 포워딩사 이메일 자동 발송 (Mailable + Vehicle saving 리스너) | 🟡 보류 (SMTP 확정 후) |
| - | NICE API 실연동 (현재 스텁) | 🟡 보류 (role/대시보드 완성 후) |
| 13 | AWS Lightsail 배포 | ⏳ |

## ⏭️ 다음 세션 작업 순서 (2026-05-17 종료 시점 — 큐 20 전체 완료 후 갱신)

> 세션 시작 시: `CLAUDE.md, CLAUDE_1.md, SKILLS.md, role기획보안_수정.md 읽고 회의록 docs/meetings/2026-05-17-purchase-sale-finance-gate.md 큐 20 완료 노트 확인해줘.`
> 기획 기준 문서: `role기획보안_수정.md` (프로젝트 루트)
> 핵심 회의록:
> - `docs/meetings/2026-05-14-3way-workflow-policy.md` (v5.1 큐 분할 — Phase 1~6)
> - `docs/meetings/2026-05-16-finance-gate-roundtable.md` (큐 19-F 자금이체 게이트)
> - `docs/meetings/2026-05-17-purchase-sale-finance-gate.md` (큐 20 매입·판매 전 흐름 게이트 + 큐 20-A~D 완료 노트)

### 🖥️ 다른 PC에서 작업 재개 절차 (필수)

```powershell
# 1. dev 브랜치 최신화
git checkout dev && git pull origin dev

# 2. 마이그 누락 확인 (큐 19-K/L 사고 재발 방지 — 회의록 부록 A § 노트북 발견 누락 참조)
php artisan migrate:status
php artisan migrate     # Pending 있으면 실행

# 3. 의존성 + 자산 (composer.lock / package-lock.json 변경 있으면)
composer install
npm install
npm run build

# 4. 캐시 클리어
php artisan view:clear && php artisan cache:clear

# 5. 자동 테스트 회귀 (현재 기대치: 246 passed)
php artisan test

# 6. 개발 서버
php artisan serve --port=8001
```

### 완료된 큐 (2026-05-17 기준)

| 큐 | 작업 | 완료 커밋 |
|---|---|---|
| 1·2·2.5·7 | 일반사용자 대시보드·파이프라인·Critical 8건·RRN 암호화 | (2026-05-12 완료) |
| **5** | 업무 대시보드 [담당자별]↔[역할별] 토글 | `cdd01e3` |
| **6 잔여** | 흐름도 reason tooltip + next-step 동선 | `a0bcd76` |
| **9** | High 도메인 안전 H1·H2·H7 | `b11c700` |
| **10** | 정산·채권 무결성 H3·H4·H5·H6 | `90d8724` |
| **11 (1~4)** | N+1 + forceDelete 백업 + db:backup + audit_logs 기록 | `b8b7023·a96f936·d0065fa·9fa6190` |
| **14 (1~4-4)** | role 재설계 + approval_requests + G2 게이트 + audit 2-actor | `39066e5` ~ `ffbdd66` (8 커밋) |
| **16 (1~4)** | G6 채널 단순화 — sales_channel enum + 5컬럼 drop + 시드 재생성 | `36b8a28·fc41fb2·b76410f·2f44f4b` |
| **17** | 폐기 컨셉 제거 (11단계 → 10단계) | `376837d` |
| **18** | 차량/바이어/컨사이니/포워딩사 close confirm 모달 | `83f6a8c` |
| **19 (A~L)** | 자금이체 5상태 게이트 + 거부·void 분기 + 정합 보강 (20+ 커밋) | `a5ed674` ~ `8cbaef3` |
| SKILLS §13 | 분모 단일 출처 박스 + admin 대시보드 미수율 분모 비대칭 fix | `7525219` |
| 19-F H-3 UI 보강 | approvals/transfers catch 모달 닫힘 + notify 토스트 글로벌 추출 | `078d8f9` |
| **20-A** | 마이그 3건 + 모델 fillable·cast·MASKED_COLUMNS | `ccf4865` |
| **20-B** | PaymentConfirmationService + Vehicle 분자 A안 필터 + canConfirmFinance alias + InterVehicleTransferService confirmed_at 동기화 | `fccb297` |
| **20-C** | 재무 처리 UI (3 탭 + 매입처 계좌 입력 + 잔금 row 색 분기 + 사이드바 배지 합산) | `063f23c` |
| **20-D** | 회계 무결성 lock (FinalPayment/PBP::updating·deleting) + paid Settlement snapshot 보강 + 신규 테스트 15건 | `833095c` |
| 20 후속 fix | finalPayments validation이 자금 이체 음수 페어 잘못 차단하던 버그 fix | `35beac8` |

**자동 테스트 현황**: **246 passed** (큐 20-D 완료 시점, +15 신규). RefreshDatabase 트레이트라 마이그 누락에도 통과 — 운영 DB 상태와 별개임 주의.

**큐 19-F 부록 A 수동 회귀**: 노트북 환경에서 B~G + H-3 통과(2026-05-17). 19-K/L 마이그 누락 1건 발견·fix. H-2 audit_logs row 누락은 별건 3 묶음으로 미룸. H-1 production 빌드 통과.

### 다음 추천 순서 — 큐 20 전체 완료 후

| 순서 | 큐 | 작업 | 공수 | 시작 명령어 |
|---|---|---|---|---|
| **0 (선택)** | 큐 20 통합 브라우저 회귀 | 깨끗한 차량 1대로 Draft→Posted 흐름 한 번 클릭 검증 (영업 잔금 입력 → 재무 확정 → 미수 감소 → emerald ✓ "확정" 표시) | 15분 | `큐 20 통합 브라우저 회귀 진행 — TEST-19F-E 등 새 차량으로 시나리오 ⓑ 핵심 흐름 검증` |
| **1** | 큐 9 확장 | G1 50% 룰 B/L 잠금 + rule_version v3 | 10~14h | `큐 9 확장 진행 — G1 50% 룰 + B/L 잠금` |
| **2** | 큐 10 확장 | G3 선적전/후/디파짓 미수 분류 | 12~15h | `큐 10 확장 진행 — G3 미수 분류` |
| **3** | 큐 15 | G5 재고관리 (NICE 후 권장) | 4~6h | (NICE API 확정 후) |
| **4** | 큐 8 / 12 | NICE API 실연동 / 포워딩 SMTP | - | (외부 키·SMTP 확정 후) |
| **5** | 별건 1 | G4 알림톡 | - | (워크플로우 완성 후) |
| **6** | 별건 2 | G7 동시 편집 락 | 16~32h | (인프라 결정 후) |
| **7** | 별건 3 | 사이드바 재구성 + **로그 화면군 일괄 노출** + audit_logs UI 신설 (+ 19-F 5상태 머신 recordEvent backfill) | 5~7h | `별건 3 진행 — 사이드바 재구성 + 모든 로그 화면 노출 + audit_logs 누락 backfill` |
| **8** | 큐 13 | AWS Lightsail 배포 | - | (모든 큐 완료 후 최종) |

### 큐 20 통합 브라우저 회귀 — 미진행 (다음 세션 첫 작업 권장)

큐 20-C UI 변경(3 탭, 매입처 계좌 입력, 잔금 row 색 분기)이 production에서 실제 동작하는지 한 번 클릭으로 검증 권장. 핵심 시나리오 ⓑ:
- TEST-19F-E (새로 생성) 또는 깨끗한 차량 1대 골라서
- 영업: 판매 잔금 5천만 입력 → 저장 → 미수 변화 없음 (Draft) + amber ⏳ "대기" 표시 확인
- 재무: `/erp/transfers` 판매 잔금 탭 → 재무 처리 완료
- 영업: 미수금 감소 확인 + emerald ✓ "확정" 표시 확인
- 확정 row 금액 변경 시도 → lock 토스트 노출 (회계 무결성)
- 매입처 계좌 입력 → DB raw 암호화 확인

**fix 1건 — 미해결 가능성**: 2026-05-17 마지막 세션에서 `finalPayments validation이 자금 이체 음수 페어 잘못 차단` 버그를 `35beac8`로 fix했지만 브라우저 재시도는 못 함. 통합 회귀에서 동시 검증.

### 큐 20 완료 요약 (회의 GO 확정 — P2 정석 패키지 구현 완료)

2026-05-16 사용자 4건 확정 → 2026-05-17 4 큐 구현 완료:
- **분자 정의 A안** ✅ — `confirmed_at IS NOT NULL` 필터 (Vehicle::getSale/PurchaseUnpaidAmountAttribute)
- **19-F-D 선행** ✅ — 부록 A B~G + H-3 통과
- **전체 통합** ✅ — 매입+판매 동시 도입 (PaymentConfirmationService 단일)
- **별도 PaymentConfirmationService** ✅ — saving 훅 미사용, DB::transaction + 4 가드

회의록 `docs/meetings/2026-05-17-purchase-sale-finance-gate.md` 큐 20-A~D 완료 노트 참조. 실측 공수 ~4h (회의록 예상 14~16h보다 단축).

### 별건 3 — 로그 화면 사이드바 노출 묶음 처리 (사용자 결정)

**원칙**: 로그 화면 사이드바 노출은 개별 화면 완성 시마다 추가하지 않고, 모든 ERP 화면 완성 후 별건 3(사이드바 재구성)에서 한꺼번에 처리. UI 일관성·사용자 메뉴 학습 비용 보존.

**묶음 대상**:
- `/admin/document-access-logs` (커밋 `2f05f89`로 화면·라우트 완성, 사이드바만 미노출)
- `audit_logs` UI 신설 (큐 11-4로 기록은 있음, UI 미구현)
- 향후 추가될 로그성 화면 전체

**진행 시점**: 큐 14·15·16·18·19·20 + role별 화면 + 별건 1·2 모두 완료 후. admin/super만 노출되는 "로그" 메뉴 그룹으로 묶기.

### 단계 9 — 채권관리 + 관리자 대시보드 화면 분리

**핵심 결정사항** (`채권관리대시보드_설계분석.md` 참조):

1. **화면 분리**:
   - `/admin/dashboard` — **매출/KPI 전용** (관리자 시각의 비즈니스 지표)
   - `/erp/receivables` — **미수금/회수 전용** (채권 관리자 시각의 액션)
   - 두 화면 완전 분리. 관리자 대시보드에 채권 위젯 추가 X (필요 시 채권 페이지 링크만).
2. **권한**: 채권관리는 **현재 admin만 접근**.
   - 추후 `receivable` role / 미들웨어로 확장 가능 → 라우트 + 컴포넌트 권한 체크에 **TODO 주석 명시**할 것
3. **담보금 = 선수금** (a안). `advance_payment1/2` 컬럼 그대로 사용. 별도 컬럼 신설 X.
4. **위험도**:
   - 코드 식별자: `safe` / `caution` / `danger` / `critical` / `none` (영문)
   - UI 라벨: 안전 / 주의 / 위험 / 심각 (한국어)
5. **회수 이력 ↔ final_payments 양방향 미러링**:
   - `receivable_histories.final_payment_id` (nullable FK)로 링크
   - 회수 이력 추가(method=deposit) 시 final_payment 자동 생성·링크
   - 회수 이력 수정/삭제 → 링크된 final_payment 동기화

**진행 순서** (모두 완료):
| 단계 | 내용 | 상태 |
|---|---|---|
| 9-A | 마이그레이션 (receivable_histories + vehicles 컬럼 8개) | ✅ |
| 9-B | Vehicle 모델 + ReceivableHistory 모델 + 미러링 로직 | ✅ |
| 9-C | `/erp/receivables` 리스트 페이지 (수출/카풀/헤이맨 채널 탭) | ✅ |
| 9-D | 회수 이력 슬라이드 패널 + CRUD | ✅ |
| 9-E | 차량 편집 패널 — 카풀/헤이맨 계산서 필드 | ✅ |
| 9-F | 관리자 대시보드 — 매출/KPI 전용으로 정리 | ✅ |

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
  - 통관: 수출통관·선적 관련 처리 필요 항목
  - 정산: 입금·출금·정산 관련 처리 필요 항목
  - 관리: 보류
