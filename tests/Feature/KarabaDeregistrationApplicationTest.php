<?php

namespace Tests\Feature;

use App\Models\Salesman;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Documents\DocumentFiller;
use App\Services\Documents\StampSlots;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Drawing as SharedDrawing;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use Tests\TestCase;

/**
 * karaba 「말소신청서_계약서」 — 수임자 3줄 인쇄 + 1.차량말소신청서 탭 직인 (jin 2026-09-04).
 *
 * ⚠️ **검증은 매핑·상수 배열이 아니라 생성물의 셀로 한다**(SKILLS §8 #37).
 *    배열만 보면 「노란칸이라 생성 때 지워지는」 함정을 통째로 못 잡는다 — 실제로 그 함정이 있었다:
 *    수임자 3칸은 주황 채움(F4B184)이라 `clearYellowFill()` 이 매핑 없는 값을 지운다(§8 #71).
 *
 * 🚫 이 변경은 **karaba 전용**이다. system(ssancarerp)·heyman 에 번지면 안 된다.
 */
class KarabaDeregistrationApplicationTest extends TestCase
{
    use RefreshDatabase;

    private const SHEET = '1.차량말소신청서';

    /** 직인 앵커 — 신청인 블록 우측. 셀 칸을 넘는 크기라 K 가 아니라 I 에서 시작한다. */
    private const SEAL_ANCHOR = 'I25';

    /** 수임자 블록 — 라벨은 B37~B39, 값은 C37~C39(C37:K37 등 병합). */
    private const AGENT_CELLS = [
        'C37' => '경기도 고양시 덕양구 유산길 9, 203호(내유동)',
        'C38' => '길영채',
        'C39' => '11-95-278048-01',
    ];

    private function template(string $set, string $file): string
    {
        return resource_path("templates/{$set}/{$file}");
    }

    // ── 양식 자체 (정적 — GD·DB 불필요) ─────────────────────────────

    public function test_karaba_template_prints_the_agent_block_without_a_yellow_fill(): void
    {
        $ss = IOFactory::createReader('Xlsx')->load($this->template('karaba', 'deregistration_application.xlsx'));
        $sheet = $ss->getSheetByName(self::SHEET);
        $this->assertNotNull($sheet);

        foreach (self::AGENT_CELLS as $coord => $expected) {
            $this->assertSame($expected, (string) $sheet->getCell($coord)->getValue(), "karaba {$coord}");

            // 🚨 채움이 남아 있으면 서류 생성 때 이 값이 지워진다(§8 #71). 값만 보면 못 잡는다.
            $fill = $sheet->getStyle($coord)->getFill();
            $this->assertNotSame(
                Fill::FILL_SOLID,
                $fill->getFillType(),
                "{$coord} 에 채움이 남아 있다 — 생성 시 값이 지워진다"
            );
        }
        $ss->disconnectWorksheets();
    }

    /** 다른 회사 양식은 그대로여야 한다 — 이 값은 karaba 수임자다. */
    public function test_other_tenants_do_not_get_the_karaba_agent_block(): void
    {
        foreach (['system', 'heyman'] as $set) {
            $ss = IOFactory::createReader('Xlsx')->load($this->template($set, 'deregistration_application.xlsx'));
            $sheet = $ss->getSheetByName(self::SHEET);
            foreach (array_keys(self::AGENT_CELLS) as $coord) {
                $this->assertSame('', (string) $sheet->getCell($coord)->getValue(), "{$set} {$coord} 에 karaba 값이 번졌다");
            }
            $ss->disconnectWorksheets();
        }
    }

    /** FAX — 싼카 번호가 karaba 양식에 남아 있으면 안 된다(파생 스크립트가 「바꾼 칸만」 보증, §8 #75). */
    public function test_karaba_templates_carry_no_ssancar_fax(): void
    {
        $cases = [
            ['clearance_set.xlsx', '차량인보이스', 'A35'],
            ['clearance_set.xlsx', '차량팩킹', 'A35'],
            ['deregistration_contract.xlsx', '2.계약서', 'E5'],
        ];
        foreach ($cases as [$file, $sheetName, $coord]) {
            $ss = IOFactory::createReader('Xlsx')->load($this->template('karaba', $file));
            $value = (string) $ss->getSheetByName($sheetName)->getCell($coord)->getValue();
            $digits = preg_replace('/\D/', '', $value);

            $this->assertStringNotContainsString('5053669977', $digits, "{$file}!{$sheetName}!{$coord} 에 싼카 FAX 가 남아 있다");
            $this->assertStringContainsString('32', $digits, "{$file}!{$sheetName}!{$coord} 에 karaba FAX 가 없다");
            $this->assertStringContainsString('7101881', $digits, "{$file}!{$sheetName}!{$coord} FAX 번호가 다르다");
            $ss->disconnectWorksheets();
        }
    }

    // ── 도장 슬롯 ────────────────────────────────────────────────────

    public function test_apply_seal_slot_exists_only_for_karaba(): void
    {
        $slotsOf = fn (string $set) => collect(StampSlots::all($set)['deregistration_set'] ?? [])
            ->map(fn ($s) => $s['sheet'].'!'.$s['anchor'])->all();

        $this->assertContains(self::SHEET.'!'.self::SEAL_ANCHOR, $slotsOf('karaba'), 'karaba 에 말소신청서 직인 슬롯이 없다');
        foreach (['system', 'heyman'] as $set) {
            $this->assertNotContains(self::SHEET.'!'.self::SEAL_ANCHOR, $slotsOf($set), "{$set} 에 karaba 전용 직인이 번졌다");
        }
    }

    /** 기본 슬롯을 복사하지 않고 얹었는지 — 복사하면 기본이 바뀔 때 karaba 만 뒤처진다. */
    public function test_karaba_inherits_every_default_slot(): void
    {
        $flat = function (array $all) {
            $out = [];
            foreach ($all as $type => $slots) {
                foreach ($slots as $s) {
                    $out[] = $type.':'.$s['sheet'].'!'.$s['anchor'];
                }
            }
            sort($out);

            return $out;
        };
        $default = $flat(StampSlots::all('system'));
        $karaba = $flat(StampSlots::all('karaba'));

        foreach ($default as $slot) {
            $this->assertContains($slot, $karaba, "karaba 가 기본 슬롯 {$slot} 을 잃었다");
        }
        $this->assertCount(count($default) + 1, $karaba, 'karaba 가 추가한 슬롯은 정확히 1개여야 한다');
    }

    /** 도장 앵커는 baked drawing 과 겹치면 안 된다 — 겹치면 이중 도장이 된다(§8 #37 ③). */
    public function test_apply_seal_anchor_has_no_baked_drawing(): void
    {
        $ss = IOFactory::createReader('Xlsx')->load($this->template('karaba', 'deregistration_application.xlsx'));
        $sheet = $ss->getSheetByName(self::SHEET);
        $anchors = [];
        foreach ($sheet->getDrawingCollection() as $drawing) {
            $anchors[] = $drawing->getCoordinates();
        }
        // 비어 있어도 반드시 단언한다 — foreach 만 두면 그림이 0개일 때 「검사 없음」으로 통과한다.
        $this->assertNotContains(self::SEAL_ANCHOR, $anchors, '앵커에 이미 그림이 있다 — clearAnchors 가 필요하다');
        $ss->disconnectWorksheets();
    }

    /**
     * 도장 상자는 **인쇄영역 안**이어야 한다 (jin 2026-09-04 「인보이스와 같은 사이즈」).
     *
     * 요구 크기(8.55cm)가 서명란 K~M(5.8cm)보다 넓어서 칸을 넘는다 — 그건 의도다.
     * 넘으면 안 되는 선은 **인쇄영역 `A1:M39`** 다: M 오른쪽으로 나간 만큼은 인쇄에서 잘리는데
     * 화면·파일은 멀쩡해서 **아무도 모른 채 반쪽 도장이 나간다**.
     * 아래쪽도 「위 임 장」 제목(32행)을 덮으면 안 된다.
     *
     * ⚠️ 기능 테스트로는 원리상 못 잡는다 — 잘려도 서류는 정상 생성된다(SKILLS §8 #71).
     *    그래서 **양식의 실제 열폭·행높이를 읽어** 상자와 대조한다.
     */
    public function test_apply_seal_box_stays_inside_the_print_area(): void
    {
        $ss = IOFactory::createReader('Xlsx')->load($this->template('karaba', 'deregistration_application.xlsx'));
        $sheet = $ss->getSheetByName(self::SHEET);
        $font = $ss->getDefaultStyle()->getFont();

        $this->assertSame('A1:M39', $sheet->getPageSetup()->getPrintArea(), '인쇄영역이 바뀌었다 — 아래 계산의 전제다');

        // ⚠️ 앵커는 **슬롯이 말하는 값**을 쓴다 — 상수를 쓰면 슬롯이 옮겨져도 이 검사가 통과한다
        //    (실제로 그렇게 짰다가, 앵커를 K 로 옮겨 인쇄영역을 넘겨도 초록이 나왔다).
        $slot = collect(StampSlots::all('karaba')['deregistration_set'])->firstWhere('key', 'apply_seal');
        $this->assertNotNull($slot, '말소신청서 직인 슬롯이 없다');
        [$anchorCol, $anchorRow] = [substr($slot['anchor'], 0, 1), (int) substr($slot['anchor'], 1)];

        // 가로 — 앵커 열의 왼쪽 오프셋 + 상자 폭이 M 오른쪽 끝을 넘으면 인쇄에서 잘린다.
        $x = 0;
        $anchorX = null;
        foreach (range('A', 'M') as $col) {
            if ($col === $anchorCol) {
                $anchorX = $x;
            }
            $x += (int) round(SharedDrawing::cellDimensionToPixels($sheet->getColumnDimension($col)->getWidth(), $font));
        }
        $this->assertNotNull($anchorX, '앵커 열이 인쇄영역 밖이다');

        // 세로 — 앵커 행부터 「위 임 장」 제목(32행) 앞까지.
        $rowH = fn (int $r) => (int) round(SharedDrawing::pointsToPixels($sheet->getRowDimension($r)->getRowHeight()));
        $availableH = 0;
        for ($r = $anchorRow; $r < 32; $r++) {
            $availableH += $rowH($r);
        }
        $this->assertSame('위   임   장', trim((string) $sheet->getCell('A32')->getValue()), '위임장 제목 행이 32 가 아니다 — 세로 한계가 바뀐다');
        $ss->disconnectWorksheets();

        $this->assertLessThanOrEqual($x - $anchorX, $slot['width'],
            '직인 가로가 인쇄영역(M 오른쪽 끝)을 넘는다 — 넘은 만큼 인쇄에서 잘린다');
        $this->assertLessThanOrEqual($availableH, $slot['height'],
            '직인 세로가 「위 임 장」 제목을 덮는다');
        $this->assertArrayNotHasKey('exact', $slot, 'exact 를 쓰면 상자 크기로 늘여 박아 도장이 찌그러진다');
    }

    /** 설정화면이 키 문자열을 그대로 보여주면 안 된다 — 슬롯이 생겼으니 라벨도 있어야 한다. */
    public function test_document_label_exists_for_the_set(): void
    {
        $this->assertArrayHasKey('deregistration_set', StampSlots::DOC_LABELS);
        $this->assertNotSame('deregistration_set', StampSlots::DOC_LABELS['deregistration_set']);
    }

    // ── 생성물 (end-to-end) ─────────────────────────────────────────

    /** 🔑 실제로 서류를 만들어 셀을 읽는다 — 매핑·상수만 보면 「생성 때 지워지는」 것을 못 잡는다. */
    public function test_generated_document_keeps_the_agent_block(): void
    {
        Setting::updateOrCreate(['key' => 'company_template_set'], ['value' => 'karaba', 'type' => 'string']);

        $sm = Salesman::firstOrCreate(['name' => 'TESTMAN'], ['type' => 'employee', 'is_active' => true]);
        $vehicle = Vehicle::create([
            'vehicle_number' => '11가1001', 'sales_channel' => 'export',
            'currency' => 'USD', 'exchange_rate' => 1350, 'dhl_request' => false,
            'salesman_id' => $sm->id, 'purchase_price' => 5_000_000,
            'purchase_date' => now()->toDateString(),
            'nice_reg_owner_name' => '홍길동',
        ]);
        $this->actingAs(User::factory()->create([
            'permission' => 'user', 'role' => '수출통관', 'email_verified_at' => now(),
        ]));

        $spreadsheet = (new DocumentFiller($vehicle))->spreadsheet('deregistration_set');
        $sheet = $spreadsheet->getSheetByName(self::SHEET);
        $this->assertNotNull($sheet, '생성물에 말소신청서 탭이 없다');

        foreach (self::AGENT_CELLS as $coord => $expected) {
            $this->assertSame($expected, (string) $sheet->getCell($coord)->getValue(),
                "생성물 {$coord} — 수임자 값이 지워졌다(노란칸 판정 확인)");
        }
    }

    /**
     * 🔑 **도장이 실제로 몇 px 로 박히는지**를 두 생성물에서 읽어 **서로 대조**한다.
     *    요구가 「Proforma Invoice 와 같은 사이즈」(jin 2026-09-04)이므로 그 문장 그대로 단언한다.
     *
     * 슬롯 숫자만 봐서는 못 잡는다 — `overlayStamp` 가 상자 안에서 **비율맞춤(contain)** 하므로,
     * 세로가 낮으면 가로가 저절로 깎인다(구 233×85 → 실제 174×85). 그래서 karaba 직인과
     * **같은 비율(1902×930)** 의 이미지를 올려 두고 박힌 Drawing 크기를 직접 확인한다.
     */
    public function test_generated_seal_is_the_same_size_as_on_the_proforma_invoice(): void
    {
        if (! function_exists('imagepng')) {
            $this->markTestSkipped('GD 없음 — 도장 이미지를 만들 수 없다');
        }
        Setting::updateOrCreate(['key' => 'company_template_set'], ['value' => 'karaba', 'type' => 'string']);

        // karaba 운영 직인의 실측 비율(사업자 고무인 블록 1902×930). 내용은 무관 — 크기만 본다.
        $img = imagecreatetruecolor(1902, 930);
        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();
        imagedestroy($img);

        $disk = config('filesystems.vehicle_docs_disk');
        Storage::fake($disk);
        Storage::disk($disk)->put('stamps/karaba/seal.png', $png);
        Setting::updateOrCreate(['key' => 'stamp_karaba_seal'], ['value' => 'stamps/karaba/seal.png', 'type' => 'string']);

        $sm = Salesman::firstOrCreate(['name' => 'TESTMAN'], ['type' => 'employee', 'is_active' => true]);
        $vehicle = Vehicle::create([
            'vehicle_number' => '11가1002', 'sales_channel' => 'export',
            'currency' => 'USD', 'exchange_rate' => 1350, 'dhl_request' => false,
            'salesman_id' => $sm->id, 'purchase_price' => 5_000_000,
            'purchase_date' => now()->toDateString(),
            'nice_reg_owner_name' => '홍길동',
        ]);
        $this->actingAs(User::factory()->create([
            'permission' => 'user', 'role' => '수출통관', 'email_verified_at' => now(),
        ]));

        // ⚠️ 앵커로 찾지 않는다 — 인보이스는 다중차량 슬롯을 `removeRow` 로 트림하므로
        //    도장이 슬롯 좌표(B65)에서 **함께 위로 밀린다**(실측 B36). 양식 baked 그림은
        //    `zip://…xlsx#xl/media/…` 경로고 업로드본만 임시파일 경로라 그걸로 고른다.
        //    ⚠️ 임시파일 이름으로 고르지 말 것 — Windows `tempnam()` 은 접두사를 3글자로 자른다
        //       (`stamp_` → `sta…`). 실제로 그렇게 짰다가 로컬에서만 못 찾았다.
        $uploadedStamp = function (string $type, string $sheetName) use ($vehicle) {
            $sheet = (new DocumentFiller($vehicle))->spreadsheet($type)->getSheetByName($sheetName);
            $this->assertNotNull($sheet, "{$type} 생성물에 {$sheetName} 탭이 없다");
            $found = null;
            foreach ($sheet->getDrawingCollection() as $drawing) {
                if (! str_starts_with((string) $drawing->getPath(), 'zip://')) {
                    $this->assertNull($found, "{$type} 에 업로드 도장이 2개다 — 이중 도장(§8 #37 ③)");
                    $found = [$drawing->getWidth(), $drawing->getHeight(), $drawing->getCoordinates()];
                }
            }

            return $found;
        };

        $onInvoice = $uploadedStamp('invoice', 'Invoice');
        $onApplication = $uploadedStamp('deregistration_set', self::SHEET);

        $this->assertNotNull($onInvoice, '인보이스에 직인이 없다');
        $this->assertNotNull($onApplication, '말소신청서에 직인이 없다');
        $this->assertSame(self::SEAL_ANCHOR, $onApplication[2], '말소신청서 직인 앵커가 옮겨졌다');
        $this->assertSame(
            [$onInvoice[0], $onInvoice[1]],
            [$onApplication[0], $onApplication[1]],
            '말소신청서 직인이 Proforma Invoice 와 다른 크기로 찍힌다 — 상자 세로가 낮으면 가로가 저절로 깎인다'
        );

        // 상자를 넘겨 찍지는 않는다(비율맞춤이 깨졌다는 뜻).
        $slot = collect(StampSlots::all('karaba')['deregistration_set'])->firstWhere('key', 'apply_seal');
        $this->assertLessThanOrEqual($slot['width'], $onApplication[0]);
        $this->assertLessThanOrEqual($slot['height'], $onApplication[1]);
    }
}
