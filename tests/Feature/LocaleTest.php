<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class LocaleTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        // Volt::test + 수동 app()->setLocale('en') 가 다음 테스트로 누수되지 않도록 복원.
        app()->setLocale(config('app.locale'));
        parent::tearDown();
    }

    private function localeUser(string $locale = 'ko'): User
    {
        return User::factory()->create([
            'permission' => 'admin',
            'locale' => $locale,
            'email_verified_at' => now(),
        ]);
    }

    private function enableEnglish(bool $on = true): void
    {
        Setting::updateOrCreate(
            ['key' => 'locale_en_enabled'],
            ['value' => $on ? '1' : '0', 'type' => 'boolean'],
        );
    }

    public function test_renders_korean_sidebar_by_default(): void
    {
        $this->actingAs($this->localeUser('ko'))
            ->get(route('erp.dashboard'))
            ->assertOk()
            ->assertSee('로그아웃')
            ->assertDontSee('Logout');
    }

    public function test_forces_korean_when_english_disabled_even_if_user_locale_is_en(): void
    {
        $this->enableEnglish(false);

        $this->actingAs($this->localeUser('en'))
            ->get(route('erp.dashboard'))
            ->assertOk()
            ->assertSee('로그아웃')
            ->assertDontSee('Logout');
    }

    public function test_renders_english_sidebar_when_enabled_and_user_locale_is_en(): void
    {
        $this->enableEnglish(true);

        $this->actingAs($this->localeUser('en'))
            ->get(route('erp.dashboard'))
            ->assertOk()
            ->assertSee('Logout')
            ->assertDontSee('로그아웃');
    }

    public function test_language_switcher_shows_only_when_english_enabled(): void
    {
        $this->actingAs($this->localeUser('ko'))
            ->get(route('erp.dashboard'))
            ->assertDontSee('English');

        $this->enableEnglish(true);

        $this->actingAs($this->localeUser('ko'))
            ->get(route('erp.dashboard'))
            ->assertSee('English');
    }

    public function test_switch_route_persists_user_locale_when_english_enabled(): void
    {
        $this->enableEnglish(true);
        $user = $this->localeUser('ko');

        $this->actingAs($user)
            ->post(route('locale.update'), ['locale' => 'en'])
            ->assertRedirect();

        $this->assertSame('en', $user->fresh()->locale);
    }

    public function test_switch_route_forces_korean_when_english_disabled(): void
    {
        $this->enableEnglish(false);
        $user = $this->localeUser('ko');

        $this->actingAs($user)
            ->post(route('locale.update'), ['locale' => 'en'])
            ->assertRedirect();

        $this->assertSame('ko', $user->fresh()->locale);
    }

    public function test_dashboard_body_translates_to_english(): void
    {
        $this->enableEnglish(true);

        $this->actingAs($this->localeUser('en'))
            ->get(route('erp.dashboard'))
            ->assertOk()
            ->assertSee('Action Items')
            ->assertSee('Vehicle Pipeline')
            ->assertDontSee('처리 필요 항목');
    }

    public function test_dashboard_body_stays_korean_by_default(): void
    {
        $this->actingAs($this->localeUser('ko'))
            ->get(route('erp.dashboard'))
            ->assertOk()
            ->assertSee('처리 필요 항목')
            ->assertSee('차량 진행 단계');
    }

    public function test_vehicle_list_translates_to_english(): void
    {
        $this->enableEnglish(true);

        $this->actingAs($this->localeUser('en'))
            ->get(route('erp.vehicles.index'))
            ->assertOk()
            ->assertSee('Add Vehicle')
            ->assertSee('Visible Columns')
            ->assertDontSee('차량 등록');
    }

    public function test_vehicle_list_stays_korean_by_default(): void
    {
        $this->actingAs($this->localeUser('ko'))
            ->get(route('erp.vehicles.index'))
            ->assertOk()
            ->assertSee('차량 등록');
    }

    public function test_vehicle_panel_basic_tab_translates_to_english(): void
    {
        $this->enableEnglish(true);
        app()->setLocale('en');   // Volt::test 는 SetLocale 미들웨어를 안 거침
        $this->actingAs($this->localeUser('en'));

        Volt::test('erp.vehicles.index')
            ->call('openCreate')
            ->assertSee('NICE Registration (12)')   // 기본정보 탭 섹션
            ->assertSee('Export Clearance')          // 패널 탭 네비
            ->assertSee('9 Cost Items')              // 매입 탭 섹션
            ->assertSee('Seller Account (remittance target)')
            ->assertSee('Payment Status')            // 판매 탭 섹션
            ->assertSee('Sale Basics')
            ->assertSee('DHL Recipient')             // DHL 탭
            ->assertSee('Purchase Documents (3)')    // 서류 탭
            ->assertSee('Power of Attorney')
            ->assertSee('Create')                    // 저장바(신규)
            ->assertDontSee('NICE 등록정보 (12)')
            ->assertDontSee('비용 9개')
            ->assertDontSee('입금 현황')
            ->assertDontSee('DHL 수취인')
            ->assertDontSee('위임장');
    }
}
