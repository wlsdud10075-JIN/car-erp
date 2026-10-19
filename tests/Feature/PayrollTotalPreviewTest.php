<?php

namespace Tests\Feature;

use App\Models\PayrollEntry;
use App\Models\Salesman;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 사내직원관리 급여 「지급합계」 (jin 2026-10-19 «지급합계 0원», ssancarerp 굴러자 9월).
 *   구: 합계를 입력칸 DOM 에서 읽고 @input 때만 다시 셌다 → 패널을 열거나 귀속월을 바꿔 서버가 값을 채우면 「₩0」 고정.
 *   지금: $wire.payrollItems·payrollCustom(Alpine 반응형)을 읽는다 — 치는 즉시·서버가 채울 때 둘 다 다시 센다.
 *   그리기는 브라우저 JS 라 기능 테스트로 못 잡는다(§8 #98 부류) — 정적 검사 + 서버 렌더 값.
 */
class PayrollTotalPreviewTest extends TestCase
{
    use RefreshDatabase;

    private function block(): string
    {
        $src = file_get_contents(resource_path('views/livewire/erp/salesmen/index.blade.php'));
        $start = strpos($src, 'data-payroll'."\n");
        $this->assertNotFalse($start, '급여 블록(data-payroll)을 못 찾았다');

        return substr($src, $start, 4000);
    }

    public function test_total_reads_livewire_state_not_dom_inputs(): void
    {
        $b = $this->block();
        $this->assertStringContainsString('$wire.payrollItems', $b, '합계는 $wire 상태를 읽어야 서버가 채운 값에도 다시 센다');
        $this->assertStringContainsString('$wire.payrollCustom', $b, '직접 추가한 줄도 합계에 들어간다');
        $this->assertStringNotContainsString("querySelectorAll('[data-pay-amount]')", $b, '입력칸 DOM 을 읽으면 월을 바꿔도 ₩0 에 머문다');
        $this->assertStringNotContainsString('tick', $b, '@input 때만 다시 세는 tick 방식으로 돌아가지 말 것');
    }

    public function test_server_render_shows_the_month_total(): void
    {
        $this->actingAs(User::factory()->create(['permission' => 'admin', 'role' => '관리']));
        $sm = Salesman::create(['name' => '굴러자', 'type' => 'staff', 'is_active' => true]);
        foreach ([['기본급', 2_300_000, 0], ['식대', 200_000, 2], ['복리', 240_000, 6]] as [$l, $a, $sort]) {
            PayrollEntry::create(['salesman_id' => $sm->id, 'month' => '2026-09', 'label' => $l, 'amount' => $a, 'sort' => $sort, 'is_custom' => false]);
        }

        $html = Volt::test('erp.salesmen.index')->call('openEdit', $sm->id)->set('payrollMonth', '2026-09')->html();

        $this->assertMatchesRegularExpression('/data-payroll-total[^>]*>₩2,740,000</u', $html);
    }
}
