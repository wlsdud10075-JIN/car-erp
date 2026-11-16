<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\BulkExportClearedService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 통관 체크 일괄 (jin 2026-11-16 A안) — 실무 순서 = 신고서 업로드 → 체크빌 → 통관 체크.
 *   ① 신고서 있는 차만 켠다(없는 차는 제외) ② 체크빌 없는 차는 경고만(그래도 켠다)
 *   ③ 이미 체크된 차는 안 건드린다 ④ 수출통관 권한만 ⑤ 화면 모달이 같은 판정을 그린다.
 */
class BulkExportClearedTest extends TestCase
{
    use RefreshDatabase;

    private function vehicle(string $number, array $attrs = []): Vehicle
    {
        $sm = Salesman::firstOrCreate(['name' => 'TESTMAN'], ['type' => 'employee', 'is_active' => true]);

        return Vehicle::create(array_merge([
            'vehicle_number' => $number, 'sales_channel' => 'export',
            'currency' => 'USD', 'exchange_rate' => 1350, 'dhl_request' => false,
            'salesman_id' => $sm->id, 'purchase_price' => 5_000_000,
            'purchase_date' => now()->toDateString(),
            'bl_loading_location' => '평택항',
        ], $attrs));
    }

    private function clearanceUser(): User
    {
        return User::factory()->create(['permission' => 'user', 'role' => '수출통관', 'email_verified_at' => now()]);
    }

    private function svc(): BulkExportClearedService
    {
        return app(BulkExportClearedService::class);
    }

    public function test_only_vehicles_with_a_declaration_get_checked_and_missing_checkbill_only_warns(): void
    {
        $user = $this->clearanceUser();
        $this->actingAs($user);
        $full = $this->vehicle('11가1001', ['export_declaration_document' => 'vehicles/1/d.pdf', 'checkbill_document' => 'vehicles/1/c.pdf']);
        $noCb = $this->vehicle('22나2002', ['export_declaration_document' => 'vehicles/2/d.pdf']);
        $noDoc = $this->vehicle('33다3003');
        $done = $this->vehicle('44라4004', ['export_declaration_document' => 'vehicles/4/d.pdf', 'is_export_cleared' => true]);
        $ids = [$full->id, $noCb->id, $noDoc->id, $done->id];

        $prev = $this->svc()->preview($ids, $user);
        $this->assertSame(['11가1001', '22나2002'], array_column($prev['targets'], 'number'));
        $this->assertSame([false, true], array_column($prev['targets'], 'no_checkbill'), '체크빌 없음은 경고 표시만');
        $this->assertSame(['33다3003'], $prev['no_doc']);
        $this->assertSame(['44라4004'], $prev['already']);

        $r = $this->svc()->apply($ids, $user, '테스트');

        $this->assertSame(2, $r['applied']);
        $this->assertTrue((bool) $full->fresh()->is_export_cleared);
        $this->assertTrue((bool) $noCb->fresh()->is_export_cleared, '체크빌이 없어도 막지 않는다(jin)');
        $this->assertFalse((bool) $noDoc->fresh()->is_export_cleared, '신고서 없는 차는 제외');
        $this->assertSame('통관완료', $full->fresh()->progress_status_cache);
        $this->assertSame(2, AuditLog::where('action', 'bulk_export_cleared')->count());
        $this->assertSame(0, AuditLog::where('auditable_id', $done->id)->where('action', 'bulk_export_cleared')->count(), '이미 체크된 차는 그대로');
    }

    public function test_only_clearance_role_may_run_it(): void
    {
        $sales = User::factory()->create(['permission' => 'user', 'role' => '영업', 'email_verified_at' => now()]);
        $v = $this->vehicle('11가1001', ['export_declaration_document' => 'vehicles/1/d.pdf']);

        $this->expectException(AuthorizationException::class);
        $this->svc()->apply([$v->id], $sales, '테스트');
    }

    public function test_modal_shows_the_same_split_and_applies(): void
    {
        $user = $this->clearanceUser();
        $this->actingAs($user);
        $ok = $this->vehicle('11가1001', ['export_declaration_document' => 'vehicles/1/d.pdf']);
        $noDoc = $this->vehicle('33다3003');

        $c = Volt::test('erp.vehicles.index')
            ->set('shipDocIds', [(string) $ok->id, (string) $noDoc->id])
            ->call('openBulkClear');

        $html = $c->html();
        $this->assertStringContainsString('체크할 차량 1대', $html);
        $this->assertMatchesRegularExpression('/data-bulk-clear-no-checkbill[^>]*>\s*⚠ 체크빌이 없는 차량 1대/u', $html);
        $this->assertMatchesRegularExpression('/data-bulk-clear-no-doc[^>]*>\s*수출신고서가 없어 제외 1대: 33다3003/u', $html);

        $c->call('applyBulkClear');

        $this->assertTrue((bool) $ok->fresh()->is_export_cleared);
        $this->assertFalse((bool) $noDoc->fresh()->is_export_cleared);
        $this->assertFalse($c->get('bulkClearOpen'));
    }
}
