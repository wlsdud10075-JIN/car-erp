<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehiclePhoto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 연동 B 수신측 — POST /api/internal/purchase-sync (board → car-erp).
 * 수신 스펙(권위) = docs/integration/purchase-sync-receiver.md.
 *
 * ⚠️ 매칭/멱등 키 = vehicle_number (VIN 아님). board 는 VIN 을 모름 — car-erp 가
 *    NICE(차량번호+소유자명)로 VIN 을 채운다.
 *
 * 검증: HMAC 위변조 → 401 / 유효 서명 → vehicle 생성 + vehicle_id / vehicle_number 멱등
 *       재전송 → 중복 없음 / 영업 매칭 / 미지원 버전 → 422 / payee 암호화 / NICE→VIN 채움 /
 *       owner_name 없으면 graceful(VIN 없이 생성) / sales_channel=export(heyman 제거).
 */
class PurchaseSyncReceiverTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-shared-hmac-secret';

    private const URI = '/api/internal/purchase-sync';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.purchase_sync.hmac_secret', self::SECRET);
        // NICE 는 기본 미설정(수동 모드) — 대부분 케이스는 graceful(VIN 없이 생성).
        // NICE→VIN 채움은 전용 테스트에서 config + Http::fake 로 검증.
        config()->set('services.nice.provide_url', '');
        config()->set('services.nice.provide_token', '');
        // 첨부 소스 디스크를 타겟과 동일로 고정(.env 로컬 board 브리지 설정이 테스트에 새지 않게).
        // 교차디스크 시나리오는 전용 테스트가 명시 override.
        config()->set('filesystems.purchase_sync_inbound_disk', config('filesystems.vehicle_docs_disk'));
    }

    /** board 와 동일한 직렬화 + 서명으로 raw body POST. */
    private function postSigned(array $payload, ?string $secret = self::SECRET, ?string $forceBody = null)
    {
        $body = $forceBody ?? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($secret !== null) {
            $server['HTTP_X_BOARD_SIGNATURE'] = 'sha256='.hash_hmac('sha256', $body, $secret);
        }

        return $this->call('POST', self::URI, [], [], [], $server, $body);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'contract_version' => 1,
            'vehicle_number' => '12가3456',
            'owner_name' => '홍길동',
            'source' => 'auction',
            'final_price' => 12000000,
            'salesman_email' => 'sales@car-erp.test',
            'car_erp_salesman_id' => null,
            'c_no' => 'C-7788',
            'payee_name' => '김예금',
            'payee_bank' => '국민은행',
            'payee_account' => '123-456-789012',
        ], $overrides);
    }

    public function test_valid_signature_creates_vehicle_and_returns_id(): void
    {
        $salesman = Salesman::create([
            'name' => '김영업', 'email' => 'sales@car-erp.test', 'type' => 'freelance', 'is_active' => true,
        ]);

        $res = $this->postSigned($this->validPayload());

        $res->assertStatus(201);
        $vehicleId = $res->json('vehicle_id');
        $this->assertNotNull($vehicleId);

        $vehicle = Vehicle::find($vehicleId);
        $this->assertSame('12가3456', $vehicle->vehicle_number);
        $this->assertSame('export', $vehicle->sales_channel);   // heyman 아님 (enum 축소)
        $this->assertSame('auction', $vehicle->purchase_source);
        $this->assertSame('C-7788', $vehicle->c_no);
        $this->assertSame(12000000, (int) $vehicle->purchase_price);
        $this->assertSame($salesman->id, $vehicle->salesman_id);
        $this->assertSame(4, (int) $vehicle->progress_status_rule_version);
        $this->assertSame('홍길동', $vehicle->nice_reg_owner_name);   // owner_name baseline
    }

    public function test_nice_lookup_fills_vin_on_creation(): void
    {
        config()->set('services.nice.provide_url', 'https://ssancar.test/provide/api/nice-lookup/');
        config()->set('services.nice.provide_token', 'fake-token');

        Http::fake([
            'ssancar.test/*' => Http::response([
                'success' => true,
                'data' => [
                    'resVehicleIdNo' => 'KMHXX00XXXX099999',
                    'resFinalOwner' => '홍길동',
                    'commCarName' => '아반떼',
                    'mnfctEntrpsNm' => '현대',
                    'resCarYearModel' => '2020',
                ],
            ], 200),
        ]);

        $res = $this->postSigned($this->validPayload(['salesman_email' => 'nobody@car-erp.test']));
        $res->assertStatus(201);

        $vehicle = Vehicle::find($res->json('vehicle_id'));
        $this->assertSame('KMHXX00XXXX099999', $vehicle->nice_reg_vin);
        $this->assertSame('아반떼', $vehicle->model_type);
        $this->assertSame('현대', $vehicle->brand);
        $this->assertNotEmpty($vehicle->nice_raw);
        $this->assertSame('KMHXX00XXXX099999', $vehicle->nice_raw['resVehicleIdNo']);
    }

    public function test_missing_owner_name_creates_vehicle_without_vin_gracefully(): void
    {
        // NICE 설정돼 있어도 owner_name 없으면 NICE 호출 자체를 안 함 → 에러 없이 VIN 없이 생성.
        config()->set('services.nice.provide_url', 'https://ssancar.test/provide/api/nice-lookup/');
        config()->set('services.nice.provide_token', 'fake-token');
        Http::fake();   // 어떤 호출도 일어나면 안 됨

        $res = $this->postSigned($this->validPayload([
            'owner_name' => null,
            'salesman_email' => 'nobody@car-erp.test',
        ]));

        $res->assertStatus(201);
        $vehicle = Vehicle::find($res->json('vehicle_id'));
        $this->assertNull($vehicle->nice_reg_vin);
        $this->assertSame('12가3456', $vehicle->vehicle_number);

        Http::assertNothingSent();
    }

    public function test_nice_failure_is_graceful(): void
    {
        config()->set('services.nice.provide_url', 'https://ssancar.test/provide/api/nice-lookup/');
        config()->set('services.nice.provide_token', 'fake-token');
        Http::fake(['ssancar.test/*' => Http::response(['success' => false, 'message' => '조회 실패'], 200)]);

        $res = $this->postSigned($this->validPayload(['salesman_email' => 'nobody@car-erp.test']));

        $res->assertStatus(201);   // NICE 실패해도 생성은 성공
        $vehicle = Vehicle::find($res->json('vehicle_id'));
        $this->assertNull($vehicle->nice_reg_vin);
    }

    public function test_default_purchase_costs_are_filled(): void
    {
        $res = $this->postSigned($this->validPayload(['salesman_email' => 'nobody@car-erp.test']));
        $res->assertStatus(201);

        $vehicle = Vehicle::find($res->json('vehicle_id'));
        // UI 신규등록과 동일 단일 출처(Vehicle::DEFAULT_PURCHASE_COSTS).
        $this->assertSame(24000, (int) $vehicle->cost_deregistration);
        $this->assertSame(11000, (int) $vehicle->cost_license);
        $this->assertSame(30000, (int) $vehicle->cost_towing);
    }

    public function test_payee_account_is_encrypted(): void
    {
        $res = $this->postSigned($this->validPayload(['salesman_email' => 'nobody@car-erp.test']));
        $res->assertStatus(201);

        $vehicle = Vehicle::find($res->json('vehicle_id'));
        $this->assertSame('김예금', $vehicle->purchase_seller_holder);
        $this->assertSame('국민은행', $vehicle->purchase_seller_bank);
        $this->assertSame('123-456-789012', $vehicle->purchase_seller_account);

        $raw = DB::table('vehicles')->where('id', $vehicle->id)->value('purchase_seller_account');
        $this->assertNotEquals('123-456-789012', $raw);
        $this->assertSame('123-456-789012', Crypt::decryptString($raw));
    }

    public function test_invalid_signature_returns_401(): void
    {
        $body = json_encode($this->validPayload(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $res = $this->call('POST', self::URI, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_BOARD_SIGNATURE' => 'sha256=deadbeef',
        ], $body);

        $res->assertStatus(401);
        $this->assertSame(0, Vehicle::count());
    }

    public function test_missing_signature_returns_401(): void
    {
        $res = $this->postSigned($this->validPayload(), secret: null);

        $res->assertStatus(401);
        $this->assertSame(0, Vehicle::count());
    }

    public function test_wrong_secret_returns_401(): void
    {
        $res = $this->postSigned($this->validPayload(), secret: 'wrong-secret');

        $res->assertStatus(401);
        $this->assertSame(0, Vehicle::count());
    }

    public function test_same_vehicle_number_resend_is_idempotent(): void
    {
        $first = $this->postSigned($this->validPayload(['salesman_email' => 'nobody@car-erp.test']));
        $first->assertStatus(201);
        $vehicleId = $first->json('vehicle_id');

        // 동일 vehicle_number 재전송 — 새로 만들지 않고 기존 id 반환(200).
        $second = $this->postSigned($this->validPayload([
            'salesman_email' => 'nobody@car-erp.test',
            'final_price' => 99999999,
        ]));
        $second->assertStatus(200);

        $this->assertSame($vehicleId, $second->json('vehicle_id'));
        $this->assertSame(1, Vehicle::count());
    }

    public function test_unsupported_contract_version_returns_422(): void
    {
        $res = $this->postSigned($this->validPayload(['contract_version' => 99]));

        $res->assertStatus(422);
        $this->assertSame(0, Vehicle::count());
    }

    public function test_missing_vehicle_number_returns_422(): void
    {
        $payload = $this->validPayload();
        unset($payload['vehicle_number']);

        $res = $this->postSigned($payload);

        $res->assertStatus(422);
        $this->assertSame(0, Vehicle::count());
    }

    public function test_invalid_source_returns_422(): void
    {
        $res = $this->postSigned($this->validPayload(['source' => 'craigslist']));

        $res->assertStatus(422);
        $this->assertSame(0, Vehicle::count());
    }

    public function test_unknown_fields_are_ignored(): void
    {
        $res = $this->postSigned($this->validPayload([
            'salesman_email' => 'nobody@car-erp.test',
            'vin' => 'SHOULD-BE-IGNORED',   // 구 계약 잔재 — 무시돼야 함
            'future_field' => 'something new',
        ]));

        $res->assertStatus(201);
        $this->assertSame(1, Vehicle::count());
        // vin 필드는 무시 — NICE 미설정이라 VIN 은 비어 있어야 함
        $this->assertNull(Vehicle::first()->nice_reg_vin);
    }

    public function test_car_erp_salesman_id_override_takes_precedence(): void
    {
        Salesman::create(['name' => '이메일영업', 'email' => 'sales@car-erp.test', 'type' => 'employee', 'is_active' => true]);
        $override = Salesman::create(['name' => '지정영업', 'email' => 'other@car-erp.test', 'type' => 'employee', 'is_active' => true]);

        $res = $this->postSigned($this->validPayload(['car_erp_salesman_id' => $override->id]));
        $res->assertStatus(201);

        $vehicle = Vehicle::find($res->json('vehicle_id'));
        $this->assertSame($override->id, $vehicle->salesman_id);
    }

    public function test_salesman_matched_via_user_email_fallback(): void
    {
        $user = User::factory()->create(['email' => 'viauser@car-erp.test']);
        $salesman = Salesman::create([
            'user_id' => $user->id, 'name' => '유저연결영업', 'type' => 'freelance', 'is_active' => true,
        ]);

        $res = $this->postSigned($this->validPayload(['salesman_email' => 'viauser@car-erp.test']));
        $res->assertStatus(201);

        $vehicle = Vehicle::find($res->json('vehicle_id'));
        $this->assertSame($salesman->id, $vehicle->salesman_id);
    }

    public function test_unmatched_salesman_leaves_null(): void
    {
        $res = $this->postSigned($this->validPayload(['salesman_email' => 'ghost@car-erp.test']));
        $res->assertStatus(201);

        $vehicle = Vehicle::find($res->json('vehicle_id'));
        $this->assertNull($vehicle->salesman_id);
    }

    public function test_inbound_audit_log_recorded(): void
    {
        $res = $this->postSigned($this->validPayload(['salesman_email' => 'nobody@car-erp.test']));
        $res->assertStatus(201);

        $this->assertSame(1, AuditLog::where('action', 'inbound_purchase_sync')
            ->where('auditable_id', $res->json('vehicle_id'))->count());
    }

    // ── 연동 B v2 — 차량 첨부(사진/서류) 수신 ────────────────────────────

    public function test_v2_attachments_create_photos_and_copy_to_car_erp_prefix(): void
    {
        $disk = Storage::fake(config('filesystems.vehicle_docs_disk'));
        $disk->put('purchase-board/sales/photos/9/front.jpg', 'IMGBYTES1');
        $disk->put('purchase-board/sales/documents/9/reg.pdf', 'PDFBYTES2');

        $res = $this->postSigned($this->validPayload([
            'contract_version' => 2,
            'vehicle_number' => '99허9999',
            'attachments' => [
                ['s3_path' => 'purchase-board/sales/photos/9/front.jpg', 'original_name' => 'front.jpg', 'kind' => 'sales_photo', 'sort' => 1],
                ['s3_path' => 'purchase-board/sales/documents/9/reg.pdf', 'original_name' => '차량등록증.pdf', 'kind' => 'sales_document', 'sort' => 2],
            ],
        ]));
        $res->assertStatus(201);
        $vid = $res->json('vehicle_id');

        $photos = VehiclePhoto::where('vehicle_id', $vid)->orderBy('sort_order')->get();
        $this->assertCount(2, $photos);
        foreach ($photos as $p) {
            $this->assertStringStartsWith("vehicles/{$vid}/synced/", $p->path);   // car-erp prefix 로 복사
            $disk->assertExists($p->path);
        }
        $disk->assertExists('purchase-board/sales/photos/9/front.jpg');           // 원본 보존(복사≠이동)
    }

    public function test_v2_without_attachments_creates_no_photos(): void
    {
        Storage::fake(config('filesystems.vehicle_docs_disk'));
        $res = $this->postSigned($this->validPayload(['contract_version' => 2, 'vehicle_number' => '11가1111']));
        $res->assertStatus(201);
        $this->assertSame(0, VehiclePhoto::where('vehicle_id', $res->json('vehicle_id'))->count());
    }

    public function test_attachment_resend_is_deduped(): void
    {
        Storage::fake(config('filesystems.vehicle_docs_disk'))->put('purchase-board/x/a.jpg', 'A');
        $payload = $this->validPayload([
            'contract_version' => 2, 'vehicle_number' => '22나2222',
            'attachments' => [['s3_path' => 'purchase-board/x/a.jpg', 'sort' => 1]],
        ]);
        $first = $this->postSigned($payload);
        $first->assertStatus(201);
        $this->postSigned($payload)->assertStatus(200);   // 멱등 재전송

        $this->assertSame(1, VehiclePhoto::where('vehicle_id', $first->json('vehicle_id'))->count());
    }

    public function test_attachments_capped_at_ten(): void
    {
        $disk = Storage::fake(config('filesystems.vehicle_docs_disk'));
        $atts = [];
        for ($i = 1; $i <= 13; $i++) {
            $disk->put("purchase-board/c/{$i}.jpg", 'X');
            $atts[] = ['s3_path' => "purchase-board/c/{$i}.jpg", 'sort' => $i];
        }
        $res = $this->postSigned($this->validPayload(['contract_version' => 2, 'vehicle_number' => '33다3333', 'attachments' => $atts]));
        $res->assertStatus(201);
        $this->assertSame(10, VehiclePhoto::where('vehicle_id', $res->json('vehicle_id'))->count());
    }

    public function test_missing_source_is_skipped_gracefully(): void
    {
        Storage::fake(config('filesystems.vehicle_docs_disk'));
        $res = $this->postSigned($this->validPayload([
            'contract_version' => 2, 'vehicle_number' => '44라4444',
            'attachments' => [['s3_path' => 'purchase-board/missing/none.jpg', 'sort' => 1]],
        ]));
        $res->assertStatus(201);   // 차량은 생성, 누락 첨부만 skip
        $this->assertSame(0, VehiclePhoto::where('vehicle_id', $res->json('vehicle_id'))->count());
    }

    public function test_attachments_cross_disk_bridge(): void
    {
        // 로컬 시나리오 — 소스(board)≠타겟(car-erp) 디스크 → 스트림 교차복사.
        Storage::fake('public');                    // 타겟
        $board = Storage::fake('board_inbound');     // 소스(board 폴더 브리지)
        config()->set('filesystems.vehicle_docs_disk', 'public');
        config()->set('filesystems.purchase_sync_inbound_disk', 'board_inbound');
        $board->put('purchase-board/sales/photos/5/x.jpg', 'BOARDBYTES');

        $res = $this->postSigned($this->validPayload([
            'contract_version' => 2, 'vehicle_number' => '55마5555',
            'attachments' => [['s3_path' => 'purchase-board/sales/photos/5/x.jpg', 'sort' => 1]],
        ]));
        $res->assertStatus(201);

        $photos = VehiclePhoto::where('vehicle_id', $res->json('vehicle_id'))->get();
        $this->assertCount(1, $photos);
        Storage::disk('public')->assertExists($photos[0]->path);
        $this->assertSame('BOARDBYTES', Storage::disk('public')->get($photos[0]->path));   // board 바이트가 car-erp 로
    }
}
