<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 통장 마감잔액 기준일 ‹ › 화살표 (jin 2026-10-26) — 하루씩 이동, 오늘 뒤로는 못 감, 바꾸면 입력칸·수정 대상이 정리된다.
 */
class CashDateArrowTest extends TestCase
{
    use RefreshDatabase;

    public function test_arrows_move_one_day_and_stop_at_today(): void
    {
        CashAccount::create(['bank' => '신한', 'account_no' => '1', 'label' => '원화']);
        $this->actingAs(User::factory()->create(['permission' => 'user', 'role' => '재무']));
        $today = now()->toDateString();

        $c = Volt::test('erp.dashboard');
        $this->assertSame($today, $c->get('cashDate'));

        $c->call('shiftCashDate', 1);
        $this->assertSame($today, $c->get('cashDate'), '오늘 뒤로는 안 간다');

        $c->set('cashAmounts.KRW', '1,000')->call('shiftCashDate', -1);
        $this->assertSame(now()->subDay()->toDateString(), $c->get('cashDate'));
        $this->assertSame('', $c->get('cashAmounts.KRW'), '날짜를 옮기면 치던 숫자는 비운다(다른 날짜에 실리지 않게)');

        $c->call('shiftCashDate', -1)->call('shiftCashDate', 1);
        $this->assertSame(now()->subDay()->toDateString(), $c->get('cashDate'));

        $c->call('shiftCashDate', -5);
        $this->assertSame(now()->subDays(2)->toDateString(), $c->get('cashDate'), '한 번에 하루만');

        $html = $c->html();
        $this->assertStringContainsString('wire:click="shiftCashDate(-1)"', $html);
        $this->assertStringContainsString('wire:click="shiftCashDate(1)"', $html);
    }

    public function test_next_arrow_is_disabled_on_today(): void
    {
        CashAccount::create(['bank' => '신한', 'account_no' => '1', 'label' => '원화']);
        $this->actingAs(User::factory()->create(['permission' => 'user', 'role' => '재무']));

        $html = Volt::test('erp.dashboard')->html();
        $this->assertMatchesRegularExpression('/wire:click="shiftCashDate\(1\)"[^>]*\sdisabled/s', $html, '오늘이면 › 비활성');
    }
}
