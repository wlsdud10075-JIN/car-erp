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
                // D50:E50 = FOB 조건. 앞 인코텀즈(F.O.B)를 ERP 수출통관 탭 incoterms(FOB/CFR)에서.
                // 미입력이면 기존 'F.O.B' 유지. 'INCHOEN PORT'는 템플릿 표기 그대로.
                'D50' => fn (Vehicle $v) => ($v->incoterms ?: 'F.O.B').' INCHOEN PORT',
            ],
            // 도장/서명 슬롯은 App\Services\Documents\StampSlots 로 중앙화.
        ];
    }
}
