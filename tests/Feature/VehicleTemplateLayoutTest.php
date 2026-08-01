<?php

namespace Tests\Feature;

use App\Console\Commands\ImportVehicles;
use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * 차량 적재양식 레이아웃 고정 (jin 2026-08-01 — ssancar 적재양식 기준).
 *
 * 🚨 이 테스트가 있는 이유: 2026-06-01 부터 2026-08-01 까지 **두 달간** exporter/importer 의
 *    선적 4열이 실사용 양식과 어긋난 채 살아 있었다. 4열 중 1열만 날짜 파싱 에러를 냈고
 *    나머지 3열은 **조용히 틀린 값**으로 들어갔다(B/L 자리에 선박명, 선박명 자리에 날짜 시리얼).
 *    열 배정은 매핑 배열만 봐서는 틀린 걸 알 수 없으므로 **생성물 + 실제 import 왕복**으로 고정한다.
 */
class VehicleTemplateLayoutTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 기대 헤더 레이아웃 — jin 이 준 실사용 양식(Desktop\ssancarerpDB\차량적재양식.xlsx) 2행 그대로.
     * 그 파일은 git 미추적이라 여기에 박제해 대조한다.
     */
    private const EXPECTED_HEADERS = [
        'B' => '구입일자', 'D' => '차량번호', 'E' => '브랜드', 'F' => '차명', 'G' => '년식',
        'H' => '주행거리', 'I' => '차대번호', 'J' => '담당자', 'K' => '구입처', 'L' => '소유자',
        'M' => '주민(법인)등록번호', 'N' => '사용본거지', 'O' => '송금내역확인', 'P' => '구입금액',
        'Q' => '매도비', 'T' => '말소일자',
        // ⚠️ 선적 4열 — 여기가 어긋났던 자리.
        'V' => '면장', 'W' => '비엘', 'X' => '컨테이너/VSL', 'Y' => '선적일자ETD', 'Z' => '도착일자ETA',
        'AA' => '면장금액', 'AB' => '바이어', 'AC' => '컨사이니', 'AE' => '통화', 'AF' => '판매금액',
        'AG' => '환율', 'AH' => '커미션', 'AI' => 'Auto Loading', 'AJ' => 'TAX/D.C', 'AK' => '운임비',
        // 입금 계열.
        'AP' => '입금', 'AQ' => '입금일', 'AR' => 'deposit', 'AS' => '입금일',
        'AT' => 'USE DEPOSIT(적립금)', 'AU' => '사용일',
        'BM' => '말소비', 'BN' => '면허비', 'BO' => '탁송비', 'BP' => '캐리비', 'BQ' => '쇼링비',
        'BR' => '보험료', 'BS' => '이전비', 'BT' => '기타1', 'BU' => '기타2', 'CK' => '비고',
    ];

    private function buildTemplate(): string
    {
        $path = sys_get_temp_dir().'/tmpl_layout_'.uniqid().'.xlsx';
        $this->artisan('vehicles:export-template', ['path' => $path, '--rows' => 5])->assertExitCode(0);

        return $path;
    }

    public function test_exported_header_row_matches_live_layout(): void
    {
        $path = $this->buildTemplate();
        $sheet = IOFactory::load($path)->getSheetByName('수출차량매입');
        @unlink($path);

        $actual = [];
        foreach ($sheet->getRowIterator(2, 2) as $row) {
            foreach ($row->getCellIterator() as $cell) {
                $v = $cell->getValue();
                if ($v !== null && trim((string) $v) !== '') {
                    $actual[$cell->getColumn()] = trim((string) $v);
                }
            }
        }

        $this->assertSame(self::EXPECTED_HEADERS, $actual, '적재양식 헤더가 실사용 양식과 어긋났다');
    }

    /** 헤더는 냈는데 열이 숨겨져 있으면 사용자가 그 칸을 못 본다(gap 숨김 로직 함정). */
    public function test_payment_and_header_only_columns_are_visible(): void
    {
        $path = $this->buildTemplate();
        $sheet = IOFactory::load($path)->getSheetByName('수출차량매입');
        @unlink($path);

        $cols = array_keys(ImportVehicles::HEADER_ONLY_COLUMNS);
        foreach (ImportVehicles::PAYMENT_SLOTS as $slot) {
            $cols[] = $slot['amount'];
            $cols[] = $slot['date'];
        }

        foreach ($cols as $col) {
            $this->assertTrue($sheet->getColumnDimension($col)->getVisible(), "{$col} 열이 숨겨져 있다");
        }
    }

    /** 한 열에 두 항목이 배정되면 뒤엣것이 앞엣것을 덮어 조용히 데이터가 사라진다. */
    public function test_no_duplicate_column_assignment(): void
    {
        $cols = array_column(ImportVehicles::MAP, 'col');
        foreach (ImportVehicles::PAYMENT_SLOTS as $slot) {
            $cols[] = $slot['amount'];
            $cols[] = $slot['date'];
        }
        $cols = array_merge($cols, array_keys(ImportVehicles::HEADER_ONLY_COLUMNS));

        $dupes = array_keys(array_filter(array_count_values($cols), fn ($n) => $n > 1));
        $this->assertSame([], $dupes, '같은 열에 두 항목이 배정됨: '.implode(',', $dupes));
    }

    /**
     * 🔒 핵심 가드 — 양식에 값을 넣고 실제로 import 해서 **필드가 뒤바뀌지 않는지** 확인한다.
     * 헤더만 맞고 MAP 이 어긋나면 위 헤더 테스트는 통과하지만 여기서 잡힌다.
     */
    public function test_round_trip_lands_values_in_correct_fields(): void
    {
        Salesman::create(['name' => 'TESTMAN', 'type' => 'employee', 'is_active' => true]);
        Buyer::create(['name' => 'Auto MVE', 'is_active' => true]);

        $path = $this->buildTemplate();
        $book = IOFactory::createReaderForFile($path)->load($path);
        $sheet = $book->getSheetByName('수출차량매입');

        $sheet->setCellValue('B3', '2026-02-03');
        $sheet->setCellValue('D3', '365수8067');
        $sheet->setCellValue('I3', 'WVWZZZ3HZKE009045');
        $sheet->setCellValue('J3', 'TESTMAN');
        $sheet->setCellValue('P3', 19_360_000);
        $sheet->setCellValue('V3', '43274-26-261048X');       // 면장
        $sheet->setCellValue('W3', 'CIGSINDU2602BS36');       // 비엘
        $sheet->setCellValue('X3', '6.02_S RORO 17-9_1');     // 컨테이너/VSL
        $sheet->setCellValue('Y3', '2026-02-20');             // 선적일자ETD
        $sheet->setCellValue('Z3', '2026-03-23');             // 도착일자ETA
        $sheet->setCellValue('AB3', 'Auto MVE');
        $sheet->setCellValue('AE3', 'EUR');
        $sheet->setCellValue('AF3', 11_385);
        $sheet->setCellValue('AG3', 1_695);
        $sheet->setCellValue('AK3', 1_161);
        $sheet->setCellValue('AT3', 762);                      // USE DEPOSIT(적립금)
        (new Xlsx($book))->save($path);

        $this->artisan('vehicles:import', ['path' => $path, '--force' => true])->assertExitCode(0);
        @unlink($path);

        $v = Vehicle::where('vehicle_number', '365수8067')->firstOrFail();

        // 어긋났던 4열 — 값이 서로 바뀌면 여기서 즉시 실패한다.
        $this->assertSame('CIGSINDU2602BS36', $v->bl_number, 'W=비엘');
        $this->assertSame('6.02_S RORO 17-9_1', $v->vessel_name, 'X=컨테이너/VSL');
        $this->assertSame('2026-02-20', $v->shipping_date?->format('Y-m-d'), 'Y=선적일자ETD');
        $this->assertSame('2026-03-23', $v->eta_date?->format('Y-m-d'), 'Z=도착일자ETA');

        // 이번에 새로 연결한 2열.
        $this->assertSame('43274-26-261048X', $v->export_declaration_number, 'V=면장');
        $this->assertEquals(762, (int) $v->savings_used, 'AT=적립금 사용');

        $this->assertSame('EUR', $v->currency);
        $this->assertEquals(11_385, (int) $v->sale_price);
    }

    /** 입금 슬롯(AP/AQ)이 금액·날짜로 바르게 읽히는지 — 한 칸 밀리면 금액이 날짜로 들어간다. */
    public function test_payment_slot_reads_amount_and_date(): void
    {
        Salesman::create(['name' => 'TESTMAN', 'type' => 'employee', 'is_active' => true]);
        Buyer::create(['name' => 'Auto MVE', 'is_active' => true]);

        $path = $this->buildTemplate();
        $book = IOFactory::createReaderForFile($path)->load($path);
        $sheet = $book->getSheetByName('수출차량매입');

        $slot = ImportVehicles::PAYMENT_SLOTS[0];
        $sheet->setCellValue('B3', '2026-02-03');
        $sheet->setCellValue('D3', '312저7644');
        $sheet->setCellValue('I3', 'WVGZZZ5NZLW355329');
        $sheet->setCellValue('J3', 'TESTMAN');
        $sheet->setCellValue('AB3', 'Auto MVE');
        $sheet->setCellValue('AE3', 'EUR');
        $sheet->setCellValue('AF3', 13_402);
        $sheet->setCellValue('AG3', 1_714);
        $sheet->setCellValue($slot['amount'].'3', 14_646);
        $sheet->setCellValue($slot['date'].'3', '2026-02-10');
        (new Xlsx($book))->save($path);

        $this->artisan('vehicles:import', ['path' => $path, '--force' => true, '--with-payments' => true])->assertExitCode(0);
        @unlink($path);

        $v = Vehicle::where('vehicle_number', '312저7644')->firstOrFail();
        $payment = $v->finalPayments()->first();

        $this->assertNotNull($payment, '입금이 적재되지 않았다');
        $this->assertEquals(14_646, (int) $payment->amount, '금액 열이 어긋났다');
        $this->assertSame('2026-02-10', $payment->payment_date?->format('Y-m-d'), '날짜 열이 어긋났다');
    }

    /** 힌트 행(1행)이 열 성격과 맞는지 — 재배정 후 B/L 칸에 날짜 힌트가 남으면 안 된다. */
    public function test_hint_row_follows_reassigned_columns(): void
    {
        $path = $this->buildTemplate();
        $sheet = IOFactory::load($path)->getSheetByName('수출차량매입');
        @unlink($path);

        $hint = fn (string $col) => trim((string) $sheet->getCell($col.'1')->getValue());

        $this->assertSame('YYYY-MM-DD', $hint('Y'), 'Y=선적일자ETD 는 날짜 힌트');
        $this->assertSame('YYYY-MM-DD', $hint('Z'), 'Z=도착일자ETA 는 날짜 힌트');
        $this->assertNotSame('YYYY-MM-DD', $hint('W'), 'W=비엘 에 날짜 힌트가 남았다');
        $this->assertNotSame('YYYY-MM-DD', $hint('X'), 'X=컨테이너/VSL 에 날짜 힌트가 남았다');
        $this->assertSame('숫자만', $hint(ImportVehicles::PAYMENT_SLOTS[0]['amount']), '입금 금액칸 힌트');
    }

    /** 헤더 열 수가 늘면 마지막 열도 함께 늘어야 한다(잘린 양식 방지). */
    public function test_template_covers_last_mapped_column(): void
    {
        $path = $this->buildTemplate();
        $sheet = IOFactory::load($path)->getSheetByName('수출차량매입');
        @unlink($path);

        $last = max(array_map(
            fn ($c) => Coordinate::columnIndexFromString($c),
            array_keys(self::EXPECTED_HEADERS),
        ));

        $this->assertGreaterThanOrEqual(
            $last,
            Coordinate::columnIndexFromString($sheet->getHighestColumn()),
            '양식이 마지막 매핑 열보다 짧다',
        );
    }
}
