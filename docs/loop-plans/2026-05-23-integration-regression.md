# Segment Integration — 운영 통합 회귀 박제

> /loop 플랜 — A안 (박제만, production 코드 무수정)
> 작성: 2026-05-23
> 목적: 회의확장씬 + 새회의 + 한글화 + 재고관리 + KPI 분리까지 누적된 기능들이 앞으로도 깨지지 않게 PHPUnit 회귀 테스트로 박제.

## 1. 목표 (Success Criteria)

이번 세션까지 누적 구현된 운영 핵심 시나리오(매입→거래완료 한 흐름 + 1차·2차 정산 + 환차익·손 + 발생/회수 KPI 분리 + 권한 scoping)를 **앞으로도 깨지지 않게** PHPUnit 통합 회귀로 박제.

- 산출물: `tests/Feature/IntegrationRegressionTest.php` (신규 1 파일)
- 케이스: §7 목록 10건
- 종료 시 상태: **모두 초록 (passing)** — 회귀 모드라 빨강이면 즉시 종료 + 로그
- 추후 사용자와 함께 보면서 시나리오 확장·수정

## 2. 사전 결정사항 (Decisions)

| 항목 | 값 |
|---|---|
| 테스트 프레임워크 | PHPUnit (프로젝트 기본 — Tests\TestCase 상속) |
| RefreshDatabase | 사용 |
| Pragma | `DB::statement('PRAGMA foreign_keys = OFF')` (SQLite 호환) |
| 참고 패턴 | `tests/Feature/SettlementKrwBreakdownTest.php` / `SettlementExchangeRateInputTest.php` |
| 정답 출처 (코드) | `app/Models/Vehicle.php` / `Settlement.php` / `FinalPayment.php` + 회의확장씬 #1·#6·#7·#8·#9 회의록 |
| 정답 출처 (회의록) | `docs/meetings/2026-05-21-extension-scene-decisions.md` 안건 1·6·7·8·9 |
| Helper | `makeAdmin()` / `makeManager()` / `makeFreelanceSettlement()` 등 case 내부 정의 (DRY 우선순위 낮음) |

## 3. 작업 단위 (Per Iteration)

한 iter = 1 테스트 case + 1 커밋.

```
1. 이미 통과하는 기존 테스트 패턴 읽기 (SettlementKrwBreakdownTest 등)
2. IntegrationRegressionTest.php 에 case 1개 작성
3. php artisan test --filter=IntegrationRegression
   → 초록 확인 (회귀 모드)
   → 빨강이면 (a) 회의록과 코드 불일치인지 (b) 테스트 잘못 작성인지 판단
       · (a) 의심되면 즉시 종료 + 진행 로그 경고
       · (b) 의심되면 SKIP + 다음 case
4. vendor/bin/pint --dirty
5. git commit -m "test: 통합 회귀 — {case 이름}"
6. 진행 로그 append → 다음 iter
```

## 4. 금지 사항 (Forbidden)

- ❌ production 코드 (`app/`, `resources/`, `routes/`, `database/migrations/`) 절대 수정
- ❌ 기존 테스트 파일 수정
- ❌ `php artisan migrate` / `key:generate` / `.env` 수정
- ❌ `master` / `demo` push
- ✅ `dev` 직커밋 OK
- ✅ `vendor/bin/pint --dirty` 커밋 전 실행
- ✅ `IntegrationRegressionTest.php` 신규 파일 작성·확장만 가능

## 5. 종료 조건 (Stop Conditions)

- §7 모든 케이스 통과 → 자동 종료 (`ScheduleWakeup` 미호출)
- 같은 case 3번 시도해도 통과 안 됨 → SKIP + 로그 → 다음 case
- **기존 419 테스트 중 어느 하나라도 깨지면 즉시 종료** + 진행 로그에 경고 + 사용자 알림

## 6. 진행 로그 위치 (Log)

`docs/loop-runs/2026-05-23-integration-regression.md` — 매 iter 끝에 1줄 append.

형식:
```
- iter N (HH:MM): {case 이름} → {결과: ✅ pass / ⚠️ skip / 🔴 fail (사유)}
```

종료 시 마지막 줄에 요약:
```
## 종료 (HH:MM)
- 총 N case 시도 / M case pass / K case skip
- 기존 회귀 419 → {최종} passed
- 다음 검토 우선: {SKIP·FAIL 항목}
```

## 7. 케이스 목록 (작성할 테스트 케이스 10건)

### A. 전체 워크플로우 (3건)

| # | Case 이름 | 검증 포인트 |
|---|---|---|
| 1 | 매입~거래완료 v4 한 흐름 통과 | 매입가→매입완료→말소→판매가→판매완료→반입지(선적중)→export_cleared+수출신고서(통관완료/v4)→bl_document(거래완료) 진행상태 cascade 검증 |
| 2 | KRW 차량 거래완료 시 자동 Settlement 생성 + 프리랜서 기본 50% 비율 | Vehicle::saved 훅 + Salesman.type='freelance' 기본 ratio=50 |
| 3 | paid 진입 시 secondary_status='pending' 자동 set | Settlement::saving 훅 (회의확장씬 #8) |

### B. 2차 정산 + 환차 (3건)

| # | Case 이름 | 검증 포인트 |
|---|---|---|
| 4 | 외화 환차익 시나리오: 입금 환율 1300, 2차 close 환율 1380 → +차이 KRW | calculateExchangeDifference 양수 / exchange_difference_krw 저장 |
| 5 | 프리랜서 actual_payout 에 환차 1:1 가산 | Settlement::getActualPayoutAttribute closed + ratio + diff 분기 |
| 6 | 사내직원(per_unit) closed 환차 미반영 | 동일 분기에서 per_unit 은 base 그대로 |

### C. KPI 정합 (2건)

| # | Case 이름 | 검증 포인트 |
|---|---|---|
| 7 | 관리자 대시보드 KPI 발생/회수/미수 정합 (부분 입금 시) | admin/dashboard kpis() — sale_total_krw / cash_received_krw / unpaid_krw 3 키 정확 산출 |
| 8 | SKILLS §13 단일 출처: sale_unpaid_amount_krw_cache 갱신 + 입금률 = 분자/분모 | Vehicle::saving 훅 캐시 갱신 + getUnpaidRatioAttribute |

### D. 권한 scoping (2건)

| # | Case 이름 | 검증 포인트 |
|---|---|---|
| 9 | role=관리 본인 부하 영업의 차량만 조회 | vehicles/index restrictToManagerScope (회의확장씬 #11) |
| 10 | role=영업 본인 차량 한정 + 다른 영업 차량 비노출 | vehicles/index restrictToOwnSalesman |

## 8. 비고

- **모두 기존 코드로 통과해야 함** (이번 세션까지 누적 구현 검증). 빨강이면 회의 결정과 코드 불일치 신호 — Claude 가 판단해서 SKIP·종료 결정.
- Case 1 (전체 워크플로우)이 가장 통합적 — Vehicle 모델 cascade 정확히 검증. 다른 case 가 깨지면 case 1 부터 다시 확인.
- 통과 후 사용자가 직접 브라우저 클릭 시나리오는 **별도 Markdown 체크리스트** (회의확장씬 합의 C 트랙) — 이 plan 범위 외.

## 9. 다음 단계 (자동화 범위 외)

- 사용자가 통과한 케이스 검토 → 추가 시나리오 발견 시 §7 확장
- 시나리오별 PHPUnit 통과 → 다음은 사용자 브라우저 통합 회귀 (1순위 C 트랙)
- 그 다음 NICE API 실연동 (큐 8, 키 발급 후)
- 마지막 AWS Lightsail 배포 (큐 13)
