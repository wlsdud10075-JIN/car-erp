<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Consignee;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Documents\DocValue;
use App\Services\VehicleExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 컨사이니 표시 폴백 회귀 (jin 2026-08-07 제보 — ssancarerp 컨사이니가 통째로 '-').
 *
 * 당사자 축소(jin 2026-07-09)로 판매 탭 컨사이니 입력칸이 제거돼 `vehicles.consignee_id` 는
 * 신규 차량에서 영원히 빈다. 그런데 읽는 쪽 4곳(차량목록·재고목록·재고필터·엑셀)이 그 칸만 보고 있어
 * 컨사이니가 안 보이고 재고 필터는 조용히 0건이 됐다(SKILLS §8 #38 — 삭제한 writer 의 소비자 잔존).
 * 실측 2026-08-07: ssancarerp 14/14 · heymanerp 07-09 이후 59/59 가 빈칸.
 *
 * 이 테스트가 강제하는 것:
 *   1. `Vehicle::effective_consignee` = 통관 → 선적 → (레거시) 판매 3단 폴백
 *   2. 서류(DocValue)·화면·엑셀이 같은 출처를 본다 — 정의가 갈리면 화면↔서류가 어긋난다
 *   3. 필터(scope)·정렬(COALESCE)이 표시와 같은 우선순위
 *   4. 🔒 정적 — 목록 뷰가 `$v->consignee` 를 직접 읽는 형태로 되돌아가지 못하게
 */
class EffectiveConsigneeFallbackTest extends TestCase
{
    use RefreshDatabase;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function consignee(string $name): Consignee
    {
        $buyer = Buyer::firstOrCreate(['name' => 'ECF BUYER'], ['is_active' => true]);

        return Consignee::create(['buyer_id' => $buyer->id, 'name' => $name, 'is_active' => true]);
    }

    private function vehicle(array $attrs = []): Vehicle
    {
        return Vehicle::create(array_merge([
            'vehicle_number' => 'ECF-'.++$this->counter,
            'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'dhl_request' => false,
        ], $attrs));
    }

    public function test_fallback_priority_is_export_then_bl_then_sale(): void
    {
        $exp = $this->consignee('EXPORT CONS');
        $bl = $this->consignee('BL CONS');
        $sale = $this->consignee('SALE CONS');

        $all = $this->vehicle([
            'export_consignee_id' => $exp->id, 'bl_consignee_id' => $bl->id, 'consignee_id' => $sale->id,
        ]);
        $this->assertSame('EXPORT CONS', $all->effective_consignee?->name);

        // 선적까지만 입력된 상태(통관 이어받기 전) — 지금 신규 차량의 실제 모습
        $shipped = $this->vehicle(['bl_consignee_id' => $bl->id, 'consignee_id' => $sale->id]);
        $this->assertSame('BL CONS', $shipped->effective_consignee?->name);

        // 07-09 이전 레거시 차량 — 판매 칸에만 값이 있다(heymanerp 76대). 이 폴백을 지우면 이들이 안 보인다.
        $legacy = $this->vehicle(['consignee_id' => $sale->id]);
        $this->assertSame('SALE CONS', $legacy->effective_consignee?->name);

        $this->assertNull($this->vehicle()->effective_consignee);
    }

    public function test_documents_use_the_same_source_as_screens(): void
    {
        $bl = $this->consignee('DOC CONS');
        $v = $this->vehicle(['bl_consignee_id' => $bl->id]);

        $this->assertSame(
            $v->effective_consignee?->id,
            DocValue::invoiceConsignee($v)?->id,
            '서류와 화면의 컨사이니 정의가 갈렸다 — 같은 차량이 서로 다른 컨사이니로 인쇄된다'
        );
    }

    public function test_scope_matches_consignee_in_any_of_the_three_columns(): void
    {
        $c = $this->consignee('FILTER CONS');
        $other = $this->consignee('OTHER CONS');

        $byExport = $this->vehicle(['export_consignee_id' => $c->id]);
        $byBl = $this->vehicle(['bl_consignee_id' => $c->id]);
        $bySale = $this->vehicle(['consignee_id' => $c->id]);
        $unrelated = $this->vehicle(['bl_consignee_id' => $other->id]);

        $ids = Vehicle::whereEffectiveConsignee($c->id)->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$byExport->id, $byBl->id, $bySale->id], $ids);
        $this->assertNotContains($unrelated->id, $ids);
    }

    public function test_sort_expression_covers_all_three_columns_in_priority_order(): void
    {
        $this->assertSame(
            'COALESCE(export_consignee_id, bl_consignee_id, consignee_id)',
            Vehicle::effectiveConsigneeSortExpression()
        );
        // 표시 폴백과 정렬 우선순위가 같은 배열에서 나오는지 (한쪽만 바꾸면 정렬이 화면과 어긋난다)
        $this->assertSame(
            ['export_consignee_id', 'bl_consignee_id', 'consignee_id'],
            Vehicle::CONSIGNEE_FALLBACK_COLUMNS
        );
    }

    public function test_excel_export_column_shows_shipping_consignee(): void
    {
        $bl = $this->consignee('XLSX CONS');
        $v = $this->vehicle(['bl_consignee_id' => $bl->id]);

        $sheet = (new VehicleExportService)
            ->build(Vehicle::whereKey($v->id)->get(), ['vehicle_number', 'consignee'])
            ->getActiveSheet();

        $flat = [];
        foreach ($sheet->getRowIterator() as $row) {
            foreach ($row->getCellIterator() as $cell) {
                $flat[] = (string) $cell->getValue();
            }
        }

        $this->assertContains('XLSX CONS', $flat, '엑셀 컨사이니 열이 판매 칸만 보고 있어 빈칸으로 나간다');
    }

    public function test_inventory_consignee_filter_finds_shipping_consignee(): void
    {
        $admin = User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]);
        $sm = Salesman::firstOrCreate(['name' => 'ECFMAN'], ['type' => 'employee', 'is_active' => true]);
        $buyer = Buyer::firstOrCreate(['name' => 'ECF BUYER'], ['is_active' => true]);
        $cons = $this->consignee('INV CONS');

        // 재고(매입 완납 + 미출고) + 선적 컨사이니만 지정된 차량 = 07-09 이후의 정상적인 모습
        $v = $this->vehicle([
            'salesman_id' => $sm->id, 'buyer_id' => $buyer->id, 'bl_consignee_id' => $cons->id,
            'purchase_date' => '2026-04-01', 'purchase_price' => 1_000_000,
        ]);
        $v->purchaseBalancePayments()->create([
            'amount' => 1_000_000, 'payment_date' => '2026-04-10', 'confirmed_at' => now(),
        ]);
        $v->refreshCaches();

        Volt::actingAs($admin)->test('erp.inventory.index')
            ->set('buyerFilter', (string) $buyer->id)
            ->set('consigneeFilter', (string) $cons->id)
            ->assertSee($v->vehicle_number);
    }

    /**
     * 🔒 재발 방지 — 목록 뷰가 판매 칸(`$v->consignee`)을 직접 읽는 형태로 돌아가면 실패한다.
     * 이 부류는 기능 테스트로 못 잡는다(값이 비어도 화면은 정상 렌더되고 '-' 만 뜬다).
     */
    public function test_list_views_read_the_fallback_not_the_raw_sale_column(): void
    {
        $views = [
            'resources/views/livewire/erp/vehicles/index.blade.php',
            'resources/views/livewire/erp/inventory/index.blade.php',
        ];

        foreach ($views as $rel) {
            $src = file_get_contents(base_path($rel));

            $this->assertStringContainsString(
                '$v->effective_consignee?->name',
                $src,
                "{$rel} 의 컨사이니 컬럼이 폴백을 안 쓴다 — 07-09 이후 차량이 전부 '-' 로 보인다"
            );
            $this->assertStringNotContainsString(
                '$v->consignee?->name',
                $src,
                "{$rel} 가 판매 칸을 직접 읽는다 — 그 칸은 당사자 축소(2026-07-09) 이후 아무도 안 채운다"
            );
        }

        $exporter = file_get_contents(base_path('app/Services/VehicleExportService.php'));
        $this->assertStringNotContainsString(
            '$v->consignee?->name',
            $exporter,
            '엑셀 컨사이니 열이 판매 칸을 직접 읽는다 — 화면엔 보이는데 엑셀만 빈칸으로 나간다'
        );
    }
}
