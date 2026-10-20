<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\FinalPayment;
use App\Models\ForwardingCompany;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 🚚 거래완료 차 출고일 자동 채움 (jin 2026-10-20 «추천대로») — 선적일 → B/L 발행일 → 거래완료가 된 날.
 *
 * 구: 선적일이 있을 때만 복사 → FOB포워더(바이어가 직접 B/L·선적)·선적일 없이 B/L 만 올라간 차가 출고일 없이
 *     재고 어디에도 안 보였다(실측 heymanerp 3 · ssancarerp 1 · karabaerp 1). 매입취소로 끝난 차는 비워 둔다.
 * 경로 셋 다: 차량 저장 훅 · 입금 저장의 raw 캐시 갱신(refreshCaches) · 야간 rebuild-caches.
 */
class WarehouseOutAutofillTest extends TestCase
{
    use RefreshDatabase;

    private function sold(array $over = []): Vehicle
    {
        $buyer = Buyer::firstOrCreate(['name' => 'OUT BUYER'], ['is_active' => true]);

        return Vehicle::create(array_merge([
            'vehicle_number' => 'OUT-'.fake()->unique()->numberBetween(1000, 9999),
            'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'buyer_id' => $buyer->id, 'sale_date' => '2026-10-01', 'sale_price' => 1_000_000,
            'purchase_price' => 500_000, 'purchase_date' => '2026-09-01',
        ], $over));
    }

    public function test_falls_back_from_shipping_date_to_bl_issue_date_to_today(): void
    {
        $this->assertSame('2026-10-03', $this->sold(['shipping_date' => '2026-10-03', 'bl_issue_date' => '2026-10-09', 'bl_document' => 'bl/a.pdf'])->warehouse_out_date->toDateString(), '선적일 우선');
        $this->assertSame('2026-10-09', $this->sold(['bl_issue_date' => '2026-10-09', 'bl_document' => 'bl/b.pdf'])->warehouse_out_date->toDateString(), '선적일 없으면 B/L 발행일');
        $this->assertSame(now()->toDateString(), $this->sold(['bl_document' => 'bl/c.pdf'])->warehouse_out_date->toDateString(), '둘 다 없으면 거래완료가 된 날');
    }

    public function test_a_human_entered_date_is_kept_and_non_completed_cars_stay_empty(): void
    {
        $this->assertSame('2026-09-30', $this->sold(['bl_document' => 'bl/a.pdf', 'warehouse_out_date' => '2026-09-30'])->warehouse_out_date->toDateString());
        $this->assertNull($this->sold(['bl_loading_location' => '평택'])->warehouse_out_date, '거래완료가 아니면 안 채운다');
    }

    /** FOB포워더 완납은 입금 저장(FinalPayment::saved → refreshCaches raw update)으로 거래완료가 된다 — 훅이 안 뜨는 경로. */
    public function test_fob_forwarder_completion_by_payment_fills_it_too(): void
    {
        $fc = ForwardingCompany::create(['name' => 'FOB포워더', 'is_active' => true, 'buyer_handles_bl' => true]);
        $v = $this->sold(['incoterms' => 'FOB', 'forwarding_company_id' => $fc->id]);
        $this->assertNull($v->warehouse_out_date);

        FinalPayment::create(['vehicle_id' => $v->id, 'amount' => 1_000_000, 'type' => 'balance',
            'payment_date' => '2026-10-15', 'exchange_rate' => 1, 'confirmed_at' => now()]);

        $v->refresh();
        $this->assertSame('거래완료', $v->progress_status_cache);
        $this->assertSame(now()->toDateString(), $v->warehouse_out_date?->toDateString(), '입금으로 거래완료가 된 날이 출고일');
    }

    public function test_cancelled_deals_are_left_empty(): void
    {
        $v = $this->sold(['bl_document' => 'bl/a.pdf']);
        DB::table('vehicles')->where('id', $v->id)->update(['cancel_status' => Vehicle::CANCEL_ACTIVE, 'warehouse_out_date' => null]);
        $v->refresh();
        $this->assertNull($v->autoWarehouseOutDate());
        $v->refreshCaches();
        $this->assertNull($v->fresh()->warehouse_out_date);
    }

    public function test_nightly_rebuild_fills_cars_that_skipped_the_hook(): void
    {
        $v = $this->sold(['bl_document' => 'bl/a.pdf', 'bl_issue_date' => '2026-10-11']);
        DB::table('vehicles')->where('id', $v->id)->update(['warehouse_out_date' => null]);   // 적재(withoutEvents)·raw 경로 재현

        Artisan::call('vehicles:rebuild-caches');

        $this->assertSame('2026-10-11', $v->fresh()->warehouse_out_date->toDateString());
    }
}
