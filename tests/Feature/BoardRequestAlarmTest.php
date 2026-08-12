<?php

namespace Tests\Feature;

use App\Models\BoardRequest;
use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\TaskAlarm;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * board 요청·확인 신호의 **가시성** — jin 2026-08-09.
 *
 * 문제: 재무 처리 화면에 들어가 탭까지 눌러보기 전엔 요청이 온 걸 알 수 없었다.
 * 재무가 하루 종일 모르고 지나가면 영업이 결국 카톡으로 물어보고, 이 기능의 존재 이유가 사라진다.
 *
 * 그래서 세 곳에 나타나야 한다 — **탭 숫자 · 사이드바 뱃지 · 알람(벨/목록)**.
 * 알람은 `target_role='관리'` 라 기존 `scopeVisibleTo` 분기를 그대로 타고,
 * **admin·업무관리자는 전체 / role='관리' 는 본인 팀**(휴가 위임 포함)이 본다.
 */
class BoardRequestAlarmTest extends TestCase
{
    use RefreshDatabase;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function vehicle(?int $salesmanId = null, ?int $buyerId = null): Vehicle
    {
        return Vehicle::create([
            'vehicle_number' => 'BAL-'.++$this->counter,
            'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'dhl_request' => false, 'salesman_id' => $salesmanId, 'buyer_id' => $buyerId,
            'purchase_date' => '2026-08-01', 'purchase_price' => 1_000_000,
        ]);
    }

    public function test_opening_a_request_creates_an_alarm(): void
    {
        $v = $this->vehicle();
        BoardRequest::raise($v->id, BoardRequest::TYPE_PURCHASE_BALANCE, 'sales@ex.com');

        $alarm = TaskAlarm::where('vehicle_id', $v->id)->open()->first();

        $this->assertNotNull($alarm, '신호를 보냈는데 알람이 안 생겼다 — 아무도 모르고 지나간다');
        $this->assertSame('board_purchase_balance', $alarm->type);
        $this->assertSame('관리', $alarm->target_role);
        $this->assertSame($v->vehicle_number, $alarm->message_meta['vehicle_number'] ?? null);
    }

    public function test_sale_confirm_uses_its_own_alarm_type(): void
    {
        $buyer = Buyer::create(['name' => 'ABC', 'is_active' => true]);
        $v = $this->vehicle(null, $buyer->id);
        BoardRequest::raise($v->id, BoardRequest::TYPE_SALE_PAYMENT_CONFIRM, 'sales@ex.com', $buyer->id, 'b-1');

        $this->assertSame('board_sale_confirm', TaskAlarm::where('vehicle_id', $v->id)->open()->value('type'));
    }

    /** 처리한 일이 벨에 남아 있으면 사람이 알람을 안 믿게 된다. */
    public function test_alarm_closes_when_request_is_done(): void
    {
        $v = $this->vehicle();
        $req = BoardRequest::raise($v->id, BoardRequest::TYPE_PURCHASE_BALANCE, 'sales@ex.com');

        $req->markDone();

        $this->assertSame(0, TaskAlarm::where('vehicle_id', $v->id)->open()->count(), '요청은 닫혔는데 알람이 남았다');
        $this->assertSame('auto_resolved', TaskAlarm::where('vehicle_id', $v->id)->value('resolved_reason'));
    }

    /** 자동 해소(매입 미지급 0)로 닫힐 때도 알람이 같이 닫혀야 한다. */
    public function test_alarm_closes_on_auto_resolution(): void
    {
        $v = $this->vehicle();
        BoardRequest::raise($v->id, BoardRequest::TYPE_PURCHASE_BALANCE, 'sales@ex.com');

        $v->purchaseBalancePayments()->create([
            'amount' => 1_000_000, 'payment_date' => '2026-08-05', 'confirmed_at' => now(),
        ]);

        $this->assertSame(0, TaskAlarm::where('vehicle_id', $v->id)->open()->count());
    }

    /** 재전송(멱등)이 알람을 두 개로 늘리면 안 된다. */
    public function test_resend_does_not_duplicate_alarm(): void
    {
        $v = $this->vehicle();
        BoardRequest::raise($v->id, BoardRequest::TYPE_PURCHASE_BALANCE, 'sales@ex.com');
        BoardRequest::raise($v->id, BoardRequest::TYPE_PURCHASE_BALANCE, 'sales@ex.com');

        $this->assertSame(1, TaskAlarm::where('vehicle_id', $v->id)->open()->count());
    }

    /**
     * jin 2026-08-09 요구 — **관리도 업무관리자도** 봐야 한다.
     *
     * @param  array<string, mixed>  $attrs
     */
    #[DataProvider('viewerProvider')]
    public function test_alarm_is_visible_to_managers(array $attrs, bool $expected): void
    {
        $sm = Salesman::create(['name' => 'SM', 'email' => 'sm@ex.com', 'is_active' => true]);
        $v = $this->vehicle($sm->id);
        BoardRequest::raise($v->id, BoardRequest::TYPE_PURCHASE_BALANCE, 'sales@ex.com');

        $user = User::factory()->create(array_merge(['email_verified_at' => now()], $attrs));
        $count = TaskAlarm::query()->visibleTo($user)->open()->count();

        $this->assertSame(
            $expected ? 1 : 0,
            $count,
            '가시성이 기대와 다르다: '.json_encode($attrs, JSON_UNESCAPED_UNICODE)
        );
    }

    public static function viewerProvider(): array
    {
        return [
            '최고관리자(전체)' => [['permission' => 'admin', 'role' => '관리'], true],
            '업무관리자(전체)' => [['permission' => 'manager', 'role' => '영업'], true],
            'super(전체)' => [['permission' => 'super', 'role' => '관리'], true],
            // role='관리' 는 본인 팀만 — 팀 배정이 없으면 안 보이는 게 맞다(scopeVisibleTo 기존 규칙).
            '관리(팀 없음)' => [['permission' => 'user', 'role' => '관리'], false],
            '영업' => [['permission' => 'user', 'role' => '영업'], false],
        ];
    }

    /** role='관리' 는 **본인 팀 차량**이면 본다 — 위 케이스의 짝. */
    public function test_manager_role_sees_own_team_vehicle(): void
    {
        $salesUser = User::factory()->create(['permission' => 'user', 'role' => '영업', 'email_verified_at' => now()]);
        $sm = Salesman::create(['name' => 'MINE', 'email' => 'mine@ex.com', 'is_active' => true, 'user_id' => $salesUser->id]);
        $manager = User::factory()->create(['permission' => 'user', 'role' => '관리', 'email_verified_at' => now()]);
        $manager->managedSalesmanUsers()->attach($salesUser->id);

        $v = $this->vehicle($sm->id);
        BoardRequest::raise($v->id, BoardRequest::TYPE_PURCHASE_BALANCE, 'sales@ex.com');

        $this->assertSame(1, TaskAlarm::query()->visibleTo($manager->fresh())->open()->count());
    }

    /** 뱃지 숫자는 단일 출처를 쓴다 — 화면마다 다른 숫자가 나오면 아무도 안 믿는다. */
    public function test_open_count_is_single_source(): void
    {
        $buyer = Buyer::create(['name' => 'ABC', 'is_active' => true]);
        BoardRequest::raise($this->vehicle()->id, BoardRequest::TYPE_PURCHASE_BALANCE, 'a@ex.com');
        BoardRequest::raise($this->vehicle()->id, BoardRequest::TYPE_PURCHASE_BALANCE, 'a@ex.com');
        $sale = BoardRequest::raise($this->vehicle(null, $buyer->id)->id, BoardRequest::TYPE_SALE_PAYMENT_CONFIRM, 'a@ex.com', $buyer->id, 'b-1');

        $this->assertSame(3, BoardRequest::openCount());
        $this->assertSame(2, BoardRequest::openCount(BoardRequest::TYPE_PURCHASE_BALANCE));
        $this->assertSame(1, BoardRequest::openCount(BoardRequest::TYPE_SALE_PAYMENT_CONFIRM));

        $sale->markDone();
        $this->assertSame(2, BoardRequest::openCount(), '닫힌 신호가 뱃지에 남았다');
    }
}
