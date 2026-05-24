<?php

namespace App\Services\Documents\Mappings;

use App\Models\Vehicle;
use App\Services\Documents\DocValue;

/**
 * 선적 — 컨테이너 Invoice & Packing. 수출 전용.
 * 다중차량(행 21·24·27·30) 양식이나 우선 1대=1행(행 21 + 연료/배기량 행 22)만.
 * 그룹(여러 대) 기입은 추후 b단계. I21(=H21)은 수식 — 엔진 자동 보존.
 */
class ContainerInvoicePackingMapping
{
    public static function config(): array
    {
        return [
            'template' => 'container_invoice_packing.xlsx',
            'sheet' => 'INVOICE',
            'label' => '컨테이너_Invoice_Packing',
            'cells' => [
                'B9' => fn (Vehicle $v) => DocValue::consigneeBlock($v),                    // 수하인(account & risk)
                'I15' => fn (Vehicle $v) => $v->bl_loading_location,                        // 반입지
                'B16' => fn (Vehicle $v) => $v->port_of_loading,                            // Port of loading
                'E16' => fn (Vehicle $v) => DocValue::destinationCountry($v),               // Discharge(목적국)
                'I17' => fn (Vehicle $v) => $v->container_number,                           // CONTAINER NO
                'C21' => fn (Vehicle $v) => $v->brand,                                      // Commodity(maker)
                'D21' => fn (Vehicle $v) => DocValue::carName($v),                          // Model
                'E21' => fn (Vehicle $v) => $v->year,                                       // Year
                'F21' => fn (Vehicle $v) => $v->nice_reg_vin,                               // Chassis No.
                'G21' => fn (Vehicle $v) => 1,                                              // Q'TY
                'H21' => fn (Vehicle $v) => $v->sale_price,                                 // Unit Price
                'J21' => fn (Vehicle $v) => $v->nice_spec_curb_weight ?: $v->weight_kg,     // Weight(KG)
                'L21' => fn (Vehicle $v) => $v->transport_fee,                              // Shipping
                'E22' => fn (Vehicle $v) => $v->nice_reg_fuel_type,                         // TYPE OF FUEL
                'G22' => fn (Vehicle $v) => $v->nice_spec_displacement,                     // PISTON DISPLACEMENT
                'D37' => fn (Vehicle $v) => $v->incoterms,                                  // Incoterms
            ],
        ];
    }
}
