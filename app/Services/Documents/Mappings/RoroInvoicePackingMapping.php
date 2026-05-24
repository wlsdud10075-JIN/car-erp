<?php

namespace App\Services\Documents\Mappings;

use App\Models\Vehicle;
use App\Services\Documents\DocValue;

/**
 * 선적 — RORO Invoice & Packing. 수출 전용.
 * RORO 는 차량을 연속 행(21·22·…)에 나열. 우선 1대=1행(행 21)만. 그룹은 추후.
 * G12(Notify party)는 car-erp 대응 없음 → 공란. I21(=H21) 수식 보존.
 */
class RoroInvoicePackingMapping
{
    public static function config(): array
    {
        return [
            'template' => 'roro_invoice_packing.xlsx',
            'sheet' => 'INVOICE',
            'label' => 'RORO_Invoice_Packing',
            'cells' => [
                'B9' => fn (Vehicle $v) => DocValue::consigneeBlock($v),
                'I15' => fn (Vehicle $v) => $v->bl_loading_location,                        // 반입지
                'B16' => fn (Vehicle $v) => $v->port_of_loading,                            // Port of loading
                'E16' => fn (Vehicle $v) => DocValue::destinationCountry($v),               // Discharge
                'C21' => fn (Vehicle $v) => $v->brand,
                'D21' => fn (Vehicle $v) => DocValue::carName($v),
                'E21' => fn (Vehicle $v) => $v->year,
                'F21' => fn (Vehicle $v) => $v->nice_reg_vin,
                'G21' => fn (Vehicle $v) => 1,
                'H21' => fn (Vehicle $v) => $v->sale_price,
                'J21' => fn (Vehicle $v) => $v->nice_spec_curb_weight ?: $v->weight_kg,     // Weight(KG)
                'L21' => fn (Vehicle $v) => $v->transport_fee,                              // Shipping
                'C32' => fn (Vehicle $v) => $v->incoterms,                                  // Incoterms
            ],
        ];
    }
}
