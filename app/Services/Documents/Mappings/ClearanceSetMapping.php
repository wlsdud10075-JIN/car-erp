<?php

namespace App\Services\Documents\Mappings;

use App\Models\Vehicle;
use App\Services\Documents\DocValue;

/**
 * 통관 — 통관 SET (8시트). 마스터 시트 `구매리스트` 40칸만 채우면
 * 한글/영문등록증·말소증·차량인보이스·차량팩킹·Travel Invoice 6시트가
 * `=구매리스트!셀` 수식으로 자동연동(검증완료). 매핑은 구매리스트만.
 *
 * 매핑 제외:
 * - 매매업 등록번호(D3·G3): NICE 비제공·거래신고 관리번호 — 사용자 수기 (공란)
 * - I6·I13: 템플릿 수식 — 엔진이 자동 보존
 * NICE 칸(형식·제원관리번호·출력)은 nice_raw 에서 읽음 → NICE 연동 전엔 공란.
 * 기통수(G12)·검사시작(I10)·검사종료(I11)는 nice_raw 의 engineSpec·resValidPeriod 를
 *   서류 생성 시점에 파싱(DocValue::niceCylinders/niceInspectionStart/End) — 전용 컬럼·입력 필드 없이.
 */
class ClearanceSetMapping
{
    public static function config(): array
    {
        return [
            'template' => 'clearance_set.xlsx',
            'sheet' => '구매리스트',
            'label' => '통관SET',
            'cells' => [
                'B3' => fn (Vehicle $v) => $v->nice_reg_vin ? substr($v->nice_reg_vin, -6) : null,  // ID (VIN 끝 6자리)
                'I3' => fn (Vehicle $v) => $v->nice_reg_date,                       // 등록증날짜
                'B4' => fn (Vehicle $v) => $v->vehicle_number,                      // 차량번호
                'D4' => fn (Vehicle $v) => DocValue::romanizePlate($v->vehicle_number), // 영문차량번호
                'G4' => fn (Vehicle $v) => DocValue::niceRaw($v, 'fomNm'),          // 형식 (NICE)
                'I4' => fn (Vehicle $v) => $v->nice_reg_engine_no,                  // 원동기형식
                'B5' => fn (Vehicle $v) => DocValue::carNameFull($v),               // 차명 (brand+model)
                'D5' => fn (Vehicle $v) => $v->nice_reg_vin,                        // 차대번호
                'G5' => fn (Vehicle $v) => DocValue::niceRaw($v, 'resSpecControlNo'), // 제원관리번호 (NICE)
                'I5' => fn (Vehicle $v) => $v->nice_spec_year ?: $v->year,          // 연도
                'B6' => fn (Vehicle $v) => $v->nice_reg_first_date,                 // 최초등록일
                'D6' => fn (Vehicle $v) => $v->year,                               // 연도
                'G6' => fn (Vehicle $v) => $v->nice_reg_vehicle_form,               // 차종
                'B7' => fn (Vehicle $v) => $v->deregistration_date,                // 말소등록일
                'D7' => fn (Vehicle $v) => $v->mileage,                            // 주행거리
                'G7' => fn (Vehicle $v) => $v->nice_spec_length,                   // 길이
                'I7' => fn (Vehicle $v) => $v->nice_spec_width,                    // 너비
                'G8' => fn (Vehicle $v) => $v->nice_spec_height,                   // 높이
                'I8' => fn (Vehicle $v) => $v->weight_kg,                          // 총중량
                'G9' => fn (Vehicle $v) => $v->nice_reg_passengers,                // 인원
                'B10' => fn (Vehicle $v) => $v->port_of_loading,                   // Port
                'D10' => fn (Vehicle $v) => DocValue::destinationCountry($v),      // 목적국
                'G10' => fn (Vehicle $v) => $v->nice_spec_displacement,            // 배기량
                'I10' => fn (Vehicle $v) => DocValue::niceInspectionStart($v),     // 검사시작 (NICE resValidPeriod 분할)
                'I11' => fn (Vehicle $v) => DocValue::niceInspectionEnd($v),       // 검사종료 (NICE resValidPeriod 분할)
                'B11' => fn (Vehicle $v) => $v->vessel_name,                       // VSL
                'G11' => fn (Vehicle $v) => DocValue::niceRaw($v, 'maxPower'),     // 출력 (NICE)
                'B12' => fn (Vehicle $v) => $v->container_number ?: $v->bl_loading_location, // 컨테이너 NO
                'D12' => fn (Vehicle $v) => $v->shipping_method,                   // con/roro
                'G12' => fn (Vehicle $v) => DocValue::niceCylinders($v),           // 기통수 (NICE engineSpec 앞 — nice_raw 파싱)
                'I12' => fn (Vehicle $v) => $v->mileage,                           // 주행거리
                'G13' => fn (Vehicle $v) => $v->nice_reg_fuel_type,                // 연료
                'B14' => fn (Vehicle $v) => DocValue::consigneeBlock($v),          // 컨사이니 (이름+ID+주소)
                'D14' => fn (Vehicle $v) => DocValue::invoiceConsignee($v)?->name, // NAME
                'B15' => fn (Vehicle $v) => $v->sale_price,                        // 판매금
                'D15' => fn (Vehicle $v) => $v->transport_fee,                     // 운임
            ],
        ];
    }
}
