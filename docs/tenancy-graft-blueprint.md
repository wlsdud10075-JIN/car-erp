# 테넌시 이식 청사진 & 견적 (car-erp 멀티테넌트 SaaS화)

> 작성 2026-07-22. 목적: 별도 fork(`Project-SaaS`)를 되살리는 대신, **현재 car-erp에 테넌시 층을 순방향 이식(방향 B)**하기 위한 검증된 레시피 + 실측 견적. 첫 SaaS 고객이 가까워질 때 이 문서대로 착수한다. Project-SaaS repo 코드는 썩어도 이 레시피는 안 썩는다.
>
> 결정 맥락: 메모리 `project_tenancy_saas_direction`. 제품 목표 = 공유서버 멀티테넌트(팔 것). 현재 = silo(회사별 서버/DB/APP_KEY). Project-SaaS(stancl multi-DB, 완성·advisor검토) = 404커밋 stale → 되살리지 말고 **청사진으로만** 쓴다.

## 1. 왜 이식이 생각보다 싼가 (2026-07-22 실측)

Project-SaaS 테넌시층을 뜯어 현재 car-erp와 대조한 결과:

| 항목 | 실측 | 의미 |
|---|---|---|
| **업무 모델 수정** | **0줄** (Vehicle·Buyer 등 `$connection`/`BelongsToTenant` 없음) | stancl multi-DB가 **기본 커넥션만 스위칭** → 업무모델은 테넌트DB로 자동. **404커밋 업무로직이 무수정으로 돎** |
| **크로스DB 쿼리** | **0** (`DB::connection(`·명시 커넥션 없음) | 중앙↔테넌트 경계 넘는 쿼리 없음 → 조용한 오류 위험 낮음 |
| **신규 마이그 39개**(2026-06-18~) | **전부 업무테이블/users** | 분류 자명: 전부 `migrations/tenant/`. 중앙행 0개 |
| **테넌시 커스텀 코드** | ~**6파일 ~500줄** | config/tenancy.php(211)·Tenant(23)·Operator(23)·TenantConfigBootstrapper(68)·TenantEncryptionBootstrapper(68)·InitializeTenancy·TenancyServiceProvider·TenantProvision. **Project-SaaS에서 복사** |

→ **핵심**: 방향 A(404커밋을 테넌시 베이스로 역포팅)와 달리, 방향 B는 **업무로직을 안 옮긴다**. 테넌시는 인프라 층으로 얹히고, 검증된 업무코드는 제자리에 둔 채 테넌트DB로 자동 라우팅된다.

## 2. 아키텍처 (Project-SaaS 확정본)

- **stancl/tenancy 3.10, multi-database 모드** — 테넌트마다 물리 DB 분리.
- **중앙 DB**: `tenants`, `domains`, `operators`(플랫폼 super), `tenants.encryption_key_wrapped`(테넌트별 암호화키 래핑저장). = 7 마이그.
- **테넌트 DB**: `users`(테넌트별 격리) + 전 업무테이블(countries·buyers·vehicles·settlements…). = 80+ 마이그.
- **식별**: 도메인 기반(`InitializeTenancy` 미들웨어). 회사 도메인 → 테넌트. 센트럴 도메인(localhost)은 `X-Tenant` 헤더. **미들웨어 우선순위를 StartSession보다 위로**(세션이 테넌트DB로 새는 것 방지 — ADV-2).
- **테넌트별 암호화키**: `TenantEncryptionBootstrapper`가 Encrypter 재바인딩 → **RRN이 테넌트별 키로** 격리(공유서버에서 필수). 현 silo의 "서버별 APP_KEY"를 공유서버로 이관하는 열쇠.
- **설정 오버라이드**: `TenantConfigBootstrapper` — 테넌트 settings(DB) → config(). 현재 매핑 3개(`company.template_set`·`nice.provide_url`·`nice.provide_token`). ⚠️ mail/S3는 resolved-instance 캐시라 `clearResolvedInstance` 필요(실호출 배선 시).

## 3. 이식 레시피 (단계별)

**Phase 1 — 테넌시 스캐폴드 (payload 복사)**
- `composer require stancl/tenancy:^3.10`.
- Project-SaaS에서 복사: `config/tenancy.php`, `app/Providers/TenancyServiceProvider.php`, `app/Http/Middleware/InitializeTenancy.php`, `app/Models/{Tenant,Operator}.php`, `app/Tenancy/{TenantConfig,TenantEncryption}Bootstrapper.php`.
- 중앙 마이그 추가: tenants·domains·operators·encryption_key_wrapped.
- ⚠️ 이식 시점의 config 매핑을 **재점검**: 그간 늘어난 설정(락수치 `lock_threshold_*`·grace `receivable_grace_days_*`·알림톡 게이트 등)은 대부분 `Setting::get()` **런타임 조회**라 테넌트DB에서 자연히 읽힘 → 부트스트래퍼 추가 불필요. **config()로 굳는 값만** CONFIG_MAP에 추가.

**Phase 2 — 마이그레이션 재배치 (기계적, 신중)**
- 현재 `database/migrations/*` 중 **업무·users 전부 → `database/migrations/tenant/`**. 중앙엔 tenants/domains/operators/encryption만.
- FK 순서는 테넌트 세트 내에서 함께 도므로 보존됨(현재 순서 유지). 39개 신규분도 전부 tenant.
- 검증: 빈 테넌트 프로비저닝 → 전 마이그 clean 통과.

**Phase 3 — 라우트/세션 배선**
- `InitializeTenancy`를 web(+api) 그룹에, `central_domains` 설정. 세션·큐 테넌트 안전성(미들웨어 우선순위).

**Phase 4 — 테스트 스위트 테넌트화**
- `TestCase`가 테넌트 컨텍스트에서 돌게(스탠클 테스트 헬퍼). 단일DB 가정 테스트 소수 보정. 현 639 통과 재검증.

**Phase 5 — 프로비저닝 + heyman 실데이터 이관 (go-live, 위험구간)**
- `TenantProvision` 명령 + `tenants:migrate-heyman` 런북(Project-SaaS에 존재).
- **순서 = 카나리아**: ssancar·karaba(실데이터 X) 먼저 프로비저닝·컷오버 → 검증 → heyman(실데이터) 이관. 백업 필수, 롤백 계획, 업무시간 외.

**Phase 6 — board 테넌트화 (별도)**
- board는 row-level(`TenantScope`/`UserTenantScope`) 방식. 연동 A/B를 테넌트 인지 라우팅으로.

## 4. 견적 (1인 집중 기준)

| Phase | 작업 | 대략 |
|---|---|---|
| 1 | 스캐폴드 복사·배선 | 2~3일 |
| 2 | 마이그 재배치·검증 | 1~2일 |
| 3 | 라우트/세션 | 1일 |
| 4 | 테스트 테넌트화 | 2~3일 |
| 5 | 프로비저닝+heyman 이관+컷오버 | 2~3일 + go-live 창 |
| **car-erp 소계** | | **≈ 8~12 작업일 (2~2.5주)** |
| 6 | board 테넌트화 | +≈ 1주 |

> months 아님. **업무로직 무수정**이라 이 규모. 진짜 비용·위험은 **Phase 5 컷오버**(라이브 heyman DB 이관 + 도메인 라우팅 전환)에 집중 — 여기만 신중히.

## 5. 위험 & 함정

- **Phase 5 컷오버** = 유일한 고위험. heyman 실데이터를 테넌트DB로 이관 + 도메인 resolve 전환. → 백업·롤백·업무시간외·카나리아(빈 회사 먼저).
- **세션 누출**: InitializeTenancy가 StartSession보다 먼저 안 돌면 세션이 테넌트DB로 샘 → 우선순위 명시(ADV-2).
- **mail/S3 캐시**: config override만으론 부족, resolved-instance clear 필요.
- **APP_KEY/RRN**: 테넌트별 키로 이관 시 **기존 heyman RRN은 heyman의 현재 키로 복호화 후 테넌트키로 재암호화**해야 함 — 이관 스크립트가 이걸 처리하는지 반드시 검증(안 하면 RRN 전량 손실). ⚠️ APP_KEY 경고(CLAUDE.md) 동급 위험.
- **stale 청사진**: Project-SaaS 코드 자체는 404커밋 옛 버전 기준 → 복사할 때 현재 car-erp 시그니처에 맞게 조정.

## 6. 그때까지 "테넌트화 준비" 위생 (지금부터 car-erp 개발 시)

이걸 지키면 이식이 계속 싸게 유지됨:
- **크로스DB join 금지** — 한 쿼리가 "중앙(tenants/operators)"과 "업무"를 조인하지 않게. (지금 0건, 유지)
- **회사 분기는 데이터(설정)로** — `Setting::get()` 런타임 조회 패턴 유지(오늘 template_set 작업이 그 예). 하드코딩·`.env` 직참조 지양.
- **명시적 커넥션 하드코딩 금지** — `DB::connection('mysql')` 등으로 특정 커넥션 박지 말 것(기본 커넥션 스위칭에 맡김).
- 새 테이블 = 기본이 테넌트행(업무). 플랫폼 전역 데이터만 중앙.

## 7. Project-SaaS repo 처리

- **더 이상 거기서 개발하지 않는다**(fork 세금 중단). car-erp = 단일 진실원천.
- repo는 **이 청사진의 참조 구현으로 보존**(삭제 X). 코드가 썩어도 stancl 세팅·부트스트래퍼·프로비저닝·이관 런북의 실물 예시로 가치.
- 착수 시 이 문서 + Project-SaaS의 해당 파일을 나란히 놓고 현재 car-erp에 맞춰 이식.

---

**한 줄**: 방향 B(현 car-erp에 테넌시 이식)는 업무로직 무수정 덕에 **≈2~2.5주(car-erp) + 1주(board)**, 유일한 고위험은 heyman 데이터 컷오버(RRN 재암호화 포함). 첫 고객 임박 시 이 문서대로 착수하고, 그전까지는 §6 위생만 지키면 계속 싸게 유지된다.
