# 정산 데이터 정리 + 사내직원 차등정산 — 핸드오프 (2026-06-22)

> 세션 컨텍스트 가득 차 종료. 다음 세션 재개용 정리. **resume 트리거: "정산 데이터 정리 이어서"**

## A. 지금 풀 문제 — 서버 정산 데이터 이상 (최우선)

### 증상
서버(운영) **정산 146건이 전부 `지급완료(paid) + 2차마감(closed)`** — 다른 상태 0건. 데이터 일괄 적재(import/load) 때 정산을 전부 paid+closed로 박은 것으로 추정.

### 범위 (읽기전용 진단, 2026-06-22)
- 정산 보유 차량 146대 전부 paid+closed.
- **그중 70대 = 진행중(거래완료 아님, 판매중/판매완료)인데 정산완료** ← 명백히 잘못.
- 나머지 76대 = 거래완료 차량 (이것도 CK로 검증 필요).
- 대표 사례 = **96더5119 (id 140)**: 판매중인데 settle id=432 paid+closed(2026-06-11). 그래서 환율·판매가 수정이 막힘(정산 지급 잠금은 ledger unlock 토큰으로 안 풀림).

### 정답 = jin의 xlsx (코드 근사치 말고 이게 권위)
파일: **`1. 헤이맨 수출차량현황표.xlsx`** (프로젝트 루트, 첫 탭 = `수출차량매입-2026`, 3349행). 헤더는 **행2**.
- **CK열(비고)** = 정산일 텍스트(예 "26.05.10정산") 있으면 **진짜 정산됨** → paid 유지.
- **CK 비어있음 + CG=0** 동시 = 진행중 → **정산에 있으면 안 됨** (paid 되돌림/정산행 제거 대상).
- 관련 수식(jin 작성, 행3 기준):
  - `CD 총마진 = (CB+CC)*0.9` (CB 판매마진=CA-BX, CC 부가세마진=P*0.09)
  - `CF 지급액(사내직원) = IF(BX>=100000000,CD*0.25,IF(CD<0,0,IF(CD<1000000,100000,IF(CD<10000000,200000))))`
  - `CG = IF(COUNTA(CK),CF,0)` (CK 있으면 지급액, 없으면 0)
  - 컬럼: **BX=매입금액(=R 구입금액), CD=총마진** → ERP `Settlement::total_margin` = CD, `vehicle.purchase_price` = BX.

### 계획 (차근차근)
```
1단계 ✅ 범위 파악 — 70대 진행중+정산완료 (완료)
2단계    xlsx CK ↔ 서버 정산 대조 → "지워야 할 정산" 정확한 명단 (차량번호 매칭)
3단계    교정 artisan 명령 작성 + dry-run (변경 없이 명단·건수만)
4단계    dry-run 검토 → jin 승인 → 실제 교정 (paid+closed 되돌림/삭제)
         ※ jin 결정: **명백한 70대 먼저 → 그 다음 76대(거래완료) CK 검증**
```
⚠️ paid+closed 정산은 보호 가드(Settlement deleting 가드 SKILLS #27 + paid 잠금)가 있어 그냥 못 지움 → **통제된 artisan 명령**(skip 플래그/명시)으로. 운영 데이터라 직접 손대지 말고 dry-run→승인.
⚠️ 운영 SSH는 읽기전용 원칙. 교정은 artisan 명령을 배포(또는 명시 승인된 1회 실행)로.

---

## A-2. 진행 (2026-06-22 세션 2) — 분석 완료 + 삭제 커맨드 작성

### CK 규칙 확정 (jin)
- **정산됨** = CK 에 `정산` 포함 AND `미정산` 미포함 (예 `26.05.10정산`, `6월 정산`).
- **미정산** = 빈칸 OR `미정산` 포함 (jin: "6월 미정산은 진짜 미정산, 빈칸과 같다").
- xlsx CK 분포(168행): 빈칸 65 / `26.05.10정산` 55 / `6월 정산` 46 / `6월 미정산` 2 → 정산됨 101 · 미정산 67.

### 146건 분류 (`scripts/audit-settlement-ck.php`, 로컬=서버 동일)
| 그룹 | 조건 | 건수 | 판정 |
|---|---|---|---|
| G1 | 진행중 + 미정산 | 40 | 제거 (96더5119 등 잠금 원인) |
| G2 | 거래완료 + 정산됨 | 71 | 정산됨 |
| G3 | 거래완료 + 미정산 | 5 | 미정산 |
| G4 | 판매완료 + 정산됨(`6월 정산`) | 30 | **정산됨 (jin 확인 — 30건 전부 정산완료)** |
- G1+G4=70 → handoff의 "진행중 70대"와 일치(40 미정산+30 정산).
- 정산됨(재산정 대상) = G2 71 + G4 30 = **101대**. 미정산 = G1 40 + G3 5 = 45.

### 원인 + 삭제 메커니즘 (검증 완료, 로컬)
- 원인: `vehicles:import --with-payments` 가 판매가>0 전 차량에 CK 무시하고 paid+closed 정산을 **전부 ratio 50%** 로 생성(`ImportVehicles.php:344-358`).
- ⚠️ **유지 후보 101건도 전부 ratio 50% 오류** — 금액 정합 아님(CK상 사내직원 tier 10만/20만 또는 비율). 클린 슬레이트 필요.
- 삭제 가드: `Settlement::deleting`(`Settlement.php:98`)은 `auth()->check()` 없으면 통과 → artisan 컨텍스트라 paid/closed 여도 삭제 가능. query-builder `forceDelete` 로 우회.
- 회계잠금: H4(`vehicles/index.blade.php:883`)·자동PBP(`Vehicle.php:624`) 모두 `settlements()->where('settlement_status','paid')->exists()` 의존 → **정산 삭제 시 즉시 해제**(96더5119 로컬 실증).
- 정산 자동생성은 **전환 트리거**(`Vehicle.php:662` `wasChanged('progress_status_cache')`) → 이미 거래완료인 차량은 일반 편집으로 재생성 안 됨.
- 삭제는 progress/미수 캐시에 영향 없음(설정값이 정산에 의존 X) — 로컬에서 146 삭제 후 96더5119 판매중 불변 확인.

### jin 결정 (2026-06-22)
- **146건 전부 삭제 → Part B(사내직원 차등 tier) 구현 후 CK='정산' 차량(101대)만 올바른 금액으로 재산정** (클린 슬레이트).
- import 입금(FinalPayment `note='import 입금'`)은 **보존** — 실제 수금/미수/진행상태 근거.

### 커맨드 (작성·로컬 검증 완료, dev 커밋)
`php artisan settlements:purge-import [--apply]` (`app/Console/Commands/PurgeImportSettlements.php`)
- dry-run 기본: 146건·영향차량·진행상태 분포 출력, 쓰기 없음. `--apply` 시 forceDelete + refreshCaches.
- 로컬 트랜잭션 실증: 146→0, 96더5119 잠금 해제 ✅, progress 불변, 롤백 정상.

### ✅ 서버 실행 완료 (2026-06-22 17:12, deploy #15)
- master `7c2b4f8` (코드 2개만, .md 제외) → 자동배포.
- 사전 백업: `storage/backups/db/car_erp-20260622_171207.sql` (799KB, S3 `db-backups/`).
- 서버 dry-run 146건(거래완료 76·통관중 1·판매완료 32·판매중 37) → `--apply`: **146 forceDelete, 캐시 146대 재계산, 정산 잔여 0**.
- 검증: **96더5119 회계잠금 해제 ✅** (progress 판매중 유지).

### 남은 작업
1. (jin) 96더5119 등 진행중 차량 환율/판매가 실제 수정 동작 확인.
2. **Part B 구현 → CK='정산' 101대 재산정** (아래 B). 재산정 대상 = `scripts/audit-settlement-ck.php` G2(71)+G4(30). G4 30건 jin 정산완료 확인.

## B. 사내직원 차등정산 공식 — 확정 (구현 보류, A 끝난 뒤)

기존: `Settlement` 사내직원(per_unit) = flat `EMPLOYEE_PER_UNIT_DEFAULT=100,000`. 이걸 차등으로:

**확정 공식** (jin 답변 반영):
```
매입금액 ≥ 1억        → 총마진 × 0.25 (= 25%)
총마진 < 0           → 0
총마진 < 100만        → 100,000
그 외(총마진 ≥ 100만)  → 200,000      ← 1000만 상한 제거 확정. 100만 정확히 = 20만.
```
(엑셀 CF의 `IF(CD<10000000,200000)` 마지막 else 누락 버그 = 20만으로 채움 확정.)

**구현 설계(예정, live 방식)**:
- `Settlement::employeePerUnitTier(int $totalMargin, int $purchasePrice): int` 신설.
- `getEffectivePerUnitAmountAttribute`: `per_unit_amount !== null` → override 그대로, 아니면 `employeePerUnitTier($this->total_margin, $this->vehicle->purchase_price)`.
- `Vehicle.php` 거래완료 자동채움(line ~679): per_unit 일 때 `EMPLOYEE_PER_UNIT_DEFAULT`(100,000) 저장 → **null 로**(live 계산 위해).
- H3 가드(`Settlement.php` line ~135): `per_unit_amount > 0` → **`settlement_amount > 0`** 로 (null 이어도 통과하게).
- 테스트: 4구간(1억↑/음수/100만미만/100만이상) + override + H3 가드 + 거래완료 자동생성.
- ⚠️ 회계 공식 변경(무거움) — advisor/회의 고려. 메모리 [[project_settlement_employee_tier]].

## C. 이번 세션 완료분 (참고)
- **deploy #13** (master `0f6b657`): 양식 유령열 제거·도장/서명 오버레이(전 서류, 직인 160)·연동 B v2 차량 첨부 수신(+소스디스크 분리)·연비. 배포기록 §24.
- **deploy #14** (master `cc7eb96`): 매입/판매 잔금 검증 메시지 한글화. 배포기록 §25.
- **Ledger 잠금해제 권한 관리 추가** (dev `7873395`, **미배포** — master 안 올림). User::canUnlockLedger = super/admin + 관리(본인팀). 단 이건 잔금 ledger 잠금용이고 **정산 paid 잠금은 별개**(96더5119는 정산 잠금이라 이걸로 안 풀림).
