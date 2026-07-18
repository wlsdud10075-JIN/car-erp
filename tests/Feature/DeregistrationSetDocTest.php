<?php

namespace Tests\Feature;

use App\Models\Vehicle;
use App\Services\Documents\DocumentFiller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * item 8 (jin 2026-07-18) — 말소신청서+계약서 1파일 2시트 병합본(deregistration_set).
 * appendSheets 로 계약서 시트를 신청서 워크북에 graft, Sheet!Cell 로 양 시트 기입.
 */
class DeregistrationSetDocTest extends TestCase
{
    use RefreshDatabase;

    public function test_merged_doc_has_two_sheets_with_filled_cells(): void
    {
        $v = Vehicle::create([
            'vehicle_number' => '11가1111', 'sales_channel' => 'export',
            'nice_reg_vin' => 'KMHTEST0000123456', 'nice_reg_owner_name' => '홍길동',
            'mileage' => 50000, 'incoterms' => 'CFR',
        ]);

        $ss = (new DocumentFiller($v))->spreadsheet('deregistration_set');

        $names = $ss->getSheetNames();
        $this->assertContains('1.차량말소신청서', $names, '탭1 = 말소신청서');
        $this->assertContains('2.계약서', $names, '탭2 = 계약서(graft)');

        // 탭1 셀
        $this->assertSame('11가1111', $ss->getSheetByName('1.차량말소신청서')->getCell('A11')->getValue());
        $this->assertSame('홍길동', $ss->getSheetByName('1.차량말소신청서')->getCell('D6')->getValue());

        // 탭2 셀 (graft 된 시트, Sheet!Cell 로 기입)
        $this->assertSame('KMHTEST0000123456', $ss->getSheetByName('2.계약서')->getCell('B50')->getValue());
        $this->assertStringContainsString('CFR', (string) $ss->getSheetByName('2.계약서')->getCell('D50')->getValue());
    }

    public function test_filename_uses_merged_label(): void
    {
        $v = Vehicle::create(['vehicle_number' => '22나2222', 'sales_channel' => 'export']);
        $name = (new DocumentFiller($v))->filename('deregistration_set');

        $this->assertStringContainsString('말소신청서_계약서', $name);
        $this->assertStringContainsString('22나2222', $name);
        $this->assertStringEndsWith('.xlsx', $name);
    }
}
