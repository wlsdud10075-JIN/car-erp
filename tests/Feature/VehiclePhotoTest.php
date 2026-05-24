<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehiclePhoto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 차량 사진(jpg/png) 업로드/갤러리/개별삭제 — vehicle_photos, vehicle_docs_disk 저장.
 */
class VehiclePhotoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
        Storage::fake('public');   // vehicle_docs_disk 기본값 = public
    }

    private function admin(): User
    {
        return User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]);
    }

    public function test_upload_creates_photo_rows_and_files(): void
    {
        $this->actingAs($this->admin());

        Volt::test('erp.vehicles.index')
            ->set('vehicle_number', '12가7777')
            ->set('sales_channel', 'export')
            ->set('photoFiles', [
                UploadedFile::fake()->image('a.jpg'),
                UploadedFile::fake()->image('b.png'),
                UploadedFile::fake()->image('c.jpg'),
            ])
            ->call('save')
            ->assertHasNoErrors();

        $vehicle = Vehicle::where('vehicle_number', '12가7777')->firstOrFail();
        $this->assertCount(3, $vehicle->photos);
        foreach ($vehicle->photos as $p) {
            $this->assertStringStartsWith("vehicles/{$vehicle->id}/photos", $p->path);
            Storage::disk('public')->assertExists($p->path);
        }
    }

    public function test_remove_existing_photo_deletes_row_and_file(): void
    {
        $this->actingAs($this->admin());
        $vehicle = Vehicle::create(['vehicle_number' => '12가8888', 'sales_channel' => 'export']);
        $path = UploadedFile::fake()->image('x.jpg')->store("vehicles/{$vehicle->id}/photos", 'public');
        $photo = VehiclePhoto::create(['vehicle_id' => $vehicle->id, 'path' => $path, 'sort_order' => 1]);
        Storage::disk('public')->assertExists($path);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $vehicle->id)
            ->call('removeExistingPhoto', $photo->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('vehicle_photos', ['id' => $photo->id]);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_max_photos_guard(): void
    {
        $this->actingAs($this->admin());

        $files = [];
        for ($i = 0; $i < 11; $i++) {
            $files[] = UploadedFile::fake()->image("p{$i}.jpg");
        }

        Volt::test('erp.vehicles.index')
            ->set('vehicle_number', '12가9999')
            ->set('sales_channel', 'export')
            ->set('photoFiles', $files)
            ->call('save')
            ->assertHasErrors('photoFiles');

        $this->assertDatabaseMissing('vehicles', ['vehicle_number' => '12가9999']);
    }

    public function test_non_image_rejected(): void
    {
        $this->actingAs($this->admin());

        Volt::test('erp.vehicles.index')
            ->set('vehicle_number', '12가1212')
            ->set('sales_channel', 'export')
            ->set('photoFiles', [UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf')])
            ->call('save')
            ->assertHasErrors('photoFiles.*');
    }
}
