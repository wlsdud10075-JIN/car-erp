<?php

namespace Tests\Feature;

use App\Models\Vehicle;
use App\Services\Documents\DocumentFiller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Calculation\Calculation;
use Tests\TestCase;

/**
 * 2026-06-24 — 인보이스 ① 판매통화 적응($→통화기호) ② DEPOSIT 제거(더블카운트 수정).
 *
 * 버그(jin 보고): EUR 차량인데 금액이 $로 표시 / TOTAL 이 판매총액의 2배(5700→11400).
 *   원인 ①: 템플릿 금액 서식 \$#,##0 하드코딩. ②: DEPOSIT(E29) 가 TOTAL(=SUM(E27:F30)) 에
 *   양수로 가산돼 SUBTOTAL+DEPOSIT 더블카운트.
 */
class SalesInvoiceCurrencyDepositTest extends TestCase
{
    use RefreshDatabase;

    private function invoiceVehicle(string $currency): Vehicle
    {
        $v = Vehicle::create([
            'vehicle_number' => 'INV-'.$currency,
            'sales_channel' => 'export', 'currency' => $currency, 'exchange_rate' => 1707,
            'sale_price' => 4399, 'transport_fee' => 1301,     // SUBTOTAL = 5700
            'sale_date' => '2026-06-01', 'purchase_date' => '2026-05-01', 'dhl_request' => false,
        ]);
        // 확정 입금(과거 DEPOSIT 소스) — 이제 인보이스에 안 나와야 함.
        $v->finalPayments()->create([
            'type' => 'balance', 'amount' => 5700, 'exchange_rate' => 1707,
            'payment_date' => '2026-06-10', 'confirmed_at' => now(),
        ]);

        return $v->fresh();
    }

    private function invoiceSheet(Vehicle $v)
    {
        $ss = (new DocumentFiller($v))->spreadsheet('invoice');
        Calculation::getInstance($ss)->clearCalculationCache();

        return $ss->getSheetByName('Invoice');
    }

    public function test_eur_uses_euro_symbol_and_label(): void
    {
        $sheet = $this->invoiceSheet($this->invoiceVehicle('EUR'));

        $fmt = $sheet->getStyle('E18')->getNumberFormat()->getFormatCode();
        $this->assertStringContainsString('€', $fmt);
        $this->assertStringNotContainsString('$', $fmt);
        $this->assertSame('EUR Rate', (string) $sheet->getCell('D10')->getValue());
    }

    public function test_usd_keeps_dollar(): void
    {
        $sheet = $this->invoiceSheet($this->invoiceVehicle('USD'));

        $this->assertStringContainsString('$', $sheet->getStyle('E18')->getNumberFormat()->getFormatCode());
        $this->assertSame('Dollar Rate', (string) $sheet->getCell('D10')->getValue());
    }

    public function test_deposit_removed_no_double_count(): void
    {
        $sheet = $this->invoiceSheet($this->invoiceVehicle('EUR'));

        // DEPOSIT 값·라벨 모두 제거.
        $this->assertSame('', (string) $sheet->getCell('E29')->getValue());
        $this->assertSame('', (string) $sheet->getCell('C28')->getValue());
        $this->assertSame('', (string) $sheet->getCell('C29')->getValue());

        // TOTAL = SUBTOTAL = 5700 (확정입금 5700 이 더해진 11400 이 아님).
        $this->assertEquals(5700, $sheet->getCell('E27')->getCalculatedValue());
        $this->assertEquals(5700, $sheet->getCell('E31')->getCalculatedValue());
        $this->assertEquals(5700, $sheet->getCell('E34')->getCalculatedValue());
    }
}
