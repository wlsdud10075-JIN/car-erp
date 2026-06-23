<?php

namespace Tests\Feature;

use App\Models\PurchaseBalancePayment;
use App\Models\Setting;
use App\Models\TaskAlarm;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 연동 B v3 Part 3 — 매입 도착 알람 (purchase_arrival, target_role='관리').
 * board won→purchase-sync 신규 차량 생성 시 [관리]/admin 에게 "신규 매입차 도착" 알람.
 * 해소 = 계약금(PBP down) 입력 자동 + 수동 [확인] (jin 2026-06-23).
 */
class PurchaseArrivalAlarmTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-shared-hmac-secret';

    private const URI = '/api/internal/purchase-sync';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.purchase_sync.hmac_secret', self::SECRET);
        config()->set('services.nice.provide_url', '');
        config()->set('services.nice.provide_token', '');
    }

    private function enableAlarm(): void
    {
        Setting::create(['key' => 'alarm_enabled', 'value' => '1', 'type' => 'boolean']);
    }

    private function postSigned(array $payload)
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $this->call('POST', self::URI, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_BOARD_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, self::SECRET),
        ], $body);
    }

    private function payload(array $o = []): array
    {
        return array_merge([
            'contract_version' => 3,
            'vehicle_number' => '70가7000',
            'owner_name' => '홍길동',
            'source' => 'auction',
            'final_price' => 10000000,
            'salesman_email' => 'nobody@car-erp.test',
        ], $o);
    }

    public function test_arrival_alarm_created_on_new_vehicle_when_enabled(): void
    {
        $this->enableAlarm();
        $res = $this->postSigned($this->payload());
        $res->assertStatus(201);

        $alarm = TaskAlarm::where('type', 'purchase_arrival')->first();
        $this->assertNotNull($alarm);
        $this->assertSame('관리', $alarm->target_role);
        $this->assertSame($res->json('vehicle_id'), $alarm->vehicle_id);
        $this->assertSame('70가7000', $alarm->message_meta['vehicle_number']);
        $this->assertNull($alarm->resolved_at);
    }

    public function test_arrival_alarm_not_created_when_disabled(): void
    {
        // alarm_enabled 미설정(기본 false) → 생성 안 됨 (배포 ≠ 작동).
        $this->postSigned($this->payload())->assertStatus(201);
        $this->assertSame(0, TaskAlarm::where('type', 'purchase_arrival')->count());
    }

    public function test_arrival_alarm_not_duplicated_on_idempotent_resend(): void
    {
        $this->enableAlarm();
        $this->postSigned($this->payload())->assertStatus(201);
        $this->postSigned($this->payload(['final_price' => 9999]))->assertStatus(200);   // 멱등 스킵(200)
        $this->assertSame(1, TaskAlarm::where('type', 'purchase_arrival')->count());      // 재전송 스팸 없음
    }

    public function test_down_payment_auto_resolves_arrival_alarm(): void
    {
        $this->enableAlarm();
        $vid = $this->postSigned($this->payload())->json('vehicle_id');
        $alarm = TaskAlarm::where('type', 'purchase_arrival')->where('vehicle_id', $vid)->first();
        $this->assertNull($alarm->resolved_at);

        PurchaseBalancePayment::create([
            'vehicle_id' => $vid, 'amount' => 1000000, 'type' => 'down',
            'payment_date' => now()->toDateString(),
        ]);

        $this->assertNotNull($alarm->fresh()->resolved_at);
        $this->assertSame('down_payment', $alarm->fresh()->resolved_reason);
    }

    public function test_balance_payment_does_not_resolve_arrival(): void
    {
        $this->enableAlarm();
        $vid = $this->postSigned($this->payload())->json('vehicle_id');

        PurchaseBalancePayment::create([
            'vehicle_id' => $vid, 'amount' => 5000000, 'type' => 'balance',
            'payment_date' => now()->toDateString(),
        ]);

        $alarm = TaskAlarm::where('type', 'purchase_arrival')->where('vehicle_id', $vid)->first();
        $this->assertNull($alarm->resolved_at);   // 잔금은 도착 알람 해소 안 함 (계약금만)
    }

    // ── 가시성 (canSeeAlarm + scopeVisibleTo lockstep) ──────────────────

    private function arrivalAlarm(): TaskAlarm
    {
        $v = Vehicle::create(['vehicle_number' => '71나'.rand(1000, 9999), 'sales_channel' => 'export']);

        return TaskAlarm::create([
            'type' => 'purchase_arrival', 'vehicle_id' => $v->id, 'target_role' => '관리',
            'due_date' => now()->toDateString(),
            'message_meta' => ['vehicle_number' => $v->vehicle_number],
        ]);
    }

    public function test_admin_sees_arrival_alarm(): void
    {
        $admin = User::factory()->create(['permission' => 'admin', 'role' => '관리', 'email_verified_at' => now()]);
        $alarm = $this->arrivalAlarm();

        $this->assertTrue($admin->canSeeAlarm($alarm));
        $this->assertSame(1, TaskAlarm::visibleTo($admin)->count());
    }

    public function test_clearance_user_does_not_see_arrival_alarm(): void
    {
        $clearance = User::factory()->create(['permission' => 'user', 'role' => '수출통관', 'email_verified_at' => now()]);
        $alarm = $this->arrivalAlarm();

        $this->assertFalse($clearance->canSeeAlarm($alarm));
        $this->assertSame(0, TaskAlarm::visibleTo($clearance)->count());
    }

    public function test_sales_user_does_not_see_arrival_alarm(): void
    {
        $sales = User::factory()->create(['permission' => 'user', 'role' => '영업', 'email_verified_at' => now()]);
        $alarm = $this->arrivalAlarm();

        $this->assertFalse($sales->canSeeAlarm($alarm));
        $this->assertSame(0, TaskAlarm::visibleTo($sales)->count());
    }

    public function test_manual_confirm_resolves_arrival_alarm(): void
    {
        $admin = User::factory()->create(['permission' => 'admin', 'role' => '관리', 'email_verified_at' => now()]);
        $alarm = $this->arrivalAlarm();
        $this->actingAs($admin);

        Volt::test('erp.alarm-center')->call('confirm', $alarm->id);

        $alarm->refresh();
        $this->assertNotNull($alarm->confirmed_at);
        $this->assertNotNull($alarm->resolved_at);   // 수동 [확인] = 해소(사라짐)
        $this->assertSame('manual_confirm', $alarm->resolved_reason);
    }
}
