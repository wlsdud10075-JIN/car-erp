<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\SettlementPayoutBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 🧾 사내직원 월정산 = 기능설정에서 지정한 사람만 (jin 2026-11-05 «내수처럼 지정한 사람만 보게»).
 * 저장 전 = 종전(관리 이상) · 저장 뒤 = 체크한 사람(재무도 가능) + 지금 자기 차례인 결재자. 시스템관리자는 항상.
 */
class StaffPayoutVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function user(string $permission, string $role = '관리', ?string $title = null): User
    {
        return User::factory()->create(['permission' => $permission, 'role' => $role, 'approval_title' => $title,
            'phone' => '0106666'.str_pad((string) ++$this->n, 4, '0', STR_PAD_LEFT), 'email_verified_at' => now()]);
    }

    private function save(array $ids): void
    {
        Setting::query()->updateOrCreate(['key' => 'staff_payout_viewers'], ['value' => json_encode($ids), 'type' => 'string']);
        Setting::flushRiskParamMemo();
    }

    public function test_before_saving_it_stays_as_today(): void
    {
        $this->actingAs($this->user('user', '관리'))->get('/erp/payout-batches')->assertOk();
        $this->actingAs($this->user('user', '재무'))->get('/erp/payout-batches')->assertForbidden();
        $this->actingAs($this->user('user', '영업'))->get('/erp/payout-batches')->assertForbidden();
    }

    public function test_after_saving_only_checked_people_and_finance_can_be_added(): void
    {
        $picked = $this->user('user', '관리');
        $other = $this->user('user', '관리');
        $finance = $this->user('user', '재무');
        $this->save([$picked->id, $finance->id]);

        $this->actingAs($picked)->get('/erp/payout-batches')->assertOk();
        $this->actingAs($finance)->get('/erp/payout-batches')->assertOk();
        $this->actingAs($other)->get('/erp/payout-batches')->assertForbidden();
        $this->actingAs($other)->get('/erp/dashboard')->assertDontSee(route('erp.payout-batches.index'), false);
        $this->actingAs($this->user('super'))->get('/erp/payout-batches')->assertOk();
    }

    public function test_an_unchecked_approver_gets_in_on_their_turn(): void
    {
        $submitter = $this->user('manager');
        $ceo = $this->user('admin', '관리', '대표');
        $this->save([$submitter->id]);
        $batch = SettlementPayoutBatch::create(['month' => '2026-10', 'kind' => SettlementPayoutBatch::KIND_MONTH, 'status' => 'pending',
            'submitter_id' => $submitter->id, 'submitter_rank' => 2, 'total_payout' => 0, 'settlement_count' => 0, 'current_level' => 3]);

        $this->actingAs($ceo)->get('/erp/payout-batches')->assertOk();   // 대표 차례(종전 사다리 rank 3)
        $this->actingAs($ceo)->get(route('erp.payout-batches.export', $batch))->assertOk();

        $batch->forceFill(['status' => 'approved'])->save();
        $this->actingAs($ceo)->get('/erp/payout-batches')->assertForbidden();   // 차례가 끝나면 다시 지정자만
    }

    public function test_feature_settings_column_saves_and_is_audited(): void
    {
        $super = $this->user('super');
        $a = $this->user('user', '관리');
        $fin = $this->user('user', '재무');
        $this->actingAs($super);

        $c = Volt::test('admin.settings')->assertSee(__('feature_settings.staff_payout_col'));
        $this->assertContains((string) $a->id, $c->get('payrollViewerMatrix')['staff_payout'], '저장 전 = 관리 이상 체크(종전과 같게)');
        $this->assertNotContains((string) $fin->id, $c->get('payrollViewerMatrix')['staff_payout']);

        $c->set('payrollViewerMatrix.staff_payout', [(string) $fin->id])->call('savePayrollViewers');
        $this->assertSame([$fin->id], Setting::staffPayoutViewerIds());
        $this->assertDatabaseHas('audit_logs', ['action' => 'staff_payout_viewers_changed']);
    }
}
