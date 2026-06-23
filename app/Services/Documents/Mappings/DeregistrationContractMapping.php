<?php

namespace App\Services\Documents\Mappings;

use App\Models\Vehicle;

/**
 * 매입 — 말소 계약서 (영문 양식). 노란칸 1개 (BODY NUMBER = 차대번호).
 * 나머지(품목 리스트·조건)는 고정 양식이라 매핑 없음.
 */
class DeregistrationContractMapping
{
    public static function config(): array
    {
        return [
            'template' => 'deregistration_contract.xlsx',
            'sheet' => '2.계약서',
            'label' => '말소계약서',
            'cells' => [
                'B50' => fn (Vehicle $v) => $v->nice_reg_vin,   // BODY NUMBER (차대번호)
            ],
            // 도장/서명 슬롯은 App\Services\Documents\StampSlots 로 중앙화.
        ];
    }
}
