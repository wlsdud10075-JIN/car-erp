<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\PurchaseBalancePayment;
use App\Models\Setting;
use App\Models\TaskAlarm;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 🧹 일이 끝난 알림은 저절로 사라진다 (jin 2026-11-20 «불필요한 알림은 좀 없어지지 않나»).
 *
 * 지키는 것: ①새 차 도착 = 매입 지급 줄 확정(종류 무관) 또는 「매입중」을 벗어남 ②선적요청 = 반입지 또는 선적일
 *            ③B/L 요청 = B/L 파일 ④통관서류 = 통관 체크 또는 도착일 30일 경과 ⑤삭제된 차의 알림
 *            ⑥아직 안 끝난 알림·다른 종류(선적 변경요청·판매가 수정)는 그대로 ⑦저장하는 순간 그 차 알림이 사라지고,
 *            매일 점검(alarms:scan)이 게이트가 꺼져 있어도 나머지를 정리한다 ⑧dry-run 은 아무것도 안 바꾼다.
 */
class TaskAlarmSettledTest extends TestCase
{
    use RefreshDatabase;

    private function car(string $no, array $extra = []): Vehicle
    {
        return Vehicle::create(array_merge([
            'vehicle_number' => $no, 'sales_channel' => 'export', 'currency' => 'USD', 'exchange_rate' => 1400, 'dhl_request' => false,
            'purchase_price' => 10_000_000, 'selling_fee' => 0, 'purchase_date' => now()->subDays(20)->toDateString(),
        ], $extra))->fresh();
    }

    private function alarm(Vehicle $v, string $type, string $role = '관리'): TaskAlarm
    {
        return TaskAlarm::create(['type' => $type, 'vehicle_id' => $v->id, 'target_role' => $role, 'due_date' => now()->toDateString(),
            'message_meta' => ['vehicle_number' => $v->vehicle_number]]);
    }

    private function open(TaskAlarm $a): bool
    {
        return $a->fresh()->resolved_at === null;
    }

    public function test_new_car_alarm_goes_when_any_purchase_payment_is_confirmed(): void
    {
        $v = $this->car('11가6001');
        $a = $this->alarm($v, 'purchase_arrival');
        $other = $this->alarm($this->car('11가6002'), 'purchase_arrival');

        // 미확정 줄로는 안 사라진다
        PurchaseBalancePayment::create(['vehicle_id' => $v->id, 'type' => 'balance', 'amount' => 3_000_000, 'payment_date' => now()->toDateString()]);
        $this->assertTrue($this->open($a));

        // 잔금 줄이 확정되면(계약금이 아니어도) 사라진다 — 저장하는 순간
        PurchaseBalancePayment::create(['vehicle_id' => $v->id, 'type' => 'balance', 'amount' => 3_000_000, 'payment_date' => now()->toDateString(), 'confirmed_at' => now()]);
        $this->assertFalse($this->open($a));
        $this->assertSame('settled_purchase', $a->fresh()->resolved_reason);
        $this->assertTrue($this->open($other), '아직 손대지 않은 새 차의 알림은 남는다');
    }

    public function test_new_car_alarm_goes_when_the_car_has_moved_past_purchasing(): void
    {
        $v = $this->car('11가6003');
        $a = $this->alarm($v, 'purchase_arrival');
        $this->assertSame('매입중', $v->progress_status_cache);

        $v->update(['buyer_id' => Buyer::create(['name' => 'B', 'is_active' => true])->id, 'sale_price' => 9000, 'sale_date' => now()->toDateString()]);

        $this->assertSame('판매중', $v->fresh()->progress_status_cache);
        $this->assertTrue($this->open($a), '방금 뜬 알림은 단계가 넘어갔다고 바로 지우지 않는다 — board 가 판매 정보까지 채워 보낸 차가 아무도 못 보고 사라진다');

        // 3일 넘게 떠 있었으면 정리한다
        \DB::table('task_alarms')->where('id', $a->id)->update(['created_at' => now()->subDays(4)]);
        TaskAlarm::resolveSettled();
        $this->assertFalse($this->open($a));
    }

    public function test_shipping_bl_and_clearance_alarms_go_when_the_work_is_done(): void
    {
        $ship = $this->car('11가6010');
        $bl = $this->car('11가6011');
        $eta = $this->car('11가6012', ['eta_date' => now()->addDays(3)->toDateString()]);
        $aShip = $this->alarm($ship, 'shipping_requested', '수출통관');
        $aBl = $this->alarm($bl, 'bl_requested', '수출통관');
        $aEta = $this->alarm($eta, 'eta_clearance', '수출통관');
        $aChange = $this->alarm($ship, 'shipping_change_requested', '수출통관');

        $ship->update(['bl_loading_location' => '인천항 3부두']);
        $this->assertFalse($this->open($aShip));
        $this->assertSame('settled_shipping', $aShip->fresh()->resolved_reason);
        $this->assertTrue($this->open($aChange), '선적 변경요청은 선적요청 화면에서 처리한다 — 여기서 안 건드린다');
        $this->assertTrue($this->open($aBl));
        $this->assertTrue($this->open($aEta));

        $bl->update(['bl_document' => 'vehicles/1/bl.pdf']);
        $this->assertFalse($this->open($aBl));

        $eta->update(['is_export_cleared' => true]);
        $this->assertFalse($this->open($aEta));
        $this->assertSame('settled_eta', $aEta->fresh()->resolved_reason);
    }

    public function test_shipping_date_alone_also_settles_the_shipping_request(): void
    {
        $v = $this->car('11가6020');
        $a = $this->alarm($v, 'shipping_requested', '수출통관');
        $v->update(['shipping_date' => now()->addDays(5)->toDateString()]);
        $this->assertFalse($this->open($a));
    }

    public function test_daily_scan_sweeps_the_backlog_even_when_alarm_creation_is_off(): void
    {
        Setting::updateOrCreate(['key' => 'alarm_enabled'], ['value' => '0']);
        $oldEta = $this->car('11가6030');
        $freshEta = $this->car('11가6031');
        $gone = $this->car('11가6032');
        $price = $this->car('11가6033');
        // 저장 훅을 안 타는 경로(raw update·시간 경과·삭제)로 조건이 맞춰진 것들
        \DB::table('vehicles')->where('id', $oldEta->id)->update(['eta_date' => now()->subDays(31)->toDateString()]);
        \DB::table('vehicles')->where('id', $freshEta->id)->update(['eta_date' => now()->subDays(29)->toDateString()]);
        \DB::table('vehicles')->where('id', $gone->id)->update(['deleted_at' => now()]);
        $aOld = $this->alarm($oldEta, 'eta_clearance', '수출통관');
        $aFresh = $this->alarm($freshEta, 'eta_clearance', '수출통관');
        $aGone = $this->alarm($gone, 'shipping_requested', '수출통관');
        $aPrice = $this->alarm($price, TaskAlarm::TYPE_PRICE_CHANGED, TaskAlarm::ROLE_PRICE_WATCH);

        // dry-run 은 세기만 한다
        $this->assertSame(1, TaskAlarm::resolveSettled(null, true)['eta_clearance']);
        $this->assertSame(1, TaskAlarm::resolveSettled(null, true)['vehicle_deleted']);
        $this->artisan('alarms:scan', ['--dry-run' => true])->assertSuccessful();
        $this->assertTrue($this->open($aOld));

        $this->artisan('alarms:scan')->assertSuccessful();

        $this->assertFalse($this->open($aOld), '도착일이 30일 넘게 지난 통관서류 알람');
        $this->assertTrue($this->open($aFresh), '29일은 아직');
        $this->assertFalse($this->open($aGone));
        $this->assertSame('vehicle_deleted', $aGone->fresh()->resolved_reason);
        $this->assertTrue($this->open($aPrice), '판매가 수정 알림은 대상이 아니다');
    }
}
