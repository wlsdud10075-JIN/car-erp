<?php

namespace Tests\Feature;

use App\Models\Salesman;
use App\Models\TaskAlarm;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 💱 판매가·환율 수정 알림 (jin 2026-11-18 «판매가 수정이 된다? 이건 알아야 할 거 같아서»).
 *
 * 지키는 것: ①처음 기입(판매가 0→값)은 안 뜬다 ②판매가·TAX D/C·커미션·판매환율이 실제로 바뀌면 뜬다(반올림·표기 차이 제외)
 *            ③같은 차의 안 본 알림엔 덧붙인다 ④받는 사람 = 영업 외 전체(재무 포함, 영업 제외) ⑤[확인] = 해소.
 */
class SalePriceChangeAlarmTest extends TestCase
{
    use RefreshDatabase;

    private function sold(array $extra = []): Vehicle
    {
        $sm = Salesman::firstOrCreate(['name' => 'S'], ['type' => 'employee', 'is_active' => true]);

        return Vehicle::create(array_merge([
            'vehicle_number' => '11가'.random_int(1000, 9999), 'sales_channel' => 'export', 'currency' => 'EUR', 'exchange_rate' => 1480,
            'salesman_id' => $sm->id, 'sale_price' => 8249, 'sale_date' => '2026-11-10',
        ], $extra));
    }

    private function user(string $role, string $perm = 'user'): User
    {
        return User::factory()->create(['permission' => $perm, 'role' => $role, 'email_verified_at' => now()]);
    }

    public function test_first_entry_does_not_alarm(): void
    {
        $v = $this->sold(['sale_price' => 0, 'sale_date' => null]);
        $v->update(['sale_price' => 8249, 'sale_date' => '2026-11-10', 'exchange_rate' => 1500]);

        $this->assertSame(0, TaskAlarm::count());
    }

    public function test_edit_alarms_and_merges_into_one_open_alarm(): void
    {
        $this->actingAs($by = $this->user('관리'));
        $v = $this->sold();
        $v->update(['sale_price' => 8244]);
        $v->update(['exchange_rate' => 1473, 'tax_dc' => 100]);

        $alarms = TaskAlarm::where('type', TaskAlarm::TYPE_PRICE_CHANGED)->get();
        $this->assertCount(1, $alarms, '같은 차의 안 본 알림은 한 줄로');
        $changes = $alarms->first()->message_meta['changes'];
        $this->assertSame(['sale_price', 'tax_dc', 'exchange_rate'], array_column($changes, 'field'));
        $this->assertSame(8249.0, (float) $changes[0]['old']);
        $this->assertSame(8244.0, (float) $changes[0]['new']);
        $this->assertSame($by->id, $changes[0]['by']);
    }

    public function test_rounding_and_same_value_do_not_alarm(): void
    {
        $v = $this->sold(['sale_price' => 103006.44]);
        $v->update(['sale_price' => 103006]);          // 1 미만 반올림
        $v->update(['sale_price' => '103006.00']);     // 표기만 다름
        $v->update(['transport_fee' => 1500]);         // 지켜보는 칸이 아님(운임비)

        $this->assertSame(0, TaskAlarm::count());
    }

    public function test_recipients_are_everyone_but_sales(): void
    {
        $v = $this->sold();
        $v->update(['sale_price' => 9000]);
        $alarm = TaskAlarm::first();

        foreach (['재무', '관리', '수출통관'] as $role) {
            $u = $this->user($role);
            $this->assertTrue($u->canSeeAlarm($alarm), $role);
            $this->assertSame(1, TaskAlarm::query()->visibleTo($u)->count(), $role);
        }
        $this->assertTrue($this->user('관리', 'admin')->canSeeAlarm($alarm));
        $sales = $this->user('영업');
        $this->assertFalse($sales->canSeeAlarm($alarm));
        $this->assertSame(0, TaskAlarm::query()->visibleTo($sales)->count());
    }

    public function test_finance_opens_the_inbox_and_confirm_resolves(): void
    {
        $v = $this->sold();
        $v->update(['sale_price' => 9000]);
        $this->actingAs($this->user('재무'));

        Volt::test('erp.alarms.index')
            ->assertSeeHtml('data-alarm-price')
            ->assertSee('8,249')
            ->call('confirm', TaskAlarm::first()->id);

        $this->assertNotNull(TaskAlarm::first()->resolved_at, '[확인] = 해소');
    }
}
