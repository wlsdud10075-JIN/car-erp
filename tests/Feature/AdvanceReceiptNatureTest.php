<?php

namespace Tests\Feature;

use App\Models\AdvanceReceipt;
use App\Models\CashSnapshot;
use App\Models\User;
use App\Services\CapitalStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 가수금 성격 구분 (jin 2026-07-31).
 *
 * 청산가치는 가수금을 전액 부채로 차감해 왔는데 실제로는 성격이 섞여 있다.
 *   · 김진숙차입 → 갚아야 할 돈 (liability) → 차감
 *   · 대표이사 가수금 · 싼카대여 → 대표 본인 돈 (equity) → 차감 안 함
 *
 * 🚨 기본값이 liability 인 게 핵심이다 — 분류하기 전에는 현행 계산과 동일해야
 *    배포하는 순간 청산가치가 흔들리지 않는다.
 */
class AdvanceReceiptNatureTest extends TestCase
{
    use RefreshDatabase;

    private function finance(): User
    {
        // canEnterCashBalance = 재무·관리·업무관리자·대표
        return User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
    }

    private function receipt(int $amount, ?string $nature = null): AdvanceReceipt
    {
        return AdvanceReceipt::create(array_filter([
            'received_date' => '2026-07-01',
            'company_name' => '테스트상사',
            'amount' => $amount,
            'nature' => $nature,
        ]));
    }

    public function test_nature_defaults_to_liability_so_existing_behaviour_is_unchanged(): void
    {
        $r = $this->receipt(1_000_000);

        $this->assertSame(AdvanceReceipt::NATURE_LIABILITY, $r->fresh()->nature,
            '기본값이 liability 가 아니면 배포 즉시 청산가치가 바뀝니다.');
    }

    public function test_totals_split_by_nature(): void
    {
        $this->receipt(1_000_000, AdvanceReceipt::NATURE_LIABILITY);
        $this->receipt(3_000_000, AdvanceReceipt::NATURE_EQUITY);

        $this->assertSame(4_000_000, AdvanceReceipt::totalKrw(), '전체 합은 성격과 무관해야 합니다.');
        $this->assertSame(1_000_000, AdvanceReceipt::liabilityKrw());
        $this->assertSame(3_000_000, AdvanceReceipt::equityKrw());
    }

    public function test_snapshot_only_deducts_liability(): void
    {
        $this->receipt(1_000_000, AdvanceReceipt::NATURE_LIABILITY);
        $this->receipt(3_000_000, AdvanceReceipt::NATURE_EQUITY);

        $snap = app(CapitalStatusService::class)->capture(['krw' => 0], null, '2026-07-31');

        $this->assertSame(1_000_000, (int) $snap->advance_krw,
            '대표 자산성(equity)은 갚을 의무가 없으므로 청산가치에서 빼면 안 됩니다.');
    }

    public function test_changing_nature_moves_the_amount_but_not_past_snapshots(): void
    {
        $r = $this->receipt(5_000_000, AdvanceReceipt::NATURE_LIABILITY);
        $before = app(CapitalStatusService::class)->capture(['krw' => 0], null, '2026-07-30');
        $this->assertSame(5_000_000, (int) $before->advance_krw);

        $r->update(['nature' => AdvanceReceipt::NATURE_EQUITY]);

        // 과거 스냅샷은 그 시점 값을 박아둔 것이라 소급되지 않는다.
        $this->assertSame(5_000_000, (int) CashSnapshot::find($before->id)->advance_krw,
            '이미 찍힌 스냅샷이 나중 분류로 덮이면 추적이 깨집니다.');

        // 다음 스냅샷부터 반영.
        $after = app(CapitalStatusService::class)->capture(['krw' => 0], null, '2026-07-31');
        $this->assertSame(0, (int) $after->advance_krw);
    }

    public function test_screen_shows_nature_and_no_longer_shows_person(): void
    {
        $this->receipt(1_000_000, AdvanceReceipt::NATURE_LIABILITY);

        Volt::actingAs($this->finance())->test('erp.deposits.index')
            ->assertSee('성격')
            ->assertDontSee('담당자');
    }

    public function test_nature_can_be_changed_from_the_list(): void
    {
        $r = $this->receipt(2_000_000);

        Volt::actingAs($this->finance())->test('erp.deposits.index')
            ->call('setNature', $r->id, AdvanceReceipt::NATURE_EQUITY);

        $this->assertSame(AdvanceReceipt::NATURE_EQUITY, $r->fresh()->nature);
    }

    public function test_unknown_nature_is_rejected(): void
    {
        $r = $this->receipt(2_000_000);

        Volt::actingAs($this->finance())->test('erp.deposits.index')
            ->call('setNature', $r->id, 'whatever')
            ->assertStatus(422);

        $this->assertSame(AdvanceReceipt::NATURE_LIABILITY, $r->fresh()->nature);
    }

    public function test_users_without_cash_permission_cannot_change_nature(): void
    {
        $r = $this->receipt(2_000_000);
        $sales = User::factory()->create(['permission' => 'user', 'role' => '영업', 'email_verified_at' => now()]);

        Volt::actingAs($sales)->test('erp.deposits.index')->assertStatus(403);
        $this->assertSame(AdvanceReceipt::NATURE_LIABILITY, $r->fresh()->nature);
    }
}
