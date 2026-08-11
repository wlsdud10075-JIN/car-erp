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
     * 💰 금액은 **`amount_krw` 하나뿐**이고 **표시 전용**이다 (§11-2, 2026-08-11 개정).
     *
     * 구 규칙("금액 컬럼 금지")은 폐기됐다 — 받는 사람이 얼마를 보낼지 몰라 신호가 카톡으로
     * 되돌아갔다. 대신 지킬 선은 옮겼다: **여기가 회계 수치의 출처가 되는 길을 막는다.**
     * 판매가·매입가 같은 회계 컬럼이 이 표에 복제되기 시작하면 board 가 원장의 2차 출처가 된다.
     */
    public function test_table_carries_only_the_display_amount(): void
    {
        $this->assertTrue(
            Schema::hasColumn('board_requests', 'amount_krw'),
            'amount_krw 가 없다 — 금액 없는 신호는 받는 사람이 처리할 수 없다'
        );

        foreach (['amount', 'price', 'sale_price', 'purchase_price', 'exchange_rate', 'margin'] as $col) {
            $this->assertFalse(
                Schema::hasColumn('board_requests', $col),
                "board_requests.{$col} 이 생겼다 — 회계 수치를 신호 표에 복제하지 않는다(§11-5 흡수 금지). ".
                '표시용 요청 금액은 amount_krw 하나로 충분하다.'
            );
        }
    }

    /**
     * 🚫 **금액을 받지 않는 type 에는 저장하지 않는다** — 표시 자리가 없어 유령 데이터가 된다.
     * 판정은 `TYPE_META['amount']` 단일 출처(호출측이 임의로 넘겨도 모델이 막는다).
     */
    public function test_amount_is_ignored_for_types_without_an_amount_slot(): void
    {
        $v = $this->vehicle();

        $legacy = BoardRequest::raise($v->id, BoardRequest::TYPE_PURCHASE_PAYMENT, 's@ex.com', amountKrw: 1_234_000);
        $deposit = BoardRequest::raise($v->id, BoardRequest::TYPE_PURCHASE_DEPOSIT, 's@ex.com', amountKrw: 1_234_000);

        $this->assertNull($legacy->amount_krw, '금액칸 없는 type 에 금액이 저장됐다');
        $this->assertSame(1_234_000, $deposit->amount_krw);
    }

    /**
     * 🗺️ TYPE_META 가 화면·판정의 단일 출처다 — 새 type 을 넣고 키를 빠뜨리면
     * **에러 없이 뱃지·색·문구만 사라진다**(기능 테스트로는 못 잡는 부류).
     */
    public function test_every_type_has_complete_meta(): void
    {
        foreach (BoardRequest::TYPES as $type) {
            $meta = BoardRequest::meta($type);
            foreach (['badge', 'title', 'action', 'task', 'alarm', 'color'] as $key) {
                $this->assertArrayHasKey($key, $meta, "{$type} 의 TYPE_META 에 '{$key}' 가 없다");
                $this->assertNotSame('', trim((string) $meta[$key]));
            }
            foreach (['manual_confirm', 'auto_resolve', 'amount'] as $flag) {
                $this->assertIsBool($meta[$flag] ?? null, "{$type} 의 '{$flag}' 가 bool 이 아니다");
            }
            // 빌드된 CSS 에 있는 색만 쓴다 (SKILLS §8 #50 — 없는 색은 켜도 회색으로 보인다).
            $this->assertContains($meta['color'], ['blue', 'purple'], "{$type} 의 색이 빌드 CSS 에 없는 값이다");
            // 알람 type 은 겹치면 안 된다 — 겹치면 한 신호를 닫을 때 다른 신호의 벨까지 꺼진다.
            $this->assertSame(
                $meta,
                BoardRequest::metaByAlarmType($meta['alarm']),
                "{$type} 의 알람 type 이 다른 type 과 겹친다"
            );
        }

        // 계약금은 자동소멸 대상이 아니다 — 미지급 0 으로 꺼지면 거짓 신호가 남는다(인계 §3).
        $this->assertNotContains(BoardRequest::TYPE_PURCHASE_DEPOSIT, BoardRequest::typesWith('auto_resolve'));
        // 구 입금요청은 확인 버튼이 없다(자동소멸 전용).
        $this->assertNotContains(BoardRequest::TYPE_PURCHASE_PAYMENT, BoardRequest::typesWith('manual_confirm'));
    }

    public function test_open_is_idempotent_per_vehicle_and_type(): void
    {
        $v = $this->vehicle();

        $first = BoardRequest::raise($v->id, BoardRequest::TYPE_PURCHASE_PAYMENT, 'sales@ex.com');
        $second = BoardRequest::raise($v->id, BoardRequest::TYPE_PURCHASE_PAYMENT, 'sales@ex.com');

        $this->assertNotNull($first);
        $this->assertNull($second, 'board 재전송이 두 번째 신호를 만들었다 — 뱃지가 쌓인다');
        $this->assertSame(1, BoardRequest::where('vehicle_id', $v->id)->count());
    }

    public function test_different_types_are_independent(): void
    {
        $v = $this->vehicle();

        $this->assertNotNull(BoardRequest::raise($v->id, BoardRequest::TYPE_PURCHASE_PAYMENT, 'a@ex.com'));
        $this->assertNotNull(BoardRequest::raise($v->id, BoardRequest::TYPE_SALE_PAYMENT_CONFIRM, 'a@ex.com'));

        $this->assertSame(2, BoardRequest::where('vehicle_id', $v->id)->open()->count());
    }

    public function test_can_reopen_after_done(): void
    {
        $v = $this->vehicle();
        $first = BoardRequest::raise($v->id, BoardRequest::TYPE_PURCHASE_PAYMENT, 'a@ex.com');
        $first->markDone();

        // 닫힌 뒤엔 다시 요청할 수 있어야 한다(2차 입금 등) — 멱등이 영구 차단이 되면 안 된다.
        $this->assertNotNull(BoardRequest::raise($v->id, BoardRequest::TYPE_PURCHASE_PAYMENT, 'a@ex.com'));
    }

    public function test_mark_done_records_who_and_is_noop_when_not_open(): void
    {
        $user = User::factory()->create(['permission' => 'user', 'role' => '재무', 'email_verified_at' => now()]);
        $r = BoardRequest::raise($this->vehicle()->id, BoardRequest::TYPE_SALE_PAYMENT_CONFIRM, 'a@ex.com');

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
        $r = BoardRequest::raise($this->vehicle()->id, BoardRequest::TYPE_PURCHASE_PAYMENT, 'a@ex.com');
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
            $lines->push(BoardRequest::raise(
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
        $a = BoardRequest::raise($this->vehicle()->id, BoardRequest::TYPE_SALE_PAYMENT_CONFIRM, 'a@ex.com', null, $batch);
        $b = BoardRequest::raise($this->vehicle()->id, BoardRequest::TYPE_SALE_PAYMENT_CONFIRM, 'a@ex.com', null, $batch);

        $a->update(['status' => BoardRequest::STATUS_CANCELLED]);
        $b->markDone();

        $this->assertSame('done', BoardRequest::batchStatus(collect([$a->fresh(), $b->fresh()])));

        $a->update(['status' => BoardRequest::STATUS_CANCELLED]);
        $b->update(['status' => BoardRequest::STATUS_CANCELLED]);
        $this->assertSame('cancelled', BoardRequest::batchStatus(collect([$a->fresh(), $b->fresh()])));
    }
}
