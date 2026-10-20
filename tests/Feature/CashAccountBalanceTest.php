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

    /** 어느 계좌든 원화·달러·유로 세 칸 (jin 2026-10-14 «신한이라고 한화만 해야 된다는 보장이 어디 있어») — 비운 칸은 안 건드린다. */
    public function test_any_account_takes_all_three_currencies_and_totals_land_in_the_snapshot(): void
    {
        $sh = CashAccount::create(['bank' => '신한', 'account_no' => '140-013-363536']);
        $this->actingAs($u = $this->finance());

        Volt::test('erp.dashboard')->set('cashDate', '2026-10-13')->set('cashAccountId', (string) $sh->id)
            ->set('cashAmounts.KRW', '3,000,000')->set('cashAmounts.USD', '1,000.50')->set('cashAmounts.EUR', '200')->call('saveAccountBalance');

        $this->assertSame(3, CashAccountBalance::count());
        $snap = CashSnapshot::whereDate('snapshot_date', '2026-10-13')->firstOrFail();
        $this->assertSame(3_000_000, (int) $snap->balance_krw);
        $this->assertSame(1000.5, (float) $snap->balance_usd);
        $this->assertSame(200.0, (float) $snap->balance_eur);
        $this->assertSame($u->id, (int) $snap->entered_by);

        // 달러만 다시 넣으면 원화·유로는 그대로(이어쓰기)
        Volt::test('erp.dashboard')->set('cashDate', '2026-10-14')->set('cashAccountId', (string) $sh->id)   // 미래 날짜는 거부되므로 어제→오늘
            ->set('cashAmounts.USD', '900')->call('saveAccountBalance');
        $snap2 = CashSnapshot::whereDate('snapshot_date', '2026-10-14')->firstOrFail();
        $this->assertSame(3_000_000, (int) $snap2->balance_krw);
        $this->assertSame(900.0, (float) $snap2->balance_usd);
        $this->assertSame(200.0, (float) $snap2->balance_eur);
    }

    public function test_untouched_accounts_carry_forward_their_latest_value(): void
    {
        $a = CashAccount::create(['bank' => '신한', 'account_no' => '1']);
        $b = CashAccount::create(['bank' => '국민', 'account_no' => '2']);
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

    public function test_never_entered_accounts_are_flagged_above_the_table(): void
    {
        $a = CashAccount::create(['bank' => '신한', 'account_no' => '1']);
        CashAccount::create(['bank' => '국민', 'account_no' => '2']);
        app(CashAccountBalanceService::class)->save($a, now()->toDateString(), ['KRW' => 1]);   // 원화만 넣어도 「넣은 계좌」다
        $this->actingAs($this->finance());

        Volt::test('erp.dashboard')->assertSeeHtml('data-cash-never-entered')->assertSee(__('cash.account.never_entered', ['n' => 1]));
    }

    /** 마이너스 통장은 원화 칸에 − 를 직접 친다(토글 없음, jin 2026-10-14). 외화 음수는 종전대로 거부. */
    /** 📱 폰에서 표가 옆으로 넘치던 것(jin 2026-10-20) — 폰은 계좌별 카드, 표는 sm 이상에서만. 카드에도 같은 값·합계·기준일. */
    public function test_phone_gets_account_cards_and_the_table_is_desktop_only(): void
    {
        $a = CashAccount::create(['bank' => '신한', 'account_no' => '140-013-275958', 'label' => '원화']);
        app(CashAccountBalanceService::class)->save($a, now()->toDateString(), ['KRW' => 52_766_844]);
        $this->actingAs($this->finance());

        $html = Volt::test('erp.dashboard')->html();
        $this->assertMatchesRegularExpression('/class="[^"]*sm:hidden[^"]*" data-cash-account-cards/', $html);
        $this->assertMatchesRegularExpression('/class="mt-2 hidden overflow-x-auto sm:block">\s*<table[^>]*data-cash-account-table/', $html);
        $cards = substr($html, strpos($html, 'data-cash-account-cards'), 4000);
        $this->assertStringContainsString('신한(원화) 140-013-275958', $cards);
        $this->assertStringContainsString('52,766,844', $cards);
    }

    public function test_krw_account_takes_a_typed_minus_and_foreign_negative_is_refused(): void
    {
        $a = CashAccount::create(['bank' => '신한', 'account_no' => '1']);
        $this->actingAs($this->finance());

        Volt::test('erp.dashboard')->set('cashAccountId', (string) $a->id)->set('cashAmounts.KRW', '-300,000,000')->call('saveAccountBalance');
        $this->assertSame(-300_000_000, (int) CashSnapshot::latest('snapshot_date')->first()->balance_krw);

        Volt::test('erp.dashboard')->set('cashAccountId', (string) $a->id)->set('cashAmounts.USD', '-5')->call('saveAccountBalance')
            ->assertDispatched('notify', type: 'error');
        $this->assertSame(0, CashAccountBalance::where('currency', 'USD')->count());
    }

    public function test_entry_permission_holders_can_add_and_hide_accounts_and_sales_cannot(): void
    {
        $this->actingAs($this->finance());
        Volt::test('erp.dashboard')->set('newBank', '농협')->set('newAccountNo', '301-0344-9249-21')
            ->call('addCashAccount')->assertHasNoErrors();
        $acc = CashAccount::firstOrFail();
        $this->assertSame(['KRW', 'USD', 'EUR'], $acc->currencies);
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
