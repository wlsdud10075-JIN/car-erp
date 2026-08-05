<?php

namespace Tests\Feature;

use App\Models\Vehicle;
use App\Services\Documents\DocumentFiller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use Tests\TestCase;

/**
 * 통관 SET 「말소증」 B6:E6 배경색 (jin 2026-08-05).
 *
 * B6 은 `=구매리스트!D3` **수식 셀**이라 자동기입 대상이 아닌데 노란 마커(FFC000)로
 * 칠해져 있어서, DocumentFiller 의 노란셀 정리가 fill 을 지워 **흰 칸으로 인쇄**됐다.
 * 옆칸 A6("제")은 연파랑(FFDBE5F2)이라 한 줄에서 색이 끊겨 보인다.
 *
 * ⚠️ 검증은 템플릿이 아니라 **생성물**로 한다(SKILLS §8 #37) — 템플릿만 고쳐 두고
 * 필러가 다시 지우면 화면에서는 그대로 흰색이다.
 */
class ClearanceDeregistrationFillTest extends TestCase
{
    use RefreshDatabase;

    private function vehicle(): Vehicle
    {
        return Vehicle::create([
            'vehicle_number' => '19더9065',
            'sales_channel' => 'export',
            'currency' => 'USD',
            'exchange_rate' => 1438,
            'dhl_request' => false,
            'registration_number' => '2024-12345',
        ]);
    }

    public function test_deregistration_b6_keeps_the_same_fill_as_a6(): void
    {
        $sheet = (new DocumentFiller($this->vehicle()))
            ->spreadsheet('clearance')
            ->getSheetByName('말소증');

        $a6 = $sheet->getStyle('A6')->getFill();
        $b6 = $sheet->getStyle('B6')->getFill();

        $this->assertSame(Fill::FILL_SOLID, $b6->getFillType(), 'B6 fill 이 제거돼 흰 칸이 됐다');
        $this->assertSame(
            $a6->getStartColor()->getARGB(),
            $b6->getStartColor()->getARGB(),
            'B6:E6 배경이 A6("제") 와 달라 한 줄에서 색이 끊긴다'
        );
    }

    /** 병합 범위 전체가 같은 색이어야 한다(좌상단만 칠하면 인쇄에서 어긋난다). */
    public function test_merged_range_is_filled_uniformly(): void
    {
        $sheet = (new DocumentFiller($this->vehicle()))
            ->spreadsheet('clearance')
            ->getSheetByName('말소증');

        $expected = $sheet->getStyle('A6')->getFill()->getStartColor()->getARGB();

        foreach (['B6', 'C6', 'D6', 'E6'] as $coord) {
            $this->assertSame(
                $expected,
                $sheet->getStyle($coord)->getFill()->getStartColor()->getARGB(),
                "$coord 배경이 다르다"
            );
        }
    }

    /** 색을 바꾸느라 cascade 수식을 깨뜨리지 않았는지 — 값이 구매리스트 D3 를 그대로 받아야 한다. */
    public function test_registration_number_still_cascades_into_b6(): void
    {
        $sheet = (new DocumentFiller($this->vehicle()))
            ->spreadsheet('clearance')
            ->getSheetByName('말소증');

        $this->assertSame('=구매리스트!D3', (string) $sheet->getCell('B6')->getValue());
    }
}
