<?php

namespace App\Services\Documents\Mappings;

use App\Models\Vehicle;
use App\Services\Documents\DocValue;

/**
 * 선적 — RORO CONTRACT. 수출 전용. 컨테이너 CONTRACT 와 동일 구조(HBB340.).
 * 차량 행 16~19 중 우선 1대=1행(행 16)만. 수식 자동 보존.
 */
class RoroContractMapping
{
    public static function config(): array
    {
        return [
            'template' => 'roro_contract.xlsx',
            'sheet' => 'HBB340.',
            'label' => 'RORO_Contract',
            'cells' => [
                'F4' => fn (Vehicle $v) => $v->container_number ?: $v->bl_loading_location,
                'F5' => fn (Vehicle $v) => DocValue::invoiceConsignee($v)?->name,
                'B16' => fn (Vehicle $v) => $v->brand,
                'C16' => fn (Vehicle $v) => DocValue::carName($v),
                'D16' => fn (Vehicle $v) => $v->year,
                'E16' => fn (Vehicle $v) => $v->nice_reg_vin,
                'F16' => fn (Vehicle $v) => $v->sale_price,
                'G16' => fn (Vehicle $v) => $v->transport_fee,
            ],
        ];
    }
}
