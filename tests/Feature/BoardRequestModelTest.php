<?php

namespace Tests\Feature;

use App\Models\BoardRequest;
use App\Models\Buyer;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * board↔erp 요청·확인 신호 A단계 — 테이블·모델·멱등 가드.
 *
 * 권위 = docs/integration/board-portal-api.md §11 / 계획 = docs/design/board-erp-request-ack.md.
 * 핵심 불변식 2개:
 *   ① 같은 차 + 같은 type 에 열린 신호는 **하나뿐**(board 가 재전송해도 뱃지가 안 쌓인다)
 *   ② **금액 컬럼이 없다** — 있으면 "board 가 보낸 금액이 회계에 반영"되는 길이 열린다(§11-2)
 */
class BoardRequestModelTest extends TestCase
{
    use RefreshDatabase;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function vehicle(): Vehicle
    {
        return Vehicle::create([
            'vehicle_number' => 'BRQ-'.++$this->counter,
            'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'dhl_request' => false,
        ]);
    }

    /**
     * 🚫 금액 컬럼 금지 (§11-2) — 신호는 "누구의 어느 차"까지만 지목한다.
     * 금액이 생기면 board 가 회계 수치의 출처가 되는 길이 열린다.
     */
    public function test_table_has_no_amount_column(): void
    {
        foreach (['amount', 'amount_krw', 'requested_amount', 'price', 'sale_price'] as $col) {
            $this->assertFalse(
                Schema::hasColumn('board_requests', $col),
                "board_requests.{$col} 이 생겼다 — 금액은 신호에 싣지 않는다(§11-2). 은행 API 연동이면 스펙부터 개정할 것"
            );
        }
    }

    public function test_open_is_idempotent_per_vehicle_and_type(): void
    {
        $v = $this->vehicle();

        $first = BoardRequest::open($v->id, BoardRequest::TYPE_PURCHASE_PAYMENT, 'sales@ex.com');
        $second = BoardRequest::open($v->id, BoardRequest::TYPE_PURCHASE_PAYMENT, 'sales@ex.com');

        $this->assertNotNull($first);
        $this->assertNull($second, 'board 재전송이 두 번째 신호를 만들었다 — 뱃지가 쌓인다');
        $this->assertSame(1, BoardRequest::where('vehicle_id', $v->id)->count());
    }

    public function test_different_types_are_independent(): void
    {
        $v = $this->vehicle();

        $this->assertNotNull(BoardRequest::open($v->id, BoardRequest::TYPE_PURCHASE_PAYMENT, 'a@ex.com'));
        $this->assertNotNull(BoardRequest::open($v->id, BoardRequest::TYPE_SALE_PAYMENT_CONFIRM, 'a@ex.com'));

        $this->assertSame(2, BoardRequest::where('vehicle_id', $v->id)->open()->count());
    }

    public function test_can_reopen_after_done(): void
    {
        $v = $this->vehicle();
        $first = BoardRequest::open($v->id, BoardRequest::TYPE_PURCHASE_PAYMENT, 'a@ex.com');
        $first->markDone();

        // 닫힌 뒤엔 다시 요청할 수 있어야 한다(2차 입금 등) — 멱등이 영구 차단이 되면 안 된다.
        $this->assertNotNull(BoardRequest::open($v->id, BoardRequest::TYPE_PURCHASE_PAYMENT, 'a@ex.com'));
    }

    public function test_mark_done_records_who_and_is_noop_when_not_open(): void
    {
        $user = User::factory()->create(['permission' => 'user', 'role' => '재무', 'email_verified_at' => now()]);
        $r = BoardRequest::open($this->vehicle()->id, BoardRequest::TYPE_SALE_PAYMENT_CONFIRM, 'a@ex.com');

        $r->markDone($user);
        $r->refresh();
        $firstConfirmedAt = $r->confirmed_at;

        $this->assertSame(BoardRequest::STATUS_DONE, $r->status);
        $this->assertSame($user->id, $r->confirmed_by_id);

        // 두 번째 호출은 no-op — 확인 시각이 덮이면 감사 기록이 흐려진다.
        $r->markDone(User::factory()->create(['email_verified_at' => now()]));
        $r->refresh();
        $this->assertSame($user->id, $r->confirmed_by_id);
        $this->assertEquals($firstConfirmedAt, $r->confirmed_at);
    }

    /** 자동 해소(매입 미지급 0)는 사람이 없다 — confirmed_by 가 비어도 done 이어야 한다. */
    public function test_system_resolution_has_no_confirmer(): void
    {
        $r = BoardRequest::open($this->vehicle()->id, BoardRequest::TYPE_PURCHASE_PAYMENT, 'a@ex.com');
        $r->markDone();
        $r->refresh();

        $this->assertSame(BoardRequest::STATUS_DONE, $r->status);
        $this->assertNull($r->confirmed_by_id);
        $this->assertNotNull($r->confirmed_at);
    }

    public function test_batch_status_aggregates_lines(): void
    {
        $buyer = Buyer::create(['name' => 'BRQ BUYER', 'is_active' => true]);
        $batch = 'batch-uuid-1';
        $lines = collect();
        foreach (range(1, 3) as $i) {
            $lines->push(BoardRequest::open(
                $this->vehicle()->id, BoardRequest::TYPE_SALE_PAYMENT_CONFIRM, 'a@ex.com', $buyer->id, $batch
            ));
        }

        $this->assertSame('open', BoardRequest::batchStatus($lines));

        $lines[0]->markDone();
        $this->assertSame('partial', BoardRequest::batchStatus($lines->map->fresh()));

        $lines->each(fn ($l) => $l->fresh()->markDone());
        $this->assertSame('done', BoardRequest::batchStatus($lines->map->fresh()));

        // 같은 batch_id 를 공유한다 = 한 묶음
        $this->assertSame(3, BoardRequest::where('batch_id', $batch)->count());
    }

    /** 취소된 라인은 집계에서 빠진다 — 취소 3대 중 2대만 남으면 그 2대로 판정. */
    public function test_cancelled_lines_are_excluded_from_batch_status(): void
    {
        $batch = 'batch-uuid-2';
        $a = BoardRequest::open($this->vehicle()->id, BoardRequest::TYPE_SALE_PAYMENT_CONFIRM, 'a@ex.com', null, $batch);
        $b = BoardRequest::open($this->vehicle()->id, BoardRequest::TYPE_SALE_PAYMENT_CONFIRM, 'a@ex.com', null, $batch);

        $a->update(['status' => BoardRequest::STATUS_CANCELLED]);
        $b->markDone();

        $this->assertSame('done', BoardRequest::batchStatus(collect([$a->fresh(), $b->fresh()])));

        $a->update(['status' => BoardRequest::STATUS_CANCELLED]);
        $b->update(['status' => BoardRequest::STATUS_CANCELLED]);
        $this->assertSame('cancelled', BoardRequest::batchStatus(collect([$a->fresh(), $b->fresh()])));
    }
}
