# 지급 게이트웨이 연동 — 계약금 자동이체 (board → car-erp → 하나은행 펌뱅킹)

> **권위 스펙(car-erp).** board는 이 파일을 **경로로 읽고**, board repo의 SKILLS.md/CLAUDE.md엔 **포인터 1줄만** 남긴다(복사 금지 — drift 방지). 연동 B(`purchase-sync-receiver.md`)와 동일한 상호링크 규칙.
> 상태: **설계 단계 / 미구현** (2026-06-18 회의 결정시트 기준). 결정시트 원본 = `docs/meeting-payment-automation-2026-06-18.html`, 펌뱅킹·VAN 액션플랜 = `docs/firmbanking-van-actionplan.html`. car-erp 메모리 키 = `project_payment_automation`("지급 자동화 이어서").

## 1. 전체 구조 (확정 — 결정 #1 몰빵)

```
[board] 영업사원 "송금 요청" 등록 → 영업팀장 board에서 건당 수락(결정 #4)
   → (HMAC 서명 요청) →
[car-erp] 지급 게이트웨이(DisbursementService: 멱등·한도·계좌 화이트리스트·예금주조회·감사 재검증)
   → 하나은행 펌뱅킹 계약금 국내이체(원화) → 성공 시 차량 등록/갱신 → board 회신
```

- **자금 자격증명(VAN·은행 키)은 car-erp 한 곳에만.** board는 "요청"만 보냄 — board가 뚫려도 돈은 안 샘.
- **승인은 board에서 그대로.** car-erp에 영업팀장 직책/전용화면 추가 **불필요**. 승인자 이름은 요청 payload에 담겨와 car-erp 감사로그에 기록.
- car-erp엔 별도 FinanceGate 없음 → **`DisbursementService` 신설**이 게이트웨이 본체.
- 멀티회사: heyman·karaba는 **법인별 별도 계약·계좌**. 우선 heyman부터(현 board 연동도 heyman만). **해외송금은 펌뱅킹 불가**(별도).

## 2. board가 할 일 (board repo 작업)

1. **입력란 신설**: `계약금(deposit_amount)` · `이체완료일` · `거래관리번호`(멱등키/이중송금 방지).
2. **영업팀장 건당 승인 → 즉시 송금 요청**(결정 #4): 수락 시 car-erp 게이트웨이 엔드포인트로 HMAC 서명 요청 전송.
3. 전송 필드 → car-erp 매핑(아래 §4). **car-erp 수신 스펙이 권위** — board는 그에 맞춰 보냄.

## 3. car-erp가 할 일 (car-erp repo 작업 — 이 레포)

- `DisbursementService`: 멱등(거래관리번호 키) · 건당/일일 한도(결정 #8) · 계좌 화이트리스트 · 예금주조회(결정 #7) · 감사로그 · HMAC 검증.
- 수신 엔드포인트(결정 #9 = **한 콜**: 승인→송금→차량 등록/갱신). 연동 B(`purchase-sync`)와 별개 신설 또는 확장.
- 계약금 = `purchase_balance_payments` type=`down`, 이체완료 시 `confirmed`. 남은금액 = (매입가+매도비)−계약금 자동계산.
- car-erp(매입·정산, 고액) 측 송금은 **Jin 승인 버튼 후 실행**(결정 #5).

## 4. board → car-erp 필드 contract

| board 전송 | car-erp 매핑 | 비고 |
|---|---|---|
| vehicle_number, owner_name | 차량번호 / NICE 조회 키 | 기존 |
| 차량금액(final_price) | `purchase_price` | 기존 |
| 배송금액 | **결정 #2** (잠정 `cost_towing` 탁송비 추천 — 운임비는 판매측이라 비추) | 매입측 비용 |
| 통화 | `currency` 또는 매입통화 신설(**결정 #3**) | 송금은 항상 원화 |
| 은행·예금주·계좌 | `purchase_seller_bank/holder/account`(암호화) | 기존 |
| **계약금(deposit_amount)** | PBP type=`down`(이체완료 시 confirmed) | board 입력란 신설 |
| **이체완료일·거래관리번호** | PBP `payment_date` · 감사/멱등키 | 신설(이중송금 방지) |
| (자동) 남은금액 | 매입 미지급 = (매입가+매도비)−계약금 | car-erp 자동계산 |

## 5. 9개 결정 상태 (결정시트 2026-06-18)

| # | 항목 | 값 |
|---|---|---|
| 1 | 구조 | **car-erp 단일 게이트웨이(몰빵)** ✅ |
| 2 | 배송금액 매핑 | 잠정 탁송비(`cost_towing`) 추천 — **확정 필요** |
| 3 | 매입가 통화 | 국내 원화 전제 — **확정 필요**(외화 가능 시 매입통화 신설) |
| 4 | board 트리거 | 건당 승인 → 즉시 송금 ✅(Jin 구상) |
| 5 | car-erp 트리거 | Jin 승인 버튼 후 실행 ✅ |
| 6 | VAN사 | **미정**(쿠콘/웹케시/세틀뱅크 후보) |
| 7 | 예금주 조회 | 전 송금 직전 1회 사용 ✅ |
| 8 | 한도 | **미입력** — board/car-erp 건당·일일 한도 정해야 |
| 9 | 등록+송금 | 한 콜(승인→송금→등록) ✅ |

> ⚠️ #2·#3·#6·#8 미확정. 구현 착수 전 Jin 확정 필요. 은행·VAN 계약(법인등기부·인감·사업자등록증 등)은 코드 무관, Jin이 별도 진행.
