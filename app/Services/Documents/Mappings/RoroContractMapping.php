<?php

namespace App\Services\Documents\Mappings;

use App\Models\Vehicle;
use App\Services\Documents\DocValue;

/**
 * 선적 — RORO CONTRACT. 수출 전용. 다중차량. 컨테이너 CONTRACT 와 동일 구조(HBB340., 30슬롯 확장).
 * 슬롯 = 1행, stride 1, first 16. per-row 수식(A=RIGHT, I=F+G) 자동 보존, footer range 재기록.
 */
class RoroContractMapping
{
    public static function config(): array
    {
        return [
            'template' => 'roro_contract.xlsx',
            'sheet' => 'HBB340.',
            'label' => 'RORO_Contract',
            'header' => [
                'F4' => fn (Vehicle $v) => $v->container_number ?: $v->bl_loading_location,
                'F5' => fn (Vehicle $v) => DocValue::invoiceConsignee($v)?->name,
            ],
            'multi' => [
                'first' => 16,
                'stride' => 1,
                'count' => 30,
                'footerAggregates' => [
                    ['cell' => 'F46', 'fmt' => '=SUM(F%d:G%d)'],
                    ['cell' => 'I46', 'fmt' => '=SUM(F%d:F%d)'],
                    ['cell' => 'I47', 'fmt' => '=SUM(G%d:G%d)'],
                ],
                'slotCells' => [
                    0 => [
                        'B' => fn (Vehicle $v) => $v->brand,
                        'C' => fn (Vehicle $v) => DocValue::carName($v),
                        'D' => fn (Vehicle $v) => $v->year,
                        'E' => fn (Vehicle $v) => $v->nice_reg_vin,
                        'F' => fn (Vehicle $v) => DocValue::money($v->sale_price),
                        'G' => fn (Vehicle $v) => DocValue::money($v->transport_fee),
                    ],
                ],
            ],
            // 직인 오버레이 — B59 합성블록(회사정보+직인). 컨테이너 계약서와 동일.
            'stamps' => [
                ['role' => 'seal', 'anchor' => 'B59', 'width' => 610, 'height' => 405],
            ],
        ];
    }
}
