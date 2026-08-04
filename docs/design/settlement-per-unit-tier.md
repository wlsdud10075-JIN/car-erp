# 사내직원 정산 — 담당자별 차등(tier) + 퇴사자 승계 바이어

> jin 2026-08-04 확정. 구현 완료(dev). **master 배포 전 §6 체크리스트 필수.**

## 1. 무엇이 바뀌었나

종전엔 `per_unit`(사내직원) **전원**이 차등 tier를 탔다. 실제로는 **특정 인원 한정** 규칙이었고,
퇴사자에게서 승계받은 바이어 건은 **건당 5만원**이라는 별도 규칙도 있었다(지금까지 재무가 수동 입력).

## 2. 확정 규칙 (우선순위 — 위에서부터 첫 매칭)

| # | 조건 | 정산액 |
|---|---|---|
| ① | `per_unit_amount` 직접 입력 | 그 값 (재무 override, 최우선) |
| ② | 총마진 < 0 | **0원** — 승계·tier 무관 |
| ③ | 승계 바이어 건 | **50,000원** — tier·1억보다 우선, 영구 |
| ④ | tier OFF 담당자 | **100,000원** 고정 |
| ⑤ | tier ON — 매입합계(구입금액+매도비) ≥ 1억 | 총마진 × **25%** |
| ⑥ | tier ON — 총마진 < 100만 | 100,000원 |
| ⑦ | tier ON — 총마진 ≥ 100만 | 200,000원 |

- 승계 5만원은 **사내직원만**. 프리랜서는 종전대로 비율(50%) 정산.
- 임계값·금액은 전부 `Setting` 으로 조정 가능(`settlement_employee_*`). 승계액 = `settlement_employee_inherited_amount`.
- 담당자 없는 정산(데이터 결손)은 **보수적으로 tier OFF**(10만) — 과다지급 방지.

## 3. jin 답변 원문 (재논의 방지)

| 질문 | 답 |
|---|---|
| tier 적용 대상 | **무사백 1명만** |
| 승계 5만원 적용 유형 | **사내직원만** (건당제 안에서 10만 → 5만) |
| 승계 유지 기간 | **영구** — 그 바이어는 계속 5만원 |
| 승계인데 손해가 나면 | **0원** — 손해차량은 승계여도 0원 |
| 향후 tier 대상 변경 | **화면에서 켜고 끌 수 있게** (코드에 이름 박지 않음) |

## 4. 구현

- `salesmen.per_unit_tier_enabled` — 영업담당자 편집의 「차등 정산(tier) 적용」 체크박스.
  **사내직원(`user.type=employee`)일 때만** 노출, **`canApprove()` = 「[관리] 이상」만** 수정
  (role `관리` · 업무관리자(manager) · 최고관리자(admin) · 시스템관리자(super)).
- `buyers.is_inherited` + `inherited_from_salesman_id` + `inherited_at` — 바이어 편집의 「승계받은 바이어」.
  같은 권한. 해제 시 부속 2필드도 함께 비운다(ON 일 때만 부속 정보 존재).
- 계산은 `Settlement::employeePerUnitTier($totalMargin, $purchaseTotal, $tierEnabled = true, $inheritedBuyer = false)`.
  **선택 인자라 기존 호출·테스트는 그대로 동작**한다(기본값 = 종전 공식).
- 두 스위치 모두 **돈을 바꾸므로 변경 시 `AuditLog::recordChange`** 로 이력을 남긴다
  (`Salesman`·`Buyer` 엔 감사 훅이 없어 화면 저장 지점에서 직접).

### 마이그레이션이 현행 동작을 보존한다

`per_unit_tier_enabled` 기본값은 false 지만, **과거 tier 상향을 실제로 받은 담당자를 자동 ON** 한다.
판정은 동결된 컬럼값(`settlements.per_unit_amount > 100,000`)만 보는 순수 SQL —
accessor 재계산은 모델 부팅·관계 로드가 필요해 마이그레이션에 부적절하다.

실측(2026-08-04): heymanerp = 무사백만 ON / ssancarerp = 전부 NULL 이라 0명.

⚠️ **karabaerp 는 이 판정을 아예 건너뛴다.** karaba 는 이익율 정산이라 tier 를 안 쓰는데,
`Settlement::saving` 이 `effective_per_unit_amount` 를 **`isKaraba()` 와 무관하게 materialize** 하는 탓에
20만 동결값이 남아 있다(실측: per_unit 29건 중 동결 7건, 최대 200,000 → 홍승환 1명이 걸린다).
정산액에는 영향이 없지만(이익율 공식이 `effective_per_unit_amount` 를 안 씀) 쓰이지도 않는 플래그가
켜져 다음 사람을 헷갈리게 하므로, 마이그레이션이 회사 프로파일을 보고 karaba 면 자동 ON 을 생략한다.

## 5. 영향 범위 (2026-08-04 운영 실측)

| 회사 | per_unit 정산 | 상태 | 배포 영향 |
|---|---|---|---|
| heymanerp | 155건 (무사백 154 · 조하 1) | **전부 paid·동결** | 소급 변동 **0** — 신규 정산부터 |
| ssancarerp | 3건 | 전부 pending·미동결 | tier 상향이 원래 0건이라 금액 변동 **0** |
| karabaerp | 29건 (동결 7) | — | **무관** (`isKaraba()` → 이익율 공식, `effective_per_unit_amount` 미사용). 자동 ON 생략 |

- heymanerp tier 상향 실적: 20만 35건 + 25% 4건(94만~173만) = **전부 무사백**.
- heymanerp 조하 s#575 = 이미 5만원 수동 입력 상태(`13두9434`). **승계 규칙이 수동으로 운용돼 온 정황.**
- ⚠️ ssancarerp 에 **무사백이 3명 중복 등록**(`#10 무사백`, `#11 무사백2`, `#16 무사백`) — 데이터 정리 필요.

## 6. master 배포 전 체크리스트

0. 🚨 **배포만으로는 승계 5만원이 한 건도 적용되지 않는다** — `buyers.is_inherited` 가 전부 false 로 시작한다.
   **어느 바이어가 승계분인지 jin 확인 후 체크**해야 기능이 산다. 후보(heymanerp 실측): 조하 담당 1건
   (그 바이어의 정산 s#575 는 이미 수동 5만원) · 이원호 담당 1건. 무사백 16건은 본인 개척으로 추정.
1. 마이그레이션이 3사에서 도는지 — `salesmen`·`buyers` 컬럼 추가 + 자동 ON 업데이트.
2. 배포 후 **heymanerp 무사백이 실제로 ON 인지 확인**. (자동 ON 이 예상대로 걸렸나)
3. ssancarerp 는 **전원 OFF 가 정상**(jin 확인). 켤 사람 없음.
4. karabaerp 는 값이 바뀌어도 정산액에 영향 없음 — 그래도 `isKaraba()=YES` 재확인.
5. 미동결(pending·confirmed) 정산이 있으면 그 금액이 즉시 바뀐다. 배포 시점에 재확인.

## 7. 테스트

- `SettlementTierPerSalesmanTest` (9) — 우선순위 전 분기 + soft-deleted 바이어 + Setting override + 담당자 없는 정산.
- `SettlementTierScreenTest` (6) — 화면 배선 · **영업이 프로퍼티 주입해도 못 켠다** · 감사로그 · 한글 라벨.
- `SettlementEmployeeTierTest` (8, 기존) — 담당자를 붙이도록 수정. tier 는 이제 담당자 속성이라
  담당자 없이 공식을 검증할 수 없다. **순서 재배치가 동작을 안 바꿨다는 증거가 이 테스트**다.
