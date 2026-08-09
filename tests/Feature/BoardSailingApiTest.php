<?php

namespace Tests\Feature;

use App\Models\Salesman;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * board 읽기 API 의 운항 상태 노출 (2026-08-09).
 *
 * ERP 차량목록의 「🚢 운항중 / ⚓ 도착예정」 축을 board 도 같이 본다.
 * 판정은 `Vehicle::scopeSailing` **단일 출처**를 그대로 쓴다 — 조건을 컨트롤러에 옮겨 적으면
 * "ERP엔 운항중인데 board엔 아님"이 생긴다(SKILLS §8 #44).
 */
class BoardSailingApiTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'test-board-read-secret';

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.board_read.hmac_secret' => $this->secret]);
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function signedGet(string $path, array $query)
    {
        ksort($query);
        $ts = now()->timestamp;
        $canonical = "GET\n".$path.'?'.http_build_query($query)."\n".$ts."\n";

        return $this->get($path.'?'.http_build_query($query), [
            'X-Board-Signature' => 'sha256='.hash_hmac('sha256', $canonical, $this->secret),
            'X-Timestamp' => (string) $ts,
            'X-Nonce' => (string) Str::uuid(),
        ]);
    }

    private function vehicle(int $sid, array $attrs = []): Vehicle
    {
        $v = Vehicle::create(array_merge([
            'vehicle_number' => 'SAIL'.++$this->counter.'가1234',
            'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'dhl_request' => false, 'salesman_id' => $sid,
            'purchase_date' => '2026-08-01', 'purchase_price' => 1_000_000,
            'sale_price' => 2_000_000, 'sale_date' => '2026-08-02',
        ], $attrs));
        $v->refreshCaches();

        return $v->fresh();
    }

    public function test_sales_exposes_sailing_key_label_and_dates(): void
    {
        $s = Salesman::create(['name' => 'S1', 'email' => 'sail@t.test', 'is_active' => true]);

        $this->vehicle($s->id, [
            'shipping_date' => now()->subDays(5)->toDateString(),
            'eta_date' => now()->addDays(20)->toDateString(),
            'vessel_name' => 'MV GLOVIS',
        ]);

        $res = $this->signedGet('/api/internal/board/sales', ['salesman_email' => 'sail@t.test'])
            ->assertOk()->json('data.0');

        $this->assertSame('in_transit', $res['sailing'], '기계용 키는 영문(필터값과 동일)');
        $this->assertSame(Vehicle::SAILING_IN_TRANSIT, $res['sailing_status'], '표시 라벨은 ERP 값 그대로');
        $this->assertSame('MV GLOVIS', $res['vessel_name']);
        $this->assertNotNull($res['eta_date']);
    }

    public function test_sailing_is_null_when_dates_are_missing(): void
    {
        $s = Salesman::create(['name' => 'S2', 'email' => 'none@t.test', 'is_active' => true]);
        $this->vehicle($s->id, ['shipping_date' => now()->subDay()->toDateString()]);   // ETA 없음

        $res = $this->signedGet('/api/internal/board/sales', ['salesman_email' => 'none@t.test'])
            ->assertOk()->json('data.0');

        $this->assertNull($res['sailing']);
        $this->assertNull($res['sailing_status']);
    }

    /** 🔒 API 필터 결과 = 화면 scope 결과. 갈리면 board 와 ERP 가 다른 목록을 본다. */
    public function test_sailing_filter_matches_the_screen_scope(): void
    {
        $s = Salesman::create(['name' => 'S3', 'email' => 'filter@t.test', 'is_active' => true]);

        $this->vehicle($s->id, ['shipping_date' => now()->subDays(2)->toDateString(), 'eta_date' => now()->addDays(9)->toDateString()]);
        $this->vehicle($s->id, ['shipping_date' => now()->subDays(2)->toDateString(), 'eta_date' => now()->addDays(3)->toDateString()]);
        $this->vehicle($s->id, ['shipping_date' => now()->subDays(80)->toDateString(), 'eta_date' => now()->subDays(9)->toDateString()]);
        $this->vehicle($s->id);

        foreach (['in_transit', 'arrived'] as $phase) {
            $expected = Vehicle::query()->where('salesman_id', $s->id)->sailing($phase)
                ->pluck('id')->sort()->values()->all();

            $got = collect($this->signedGet('/api/internal/board/sales', [
                'salesman_email' => 'filter@t.test', 'sailing' => $phase,
            ])->assertOk()->json('data'))->pluck('vehicle_id')->sort()->values()->all();

            $this->assertSame($expected, $got, "{$phase} — API 필터가 화면 scope 와 갈렸다");
        }
    }

    /** 알 수 없는 값은 필터 없음으로 무시한다(422 로 죽이지 않는다 — board 구버전 호환). */
    public function test_unknown_sailing_value_is_ignored(): void
    {
        $s = Salesman::create(['name' => 'S4', 'email' => 'bad@t.test', 'is_active' => true]);
        $this->vehicle($s->id, ['shipping_date' => now()->subDay()->toDateString(), 'eta_date' => now()->addDay()->toDateString()]);

        $this->signedGet('/api/internal/board/sales', ['salesman_email' => 'bad@t.test', 'sailing' => 'sunk'])
            ->assertOk()->assertJsonPath('count', 1);
    }

    /** 운항 필터와 진행상태 제외가 **동시에** 걸린다(직교 축). */
    public function test_sailing_and_exclude_status_combine(): void
    {
        $s = Salesman::create(['name' => 'S5', 'email' => 'both@t.test', 'is_active' => true]);

        $sailing = ['shipping_date' => now()->subDays(3)->toDateString(), 'eta_date' => now()->addDays(12)->toDateString()];
        $this->vehicle($s->id, $sailing + ['bl_document' => 'bl/a.pdf']);        // 거래완료 + 운항중
        $this->vehicle($s->id, $sailing + ['bl_loading_location' => '평택']);     // 선적중 + 운항중

        $data = $this->signedGet('/api/internal/board/sales', [
            'salesman_email' => 'both@t.test', 'sailing' => 'in_transit', 'exclude_status' => '거래완료',
        ])->assertOk()->json('data');

        $this->assertCount(1, $data, '운항중이면서 거래완료가 아닌 차만 남아야 한다');
        $this->assertSame('선적중', $data[0]['progress_status']);
    }

    /** 출고완료 재고에도 운항 상태가 실린다 — "나갔다"만으론 배 위인지 도착인지 모른다. */
    public function test_inventory_shipped_out_carries_sailing(): void
    {
        $s = Salesman::create(['name' => 'S6', 'email' => 'inv@t.test', 'is_active' => true]);

        $v = $this->vehicle($s->id, [
            'warehouse_out_date' => now()->subDays(7)->toDateString(),
            'shipping_date' => now()->subDays(7)->toDateString(),
            'eta_date' => now()->addDays(14)->toDateString(),
            'vessel_name' => 'MV SEOUL',
        ]);
        $v->purchaseBalancePayments()->create([
            'amount' => 1_000_000, 'payment_date' => '2026-08-02', 'confirmed_at' => now(),
        ]);

        $row = $this->signedGet('/api/internal/board/inventory', [
            'salesman_email' => 'inv@t.test', 'category' => 'shipped_out',
        ])->assertOk()->json('data.0');

        $this->assertSame('in_transit', $row['sailing']);
        $this->assertSame('MV SEOUL', $row['vessel_name']);
    }
}
