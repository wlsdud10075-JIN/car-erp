<?php

namespace Tests\Feature;

use App\Http\Controllers\VehicleDocumentController;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Documents\DocumentFiller;
use App\Services\Documents\StampSlots;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

/**
 * 🧾 통관 SET Ver.2 (jin 2026-10-15 «서류탭에서 통관SET 옆에 나란히 하나 더 — Ver.1 / Ver.2»).
 *
 * 원본 = heymanerp 생성본을 jin 이 손본 xlsx. 실측 차이 = ① 차량인보이스 탭 삭제 ② 차량팩킹 상단 오른쪽 블록 제거(전폭 병합).
 * 양식은 `scripts/build-clearance-set-v2.php` 가 **각 세트의 Ver.1 에서** 만든다(노란칸·회사정보·그 회사 직인 보존).
 * 탭 삭제로 끊긴 수식(#REF!)은 구매리스트 칸으로 되살린다 — jin 이 원본을 다시 고쳐도 이 검사가 잡는다.
 */
class ClearanceSetV2Test extends TestCase
{
    use RefreshDatabase;

    private const SETS = ['system', 'heyman', 'karaba'];

    private const SHEETS = ['구매리스트', '한글등록증', '영문등록증', '말소증', '차량팩킹', 'Travel Services Invoice'];

    /** 3사 양식 — 6시트, 차량인보이스 없음, 끊긴 수식 없음, 팩킹 CO 블록 없음·전폭 병합, 회사 블록·직인 1장, 노란칸 보존. */
    public function test_every_set_has_a_sound_v2_template(): void
    {
        foreach (self::SETS as $set) {
            $path = resource_path("templates/{$set}/clearance_set_v2.xlsx");
            $this->assertFileExists($path);
            $book = IOFactory::load($path);
            $this->assertSame(self::SHEETS, array_map(fn (Worksheet $w) => $w->getTitle(), $book->getAllSheets()), "$set 시트 구성");

            foreach ($book->getAllSheets() as $ws) {
                foreach ($ws->getRowIterator() as $row) {
                    foreach ($row->getCellIterator() as $c) {
                        $v = $c->getValue();
                        if (is_string($v) && str_starts_with($v, '=')) {
                            $this->assertStringNotContainsString('#REF', $v, "$set {$ws->getTitle()}!{$c->getCoordinate()} 끊긴 수식");
                            $this->assertStringNotContainsString('차량인보이스', $v, "$set {$ws->getTitle()}!{$c->getCoordinate()} 지운 탭 참조");
                        }
                    }
                }
            }
            $packing = $book->getSheetByName('차량팩킹');
            $flat = implode(' ', array_map(fn ($r) => implode(' ', array_map(fn ($v) => $v instanceof RichText ? $v->getPlainText() : (string) $v, $r)), $packing->toArray(null, false, false)));
            $this->assertStringNotContainsString('CERTIFICATE OF ORIGIN', $flat, "$set 팩킹 오른쪽 블록이 남아 있다");
            $this->assertArrayHasKey('A3:I7', $packing->getMergeCells(), "$set 팩킹 Shipper 칸 전폭 병합");
            $a3 = $packing->getCell('A3')->getValue();
            $a3 = strtoupper($a3 instanceof RichText ? $a3->getPlainText() : (string) $a3);
            $this->assertStringContainsString(['system' => 'SSANCAR', 'heyman' => 'HEYMAN', 'karaba' => 'KARABA'][$set], $a3, "$set 팩킹 Shipper 가 그 회사가 아니다");
            $this->assertCount(1, $packing->getDrawingCollection(), "$set 팩킹 직인 1장(원본의 업로드 오버레이·G9 그림은 안 가져온다)");
            $this->assertSame('=구매리스트!D12', $packing->getCell('E17')->getValue(), "$set 팩킹 E17(con/roro) 복구");
            $this->assertSame('=구매리스트!D15', $book->getSheetByName('Travel Services Invoice')->getCell('F29')->getValue(), "$set Travel 운임 복구");
            $this->assertSame('solid', $book->getSheetByName('구매리스트')->getStyle('B4')->getFill()->getFillType(), "$set 구매리스트 노란칸(기입 표식)이 없다 — 원본을 통째로 썼나");
        }
    }

    /** 생성 — Ver.1 과 같은 매핑으로 채워지고, 차량인보이스를 거치던 값(차명·차대번호·con/roro·운임)이 팩킹·Travel 에 그대로 cascade 된다. */
    public function test_v2_fills_the_same_cells_and_cascades_without_the_invoice_sheet(): void
    {
        $v = Vehicle::create([
            'vehicle_number' => 'V2-1', 'sales_channel' => 'export', 'currency' => 'USD', 'exchange_rate' => 1400,
            'brand' => 'BMW', 'model' => '520d', 'nice_reg_vin' => 'WBAJC5105HG850942', 'shipping_method' => 'RORO',
            'sale_price' => 15000, 'transport_fee' => 1670, 'registration_number' => '2026-00001',
        ]);
        $filler = new DocumentFiller($v);
        $ss = $filler->spreadsheet('clearance_v2');

        $this->assertNull($ss->getSheetByName('차량인보이스'));
        $this->assertCount(6, $ss->getAllSheets());
        $list = $ss->getSheetByName('구매리스트');
        $this->assertSame('V2-1', (string) $list->getCell('B4')->getValue());
        $this->assertSame('2026-00001', (string) $list->getCell('D3')->getValue());
        $this->assertSame('RORO', (string) $list->getCell('D12')->getValue());
        $this->assertEquals(1670, $list->getCell('D15')->getValue());
        $packing = $ss->getSheetByName('차량팩킹');
        $this->assertSame('=구매리스트!B5', $packing->getCell('B22')->getValue(), '차명은 구매리스트에서 바로');
        $this->assertSame('=구매리스트!D5', $packing->getCell('D22')->getValue());
        $this->assertSame('=구매리스트!D12', $packing->getCell('E17')->getValue());
        $this->assertStringStartsWith('통관SET(Ver.2)_V2-1_', $filler->filename('clearance_v2'));
        $this->assertStringStartsWith('통관SET(Ver.1)_V2-1_', $filler->filename('clearance'), 'Ver.1 파일명에도 버전이 붙는다');
    }

    /** 배선 — 지원 목록·라우트 200·서류 탭 카드 두 장·도장 슬롯(차량인보이스 슬롯만 뺀 것)·슬롯 앵커에 baked 직인. */
    public function test_v2_is_wired_into_the_controller_docs_tab_and_stamp_slots(): void
    {
        $ref = new \ReflectionClass(VehicleDocumentController::class);
        $this->assertContains('clearance_v2', $ref->getConstant('SUPPORTED_TYPES'));
        $this->assertNotContains('clearance_v2', $ref->getConstant('MULTI_TYPES'));

        $user = User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
        $v = Vehicle::create(['vehicle_number' => 'V2-2', 'sales_channel' => 'export', 'currency' => 'USD', 'exchange_rate' => 1400]);
        $this->actingAs($user)->get(route('erp.vehicles.documents.show', ['id' => $v->id, 'type' => 'clearance_v2']))->assertOk();

        $html = Volt::test('erp.vehicles.index')->call('openEdit', $v->id)->html();
        $this->assertStringContainsString('data-doc-clearance-v2', $html, '서류 탭에 Ver.2 카드가 없다');
        $this->assertStringContainsString(__('vehicle.docs.clearance_set'), $html);
        $this->assertStringContainsString(__('vehicle.docs.clearance_set_v2'), $html);

        $this->assertSame('통관 SET (Ver.2)', StampSlots::DOC_LABELS['clearance_v2'] ?? null);
        foreach (self::SETS as $set) {   // karaba 도 기본 배치를 상속한다(G33)
            $slots = StampSlots::for('clearance_v2', $set);
            $this->assertNotEmpty($slots, "$set 에 Ver.2 도장 슬롯이 없으면 업로드 도장이 조용히 안 얹힌다");
            $this->assertNotContains('차량인보이스', array_column($slots, 'sheet'));
            $v1 = collect(StampSlots::for('clearance', $set))->reject(fn ($s) => $s['sheet'] === '차량인보이스')->values()->all();
            $this->assertEquals($v1, $slots, "$set Ver.2 슬롯 = Ver.1 에서 차량인보이스만 뺀 것");
            // 슬롯 앵커마다 양식에 baked 그림이 있어야 교체가 된다(ClearanceStampAnchorTest 와 같은 약속)
            $book = IOFactory::load(resource_path("templates/{$set}/clearance_set_v2.xlsx"));
            foreach ($slots as $slot) {
                if ($slot['role'] === 'logo') {
                    continue;
                }
                $anchors = array_map(fn ($d) => $d->getCoordinates(), iterator_to_array($book->getSheetByName($slot['sheet'])->getDrawingCollection()));
                $this->assertContains($slot['anchor'], $anchors, "$set {$slot['sheet']} {$slot['anchor']} 에 baked 직인 없음");
            }
        }
    }
}
