<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ForwardingCompany;
use App\Models\ForwardingInvoice;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 포워딩사 운임 인보이스(지급 청산) — jin 2026-07-16.
 * "줬나/안줬나" 지급 여부 추적. paid_at = 단일 출처, 감사로그 한글, 권한 canManageForwarding.
 */
class ForwardingInvoiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function admin(): User
    {
        return User::factory()->create(['permission' => 'admin', 'role' => '관리', 'email_verified_at' => now()]);
    }

    private function shipVehicle(int $fcId, array $attr = []): Vehicle
    {
        return Vehicle::create(array_merge([
            'vehicle_number' => '77가'.rand(1000, 9999),
            'sales_channel' => 'export',
            'forwarding_company_id' => $fcId,
            'shipping_date' => '2026-05-10',
        ], $attr));
    }

    public function test_settle_container_group_creates_invoice_and_audit(): void
    {
        $fc = ForwardingCompany::create(['name' => 'FWD A', 'is_active' => true]);
        $this->shipVehicle($fc->id, ['container_number' => 'ABCD1234567', 'shipping_method' => 'CONTAINER', 'transport_fee' => 500, 'currency' => 'USD']);

        $fkey = md5("{$fc->id}|container|ABCD1234567");

        Volt::actingAs($this->admin())->test('erp.forwarding-companies.index')
            ->set('invForm', [$fkey => ['amount' => '1200', 'currency' => 'USD']])
            ->call('saveInvoice', $fc->id, 'container', 'ABCD1234567', true)
            ->assertHasNoErrors();

        $inv = ForwardingInvoice::where('forwarding_company_id', $fc->id)->first();
        $this->assertNotNull($inv);
        $this->assertSame('1200.00', (string) $inv->amount);
        $this->assertNotNull($inv->paid_at, 'settle=true 면 청산(paid_at)');

        $this->assertTrue(
            AuditLog::where('auditable_type', ForwardingInvoice::class)
                ->where('action', 'forwarding_invoice_paid')->exists(),
            '청산 시 중앙 감사로그에 기록'
        );
    }

    public function test_unsettle_keeps_amount(): void
    {
        $fc = ForwardingCompany::create(['name' => 'FWD B', 'is_active' => true]);
        $inv = ForwardingInvoice::create([
            'forwarding_company_id' => $fc->id, 'group_type' => 'container', 'group_key' => 'X1',
            'currency' => 'USD', 'amount' => 900, 'paid_at' => now(),
        ]);

        Volt::actingAs($this->admin())->test('erp.forwarding-companies.index')
            ->call('unsettleInvoice', $inv->id)
            ->assertHasNoErrors();

        $inv->refresh();
        $this->assertNull($inv->paid_at, '청산 취소 시 미지급');
        $this->assertSame('900.00', (string) $inv->amount, '취소해도 금액 기록은 유지');
    }

    public function test_reconciliation_converted_and_difference(): void
    {
        $fc = ForwardingCompany::create(['name' => 'FWD REC', 'is_active' => true]);
        // jin 예시: 1000 EUR × 1300 = 1,300,000, 실송금 1,299,999 → 차액 +1
        $inv = ForwardingInvoice::create([
            'forwarding_company_id' => $fc->id, 'group_type' => 'container', 'group_key' => 'R1',
            'currency' => 'EUR', 'amount' => 1000, 'manual_rate' => 1300,
            'actual_paid_krw' => 1299999, 'write_off_krw' => 0,
        ]);
        $this->assertSame(1_300_000, $inv->converted_krw, '환산 KRW = floor(금액×환율)');
        $this->assertSame(1, $inv->difference_krw, '차액 = 환산 − 실송금 − 버림');

        // 버림 반영 → 차액 0
        $inv->write_off_krw = 1;
        $inv->save();
        $this->assertSame(0, $inv->fresh()->difference_krw, '우수리 버림 후 0');
    }

    public function test_reconciliation_krw_invoice_ignores_rate(): void
    {
        $fc = ForwardingCompany::create(['name' => 'FWD KRW', 'is_active' => true]);
        $inv = ForwardingInvoice::create([
            'forwarding_company_id' => $fc->id, 'group_type' => 'vessel', 'group_key' => 'K1',
            'currency' => 'KRW', 'amount' => 500000, 'manual_rate' => null, 'actual_paid_krw' => 500000,
        ]);
        $this->assertSame(500_000, $inv->converted_krw, 'KRW 청구는 금액 그대로(환율 무시)');
        $this->assertSame(0, $inv->difference_krw);
    }

    public function test_truncate_sets_writeoff_to_zero_diff(): void
    {
        $fc = ForwardingCompany::create(['name' => 'FWD TRUNC', 'is_active' => true]);
        $this->shipVehicle($fc->id, ['container_number' => 'RC1', 'shipping_method' => 'CONTAINER', 'transport_fee' => 1000, 'currency' => 'EUR']);
        $fkey = md5("{$fc->id}|container|RC1");

        $comp = Volt::actingAs($this->admin())->test('erp.forwarding-companies.index')
            ->set('invForm', [$fkey => ['currency' => 'EUR', 'amount' => '1000', 'manual_rate' => '1300', 'actual_paid_krw' => '1,299,999']])
            ->call('truncateInvoice', $fc->id, 'container', 'RC1')
            ->assertHasNoErrors();

        $this->assertSame('1', $comp->get('invForm')[$fkey]['write_off_krw'], '버림 = 우수리(+1)');

        $comp->call('saveInvoice', $fc->id, 'container', 'RC1', true)->assertHasNoErrors();
        $inv = ForwardingInvoice::where('group_key', 'RC1')->first();
        $this->assertSame(0, $inv->difference_krw, '버림 후 차액 0');
        $this->assertNotNull($inv->paid_at);
    }

    public function test_saveinvoice_persists_reconciliation_and_memo(): void
    {
        $fc = ForwardingCompany::create(['name' => 'FWD SAVE', 'is_active' => true]);
        $this->shipVehicle($fc->id, ['container_number' => 'RS1', 'shipping_method' => 'CONTAINER', 'transport_fee' => 2000, 'currency' => 'USD']);
        $fkey = md5("{$fc->id}|container|RS1");

        Volt::actingAs($this->admin())->test('erp.forwarding-companies.index')
            ->set('invForm', [$fkey => ['currency' => 'USD', 'amount' => '2000', 'manual_rate' => '1350', 'actual_paid_krw' => '2,700,000', 'memo' => '6월 운임 정산']])
            ->call('saveInvoice', $fc->id, 'container', 'RS1', false)
            ->assertHasNoErrors();

        $inv = ForwardingInvoice::where('group_key', 'RS1')->first();
        $this->assertSame('1350.0000', (string) $inv->manual_rate);
        $this->assertSame('2700000.00', (string) $inv->actual_paid_krw);
        $this->assertSame('6월 운임 정산', $inv->memo);
        $this->assertSame(2_700_000, $inv->converted_krw, 'USD 2000 × 1350');
        $this->assertSame(0, $inv->difference_krw);
    }

    public function test_non_manager_cannot_open_screen(): void
    {
        $sales = User::factory()->create(['permission' => 'user', 'role' => '영업', 'email_verified_at' => now()]);

        Volt::actingAs($sales)->test('erp.forwarding-companies.index')->assertStatus(403);
    }

    public function test_calendar_renders_including_multi_month_bar(): void
    {
        $fc = ForwardingCompany::create(['name' => 'FWD CAL', 'is_active' => true]);
        // 한 달 내
        $this->shipVehicle($fc->id, ['container_number' => 'C1', 'shipping_method' => 'CONTAINER', 'shipping_date' => '2026-05-10', 'eta_date' => '2026-05-20']);
        // 2달 걸침(5월→7월) — 좌우 화살표 세그먼트 케이스
        $this->shipVehicle($fc->id, ['container_number' => 'C2', 'shipping_method' => 'CONTAINER', 'shipping_date' => '2026-05-25', 'eta_date' => '2026-07-05']);

        Volt::actingAs($this->admin())->test('erp.forwarding-companies.index')
            ->set('calMonth', '2026-05')
            ->set('showCalendar', true)
            ->assertOk();
    }
}
