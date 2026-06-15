<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 연동 B 수신측 — POST /api/internal/purchase-sync (board → car-erp).
 * 수신 스펙(권위) = docs/integration/purchase-sync-receiver.md.
 *
 * 검증: HMAC 위변조 → 401 / 유효 서명 → vehicle 생성 + vehicle_id / VIN 멱등 재전송 →
 *       중복 생성 안 함 / 영업 매칭(이메일·override) / 미지원 버전 → 422 / payee 암호화.
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
            'vin' => 'KMHXX00XXXX000001',
            'vehicle_number' => '12가3456',
            'source' => 'auction',
            'final_price' => 12000000,
            'salesman_email' => 'sales@car-erp.test',
            'car_erp_salesman_id' => null,
            'c_no' => 'C-7788',
            'payee_name' => '홍길동',
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
        $this->assertSame('KMHXX00XXXX000001', $vehicle->nice_reg_vin);
        $this->assertSame('12가3456', $vehicle->vehicle_number);
        $this->assertSame('heyman', $vehicle->sales_channel);
        $this->assertSame('auction', $vehicle->purchase_source);
        $this->assertSame('C-7788', $vehicle->c_no);
        $this->assertSame(12000000, (int) $vehicle->purchase_price);
        $this->assertSame($salesman->id, $vehicle->salesman_id);
        $this->assertSame(4, (int) $vehicle->progress_status_rule_version);
    }

    public function test_payee_account_is_encrypted(): void
    {
        $res = $this->postSigned($this->validPayload(['salesman_email' => 'nobody@car-erp.test']));
        $res->assertStatus(201);

        $vehicle = Vehicle::find($res->json('vehicle_id'));
        $this->assertSame('홍길동', $vehicle->purchase_seller_holder);
        $this->assertSame('국민은행', $vehicle->purchase_seller_bank);
        $this->assertSame('123-456-789012', $vehicle->purchase_seller_account);

        $raw = DB::table('vehicles')->where('id', $vehicle->id)->value('purchase_seller_account');
        $this->assertNotEquals('123-456-789012', $raw);
        $this->assertSame('123-456-789012', Crypt::decryptString($raw));
    }

    public function test_invalid_signature_returns_401(): void
    {
        $payload = $this->validPayload();
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

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

    public function test_same_vin_resend_is_idempotent(): void
    {
        $first = $this->postSigned($this->validPayload(['salesman_email' => 'nobody@car-erp.test']));
        $first->assertStatus(201);
        $vehicleId = $first->json('vehicle_id');

        // 동일 VIN, 다른 vehicle_number 로 재전송 — 새로 만들지 않고 기존 id 반환(200).
        $second = $this->postSigned($this->validPayload([
            'salesman_email' => 'nobody@car-erp.test',
            'vehicle_number' => '99호9999',
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

    public function test_validation_failure_returns_422(): void
    {
        // source 가 enum 외 값
        $res = $this->postSigned($this->validPayload(['source' => 'craigslist']));

        $res->assertStatus(422);
        $this->assertSame(0, Vehicle::count());
    }

    public function test_unknown_fields_are_ignored(): void
    {
        $res = $this->postSigned($this->validPayload([
            'salesman_email' => 'nobody@car-erp.test',
            'future_field' => 'something new',
            'another_unknown' => 12345,
        ]));

        $res->assertStatus(201);
        $this->assertSame(1, Vehicle::count());
    }

    public function test_car_erp_salesman_id_override_takes_precedence(): void
    {
        // 이메일로 찾히는 영업과, override 로 지정할 영업을 따로 둠.
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
}
