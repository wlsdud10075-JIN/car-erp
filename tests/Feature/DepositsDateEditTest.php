<?php

namespace Tests\Feature;

use App\Models\AdvanceReceipt;
use App\Models\AuctionDeposit;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 📅 예치·가수금 날짜 수정 (jin 2026-11-03 «날짜 기입이 잘못된 게 많아서»).
 *
 * 날짜는 정렬에만 쓰여 합계는 안 움직이지만, 누가 언제 바꿨는지는 감사로그에 남는다.
 * 8자리(20260717)는 서버에서 정규화한다 — 안 하면 date 캐스트가 1970 으로 읽는다(SKILLS §14).
 */
class DepositsDateEditTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): User
    {
        return User::factory()->create(['permission' => 'manager', 'role' => '관리', 'email_verified_at' => now()]);
    }

    private function advance(): AdvanceReceipt
    {
        return AdvanceReceipt::create([
            'received_date' => '2026-07-01', 'company_name' => 'OO상사',
            'amount' => 5_000_000, 'nature' => AdvanceReceipt::NATURE_LIABILITY,
        ]);
    }

    private function dateLogs($row, string $col)
    {
        return AuditLog::where('auditable_type', $row::class)->where('auditable_id', $row->id)
            ->where('column_name', $col)->get();
    }

    public function test_manager_changes_advance_date_and_it_is_audited(): void
    {
        $this->actingAs($this->manager());
        $row = $this->advance();
        $total = AdvanceReceipt::totalKrw();

        Volt::test('erp.deposits.index')
            ->call('setDate', $row->id, '2026-06-15')
            ->assertHasNoErrors();

        $this->assertSame('2026-06-15', $row->fresh()->received_date->toDateString());
        $this->assertSame($total, AdvanceReceipt::totalKrw(), '날짜를 고쳐도 합계는 그대로여야 한다');

        $log = $this->dateLogs($row, 'received_date')->sole();
        $this->assertSame('2026-07-01', $log->old_value);
        $this->assertSame('2026-06-15', $log->new_value);
    }

    public function test_auction_tab_edits_deposited_date(): void
    {
        $this->actingAs($this->manager());
        $row = AuctionDeposit::create(['deposited_date' => '2026-07-01', 'auction_house' => '현대글로비스', 'amount' => 3_000_000]);

        Volt::test('erp.deposits.index')
            ->call('setTab', 'auction')
            ->call('setDate', $row->id, '2026-05-20')
            ->assertHasNoErrors();

        $this->assertSame('2026-05-20', $row->fresh()->deposited_date->toDateString());
        $this->assertCount(1, $this->dateLogs($row, 'deposited_date'));
    }

    public function test_eight_digit_input_is_normalized(): void
    {
        $this->actingAs($this->manager());
        $row = $this->advance();

        Volt::test('erp.deposits.index')->call('setDate', $row->id, '20260717')->assertHasNoErrors();

        $this->assertSame('2026-07-17', $row->fresh()->received_date->toDateString());
    }

    public function test_invalid_dates_are_rejected(): void
    {
        $this->actingAs($this->manager());
        $row = $this->advance();

        foreach (['abc', '2026-13-45', '2026-02-30', ''] as $bad) {
            Volt::test('erp.deposits.index')
                ->call('setDate', $row->id, $bad)
                ->assertHasErrors("rowDate.{$row->id}");
        }

        $this->assertSame('2026-07-01', $row->fresh()->received_date->toDateString());
        $this->assertCount(0, $this->dateLogs($row, 'received_date'));
    }

    public function test_same_date_writes_no_audit_row(): void
    {
        $this->actingAs($this->manager());
        $row = $this->advance();

        Volt::test('erp.deposits.index')->call('setDate', $row->id, '20260701')->assertHasNoErrors();

        $this->assertCount(0, $this->dateLogs($row, 'received_date'));
    }

    /** 상환된 행도 고칠 수 있다 — 날짜는 집계에 안 들어가 성격 잠금과 이유가 다르다. */
    public function test_repaid_row_date_is_editable(): void
    {
        $this->actingAs($this->manager());
        $row = $this->advance();
        $row->update(['repaid_at' => '2026-08-01']);

        Volt::test('erp.deposits.index')->call('setDate', $row->id, '2026-06-30')->assertHasNoErrors();

        $this->assertSame('2026-06-30', $row->fresh()->received_date->toDateString());
    }

    public function test_finance_role_is_blocked(): void
    {
        $this->actingAs(User::factory()->create(['permission' => 'user', 'role' => '재무', 'email_verified_at' => now()]));
        $row = $this->advance();

        Volt::test('erp.deposits.index')->assertForbidden();

        $this->assertSame('2026-07-01', $row->fresh()->received_date->toDateString());
    }
}
