# 카라바 매입탭 캐스케이드 + 비용 12개 + 이익율 정산

> 상태: **설계 확정 (미구현)** — 2026-07-22, jin 합의. dev 착수 대기.
> 정본 서식 = `Desktop/Karaba/★26년 매입대장-카라바★-260102.xlsx` 탭 `26년매입대장-카라바` (수식 실측).
> 게이팅 = `Setting::isKaraba()`. heyman/ssancar 무영향.
> 관련: [[project_karaba_customization]] · [[project_karaba_evidence_cascade]] · [[project_settlement_v2_groupware_design]]

## A. 매입탭 2단 캐스케이드 + 매매상 체크

**필드 = 매입등록(1단) → 증빙유형(2단, 자동 캐스케이드) + 매매상 체크박스**

라벨: 기존 "매입증빙" → **매입등록**(1단). 2단 = **증빙유형**.

```
매입등록(1단)      →  증빙유형(2단)
├ 일반매입         → 세금계산서 / 대체서류 / 영세율 및 기타
├ 의제매입         → 개인 / 개인사업자(비사업용 차량확인서) / 간이과세자
├ 혼합매입         → 의제매입+세금계산서 / 의제매입+대체서류 / 세금계산서+대체서류
├ 리스/캐피탈       → 세금계산서+의제매입 / 승계서+의제매입 / 불공제
├ 구매대행         → (2단 없음)
└ 선적대행         → (2단 없음)

[☐ 매매상]  별도 체크박스 → 체크 + 계약금 입력 + 잔금 미납 시 10일 잔금 알림
```

- 캐스케이드 맵은 매입탭.png(2026-07-22, 최신) + 업무프로세스 정본. **임의 변경 금지.**
- 컬럼: 1단 = 신규 `purchase_registration_type` / 2단 = 신규 `purchase_evidence_subtype` (기존 flat `purchase_evidence_type`·`purchase_partner_type`은 karaba 소량 데이터라 정리/이관). 최종 컬럼명은 구현 시 확정.

## B. 매매상 알림 재연결 (blocker 해소)

- 기존: `purchase_partner_type === '매매상'` 리터럴 감지(`Vehicle.php:1428`/`:1658`).
- 변경: **신규 boolean `is_dealer_purchase`** 체크박스로 트리거 이동. 캐스케이드와 독립 → 어느 매입등록 유형이든 매매상 체크만 하면 알림. 알림 로직(scopeAction `purchase_balance_due`·accessor) 조건만 교체, 나머지 유지.

## C. 매입세액(VAT) 입력칸

- 신규 컬럼 `purchase_vat_amount` (int, KRW). 매입탭 **매입가 옆**. 재무가 세금계산서 세액 **수기 입력**. 정산 DT.
- 자동계산 안 함(의제공제율·영세율·불공제 = 세무 규칙 하드코딩 위험). karaba 프로파일 전용.

## D. 비용 — 이미지 12개 (부품은 수입으로 분리)

| # | 항목 | 컬럼 | 상태 |
|---|---|---|---|
| 1 | 말소비 | `cost_deregistration` | 기존 |
| 2 | 이전비 | `cost_transfer` | 기존 |
| 3 | 면허비용 | `cost_license` | 기존 |
| 4 | 탁송비 | `cost_towing` | 기존 |
| 5 | 캐리어비 | `cost_carry` | 기존 |
| 6 | 보험료 | `cost_insurance` | 기존 |
| 7 | 점검비 | `cost_inspection` | ⭐신규 |
| 8 | 성능비 | `cost_performance` | ⭐신규 |
| 9 | 정비비용 | `cost_repair` | ⭐신규 |
| 10 | 광고비용 | `cost_advertising` | ⭐신규 |
| 11 | 기타비용1 | `cost_extra1` | 기존 |
| 12 | 기타비용2 | `cost_extra2` | 기존 |

- **신규 컬럼 4개**: cost_inspection·cost_performance·cost_repair·cost_advertising.
- **쇼링(`cost_shoring`)** = karaba UI 숨김(컬럼 유지, heyman/ssancar 계속 사용).
- **부품** = 비용 아님 → **판매 수입**(엑셀 AN/AS, 총판매가 반영). 매입 비용에서 제외. (엑셀 그대로, jin 2026-07-22)

## E. 이익율 정산 (karaba 전용 strategy) — 엑셀 수식 실측 그대로

**정본 매입대장 수식 (권위):**
```
DN 부대비용합계 = 선적+쇼링+탁송+주유외+캐리어+견인+보험+점검+주차+관세수수료+말소+이전+낙찰수수료+기타1+2+3
DP 부대비용(정산) = DN − 선적(CB) − 쇼링(CE)          ← 선적운임·쇼링만 제외
DO 구매가 = 차대금(U) + 매도비(V)
DQ 판매가 = 차대금외화(AL) × 환율(AP)                  ← 운임·부품 제외, 차대금만
DT 매입세액 = 세액(AG)
DX 영업이익 = DQ − (DO + DP − DT)
DY 이익율 = 영업이익 / 판매가
EC/ED/EE tier = ROUNDDOWN(SUMIF(이익율,밴드,영업이익)×비율, -1)
   ≥6%→×20% / 5%~6%→×15% / <5%→×10%
EH 지급예정 = EC+ED+EE − 서류공제(EF) − 비용공제(EG)
```

**우리 모델 번역:**
```
구매가        = purchase_price + selling_fee
부대비용(정산) = 12개 비용 합 (쇼링·선적운임은 애초에 12개에 없음 → 12개 전부 차감)
판매가        = sale_price × exchange_rate   (운임 transport_fee·부품 제외 — 차대금 판매가만)
매입세액       = purchase_vat_amount (수기)
영업이익       = 판매가 − (구매가 + 부대비용 − 매입세액)
이익율         = 영업이익 / 판매가
tier: ≥6%→20% / 5~6%→15% / <5%→10%   (6% 배타경계 — 엑셀 중복은 버그, ERP는 배타)
지급 = ROUNDDOWN(tier금액, 10원 절사) − 서류공제 − 비용공제
가드: 영업이익 음수 → 지급 0 바닥 (jin 2026-07-08)
```

- **포함/제외 규칙 = 선적운임·쇼링만 제외, 나머지 부대비용 전부 영업이익 차감.** 12개 비용은 쇼링·선적운임을 안 담으니 **전부 차감**.
- `Setting::isKaraba()` 게이팅으로 별도 settlement strategy. heyman/ssancar 총마진 공식(부가세마진=구입가×9%·×0.9)과 완전 분리.
- VAT율 파라미터화(`settlement_vat_margin_rate` 등)는 이미 배포됨 — karaba tier는 그와 별개 strategy.

## 신규 컬럼 요약 (마이그, karaba 프로파일 전용·3-DB 안전)
- `purchase_registration_type` (매입등록 1단)
- `purchase_evidence_subtype` (증빙유형 2단)
- `is_dealer_purchase` (boolean, 매매상 알림)
- `purchase_vat_amount` (int, 매입세액)
- `cost_inspection` / `cost_performance` / `cost_repair` / `cost_advertising` (비용 4개)
- 부품 수입: 판매쪽 필드(기존 `sale_other_costs` 활용 검토 or 신규)

## 구현 phase (제안)
1. **매입탭 캐스케이드** — 매입등록+증빙유형 2단 + 매매상 체크 + 알림 재연결. 라벨 변경.
2. **비용 12개** — 신규 4컬럼 + UI + 쇼링 숨김 + 부품 수입 이동.
3. **이익율 정산 strategy** — 매입세액 칸 + karaba tier settlement (computed) + 음수 0 바닥.

각 phase 테스트(KarabaPurchaseTabTest·KarabaSettlementTest 신규). heyman/ssancar 회귀 0 (게이팅). master 3사 배포는 완성·테스트 후 jin 별도 승인 ([[feedback_three_company_deploy_safety]]).

## 미결/구현 시 확인
- 컬럼 재사용 vs 신규 최종 결정(기존 flat 2컬럼 정리 방식).
- 부품 수입 필드 위치(sale_other_costs vs 신규).
- 서류공제(EF)·비용공제(EG) 입력 UI(기존 Settlement.document_fee/other_deduction 재사용).
