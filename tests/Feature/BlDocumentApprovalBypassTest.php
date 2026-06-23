<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\UnpaidExportOverride;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * B/L 100% 게이트 — 관리/관리자 승인 우회 (2026-05-26 외부리뷰 감사 회의 결정).
 * 회의록 docs/meetings/2026-05-26-external-review-audit.md §사용자결정 1.
 *
 * 정책: B/L 발급은 잔금 100% 완납 필수. 부족분(예 90%)은 [관리] role 또는 admin/super 가
 *   미입금 우회(UnpaidExportOverride, stage='bl')를 승인하면 발급 가능.
 *
 * 검증 범위:
 * - 권한 매트릭스: 관리/admin/super → 승인 가능 / 영업·재무·수출통관 → 불가
 * - 관리 승인 우회 → 미완납 차량 B/L 발급 통과
 * - 다른 차량에 발급된 승인은 적용 안 됨 (per-vehicle FK)
 */
class BlDocumentApprovalBypassTest extends TestCase
{
    use RefreshDatabase;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    /** 미완납(90% 미수) 차량 1대 생성 — unauth 상태라 G2 등 가드 우회. */
    private function makeUnpaidVehicle(): Vehicle
    {
        $this->counter++;
        $buyer = Buyer::firstOrCreate(['name' => 'BYPASS BUYER'], ['is_active' => true]);

        $v = Vehicle::create([
            'vehicle_number' => 'BYPASS-'.$this->counter,
            'sales_channel' => 'export',
            'currency' => 'KRW',
            'exchange_rate' => 1,
            'sale_price' => 1000000,
            'sale_date' => '2026-05-01',
            'buyer_id' => $buyer->id,
            'dhl_request' => false,
        ]);

        // 입금 10만 → 미수 90만 (미수율 90%, 미완납)
        $v->finalPayments()->create([
            'amount' => 100000,
            'type' => 'deposit_down',
            'confirmed_at' => now(),
        ]);

        return $v->fresh();
    }

    // ── 권한 매트릭스 ──────────────────────────────────────────────
    public function test_management_role_can_approve_unpaid_export(): void
    {
        $user = User::factory()->create(['permission' => 'user', 'role' => '관리']);
        $this->assertTrue($user->canApproveUnpaidExport());
    }

    public function test_admin_and_super_can_approve_unpaid_export(): void
    {
        $admin = User::factory()->create(['permission' => 'admin']);
        $super = User::factory()->create(['permission' => 'super']);
        $this->assertTrue($admin->canApproveUnpaidExport());
        $this->assertTrue($super->canApproveUnpaidExport());
    }

    public function test_sales_finance_clearance_roles_cannot_approve(): void
    {
        foreach (['영업', '재무', '수출통관'] as $role) {
            $user = User::factory()->create(['permission' => 'user', 'role' => $role]);
            $this->assertFalse($user->canApproveUnpaidExport(), "{$role} 는 승인 권한이 없어야 함");
        }
    }

    // ── 통합: 관리 승인 우회가 B/L 100% 게이트를 통과시킨다 ──────────
    public function test_management_approved_override_bypasses_bl_gate(): void
    {
        $manager = User::factory()->create(['permission' => 'user', 'role' => '관리']);
        $v = $this->makeUnpaidVehicle();

        UnpaidExportOverride::create([
            'vehicle_id' => $v->id,
            'stage' => 'bl',
            'approved_by' => $manager->id,
            'reason' => '바이어 신용 확인 + 잔금 추후 입금 약정. 관리 승인 우회.',
            'approved_at' => now(),
            'ip_address' => '127.0.0.1',
            'sale_unpaid_amount_snapshot' => 900000,
        ]);

        $this->actingAs($manager);
        $v2 = Vehicle::find($v->id);
        $v2->bl_document = 'bl/bypass.pdf';
        $v2->save();   // 미완납이지만 관리 우회 승인 → 통과

        $this->assertSame('bl/bypass.pdf', $v2->fresh()->bl_document);
    }

    // ── 무결성: 다른 차량 승인은 적용 안 됨 ────────────────────────
    public function test_override_for_other_vehicle_does_not_bypass(): void
    {
        $admin = User::factory()->create(['permission' => 'admin']);
        $vehicleA = $this->makeUnpaidVehicle();
        $vehicleB = $this->makeUnpaidVehicle();

        // 승인은 A 차량에만
        UnpaidExportOverride::create([
            'vehicle_id' => $vehicleA->id,
            'stage' => 'bl',
            'approved_by' => $admin->id,
            'reason' => 'A 차량 한정 승인 — B 차량에는 적용되지 않아야 함.',
            'approved_at' => now(),
            'ip_address' => '127.0.0.1',
            'sale_unpaid_amount_snapshot' => 900000,
        ]);

        $this->actingAs($admin);
        $vB = Vehicle::find($vehicleB->id);
        $vB->bl_document = 'bl/otherB.pdf';

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('B/L 발행 차단');
        $vB->save();   // B에는 승인 없음 → 차단
    }
}
