<?php

namespace Tests\Feature;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use Tests\TestCase;

/**
 * 📄 karaba 말소신청서 — 수임자 칸(1.차량말소신청서!C39)이 전화번호(jin 2026-09-22)여야 한다.
 *
 * 흰칸·매핑 없음이라 양식 리터럴이 그대로 인쇄된다(SKILLS §8 #71). 옛 값 731110-1041111 은
 * 3세트 전수 스캔에서 karaba 이 한 칸뿐이었다. 도구 = scripts/fix-karaba-importer-phone.php (--verify).
 * ⚠️ 기능 테스트로는 원리상 못 잡는다 — 서류는 정상 생성되고 그 칸만 옛 번호가 찍힌다.
 */
class KarabaDeregistrationImporterPhoneTest extends TestCase
{
    public function test_karaba_deregistration_application_prints_the_phone_number(): void
    {
        $path = resource_path('templates/karaba/deregistration_application.xlsx');
        $ws = IOFactory::load($path)->getSheetByName('1.차량말소신청서');
        $v = $ws->getCell('C39')->getValue();
        $v = $v instanceof RichText ? $v->getPlainText() : (string) $v;

        $this->assertSame('010-4703-0627', $v, 'karaba 말소신청서 수임자 칸이 전화번호가 아니다');

        $old = 0;
        foreach ($ws->getRowIterator() as $row) {
            foreach ($row->getCellIterator() as $cell) {
                $c = $cell->getValue();
                $c = $c instanceof RichText ? $c->getPlainText() : $c;
                if (is_string($c) && str_contains($c, '731110-1041111')) {
                    $old++;
                }
            }
        }
        $this->assertSame(0, $old, '옛 번호 731110-1041111 이 같은 시트에 남아 있다');
    }
}
