<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Vehicle;
use App\Services\Documents\DocumentFiller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use Tests\TestCase;

/**
 * 2026-06-29 서류 매핑 수정:
 *  - 말소계약서 D50 = 인코텀즈(FOB/CFR) + ' INCHOEN PORT' (미입력 시 F.O.B 유지)
 *  - 통관SET 구매리스트 B12 = RORO 면 'RORO'(차량인보이스 G2/G3 cascade), 아니면 컨테이너번호
 */
class DocumentMappingFixesTest extends TestCase
{
    use RefreshDatabase;

    public function test_deregistration_contract_d50_uses_incoterms(): void
    {
        $v = Vehicle::create(['vehicle_number' => '01가0001', 'sales_channel' => 'export', 'incoterms' => 'CFR']);
        $ss = (new DocumentFiller($v))->spreadsheet('deregistration_contract');

        $this->assertSame('CFR INCHOEN PORT', $ss->getSheetByName('2.계약서')->getCell('D50')->getValue());
    }

    public function test_deregistration_contract_d50_fallback_when_no_incoterms(): void
    {
        $v = Vehicle::create(['vehicle_number' => '01가0002', 'sales_channel' => 'export']);
        $ss = (new DocumentFiller($v))->spreadsheet('deregistration_contract');

        $this->assertSame('F.O.B INCHOEN PORT', $ss->getSheetByName('2.계약서')->getCell('D50')->getValue());
    }

    public function test_clearance_b12_shows_roro_and_cascades(): void
    {
        $v = Vehicle::create([
            'vehicle_number' => '01가0003', 'sales_channel' => 'export',
            'shipping_method' => 'RORO', 'container_number' => null,
        ]);
        $ss = (new DocumentFiller($v))->spreadsheet('clearance');

        $this->assertSame('RORO', $ss->getSheetByName('구매리스트')->getCell('B12')->getValue());
        // 차량인보이스 G2 = =구매리스트!B12 cascade
        $this->assertSame('RORO', $ss->getSheetByName('차량인보이스')->getCell('G2')->getCalculatedValue());
    }

    public function test_clearance_b12_shows_container_number_when_container(): void
    {
        $v = Vehicle::create([
            'vehicle_number' => '01가0004', 'sales_channel' => 'export',
            'shipping_method' => 'CONTAINER', 'container_number' => 'ABCU1234567',
        ]);
        $ss = (new DocumentFiller($v))->spreadsheet('clearance');

        $this->assertSame('ABCU1234567', $ss->getSheetByName('구매리스트')->getCell('B12')->getValue());
    }

    /**
     * HEYMAN 템플릿 셀 편집 후 무결성 — 재저장한 heyman 양식이 정상 생성되고
     * (clearance 크로스시트 cascade 보존) 편집한 TEL/FAX 가 반영되는지.
     */
    public function test_heyman_templates_generate_correctly_after_edits(): void
    {
        Setting::create(['key' => 'company_template_set', 'value' => 'heyman', 'type' => 'string']);
        $v = Vehicle::create([
            'vehicle_number' => '01가0005', 'sales_channel' => 'export',
            'shipping_method' => 'RORO', 'container_number' => null, 'incoterms' => 'CFR',
        ]);

        // clearance — cascade(=구매리스트!B12) 가 재저장 후에도 살아있어야(수식 무결성)
        $clr = (new DocumentFiller($v))->spreadsheet('clearance');
        $this->assertSame('RORO', $clr->getSheetByName('구매리스트')->getCell('B12')->getValue());
        $this->assertSame('RORO', $clr->getSheetByName('차량인보이스')->getCell('G2')->getCalculatedValue());
        $this->assertStringContainsString('82-10-9009-9977', (string) $clr->getSheetByName('차량팩킹')->getCell('A3')->getValue());

        // container/roro 인보이스 TEL
        $ci = (new DocumentFiller($v))->spreadsheet('container_invoice_packing');
        $this->assertStringContainsString('82-10-9009-9977', (string) $ci->getSheetByName('INVOICE')->getCell('B3')->getValue());
        $this->assertStringContainsString('FAX:82-505-366-9977', (string) $ci->getSheetByName('INVOICE')->getCell('B3')->getValue());

        // 말소계약서 E5 fax(heyman) + D50 incoterms. FAX 서식 정규화(2026-07-21 jin): 공백 제거 82-505-366-9977.
        $dc = (new DocumentFiller($v))->spreadsheet('deregistration_contract');
        $e5 = $dc->getSheetByName('2.계약서')->getCell('E5')->getValue();
        $e5txt = $e5 instanceof RichText ? $e5->getPlainText() : (string) $e5;
        $this->assertStringContainsString('82-505-366-9977', $e5txt);
        $this->assertStringNotContainsString('031 - 499 - 1989', $e5txt);
        $this->assertSame('CFR INCHOEN PORT', $dc->getSheetByName('2.계약서')->getCell('D50')->getValue());
    }
}
