<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Vehicle;
use App\Services\Documents\DocumentFiller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use Tests\TestCase;

/**
 * 차량 등록번호 → 통관 SET 구매리스트 D3 기입 (2026-06-16).
 * D3 은 말소증 "제 [등록번호] 호" 로 cascade(=구매리스트!D3) 되는 칸.
 */
class ClearanceRegistrationNumberTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_number_fills_clearance_d3(): void
    {
        $v = Vehicle::create([
            'vehicle_number' => 'REG-1', 'sales_channel' => 'export',
            'currency' => 'USD', 'exchange_rate' => 1438, 'dhl_request' => false,
            'registration_number' => '2024-12345',
        ]);

        $ss = (new DocumentFiller($v))->spreadsheet('clearance');
        $cell = $ss->getSheetByName('구매리스트')->getCell('D3')->getValue();

        $this->assertSame('2024-12345', (string) $cell);
    }

    public function test_blank_registration_number_leaves_d3_empty(): void
    {
        $v = Vehicle::create([
            'vehicle_number' => 'REG-2', 'sales_channel' => 'export',
            'currency' => 'USD', 'exchange_rate' => 1438, 'dhl_request' => false,
        ]);

        $ss = (new DocumentFiller($v))->spreadsheet('clearance');
        $cell = $ss->getSheetByName('구매리스트')->getCell('D3')->getValue();

        $this->assertSame('', (string) $cell);
    }

    // ① 차량등록증 자동차등록번호 → 구매리스트 G3 (한글/영문등록증 cascade)
    public function test_reg_cert_number_fills_clearance_g3(): void
    {
        $v = Vehicle::create([
            'vehicle_number' => 'REG-3', 'sales_channel' => 'export',
            'currency' => 'USD', 'exchange_rate' => 1438, 'dhl_request' => false,
            'reg_cert_number' => 'CERT-9999',
        ]);

        $ss = (new DocumentFiller($v))->spreadsheet('clearance');

        $this->assertSame('CERT-9999', (string) $ss->getSheetByName('구매리스트')->getCell('G3')->getValue());
    }

    // ③ I6 차종 영문변환 수식 — 실 NICE 포맷("승용 중형" 띄어쓰기) 매칭
    public function test_clearance_i6_formula_uses_real_vehicle_form_format(): void
    {
        $v = Vehicle::create([
            'vehicle_number' => 'REG-4', 'sales_channel' => 'export',
            'currency' => 'USD', 'exchange_rate' => 1438, 'dhl_request' => false,
        ]);

        $i6 = (string) (new DocumentFiller($v))->spreadsheet('clearance')->getSheetByName('구매리스트')->getCell('I6')->getValue();

        $this->assertStringContainsString('승용 중형', $i6);   // 띄어쓰기 포맷
        $this->assertStringContainsString('Medium Passenger', $i6);
        $this->assertStringNotContainsString('중형승용', $i6);  // 옛 붙여쓰기(버그) 아님
    }

    // ⑤ 차량인보이스 상호 첫 줄 = 기능설정 브랜드(대문자), 나머지 줄 보존
    public function test_invoice_brand_header_follows_setting(): void
    {
        Setting::updateOrCreate(['key' => 'sidebar_brand'], ['value' => 'Heyman']);

        $v = Vehicle::create([
            'vehicle_number' => 'REG-5', 'sales_channel' => 'export',
            'currency' => 'USD', 'exchange_rate' => 1438, 'dhl_request' => false,
        ]);

        $a3 = (new DocumentFiller($v))->spreadsheet('clearance')->getSheetByName('차량인보이스')->getCell('A3')->getValue();
        $text = $a3 instanceof RichText ? $a3->getPlainText() : (string) $a3;
        $lines = explode("\n", $text);

        $this->assertSame('HEYMAN LTD.,', trim($lines[0]));        // 첫 줄 = 대문자 브랜드
        $this->assertStringContainsString('Sangidaehak-ro', $text); // 주소 줄 보존
    }
}
