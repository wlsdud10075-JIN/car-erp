<?php

namespace Tests\Feature;

use App\Models\Salesman;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Documents\DocumentFiller;
use App\Services\Documents\StampSlots;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
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

        $this->assertContains(self::SHEET.'!K26', $slotsOf('karaba'), 'karaba 에 말소신청서 직인 슬롯이 없다');
        foreach (['system', 'heyman'] as $set) {
            $this->assertNotContains(self::SHEET.'!K26', $slotsOf($set), "{$set} 에 karaba 전용 직인이 번졌다");
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
        $this->assertNotContains('K26', $anchors, '앵커에 이미 그림이 있다 — clearAnchors 가 필요하다');
        $ss->disconnectWorksheets();
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
}
