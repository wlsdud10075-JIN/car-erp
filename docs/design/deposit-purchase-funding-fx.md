# 설계문서 — 보증금 매입 자금 흐름(FX funding)

> 상태: **✅ 구현 완료 (dev `c6f24bc`, master 미배포)**. jin 2026-07-20 라운드테이블 + C2 확정 → 2026-07-21 구현.
> 스파이크로 C2 핵심(소스 −FP→여력 자동↓·매입 GREEN) 실증 후 구현. 테스트 PurchaseFundingTest 7 + 회귀 63.
> 코드: 마이그 `2026_07_21_000001` · InterVehicleTransferService(applyPurchaseFunding/approve/confirm) ·
> ApprovalRequest(TYPE_INTER_VEHICLE_PURCHASE_FUNDING) · transfers/index confirm kind분기 · vehicles/index 매입탭 UI.
> 관련 메모리: `project_deposit_apply_feature` · `project_gate_thresholds_and_proof`(item1 여력).

---

## ① 배경 & jin 확정 모델

**목표**: 신용도 높은 바이어의 "보증금 여력"으로 **국내 매입대금(원화)을 선지급** → ERP상 매입 GREEN(매입완료) → 그 선지급액만큼 바이어 미수(외화 채권)로 잡고 → **나중에 그때 환율로 정확히 회수**. 실무자가 물 흐르듯 처리.

**판매측 이동(이미 구현, 863550e)과의 차이**:
- 현행 보증금 적용 = 그 바이어의 다른 선적전 차 **판매측 입금을 신규 차 판매측으로 이동**(FinalPayment 페어). 회사 안 장부이동. 매입 PBP는 안 건드림 → **매입 GREEN 안 됨**.
- 이번 FX funding = **실제 회사 원화가 국내 매입 판매자에게 나가는 outflow**를 바이어 신용으로 funding. 성격이 근본적으로 다름.

---

## ② 확정된 것 (재확인 완료 — 다시 논의 불필요)

**재원 = 「보증금 여력」 (item1, `47f810c` 3사 배포됨)**
- `Buyer::computeReceivableGauge` = **바이어 선적전(출고 전) 총 판매액 × 50% − 미수**.
- 바이어 aggregate 미수율 **≤ 50%면 여력 사용 가능, > 50%면 락**. 완납된 선적전 차도 분모에 포함(미수 0).
- 매입 funding은 이 여력을 **소진**한다 → funding 발생 시 `available_krw`가 그만큼 줄어야 함(재사용 방지).
- ⚠️ 이건 "완납 실잉여만"도 "per-vehicle 50%+"도 아닌 **바이어 단위 여력**. 보안팀이 요구한 "바이어 누적 한도(aggregate cap)"를 구조적으로 자동 충족.

---

## ③ 라운드테이블 6부서 요약 (2026-07-20)

전원 **"지금 코드 급조 금지, 설계 먼저"** (구현가능 0표).

| 부서 | 판정 | 핵심 지적 |
|---|---|---|
| 회계·정산감사 | 추가설계 필수 | 별도 채권 원장. 환차 **별도 필드**(기존 `exchange_difference_krw` 오염 금지). PBP에 funding marker. |
| Engineer | 추가설계 필수 | 재사용 불가(매입 PBP엔 buyer_id·currency·exchange_rate 없음). 신규 테이블 `purchase_deposit_fundings`. |
| QA | 위험 高 | **게이트 역설**(아래 ④-4). 크로스원장 이중계상. sale_price=0 오염. |
| 보안 | 지급승인 사다리 필수 | 실제 outflow → **재무 실물확정 3단 SoD** 계승. 바이어 aggregate cap. transfer_id/confirmed_at 회계락 상속. |
| PO | HOLD | 가치 中·시급 아님(대체경로 존재). MVP=매입탭 여력표시(있음)+PBP 수기입력+메모 추적. |
| Ops | 中 | additive 마이그(CHECK에 FK컬럼 금지·enum 대신 string). 단계 배포. 배포직후 cache rebuild. closed 정산 소급 시 §5-6 lock 선행. |

---

## ④ 확정 모델 = C2 (미수 기반 통합, jin 확정 2026-07-20)

**기록 구조 = C2 확정.** 별도 원장(B) 폐기. **기존 차량간이체(863550e 보증금 적용)를 "매입탭 landing"까지 확장.**

동작: 소스 차(완납/여유 있는 그 바이어 선적전 차)에서 보증금을 끌어 → **매입 대상 차의 매입대금(원화)을 funding** → 소스 미수↑, 매입 GREEN. 여력=50%−미수라 저절로 재계산.

### C1을 버린 이유 (기록)
C1(대상 차 자기 판매 미수에 얹기)은 매입 funding액이 그 차 **자기 판매가를 초과** → 미수율 >100% → 게이지(0~100%)·게이트(50%/100%) 붕괴. C2는 소스 차 미수가 항상 ≤ 판매가라 **모든 미수율 0~100% 유지**. (워크드 예시: S1 $100k 완납 → 여력 $50k → T3 매입 3천만원=$23k funding → C2는 S1 미수 $0→$23k(77% 정상), C1은 T3 미수 158% 붕괴.)

### C2로 자동 해소된 것
- **상계/회수**: 소스 차의 **일반 미수 lifecycle 그대로**. B가 소스 차 미수를 정상 입금으로 갚으면 회수 완료. 별도 상계 로직 불필요.
- **게이트 역설(QA 함정)**: 소스·대상 모두 미수율 정상 → C5/G1 안 깨짐. 대상 차 판매 미수 안 건드림.
- **바이어 aggregate 한도(보안)**: 여력(선적전총액×50%−미수)이 캡 → 자동 충족.
- **이중계상(회계)**: 신규 원장 없이 기존 미수 단일출처(§13)만 씀. B의 총 미수가 사용액만큼 1회 증가(소스에), 대상 매입은 자산측 지급 → 정상 복식.

### C2에서 새로 손댈 것 (2가지)
1. **크로스 통화** — 현행 `assertGuards`는 same-currency 강제(source.currency === target.currency). 매입 landing은 **소스 외화 미수 ↔ 매입 원화**. 매입 원화액 고정, **소스 미수(외화) = 매입원화 ÷ 소스 판매환율**. funding 시점 소스 환율 스냅샷 저장(회수 환차 계산용).
2. **outflow 승인 + marker** — 매입 원화가 실제 국내 판매자에게 나가는 outflow → 현행 deposit_apply의 "admin 즉시적용" 대신 **재무 실물확정 단계**(은행이체 후 확정 시 PBP 생성) + 매입 PBP에 "바이어 funding" marker(감사·현금예측 구분). standard 차량간이체의 관리승인→재무확정 SoD 재사용.

### 환차 귀속 = ✅ 기존 흐름 (jin 2026-07-21 확정)
- 소스 차 미수를 그때 환율로 회수 시 환차 발생 → **기존 2차정산 환차(§5-4, 프리랜서만 반영)로 소스 차 정산에 그대로 흐름.** 별도 필드·회사부담 분리 안 함.
- 근거(jin): 소스 차는 그 영업이 판 거라 그 미수·환차는 원래 그 영업 장부 안의 일. 이미 배포된 standard 차량간이체도 동일 처리 → 일관성.
- ⚠️ 소스 차 ≠ 매입 대상 차의 담당 영업일 수 있음(같은 바이어라도). 그 경우 환차는 소스 차 영업에 붙음(의도된 동작 — 소스 미수라 그 영업 몫). 실무상 같은 바이어면 담당 대개 동일.
- **→ 이로써 모든 설계 결정 종료. 구현 착수 가능.**

---

## ⑤ 권고 데이터 모델 (C2 — 신규 테이블 없음, 기존 재사용)

```
재사용: inter_vehicle_transfers (kind 확장) + FinalPayment(소스 판매 미수) + PurchaseBalancePayment(대상 매입)

inter_vehicle_transfers 추가 컬럼 (additive, string/CHECK-FK 금지):
  kind = 'purchase_funding'      (기존 'standard'/'deposit_apply'에 추가, string(20))
  target_ledger = 'purchase'     (매입 PBP landing 구분 — 기존은 판매 FinalPayment)
  source_exchange_rate           (funding 시점 소스 판매환율 스냅샷 — 회수 환차용)
  purchase_balance_payment_id    (생성된 매입 PBP FK)

실행 (재무 실물확정 시 = 은행이체 후):
  1. 소스 차: FinalPayment −(매입원화 ÷ 소스환율)  [외화, 소스 판매 미수↑]   ← 기존 로직 재사용
  2. 대상 차: PurchaseBalancePayment +매입원화  [KRW, confirmed_at SET, note="바이어 funding #"]
       → getPurchaseUnpaidAmountAttribute 자동 매입 GREEN
  3. 소스 미수 행 marker "→ 차량#T 매입 funding 차감"  /  대상 PBP에 "바이어 funding" 뱃지(실지급 구분)

승인: standard 차량간이체 흐름 재사용 — 기안→관리승인→재무확정(SoD). deposit_apply 즉시적용 아님.
가드: assertGuards 에서 매입 landing 은 same-currency 예외 허용 + 여력(computeReceivableGauge) 캡 검증.
회계락: 생성 FinalPayment/PBP 에 transfer_id + confirmed_at 상속(사후 조작 차단, §8 #27).
회수/환차: 소스 차 미수는 일반 미수라 정상 입금으로 회수 → 그때 환율 차이는 기존 2차정산 환차(§5-4)가 처리.
```

**회귀 불변식(테스트로 못박을 것)**:
1. 매입 funding 후 **소스·대상 미수율 모두 ≤ 100%**(C1 오버플로 없음).
2. 대상 차 매입 GREEN = 매입 PBP로만, **대상 판매 미수 불변**.
3. 소스 FinalPayment(−외화) ↔ 대상 PBP(+원화) **크로스 통화 대칭**(소스환율 스냅샷 기준).
4. funding 으로 여력(`available_krw`)이 소스 미수 증가분만큼 감소(이중사용 방지).
5. outflow 는 **재무 실물확정 전 PBP 미생성**(승인만으로 GREEN 안 됨).

---

## ⑥ 단계 배포 (Ops 권고)
1. **1차** — 스키마(additive) + 매입탭 "여력/funding 표시"만, 회계 공식 미반영. 마이그 3-DB 검증(CHECK에 FK컬럼 금지).
2. **배포직후** `vehicles:rebuild-caches` 1회(미수 공식에 항목 추가 시).
3. **2차** — 미수·정산 반영 로직. 업무시간 외 + `gh run watch` 3잡 green. closed 정산 소급이면 §5-6 lock 재설계 선행.
4. **취소 = 반대거래 추가**(append-only). 물리 delete 금지(§8 #27).

---

## ⑦ PO 대안 (참고) — MVP 없이도 실무 대응
회계 완결 전이라도: 매입탭 여력 표시(이미 있음) + 재무가 **기존 매입 PBP 수기입력** + note "OO바이어 신용 funding" → 자동화 없이 추적만. jin이 "지금 급하지 않다" 판단 시 이걸로 버티고 정식 구현은 큐 하단.
