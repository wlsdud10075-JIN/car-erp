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

        // ⚠️ 여기서 `->image()` 를 쓰면 GD 가 한도+1 장을 실제로 그려서, 전체 테스트 실행 중
        //    이 클래스에서 메모리가 터진다(단독 실행은 통과해 위장된다 — 2026-08-25 실측).
        //    개수만 세는 가드라 실제 픽셀이 필요 없다.
        $files = [];
        for ($i = 0; $i <= VehiclePhoto::MAX_BASIC; $i++) {   // 한도 +1 장
            $files[] = UploadedFile::fake()->create("p{$i}.jpg", 1, 'image/jpeg');
        }

        $component = Volt::test('erp.vehicles.index')
            ->set('vehicle_number', '12가9999')
            ->set('sales_channel', 'export')
            ->set('salesman_id_str', (string) $sm->id)
            ->set('buyer_id_str', (string) $buyer->id)
            ->set('photoFiles', $files)
            ->call('save')
            ->assertHasErrors('photoFiles');

        // ⚠️ 「에러가 났다」로는 부족하다 — 형식·용량 같은 다른 이유로 막혀도 통과해버려
        //    한도 가드가 죽어도 초록으로 남는다. 한도 문구인지까지 확인한다.
        $this->assertSame(
            __('vehicle.toast.max_photos', ['max' => VehiclePhoto::MAX_BASIC]),
            $component->errors()->first('photoFiles')
        );

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

    /**
     * 한도 숫자는 화면 라벨에 직접 박지 않는다 — 상수가 바뀌어도 라벨이 과거 값을 말하면
     * 사용자는 화면을 믿고 반대로 조작한다(2026-07-07 선적 한도를 30으로 올리고도
     * 라벨은 "최대 10건"으로 남아 있었다). 기능 테스트로는 원리상 못 잡는다 — 렌더는 정상이다.
     */
    public function test_gallery_labels_take_the_limit_from_the_constant(): void
    {
        foreach (['ko', 'en'] as $locale) {
            foreach (['photos', 'ship_photos'] as $key) {
                $raw = __("vehicle.panel.sec.{$key}", [], $locale);
                $this->assertStringContainsString(':max', $raw, "{$locale}/{$key} 라벨은 :max 치환자를 써야 한다");
                $this->assertDoesNotMatchRegularExpression(
                    '/\d/',
                    str_replace(':max', '', $raw),
                    "{$locale}/{$key} 라벨에 한도 숫자를 직접 적지 말 것"
                );
            }
        }

        $this->assertStringContainsString(
            (string) VehiclePhoto::MAX_BASIC,
            __('vehicle.panel.sec.photos', ['max' => VehiclePhoto::MAX_BASIC], 'ko')
        );
        $this->assertStringContainsString(
            (string) VehiclePhoto::MAX_SHIPPING,
            __('vehicle.panel.sec.ship_photos', ['max' => VehiclePhoto::MAX_SHIPPING], 'ko')
        );
    }
}
