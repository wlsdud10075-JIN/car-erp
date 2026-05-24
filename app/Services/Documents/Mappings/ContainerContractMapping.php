<?php

namespace App\Services\Documents\Mappings;

use App\Models\Vehicle;
use App\Services\Documents\DocValue;

/**
 * 선적 — 컨테이너 CONTRACT. 수출 전용. 차량 행 16~19 중 우선 1대=1행(행 16)만.
 * A16(=RIGHT(E16,6))·I16(=F16+G16) 수식 — 엔진 자동 보존.
 */
class ContainerContractMapping
{
    public static function config(): array
    {
        return [
            'template' => 'container_contract.xlsx',
            'sheet' => 'HBB340.',
            'label' => '컨테이너_Contract',
            'cells' => [
                'F4' => fn (Vehicle $v) => $v->container_number ?: $v->bl_loading_location, // Invoice No(컨테이너)
                'F5' => fn (Vehicle $v) => DocValue::invoiceConsignee($v)?->name,           // Name
                'B16' => fn (Vehicle $v) => $v->brand,                                      // Brand
                'C16' => fn (Vehicle $v) => DocValue::carName($v),                          // Model
                'D16' => fn (Vehicle $v) => $v->year,                                       // Year
                'E16' => fn (Vehicle $v) => $v->nice_reg_vin,                               // Chassis No.
                'F16' => fn (Vehicle $v) => $v->sale_price,                                 // FOB PRICE
                'G16' => fn (Vehicle $v) => $v->transport_fee,                              // Shipping cost
            ],
        ];
    }
}
