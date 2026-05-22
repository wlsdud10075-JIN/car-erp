<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Consignee;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * 회의확장씬 #4 회귀 — Consignee ID 2컬럼 + 선적 진입 가드.
 *
 * - consignees.id_type / id_value 컬럼 + Consignee::ID_TYPES 상수
 * - Vehicle::guardStageOrderForExport — bl_loading_location 입력 시 consignee_id 필수 (판매 단계 컨사이니)
 *
 * 가드는 vehicles/index::save() 가 명시 호출 (Vehicle::saving 자동 호출 X) —
 * 테스트에서 Vehicle::create 후 $v->guardStageOrderForExport() 직접 호출.
 */
class ConsigneeGateTest extends TestCase
{
    use RefreshDatabase;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function makeVehicle(array $overrides = []): Vehicle
    {
        $this->counter++;

        // C4 통과 (말소 완료) + C5 skip (sale_price=0) 기본값.
        $defaults = [
            'vehicle_number' => 'CGT-'.$this->counter,
            'sales_channel' => 'export',
            'currency' => 'KRW',
            'exchange_rate' => 1,
            'dhl_request' => false,
            'is_deregistered' => true,
            'deregistration_document' => 'dereg.pdf',
        ];

        return Vehicle::create(array_merge($defaults, $overrides));
    }

    public function test_consignees_has_id_type_and_id_value_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('consignees', 'id_type'));
        $this->assertTrue(Schema::hasColumn('consignees', 'id_value'));
    }

    public function test_consignee_id_types_constant_has_three_keys(): void
    {
        $this->assertSame(
            ['rrn', 'passport', 'business'],
            array_keys(Consignee::ID_TYPES)
        );
    }

    public function test_consignee_save_with_id_type_and_value(): void
    {
        $buyer = Buyer::create(['name' => 'BUYER', 'is_active' => true]);
        $c = Consignee::create([
            'buyer_id' => $buyer->id,
            'name' => 'CONS',
            'id_type' => 'rrn',
            'id_value' => '900101-1234567',
            'is_active' => true,
        ]);

        $this->assertSame('rrn', $c->fresh()->id_type);
        $this->assertSame('900101-1234567', $c->fresh()->id_value);
    }

    public function test_shipping_entry_blocked_without_consignee(): void
    {
        // bl_loading_location 입력 + consignee_id 없음 → 가드 차단
        $v = $this->makeVehicle([
            'bl_loading_location' => '부산항',
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('선적 진입 전 판매 컨사이니를 지정해야 합니다');

        $v->guardStageOrderForExport();
    }

    public function test_shipping_entry_passes_with_consignee(): void
    {
        // bl_loading_location 입력 + consignee_id 있음 → 가드 통과
        $buyer = Buyer::create(['name' => 'B', 'is_active' => true]);
        $c = Consignee::create([
            'buyer_id' => $buyer->id,
            'name' => 'C',
            'is_active' => true,
        ]);
        $v = $this->makeVehicle([
            'buyer_id' => $buyer->id,
            'consignee_id' => $c->id,
            'bl_loading_location' => '부산항',
        ]);

        $v->guardStageOrderForExport();
        $this->assertTrue(true);   // 예외 없이 통과
    }

    public function test_non_shipping_stage_does_not_require_consignee(): void
    {
        // bl_loading_location 없으면 consignee_id 무관 (통관 단계엔 본 가드 미적용)
        $buyer = Buyer::create(['name' => 'EB', 'is_active' => true]);
        $v = $this->makeVehicle([
            'export_buyer_id' => $buyer->id,
            'shipping_date' => '2026-05-01',
        ]);

        $v->guardStageOrderForExport();
        $this->assertTrue(true);   // 예외 없이 통과
    }

    public function test_no_export_input_passes(): void
    {
        // hasExportInput 0 → 가드 자체 skip (consignee_id 무관)
        $v = $this->makeVehicle();

        $v->guardStageOrderForExport();
        $this->assertTrue(true);
    }
}
