<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\BulkVehicleShippingDateService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 선적일·ETA 일괄 지정 (jin 2026-07-28) — 한 선박에 실린 차량을 필터로 추려 날짜를 한 번에.
 * 핵심 안전장치: 대상은 서버가 필터로 재도출(클라 ID 불신) · 차량별 스코프 재인가 · 개별+배치 감사.
 */
class BulkVehicleShippingDateTest extends TestCase
{
    use RefreshDatabase;

    private function vehicle(string $number, string $vessel): Vehicle
    {
        $sm = Salesman::firstOrCreate(['name' => 'TESTMAN'], ['type' => 'employee', 'is_active' => true]);

        return Vehicle::create([
            'vehicle_number' => $number, 'sales_channel' => 'export',
            'currency' => 'USD', 'exchange_rate' => 1350, 'dhl_request' => false,
            'salesman_id' => $sm->id, 'purchase_price' => 5_000_000,
            'purchase_date' => now()->toDateString(), 'vessel_name' => $vessel,
        ]);
    }

    private function clearanceUser(): User
    {
        return User::factory()->create(['permission' => 'user', 'role' => '수출통관', 'email_verified_at' => now()]);
    }

    public function test_applies_only_to_filtered_vehicles(): void
    {
        $user = $this->clearanceUser();
        $this->actingAs($user);
        $onBoard = $this->vehicle('11가1001', 'GMT');
        $other = $this->vehicle('22나2002', 'OTHER');

        Volt::test('erp.vehicles.index')
            ->set('search', 'GMT')
            ->call('openBulkDate')
            ->set('bulkShipDate', '2026-08-01')
            ->set('bulkEtaDate', '2026-08-20')
            ->set('bulkDateReason', 'GMT 선적분')
            ->call('applyBulkDate')
            ->assertSet('bulkDateOpen', false);

        $this->assertSame('2026-08-01', $onBoard->fresh()->shipping_date->format('Y-m-d'));
        $this->assertSame('2026-08-20', $onBoard->fresh()->eta_date->format('Y-m-d'));
        $this->assertNull($other->fresh()->shipping_date);   // 필터 밖 차량은 그대로
    }

    public function test_blank_field_is_left_unchanged(): void
    {
        $user = $this->clearanceUser();
        $this->actingAs($user);
        $v = $this->vehicle('11가1001', 'GMT');
        $v->update(['eta_date' => '2026-07-01']);

        app(BulkVehicleShippingDateService::class)->apply(
            Vehicle::query(), ['shipping_date' => '2026-08-01', 'eta_date' => ''], $user, 'GMT'
        );

        $v->refresh();
        $this->assertSame('2026-08-01', $v->shipping_date->format('Y-m-d'));
        $this->assertSame('2026-07-01', $v->eta_date->format('Y-m-d'));   // 빈칸은 안 건드림
    }

    public function test_same_value_counts_as_unchanged_and_writes_no_audit(): void
    {
        $user = $this->clearanceUser();
        $this->actingAs($user);
        $v = $this->vehicle('11가1001', 'GMT');
        $v->update(['shipping_date' => '2026-08-01']);
        AuditLog::query()->delete();

        $result = app(BulkVehicleShippingDateService::class)->apply(
            Vehicle::query(), ['shipping_date' => '2026-08-01'], $user, 'GMT'
        );

        $this->assertSame(0, $result['applied']);
        $this->assertSame(1, $result['unchanged']);
        $this->assertSame(0, AuditLog::where('auditable_id', $v->id)->count());
    }

    public function test_writes_both_column_audit_and_batch_reason(): void
    {
        $user = $this->clearanceUser();
        $this->actingAs($user);
        $v = $this->vehicle('11가1001', 'GMT');
        AuditLog::query()->delete();

        app(BulkVehicleShippingDateService::class)->apply(
            Vehicle::query(), ['shipping_date' => '2026-08-01'], $user, 'GMT 선적분'
        );

        // 컬럼 변경 감사(AUDITED_COLUMNS 등재분) + 일괄 출처 감사 둘 다
        $this->assertTrue(AuditLog::where('auditable_id', $v->id)->where('column_name', 'shipping_date')->exists());
        $batch = AuditLog::where('action', 'bulk_shipping_date_applied')->first();
        $this->assertNotNull($batch);
        $this->assertSame('GMT 선적분', $batch->new_value);
    }

    public function test_rejects_user_without_clearance_access(): void
    {
        $sales = User::factory()->create(['permission' => 'user', 'role' => '영업', 'email_verified_at' => now()]);
        $this->actingAs($sales);
        $this->vehicle('11가1001', 'GMT');

        $this->expectException(AuthorizationException::class);
        app(BulkVehicleShippingDateService::class)->apply(
            Vehicle::query(), ['shipping_date' => '2026-08-01'], $sales, 'GMT'
        );
    }

    public function test_rejects_non_whitelisted_column(): void
    {
        $user = $this->clearanceUser();
        $this->actingAs($user);

        $this->expectException(\InvalidArgumentException::class);
        app(BulkVehicleShippingDateService::class)->apply(
            Vehicle::query(), ['sale_price' => '1'], $user, 'GMT'
        );
    }

    public function test_rejects_when_both_dates_blank(): void
    {
        $user = $this->clearanceUser();
        $this->actingAs($user);

        $this->expectException(\InvalidArgumentException::class);
        app(BulkVehicleShippingDateService::class)->apply(
            Vehicle::query(), ['shipping_date' => '', 'eta_date' => ''], $user, 'GMT'
        );
    }
}
