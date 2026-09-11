<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 차량목록 「미수금」 칸 통화 표기 가드 (jin 2026-09-11 «원화로 나와, 금액은 맞는데»).
 *
 * `sale_unpaid_amount` 는 **판매통화 단위**다(SKILLS §13). 그런데 셀이 `₩` 를 리터럴로
 * 붙이고 있어서 EUR·USD 미수가 **원화로 위장**됐다. 금액 자체는 맞아서 눈으로는 안 걸린다.
 *
 * 🚨 정적 검사여야 한다 — 되돌아가도 화면은 정상 렌더되고 숫자도 맞다. 틀린 건 단위뿐이다.
 *    같은 값을 쓰는 호버 툴팁(app.js)은 처음부터 `data-currency` 를 찍고 있었다.
 */
class VehicleListCurrencyLabelTest extends TestCase
{
    private const VIEW = 'resources/views/livewire/erp/vehicles/index.blade.php';

    private function unpaidCell(): string
    {
        $src = file_get_contents(base_path(self::VIEW));

        // 미수금 <td> 블록만 잘라낸다 (colOn('unpaid_amount') ~ 그 td 닫힘).
        $start = strpos($src, "colOn('unpaid_amount')): @endphp<td");
        $this->assertNotFalse($start, '미수금 컬럼 td 를 찾지 못했다 — 컬럼 키가 바뀌었나?');
        $end = strpos($src, '</td>', $start);

        return substr($src, $start, $end - $start);
    }

    public function test_unpaid_amount_is_not_labelled_as_korean_won(): void
    {
        $this->assertStringNotContainsString(
            '₩',
            $this->unpaidCell(),
            '미수금은 판매통화 단위다. ₩ 를 박으면 EUR·USD 차가 원화로 위장된다.'
        );
    }

    public function test_unpaid_amount_prints_the_vehicle_currency(): void
    {
        $this->assertStringContainsString(
            '$v->currency',
            $this->unpaidCell(),
            '미수금 칸은 그 차량의 통화를 함께 찍어야 한다 (옆 운임비 칸·호버 툴팁과 동일).'
        );
    }
}
