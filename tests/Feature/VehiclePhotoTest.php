<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Salesman;
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

    /** ① 신규 등록 필수 — 담당자·바이어. 신규 바이어라 미수 게이트 미발동. */
    private function party(): array
    {
        return [
            Salesman::create(['name' => '영업', 'is_active' => true, 'type' => 'freelance']),
            Buyer::create(['name' => 'PHOTO BUYER '.uniqid(), 'is_active' => true]),
        ];
    }

    public function test_upload_creates_photo_rows_and_files(): void
    {
        $this->actingAs($this->admin());
        [$sm, $buyer] = $this->party();

        Volt::test('erp.vehicles.index')
            ->set('vehicle_number', '12가7777')
            ->set('sales_channel', 'export')
            ->set('salesman_id_str', (string) $sm->id)
            ->set('buyer_id_str', (string) $buyer->id)
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
        [$sm, $buyer] = $this->party();

        $files = [];
        for ($i = 0; $i < 11; $i++) {
            $files[] = UploadedFile::fake()->image("p{$i}.jpg");
        }

        Volt::test('erp.vehicles.index')
            ->set('vehicle_number', '12가9999')
            ->set('sales_channel', 'export')
            ->set('salesman_id_str', (string) $sm->id)
            ->set('buyer_id_str', (string) $buyer->id)
            ->set('photoFiles', $files)
            ->call('save')
            ->assertHasErrors('photoFiles');

        $this->assertDatabaseMissing('vehicles', ['vehicle_number' => '12가9999']);
    }

    public function test_disallowed_file_type_rejected(): void
    {
        // 2026-05-29 — 첨부 허용 18종(PDF·Excel·HWP 등)으로 확대. PDF는 이제 허용.
        // 실행파일(.exe 등)만 mimes 화이트리스트로 차단됨 (index.blade.php:1514).
        $this->actingAs($this->admin());
        [$sm, $buyer] = $this->party();

        Volt::test('erp.vehicles.index')
            ->set('vehicle_number', '12가1212')
            ->set('sales_channel', 'export')
            ->set('salesman_id_str', (string) $sm->id)
            ->set('buyer_id_str', (string) $buyer->id)
            ->set('photoFiles', [UploadedFile::fake()->create('evil.exe', 100, 'application/octet-stream')])
            ->call('save')
            ->assertHasErrors('photoFiles.*');
    }

    /** 선적 탭 선박 사진 — category='shipping' + ship-photos 경로 저장 (기본정보 차량사진과 분리). */
    public function test_ship_photo_upload_creates_shipping_category_rows(): void
    {
        $this->actingAs($this->admin());
        [$sm, $buyer] = $this->party();

        Volt::test('erp.vehicles.index')
            ->set('vehicle_number', '12가2323')
            ->set('sales_channel', 'export')
            ->set('salesman_id_str', (string) $sm->id)
            ->set('buyer_id_str', (string) $buyer->id)
            ->set('shipPhotoFiles', [
                UploadedFile::fake()->image('vessel1.jpg'),
                UploadedFile::fake()->image('vessel2.png'),
            ])
            ->call('save')
            ->assertHasNoErrors();

        $vehicle = Vehicle::where('vehicle_number', '12가2323')->firstOrFail();
        $ship = $vehicle->photos->where('category', 'shipping');
        $this->assertCount(2, $ship);
        foreach ($ship as $p) {
            $this->assertStringStartsWith("vehicles/{$vehicle->id}/ship-photos", $p->path);
            Storage::disk('public')->assertExists($p->path);
        }
    }

    /** 두 갤러리 격리 — 기본정보(existingPhotos)엔 차량사진만, 선적(existingShipPhotos)엔 선박사진만. */
    public function test_ship_photos_separated_from_basic_gallery(): void
    {
        $this->actingAs($this->admin());
        $vehicle = Vehicle::create(['vehicle_number' => '12가3434', 'sales_channel' => 'export']);

        $carPath = UploadedFile::fake()->image('car.jpg')->store("vehicles/{$vehicle->id}/photos", 'public');
        $car = VehiclePhoto::create(['vehicle_id' => $vehicle->id, 'path' => $carPath, 'sort_order' => 1]);
        $shipPath = UploadedFile::fake()->image('vessel.jpg')->store("vehicles/{$vehicle->id}/ship-photos", 'public');
        $ship = VehiclePhoto::create(['vehicle_id' => $vehicle->id, 'path' => $shipPath, 'category' => 'shipping', 'sort_order' => 1]);

        $component = Volt::test('erp.vehicles.index')->call('openEdit', $vehicle->id);

        $basicIds = collect($component->get('existingPhotos'))->pluck('id')->all();
        $shipIds = collect($component->get('existingShipPhotos'))->pluck('id')->all();

        $this->assertSame([$car->id], $basicIds, '기본정보 갤러리엔 차량사진만');
        $this->assertSame([$ship->id], $shipIds, '선적 갤러리엔 선박사진만');
    }

    public function test_remove_existing_ship_photo_deletes_row_and_file(): void
    {
        $this->actingAs($this->admin());
        $vehicle = Vehicle::create(['vehicle_number' => '12가4545', 'sales_channel' => 'export']);
        $path = UploadedFile::fake()->image('v.jpg')->store("vehicles/{$vehicle->id}/ship-photos", 'public');
        $photo = VehiclePhoto::create(['vehicle_id' => $vehicle->id, 'path' => $path, 'category' => 'shipping', 'sort_order' => 1]);
        Storage::disk('public')->assertExists($path);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $vehicle->id)
            ->call('removeExistingShipPhoto', $photo->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('vehicle_photos', ['id' => $photo->id]);
        Storage::disk('public')->assertMissing($path);
    }
}
