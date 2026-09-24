<?php

namespace Tests\Feature;

use App\Models\Vehicle;
use App\Services\Documents\DocumentFiller;
use App\Services\Documents\DocValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Calculation\Calculation;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MultiVehicleShippingDocumentTest extends TestCase
{
    use RefreshDatabase;

    /** @return Collection<int, Vehicle> */
    private function makeVehicles(int $count): Collection
    {
        return collect(range(1, $count))->map(fn (int $i) => Vehicle::create([
            'vehicle_number' => '12가'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
            'sales_channel' => 'export',
            'brand' => 'BRAND'.$i,
            'model_type' => 'MODEL'.$i,
            'year' => 2010 + $i,
            'nice_reg_vin' => 'VIN000000000'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
            'sale_price' => 1000 * $i,        // 합 검증용
            'transport_fee' => 100 * $i,      // shipping 합 검증용
            // ⚠️ 기타청구 3항이 전부 0 이 아니어야 한다 — 0 이면 「단가에 합산」이 빠져도 합계가 우연히 맞는다
            //    (2026-09-22 까지 이 픽스처가 그 장님 상태였다). 순액 = 30i + 20i − 10i = 40i.
            'commission' => 30 * $i,
            'auto_loading' => 20 * $i,
            'tax_dc' => 10 * $i,
        ]));
    }

    /**
     * 「판매가 열」 기대값 — 선적 4종 전부 단가에 기타청구를 합산한다
     * (인보이스&팩킹 jin 2026-09-22 → 계약서 2026-09-24. 09-23 까지는 계약서 FOB 가 원 판매가 + E47~E49 3줄이었다).
     */
    private static function expectedAmount(string $type, Collection $vehicles): float
    {
        return (float) $vehicles->sum(fn (Vehicle $v) => DocValue::unitPriceWithCharges($v));
    }

    #[DataProvider('shippingTypes')]
    public function test_multi_vehicle_fills_slots_and_sums(string $type, string $sheetName, int $first, int $stride, string $brandCol, string $modelCol): void
    {
        foreach ([1, 3, 7, 12, 30] as $n) {
            $vehicles = $this->makeVehicles($n);
            $expectedAmount = self::expectedAmount($type, $vehicles);

            $ss = (new DocumentFiller($vehicles))->spreadsheet($type);
            $sheet = $ss->getSheetByName($sheetName);
            Calculation::getInstance($ss)->clearCalculationCache();

            // 각 슬롯에 차량 데이터가 정확히 들어갔는지 (첫·마지막 슬롯)
            $this->assertSame('BRAND1', (string) $sheet->getCell($brandCol.$first)->getValue(), "$type N=$n slot1 brand");
            $this->assertSame('MODEL1', (string) $sheet->getCell($modelCol.$first)->getValue(), "$type N=$n slot1 model");
            $lastBase = $first + ($n - 1) * $stride;
            $this->assertSame('BRAND'.$n, (string) $sheet->getCell($brandCol.$lastBase)->getValue(), "$type N=$n last brand");

            // footer 금액 합 = Σ sale_price, #REF 없음
            $footerAmount = $this->footerAmount($sheet, $type, $n);
            $this->assertEquals($expectedAmount, $footerAmount, "$type N=$n amount sum");

            // 전 시트에 #REF! 잔재 없음
            $this->assertNoRefErrors($ss, "$type N=$n");
        }
    }

    public static function shippingTypes(): array
    {
        return [
            // type, sheet, firstRow, stride, brandCol, modelCol
            'container_invoice' => ['container_invoice_packing', 'INVOICE', 21, 3, 'C', 'D'],
            'roro_invoice' => ['roro_invoice_packing', 'INVOICE', 21, 1, 'C', 'D'],
            'container_contract' => ['container_contract', 'HBB340.', 16, 1, 'B', 'C'],
            'roro_contract' => ['roro_contract', 'HBB340.', 16, 1, 'B', 'C'],
        ];
    }

    /** footer 금액 합 계산값 (트림으로 행 이동 — type 별 원본 footer 행에서 removed 차감). */
    private function footerAmount($sheet, string $type, int $n): float
    {
        [$col, $extRow, $first, $stride, $capacity] = match ($type) {
            'container_invoice_packing' => ['I', 111, 21, 3, 30],
            'roro_invoice_packing' => ['I', 51, 21, 1, 30],
            'container_contract', 'roro_contract' => ['I', 46, 16, 1, 30],   // I46 = FOB(sale_price) 합
        };
        $removed = ($capacity - $n) * $stride;

        return (float) $sheet->getCell($col.($extRow - $removed))->getCalculatedValue();
    }

    private function assertNoRefErrors($ss, string $ctx): void
    {
        foreach ($ss->getWorksheetIterator() as $sheet) {
            foreach ($sheet->getCoordinates(false) as $coord) {
                $v = $sheet->getCell($coord)->getValue();
                if (is_string($v) && str_contains($v, '#REF!')) {
                    $this->fail("$ctx: #REF! at {$sheet->getTitle()}!$coord ($v)");
                }
            }
        }
        $this->assertTrue(true);
    }

    /** Xlsx writer 출력이 깨지지 않고(§12 writer "Invalid parameters") 재로드 시 합계 보존되는지. */
    public function test_written_file_reloads_with_correct_sum(): void
    {
        $vehicles = $this->makeVehicles(5);
        $ss = (new DocumentFiller($vehicles))->spreadsheet('roro_invoice_packing');

        $tmp = tempnam(sys_get_temp_dir(), 'shipdoc_').'.xlsx';
        (new Xlsx($ss))->save($tmp);   // preCalc 기본 true — 계산값까지 기록

        $reloaded = IOFactory::load($tmp);
        $sheet = $reloaded->getSheetByName('INVOICE');
        // 트림 후 footer(원본 51 - removed 25 = 26). I열 = Σ(판매가 + 기타청구) = 15000 + 40×15 = 15600
        $this->assertEquals(15600, (float) $sheet->getCell('I26')->getCalculatedValue());

        @unlink($tmp);
    }

    /**
     * 🧾 jin 2026-09-22: «other charge 항목이 판매가에 같이 합산되고, 문서 자체에는 판매가 + 운임비 = 최종금액».
     * 단가(H) = 판매가 + Commission + Auto Loading − TAX D/C 이고, 08-28 에 푸터 여유행에 내던
     * 「OTHER CHARGE」 줄은 없다. GRAND TOTAL 은 SUB TOTAL + OCEAN FREIGHT 그대로다.
     * 푸터 합만 보면 못 잡는다(항이 H 로 옮겨 가도 합은 같다) — 슬롯 단가와 여유행을 직접 본다.
     */
    #[DataProvider('invoicePackingTypes')]
    public function test_unit_price_absorbs_other_charges_and_the_spare_row_stays_empty(string $type, int $first, int $stride, int $spareRow, int $grandRow): void
    {
        $vehicles = $this->makeVehicles(3);
        $sheet = (new DocumentFiller($vehicles))->spreadsheet($type)->getSheetByName('INVOICE');
        $removed = (30 - 3) * $stride;

        // 슬롯 1 단가 = 1000 + (30 + 20 − 10) = 1040 (원 판매가 1000 이 아니다)
        $this->assertEquals(1040.0, (float) $sheet->getCell('H'.$first)->getValue(), "$type 단가에 기타청구가 합산되지 않았다");
        $this->assertEquals(3120.0, (float) $sheet->getCell('H'.($first + 2 * $stride))->getValue());

        // 여유행(OTHER CHARGE 자리)은 라벨도 금액도 없다
        $this->assertSame('', trim((string) $sheet->getCell('F'.($spareRow - $removed))->getValue()), "$type 여유행에 라벨이 남아 있다 — 08-28 「OTHER CHARGE」 줄이 되살아났다");
        $this->assertSame('', trim((string) $sheet->getCell('I'.($spareRow - $removed))->getValue()));

        // GRAND TOTAL = Σ단가 + Σ운임 = (1040+2080+3120) + (100+200+300) = 6840 — 기타청구가 두 번 들어가지 않는다
        $this->assertEquals(6840.0, (float) $sheet->getCell('I'.($grandRow - $removed))->getCalculatedValue(), "$type GRAND TOTAL ≠ 판매가 + 운임");
        $this->assertEquals(DocValue::documentSaleTotal($vehicles), (float) $sheet->getCell('I'.($grandRow - $removed))->getCalculatedValue());
    }

    public static function invoicePackingTypes(): array
    {
        return [
            // type, firstRow, stride, spareRow(원본), grandTotalRow(원본)
            'container_invoice' => ['container_invoice_packing', 21, 3, 113, 114],
            'roro_invoice' => ['roro_invoice_packing', 21, 1, 53, 54],
        ];
    }

    /**
     * 계약서판 (2026-09-24) — FOB(F) 에 기타청구가 합산되고, 08-28 의 여유행 3줄(E47~F49)은 라벨도 값도 없다.
     * TOTAL(F52 = SUM(F46:G51)) 은 3줄이 F열로 흡수돼 숫자가 그대로다 — 두 번 들어가면 여기서 잡힌다.
     */
    #[DataProvider('contractTypes')]
    public function test_contract_fob_absorbs_other_charges_and_the_spare_rows_stay_empty(string $type): void
    {
        $vehicles = $this->makeVehicles(3);
        $sheet = (new DocumentFiller($vehicles))->spreadsheet($type)->getSheetByName('HBB340.');
        $removed = 30 - 3;   // stride 1, first 16 → 푸터가 27행 위로

        $this->assertEquals(1040.0, (float) $sheet->getCell('F16')->getValue(), "$type FOB 에 기타청구가 합산되지 않았다");
        $this->assertEquals(3120.0, (float) $sheet->getCell('F18')->getValue());

        foreach ([47, 48, 49] as $r) {
            $this->assertSame('', trim((string) $sheet->getCell('E'.($r - $removed))->getValue()), "$type 여유행 {$r} 에 라벨이 남아 있다 — 08-28 3줄을 되살리지 말 것");
            $this->assertSame('', trim((string) $sheet->getCell('F'.($r - $removed))->getValue()));
        }

        // I46 FOB 합 = 1040+2080+3120 = 6240 · F52 TOTAL = FOB + 운임 = 6240 + 600 = 6840 (기타청구 이중계상 없음)
        $this->assertEquals(6240.0, (float) $sheet->getCell('I'.(46 - $removed))->getCalculatedValue());
        $this->assertEquals(6840.0, (float) $sheet->getCell('F'.(52 - $removed))->getCalculatedValue(), "$type TOTAL ≠ 판매가 + 기타청구 + 운임");
        $this->assertEquals(DocValue::documentSaleTotal($vehicles), (float) $sheet->getCell('F'.(52 - $removed))->getCalculatedValue());
    }

    public static function contractTypes(): array
    {
        return [
            'container_contract' => ['container_contract'],
            'roro_contract' => ['roro_contract'],
        ];
    }

    /**
     * 금액 셀은 숫자형(TYPE_NUMERIC)이어야 한다. decimal cast 가 문자열 "3760.00" 로 들어가
     * 텍스트로 박히면 PhpSpreadsheet SUM 은 강제변환해 맞게 나오지만 **실제 Excel SUM 은 텍스트를
     * 무시** → SUB TOTAL/GRAND TOTAL 금액이 0(Weight 만 합산). 이 회귀를 셀 타입으로 직접 가드.
     */
    #[DataProvider('shippingTypes')]
    public function test_money_cells_written_as_numeric(string $type, string $sheetName, int $first, int $stride, string $brandCol, string $modelCol): void
    {
        $moneyCols = in_array($type, ['container_invoice_packing', 'roro_invoice_packing'], true)
            ? ['H', 'L']   // unit price, shipping
            : ['F', 'G'];  // FOB price, shipping cost (contracts)

        $sheet = (new DocumentFiller($this->makeVehicles(3)))->spreadsheet($type)->getSheetByName($sheetName);

        foreach ($moneyCols as $col) {
            $this->assertSame(
                DataType::TYPE_NUMERIC,
                $sheet->getCell($col.$first)->getDataType(),
                "$type 금액 셀 $col$first 은 숫자형이어야 Excel SUM 이 합산함 (텍스트면 무시됨)",
            );
        }
    }

    public function test_single_vehicle_still_produces_one_slot(): void
    {
        $v = $this->makeVehicles(1)->first();
        $ss = (new DocumentFiller($v))->spreadsheet('container_invoice_packing');
        $sheet = $ss->getSheetByName('INVOICE');

        $this->assertSame('BRAND1', (string) $sheet->getCell('C21')->getValue());
        // 트림되어 두 번째 슬롯(행 24)은 사라짐 → 그 자리는 footer(SUB TOTAL 등)
        $this->assertStringNotContainsString('BRAND', (string) $sheet->getCell('C24')->getValue());
    }
}
