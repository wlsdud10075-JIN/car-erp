<?php

namespace App\Services\Documents\Mappings;

use App\Models\Vehicle;
use App\Services\Documents\DocValue;

/**
 * 선적 — 컨테이너 CONTRACT. 수출 전용. 다중차량.
 *
 * 양식은 30슬롯 확장됨(슬롯 = 1행, stride 1, first 16). A16(=RIGHT(E16,6))·I16(=F16+G16) per-row 수식
 * 자동 보존. footer 집계(F46 전체합 / I46 FOB합 / I47 운임합)는 채운 영역 range 로 재기록.
 * F4/F5(Invoice No·Name)는 슬롯 위라 위치 불변.
 *
 * 🔀 2026-09-24 (jin) — FOB PRICE(F) = 판매가 + 기타청구. 08-28 의 푸터 여유행 3줄(E47~F49)은 폐기.
 *   경위·🚫 되살리기 금지 = RoroContractMapping docblock(동일 구조).
 */
class ContainerContractMapping
{
    public static function config(): array
    {
        return [
            'template' => 'container_contract.xlsx',
            'sheet' => 'HBB340.',
            'label' => '컨테이너_Contract',
            'currencyAware' => true,   // 판매통화 적응 ($→통화기호) — 2026-06-24
            'header' => [
                'F4' => fn (Vehicle $v) => $v->container_number ?: $v->bl_loading_location, // Invoice No(컨테이너)
                'F5' => fn (Vehicle $v) => DocValue::invoiceConsignee($v)?->name,           // Name
                'F6' => fn (Vehicle $v) => DocValue::invoiceConsignee($v)?->address ?: DocValue::invoiceBuyer($v)?->address,             // Adress
                'F7' => fn (Vehicle $v) => DocValue::invoiceConsignee($v)?->contact_phone ?: DocValue::invoiceBuyer($v)?->contact_phone, // Phone
                'F9' => fn (Vehicle $v) => DocValue::money($v->exchange_rate),              // Dollar/통화 Rate (환율)
            ],
            'multi' => [
                'first' => 16,
                'stride' => 1,
                'count' => 30,
                'footerAggregates' => [
                    ['cell' => 'F46', 'fmt' => '=SUM(F%d:G%d)'],   // 전체합(FOB+운임)
                    ['cell' => 'I46', 'fmt' => '=SUM(F%d:F%d)'],   // FOB 합
                    ['cell' => 'I47', 'fmt' => '=SUM(G%d:G%d)'],   // 운임 합
                ],
                // 47~51 행(양식 여유행)은 비워 둔다 — 클래스 docblock 참조(09-24, 기타청구는 F열 단가에 합산).
                'slotCells' => [
                    0 => [
                        // 브랜드 영문 — 같은 선적 건의 Invoice&Packing 은 이미 brandEn 을 쓴다.
                        //   여기만 한글이라 **한 건의 서류 두 장이 서로 다른 표기**로 나갔다(jin 2026-09-03).
                        'B' => fn (Vehicle $v) => DocValue::brandEn($v),             // Brand
                        'C' => fn (Vehicle $v) => DocValue::carName($v), // Model
                        'D' => fn (Vehicle $v) => $v->year,             // Year
                        'E' => fn (Vehicle $v) => $v->nice_reg_vin,     // Chassis No.
                        'F' => fn (Vehicle $v) => DocValue::unitPriceWithCharges($v),   // FOB PRICE = 판매가 + 기타청구
                        'G' => fn (Vehicle $v) => DocValue::money($v->transport_fee), // Shipping cost
                    ],
                ],
            ],
            // 도장/서명 슬롯은 App\Services\Documents\StampSlots 로 중앙화(applyStamps 가 거기서 읽음).
        ];
    }
}
