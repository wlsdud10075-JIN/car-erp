<?php

namespace Tests\Feature;

use App\Models\Vehicle;
use App\Services\Documents\DocumentFiller;
use App\Services\Documents\DocValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Calculation\Calculation;
use Tests\TestCase;

/**
 * 대외 서류의 **최종 합계는 4항을 전부 반영**해야 한다 (jin 2026-08-28).
 *
 *   판매가 + Commission + Auto Loading − TAX D/C  → 여기에 운임비를 더한 것이 청구 총액
 *
 * 🚨 종전에 5종이 `sale_price + transport_fee` 만 찍고 있었다 — commission·auto_loading·tax_dc 가
 *    **셀 자체가 없어서** 통째로 빠졌다(container/roro invoice&packing · container/roro contract · 통관SET).
 *    승인 서류인 Proforma Invoice·판매계약서만 맞았다. 예외도 경고도 없이 **금액만 낮게** 나간다.
 *
 * 🔒 **검증은 매핑 배열이 아니라 생성물로 한다** (SKILLS §8 #37) — 배열만 보는 테스트는
 *    푸터 SUM range 가 새 칸을 안 덮어도 통과한다. 여기서는 실제로 채운 시트의 합계 셀을
 *    계산값으로 읽고, 미사용 슬롯 트림으로 인한 **행 이동까지 계산해서** 비교한다.
 *
 * 🏢 **3사 양식 전부**를 돈다(system·heyman·karaba) — 회사별 사본이라 한 곳만 고치면 갈린다.
 */
class DocumentSaleTotalTest extends TestCase
{
    use RefreshDatabase;

    private const TEMPLATE_SETS = ['system', 'heyman', 'karaba'];

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
            'currency' => 'USD',
            'exchange_rate' => 1300,
            'sale_date' => '2026-08-01',
            // ⚠️ 4항이 전부 0 이 아니어야 한다 — 0 이면 빠져 있어도 합계가 우연히 맞는다.
            'sale_price' => 1000 * $i,
            'transport_fee' => 100 * $i,
            'commission' => 30 * $i,
            'auto_loading' => 20 * $i,
            'tax_dc' => 10 * $i,
        ]));
    }

    /**
     * type => [시트, 합계셀, 원본행, first, stride, capacity]
     * 합계셀 = 그 서류가 바이어에게 내미는 **최종 청구액**.
     */
    private static function totalCells(): array
    {
        return [
            'invoice' => ['Invoice', 'E', 60, 18, 1, 30],
            'sales_contract' => ['CONTRACT', 'R', 54, 22, 1, 30],
            'container_invoice_packing' => ['INVOICE', 'I', 114, 21, 3, 30],
            'roro_invoice_packing' => ['INVOICE', 'I', 54, 21, 1, 30],
            'container_contract' => ['HBB340.', 'F', 52, 16, 1, 30],
            'roro_contract' => ['HBB340.', 'F', 52, 16, 1, 30],
        ];
    }

    public function test_every_shipping_document_total_includes_commission_loading_and_discount(): void
    {
        foreach (self::TEMPLATE_SETS as $set) {
            config(['company.template_set' => $set]);

            foreach (self::totalCells() as $type => [$sheetName, $col, $row, $first, $stride, $capacity]) {
                foreach ([1, 3] as $n) {
                    $vehicles = $this->makeVehicles($n);
                    $expected = DocValue::documentSaleTotal($vehicles);

                    // 4항이 실제로 값을 갖는지 먼저 못박는다 — 0 이면 이 테스트는 아무것도 검사하지 않는다.
                    $this->assertGreaterThan(
                        $vehicles->sum('sale_price') + $vehicles->sum('transport_fee'),
                        $expected,
                        '표본이 잘못됐다 — commission/auto_loading 이 0 이면 누락을 못 잡는다.'
                    );

                    $ss = (new DocumentFiller($vehicles))->spreadsheet($type);
                    $sheet = $ss->getSheetByName($sheetName);
                    $this->assertNotNull($sheet, "$set/$type: 시트 $sheetName 없음");
                    Calculation::getInstance($ss)->clearCalculationCache();

                    $removed = ($capacity - $n) * $stride;
                    $actual = (float) $sheet->getCell($col.($row - $removed))->getCalculatedValue();

                    $this->assertEqualsWithDelta(
                        $expected,
                        $actual,
                        0.5,
                        "[$set] $type (N=$n) 총액이 4항을 반영하지 않는다 — ".
                        "기대 {$expected} / 실제 {$actual}. commission·auto_loading·tax_dc 가 빠졌을 가능성.",
                    );

                    $ss->disconnectWorksheets();
                    Vehicle::query()->forceDelete();
                }
            }
        }
    }

    /**
     * 통관 SET — 마스터 「판매금」이 4항 합이어야 하고, 그 결과 차량인보이스의 총액이
     * ERP 면장 기준액(`Vehicle::declaration_base_amount`)과 **같아야** 한다.
     *
     * 🚨 `=구매리스트!B15` cascade 라 이 칸 하나가 6시트를 움직인다.
     */
    public function test_clearance_set_matches_the_declaration_base_amount(): void
    {
        foreach (self::TEMPLATE_SETS as $set) {
            config(['company.template_set' => $set]);

            $v = $this->makeVehicles(1)->first();
            $ss = (new DocumentFiller($v))->spreadsheet('clearance');
            Calculation::getInstance($ss)->clearCalculationCache();

            $master = $ss->getSheetByName('구매리스트');
            $this->assertNotNull($master, "[$set] 구매리스트 시트 없음");

            // 판매금 = 판매가 + Commission + Auto Loading − TAX D/C (운임은 D15 로 따로)
            $this->assertEqualsWithDelta(
                (float) $v->sale_price + DocValue::otherCharge($v),
                (float) $master->getCell('B15')->getCalculatedValue(),
                0.5,
                "[$set] 통관SET 판매금(B15)이 4항 합이 아니다.",
            );

            $invoice = $ss->getSheetByName('차량인보이스');
            if ($invoice !== null) {
                // J22 = 판매금 + 운임 = ERP 면장 기준액
                $this->assertEqualsWithDelta(
                    (float) $v->declaration_base_amount,
                    (float) $invoice->getCell('J22')->getCalculatedValue(),
                    0.5,
                    "[$set] 통관SET 차량인보이스 총액이 ERP 면장 기준액과 다르다.",
                );
            }

            // 🚨 이 수정의 근거가 「8시트가 같이 낮았다」이므로 **하류 시트까지** 단언한다.
            //    구매리스트 → 차량인보이스 → Travel Services Invoice 로 두 단계 cascade 한다.
            $travel = $ss->getSheetByName('Travel Services Invoice');
            if ($travel !== null) {
                foreach (['F31' => 'Grend Total', 'F11' => 'Total invoice'] as $coord => $label) {
                    $this->assertEqualsWithDelta(
                        (float) $v->declaration_base_amount,
                        (float) $travel->getCell($coord)->getCalculatedValue(),
                        0.5,
                        "[$set] Travel Services Invoice $label($coord) 이 cascade 를 안 받았다.",
                    );
                }
            }

            $ss->disconnectWorksheets();
            Vehicle::query()->forceDelete();
        }
    }

    /**
     * 4항 식은 **한 곳에만** 있어야 한다 (SKILLS §8 #45).
     * 서류가 7종이라 복제되면 08-27 면장 변경 같은 게 왔을 때 일부만 고쳐진다.
     */
    public function test_the_formula_is_not_copied_into_mappings(): void
    {
        $dir = app_path('Services/Documents/Mappings');
        foreach (glob($dir.'/*.php') as $file) {
            $src = (string) file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression(
                // 실제 **식**만 잡는다 — 주석·개별 항목(`sum($vs, 'commission')` 같은 itemize)은
                // 정당하다. 세 컬럼을 한 수식에서 직접 읽는 형태만 금지한다.
                '/\$v->commission[^;]{0,150}\$v->auto_loading[^;]{0,150}\$v->tax_dc/s',
                $src,
                basename($file).' 가 4항 식을 복제하고 있다 — `DocValue::otherCharge()` / '.
                '`DocValue::documentSaleTotal()` 을 쓸 것.',
            );
        }
    }
}
