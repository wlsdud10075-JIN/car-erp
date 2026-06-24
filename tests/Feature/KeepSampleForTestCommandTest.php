<?php

namespace Tests\Feature;

use App\Models\Settlement;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 2026-06-24 — vehicles:keep-sample-for-test 검증.
 *
 * 표본(거래완료 N + 완납 진행중 M)만 남기고 차량 도메인 하드삭제.
 * SoftDeletes/deleting 가드를 우회하는지(paid/closed 정산도 삭제) + created_at 분산 확인.
 */
class KeepSampleForTestCommandTest extends TestCase
{
    use RefreshDatabase;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function completedVehicle(string $status = 'paid'): Vehicle
    {
        $v = Vehicle::create([
            'vehicle_number' => 'KC-'.++$this->counter,
            'sales_channel' => 'export', 'currency' => 'USD', 'exchange_rate' => 1350,
            'dhl_request' => false,
            'sale_price' => 400, 'sale_date' => '2026-03-01', 'purchase_date' => '2026-01-01',
            'bl_document' => 'bl/doc-'.$this->counter.'.pdf',
            'bl_loading_location' => 'Busan', 'is_export_cleared' => true,
        ]);
        // 거래완료 차량엔 정산 1건 (paid 상태로 — deleting 가드 대상).
        Settlement::create([
            'vehicle_id' => $v->id, 'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => $status,
            'confirmed_at' => now(), 'paid_at' => now(),
        ]);

        return $v;
    }

    private function inProgressPaidVehicle(): Vehicle
    {
        $v = Vehicle::create([
            'vehicle_number' => 'KP-'.++$this->counter,
            'sales_channel' => 'export', 'currency' => 'USD', 'exchange_rate' => 1350,
            'dhl_request' => false,
            'sale_price' => 400, 'sale_date' => '2026-04-01', 'purchase_date' => '2026-02-01',
        ]);
        // 완납 (미수 0) — 진행중(거래완료 아님), 정산 없음 (수동추가 대상).
        $v->finalPayments()->create([
            'amount' => 400, 'type' => 'balance', 'exchange_rate' => 1350,
            'payment_date' => '2026-04-01', 'confirmed_at' => now(),
        ]);
        $v->refreshProgressCache();

        return $v;
    }

    public function test_keeps_only_sample_and_hard_deletes_the_rest(): void
    {
        // 거래완료 5대(정산 paid) + 완납 진행중 2대 + 그냥 매입중 4대.
        $completed = collect(range(1, 5))->map(fn () => $this->completedVehicle());
        $inProgress = collect(range(1, 2))->map(fn () => $this->inProgressPaidVehicle());
        collect(range(1, 4))->each(fn () => Vehicle::create([
            'vehicle_number' => 'KX-'.++$this->counter, 'sales_channel' => 'export',
            'currency' => 'USD', 'dhl_request' => false, 'purchase_price' => 100, 'purchase_date' => '2026-01-01',
        ]));

        $this->assertSame(11, Vehicle::count());
        $this->assertSame(5, Settlement::count());

        $this->artisan('vehicles:keep-sample-for-test', [
            '--completed' => 3, '--in-progress' => 2, '--spread-months' => 3, '--force' => true,
        ])->assertSuccessful();

        // 3 거래완료 + 2 진행중 = 5대만 남음.
        $this->assertSame(5, Vehicle::count());
        // 남은 정산 = 거래완료 3건 (진행중은 정산 없음). paid 정산도 하드삭제됨(가드 우회).
        $this->assertSame(3, Settlement::count());

        // 진행중 완납 2대 보존 (수동추가 테스트 대상).
        $this->assertSame(2, Vehicle::whereIn('id', $inProgress->pluck('id'))->count());
    }

    public function test_spread_distributes_created_at_across_months(): void
    {
        collect(range(1, 6))->each(fn () => $this->completedVehicle());

        $this->artisan('vehicles:keep-sample-for-test', [
            '--completed' => 6, '--in-progress' => 0, '--spread-months' => 3, '--force' => true,
        ])->assertSuccessful();

        // 6건을 최근 3개월에 분산 → distinct 월 = 3.
        $months = Settlement::pluck('created_at')->map(fn ($d) => $d->format('Y-m'))->unique();
        $this->assertSame(3, $months->count());
    }
}
