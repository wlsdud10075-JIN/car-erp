<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Vehicle;
use App\Services\Documents\DocumentFiller;
use App\Services\Documents\StampSlots;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Calculation\Calculation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use Tests\TestCase;

/**
 * Proforma Invoice 다중차량 (jin 2026-07-31) — 슬롯 18~47(30대) · 푸터 53~63.
 *
 * ⚠️ 매핑 배열만 검사하는 테스트로는 **푸터 좌표가 틀려도 통과한다.** 여기서는 실제로 문서를 생성해
 *    셀 값을 읽는다. `fillMulti` 는 aggregates 를 removeRow **전에** 기입하므로(SKILLS §12),
 *    좌표가 한 칸이라도 어긋나면 값이 엉뚱한 칸에 앉거나 트림 구간과 함께 사라진다.
 */
class SalesInvoiceLayoutTest extends TestCase
{
    use RefreshDatabase;

    private const FIRST = 18;

    private const SLOTS = 30;

    private const COMMISSION = 53;

    private const AUTO_LOADING = 54;

    private const TAX_DC = 55;

    private const SUB_TOTAL = 56;

    /** 폐기된 DEPOSIT 행 — 라벨이 남아 있으면 안 된다. */
    private const DEPOSIT_LABELS = [57, 58];

    private const TOTAL = 60;

    private const BALANCE = 63;

    /** @return Collection<int, Vehicle> */
    private function makeVehicles(int $count, string $currency = 'USD'): Collection
    {
        $buyer = Buyer::create(['name' => 'GYSII AUTO', 'is_active' => true]);

        return collect(range(1, $count))->map(function (int $i) use ($buyer, $currency) {
            return Vehicle::create([
                'vehicle_number' => '12가'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'sales_channel' => 'export',
                'brand' => 'HYUNDAI', 'model_type' => 'TUCSON',
                'nice_reg_vin' => 'KMHJ581ABGU10'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'currency' => $currency, 'exchange_rate' => 1400,
                'sale_date' => '2026-07-01', 'buyer_id' => $buyer->id,
                'sale_price' => 1000 * $i,
                'transport_fee' => 100 * $i,
                'commission' => 10 * $i,
                'auto_loading' => 5 * $i,
                'tax_dc' => 2 * $i,
            ])->fresh();
        });
    }

    private function sheet(Collection $vehicles)
    {
        $ss = (new DocumentFiller($vehicles))->spreadsheet('invoice');
        Calculation::getInstance($ss)->clearCalculationCache();

        return $ss->getSheetByName('Invoice');
    }

    /** 미사용 슬롯이 트림되므로 푸터는 (30 − 대수)만큼 위로 올라온다. */
    private function row(int $templateRow, int $count): int
    {
        return $templateRow - (self::SLOTS - $count);
    }

    /** 푸터 라벨은 양식에서 RichText 다 — 평문으로 읽어 비교한다. */
    private function label($sheet, string $coord): ?string
    {
        $value = $sheet->getCell($coord)->getValue();

        return $value instanceof RichText ? $value->getPlainText() : $value;
    }

    /** Σ(FOB + 운임 + COMMISSION + AUTO − TAX) — 1..n 에 대해 1113·i 의 합. */
    private function expectedSubTotal(int $count): float
    {
        return 1113.0 * ($count * ($count + 1) / 2);
    }

    public function test_slots_and_footer_land_on_expected_cells(): void
    {
        $sheet = $this->sheet($this->makeVehicles(3));

        // 슬롯 — A Code / B Maker / C Model / D Chassis / E FOB / F Shipping
        $this->assertSame('12가0001', $sheet->getCell('A'.self::FIRST)->getValue(), 'Code = 한글 차량번호 그대로');
        $this->assertSame('HYUNDAI', $sheet->getCell('B'.self::FIRST)->getValue());
        $this->assertSame('TUCSON', $sheet->getCell('C'.self::FIRST)->getValue());
        $this->assertStringStartsWith('KMHJ581ABGU10', (string) $sheet->getCell('D'.self::FIRST)->getValue());
        $this->assertEquals(1000, $sheet->getCell('E'.self::FIRST)->getValue(), 'FOB');
        $this->assertEquals(100, $sheet->getCell('F'.self::FIRST)->getValue(), 'Shipping cost');

        // 3번째 슬롯까지 채워졌나
        $this->assertSame('12가0003', $sheet->getCell('A'.(self::FIRST + 2))->getValue());
        $this->assertEquals(3000, $sheet->getCell('E'.(self::FIRST + 2))->getValue());

        // 푸터 값 — commission 10i / auto 5i / tax 2i (i=1..3)
        $sub = $this->expectedSubTotal(3);   // 6678
        $this->assertEquals(60, $sheet->getCell('E'.$this->row(self::COMMISSION, 3))->getValue(), 'Σ COMMISSION');
        $this->assertEquals(30, $sheet->getCell('E'.$this->row(self::AUTO_LOADING, 3))->getValue(), 'Σ AUTO LODING');
        $this->assertEquals(-12, $sheet->getCell('E'.$this->row(self::TAX_DC, 3))->getValue(), 'TAX D/C 는 음수(할인)');
        $this->assertEquals($sub, $sheet->getCell('E'.$this->row(self::SUB_TOTAL, 3))->getValue(), 'SUB TOTAL');
        $this->assertEquals($sub, $sheet->getCell('E'.$this->row(self::TOTAL, 3))->getValue(), 'TOTAL');
        $this->assertEquals($sub, $sheet->getCell('E'.$this->row(self::BALANCE, 3))->getValue(), 'BALANCE MONEY');

        // 라벨도 같이 올라왔는지 — 값만 맞고 라벨이 어긋나면 인쇄물이 뒤죽박죽이 된다.
        $this->assertSame('COMMISSION', $this->label($sheet, 'C'.$this->row(self::COMMISSION, 3)));
        $this->assertSame('SUB TOTAL', $this->label($sheet, 'C'.$this->row(self::SUB_TOTAL, 3)));
        $this->assertSame('TOTAL', $this->label($sheet, 'C'.$this->row(self::TOTAL, 3)));
        $this->assertSame(' BALANCE MONEY', $this->label($sheet, 'C'.$this->row(self::BALANCE, 3)));
    }

    /**
     * 🚨 푸터는 전부 '값'이어야 한다. 수식이 남으면 removeRow 가 range 를 축소하지 않아
     *    (SKILLS §12 실측) SUB TOTAL 이 자기 자신을 삼키는 순환참조가 된다.
     */
    public function test_footer_cells_are_values_not_formulas(): void
    {
        $sheet = $this->sheet($this->makeVehicles(3));

        foreach ([self::SUB_TOTAL, self::TOTAL, self::BALANCE] as $templateRow) {
            $coord = 'E'.$this->row($templateRow, 3);
            $value = $sheet->getCell($coord)->getValue();
            $this->assertIsNumeric($value, "{$coord}: 푸터에 수식이 남았다 — removeRow 로 깨진다");
        }
    }

    /** 폐기된 DEPOSIT 행 라벨이 양식에 남아 있으면 인쇄물에 빈 DEPOSIT 줄이 뜬다. */
    public function test_deposit_row_labels_are_gone(): void
    {
        $sheet = $this->sheet($this->makeVehicles(3));

        foreach (self::DEPOSIT_LABELS as $templateRow) {
            $coord = 'C'.$this->row($templateRow, 3);
            $this->assertNull($sheet->getCell($coord)->getValue(), "{$coord}: DEPOSIT 라벨 잔존");
        }
    }

    /** 1대 발급(서류탭 버튼) — 종전 단일차량 출력과 같은 숫자여야 한다. */
    public function test_single_vehicle_matches_previous_output(): void
    {
        $sheet = $this->sheet($this->makeVehicles(1));

        $this->assertSame('12가0001', $sheet->getCell('A'.self::FIRST)->getValue());
        $this->assertEquals(10, $sheet->getCell('E'.$this->row(self::COMMISSION, 1))->getValue());
        $this->assertEquals(5, $sheet->getCell('E'.$this->row(self::AUTO_LOADING, 1))->getValue());
        $this->assertEquals(-2, $sheet->getCell('E'.$this->row(self::TAX_DC, 1))->getValue());
        // 1000 + 100 + 10 + 5 − 2
        $this->assertEquals(1113, $sheet->getCell('E'.$this->row(self::SUB_TOTAL, 1))->getValue());
        $this->assertNull($sheet->getCell('A'.(self::FIRST + 1))->getValue(), '남은 슬롯이 트림돼야 한다');
    }

    /** TAX D/C 가 0 이면 종전대로 빈칸(라벨만) — `$0` 이 찍히면 안 된다. */
    public function test_zero_tax_dc_stays_blank(): void
    {
        $buyer = Buyer::create(['name' => 'GYSII AUTO', 'is_active' => true]);
        $v = Vehicle::create([
            'vehicle_number' => '12가9999', 'sales_channel' => 'export',
            'currency' => 'USD', 'exchange_rate' => 1400,
            'sale_date' => '2026-07-01', 'buyer_id' => $buyer->id,
            'sale_price' => 5000, 'tax_dc' => 0,
        ]);

        $sheet = $this->sheet(collect([$v->fresh()]));

        $this->assertNull($sheet->getCell('E'.$this->row(self::TAX_DC, 1))->getValue(), 'TAX D/C 0 은 빈칸');
        $this->assertEquals(0, $sheet->getCell('E'.$this->row(self::COMMISSION, 1))->getValue(), 'COMMISSION 0 은 $0');
    }

    public function test_full_thirty_slots_fit(): void
    {
        $sheet = $this->sheet($this->makeVehicles(30));

        $this->assertEquals(30000, $sheet->getCell('E'.(self::FIRST + 29))->getValue(), '30번째 슬롯 FOB');
        $this->assertSame('SUB TOTAL', $this->label($sheet, 'C'.self::SUB_TOTAL), '30대면 트림 없음');
        $this->assertEquals($this->expectedSubTotal(30), $sheet->getCell('E'.self::SUB_TOTAL)->getValue());
    }

    public function test_currency_format_follows_sale_currency(): void
    {
        foreach (['EUR' => '€', 'JPY' => '¥', 'KRW' => '₩'] as $cur => $symbol) {
            $sheet = $this->sheet($this->makeVehicles(2, $cur));
            foreach (['E'.self::FIRST, 'F'.self::FIRST, 'E'.$this->row(self::BALANCE, 2)] as $coord) {
                $fmt = $sheet->getStyle($coord)->getNumberFormat()->getFormatCode();
                $this->assertStringContainsString($symbol, $fmt, "{$cur} 서식에 {$symbol} 없음 ({$coord}: {$fmt})");
                $this->assertStringNotContainsString('$', $fmt, "{$cur} 인데 달러 기호가 남음 ({$coord})");
            }
        }
    }

    public function test_usd_keeps_dollar_format(): void
    {
        $sheet = $this->sheet($this->makeVehicles(2, 'USD'));
        $fmt = $sheet->getStyle('E'.self::FIRST)->getNumberFormat()->getFormatCode();

        $this->assertStringContainsString('$', $fmt);
        $this->assertStringNotContainsString('€', $fmt, 'USD 인보이스에 유로가 나오면 안 된다');
    }

    public function test_stamp_anchor_matches_baked_drawing_in_every_tenant(): void
    {
        // 🚨 `removeDrawingsAt` 은 **정확히 같은 앵커**의 도장만 지운다. 슬롯을 30행 늘리면서
        //    baked 직인이 B36 → B65 로 밀렸으므로 StampSlots 앵커도 함께 옮겨야 한다.
        //    어긋나면 업로드 직인이 baked 위에 겹쳐 **이중 도장**이 된다(SKILLS §8 #37 ③).
        //    GD 없이도 돌도록 이미지 업로드 대신 좌표만 정적 대조한다.
        foreach (['system', 'heyman', 'karaba'] as $set) {
            $slots = StampSlots::for('invoice', $set);
            $this->assertNotEmpty($slots, "{$set}: invoice 도장 슬롯이 없다");

            $sheet = IOFactory::load(resource_path("templates/{$set}/sales_invoice.xlsx"))->getSheetByName('Invoice');

            $baked = [];
            foreach ($sheet->getDrawingCollection() as $d) {
                $baked[] = $d->getCoordinates();
            }

            foreach ($slots as $slot) {
                $this->assertContains(
                    $slot['anchor'],
                    $baked,
                    "{$set}: StampSlots 앵커 {$slot['anchor']} 에 baked 도장이 없다 — 이중 도장이 된다. "
                    .'양식 실제 위치: '.implode(',', $baked)
                );
            }
        }
    }

    /**
     * 🚨 도장이 트림과 **함께 올라오는지** — 인보이스는 이번에 처음 removeRow 경로에 들어갔다.
     *    applyStamps 는 fillMulti 앞에서 돌아 직인을 B65 에 놓고, 그 뒤 트림이 (30−n)행을 지운다.
     *    함께 안 움직이면 인쇄물에서 직인만 저 아래 빈 칸에 남는다.
     *    baked 직인이 이미 양식에 있으므로 업로드(GD) 없이 생성만 해서 좌표를 잴 수 있다.
     */
    public function test_baked_seal_moves_up_with_trim(): void
    {
        foreach ([1, 3, 30] as $count) {
            $sheet = $this->sheet($this->makeVehicles($count));

            $coords = [];
            foreach ($sheet->getDrawingCollection() as $d) {
                $coords[] = $d->getCoordinates();
            }

            $expected = 'B'.$this->row(65, $count);   // 템플릿 B65 → 트림만큼 위로
            $this->assertContains(
                $expected,
                $coords,
                "{$count}대: 직인이 트림과 함께 이동하지 않았다(기대 {$expected}, 실제 ".implode(',', $coords).')'
            );
        }
    }

    /** 양식 3사가 같은 기하를 유지하는지 — 한 곳만 재확장되면 좌표가 어긋난다. */
    public function test_every_tenant_template_has_thirty_slots(): void
    {
        foreach (['system', 'heyman', 'karaba'] as $set) {
            $sheet = IOFactory::load(resource_path("templates/{$set}/sales_invoice.xlsx"))->getSheetByName('Invoice');

            $this->assertSame('COMMISSION', $this->label($sheet, 'C'.self::COMMISSION), "{$set}: COMMISSION 행 위치");
            $this->assertSame('SUB TOTAL', $this->label($sheet, 'C'.self::SUB_TOTAL), "{$set}: SUB TOTAL 행 위치");
            $this->assertSame(' BALANCE MONEY', $this->label($sheet, 'C'.self::BALANCE), "{$set}: BALANCE 행 위치");
            // 마지막 슬롯(47)까지 노란 배경 = 기입 대상. 48 은 슬롯 밖.
            $this->assertSame('FFFFFF00', $sheet->getStyle('E'.(self::FIRST + self::SLOTS - 1))->getFill()->getStartColor()->getARGB(), "{$set}: 30번째 슬롯이 없다");
        }
    }
}
