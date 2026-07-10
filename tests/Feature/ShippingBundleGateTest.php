<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\FinalPayment;
use App\Models\Salesman;
use App\Models\Setting;
use App\Models\ShippingRequest;
use App\Models\UnpaidExportOverride;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Phase 3 — 묶음 (나)+(a) 선적 진입/B/L 락 (2026-07-10).
 *   (나) 묶음 내 각 차량이 각자 임계(선적 50% / B/L 100%) 넘어야. 미달 1대면 (a) 묶음 통째 대기.
 *   관리 승인 우회(unpaid_export_override) 차량은 blocker 제외. lockEnabled 토글 존중.
 */
class ShippingBundleGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function admin(): User
    {
        return User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
    }

    private function setLock(string $lock, bool $on): void
    {
        Setting::updateOrCreate(
            ['key' => 'lock_'.$lock.'_'.Setting::companyTemplateSet()],
            ['value' => $on ? '1' : '0', 'type' => 'boolean'],
        );
    }

    /**
     * $unpaidPercents 미수율(%) 배열대로 export 차량 N대 + 한 묶음(batch) 생성.
     *
     * @return array{0:string,1:array<int,Vehicle>}
     */
    private function bundle(array $unpaidPercents): array
    {
        $buyer = Buyer::create(['name' => 'B', 'is_active' => true]);
        $sm = Salesman::create(['name' => 'S', 'is_active' => true, 'type' => 'freelance']);
        $batchId = 'batch-test';
        $vehicles = [];

        foreach (array_values($unpaidPercents) as $i => $unpaidPct) {
            $sale = 10_000_000;
            $received = (int) round($sale * (1 - $unpaidPct / 100));
            $v = Vehicle::create([
                'vehicle_number' => 'BND-'.$i,
                'sales_channel' => 'export',
                'buyer_id' => $buyer->id,
                'salesman_id' => $sm->id,
                'currency' => 'KRW',
                'exchange_rate' => 1,
                'sale_price' => $sale,
                'sale_date' => '2026-05-01',
            ]);
            if ($received > 0) {
                FinalPayment::create([
                    'vehicle_id' => $v->id,
                    'amount' => $received,
                    'type' => 'balance',
                    'payment_date' => '2026-05-05',
                    'confirmed_at' => now(),
                ]);
            }
            $v->refreshCaches();
            ShippingRequest::create([
                'batch_id' => $batchId,
                'vehicle_id' => $v->id,
                'shipping_method' => 'RORO',
                'status' => ShippingRequest::STATUS_REQUESTED,
                'requested_by_email' => 'x@x.test',
                'requested_at' => now(),
            ]);
            $vehicles[] = $v;
        }

        return [$batchId, $vehicles];
    }

    private function bundleStatus(string $batchId): string
    {
        return ShippingRequest::where('batch_id', $batchId)->value('status');
    }

    public function test_entry_blocked_when_a_member_is_under_50(): void
    {
        $this->setLock('shipping_entry', true);
        [$batch] = $this->bundle([30, 20, 80]);   // 80% 미수 차량이 blocker
        $this->actingAs($this->admin());

        Volt::test('erp.shipping-requests.index')
            ->call('changeStatus', $batch, ShippingRequest::STATUS_IN_PROGRESS);

        // (a) 묶음 통째 대기 — 여전히 requested
        $this->assertSame(ShippingRequest::STATUS_REQUESTED, $this->bundleStatus($batch));
    }

    public function test_entry_passes_when_all_members_over_50(): void
    {
        $this->setLock('shipping_entry', true);
        [$batch] = $this->bundle([30, 20, 40]);   // 전부 50% 이상 입금
        $this->actingAs($this->admin());

        Volt::test('erp.shipping-requests.index')
            ->call('changeStatus', $batch, ShippingRequest::STATUS_IN_PROGRESS);

        $this->assertSame(ShippingRequest::STATUS_IN_PROGRESS, $this->bundleStatus($batch));
    }

    public function test_entry_lock_off_passes_even_with_blocker(): void
    {
        $this->setLock('shipping_entry', false);
        [$batch] = $this->bundle([30, 20, 80]);
        $this->actingAs($this->admin());

        Volt::test('erp.shipping-requests.index')
            ->call('changeStatus', $batch, ShippingRequest::STATUS_IN_PROGRESS);

        $this->assertSame(ShippingRequest::STATUS_IN_PROGRESS, $this->bundleStatus($batch));
    }

    public function test_entry_override_excludes_blocker(): void
    {
        $this->setLock('shipping_entry', true);
        [$batch, $vehicles] = $this->bundle([30, 20, 80]);
        // 80% 미수 차량에 관리 선적 진입 우회 승인
        UnpaidExportOverride::create([
            'vehicle_id' => $vehicles[2]->id,
            'stage' => 'shipping',
            'approved_by' => $this->admin()->id,
            'approved_at' => now(),
            'reason' => 'L/C 확인',
        ]);
        $this->actingAs($this->admin());

        Volt::test('erp.shipping-requests.index')
            ->call('changeStatus', $batch, ShippingRequest::STATUS_IN_PROGRESS);

        $this->assertSame(ShippingRequest::STATUS_IN_PROGRESS, $this->bundleStatus($batch));
    }
}
