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
            // 도장/서명 오버레이 — A60 양식 기본 = 서명만(회사정보 미포함)이라 교체가 안전.
            // 기능설정에서 회사(template_set)별 서명 업로드 시 그 이미지로 교체, 없으면 기본 유지.
            'stamps' => [
                ['role' => 'signature', 'anchor' => 'A60', 'width' => 612, 'height' => 179],
            ],
        ];
    }
}
