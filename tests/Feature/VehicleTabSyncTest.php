<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 🪟 여러 탭 동기화 (jin 2026-10-08).
 *
 * 한 사람이 탭 3개로 차량관리를 쓰다 탭3에서 저장하면, 탭1은 옛 폼이라 저장 순간 「옛 폼 저장 거부」가 떴다.
 * 그 가드는 그대로 두고(PaymentRowDoubleSaveTest), **가드가 터지기 전에** 다른 탭이 알아채게 한다:
 *   - 안 건드린 탭 → 조용히 최신으로 다시 연다
 *   - 입력 중인 탭 → 배너 + 저장 차단(입력은 보존) + [최신으로 다시 열기]
 * 신호 = 같은 브라우저 BroadcastChannel(즉시) · 30초 하트비트(다른 사용자). 판정은 항상 editFingerprint().
 *
 * ⚠️ editFingerprint() 는 updated_at 을 **초 단위**로 본다 — 같은 초 안의 update 는 지문이 안 바뀐다.
 *    「다른 탭이 저장했다」는 updated_at 을 1분 앞으로 밀어 재현한다(그냥 update 하면 테스트가 헛통과한다).
 */
class VehicleTabSyncTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $admin = User::factory()->create(['permission' => 'super', 'role' => '관리', 'email_verified_at' => now()]);
        $sm = Salesman::create(['name' => '담당', 'type' => 'employee', 'is_active' => true]);
        $buyer = Buyer::create(['name' => 'BUYER', 'is_active' => true, 'salesman_id' => $sm->id]);
        $v = Vehicle::create([
            'buyer_id' => $buyer->id, 'export_buyer_id' => $buyer->id, 'bl_buyer_id' => $buyer->id,
            'vehicle_number' => '12가3456', 'sales_channel' => 'export', 'currency' => 'USD', 'exchange_rate' => 1400,
            'salesman_id' => $sm->id, 'purchase_price' => 20_000_000, 'purchase_date' => '2026-09-01',
            'sale_price' => 8_711, 'sale_date' => '2026-09-10',
        ]);

        return [$v, $admin];
    }

    /** 「다른 탭(또는 다른 사용자)이 먼저 저장했다」 — 모델 이벤트 없이 updated_at 만 민다. */
    private function savedElsewhere(Vehicle $v): void
    {
        Vehicle::query()->whereKey($v->id)->update(['updated_at' => now()->addMinute()]);
    }

    public function test_clean_tab_reloads_quietly_on_heartbeat(): void
    {
        [$v, $admin] = $this->fixture();
        $this->actingAs($admin);

        $c = Volt::test('erp.vehicles.index')->call('openEdit', $v->id);
        $before = $c->get('formFingerprint');
        $this->assertNotSame('', $before);

        $this->savedElsewhere($v);
        $c->call('heartbeat');

        $c->assertSet('remoteChanged', false)->assertSet('formDirty', false)->assertDispatched('notify');
        $this->assertNotSame($before, $c->get('formFingerprint'), '안 건드린 탭은 조용히 최신 지문으로 다시 열려야 한다');
    }

    public function test_dirty_tab_gets_banner_and_save_is_blocked_until_reload(): void
    {
        [$v, $admin] = $this->fixture();
        $this->actingAs($admin);

        $c = Volt::test('erp.vehicles.index')->call('openEdit', $v->id)->set('memo', '탭1에서 입력 중');
        $c->assertSet('formDirty', true);
        $before = $c->get('formFingerprint');

        $this->savedElsewhere($v);
        $c->call('heartbeat');

        $c->assertSet('remoteChanged', true)->assertSeeHtml('data-remote-changed');
        $this->assertSame($before, $c->get('formFingerprint'), '입력 중인 탭은 말없이 다시 열리면 안 된다');
        $c->assertSet('memo', '탭1에서 입력 중');

        // 저장은 막히고 입력은 그대로 — 지문 가드까지 가면 입력이 통째로 사라진다
        $c->call('save')->assertSet('remoteChanged', true)->assertSet('memo', '탭1에서 입력 중');
        $this->assertNull($v->fresh()->memo, '배너가 떠 있는 동안 옛 폼이 저장되면 안 된다');

        // 사용자가 눌러야 다시 연다
        $c->call('reloadFromRemote')->assertSet('remoteChanged', false)->assertSet('formDirty', false);
        $this->assertNotSame($before, $c->get('formFingerprint'));
    }

    public function test_broadcast_signal_from_another_tab_behaves_like_heartbeat(): void
    {
        [$v, $admin] = $this->fixture();
        $this->actingAs($admin);

        $c = Volt::test('erp.vehicles.index')->call('openEdit', $v->id);
        $before = $c->get('formFingerprint');

        // 아무것도 안 바뀐 신호 — 다시 열지 않는다
        $c->dispatch('vehicles-changed')->assertSet('remoteChanged', false)->assertNotDispatched('notify');
        $this->assertSame($before, $c->get('formFingerprint'));

        $this->savedElsewhere($v);
        $c->dispatch('vehicles-changed')->assertDispatched('notify');
        $this->assertNotSame($before, $c->get('formFingerprint'));
    }

    public function test_list_filter_changes_do_not_count_as_form_edits(): void
    {
        [$v, $admin] = $this->fixture();
        $this->actingAs($admin);

        Volt::test('erp.vehicles.index')->call('openEdit', $v->id)
            ->set('search', '12가')->set('perPage', 20)->set('sortColumn', 'vehicle_number')
            ->assertSet('formDirty', false);
    }

    public function test_a_write_notifies_other_tabs_and_a_read_does_not(): void
    {
        [$v, $admin] = $this->fixture();
        $this->actingAs($admin);

        $c = Volt::test('erp.vehicles.index')->call('openEdit', $v->id);
        $c->call('heartbeat')->assertNotDispatched('vehicles-changed');

        $c->set('memo', '저장')->set('userConfirmedDocCheckMismatch', true)
            ->call('save')->assertHasNoErrors()->assertDispatched('vehicles-changed');
        $this->assertSame('저장', $v->fresh()->memo);
    }

    public function test_wiring_is_in_place(): void
    {
        $js = file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString("new BroadcastChannel('car-erp-vehicles')", $js);
        $this->assertStringContainsString("window.Livewire.on('vehicles-changed'", $js, '서버 신호를 다른 탭으로 넘기는 배선');
        $this->assertStringContainsString("window.Livewire.dispatch('vehicles-changed')", $js, '다른 탭의 신호를 자기 컴포넌트로 넣는 배선');

        $inventory = file_get_contents(resource_path('views/livewire/erp/inventory/index.blade.php'));
        $this->assertStringContainsString("#[On('vehicles-changed')]", $inventory, '재고관리 탭도 같이 새로 그려져야 한다');

        // 배너 색은 빌드된 CSS 에 있어야 먹는다(§8 #50). 매니페스트가 가리키는 진짜 시트를 본다.
        $manifest = public_path('build/manifest.json');
        if (! is_file($manifest)) {
            $this->markTestSkipped('빌드 산출물 없음');
        }
        $file = json_decode(file_get_contents($manifest), true)['resources/css/app.css']['file'] ?? null;
        $css = $file ? file_get_contents(public_path('build/'.$file)) : '';
        foreach (['bg-blue-50', 'border-blue-300', 'text-blue-800', 'btn-primary'] as $cls) {
            $this->assertStringContainsString($cls, $css, "배너 클래스 {$cls} 가 빌드 CSS 에 없다");
        }
    }
}
