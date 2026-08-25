<?php

namespace Tests\Feature;

use App\Models\ForwardingCompany;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 포워딩사 화물추적 링크 (jin 2026-08-25).
 *
 * 판정 단일 출처 = `Vehicle::tracking_url`. 화면·API·(나중에) ssancar 포털이 전부 이걸 읽는다 —
 * 조건을 어딘가에 옮겨 적으면 「한쪽만 열리는」 상태가 되고 예외도 로그도 안 남는다.
 */
class ForwardingTrackingLinkTest extends TestCase
{
    use RefreshDatabase;

    private const TEMPLATE = 'https://www.cigbooking.com/track/{VIN}';

    private const VIN = 'WBAJD9100JWC11399';

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function company(?string $template = self::TEMPLATE): ForwardingCompany
    {
        return ForwardingCompany::create([
            'name' => 'TRK '.uniqid(),
            'is_active' => true,
            'tracking_url_template' => $template,
        ]);
    }

    private function vehicle(?ForwardingCompany $fc, ?string $shippingDate, string $vin = self::VIN): Vehicle
    {
        $v = Vehicle::create([
            'vehicle_number' => '99가'.random_int(1000, 9999),
            'sales_channel' => 'export',
            'forwarding_company_id' => $fc?->id,
            'nice_reg_vin' => $vin,
            'shipping_date' => $shippingDate,
        ]);

        return $v->fresh()->load('forwardingCompany');
    }

    /** 🔑 jin 요구 — 링크가 없는 포워딩사는 버튼도 안내문구도 **아예 안 뜬다**. */
    public function test_forwarder_without_a_template_shows_nothing_at_all(): void
    {
        $v = $this->vehicle($this->company(null), now()->subDays(18)->toDateString());

        $this->assertNull($v->tracking_url);
        $this->assertNull($v->tracking_block_reason, '링크가 없는 회사엔 사유조차 띄우지 않는다 — 그 회사는 추적을 안 하는 것이라 설명할 게 없다');
    }

    public function test_vehicle_without_a_forwarder_shows_nothing_at_all(): void
    {
        $v = $this->vehicle(null, now()->subDays(18)->toDateString());

        $this->assertNull($v->tracking_url);
        $this->assertNull($v->tracking_block_reason);
    }

    public function test_link_opens_from_the_day_after_departure(): void
    {
        $fc = $this->company();

        $this->assertSame(
            'https://www.cigbooking.com/track/'.self::VIN,
            $this->vehicle($fc, now()->subDay()->toDateString())->tracking_url,
            'D+1 이면 열린다'
        );
        $this->assertNotNull($this->vehicle($fc, now()->subDays(18)->toDateString())->tracking_url);
    }

    /**
     * 출항 당일은 아직 닫혀 있다 — 선사도 선적 후 준비·데이터 전달에 시간이 걸린다(jin).
     * 여기서 열어 두면 「눌렀는데 없다」가 나온다.
     */
    public function test_departure_day_is_still_too_early(): void
    {
        $v = $this->vehicle($this->company(), now()->toDateString());

        $this->assertNull($v->tracking_url);
        $this->assertSame('too_early', $v->tracking_block_reason);
    }

    /**
     * 🚨 `sailing_phase` 로 판정하면 안 되는 이유 — 선적일이 **미래**여도 ETA 만 있으면 `운항중`이 된다.
     * 실측 CIG 61대 중 4대가 정확히 이 상태였고 조회가 안 됐다.
     */
    public function test_future_shipping_date_is_closed_even_when_sailing_phase_says_in_transit(): void
    {
        $fc = $this->company();
        $v = $this->vehicle($fc, now()->addDays(6)->toDateString());
        $v->update(['eta_date' => now()->addDays(40)->toDateString()]);
        $v = $v->fresh()->load('forwardingCompany');

        $this->assertSame('in_transit', $v->sailing_phase, '전제 확인 — 선적일이 미래인데도 운항중으로 잡힌다');
        $this->assertNull($v->tracking_url, '그래도 링크는 닫혀 있어야 한다');
        $this->assertSame('too_early', $v->tracking_block_reason);
    }

    public function test_missing_shipping_date_or_vin_is_explained_not_hidden(): void
    {
        $fc = $this->company();

        $this->assertSame('not_departed', $this->vehicle($fc, null)->tracking_block_reason);
        $this->assertSame('no_vin', $this->vehicle($fc, now()->subDays(5)->toDateString(), '')->tracking_block_reason);
    }

    /** 템플릿에 `{VIN}` 이 없으면 어느 차를 눌러도 같은 페이지로 간다 — 「되는 것처럼 보이는」 링크를 만들지 않는다. */
    public function test_template_without_the_placeholder_produces_no_link(): void
    {
        $fc = $this->company('https://www.cigbooking.com/track');

        $this->assertNull($fc->trackingUrlFor(self::VIN));
    }

    public function test_vin_is_url_encoded(): void
    {
        $this->assertSame(
            'https://www.cigbooking.com/track/AB%20CD',
            $this->company()->trackingUrlFor('AB CD')
        );
    }

    /** 저장 화면 가드 — https 아닌 값·플레이스홀더 없는 값은 거부한다. */
    public function test_admin_screen_rejects_unsafe_templates(): void
    {
        $this->actingAs(User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]));

        foreach (['javascript:alert(1){VIN}', 'http://insecure.example/{VIN}', 'https://example.com/track'] as $bad) {
            Volt::test('erp.forwarding-companies.index')
                ->call('openCreate')
                ->set('name', 'BAD')
                ->set('tracking_url_template', $bad)
                ->call('save')
                ->assertHasErrors('tracking_url_template');
        }

        Volt::test('erp.forwarding-companies.index')
            ->call('openCreate')
            ->set('name', 'GOOD')
            ->set('tracking_url_template', self::TEMPLATE)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(self::TEMPLATE, ForwardingCompany::where('name', 'GOOD')->value('tracking_url_template'));
    }

    /** 새 등록 폼에 직전 회사의 URL 이 남으면 안 된다. */
    public function test_create_form_does_not_keep_the_previous_url(): void
    {
        $this->actingAs(User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]));
        $fc = $this->company();

        Volt::test('erp.forwarding-companies.index')
            ->call('openEdit', $fc->id)
            ->assertSet('tracking_url_template', self::TEMPLATE)
            ->call('openCreate')
            ->assertSet('tracking_url_template', '');
    }

    /** 화면이 조건을 옮겨 적지 않고 모델 값을 그대로 읽는지 — 정적 검사(값이 비어도 렌더는 정상이라 기능 테스트로는 못 잡는다). */
    public function test_vehicle_panel_reads_the_single_source(): void
    {
        $blade = file_get_contents(resource_path('views/livewire/erp/vehicles/index.blade.php'));

        $this->assertStringContainsString('$this->trackingLink', $blade);
        $this->assertStringNotContainsString('TRACKING_ACTIVE_AFTER_DAYS', $blade, '지연일 판정을 화면에 복제하지 말 것 — Vehicle::tracking_url 하나만 본다');
    }

    /** 담당자 관계가 있어도 추가 쿼리가 터지지 않게 eager load 되는지. */
    public function test_panel_eager_loads_the_forwarder(): void
    {
        $this->actingAs(User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]));
        Salesman::create(['name' => '영업', 'is_active' => true, 'type' => 'freelance']);
        $v = $this->vehicle($this->company(), now()->subDays(3)->toDateString());

        $link = Volt::test('erp.vehicles.index')->call('openEdit', $v->id)->get('trackingLink');

        $this->assertIsArray($link);
        $this->assertNotNull($link['url'] ?? null);
    }
}
