<?php

namespace App\Services\Documents\Mappings;

use App\Models\Vehicle;
use App\Services\Documents\DocValue;
use Illuminate\Support\Collection;

/**
 * 선적 — 컨테이너 CONTRACT. 수출 전용. 다중차량.
 *
 * 양식은 30슬롯 확장됨(슬롯 = 1행, stride 1, first 16). A16(=RIGHT(E16,6))·I16(=F16+G16) per-row 수식
 * 자동 보존. footer 집계(F46 전체합 / I46 FOB합 / I47 운임합)는 채운 영역 range 로 재기록.
 * F4/F5(Invoice No·Name)는 슬롯 위라 위치 불변.
 */
class ContainerContractMapping
{
    /** 컬렉션 합 — 금액 컬럼은 NOT NULL(default 0) 이라 null 분기 불필요. */
    private static function sum(Collection $vs, string $column): float
    {
        return (float) $vs->sum(fn (Vehicle $v) => (float) ($v->{$column} ?? 0));
    }

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
                'aggregates' => [
                    // 기타 청구 3줄 — 종전엔 **아예 없어서** TOTAL 이 «판매가 + 운임» 만이었다(jin 2026-08-28).
                    //   47~51 행은 양식의 빈 여유행이고 `F52 TOTAL = SUM(F46:G51)` 이 이미 덮고 있어
                    //   **xlsx 를 한 장도 안 고치고** 흡수된다(실측 — 3사 양식 동일).
                    // ⚠️ TAX D/C 는 그 SUM 이 「더하는」 칸이라 **음수로** 넣는다(SalesInvoice E55 와 같은 이유).
                    // 🧭 0 이면 라벨도 값도 안 쓴다 — 대부분의 차가 0 이라 그냥 두면 `$0` 줄만 늘어난다.
                    // 🚫 외국인 계약서라 라벨은 영문 (SKILLS §8 #29).
                    'E47' => fn (Collection $vs) => self::sum($vs, 'commission') ? 'COMMISSION' : null,
                    'F47' => fn (Collection $vs) => self::sum($vs, 'commission') ?: null,
                    'E48' => fn (Collection $vs) => self::sum($vs, 'auto_loading') ? 'AUTO LOADING' : null,
                    'F48' => fn (Collection $vs) => self::sum($vs, 'auto_loading') ?: null,
                    'E49' => fn (Collection $vs) => self::sum($vs, 'tax_dc') ? 'TAX D/C' : null,
                    'F49' => fn (Collection $vs) => ($t = self::sum($vs, 'tax_dc')) ? -1 * $t : null,
                ],
                'slotCells' => [
                    0 => [
                        'B' => fn (Vehicle $v) => $v->brand,             // Brand
                        'C' => fn (Vehicle $v) => DocValue::carName($v), // Model
                        'D' => fn (Vehicle $v) => $v->year,             // Year
                        'E' => fn (Vehicle $v) => $v->nice_reg_vin,     // Chassis No.
                        'F' => fn (Vehicle $v) => DocValue::money($v->sale_price),    // FOB PRICE
                        'G' => fn (Vehicle $v) => DocValue::money($v->transport_fee), // Shipping cost
                    ],
                ],
            ],
            // 도장/서명 슬롯은 App\Services\Documents\StampSlots 로 중앙화(applyStamps 가 거기서 읽음).
        ];
    }
}
