<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\FinalPayment;
use App\Models\Salesman;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * vehicles:import --with-payments 정정 (2026-06-11).
 *  A) 외화차 원화입금 → 외화 환산(amount÷환율) — ×환율 폭발 방지.
 *  B) 기존 수동 확정입금 있으면 import 입금/정산 생략 — 이중계상 방지.
 */
class ImportVehiclesPaymentTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string,mixed> $c 컬럼레터=>값 */
    private function fixture(array $c): string
    {
        $ss = new Spreadsheet;
        $sh = $ss->getActiveSheet();
        $sh->setTitle('수출차량매입-2026');
        $defaults = [
            'B' => '2026-02-01', 'E' => 'BMW', 'F' => 'X5', 'G' => 2020, 'H' => 50000,
            'J' => 'TESTMAN', 'P' => 1000000, 'AC' => '',
        ];
        foreach (array_merge($defaults, $c) as $col => $val) {
            $sh->setCellValue($col.'3', $val);
        }
        $path = sys_get_temp_dir().'/imp_pay_'.uniqid().'.xlsx';
        (new Xlsx($ss))->save($path);

        return $path;
    }

    public function test_foreign_won_payment_is_converted_to_currency(): void
    {
        Salesman::create(['name' => 'TESTMAN', 'type' => 'employee', 'is_active' => true]);

        // EUR 차, 환율 1430, 정산1 입금 31,832,300(원화) — 외화슬롯에 원화
        $path = $this->fixture([
            'D' => 'EUR-1', 'I' => 'EURVIN1', 'AB' => 'EUR BUYER',
            'AE' => 'EUR', 'AF' => 26223.78, 'AG' => 1430,
            'AO' => 31832300, 'AP' => '2026-02-11',
        ]);
        $this->artisan('vehicles:import', ['path' => $path, '--force' => true, '--with-payments' => true])->assertExitCode(0);
        @unlink($path);

        $v = Vehicle::where('vehicle_number', 'EUR-1')->first();
        $fp = $v->finalPayments()->first();
        $this->assertNotNull($fp, '입금 미생성');
        // 31,832,300 ÷ 1430 ≈ 22,260.35 (원화 그대로 박히면 31,832,300 이어야 함 → 실패해야 정상)
        $this->assertEqualsWithDelta(22260.35, (float) $fp->amount, 0.5, '원화가 외화 환산 안 됨');
        $this->assertEqualsWithDelta(31832300, (int) $fp->amount_krw, 2000, 'amount_krw 가 실제 원화와 다름');
    }

    public function test_import_skips_payment_when_manual_exists(): void
    {
        Salesman::create(['name' => 'TESTMAN', 'type' => 'employee', 'is_active' => true]);
        $buyer = Buyer::create(['name' => 'M BUYER', 'is_active' => true]);

        // 기존 차량 + 수동 확정입금
        $v = Vehicle::create([
            'vehicle_number' => 'DUP-1', 'sales_channel' => 'export', 'dhl_request' => false,
            'currency' => 'EUR', 'exchange_rate' => 1430, 'nice_reg_vin' => 'DUPVIN1',
            'buyer_id' => $buyer->id, 'sale_date' => '2026-05-01', 'sale_price' => 5200,
        ]);
        $v->finalPayments()->create([
            'type' => 'balance', 'amount' => 5200, 'exchange_rate' => 1430,
            'payment_date' => '2026-06-08', 'confirmed_at' => now(), 'note' => '회수: 수동입력',
        ]);
        $this->assertSame(1, $v->finalPayments()->count());

        // 같은 VIN import --with-payments (정산1 = 5200)
        $path = $this->fixture([
            'D' => 'DUP-1', 'I' => 'DUPVIN1', 'AB' => 'M BUYER',
            'AE' => 'EUR', 'AF' => 5200, 'AG' => 1430,
            'AO' => 5200, 'AP' => '2026-02-11',
        ]);
        $this->artisan('vehicles:import', ['path' => $path, '--force' => true, '--with-payments' => true])->assertExitCode(0);
        @unlink($path);

        $v->refresh();
        // import 입금 생략 → 여전히 수동 1건만 (이중계상 없음)
        $this->assertSame(1, $v->finalPayments()->count(), 'import 입금이 이중으로 추가됨');
        $this->assertSame(0, FinalPayment::where('vehicle_id', $v->id)->where('note', 'import 입금')->count());
    }
}
