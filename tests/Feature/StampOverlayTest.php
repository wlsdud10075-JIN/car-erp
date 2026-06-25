<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Documents\DocumentFiller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

/**
 * 도장/서명 오버레이 — 기능설정 업로드 → DocumentFiller 가 양식 도장을 교체.
 * 미업로드 시 양식 기본 도장 유지(하위호환).
 */
class StampOverlayTest extends TestCase
{
    use RefreshDatabase;

    private function disk(): string
    {
        return config('filesystems.vehicle_docs_disk');
    }

    private function drawingAt(Worksheet $sheet, string $anchor): array
    {
        $hits = [];
        foreach ($sheet->getDrawingCollection() as $d) {
            if ($d->getCoordinates() === $anchor) {
                $hits[] = $d;
            }
        }

        return $hits;
    }

    public function test_uploaded_signature_replaces_baked_stamp(): void
    {
        Storage::fake($this->disk());
        $bytes = (string) UploadedFile::fake()->image('sig.png', 300, 120)->get();
        Storage::disk($this->disk())->put('stamps/system/signature.png', $bytes);
        Setting::updateOrCreate(
            ['key' => 'stamp_system_signature'],
            ['value' => 'stamps/system/signature.png', 'type' => 'string'],
        );

        $vehicle = Vehicle::create(['vehicle_number' => '12가1234', 'sales_channel' => 'export']);
        $ss = (new DocumentFiller($vehicle))->spreadsheet('deregistration_contract');
        $sheet = $ss->getSheetByName('2.계약서');

        // 업로드 서명은 슬롯 위치(A62, jin 지정)에 1개. 양식 baked(A60)는 clearAnchors 로 제거 → 이중 X.
        $this->assertCount(0, $this->drawingAt($sheet, 'A60'), '양식 baked 서명(A60)은 제거되어야 함');
        $hits = $this->drawingAt($sheet, 'A62');
        $this->assertCount(1, $hits, 'A62 에 업로드 서명이 정확히 1개여야 함 (이중 X)');
        $this->assertInstanceOf(Drawing::class, $hits[0]);
        $this->assertSame($bytes, file_get_contents($hits[0]->getPath()), '업로드한 서명으로 교체되어야 함');
    }

    public function test_without_upload_keeps_template_default(): void
    {
        Storage::fake($this->disk());

        $vehicle = Vehicle::create(['vehicle_number' => '34나5678', 'sales_channel' => 'export']);
        $ss = (new DocumentFiller($vehicle))->spreadsheet('deregistration_contract');
        $sheet = $ss->getSheetByName('2.계약서');

        // 업로드 없음 → 양식 기본 도장이 그대로 1개 남아있어야 함
        $this->assertCount(1, $this->drawingAt($sheet, 'A60'), '미업로드 시 양식 기본 도장 유지');
    }

    public function test_uploaded_seal_replaces_invoice_composite(): void
    {
        Storage::fake($this->disk());
        $bytes = (string) UploadedFile::fake()->image('seal.png', 200, 200)->get();
        Storage::disk($this->disk())->put('stamps/system/seal.png', $bytes);
        Setting::updateOrCreate(['key' => 'stamp_system_seal'], ['value' => 'stamps/system/seal.png', 'type' => 'string']);

        $vehicle = Vehicle::create(['vehicle_number' => '56다7890', 'sales_channel' => 'export']);
        $ss = (new DocumentFiller($vehicle))->spreadsheet('invoice');
        $sheet = $ss->getSheetByName('Invoice');

        $hits = $this->drawingAt($sheet, 'B36');
        $this->assertCount(1, $hits, 'B36 직인이 정확히 1개');
        $this->assertSame($bytes, file_get_contents($hits[0]->getPath()), '업로드 직인으로 교체');
    }

    public function test_uploaded_seal_on_multi_doc_contract(): void
    {
        // 선적 계약서(removeRow 경유)에서도 직인 오버레이가 살아남아야 함.
        Storage::fake($this->disk());
        $bytes = (string) UploadedFile::fake()->image('seal.png', 200, 200)->get();
        Storage::disk($this->disk())->put('stamps/system/seal.png', $bytes);
        Setting::updateOrCreate(['key' => 'stamp_system_seal'], ['value' => 'stamps/system/seal.png', 'type' => 'string']);

        $vehicle = Vehicle::create(['vehicle_number' => '78라9012', 'sales_channel' => 'export']);
        $ss = (new DocumentFiller($vehicle))->spreadsheet('container_contract');
        $sheet = $ss->getSheetByName('HBB340.');

        // removeRow 로 B59 가 위로 이동했어도 업로드 직인이 정확히 1개 존재
        $seals = [];
        foreach ($sheet->getDrawingCollection() as $d) {
            if ($d instanceof Drawing && @file_get_contents($d->getPath()) === $bytes) {
                $seals[] = $d;
            }
        }
        $this->assertCount(1, $seals, '선적 계약서에 업로드 직인 1개 (removeRow 후에도 생존)');
    }

    public function test_large_upload_is_scaled_to_fit_without_distortion(): void
    {
        Storage::fake($this->disk());
        // 1000x1000 정사각 도장 — 박스(598x373)로 강제하면 찌그러짐. 비율 유지 fit 이어야 함.
        $bytes = (string) UploadedFile::fake()->image('seal.png', 1000, 1000)->get();
        Storage::disk($this->disk())->put('stamps/system/seal.png', $bytes);
        Setting::updateOrCreate(['key' => 'stamp_system_seal'], ['value' => 'stamps/system/seal.png', 'type' => 'string']);

        $vehicle = Vehicle::create(['vehicle_number' => '90마1234', 'sales_channel' => 'export']);
        $ss = (new DocumentFiller($vehicle))->spreadsheet('invoice');
        $sheet = $ss->getSheetByName('Invoice');

        $d = $this->drawingAt($sheet, 'B36')[0];
        $this->assertSame($d->getWidth(), $d->getHeight(), '정사각 업로드는 정사각 유지(왜곡 X)');
        $this->assertLessThanOrEqual(598, $d->getWidth(), '박스 폭 이내');
        $this->assertLessThanOrEqual(373, $d->getHeight(), '박스 높이 이내');
    }

    public function test_super_can_upload_and_remove_signature(): void
    {
        Storage::fake($this->disk());
        $super = User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]);
        $this->actingAs($super);

        $component = Volt::test('admin.settings')
            ->set('signatureUpload', UploadedFile::fake()->image('sig.png', 300, 120));

        $this->assertNotNull(Setting::get('stamp_system_signature'));
        Storage::disk($this->disk())->assertExists(Setting::get('stamp_system_signature'));

        $component->call('removeStamp', 'signature');
        $this->assertNull(Setting::get('stamp_system_signature'));
    }

    public function test_super_can_upload_seal(): void
    {
        Storage::fake($this->disk());
        $super = User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]);
        $this->actingAs($super);

        Volt::test('admin.settings')->set('sealUpload', UploadedFile::fake()->image('seal.png', 200, 200));

        $this->assertNotNull(Setting::get('stamp_system_seal'));
        Storage::disk($this->disk())->assertExists(Setting::get('stamp_system_seal'));
    }

    public function test_non_super_cannot_open_settings(): void
    {
        $admin = User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
        $this->actingAs($admin);

        Volt::test('admin.settings')->assertStatus(403);
    }
}
