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
use Tests\TestCase;

/**
 * 판매계약서 새 레이아웃 (jin 2026-07-29) — 26열·슬롯 22~51·푸터 52~57.
 *
 * ⚠️ 매핑 배열만 검사하는 테스트로는 **푸터 좌표가 틀려도 통과한다.** 여기서는 실제로 문서를 생성해
 *    셀 값을 읽는다. `fillMulti` 는 footerAggregate 를 removeRow **전에** 기입하므로(SKILLS §12),
 *    좌표가 한 칸이라도 어긋나면 값이 엉뚱한 칸에 앉거나 트림 구간과 함께 사라진다.
 */
class SalesContractLayoutTest extends TestCase
{
    use RefreshDatabase;

    private const FIRST = 22;

    private const SUB = 52;

    private const OTHER = 53;

    private const TOTAL = 54;

    private const RECEIVED = 55;

    private const DEPOSIT = 56;

    private const BALANCE = 57;

    /** @return Collection<int, Vehicle> */
    private function makeVehicles(int $count, string $currency = 'EUR'): Collection
    {
        $buyer = Buyer::create(['name' => 'GYSII AUTO', 'is_active' => true]);

        return collect(range(1, $count))->map(function (int $i) use ($buyer, $currency) {
            $v = Vehicle::create([
                'vehicle_number' => '12가'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'sales_channel' => 'export',
                'brand' => 'BENZ', 'model_type' => 'S580',
                'nice_reg_vin' => 'W1K6X7GB8MA00'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'currency' => $currency, 'exchange_rate' => 1400,
                'sale_date' => '2026-07-01', 'buyer_id' => $buyer->id,
                'sale_price' => 1000 * $i,
                'transport_fee' => 100 * $i,
                'commission' => 10 * $i,
                'auto_loading' => 5 * $i,
                'tax_dc' => 2 * $i,
                'savings_used' => 50 * $i,        // Deposit 행
            ]);
            // 확정 입금 → Received 행
            $v->finalPayments()->create([
                'type' => 'balance', 'amount' => 300 * $i,
                'payment_date' => '2026-07-10', 'confirmed_at' => now(),
            ]);

            return $v->fresh();
        });
    }

    private function sheet(Collection $vehicles)
    {
        $ss = (new DocumentFiller($vehicles))->spreadsheet('sales_contract');
        Calculation::getInstance($ss)->clearCalculationCache();

        return $ss->getSheetByName('CONTRACT');
    }

    /**
     * 미사용 슬롯이 removeRow 로 트림되므로 푸터는 (30 − 대수)만큼 **위로 올라온다**.
     * 템플릿 좌표를 그대로 쓰면 30대일 때만 맞는다.
     */
    private function row(int $templateRow, int $count): int
    {
        return $templateRow - (30 - $count);
    }

    public function test_slots_and_footer_land_on_expected_cells(): void
    {
        $vehicles = $this->makeVehicles(3);
        $sheet = $this->sheet($vehicles);

        // 슬롯 — A Code / E Brand / I Model / M Chassis / R FOB / U SHIPPING
        $this->assertSame('12GA0001', $sheet->getCell('A'.self::FIRST)->getValue(), '차량번호 로마자');
        $this->assertSame('BENZ', $sheet->getCell('E'.self::FIRST)->getValue());
        $this->assertSame('S580', $sheet->getCell('I'.self::FIRST)->getValue());
        $this->assertStringStartsWith('W1K6X7GB8MA', (string) $sheet->getCell('M'.self::FIRST)->getValue());
        $this->assertEquals(1000, $sheet->getCell('R'.self::FIRST)->getValue(), 'FOB');
        $this->assertEquals(100, $sheet->getCell('U'.self::FIRST)->getValue(), 'SHIPPING (차량별)');

        // 푸터 값 — sale_price 1000·2000·3000 / transport 100·200·300 / other = (10+5-2)*i = 13i
        $other = 13 * (1 + 2 + 3);            // 78
        $subTotal = 6000 + 600;               // 6600
        $total = $subTotal + $other;          // 6678
        $received = 300 * 6;                  // 1800
        $deposit = 50 * 6;                    // 300

        $this->assertEquals($other, $sheet->getCell('R'.$this->row(self::OTHER, 3))->getValue(), 'Other Charge');
        $this->assertEquals($total, $sheet->getCell('R'.$this->row(self::TOTAL, 3))->getValue(), 'Total = Sub + Other');
        $this->assertEquals($received, $sheet->getCell('R'.$this->row(self::RECEIVED, 3))->getValue(), 'Received');
        $this->assertEquals($deposit, $sheet->getCell('R'.$this->row(self::DEPOSIT, 3))->getValue(), 'Deposit = 적립금');
        $this->assertEquals(
            $total - $received - $deposit,
            $sheet->getCell('R'.$this->row(self::BALANCE, 3))->getValue(),
            'Balance = Total − Received − Deposit (샘플의 +Deposit 는 오류)'
        );

        // 라벨도 같이 올라왔는지 — 값만 맞고 라벨이 어긋나면 인쇄물이 뒤죽박죽이 된다.
        $this->assertSame('Other Charge', $sheet->getCell('M'.$this->row(self::OTHER, 3))->getValue());
        $this->assertSame('Balance Money', $sheet->getCell('M'.$this->row(self::BALANCE, 3))->getValue());
    }

    public function test_sub_total_sums_only_filled_slots(): void
    {
        $sheet = $this->sheet($this->makeVehicles(3));
        $sub = $this->row(self::SUB, 3);

        // footerAggregate 는 채운영역까지만 SUM — 트림 구간을 참조하면 #REF! 가 된다.
        $this->assertSame('=SUM(R22:R24)', $sheet->getCell('R'.$sub)->getValue());
        $this->assertSame('=SUM(U22:U24)', $sheet->getCell('U'.$sub)->getValue());
        $this->assertSame('=SUM(X22:X24)', $sheet->getCell('X'.$sub)->getValue());
    }

    public function test_unused_slots_are_trimmed(): void
    {
        $three = $this->sheet($this->makeVehicles(3));

        // 30슬롯 중 3개만 쓰면 27행이 제거돼 푸터가 위로 올라온다.
        $this->assertSame('Sub Total', $three->getCell('M'.$this->row(self::SUB, 3))->getValue());
        $this->assertLessThan(50, $three->getHighestRow(), '미사용 슬롯이 트림돼야 한다');
        $this->assertNull($three->getCell('A25')->getValue(), '트림된 자리에 빈 슬롯이 남으면 안 된다');
    }

    public function test_full_thirty_slots_fit(): void
    {
        $sheet = $this->sheet($this->makeVehicles(30));

        $this->assertEquals(30000, $sheet->getCell('R'.(self::FIRST + 29))->getValue(), '30번째 슬롯 FOB');
        $this->assertSame('Sub Total', $sheet->getCell('M'.self::SUB)->getValue(), '30대면 트림 없음');
    }

    public function test_currency_format_follows_sale_currency(): void
    {
        // 🚨 원본 샘플은 `[$€-2]` 유로 하드코딩이라 applyCurrency 의 정규식에 걸려 서식이 깨졌다.
        //    `\$` 기반이어야 EUR→€ / JPY→¥ 로 정상 치환된다.
        foreach (['EUR' => '€', 'JPY' => '¥', 'KRW' => '₩'] as $cur => $symbol) {
            $sheet = $this->sheet($this->makeVehicles(2, $cur));
            foreach (['R'.self::FIRST, 'U'.self::FIRST, 'R'.$this->row(self::BALANCE, 2)] as $coord) {
                $fmt = $sheet->getStyle($coord)->getNumberFormat()->getFormatCode();
                $this->assertStringContainsString($symbol, $fmt, "{$cur} 서식에 {$symbol} 없음 ({$coord}: {$fmt})");
                $this->assertStringNotContainsString('$', $fmt, "{$cur} 인데 달러 기호가 남음 ({$coord})");
                $this->assertStringNotContainsString('€€', $fmt, '서식이 깨짐 — [$€-2] 잔재');
            }
        }
    }

    public function test_usd_keeps_dollar_format(): void
    {
        $sheet = $this->sheet($this->makeVehicles(2, 'USD'));
        $fmt = $sheet->getStyle('R'.self::FIRST)->getNumberFormat()->getFormatCode();

        $this->assertStringContainsString('$', $fmt);
        $this->assertStringNotContainsString('€', $fmt, 'USD 계약서에 유로가 나오면 안 된다(원본 샘플의 증상)');
    }

    public function test_stamp_anchor_matches_baked_drawing_in_every_tenant(): void
    {
        // 🚨 `removeDrawingsAt` 은 **정확히 같은 앵커**의 도장만 지운다. 양식에 박힌 baked 도장과
        //    StampSlots 앵커가 어긋나면 업로드 직인이 baked 위에 겹쳐 **이중 도장**이 된다.
        //    (2026-07-29 레이아웃 개편으로 baked 가 B71 → C70 으로 이동했다.)
        //    GD 없이도 돌도록 이미지 업로드 대신 좌표만 정적 대조한다.
        foreach (['system', 'heyman', 'karaba'] as $set) {
            $slots = StampSlots::for('sales_contract', $set);
            $this->assertNotEmpty($slots, "{$set}: sales_contract 도장 슬롯이 없다");

            $path = resource_path("templates/{$set}/sales_contract.xlsx");
            $sheet = IOFactory::load($path)->getSheetByName('CONTRACT');

            $baked = [];
            foreach ($sheet->getDrawingCollection() as $d) {
                $baked[] = $d->getCoordinates();
            }

            foreach ($slots as $slot) {
                $this->assertContains(
                    $slot['anchor'],
                    $baked,
                    "{$set}: StampSlots 앵커 {$slot['anchor']} 에 baked 도장이 없다 — 업로드 직인이 겹쳐 이중 도장이 된다. "
                    .'양식 실제 위치: '.implode(',', $baked)
                );
            }
        }
    }

    public function test_no_ghost_values_inside_bank_merges(): void
    {
        // 원본 샘플은 병합 안쪽에 옛 좌표 값이 남아 있었다. 테넌트 파생 시 ssancar 값이 박제되므로 비어야 한다.
        $sheet = $this->sheet($this->makeVehicles(1));

        foreach (['C15', 'G15', 'O15', 'C16', 'G16', 'O16', 'C17', 'G17', 'O17'] as $g) {
            $this->assertEmpty($sheet->getCell($g)->getValue(), "{$g} 에 유령값이 남아 있다");
        }
    }
}
