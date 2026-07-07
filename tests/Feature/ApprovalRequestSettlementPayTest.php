<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ApprovalRequestSettlementPayTest extends TestCase
{
    use RefreshDatabase;

    private function makeSettlement(string $status = 'confirmed'): Settlement
    {
        $v = Vehicle::create(['vehicle_number' => '12가0100', 'sales_channel' => 'export']);

        return Settlement::create([
            'vehicle_id' => $v->id,
            'settlement_type' => 'per_unit',
            'per_unit_amount' => 500000,
            'other_deduction' => 0,
            'settlement_status' => $status,
        ]);
    }

    public function test_sales_user_cannot_transition_settlement_to_paid_directly(): void
    {
        $sales = User::factory()->create(['permission' => 'user', 'role' => '영업']);
        $this->actingAs($sales);

        $s = $this->makeSettlement('confirmed');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('월배치 승인으로만');   // Phase 2 — 직접 paid=대표만, 나머지 배치
        $s->settlement_status = 'paid';
        $s->save();
    }

    public function test_admin_can_transition_settlement_to_paid_directly(): void
    {
        $admin = User::factory()->create(['permission' => 'admin', 'role' => '관리']);
        $this->actingAs($admin);

        $s = $this->makeSettlement('confirmed');

        $s->settlement_status = 'paid';
        $s->save();

        $this->assertSame('paid', $s->fresh()->settlement_status);
    }

    public function test_approval_request_execute_transitions_settlement_to_paid(): void
    {
        $sales = User::factory()->create(['permission' => 'user', 'role' => '영업']);
        // Phase 2 — 개별 지급 최종 실행은 대표(admin/super)만.
        $rep = User::factory()->create(['permission' => 'admin', 'role' => '관리']);

        $s = $this->makeSettlement('confirmed');

        $req = ApprovalRequest::create([
            'requester_id' => $sales->id,
            'action_type' => ApprovalRequest::TYPE_SETTLEMENT_PAY,
            'target_type' => Settlement::class,
            'target_id' => $s->id,
            'status' => ApprovalRequest::STATUS_PENDING,
            'reason' => 'paid 전환 요청',
        ]);

        // 대표(admin) 컨텍스트에서 execute()
        $this->actingAs($rep);
        $req->update(['status' => ApprovalRequest::STATUS_APPROVED, 'approver_id' => $rep->id, 'decided_at' => now()]);
        $req->execute();

        $this->assertSame('paid', $s->fresh()->settlement_status);
        $this->assertNotNull($s->fresh()->paid_at);
    }

    public function test_manager_cannot_execute_individual_pay_bypassing_representative(): void
    {
        // Phase 2 SoD — manager 가 개별 지급 승인으로 대표 미경유 paid 하는 이중경로 차단.
        $sales = User::factory()->create(['permission' => 'user', 'role' => '영업']);
        $manager = User::factory()->create(['permission' => 'manager', 'role' => '관리']);
        $s = $this->makeSettlement('confirmed');

        $req = ApprovalRequest::create([
            'requester_id' => $sales->id,
            'action_type' => ApprovalRequest::TYPE_SETTLEMENT_PAY,
            'target_type' => Settlement::class,
            'target_id' => $s->id,
            'status' => ApprovalRequest::STATUS_PENDING,
            'reason' => 'paid 전환 요청',
        ]);

        $this->actingAs($manager);
        try {
            $req->execute();
            $this->fail('manager 개별 지급 실행은 차단돼야 한다');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('대표', $e->getMessage());
        }
        $this->assertSame('confirmed', $s->fresh()->settlement_status, '대표 미경유 paid 불가');
    }

    public function test_approval_request_execute_blocks_non_confirmed_settlement(): void
    {
        $sales = User::factory()->create(['permission' => 'user', 'role' => '영업']);
        $manager = User::factory()->create(['permission' => 'user', 'role' => '관리']);

        $s = $this->makeSettlement('pending');   // confirmed 아님

        $req = ApprovalRequest::create([
            'requester_id' => $sales->id,
            'action_type' => ApprovalRequest::TYPE_SETTLEMENT_PAY,
            'target_type' => Settlement::class,
            'target_id' => $s->id,
            'status' => ApprovalRequest::STATUS_APPROVED,
            'approver_id' => $manager->id,
            'decided_at' => now(),
        ]);

        $this->actingAs($manager);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('confirmed가 아닙니다');
        $req->execute();
    }

    public function test_audit_logs_link_approval_request_id_on_settlement_pay(): void
    {
        $sales = User::factory()->create(['permission' => 'user', 'role' => '영업']);
        $rep = User::factory()->create(['permission' => 'admin', 'role' => '관리']);   // Phase 2 — 실행=대표

        $s = $this->makeSettlement('confirmed');

        $req = ApprovalRequest::create([
            'requester_id' => $sales->id,
            'action_type' => ApprovalRequest::TYPE_SETTLEMENT_PAY,
            'target_type' => Settlement::class,
            'target_id' => $s->id,
            'status' => ApprovalRequest::STATUS_APPROVED,
            'approver_id' => $rep->id,
            'decided_at' => now(),
        ]);

        $this->actingAs($rep);
        $req->execute();

        $log = AuditLog::where('auditable_type', Settlement::class)
            ->where('auditable_id', $s->id)
            ->where('column_name', 'settlement_status')
            ->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame($req->id, $log->approval_request_id, 'audit_logs.approval_request_id가 ApprovalRequest와 링크되어야 함');
        $this->assertSame('confirmed', $log->old_value);
        $this->assertSame('paid', $log->new_value);
    }
}
