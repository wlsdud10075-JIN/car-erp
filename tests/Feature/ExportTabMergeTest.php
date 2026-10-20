<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Consignee;
use App\Models\ForwardingCompany;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 「수출」 탭 합본 (jin 2026-10-20) — 구 「선적」 + 「수출통관」 두 탭을 실무 입력 순서 한 탭으로.
 *
 * 지키는 것:
 *   ① 옮긴 칸이 전부 새 탭에 있고, 순서가 반입지 → 신고서 → 선적일 → ETA → 통관 체크다.
 *   ② 저장 확인 모달(영업·관리)이 탭마다 **방금 넣은 값**을 보여주고, [확인] 뒤 그 값이 DB 에 들어간다
 *      (jin «정확한 내용들이 다 보여지고, 내용이 들어갔는지도»). B/L 모달은 B/L 탭 칸만 — 구 모달은 반입지·VSL 을 보여줬다.
 *   ③ 체크↔서류 확인 모달은 「체크했는데 신고서 없음」 한 방향만 — 「신고서만」은 정상 단계(선적중~통관중).
 *   ④ 흐름도 선적·통관 칸과 확인 모달 [이동] 버튼이 실재하는 'export' 탭으로 간다.
 */
class ExportTabMergeTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Buyer $buyer;

    private Consignee $consignee;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
        Storage::fake(config('filesystems.vehicle_docs_disk'));

        // 저장 확인 모달은 영업·관리 role 에만 뜬다 — 관리 + 본인 팀 영업담당자.
        $this->manager = User::factory()->create(['permission' => 'user', 'role' => '관리', 'email_verified_at' => now()]);
        $sub = User::factory()->create(['permission' => 'user', 'role' => '영업', 'manager_user_id' => $this->manager->id, 'email_verified_at' => now()]);
        $this->salesman = Salesman::create(['name' => '팀원영업', 'user_id' => $sub->id, 'is_active' => true, 'type' => 'freelance']);
        $this->buyer = Buyer::create(['name' => 'EXPORT TAB BUYER', 'is_active' => true]);
        $this->consignee = Consignee::create(['buyer_id' => $this->buyer->id, 'name' => 'EXPORT TAB CONS', 'is_active' => true]);
    }

    private Salesman $salesman;

    /** 판매 완납 · 말소 완료 · 컨사이니 지정 — 반입·선적·통관 게이트를 모두 통과하는 차. 통관 바이어는 일부러 비운다. */
    private function readyVehicle(array $attrs = []): Vehicle
    {
        $v = Vehicle::create(array_merge([
            'vehicle_number' => '12가'.random_int(1000, 9999), 'sales_channel' => 'export',
            'currency' => 'KRW', 'exchange_rate' => 1, 'salesman_id' => $this->salesman->id,
            'purchase_price' => 1_000_000, 'sale_price' => 2_000_000, 'sale_date' => '2026-10-01',
            'buyer_id' => $this->buyer->id, 'bl_buyer_id' => $this->buyer->id, 'bl_consignee_id' => $this->consignee->id,
            'is_deregistered' => true, 'deregistration_document' => 'vehicles/dereg.pdf', 'nice_reg_owner_rrn' => '801201-1234567',
        ], $attrs));
        $v->finalPayments()->create(['amount' => 2_000_000, 'type' => 'deposit_down', 'confirmed_at' => now()]);

        return $v->fresh();
    }

    /** 저장 확인 모달 부분만 잘라낸다 — 패널 본문에도 같은 라벨이 있어 전체 HTML 로는 판정이 안 된다. */
    private function modalHtml($component): string
    {
        $html = $component->html();
        $at = strpos($html, 'wire:key="save-confirm-modal"');
        $this->assertNotFalse($at, '저장 확인 모달이 그려지지 않았다');

        return substr($html, $at, 12000);
    }

    public function test_export_tab_replaces_the_two_tabs_and_keeps_every_field_in_input_order(): void
    {
        $this->actingAs($this->manager);
        $v = $this->readyVehicle();
        $html = Volt::test('erp.vehicles.index')->call('openEdit', $v->id)->html();

        $this->assertStringContainsString("x-show=\"tab === 'export'\"", $html);
        $this->assertStringNotContainsString("x-show=\"tab === 'shipping'\"", $html, '구 선적 탭이 남았다');
        $this->assertStringNotContainsString("x-show=\"tab === 'clearance'\"", $html, '구 수출통관 탭이 남았다');
        $this->assertStringContainsString(__('vehicle.panel.tab.export'), $html);

        // 화면 순서 = 실무 입력 순서 (반입지 → 신고서 → 선적일 → ETA → 통관 체크)
        $order = ['wire:model="bl_loading_location"', 'model="exportDeclarationDocFile"', 'wire:model="shipping_date"', 'wire:model="eta_date"', 'wire:model="is_export_cleared"'];
        $pos = array_map(fn ($needle) => strpos($html, $needle), $order);
        foreach ($pos as $i => $p) {
            $this->assertNotFalse($p, $order[$i].' 칸이 수출 탭에 없다');
        }
        $sorted = $pos;
        sort($sorted);
        $this->assertSame($sorted, $pos, '수출 탭 칸 순서가 입력 순서와 다르다');

        // 구 두 탭의 나머지 칸도 전부 남았다
        foreach (['forwarding_company_id_str', 'export_declaration_amount_str', 'export_declaration_number', 'shipping_method', 'port_of_loading', 'incoterms', 'discharge_port_id_str', 'bl_consignee_id_str', 'vessel_name', 'container_number', 'document_deadline_date', 'tabMemos.shipping', 'tabMemos.clearance', 'shipPhotoUpload'] as $m) {
            $this->assertStringContainsString($m, $html, "{$m} 칸이 사라졌다");
        }

        // 당사자 = 바이어(판매에서) + 컨사이니 한 줄 — 「통관/선적 ○○」 이름 없이
        $this->assertStringNotContainsString(__('vehicle.field.export_buyer'), $html);
        $this->assertStringNotContainsString(__('vehicle.field.bl_consignee'), $html);
    }

    public function test_export_save_modal_shows_what_was_entered_and_saves_it(): void
    {
        $this->actingAs($this->manager);
        $v = $this->readyVehicle();
        $fwd = ForwardingCompany::create(['name' => 'WOOSUNG FWD', 'is_active' => true]);

        $c = Volt::test('erp.vehicles.index')->call('openEdit', $v->id)
            ->set('bl_loading_location', 'PUSAN')
            ->set('forwarding_company_id_str', (string) $fwd->id)
            ->set('export_declaration_number', 'D-2026-777')
            ->set('shipping_date', '2026-10-22')
            ->set('vessel_name', 'GLOVIS SUNRISE')
            ->set('container_number', 'CNTR-0001')
            ->set('eta_date', '2026-11-20')
            ->call('requestSave', 'export')
            ->assertSet('showSaveConfirmModal', true)
            ->assertSet('activeTabForSave', 'export');

        $modal = $this->modalHtml($c);
        foreach (['EXPORT TAB BUYER', 'EXPORT TAB CONS', 'PUSAN', 'WOOSUNG FWD', 'D-2026-777', '2026-10-22', 'GLOVIS SUNRISE', 'CNTR-0001', '2026-11-20'] as $val) {
            $this->assertStringContainsString($val, $modal, "수출 저장 모달에 「{$val}」이 안 보인다");
        }
        // 통관 바이어가 아직 비어 있어도 화면과 같은 바이어가 보인다(구: 「미선택」)
        $this->assertNull($v->fresh()->export_buyer_id);
        $this->assertStringContainsString(__('vehicle.save.val_unchecked'), $modal, '통관 체크 전 상태가 보여야 한다');

        $c->call('confirmAndSave')->assertHasNoErrors();
        $v->refresh();
        $this->assertSame('PUSAN', $v->bl_loading_location);
        $this->assertSame((int) $fwd->id, (int) $v->forwarding_company_id);
        $this->assertSame('D-2026-777', $v->export_declaration_number);
        $this->assertSame('2026-10-22', $v->shipping_date->toDateString());
        $this->assertSame('GLOVIS SUNRISE', $v->vessel_name);
        $this->assertSame('CNTR-0001', $v->container_number);
        $this->assertSame('2026-11-20', $v->eta_date->toDateString());
        $this->assertSame((int) $this->buyer->id, (int) $v->export_buyer_id, '저장 때 판매 바이어를 이어받는다');
        $this->assertSame('통관중', $v->progress_status, '반입지 + 선적일 + ETA = 통관중');

        // 통관 체크까지 → 모달에 체크 표시 → 저장 → 통관완료
        $c2 = Volt::test('erp.vehicles.index')->call('openEdit', $v->id)
            ->set('is_export_cleared', true)
            ->call('requestSave', 'export');
        $this->assertStringContainsString(__('vehicle.save.val_checked'), $this->modalHtml($c2));
        $c2->call('confirmAndSave');
        // 신고서 없이 체크 → 확인 모달 1회(한 방향 경고) → [그대로 저장]
        $this->assertTrue($c2->get('showDocCheckModal'));
        $c2->call('confirmSaveWithDocMismatch')->assertHasNoErrors();
        $this->assertSame('통관완료', $v->fresh()->progress_status);
    }

    public function test_bl_save_modal_shows_only_the_bl_tab_fields_and_saves_them(): void
    {
        $this->actingAs($this->manager);
        $v = $this->readyVehicle(['bl_loading_location' => 'PUSAN', 'vessel_name' => 'OLD VESSEL', 'container_number' => 'OLD-CNTR']);

        $c = Volt::test('erp.vehicles.index')->call('openEdit', $v->id)
            ->set('bl_number', 'BL-OCT-20')
            ->set('bl_issue_date', '2026-11-01')
            ->set('bl_type', 'surrender')
            ->call('requestSave', 'bl')
            ->assertSet('showSaveConfirmModal', true);

        $modal = $this->modalHtml($c);
        foreach (['BL-OCT-20', '2026-11-01', __('shipping.bl.type.surrender'), __('vehicle.save.f.checkbill'), __('vehicle.save.f.bl_doc')] as $val) {
            $this->assertStringContainsString($val, $modal, "B/L 저장 모달에 「{$val}」이 안 보인다");
        }
        foreach (['OLD VESSEL', 'OLD-CNTR', __('vehicle.save.f.loading')] as $val) {
            $this->assertStringNotContainsString($val, $modal, "B/L 모달에 수출 탭 칸 「{$val}」이 섞였다");
        }

        $c->call('confirmAndSave')->assertHasNoErrors();
        $v->refresh();
        $this->assertSame('BL-OCT-20', $v->bl_number);
        $this->assertSame('2026-11-01', $v->bl_issue_date->toDateString());
        $this->assertSame('surrender', $v->bl_type);
    }

    public function test_purchase_and_sale_save_modals_show_and_save_their_values(): void
    {
        $this->actingAs($this->manager);
        $v = $this->readyVehicle();

        $c = Volt::test('erp.vehicles.index')->call('openEdit', $v->id)
            ->set('purchase_from', 'DEALER SEOUL')
            ->set('purchase_date', '2026-09-15')
            ->call('requestSave', 'purchase')
            ->assertSet('activeTabForSave', 'purchase');
        $modal = $this->modalHtml($c);
        $this->assertStringContainsString('DEALER SEOUL', $modal);
        $this->assertStringContainsString('2026-09-15', $modal);
        $c->call('confirmAndSave')->assertHasNoErrors();
        $this->assertSame('DEALER SEOUL', $v->fresh()->purchase_from);

        $c = Volt::test('erp.vehicles.index')->call('openEdit', $v->id)
            ->set('sale_date', '2026-10-03')
            ->call('requestSave', 'sale')
            ->assertSet('activeTabForSave', 'sale');
        $modal = $this->modalHtml($c);
        $this->assertStringContainsString('2026-10-03', $modal);
        $this->assertStringContainsString('EXPORT TAB BUYER', $modal);
        $c->call('confirmAndSave')->assertHasNoErrors();
        $this->assertSame('2026-10-03', $v->fresh()->sale_date->toDateString());
    }

    public function test_other_roles_save_the_export_tab_without_the_modal(): void
    {
        $admin = User::factory()->create(['permission' => 'admin', 'role' => '수출통관']);   // 모달은 role 영업·관리만
        $this->actingAs($admin);
        $v = $this->readyVehicle();

        Volt::test('erp.vehicles.index')->call('openEdit', $v->id)
            ->set('bl_loading_location', 'INCHEON')
            ->call('requestSave', 'export')
            ->assertSet('showSaveConfirmModal', false)
            ->assertHasNoErrors();
        $this->assertSame('INCHEON', $v->fresh()->bl_loading_location);
        $this->assertSame('선적중', $v->fresh()->progress_status);
    }

    public function test_declaration_without_the_check_is_normal_but_the_check_without_a_declaration_warns(): void
    {
        $this->actingAs($this->manager);

        // 신고서만 있고 체크 전 = 선적중(정상) → 모달 없이 저장
        $v = $this->readyVehicle(['bl_loading_location' => 'PUSAN', 'export_declaration_document' => 'vehicles/decl.pdf']);
        Volt::test('erp.vehicles.index')->call('openEdit', $v->id)
            ->set('vessel_name', 'ANY')
            ->call('save')
            ->assertSet('showDocCheckModal', false)
            ->assertHasNoErrors();
        $this->assertSame('ANY', $v->fresh()->vessel_name);

        // 체크했는데 신고서 없음 → 경고 1회, [수출 탭으로]는 실재하는 'export' 탭으로
        $w = $this->readyVehicle(['bl_loading_location' => 'PUSAN']);
        $c = Volt::test('erp.vehicles.index')->call('openEdit', $w->id)
            ->set('is_export_cleared', true)
            ->call('save')
            ->assertSet('showDocCheckModal', true);
        $m = collect($c->get('docCheckMismatches'))->firstWhere('stage', 'export_clearance');
        $this->assertSame('export', $m['tab']);
        $this->assertStringContainsString(__('vehicle.modal.doc_export_checked_no_doc'), $c->html());
        $this->assertStringContainsString(__('vehicle.modal.doc_goto', ['label' => __('vehicle.panel.tab.export')]), $c->html());
        $c->call('dismissDocCheckModal', 'export')->assertDispatched('switch-tab', tab: 'export');
    }

    /** B/L 바이어가 빈 옛 차도 판매 바이어 기준으로 컨사이니를 고를 수 있다(구: 「바이어 먼저 선택」으로 잠김). */
    public function test_consignee_can_be_chosen_from_the_sale_buyer_on_old_vehicles(): void
    {
        $this->actingAs($this->manager);
        $v = $this->readyVehicle(['bl_buyer_id' => null, 'bl_consignee_id' => null]);

        $c = Volt::test('erp.vehicles.index')->call('openEdit', $v->id)
            ->assertSet('bl_buyer_id_str', (string) $this->buyer->id);
        $this->assertTrue($c->get('consigneesForBl')->contains('id', $this->consignee->id));

        $c->set('bl_consignee_id_str', (string) $this->consignee->id)->call('save')->assertHasNoErrors();
        $this->assertSame((int) $this->consignee->id, (int) $v->fresh()->bl_consignee_id);
        $this->assertSame((int) $this->consignee->id, (int) $v->fresh()->export_consignee_id, '통관 컨사이니도 같은 값으로 이어받는다');
    }

    /**
     * 🚪 막는 문구가 없어진 탭을 가리키면 사람은 「갈 곳이 없다」 — 2026-07 당사자 축소 때 「판매 탭에서 컨사이니 등록」이
     *    데드엔드가 됐던 그 형태(jin 2026-10-20 «지난번 판매탭에서 컨사이니 뺄 때 락이 그대로 판매탭에서 걸리는 오류»).
     *    사용자에게 보이는 문자열(주석 제외)에 옛 탭 이름이 남으면 실패한다.
     */
    public function test_no_user_facing_message_points_to_the_old_tabs(): void
    {
        $files = array_merge(
            glob(app_path('Models/*.php')), glob(app_path('Services/*.php')) ?: [], glob(app_path('Services/*/*.php')) ?: [],
            [lang_path('ko/vehicle.php'), lang_path('ko/dashboard.php'), resource_path('views/livewire/erp/vehicles/index.blade.php')],
        );
        $hits = [];
        foreach ($files as $f) {
            foreach (file($f) as $no => $line) {
                $code = trim($line);
                if (str_starts_with($code, '//') || str_starts_with($code, '*') || str_starts_with($code, '/*') || str_starts_with($code, '{{--')) {
                    continue;
                }
                $code = preg_replace('#\s//.*$#u', '', $code);   // 줄 끝 주석
                if (preg_match('/(?<![가-힣])(선적|수출통관|통관|선적\(B\/L\)) 탭/u', $code)) {
                    $hits[] = basename($f).':'.($no + 1).' '.mb_substr($code, 0, 80);
                }
            }
        }
        $this->assertSame([], $hits, "옛 탭 이름을 가리키는 문구:\n".implode("\n", $hits));
    }

    public function test_flow_nodes_for_shipping_and_clearance_open_the_export_tab(): void
    {
        $this->actingAs($this->manager);
        $v = $this->readyVehicle();
        $flow = collect(Volt::test('erp.vehicles.index')->call('openEdit', $v->id)->get('progressFlow'));

        $this->assertSame('export', $flow->firstWhere('key', 'shipping')['tab']);
        $this->assertSame('export', $flow->firstWhere('key', 'clearance')['tab']);
        $tabs = ['basic', 'purchase', 'sale', 'export', 'bl', 'dhl', 'docs'];
        foreach ($flow as $node) {
            $this->assertContains($node['tab'], $tabs, "흐름도 {$node['key']} 칸이 없는 탭({$node['tab']})을 가리킨다");
        }
    }
}
