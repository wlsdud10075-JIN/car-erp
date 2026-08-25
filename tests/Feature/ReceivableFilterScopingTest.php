<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 채권관리 필터 — ① 바이어 드롭다운의 담당자 종속 ② 과입금만 (jin 2026-08-25).
 *
 * 둘 다 「조용히 0건」이 나오기 쉬운 자리다:
 *   ① 담당자를 바꿔도 옛 바이어가 남으면 A 담당자 + B 의 바이어 조합이 된다.
 *   ② 과입금은 미수가 음수라 기본 탭(미수 > 0)과 조건이 충돌한다.
 */
class ReceivableFilterScopingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
        $this->actingAs(User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]));
    }

    private function salesman(string $name): Salesman
    {
        return Salesman::create(['name' => $name, 'is_active' => true, 'type' => 'freelance']);
    }

    private function buyer(string $name, ?Salesman $sm): Buyer
    {
        return Buyer::create(['name' => $name, 'is_active' => true, 'salesman_id' => $sm?->id]);
    }

    /**
     * 미수 캐시는 훅이 다시 쓰므로 raw update 로 원하는 값을 박는다(부호가 이 테스트의 전부다).
     * ⚠️ 채권관리는 `sale_price > 0` 인 차만 본다 — 판매가를 안 넣으면 목록이 통째로 빈다.
     */
    private function vehicleWithUnpaid(Buyer $b, ?Salesman $sm, int $unpaidKrw): Vehicle
    {
        $v = Vehicle::create([
            'vehicle_number' => '77가'.random_int(1000, 9999),
            'sales_channel' => 'export',
            'buyer_id' => $b->id,
            'salesman_id' => $sm?->id,
            'sale_price' => 10_000,
            'sale_date' => now()->subMonths(2)->toDateString(),
            'exchange_rate' => 1_400,
        ]);
        DB::table('vehicles')->where('id', $v->id)->update(['sale_unpaid_amount_krw_cache' => $unpaidKrw]);

        return $v->fresh();
    }

    public function test_buyer_dropdown_narrows_to_the_selected_salesman(): void
    {
        $a = $this->salesman('영업A');
        $b = $this->salesman('영업B');
        $ba = $this->buyer('BUYER-A', $a);
        $bb = $this->buyer('BUYER-B', $b);

        $all = Volt::test('erp.receivables.index')->get('buyers')->pluck('id');
        $this->assertTrue($all->contains($ba->id) && $all->contains($bb->id), '담당자 미선택이면 전체');

        $narrowed = Volt::test('erp.receivables.index')
            ->set('salesmanFilter', (string) $a->id)
            ->get('buyers')->pluck('id');

        $this->assertTrue($narrowed->contains($ba->id));
        $this->assertFalse($narrowed->contains($bb->id), '다른 담당자의 바이어는 빠진다');
    }

    /**
     * 🚨 담당자를 바꿨는데 옛 바이어가 남으면 **조용히 0건**이 된다.
     * 사용자는 바이어를 건드린 적이 없으니 이유를 못 찾는다.
     */
    public function test_changing_salesman_clears_a_buyer_that_no_longer_belongs(): void
    {
        $a = $this->salesman('영업A');
        $b = $this->salesman('영업B');
        $ba = $this->buyer('BUYER-A', $a);
        $this->buyer('BUYER-B', $b);

        Volt::test('erp.receivables.index')
            ->set('salesmanFilter', (string) $a->id)
            ->set('buyerFilter', (string) $ba->id)
            ->set('salesmanFilter', (string) $b->id)
            ->assertSet('buyerFilter', '', '남의 담당자로 바꾸면 바이어 선택이 비워진다');
    }

    public function test_keeps_the_buyer_when_it_still_belongs(): void
    {
        $a = $this->salesman('영업A');
        $ba = $this->buyer('BUYER-A', $a);

        Volt::test('erp.receivables.index')
            ->set('salesmanFilter', (string) $a->id)
            ->set('buyerFilter', (string) $ba->id)
            ->set('salesmanFilter', '')          // 전체로 되돌려도 그 바이어는 여전히 유효하다
            ->assertSet('buyerFilter', (string) $ba->id);
    }

    /** 과입금을 고르면 탭이 「완납」으로 따라간다 — 안 그러면 기본 탭과 충돌해 0건이다. */
    public function test_overpaid_filter_moves_the_tab_to_paid_up(): void
    {
        Volt::test('erp.receivables.index')
            ->assertSet('classification', '')
            ->set('cancelFilter', 'overpaid')
            ->assertSet('classification', 'paid_up');
    }

    public function test_overpaid_filter_lists_only_negative_receivables(): void
    {
        $sm = $this->salesman('영업A');
        $b = $this->buyer('BUYER-A', $sm);

        $over = $this->vehicleWithUnpaid($b, $sm, -2_754_730);
        $paid = $this->vehicleWithUnpaid($b, $sm, 0);
        $owing = $this->vehicleWithUnpaid($b, $sm, 5_000_000);

        $ids = Volt::test('erp.receivables.index')
            ->set('cancelFilter', 'overpaid')
            ->get('vehicles')->pluck('id');

        $this->assertTrue($ids->contains($over->id), '과입금 차는 보인다');
        $this->assertFalse($ids->contains($paid->id), '미수 0(완납)은 과입금이 아니다');
        $this->assertFalse($ids->contains($owing->id), '미수가 남은 차는 당연히 아니다');
    }

    /** 다른 옵션은 그대로 — 과입금을 넣었다고 매입취소/정상 필터가 흔들리면 안 된다. */
    public function test_existing_cancel_options_still_work(): void
    {
        $sm = $this->salesman('영업A');
        $b = $this->buyer('BUYER-A', $sm);
        $normal = $this->vehicleWithUnpaid($b, $sm, 5_000_000);

        $ids = Volt::test('erp.receivables.index')
            ->set('cancelFilter', 'normal')
            ->get('vehicles')->pluck('id');

        $this->assertTrue($ids->contains($normal->id));
    }
}
