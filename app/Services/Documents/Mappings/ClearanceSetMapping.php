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
 * - I6·I13: 템플릿 수식 — 엔진이 자동 보존 (차종/연료 영문변환)
 * D3(등록번호)은 차량 registration_number 로 기입 → 말소증 "제 [D3] 호" cascade.
 * G3(차량등록증 자동차등록번호)은 reg_cert_number 수기필드 → 한글/영문등록증 cascade.
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
            'currencyAware' => true,   // 판매통화 적응 ($→통화기호, 전 시트) — 2026-06-24
            // ⑤ 차량인보이스 상호 첫 줄을 기능설정 브랜드(대문자)로 — RichText 첫 줄만 치환(나머지 보존).
            'brandHeader' => ['sheet' => '차량인보이스', 'cell' => 'A3'],
            'cells' => [
                'B3' => fn (Vehicle $v) => $v->nice_reg_vin ? substr($v->nice_reg_vin, -6) : null,  // ID (VIN 끝 6자리)
                'D3' => fn (Vehicle $v) => $v->registration_number,                 // 등록번호 (말소증 "제 ○○ 호")
                'G3' => fn (Vehicle $v) => $v->reg_cert_number,                     // 차량등록증 자동차등록번호 (한글/영문등록증 cascade)
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
                'B8' => fn (Vehicle $v) => $v->nice_reg_use_type ?: DocValue::niceRaw($v, 'resUseType'), // 용도/Usage (NICE resUseType → 한글/영문등록증 P4 cascade)
                'B7' => fn (Vehicle $v) => $v->deregistration_date,                // 말소등록일
                'D7' => fn (Vehicle $v) => $v->mileage,                            // 주행거리
                'G7' => fn (Vehicle $v) => $v->nice_spec_length,                   // 길이
                'I7' => fn (Vehicle $v) => $v->nice_spec_width,                    // 너비
                'G8' => fn (Vehicle $v) => $v->nice_spec_height,                   // 높이
                'I8' => fn (Vehicle $v) => $v->weight_kg,                          // 총중량
                'G9' => fn (Vehicle $v) => $v->nice_reg_passengers,                // 인원
                'I9' => fn (Vehicle $v) => $v->nice_reg_max_load,                  // 최대적재량 (NICE mxmmLdg → 한글/영문등록증 I23 cascade)
                'B10' => fn (Vehicle $v) => $v->port_of_loading,                   // Port
                'D10' => fn (Vehicle $v) => DocValue::dischargeDestination($v),    // 목적국/목적항 — 입력 목적항(영문) 우선
                'G10' => fn (Vehicle $v) => $v->nice_spec_displacement,            // 배기량
                'I10' => fn (Vehicle $v) => DocValue::niceInspectionStart($v),     // 검사시작 (NICE resValidPeriod 분할)
                'I11' => fn (Vehicle $v) => DocValue::niceInspectionEnd($v),       // 검사종료 (NICE resValidPeriod 분할)
                'B11' => fn (Vehicle $v) => $v->vessel_name,                       // VSL
                'D11' => fn (Vehicle $v) => $v->shipping_date,                     // 선적일 (차량인보이스 C18 cascade)
                'G11' => fn (Vehicle $v) => DocValue::niceRaw($v, 'maxPower'),     // 출력 (NICE)
                // 컨테이너 NO. RORO 면 컨테이너 번호가 없어 참조셀(차량인보이스 G2·G3)이 빈칸 → 'RORO' 표기.
                'B12' => fn (Vehicle $v) => $v->shipping_method === 'RORO'
                    ? 'RORO'
                    : ($v->container_number ?: $v->bl_loading_location),
                'D12' => fn (Vehicle $v) => $v->shipping_method,                   // con/roro
                'G12' => fn (Vehicle $v) => DocValue::niceCylinders($v),           // 기통수 (NICE engineSpec 앞 — nice_raw 파싱)
                'I12' => fn (Vehicle $v) => $v->mileage,                           // 주행거리
                'G13' => fn (Vehicle $v) => $v->nice_reg_fuel_type,                // 연료
                'H13' => fn (Vehicle $v) => $v->nice_spec_fuel_efficiency,         // 연비 (NICE fuelCnsmpRt → 한글/영문등록증 F31 cascade)
                'B14' => fn (Vehicle $v) => DocValue::consigneeBlock($v, labelIdValue: true), // 컨사이니 (이름+Business number 라벨+주소+이메일+전화+담당자)
                // D14(NAME)는 템플릿 셀에 `=B14` 수식 — 컨사이니 블록 전체를 미러(Travel Invoice F5 cascade).
                //   writeCell 이 수식 셀은 안 덮어쓰므로 매핑에서 제외.
                'B15' => fn (Vehicle $v) => DocValue::money($v->sale_price),        // 판매금 (float — 텍스트면 차량인보이스 SUM/통화서식 깨짐)
                'D15' => fn (Vehicle $v) => DocValue::money($v->transport_fee),     // 운임 (float)
                // Travel Services Invoice 컨사이니 칸 — 계약서 F6/F7 과 동일 소스(컨사이니→없으면 바이어).
                //   엔진의 'Sheet!Cell' 시트지정 좌표로 마스터 외 시트에 직접 기입.
                'Travel Services Invoice!F7' => fn (Vehicle $v) => DocValue::invoiceConsignee($v)?->address ?: DocValue::invoiceBuyer($v)?->address,             // Adress
                'Travel Services Invoice!F8' => fn (Vehicle $v) => (DocValue::invoiceConsignee($v)?->country ?: DocValue::invoiceBuyer($v)?->country)?->code, // Country (ISO3 코드 — 수출서류라 한글명 X)
                'Travel Services Invoice!F9' => fn (Vehicle $v) => DocValue::invoiceConsignee($v)?->contact_phone ?: DocValue::invoiceBuyer($v)?->contact_phone, // Phone
            ],
        ];
    }
}
