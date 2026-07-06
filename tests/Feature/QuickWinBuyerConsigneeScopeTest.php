<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Consignee;
use App\Models\Country;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * jin 2026-07-06 quick win ⑥⑦ 회귀 잠금.
 *  ⑥ 바이어 등록 시 컨사이니 1번 자동생성 (UI/auth 컨텍스트만, 임포트·시더 제외).
 *  ⑦ 차량편집 바이어 드롭다운 [관리]/영업 팀 스코프 + 현재 선택 바이어 항상 포함.
 */
class QuickWinBuyerConsigneeScopeTest extends TestCase
{
    use RefreshDatabase;

    // ── ⑥ 자동 컨사이니 ────────────────────────────────────────────

    public function test_auth_buyer_create_auto_creates_matching_consignee(): void
    {
        $this->actingAs(User::factory()->create(['permission' => 'admin']));
        $country = Country::create(['name' => '알바니아', 'code' => 'ALB', 'currency' => 'ALL']);

        $buyer = Buyer::create([
            'name' => 'AUTOCONS BUYER',
            'country_id' => $country->id,
            'contact_email' => 'b@x.com',
            'contact_phone' => '123',
            'address' => 'ADDR 1',
            'is_active' => true,
        ]);

        $cons = Consignee::where('buyer_id', $buyer->id)->get();
        $this->assertCount(1, $cons, '바이어 등록 시 컨사이니 1건 자동 생성');
        $this->assertSame('AUTOCONS BUYER', $cons->first()->name);
        $this->assertSame($country->id, $cons->first()->country_id);
        $this->assertSame('b@x.com', $cons->first()->contact_email);
        $this->assertSame('ADDR 1', $cons->first()->address);
    }

    public function test_unauthenticated_buyer_create_skips_auto_consignee(): void
    {
        // 임포트·시더(artisan, auth 없음) 경로 — 자동생성 안 함 (일괄업로드 중복 방지).
        $buyer = Buyer::create(['name' => 'IMPORT BUYER', 'is_active' => true]);

        $this->assertSame(0, Consignee::where('buyer_id', $buyer->id)->count());
    }

    public function test_skip_flag_suppresses_auto_consignee(): void
    {
        $this->actingAs(User::factory()->create(['permission' => 'admin']));

        Buyer::$skipAutoConsignee = true;
        try {
            $buyer = Buyer::create(['name' => 'FLAG BUYER', 'is_active' => true]);
        } finally {
            Buyer::$skipAutoConsignee = false;
        }

        $this->assertSame(0, Consignee::where('buyer_id', $buyer->id)->count());
    }

    // ── ⑦ 바이어 드롭다운 스코프 ────────────────────────────────────

    private function makeManagerScope(): array
    {
        $manager = User::factory()->create(['permission' => 'user', 'role' => '관리']);
        $sub = User::factory()->create(['permission' => 'user', 'role' => '영업', 'manager_user_id' => $manager->id]);
        $teamSalesman = Salesman::create(['name' => '팀원영업', 'user_id' => $sub->id, 'is_active' => true, 'type' => 'employee']);
        $otherSalesman = Salesman::create(['name' => '외부영업', 'is_active' => true, 'type' => 'employee']);

        // Buyer::created 자동 컨사이니가 스코프 카운트에 영향 없게 — 여기선 auth 없이 생성.
        $teamBuyer = Buyer::create(['name' => 'TEAM BUYER', 'salesman_id' => $teamSalesman->id, 'is_active' => true]);
        $otherBuyer = Buyer::create(['name' => 'OTHER BUYER', 'salesman_id' => $otherSalesman->id, 'is_active' => true]);

        return compact('manager', 'teamBuyer', 'otherBuyer');
    }

    public function test_manager_buyers_dropdown_scoped_to_team(): void
    {
        $c = $this->makeManagerScope();
        $this->actingAs($c['manager']);

        $buyers = Volt::test('erp.vehicles.index')->instance()->buyers;

        $this->assertTrue($buyers->contains('id', $c['teamBuyer']->id), '팀 바이어는 보임');
        $this->assertFalse($buyers->contains('id', $c['otherBuyer']->id), '팀 밖 바이어는 안 보임');
    }

    public function test_admin_buyers_dropdown_sees_all(): void
    {
        $c = $this->makeManagerScope();
        $this->actingAs(User::factory()->create(['permission' => 'admin']));

        $buyers = Volt::test('erp.vehicles.index')->instance()->buyers;

        $this->assertTrue($buyers->contains('id', $c['teamBuyer']->id));
        $this->assertTrue($buyers->contains('id', $c['otherBuyer']->id), 'admin 은 전체');
    }

    public function test_selected_out_of_scope_buyer_always_included(): void
    {
        $c = $this->makeManagerScope();
        $this->actingAs($c['manager']);

        // 편집 중 차량의 현재 바이어가 팀 밖이어도 드롭다운에서 사라지면 안 됨.
        $buyers = Volt::test('erp.vehicles.index')
            ->set('buyer_id_str', (string) $c['otherBuyer']->id)
            ->instance()->buyers;

        $this->assertTrue($buyers->contains('id', $c['otherBuyer']->id), '선택된 팀 밖 바이어는 항상 포함');
    }
}
