<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\FinalPayment;
use App\Models\ReceivableHistory;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 판매탭 4항목(계약금·중도금·선수금1·수수료) 정밀도 + 재작성 방지 (jin 2026-09-11).
 *
 * 실사고 = 23로0319 수수료 **6.46 → 6.00**. 저장은 멀쩡했고 **다시 불러올 때** 소수를 버렸는데,
 * 그 값이 편집칸으로 되돌아와 다음 저장이 DB 를 덮었다.
 *
 * 🚨 **단위 테스트로는 원리상 못 잡는다** — 저장 한 번만 보면 6.46 이 정상으로 들어간다.
 *    반드시 **왕복**(저장 → 재로드 → 그대로 재저장)으로 검사해야 드러난다.
 *
 * 지키는 것:
 *   ① 왕복해도 소수가 살아남는다 (수수료·잔금)
 *   ② 1 미만 금액이 행째 사라지지 않는다
 *   ③ 읽기 전용 3항목은 무관한 저장에 **안 움직인다** (2026-07-06 「신규입력 중단」의 전제)
 *   ④ 밖에서 지운 행이 폼의 옛 값으로 **되살아나지 않는다**
 *   ⑤ 2차 정산 마감 차량은 조용히 덮어쓰지 않는다
 *   ⑥ 그래도 사람이 진짜 고치면 고쳐진다 (가드가 과하지 않은지)
 */
class PaymentBreakdownPrecisionTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function finance(): User
    {
        return User::factory()->create([
            'permission' => 'user', 'role' => '재무', 'email_verified_at' => now(),
        ]);
    }

    private function vehicle(string $currency = 'EUR'): Vehicle
    {
        $s = Salesman::create(['name' => 'S'.++$this->n, 'is_active' => true]);
        $b = Buyer::create(['name' => 'B'.$this->n, 'is_active' => true, 'salesman_id' => $s->id]);

        return Vehicle::create([
            'vehicle_number' => '11가'.str_pad((string) (1000 + $this->n), 4, '0', STR_PAD_LEFT),
            'sales_channel' => 'export',
            'currency' => $currency,
            'exchange_rate' => 1400,
            'dhl_request' => false,
            'salesman_id' => $s->id,
            'buyer_id' => $b->id,
            'sale_price' => 50000,
            'sale_date' => now()->toDateString(),
        ]);
    }

    private function panel(): Testable
    {
        return Volt::actingAs($this->finance())->test('erp.vehicles.index');
    }

    private function feeAmount(Vehicle $v): ?float
    {
        $fp = FinalPayment::where('vehicle_id', $v->id)->where('type', 'fee')->first();

        return $fp ? (float) $fp->amount : null;
    }

    // ── ① 왕복해도 소수가 살아남는다 ────────────────────────────────

    public function test_a_decimal_wire_fee_survives_a_save_reload_save_round_trip(): void
    {
        $v = $this->vehicle();

        $c = $this->panel()->call('openEdit', $v->id)->set('fee_str', '6.46')->call('save');
        $this->assertSame(6.46, $this->feeAmount($v), '첫 저장부터 깎였다');

        // 다시 열었을 때 칸에 무엇이 들어오나 — 여기가 실사고 지점이다.
        $c->call('openEdit', $v->id)->assertSet('fee_str', '6.46');

        // 아무것도 안 바꾸고 그대로 저장 — 예전엔 여기서 6.00 으로 덮였다.
        $c->call('save');
        $this->assertSame(6.46, $this->feeAmount($v), '재저장이 소수를 깎았다');
    }

    public function test_an_untouched_save_does_not_rewrite_the_row(): void
    {
        $v = $this->vehicle();

        $c = $this->panel()->call('openEdit', $v->id)->set('fee_str', '6.46')->call('save');
        $before = FinalPayment::where('vehicle_id', $v->id)->where('type', 'fee')->sole();

        $c->call('openEdit', $v->id)->call('save');

        $after = FinalPayment::where('vehicle_id', $v->id)->where('type', 'fee')->sole();
        // 같은 행이어야 한다 — 지우고 다시 만들면 id 가 바뀌고 수금일이 오늘로 리셋된다.
        $this->assertSame($before->id, $after->id, '안 건드렸는데 행이 다시 만들어졌다');
    }

    public function test_a_whole_number_still_shows_without_decimals(): void
    {
        // 표시 회귀 방지 — '6.00' 으로 보이면 사람이 또 손댄다.
        $v = $this->vehicle();

        $this->panel()->call('openEdit', $v->id)->set('fee_str', '6')->call('save')
            ->call('openEdit', $v->id)->assertSet('fee_str', '6');
    }

    // ── ② 1 미만이 사라지지 않는다 ──────────────────────────────────

    public function test_a_fee_below_one_is_not_swallowed(): void
    {
        // 예전: (int) 0.50 = 0 → 칸 '0' → 다음 저장에서 delete 되고 `>0` 이 아니라 **재생성 안 됨**.
        $v = $this->vehicle();

        $c = $this->panel()->call('openEdit', $v->id)->set('fee_str', '0.50')->call('save');
        $this->assertSame(0.5, $this->feeAmount($v));

        $c->call('openEdit', $v->id)->assertSet('fee_str', '0.5')->call('save');

        $this->assertSame(0.5, $this->feeAmount($v), '1 미만 수수료가 통째로 사라졌다');
    }

    // ── ③ 읽기 전용 3항목은 안 움직인다 ─────────────────────────────

    public function test_a_read_only_down_payment_is_untouched_by_an_unrelated_save(): void
    {
        // ssancarerp 실측 125건이 이 형태다 — 소수를 가진 계약금이 무관한 저장에 깎였다.
        $v = $this->vehicle();
        $fp = FinalPayment::create([
            'vehicle_id' => $v->id, 'type' => 'deposit_down', 'amount' => 2839.74,
            'payment_date' => '2026-08-01', 'confirmed_at' => now(),
        ]);

        $this->panel()->call('openEdit', $v->id)
            ->set('memo', '통관 메모만 고친다')
            ->call('save');

        $after = FinalPayment::find($fp->id);
        $this->assertNotNull($after, '무관한 저장이 계약금 행을 지웠다');
        $this->assertSame(2839.74, (float) $after->amount, '계약금이 깎였다');
        $this->assertSame('2026-08-01', $after->payment_date->format('Y-m-d'), '수금일이 오늘로 리셋됐다');
    }

    // ── ④ 밖에서 지운 행이 되살아나지 않는다 ────────────────────────

    public function test_a_row_deleted_elsewhere_does_not_come_back_on_the_next_save(): void
    {
        // 실사고 그대로 — 「저장하고 계속」으로 패널이 **열린 채** 남아 있고, 그 사이 채권관리에서
        // 그 행이 지워졌다. 폼엔 아직 옛 값이 실려 있다.
        //   ⚠️ 일반 save() 는 끝에 close() 라 폼이 비워진다 — 그 경로로 쓰면 가드를 안 지나
        //     테스트가 **헛통과**한다(2026-09-11 에 실제로 그렇게 썼다가 브레이크 테스트로 잡았다).
        $v = $this->vehicle();

        $c = $this->panel()->call('openEdit', $v->id)
            ->set('fee_str', '6.46')
            ->call('saveAndContinue', 'sale');
        $fp = FinalPayment::where('vehicle_id', $v->id)->where('type', 'fee')->sole();
        $this->assertSame('6.46', $c->get('fee_str'), '사전조건 — 패널이 열린 채 값을 들고 있어야 한다');

        // 채권관리에서 미러 행을 지운다 → 연결된 판매잔금까지 같이 사라진다.
        ReceivableHistory::where('final_payment_id', $fp->id)->get()->each->delete();
        $this->assertNull($this->feeAmount($v), '사전조건이 깨졌다 — 채권관리 삭제가 잔금을 안 지웠다');

        $c->call('saveAndContinue', 'sale');   // 폼엔 아직 '6.46' 이 남아 있다

        $this->assertNull($this->feeAmount($v), '지운 수수료가 폼의 옛 값으로 되살아났다');
    }

    // ── ⑤ 2차 정산 마감 차량 ────────────────────────────────────────

    public function test_a_closed_settlement_vehicle_is_not_silently_rewritten(): void
    {
        // 이 sync 는 bulk delete + $allowConfirmedMutation 라 FinalPayment 의 마감 가드를
        // 두 겹으로 우회한다 — 여기서 막지 않으면 마감된 회계가 조용히 다시 쓰인다.
        $v = $this->vehicle();
        $fp = FinalPayment::create([
            'vehicle_id' => $v->id, 'type' => 'fee', 'amount' => 6.46,
            'payment_date' => '2026-08-01', 'confirmed_at' => now(),
        ]);
        Settlement::withoutEvents(fn () => Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => $v->salesman_id, 'settlement_type' => 'ratio',
            'settlement_status' => 'paid', 'secondary_status' => 'closed',
        ]));

        $this->panel()->call('openEdit', $v->id)->set('fee_str', '9.99')->call('save');

        $this->assertSame(6.46, (float) FinalPayment::find($fp->id)->amount, '마감된 차량의 수수료가 덮였다');
    }

    public function test_the_closed_vehicle_tells_the_user_why_nothing_changed(): void
    {
        // 조용히 무시하면 사람은 「저장했는데 안 바뀐다」로 겪는다 (SKILLS §8 #60).
        $v = $this->vehicle();
        FinalPayment::create([
            'vehicle_id' => $v->id, 'type' => 'fee', 'amount' => 6.46,
            'payment_date' => '2026-08-01', 'confirmed_at' => now(),
        ]);
        Settlement::withoutEvents(fn () => Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => $v->salesman_id, 'settlement_type' => 'ratio',
            'settlement_status' => 'paid', 'secondary_status' => 'closed',
        ]));

        $this->panel()->call('openEdit', $v->id)->set('fee_str', '9.99')->call('save')
            ->assertDispatched('notify', function (string $event, array $params) {
                return ($params['type'] ?? '') === 'error'
                    && str_contains($params['message'] ?? '', '2차 정산');
            });
    }

    public function test_clearing_the_field_cannot_delete_a_closed_settlement_row(): void
    {
        // 🚨 여기가 진짜 구멍이다. 금액을 **비우면** 생성이 없어 `FinalPayment::creating` 의
        //    마감 가드가 발동하지 않는데, 삭제는 **bulk** 라 `deleting` 가드도 안 뜬다
        //    (게다가 $allowConfirmedMutation 으로 확정 잠금까지 열어 둔 상태다).
        //    ⇒ 마감된 회계 행이 예외도 감사기록도 없이 사라진다.
        $v = $this->vehicle();
        $fp = FinalPayment::create([
            'vehicle_id' => $v->id, 'type' => 'fee', 'amount' => 6.46,
            'payment_date' => '2026-08-01', 'confirmed_at' => now(),
        ]);
        Settlement::withoutEvents(fn () => Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => $v->salesman_id, 'settlement_type' => 'ratio',
            'settlement_status' => 'paid', 'secondary_status' => 'closed',
        ]));

        $this->panel()->call('openEdit', $v->id)->set('fee_str', '')->call('save');

        $this->assertNotNull(FinalPayment::find($fp->id), '마감된 차량의 확정 수수료가 조용히 삭제됐다');
    }

    // ── ⑥ 진짜 변경은 여전히 통과한다 ───────────────────────────────

    public function test_a_real_edit_still_goes_through(): void
    {
        // 가드가 과하면 「고쳐지지가 않는다」가 된다 — 반대쪽도 박아 둔다.
        $v = $this->vehicle();

        $c = $this->panel()->call('openEdit', $v->id)->set('fee_str', '6.46')->call('save');
        $this->assertSame(6.46, $this->feeAmount($v));

        $c->call('openEdit', $v->id)->set('fee_str', '7.25')->call('save');
        $this->assertSame(7.25, $this->feeAmount($v), '사람이 고친 값이 반영되지 않았다');
    }

    public function test_clearing_the_field_still_removes_the_row(): void
    {
        $v = $this->vehicle();

        $c = $this->panel()->call('openEdit', $v->id)->set('fee_str', '6.46')->call('save');
        $c->call('openEdit', $v->id)->set('fee_str', '')->call('save');

        $this->assertNull($this->feeAmount($v), '칸을 비웠는데 행이 남았다');
    }

    // ── 화면 표시 ───────────────────────────────────────────────────

    public function test_a_locked_payment_row_shows_its_decimals(): void
    {
        // jin 2026-09-11 제보 — DB·입력칸은 6.46 인데 **잠긴 행의 표시만** 6 이었다.
        //   잔금 행은 회수이력 미러가 붙는 순간 잠기므로(= 사실상 전부) 사람이 보는 건 이쪽이다.
        //   `number_format($x)` 는 버리는 게 아니라 **반올림**한다 → 10,434.54 는 10,435 로 보였다.
        $v = $this->vehicle();
        FinalPayment::create([
            'vehicle_id' => $v->id, 'type' => 'fee', 'amount' => 6.46,
            'exchange_rate' => 1564, 'payment_date' => '2026-09-11', 'confirmed_at' => now(),
        ]);
        FinalPayment::create([
            'vehicle_id' => $v->id, 'type' => 'balance', 'amount' => 10434.54,
            'exchange_rate' => 1546.74, 'payment_date' => '2026-09-11', 'confirmed_at' => now(),
        ]);

        $html = $this->panel()->call('openEdit', $v->id)->html();

        $this->assertStringContainsString('6.46', $html, '잠긴 수수료 행이 소수를 안 보여준다');
        $this->assertStringContainsString('10,434.54', $html, '잠긴 잔금 행이 반올림돼 보인다');
    }

    public function test_a_whole_number_row_stays_without_decimals(): void
    {
        // 반대쪽도 박아 둔다 — 원화 잔금이 전부 「1,000,000.00」 이 되면 그것대로 읽기 나쁘다.
        $v = $this->vehicle('KRW');
        FinalPayment::create([
            'vehicle_id' => $v->id, 'type' => 'balance', 'amount' => 1000000,
            'payment_date' => '2026-09-11', 'confirmed_at' => now(),
        ]);

        $html = $this->panel()->call('openEdit', $v->id)->html();

        $this->assertStringContainsString('1,000,000', $html);
        $this->assertStringNotContainsString('1,000,000.00', $html, '정수인데 소수 두 자리가 붙었다');
    }

    public function test_the_sale_summary_shows_decimals(): void
    {
        // jin 2026-09-11 — 판매탭 위쪽 요약(총판매가·입금·미수)도 반올림하고 있었다.
        //   실측 heymanerp 12대가 해당 (예: 미수 125.83 → 126).
        $v = $this->vehicle();
        $v->update(['sale_price' => 6020, 'transport_fee' => 0]);
        FinalPayment::create([
            'vehicle_id' => $v->id, 'type' => 'balance', 'amount' => 5894.17,
            'exchange_rate' => 1400, 'payment_date' => '2026-09-11', 'confirmed_at' => now(),
        ]);

        $html = $this->panel()->call('openEdit', $v->id)->html();

        $this->assertStringContainsString('125.83', $html, '미수가 반올림돼 보인다');
        $this->assertStringContainsString('5,894.17', $html, '입금이 반올림돼 보인다');
    }

    // ── 정적 가드 ───────────────────────────────────────────────────

    public function test_the_breakdown_loader_never_casts_the_sum_to_int(): void
    {
        // 되돌아가도 화면은 정상 렌더되고 저장도 성공한다 — 기능 테스트로는 못 잡는 회귀다.
        $src = file_get_contents(resource_path('views/livewire/erp/vehicles/index.blade.php'));

        $this->assertStringNotContainsString(
            '(string) (int) $sum',
            $src,
            '4항목 합계를 다시 정수로 깎고 있다 — 외화 cents 가 통째로 날아간다(2026-09-11 실사고).',
        );
    }
}
