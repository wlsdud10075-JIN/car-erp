<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\PurchaseBalancePayment;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 큐 10 확장 — G3 미수 분류 테스트.
 * 회의록 docs/meetings/2026-05-14-3way-workflow-policy.md §G3 + 사용자 결정 2026-05-18.
 *
 * 분류 정의 (pivot=출고일, jin 2026-07-18 — 구 pivot=progress_status):
 * - 선적전 미수: warehouse_out_date IS NULL (항구 대기) AND sale_unpaid_amount_krw_cache > 0 (grace 제외)
 * - 선적후 미수: warehouse_out_date IS NOT NULL (출항) AND sale_unpaid_amount_krw_cache > 0
 * - 디파짓: savings_used > 0
 *
 * 검증:
 * - Vehicle::scopeAction 3 액션 SQL 정합
 * - 채권관리 페이지 분류별 카운트
 * - 관리자 대시보드 분류 KPI
 */
class G3ReceivableClassificationTest extends TestCase
{
    use RefreshDatabase;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function makeVehicle(array $overrides = []): Vehicle
    {
        $this->counter++;

        $defaults = [
            'vehicle_number' => 'G3T-'.$this->counter,
            'sales_channel' => 'export',
            'currency' => 'KRW',
            'exchange_rate' => 1,
            'sale_price' => 1000000,
            'dhl_request' => false,
        ];

        // 2026-05-19 풀회의 안건 E — sale_price > 0 시 sale_date·buyer_id 자동 채움.
        $salePrice = $overrides['sale_price'] ?? $defaults['sale_price'];
        if ($salePrice > 0) {
            if (! array_key_exists('buyer_id', $overrides)) {
                $defaults['buyer_id'] = Buyer::firstOrCreate(['name' => 'TEST BUYER'], ['is_active' => true])->id;
            }
            if (! array_key_exists('sale_date', $overrides)) {
                $defaults['sale_date'] = '2026-05-01';
            }
        }

        // 큐 22-A-3 (2026-05-20) — vehicles 4컬럼 DROP. override 키가 있으면 confirmed FP 자동 생성.
        $sale4Map = [
            'deposit_down_payment' => 'deposit_down',
            'interim_payment' => 'interim',
            'advance_payment1' => 'advance_1',
            'advance_payment2' => 'fee',
        ];
        $sale4Inserts = [];
        foreach ($sale4Map as $col => $type) {
            if (array_key_exists($col, $overrides)) {
                if ((float) $overrides[$col] > 0) {
                    $sale4Inserts[] = ['amount' => (float) $overrides[$col], 'type' => $type];
                }
                unset($overrides[$col]);
            }
        }

        // 큐 22-C-E (2026-05-20) — vehicles 2컬럼 DROP. override 키가 있으면 confirmed PBP 자동 생성.
        $purchase2Map = [
            'down_payment' => 'down',
            'selling_fee_payment' => 'selling_fee',
        ];
        $purchase2Inserts = [];
        foreach ($purchase2Map as $col => $type) {
            if (array_key_exists($col, $overrides)) {
                if ((float) $overrides[$col] > 0) {
                    $purchase2Inserts[] = ['amount' => (float) $overrides[$col], 'type' => $type];
                }
                unset($overrides[$col]);
            }
        }

        $v = Vehicle::create(array_merge($defaults, $overrides));

        foreach ($sale4Inserts as $row) {
            $v->finalPayments()->create([
                'amount' => $row['amount'],
                'type' => $row['type'],
                'confirmed_at' => now(),
            ]);
        }
        if (! empty($purchase2Inserts)) {
            PurchaseBalancePayment::$skipCreatingGuard = true;
            try {
                foreach ($purchase2Inserts as $row) {
                    $v->purchaseBalancePayments()->create([
                        'amount' => $row['amount'],
                        'type' => $row['type'],
                        'payment_date' => now()->subDay()->toDateString(),
                        'confirmed_at' => now(),
                    ]);
                }
            } finally {
                PurchaseBalancePayment::$skipCreatingGuard = false;
            }
        }
        if (! empty($sale4Inserts) || ! empty($purchase2Inserts)) {
            $v->refresh();
        }

        return $v;
    }

    public function test_before_shipping_is_unpaid_without_warehouse_out(): void
    {
        // 선적전 = 출고일 없음(항구 대기) + 미수. 진행단계 무관.
        $v1 = $this->makeVehicle();
        $v1->sale_unpaid_amount_krw_cache = 500000;
        $v1->saveQuietly();

        $v2 = $this->makeVehicle();
        $v2->sale_unpaid_amount_krw_cache = 300000;
        $v2->saveQuietly();

        // 출고됨(선적후) — 제외
        $v3 = $this->makeVehicle(['warehouse_out_date' => now()->toDateString()]);
        $v3->sale_unpaid_amount_krw_cache = 700000;
        $v3->saveQuietly();

        // 미수 0 — 제외
        $v4 = $this->makeVehicle();
        $v4->sale_unpaid_amount_krw_cache = 0;
        $v4->saveQuietly();

        $ids = Vehicle::query()->action('receivable_before_shipping')->pluck('id')->toArray();
        $this->assertContains($v1->id, $ids);
        $this->assertContains($v2->id, $ids);
        $this->assertNotContains($v3->id, $ids);
        $this->assertNotContains($v4->id, $ids);
    }

    public function test_after_shipping_is_unpaid_with_warehouse_out(): void
    {
        // 선적후 = 출고일 있음(출항) + 미수.
        $v1 = $this->makeVehicle(['warehouse_out_date' => now()->toDateString()]);
        $v1->sale_unpaid_amount_krw_cache = 100000;
        $v1->saveQuietly();

        $v2 = $this->makeVehicle(['warehouse_out_date' => now()->subDay()->toDateString()]);
        $v2->sale_unpaid_amount_krw_cache = 50000;
        $v2->saveQuietly();

        // 출고 전(선적전) — 제외
        $v3 = $this->makeVehicle();
        $v3->sale_unpaid_amount_krw_cache = 200000;
        $v3->saveQuietly();

        // 미수 0 — 제외 (출고됐어도 미수 없으면 채권 아님)
        $v4 = $this->makeVehicle(['warehouse_out_date' => now()->toDateString()]);
        $v4->sale_unpaid_amount_krw_cache = 0;
        $v4->saveQuietly();

        $ids = Vehicle::query()->action('receivable_after_shipping')->pluck('id')->toArray();
        $this->assertContains($v1->id, $ids);
        $this->assertContains($v2->id, $ids);
        $this->assertNotContains($v3->id, $ids);
        $this->assertNotContains($v4->id, $ids);
    }

    public function test_deposit_classification_includes_savings_used_vehicles(): void
    {
        $v1 = $this->makeVehicle(['savings_used' => 50000]);
        $v2 = $this->makeVehicle(['savings_used' => 0]);
        $v3 = $this->makeVehicle(['savings_used' => 100]);

        $ids = Vehicle::query()->action('deposit_by_buyer')->pluck('id')->toArray();
        $this->assertContains($v1->id, $ids);
        $this->assertNotContains($v2->id, $ids);
        $this->assertContains($v3->id, $ids);
    }

    public function test_classification_sql_matches_receivables_page_query(): void
    {
        // 채권관리 페이지의 buildQuery + classification 분기와 동일 결과 검증 (pivot=출고일)
        $v1 = $this->makeVehicle();   // 출고 전
        $v1->sale_unpaid_amount_krw_cache = 100;
        $v1->saveQuietly();

        $v2 = $this->makeVehicle(['warehouse_out_date' => now()->toDateString()]);   // 출고 후
        $v2->sale_unpaid_amount_krw_cache = 200;
        $v2->saveQuietly();

        // scopeAction 결과
        $scopeBefore = Vehicle::query()->action('receivable_before_shipping')->pluck('id')->sort()->values()->toArray();
        $scopeAfter = Vehicle::query()->action('receivable_after_shipping')->pluck('id')->sort()->values()->toArray();

        // receivables/index 페이지 SQL (동일 출처 — 출고일 pivot + grace 제외)
        $pageBefore = Vehicle::query()
            ->where('sale_price', '>', 0)
            ->whereNull('warehouse_out_date')
            ->where('sale_unpaid_amount_krw_cache', '>', 0)
            ->excludeReceivableGrace()
            ->pluck('id')->sort()->values()->toArray();
        $pageAfter = Vehicle::query()
            ->where('sale_price', '>', 0)
            ->whereNotNull('warehouse_out_date')
            ->where('sale_unpaid_amount_krw_cache', '>', 0)
            ->pluck('id')->sort()->values()->toArray();

        $this->assertSame($scopeBefore, $pageBefore);
        $this->assertSame($scopeAfter, $pageAfter);
    }

    public function test_deposit_excludes_zero_or_null_savings(): void
    {
        $v1 = $this->makeVehicle();   // savings_used default 0
        $v2 = $this->makeVehicle(['savings_used' => 1]);

        $count = Vehicle::query()->action('deposit_by_buyer')->count();
        $this->assertSame(1, $count);

        $this->assertContains($v2->id, Vehicle::query()->action('deposit_by_buyer')->pluck('id')->toArray());
        $this->assertNotContains($v1->id, Vehicle::query()->action('deposit_by_buyer')->pluck('id')->toArray());
    }
}
