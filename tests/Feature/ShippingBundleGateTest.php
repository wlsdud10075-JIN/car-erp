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
 * 선적 진입 락 = 묶음 총금액 aggregate 50% (jin 2026-07-20, item4 — 개별(나) 되돌림).
 *   묶은 것의 총 입금률(Σ미수 / Σ총액)이 50% 미달이면 묶음 통째 대기. 큰 금액 차가 입금되면 묶음 넘어감.
 *   관리 승인 우회(unpaid_export_override stage=shipping) 차량은 aggregate 집계에서 제외(escape).
 *   lockEnabled 토글 존중. B/L(100%)·개별 C5 는 이 테스트 범위 밖(별개 유지).
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
     * $sales 로 차량별 판매가 지정(미지정 시 각 1천만). aggregate 테스트에서 큰/작은 금액 혼합용.
     *
     * @return array{0:string,1:array<int,Vehicle>}
     */
    private function bundle(array $unpaidPercents, ?array $sales = null): array
    {
        $buyer = Buyer::create(['name' => 'B', 'is_active' => true]);
        $sm = Salesman::create(['name' => 'S', 'is_active' => true, 'type' => 'freelance']);
        $batchId = 'batch-test';
        $vehicles = [];

        foreach (array_values($unpaidPercents) as $i => $unpaidPct) {
            $sale = $sales[$i] ?? 10_000_000;
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

    public function test_entry_blocked_when_bundle_aggregate_over_50(): void
    {
        $this->setLock('shipping_entry', true);
        [$batch] = $this->bundle([70, 70]);   // aggregate 미수율 14M/20M = 70% > 50%
        $this->actingAs($this->admin());

        Volt::test('erp.shipping-requests.index')
            ->call('changeStatus', $batch, ShippingRequest::STATUS_IN_PROGRESS);

        // 묶음 총 입금률 미달 → 통째 대기
        $this->assertSame(ShippingRequest::STATUS_REQUESTED, $this->bundleStatus($batch));
    }

    public function test_entry_passes_when_aggregate_under_50_despite_high_member(): void
    {
        // jin 예시 — 큰 차 완납 + 작은 차 미납. 개별로는 100% 미수인 차가 있어도 묶음 aggregate 는 통과.
        $this->setLock('shipping_entry', true);
        [$batch] = $this->bundle([0, 100], [100_000_000, 10_000_000]);   // 0 + 10M 미수 / 110M = 9%
        $this->actingAs($this->admin());

        Volt::test('erp.shipping-requests.index')
            ->call('changeStatus', $batch, ShippingRequest::STATUS_IN_PROGRESS);

        $this->assertSame(ShippingRequest::STATUS_IN_PROGRESS, $this->bundleStatus($batch));
    }

    public function test_entry_lock_off_passes_even_when_aggregate_over_50(): void
    {
        $this->setLock('shipping_entry', false);
        [$batch] = $this->bundle([70, 70]);
        $this->actingAs($this->admin());

        Volt::test('erp.shipping-requests.index')
            ->call('changeStatus', $batch, ShippingRequest::STATUS_IN_PROGRESS);

        $this->assertSame(ShippingRequest::STATUS_IN_PROGRESS, $this->bundleStatus($batch));
    }

    public function test_entry_override_excludes_vehicle_from_aggregate(): void
    {
        // 80% 미수 차 + 30% 미수 차 → aggregate 55% 차단. 큰 미수차에 우회 승인하면 집계 제외 → 나머지 30% 통과.
        $this->setLock('shipping_entry', true);
        [$batch, $vehicles] = $this->bundle([80, 30]);
        UnpaidExportOverride::create([
            'vehicle_id' => $vehicles[0]->id,
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
