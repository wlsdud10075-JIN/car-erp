<?php

namespace App\Services\Documents\Mappings;

use App\Models\Vehicle;
use App\Services\Documents\DocValue;

/**
 * 매입 — 말소증(한글·영문) 2시트. 서류탭 「매입」 위임장 옆 (jin 2026-09-03).
 *
 * 🔀 **통관 SET 의 말소증 시트와는 별개다** (jin 명시). 서식이 다르다 —
 *    통관 SET = 별지20호 2025-01-10 개정판(한·영 한 장) / 이건 구서식(한글 1장 + 영문 1장).
 *    그래서 구매리스트 마스터를 쓰지 않고 **ERP 데이터를 2시트에 직접** 기입한다.
 *
 * 양식 = `scripts/build-deregistration-certificate-template.php` 로 3사 생성(`--verify` 로 대조).
 * 도장·직인(관인 + 수입증지)은 양식에 baked 된 것을 그대로 쓴다 — StampSlots 미등록(업로드 대상 아님).
 *
 * 🚫 **`#REF!` 를 되살리지 말 것** — 원본이 초기문서라 데이터 칸이 전부 `=#REF!` 였다.
 *    `DocumentFiller::writeCell` 은 `=` 로 시작하는 셀을 안 덮어쓰므로, 수식이 남아 있으면
 *    아래 매핑이 **통째로 조용히 무시**되고 서류에 `#REF!` 가 인쇄된다. 빌더가 이미 걷어냈다.
 *
 * 채워지는 정도(운영 실측 2026-09-03) — 값이 없으면 빈칸이다. 없는 값을 만들어 채우지 않는다.
 *   heymanerp 265대: 차대번호 265 · 주행거리 264 · 말소등록일 252 · 제원관리번호 228 ·
 *                    최초등록일/원동기/차종/용도 224~226 · **등록번호(제 ○○ 호) 131**
 *   ssancarerp 는 엑셀 적재분이라 NICE 칸 커버리지가 5% 다(적재 원본에 그 열이 없었다).
 */
class DeregistrationCertificateMapping
{
    /** 영문시트 — 좌표에 시트명을 붙여 기입한다(엔진의 'Sheet!Cell' 지원). */
    private const EN = '영문말소증!';

    public static function config(): array
    {
        return [
            'template' => 'deregistration_certificate.xlsx',
            'sheet' => '말소증',
            'label' => '말소증',
            'cells' => [
                // ─── 한글 ────────────────────────────────────────────
                // 제 ○○ 호 — 매입탭 「등록번호」(registration_number). 통관 SET 구매리스트 D3 과 같은 출처.
                'C4' => fn (Vehicle $v) => $v->registration_number,
                'E5' => fn (Vehicle $v) => $v->vehicle_number,                        // 자동차등록번호
                'K5' => fn (Vehicle $v) => $v->nice_reg_vehicle_form,                 // 차종 (NICE)
                'K6' => fn (Vehicle $v) => $v->mileage,                               // 주행거리 (영문 K6 은 =말소증!K6 미러)
                'E7' => fn (Vehicle $v) => DocValue::carName($v),                     // 차명 — 말소·위임장은 model-only
                'K7' => fn (Vehicle $v) => $v->nice_reg_vin,                          // 차대번호
                'E8' => fn (Vehicle $v) => $v->nice_reg_engine_no,                    // 원동기형식
                'K8' => fn (Vehicle $v) => $v->nice_spec_year ?: $v->year,            // 모델연도
                'E9' => fn (Vehicle $v) => DocValue::niceRaw($v, 'resSpecControlNo'), // 제원관리번호 (NICE)
                'K9' => fn (Vehicle $v) => $v->nice_reg_use_type ?: DocValue::niceRaw($v, 'resUseType'), // 용도 (NICE)
                'E10' => fn (Vehicle $v) => $v->nice_reg_first_date,                  // 최초등록일
                // 말소등록일 — 맨 아래 발행일(H20)이 `=E13` 이라 자동으로 따라온다.
                'E13' => fn (Vehicle $v) => $v->deregistration_date,

                // ─── 영문 ────────────────────────────────────────────
                // 소유자 3칸(E11·K11·E12)·용도 문구·권리관계는 양식에 회사별로 인쇄돼 있다(빌더가 기입).
                self::EN.'C4' => fn (Vehicle $v) => $v->registration_number,
                self::EN.'E5' => fn (Vehicle $v) => DocValue::romanizePlate($v->vehicle_number), // Plate no (영문 번호판)
                self::EN.'K5' => fn (Vehicle $v) => DocValue::vehicleFormEn($v),      // Category
                self::EN.'E7' => fn (Vehicle $v) => DocValue::carName($v),            // Model Name
                self::EN.'K7' => fn (Vehicle $v) => $v->nice_reg_vin,                 // Chassis No
                self::EN.'E8' => fn (Vehicle $v) => $v->nice_reg_engine_no,           // Motor Type
                self::EN.'K8' => fn (Vehicle $v) => $v->nice_spec_year ?: $v->year,   // Model year
                self::EN.'E9' => fn (Vehicle $v) => DocValue::niceRaw($v, 'resSpecControlNo'), // Approval No
                self::EN.'K9' => fn (Vehicle $v) => DocValue::useTypeEn($v),          // Purpose
                self::EN.'E10' => fn (Vehicle $v) => $v->nice_reg_first_date,         // Car Registration date
                self::EN.'E13' => fn (Vehicle $v) => $v->deregistration_date,         // Repealed Date
            ],
        ];
    }
}
