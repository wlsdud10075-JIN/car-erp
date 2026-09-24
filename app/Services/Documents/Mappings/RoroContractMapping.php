<?php

namespace App\Services\Documents\Mappings;

use App\Models\Vehicle;
use App\Services\Documents\DocValue;

/**
 * 선적 — RORO CONTRACT. 수출 전용. 다중차량. 컨테이너 CONTRACT 와 동일 구조(HBB340., 30슬롯 확장).
 * 슬롯 = 1행, stride 1, first 16. per-row 수식(A=RIGHT, I=F+G) 자동 보존, footer range 재기록.
 *
 * 🔀 2026-09-24 (jin) — FOB PRICE(F) = 판매가 + 기타청구(COMMISSION + AUTO LOADING − TAX D/C).
 *   08-28 엔 푸터 여유행 E47~F49 에 기타청구 3줄을 따로 냈으나, 같은 선적 건의 Invoice&Packing 이 09-22 부터
 *   단가에 합산하면서 계약서만 단가가 달라 보였다 → 계약서도 같은 단일 출처(`DocValue::unitPriceWithCharges`)로.
 *   TOTAL(F52) 숫자는 그대로다(3줄이 F열로 흡수). 🚫 여유행 3줄을 되살리지 말 것 — 되살리면 기타청구가 두 번 들어간다.
 */
class RoroContractMapping
{
    public static function config(): array
    {
        return [
            'template' => 'roro_contract.xlsx',
            'sheet' => 'HBB340.',
            'label' => 'RORO_Contract',
            'currencyAware' => true,   // 판매통화 적응 ($→통화기호) — 2026-06-24
            'header' => [
                'F4' => fn (Vehicle $v) => $v->container_number ?: $v->bl_loading_location,
                'F5' => fn (Vehicle $v) => DocValue::invoiceConsignee($v)?->name,
                'F6' => fn (Vehicle $v) => DocValue::invoiceConsignee($v)?->address ?: DocValue::invoiceBuyer($v)?->address,             // Adress
                'F7' => fn (Vehicle $v) => DocValue::invoiceConsignee($v)?->contact_phone ?: DocValue::invoiceBuyer($v)?->contact_phone, // Phone
                'F9' => fn (Vehicle $v) => DocValue::money($v->exchange_rate),              // Dollar/통화 Rate (환율)
            ],
            'multi' => [
                'first' => 16,
                'stride' => 1,
                'count' => 30,
                'footerAggregates' => [
                    ['cell' => 'F46', 'fmt' => '=SUM(F%d:G%d)'],
                    ['cell' => 'I46', 'fmt' => '=SUM(F%d:F%d)'],
                    ['cell' => 'I47', 'fmt' => '=SUM(G%d:G%d)'],
                ],
                // 47~51 행(양식 여유행)은 비워 둔다 — 클래스 docblock 참조(09-24, 기타청구는 F열 단가에 합산).
                'slotCells' => [
                    0 => [
                        // 브랜드 영문 — 같은 선적 건의 Invoice&Packing 은 이미 brandEn 을 쓴다.
                        //   여기만 한글이라 **한 건의 서류 두 장이 서로 다른 표기**로 나갔다(jin 2026-09-03).
                        'B' => fn (Vehicle $v) => DocValue::brandEn($v),
                        'C' => fn (Vehicle $v) => DocValue::carName($v),
                        'D' => fn (Vehicle $v) => $v->year,
                        'E' => fn (Vehicle $v) => $v->nice_reg_vin,
                        'F' => fn (Vehicle $v) => DocValue::unitPriceWithCharges($v),   // FOB PRICE = 판매가 + 기타청구
                        'G' => fn (Vehicle $v) => DocValue::money($v->transport_fee),
                    ],
                ],
            ],
            // 도장/서명 슬롯은 App\Services\Documents\StampSlots 로 중앙화.
        ];
    }
}
