<?php

namespace App\Services\Documents\Mappings;

use App\Models\Vehicle;
use App\Services\Documents\DocValue;
use Illuminate\Support\Collection;

/**
 * 판매 — SALES CONTRACT (영문, 다중차량). 수출 전용. 1바이어·단일통화(컨트롤러 동질성 가드).
 *
 * 2026-07-29 레이아웃 전면 개편 (jin 새 디자인 원본 반영, scripts/build-sales-contract-template.php).
 *   구: 8열(A~H)·슬롯 23~52·푸터 53~59 → 신: 26열(A~Z)·슬롯 22~51·푸터 52~57.
 *   표에 **차량별 SHIPPING·TOTAL 컬럼 신설** — 운임비가 푸터 집계에서 행 단위로 내려왔다.
 *
 * 슬롯 컬럼(병합 앵커): A Code / E Brand / I Model / M Chassis / R FOB / U SHIPPING / X TOTAL(수식)
 *
 * 푸터 (jin 2026-07-29 확정 배치):
 *   R52·U52·X52  Sub Total     = 표 3열 SUM — footerAggregate(수식, 채운영역 range)
 *   R53          Other Charge  = Σ(commission + auto_loading − tax_dc)   ┐
 *   R54          Total Amount  = Sub Total(X) + Other Charge             │ aggregates(값) —
 *   R55          Received      = Σ 확정입금                               │ 표에 없는 필드라 컬렉션 집계,
 *   R56          Deposit       = Σ 적립금 사용(savings_used)              │ removeRow 안전 위해 수식 아닌 값
 *   R57          Balance       = Total − Received − Deposit              ┘
 *
 * ⚠️ **Deposit = 적립금이지 계약금이 아니다**(jin 2026-07-29). 계약금은 확정 FP 라 Received 에 이미 들어간다
 *    — Deposit 에 계약금을 넣으면 이중계상된다. 원본 샘플의 `Balance = Total − Received + Deposit`(더하기)도
 *    오류라 **빼기**로 바로잡았다.
 *
 * ⚠️ 환율 행(USD/Euro Rate)은 **삭제**(jin 2026-07-29). 구 매핑의 C55/C56 은 갈 곳이 없다.
 * R12(Date)=TODAY() 수식은 매핑 제외(엔진이 자동 보존).
 */
class SalesContractMapping
{
    /** Σ(commission + auto_loading − tax_dc) — "Other Charge" (판매금 base 잔여분). */
    private static function otherCharge(Vehicle $v): int
    {
        return (int) (($v->commission ?? 0) + ($v->auto_loading ?? 0) - ($v->tax_dc ?? 0));
    }

    /** 표 합계(Sub Total X열) 기준 — 차량별 FOB + SHIPPING. */
    private static function rowTotal(Vehicle $v): int
    {
        return (int) (($v->sale_price ?? 0) + ($v->transport_fee ?? 0));
    }

    public static function config(): array
    {
        return [
            'template' => 'sales_contract.xlsx',
            'sheet' => 'CONTRACT',
            'label' => '판매계약서',
            'currencyAware' => true,   // 판매통화 적응 ($ → 통화기호)
            'header' => [
                // Delivery Term — 수출통관탭 incoterms(예 CFR) + 목적항(영문). 미입력 시 'CFR'.
                //   ⚠ 외국인 계약서 = 한글 금지 → 목적항은 입력 영문 항구(dischargePort)만. 한글 국가명 fallback 사용 안 함.
                'E7' => fn (Vehicle $v) => trim(($v->incoterms ?: 'CFR').' '.($v->dischargePort?->name ?? '')),
                // Contract No = Invoice No 와 동일 규칙(이니셜+차대번호 끝자리 숫자) — jin 2026-07-29.
                'E12' => fn (Vehicle $v) => DocValue::invoiceNo($v),
                // 바이어 블록 = ERP 바이어(erp/buyers) 데이터와 일치 (jin 2026-07-10).
                //   passport/ID·Tel·Email·Address 모두 Buyer 레코드에서.
                'P64' => fn (Vehicle $v) => DocValue::invoiceBuyer($v)?->name,                                    // Buyer 상호
                'P66' => fn (Vehicle $v) => 'Passport/ID number : '.(DocValue::invoiceBuyer($v)?->passport_id ?? ''),  // 여권/ID (바이어)
                'P67' => fn (Vehicle $v) => 'Tel: '.(DocValue::invoiceBuyer($v)?->contact_phone ?? '')
                    .'     Email: '.(DocValue::invoiceBuyer($v)?->contact_email ?? ''),                          // 전화·이메일 (바이어)
                'P68' => fn (Vehicle $v) => 'Address : '.(DocValue::invoiceBuyer($v)?->address ?? ''),           // 주소 (바이어)
            ],
            'multi' => [
                'first' => 22,
                'stride' => 1,
                'count' => 30,
                'slotCells' => [
                    0 => [
                        // ⚠ 외국인 계약서 = 한글 금지. Code=로마자 차량번호, Brand=영문(NICE 한글→영문 변환).
                        'A' => fn (Vehicle $v) => DocValue::romanizePlate($v->vehicle_number), // Code (로마자 차량번호)
                        'E' => fn (Vehicle $v) => DocValue::brandEn($v),          // Brand (영문)
                        'I' => fn (Vehicle $v) => DocValue::carName($v),         // Model
                        'M' => fn (Vehicle $v) => $v->nice_reg_vin,             // Chassis No.
                        'R' => fn (Vehicle $v) => DocValue::money($v->sale_price),      // FOB PRICE
                        'U' => fn (Vehicle $v) => DocValue::money($v->transport_fee),   // SHIPPING (차량별)
                        // X(TOTAL)=슬롯에 박힌 =SUM(R:W) 수식 — 매핑 대상 아님.
                    ],
                ],
                'footerAggregates' => [
                    ['cell' => 'R52', 'fmt' => '=SUM(R%d:R%d)'],   // Sub Total — FOB 합
                    ['cell' => 'U52', 'fmt' => '=SUM(U%d:U%d)'],   // Sub Total — SHIPPING 합
                    ['cell' => 'X52', 'fmt' => '=SUM(X%d:X%d)'],   // Sub Total — TOTAL 합
                ],
                'aggregates' => [
                    'R53' => fn (Collection $vs) => (int) $vs->sum(fn (Vehicle $v) => self::otherCharge($v)),                        // Other Charge
                    'R54' => fn (Collection $vs) => (int) $vs->sum(fn (Vehicle $v) => self::rowTotal($v) + self::otherCharge($v)),   // Total Amount
                    'R55' => fn (Collection $vs) => (int) $vs->sum(fn (Vehicle $v) => DocValue::confirmedReceived($v)),              // Received
                    'R56' => fn (Collection $vs) => (int) $vs->sum(fn (Vehicle $v) => $v->savings_used ?? 0),                        // Deposit (적립금)
                    'R57' => fn (Collection $vs) => (int) $vs->sum(fn (Vehicle $v) => self::rowTotal($v) + self::otherCharge($v)
                        - DocValue::confirmedReceived($v) - ($v->savings_used ?? 0)),                                                // Balance
                ],
            ],
            // 도장/서명 슬롯은 App\Services\Documents\StampSlots 로 중앙화.
        ];
    }
}
