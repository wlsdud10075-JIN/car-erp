<?php

namespace Tests\Feature;

use App\Models\Salesman;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * ssancar 대량적재 — 표준 양식 export + 제각각 서식 흡수(parseDate 강화).
 *   - 날짜 '26.05.10정산'(혼합)·'2026년 6월 7일'(한국식)→정상 파싱
 *   - '5월 예정'·'선적중'(상태텍스트)·'05-10'(연도없음)→null(미발생 분류, 에러 아님)
 *   - vehicles:export-template 가 만든 양식을 vehicles:import 가 무수정 round-trip
 */
class VehicleImportTemplateTest extends TestCase
{
    use RefreshDatabase;

    /** 제각각 날짜 서식을 한 행에 넣고 import → 정규화 결과 검증 */
    public function test_import_normalizes_messy_date_formats(): void
    {
        Salesman::create(['name' => 'TESTMAN', 'type' => 'employee', 'is_active' => true]);

        $ss = new Spreadsheet;
        $sh = $ss->getActiveSheet();
        $sh->setTitle('수출차량매입-2026');
        $sh->setCellValue('D2', '차량번호');
        $sh->setCellValue('J2', '담당자');
        $sh->setCellValue('P2', '구입금액');
        $sh->setCellValue('D3', '99가1234');
        $sh->setCellValue('I3', 'MESSYVIN0000001');
        $sh->setCellValue('J3', 'TESTMAN');
        $sh->setCellValue('P3', 5_000_000);
        $set = fn (string $c, string $v) => $sh->setCellValueExplicit($c.'3', $v, DataType::TYPE_STRING);
        $set('B', '26.05.10정산');   // 구입일자 — 혼합 → 2026-05-10 (연도추정 기준)
        $set('T', '5월 예정');        // 말소일자 — 미발생 → null
        $set('W', '05-10');           // 선적일자 — 연도없음 → 구입연도 추정 2026-05-10
        $set('Y', '2026년 6월 7일');  // 도착일자 — 한국식 → 2026-06-07

        $path = sys_get_temp_dir().'/import_messy_'.uniqid().'.xlsx';
        (new Xlsx($ss))->save($path);

        $this->artisan('vehicles:import', ['path' => $path, '--force' => true])->assertExitCode(0);
        @unlink($path);

        $v = Vehicle::where('vehicle_number', '99가1234')->firstOrFail();
        $this->assertSame('2026-05-10', $v->purchase_date?->format('Y-m-d'), '혼합 날짜 프리픽스 추출 실패');
        $this->assertNull($v->deregistration_date, "'5월 예정'은 미발생 → null 이어야");
        $this->assertSame('2026-05-10', $v->shipping_date?->format('Y-m-d'), "'05-10'은 구입연도(2026)로 추정되어야");
        $this->assertSame('2026-06-07', $v->eta_date?->format('Y-m-d'), '한국식 년월일 파싱 실패');
        $this->assertFalse((bool) $v->is_deregistered, '말소일 null 이므로 미말소');
    }

    /** 구입연도가 없으면 연도없는 날짜는 추정 못 하고 null 유지 */
    public function test_yearless_date_stays_null_without_purchase_year(): void
    {
        Salesman::create(['name' => 'TESTMAN', 'type' => 'employee', 'is_active' => true]);

        $ss = new Spreadsheet;
        $sh = $ss->getActiveSheet();
        $sh->setTitle('수출차량매입-2026');
        $sh->setCellValue('D3', '99가7777');
        $sh->setCellValue('I3', 'NOYEARVIN000001');
        $sh->setCellValue('J3', 'TESTMAN');
        $sh->setCellValue('P3', 4_000_000);
        // 구입일자(B) 비움 → 추정 기준 없음
        $sh->setCellValueExplicit('W3', '05-10', DataType::TYPE_STRING);

        $path = sys_get_temp_dir().'/import_noyear_'.uniqid().'.xlsx';
        (new Xlsx($ss))->save($path);
        $this->artisan('vehicles:import', ['path' => $path, '--force' => true])->assertExitCode(0);
        @unlink($path);

        $v = Vehicle::where('vehicle_number', '99가7777')->firstOrFail();
        $this->assertNull($v->shipping_date, '구입연도 없으면 추정 불가 → null');
    }

    /** export-template 가 만든 양식을 import 가 그대로 읽는다(round-trip) */
    public function test_exported_template_is_importable(): void
    {
        Salesman::create(['name' => 'TESTMAN', 'type' => 'employee', 'is_active' => true]);

        $tmpl = sys_get_temp_dir().'/tmpl_'.uniqid().'.xlsx';
        $this->artisan('vehicles:export-template', ['path' => $tmpl, '--rows' => 10])->assertExitCode(0);
        $this->assertFileExists($tmpl);

        // 생성된 양식(헤더 2행)을 열어 데이터 1행 기입
        $book = IOFactory::createReaderForFile($tmpl)->load($tmpl);
        $sh = $book->getSheetByName('수출차량매입');
        $this->assertSame('차량번호', $sh->getCell('D2')->getValue(), '헤더 위치(D2) 불일치');
        $sh->setCellValue('D3', '11가9999');
        $sh->setCellValue('I3', 'TMPLVIN00000001');
        $sh->setCellValue('J3', 'TESTMAN');
        $sh->setCellValue('B3', '2026-02-15');
        $sh->setCellValue('P3', 7_000_000);
        $sh->setCellValue('AE3', 'USD');
        (new Xlsx($book))->save($tmpl);

        $this->artisan('vehicles:import', ['path' => $tmpl, '--force' => true])->assertExitCode(0);
        @unlink($tmpl);

        $v = Vehicle::where('vehicle_number', '11가9999')->firstOrFail();
        $this->assertSame('2026-02-15', $v->purchase_date?->format('Y-m-d'));
        $this->assertSame(7_000_000, (int) $v->purchase_price);
        $this->assertSame('USD', $v->currency);
    }
}
