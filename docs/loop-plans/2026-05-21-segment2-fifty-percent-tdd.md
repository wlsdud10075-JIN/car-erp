# Segment 2 — 50% 수출통관 게이트 (TDD 박제)

> /loop 플랜 — A안 (박제만, production 코드 무수정)
> 작성: 2026-05-20
> 대상 워크플로우: `워크플로우 개선 버전.txt` line 32~37 (미수금 50% 이상 → 수출통관 차단, 50% 미만 → 진행)
> 관련 큐: 큐 9 확장 (미구현 — CLAUDE.md "다음 추천 순서 1")

## 1. 목표 (Success Criteria)

50% 룰 사양을 PHPUnit 테스트로 **빨갛게 박제**. 다음 큐 9 확장 구현 시 success criteria로 사용.

- 산출물: `tests/Feature/FiftyPercentExportGateTest.php` (신규 1 파일)
- 케이스: 아래 §7 목록 6~8개
- 종료 시 상태: **모두 빨강 (failing)** — TDD 모드라 빨강이 정상

## 2. 사전 결정사항 (Decisions)

| 항목 | 값 |
|---|---|
| 분모 | `Vehicle::getSaleTotalAmountAttribute` (SKILLS.md §13 단일 출처) |
| 분자 | `Vehicle::getSaleUnpaidAmountAttribute` (confirmed_at IS NOT NULL 필터 — A안) |
| 입금률 계산 | `(sale_total_amount - sale_unpaid_amount) / sale_total_amount` |
| 차단 조건 | 입금률 < 50% AND 관리 승인 없음 |
| 검증 위치 (가정) | `Vehicle::saving` 훅에서 `export_buyer_id` 또는 `shipping_date` dirty 감지 |
| 예외 클래스 | `Illuminate\Validation\ValidationException` |
| 예외 메시지 (가정) | "미수금 50% 이상 — 수출통관 불가 (관리 승인 필요)" |
| 환율 0 외화 차량 | KRW 캐시 null → 별도 예외 ("환율 미입력 — 통관 불가") |
| 관리 승인 우회 | Segment 4-b 와 연계 (이 segment는 "승인 없는 상태"만 검증) |

> ⚠️ 검증 위치·예외 메시지는 **가정값**. 구현 시 회의에서 확정.
> 테스트는 "ValidationException 던져진다"까지만 검증하고 메시지는 contain 매칭으로 느슨하게.

## 3. 작업 단위 (Per Iteration)

```
1. FiftyPercentExportGateTest.php에 case 1개 작성 (빨강 의도)
2. php artisan test --filter=FiftyPercentExportGate
   → 빨강 1개 추가 확인 (정상)
   → 기존 246 테스트는 여전히 초록 확인 (중요!)
3. vendor/bin/pint --dirty
4. git commit -m "test: 50% 게이트 spec — {case 이름} (구현 대기)"
5. 진행 로그 append → 다음 iter
```

## 4. 금지 사항 (Forbidden)

- ❌ production 코드 수정 (`app/`, `resources/`, `routes/`, `database/`)
- ❌ `Vehicle::saving` 훅 / `PaymentConfirmationService` 등 어떤 구현도 추가 X
- ❌ 기존 테스트 수정
- ❌ `migrate` / `key:generate` / `.env`
- ❌ `master` / `demo` push

## 5. 종료 조건 (Stop Conditions)

- §7 모든 케이스 박제 완료 → 자동 종료
- **기존 246 테스트 중 어느 하나라도 깨지면 즉시 종료** (테스트 추가만 했는데 다른 거 깨지면 비정상)
- 3 strike → SKIP

## 6. 진행 로그 위치 (Log)

`docs/loop-runs/2026-05-21-segment2.md`

형식:
```
- iter N (HH:MM): {case 이름} → 빨강 ✅ (구현 대기)
```

## 7. 케이스 목록 (작성할 테스트 케이스)

| # | Case 이름 | 입금률 | 기대 결과 |
|---|---|---|---|
| 1 | 입금률 49.9% 차량에 export_buyer_id 입력 시도 → 차단 | 49.9% | ValidationException |
| 2 | 입금률 정확히 50% → 통관 통과 | 50.0% | 진행상태 수출통관중 |
| 3 | 입금률 50.1% → 통관 통과 | 50.1% | 진행상태 수출통관중 |
| 4 | 입금률 0% (계약금만) → 차단 | 0% | ValidationException |
| 5 | 입금률 100% (완납) → 통관 통과 | 100% | 진행상태 수출통관중 |
| 6 | 환율 0 외화 차량 → 별도 예외 | n/a | "환율 미입력" 예외 |
| 7 | KRW 차량 환율 1.0 가정 → 정상 게이트 적용 | 30% | ValidationException |
| 8 | (선택) shipping_date 변경 시도도 같은 게이트 | 40% | ValidationException |

## 8. 비고

- 케이스 1·2·3은 **경계값 테스트** — 50% 룰 구현 시 부등호 방향 (>= vs >) 결정에 핵심
- 분모 단일 출처(SKILLS.md §13) 위반 시 케이스 6·7이 미묘하게 실패할 수 있음
- 케이스 6 환율 0 처리는 회의 안건 (CLAUDE.md "환율 0 시 KRW 캐시 0 → 완납으로 오판" 이슈와 연계)

## 9. 다음 단계 (자동화 범위 외)

- 큐 9 확장 진행 시 이 테스트들이 success criteria
- 구현 후 `php artisan test --filter=FiftyPercentExportGate` 모두 초록 = 완료
