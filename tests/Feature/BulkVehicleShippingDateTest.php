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

    public function test_eight_digit_date_is_normalized(): void
    {
        // 20260801 이 그대로 새면 Eloquent date 캐스트가 Unix 타임스탬프로 읽어 1970 이 된다
        // (2026-07-20 실측). 화면(app.js focusout)뿐 아니라 서버에서도 막는다.
        $user = $this->clearanceUser();
        $this->actingAs($user);
        $v = $this->vehicle('11가1001', 'GMT');

        app(BulkVehicleShippingDateService::class)->apply(
            Vehicle::query(), ['shipping_date' => '20260801'], $user, 'GMT'
        );

        $this->assertSame('2026-08-01', $v->fresh()->shipping_date->format('Y-m-d'));
    }

    public function test_rejects_malformed_date(): void
    {
        $user = $this->clearanceUser();
        $this->actingAs($user);

        $this->expectException(\InvalidArgumentException::class);
        app(BulkVehicleShippingDateService::class)->apply(
            Vehicle::query(), ['shipping_date' => '8월 1일'], $user, 'GMT'
        );
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

    // ───────────────────────── 선박명(VSL) 일괄 (jin 2026-08-12) ─────────────────────────

    /** 선박명이 하나뿐(또는 없음)이면 경고 없이 바로 덮는다. */
    public function test_vessel_applies_without_warning_when_uniform(): void
    {
        $user = $this->clearanceUser();
        $this->actingAs($user);
        $a = $this->vehicle('11가1001', '');
        $b = $this->vehicle('11가1002', '');

        Volt::test('erp.vehicles.index')
            ->set('search', '11가100')
            ->call('openBulkDate')
            ->set('bulkVessel', 'MV GMT ASTRO V.2602')
            ->call('applyBulkDate')
            ->assertSet('bulkDateOpen', false)
            ->assertSet('bulkVesselConflict', []);

        $this->assertSame('MV GMT ASTRO V.2602', $a->fresh()->vessel_name);
        $this->assertSame('MV GMT ASTRO V.2602', $b->fresh()->vessel_name);
    }

    /**
     * 🚢 **서로 다른 선박명이 섞였으면 한 번 멈춘다** — 모르고 덮으면 다른 배에 실린 차의
     * 배 이름이 수백 대 단위로 날아간다. 이때 **아무것도 저장되면 안 된다**(날짜 포함).
     */
    public function test_mixed_vessels_halt_before_writing_anything(): void
    {
        $user = $this->clearanceUser();
        $this->actingAs($user);
        $a = $this->vehicle('11가1001', 'MV GMT ASTRO V.2602');
        $b = $this->vehicle('11가1002', 'MV HYUNDAI SPIRIT');

        $c = Volt::test('erp.vehicles.index')
            ->set('search', '11가100')
            ->call('openBulkDate')
            ->set('bulkShipDate', '2026-08-01')
            ->set('bulkVessel', 'MV GMT ASTRO V.2602')
            ->call('applyBulkDate')
            ->assertSet('bulkDateOpen', true);   // 모달은 열린 채 경고 화면으로

        $this->assertNotSame([], $c->get('bulkVesselConflict'), '섞였는데 경고가 안 떴다');
        $this->assertSame('MV HYUNDAI SPIRIT', $b->fresh()->vessel_name, '경고 단계에서 덮어버렸다');
        $this->assertNull($a->fresh()->shipping_date, '경고 단계인데 날짜가 저장됐다');
    }

    /** 「그래도 덮기」 = 같은 액션 재호출. 이번엔 경고 없이 전부 덮는다. */
    public function test_force_overwrite_proceeds(): void
    {
        $user = $this->clearanceUser();
        $this->actingAs($user);
        $a = $this->vehicle('11가1001', 'MV GMT ASTRO V.2602');
        $b = $this->vehicle('11가1002', 'MV HYUNDAI SPIRIT');

        Volt::test('erp.vehicles.index')
            ->set('search', '11가100')
            ->call('openBulkDate')
            ->set('bulkVessel', 'MV GMT ASTRO V.2602')
            ->call('applyBulkDate')          // 1회차 = 경고
            ->call('applyBulkDate')          // 2회차 = 그래도 덮기
            ->assertSet('bulkDateOpen', false);

        $this->assertSame('MV GMT ASTRO V.2602', $a->fresh()->vessel_name);
        $this->assertSame('MV GMT ASTRO V.2602', $b->fresh()->vessel_name);
    }

    /**
     * ⚠️ **빈 값은 "다름" 으로 안 센다** — 처음 채우는 게 이 도구의 주 용도라, 비어 있는 차가
     * 섞였다고 매번 경고하면 경고가 무의미해진다(늘 뜨면 아무도 안 읽는다).
     */
    public function test_empty_vessel_does_not_count_as_conflict(): void
    {
        $user = $this->clearanceUser();
        $this->actingAs($user);
        $filled = $this->vehicle('11가1001', 'MV GMT ASTRO V.2602');
        $empty = $this->vehicle('11가1002', '');

        Volt::test('erp.vehicles.index')
            ->set('search', '11가100')
            ->call('openBulkDate')
            ->set('bulkVessel', 'MV GMT ASTRO V.2602')
            ->call('applyBulkDate')
            ->assertSet('bulkDateOpen', false)
            ->assertSet('bulkVesselConflict', []);

        $this->assertSame('MV GMT ASTRO V.2602', $empty->fresh()->vessel_name);
        $this->assertSame('MV GMT ASTRO V.2602', $filled->fresh()->vessel_name);
    }

    /** 선박명 칸을 비우고 날짜만 넣으면 기존 선박명은 그대로 — 경고도 안 뜬다. */
    public function test_blank_vessel_leaves_existing_untouched(): void
    {
        $user = $this->clearanceUser();
        $this->actingAs($user);
        $a = $this->vehicle('11가1001', 'MV GMT ASTRO V.2602');
        $b = $this->vehicle('11가1002', 'MV HYUNDAI SPIRIT');

        Volt::test('erp.vehicles.index')
            ->set('search', '11가100')
            ->call('openBulkDate')
            ->set('bulkShipDate', '2026-08-01')
            ->call('applyBulkDate')
            ->assertSet('bulkDateOpen', false)
            ->assertSet('bulkVesselConflict', []);

        $this->assertSame('MV GMT ASTRO V.2602', $a->fresh()->vessel_name);
        $this->assertSame('MV HYUNDAI SPIRIT', $b->fresh()->vessel_name);
        $this->assertSame('2026-08-01', $a->fresh()->shipping_date->format('Y-m-d'));
    }

    /** 🚫 이 도구로는 값을 **비울 수 없다** — 빈 문자열이 지우기 신호가 되면 오조작 한 번에 수백 대가 날아간다. */
    public function test_cannot_clear_a_vessel_through_the_bulk_tool(): void
    {
        $user = $this->clearanceUser();
        $this->actingAs($user);
        $v = $this->vehicle('11가1001', 'MV GMT ASTRO V.2602');

        $this->expectException(\InvalidArgumentException::class);
        try {
            app(BulkVehicleShippingDateService::class)->apply(
                Vehicle::query(), ['vessel_name' => '   '], $user, '지우기 시도'
            );
        } finally {
            $this->assertSame('MV GMT ASTRO V.2602', $v->fresh()->vessel_name);
        }
    }

    /** 선박명 변경도 감사에 남는다 — 수백 대를 한 번에 바꾸는데 기록이 없으면 되짚을 수 없다. */
    public function test_vessel_change_is_audited(): void
    {
        $user = $this->clearanceUser();
        $this->actingAs($user);
        $v = $this->vehicle('11가1001', 'MV OLD');

        app(BulkVehicleShippingDateService::class)->apply(
            Vehicle::query()->where('id', $v->id), ['vessel_name' => 'MV NEW'], $user, 'GMT 선적분'
        );

        $this->assertSame('MV NEW', $v->fresh()->vessel_name);
        $this->assertDatabaseHas('audit_logs', [
            'auditable_id' => $v->id, 'action' => 'updated', 'column_name' => 'vessel_name',
            'old_value' => 'MV OLD', 'new_value' => 'MV NEW',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'auditable_id' => $v->id, 'action' => 'bulk_shipping_date_applied', 'new_value' => 'GMT 선적분',
        ]);
    }

    /** 분포 집계 — 빈 값은 `''` 키로 모이고, 종류 수는 실제 값만 센다. */
    public function test_vessel_breakdown_and_distinct_count(): void
    {
        $this->vehicle('11가1001', 'MV A');
        $this->vehicle('11가1002', 'MV A');
        $this->vehicle('11가1003', 'MV B');
        $this->vehicle('11가1004', '');

        $service = app(BulkVehicleShippingDateService::class);
        $breakdown = $service->vesselBreakdown(Vehicle::query());

        $this->assertSame(2, $breakdown['MV A']);
        $this->assertSame(1, $breakdown['MV B']);
        $this->assertSame(1, $breakdown['']);
        $this->assertSame(2, BulkVehicleShippingDateService::distinctCount($breakdown));
        $this->assertSame(1, BulkVehicleShippingDateService::distinctCount(['MV A' => 5, '' => 9]));
    }
}
