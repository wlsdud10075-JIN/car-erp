<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 미수 매입 게이트 (2026-07-08) — 바이어 미수율 > 50% 이면 신규 차량 등록 차단.
 *   ① 신규 등록 시 담당자·바이어 필수 (게이트 전제).
 *   ② 바이어 총미수율 초과 시 차단 + 관리 인라인 승인 우회((가) — 그 차 1건만, AuditLog).
 * 게이트 수치는 Buyer::computeReceivableGauge 단일 출처(목록·드로어 게이지와 동일).
 */
class PurchaseReceivableGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function manager(): User
    {
        return User::factory()->create(['permission' => 'user', 'role' => '관리', 'email_verified_at' => now()]);
    }

    private function salesman(): Salesman
    {
        return Salesman::create(['name' => '영업', 'is_active' => true, 'type' => 'freelance']);
    }

    /** 미수율 100% 바이어 (판매 1000만 KRW, 입금 0). */
    private function overThresholdBuyer(): Buyer
    {
        $buyer = Buyer::create(['name' => '미수많은바이어', 'is_active' => true]);
        $sm = Salesman::create(['name' => '기존영업', 'is_active' => true, 'type' => 'freelance']);
        $v = Vehicle::create([
            'vehicle_number' => 'OWED-1',
            'sales_channel' => 'heyman',
            'buyer_id' => $buyer->id,
            'salesman_id' => $sm->id,
            'currency' => 'KRW',
            'exchange_rate' => 1,
            'sale_price' => 10_000_000,
            'sale_date' => '2026-05-01',
        ]);
        $v->refreshCaches();

        return $buyer;
    }

    public function test_new_registration_requires_salesman_and_buyer(): void
    {
        $this->actingAs($this->manager());

        Volt::test('erp.vehicles.index')
            ->call('openCreate')
            ->set('vehicle_number', 'REQ-1')
            ->set('purchase_price_str', '5,000,000')
            ->call('save')
            ->assertHasErrors(['salesman_id_str', 'buyer_id_str']);

        $this->assertDatabaseMissing('vehicles', ['vehicle_number' => 'REQ-1']);
    }

    public function test_high_unpaid_buyer_blocks_new_registration(): void
    {
        $buyer = $this->overThresholdBuyer();
        $sm = $this->salesman();
        $this->actingAs($this->manager());

        Volt::test('erp.vehicles.index')
            ->call('openCreate')
            ->set('vehicle_number', 'BLOCK-1')
            ->set('salesman_id_str', (string) $sm->id)
            ->set('buyer_id_str', (string) $buyer->id)
            ->set('purchase_price_str', '5,000,000')
            ->call('save')
            ->assertSet('showPurchaseGate', true)
            ->assertHasNoErrors();

        // 차단 — 차량 미생성
        $this->assertDatabaseMissing('vehicles', ['vehicle_number' => 'BLOCK-1']);
    }

    public function test_manager_approval_registers_and_writes_audit(): void
    {
        $buyer = $this->overThresholdBuyer();
        $sm = $this->salesman();
        $this->actingAs($this->manager());

        Volt::test('erp.vehicles.index')
            ->call('openCreate')
            ->set('vehicle_number', 'APPR-1')
            ->set('salesman_id_str', (string) $sm->id)
            ->set('buyer_id_str', (string) $buyer->id)
            ->set('purchase_price_str', '5,000,000')
            ->call('save')
            ->assertSet('showPurchaseGate', true)
            ->set('purchaseGateReason', 'L/C 확인 완료. 5/20 잔금 입금 예정.')
            ->call('approvePurchaseGate')
            ->assertHasNoErrors()
            ->assertSet('showPurchaseGate', false);

        $v = Vehicle::where('vehicle_number', 'APPR-1')->first();
        $this->assertNotNull($v, '승인 후 차량 생성');
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Vehicle::class,
            'auditable_id' => $v->id,
            'action' => 'purchase_gate_override',
        ]);
    }

    public function test_low_unpaid_buyer_passes(): void
    {
        // 신규 바이어(판매 이력 없음) → 게이지 null → 게이트 미발동.
        $buyer = Buyer::create(['name' => '깨끗한바이어', 'is_active' => true]);
        $sm = $this->salesman();
        $this->actingAs($this->manager());

        Volt::test('erp.vehicles.index')
            ->call('openCreate')
            ->set('vehicle_number', 'CLEAN-1')
            ->set('salesman_id_str', (string) $sm->id)
            ->set('buyer_id_str', (string) $buyer->id)
            ->set('purchase_price_str', '5,000,000')
            ->call('save')
            ->assertSet('showPurchaseGate', false)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('vehicles', ['vehicle_number' => 'CLEAN-1']);
    }

    public function test_editing_and_switching_to_over_threshold_buyer_fires_gate(): void
    {
        // 기존 차량(깨끗한 바이어) 편집 중 미수 많은 바이어로 교체 → 게이트 발동.
        //   (차단은 early-return 이라 save 성공 재로드 경로를 안 타 — 순수 게이트 검증.)
        $overBuyer = $this->overThresholdBuyer();
        $cleanBuyer = Buyer::create(['name' => '원래바이어', 'is_active' => true]);
        $sm = $this->salesman();
        $car = Vehicle::create([
            'vehicle_number' => 'EDIT-1',
            'sales_channel' => 'heyman',
            'buyer_id' => $cleanBuyer->id,
            'salesman_id' => $sm->id,
        ]);
        $this->actingAs(User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]));

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $car->id)
            ->set('buyer_id_str', (string) $overBuyer->id)   // 미수 많은 바이어로 교체
            ->call('save')
            ->assertSet('showPurchaseGate', true);
    }
}
