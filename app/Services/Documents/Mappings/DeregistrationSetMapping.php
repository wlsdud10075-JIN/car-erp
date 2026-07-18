<?php

namespace App\Services\Documents\Mappings;

use App\Models\Vehicle;
use App\Services\Documents\DocValue;

/**
 * 매입 — 말소신청서 + 말소계약서 병합본 (jin 2026-07-18, item 8).
 * 1파일 2시트: 탭1 '1.차량말소신청서'(말소신청서 템플릿) + 탭2 '2.계약서'(계약서 템플릿 graft).
 * DeregistrationMapping(시트1) + DeregistrationContractMapping(시트2)의 셀을 Sheet!Cell 로 통합.
 * 도장/서명은 StampSlots['deregistration_set'](= 계약서 슬롯, 시트 '2.계약서').
 */
class DeregistrationSetMapping
{
    public static function config(): array
    {
        return [
            'template' => 'deregistration_application.xlsx',
            'sheet' => '1.차량말소신청서',
            // 계약서 시트를 신청서 워크북에 graft (DocumentFiller appendSheets).
            'appendSheets' => [
                ['template' => 'deregistration_contract.xlsx', 'sheet' => '2.계약서'],
            ],
            'label' => '말소신청서_계약서',
            'cells' => [
                // ── 탭1: 차량말소신청서 (기본 sheet) ──
                'L1' => fn (Vehicle $v) => DocValue::carName($v),
                'D6' => fn (Vehicle $v) => $v->nice_reg_owner_name,
                'D7' => fn (Vehicle $v) => $v->nice_reg_owner_rrn,
                'D8' => fn (Vehicle $v) => $v->nice_reg_owner_addr,
                'A11' => fn (Vehicle $v) => $v->vehicle_number,
                'E11' => fn (Vehicle $v) => $v->nice_reg_vin,
                'J11' => fn (Vehicle $v) => $v->mileage,
                // ── 탭2: 계약서 (graft 된 시트, Sheet!Cell) ──
                '2.계약서!B50' => fn (Vehicle $v) => $v->nice_reg_vin,
                '2.계약서!D50' => fn (Vehicle $v) => ($v->incoterms ?: 'F.O.B').' INCHOEN PORT',
            ],
        ];
    }
}
