<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\VehicleExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 🧮 「정산 처리」 화면 = 기능설정에서 지정한 사람만 (jin 2026-11-28 «볼 사람 지정 방식으로»).
 * 저장 전 = 종전(재무·관리·업무관리자 이상) · 저장 뒤 = 그중 체크한 사람 + 시스템관리자.
 * 사내직원관리·재무 처리는 이 설정과 무관하다(jin «사내직원관리는 현재가 맞아»).
 */
class SettlementScreenVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function user(string $permission, string $role = '관리'): User
    {
        return User::factory()->create(['permission' => $permission, 'role' => $role,
            'phone' => '0107777'.str_pad((string) ++$this->n, 4, '0', STR_PAD_LEFT), 'email_verified_at' => now()]);
    }

    private function save(array $ids): void
    {
        Setting::query()->updateOrCreate(['key' => 'settlement_viewers'], ['value' => json_encode($ids), 'type' => 'string']);
        Setting::flushRiskParamMemo();
    }

    public function test_before_saving_it_stays_as_today(): void
    {
        $this->actingAs($this->user('user', '관리'))->get('/erp/settlements')->assertOk();
        $this->actingAs($this->user('user', '재무'))->get('/erp/settlements')->assertOk();
        $this->actingAs($this->user('manager'))->get('/erp/settlements')->assertOk();
        $this->actingAs($this->user('user', '수출통관'))->get('/erp/settlements')->assertForbidden();
    }

    public function test_after_saving_only_checked_people_see_the_screen_menu_and_export(): void
    {
        $picked = $this->user('manager');
        $other = $this->user('user', '관리');
        $otherManager = $this->user('manager');
        $this->save([$picked->id]);

        $this->actingAs($picked)->get('/erp/settlements')->assertOk();
        $this->actingAs($picked)->get('/erp/dashboard')->assertSee(route('erp.settlements.index'), false);

        foreach ([$other, $otherManager] as $u) {
            $this->actingAs($u)->get('/erp/settlements')->assertForbidden();
            $this->actingAs($u)->get(route('erp.settlements.export'))->assertForbidden();
            $this->actingAs($u)->get('/erp/dashboard')->assertOk()->assertDontSee(route('erp.settlements.index'), false);
        }
        $this->actingAs($this->user('super'))->get('/erp/settlements')->assertOk();
    }

    public function test_other_money_screens_are_not_affected(): void
    {
        $other = $this->user('user', '관리');
        $this->save([]);

        $this->assertFalse($other->canSeeSettlementScreen());
        $this->assertTrue($other->canAccessSettlement(), '라우트 권한은 그대로 — 사내직원관리·재무 처리가 같이 쓴다');
        $this->actingAs($other)->get('/erp/salesmen')->assertOk();
        $this->actingAs($other)->get('/erp/transfers')->assertOk();
        $this->actingAs($other)->get('/erp/receivables')->assertOk();
    }

    public function test_vehicle_export_drops_settlement_columns_for_unchecked_people(): void
    {
        $picked = $this->user('manager');
        $other = $this->user('manager');
        $this->save([$picked->id]);
        $svc = app(VehicleExportService::class);

        $this->assertContains('total_margin', $svc->columnKeys($picked->canSeeSettlementScreen()));
        $this->assertNotContains('total_margin', $svc->columnKeys($other->canSeeSettlementScreen()));
        $this->actingAs($other)->get(route('erp.vehicles.export', ['cols' => 'vehicle_number,total_margin']))->assertOk();
        $this->assertStringContainsString('canSeeSettlementScreen', file_get_contents(app_path('Http/Controllers/VehicleExportController.php')));
        $this->assertStringNotContainsString('canAccessSettlement()', file_get_contents(resource_path('views/livewire/erp/vehicles/index.blade.php')));
    }

    public function test_a_person_outside_the_role_is_not_let_in_by_being_listed(): void
    {
        $clearance = $this->user('user', '수출통관');
        $this->save([$clearance->id]);

        $this->actingAs($clearance)->get('/erp/settlements')->assertForbidden();
    }

    public function test_feature_settings_column_saves_and_is_audited(): void
    {
        $super = $this->user('super');
        $mgr = $this->user('user', '관리');
        $fin = $this->user('user', '재무');
        $clr = $this->user('user', '수출통관');
        $this->actingAs($super);

        $c = Volt::test('admin.settings')->assertSee(__('feature_settings.settlement_col'));
        $checked = $c->get('payrollViewerMatrix')['settlement'];
        $this->assertContains((string) $mgr->id, $checked, '저장 전 = 정산 접근 권한자 전원 체크(종전과 같게)');
        $this->assertContains((string) $fin->id, $checked);
        $this->assertNotContains((string) $clr->id, $checked);
        $this->assertNull(Setting::settlementViewerIds());

        $c->set('payrollViewerMatrix.settlement', [(string) $fin->id, (string) $clr->id])->call('savePayrollViewers');
        $this->assertSame([$fin->id], Setting::settlementViewerIds(), '후보 밖(수출통관)은 저장되지 않는다');
        $this->assertDatabaseHas('audit_logs', ['action' => 'settlement_viewers_changed']);
        $this->assertFalse($mgr->fresh()->canSeeSettlementScreen());
        $this->assertTrue($fin->fresh()->canSeeSettlementScreen());
    }
}
