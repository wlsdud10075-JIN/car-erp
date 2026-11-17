<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Buyer;
use App\Models\DailyExchangeRate;
use App\Models\FinalPayment;
use App\Models\ReceivableHistory;
use App\Models\Setting;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 💱 판매잔금 환율 자동 기록 + 환차손익(회사) (jin 2026-11-17).
 *   «12월 1일부터 환율 기입란이 없어진다 — 전날 마감한 금액으로 넣자 · 종류는 기능설정에서 바꿀 수 있게»
 *   «회사이익 화면에는 환차손익 결과값» — 환차를 정산에서 뺀 정산의 실현 환차가 회사 몫.
 *
 * 지키는 것: ① 설정 전엔 종전(칸 보임·사람 값 저장) ② 시작일부터 칸이 숨고 서버가 입금일 전날 마감을 적는다(출처 날짜·종류도)
 *   ③ 확정 잔금·시작 전에 사람이 적은 환율은 안 덮는다 ④ 채권관리 입금도 같은 규칙 ⑤ 환차 제외 정산의 환차는 회사이익에 더해진다
 */
class PaymentRateAutoRecordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-12-02 10:00:00');
        Cache::put('exchange_rates', ['USD' => 1400.0], 3600);   // 실시간 조회 네트워크 회피
        DailyExchangeRate::create(['currency' => 'USD', 'rate_date' => '2026-11-27', 'rate' => 1320.5, 'base_rate' => 1333.0, 'source' => 'naver']);   // 금
        DailyExchangeRate::create(['currency' => 'USD', 'rate_date' => '2026-12-01', 'rate' => 1324.4, 'base_rate' => 1337.5, 'source' => 'naver']);   // 화
    }

    private function settings(string $autoFrom, string $kind = 'receive', string $fxFrom = ''): void
    {
        $set = Setting::companyTemplateSet();
        foreach (['payment_rate_auto_from_' => $autoFrom, 'payment_rate_kind_' => $kind, 'settlement_fx_excluded_from_' => $fxFrom] as $k => $v) {
            Setting::updateOrCreate(['key' => $k.$set], ['value' => $v, 'type' => 'string']);
        }
        Setting::flushRiskParamMemo();
    }

    private function vehicle(): Vehicle
    {
        $b = Buyer::firstOrCreate(['name' => 'FX BUYER'], ['is_active' => true]);

        return Vehicle::create([
            'vehicle_number' => '55오5555', 'sales_channel' => 'export', 'currency' => 'USD', 'dhl_request' => false, 'buyer_id' => $b->id,
            'exchange_rate' => 1300, 'sale_date' => '2026-11-01', 'sale_price' => 5000, 'purchase_date' => '2026-11-01',
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
    }

    public function test_before_the_start_date_the_field_shows_and_people_type_the_rate(): void
    {
        $this->settings('2026-12-15');
        $this->actingAs($this->admin());
        $v = $this->vehicle();

        $c = Volt::test('erp.vehicles.index')->call('openEdit', $v->id)->call('addFinalPayment')
            ->set('finalPayments.0.amount', '1000')->set('finalPayments.0.exchange_rate', '1350');
        $this->assertStringNotContainsString('data-fp-rate-auto', $c->html());
        $c->call('save')->assertHasNoErrors();

        $fp = FinalPayment::where('vehicle_id', $v->id)->sole();
        $this->assertEqualsWithDelta(1350.0, (float) $fp->exchange_rate, 0.001);
        $this->assertNull($fp->rate_source_date);
    }

    public function test_from_the_start_date_the_server_records_the_previous_day_close(): void
    {
        $this->settings('2026-12-01');
        $this->actingAs($this->admin());
        $v = $this->vehicle();

        $c = Volt::test('erp.vehicles.index')->call('openEdit', $v->id)->call('addFinalPayment');
        $this->assertStringContainsString('data-fp-rate-auto', $c->html(), '칸이 숨는다');
        $c->set('finalPayments.0.amount', '1000')
            ->set('finalPayments.0.exchange_rate', '9999')   // 클라이언트가 값을 밀어 넣어도
            ->call('save')->assertHasNoErrors();

        $fp = FinalPayment::where('vehicle_id', $v->id)->sole();
        $this->assertEqualsWithDelta(1324.4, (float) $fp->exchange_rate, 0.001, '입금일(12-02) 전날 12-01 마감 · 송금 받으실 때');
        $this->assertSame('2026-12-01', $fp->rate_source_date->toDateString());
        $this->assertSame('receive', $fp->rate_kind);
        $this->assertEqualsWithDelta(1_324_400.0, (float) $fp->amount_krw, 0.01);
    }

    public function test_kind_setting_switches_to_the_base_rate_and_monday_uses_friday(): void
    {
        $this->settings('2026-12-01', 'base');

        $this->assertSame(['exchange_rate' => 1333.0, 'rate_source_date' => '2026-11-27', 'rate_kind' => 'base'], FinalPayment::autoRateFields('USD', '2026-11-30'), '월요일 입금 = 금요일 마감 매매기준율');
    }

    public function test_confirmed_rows_and_earlier_human_rates_are_not_overwritten(): void
    {
        $v = $this->vehicle();
        $old = FinalPayment::create(['vehicle_id' => $v->id, 'type' => 'balance', 'amount' => 1000, 'exchange_rate' => 1310, 'payment_date' => '2026-11-20', 'confirmed_at' => now()]);
        $this->settings('2026-12-01');
        $this->actingAs($this->admin());

        Volt::test('erp.vehicles.index')->call('openEdit', $v->id)->set('finalPayments.0.note', '메모만')->call('save');

        $this->assertEqualsWithDelta(1310.0, (float) $old->fresh()->exchange_rate, 0.001, '확정 잔금 환율은 그대로');
        $this->assertNull($old->fresh()->rate_source_date);
    }

    public function test_receivables_deposit_follows_the_same_rule(): void
    {
        $this->settings('2026-12-01');
        $user = $this->admin();
        $this->actingAs($user);
        $v = $this->vehicle();

        $h = ReceivableHistory::create(['vehicle_id' => $v->id, 'collected_at' => '2026-12-02', 'collector_id' => $user->id, 'method' => 'deposit', 'amount' => 500, 'exchange_rate' => 1399]);

        $fp = FinalPayment::find($h->fresh()->final_payment_id);
        $this->assertEqualsWithDelta(1324.4, (float) $fp->exchange_rate, 0.001, '사람이 적은 1399 대신 전날 마감');
        $this->assertSame('2026-12-01', $fp->rate_source_date->toDateString());
        $this->assertEqualsWithDelta(1324.4, (float) $h->fresh()->exchange_rate, 0.001, '회수 줄도 같은 값');
    }

    public function test_company_profit_gets_the_fx_of_settlements_that_excluded_it(): void
    {
        DB::statement('PRAGMA foreign_keys = OFF');
        $this->settings('2026-12-01', 'receive', '2026-12');
        $v = $this->vehicle();
        FinalPayment::create(['vehicle_id' => $v->id, 'type' => 'balance', 'amount' => 5000, 'exchange_rate' => 1324.4, 'payment_date' => '2026-12-01', 'confirmed_at' => now()]);
        $mk = fn (string $month) => Settlement::create(['vehicle_id' => $v->id, 'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'pending', 'attributed_month' => $month])->fresh();

        $dec = $mk('2026-12-01');
        $fx = (int) round(5000 * (1324.4 - 1300));   // 실현 환차 = 실입금 − 받은외화 × 판매환율 = 122,000
        $this->assertSame($fx, $dec->companyFxKrw());
        $this->assertSame($dec->total_margin - $dec->actual_payout - $dec->shipping_fee + $fx, $dec->company_net, '회사 몫에 더해진다');

        $nov = $mk('2026-11-01');
        $this->assertSame(0, $nov->companyFxKrw(), '환차 포함 정산은 총마진에 이미 들어 있어 0');
    }

    public function test_fx_settings_are_saved_by_super_admin_only_and_audited(): void
    {
        $this->actingAs(User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]));

        Volt::test('admin.settings')->set('fxExcludedFrom', '2026-12')->set('paymentRateAutoFrom', '2026-12-01')->set('paymentRateKind', 'base')
            ->call('saveFxSettings')->assertHasNoErrors();
        Setting::flushRiskParamMemo();

        $this->assertSame('2026-12', Setting::settlementFxExcludedFrom());
        $this->assertSame('2026-12-01', Setting::paymentRateAutoFrom());
        $this->assertSame('base', Setting::paymentRateKind());
        $this->assertSame(1, AuditLog::where('action', 'fx_settings_changed')->count());
    }
}
