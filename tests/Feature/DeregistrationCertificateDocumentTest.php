<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use App\Services\Documents\DocumentFiller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * 말소증(한글·영문) — 서류탭 「매입」 위임장 옆 (jin 2026-09-03).
 *
 * 이 부류는 **기능 테스트로 원리상 못 잡는다** — 서류는 늘 정상 생성되고 칸만 비거나 틀린다.
 * 그래서 매핑 배열이 아니라 **생성물의 셀을 실제로 읽어** 확인한다(SKILLS §8 #37).
 *
 * 지키는 것 4가지:
 *  ① `#REF!` 잔존 0 — 원본이 초기문서라 데이터 칸이 전부 `=#REF!` 였다. 되살아나면
 *    `DocumentFiller::writeCell` 이 수식 셀을 안 덮어써서 **매핑이 통째로 조용히 무시**된다.
 *  ② 소유자 3칸이 회사별로 갈린다 — 특히 karaba 법인등록번호가 **싼카 것이 아니어야** 한다.
 *    지금도 karaba 의 다른 양식 4곳(위임장 C25 · 통관SET 말소증 K21 · 한글/영문등록증 M8)에
 *    싼카 번호 `120111-0922270` 이 남아 있다 — 이 서류로 그 실수가 번지지 않게 못박는다.
 *  ③ 날짜 서식이 한글=한국식 / 영문=미국식 (jin 2026-09-03).
 *  ④ 도장·직인(관인 + 수입증지)이 시트마다 2개씩 보존 — jin "지금 있는 파일 그대로".
 */
class DeregistrationCertificateDocumentTest extends TestCase
{
    use RefreshDatabase;

    private const TYPE = 'deregistration_certificate';

    private const SHEET_KO = '말소증';

    private const SHEET_EN = '영문말소증';

    private const SETS = ['system', 'heyman', 'karaba'];

    /** 회사별 소유자 — 빌더 상수와 같은 값. 여기서 한 번 더 못박아 양식이 조용히 바뀌면 실패시킨다. */
    private const OWNER = [
        'system' => ['ko' => '주식회사 싼카', 'en' => 'SSANCAR LTD.', 'corp' => '120111-0922270'],
        'heyman' => ['ko' => '주식회사 헤이맨', 'en' => 'HEYMAN LTD.', 'corp' => '110111-7526176'],
        'karaba' => ['ko' => '주식회사 카라바', 'en' => 'KARABA CO., LTD.', 'corp' => '120111-1058941'],
    ];

    private function vehicle(array $overrides = []): Vehicle
    {
        return Vehicle::create(array_merge([
            'vehicle_number' => '19더9065',
            'sales_channel' => 'export',
            'currency' => 'USD',
            'registration_number' => '4115-202403-014223',
            'model_type' => 'A6 50 TDI quattro',
            'nice_reg_vin' => 'WAUZZZ4GXGN013468',
            'nice_reg_engine_no' => 'CRT',
            'nice_reg_vehicle_form' => '승용 중형',
            'nice_reg_use_type' => '자가용',
            'nice_reg_first_date' => '2016-05-20',
            'deregistration_date' => '2026-03-11',
            'mileage' => 109877,
            'year' => 2016,
        ], $overrides));
    }

    // ── ① #REF! 잔존 0 ────────────────────────────────────────────────

    public function test_no_broken_reference_survives_in_any_template(): void
    {
        foreach (self::SETS as $set) {
            $path = resource_path("templates/{$set}/deregistration_certificate.xlsx");
            $this->assertFileExists($path, "{$set} 말소증 양식이 없다");
            $ss = IOFactory::createReaderForFile($path)->load($path);

            foreach ([self::SHEET_KO, self::SHEET_EN] as $sheetName) {
                $sheet = $ss->getSheetByName($sheetName);
                $this->assertNotNull($sheet, "{$set}/{$sheetName} 시트가 없다");

                foreach ($sheet->getRowIterator() as $row) {
                    $it = $row->getCellIterator();
                    $it->setIterateOnlyExistingCells(true);
                    foreach ($it as $cell) {
                        $v = $cell->getValue();
                        $this->assertFalse(is_string($v) && str_contains($v, '#REF!'),
                            "{$set}/{$sheetName}!{$cell->getCoordinate()} 에 #REF! 가 살아났다 — "
                            .'writeCell 이 수식 셀을 안 덮어쓰므로 이 칸의 매핑이 조용히 무시되고 #REF! 가 인쇄된다.');
                    }
                }
            }
        }
    }

    // ── ② 소유자 — 회사별로 갈리고, karaba 가 싼카 번호를 쓰지 않는다 ──

    public function test_owner_block_differs_per_company(): void
    {
        foreach (self::SETS as $set) {
            config(['company.template_set' => $set]);
            $ss = (new DocumentFiller($this->vehicle()))->spreadsheet(self::TYPE);
            $want = self::OWNER[$set];

            $ko = $ss->getSheetByName(self::SHEET_KO);
            $en = $ss->getSheetByName(self::SHEET_EN);

            $this->assertSame($want['ko'], (string) $ko->getCell('E11')->getValue(), "{$set} 한글 상호");
            $this->assertSame($want['en'], (string) $en->getCell('E11')->getValue(), "{$set} 영문 상호");
            $this->assertSame($want['corp'], (string) $ko->getCell('K11')->getValue(), "{$set} 법인등록번호(한글)");
            $this->assertSame($want['corp'], (string) $en->getCell('K11')->getValue(), "{$set} 법인등록번호(영문)");
            $this->assertNotSame('', trim((string) $ko->getCell('E12')->getValue()), "{$set} 한글 주소가 비었다");
            $this->assertNotSame('', trim((string) $en->getCell('E12')->getValue()), "{$set} 영문 주소가 비었다");
        }
    }

    public function test_karaba_does_not_carry_the_ssancar_corporate_number(): void
    {
        // karaba 의 다른 양식 4곳에 실제로 남아 있는 실수다 — 이 서류로 번지면 관공서 서류에 남의 법인번호가 나간다.
        config(['company.template_set' => 'karaba']);
        $ss = (new DocumentFiller($this->vehicle()))->spreadsheet(self::TYPE);

        foreach ([self::SHEET_KO, self::SHEET_EN] as $sheetName) {
            $this->assertNotSame('120111-0922270', (string) $ss->getSheetByName($sheetName)->getCell('K11')->getValue(),
                "karaba/{$sheetName}!K11 에 싼카 법인등록번호가 들어갔다");
        }
    }

    // ── 데이터 기입 ───────────────────────────────────────────────────

    public function test_korean_sheet_is_filled_from_erp(): void
    {
        config(['company.template_set' => 'heyman']);
        $sheet = (new DocumentFiller($this->vehicle()))->spreadsheet(self::TYPE)->getSheetByName(self::SHEET_KO);

        // 「제 ○○ 호」= 매입탭 등록번호 (jin 2026-09-03)
        $this->assertSame('4115-202403-014223', (string) $sheet->getCell('C4')->getValue());
        $this->assertSame('19더9065', (string) $sheet->getCell('E5')->getValue());
        $this->assertSame('승용 중형', (string) $sheet->getCell('K5')->getValue());
        $this->assertSame('109877', (string) $sheet->getCell('K6')->getValue());
        $this->assertSame('A6 50 TDI quattro', (string) $sheet->getCell('E7')->getValue());
        $this->assertSame('WAUZZZ4GXGN013468', (string) $sheet->getCell('K7')->getValue());
        $this->assertSame('CRT', (string) $sheet->getCell('E8')->getValue());
        $this->assertSame('자가용', (string) $sheet->getCell('K9')->getValue());
        $this->assertNotSame('', (string) $sheet->getCell('E10')->getValue(), '최초등록일이 비었다');
        $this->assertNotSame('', (string) $sheet->getCell('E13')->getValue(), '말소등록일이 비었다');
    }

    public function test_english_sheet_uses_romanized_plate_and_english_terms(): void
    {
        config(['company.template_set' => 'heyman']);
        $sheet = (new DocumentFiller($this->vehicle()))->spreadsheet(self::TYPE)->getSheetByName(self::SHEET_EN);

        // 수출서류라 한글 번호판을 그대로 쓰면 안 된다(SKILLS §8 #29).
        $this->assertSame('19DEO9065', (string) $sheet->getCell('E5')->getValue());
        $this->assertSame('Medium Passenger', (string) $sheet->getCell('K5')->getValue());
        $this->assertSame('Private Car', (string) $sheet->getCell('K9')->getValue());
        $this->assertSame('WAUZZZ4GXGN013468', (string) $sheet->getCell('K7')->getValue());
    }

    public function test_business_use_vehicle_is_not_printed_as_private(): void
    {
        // heymanerp 실측 3 대. SKILLS §8 #71 의 그 회귀 — 「자가용」이 그럴듯해서 더 조용하다.
        config(['company.template_set' => 'heyman']);
        $ss = (new DocumentFiller($this->vehicle(['nice_reg_use_type' => '영업용'])))->spreadsheet(self::TYPE);

        $this->assertSame('영업용', (string) $ss->getSheetByName(self::SHEET_KO)->getCell('K9')->getValue());
        $this->assertSame('Business', (string) $ss->getSheetByName(self::SHEET_EN)->getCell('K9')->getValue());
    }

    public function test_missing_nice_data_leaves_blanks_rather_than_inventing_values(): void
    {
        // ssancarerp 적재분은 NICE 칸이 5% 만 차 있다. 없는 값을 만들어 채우면 거짓 서류가 된다.
        // ⚠️ 반드시 3 사를 다 돈다 — 한 세트만 보면 다른 세트의 샘플 잔재를 놓친다(SKILLS §8 #71 이 그 사고).
        $bare = Vehicle::create(['vehicle_number' => '11가1111', 'sales_channel' => 'export', 'currency' => 'USD']);

        foreach (self::SETS as $set) {
            config(['company.template_set' => $set]);
            $ss = (new DocumentFiller($bare))->spreadsheet(self::TYPE);

            foreach (['C4', 'K5', 'K7', 'E8', 'K9'] as $coord) {
                $this->assertSame('', (string) $ss->getSheetByName(self::SHEET_KO)->getCell($coord)->getValue(),
                    "{$set}/한글 {$coord} — 값이 없는데 뭔가 인쇄됐다(양식 샘플 잔재 의심)");
            }
            foreach (['K5', 'K9'] as $coord) {
                $this->assertSame('', (string) $ss->getSheetByName(self::SHEET_EN)->getCell($coord)->getValue(),
                    "{$set}/영문 {$coord} — 값이 없는데 뭔가 인쇄됐다");
            }
        }
    }

    // ── ③ 날짜 서식 — 한글=한국식 / 영문=미국식 ─────────────────────

    public function test_date_format_follows_the_language_of_each_sheet(): void
    {
        foreach (self::SETS as $set) {
            $path = resource_path("templates/{$set}/deregistration_certificate.xlsx");
            $ss = IOFactory::createReaderForFile($path)->load($path);

            foreach (['E10', 'E13'] as $coord) {
                $this->assertSame('yyyy"년"\ m"월"\ d"일";@',
                    $ss->getSheetByName(self::SHEET_KO)->getStyle($coord)->getNumberFormat()->getFormatCode(),
                    "{$set} 한글시트 {$coord} 날짜서식이 한국식이 아니다");
                $this->assertSame('[$-409]mmm" . "dd" . "yy;@',
                    $ss->getSheetByName(self::SHEET_EN)->getStyle($coord)->getNumberFormat()->getFormatCode(),
                    "{$set} 영문시트 {$coord} 날짜서식이 미국식이 아니다");
            }
        }
    }

    // ── ④ 도장·직인 보존 ─────────────────────────────────────────────

    public function test_stamps_survive_generation(): void
    {
        // 관인 + 수입증지 2개. StampSlots 미등록이라 업로드 직인이 덮지 않는다 — 양식 것을 그대로 쓴다.
        foreach (self::SETS as $set) {
            config(['company.template_set' => $set]);
            $ss = (new DocumentFiller($this->vehicle()))->spreadsheet(self::TYPE);

            foreach ([self::SHEET_KO, self::SHEET_EN] as $sheetName) {
                $this->assertSame(2, $ss->getSheetByName($sheetName)->getDrawingCollection()->count(),
                    "{$set}/{$sheetName} 도장·직인이 사라졌다");
            }
        }
    }

    // ── 라우트 ────────────────────────────────────────────────────────

    public function test_route_serves_the_document(): void
    {
        $user = User::factory()->create([
            'permission' => 'admin', 'role' => '관리', 'email_verified_at' => now(),
        ]);
        $v = $this->vehicle();

        $this->actingAs($user)
            ->get(route('erp.vehicles.documents.show', ['id' => $v->id, 'type' => self::TYPE]))
            ->assertOk();
    }
}
