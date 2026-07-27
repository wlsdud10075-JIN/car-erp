<?php

namespace Tests\Feature;

use App\Models\ForwardingCompany;
use App\Models\ForwardingInvoice;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 묶음 기준 변경(container › vessel › declaration, 2026-07-27) 후속 remap 마이그레이션 검증.
 *
 * 화면은 (회사, group_type, group_key) 로 인보이스를 찾으므로, 우선순위가 바뀌면 declaration 으로
 * 저장된 행은 조회가 안 돼 지급 기록이 화면에서 사라진다. 마이그레이션은 "1:1 로 명확한" 것만 옮기고
 * 애매한 건(배 2척에 걸침 / 같은 배로 몰림 / 키 선점) 손대지 않는다.
 */
class ForwardingInvoiceVesselRemapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function runMigration(): void
    {
        $migration = require database_path('migrations/2026_07_27_120000_remap_declaration_forwarding_invoices_to_vessel.php');
        $migration->up();
    }

    private function vehicle(int $fcId, string $decl, ?string $vessel): Vehicle
    {
        return Vehicle::create([
            'vehicle_number' => '77가'.rand(1000, 9999),
            'sales_channel' => 'export',
            'forwarding_company_id' => $fcId,
            'export_declaration_number' => $decl,
            'vessel_name' => $vessel,
        ]);
    }

    private function invoice(int $fcId, string $type, string $key): ForwardingInvoice
    {
        return ForwardingInvoice::create([
            'forwarding_company_id' => $fcId,
            'group_type' => $type,
            'group_key' => $key,
            'currency' => 'USD',
            'amount' => 1590,
        ]);
    }

    /** 선박명이 하나로 확정되면 vessel 키로 옮긴다. */
    public function test_unambiguous_declaration_invoice_moves_to_vessel(): void
    {
        $fc = ForwardingCompany::create(['name' => 'FWD-A', 'is_active' => true]);
        $this->vehicle($fc->id, 'DECL-1', 'THALATTA V-WM612');
        $inv = $this->invoice($fc->id, 'declaration', 'DECL-1');

        $this->runMigration();

        $inv->refresh();
        $this->assertSame('vessel', $inv->group_type);
        $this->assertSame('THALATTA V-WM612', $inv->group_key);
    }

    /** 인보이스 1장이 배 2척에 걸치면 사람이 나눠야 하므로 건드리지 않는다. */
    public function test_declaration_spanning_two_vessels_is_left_alone(): void
    {
        $fc = ForwardingCompany::create(['name' => 'FWD-B', 'is_active' => true]);
        $this->vehicle($fc->id, 'DECL-2', 'MV GMT ASTRO V.2606');
        $this->vehicle($fc->id, 'DECL-2', 'MV AH SHIN V.2607');
        $inv = $this->invoice($fc->id, 'declaration', 'DECL-2');

        $this->runMigration();

        $inv->refresh();
        $this->assertSame('declaration', $inv->group_type);
        $this->assertSame('DECL-2', $inv->group_key);
    }

    /** 같은 배로 여러 인보이스가 몰리면(합칠지 여부는 사람 판단) 전부 그대로 둔다. */
    public function test_multiple_declarations_targeting_same_vessel_are_left_alone(): void
    {
        $fc = ForwardingCompany::create(['name' => 'FWD-C', 'is_active' => true]);
        $this->vehicle($fc->id, 'DECL-3', 'MV GMT ASTRO V.2606');
        $this->vehicle($fc->id, 'DECL-4', 'MV GMT ASTRO V.2606');
        $a = $this->invoice($fc->id, 'declaration', 'DECL-3');
        $b = $this->invoice($fc->id, 'declaration', 'DECL-4');

        $this->runMigration();

        $this->assertSame('declaration', $a->refresh()->group_type);
        $this->assertSame('declaration', $b->refresh()->group_type);
    }

    /** 대상 vessel 키를 이미 다른 인보이스가 쓰고 있으면 덮지 않는다. */
    public function test_occupied_vessel_key_is_not_overwritten(): void
    {
        $fc = ForwardingCompany::create(['name' => 'FWD-D', 'is_active' => true]);
        $this->vehicle($fc->id, 'DECL-5', 'MV SEA HOPE');
        $existing = $this->invoice($fc->id, 'vessel', 'MV SEA HOPE');
        $inv = $this->invoice($fc->id, 'declaration', 'DECL-5');

        $this->runMigration();

        $this->assertSame('declaration', $inv->refresh()->group_type);
        $this->assertSame('vessel', $existing->refresh()->group_type);
    }

    /** 선박명이 아직 없으면 declaration 폴백으로 계속 유효하다. */
    public function test_declaration_without_vessel_stays(): void
    {
        $fc = ForwardingCompany::create(['name' => 'FWD-E', 'is_active' => true]);
        $this->vehicle($fc->id, 'DECL-6', null);
        $inv = $this->invoice($fc->id, 'declaration', 'DECL-6');

        $this->runMigration();

        $this->assertSame('declaration', $inv->refresh()->group_type);
    }

    /** 다른 회사가 같은 선박명을 써도 서로 간섭하지 않는다(묶음은 회사별). */
    public function test_same_vessel_across_companies_does_not_collide(): void
    {
        $a = ForwardingCompany::create(['name' => 'FWD-F', 'is_active' => true]);
        $b = ForwardingCompany::create(['name' => 'FWD-G', 'is_active' => true]);
        $this->vehicle($a->id, 'DECL-7', 'MV SHARED');
        $this->vehicle($b->id, 'DECL-8', 'MV SHARED');
        $ia = $this->invoice($a->id, 'declaration', 'DECL-7');
        $ib = $this->invoice($b->id, 'declaration', 'DECL-8');

        $this->runMigration();

        $this->assertSame('vessel', $ia->refresh()->group_type);
        $this->assertSame('vessel', $ib->refresh()->group_type);
    }
}
