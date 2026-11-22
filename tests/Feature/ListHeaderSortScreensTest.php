<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\User;
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
        foreach (['buyers' => 7, 'consignees' => 5, 'salesmen' => 7, 'settlements' => 8] as $screen => $expected) {
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
}
