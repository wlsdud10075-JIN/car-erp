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
 * 페이지네이션 동점 tie-break (jin 2026-09-11). 상세 = SKILLS §8 #92.
 *
 * 목록이 `created_at` 같은 **유일하지 않은 컬럼**으로 정렬하면, 값이 같은 행들의 순서를
 * DB 가 보장하지 않는다. LIMIT/OFFSET 페이지네이션에서 **같은 행이 두 페이지에 나오거나
 * 어떤 행은 어느 페이지에도 안 나온다.** 예외도 로그도 없다.
 *
 * 실측 계기 — ssancarerp 차량 4,794대 중 **4,693대가 같은 초**에 등록돼 있다
 * (2026-08-28 일괄 적재, 한 초에 최대 79대). 그리고 이 불안정성이 실제로 CI 를 한 번
 * 빨갛게 만들어 **3사 배포를 막았다**(`VehicleBuyerFilterComboboxTest`, 코드는 멀쩡했다).
 *
 * 🚨 **이 부류는 「가끔」 틀린다** — 한 번 통과했다고 안전한 게 아니다. 그래서
 *    ①전 페이지를 모아 중복·누락을 직접 세고 ②정렬 끝에 유일키가 붙어 있는지 정적으로 검사한다.
 */
class PaginationStableOrderTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 목록을 페이지별로 훑어, **각 페이지에 보이는 차량번호 집합**을 돌려준다.
     *
     * ⚠️ 화면은 데스크탑 표와 모바일 카드를 **둘 다** 렌더한다(SKILLS §11 페어 렌더) →
     *    한 차가 HTML 에 두 번 나온다. 그래서 페이지 안에서는 unique 로 누른다.
     *    우리가 잡으려는 건 **페이지 사이의** 중복·누락이다.
     *
     * @return list<list<string>>
     */
    private function pagesOf(string $platePrefix, int $perPage, int $pages): array
    {
        $out = [];
        $c = Volt::test('erp.vehicles.index')->set('perPage', $perPage);
        for ($p = 1; $p <= $pages; $p++) {
            $c->call('gotoPage', $p);
            preg_match_all('/'.$platePrefix.'\d{4}/u', $c->html(), $m);
            $out[] = array_values(array_unique($m[0]));
        }

        return $out;
    }

    /** 일괄 적재 재현 — SQLite 는 마이크로초까지 저장해서 그냥 만들면 동점이 안 생긴다. */
    private function forceSameTimestamp(): void
    {
        Vehicle::query()->update(['created_at' => '2026-08-28 13:48:12']);
    }

    public function test_paging_through_tied_timestamps_never_duplicates_or_drops_a_row(): void
    {
        $this->actingAs(User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]));
        $sm = Salesman::create(['name' => '영업', 'type' => 'employee', 'is_active' => true]);
        $buyer = Buyer::create(['name' => 'TIE TEST', 'is_active' => true, 'salesman_id' => $sm->id]);

        for ($i = 1; $i <= 30; $i++) {
            Vehicle::create([
                'vehicle_number' => sprintf('77가%04d', 1000 + $i),
                'sales_channel' => 'export', 'currency' => 'USD', 'exchange_rate' => 1350,
                'dhl_request' => false, 'salesman_id' => $sm->id, 'buyer_id' => $buyer->id,
                'purchase_price' => 5_000_000, 'purchase_date' => now()->toDateString(),
            ]);
        }
        $this->forceSameTimestamp();
        $this->assertSame(1, Vehicle::query()->distinct()->count('created_at'),
            '사전조건이 깨졌다 — 30대가 같은 시각이 아니다');

        $pages = $this->pagesOf('77가', perPage: 7, pages: 5);
        $all = array_merge(...$pages);

        $this->assertCount(30, $all, '페이지를 다 합쳤는데 30대가 아니다 — 누락이 있다');
        $this->assertCount(30, array_unique($all), '같은 차가 두 페이지에 나왔다');
    }

    /**
     * 🚨 **SQLite 로는 불안정을 재현할 수 없다** — 동점이어도 rowid 순으로 안정적으로 돌려준다.
     *    (운영은 MySQL 이고 거기서 흔들린다 — SKILLS §8 #36 의 그 드라이버 차이.)
     *    그래서 「결과」가 아니라 **실제로 나간 SQL** 을 본다. 이건 드라이버와 무관하게 유효하다.
     */
    public function test_the_vehicle_list_query_orders_by_a_unique_key_last(): void
    {
        $this->actingAs(User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]));
        $sm = Salesman::create(['name' => '영업2', 'type' => 'employee', 'is_active' => true]);
        Vehicle::create([
            'vehicle_number' => '88가1001', 'sales_channel' => 'export', 'currency' => 'USD',
            'exchange_rate' => 1350, 'dhl_request' => false, 'salesman_id' => $sm->id,
            'purchase_price' => 1_000_000, 'purchase_date' => now()->toDateString(),
        ]);

        $seen = [];
        DB::listen(function ($q) use (&$seen) {
            // 페이지네이션 쿼리만 본다 — 집계·드롭다운 쿼리(order by "cnt" 등)는 대상이 아니다.
            if (str_contains($q->sql, 'from "vehicles"')
                && str_contains($q->sql, 'order by')
                && str_contains($q->sql, 'offset')) {
                $seen[] = $q->sql;
            }
        });

        Volt::test('erp.vehicles.index')->set('perPage', 10)->call('gotoPage', 1)->html();

        $this->assertNotSame([], $seen, '차량 목록 쿼리를 하나도 못 잡았다 — 테스트가 무의미하다');

        foreach ($seen as $sql) {
            $clause = substr($sql, (int) strrpos($sql, 'order by') + 8);
            $clause = trim(preg_replace('/\s+limit\s.*$/is', '', $clause));
            $this->assertMatchesRegularExpression(
                '/"(?:id|batch_id)"\s+(asc|desc)$/i',
                $clause,
                "정렬이 유일키로 안 끝난다 → 동점 행의 순서가 매 쿼리 달라질 수 있다.
  order by {$clause}",
            );
        }
    }

    /**
     * 정적 검사 — 페이지네이션하는 목록은 **정렬 끝에 유일키**가 있어야 한다.
     *
     * ⚠️ 기능 테스트로는 원리상 못 잡는다 — tie-break 를 빼도 화면은 정상 렌더되고,
     *    운이 좋으면 순서까지 맞는다. 되돌아가도 한참 뒤에야 「차가 사라졌다」로 드러난다.
     */
    public function test_every_paginated_list_ends_its_ordering_with_a_unique_key(): void
    {
        // 정렬 끝에 이것 중 하나가 있어야 유일하다. batch_id 는 GROUP BY 의 그룹키라 유일하다.
        $uniqueTail = ['id', 'batch_id'];

        $files = [];
        foreach (['resources/views/livewire/erp', 'resources/views/livewire/admin'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir)));
            foreach ($it as $f) {
                if ($f->isFile() && str_ends_with($f->getFilename(), '.blade.php')) {
                    $files[] = $f->getPathname();
                }
            }
        }

        $offenders = [];
        foreach ($files as $file) {
            $src = file_get_contents($file);
            foreach ($this->matchAll('/->paginate\(/', $src) as $pos) {
                $window = substr($src, max(0, $pos - 1500), min($pos, 1500));
                // ⚠️ 변수 컬럼(`orderBy($this->sortColumn, …)`)도 **정렬 호출**이다.
                //    따옴표 컬럼만 세면 그게 마지막이어도 안 보여서 검사가 통째로 속는다
                //    (2026-09-11 에 실제로 속았다 — 되돌려 보고서야 알았다, SKILLS §8 #73).
                $calls = $this->matchAll('/->(?:orderBy|orderByDesc|orderByRaw|latest|oldest)\s*\(/', $window);
                if ($calls === []) {
                    continue;   // 정렬이 아예 없는 목록은 대상이 아니다
                }
                $tail = substr($window, (int) end($calls), 120);
                if (! preg_match("/^->(?:orderBy|orderByDesc)\s*\(\s*'(?:[a-z_]+\.)?(id|batch_id)'/i", $tail)) {
                    $offenders[] = basename(dirname($file)).'/'.basename($file).' → '.trim(explode('
', $tail)[0]);
                }
            }
        }

        $this->assertSame([], $offenders,
            "페이지네이션 목록의 정렬이 유일키로 끝나지 않는다 — 페이지가 흔들린다(SKILLS §8 #92).\n  ".
            implode("\n  ", $offenders));
    }

    /** @return list<int> */
    private function matchAll(string $pattern, string $subject): array
    {
        preg_match_all($pattern, $subject, $m, PREG_OFFSET_CAPTURE);

        return array_map(fn ($x) => (int) $x[1], $m[0]);
    }
}
