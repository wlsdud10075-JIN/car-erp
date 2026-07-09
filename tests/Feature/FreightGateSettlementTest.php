<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 운임 확정 게이트 (jin 2026-07-09) — 완납이어도 운임이 확정돼야 정산 자동 생성.
 *   FOB → 통과 / CFR+운임비>0 → 통과 / (CFR+운임0 · incoterms NULL) → 대기.
 * 인코텀즈/운임비 확정 시 재트리거. 대기 큐 스코프 + 백필/재정렬 커맨드.
 */
class FreightGateSettlementTest extends TestCase
{
    use RefreshDatabase;

    private int $c = 0;

    private function admin(): User
    {
        return User::factory()->create(['permission' => 'admin', 'role' => '관리', 'email_verified_at' => now()]);
    }

    /** 판매 입력된 export 차량 (완납 아님). 외화(USD) 기본 — 운임 게이트 대상. */
    private function sold(?string $incoterms, int $salePrice = 1_000_000, int $transportFee = 0, string $currency = 'USD'): Vehicle
    {
        $buyer = Buyer::create(['name' => 'B'.++$this->c, 'is_active' => true]);
        $salesman = Salesman::create(['name' => 'S'.$this->c, 'is_active' => true, 'type' => 'employee']);

        return Vehicle::create([
            'vehicle_number' => 'FG'.$this->c, 'sales_channel' => 'export',
            'incoterms' => $incoterms, 'currency' => $currency, 'exchange_rate' => 1,
            'sale_price' => $salePrice, 'transport_fee' => $transportFee,
            'sale_date' => '2026-06-01', 'buyer_id' => $buyer->id, 'salesman_id' => $salesman->id,
        ]);
    }

    /** 완납 잔금 확정. */
    private function payFull(Vehicle $v, int $amount): void
    {
        $v->finalPayments()->create([
            'amount' => $amount, 'type' => 'balance', 'payment_date' => '2026-06-15', 'confirmed_at' => now(),
        ]);
    }

    public function test_fob_full_payment_creates_settlement(): void
    {
        $this->actingAs($this->admin());
        $v = $this->sold('FOB');
        $this->payFull($v, 1_000_000);
        $this->assertSame(1, Settlement::count(), 'FOB 완납 → 정산 생성');
    }

    public function test_cfr_with_freight_creates_settlement(): void
    {
        $this->actingAs($this->admin());
        // CFR + 운임비 20만 → 총판매가 120만. 120만 완납해야 게이트+완납 동시 충족.
        $v = $this->sold('CFR', 1_000_000, 200_000);
        $this->payFull($v, 1_200_000);
        $this->assertSame(1, Settlement::count(), 'CFR+운임>0 완납 → 정산 생성');
    }

    public function test_cfr_zero_freight_held(): void
    {
        $this->actingAs($this->admin());
        $v = $this->sold('CFR', 1_000_000, 0);
        $this->payFull($v, 1_000_000);
        $this->assertSame(0, Settlement::count(), 'CFR+운임0 → 대기 (운임 미확정)');
    }

    public function test_null_incoterms_held(): void
    {
        $this->actingAs($this->admin());
        $v = $this->sold(null, 1_000_000, 0);
        $this->payFull($v, 1_000_000);
        $this->assertSame(0, Settlement::count(), 'incoterms 미입력 → 대기');
    }

    public function test_setting_fob_later_retriggers_settlement(): void
    {
        $this->actingAs($this->admin());
        $v = $this->sold(null, 1_000_000, 0);
        $this->payFull($v, 1_000_000);
        $this->assertSame(0, Settlement::count(), '완납했지만 NULL → 대기');

        // 사람이 인코텀즈 FOB 확정 → 그 저장 시점에 재트리거로 정산 생성.
        $v->refresh();
        $v->incoterms = 'FOB';
        $v->save();
        $this->assertSame(1, Settlement::count(), 'FOB 확정 → 재트리거 정산 생성');
    }

    public function test_krw_domestic_auto_passes_gate(): void
    {
        // 원화 정산(국내판매) — incoterms NULL·운임0 이어도 완납 즉시 정산 (동결 방지).
        $this->actingAs($this->admin());
        $v = $this->sold(null, 1_000_000, 0, 'KRW');
        $this->payFull($v, 1_000_000);
        $this->assertSame(1, Settlement::count(), 'KRW 완납 → 게이트 자동통과, 정산 생성');
    }

    public function test_krw_not_in_awaiting_queue(): void
    {
        $this->actingAs($this->admin());
        $krw = $this->sold(null, 1_000_000, 0, 'KRW');
        $this->payFull($krw, 1_000_000);
        $this->assertNotContains($krw->id, Vehicle::awaitingFreightConfirm()->pluck('id')->all(), '원화는 대기 큐 제외');
    }

    public function test_softdelete_then_fob_recreates_settlement(): void
    {
        // 실제 프로드 경로: reconcile 소프트삭제 → 사람이 FOB 확정 → 재트리거 새 정산 (유니크 인덱스 없음).
        $this->actingAs($this->admin());
        $v = $this->sold(null, 1_000_000, 0);   // 외화 NULL → 대기
        $s = Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => $v->salesman_id,
            'settlement_type' => 'per_unit', 'per_unit_amount' => 100_000, 'settlement_status' => 'pending',
        ]);
        $this->payFull($v, 1_000_000);

        $this->artisan('settlements:reconcile-freight-gate --apply')->assertSuccessful();
        $this->assertSoftDeleted($s);

        $v->refresh();
        $v->incoterms = 'FOB';
        $v->save();   // 재트리거 — 트래시 제외 exists() → 새 정산 insert (FK 인덱스 유니크 아님)

        $this->assertSame(1, Settlement::whereNull('deleted_at')->count(), '살아있는 정산 1건 (새로 생성)');
        $this->assertSame(2, Settlement::withTrashed()->count(), '트래시 1 + 신규 1');
    }

    public function test_awaiting_scope_lists_held_vehicle(): void
    {
        $this->actingAs($this->admin());
        $held = $this->sold(null, 1_000_000, 0);
        $this->payFull($held, 1_000_000);
        $ok = $this->sold('FOB', 1_000_000, 0);
        $this->payFull($ok, 1_000_000);   // 정산 생성됨 → 큐에서 제외

        $ids = Vehicle::awaitingFreightConfirm()->pluck('id')->all();
        $this->assertContains($held->id, $ids, '대기 차량 포함');
        $this->assertNotContains($ok->id, $ids, '정산된 차량 제외');
    }

    public function test_backfill_incoterms_cfr_command(): void
    {
        // 운임비>0 + incoterms NULL → 백필로 CFR 확정.
        $v = $this->sold(null, 1_000_000, 300_000);   // auth 없이 생성됨(정산 자동생성 X)

        $this->artisan('vehicles:backfill-incoterms-cfr')->assertSuccessful();   // dry-run
        $this->assertNull($v->fresh()->incoterms, 'dry-run 은 변경 없음');

        $this->artisan('vehicles:backfill-incoterms-cfr --apply')->assertSuccessful();
        $this->assertSame('CFR', $v->fresh()->incoterms, 'CFR 백필');
    }

    public function test_reconcile_deletes_premature_pending(): void
    {
        // 구 규칙으로 만들어진 조기 pending 정산 (운임 미확정 차량).
        $v = $this->sold(null, 1_000_000, 0);
        $s = Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => $v->salesman_id,
            'settlement_type' => 'per_unit', 'per_unit_amount' => 100_000,
            'settlement_status' => 'pending',
        ]);

        $this->artisan('settlements:reconcile-freight-gate')->assertSuccessful();   // dry-run
        $this->assertDatabaseHas('settlements', ['id' => $s->id, 'deleted_at' => null]);

        $this->artisan('settlements:reconcile-freight-gate --apply')->assertSuccessful();
        $this->assertSoftDeleted($s);   // 조기 pending 정산 소프트삭제 (복구·감사 가능)
    }

    public function test_reconcile_keeps_confirmed_freight_ok(): void
    {
        // FOB(운임 확정) 차량의 confirmed 정산은 유지.
        $v = $this->sold('FOB', 1_000_000, 0);
        $s = Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => $v->salesman_id,
            'settlement_type' => 'per_unit', 'per_unit_amount' => 100_000,
            'settlement_status' => 'confirmed', 'confirmed_at' => now(),
        ]);

        $this->artisan('settlements:reconcile-freight-gate --apply')->assertSuccessful();
        $this->assertNotNull($s->fresh(), '운임 확정 정산은 유지');
    }
}
