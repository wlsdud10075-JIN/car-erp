<?php

namespace Tests\Feature;

use App\Models\PurchaseBalancePayment;
use App\Models\Setting;
use App\Models\TaskAlarm;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * karaba 매매상 잔금 10일 알림 (3단계, 2026-07-12).
 *   거래처구분 '매매상' + 계약금(down PBP) 입력 + 잔금 미납 → task_alarm 'purchase_balance_due'.
 *   due_date = 계약금일 + 10. 잔금 완납 시 자동 해소.
 */
class KarabaBalanceAlarmTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::updateOrCreate(['key' => 'company_template_set'], ['value' => 'karaba', 'type' => 'string']);
        Setting::updateOrCreate(['key' => 'alarm_enabled'], ['value' => '1', 'type' => 'boolean']);
        $this->actingAs(User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]));
    }

    private function dealerVehicle(bool $isDealer = true): Vehicle
    {
        return Vehicle::create([
            'vehicle_number' => '77허8888',
            'sales_channel' => 'export',
            'currency' => 'KRW',
            'exchange_rate' => 1,
            'purchase_price' => 10000000,
            'is_dealer_purchase' => $isDealer,   // 매매상 체크박스 (2026-07-22 트리거 이관)
            'dhl_request' => false,
        ]);
    }

    private function addDown(Vehicle $v, int $amount = 1000000): void
    {
        PurchaseBalancePayment::create([
            'vehicle_id' => $v->id, 'type' => 'down', 'amount' => $amount,
            'payment_date' => now()->startOfDay(), 'confirmed_at' => now(),
        ]);
    }

    public function test_scope_matches_dealer_with_deposit_and_unpaid(): void
    {
        $v = $this->dealerVehicle();
        $this->addDown($v);

        $this->assertSame(1, Vehicle::query()->action('purchase_balance_due')->count());
        $this->assertSame(10, $v->fresh()->purchase_balance_due_days);
    }

    public function test_scope_excludes_non_dealer(): void
    {
        $v = $this->dealerVehicle(false);   // 매매상 체크 안 함
        $this->addDown($v);

        $this->assertSame(0, Vehicle::query()->action('purchase_balance_due')->count());
        $this->assertNull($v->fresh()->purchase_balance_due_days);
    }

    public function test_scope_excludes_when_no_deposit(): void
    {
        $this->dealerVehicle();   // 계약금 없음

        $this->assertSame(0, Vehicle::query()->action('purchase_balance_due')->count());
    }

    public function test_scan_creates_alarm_then_resolves_on_full_payment(): void
    {
        $v = $this->dealerVehicle();
        $this->addDown($v);

        $this->artisan('alarms:scan')->assertSuccessful();

        $alarm = TaskAlarm::where('type', 'purchase_balance_due')->where('vehicle_id', $v->id)->first();
        $this->assertNotNull($alarm);
        $this->assertSame('관리', $alarm->target_role);
        $this->assertNull($alarm->resolved_at);
        $this->assertSame(now()->addDays(10)->toDateString(), $alarm->due_date->toDateString());

        // 잔금 완납 → PBP saved 훅이 즉시 해소
        PurchaseBalancePayment::create([
            'vehicle_id' => $v->id, 'type' => 'balance', 'amount' => 9000000,
            'payment_date' => now()->startOfDay(), 'confirmed_at' => now(),
        ]);

        $this->assertNotNull($alarm->fresh()->resolved_at);
    }

    public function test_vehicle_list_shows_balance_due_badge(): void
    {
        $v = $this->dealerVehicle();
        $this->addDown($v);

        Volt::test('erp.vehicles.index')->assertSee('D-10 잔금');
    }

    public function test_lead_days_setting_overrides_deadline(): void
    {
        Setting::updateOrCreate(['key' => 'alarm_balance_due_days'], ['value' => '20', 'type' => 'integer']);
        $v = $this->dealerVehicle();
        $this->addDown($v);   // 계약금 오늘 → 마감 오늘+20

        $this->assertSame(20, $v->fresh()->purchase_balance_due_days);
    }

    public function test_feature_settings_shows_balance_due_alarm_config(): void
    {
        $this->actingAs(User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]));

        Volt::test('admin.settings')->assertSee('매매상 잔금 (계약금 후)');
    }
}
