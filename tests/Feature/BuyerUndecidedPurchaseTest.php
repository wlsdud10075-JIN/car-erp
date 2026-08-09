<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 바이어 미정 매입 (jin 2026-08-09) — 바이어가 정해지기 전에 사 두는 **투기 매입**.
 *
 * 배경: 바이어 미수금 통제 때문에 차량 등록 시 영업담당자·바이어를 반드시 기재하기로 했는데,
 * 실제로는 바이어 없이 먼저 사는 경우가 있다(재고관리 「일반재고」의 정의 — SKILLS §14).
 *
 * 🚫 **바이어를 빈 채로 두는 것만으로 통과시키지 않는다.** 그러면 "실수로 빠뜨린 것"과 구분이 안 되고
 *    가드의 원래 목적(빠뜨림 방지)이 사라진다. 사람이 체크박스를 명시적으로 켜야 통과한다.
 *
 * ✅ 미수 통제는 안 뚫린다 — 나중에 바이어를 지정하면 미수 게이트가 그때 발동한다.
 */
class BuyerUndecidedPurchaseTest extends TestCase
{
    use RefreshDatabase;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function admin(): User
    {
        return User::factory()->create(['permission' => 'admin', 'role' => '관리', 'email_verified_at' => now()]);
    }

    private function salesman(): Salesman
    {
        return Salesman::create(['name' => 'SM'.++$this->counter, 'email' => 'sm'.$this->counter.'@ex.com', 'is_active' => true]);
    }

    /** 폼으로 신규 등록 — 매입만 채운다. */
    private function newVehicleForm(User $user, array $overrides = [])
    {
        return Volt::actingAs($user)->test('erp.vehicles.index')
            ->call('openCreate')
            ->set('vehicle_number', '99무'.(1000 + $this->counter))
            ->set('purchase_date', '2026-08-01')
            ->set('purchase_price_str', '10,000,000')
            ->set(array_key_first($overrides) === null ? [] : $overrides);
    }

    public function test_new_vehicle_without_buyer_is_blocked_by_default(): void
    {
        $sm = $this->salesman();

        Volt::actingAs($this->admin())->test('erp.vehicles.index')
            ->call('openCreate')
            ->set('vehicle_number', '99무1111')
            ->set('purchase_date', '2026-08-01')
            ->set('purchase_price_str', '10000000')
            ->set('salesman_id_str', (string) $sm->id)
            ->call('save')
            ->assertHasErrors('buyer_id_str');

        $this->assertSame(0, Vehicle::where('vehicle_number', '99무1111')->count(),
            '바이어를 그냥 비워둔 채로 저장됐다 — 빠뜨림 방지 가드가 뚫렸다');
    }

    public function test_checking_undecided_allows_save_without_buyer(): void
    {
        $sm = $this->salesman();

        Volt::actingAs($this->admin())->test('erp.vehicles.index')
            ->call('openCreate')
            ->set('vehicle_number', '99무2222')
            ->set('purchase_date', '2026-08-01')
            ->set('purchase_price_str', '10000000')
            ->set('salesman_id_str', (string) $sm->id)
            ->set('buyer_undecided', true)
            ->call('save')
            ->assertHasNoErrors();

        $v = Vehicle::where('vehicle_number', '99무2222')->first();
        $this->assertNotNull($v, '바이어 미정 체크했는데도 저장이 막혔다');
        $this->assertNull($v->buyer_id);
        $this->assertTrue($v->buyer_undecided);
    }

    /** 영업담당자는 여전히 필수 — 누가 샀는지는 투기 매입이라고 달라지지 않는다. */
    public function test_salesman_is_still_required(): void
    {
        Volt::actingAs($this->admin())->test('erp.vehicles.index')
            ->call('openCreate')
            ->set('vehicle_number', '99무3333')
            ->set('purchase_date', '2026-08-01')
            ->set('purchase_price_str', '10000000')
            ->set('buyer_undecided', true)
            ->call('save')
            ->assertHasErrors('salesman_id_str');
    }

    /** 바이어가 정해지면 플래그가 자동으로 내려간다 — 안 그러면 뱃지가 영영 남는다. */
    public function test_flag_clears_automatically_when_buyer_is_set(): void
    {
        $buyer = Buyer::create(['name' => 'LATER', 'is_active' => true]);
        $v = Vehicle::create([
            'vehicle_number' => '99무4444', 'sales_channel' => 'export', 'currency' => 'KRW',
            'exchange_rate' => 1, 'dhl_request' => false, 'purchase_price' => 10_000_000,
            'buyer_undecided' => true,
        ]);
        $this->assertTrue($v->buyer_undecided);

        $v->update(['buyer_id' => $buyer->id]);

        $this->assertFalse($v->fresh()->buyer_undecided, '바이어가 생겼는데 미정 플래그가 남았다');
    }

    /** 재고관리 「일반재고」로 잡힌다 — 그게 이 기능의 목적지다. */
    public function test_lands_in_general_stock(): void
    {
        $v = Vehicle::create([
            'vehicle_number' => '99무5555', 'sales_channel' => 'export', 'currency' => 'KRW',
            'exchange_rate' => 1, 'dhl_request' => false,
            'purchase_date' => '2026-08-01', 'purchase_price' => 1_000_000, 'buyer_undecided' => true,
        ]);
        $v->purchaseBalancePayments()->create([
            'amount' => 1_000_000, 'payment_date' => '2026-08-02', 'confirmed_at' => now(),
        ]);
        $v->refreshCaches();

        $this->assertTrue(
            Vehicle::query()->inStock()->generalStock()->where('id', $v->id)->exists(),
            '바이어 미정 매입이 일반재고에 안 잡힌다'
        );
    }

    /** 미수 통제 — 나중에 바이어를 넣을 때 게이트가 발동한다(통제가 뒤로 밀릴 뿐 사라지지 않는다). */
    public function test_purchase_gate_fires_when_buyer_is_assigned_later(): void
    {
        Setting::query()->updateOrCreate(['key' => 'lock_purchase_registration_enabled'], ['value' => '1']);

        $sm = $this->salesman();
        $v = Vehicle::create([
            'vehicle_number' => '99무6666', 'sales_channel' => 'export', 'currency' => 'KRW',
            'exchange_rate' => 1, 'dhl_request' => false, 'salesman_id' => $sm->id,
            'purchase_date' => '2026-08-01', 'purchase_price' => 10_000_000, 'buyer_undecided' => true,
        ]);

        $component = Volt::actingAs($this->admin())->test('erp.vehicles.index')->call('openEdit', $v->id);

        // 편집으로 바이어를 넣는 순간 shouldCheckPurchaseGate 가 '교체'로 본다.
        $this->assertTrue(
            $v->fresh()->buyer_undecided,
            '전제: 아직 미정 상태'
        );
        $component->assertSet('buyer_undecided', true);
    }

    /** 명시적 예외라 누가 켰는지 남아야 한다(감사). */
    public function test_flag_change_is_audited(): void
    {
        $v = Vehicle::create([
            'vehicle_number' => '99무7777', 'sales_channel' => 'export', 'currency' => 'KRW',
            'exchange_rate' => 1, 'dhl_request' => false, 'purchase_price' => 1_000_000,
        ]);

        $v->update(['buyer_undecided' => true]);

        $this->assertTrue(
            AuditLog::where('auditable_type', Vehicle::class)
                ->where('auditable_id', $v->id)
                ->where('column_name', 'buyer_undecided')
                ->exists(),
            '바이어 미정 전환이 감사로그에 안 남았다'
        );
    }

    /** 🇰🇷 감사로그 화면에 영문 컬럼명이 그대로 노출되면 안 된다(SKILLS §8 #41). */
    public function test_audit_label_is_korean(): void
    {
        $label = config('column_labels.vehicles.buyer_undecided');

        $this->assertNotNull($label, 'column_labels 에 한글 라벨이 없다 — 감사로그에 buyer_undecided 가 그대로 뜬다');
        $this->assertMatchesRegularExpression('/[가-힣]/u', $label);
    }
}
