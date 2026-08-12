<?php

namespace Tests\Feature;

use App\Models\AdvanceReceipt;
use App\Models\CashSnapshot;
use App\Models\User;
use App\Services\CapitalStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 가수금 상환완료 (jin 2026-08-12) — 갚은 행을 **지우지 않고 남긴다**.
 *
 * 종전엔 반제 = 행 삭제였다. 숫자는 맞았지만 갚은 이력이 화면에서 사라져 누적 확인이 안 됐다.
 *
 * 🚨 **이 파일이 지키는 핵심 = 상환분이 집계에서 빠진다는 것.**
 *    안 빠지면 갚았는데도 청산가치에서 계속 차감되거나(liability) 투입원금이 부풀려진다(equity).
 *    화면만 초록으로 바뀌고 숫자는 틀린 상태 — 예외도 로그도 없어 아무도 모른다.
 */
class AdvanceReceiptRepaymentTest extends TestCase
{
    use RefreshDatabase;

    /** 예치·가수금 게이트 = canAccessDeposits(대표·업무관리자만). */
    private function owner(): User
    {
        return User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
    }

    private function receipt(int $amount, string $nature = AdvanceReceipt::NATURE_LIABILITY): AdvanceReceipt
    {
        return AdvanceReceipt::create([
            'received_date' => '2026-07-01',
            'company_name' => '테스트상사',
            'amount' => $amount,
            'nature' => $nature,
        ]);
    }

    /** 🚨 상환분은 청산가치 차감액에서 빠진다 — 이 기능의 존재 이유. */
    public function test_repaid_row_leaves_the_liability_total(): void
    {
        $paid = $this->receipt(3_000_000);
        $this->receipt(5_000_000);

        $this->assertSame(8_000_000, AdvanceReceipt::liabilityKrw());

        $paid->update(['repaid_at' => '2026-08-12']);

        $this->assertSame(5_000_000, AdvanceReceipt::liabilityKrw(), '갚았는데 부채로 계속 잡힌다');
        $this->assertSame(5_000_000, AdvanceReceipt::totalKrw());
        $this->assertSame(3_000_000, AdvanceReceipt::repaidKrw());
    }

    /** 대표 자산(equity)도 마찬가지 — 상환분이 남으면 투입원금이 부풀려진다. */
    public function test_repaid_row_leaves_the_equity_total(): void
    {
        $row = $this->receipt(4_000_000, AdvanceReceipt::NATURE_EQUITY);

        $this->assertSame(4_000_000, AdvanceReceipt::equityKrw());

        $row->update(['repaid_at' => '2026-08-12']);

        $this->assertSame(0, AdvanceReceipt::equityKrw(), '갚았는데 원금에 계속 잡힌다');
    }

    /** 🚨 자금현황 스냅샷도 미상환만 캡처한다 — 대시보드·자금보고서·알림톡이 전부 이 값을 읽는다. */
    public function test_snapshot_captures_outstanding_only(): void
    {
        $user = $this->owner();
        $paid = $this->receipt(3_000_000);
        $this->receipt(5_000_000);
        $paid->update(['repaid_at' => '2026-08-12']);

        $snap = app(CapitalStatusService::class)->capture(['krw' => 10_000_000], $user);

        $this->assertSame(5_000_000, (int) $snap->advance_krw, '스냅샷이 갚은 돈까지 부채로 캡처했다');
        $this->assertSame(5_000_000, (int) CashSnapshot::latest('snapshot_date')->first()->advance_krw);
    }

    /** 화면 버튼 — 누른 날로 찍히고 누가 눌렀는지 남는다. */
    public function test_button_stamps_today_and_who(): void
    {
        Carbon::setTestNow('2026-08-12 10:00');
        $user = $this->owner();
        $this->actingAs($user);
        $row = $this->receipt(3_000_000);

        Volt::test('erp.deposits.index')->call('markRepaid', $row->id);

        $row->refresh();
        $this->assertSame('2026-08-12', $row->repaid_at->format('Y-m-d'));
        $this->assertSame($user->id, $row->repaid_by);

        Carbon::setTestNow();
    }

    /** 🚫 「대표 자산」은 버튼 대상이 아니다 (jin 확정) — 회수는 종전대로 삭제로 정리. */
    public function test_equity_row_cannot_be_repaid_through_the_button(): void
    {
        $this->actingAs($this->owner());
        $row = $this->receipt(4_000_000, AdvanceReceipt::NATURE_EQUITY);

        Volt::test('erp.deposits.index')->call('markRepaid', $row->id)->assertStatus(422);

        $this->assertNull($row->fresh()->repaid_at);
    }

    /** 무르기 — 오클릭 되돌림. 없으면 잘못 누른 순간 복구할 방법이 없다. */
    public function test_undo_puts_it_back_as_outstanding(): void
    {
        $this->actingAs($this->owner());
        $row = $this->receipt(3_000_000);

        $c = Volt::test('erp.deposits.index');
        $c->call('markRepaid', $row->id);
        $this->assertSame(0, AdvanceReceipt::liabilityKrw());

        $c->call('undoRepaid', $row->id);

        $this->assertNull($row->fresh()->repaid_at);
        $this->assertNull($row->fresh()->repaid_by);
        $this->assertSame(3_000_000, AdvanceReceipt::liabilityKrw());
    }

    /** 이미 갚은 행은 성격을 못 바꾼다 — 바꾸면 「갚은돈」과 「대표 자산」 사이를 오가며 과거 숫자가 흔들린다. */
    public function test_repaid_row_nature_is_locked(): void
    {
        $this->actingAs($this->owner());
        $row = $this->receipt(3_000_000);
        $row->update(['repaid_at' => '2026-08-12']);

        Volt::test('erp.deposits.index')
            ->call('setNature', $row->id, AdvanceReceipt::NATURE_EQUITY)
            ->assertStatus(422);

        $this->assertSame(AdvanceReceipt::NATURE_LIABILITY, $row->fresh()->nature);
    }

    /**
     * 기본 목록은 **미상환만** — 갚은 행이 쌓이면 "지금 남은 게 얼마" 인지가 안 보인다.
     * 토글을 켜면 이력이 보이고, **그래도 상단 합계는 미상환 기준**이어야 한다
     * (회계 숫자가 화면 설정으로 흔들리면 안 된다).
     */
    public function test_list_hides_repaid_by_default_but_totals_never_move(): void
    {
        $this->actingAs($this->owner());
        $paid = $this->receipt(3_000_000);
        $open = $this->receipt(5_000_000);
        $paid->update(['repaid_at' => '2026-08-12']);

        $c = Volt::test('erp.deposits.index');
        $this->assertSame([$open->id], $c->get('rows')->pluck('id')->all(), '기본 목록에 갚은 행이 보인다');
        $this->assertSame(5_000_000, $c->get('total'));

        $c->call('toggleShowRepaid');
        $this->assertCount(2, $c->get('rows'), '토글을 켰는데 이력이 안 보인다');
        $this->assertSame(5_000_000, $c->get('total'), '토글이 합계를 바꿨다 — 회계 숫자가 화면 설정에 흔들린다');
    }

    /** 삭제는 남아 있다 — 이제 "잘못 입력한 행 지우기" 전용이다. */
    public function test_delete_still_works_for_mistaken_rows(): void
    {
        $this->actingAs($this->owner());
        $row = $this->receipt(3_000_000);

        Volt::test('erp.deposits.index')->call('remove', $row->id);

        $this->assertSoftDeleted('advance_receipts', ['id' => $row->id]);
        $this->assertSame(0, AdvanceReceipt::liabilityKrw());
    }
}
