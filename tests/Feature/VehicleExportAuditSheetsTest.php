<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\FinalPayment;
use App\Models\ReceivableHistory;
use App\Models\Salesman;
use App\Models\Vehicle;
use App\Services\VehicleExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/**
 * 차량 엑셀의 회계실사용 상세 2시트 (jin 2026-09-09).
 *
 * 🧭 차량 시트는 **1대 1행**이라 `판매총액`·`미입금액` 같은 합계만 담긴다. 실사는 「이 돈이 언제 어느
 *    명목으로 들어왔나」를 짚어야 해서 그걸로는 매칭이 안 된다. 한 차량에 잔금·회수이력이 여러 행이라
 *    같은 시트에 못 넣고 시트를 나눈다.
 *
 * 🔒 **검증은 배열이 아니라 생성물의 셀로** 한다(SKILLS §8 #37) — 매핑만 보면 헤더·좌표가 틀려도 통과한다.
 */
class VehicleExportAuditSheetsTest extends TestCase
{
    use RefreshDatabase;

    private function vehicleWithMoney(): Vehicle
    {
        $sm = Salesman::create(['name' => '영업1', 'is_active' => true]);
        $buyer = Buyer::create(['name' => 'AUDIT BUYER', 'is_active' => true, 'salesman_id' => $sm->id]);
        $v = Vehicle::create([
            'vehicle_number' => '11가1001', 'sales_channel' => 'export',
            'currency' => 'EUR', 'exchange_rate' => 1500, 'dhl_request' => false,
            'salesman_id' => $sm->id, 'buyer_id' => $buyer->id,
            'nice_reg_vin' => 'VIN1234567890',
            'sale_date' => '2026-09-01', 'sale_price' => 10000,
        ]);
        // 확정 잔금 + 미확정 잔금 + 송금수수료 — 실사에서 갈라 봐야 하는 세 가지
        FinalPayment::create([
            'vehicle_id' => $v->id, 'type' => 'balance', 'amount' => 7000, 'exchange_rate' => 1500,
            'payment_date' => '2026-09-02', 'confirmed_at' => now(), 'note' => '1차 송금',
        ]);
        FinalPayment::create([
            'vehicle_id' => $v->id, 'type' => 'fee', 'amount' => 6, 'exchange_rate' => 1500,
            'payment_date' => '2026-09-03', 'confirmed_at' => now(), 'note' => '송금 수수료',
        ]);
        FinalPayment::create([
            'vehicle_id' => $v->id, 'type' => 'balance', 'amount' => 2994, 'exchange_rate' => 1500,
            'payment_date' => '2026-09-04', 'note' => '확정 대기',   // confirmed_at 없음
        ]);
        ReceivableHistory::create([
            'vehicle_id' => $v->id, 'method' => 'cash', 'amount' => 100,
            'collected_at' => '2026-09-05', 'note' => '현금 회수',
        ]);

        return $v->fresh();
    }

    private function sheetRows(Spreadsheet $ss, string $title): array
    {
        return $ss->getSheetByName($title)->toArray(null, true, false, false);
    }

    public function test_export_has_the_two_audit_sheets_and_keeps_the_vehicle_sheet_first(): void
    {
        $v = $this->vehicleWithMoney();

        $ss = app(VehicleExportService::class)->build(Vehicle::whereKey($v->id)->get());

        $this->assertSame(['차량목록', '입금내역', '회수이력'], $ss->getSheetNames());
        $this->assertSame(0, $ss->getActiveSheetIndex(), '열었을 때 차량목록이 먼저 보여야 한다');
    }

    /** 🚨 확정·미확정을 **행으로** 다 담는다 — 「입금은 됐는데 확정이 안 된」 구간이 실사 단골 질문이다. */
    public function test_payment_sheet_lists_every_payment_row_with_its_confirmation_state(): void
    {
        $v = $this->vehicleWithMoney();

        $rows = $this->sheetRows(app(VehicleExportService::class)->build(Vehicle::whereKey($v->id)->get()), '입금내역');

        $this->assertSame('차량번호', $rows[0][0]);
        $this->assertSame('구분', $rows[0][4]);
        $this->assertSame('재무확정', $rows[0][9]);
        $this->assertCount(4, $rows, '헤더 1 + 잔금 3행이어야 한다');

        // 입금일 오름차순 — 실사는 시간순으로 읽는다
        $this->assertSame(['11가1001', 'VIN1234567890', 'AUDIT BUYER', 'EUR'], array_slice($rows[1], 0, 4));
        $this->assertSame(__('vehicle.field.balance'), $rows[1][4]);
        $this->assertSame(__('vehicle.field.fee'), $rows[2][4], '송금 수수료가 잔금과 구분돼야 한다');
        $this->assertSame(__('common.yes'), $rows[2][9]);
        $this->assertSame(__('common.no'), $rows[3][9], '미확정 행이 확정으로 보이면 안 된다');
    }

    /**
     * 🚨 **「미수반영」 열** — 입금·적립금사용·잡손실은 다른 기록의 미러라 미수에서 빠진다.
     *    표시가 없으면 실사자가 행을 그냥 더해 「장부가 안 맞는다」고 읽는다.
     */
    public function test_receivable_sheet_marks_which_rows_actually_move_the_receivable(): void
    {
        $v = $this->vehicleWithMoney();
        // 잡손실 — 과입금 정리가 남기는 항목(미러라 미수에 안 들어간다)
        ReceivableHistory::create([
            'vehicle_id' => $v->id, 'method' => 'misc_loss', 'amount' => 30,
            'collected_at' => '2026-09-06', 'note' => '과입금 잡손실 처리',
        ]);

        $rows = $this->sheetRows(app(VehicleExportService::class)->build(Vehicle::whereKey($v->id)->get()), '회수이력');

        $this->assertSame('방식', $rows[0][4]);
        $this->assertSame('미수반영', $rows[0][9]);

        $byMethod = [];
        foreach (array_slice($rows, 1) as $r) {
            $byMethod[$r[4]] = $r;
        }
        // 현금 = 실제로 미수를 줄인다
        $this->assertSame(__('common.yes'), $byMethod[__('receivable.method.cash')][9]);
        // 잡손실 = 미러라 미수에 또 반영되지 않는다
        $this->assertSame(__('common.no'), $byMethod[__('receivable.method.misc_loss')][9],
            '미러 항목이 「미수반영 예」로 나오면 실사에서 이중 계상으로 읽힌다');
        // 입금(deposit) 미러 — 확정 잔금이 만드는 행도 같은 취급
        if (isset($byMethod[__('receivable.method.deposit')])) {
            $this->assertSame(__('common.no'), $byMethod[__('receivable.method.deposit')][9]);
        }
    }

    /** 값이 없어도 시트는 존재해야 한다 — 「시트가 없다」와 「내역이 없다」는 다르다. */
    public function test_sheets_exist_even_when_a_vehicle_has_no_money_rows(): void
    {
        $v = Vehicle::create([
            'vehicle_number' => '22나2002', 'sales_channel' => 'export', 'dhl_request' => false,
            'purchase_price' => 1_000_000,
        ]);

        $ss = app(VehicleExportService::class)->build(Vehicle::whereKey($v->id)->get());

        $this->assertNotNull($ss->getSheetByName('입금내역'));
        $this->assertCount(1, $this->sheetRows($ss, '입금내역'), '헤더만 남아야 한다');
    }

    /**
     * 수식 주입 방어 — 상세 시트도 차량 시트와 같은 규칙(`writeCell`)을 쓴다.
     *
     * ⚠️ 회수이력 비고로 검사한다 — 잔금(balance) 비고는 `ReceivableHistory` 미러가 되받아써서
     *    (「회수: …」) 사람이 적은 값이 남지 않는다. 기존 동작이라 여기서 손대지 않는다.
     */
    public function test_detail_sheets_do_not_execute_formulas_from_notes(): void
    {
        $v = $this->vehicleWithMoney();
        ReceivableHistory::create([
            'vehicle_id' => $v->id, 'method' => 'other', 'amount' => 1,
            'collected_at' => '2026-09-07', 'note' => '=1+1',
        ]);

        $sheet = app(VehicleExportService::class)
            ->build(Vehicle::whereKey($v->id)->get())
            ->getSheetByName('회수이력');

        $notes = array_column(array_slice($sheet->toArray(null, true, false, false), 1), 11);
        $this->assertContains('=1+1', $notes, '비고가 문자열 그대로 담겨야 한다');
        foreach (range(2, $sheet->getHighestRow()) as $r) {
            if ($sheet->getCell('L'.$r)->getValue() === '=1+1') {
                $this->assertSame(DataType::TYPE_STRING,
                    $sheet->getCell('L'.$r)->getDataType(), '수식으로 저장되면 열 때 실행된다');
            }
        }
    }
}
