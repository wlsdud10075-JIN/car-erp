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

        $hits = $this->drawingAt($sheet, 'A60');
        $this->assertCount(1, $hits, 'A60 에 도장이 정확히 1개여야 함 (이중 X)');
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

    public function test_super_can_upload_and_remove_signature(): void
    {
        Storage::fake($this->disk());
        $super = User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]);
        $this->actingAs($super);

        $component = Volt::test('admin.settings')
            ->set('signatureUpload', UploadedFile::fake()->image('sig.png', 300, 120));

        $this->assertNotNull(Setting::get('stamp_system_signature'));
        Storage::disk($this->disk())->assertExists(Setting::get('stamp_system_signature'));

        $component->call('removeSignature');
        $this->assertNull(Setting::get('stamp_system_signature'));
    }

    public function test_non_super_cannot_open_settings(): void
    {
        $admin = User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
        $this->actingAs($admin);

        Volt::test('admin.settings')->assertStatus(403);
    }
}
