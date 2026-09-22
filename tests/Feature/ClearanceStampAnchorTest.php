<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Vehicle;
use App\Services\Documents\DocumentFiller;
use App\Services\Documents\StampSlots;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * 🖼️ 통관 SET 도장 앵커 ↔ 양식 baked drawing (2026-09-22 heyman 디자인 변경 때 신설).
 *
 * `DocumentFiller::removeDrawingsAt` 은 **정확히 같은 앵커**의 drawing 만 지운다(§8 #37 ③). 슬롯 앵커와
 * 양식에 박힌 직인 좌표가 어긋나면 업로드 직인이 baked 위에 겹쳐 **이중 도장**이 된다 — 서류는 정상 생성되고
 * 예외도 없어 기능 테스트로는 원리상 못 잡는다. 판매계약서엔 같은 가드가 있었고(SalesContractLayoutTest) 통관엔 없었다.
 *
 * heyman 은 추가로 jin 파일(통관SET_11무8205_318406.xlsx) 디자인 그대로인지 `scripts/lib/heyman-clearance-design.php` 의
 * verify 로 대조한다 — 재생성 스크립트가 되돌리면 여기서 빨개진다.
 */
class ClearanceStampAnchorTest extends TestCase
{
    use RefreshDatabase;

    /** 3세트 전부: 통관 슬롯 앵커에 양식 drawing 이 실제로 있다(정확 일치). heyman 은 오프셋까지. */
    public function test_every_clearance_slot_anchor_has_a_baked_drawing_in_every_set(): void
    {
        foreach (['system', 'heyman', 'karaba'] as $set) {
            $ss = IOFactory::load(resource_path("templates/{$set}/clearance_set.xlsx"));
            foreach (StampSlots::for('clearance', $set) as $slot) {
                $ws = $ss->getSheetByName($slot['sheet']);
                $this->assertNotNull($ws, "{$set}: 시트 없음 {$slot['sheet']}");
                $hit = collect($ws->getDrawingCollection())->filter(fn ($d) => $d->getCoordinates() === $slot['anchor']);
                if ($slot['role'] === 'logo') {
                    // 로고 자리는 조각 2장이 같은 앵커(A1)에 있을 수 있다 — removeDrawingsAt 이 앵커 일치 전부를 지우므로 문제없다
                    $this->assertGreaterThanOrEqual(1, $hit->count(), "{$set}/{$slot['sheet']}: 앵커 {$slot['anchor']} 에 baked 로고가 없다");

                    continue;
                }
                $this->assertCount(1, $hit, "{$set}/{$slot['sheet']}: 앵커 {$slot['anchor']} 에 baked 직인이 정확히 1개여야 한다(0=제거 못 함·2+=겹침)");
                if ($set === 'heyman') {
                    $d = $hit->first();
                    $this->assertSame([$slot['dx'] ?? 0, $slot['dy'] ?? 0], [$d->getOffsetX(), $d->getOffsetY()],
                        "heyman/{$slot['sheet']}: 슬롯 dx/dy 가 양식 baked 오프셋과 다르다 — 업로드 직인이 다른 자리에 찍힌다");
                }
            }
        }
    }

    /** heyman 양식 = jin 파일 디자인(직인·관인 위치 · Travel 상단 · 발급기관 칸). */
    public function test_heyman_template_matches_the_jin_design(): void
    {
        require_once base_path('scripts/lib/heyman-clearance-design.php');
        $bad = verifyHeymanClearanceDesign(IOFactory::load(resource_path('templates/heyman/clearance_set.xlsx')));
        $this->assertSame([], $bad, '어긋난 항목: '.implode(' / ', $bad));
    }

    /** heyman 생성물: 업로드 직인이 baked 를 지우고 같은 앵커+오프셋에 1개만 놓인다(이중 도장 없음). */
    public function test_heyman_uploaded_seal_replaces_the_baked_one_at_the_new_anchor(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD 없음');
        }
        Setting::updateOrCreate(['key' => 'company_template_set'], ['value' => 'heyman', 'type' => 'string']);
        config(['company.template_set' => 'heyman']);
        $disk = config('filesystems.vehicle_docs_disk', 'public');
        Storage::fake($disk);
        $img = imagecreatetruecolor(300, 120);
        ob_start();
        imagepng($img);
        $bytes = ob_get_clean();
        Storage::disk($disk)->put('stamps/heyman/seal.png', $bytes);
        Setting::updateOrCreate(['key' => 'stamp_heyman_seal'], ['value' => 'stamps/heyman/seal.png', 'type' => 'string']);

        $v = Vehicle::create(['vehicle_number' => '11무8205', 'sales_channel' => 'export', 'currency' => 'USD', 'exchange_rate' => 1300, 'dhl_request' => false, 'purchase_price' => 1]);
        $ss = (new DocumentFiller($v))->spreadsheet('clearance');

        foreach (StampSlots::for('clearance', 'heyman') as $slot) {
            $ws = $ss->getSheetByName($slot['sheet']);
            $at = collect($ws->getDrawingCollection())->filter(fn ($d) => $d->getCoordinates() === $slot['anchor']);
            $this->assertCount(1, $at, "{$slot['sheet']}: 앵커 {$slot['anchor']} 에 drawing 이 1개여야 한다(2개면 이중 도장)");
            $this->assertSame([$slot['dx'], $slot['dy']], [$at->first()->getOffsetX(), $at->first()->getOffsetY()]);
            $this->assertCount(0, collect($ws->getDrawingCollection())->filter(fn ($d) => $d->getCoordinates() === 'G33'), "{$slot['sheet']}: 옛 앵커 G33 에 잔재");
        }
        $travel = $ss->getSheetByName('Travel Services Invoice');
        $this->assertCount(0, collect($travel->getDrawingCollection())->filter(fn ($d) => $d->getCoordinates() === 'A1'), 'Travel A1 에 로고가 올라갔다 — 글자 「HEYMAN」을 덮는다');
        $this->assertSame('HEYMAN', (string) $travel->getCell('A1')->getValue());
    }
}
