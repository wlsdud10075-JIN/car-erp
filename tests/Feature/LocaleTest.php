<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocaleTest extends TestCase
{
    use RefreshDatabase;

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
}
