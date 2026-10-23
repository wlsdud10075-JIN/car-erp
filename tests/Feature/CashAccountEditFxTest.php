<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CashAccount;
use App\Models\CashAccountBalance;
use App\Models\CashSnapshot;
use App\Models\User;
use App\Services\CashAccountBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 통장 잔액 — 외화 계좌 원화 경고 · 수정/삭제 · 칸 비우기 (jin 2026-10-22, heymanerp 외화 계좌에 원화가 같이 기록돼 합계 2배).
 *
 * ⚠️ 원래 버그(저장 뒤 브라우저 칸에 남은 원화가 다음 계좌에 실려 감)는 브라우저 쪽이라 Volt 로는 재현되지 않는다 —
 *    실제 재현·확인은 Playwright(스크래치패드 pw/cash.js). 여기서는 고친 장치가 남아 있는지만 본다.
 */
class CashAccountEditFxTest extends TestCase
{
    use RefreshDatabase;

    private const EMPTY = ['KRW' => '', 'USD' => '', 'EUR' => ''];

    private function finance(): User
    {
        return User::factory()->create(['permission' => 'user', 'role' => '재무']);
    }

    public function test_amount_fields_keep_all_three_keys_after_save_and_account_switch(): void
    {
        $krw = CashAccount::create(['bank' => '신한', 'account_no' => '1', 'label' => '원화']);
        $fx = CashAccount::create(['bank' => '신한', 'account_no' => '2', 'label' => '외화', 'is_foreign' => true]);
        $this->actingAs($this->finance());

        $c = Volt::test('erp.dashboard')->set('cashDate', '2026-10-21')->set('cashAccountId', (string) $krw->id)
            ->set('cashAmounts.KRW', '13,549,873')->call('saveAccountBalance')
            ->assertSet('cashAmounts', self::EMPTY);
        $c->set('cashAccountId', (string) $fx->id)->assertSet('cashAmounts', self::EMPTY)
            ->set('cashAmounts.USD', '3458.71')->call('saveAccountBalance');

        $this->assertSame(0, CashAccountBalance::where('cash_account_id', $fx->id)->where('currency', 'KRW')->count(), '외화 계좌에 원화가 실려 가면 안 된다');
        $this->assertSame(13_549_873, (int) CashSnapshot::whereDate('snapshot_date', '2026-10-21')->value('balance_krw'));
    }

    public function test_krw_into_a_foreign_account_asks_first_and_saves_on_confirm(): void
    {
        $fx = CashAccount::create(['bank' => '신한', 'account_no' => '2', 'is_foreign' => true]);
        $this->actingAs($this->finance());

        $c = Volt::test('erp.dashboard')->set('cashDate', '2026-10-21')->set('cashAccountId', (string) $fx->id)
            ->set('cashAmounts.KRW', '500,000')->set('cashAmounts.USD', '10')->call('saveAccountBalance')
            ->assertSet('cashFxWarn', true)->assertSeeHtml('data-cash-fx-warn');
        $this->assertSame(0, CashAccountBalance::count(), '묻기 전엔 저장하지 않는다');

        $c->call('cancelFxSave')->assertSet('cashFxWarn', false);
        $this->assertSame(0, CashAccountBalance::count());

        $c->call('saveAccountBalance')->assertSet('cashFxWarn', true)->call('confirmFxSave')->assertSet('cashFxWarn', false);
        $this->assertSame(2, CashAccountBalance::count(), '[그래도 기록] 이면 저장된다');

        // 다음에 또 넣으면 다시 묻는다 — 한 번 확인했다고 꺼지지 않는다
        Volt::test('erp.dashboard')->set('cashDate', '2026-10-21')->set('cashAccountId', (string) $fx->id)
            ->set('cashAmounts.KRW', '1')->call('saveAccountBalance')->assertSet('cashFxWarn', true);
    }

    public function test_usd_only_or_a_krw_account_does_not_ask(): void
    {
        $fx = CashAccount::create(['bank' => '신한', 'account_no' => '2', 'is_foreign' => true]);
        $krw = CashAccount::create(['bank' => '신한', 'account_no' => '1']);
        $this->actingAs($this->finance());

        Volt::test('erp.dashboard')->set('cashDate', '2026-10-21')->set('cashAccountId', (string) $fx->id)
            ->set('cashAmounts.USD', '10')->call('saveAccountBalance')->assertSet('cashFxWarn', false);
        Volt::test('erp.dashboard')->set('cashDate', '2026-10-21')->set('cashAccountId', (string) $krw->id)
            ->set('cashAmounts.KRW', '10')->call('saveAccountBalance')->assertSet('cashFxWarn', false);
        $this->assertSame(2, CashAccountBalance::count());
    }

    public function test_edit_loads_that_dates_values_and_clearing_a_field_deletes_it(): void
    {
        $fx = CashAccount::create(['bank' => '신한', 'account_no' => '2', 'is_foreign' => true]);
        $krw = CashAccount::create(['bank' => '신한', 'account_no' => '1']);
        $svc = app(CashAccountBalanceService::class);
        $svc->save($krw, '2026-10-21', ['KRW' => 13_549_873]);
        $svc->save($fx, '2026-10-21', ['KRW' => 13_549_873, 'USD' => 3458.71, 'EUR' => 90949.66]);   // heymanerp 10-22 그 모양
        $this->assertSame(27_099_746, (int) CashSnapshot::whereDate('snapshot_date', '2026-10-21')->value('balance_krw'));
        $this->actingAs($this->finance());

        Volt::test('erp.dashboard')->set('cashDate', '2026-10-21')->call('editCashAccount', $fx->id)
            ->assertSet('cashEditAccountId', $fx->id)
            ->assertSet('cashAmounts', ['KRW' => '13,549,873', 'USD' => '3458.71', 'EUR' => '90949.66'])
            ->assertSeeHtml('data-cash-editing')
            ->set('cashAmounts.KRW', '')->call('saveAccountBalance')
            ->assertSet('cashEditAccountId', null);

        $this->assertNull(CashAccountBalance::where('cash_account_id', $fx->id)->where('currency', 'KRW')->first());
        $this->assertSame(2, CashAccountBalance::where('cash_account_id', $fx->id)->count(), '달러·유로는 그대로');
        $this->assertSame(13_549_873, (int) CashSnapshot::whereDate('snapshot_date', '2026-10-21')->value('balance_krw'), '합계가 바로 맞춰진다');
        $this->assertDatabaseHas('audit_logs', ['auditable_id' => $fx->id, 'column_name' => 'cash_balance_krw', 'action' => 'deleted']);
    }

    public function test_edit_changes_a_value_with_audit_and_delete_removes_the_date(): void
    {
        $a = CashAccount::create(['bank' => '신한', 'account_no' => '1']);
        $svc = app(CashAccountBalanceService::class);
        $svc->save($a, '2026-10-20', ['KRW' => 1_000]);
        $svc->save($a, '2026-10-21', ['KRW' => 5_000]);
        $this->actingAs($this->finance());

        Volt::test('erp.dashboard')->set('cashDate', '2026-10-21')->call('editCashAccount', $a->id)
            ->set('cashAmounts.KRW', '4,000')->call('saveAccountBalance');
        $this->assertSame(4_000, (int) CashSnapshot::whereDate('snapshot_date', '2026-10-21')->value('balance_krw'));
        $log = AuditLog::where('column_name', 'cash_balance_krw')->where('action', 'updated')->firstOrFail();
        $this->assertSame('2026-10-21 5000.00', $log->old_value);

        Volt::test('erp.dashboard')->set('cashDate', '2026-10-21')->call('editCashAccount', $a->id)->call('deleteCashEntry');
        $this->assertSame(0, CashAccountBalance::whereDate('snapshot_date', '2026-10-21')->count());
        $this->assertSame(1_000, (int) CashSnapshot::whereDate('snapshot_date', '2026-10-21')->value('balance_krw'), '지운 뒤엔 앞 날짜 값을 이어 쓴다');
    }

    public function test_rows_show_edit_instead_of_hide_and_foreign_flag_is_toggleable(): void
    {
        $a = CashAccount::create(['bank' => '신한', 'account_no' => '1']);
        $this->actingAs($this->finance());

        $c = Volt::test('erp.dashboard')->assertSeeHtml('data-cash-edit')
            ->assertDontSeeHtml('wire:click="deactivateCashAccount('.$a->id.')"');
        $c->call('editCashAccount', $a->id)->assertSeeHtml('wire:click="deactivateCashAccount('.$a->id.')"')   // 숨김은 수정 칸 안으로
            ->call('toggleCashForeign');
        $this->assertTrue($a->fresh()->is_foreign);
        $this->assertDatabaseHas('audit_logs', ['auditable_id' => $a->id, 'column_name' => 'is_foreign']);

        Volt::test('erp.dashboard')->set('showAddAccount', true)->set('newBank', '국민')->set('newAccountNo', '9')
            ->set('newForeign', true)->call('addCashAccount');
        $this->assertTrue((bool) CashAccount::where('account_no', '9')->value('is_foreign'));
    }

    /** 💱 외화 잔액의 원화 환산 줄 — 화면 위 실시간 환율과 같은 값으로 (jin 2026-10-23). 표시만. */
    public function test_totals_show_krw_equivalent_at_live_rates(): void
    {
        $fx = CashAccount::create(['bank' => '신한', 'account_no' => '2', 'is_foreign' => true]);
        $krw = CashAccount::create(['bank' => '신한', 'account_no' => '1']);
        $svc = app(CashAccountBalanceService::class);
        $svc->save($krw, now()->toDateString(), ['KRW' => 1_000_000]);
        $svc->save($fx, now()->toDateString(), ['USD' => 100, 'EUR' => 10]);
        Cache::put('exchange_rates', ['USD' => 1300.0, 'EUR' => 1500.0], 60);   // ExchangeRateService::CACHE_KEY (private)
        $this->actingAs($this->finance());

        Volt::test('erp.dashboard')->assertSeeHtml('data-cash-fx-total')
            ->assertSee('₩1,145,000')    // 1,000,000 + 100×1,300 + 10×1,500
            ->assertSee('₩130,000')->assertSee('₩15,000')
            ->assertSeeHtml('data-cash-headline')->assertSee('₩115만')   // 「+ 계좌 추가」 옆 카드 = 억·만 표기
            ->set('cashAccountId', (string) $fx->id)->assertDontSeeHtml('data-cash-headline');   // 입력 중엔 숨겨 [저장]이 밀리지 않게
        $this->assertSame(1_000_000, (int) CashSnapshot::whereDate('snapshot_date', now()->toDateString())->value('balance_krw'), '저장값은 그대로 — 환산은 표시만');
    }
}
