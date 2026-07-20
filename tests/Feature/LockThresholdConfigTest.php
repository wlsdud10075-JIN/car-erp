<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Buyer;
use App\Models\FinalPayment;
use App\Models\Salesman;
use App\Models\Setting;
use App\Models\ShippingRequest;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 락 수치 조정 (jin 2026-07-20, item5) — super 가 기능설정에서 락별 "필요 입금률(%)" + 채권 유예일 조정.
 *   필요 입금률 P% → 미수율 cutoff = (100-P)/100. 게이트는 Setting::lockThreshold() 단일 출처로 판정.
 */
class LockThresholdConfigTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function setThreshold(string $lock, int $paidPct): void
    {
        Setting::updateOrCreate(
            ['key' => 'lock_threshold_'.$lock.'_'.Setting::companyTemplateSet()],
            ['value' => (string) $paidPct, 'type' => 'integer'],
        );
    }

    private function setLock(string $lock, bool $on): void
    {
        Setting::updateOrCreate(
            ['key' => 'lock_'.$lock.'_'.Setting::companyTemplateSet()],
            ['value' => $on ? '1' : '0', 'type' => 'boolean'],
        );
    }

    public function test_lock_threshold_converts_required_paid_pct_to_unpaid_cutoff(): void
    {
        // 기본값 — 선적/매입 50% → cutoff 0.5, B/L 100% → cutoff 0(완납).
        $this->assertEqualsWithDelta(0.5, Setting::lockThreshold('shipping_entry'), 0.0001);
        $this->assertEqualsWithDelta(0.5, Setting::lockThreshold('purchase_registration'), 0.0001);
        $this->assertEqualsWithDelta(0.0, Setting::lockThreshold('bl_issue'), 0.0001);

        // 선적 필요 입금률 70% 로 상향 → cutoff 0.3 (미수 30% 초과 차단).
        $this->setThreshold('shipping_entry', 70);
        $this->assertSame(70, Setting::lockRequiredPaidPct('shipping_entry'));
        $this->assertEqualsWithDelta(0.3, Setting::lockThreshold('shipping_entry'), 0.0001);
    }

    public function test_grace_days_configurable(): void
    {
        $this->assertSame(10, Setting::graceDays());   // 기본
        Setting::updateOrCreate(
            ['key' => 'receivable_grace_days_'.Setting::companyTemplateSet()],
            ['value' => '20', 'type' => 'integer'],
        );
        $this->assertSame(20, Setting::graceDays());
    }

    public function test_raising_shipping_threshold_blocks_previously_passing_bundle(): void
    {
        // 40% 미수(60% 입금) 차량 1대 묶음. 기본 50% 에선 통과, 필요 입금률 70%(cutoff 0.3) 로 올리면 차단.
        $this->setLock('shipping_entry', true);
        $buyer = Buyer::create(['name' => 'B', 'is_active' => true]);
        $sm = Salesman::create(['name' => 'S', 'is_active' => true, 'type' => 'freelance']);
        $v = Vehicle::create([
            'vehicle_number' => 'THR-1', 'sales_channel' => 'export',
            'buyer_id' => $buyer->id, 'salesman_id' => $sm->id,
            'currency' => 'KRW', 'exchange_rate' => 1,
            'sale_price' => 10_000_000, 'sale_date' => '2026-05-01',
        ]);
        FinalPayment::create([
            'vehicle_id' => $v->id, 'amount' => 6_000_000, 'type' => 'balance',
            'payment_date' => '2026-05-05', 'confirmed_at' => now(),
        ]);
        $v->refreshCaches();
        ShippingRequest::create([
            'batch_id' => 'thr-batch', 'vehicle_id' => $v->id, 'shipping_method' => 'RORO',
            'status' => ShippingRequest::STATUS_REQUESTED,
            'requested_by_email' => 'x@x.test', 'requested_at' => now(),
        ]);
        $admin = User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
        $this->actingAs($admin);

        // 기본 50% — 미수 40% ≤ 50% → 착수 통과
        Volt::test('erp.shipping-requests.index')
            ->call('changeStatus', 'thr-batch', ShippingRequest::STATUS_IN_PROGRESS);
        $this->assertSame(ShippingRequest::STATUS_IN_PROGRESS,
            ShippingRequest::where('batch_id', 'thr-batch')->value('status'));

        // 되돌리고 필요 입금률 70% 로 상향 → 미수 40% > 30% cutoff → 차단
        ShippingRequest::where('batch_id', 'thr-batch')->update(['status' => ShippingRequest::STATUS_REQUESTED]);
        $this->setThreshold('shipping_entry', 70);
        Volt::test('erp.shipping-requests.index')
            ->call('changeStatus', 'thr-batch', ShippingRequest::STATUS_IN_PROGRESS);
        $this->assertSame(ShippingRequest::STATUS_REQUESTED,
            ShippingRequest::where('batch_id', 'thr-batch')->value('status'), '70% 필요 시 40% 미수 묶음 대기');
    }

    public function test_save_lock_params_persists_and_audits(): void
    {
        $super = User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]);
        $this->actingAs($super);
        $set = Setting::companyTemplateSet();

        Volt::test('admin.settings')
            ->set('lockThresholds.shipping_entry', 70)
            ->set('graceDays', 15)
            ->call('saveLockParams');

        $this->assertSame('70', Setting::where('key', 'lock_threshold_shipping_entry_'.$set)->value('value'));
        $this->assertSame('15', Setting::where('key', 'receivable_grace_days_'.$set)->value('value'));
        $this->assertSame(70, Setting::lockRequiredPaidPct('shipping_entry'));
        $this->assertSame(15, Setting::graceDays());

        $this->assertTrue(
            AuditLog::where('action', 'lock_threshold_changed')->where('column_name', 'lock_threshold_shipping_entry')->exists(),
            '락 수치 변경 감사로그'
        );
    }
}
