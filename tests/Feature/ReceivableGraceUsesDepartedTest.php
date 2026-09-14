<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 🚪 **「떠났나」를 묻는 곳은 전부 같은 출처를 봐야 한다** (jin 2026-09-14 승인).
 *
 * 2026-09-14 에 `departed()` 를 4신호(출고일 · B/L 파일 · B/L 발행일 · 선적일)로 넓혔는데
 * **유예(grace) 쪽 세 곳이 안 따라왔다** — 같은 질문에 세 세대가 공존했다:
 *
 *   scopeDeparted / notDeparted          4신호 (09-14)
 *   excludeReceivableGrace · onlyReceivableGrace   2신호 (08-20)
 *   getReceivableRiskComputedAttribute   출고일만 (07-18)
 *
 * ⇒ 선적일만 찍힌 차가 **「선적후 미수(즉시 채권)」이면서 동시에 「결제대기(채권 아님)」** 가 됐다.
 *    설계 규칙(SKILLS §14)은 「선적 후 미수는 유예 없이 즉시 채권」이라 분명히 어긋난다.
 *
 * 🔢 배포 전 3사 실측 **0건**이라 화면이 틀린 적은 없었다 — 0 일 때 고치는 게 제일 싸서 지금 맞췄다.
 *
 * 🚨 **기능 테스트만으로는 재발을 못 막는다** — 조건을 옮겨 적어도 화면은 정상 렌더되고
 *    그 조합의 차가 0대면 아무 증상이 없다. 그래서 **정적 검사**를 함께 둔다.
 */
class ReceivableGraceUsesDepartedTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    /** 유예 창 안(판매 3일 전)에서 미수가 남은 차 — 「떠났나」만 달라지게 만든다. */
    private function vehicle(array $attrs = []): Vehicle
    {
        $s = Salesman::create(['name' => 'S'.++$this->n, 'is_active' => true]);
        $b = Buyer::create(['name' => 'B'.$this->n, 'is_active' => true, 'salesman_id' => $s->id]);

        return Vehicle::create(array_merge([
            'vehicle_number' => '22나'.str_pad((string) (2000 + $this->n), 4, '0', STR_PAD_LEFT),
            'sales_channel' => 'export', 'currency' => 'EUR', 'exchange_rate' => 1400,
            'dhl_request' => false, 'buyer_id' => $b->id, 'salesman_id' => $s->id,
            'sale_price' => 10000, 'sale_date' => now()->subDays(3)->toDateString(),
        ], $attrs));
    }

    /**
     * 🚨 이게 이번 결함 그 자체다 — **선적일만 찍힌 차**.
     * 옛 코드에서는 목록이 「선적후 미수」로 잡는데 위험도는 「결제대기」였다.
     */
    public function test_a_vehicle_that_only_has_a_shipping_date_is_no_longer_in_grace(): void
    {
        $v = $this->vehicle(['shipping_date' => now()->subDay()->toDateString()]);

        $this->assertTrue($v->fresh()->isDeparted(), '전제가 안 선다 — 선적일만으로 떠난 것이어야 한다');
        $this->assertNotSame('grace', $v->fresh()->receivable_risk_computed,
            '떠난 차가 아직 「결제대기」다 — 위험도 분기가 departed 를 안 본다');

        $this->assertTrue(Vehicle::query()->excludeReceivableGrace()->whereKey($v->id)->exists(),
            '떠난 차가 채권 큐에서 유예로 빠졌다');
        $this->assertFalse(Vehicle::query()->onlyReceivableGrace()->whereKey($v->id)->exists(),
            '떠난 차가 「결제대기」 목록에 남아 있다');
    }

    /** B/L 발행일만 있는 차도 같다. */
    public function test_a_bl_issue_date_also_ends_the_grace(): void
    {
        $v = $this->vehicle(['bl_issue_date' => now()->subDay()->toDateString()]);

        $this->assertNotSame('grace', $v->fresh()->receivable_risk_computed);
        $this->assertTrue(Vehicle::query()->excludeReceivableGrace()->whereKey($v->id)->exists());
    }

    /** ⚠️ 아직 안 떠난 차는 **그대로 유예**여야 한다 — 넓히다 멀쩡한 유예를 깨면 독촉이 앞당겨 나간다. */
    public function test_a_vehicle_that_has_not_left_keeps_its_grace(): void
    {
        $v = $this->vehicle();

        $this->assertSame('grace', $v->fresh()->receivable_risk_computed,
            '아직 안 떠난 차의 유예가 사라졌다 — 판매 3일차에 독촉이 나간다');
        $this->assertFalse(Vehicle::query()->excludeReceivableGrace()->whereKey($v->id)->exists());
        $this->assertTrue(Vehicle::query()->onlyReceivableGrace()->whereKey($v->id)->exists());
    }

    /** 🚫 **미래 선적일은 아직 안 떠난 것** — 배를 미리 잡아 뒀다고 유예가 끝나면 안 된다. */
    public function test_a_future_shipping_date_keeps_the_grace(): void
    {
        $v = $this->vehicle(['shipping_date' => now()->addDays(7)->toDateString()]);

        $this->assertSame('grace', $v->fresh()->receivable_risk_computed,
            '다음 주에 실을 차의 유예가 벌써 끝났다');
        $this->assertTrue(Vehicle::query()->onlyReceivableGrace()->whereKey($v->id)->exists());
    }

    /**
     * 두 스코프는 **정확한 여집합**이어야 한다.
     * 갈리면 「채권 큐에도 없고 결제대기에도 없는」 미수가 생겨 아무 화면에도 안 뜬다.
     */
    public function test_the_two_grace_scopes_stay_exact_complements(): void
    {
        $this->vehicle();                                                    // 안 떠남 · 유예 중
        $this->vehicle(['shipping_date' => now()->subDay()->toDateString()]);  // 떠남
        $this->vehicle(['bl_issue_date' => now()->subDay()->toDateString()]);  // 떠남
        $this->vehicle(['warehouse_out_date' => now()->subDay()->toDateString()]);
        $this->vehicle(['shipping_date' => now()->addDays(5)->toDateString()]); // 안 떠남
        $this->vehicle(['sale_date' => now()->subDays(60)->toDateString()]);    // 유예 지남

        $all = Vehicle::count();
        $out = Vehicle::query()->excludeReceivableGrace()->count();
        $in = Vehicle::query()->onlyReceivableGrace()->count();

        $this->assertSame($all, $out + $in, '두 스코프의 합이 전체와 다르다 — 겹치거나 빠진 차가 있다');
    }

    /**
     * 🔒 **정적 검사 — 세 곳이 다시 갈리지 못하게.**
     *
     * 유예를 판정하는 세 지점이 「떠났나」를 **직접 적으면** 실패한다.
     * 조건을 옮겨 적어도 화면은 정상이고 해당 차가 0대면 증상이 없으므로 기능 테스트로는 원리상 못 잡는다.
     */
    public function test_no_grace_decision_spells_out_the_departed_rule_itself(): void
    {
        $src = file_get_contents(base_path('app/Models/Vehicle.php'));

        $blocks = [
            'scopeExcludeReceivableGrace' => 'notDeparted()',
            'scopeOnlyReceivableGrace' => 'notDeparted()',
        ];

        foreach ($blocks as $fn => $needle) {
            $start = strpos($src, "function {$fn}(");
            $this->assertNotFalse($start, "{$fn} 를 못 찾았다 — 이름이 바뀌었으면 이 테스트도 고칠 것");
            $body = substr($src, $start, 700);

            $this->assertStringContainsString($needle, $body,
                "{$fn} 가 단일 출처({$needle})를 안 쓴다");

            foreach (["whereNull('warehouse_out_date')", "whereNull('bl_document')"] as $inlined) {
                $this->assertStringNotContainsString($inlined, $body,
                    "{$fn} 가 「떠났나」 조건을 옮겨 적고 있다 — 넓힐 때 또 갈린다(§8 #97-B)");
            }
        }

        // 위험도 accessor 의 유예 분기
        $start = strpos($src, 'function getReceivableRiskComputedAttribute(');
        $this->assertNotFalse($start);
        $body = substr($src, $start, 1600);

        $this->assertStringContainsString('! $this->isDeparted()', $body,
            '위험도 유예 분기가 isDeparted() 단일 출처를 안 쓴다');
        $this->assertStringNotContainsString('blank($this->warehouse_out_date)', $body,
            '위험도 유예 분기가 아직 출고일만 본다 — 07-18 판이 남아 있다');
    }
}
