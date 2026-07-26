<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Consignee;
use App\Models\Salesman;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 컨사이니 독립목록(/erp/consignees) 바이어 스코프 IDOR 잠금 (SKILLS §8 #26 패턴).
 * 영업 = 본인 바이어 컨사이니만 목록·열람·변경. 남 바이어 컨사이니 openEdit/delete/save = 403.
 * + buyer_id 필수 검증(미배정 컨사이니 생성 차단).
 */
class ConsigneeIdorScopeTest extends TestCase
{
    use RefreshDatabase;

    /** 영업 본인 바이어/컨사이니 vs 남 바이어/컨사이니 (auth 없이 생성 → 자동 컨사이니 없음). */
    private function makeSalesScope(): array
    {
        $salesUser = User::factory()->create(['permission' => 'user', 'role' => '영업']);
        $salesman = Salesman::create(['name' => '본인영업', 'user_id' => $salesUser->id, 'is_active' => true, 'type' => 'employee']);
        $otherSalesman = Salesman::create(['name' => '남영업', 'is_active' => true, 'type' => 'employee']);

        $myBuyer = Buyer::create(['name' => 'MY BUYER', 'salesman_id' => $salesman->id, 'is_active' => true]);
        $otherBuyer = Buyer::create(['name' => 'OTHER BUYER', 'salesman_id' => $otherSalesman->id, 'is_active' => true]);

        $myCons = Consignee::create(['name' => 'MY CONS', 'buyer_id' => $myBuyer->id, 'is_active' => true]);
        $otherCons = Consignee::create(['name' => 'OTHER CONS', 'buyer_id' => $otherBuyer->id, 'is_active' => true]);

        return compact('salesUser', 'myBuyer', 'otherBuyer', 'myCons', 'otherCons');
    }

    public function test_sales_list_scoped_to_own_buyers(): void
    {
        $s = $this->makeSalesScope();
        $this->actingAs($s['salesUser']);

        $ids = Volt::test('erp.consignees.index')->instance()->consignees->pluck('id');

        $this->assertTrue($ids->contains($s['myCons']->id), '본인 바이어 컨사이니는 보임');
        $this->assertFalse($ids->contains($s['otherCons']->id), '남 바이어 컨사이니는 안 보임');
    }

    public function test_sales_buyers_dropdown_scoped(): void
    {
        $s = $this->makeSalesScope();
        $this->actingAs($s['salesUser']);

        $buyers = Volt::test('erp.consignees.index')->instance()->buyers;

        $this->assertTrue($buyers->contains('id', $s['myBuyer']->id));
        $this->assertFalse($buyers->contains('id', $s['otherBuyer']->id), '남 바이어는 드롭다운에도 없음');
    }

    public function test_admin_sees_all_consignees(): void
    {
        $s = $this->makeSalesScope();
        $this->actingAs(User::factory()->create(['permission' => 'admin']));

        $ids = Volt::test('erp.consignees.index')->instance()->consignees->pluck('id');

        $this->assertTrue($ids->contains($s['myCons']->id));
        $this->assertTrue($ids->contains($s['otherCons']->id), 'admin 은 전체');
    }

    public function test_sales_open_edit_out_of_scope_forbidden(): void
    {
        $s = $this->makeSalesScope();
        $this->actingAs($s['salesUser']);

        Volt::test('erp.consignees.index')
            ->call('openEdit', $s['otherCons']->id)
            ->assertStatus(403);
    }

    public function test_sales_delete_out_of_scope_forbidden(): void
    {
        $s = $this->makeSalesScope();
        $this->actingAs($s['salesUser']);

        Volt::test('erp.consignees.index')
            ->call('delete', $s['otherCons']->id)
            ->assertStatus(403);

        $this->assertDatabaseHas('consignees', ['id' => $s['otherCons']->id]);   // 삭제 안 됨
    }

    public function test_sales_save_out_of_scope_buyer_forbidden(): void
    {
        $s = $this->makeSalesScope();
        $this->actingAs($s['salesUser']);

        Volt::test('erp.consignees.index')
            ->set('name', 'HACK CONS')
            ->set('buyer_id_str', (string) $s['otherBuyer']->id)
            ->call('save')
            ->assertStatus(403);

        $this->assertDatabaseMissing('consignees', ['name' => 'HACK CONS']);
    }

    public function test_buyer_required_on_save(): void
    {
        $s = $this->makeSalesScope();
        $this->actingAs($s['salesUser']);

        Volt::test('erp.consignees.index')
            ->set('name', 'NO BUYER CONS')
            ->set('buyer_id_str', '')
            ->call('save')
            ->assertHasErrors('buyer_id_str');

        $this->assertDatabaseMissing('consignees', ['name' => 'NO BUYER CONS']);
    }

    public function test_sales_can_edit_own_consignee(): void
    {
        $s = $this->makeSalesScope();
        $this->actingAs($s['salesUser']);

        Volt::test('erp.consignees.index')
            ->call('openEdit', $s['myCons']->id)
            ->set('contact_phone', '999')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('consignees', ['id' => $s['myCons']->id, 'contact_phone' => '999']);
    }
}
