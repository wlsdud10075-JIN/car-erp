<?php

namespace Tests\Feature;

use App\Models\Consignee;
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

    /**
     * ③ I6 차종 영문 — 2026-09-03 부터 **수식이 아니라 매핑**(`DocValue::vehicleFormEn`)이 채운다.
     *
     * 종전엔 여기서 수식 문자열을 검사했는데, 그건 「승용」만 보증했다. 옛 SUBSTITUTE 는
     * 승합·화물을 「중형 승합」 순서로 찾아 실제 값 **「승합 중형」을 못 잡고 한글을 그대로 인쇄**했고
     * (heymanerp 실측 1대), 승용 대형만 `HEAVY Passenger`·승합은 `Ven` 오타였다.
     * ⇒ 이제 **생성물의 값**을 본다(SKILLS §8 #37) — 수식이 되살아나면 값이 한글로 나와 여기서 걸린다.
     */
    public function test_clearance_i6_converts_every_vehicle_form_to_english(): void
    {
        $cases = [
            '승용 중형' => 'Medium Passenger',   // heymanerp 148 대
            '승용 대형' => 'Large Passenger',    // 71 대 — 옛 수식은 HEAVY 였다
            '승용 소형' => 'Small Passenger',    // 1 대
            '승합 중형' => 'Medium Van',         // 1 대 — 옛 수식이 못 잡던 순서·오타(Ven)
            '화물 대형' => 'Large Cargo',
            '중형승용' => 'Medium Passenger',    // 옛 적재분 붙여쓰기도 같은 결과
        ];

        foreach ($cases as $form => $want) {
            $v = Vehicle::create([
                'vehicle_number' => 'REG-4-'.crc32($form), 'sales_channel' => 'export',
                'currency' => 'USD', 'exchange_rate' => 1438, 'dhl_request' => false,
                'nice_reg_vehicle_form' => $form,
            ]);

            $ss = (new DocumentFiller($v))->spreadsheet('clearance');

            $this->assertSame($want, (string) $ss->getSheetByName('구매리스트')->getCell('I6')->getValue(),
                "차종 '{$form}' 이 영문으로 안 바뀌었다 — 양식에 옛 수식이 되살아났는지 확인할 것");
            $this->assertSame($want, (string) $ss->getSheetByName('영문등록증')->getCell('M4')->getCalculatedValue(),
                "차종 '{$form}' 이 영문등록증까지 cascade 안 됐다");
            $this->assertSame($form, (string) $ss->getSheetByName('구매리스트')->getCell('G6')->getValue(),
                '한글 차종은 원본 그대로여야 한다');
        }
    }

    /** 모르는 값은 영문으로 위장하지 않는다 — heymanerp 에 쓰레기값(`205 004`) 1 대가 실재한다. */
    public function test_clearance_i6_passes_unknown_vehicle_form_through(): void
    {
        $v = Vehicle::create([
            'vehicle_number' => 'REG-4X', 'sales_channel' => 'export',
            'currency' => 'USD', 'exchange_rate' => 1438, 'dhl_request' => false,
            'nice_reg_vehicle_form' => '205 004',
        ]);

        $this->assertSame('205 004', (string) (new DocumentFiller($v))
            ->spreadsheet('clearance')->getSheetByName('구매리스트')->getCell('I6')->getValue());
    }

    // 구매리스트 B14 컨사이니 — ID 줄에 'Business number : ' 라벨 (jin 2026-06-25)
    public function test_clearance_b14_consignee_labels_business_number(): void
    {
        $consignee = Consignee::create([
            'name' => 'VIA AUTO', 'id_value' => '812326016', 'address' => 'PRISHTINE, KOSOVO',
        ]);
        $v = Vehicle::create([
            'vehicle_number' => 'REG-6', 'sales_channel' => 'export',
            'currency' => 'USD', 'exchange_rate' => 1438, 'dhl_request' => false,
            'consignee_id' => $consignee->id,
        ]);

        $b14 = (string) (new DocumentFiller($v))->spreadsheet('clearance')->getSheetByName('구매리스트')->getCell('B14')->getValue();

        $this->assertStringContainsString('Business number : 812326016', $b14);
        $this->assertStringContainsString('VIA AUTO', $b14);   // 이름 줄 보존
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
