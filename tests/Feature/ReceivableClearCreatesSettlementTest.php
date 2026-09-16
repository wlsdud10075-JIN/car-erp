<?php

namespace Tests\Feature;

use App\Console\Commands\BackfillMissingSettlements;
use App\Models\Buyer;
use App\Models\FinalPayment;
use App\Models\ReceivableHistory;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 🧾 **채권 회수로 완납이 되면 정산이 만들어진다** (jin 2026-09-16 제보).
 *
 * 정산 자동생성의 계기는 셋뿐이었다 — 판매 잔금 저장 · 거래완료 진입 · 인코텀즈/운임비 변경.
 * 그런데 미수를 0 으로 만드는 길은 그것만이 아니다. 채권관리에서 짜투리를 「기타」로 털면
 * 미수는 0 이 되는데 **판매 잔금이 안 생기고**(미러링은 「입금」 한 종류뿐), 캐시 갱신은
 * raw update 라 `Vehicle::saved` 도 안 뜬다(SKILLS §8 #43). ⇒ 아무 계기도 안 울린다.
 *
 * 실사고 = ssancarerp `263버8577` — 완납·CFR·운임비 1,939 다 들어갔고 막는 사유가 `[]` 인데
 * 정산이 0 건이었다. 3사 실측 누락 3대(ssancar 1 · heyman 2 · karaba 0).
 *
 * 🚨 기능 테스트가 놓치던 자리다 — 「기타」로 털어도 **미수는 정확히 0 이 되고 화면도 정상**이다.
 *    어긋나는 건 정산 행의 존재뿐이라, 정산을 세지 않으면 영영 안 드러난다.
 */
class ReceivableClearCreatesSettlementTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function finance(): User
    {
        return User::factory()->create([
            'permission' => 'user', 'role' => '재무', 'email_verified_at' => now(),
        ]);
    }

    /** 운영 그대로 — 완납 직전(짜투리만 남음)에서 멈춘 차. */
    private function almostPaid(float $remainder = 255): Vehicle
    {
        $sm = Salesman::create(['name' => 'S'.++$this->n, 'is_active' => true, 'settlement_type' => 'ratio']);
        $b = Buyer::create(['name' => 'B'.$this->n, 'is_active' => true, 'salesman_id' => $sm->id]);

        $v = Vehicle::create([
            'vehicle_number' => '26바'.str_pad((string) (3000 + $this->n), 4, '0', STR_PAD_LEFT),
            'sales_channel' => 'export', 'currency' => 'EUR', 'exchange_rate' => 1628,
            'dhl_request' => false, 'salesman_id' => $sm->id, 'buyer_id' => $b->id,
            'sale_price' => 7_716, 'transport_fee' => 1_939, 'incoterms' => 'CFR',
            'sale_date' => now()->subMonth()->toDateString(),
        ]);

        // 짜투리만 남기고 확정 잔금으로 채운다 — 운영은 계약금 + 잔금 2행이었다.
        FinalPayment::create([
            'vehicle_id' => $v->id, 'type' => 'balance',
            'amount' => $v->sale_total_amount - $remainder,
            'payment_date' => now()->subDays(20)->toDateString(), 'confirmed_at' => now()->subDays(20),
        ]);

        return $v->fresh();
    }

    /** 🚨 이번 결함 그 자체 — 「기타」로 짜투리를 털면 정산이 안 생겼다. */
    public function test_clearing_the_remainder_as_other_creates_the_settlement(): void
    {
        $this->actingAs($this->finance());
        $v = $this->almostPaid();

        $this->assertSame(0, $v->settlements()->count(), '전제가 안 선다 — 아직 정산이 없어야 한다');
        $this->assertGreaterThan(0, $v->sale_unpaid_amount, '전제가 안 선다 — 짜투리가 남아 있어야 한다');

        ReceivableHistory::create([
            'vehicle_id' => $v->id, 'method' => 'other', 'amount' => 255,
            'collected_at' => now()->toDateString(), 'note' => '운임 인상분 회사 비용처리',
        ]);

        $this->assertSame(0, (int) round($v->fresh()->sale_unpaid_amount), '미수가 0 이어야 한다');
        $this->assertSame(1, $v->settlements()->count(),
            '회수로 완납이 됐는데 정산이 안 만들어졌다 — 계기가 다시 빠졌다');
    }

    /** 현금·상계도 같다 — 「입금」만 특별대우하면 사람 눈엔 같은 행위가 다른 결과를 낸다. */
    public function test_cash_and_offset_do_the_same(): void
    {
        foreach (['cash', 'offset'] as $method) {
            $this->actingAs($this->finance());
            $v = $this->almostPaid();

            ReceivableHistory::create([
                'vehicle_id' => $v->id, 'method' => $method, 'amount' => 255,
                'collected_at' => now()->toDateString(),
            ]);

            $this->assertSame(1, $v->settlements()->count(), "{$method} 로 털었을 때 정산이 안 생겼다");
        }
    }

    /** 🚫 **두 번 만들어지지 않는다** — 「입금」은 판매 잔금을 만들어 기존 계기도 함께 울린다. */
    public function test_a_deposit_still_creates_exactly_one_settlement(): void
    {
        $this->actingAs($this->finance());
        $v = $this->almostPaid();

        $h = ReceivableHistory::create([
            'vehicle_id' => $v->id, 'method' => 'deposit', 'amount' => 255,
            'collected_at' => now()->toDateString(),
        ]);
        // 「입금」은 미확정 잔금으로 들어간다 — 재무가 확정해야 미수가 준다(§8 #85).
        FinalPayment::where('id', $h->fresh()->final_payment_id)->update(['confirmed_at' => now()]);
        $v->fresh()->refreshCaches();
        ReceivableHistory::find($h->id)->save();   // 확정 후 같은 경로를 한 번 더 태운다

        $this->assertLessThanOrEqual(1, $v->settlements()->count(), '정산이 두 번 만들어졌다');
    }

    /** ⚠️ 아직 미수가 남으면 만들지 않는다 — 부분 회수로 정산이 새면 안 된다. */
    public function test_a_partial_recovery_does_not_create_a_settlement(): void
    {
        $this->actingAs($this->finance());
        $v = $this->almostPaid(remainder: 1_000);

        ReceivableHistory::create([
            'vehicle_id' => $v->id, 'method' => 'other', 'amount' => 400,
            'collected_at' => now()->toDateString(),
        ]);

        $this->assertGreaterThan(0, $v->fresh()->sale_unpaid_amount);
        $this->assertSame(0, $v->settlements()->count(), '미수가 남았는데 정산이 만들어졌다');
    }

    /** 🚫 로그인 없는 경로(적재·시드·artisan)는 그대로 통과 — 다른 두 계기와 같은 정책이다. */
    public function test_an_unauthenticated_path_still_creates_nothing(): void
    {
        $v = $this->almostPaid();

        ReceivableHistory::create([
            'vehicle_id' => $v->id, 'method' => 'other', 'amount' => 255,
            'collected_at' => now()->toDateString(),
        ]);

        $this->assertSame(0, $v->settlements()->count(),
            '로그인 없는 대량 유입에서 정산이 만들어졌다 — 적재가 정산을 쏟아낸다');
    }

    // ── 백필 도구 ────────────────────────────────────────────────────────

    /**
     * 🚢 **완납인데 이미 배를 탄 차도 백필 후보다** (2026-09-16).
     *
     * v4 cascade 는 선적·통관을 판매완료보다 **먼저** 평가한다 — 그래서 완납된 차의 진행상태는
     * 대부분 `선적완료`·`통관중` 이다. 도구가 `판매완료`·`거래완료` 로 좁혀 놔서 그런 차가
     * 통째로 빠졌다(실사고 `263버8577`).
     */
    public function test_the_backfill_no_longer_skips_shipped_vehicles(): void
    {
        $src = file_get_contents(base_path('app/Console/Commands/BackfillMissingSettlements.php'));

        $this->assertFalse(str_contains($src, "'판매완료', '거래완료'"),
            '백필 도구가 다시 진행상태로 좁힌다 — 완납인데 선적된 차가 후보에서 빠진다');
    }

    /**
     * 🔒 **순수 확대다** (§8 #55) — 구 조건이 뽑던 차는 한 대도 안 빠져야 한다.
     * 넓히는 변경은 새로 넣은 배제 조건이 조용히 깎는 일이 실재한다.
     */
    public function test_widening_the_backfill_never_drops_an_old_candidate(): void
    {
        $this->actingAs($this->finance());

        $shipped = $this->almostPaid();                       // 완납 뒤 선적완료가 될 차
        ReceivableHistory::create([
            'vehicle_id' => $shipped->id, 'method' => 'other', 'amount' => 255,
            'collected_at' => now()->toDateString(),
        ]);
        $shipped->update(['bl_loading_location' => '평택', 'export_declaration_document' => 'x.pdf']);

        $plain = $this->almostPaid();                          // 판매완료로 남을 차
        ReceivableHistory::create([
            'vehicle_id' => $plain->id, 'method' => 'other', 'amount' => 255,
            'collected_at' => now()->toDateString(),
        ]);

        // 구 조건이 뽑던 집합을 테스트 안에서 직접 질의한다.
        $oldWay = Vehicle::query()
            ->whereIn('progress_status_cache', ['판매완료', '거래완료'])
            ->where('sale_price', '>', 0)
            ->whereNotNull('salesman_id')
            ->pluck('id');

        $newWay = Vehicle::query()
            ->where('sale_price', '>', 0)
            ->whereNotNull('salesman_id')
            ->pluck('id');

        foreach ($oldWay as $id) {
            $this->assertTrue($newWay->contains($id), "구 조건이 뽑던 차 #{$id} 가 새 조건에서 빠졌다");
        }
        $this->assertTrue($newWay->contains($shipped->id), '선적된 완납 차가 새 조건에도 없다');
        $this->assertSame('선적완료', $shipped->fresh()->progress_status_cache, '전제가 안 선다');
    }

    /** 명령이 실제로 도는지 — 클래스만 고치고 구문이 깨지면 운영에서야 안다. */
    public function test_the_backfill_command_runs_in_dry_run(): void
    {
        $this->assertTrue(class_exists(BackfillMissingSettlements::class));

        $this->artisan('settlements:backfill-missing')->assertSuccessful();
    }
}
