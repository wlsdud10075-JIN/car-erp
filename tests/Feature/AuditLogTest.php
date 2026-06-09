<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Settlement;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_vehicle_create_logs_created_event(): void
    {
        $v = Vehicle::create(['vehicle_number' => '12가0001', 'sales_channel' => 'export']);

        $logs = AuditLog::where('auditable_type', Vehicle::class)
            ->where('auditable_id', $v->id)->get();

        $this->assertCount(1, $logs);
        $this->assertSame('created', $logs->first()->action);
        $this->assertNull($logs->first()->column_name);
    }

    public function test_vehicle_update_on_tracked_column_logs_change(): void
    {
        $v = Vehicle::create(['vehicle_number' => '12가0002', 'sales_channel' => 'export', 'sale_price' => 1000]);
        $v->sale_price = 2500;
        $v->save();

        $log = AuditLog::where('auditable_type', Vehicle::class)
            ->where('auditable_id', $v->id)
            ->where('action', 'updated')
            ->where('column_name', 'sale_price')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('1000', $log->old_value);
        $this->assertSame('2500', $log->new_value);
    }

    public function test_vehicle_update_on_untracked_column_does_not_log(): void
    {
        $v = Vehicle::create(['vehicle_number' => '12가0003', 'sales_channel' => 'export']);
        $beforeCount = AuditLog::where('auditable_id', $v->id)->where('action', 'updated')->count();

        $v->model_type = 'Sonata';   // 추적 안 하는 컬럼
        $v->save();

        $afterCount = AuditLog::where('auditable_id', $v->id)->where('action', 'updated')->count();
        $this->assertSame($beforeCount, $afterCount);
    }

    public function test_vehicle_rrn_change_logs_with_masked_values(): void
    {
        $v = Vehicle::create(['vehicle_number' => '12가0004', 'sales_channel' => 'export']);
        $v->nice_reg_owner_rrn = '900101-1234567';
        $v->save();

        $log = AuditLog::where('auditable_id', $v->id)
            ->where('column_name', 'nice_reg_owner_rrn')
            ->first();

        $this->assertNotNull($log);
        $this->assertNull($log->old_value);                                       // 직전 NULL
        $this->assertStringNotContainsString('900101', (string) $log->new_value); // 평문 절대 노출 X
        $this->assertSame(AuditLog::MASKED_COLUMNS['nice_reg_owner_rrn'], $log->new_value);
    }

    public function test_vehicle_rrn_reassign_same_value_does_not_log(): void
    {
        $v = Vehicle::create([
            'vehicle_number' => '12가0005',
            'sales_channel' => 'export',
            'nice_reg_owner_rrn' => '900101-1234567',
        ]);

        $beforeCount = AuditLog::where('auditable_id', $v->id)->count();

        $fresh = Vehicle::find($v->id);
        $fresh->nice_reg_owner_rrn = '900101-1234567';   // 동일 평문 재할당
        $fresh->save();

        $afterCount = AuditLog::where('auditable_id', $v->id)->count();
        $this->assertSame($beforeCount, $afterCount, '동일 RRN 재할당은 mutator에서 skip → audit 없어야 함');
    }

    public function test_vehicle_force_delete_logs_only_force_deleted(): void
    {
        $v = Vehicle::create(['vehicle_number' => '12가0006', 'sales_channel' => 'export']);
        $v->forceDelete();

        $logs = AuditLog::where('auditable_id', $v->id)
            ->whereIn('action', ['deleted', 'force_deleted'])
            ->pluck('action')
            ->all();

        $this->assertSame(['force_deleted'], $logs, 'force_deleted만 1건 — soft deleted 중복 X');
    }

    public function test_vehicle_soft_delete_logs_deleted(): void
    {
        $v = Vehicle::create(['vehicle_number' => '12가0007', 'sales_channel' => 'export']);
        $v->delete();

        $log = AuditLog::where('auditable_id', $v->id)
            ->where('action', 'deleted')
            ->first();

        $this->assertNotNull($log);
    }

    // 2026-05-19 풀회의 P0-3 — 말소 처리 actor 책임 추적.
    // 4 role 누구나 말소 처리 가능해질 예정 → audit_logs 기록 필수.
    public function test_vehicle_deregistration_change_logs_audit(): void
    {
        $v = Vehicle::create(['vehicle_number' => '12가0009', 'sales_channel' => 'export']);
        $v->is_deregistered = true;
        $v->deregistration_document = 'dereg.pdf';
        $v->save();

        $deregLog = AuditLog::where('auditable_id', $v->id)
            ->where('column_name', 'is_deregistered')
            ->first();
        $docLog = AuditLog::where('auditable_id', $v->id)
            ->where('column_name', 'deregistration_document')
            ->first();

        $this->assertNotNull($deregLog, 'is_deregistered 변경이 audit_logs에 기록돼야 함');
        $this->assertNotNull($docLog, 'deregistration_document 변경이 audit_logs에 기록돼야 함');
        $this->assertSame('1', (string) $deregLog->new_value);
        $this->assertSame('dereg.pdf', $docLog->new_value);
    }

    public function test_settlement_status_change_logs(): void
    {
        $v = Vehicle::create(['vehicle_number' => '12가0008', 'sales_channel' => 'export']);
        $s = Settlement::create([
            'vehicle_id' => $v->id,
            'settlement_type' => 'ratio',
            'settlement_ratio' => 50,
            'settlement_status' => 'pending',
        ]);

        $s->settlement_status = 'confirmed';
        $s->save();

        $log = AuditLog::where('auditable_type', Settlement::class)
            ->where('auditable_id', $s->id)
            ->where('column_name', 'settlement_status')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('pending', $log->old_value);
        $this->assertSame('confirmed', $log->new_value);
    }

    // Review2 항목 A (2026-06-09) — 사내직원 차등정산 수동 조정(건당 10만→20만 등) 추적.
    // jin 정책상 변경은 허용 — 잠그지 않고 감사로그로 추적성만 확보.
    public function test_settlement_amount_param_change_logs(): void
    {
        $v = Vehicle::create(['vehicle_number' => '12가0010', 'sales_channel' => 'export']);
        $s = Settlement::create([
            'vehicle_id' => $v->id,
            'settlement_type' => 'per_unit',
            'per_unit_amount' => 100000,
            'settlement_status' => 'pending',
        ]);

        $s->per_unit_amount = 200000;   // 건당 10만 → 20만 (차등 tier 수동 조정)
        $s->save();

        $log = AuditLog::where('auditable_type', Settlement::class)
            ->where('auditable_id', $s->id)
            ->where('column_name', 'per_unit_amount')
            ->first();

        $this->assertNotNull($log, 'per_unit_amount 변경이 audit_logs에 기록돼야 함');
        $this->assertSame(100000, (int) $log->old_value);
        $this->assertSame(200000, (int) $log->new_value);
    }
}
