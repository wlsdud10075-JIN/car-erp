<?php

namespace Tests\Feature;

use App\Models\Vehicle;
use App\Services\Documents\DocumentFiller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Calculation\Calculation;
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
        ]));
    }

    #[DataProvider('shippingTypes')]
    public function test_multi_vehicle_fills_slots_and_sums(string $type, string $sheetName, int $first, int $stride, string $brandCol, string $modelCol): void
    {
        foreach ([1, 3, 7, 12, 30] as $n) {
            $vehicles = $this->makeVehicles($n);
            $expectedAmount = $vehicles->sum('sale_price');

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
        // 트림 후 footer(원본 51 - removed 25 = 26). I열 = Σ sale_price = 1000+2000+...+5000 = 15000
        $this->assertEquals(15000, (float) $sheet->getCell('I26')->getCalculatedValue());

        @unlink($tmp);
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
