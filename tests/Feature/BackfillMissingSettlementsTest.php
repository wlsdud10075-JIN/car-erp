<?php

namespace Tests\Feature;

use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 2026-06-24 — settlements:backfill-missing 검증.
 *
 * 갭: CLI/import(무인증)로 거래완료가 들어오면 Vehicle::saved 정산 자동생성 훅이 skip → 정산 누락.
 * 백필이 거래완료(bl_document)+영업담당자 있음+정산없음 차량에만 pending 정산을 멱등 생성하는지.
 */
class BackfillMissingSettlementsTest extends TestCase
{
    use RefreshDatabase;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    /** auth 없이(=CLI/import 모사) 거래완료 차량 생성 → 자동 정산 안 생김. */
    private function completedNoAuth(?int $salesmanId): Vehicle
    {
        return Vehicle::create([
            'vehicle_number' => 'BF-'.++$this->counter,
            'sales_channel' => 'export', 'currency' => 'USD', 'exchange_rate' => 1350,
            'dhl_request' => false,
            'sale_price' => 400, 'sale_date' => '2026-03-01', 'purchase_date' => '2026-01-01',
            'bl_document' => 'bl/doc-'.$this->counter.'.pdf',
            'salesman_id' => $salesmanId,
        ]);
    }

    public function test_backfills_only_completed_with_salesman_and_no_settlement(): void
    {
        $freelancer = Salesman::create(['name' => '프리', 'type' => 'freelance']);
        $employee = Salesman::create(['name' => '사내', 'type' => 'employee']);

        $a = $this->completedNoAuth($freelancer->id);
        $b = $this->completedNoAuth($employee->id);
        $noSalesman = $this->completedNoAuth(null);          // 담당자 없음 → 백필 불가
        // 매입중(거래완료 아님) → 대상 아님
        Vehicle::create([
            'vehicle_number' => 'BF-X'.++$this->counter, 'sales_channel' => 'export',
            'currency' => 'USD', 'dhl_request' => false, 'purchase_price' => 100,
            'purchase_date' => '2026-01-01', 'salesman_id' => $freelancer->id,
        ]);

        // 무인증 생성이라 자동 정산 0건.
        $this->assertSame(0, Settlement::count());

        $this->artisan('settlements:backfill-missing', ['--force' => true])->assertSuccessful();

        // 거래완료+담당자 있는 2대만 백필.
        $this->assertSame(2, Settlement::count());
        $this->assertSame('ratio', Settlement::where('vehicle_id', $a->id)->value('settlement_type'));
        $this->assertSame('per_unit', Settlement::where('vehicle_id', $b->id)->value('settlement_type'));
        $this->assertSame('pending', Settlement::where('vehicle_id', $a->id)->value('settlement_status'));
        // 담당자 없는 거래완료는 백필 안 됨.
        $this->assertSame(0, Settlement::where('vehicle_id', $noSalesman->id)->count());
    }

    public function test_idempotent_no_duplicate_on_rerun(): void
    {
        $sm = Salesman::create(['name' => '프리2', 'type' => 'freelance']);
        $this->completedNoAuth($sm->id);

        $this->artisan('settlements:backfill-missing', ['--force' => true])->assertSuccessful();
        $this->artisan('settlements:backfill-missing', ['--force' => true])->assertSuccessful();

        $this->assertSame(1, Settlement::count());
    }

    public function test_dry_run_creates_nothing(): void
    {
        $sm = Salesman::create(['name' => '프리3', 'type' => 'freelance']);
        $this->completedNoAuth($sm->id);

        $this->artisan('settlements:backfill-missing')->assertSuccessful();

        $this->assertSame(0, Settlement::count());
    }
}
