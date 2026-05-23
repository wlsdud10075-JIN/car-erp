# Segment 1 — 매입 워크플로우 회귀 테스트

> /loop 플랜 — A안 (박제만, production 코드 무수정)
> 작성: 2026-05-20
> 대상 워크플로우: `워크플로우 개선 버전.txt` line 2~13 ([영업] 차량/매입 등록 → [재무] 송금/매입 기입 → 매입완료)

## 1. 목표 (Success Criteria)

이미 구현된 큐 20-A~D의 매입 워크플로우 게이트가 **앞으로도 깨지지 않게** PHPUnit 회귀 테스트로 박제.

- 산출물: `tests/Feature/PurchaseWorkflowRegressionTest.php` (신규 1 파일)
- 케이스: 아래 §7 목록 6~8개
- 종료 시 상태: **모두 초록 (passing)** — 회귀 모드라 빨강이면 안 됨

## 2. 사전 결정사항 (Decisions)

| 항목 | 값 |
|---|---|
| 테스트 프레임워크 | Pest (프로젝트 기본) |
| 정답 출처 | `app/Services/PaymentConfirmationService.php` + 큐 20-B 커밋 `fccb297` |
| 참고 패턴 | `tests/Feature/PaymentConfirmationServiceTest.php` |
| 분모 | `Vehicle::getSaleTotalAmountAttribute` / `getPurchaseUnpaidAmountAttribute` (SKILLS.md §13) |
| RefreshDatabase | 사용 (`uses(RefreshDatabase::class)`) |
| Factory | `Vehicle::factory()` / `PurchaseBalancePayment::factory()` 활용 |

## 3. 작업 단위 (Per Iteration)

한 iter = 1 테스트 case + 1 커밋.

```
1. PaymentConfirmationServiceTest.php 패턴 읽기
2. PurchaseWorkflowRegressionTest.php에 case 1개 작성
3. php artisan test --filter=PurchaseWorkflowRegression
   → 초록 확인 (회귀라 빨강이면 즉시 종료 + 로그)
4. vendor/bin/pint --dirty
5. git commit -m "test: 매입 회귀 — {case 이름}"
6. 진행 로그 append → 다음 iter 스케줄
```

## 4. 금지 사항 (Forbidden)

- ❌ production 코드 (`app/`, `resources/`, `routes/`, `database/`) 절대 수정
- ❌ 기존 테스트 파일 수정 (`PaymentConfirmationServiceTest.php` 포함)
- ❌ `php artisan migrate` / `key:generate` / `.env` 수정
- ❌ `master` / `demo` push
- ✅ `dev` 직커밋 OK

## 5. 종료 조건 (Stop Conditions)

- §7 모든 케이스 통과 → 자동 종료 (`ScheduleWakeup` 미호출)
- 같은 case 3번 시도해도 통과 안 됨 → SKIP, 다음 case로 (3 strike)
- **기존 246 테스트 중 어느 하나라도 깨지면 즉시 종료** + 진행 로그에 경고

## 6. 진행 로그 위치 (Log)

`docs/loop-runs/2026-05-20-segment1.md` — 매 iter 끝에 1줄 append.

형식:
```
- iter N (HH:MM): {case 이름} → {결과} {결과 표시}
```

## 7. 케이스 목록 (작성할 테스트 케이스)

| # | Case 이름 | 검증 포인트 |
|---|---|---|
| 1 | 영업이 매입가만 입력하면 진행상태 매입중 | `progress_status === '매입중'` |
| 2 | 영업이 매입 잔금 row 입력해도 confirmed_at null이면 미지급 유지 | `purchase_unpaid_amount > 0` |
| 3 | 재무가 PaymentConfirmationService::confirm 호출하면 매입완료 | `progress_status === '매입완료'` |
| 4 | 매입 미지급 정확히 0이 되어야 매입완료 (1원 남으면 매입중) | 경계값 |
| 5 | 재무 확정된 row 금액을 영업이 변경 시도 → ValidationException (lock) | 회계 무결성 |
| 6 | 재무 확정된 row 삭제 시도 → ValidationException (lock) | 회계 무결성 |
| 7 | progress_status_cache 컬럼이 confirm 후 자동 갱신됨 | 캐시 동기화 |
| 8 | (선택) audit_logs에 confirm 액션 기록 남는지 | 큐 14 audit 연계 |

## 8. 비고

이 segment는 **회귀 모드**라 모든 케이스가 초록이 정상. 빨강이 뜨면:
- (a) 이미 구현된 게 회의록과 다름 → 큐 20 재검토 필요
- (b) 테스트가 잘못 작성됨 → SKIP

→ Claude 판단으로 (a)/(b) 구분 어려우면 SKIP + 로그 + 다음 case 진행.
