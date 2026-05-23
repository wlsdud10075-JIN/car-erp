# Segment 3 — 말소 게이트 (판매 보류) (TDD 박제)

> /loop 플랜 — A안 (박제만, production 코드 무수정)
> 작성: 2026-05-20
> 대상 워크플로우: `워크플로우 개선 버전.txt` line 18 ("말소가 완료되지 않았다면 판매중·판매완료는 보류, 말소 진행상황에 따라 표시")
> 관련 회의 안건: `progress_status_rule_version` **v3 도입** (회의 미진행)

## 1. 목표 (Success Criteria)

말소 게이트 사양을 PHPUnit 테스트로 **빨갛게 박제**. 회의에서 v3 도입 결정 후 구현 시 success criteria로 사용.

- 산출물: `tests/Feature/DeregistrationGateTest.php` (신규 1 파일)
- 케이스: 아래 §7 목록 5~7개
- 종료 시 상태: **모두 빨강 (failing)** — TDD 모드라 빨강이 정상

## 2. 사전 결정사항 (Decisions)

| 항목 | 값 |
|---|---|
| 현재 SKILLS.md §2 rule 8 | `is_deregistered=true AND deregistration_document → 말소완료` (우선순위 8) |
| 새 사양 (워크플로우 개선 버전) | "말소 미완료면 판매중/판매완료 보류, 말소 진행상황에 따라 표시" |
| 우선순위 재배치 (가정) | 말소 게이트가 판매 게이트보다 우선 평가 |
| Rule version | **v3** — 신규 row만 적용, v2 row는 grandfather (큐 2.6 패턴 차용) |
| 분기 컬럼 | `progress_status_rule_version >= 3` |
| 영향 범위 | `Vehicle::getProgressStatusAttribute` accessor 우선순위 재배치 |

> ⚠️ v3 도입은 **CLAUDE.md 라운드테이블 트리거 조건** (말소 컨셉 변경은 도메인 핵심).
> 이 segment는 **회의 전 사양 박제용**. 구현 코드 절대 추가 X.

## 3. 작업 단위 (Per Iteration)

```
1. DeregistrationGateTest.php에 case 1개 작성 (빨강 의도)
   → 새 row는 progress_status_rule_version=3으로 강제 setting
2. php artisan test --filter=DeregistrationGate
   → 빨강 1개 추가 확인
   → 기존 246 테스트 여전히 초록 확인
3. vendor/bin/pint --dirty
4. git commit -m "test: 말소 게이트 spec v3 — {case 이름} (회의·구현 대기)"
5. 진행 로그 append
```

## 4. 금지 사항 (Forbidden)

- ❌ `Vehicle::getProgressStatusAttribute` 수정 절대 금지 (회의 사항)
- ❌ `progress_status_rule_version` 컬럼 마이그 작성 X
- ❌ production 코드 / 기존 테스트 수정
- ❌ `migrate` / `key:generate` / `.env`
- ❌ `master` / `demo` push

## 5. 종료 조건 (Stop Conditions)

- §7 모든 케이스 박제 완료 → 자동 종료
- **기존 246 테스트 깨지면 즉시 종료** (테스트 추가만으로 다른 거 안 깨져야 함)
- 3 strike → SKIP

## 6. 진행 로그 위치 (Log)

`docs/loop-runs/2026-05-22-segment3.md`

형식:
```
- iter N (HH:MM): {case 이름} → 빨강 ✅ (회의·구현 대기)
```

## 7. 케이스 목록 (작성할 테스트 케이스)

| # | Case 이름 | 차량 상태 | 기대 결과 (v3) |
|---|---|---|---|
| 1 | rule v3 차량: 매입완료 + 말소 미완료 + 판매가 입력 → 매입완료 유지 (판매중 표시 X) | sale_price>0, is_deregistered=false | `progress_status === '매입완료'` |
| 2 | rule v3 차량: 매입완료 + 말소 미완료 + 판매가 + 완납 → 매입완료 유지 (판매완료 보류) | 완납 but 말소 X | `progress_status === '매입완료'` |
| 3 | rule v3 차량: 말소 완료 + 판매가 입력 → 판매중 표시 | is_deregistered=true | `progress_status === '판매중'` |
| 4 | rule v3 차량: 말소 완료 + 판매가 + 완납 → 판매완료 | 말소 OK + 완납 | `progress_status === '판매완료'` |
| 5 | rule v2 차량 (grandfather): 말소 미완료 + 판매가 + 완납 → 판매완료 (구 규칙 그대로) | rule_version=2 | `progress_status === '판매완료'` |
| 6 | rule v3 차량: 말소 서류만 있고 is_deregistered=false → 매입완료 유지 | 서류 있음 but flag false | `progress_status === '매입완료'` |
| 7 | (선택) rule v3 차량: 통관 단계까지 가도 말소 미완료면 어떻게 표시? | 회의 결정 필요 | SKIP 또는 spec 미확정 마킹 |

## 8. 비고

- 케이스 5는 **grandfather 호환** 검증 — v3 도입 시 기존 row 깨지면 안 됨
- 케이스 7은 회의 결정 사항 — 박제 시 "spec 미확정" 주석으로 표시하고 빨강 OK
- 이 segment의 테스트가 빨갛다는 사실 자체가 **회의 안건 자료**

## 9. 다음 단계 (자동화 범위 외)

- 라운드테이블 풀회의 안건: "rule v3 도입 — 말소 게이트 우선순위 + grandfather 정책"
- 회의 결정 후 큐 별도 진행 시 이 테스트들이 success criteria
- 마이그레이션: `progress_status_rule_version` 컬럼 신규 row 기본값 3으로 변경
