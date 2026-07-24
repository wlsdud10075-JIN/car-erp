<?php

namespace Tests\Feature;

use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 정산 락 개편 (jin 2026-07-24) — 잠금 해제(회계 재조정) UI를 정산 화면으로 이동.
 * 잠금 원인이 2차 정산 마감(closed)이므로, 잠금 해제도 정산 화면에서 시작한다:
 *   closed 정산 행 [🔓 회계 재조정] → 사유 입력 → 차량 잠금 토큰 발급 → 차량 편집 패널 딥링크.
 */
class SettlementReadjustTest extends TestCase
{
    use RefreshDatabase;

    private function closedSettlementVehicle(): array
    {
        $v = Vehicle::create([
            'vehicle_number' => 'RDJ-1', 'sales_channel' => 'export',
            'currency' => 'KRW', 'exchange_rate' => 1, 'purchase_price' => 1_000_000,
        ]);
        // auth 없는 시점 생성 → paid 전환 승인 가드 자동 우회.
        $settlement = Settlement::create([
            'vehicle_id' => $v->id, 'settlement_type' => 'ratio', 'settlement_ratio' => 50,
            'settlement_status' => 'paid', 'paid_at' => now(),
            'secondary_status' => 'closed', 'secondary_closed_at' => now(),
        ]);

        return [$v, $settlement];
    }

    public function test_readjust_unlocks_ledger_and_redirects_to_vehicle(): void
    {
        [$v, $settlement] = $this->closedSettlementVehicle();
        $admin = User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
        $this->actingAs($admin);

        Volt::test('erp.settlements.index')
            ->call('openReadjustModal', $settlement->id)
            ->assertSet('showReadjustModal', true)
            ->set('readjustReason', '탁송비 실측 정정 필요 (위카 명세서 6월)')
            ->call('submitReadjust')
            ->assertRedirect(route('erp.vehicles.index', ['openVehicle' => $v->id]));

        // 차량 잠금 토큰 발급됨 → 딥링크로 이동한 차량 편집 패널에서 회계필드 1회 수정 가능.
        $this->assertTrue(Cache::has(Vehicle::ledgerUnlockCacheKey($v->id)));
    }

    public function test_readjust_requires_reason_min_length(): void
    {
        [, $settlement] = $this->closedSettlementVehicle();
        $admin = User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
        $this->actingAs($admin);

        Volt::test('erp.settlements.index')
            ->call('openReadjustModal', $settlement->id)
            ->set('readjustReason', '짧음')
            ->call('submitReadjust')
            ->assertHasErrors('readjustReason');
    }

    public function test_readjust_denied_for_sales_role(): void
    {
        [, $settlement] = $this->closedSettlementVehicle();
        $sales = User::factory()->create(['permission' => 'user', 'role' => '영업', 'email_verified_at' => now()]);
        $this->actingAs($sales);

        // canUnlockLedger 실패 → 모달 안 열림 (친절 차단).
        Volt::test('erp.settlements.index')
            ->call('openReadjustModal', $settlement->id)
            ->assertSet('showReadjustModal', false);
    }
}
