<?php

namespace App\Services\Documents\Mappings;

use App\Models\Vehicle;
use App\Services\Documents\DocValue;

/**
 * 매입 — 자동차말소등록신청서 (별지 제17호). 단일차량. GAP 없음.
 * 노란칸 9개 중 A24(=TODAY 수식)·N1(사진/직인)은 매핑 제외 (자동 보존·공란).
 */
class DeregistrationMapping
{
    public static function config(): array
    {
        return [
            'template' => 'deregistration_application.xlsx',
            'sheet' => '1.차량말소신청서',
            'label' => '말소신청서',
            'cells' => [
                'L1' => fn (Vehicle $v) => DocValue::carName($v),       // 차량명
                'D6' => fn (Vehicle $v) => $v->nice_reg_owner_name,     // 소유자 성명
                'D7' => fn (Vehicle $v) => $v->nice_reg_owner_rrn,      // 주민(법인)등록번호 (accessor 복호화)
                'D8' => fn (Vehicle $v) => $v->nice_reg_owner_addr,     // 사용본거지(주소)
                'A11' => fn (Vehicle $v) => $v->vehicle_number,        // 자동차등록번호
                'E11' => fn (Vehicle $v) => $v->nice_reg_vin,          // 차대번호
                'J11' => fn (Vehicle $v) => $v->mileage,               // 주행거리
            ],
        ];
    }
}
