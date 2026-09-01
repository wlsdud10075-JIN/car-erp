<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\BulkVehicleShippingDateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 컨테이너 번호 **접두어** 일괄 교체 (jin 2026-09-01).
 *
 * ssancarerp 는 RORO 여도 자체 관리코드를 적는다 — `6.08_G RORO 12-33_5`
 * (6=26년 · 08=월 · G=선박 약칭). 선적이 밀리면 한 배가 통째로 다음 배로 넘어가고,
 * 그때 **앞 토큰만** `6.09_A` 로 바뀐다. 뒤 화물 자리는 그대로다.
 *
 * 실측(2026-09-01 ssancarerp 4,407건): 접두어형 3,576 · ISO 748 · 그 외 83 · 접두어 22종.
 * ⇒ ISO 번호와 옛 표기를 **건드리지 않는 것**이 이 테스트의 핵심이다.
 */
class BulkContainerPrefixTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function vehicle(string $containerNo, string $vessel = 'GMT'): Vehicle
    {
        $sm = Salesman::firstOrCreate(['name' => 'CTMAN'], ['type' => 'employee', 'is_active' => true]);

        return Vehicle::create([
            'vehicle_number' => sprintf('%02d가%04d', 10 + ++$this->n, 1000 + $this->n),
            'sales_channel' => 'export', 'currency' => 'USD', 'exchange_rate' => 1350,
            'dhl_request' => false, 'salesman_id' => $sm->id,
            'purchase_price' => 5_000_000, 'purchase_date' => now()->toDateString(),
            'vessel_name' => $vessel, 'container_number' => $containerNo,
        ]);
    }

    private function clearanceUser(): User
    {
        return User::factory()->create(['permission' => 'user', 'role' => '수출통관', 'email_verified_at' => now()]);
    }

    // ── 순수 함수 ────────────────────────────────────────────────

    public function test_only_the_leading_token_is_replaced(): void
    {
        $this->assertSame(
            '6.09_A RORO 12-33_5',
            BulkVehicleShippingDateService::replaceContainerPrefix('6.08_G RORO 12-33_5', '6.08_G', '6.09_A')
        );
    }

    /** 🔑 뒤에 우연히 같은 문자열이 또 있어도 앞 토큰만 바뀐다(str_replace 였다면 둘 다 바뀐다). */
    public function test_a_repeat_of_the_prefix_later_in_the_string_is_untouched(): void
    {
        $this->assertSame(
            '6.09_A RORO 6.08_G-2',
            BulkVehicleShippingDateService::replaceContainerPrefix('6.08_G RORO 6.08_G-2', '6.08_G', '6.09_A')
        );
    }

    public function test_partial_token_match_is_rejected(): void
    {
        // '6.08_GX' 는 '6.08_G' 로 시작하지만 **다른 배**다 — 토큰 경계를 안 보면 여기가 깨진다.
        $this->assertNull(
            BulkVehicleShippingDateService::replaceContainerPrefix('6.08_GX RORO 1-1', '6.08_G', '6.09_A')
        );
    }

    public function test_iso_and_legacy_numbers_are_never_touched(): void
    {
        foreach (['EISU8533921', 'MRKU4045753 /  KR1240933', 'RORO 4-19', '-', ''] as $no) {
            $this->assertNull(
                BulkVehicleShippingDateService::replaceContainerPrefix($no, '6.08_G', '6.09_A'),
                "건드리면 안 되는 값이 바뀌었다: {$no}"
            );
        }
    }

    public function test_prefix_of_recognizes_only_the_prefix_shape(): void
    {
        $this->assertSame('6.08_G', BulkVehicleShippingDateService::containerPrefixOf('6.08_G RORO 12-33_5'));
        $this->assertSame('', BulkVehicleShippingDateService::containerPrefixOf('EISU8533921'));
        $this->assertSame('', BulkVehicleShippingDateService::containerPrefixOf('RORO 4-19'));
        $this->assertSame('', BulkVehicleShippingDateService::containerPrefixOf('-'));
        $this->assertSame('', BulkVehicleShippingDateService::containerPrefixOf(null));
    }

    // ── 서비스 ───────────────────────────────────────────────────

    public function test_breakdown_counts_prefixes_and_lumps_the_rest(): void
    {
        $this->vehicle('6.08_G RORO 12-33_5');
        $this->vehicle('6.08_G RORO 12-34_1');
        $this->vehicle('6.08_H RORO 1-1');
        $this->vehicle('EISU8533921');

        $out = app(BulkVehicleShippingDateService::class)->containerPrefixBreakdown(Vehicle::query());

        $this->assertSame(2, $out['6.08_G']);
        $this->assertSame(1, $out['6.08_H']);
        $this->assertSame(1, $out[''], 'ISO 번호는 접두어 없음으로 묶인다');
    }

    public function test_apply_moves_one_prefix_and_leaves_the_others(): void
    {
        $user = $this->clearanceUser();
        $this->actingAs($user);
        $move = $this->vehicle('6.08_G RORO 12-33_5');
        $stay = $this->vehicle('6.08_H RORO 1-1');
        $iso = $this->vehicle('EISU8533921');

        $out = app(BulkVehicleShippingDateService::class)->apply(
            Vehicle::query(), [], $user, '9월 A선 이월', ['from' => '6.08_G', 'to' => '6.09_A']
        );

        $this->assertSame(1, $out['applied']);
        $this->assertSame(2, $out['unchanged']);
        $this->assertSame('6.09_A RORO 12-33_5', $move->fresh()->container_number);
        $this->assertSame('6.08_H RORO 1-1', $stay->fresh()->container_number);
        $this->assertSame('EISU8533921', $iso->fresh()->container_number);
    }

    /** 선적일이 밀린 게 원인이라 날짜와 **한 번에** 바뀌어야 한다 — 두 번 나눠 하면 중간 상태가 남는다. */
    public function test_prefix_and_shipping_date_move_together(): void
    {
        $user = $this->clearanceUser();
        $this->actingAs($user);
        $v = $this->vehicle('6.08_G RORO 12-33_5');

        app(BulkVehicleShippingDateService::class)->apply(
            Vehicle::query(),
            ['shipping_date' => '20260910', 'eta_date' => '2026-10-05'],
            $user, '9월 A선 이월', ['from' => '6.08_G', 'to' => '6.09_A']
        );

        $v->refresh();
        $this->assertSame('6.09_A RORO 12-33_5', $v->container_number);
        $this->assertSame('2026-09-10', $v->shipping_date->format('Y-m-d'), '8자리 날짜도 같이 정규화된다');
        $this->assertSame('2026-10-05', $v->eta_date->format('Y-m-d'));
    }

    /** 🚨 수백 대가 한 번에 바뀌는데 흔적이 없으면 되짚을 수가 없다. */
    public function test_container_number_change_is_audited(): void
    {
        $user = $this->clearanceUser();
        $this->actingAs($user);
        $v = $this->vehicle('6.08_G RORO 12-33_5');

        app(BulkVehicleShippingDateService::class)->apply(
            Vehicle::query(), [], $user, '9월 A선 이월', ['from' => '6.08_G', 'to' => '6.09_A']
        );

        $row = AuditLog::where('auditable_id', $v->id)
            ->where('column_name', 'container_number')->first();

        $this->assertNotNull($row, 'container_number 가 AUDITED_COLUMNS 에 없으면 조용히 안 남는다');
        $this->assertSame('6.08_G RORO 12-33_5', $row->old_value);
        $this->assertSame('6.09_A RORO 12-33_5', $row->new_value);
        $this->assertNotNull(
            AuditLog::where('auditable_id', $v->id)->where('action', 'bulk_shipping_date_applied')->first(),
            '일괄 출처(사유)도 따로 남아야 한다'
        );
    }

    public function test_half_filled_prefix_is_rejected_with_a_reason(): void
    {
        $user = $this->clearanceUser();
        $this->actingAs($user);
        $this->vehicle('6.08_G RORO 1-1');

        $this->expectException(InvalidArgumentException::class);
        app(BulkVehicleShippingDateService::class)->apply(
            Vehicle::query(), [], $user, 'x', ['from' => '6.08_G', 'to' => '']
        );
    }

    /** 공백이 들어가면 토큰 경계가 깨져 뒤 화물 자리가 통째로 밀린다 — 입구에서 막는다. */
    public function test_new_prefix_with_a_space_is_rejected(): void
    {
        $user = $this->clearanceUser();
        $this->actingAs($user);
        $this->vehicle('6.08_G RORO 1-1');

        $this->expectException(InvalidArgumentException::class);
        app(BulkVehicleShippingDateService::class)->apply(
            Vehicle::query(), [], $user, 'x', ['from' => '6.08_G', 'to' => '6.09_A RORO']
        );
    }

    public function test_nothing_filled_at_all_is_still_rejected(): void
    {
        $user = $this->clearanceUser();
        $this->actingAs($user);

        $this->expectException(InvalidArgumentException::class);
        app(BulkVehicleShippingDateService::class)->apply(Vehicle::query(), [], $user, 'x');
    }

    // ── 화면 ─────────────────────────────────────────────────────

    public function test_modal_offers_only_prefixes_present_in_the_target(): void
    {
        $user = $this->clearanceUser();
        $this->actingAs($user);
        $this->vehicle('6.08_G RORO 12-33_5', 'GMT');
        $this->vehicle('EISU8533921', 'GMT');
        $this->vehicle('6.01_Y RORO 9-9', 'OTHER');

        $c = Volt::test('erp.vehicles.index')->set('search', 'GMT')->call('openBulkDate');
        $prefixes = $c->instance()->bulkContainerPrefixes();

        $this->assertSame(['6.08_G' => 1], $prefixes,
            '대상 밖 접두어(6.01_Y)나 ISO 번호가 목록에 뜨면 고를 수 없는 값을 고르게 된다');
    }

    public function test_screen_applies_prefix_with_the_dates(): void
    {
        $user = $this->clearanceUser();
        $this->actingAs($user);
        $v = $this->vehicle('6.08_G RORO 12-33_5', 'GMT');

        Volt::test('erp.vehicles.index')
            ->set('search', 'GMT')
            ->call('openBulkDate')
            ->set('bulkShipDate', '2026-09-10')
            ->set('bulkContainerFrom', '6.08_G')
            ->set('bulkContainerTo', '6.09_A')
            ->set('bulkDateReason', '9월 A선 이월')
            ->call('applyBulkDate')
            ->assertSet('bulkDateOpen', false);

        $v->refresh();
        $this->assertSame('6.09_A RORO 12-33_5', $v->container_number);
        $this->assertSame('2026-09-10', $v->shipping_date->format('Y-m-d'));
    }

    /** 한쪽만 채우고 누르면 저장되지 않고 사유가 토스트로 나와야 한다(조용히 무시 금지). */
    public function test_screen_reports_half_filled_prefix_instead_of_silently_ignoring(): void
    {
        $user = $this->clearanceUser();
        $this->actingAs($user);
        $v = $this->vehicle('6.08_G RORO 12-33_5', 'GMT');

        Volt::test('erp.vehicles.index')
            ->set('search', 'GMT')
            ->call('openBulkDate')
            ->set('bulkContainerFrom', '6.08_G')
            ->call('applyBulkDate')
            ->assertSet('bulkDateOpen', true)
            ->assertDispatched('notify');

        $this->assertSame('6.08_G RORO 12-33_5', $v->fresh()->container_number);
    }
}
