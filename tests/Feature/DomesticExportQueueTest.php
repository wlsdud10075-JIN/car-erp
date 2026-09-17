<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\PurchaseBalancePayment;
use App\Models\Salesman;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 🏠 **내수(국내 판매) 차량은 수출 흐름 큐에 안 들어간다** (jin 2026-09-17).
 *
 * jin 제보 = *「정산 안 하는 사용자는 내수용처럼 국내에서 사고파는 거라 말소를 직접적으로 하지 않아서
 * 지금 말소처리필요에 다 잡힌다」*. 실측 ssancarerp 「말소 처리 필요」 274대 중 **21대**가 그것이었다.
 *
 * 🔑 판정 키는 **내수 바이어**(`buyers.is_domestic`)다 — 담당자(`salesmen.payout_excluded`)가 아니다.
 *    이유 = `Vehicle::NOT_FOR_DOMESTIC` 주석.
 *
 * ⚠️ **기능 테스트로는 원리상 못 잡는 부분이 있다** — 조건을 안 넣어도 화면은 정상 렌더되고 큐도 정상
 *    동작한다(숫자만 부풀어 있다). 그래서 ①큐 소속을 직접 세고 ②scope↔accessor 일치를 단언하고
 *    ③돈 큐가 제외 목록에 안 섞였는지 **정적으로** 본다.
 */
class DomesticExportQueueTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    /** `NOT_FOR_CANCELLED` 와 같은 16개 — 매입취소 테스트와 나란히 둔다(둘이 갈리면 여기가 먼저 빨개진다). */
    private const WORKFLOW = [
        'deregistration_needed',
        'clearance_needed', 'clearance_request_needed', 'clearance_info_missing',
        'clearance_stuck', 'clearance_candidates', 'forwarding_missing',
        'export_declaration_upload_needed',
        'shipping_needed', 'shipping_process_needed', 'bl_upload_needed',
        'dhl_needed', 'dhl_dispatch_needed',
        'eta_clearance_reminder', 'eta_missing',
        'document_deadline_reminder',
    ];

    /**
     * 실사고 그대로의 차 — 매입 완납 + 말소 미처리 + 판매됨.
     * 내수는 원화·「내수」 바이어. 대조군은 같은 상태의 수출 차량.
     */
    private function paidVehicle(bool $domestic, array $extra = []): Vehicle
    {
        $sm = Salesman::create(['name' => 'S'.++$this->n, 'type' => 'employee', 'is_active' => true]);
        $buyer = Buyer::create([
            'name' => 'B'.++$this->n, 'is_active' => true,
            'salesman_id' => $sm->id, 'is_domestic' => $domestic,
        ]);
        $v = Vehicle::create(array_merge([
            'vehicle_number' => sprintf('%02d로%04d', 10 + $this->n, 1000 + $this->n),
            'sales_channel' => 'export',
            'currency' => $domestic ? 'KRW' : 'EUR',
            'exchange_rate' => $domestic ? 0 : 1746,
            'salesman_id' => $sm->id,
            'buyer_id' => $buyer->id,
            'purchase_price' => 1_000_000,
            'purchase_date' => now()->subDays(60)->toDateString(),
            'sale_price' => $domestic ? 9_000_000 : 6_000,
            'sale_date' => now()->subDays(50)->toDateString(),
            'is_deregistered' => false,
        ], $extra));
        PurchaseBalancePayment::create([
            'vehicle_id' => $v->id, 'amount' => 1_000_000,
            'payment_date' => now()->subDays(60)->toDateString(),
            'confirmed_at' => now()->subDays(60),
        ]);
        $v->refresh()->refreshProgressCache();

        return $v->refresh();
    }

    /** 전제 — 이 픽스처가 실제로 말소 큐에 들어가는 상태라는 것부터 확인한다. */
    public function test_the_fixture_reproduces_the_reported_state(): void
    {
        $export = $this->paidVehicle(false);

        $this->assertSame(0, $export->purchase_unpaid_amount, '매입 완납이어야 말소 큐에 들어간다');
        $this->assertFalse((bool) $export->is_deregistered);
        $this->assertTrue(Vehicle::query()->action('deregistration_needed')->where('id', $export->id)->exists(),
            '대조군(수출)이 말소 큐에 없다 — 픽스처가 증상을 재현하지 못한다');
    }

    /** 🚫 단계 큐 16개 전부에서 빠진다 — 내수는 말소·통관·선적·B/L·DHL 이 애초에 없다. */
    public function test_domestic_vehicle_is_out_of_every_workflow_queue(): void
    {
        $domestic = $this->paidVehicle(true);

        foreach (self::WORKFLOW as $action) {
            $this->assertFalse(
                Vehicle::query()->action($action)->where('id', $domestic->id)->exists(),
                "내수 차가 {$action} 큐에 남아 있다"
            );
        }
    }

    /**
     * ✅ **돈 큐는 그대로** — 내수도 돈은 받고 정산도 한다(내수 공식 2026-09-08).
     *    여기까지 빼면 받을 돈·줄 돈이 화면에서 사라진다(매입취소와 같은 선).
     */
    public function test_domestic_vehicle_stays_in_the_money_queues(): void
    {
        $sm = Salesman::create(['name' => 'SD', 'type' => 'employee', 'is_active' => true]);
        $buyer = Buyer::create(['name' => '내수', 'is_active' => true, 'salesman_id' => $sm->id, 'is_domestic' => true]);
        $v = Vehicle::create([
            'vehicle_number' => '77허7777',
            'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 0,
            'salesman_id' => $sm->id, 'buyer_id' => $buyer->id,
            'purchase_price' => 1_000_000, 'purchase_date' => now()->subDays(30)->toDateString(),
            'sale_price' => 9_000_000, 'sale_date' => now()->subDays(20)->toDateString(),
        ]);
        $v->refresh()->refreshProgressCache();

        $this->assertTrue(Vehicle::query()->action('purchase_unpaid')->where('id', $v->id)->exists(),
            '내수 차의 매입 미지급이 재무 큐에서 사라졌다');
        $this->assertTrue(Vehicle::query()->action('sale_unpaid')->where('id', $v->id)->exists(),
            '내수 차의 판매 미입금이 채권 큐에서 사라졌다');
    }

    /** 🔒 돈 큐가 제외 목록에 섞여 들어가지 못하게 — 정적 검사(§8 #103 「빼는 규칙」). */
    public function test_the_exclusion_list_never_contains_a_money_queue(): void
    {
        $ref = new \ReflectionClass(Vehicle::class);
        $list = $ref->getConstant('NOT_FOR_DOMESTIC');

        $this->assertIsArray($list);
        $this->assertSame($ref->getConstant('NOT_FOR_CANCELLED'), $list,
            '매입취소 목록과 갈렸다 — 갈라야 한다면 이 단언을 지우고 이유를 상수 주석에 적을 것');
        foreach ([
            'purchase_unpaid', 'purchase_unpaid_all', 'purchase_balance_due', 'sale_unpaid',
            'receivable_before_shipping', 'receivable_after_shipping',
            'exchange_rate_missing',
        ] as $money) {
            $this->assertNotContains($money, $list, "돈 큐 {$money} 가 내수 제외 목록에 들어갔다");
        }
    }

    /**
     * 🗑️ 「말소 필요」 토글과 말소 재촉 알림톡도 **같은 출처를 지나므로 자동으로** 빠진다.
     *    (조건을 두 곳에 적지 않는다는 증거 — §8 #44)
     */
    public function test_the_toggle_and_the_reminder_inherit_the_exclusion(): void
    {
        $domestic = $this->paidVehicle(true);
        $export = $this->paidVehicle(false);

        $due = Vehicle::query()->action('deregistration_due')->pluck('id')->all();
        $this->assertNotContains($domestic->id, $due, '내수 차가 「말소 필요」 토글에 남아 있다');
        $this->assertContains($export->id, $due, '대조군이 토글에서 빠졌다 — 조건을 과하게 좁혔다');

        $reminder = fn () => Vehicle::query()->action('deregistration_needed')
            ->where('is_deregistered', false)
            ->where(fn ($q) => $q->whereNull('container_number')->orWhere('container_number', ''))
            ->where(fn ($q) => $q->whereNull('export_declaration_number')->orWhere('export_declaration_number', ''))
            ->whereNotNull('buyer_id')
            ->pluck('id')->all();

        $this->assertNotContains($domestic->id, $reminder(), '내수 차가 말소 재촉 알림톡 대상에 남아 있다');
        $this->assertContains($export->id, $reminder(), '대조군이 알림톡 대상에서 빠졌다');
    }

    /** 🔒 카운트와 목록이 함께 움직인다 — 갈리면 「카드엔 있는데 눌러도 없는」 화면이 된다(§8 #44). */
    public function test_dashboard_count_and_list_agree_after_the_exclusion(): void
    {
        $this->paidVehicle(true);
        $export = $this->paidVehicle(false);

        $ids = Vehicle::query()->whereNull('deleted_at')->action('deregistration_needed')->pluck('id')->all();

        $this->assertSame([$export->id], $ids);
        $this->assertSame(count($ids),
            Vehicle::query()->whereNull('deleted_at')->action('deregistration_needed')->count(),
            '카운트와 목록이 갈렸다');
    }

    /**
     * 🔒 **scope ↔ accessor 일치** — SQL 과 PHP 판정이 갈리면 「목록엔 없는데 뱃지는 내수」가 된다.
     *    바이어 없음·외화 내수까지 네 갈래를 전부 같은 답으로 맞춘다.
     */
    public function test_scope_and_accessor_agree(): void
    {
        $domestic = $this->paidVehicle(true);
        $export = $this->paidVehicle(false);
        $noBuyer = Vehicle::create([
            'vehicle_number' => '88누8888', 'sales_channel' => 'export', 'currency' => 'EUR',
            'exchange_rate' => 1746, 'purchase_price' => 1_000_000,
        ]);
        // 내수 바이어인데 아직 외화 — jin 2026-09-16 의 정상 작업 순서. 그 사이에도 말소 재촉은 안 가야 한다.
        $awaitingKrw = $this->paidVehicle(true, ['currency' => 'EUR', 'exchange_rate' => 1746, 'sale_price' => 6_000]);

        foreach ([$domestic, $export, $noBuyer, $awaitingKrw] as $v) {
            $this->assertSame(
                $v->isDomesticSale(),
                Vehicle::query()->domesticSale()->where('id', $v->id)->exists(),
                "scope 와 accessor 가 갈렸다 (차량 {$v->vehicle_number})"
            );
            $this->assertSame(
                ! $v->isDomesticSale(),
                Vehicle::query()->notDomesticSale()->where('id', $v->id)->exists(),
                "여집합이 아니다 (차량 {$v->vehicle_number})"
            );
        }

        $this->assertTrue($awaitingKrw->isDomesticAwaitingKrw(), '외화 내수 픽스처가 그 상태가 아니다');
        $this->assertFalse(Vehicle::query()->action('deregistration_needed')->where('id', $awaitingKrw->id)->exists(),
            '통화 정리 전 내수 차가 말소 큐에 남아 있다 — 통화를 보는 판정을 쓴 것');
    }

    /**
     * 🗑️ **바이어를 지워도 내수는 내수다** — accessor 가 `withTrashed` 로 읽으므로 scope 도 그래야 한다.
     *    `deleted_at` 을 거르면 바이어 삭제 순간 그 차가 조용히 수출 큐로 돌아온다.
     */
    public function test_a_soft_deleted_domestic_buyer_still_excludes_the_vehicle(): void
    {
        $domestic = $this->paidVehicle(true);

        Buyer::find($domestic->buyer_id)->delete();
        $domestic->refresh();

        $this->assertTrue($domestic->isDomesticSale(), 'accessor 가 삭제된 바이어를 못 읽었다');
        $this->assertFalse(Vehicle::query()->action('deregistration_needed')->where('id', $domestic->id)->exists(),
            '바이어를 지우자 내수 차가 말소 큐로 돌아왔다');
    }
}
