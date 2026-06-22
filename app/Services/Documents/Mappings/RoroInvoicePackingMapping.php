<?php

namespace App\Services\Documents\Mappings;

use App\Models\Vehicle;
use App\Services\Documents\DocValue;

/**
 * 선적 — RORO Invoice & Packing. 수출 전용. 다중차량.
 *
 * 양식은 30슬롯 확장됨(슬롯 = 1행, stride 1, first 21 — 연료 sub-row 없음). C52 = 확장본 Incoterms.
 * footer 집계(I/J/K/L row 51)는 채운 영역 range 로 재기록. G12(Notify party)는 car-erp 대응 없음 → 공란.
 */
class RoroInvoicePackingMapping
{
    public static function config(): array
    {
        return [
            'template' => 'roro_invoice_packing.xlsx',
            'sheet' => 'INVOICE',
            'label' => 'RORO_Invoice_Packing',
            'header' => [
                'B9' => fn (Vehicle $v) => DocValue::consigneeBlock($v),
                'I15' => fn (Vehicle $v) => $v->bl_loading_location,          // 반입지
                'B16' => fn (Vehicle $v) => $v->port_of_loading,             // Port of loading
                'E16' => fn (Vehicle $v) => DocValue::destinationCountry($v), // Discharge
                'C52' => fn (Vehicle $v) => $v->incoterms,                   // Incoterms (footer)
            ],
            'multi' => [
                'first' => 21,
                'stride' => 1,
                'count' => 30,
                'footerAggregates' => [
                    ['cell' => 'I51', 'fmt' => '=SUM(I%d:I%d)'],
                    ['cell' => 'J51', 'fmt' => '=SUM(J%d:J%d)'],
                    ['cell' => 'K51', 'fmt' => '=SUM(K%d:K%d)'],
                    ['cell' => 'L51', 'fmt' => '=SUM(L%d:L%d)'],
                ],
                'slotCells' => [
                    0 => [
                        'C' => fn (Vehicle $v) => $v->brand,
                        'D' => fn (Vehicle $v) => DocValue::carName($v),
                        'E' => fn (Vehicle $v) => $v->year,
                        'F' => fn (Vehicle $v) => $v->nice_reg_vin,
                        'G' => fn (Vehicle $v) => 1,                                         // Q'TY
                        'H' => fn (Vehicle $v) => DocValue::money($v->sale_price),
                        'J' => fn (Vehicle $v) => $v->nice_spec_curb_weight ?: $v->weight_kg, // weight(KG)
                        'L' => fn (Vehicle $v) => DocValue::money($v->transport_fee),         // shipping
                    ],
                ],
            ],
            // 서명 오버레이 — H55 합성블록(상호+서명). 컨테이너 인보이스와 동일 역할.
            'stamps' => [
                ['role' => 'signature', 'anchor' => 'H55', 'width' => 490, 'height' => 234],
            ],
        ];
    }
}
