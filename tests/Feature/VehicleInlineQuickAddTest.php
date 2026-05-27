<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Consignee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 작업2 (2026-05-27) — 차량 등록/수정 중 바이어·컨사이니 인라인 quick-add.
 * 패널 안 닫고 즉석 등록 → 자동 선택. 6개 드롭다운(판매/수출/선적 × 바이어/컨사이니) 단일 모달로 처리.
 */
class VehicleInlineQuickAddTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['permission' => 'admin']));
    }

    /** 바이어 quick-add → 3개 문맥 각각의 *_buyer_id_str 에 자동 선택 */
    public function test_quick_add_buyer_creates_and_auto_selects_per_context(): void
    {
        $cases = [
            'sale' => 'buyer_id_str',
            'export' => 'export_buyer_id_str',
            'bl' => 'bl_buyer_id_str',
        ];

        foreach ($cases as $context => $field) {
            $name = "QA BUYER {$context}";

            $component = Volt::test('erp.vehicles.index')
                ->call('openQuickAdd', 'buyer', $context)
                ->assertSet('quickAddOpen', true)
                ->assertSet('quickAddType', 'buyer')
                ->set('qaName', $name)
                ->call('saveQuickAdd')
                ->assertHasNoErrors()
                ->assertSet('quickAddOpen', false);

            $buyer = Buyer::where('name', $name)->first();
            $this->assertNotNull($buyer, "buyer for {$context} not created");
            $this->assertTrue((bool) $buyer->is_active);
            $component->assertSet($field, (string) $buyer->id);
        }
    }

    /** 컨사이니 quick-add → 문맥의 선택된 바이어를 buyer_id 로 종속 + *_consignee_id_str 자동 선택 */
    public function test_quick_add_consignee_inherits_context_buyer(): void
    {
        $buyer = Buyer::create(['name' => 'EXP BUYER', 'is_active' => true]);

        $component = Volt::test('erp.vehicles.index')
            ->set('export_buyer_id_str', (string) $buyer->id)
            ->call('openQuickAdd', 'consignee', 'export')
            ->assertSet('quickAddOpen', true)
            ->assertSet('quickAddBuyerName', 'EXP BUYER')
            ->set('qaName', 'EXP CONSIGNEE')
            ->call('saveQuickAdd')
            ->assertHasNoErrors()
            ->assertSet('quickAddOpen', false);

        $cons = Consignee::where('name', 'EXP CONSIGNEE')->first();
        $this->assertNotNull($cons);
        $this->assertEquals($buyer->id, $cons->buyer_id);
        $component->assertSet('export_consignee_id_str', (string) $cons->id);
    }

    /** 바이어 미선택 상태에서 컨사이니 quick-add 시도 → 모달 안 열림 (바이어 종속 보호) */
    public function test_quick_add_consignee_blocked_without_buyer(): void
    {
        Volt::test('erp.vehicles.index')
            ->call('openQuickAdd', 'consignee', 'sale')
            ->assertSet('quickAddOpen', false);

        $this->assertSame(0, Consignee::count());
    }

    /** 바이어 quick-add 는 종속 컨사이니 선택을 리셋 (신규 바이어엔 컨사이니 없음) */
    public function test_quick_add_buyer_resets_dependent_consignee(): void
    {
        $oldBuyer = Buyer::create(['name' => 'OLD BUYER', 'is_active' => true]);
        $oldCons = Consignee::create(['name' => 'OLD CONS', 'buyer_id' => $oldBuyer->id, 'is_active' => true]);

        $component = Volt::test('erp.vehicles.index')
            ->set('buyer_id_str', (string) $oldBuyer->id)
            ->set('consignee_id_str', (string) $oldCons->id)
            ->call('openQuickAdd', 'buyer', 'sale')
            ->set('qaName', 'NEW BUYER')
            ->call('saveQuickAdd')
            ->assertHasNoErrors();

        $newBuyer = Buyer::where('name', 'NEW BUYER')->first();
        $component
            ->assertSet('buyer_id_str', (string) $newBuyer->id)
            ->assertSet('consignee_id_str', '');
    }

    /** name 미입력 → 검증 실패, 모달 유지, 생성 안 됨 */
    public function test_quick_add_requires_name(): void
    {
        Volt::test('erp.vehicles.index')
            ->call('openQuickAdd', 'buyer', 'sale')
            ->set('qaName', '')
            ->call('saveQuickAdd')
            ->assertHasErrors(['qaName' => 'required'])
            ->assertSet('quickAddOpen', true);

        $this->assertSame(0, Buyer::count());
    }
}
