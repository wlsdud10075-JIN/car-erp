<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 🗓️ 「날짜 기준이 전체라 기간이 안 걸린다」는 안내가 **언제** 뜨는가 (jin 2026-09-10).
 *
 * 배경: 날짜 기준 기본이 「전체」이고 기간 기본이 **1년**이라, 조건만 보면 진입 직후부터 늘 참이다.
 * 처음엔 필터바에 상시 뱃지로 만들었는데 jin 이 막았다 —
 * *"저렇게 계속 자리 차지하고 있는 게 더 이상한데... 그걸 움직이거나 변동했을 때 팝업이 뜨게"*.
 *
 * ⇒ **기간을 바꿔서 조회할 때만** 알린다. 진입 상태에서는 조용해야 한다.
 *   그 「바꿨나」 판정이 이 테스트의 전부다 — 틀리면 안내가 상주하거나(시끄럽다) 영영 안 뜬다(무용).
 */
class VehicleDateRangeNoticeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create([
            'permission' => 'admin', 'role' => '관리', 'email_verified_at' => now(),
        ]);
    }

    private function screen()
    {
        return Volt::actingAs($this->admin())->test('erp.vehicles.index');
    }

    /** 진입 직후 조회는 조용하다 — 기본 기간(1년)은 사용자가 넣은 게 아니다. */
    public function test_untouched_default_range_says_nothing(): void
    {
        $this->screen()
            ->call('applyFilters')
            ->assertNotDispatched('notify');
    }

    /** 기간을 바꿔 조회하면 알린다 — 이때가 「좁혔다고 믿는」 순간이다. */
    public function test_changing_the_range_warns_that_it_will_not_apply(): void
    {
        $this->screen()
            ->set('dateFrom', '2026-09-01')
            ->call('applyFilters')
            ->assertDispatched('notify');
    }

    /** 날짜 기준을 고른 뒤에는 기간이 실제로 걸리므로 알리지 않는다. */
    public function test_no_warning_once_a_date_basis_is_chosen(): void
    {
        $this->screen()
            ->set('dateType', 'purchase')
            ->set('dateFrom', '2026-09-01')
            ->call('applyFilters')
            ->assertNotDispatched('notify');
    }

    /**
     * 🚫 상시 표시로 되돌아가지 않는다 — 필터바는 이미 빽빽하고, 조건이 늘 참이라
     *    뱃지로 두면 화면에 상주한다(jin 이 그래서 물렸다). 되돌아가도 화면은 정상 렌더된다.
     */
    public function test_the_notice_is_not_a_permanent_badge(): void
    {
        $src = file_get_contents(base_path('resources/views/livewire/erp/vehicles/index.blade.php'));

        $this->assertStringNotContainsString("__('vehicle.date_range_ignored')", $src,
            '기간 미적용 안내가 다시 상시 뱃지가 됐다 — 조회 시 토스트로 알릴 것');
        $this->assertStringContainsString("__('vehicle.date_range_ignored_hint')", $src,
            '안내 자체가 사라졌다');
    }

    /** 🔒 판정 기준값은 클라이언트가 못 바꾼다 — 바꾸면 안내가 조용히 꺼진다. */
    public function test_the_baseline_is_locked(): void
    {
        $src = file_get_contents(base_path('resources/views/livewire/erp/vehicles/index.blade.php'));

        $this->assertMatchesRegularExpression(
            '/#\[Locked\]\s*\n\s*public string \$defaultDateFrom/',
            $src,
            '기본 기간 판정값에 #[Locked] 가 없다 — 클라이언트가 덮으면 안내가 안 뜬다'
        );
    }
}
