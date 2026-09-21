<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cookie;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 📱 **데스크탑 표와 모바일 카드를 둘 다 그려 보내던 것** (jin 2026-09-21 「100개 화면이 느리다」).
 *
 * 서버는 화면 폭을 모르니 둘 다 그리고 CSS(`sm:hidden`)로 한쪽을 가렸다 — 100행이면 **같은 목록이 두 벌**.
 * 안 보이는 «칸»을 그려 가리던 §8 #79 와 같은 문제의 «블록»판이다.
 *
 * 실측 (운영 사본 4,960대 · perPage=100):
 *   최초 방문(모름)   1,000KB · DOM 22,956   ← 예전엔 매 로그인이 이랬다
 *   재방문 데스크탑     541KB · DOM  9,720
 *   재방문 폰           305KB · DOM  5,872
 * 거기에 예전엔 페이지 로드마다 `syncVisibleColumns` 왕복이 한 번 더 붙었다(응답 500KB대).
 *
 * 🚨 **정적·구조 검사여야 한다** — 되돌아가도 화면은 정상으로 보이고 응답만 커진다.
 */
class VehicleListViewportRenderTest extends TestCase
{
    use RefreshDatabase;

    private const VIEW = 'resources/views/livewire/erp/vehicles/index.blade.php';

    private function source(): string
    {
        return file_get_contents(base_path(self::VIEW));
    }

    private function actor(): User
    {
        return User::factory()->create([
            'permission' => 'admin', 'role' => '관리', 'email_verified_at' => now(),
        ]);
    }

    private function vehicle(string $plate): Vehicle
    {
        return Vehicle::create([
            'vehicle_number' => $plate, 'sales_channel' => 'export', 'currency' => 'USD',
            'exchange_rate' => 1300, 'purchase_price' => 5_000_000, 'purchase_date' => '2026-07-01',
        ]);
    }

    // ── 「모르면 둘 다」 ────────────────────────────────────────────────

    /** 🔑 화면이 알려 주기 전에는 **둘 다** 그린다 — 반대로 두면 첫 렌더에서 목록이 사라진다. */
    public function test_both_lists_render_while_the_viewport_is_unknown(): void
    {
        $this->actingAs($this->actor());
        $this->vehicle('11가1111');

        $html = Volt::test('erp.vehicles.index')->html();

        $this->assertGreaterThan(0, substr_count($html, '<tbody'), '데스크탑 표가 안 그려졌다');
        $this->assertStringContainsString('block sm:hidden', $html, '모바일 카드가 안 그려졌다');
        $this->assertStringContainsString('11가1111', $html);
    }

    // ── 알면 한쪽만 ────────────────────────────────────────────────────

    /** 데스크탑이라고 알면 모바일 카드는 안 그린다. */
    public function test_a_known_desktop_skips_the_mobile_cards(): void
    {
        $this->actingAs($this->actor());
        $this->vehicle('22나2222');

        $html = Volt::test('erp.vehicles.index')->call('syncViewport', false)->html();

        $this->assertGreaterThan(0, substr_count($html, '<tbody'), '데스크탑 표가 사라졌다');
        $this->assertStringNotContainsString('block sm:hidden space-y-2', $html,
            '데스크탑인데 모바일 카드를 그린다 — 100행이면 목록이 두 벌이다');
    }

    /** 🚨 폰이라고 알면 표는 안 그린다 — 그런데 **카드는 반드시 남아야** 한다(빈 화면 방지). */
    public function test_a_known_phone_skips_the_table_but_keeps_the_cards(): void
    {
        $this->actingAs($this->actor());
        $this->vehicle('33다3333');

        $html = Volt::test('erp.vehicles.index')->call('syncViewport', true)->html();

        $this->assertStringContainsString('block sm:hidden', $html, '폰인데 카드가 없다 — 화면이 빈다');
        $this->assertStringContainsString('33다3333', $html);
        $this->assertSame(0, substr_count($html, '<th'), '폰인데 표 헤더를 그린다');
    }

    /**
     * 🔑 **알고 나면 목록이 한 벌 줄어든다.**
     *    ⚠️ 차량번호 절대 개수로 세지 말 것 — 표의 삭제 버튼 `wire:confirm` 문구에도 번호가 들어가
     *    같은 행에서 2번 나온다. 「모를 때 − 알 때 = 모바일 카드 한 벌」을 **차이로** 단언한다.
     */
    public function test_knowing_the_viewport_drops_exactly_one_copy_of_the_list(): void
    {
        $this->actingAs($this->actor());
        $this->vehicle('44라4444');

        $unknown = substr_count(Volt::test('erp.vehicles.index')->html(), '44라4444');
        $desktop = substr_count(Volt::test('erp.vehicles.index')->call('syncViewport', false)->html(), '44라4444');
        $phone = substr_count(Volt::test('erp.vehicles.index')->call('syncViewport', true)->html(), '44라4444');

        $this->assertGreaterThan($desktop, $unknown, '데스크탑인데 모바일 카드가 그대로 남았다');
        $this->assertGreaterThan($phone, $desktop, '폰인데 표가 그대로 남았다');
        $this->assertSame($unknown, $desktop + $phone, '두 목록의 합이 「모를 때」와 안 맞는다');
    }

    // ── 첫 요청부터 알기 (쿠키) ────────────────────────────────────────

    /**
     * 🍪 **쿠키라야 로그인 직후 첫 요청부터 안다.** 세션만 쓰면 로그인할 때마다
     *    그 한 번이 「36칸 × 100행 × 두 벌」로 나간다.
     */
    public function test_the_preferences_come_back_from_the_cookie_without_a_session(): void
    {
        $this->actingAs($this->actor());
        $this->vehicle('55마5555');

        $html = $this->withUnencryptedCookies(['veh_mobile' => '0', 'veh_cols' => 'brand_model'])
            ->get('/erp/vehicles')->assertOk()->getContent();

        $this->assertStringNotContainsString('block sm:hidden space-y-2', $html,
            '쿠키의 화면폭이 첫 렌더에 반영되지 않았다');
        $this->assertStringContainsString('55마5555', $html, '목록이 통째로 사라졌다');
        // 쿠키의 컬럼 목록(1개)이 첫 렌더에 반영됐나 — 안 되면 37칸이 다 그려진다.
        $this->assertLessThan(20, substr_count($html, '<th'), '쿠키의 컬럼 목록이 첫 렌더에 반영되지 않았다');
    }

    /** 동기화하면 쿠키로 되돌려 준다 — 다음 방문이 바로 가벼워진다. */
    public function test_sync_writes_the_cookies_back(): void
    {
        $this->actingAs($this->actor());

        Volt::test('erp.vehicles.index')->call('syncViewport', true);
        $this->assertSame('1', Cookie::queued('veh_mobile')?->getValue());

        Volt::test('erp.vehicles.index')->call('syncVisibleColumns', ['brand_model', 'vin']);
        $this->assertSame('brand_model,vin', Cookie::queued('veh_cols')?->getValue());
    }

    /** 🔓 이 쿠키들은 **평문**이어야 서버가 첫 요청에 읽는다(암호화 제외 목록에 있어야 한다). */
    public function test_the_preference_cookies_are_not_encrypted(): void
    {
        $src = file_get_contents(base_path('bootstrap/app.php'));
        $this->assertStringContainsString("encryptCookies(except: ['veh_cols', 'veh_mobile'])", $src,
            '암호화 제외에서 빠지면 서버가 쿠키를 못 읽어 첫 화면이 다시 무거워진다');
    }

    // ── 구조 가드 ──────────────────────────────────────────────────────

    /**
     * 🚫 **`@if` 로 감싸면 안 된다** — Livewire 가 조건마다 `<!--[if BLOCK]-->` 주석을 넣어
     *    100행이면 오히려 커진다(§8 #79 실측 908 → 1,084KB).
     */
    public function test_the_list_blocks_are_wrapped_without_livewire_block_markers(): void
    {
        $src = $this->source();

        foreach (['renderDesktopList', 'renderMobileList'] as $fn) {
            $this->assertStringContainsString('@php if ($this->'.$fn.'()): @endphp', $src,
                $fn.' 을 @php if 로 감싸지 않았다');
            $this->assertStringNotContainsString('@if($this->'.$fn.'())', $src,
                $fn.' 을 @if 로 감쌌다 — Livewire 마커가 행마다 붙는다');
        }
    }

    /** 🚨 창 크기가 바뀌면 다시 알려야 한다 — 안 하면 줄였을 때 화면이 빈다. */
    public function test_the_client_re_reports_when_the_breakpoint_changes(): void
    {
        $src = $this->source();

        $this->assertStringContainsString("MQ.addEventListener('change'", $src,
            '브레이크포인트 변화를 안 듣는다 — 창을 줄이면 표는 CSS 가 숨기고 카드는 서버가 안 그려 빈 화면이 된다');
        $this->assertStringContainsString('max-width: 639.98px', $src, 'sm 분기점(640px)과 어긋났다');
    }

    /** 서버가 이미 아는 값이면 컬럼 동기화 왕복을 하지 않는다. */
    public function test_the_column_sync_is_skipped_when_the_server_already_agrees(): void
    {
        $src = $this->source();

        $this->assertStringContainsString('vehicleColumnsToggle(columns, serverKnows)', $src);
        $this->assertStringContainsString('JSON.stringify([...serverKnows].sort())', $src,
            '서버가 아는 값으로 도장을 미리 안 찍으면 페이지 로드마다 왕복이 한 번 더 나간다');
    }
}
