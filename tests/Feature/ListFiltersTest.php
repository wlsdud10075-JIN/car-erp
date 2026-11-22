<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Consignee;
use App\Models\User;
use App\Support\ListFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 🏷️ 필터칩공용(`ListFilters` + `<x-erp.filter-chips>`) — 화면은 필터축만 선언한다 (jin 2026-11-09 기획 · 11-22).
 *
 * 지키는 것: ①single = 하나만 켜짐·다시 누르면 해제 ②multi = 켜고 끔·전부 끄면 조건 없음 ③bool
 *            ④선언에 없는 축·값은 무시(클릭이든 URL 이든 — §8 #26) ⑤축끼리는 AND ⑥apply 클로저 ⑦show=false 축은 안 그리고 안 건다
 *            ⑧hide_when_zero ⑨칩은 행 뱃지와 같은 클래스 + 꺼짐 흐리게/켜짐 테두리 ⑩실제 화면(컨사이니)에서 끝까지
 */
class ListFiltersTest extends TestCase
{
    use RefreshDatabase;

    private function screen(array $extra = [])
    {
        return new class($extra)
        {
            use ListFilters;

            public int $resets = 0;

            public function __construct(public array $extra) {}

            public function resetPage(): void
            {
                $this->resets++;
            }

            protected function filterChips(): array
            {
                return [
                    'active' => ['type' => 'single', 'column' => 'is_active', 'options' => [
                        '1' => ['label' => '활성', 'class' => 'badge badge-green', 'count' => 2],
                        '0' => ['label' => '비활성', 'class' => 'badge badge-gray', 'count' => 0],
                    ]] + ($this->extra['active'] ?? []),
                    'name' => ['type' => 'multi', 'column' => 'name', 'options' => ['AAA' => 'AAA', 'BBB' => 'BBB', 'CCC' => 'CCC']],
                    'has_mail' => ['type' => 'bool', 'label' => '메일 있음', 'class' => 'badge badge-blue',
                        'apply' => fn (Builder $q) => $q->whereNotNull('contact_email')] + ($this->extra['has_mail'] ?? []),
                ];
            }

            public function names(): array
            {
                return $this->applyChipFilters(Buyer::query())->orderBy('name')->pluck('name')->all();
            }
        };
    }

    private function seedBuyers(): void
    {
        Buyer::create(['name' => 'AAA', 'is_active' => true, 'contact_email' => 'a@x.com']);
        Buyer::create(['name' => 'BBB', 'is_active' => true]);
        Buyer::create(['name' => 'CCC', 'is_active' => false, 'contact_email' => 'c@x.com']);
    }

    public function test_click_rules_for_single_multi_and_bool(): void
    {
        $this->seedBuyers();
        $s = $this->screen();
        $this->assertSame(['AAA', 'BBB', 'CCC'], $s->names());

        $s->chipClick('active', '0');
        $this->assertSame(['CCC'], $s->names());
        $s->chipClick('active', '1');
        $this->assertSame(['AAA', 'BBB'], $s->names(), 'single = 하나만 켜진다');
        $s->chipClick('active', '1');
        $this->assertSame(['AAA', 'BBB', 'CCC'], $s->names(), '같은 칩을 다시 누르면 해제');

        $s->chipClick('name', 'AAA');
        $s->chipClick('name', 'CCC');
        $this->assertSame(['AAA', 'CCC'], $s->names(), 'multi = 여러 개');
        $s->chipClick('has_mail');
        $s->chipClick('active', '1');
        $this->assertSame(['AAA'], $s->names(), '축끼리는 AND · apply 클로저');
        $s->chipClick('name', 'AAA');
        $s->chipClick('name', 'CCC');
        $this->assertArrayNotHasKey('name', $s->chipFilters, 'multi 를 전부 끄면 축이 사라진다');
        $this->assertSame(9, $s->resets, '칩을 누를 때마다 1페이지로');
    }

    /** 클라이언트가 보내는 값이다 — 선언 밖의 축·값은 클릭이든 주소창이든 조용히 무시한다. */
    public function test_values_outside_the_declaration_are_ignored(): void
    {
        $this->seedBuyers();
        $s = $this->screen();

        $s->chipClick('nope', '1');
        $s->chipClick('active', '9');
        $s->chipClick('has_mail', 'x');
        $this->assertSame([], $s->chipFilters);
        $this->assertSame(0, $s->resets);

        $s->chipFilters = ['active' => '9', 'name' => ['AAA', 'ZZZ', ['x']], 'has_mail' => ['1'], 'is_admin' => '1'];
        $this->assertSame(['AAA'], $s->names(), '주소창으로 들어온 엉뚱한 값은 걸리지 않는다(유효한 AAA 만)');
    }

    public function test_axes_for_rendering_hide_rules_and_badge_classes(): void
    {
        $s = $this->screen(['active' => ['hide_when_zero' => true], 'has_mail' => ['show' => false]]);

        $axes = collect($s->chipAxes())->keyBy('axis');
        $this->assertFalse($axes->has('has_mail'), 'show=false 축은 안 그린다');
        $this->assertSame(['1'], array_column($axes['active']['chips'], 'value'), '건수 0 인 칩은 숨긴다');
        $this->assertSame('badge badge-green', $axes['active']['chips'][0]['class'], '칩 = 행 뱃지와 같은 클래스');

        $s->chipFilters = ['active' => '0', 'has_mail' => '1'];
        $this->assertSame(['1', '0'], array_column(collect($s->chipAxes())->keyBy('axis')['active']['chips'], 'value'), '켜져 있는 칩은 0건이어도 남긴다');
        $this->seedBuyers();
        $this->assertSame(['CCC'], $s->names(), 'show=false 축은 걸지도 않는다');

        $html = (string) $this->blade('<x-erp.filter-chips :axes="$axes" />', ['axes' => $s->chipAxes()]);
        $this->assertStringContainsString('data-filter-chip="active:0"', $html);
        $this->assertMatchesRegularExpression('/aria-pressed="true"[^>]*data-filter-chip="active:0"[^>]*class="badge badge-gray[^"]*ring-2/s', $html, '켜짐 = 테두리');
        $this->assertMatchesRegularExpression('/data-filter-chip="active:1"[^>]*class="badge badge-green[^"]*opacity-60/s', $html, '꺼짐 = 흐리게');
        $this->assertStringContainsString('활성 (2)', preg_replace('/\s+/', ' ', $html), '건수');
    }

    /** 실제 화면 — 컨사이니 목록의 「활성/비활성」 뱃지가 같은 모양의 칩으로 걸린다. */
    public function test_consignee_screen_filters_by_the_active_chip_and_search(): void
    {
        $this->actingAs(User::factory()->create(['permission' => 'admin', 'role' => '관리', 'email_verified_at' => now()]));
        $buyer = Buyer::create(['name' => 'BUYER', 'is_active' => true]);
        Consignee::create(['name' => 'ALPHA LOGIS', 'buyer_id' => $buyer->id, 'is_active' => true, 'contact_email' => 'alpha@x.com']);
        Consignee::create(['name' => 'BETA CARGO', 'buyer_id' => $buyer->id, 'is_active' => false]);

        $c = Volt::test('erp.consignees.index');
        $c->assertSee('ALPHA LOGIS')->assertSee('BETA CARGO')->assertSeeHtml('data-filter-chip="active:1"')->assertSeeHtml('data-search-input');

        $c->call('chipClick', 'active', '0')->assertSee('BETA CARGO')->assertDontSee('ALPHA LOGIS');
        $c->call('chipClick', 'active', '0')->assertSee('ALPHA LOGIS');
        $c->set('search', 'alpha@x.com ')->call('searchNow')->assertSee('ALPHA LOGIS')->assertDontSee('BETA CARGO');
        $c->call('clearSearch')->assertSee('BETA CARGO');
    }
}
