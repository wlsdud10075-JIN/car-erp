<?php

namespace App\Services\Documents\Mappings;

use App\Models\Vehicle;
use App\Services\Documents\DocValue;
use Illuminate\Support\Collection;

/**
 * 판매 — SALES CONTRACT (영문, 다중차량). 수출 전용. 1바이어·단일통화(컨트롤러 동질성 가드).
 *
 * 양식은 scripts/build-sales-contract-template.php 로 30슬롯 확장됨(슬롯 = 1행, stride 1, first 23,
 * FOB 는 E:G 병합). 런타임(DocumentFiller::fillMulti)이 선택 N대를 채우고 미사용 슬롯 removeRow 트림.
 *
 * 푸터 구조(값칸, 확장 후 좌표):
 * - E53 Subtotal   = SUM(FOB) — footerAggregate(수식, 채운영역 range)
 * - E54 Shipping   = Σ transport_fee                     ┐
 * - E55 Other      = Σ(commission + auto_loading − tax_dc) │ aggregates(값) —
 * - E57 Total      = Subtotal + Shipping + Other          │ 표에 없는 필드라 컬렉션 집계,
 * - E58 Received   = Σ 확정입금                            │ removeRow 안전 위해 수식 아닌 값
 * - E59 Balance    = Total − Received                     ┘
 * - C55 Dollar Rate / C56 Euro Rate = 차량 통화 맞춰 한 칸만 환율(primary).
 * E12(Date)=TODAY() 수식은 매핑 제외(엔진이 자동 보존).
 */
class SalesContractMapping
{
    /** Σ(commission + auto_loading − tax_dc) — "Other Charge" (판매금 base 잔여분). */
    private static function otherCharge(Vehicle $v): int
    {
        return (int) (($v->commission ?? 0) + ($v->auto_loading ?? 0) - ($v->tax_dc ?? 0));
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
                'C7' => fn (Vehicle $v) => trim(($v->incoterms ?: 'CFR').' '.($v->dischargePort?->name ?? '')),
                'C12' => fn (Vehicle $v) => 'SC'.now()->format('ym').'-'.str_pad((string) ($v->id ?? 0), 5, '0', STR_PAD_LEFT), // Contract No
                'C55' => fn (Vehicle $v) => $v->currency === 'USD' ? DocValue::money($v->exchange_rate) : null,   // Dollar Rate
                'C56' => fn (Vehicle $v) => $v->currency === 'EUR' ? DocValue::money($v->exchange_rate) : null,   // Euro Rate
                // 바이어 블록 = ERP 바이어(erp/buyers) 데이터와 일치 (jin 2026-07-10).
                //   passport/ID·Tel·Email·Address 모두 Buyer 레코드에서. (구: 컨사이니 우선 + Email 은 존재않는 ->email 참조 버그)
                'E66' => fn (Vehicle $v) => DocValue::invoiceBuyer($v)?->name,                                    // Buyer 상호
                'E68' => fn (Vehicle $v) => 'Passport/ID number : '.(DocValue::invoiceBuyer($v)?->passport_id ?? ''),  // 여권/ID (바이어)
                'E69' => fn (Vehicle $v) => 'Tel: '.(DocValue::invoiceBuyer($v)?->contact_phone ?? '')
                    .'     Email: '.(DocValue::invoiceBuyer($v)?->contact_email ?? ''),                          // 전화·이메일 (바이어)
                'E70' => fn (Vehicle $v) => 'Address : '.(DocValue::invoiceBuyer($v)?->address ?? ''),           // 주소 (바이어)
            ],
            'multi' => [
                'first' => 23,
                'stride' => 1,
                'count' => 30,
                'slotCells' => [
                    0 => [
                        // ⚠ 외국인 계약서 = 한글 금지. Code=로마자 차량번호, Brand=영문(NICE 한글→영문 변환).
                        'A' => fn (Vehicle $v) => DocValue::romanizePlate($v->vehicle_number), // Code (로마자 차량번호)
                        'B' => fn (Vehicle $v) => DocValue::brandEn($v),          // Brand (영문)
                        'C' => fn (Vehicle $v) => DocValue::carName($v),         // Model
                        'D' => fn (Vehicle $v) => $v->nice_reg_vin,             // Chassis No.
                        'E' => fn (Vehicle $v) => DocValue::money($v->sale_price), // FOB PRICE
                    ],
                ],
                'footerAggregates' => [
                    ['cell' => 'E53', 'fmt' => '=SUM(E%d:E%d)'],   // Subtotal (FOB 합)
                ],
                'aggregates' => [
                    'E54' => fn (Collection $vs) => (int) $vs->sum(fn (Vehicle $v) => $v->transport_fee ?? 0),               // Shipping Cost
                    'E55' => fn (Collection $vs) => (int) $vs->sum(fn (Vehicle $v) => self::otherCharge($v)),                // Other Charge
                    'E57' => fn (Collection $vs) => (int) $vs->sum(fn (Vehicle $v) => ($v->sale_price ?? 0) + ($v->transport_fee ?? 0) + self::otherCharge($v)), // Total
                    'E58' => fn (Collection $vs) => (int) $vs->sum(fn (Vehicle $v) => DocValue::confirmedReceived($v)),      // Received
                    'E59' => fn (Collection $vs) => (int) $vs->sum(fn (Vehicle $v) => ($v->sale_price ?? 0) + ($v->transport_fee ?? 0) + self::otherCharge($v) - DocValue::confirmedReceived($v)), // Balance
                ],
            ],
            // 도장/서명 슬롯은 App\Services\Documents\StampSlots 로 중앙화.
        ];
    }
}
