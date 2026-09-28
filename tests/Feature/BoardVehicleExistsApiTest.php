<?php

namespace Tests\Feature;

use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * board 전송 감사용 차량 존재 확인 API (jin 2026-09-28, 야간 배치 실행기 1단계).
 *
 * 지키는 것:
 *   ① 응답은 **id 의 존재 여부뿐** — 차량번호·금액·바이어를 싣지 않는다(싣는 순간 본인격리 대상).
 *   ② 소프트 삭제된 차는 `missing` — board 가 가리키는 행이 「산 행」인지가 질문이다(SKILLS §8 #110).
 *   ③ 서명 없으면 401, 500개 초과·빈 목록은 422 — 감사 도구가 조용히 0 을 받는 일이 없게.
 */
class BoardVehicleExistsApiTest extends TestCase
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

    private function vehicle(): Vehicle
    {
        return Vehicle::create([
            'vehicle_number' => 'EX'.++$this->counter.'가1234',
            'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'dhl_request' => false, 'purchase_date' => '2026-09-01', 'purchase_price' => 1_000_000,
        ]);
    }

    public function test_splits_ids_into_exists_and_missing_and_carries_nothing_else(): void
    {
        $a = $this->vehicle();
        $b = $this->vehicle();
        $ghost = $b->id + 1000;

        $res = $this->signedGet('/api/internal/board/vehicles/exists', ['ids' => "{$a->id},{$ghost},{$b->id}"]);

        $res->assertOk()->assertExactJson([
            'exists' => [$a->id, $b->id],
            'missing' => [$ghost],
        ]);
    }

    public function test_a_soft_deleted_vehicle_counts_as_missing(): void
    {
        $v = $this->vehicle();
        $v->delete();

        $this->signedGet('/api/internal/board/vehicles/exists', ['ids' => (string) $v->id])
            ->assertOk()->assertExactJson(['exists' => [], 'missing' => [$v->id]]);
    }

    public function test_duplicates_and_junk_are_ignored(): void
    {
        $v = $this->vehicle();

        $this->signedGet('/api/internal/board/vehicles/exists', ['ids' => "{$v->id},{$v->id},abc,0,-3"])
            ->assertOk()->assertExactJson(['exists' => [$v->id], 'missing' => []]);
    }

    public function test_empty_or_oversized_lists_are_rejected_not_silently_zero(): void
    {
        // 빈 값(`ids=`)은 서명 정규화에서 쿼리가 비어 401 로 먼저 걸린다 — 422 검사는 「값은 있는데 유효 id 가 0개」로.
        $this->signedGet('/api/internal/board/vehicles/exists', ['ids' => ','])->assertStatus(422);
        $this->signedGet('/api/internal/board/vehicles/exists', ['ids' => 'abc'])->assertStatus(422);
        $this->signedGet('/api/internal/board/vehicles/exists', ['ids' => implode(',', range(1, 501))])->assertStatus(422);
    }

    public function test_unsigned_request_is_refused(): void
    {
        $this->get('/api/internal/board/vehicles/exists?ids=1')->assertStatus(401);
    }
}
