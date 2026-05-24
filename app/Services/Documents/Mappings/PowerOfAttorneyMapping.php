<?php

namespace App\Services\Documents\Mappings;

use App\Models\Vehicle;
use App\Services\Documents\DocValue;

/**
 * 매입 — 위임장. 노란칸 5개 중 O1(직인)은 공란.
 * 행 5에 등록번호·차대번호·차명·연식 4필드.
 */
class PowerOfAttorneyMapping
{
    public static function config(): array
    {
        return [
            'template' => 'power_of_attorney.xlsx',
            'sheet' => '4.위임장',
            'label' => '위임장',
            'cells' => [
                'A5' => fn (Vehicle $v) => $v->vehicle_number,    // 자동차(건설기계) 등록번호
                'E5' => fn (Vehicle $v) => $v->nice_reg_vin,      // 차대번호
                'I5' => fn (Vehicle $v) => DocValue::carName($v), // 차명
                'L5' => fn (Vehicle $v) => $v->year,              // 연식
            ],
        ];
    }
}
