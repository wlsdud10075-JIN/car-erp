<?php

namespace App\Services\Documents\Mappings;

use App\Models\Vehicle;
use App\Services\Documents\DocValue;

/**
 * 선적 — 컨테이너 Invoice & Packing. 수출 전용. 다중차량.
 *
 * 양식은 scripts/extend_shipping_templates.php 로 30슬롯 확장됨(슬롯 = 3행: main+연료+spacer,
 * stride 3, first 21). 런타임(DocumentFiller)이 선택 N대만큼 슬롯을 채우고 나머지는 removeRow 로
 * 트림한다. footer 집계(I/J/K/L)는 채운 영역 range 로 재기록(removeRow 가 range 자동축소 안 함).
 * I21(=H21)·M21(=SUM(I,L)) 등 per-row 수식은 슬롯에 이미 박혀 자동 보존.
 */
class ContainerInvoicePackingMapping
{
    public static function config(): array
    {
        return [
            'template' => 'container_invoice_packing.xlsx',
            'sheet' => 'INVOICE',
            'label' => '컨테이너_Invoice_Packing',
            'currencyAware' => true,   // 판매통화 적응 ($→통화기호) — 2026-06-24
            // 슬롯 위/아래 1회 기입 (primary = 선택 첫 차량). D112 = 확장본의 Incoterms(슬롯 아래로 이동).
            'header' => [
                'B9' => fn (Vehicle $v) => DocValue::consigneeBlock($v),       // 수하인(account & risk)
                'I15' => fn (Vehicle $v) => $v->bl_loading_location,           // 반입지
                'B16' => fn (Vehicle $v) => $v->port_of_loading,              // Port of loading
                'E16' => fn (Vehicle $v) => DocValue::destinationCountry($v),  // Discharge(목적국)
                'I17' => fn (Vehicle $v) => $v->container_number,             // CONTAINER NO
                'D112' => fn (Vehicle $v) => $v->incoterms,                   // Incoterms (footer)
            ],
            'multi' => [
                'first' => 21,
                'stride' => 3,
                'count' => 30,
                'footerAggregates' => [
                    ['cell' => 'I111', 'fmt' => '=SUM(I%d:I%d)'],   // AMOUNT 합
                    ['cell' => 'J111', 'fmt' => '=SUM(J%d:J%d)'],   // Weight 합
                    ['cell' => 'K111', 'fmt' => '=SUM(K%d:K%d)'],   // CBM 합
                    ['cell' => 'L111', 'fmt' => '=SUM(L%d:L%d)'],   // Shipping 합
                ],
                'slotCells' => [
                    0 => [   // main 행
                        'C' => fn (Vehicle $v) => $v->brand,                                  // maker
                        'D' => fn (Vehicle $v) => DocValue::carName($v),                      // model
                        'E' => fn (Vehicle $v) => $v->year,                                  // year
                        'F' => fn (Vehicle $v) => $v->nice_reg_vin,                          // chassis no.
                        'G' => fn (Vehicle $v) => 1,                                         // Q'TY
                        'H' => fn (Vehicle $v) => DocValue::money($v->sale_price),            // unit price
                        'J' => fn (Vehicle $v) => $v->nice_spec_curb_weight ?: $v->weight_kg, // weight(KG)
                        'L' => fn (Vehicle $v) => DocValue::money($v->transport_fee),         // shipping
                    ],
                    1 => [   // 연료/배기량 sub-row
                        'E' => fn (Vehicle $v) => $v->nice_reg_fuel_type,                    // type of fuel
                        'G' => fn (Vehicle $v) => $v->nice_spec_displacement,                // piston displacement
                    ],
                ],
            ],
            // 도장/서명 슬롯은 App\Services\Documents\StampSlots 로 중앙화.
        ];
    }
}
