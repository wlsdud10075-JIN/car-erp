<?php

namespace Tests\Feature;

use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\Vehicle;
use App\Support\SettlementCkBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Tests\TestCase;

/**
 * 2026-06-24 — 정산 created_at 을 CK 배치(일한 月)로 백데이트.
 * 헬퍼(파싱) + 커맨드(실제 xlsx → created_at 보정) 검증.
 */
class BackdateSettlementsFromCkTest extends TestCase
{
    use RefreshDatabase;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    public function test_helper_parses_both_ck_formats(): void
    {
        // "26.05.10정산" → 지급 2026-05-10 → 일한 月 2026-04
        $this->assertSame('2026-04-15', SettlementCkBatch::workCreatedAt('26.05.10정산', 2026)?->format('Y-m-d'));
        // "6월 정산" → 지급 {연도}-06-10 → 일한 月 2026-05
        $this->assertSame('2026-05-15', SettlementCkBatch::workCreatedAt('6월 정산', 2026)?->format('Y-m-d'));
        // 1월 지급 → 전년 12월 일한 분 (경계)
        $this->assertSame('2025-12-15', SettlementCkBatch::workCreatedAt('1월 정산', 2026)?->format('Y-m-d'));
        // 미정산/빈값/무관 → null
        $this->assertNull(SettlementCkBatch::workCreatedAt('미정산', 2026));
        $this->assertNull(SettlementCkBatch::workCreatedAt('', 2026));
        $this->assertNull(SettlementCkBatch::workCreatedAt('비고없음', 2026));
    }

    public function test_year_from_sheet(): void
    {
        $this->assertSame(2026, SettlementCkBatch::yearFromSheet('수출차량매입-2026', 1999));
        $this->assertSame(1999, SettlementCkBatch::yearFromSheet('시트', 1999));
    }

    private function vehicleWithSettlement(string $vno): Vehicle
    {
        $sm = Salesman::create(['name' => '담당'.++$this->counter, 'type' => 'freelance']);
        $v = Vehicle::create([
            'vehicle_number' => $vno, 'sales_channel' => 'export', 'currency' => 'USD',
            'dhl_request' => false, 'sale_price' => 400, 'sale_date' => '2026-03-01',
            'purchase_date' => '2026-01-01', 'salesman_id' => $sm->id,
        ]);
        $s = Settlement::create([
            'vehicle_id' => $v->id, 'salesman_id' => $sm->id,
            'settlement_type' => 'ratio', 'settlement_ratio' => 50, 'settlement_status' => 'pending',
        ]);
        // created_at 을 6월로(클러스터 모사).
        Settlement::where('id', $s->id)->update(['created_at' => '2026-06-22 10:00:00']);

        return $v;
    }

    private function makeCkXlsx(array $rows): string
    {
        // 첫 시트명 = 수출차량매입-2026, D열=차량번호, CK열=배치. 데이터는 3행부터.
        $ss = new Spreadsheet;
        $ws = $ss->getActiveSheet();
        $ws->setTitle('수출차량매입-2026');
        $r = 3;
        foreach ($rows as [$vno, $ck]) {
            $ws->setCellValue('D'.$r, $vno);
            $ws->setCellValue('CK'.$r, $ck);
            $r++;
        }
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ck_test_'.uniqid().'.xlsx';
        (new XlsxWriter($ss))->save($path);

        return $path;
    }

    public function test_command_backdates_created_at_by_batch(): void
    {
        $april = $this->vehicleWithSettlement('11가1111');   // 5.10정산 → 4월
        $may = $this->vehicleWithSettlement('22나2222');     // 6월정산 → 5월
        $unmarked = $this->vehicleWithSettlement('33다3333'); // CK 없음 → 유지

        $path = $this->makeCkXlsx([
            ['11가1111', '26.05.10정산'],
            ['22나2222', '6월 정산'],
            ['33다3333', '미정산'],
        ]);

        $this->artisan('settlements:backdate-from-ck', ['path' => $path, '--apply' => true])
            ->assertSuccessful();

        $month = fn (Vehicle $v) => Settlement::where('vehicle_id', $v->id)->value('created_at')->format('Y-m');
        $this->assertSame('2026-04', $month($april));
        $this->assertSame('2026-05', $month($may));
        $this->assertSame('2026-06', $month($unmarked));  // CK '미정산' → 미변경

        @unlink($path);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $v = $this->vehicleWithSettlement('44라4444');
        $path = $this->makeCkXlsx([['44라4444', '26.05.10정산']]);

        $this->artisan('settlements:backdate-from-ck', ['path' => $path])->assertSuccessful();

        $this->assertSame('2026-06', Settlement::where('vehicle_id', $v->id)->value('created_at')->format('Y-m'));

        @unlink($path);
    }
}
