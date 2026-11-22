<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 🔼🔽 정렬 헤더를 바이어·컨사이니·사내직원·정산·재무처리에 붙였다 (jin 2026-11-22 «헤더가 있는 곳은 전체 적용»).
 *
 * 정렬 선언은 raw SQL 조각(`StackedHeaderSort::applySortSpec`)이라 **칸 이름 하나만 틀려도 그 헤더를 누르는 순간 500** 이다.
 * 그래서 화면마다 선언된 키를 **전부** 한 번씩 눌러 본다(행이 없어도 SQL 은 준비 단계에서 검증된다).
 */
class ListHeaderSortScreensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
        $this->actingAs(User::create([
            'name' => '관리자', 'email' => 'a'.uniqid().'@t.test', 'password' => 'x',
            'permission' => 'admin', 'role' => '관리', 'email_verified_at' => now(),
        ]));
    }

    /** @return string[] 화면 소스의 sortTh('키', …) 목록 */
    private function headerKeys(string $screen): array
    {
        preg_match_all("/sortTh\('([a-z0-9_]+)'/", file_get_contents(resource_path("views/livewire/erp/{$screen}/index.blade.php")), $m);

        return array_values(array_unique($m[1]));
    }

    public function test_every_sort_header_runs_on_the_four_single_table_screens(): void
    {
        foreach (['buyers' => 7, 'consignees' => 5, 'salesmen' => 7, 'settlements' => 13] as $screen => $expected) {
            $keys = $this->headerKeys($screen);
            $this->assertCount($expected, $keys, "{$screen}: 정렬 헤더 수가 다르다");
            foreach ($keys as $key) {
                $c = Volt::test("erp.{$screen}.index")->call('clickSort', $key);
                $c->assertOk()->assertSet('sortColumn', $key);   // 허용 키가 아니면 조용히 무시된다 — 헤더와 선언이 어긋난 것
                $c->call('clickSort', $key)->assertOk()->assertSet('sortDirection', 'desc');
            }
        }
    }

    /** 재무처리는 탭마다 표가 다르다 — 탭별 키를 그 탭에서 누른다. 탭을 바꾸면 정렬은 기본으로 돌아간다. */
    public function test_transfers_sorts_per_tab_and_resets_on_tab_change(): void
    {
        $tabs = [
            'transfer' => ['amount', 'status', 'elapsed'],
            'sale_payment' => ['vehicle', 'progress', 'amount', 'payment_date', 'memo', 'status'],
            'purchase_payment' => ['vehicle', 'progress', 'amount', 'payment_date', 'memo', 'status'],
        ];
        foreach ($tabs as $tab => $keys) {
            foreach ($keys as $key) {
                Volt::test('erp.transfers.index')->set('tabType', $tab)
                    ->call('clickSort', $key)->assertOk()->assertSet('sortColumn', $key);
            }
        }
        // 잔금 탭 전용 키는 자금 이체 탭에서 무시된다
        Volt::test('erp.transfers.index')->set('tabType', 'transfer')->call('clickSort', 'vehicle')->assertSet('sortColumn', '');
        Volt::test('erp.transfers.index')->set('tabType', 'sale_payment')->call('clickSort', 'vehicle')
            ->set('tabType', 'transfer')->assertOk()->assertSet('sortColumn', '');
    }

    public function test_buyers_sort_by_name_and_fall_back_to_the_default_order(): void
    {
        $sm = Salesman::create(['name' => '홍길동', 'is_active' => true, 'type' => 'employee']);
        Buyer::create(['name' => 'AAA-CO', 'salesman_id' => null, 'is_active' => true]);
        Buyer::create(['name' => 'ZZZ-CO', 'salesman_id' => $sm->id, 'is_active' => true]);

        // 기본 = 담당자별 묶음(미지정은 뒤)
        $c = Volt::test('erp.buyers.index')->assertSeeInOrder(['ZZZ-CO', 'AAA-CO']);
        $c->call('clickSort', 'name')->assertSeeInOrder(['AAA-CO', 'ZZZ-CO']);
        $c->call('clickSort', 'name')->assertSeeInOrder(['ZZZ-CO', 'AAA-CO']);
        $c->call('clickSort', 'name')->assertSet('sortColumn', '');           // ▲ → ▼ → 빠짐
        // 담당자 이름순 — 미지정은 방향과 무관하게 뒤
        $c->call('clickSort', 'salesman')->assertSeeInOrder(['ZZZ-CO', 'AAA-CO']);
    }

    /**
     * 🧮 정산의 계산 칸(입금률·총마진·마진율·정산액·실지급액) — 저장하지 않는 값이라 걸린 정산을 전부 계산해 줄 세운다
     *    (jin 2026-11-22 «이건 정말 정렬하고 싶지»). 순서는 화면이 찍는 값 그대로여야 하고, 페이지를 넘겨도 이어져야 한다.
     */
    public function test_settlements_sort_by_a_computed_column_across_pages(): void
    {
        $sm = Salesman::create(['name' => '계산정렬', 'is_active' => true, 'type' => 'freelance']);
        $payout = [];
        foreach ([400, 900, 100, 700, 250, 150, 300, 550, 650, 800, 950, 50] as $price) {
            $v = Vehicle::create([
                'vehicle_number' => 'CS-'.$price, 'sales_channel' => 'export', 'currency' => 'USD', 'exchange_rate' => 1350,
                'dhl_request' => false, 'sale_price' => $price * 10, 'sale_date' => '2026-04-01', 'purchase_date' => '2026-01-01',
                'purchase_price' => 1000000, 'salesman_id' => $sm->id,
            ]);
            $s = Settlement::create([
                'vehicle_id' => $v->id, 'salesman_id' => $sm->id, 'settlement_type' => 'ratio', 'settlement_ratio' => 50, 'settlement_status' => 'pending',
            ]);
            $payout['CS-'.$price] = $s->fresh()->actual_payout;
        }
        $this->assertCount(12, array_unique($payout), '픽스처의 실지급액이 서로 달라야 순서를 볼 수 있다');
        asort($payout);
        $asc = array_keys($payout);

        $c = Volt::test('erp.settlements.index')->set('monthFilter', '')->set('perPage', 10)->call('clickSort', 'actual_payout');
        $c->assertSet('sortColumn', 'actual_payout')->assertSeeInOrder(array_slice($asc, 0, 10));
        $c->call('clickSort', 'actual_payout')->assertSet('sortDirection', 'desc')->assertSeeInOrder(array_slice(array_reverse($asc), 0, 10));
        $c->call('clickSort', 'actual_payout')->assertSet('sortColumn', '');            // ▲ → ▼ → 기본

        // 계산 칸은 한 칸만 — DB 칸 정렬 중에 누르면 그 정렬은 풀리고, 계산 칸 정렬 중에 DB 칸을 누르면 그 칸 하나로 바뀐다
        $c->call('clickSort', 'status')->call('clickSort', 'total_margin')->assertSet('sortColumn', 'total_margin')->assertSet('sortExtra', []);
        $c->call('clickSort', 'status')->assertSet('sortColumn', 'status')->assertSet('sortExtra', []);

        // 페이지를 넘겨도 줄이 이어진다 — 10건씩이면 2쪽은 11·12번째
        $rows = Volt::test('erp.settlements.index')->set('monthFilter', '')->set('perPage', 10)
            ->call('clickSort', 'actual_payout')->call('gotoPage', 2)->instance()->settlements;
        $this->assertSame(12, $rows->total());
        $this->assertSame([$asc[10], $asc[11]], $rows->map(fn ($s) => $s->vehicle->vehicle_number)->all());
    }

    /** 상한을 넘으면 계산하지 않는다 — 30초마다 다시 그리는 화면이라 수천 건을 매번 계산하게 두지 않는다. */
    public function test_settlements_computed_sort_refuses_above_the_cap(): void
    {
        $this->assertSame(1000, (new \ReflectionClassConstant(Volt::test('erp.settlements.index')->instance(), 'COMPUTED_SORT_MAX'))->getValue());
        $src = file_get_contents(resource_path('views/livewire/erp/settlements/index.blade.php'));
        $this->assertStringContainsString('> self::COMPUTED_SORT_MAX) {', $src);
        $this->assertStringContainsString("__('settlement.computed_sort_too_many'", $src);
        $this->assertNotSame('settlement.computed_sort_too_many', __('settlement.computed_sort_too_many'));
    }
}
