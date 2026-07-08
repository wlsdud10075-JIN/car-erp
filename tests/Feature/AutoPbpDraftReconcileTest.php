<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 자동 PBP Draft 제거 회귀 (jin 2026-07-03) — 단순 저장은 재무처리로 자동 유입 안 됨.
 *
 * 구 동작: Vehicle::saved 훅이 매입가 저장 시 전액·confirmed_at=NULL 자동 Draft 를 만들어
 *   재무처리 대기 큐에 뜨게 했다. → 폐기. 단순 저장(매입가만)은 이제 PBP 를 만들지 않는다.
 * 매입 미지급은 accessor(확정 PBP 기준)라 대시보드/요약박스에 그대로 노출되고,
 * 재무는 실제 지급 시 매입 잔금 탭에서 직접 확정한다. 확정 입금(계약금 등)은 종전대로 저장.
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
        // ① 신규 등록 필수 — 담당자·바이어. 신규 바이어라 미수 게이트 미발동.
        $sm = Salesman::create(['name' => '영업', 'is_active' => true, 'type' => 'freelance']);
        $buyer = Buyer::create(['name' => 'PHTM BUYER-'.$this->counter, 'is_active' => true]);
        $this->actingAs($actor);

        Volt::test('erp.vehicles.index')
            ->call('openCreate')
            ->set('vehicle_number', $number)
            ->set('salesman_id_str', (string) $sm->id)
            ->set('buyer_id_str', (string) $buyer->id)
            ->set('purchase_price_str', $purchasePriceStr)
            ->set('down_payment_str', $downStr)
            ->call('save')
            ->assertHasNoErrors();

        return Vehicle::where('vehicle_number', $number)->firstOrFail();
    }

    public function test_confirmed_payment_only_no_auto_draft(): void
    {
        // [관리]가 매입가 14,300,000 + 계약금 14,300,000(전액) 확정 입력 → 확정 1건만, 자동 Draft 없음.
        $vehicle = $this->createVehicleViaPanel($this->manager(), '14,300,000', '14,300,000');

        $rows = $vehicle->purchaseBalancePayments()->get();

        $this->assertSame(0, $rows->whereNull('confirmed_at')->count(), '자동 Draft 는 생성되지 않는다');
        $this->assertSame(1, $rows->count());
        $this->assertSame(14_300_000, (int) $rows->first()->amount);
        $this->assertNotNull($rows->first()->confirmed_at);
        $this->assertSame(0, $vehicle->fresh()->purchase_unpaid_amount);
    }

    public function test_simple_save_creates_no_pbp_and_shows_unpaid_via_accessor(): void
    {
        // 핵심 회귀 — [관리]가 매입가만 입력(지급 미기록) → PBP 0건(재무처리 큐 유입 없음).
        // 미지급은 accessor 로 전액 노출 → 대시보드 매입 미지급/요약박스에 그대로 뜸.
        $vehicle = $this->createVehicleViaPanel($this->manager(), '10,000,000', '');

        $this->assertSame(0, $vehicle->purchaseBalancePayments()->count(), '단순 저장은 PBP 를 만들지 않는다');
        $this->assertSame(10_000_000, $vehicle->fresh()->purchase_unpaid_amount);
    }

    public function test_partial_confirmed_payment_leaves_only_confirmed_row(): void
    {
        // 일부만 확정(계약금 4,000,000) → 확정 1건만. 남은 6,000,000 은 accessor 미지급으로만(대기 Draft 없음).
        $vehicle = $this->createVehicleViaPanel($this->manager(), '10,000,000', '4,000,000');

        $rows = $vehicle->purchaseBalancePayments()->get();

        $this->assertSame(0, $rows->whereNull('confirmed_at')->count(), '대기 자동 Draft 없음');
        $this->assertSame(1, $rows->count());
        $confirmed = $rows->whereNotNull('confirmed_at')->first();
        $this->assertSame(4_000_000, (int) $confirmed->amount);
        $this->assertSame(6_000_000, $vehicle->fresh()->purchase_unpaid_amount);
    }
}
