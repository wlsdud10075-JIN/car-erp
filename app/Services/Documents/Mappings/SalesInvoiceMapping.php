<?php

namespace App\Services\Documents\Mappings;

use App\Models\Vehicle;
use App\Services\Documents\DocValue;
use Illuminate\Support\Collection;

/**
 * 판매 — Proforma Invoice. **다중차량**(1바이어·단일통화). 수출 채널 전용(컨트롤러 EXPORT_ONLY 가드).
 *
 * 2026-07-31 다중차량 전환 (jin — board 영업이 많이 쓰게 될 서류).
 *   양식은 레이아웃 유지·행만 확장(`scripts/extend-sales-invoice-template.php`): 차량행 18 → 슬롯 18~47(30대).
 *   그에 따라 푸터가 +29 이동: COMMISSION 53 / AUTO LODING 54 / TAX D/C 55 / SUB TOTAL 56 /
 *   TOTAL 60 / BALANCE MONEY 63.
 *
 * ⚠️ **푸터는 전부 '값'이다(수식 아님).** 양식의 SUB TOTAL 은 `=SUM(E18:F55)` 로 슬롯 + 아래 3개
 *    비용행을 한꺼번에 덮는데, fillMulti 의 removeRow 는 그 range 를 자동축소하지 않는다(실측, §12).
 *    미사용 슬롯이 삭제되면 range 가 자기 자신까지 삼켜 순환참조가 된다. 비용행이 슬롯 **아래**에
 *    있어 판매계약서처럼 "슬롯 열 SUM 수식"으로 분리할 수도 없다(그러면 SUB TOTAL 이 비용행을
 *    포함하지 않게 되어 **인쇄물 의미가 바뀐다** — 비용행이 SUB TOTAL 위에 있으므로).
 *    → 전부 `aggregates`(값)로 기입한다. 1대일 때 출력 숫자는 종전과 동일하다.
 *
 * ⚠️ **DEPOSIT 행은 폐기 상태 유지**(jin 2026-06-24) — 합계 수식에 양수로 가산돼 판매총액이 2배가 됐다.
 *    라벨(C57·C58)은 비노란이라 clearYellowFill 이 못 지우는데, 런타임 `clearCells` 는 trim 으로
 *    좌표가 움직여 다중차량에선 못 쓴다 → **양식에서 아예 비웠다**(extend 스크립트 3단계).
 *
 * Invoice No·바이어/컨사이니·환율 등 헤더는 **primary(첫 차량) 기준**(판매계약서 선례).
 * 동일 바이어·단일통화는 컨트롤러 `HOMOGENEOUS_TYPES` 가드가 보장한다.
 *
 * 🔀 2026-09-03 — Code(A열)·Maker(B열)가 **한글이었다**. 2026-07-31 다중차량 전환 때
 *    「종전 동작 보존, 변경은 별건」으로 미뤄둔 것을 jin 이 결정했다: 판매계약서와 **같은 규칙**으로 통일.
 *      A Code  = `DocValue::romanizePlate()`  (`62거4485` → `62GEO4485`)
 *      B Maker = `DocValue::brandEn()`        (실측 3사 한글 브랜드 10대)
 *    바이어가 받는 영문 서류라 한글이 남으면 읽지 못한다. 가드 = `EnglishDocumentTermsTest`.
 */
class SalesInvoiceMapping
{
    /**
     * 컬렉션 합계. 금액 컬럼은 전부 NOT NULL(default 0) 이라 null 분기가 필요 없다.
     * 1대일 때 종전 출력과 동일: COMMISSION·AUTO LODING 은 0 이어도 `$0` 이 찍히고(money(0)=0.0),
     * TAX D/C 만 0 이면 빈칸이다(아래 falsy 분기 — 종전 `$v->tax_dc ? … : null` 과 같음).
     */
    private static function sum(Collection $vs, string $column): float
    {
        return (float) $vs->sum(fn (Vehicle $v) => (float) ($v->{$column} ?? 0));
    }

    /**
     * SUB TOTAL = TOTAL = BALANCE — 차량 합(FOB+운임) + COMMISSION + AUTO LODING − TAX D/C.
     *
     * 🧭 식은 `DocValue::documentSaleTotal` 단일 출처다 — 서류 7종이 같은 식을 쓰므로
     *    여기서 복제하지 않는다(SKILLS §8 #45).
     */
    private static function subTotal(Collection $vs): float
    {
        return DocValue::documentSaleTotal($vs);
    }

    public static function config(): array
    {
        return [
            'template' => 'sales_invoice.xlsx',
            'sheet' => 'Invoice',
            'label' => 'Invoice',
            // 판매통화 적응 ($ → 통화기호) — 2026-06-24.
            'currencyAware' => true,
            'header' => [
                'E3' => fn (Vehicle $v) => $v->sale_date ?: now(),                                    // Date
                'E4' => fn (Vehicle $v) => DocValue::invoiceNo($v),                                   // Invoice No. = {이니셜}{차대번호 끝자리숫자} (item 7)
                'E5' => fn (Vehicle $v) => DocValue::invoiceBuyer($v)?->name,                          // Buyer Name
                'E6' => fn (Vehicle $v) => DocValue::invoiceConsignee($v)?->name,                      // Client Name
                'E7' => fn (Vehicle $v) => DocValue::consigneeIdValue($v),                             // Passport
                'E8' => fn (Vehicle $v) => DocValue::invoiceConsignee($v)?->address ?: DocValue::invoiceBuyer($v)?->address, // Address
                'E9' => fn (Vehicle $v) => DocValue::invoiceConsignee($v)?->contact_phone ?: DocValue::invoiceBuyer($v)?->contact_phone, // Phone
                'E10' => fn (Vehicle $v) => DocValue::invoiceBuyer($v)?->contact_email,                // Email (바이어 이메일, A안 신규행 — jin 2026-07-21)
                'E11' => fn (Vehicle $v) => DocValue::money($v->exchange_rate),                        // Dollar Rate
            ],
            'multi' => [
                'first' => 18,
                'stride' => 1,
                'count' => 30,
                'slotCells' => [
                    0 => [
                        // Code — 바이어가 받는 영문 서류라 로마자(jin 2026-09-03). 판매계약서와 같은 규칙.
                        'A' => fn (Vehicle $v) => DocValue::romanizePlate($v->vehicle_number),
                        // Maker — NICE 는 한글로 준다(실측 3사 10대: 벤츠·르노(삼성)·기아·아우디·현대)

                        'B' => fn (Vehicle $v) => DocValue::brandEn($v),
                        'C' => fn (Vehicle $v) => DocValue::carName($v),          // Model
                        'D' => fn (Vehicle $v) => $v->nice_reg_vin,               // Chassis No.
                        'E' => fn (Vehicle $v) => DocValue::money($v->sale_price),      // FOB PRICE
                        'F' => fn (Vehicle $v) => DocValue::money($v->transport_fee),   // Shipping cost
                    ],
                ],
                // 슬롯 열 SUM 수식 없음 — 위 docblock 참조(푸터 전부 값).
                'footerAggregates' => [],
                'aggregates' => [
                    'E53' => fn (Collection $vs) => self::sum($vs, 'commission'),    // COMMISSION
                    'E54' => fn (Collection $vs) => self::sum($vs, 'auto_loading'),  // AUTO LODING
                    // TAX D/C — 양식 합계에 더해지므로 음수로(할인). 0 이면 종전대로 빈칸.
                    'E55' => fn (Collection $vs) => ($t = self::sum($vs, 'tax_dc')) ? -1 * $t : null,
                    'E56' => fn (Collection $vs) => self::subTotal($vs),   // SUB TOTAL
                    'E60' => fn (Collection $vs) => self::subTotal($vs),   // TOTAL
                    'E63' => fn (Collection $vs) => self::subTotal($vs),   // BALANCE MONEY
                ],
            ],
            // 도장/서명 슬롯은 App\Services\Documents\StampSlots 로 중앙화.
        ];
    }
}
