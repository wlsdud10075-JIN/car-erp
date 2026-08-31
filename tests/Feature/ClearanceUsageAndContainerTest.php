<?php

namespace Tests\Feature;

use App\Models\Vehicle;
use App\Services\Documents\DocumentFiller;
use App\Services\Documents\Mappings\ClearanceSetMapping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * 통관 SET 두 건 (jin 2026-08-31).
 *
 * ① **용도/Usage 가 양식 리터럴로 박혀 있었다.** `heyman` 세트에만 cascade 수식이 있고
 *    `system`(ssancarerp)·`karaba` 는 샘플 리터럴(`자가용` / `Private car`)이었다.
 *    - 영문 P4 는 **노란칸**이라 `DocumentFiller` 가 「매핑 없는 샘플값」으로 보고 **비운다** → 공란 인쇄.
 *    - 한글 P4 는 흰칸이라 안 지워지고 **모든 차량에 「자가용」이 인쇄**된다 — 영업용·관용 차에 거짓.
 *      비어 있으면 눈에 띄지만 「자가용」은 그럴듯해서 **더 조용하다**(실측 heymanerp 영업용 3 대 실재).
 *
 * ② **컨테이너 번호가 적혀 있으면 그걸 쓴다.** 종전엔 RORO 면 무조건 `'RORO'` 를 찍어,
 *    RORO 로 보내며 번호로 관리하는 ssancarerp 에서 그 번호가 서류에서 사라졌다.
 *
 * ⚠️ 둘 다 **화면·예외로는 안 드러난다** — 서류는 정상 생성되고 칸만 비거나 틀린다.
 *    그래서 ①은 3 세트 양식을 **정적 대조**하고, ②는 매핑 함수를 **직접 호출**해 표로 검증한다.
 */
class ClearanceUsageAndContainerTest extends TestCase
{
    use RefreshDatabase;

    private const KOR = '=구매리스트!B8';

    private const ENG = '=SUBSTITUTE(SUBSTITUTE(SUBSTITUTE(구매리스트!B8,"자가용","Private Car"),"영업용","Business"),"관용","Official")';

    /** 매핑의 B12 resolver 를 꺼내 그대로 호출한다(양식 좌표까지 함께 고정). */
    private function containerCell(array $attrs): mixed
    {
        $cells = ClearanceSetMapping::config()['cells'];
        $this->assertArrayHasKey('B12', $cells, '컨테이너 NO 좌표가 B12 가 아니게 됐다');

        return ($cells['B12'])(new Vehicle($attrs));
    }

    // ── ① 용도 cascade — 3 세트가 같아야 한다 ──────────────────────────

    public function test_every_template_set_cascades_the_usage_field(): void
    {
        foreach (['system', 'heyman', 'karaba'] as $set) {
            $path = resource_path("templates/{$set}/clearance_set.xlsx");
            $this->assertFileExists($path);
            $ss = IOFactory::createReaderForFile($path)->load($path);

            foreach ([['한글등록증', self::KOR], ['영문등록증', self::ENG]] as [$sheet, $want]) {
                $val = $ss->getSheetByName($sheet)->getCell('P4')->getValue();

                $this->assertIsString($val,
                    "{$set}/{$sheet}!P4 가 수식이 아니라 리터럴이다 — 영문은 노란칸이라 비워지고, "
                    .'한글은 모든 차량에 그 값이 그대로 인쇄된다.');
                $this->assertSame($want, $val, "{$set}/{$sheet}!P4 cascade 수식이 다르다");
            }
        }
    }

    public function test_usage_source_cell_is_still_mapped(): void
    {
        // cascade 의 뿌리(B8)가 사라지면 세 세트가 조용히 전부 빈칸이 된다.
        $cells = ClearanceSetMapping::config()['cells'];
        $this->assertArrayHasKey('B8', $cells, '용도 cascade 의 출처 B8 이 매핑에서 사라졌다');

        $this->assertSame('영업용', ($cells['B8'])(new Vehicle(['nice_reg_use_type' => '영업용'])));
    }

    // ── ② 컨테이너 번호 우선 ──────────────────────────────────────────

    public function test_written_container_number_wins_even_for_roro(): void
    {
        $this->assertSame('ABCD1234567', $this->containerCell([
            'shipping_method' => 'RORO',
            'container_number' => 'ABCD1234567',
            'bl_loading_location' => '평택',
        ]), 'RORO 라는 이유로 적어둔 컨테이너 번호가 버려졌다');
    }

    public function test_nothing_changes_when_there_is_no_container_number(): void
    {
        // 순수 확대 — 번호가 없는 차는 한 대도 안 바뀐다(SKILLS §8 #55).
        $this->assertSame('RORO', $this->containerCell([
            'shipping_method' => 'RORO', 'container_number' => null, 'bl_loading_location' => '평택',
        ]), 'RORO + 번호없음 은 종전대로 RORO 여야 한다');

        $this->assertSame('평택', $this->containerCell([
            'shipping_method' => 'CONTAINER', 'container_number' => '', 'bl_loading_location' => '평택',
        ]), 'CONTAINER + 번호없음 은 종전대로 반입지여야 한다');

        $this->assertSame('평택', $this->containerCell([
            'shipping_method' => null, 'container_number' => null, 'bl_loading_location' => '평택',
        ]), '선적방법 미입력 + 번호없음 은 종전대로 반입지여야 한다');
    }

    public function test_container_shipment_keeps_its_number(): void
    {
        $this->assertSame('ZZZZ9999999', $this->containerCell([
            'shipping_method' => 'CONTAINER',
            'container_number' => 'ZZZZ9999999',
            'bl_loading_location' => '평택',
        ]));
    }

    // ── 생성물로 확인 — 배열이 아니라 실제 파일의 셀을 읽는다 (SKILLS §8 #37) ──

    public function test_generated_document_prints_the_real_usage(): void
    {
        $v = Vehicle::create([
            'vehicle_number' => '11가1111', 'sales_channel' => 'export', 'currency' => 'USD',
            'nice_reg_use_type' => '영업용',
        ]);

        foreach (['system', 'heyman', 'karaba'] as $set) {
            config(['company.template_set' => $set]);
            $ss = (new DocumentFiller($v))->spreadsheet('clearance');

            $this->assertSame('영업용', (string) $ss->getSheetByName('한글등록증')->getCell('P4')->getCalculatedValue(),
                "{$set} — 한글등록증 용도가 차량 값을 안 따라온다");
            $this->assertSame('Business', (string) $ss->getSheetByName('영문등록증')->getCell('P4')->getCalculatedValue(),
                "{$set} — 영문등록증 Usage 가 비거나 번역이 안 됐다");
        }

        // 자가용 = 운영 266 대(3 사 전수 중 사실상 전부). 표기는 `Private Car` 여야 한다(jin 2026-08-31).
        $p = Vehicle::create([
            'vehicle_number' => '33다3333', 'sales_channel' => 'export', 'currency' => 'USD',
            'nice_reg_use_type' => '자가용',
        ]);
        foreach (['system', 'heyman', 'karaba'] as $set) {
            config(['company.template_set' => $set]);
            $ss = (new DocumentFiller($p))->spreadsheet('clearance');

            $this->assertSame('자가용', (string) $ss->getSheetByName('한글등록증')->getCell('P4')->getCalculatedValue());
            $this->assertSame('Private Car', (string) $ss->getSheetByName('영문등록증')->getCell('P4')->getCalculatedValue(),
                "{$set} — 자가용은 「Private Car」로 나와야 한다");
        }
    }

    public function test_generated_document_prints_the_container_number_for_roro(): void
    {
        $v = Vehicle::create([
            'vehicle_number' => '22나2222', 'sales_channel' => 'export', 'currency' => 'USD',
            'shipping_method' => 'RORO', 'container_number' => 'ABCD1234567',
        ]);

        config(['company.template_set' => 'system']);
        $ss = (new DocumentFiller($v))->spreadsheet('clearance');

        $this->assertSame('ABCD1234567',
            (string) $ss->getSheetByName('구매리스트')->getCell('B12')->getCalculatedValue());
        foreach (['G2', 'G3'] as $c) {
            $this->assertSame('ABCD1234567',
                (string) $ss->getSheetByName('차량인보이스')->getCell($c)->getCalculatedValue(),
                "차량인보이스!{$c} 에 컨테이너 번호가 안 내려왔다");
        }
    }

    // ── ③ 긴 번호가 잘리지 않는다 (jin 2026-08-31) ────────────────────

    /**
     * G 열 너비가 **ISO 규격 11 자** 기준(12.25)이었는데 두 회사 모두 자체 관리코드·2 개 병기를 적고 있었다.
     * 운영 실측: `6.06_G RORO 11-27_10`(20 자, ssancarerp 3,571 건) · `EITU9093137 /  EMCKPM6885`(25 자).
     * ⇒ 열을 넓히고(20 자를 9pt 그대로 수용) 자동축소를 안전망으로 켠다.
     */
    public function test_container_cell_is_wide_enough_and_never_clips(): void
    {
        // 🚨 차량팩킹도 대상이다 — `=차량인보이스!G2` 로 값은 미러하지만 **열 너비는 자기 것**을 쓴다.
        //    인보이스만 넓히면 팩킹리스트에서는 그대로 잘린다(SKILLS §8 #69).
        foreach (['system', 'heyman', 'karaba'] as $set) {
            $path = resource_path("templates/{$set}/clearance_set.xlsx");
            $ss = IOFactory::createReaderForFile($path)->load($path);

            foreach (['차량인보이스', '차량팩킹'] as $sheet) {
                $ws = $ss->getSheetByName($sheet);

                $this->assertGreaterThanOrEqual(19.5, (float) $ws->getColumnDimension('G')->getWidth(),
                    "{$set}/{$sheet} — 컨테이너 NO 열이 좁아 20 자짜리가 잘린다(운영 실측 3,571 건).");

                foreach (['G2', 'G3'] as $c) {
                    $a = $ws->getStyle($c)->getAlignment();
                    $this->assertTrue($a->getShrinkToFit(),
                        "{$set}/{$sheet}/{$c} — 자동축소가 꺼져 있어 더 긴 값(25 자)이 잘린다.");
                    // 엑셀은 자동축소와 줄바꿈을 동시에 못 켠다 — wrap 이 켜지면 축소가 무시된다.
                    $this->assertFalse($a->getWrapText(),
                        "{$set}/{$sheet}/{$c} — 줄바꿈이 켜지면 자동축소가 무효가 된다.");
                }
            }
        }
    }

    /**
     * 양식 제작 당시 차량의 컨테이너 번호(`DFSU6646075`)가 라벨과 함께 박혀 있었다.
     * **흰칸이라 `DocumentFiller` 가 안 지운다** — 노란칸이었으면 비워졌을 것이다(#71 과 같은 뿌리,
     * 칸 색깔로 운명이 갈린다). 병합 폭이 좁아 **잘려서 보이지 않았을 뿐**이라 아무도 못 봤다.
     * 🧭 **잘려서 안 보이는 것은 「없는 것」이 아니다** — 폭·병합을 건드리면 드러난다.
     */
    public function test_no_sample_container_number_is_baked_into_the_forms(): void
    {
        foreach (['system', 'heyman', 'karaba'] as $set) {
            $ss = IOFactory::createReaderForFile(resource_path("templates/{$set}/clearance_set.xlsx"))
                ->load(resource_path("templates/{$set}/clearance_set.xlsx"));

            foreach (['차량인보이스', '차량팩킹'] as $sheet) {
                $ws = $ss->getSheetByName($sheet);
                foreach (['E2' => 'Reference No.', 'E3' => 'Reference code.'] as $coord => $label) {
                    $raw = $ws->getCell($coord)->getValue();
                    $text = is_object($raw) ? $raw->getPlainText() : (string) $raw;

                    $this->assertSame($label, $text,
                        "{$set}/{$sheet}!{$coord} — 라벨 외의 값(샘플 번호)이 박혀 있다. "
                        .'매핑도 없고 흰칸이라 그대로 인쇄된다.');
                }
            }
        }
    }

    /** 다른 양식에도 같은 샘플이 남지 않았는지 — 9 종 × 3 세트 전수. */
    public function test_the_sample_number_is_gone_from_every_template(): void
    {
        foreach (['system', 'heyman', 'karaba'] as $set) {
            foreach (glob(resource_path("templates/{$set}/*.xlsx")) as $path) {
                $ss = IOFactory::createReaderForFile($path)->load($path);
                foreach ($ss->getWorksheetIterator() as $ws) {
                    foreach ($ws->getRowIterator() as $row) {
                        foreach ($row->getCellIterator() as $cell) {
                            $v = $cell->getValue();
                            if ($v === null) {
                                continue;
                            }
                            $t = is_object($v) ? $v->getPlainText() : (string) $v;
                            $this->assertStringNotContainsString('DFSU6646075', $t,
                                basename($path).' '.$ws->getTitle().'!'.$cell->getCoordinate()
                                .' 에 샘플 컨테이너 번호가 남아 있다.');
                        }
                    }
                }
            }
        }
    }

    // ── 생성물로 확인 — 인보이스 G2·G3 가 그 값을 받는다 ───────────────

    public function test_invoice_cells_reference_the_container_cell(): void
    {
        // 양식이 참조를 D12(선적방법) 등으로 바꿔 달면 이 검사가 잡는다.
        foreach (['system', 'heyman', 'karaba'] as $set) {
            $path = resource_path("templates/{$set}/clearance_set.xlsx");
            $ws = IOFactory::createReaderForFile($path)->load($path)->getSheetByName('차량인보이스');
            foreach (['G2', 'G3'] as $c) {
                $this->assertSame('=구매리스트!B12', $ws->getCell($c)->getValue(),
                    "{$set}/차량인보이스!{$c} 가 컨테이너 NO 칸(B12)을 안 본다");
            }
        }
    }
}
