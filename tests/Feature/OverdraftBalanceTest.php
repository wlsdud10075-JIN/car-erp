<?php

namespace Tests\Feature;

use App\Models\CashSnapshot;
use App\Models\Setting;
use App\Models\User;
use App\Services\CapitalStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 마이너스 통장(한도대출) — 원화 잔액 음수 허용 (jin 2026-08-12).
 *
 * 싼카는 −5억 한도 통장을 써서 원화 잔액이 +/− 를 오간다. 종전엔 입력이 `>= 0` 으로 막혀 있어
 * 그 날 0 을 넣거나 입력을 건너뛸 수밖에 없었고, **둘 다 청산가치를 사용액만큼 부풀렸다.**
 *
 * 🧭 잔액을 있는 그대로 넣는 게 회계적으로 맞다 — 청산가치·순자산이 통장현금을 그대로 더하므로
 *    음수면 자동 차감된다("접으면 갚아야 할 돈"). 차입금을 별도 항목으로 쪼개지 않은 이유는
 *    사람이 통장 앱의 숫자를 두 칸으로 나눠 옮기다 실수하기 때문(`CapitalStatusService::overdraftLimit` 주석).
 */
class OverdraftBalanceTest extends TestCase
{
    use RefreshDatabase;

    private function cashUser(): User
    {
        return User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
    }

    /** 🚨 원화 음수가 저장된다 — 이 기능의 존재 이유. */
    public function test_negative_krw_is_saved(): void
    {
        $this->actingAs($this->cashUser());

        Volt::test('erp.dashboard')
            ->set('cashKrw', '300,000,000')
            ->set('cashKrwNegative', true)
            ->call('saveCashBalance');

        $this->assertSame(-300_000_000, (int) CashSnapshot::latest('snapshot_date')->first()->balance_krw);
    }

    /**
     * ⚠️ **부호는 토글이 정한다** — 금액칸(`data-money`)이 `-` 를 실시간으로 지우고 `-` 키를
     * ÷1000 단축키로 써서 음수를 타이핑할 수 없다. 칸에 음수가 와도 절대값으로 다룬 뒤 토글로 부호를 붙인다
     * (안 그러면 토글 OFF + 칸에 음수 = 의도치 않은 음수가 된다).
     */
    public function test_sign_comes_from_the_toggle_not_the_text(): void
    {
        $this->actingAs($this->cashUser());

        Volt::test('erp.dashboard')
            ->set('cashKrw', '-300,000,000')   // 칸에 음수가 흘러들어와도
            ->set('cashKrwNegative', false)    // 토글이 꺼져 있으면 양수다
            ->call('saveCashBalance');

        $this->assertSame(300_000_000, (int) CashSnapshot::latest('snapshot_date')->first()->balance_krw);
    }

    /** 외화는 종전대로 0 이상 — 마이너스 통장은 원화 계좌라 외화칸의 음수는 오타일 확률이 높다. */
    public function test_negative_foreign_currency_is_still_rejected(): void
    {
        $this->actingAs($this->cashUser());

        Volt::test('erp.dashboard')
            ->set('cashKrw', '1,000,000')
            ->set('cashUsd', '-50')
            ->call('saveCashBalance');

        $this->assertSame(0, CashSnapshot::count(), '외화 음수가 저장됐다');
    }

    /** 🚨 음수 잔액이 청산가치·순자산에서 그대로 차감된다 — 별도 부채 항목 없이도 회계가 맞는 근거. */
    public function test_negative_balance_reduces_liquidation_and_working_capital(): void
    {
        $user = $this->cashUser();
        $svc = app(CapitalStatusService::class);

        $plus = $svc->capture(['krw' => 100_000_000], $user, '2026-08-10');
        $minus = $svc->capture(['krw' => -300_000_000], $user, '2026-08-11');

        $a = $svc->derive($plus);
        $b = $svc->derive($minus);

        $this->assertSame(400_000_000, $a['liquidation_krw'] - $b['liquidation_krw'],
            '음수 잔액이 청산가치에 그대로 안 반영됐다');
        $this->assertSame(400_000_000, $a['working_capital_krw'] - $b['working_capital_krw']);
        $this->assertSame(-300_000_000, $b['cash_krw']);
    }

    /** 한도를 안 넣은 회사엔 아무것도 안 뜬다 — 마이너스 통장을 안 쓰는 곳에 빈 위젯을 만들지 않는다. */
    public function test_no_limit_means_no_overdraft_block(): void
    {
        $svc = app(CapitalStatusService::class);
        $snap = $svc->capture(['krw' => -100_000_000], $this->cashUser());

        $this->assertNull($svc->overdraftLimit());
        $this->assertNull($svc->derive($snap)['overdraft']);
    }

    /** 사용액·여력 — 잔액이 +면 사용 0. */
    public function test_used_and_headroom(): void
    {
        Setting::updateOrCreate(
            ['key' => CapitalStatusService::OVERDRAFT_KEY],
            ['value' => '500000000', 'type' => 'integer'],
        );
        $svc = app(CapitalStatusService::class);
        $user = $this->cashUser();

        $od = $svc->derive($svc->capture(['krw' => -300_000_000], $user, '2026-08-11'))['overdraft'];
        $this->assertSame(['limit' => 500_000_000, 'used' => 300_000_000, 'headroom' => 200_000_000, 'over' => false], $od);

        $od = $svc->derive($svc->capture(['krw' => 50_000_000], $user, '2026-08-12'))['overdraft'];
        $this->assertSame(0, $od['used'], '잔액이 +인데 사용액이 잡혔다');
        $this->assertSame(500_000_000, $od['headroom']);

        $od = $svc->derive($svc->capture(['krw' => -600_000_000], $user, '2026-08-13'))['overdraft'];
        $this->assertTrue($od['over'], '한도 초과가 안 잡혔다');
        $this->assertSame(0, $od['headroom']);
    }

    /**
     * ⚠️ **사용액은 원화만 본다.** 환산현금(`cash_krw`)은 외화를 더한 값이라 달러가 있으면
     * 마이너스가 가려진다 — 한도는 원화 통장의 것이다.
     */
    public function test_used_ignores_foreign_currency(): void
    {
        Setting::updateOrCreate(
            ['key' => CapitalStatusService::OVERDRAFT_KEY],
            ['value' => '500000000', 'type' => 'integer'],
        );
        $svc = app(CapitalStatusService::class);

        // 원화 −3억인데 달러가 넉넉해 환산현금은 플러스인 상황
        $snap = $svc->capture(['krw' => -300_000_000, 'usd' => 500_000], $this->cashUser());

        $this->assertGreaterThan(0, $snap->cash_krw, '전제가 틀렸다 — 환산현금이 플러스여야 하는 케이스');
        $this->assertSame(300_000_000, $svc->derive($snap)['overdraft']['used'],
            '외화가 마이너스 통장 사용액을 가렸다');
    }

    /** 한도는 **표시 전용** — 청산가치·손익 어디에도 안 들어간다. */
    public function test_limit_never_touches_the_numbers(): void
    {
        $svc = app(CapitalStatusService::class);
        $user = $this->cashUser();
        $snap = $svc->capture(['krw' => -300_000_000], $user);

        $before = $svc->derive($snap);
        Setting::updateOrCreate(
            ['key' => CapitalStatusService::OVERDRAFT_KEY],
            ['value' => '500000000', 'type' => 'integer'],
        );
        $after = app(CapitalStatusService::class)->derive($snap->fresh());

        $this->assertSame($before['liquidation_krw'], $after['liquidation_krw']);
        $this->assertSame($before['working_capital_krw'], $after['working_capital_krw']);
        $this->assertSame($before['cash_krw'], $after['cash_krw']);
    }
}
