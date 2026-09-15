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
 * 📅 **채권 목록 「경과일」 칸 + 오래된 순 정렬** (jin 2026-09-15 승인).
 *
 * 왜 만들었나 — 등급만으로는 **같은 칸 안에서 무엇이 급한지** 알 수 없었다.
 * 싼카 실측(2026-09-15, 미수 455 대):
 *
 *   주의 317대  10~91일   (그중 124대는 30일 이내 = 정상 거래)
 *   심각  86대  39~174일
 *   위험  41대  26~279일  ← 26일짜리와 9개월짜리가 같은 칸에 있었다
 *
 * 목록은 미납금 큰 순으로만 정렬돼 있어, 279 일짜리를 찾으려면 **41 대를 하나씩 열어
 * 판매일을 보고 머리로 빼야** 했다. 그래서 실제로는 아무도 안 했다.
 *
 * 🚫 등급·알림톡·대시보드 숫자는 **하나도 안 건드린다** — 보여주기와 정렬만이다.
 */
class ReceivableAgeColumnTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function admin(): User
    {
        return User::factory()->create([
            'permission' => 'admin', 'role' => '관리', 'email_verified_at' => now(),
        ]);
    }

    /** 미수가 남은 차. 첫 인자로 나이를, 둘째로 미납금 크기를 정한다. */
    private function vehicle(int $soldDaysAgo, float $salePrice = 5_000): Vehicle
    {
        $s = Salesman::create(['name' => 'S'.++$this->n, 'is_active' => true]);
        $b = Buyer::create(['name' => 'B'.$this->n, 'is_active' => true]);

        return Vehicle::create([
            'vehicle_number' => '77바'.str_pad((string) (1000 + $this->n), 4, '0', STR_PAD_LEFT),
            'sales_channel' => 'export', 'currency' => 'EUR', 'exchange_rate' => 1500,
            'salesman_id' => $s->id, 'buyer_id' => $b->id,
            'sale_price' => $salePrice,
            'sale_date' => now()->subDays($soldDaysAgo)->toDateString(),
        ]);
    }

    /**
     * 🔗 **화면 숫자 = 등급이 쓰는 숫자.**
     * 갈리면 「90일인데 왜 아직 주의야?」가 된다 — 사람은 화면을 믿는다.
     */
    public function test_the_screen_number_comes_from_the_same_source_as_the_grade(): void
    {
        $v = $this->vehicle(137);

        $this->assertSame(137, $v->fresh()->days_since_sale);

        $src = file_get_contents(base_path('app/Models/Vehicle.php'));
        $start = strpos($src, 'function getReceivableRiskComputedAttribute(');
        $this->assertNotFalse($start);
        $body = substr($src, $start, 2600);

        $this->assertTrue(str_contains($body, '$this->days_since_sale'),
            '위험도 계산이 경과일 단일 출처를 안 쓴다 — 화면과 등급이 다른 날짜를 셀 수 있다(§8 #45)');
        $this->assertFalse(str_contains($body, 'startOfDay()->diffInDays('),
            '위험도 계산이 날짜를 다시 빼고 있다 — 기준일을 바꾸면 한쪽만 따라간다');
    }

    /** 판매일이 없으면 숫자가 아니라 null 이다 — 0 으로 눕히면 「오늘 판 차」로 보인다. */
    public function test_a_vehicle_without_a_sale_date_has_no_age(): void
    {
        $s = Salesman::create(['name' => 'S0', 'is_active' => true]);
        $v = Vehicle::create([
            'vehicle_number' => '77바0001', 'sales_channel' => 'export',
            'currency' => 'KRW', 'salesman_id' => $s->id, 'purchase_price' => 1_000_000,
        ]);

        $this->assertNull($v->fresh()->days_since_sale);
    }

    /**
     * ⚠️ Carbon 3 의 diffInDays 는 부호가 있다(§8 #34).
     * 판매일이 미래인 오입력은 **음수 그대로** 보여준다 — 0 으로 눕히면 오입력이 숨는다.
     */
    public function test_a_future_sale_date_is_not_flattened_to_zero(): void
    {
        $v = $this->vehicle(-5);

        $this->assertSame(-5, $v->fresh()->days_since_sale);
    }

    /** 🚨 **기본 정렬은 종전 그대로** — 바꾸면 익숙한 화면이 조용히 흔들린다. */
    public function test_the_default_order_is_still_the_largest_unpaid(): void
    {
        $small = $this->vehicle(300, 1_000);    // 제일 오래됐지만 미납금이 작다
        $big = $this->vehicle(5, 90_000);       // 제일 최근인데 미납금이 크다

        $html = Volt::actingAs($this->admin())->test('erp.receivables.index')->html();

        $this->assertNotFalse(strpos($html, $small->vehicle_number), '전제가 안 선다 — 두 차가 다 보여야 한다');
        $this->assertLessThan(strpos($html, $small->vehicle_number), strpos($html, $big->vehicle_number),
            '기본 정렬이 바뀌었다 — 미납금 큰 차가 위에 있어야 한다');
    }

    /** 📅 오래된 순을 고르면 **가장 오래된 차가 맨 위**로 온다. */
    public function test_sorting_by_age_puts_the_oldest_first(): void
    {
        $oldest = $this->vehicle(300, 1_000);
        $newest = $this->vehicle(5, 90_000);

        $html = Volt::actingAs($this->admin())->test('erp.receivables.index')
            ->call('applySort', 'age')
            ->html();

        $this->assertNotFalse(strpos($html, $newest->vehicle_number), '전제가 안 선다 — 두 차가 다 보여야 한다');
        $this->assertLessThan(strpos($html, $newest->vehicle_number), strpos($html, $oldest->vehicle_number),
            '오래된 순인데 최근 차가 위에 있다');
    }

    /** 🚫 아무 값이나 넣어 정렬을 깨뜨릴 수 없다 — URL 로 직접 들어오는 값이다. */
    public function test_an_unknown_sort_key_is_ignored(): void
    {
        $c = Volt::actingAs($this->admin())->test('erp.receivables.index')
            ->call('applySort', 'sale_price; drop table');

        $c->assertSet('sortKey', 'unpaid');
    }

    /**
     * 🈳 **번역 키가 화면에 글자로 새면 안 된다**(§8 #73).
     * lang 파일의 엉뚱한 그룹에 키를 넣으면 ko·en 대조 테스트는 통과하고 **화면만 틀린다**.
     */
    public function test_the_new_labels_render_as_words_not_keys(): void
    {
        $this->vehicle(42);

        $html = Volt::actingAs($this->admin())->test('erp.receivables.index')->html();

        foreach (['receivable.col.age', 'receivable.age_days', 'receivable.sort.'] as $leak) {
            $this->assertFalse(str_contains($html, $leak),
                "번역 키가 그대로 렌더됐다 — lang 그룹 위치를 확인할 것: {$leak}");
        }

        /*
         * 🚨 **데스크탑과 모바일을 갈라서 센다.**
         * 처음엔 화면 전체에서 「42일」을 찾았는데, 표의 칸을 통째로 지워도 **모바일 카드가
         * 대신 그려 줘서 초록이 나왔다**(일부러 깨뜨려 보고 알았다 — §8 #73).
         * 한쪽만 그리는 상태로 되돌아가면 반드시 빨개져야 한다.
         */
        $table = substr($html, 0, strpos($html, '</table>') ?: strlen($html));
        $cards = substr($html, strpos($html, '</table>') ?: 0);

        $this->assertTrue(str_contains($table, '42일'), '표(데스크탑)에 경과일 칸이 없다');
        $this->assertTrue(str_contains($cards, '42일'), '카드(모바일)에 경과일이 없다');
        $this->assertTrue(str_contains($table, '경과일'), '표 머리글에 「경과일」이 없다');
        $this->assertTrue(str_contains($cards, '오래된 순'), '모바일에 정렬 버튼이 없다 — 폰에선 정렬을 못 바꾼다');
    }

    /**
     * 🔒 **페이지네이션 안정성** — 오래된 순은 같은 판매일이 무더기로 생긴다
     * (적재 한 번이면 수백 대가 같은 날짜다 — 실측 싼카 4,693/4,794 가 같은 「초」에 등록).
     * 유일키 tie-break 가 빠지면 같은 행이 두 페이지에 나오거나 통째로 빠진다(§8 #92).
     */
    public function test_the_age_sort_keeps_a_unique_tiebreaker(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->vehicle(100);   // 판매일이 전부 같다
        }

        $seen = [];
        DB::listen(function ($q) use (&$seen) {
            if (stripos($q->sql, 'offset') !== false && stripos($q->sql, 'order by') !== false) {
                $seen[] = $q->sql;
            }
        });

        Volt::actingAs($this->admin())->test('erp.receivables.index')->call('applySort', 'age')->html();

        $this->assertNotEmpty($seen, '페이지네이션 쿼리를 못 잡았다 — 이 테스트가 아무것도 검사하지 않는다');
        foreach ($seen as $sql) {
            $order = substr($sql, stripos($sql, 'order by'));
            $ok = (bool) preg_match('/["`]?id["`]?\s+desc/i', $order);
            $this->assertTrue($ok, "정렬 끝에 유일키가 없다 — 같은 판매일 행의 순서가 페이지마다 달라진다:\n".$order);
        }
    }
}
