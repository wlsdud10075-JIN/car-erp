# /loop 플랜 — 워크플로우 게이트 박제 (A안)

> 작성: 2026-05-20
> 기준 문서: `C:\Users\User\Desktop\워크플로우 개선 버전.txt`
> 모든 플랜은 **A안 — 박제만** (production 코드 절대 무수정, PHPUnit 테스트 파일만 추가)

## 플랜 목록

| 밤 | 파일 | Segment | 비고 |
|---|---|---|---|
| 1 | `2026-05-20-segment1-purchase-regression.md` | 매입 워크플로우 회귀 | 이미 구현됨 — 회귀 테스트 |
| 2 | `2026-05-21-segment2-fifty-percent-tdd.md` | 50% 수출통관 게이트 | 큐 9 확장 대기 — failing test |
| 3 | `2026-05-22-segment3-deregistration-gate-tdd.md` | 말소 게이트 (판매 보류) | rule v3 회의 안건 — failing test |
| 4 | `2026-05-23-segment4a-bl-100-tdd.md` | 100% B/L 발급 게이트 | 신규 게이트 — failing test |
| 5 | `2026-05-24-segment4b-approval-bypass-tdd.md` | 관리 승인 우회 | 큐 14 인프라 재사용 — failing test |
| **2026-05-23** | **`2026-05-23-integration-regression.md`** | **운영 통합 회귀 (1순위)** | **회의확장씬·새회의·한글화·KPI 분리 누적 시나리오 박제 — 10건** |
| **2026-05-24** | **`2026-05-24-document-autofill.md`** | **서류 자동기입 9종 회귀 + 사용자 체크리스트** | **PHPUnit 12건(셀 매핑·엔진 불변식) + C안 다운로드 체크리스트. #3 다중차량 추후 확장** |

## /loop 호출 명령

각 밤마다 해당 .md 파일 지정:

```
/loop docs/loop-plans/2026-05-20-segment1-purchase-regression.md 를 읽고 그대로 진행해줘.
```

또는 dynamic 모드 (Claude가 self-pace):

```
/loop docs/loop-plans/2026-05-21-segment2-fifty-percent-tdd.md 의 success criteria까지 자율 진행
```

## 자기 전 체크리스트

```powershell
# 1. 절전 영구 끄기 (한 번만)
powercfg /change standby-timeout-ac 0
powercfg /change hibernate-timeout-ac 0

# 2. 베이스라인 확인
php artisan test       # 444 passed 확인 (2026-05-24)
git status             # clean 확인
git pull origin dev    # 최신 동기화

# 3. /loop 실행
# (위 명령 중 하나)

# 4. 노트북 덮개 닫아도 안 자게 (Windows 설정)
# 설정 → 시스템 → 전원 → "덮개를 닫을 때: 아무 작업 안 함"
```

## 아침에 검수할 것

1. **진행 로그 확인**: `docs/loop-runs/2026-05-XX-segmentN.md`
2. **테스트 결과**: `php artisan test`
   - Segment 1: 모두 초록 (회귀 OK)
   - Segment 2~4-b: 빨간 줄 N개 (정상 — 구현 대기)
3. **git log**: 커밋이 의도대로 쪼개졌는지
4. **빨간 줄 검토**: 사양 OK / 수정 / 회의 필요로 분류

## 공통 위험 방지 규칙 (모든 플랜에 적용)

- ❌ `php artisan migrate` 실행 금지 (파일 작성은 OK)
- ❌ `php artisan key:generate` 절대 금지 (RRN 영구 손실)
- ❌ `.env` 수정 금지
- ❌ `master` / `demo` 브랜치 push 금지
- ❌ production 코드 (`app/`, `resources/`, `routes/`, `database/migrations/`) 수정 금지
- ❌ 기존 테스트 파일 수정 금지 (신규 파일만 추가)
- ✅ `dev` 브랜치 직커밋 OK (CLAUDE.md 1인 개발 규칙)
- ✅ `vendor/bin/pint --dirty` 커밋 전 실행

## 종료 조건 (공통)

- 모든 케이스 박제 완료 → `ScheduleWakeup` 호출 안 함 → 자동 종료
- 또는 3 strike (같은 케이스 3번 시도해도 작성 불가) → SKIP 후 다음 진행
- 또는 기존 444 테스트 중 어느 하나라도 깨지면 → 즉시 종료 + 진행 로그에 경고
