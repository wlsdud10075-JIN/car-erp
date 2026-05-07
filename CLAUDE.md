# 중고차 수출 ERP (car-erp)

> ⚠️ **세션 시작 시 필수 로드 순서** (반드시 이 순서로 모두 읽은 후 작업 시작):
> 1. **이 파일** (`CLAUDE.md`) — 프로젝트 도메인/권한/환경
> 2. **`CLAUDE_1.md`** — LLM 코딩 가이드라인 (실수 방지 규칙)
> 3. **`SKILLS.md`** — 구현 패턴 / 재발 버그 / UI 디자인 시스템
>
> 아래 import도 함께 자동 로드됨:
> @CLAUDE_1.md
> @SKILLS.md

SSANCAR LTD.의 중고차 해외수출 전 흐름(매입 → 말소 → 판매 → 수출통관 → 선적(B/L) → DHL → 거래완료)을 관리하는 Laravel ERP.

> 신규 설계 배경·엑셀 분석은 `Desktop/CAR_ERP/NEW_ERP.md` 참조.

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

### 차량 진행상태 11단계 (computed property — DB 컬럼 X)
우선순위 높음 → 낮음으로 평가, 첫 번째 매칭 반환:
1. `is_disposed = true` → **`폐기`**
2. `dhl_request = true` → **`거래완료`**
3. `bl_document` 존재 → **`선적완료`**
4. `bl_loading_location` 입력 → **`선적중`**
5. `export_declaration_document` 존재 → **`수출통관완료`**
6. `export_buyer_id` + `shipping_date` 입력 → **`수출통관중`**
7. `sale_price > 0` AND 판매미입금 ≤ 0 → **`판매완료`**
8. `sale_price > 0` → **`판매중`**
9. `is_deregistered = true` AND `deregistration_document` 존재 → **`말소완료`**
10. `purchase_price > 0` AND 매입미지급 ≤ 0 → **`매입완료`**
11. 기본값 → **`매입중`**

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

## 새 PC 세팅
```bash
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

## 기능 토글 (Setting 모델 — 구현 예정)
- `heyman_channel_enabled` — 헤이맨 채널 on/off
- `carpul_channel_enabled` — 카풀 채널 on/off
- 변경 권한: `super`만 (`canToggleFeatures()`)

## 외부 연동 (NEW_ERP.md §12)

| 연동 | 용도 | 구현 위치 | 우선순위 |
|---|---|---|---|
| **NICE API** | 차량번호 → 차량정보 24개 필드 자동조회 (Registration 12 + Spec 12) | `app/Services/NiceApiService.php` | ★ 필수 |
| **포워딩사 메일** | 수출통관 완료 시 자동 발송 (차량정보 + 수출신고서) | `app/Mail/ForwardingNoticeMail.php` + Listener | 중 |
| DHL API | 운송장 자동 생성 | (1단계 스코프 외 — 차후 검토) | - |

**NICE API 핵심 규칙**:
- 차량번호 입력 후 트리거 (버튼 클릭 또는 `wire:model.live` blur)
- API 실패해도 **모든 필드는 수동 입력 가능 유지** (필수 fallback)
- 캐싱: 동일 차량번호 5분 정도 캐시 (불필요한 외부 호출 방지)

**포워딩 메일 트리거**:
- `is_export_cleared = true` 저장 시 + `forwarding_email_sent = false` AND `forwarding_company_id` 존재
- queue 사용 (DB job table) — 발송 실패가 저장 트랜잭션 영향 X
- 발송 후 `forwarding_email_sent = true` 자동 갱신 (재발송 방지)

**배포**: AWS Lightsail 권장 — Python ERP와 동일 환경, 인스턴스 병행 운영 가능. 마이그레이션 완료 후 Python ERP 인스턴스 종료.

상세 구현 패턴은 `SKILLS.md §14` 참조.

## 개발 진행 상황

| 단계 | 내용 | 상태 |
|---|---|---|
| 0 | Laravel 12 + Livewire/Volt/Flux/Tailwind 셋업 | ✅ 완료 |
| 1 | 인증 + 권한 구조 (User permission/role + 미들웨어 5종 + 사이드바 골격) | ✅ 완료 |
| 2 | DB 마이그레이션 11개 (countries → vehicles → settlements) | ✅ 완료 |
| 3 | Seeder (테스트 더미) | ✅ 완료 |
| 4 | 차량 목록 + 등록/수정 (탭형 슬라이드 패널 + NICE API 연동) | ⏳ |
| 5 | 바이어 / 컨사이니 / 적립금 | ✅ 완료 |
| 6 | 포워딩사 / 영업담당자 / 캐시플로우 | ✅ 완료 |
| 7 | 정산 | ⏳ |
| 8 | ERP 대시보드 KPI | ⏳ |
| 9 | 관리자 대시보드 / 사용자 관리 | ⏳ |
| 10 | 모바일 반응형 | ⏳ |
| 11 | 서류 자동 생성 (말소신청서/계약서/Invoice/CIPL) | ⏳ |
| 12 | 포워딩사 이메일 자동 발송 (Mailable + Vehicle saving 리스너) | ⏳ |
| 13 | AWS Lightsail 배포 (Python ERP와 병행 운영 후 전환) | ⏳ |

> 상세 설계 / 도메인 분석은 `Desktop/CAR_ERP/NEW_ERP.md` 참조.
