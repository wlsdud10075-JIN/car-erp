<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 🚢 운항 pill 에서 「거래완료 + 2차 정산 마감」을 뺀다 (jin 2026-09-10).
 *
 * 배경: ssancarerp 소급 적재(3,839대)가 선적일·ETA 를 전부 들고 들어왔고, `scopeSailing` 은
 * **진행상태를 일부러 안 보는** 설계(2026-08-09)라 그 과거분이 통째로 「도착예정」이 됐다 —
 * 실측 3,883 / 4,793 = **81%**. pill 이 「과거 전체」가 되어 쓸모를 잃었다.
 *
 * 🚫 그렇다고 `scopeSailing`·`sailing_status` 를 좁히면 안 된다 — ssancar.com 포털과 board 가
 *    그 값을 그대로 받아 화면을 그린다. ⇒ **차량관리 목록·pill·그 엑셀만** 좁힌다.
 *    이 테스트의 절반은 그 경계가 안 무너지는지를 본다.
 */
class SailingClosedDealTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function admin(): User
    {
        return User::factory()->create([
            'permission' => 'admin', 'role' => '관리', 'email_verified_at' => now(),
        ]);
    }

    /** 도착예정(선적일 과거 + ETA 과거) 차량 하나. */
    private function arrived(array $attrs = []): Vehicle
    {
        $s = Salesman::create(['name' => 'S'.++$this->n, 'is_active' => true]);
        $b = Buyer::create(['name' => 'B'.$this->n, 'is_active' => true]);

        return Vehicle::create(array_merge([
            'vehicle_number' => '77가'.str_pad((string) $this->n, 4, '0', STR_PAD_LEFT),
            'sales_channel' => 'export',
            'salesman_id' => $s->id,
            'buyer_id' => $b->id,
            'currency' => 'EUR',
            'exchange_rate' => 1500,
            'purchase_price' => 1_000_000,
            'sale_price' => 5_000,
            'sale_date' => now()->subMonths(3)->toDateString(),
            'shipping_date' => now()->subMonths(2)->toDateString(),
            'eta_date' => now()->subDays(20)->toDateString(),
        ], $attrs));
    }

    /**
     * ⚠️ `progress_status_cache` 는 **계산값**이다(`Vehicle::saving` 이 매 저장마다 덮어쓴다).
     *    직접 넣으면 조용히 무시되므로 v4 cascade 가 실제로 그 값을 내도록 조건을 준다.
     *    거래완료 = B/L 서류 존재(1순위) · 선적완료 = 반입지 + 수출신고서.
     */
    private function arrivedAt(string $status): Vehicle
    {
        $v = $this->arrived(match ($status) {
            '거래완료' => ['bl_document' => 'vehicles/bl-'.($this->n + 1).'.pdf'],
            '선적완료' => ['bl_loading_location' => '인천', 'export_declaration_document' => 'vehicles/ed.pdf'],
            default => [],
        });
        $v->refresh();
        $this->assertSame($status, $v->progress_status_cache, "전제: 진행상태가 {$status} 여야 한다");

        return $v;
    }

    private function settle(Vehicle $v, ?string $secondary): Settlement
    {
        return Settlement::create([
            'vehicle_id' => $v->id,
            'salesman_id' => $v->salesman_id,
            'settlement_type' => 'ratio',
            'settlement_ratio' => 50,
            'settlement_status' => 'paid',
            'secondary_status' => $secondary,
            'attributed_month' => now()->startOfMonth()->toDateString(),
        ]);
    }

    /** 도착예정 pill 을 켠 차량관리 목록의 렌더 결과. */
    private function arrivedScreen(): string
    {
        return Volt::actingAs($this->admin())->test('erp.vehicles.index')
            ->set('dateType', 'all')
            ->set('sailingFilter', 'arrived')
            ->html();
    }

    /** 그 화면에 이 차량이 실제로 그려졌나. */
    private function assertListed(Vehicle $v, string $why): void
    {
        $this->assertStringContainsString($v->vehicle_number, $this->arrivedScreen(), $why);
    }

    private function assertNotListed(Vehicle $v, string $why): void
    {
        $this->assertStringNotContainsString($v->vehicle_number, $this->arrivedScreen(), $why);
    }

    /** 끝난 일은 안 보인다 — 거래완료이고 2차까지 닫힌 차. */
    public function test_a_closed_deal_drops_out_of_the_arrived_pill(): void
    {
        $done = $this->arrivedAt('거래완료');
        $this->settle($done, 'closed');

        $this->assertNotListed($done, '거래완료 + 2차 마감인데 도착예정 목록에 남아 있다');
    }

    /** 🔑 아직 안 끝난 것은 남는다 — 실측 337대가 이 상태다(거래완료지만 2차 미마감). */
    public function test_a_deal_whose_secondary_settlement_is_still_open_stays(): void
    {
        $open = $this->arrivedAt('거래완료');
        $this->settle($open, 'pending');

        $this->assertListed($open, '2차 정산이 아직 안 닫혔는데 도착예정에서 빠졌다 — 마감되면 저절로 빠지는 게 설계다');
    }

    /** 조건은 **둘 다** 본다 — 마감된 정산이 있어도 거래완료가 아니면 아직 배 위다. */
    public function test_a_closed_settlement_alone_does_not_hide_a_vehicle_still_in_progress(): void
    {
        $shipping = $this->arrivedAt('선적완료');
        $this->settle($shipping, 'closed');

        $this->assertListed($shipping, '거래완료가 아닌 차가 정산 마감만으로 숨었다 — 조건을 「2차 마감」 하나로 줄이지 말 것');
    }

    /** 정산이 아예 없는 차도 남는다(판정 불가를 숨기지 않는다). */
    public function test_a_vehicle_without_any_settlement_stays(): void
    {
        $none = $this->arrivedAt('거래완료');

        $this->assertListed($none, '정산이 없는 차가 사라졌다');
    }

    /**
     * 🚨 pill 카운트와 목록이 같은 규칙을 쓴다 — 갈리면 「7이라고 떠 있는데 눌러보면 3」이 된다.
     */
    public function test_the_pill_count_matches_the_list(): void
    {
        $this->settle($this->arrivedAt('거래완료'), 'closed');
        $this->settle($this->arrivedAt('거래완료'), 'pending');
        $this->arrived();   // 아직 판매 단계 — 제외 대상이 아니다

        $counts = Volt::actingAs($this->admin())->test('erp.vehicles.index')
            ->set('dateType', 'all')->instance()->sailingCounts;

        $this->assertSame(2, $counts['arrived'], 'pill 카운트가 목록과 다른 규칙을 쓴다');

        $listed = Vehicle::query()->sailing('arrived')->excludeClosedDeals()->count();
        $this->assertSame(2, $listed, '목록 쿼리와 pill 카운트가 갈렸다');
    }

    /**
     * 🚫 **`scopeSailing` 자체는 안 좁힌다** — 포털·board 가 그 값을 그대로 받는다.
     *    여기서 제외가 일어나면 ssancar.com 의 진행 표시가 조용히 바뀐다.
     */
    public function test_the_shared_sailing_scope_is_left_untouched(): void
    {
        $done = $this->arrivedAt('거래완료');
        $this->settle($done, 'closed');

        $this->assertTrue(
            Vehicle::query()->sailing('arrived')->whereKey($done->id)->exists(),
            'scopeSailing 이 좁혀졌다 — 포털이 쓰는 축이라 여기서 빼면 안 된다'
        );
        $this->assertSame(Vehicle::SAILING_ARRIVED, $done->fresh()->sailing_status,
            'sailing_status accessor 가 바뀌었다');
    }

    /**
     * 화면 필터 ↔ 엑셀 정합 — pill 을 켠 채 내려받으면 같은 집합이어야 한다.
     * 안 맞으면 화면 580 · 엑셀 3,883 이 되는데 눈으로는 못 잡는다.
     */
    public function test_the_export_returns_the_same_set_as_the_screen(): void
    {
        $done = $this->arrivedAt('거래완료');
        $this->settle($done, 'closed');
        $open = $this->arrivedAt('거래완료');
        $this->settle($open, 'pending');

        $html = $this->arrivedScreen();
        $this->assertStringNotContainsString($done->vehicle_number, $html);
        $this->assertStringContainsString($open->vehicle_number, $html);

        $exported = Vehicle::query()->sailing('arrived')->excludeClosedDeals()
            ->pluck('vehicle_number')->all();
        $this->assertNotContains($done->vehicle_number, $exported, '엑셀이 화면과 다른 집합을 내보낸다');
        $this->assertContains($open->vehicle_number, $exported, '엑셀이 화면과 다른 집합을 내보낸다');

        $src = file_get_contents(base_path('app/Http/Controllers/VehicleExportController.php'));
        $this->assertStringContainsString('->sailing($sailing)->excludeClosedDeals()', $src,
            '엑셀 내보내기가 운항 필터에서 제외 규칙을 안 탄다');
    }
}
