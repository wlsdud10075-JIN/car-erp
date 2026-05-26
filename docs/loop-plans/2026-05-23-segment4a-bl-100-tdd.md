# Segment 4-a — 100% B/L 발급 게이트 (TDD 박제)

> /loop 플랜 — A안 (박제만, production 코드 무수정)
> 작성: 2026-05-20
> 대상 워크플로우: `워크플로우 개선 버전.txt` line 38~39 ("바이어에게 받은 금액이 50% 넘어 수출통관·선적 진행, B/L 문서는 잔금 100% 다 치뤄야만 바이어에게 전달, 거래완료")
> 사용자 명확화 (2026-05-20): "B/L 문서 발급 자체를 100% 완납 전까지 차단"

---

## ✅ 구현 완료 (2026-05-26) — 본 플랜은 실행됨. 이하 본문은 설계 기록

> 박제(red)가 아니라 **실제 구현 + green 검증**으로 완료. 본 §1~§9 본문은 계획 당시 기록(아카이브).
> 회의록: `docs/meetings/2026-05-26-external-review-audit.md` §사용자결정 1.

**구현 요약**
- `Vehicle::guardBlFiftyPercentRuleOnSaving()` — `unpaid_ratio > 0.5` → `> 0`(완납) 차단. 통과 조건 = 미수율 0(100% 완납).
- 통관·선적 진입(C5 `guardStageOrderForExport`)은 **50% 유지** — B/L만 100%.
- 우회 = 기존 `UnpaidExportOverride` stage='shipping' **재사용** (별도 `bl_issue` stage/ApprovalRequest type 안 만듦 — advisor 채택안). 90% 시나리오는 C5(≥50%)를 이미 통과해 conflation 구조적 불가.

**계획 대비 변경**
- 신규 파일 `BlDocumentGateTest.php` 대신 기존 **`G1BlLockTest.php`를 100% 기준으로 재작성**(8건).
- `used_at` 단발성(§ 가정)은 미채택 — 기존 인프라의 존재-체크(영구) 유지.

**테스트 가능 (green 검증 명령)**
```bash
php artisan test --filter="G1BlLockTest|BlDocumentApprovalBypassTest|VehicleLifecycleE2ETest"
```
- `G1BlLockTest` (8건) — 미완납/부분입금 차단·완납 통과·grandfather·환율 미입력·우회.
- `VehicleLifecycleE2ETest` (전체 생애주기 E2E) — 차량 등록(매입중)→매입완료→말소완료→판매중→[C5 40%선적막힘/50%통과]→선적중→선적완료→통관중→[G1 미완납 B/L막힘/100%통과]→거래완료.
- 승인 우회는 `BlDocumentApprovalBypassTest` (segment4b).

---

## 1. 목표 (Success Criteria)

100% B/L 발급 게이트 사양을 PHPUnit 테스트로 **빨갛게 박제**. 다음 큐 진행 시 success criteria로 사용.

- 산출물: `tests/Feature/BlDocumentGateTest.php` (신규 1 파일)
- 케이스: 아래 §7 목록 6~8개
- 종료 시 상태: **모두 빨강 (failing)** — TDD 모드라 빨강이 정상

## 2. 사전 결정사항 (Decisions)

| 항목 | 값 |
|---|---|
| 게이트 대상 | `Vehicle::bl_document` 컬럼 신규 업로드 (dirty 감지) |
| 검증 위치 (가정) | `Vehicle::saving` 훅 — `isDirty('bl_document') AND bl_document not null` |
| 통과 조건 | 입금률 = 100% (`sale_unpaid_amount === 0`) |
| 차단 조건 | 입금률 < 100% AND 관리 승인 없음 |
| 예외 클래스 | `Illuminate\Validation\ValidationException` |
| 예외 메시지 (가정) | "잔금 100% 미완 — B/L 발급 불가 (관리 승인 필요)" |
| 분모 | `sale_total_amount` (SKILLS.md §13) |
| 분자 | `sale_unpaid_amount` (큐 20 A안: confirmed_at IS NOT NULL 필터) |
| 100% 후 환불 케이스 | 환불로 입금률 99% 떨어진 차량에 **추가** bl_document 업로드 시도 → 차단 |
| 기존 bl_document 있는 차량 | 환불로 미수 발생해도 기존 컬럼값 유지 (이미 발급된 것 무효화 X) |
| 관리 승인 우회 | Segment 4-b 와 연계 (이 segment는 "승인 없는 상태"만 검증) |

> ⚠️ 검증 위치·메시지는 가정. 테스트는 `ValidationException` 던져진다까지만 검증.

## 3. 작업 단위 (Per Iteration)

```
1. BlDocumentGateTest.php에 case 1개 작성 (빨강 의도)
2. php artisan test --filter=BlDocumentGate
   → 빨강 1개 추가 확인
   → 기존 246 테스트 여전히 초록 확인
3. vendor/bin/pint --dirty
4. git commit -m "test: 100% B/L 게이트 spec — {case 이름} (구현 대기)"
5. 진행 로그 append
```

## 4. 금지 사항 (Forbidden)

- ❌ `Vehicle.php` / `PaymentConfirmationService` 등 production 코드 수정
- ❌ 기존 테스트 수정
- ❌ `migrate` / `key:generate` / `.env`
- ❌ `master` / `demo` push

## 5. 종료 조건 (Stop Conditions)

- §7 모든 케이스 박제 완료 → 자동 종료
- **기존 246 테스트 깨지면 즉시 종료**
- 3 strike → SKIP

## 6. 진행 로그 위치 (Log)

`docs/loop-runs/2026-05-23-segment4a.md`

형식:
```
- iter N (HH:MM): {case 이름} → 빨강 ✅ (구현 대기)
```

## 7. 케이스 목록 (작성할 테스트 케이스)

| # | Case 이름 | 입금률 | 기대 결과 |
|---|---|---|---|
| 1 | 입금률 99.9% 차량에 bl_document 업로드 시도 → 차단 | 99.9% | ValidationException |
| 2 | 입금률 정확히 100% → bl_document 업로드 통과 | 100% | `bl_document` 컬럼 채워짐 |
| 3 | 입금률 100% + bl_loading_location → 진행상태 선적완료 (큐 17 기준) | 100% + 반입지 | `progress_status === '선적완료'` |
| 4 | 입금률 50% (통관·선적은 가능) + bl_document 업로드 시도 → 차단 | 50% | ValidationException — "통관·선적 OK, B/L만 차단" 검증 |
| 5 | 입금률 0% → bl_document 업로드 시도 → 차단 | 0% | ValidationException |
| 6 | 이미 bl_document 있는 차량 + 환불로 입금률 90% → 기존 bl_document 유지 (무효화 X) | 100%→90% | `bl_document` 그대로 |
| 7 | 이미 bl_document 있는 차량 + 환불 후 **새 bl_document 업로드** 시도 → 차단 | 100%→90% | ValidationException |
| 8 | (선택) 환율 0 외화 차량 → 입금률 계산 불가, 별도 예외 | n/a | "환율 미입력" 예외 |

## 8. 비고

- 케이스 4가 핵심 — **수출통관·선적은 50% 룰만 통과하면 OK**, B/L만 100% 룰 적용
- 케이스 6·7은 환불 시나리오 — "이미 발급된 B/L 회수는 운영 X" + "추가 발급은 차단" 정책 박제
- 분모 단일 출처 위반 시 케이스 8이 미묘하게 실패

## 9. 다음 단계 (자동화 범위 외)

- 큐 9 확장과 같은 PR로 묶을지, 별도 PR로 분리할지 회의 결정
- 구현 후 `php artisan test --filter=BlDocumentGate` 모두 초록 = 완료
