<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\CashAccountBalance;
use App\Models\CashSnapshot;
use App\Models\User;
use App\Services\CashAccountBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** 계좌별 통장 잔액 (jin 2026-10-14). 합계는 종전 스냅샷 3컬럼으로 — 청산가치·보고서는 그대로. */
class CashAccountBalanceTest extends TestCase
{
    use RefreshDatabase;

    private function finance(): User
    {
        return User::factory()->create(['permission' => 'user', 'role' => '재무']);
    }

    public function test_two_currency_account_saves_two_rows_and_totals_land_in_the_snapshot(): void
    {
        $fx = CashAccount::create(['bank' => '하나', 'account_no' => '430-910007-816638', 'label' => '외화', 'currencies' => ['USD', 'EUR']]);
        $this->actingAs($u = $this->finance());

        Volt::test('erp.dashboard')->set('cashDate', '2026-10-14')->set('cashAccountId', (string) $fx->id)
            ->set('cashAmounts.USD', '1,000.50')->set('cashAmounts.EUR', '200')->call('saveAccountBalance');

        $this->assertSame(2, CashAccountBalance::count());
        $snap = CashSnapshot::whereDate('snapshot_date', '2026-10-14')->firstOrFail();
        $this->assertSame(1000.5, (float) $snap->balance_usd);
        $this->assertSame(200.0, (float) $snap->balance_eur);
        $this->assertSame(0, (int) $snap->balance_krw);
        $this->assertSame($u->id, (int) $snap->entered_by);
    }

    public function test_untouched_accounts_carry_forward_their_latest_value(): void
    {
        $a = CashAccount::create(['bank' => '신한', 'account_no' => '1', 'currencies' => ['KRW']]);
        $b = CashAccount::create(['bank' => '국민', 'account_no' => '2', 'currencies' => ['KRW']]);
        $svc = app(CashAccountBalanceService::class);
        $svc->save($a, '2026-10-10', ['KRW' => 1_000]);
        $svc->save($b, '2026-10-12', ['KRW' => 5_000]);
        $svc->save($a, '2026-10-14', ['KRW' => 3_000]);   // b 는 이 날 입력 없음 → 10-12 값 이어씀

        $this->assertSame(8_000, (int) CashSnapshot::whereDate('snapshot_date', '2026-10-14')->value('balance_krw'));
        $rows = $svc->rowsFor('2026-10-14');
        $bRow = $rows->first(fn ($r) => $r['account']->id === $b->id);
        $this->assertTrue($bRow['carried']);
        $this->assertSame('2026-10-12', $bRow['as_of']);
        // 소급 입력은 그 날짜 한 건만 캡처한다 — 뒤 날짜 스냅샷은 손대지 않는다
        $svc->save($b, '2026-10-11', ['KRW' => 9_000]);
        $this->assertSame(8_000, (int) CashSnapshot::whereDate('snapshot_date', '2026-10-14')->value('balance_krw'));
        $this->assertSame(10_000, (int) CashSnapshot::whereDate('snapshot_date', '2026-10-11')->value('balance_krw'));
    }

    public function test_krw_account_takes_its_sign_from_the_toggle(): void
    {
        $a = CashAccount::create(['bank' => '신한', 'account_no' => '1', 'currencies' => ['KRW']]);
        $this->actingAs($this->finance());

        Volt::test('erp.dashboard')->set('cashAccountId', (string) $a->id)->set('cashAmounts.KRW', '300,000,000')
            ->set('cashKrwNegative', true)->call('saveAccountBalance');

        $this->assertSame(-300_000_000, (int) CashSnapshot::latest('snapshot_date')->first()->balance_krw);
    }

    public function test_entry_permission_holders_can_add_and_hide_accounts_and_sales_cannot(): void
    {
        $this->actingAs($this->finance());
        Volt::test('erp.dashboard')->set('newBank', '농협')->set('newAccountNo', '301-0344-9249-21')->set('newCurrencies', ['KRW'])
            ->call('addCashAccount')->assertHasNoErrors();
        $acc = CashAccount::firstOrFail();
        $this->assertSame(['KRW'], $acc->currencies);
        $this->assertSame('농협 301-0344-9249-21', $acc->display_name);

        Volt::test('erp.dashboard')->call('deactivateCashAccount', $acc->id);
        $this->assertFalse($acc->fresh()->is_active);

        $this->actingAs(User::factory()->create(['permission' => 'user', 'role' => '영업', 'type' => 'employee']));
        Volt::test('erp.dashboard')->set('newBank', 'x')->set('newAccountNo', 'y')->call('addCashAccount')->assertStatus(403);
    }

    public function test_without_accounts_the_legacy_three_field_form_still_works(): void
    {
        $this->actingAs($this->finance());

        Volt::test('erp.dashboard')->assertSee(__('cash.krw'))->assertDontSee(__('cash.account.pick'))
            ->set('cashKrw', '1,000')->call('saveCashBalance');

        $this->assertSame(1_000, (int) CashSnapshot::latest('snapshot_date')->first()->balance_krw);
    }
}
