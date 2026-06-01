<?php

namespace Tests\Feature;

use App\Models\PurchaseBalancePayment;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 2026-06-01 버그 — 매입 자동 PBP Draft phantom 중복.
 *
 * Vehicle::saved 훅은 매입가 저장 시 전액·confirmed_at=NULL 자동 잔금 Draft 를 만든다.
 * canConfirmFinance(관리/재무/admin) 사용자가 같은 저장에서 계약금(type=down) 등을
 * 확정 입력하면, 자동 Draft(전액, 대기)가 중복으로 남아 재무처리 대기에 잔존 → 이중 계상.
 *
 * 수정: 폼 동기화 직후 확정 입금 합과 대조 → 전액 커버 시 삭제, 일부면 남은액 축소(대기 유지).
 * 확정 입금 0인 순수 Draft 는 손대지 않음(대기 유지 — 사용자 결정 2026-06-01).
 */
class AutoPbpDraftReconcileTest extends TestCase
{
    use RefreshDatabase;

    private int $counter = 0;

    private function manager(): User
    {
        return User::factory()->create([
            'permission' => 'user',
            'role' => '관리',
            'email_verified_at' => now(),
        ]);
    }

    private function createVehicleViaPanel(User $actor, string $purchasePriceStr, string $downStr = ''): Vehicle
    {
        $number = 'PHTM-'.++$this->counter;
        $this->actingAs($actor);

        Volt::test('erp.vehicles.index')
            ->call('openCreate')
            ->set('vehicle_number', $number)
            ->set('purchase_price_str', $purchasePriceStr)
            ->set('down_payment_str', $downStr)
            ->call('save')
            ->assertHasNoErrors();

        return Vehicle::where('vehicle_number', $number)->firstOrFail();
    }

    public function test_phantom_auto_draft_removed_when_confirmed_payment_covers_payable(): void
    {
        // 33보8596 재현 — [관리]가 매입가 14,300,000 + 계약금 지급 14,300,000(전액) 확정 입력.
        $vehicle = $this->createVehicleViaPanel($this->manager(), '14,300,000', '14,300,000');

        $rows = $vehicle->purchaseBalancePayments()->get();

        // 자동 Draft(confirmed_at=NULL)는 phantom 으로 제거되어야 한다.
        $this->assertSame(
            0,
            $rows->whereNull('confirmed_at')->count(),
            '확정 입금이 매입합계를 전액 커버하면 자동 Draft 는 삭제되어야 한다'
        );
        // 확정 계약금 1건만 남는다.
        $this->assertSame(1, $rows->count());
        $this->assertSame(14_300_000, (int) $rows->first()->amount);
        $this->assertNotNull($rows->first()->confirmed_at);
        // 미지급 0 → 매입완료 도달 가능 (이중 계상으로 음수가 되지 않음).
        $this->assertSame(0, $vehicle->fresh()->purchase_unpaid_amount);
    }

    public function test_pure_auto_draft_kept_awaiting_when_no_confirmed_payment(): void
    {
        // 순수 Draft — [관리]가 매입가만 입력, 실제 지급 미기록. 현행대로 대기 유지(사용자 결정).
        $vehicle = $this->createVehicleViaPanel($this->manager(), '10,000,000', '');

        $rows = $vehicle->purchaseBalancePayments()->get();

        $this->assertSame(1, $rows->count());
        $draft = $rows->first();
        $this->assertNull($draft->confirmed_at, '순수 자동 Draft 는 대기 유지');
        $this->assertSame(10_000_000, (int) $draft->amount);
        $this->assertSame(PurchaseBalancePayment::AUTO_DRAFT_NOTE, $draft->note);
    }

    public function test_partial_confirmed_payment_shrinks_auto_draft_to_remaining(): void
    {
        // 일부만 확정(계약금 4,000,000) → 자동 Draft 는 남은 6,000,000 으로 축소(대기 유지).
        $vehicle = $this->createVehicleViaPanel($this->manager(), '10,000,000', '4,000,000');

        $rows = $vehicle->purchaseBalancePayments()->get();
        $draft = $rows->whereNull('confirmed_at')->first();
        $confirmed = $rows->whereNotNull('confirmed_at')->first();

        $this->assertNotNull($draft, '잔여 미지급이 있으면 자동 Draft 는 남는다');
        $this->assertSame(6_000_000, (int) $draft->amount, '자동 Draft 는 남은 미지급으로 축소');
        $this->assertSame(4_000_000, (int) $confirmed->amount);
        // 확정 4,000,000 + Draft 6,000,000 = 매입합계 10,000,000 (이중 계상 없음).
        $this->assertSame(6_000_000, $vehicle->fresh()->purchase_unpaid_amount);
    }
}
