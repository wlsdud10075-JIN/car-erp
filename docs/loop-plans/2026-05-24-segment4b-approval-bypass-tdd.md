# Segment 4-b — 관리 승인 우회 (B/L 100% 이전 발급) (TDD 박제)

> /loop 플랜 — A안 (박제만, production 코드 무수정)
> 작성: 2026-05-20
> 대상 워크플로우: 사용자 명확화 (2026-05-20) — "100% 다 못받고 90%정도 받았는데 바이어가 너무 원해서 추후에 준다고 하면 [관리] 및 관리자의 승인을 받고 진행"
> 관련 인프라: 큐 14 `ApprovalRequest` 모델 (기존 — `app/Models/ApprovalRequest.php`)

## 1. 목표 (Success Criteria)

관리/관리자 승인 우회 사양을 PHPUnit 테스트로 **빨갛게 박제**. 큐 14 인프라 재사용 패턴 박제.

- 산출물: `tests/Feature/BlDocumentApprovalBypassTest.php` (신규 1 파일)
- 케이스: 아래 §7 목록 7~9개
- 종료 시 상태: **모두 빨강 (failing)** — TDD 모드라 빨강이 정상

## 2. 사전 결정사항 (Decisions)

| 항목 | 값 |
|---|---|
| 인프라 | `App\Models\ApprovalRequest` 기존 모델 재사용 (큐 14) |
| 신규 type (가정) | `BL_ISSUE_EARLY` (ApprovalRequest::type enum 또는 string) |
| 승인 권한 | `role === '관리'` OR `permission ∈ {admin, super}` |
| 영업·재무·수출통관 role | 승인 권한 없음 → AuthorizationException |
| 단발성 | 큐 14 `used_at` 컬럼 활용 — 1회 사용 후 `used_at` 채워짐, 재사용 불가 |
| 승인 대상 | 특정 `vehicle_id` 한정 (다른 차량 적용 불가) |
| 만료 (가정) | 미정 — 회의 결정 (테스트는 만료 없음 가정) |
| Vehicle::saving 훅 흐름 | bl_document dirty → 입금률 < 100% → ApprovalRequest 조회 → 없으면 차단, 있으면 used_at 채우고 통과 |
| 거부 (rejected) | 큐 14 `rejected_at` 컬럼 활용 — 거부된 승인은 통과 X |
| audit_logs | 큐 11-4 패턴 — 승인 사용 시 audit row 생성 (테스트는 row 존재만 검증) |

> ⚠️ type enum 추가 여부·만료 정책은 회의 안건.
> 테스트는 "ApprovalRequest로 우회 가능"이라는 인터페이스까지만 박제.

## 3. 작업 단위 (Per Iteration)

```
1. BlDocumentApprovalBypassTest.php에 case 1개 작성 (빨강 의도)
   → ApprovalRequest::create()로 시나리오 setup
2. php artisan test --filter=BlDocumentApprovalBypass
   → 빨강 1개 추가 확인
   → 기존 246 테스트 여전히 초록 확인
3. vendor/bin/pint --dirty
4. git commit -m "test: 승인 우회 spec — {case 이름} (구현 대기)"
5. 진행 로그 append
```

## 4. 금지 사항 (Forbidden)

- ❌ `ApprovalRequest.php` / `Vehicle.php` / `PaymentConfirmationService` 수정 절대 금지
- ❌ `BL_ISSUE_EARLY` type 추가 마이그레이션 작성 X (회의 사항)
- ❌ 기존 테스트 수정
- ❌ `migrate` / `key:generate` / `.env`
- ❌ `master` / `demo` push

## 5. 종료 조건 (Stop Conditions)

- §7 모든 케이스 박제 완료 → 자동 종료
- **기존 246 테스트 깨지면 즉시 종료** + 진행 로그 경고
- 3 strike → SKIP

## 6. 진행 로그 위치 (Log)

`docs/loop-runs/2026-05-24-segment4b.md`

형식:
```
- iter N (HH:MM): {case 이름} → 빨강 ✅ (구현 대기)
```

## 7. 케이스 목록 (작성할 테스트 케이스)

| # | Case 이름 | 시나리오 | 기대 결과 |
|---|---|---|---|
| 1 | 관리 role 사용자가 90% 차량에 BL_ISSUE_EARLY 승인 생성 → bl_document 업로드 통과 | 관리 승인 O | 통과 + `used_at` 채워짐 |
| 2 | admin 사용자가 90% 차량에 BL_ISSUE_EARLY 승인 생성 → 통과 | admin 승인 | 통과 |
| 3 | super 사용자가 90% 차량에 BL_ISSUE_EARLY 승인 생성 → 통과 | super 승인 | 통과 |
| 4 | 영업 role 사용자가 BL_ISSUE_EARLY 승인 생성 시도 → 권한 거부 | 영업 권한 부족 | AuthorizationException |
| 5 | 재무 role 사용자가 BL_ISSUE_EARLY 승인 생성 시도 → 권한 거부 | 재무 권한 부족 | AuthorizationException |
| 6 | 90% 차량 + 승인 없음 → bl_document 업로드 차단 (Segment 4-a 게이트) | 승인 X | ValidationException |
| 7 | 이미 used_at 채워진 승인은 재사용 불가 (단발성) | 1회 사용 후 재시도 | ValidationException |
| 8 | 차량 A에 발급된 승인을 차량 B에 적용 시도 → 매칭 안 됨 | 다른 vehicle_id | ValidationException |
| 9 | rejected_at 채워진 승인은 통과 X | 거부된 승인 | ValidationException |

## 8. 비고

- 케이스 1~3이 핵심 — **권한 매트릭스 박제**
- 케이스 7·8·9는 **인프라 무결성** (큐 14 패턴 재사용 검증)
- 케이스 6은 Segment 4-a 와 중복 가능 — Segment 4-a에서 이미 박제되어 있으면 SKIP
- 만료 정책은 박제 X (회의 후 결정)

## 9. 다음 단계 (자동화 범위 외)

- 회의 안건: "BL_ISSUE_EARLY type 도입 — ApprovalRequest enum 확장 vs 신규 패턴 vs 단순 권한 체크"
- 회의 결정 후 큐 별도 진행:
  - `database/migrations/` — type enum 추가 (필요 시)
  - `app/Models/ApprovalRequest.php` — type 상수 추가
  - `app/Models/Vehicle.php` — saving 훅 승인 체크 추가
  - `app/Http/Middleware/` 또는 Gate — 권한 체크
- 구현 후 `php artisan test --filter=BlDocumentApprovalBypass` 모두 초록 = 완료
