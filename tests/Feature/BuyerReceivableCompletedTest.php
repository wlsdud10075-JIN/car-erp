<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 바이어 미수 게이지 — 거래완료 분리 (jin 2026-07-18, item 5).
 * 거래완료(progress_status_cache='거래완료')는 분모(진행중 총액)·미수·건수에서 제외하고
 * completed_krw/completed_count 로 별도 반환 → 진행중 실제 미수를 정확히 표시.
 */
class BuyerReceivableCompletedTest extends TestCase
{
    use RefreshDatabase;

    private function vehicle(Buyer $buyer, int $salePrice, string $progress, int $unpaidCache, string $num): Vehicle
    {
        $v = Vehicle::create([
            'vehicle_number' => $num, 'sales_channel' => 'export',
            'buyer_id' => $buyer->id, 'sale_price' => $salePrice,
            'sale_date' => '2026-06-01', 'currency' => 'KRW', 'exchange_rate' => 1,
        ]);
        $v->progress_status_cache = $progress;
        $v->sale_unpaid_amount_krw_cache = $unpaidCache;
        $v->saveQuietly();

        return $v->fresh();
    }

    public function test_completed_excluded_from_active_receivable(): void
    {
        $buyer = Buyer::create(['name' => '딜러A', 'is_active' => true]);
        $this->vehicle($buyer, 10000, '판매중', 6000, '11가1111');    // 진행중, 미수 6000
        $this->vehicle($buyer, 20000, '거래완료', 0, '22나2222');      // 거래완료 — 분리

        $g = Buyer::computeReceivableGauge($buyer->vehicles()->get());

        $this->assertNotNull($g);
        $this->assertSame(10000, $g['total_krw'], '진행중 총액만 (거래완료 20000 제외)');
        $this->assertSame(6000, $g['unpaid_krw']);
        $this->assertSame(1, $g['vehicle_count'], '진행중 1대만');
        $this->assertSame(20000, $g['completed_krw'], '거래완료 총액 별도');
        $this->assertSame(1, $g['completed_count']);
        $this->assertEqualsWithDelta(0.6, $g['ratio'], 0.001, '미수율 = 6000/10000 (진행중 기준)');
    }

    public function test_all_completed_returns_null(): void
    {
        // 진행중 판매 없음(전부 거래완료) → 게이지·게이트 미적용(null).
        $buyer = Buyer::create(['name' => '딜러B', 'is_active' => true]);
        $this->vehicle($buyer, 20000, '거래완료', 0, '33다3333');

        $this->assertNull(Buyer::computeReceivableGauge($buyer->vehicles()->get()));
    }

    public function test_ratio_would_be_diluted_without_exclusion(): void
    {
        // 거래완료를 분모에 넣었다면 미수율 6000/30000=20%로 희석됐을 것 → 제외로 60% 정확 노출.
        $buyer = Buyer::create(['name' => '딜러C', 'is_active' => true]);
        $this->vehicle($buyer, 10000, '판매중', 6000, '44라4444');
        $this->vehicle($buyer, 20000, '거래완료', 0, '55마5555');

        $g = Buyer::computeReceivableGauge($buyer->vehicles()->get());

        $this->assertGreaterThan(Buyer::RECEIVABLE_GATE_THRESHOLD, $g['ratio'],
            '진행중 기준 60% > 게이트 임계 0.5 (희석 시 20%였다면 미발동)');
    }

    /**
     * 선적 후(출고=warehouse_out_date 채워짐)는 진행중 미수에서 제외 — shipped 버킷 분리 (jin 2026-07-20, item 1·3).
     * 매입 등록 락은 "선적 전(국내) 총금액의 50%" 기준이라 이미 출고된 차의 미수는 신규 매입 한도에 안 잡는다.
     */
    public function test_shipped_out_excluded_from_active_receivable(): void
    {
        $buyer = Buyer::create(['name' => '딜러D', 'is_active' => true]);
        $this->vehicle($buyer, 10000, '판매중', 6000, '66바6666');           // 선적 전, 미수 6000
        $shipped = $this->vehicle($buyer, 20000, '선적완료', 15000, '77사7777'); // 출고 → 분리 대상
        $shipped->warehouse_out_date = '2026-06-20';
        $shipped->saveQuietly();

        $g = Buyer::computeReceivableGauge($buyer->vehicles()->get());

        $this->assertNotNull($g);
        $this->assertSame(10000, $g['total_krw'], '선적 전 총액만 (출고 20000 제외)');
        $this->assertSame(6000, $g['unpaid_krw'], '출고 차 미수 15000 은 게이트 미포함');
        $this->assertSame(1, $g['vehicle_count'], '선적 전 1대만');
        $this->assertSame(20000, $g['shipped_krw'], '선적 후 총액 별도');
        $this->assertSame(1, $g['shipped_count']);
        $this->assertEqualsWithDelta(0.6, $g['ratio'], 0.001, '미수율 = 6000/10000 (선적 전 기준)');
    }

    /** 거래완료가 선적 후보다 우선 — 출고됐어도 거래완료면 completed 버킷(shipped 아님). */
    public function test_completed_takes_priority_over_shipped(): void
    {
        $buyer = Buyer::create(['name' => '딜러E', 'is_active' => true]);
        $done = $this->vehicle($buyer, 20000, '거래완료', 0, '88아8888');
        $done->warehouse_out_date = '2026-06-20';   // 출고 + 거래완료 동시
        $done->saveQuietly();

        $g = Buyer::computeReceivableGauge($buyer->vehicles()->get());

        $this->assertNull($g, '전부 거래완료 → 진행중 없음(null)');

        // 진행중 1대 추가해 버킷 분류 확인
        $this->vehicle($buyer, 10000, '판매중', 6000, '99자9999');
        $g2 = Buyer::computeReceivableGauge($buyer->vehicles()->get());
        $this->assertSame(1, $g2['completed_count'], '출고+거래완료는 completed 로 분류');
        $this->assertSame(0, $g2['shipped_count'], 'shipped 로 중복 분류 안 됨');
    }
}
