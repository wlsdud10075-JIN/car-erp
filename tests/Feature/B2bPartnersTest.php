<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Buyer;
use App\Models\BuyerDocument;
use App\Models\ExportLog;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 🤝 B2B 파트너스 1차 (강지나 부장 요청서 2026-10-29 · jin 2026-11-01 확정 — `docs/design/b2b-partners-2026-10-30.md`).
 *
 * 지키는 것:
 *  ① DP/FD 는 **거래(차량) 단위** — 지정한 차에만 뱃지, 바이어 지정만으로 전 차량 자동 ❌(요청서 03·07).
 *  ② 두 화면(차량관리·채권관리)이 같은 값을 그린다 · 필터가 동작한다 · 내보내기가 화면 필터를 미러한다.
 *  ③ 바이어가 바뀌면(재판매) 뱃지를 물려주지 않는다 — 자동 해제 + 감사 1줄(jin (가)).
 *  ④ 지정·관리대상·계약서 = 관리 이상. 영업은 읽기만(저장해도 값이 안 바뀐다 — 키를 안 싣는다).
 *  ⑤ 계약서 교체는 보관(superseded_at) — 삭제 ❌. 열람은 감사에 남는다(요청서 05).
 */
class B2bPartnersTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): User
    {
        // 업무관리자(permission=manager) — 관리 이상이면서 차량 스코프가 전체라 패널을 연다(role=관리 는 담당 영업이 있어야 스코프에 든다).
        return User::factory()->create(['permission' => 'manager', 'role' => '관리', 'email_verified_at' => now()]);
    }

    private function admin(): User
    {
        return User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
    }

    private function sales(Salesman $sm): User
    {
        $u = User::factory()->create(['permission' => 'user', 'role' => '영업', 'email_verified_at' => now()]);
        $sm->update(['user_id' => $u->id]);

        return $u->fresh();
    }

    private function salesman(): Salesman
    {
        return Salesman::create(['name' => 'S'.random_int(100, 999), 'is_active' => true, 'type' => 'employee']);
    }

    private function buyer(Salesman $sm, array $attrs = []): Buyer
    {
        return Buyer::create(array_merge(['name' => 'B'.random_int(100, 999), 'is_active' => true, 'salesman_id' => $sm->id], $attrs));
    }

    private function vehicle(string $number, Salesman $sm, ?Buyer $b = null, array $attrs = []): Vehicle
    {
        return Vehicle::create(array_merge([
            'vehicle_number' => $number, 'sales_channel' => 'export', 'currency' => 'USD', 'exchange_rate' => 1350,
            'dhl_request' => false, 'salesman_id' => $sm->id, 'buyer_id' => $b?->id,
            'purchase_date' => now()->toDateString(), 'purchase_price' => 5_000_000,
            'brand' => 'BMW', 'model_type' => '118d', 'year' => 2017,
        ], $attrs));
    }

    // ── ① 거래 단위 · 뱃지 ────────────────────────────────────────

    public function test_badge_renders_only_on_the_tagged_vehicle_with_the_full_name_on_hover(): void
    {
        $this->actingAs($this->admin());
        $sm = $this->salesman();
        $b = $this->buyer($sm, ['is_b2b_partner' => true]);
        $dp = $this->vehicle('11가1001', $sm, $b, ['deal_type' => 'DP']);
        $plain = $this->vehicle('22나2002', $sm, $b);   // 같은 바이어·관리대상이지만 지정 안 함

        $html = Volt::test('erp.vehicles.index')->set('dateType', 'all')->html();

        // 차량관리는 같은 행을 세 자리에 그린다 — 데스크탑 바이어 셀 + 차량번호 셀(바이어 컬럼을 껐을 때만 x-show) + 모바일 카드. 지정 안 한 차는 0.
        $this->assertSame(3, substr_count($html, 'data-deal-badge="DP"'), '지정한 차 하나 × 렌더 3곳(바이어 셀·차량번호 셀 폴백·모바일 카드)');
        $this->assertMatchesRegularExpression('/'.preg_quote($b->name, '/').'(?:<!--.*?-->|\\s)*<span[^>]*data-deal-badge="DP"/s', $html, '뱃지는 바이어 이름 바로 옆(jin 11-01)');
        $this->assertStringContainsString(Vehicle::DEAL_TYPES['DP']['title'], $html, '호버 전체 명칭');
        $this->assertStringContainsString('bg-purple-100 text-purple-700', $html);
        $this->assertStringNotContainsString('data-deal-badge="FD"', $html);
    }

    public function test_filter_narrows_both_lists_and_none_means_no_badge(): void
    {
        $this->actingAs($this->admin());
        $sm = $this->salesman();
        $b = $this->buyer($sm);
        $dp = $this->vehicle('11가1001', $sm, $b, ['deal_type' => 'DP', 'sale_price' => 10_000, 'sale_date' => now()->toDateString()]);
        $fd = $this->vehicle('22나2002', $sm, $b, ['deal_type' => 'FD', 'sale_price' => 10_000, 'sale_date' => now()->toDateString()]);
        $plain = $this->vehicle('33다3003', $sm, $b, ['sale_price' => 10_000, 'sale_date' => now()->toDateString()]);

        // 토글 뱃지(운항 토글과 같은 모양) — 같은 걸 다시 누르면 해제
        $c = Volt::test('erp.vehicles.index')->set('dateType', 'all')->call('toggleDeal', 'DP')
            ->assertSet('dealFilter', 'DP')
            ->assertSee('11가1001')->assertDontSee('22나2002')->assertDontSee('33다3003');
        $this->assertSame(['DP' => 1, 'FD' => 1], $c->instance()->dealCounts, '토글 옆 숫자 = 이 토글 제외 현재 필터 기준(「뱃지 없음」 버튼은 없다 — 기존 바이어)');
        $c->call('toggleDeal', 'DP')->assertSet('dealFilter', '')->assertSee('22나2002');
        Volt::test('erp.vehicles.index')->set('dateType', 'all')->set('dealFilter', 'none')
            ->assertSee('33다3003')->assertDontSee('11가1001')->assertDontSee('22나2002');

        // 채권관리 — 같은 축. 미수 > 0 인 차만 목록에 있으므로 판매가를 넣었다.
        $r = Volt::test('erp.receivables.index')->call('toggleDeal', 'FD');
        $r->assertSee('22나2002')->assertDontSee('11가1001')->assertDontSee('33다3003');
        $this->assertStringContainsString('data-deal-badge="FD"', $r->html(), '채권관리에도 같은 뱃지');
    }

    public function test_export_mirrors_the_deal_filter(): void
    {
        $this->actingAs($this->admin());
        $sm = $this->salesman();
        $b = $this->buyer($sm);
        $this->vehicle('11가1001', $sm, $b, ['deal_type' => 'DP']);
        $this->vehicle('22나2002', $sm, $b);

        $this->get(route('erp.vehicles.export', ['scope' => 'current', 'deal' => 'DP', 'dateType' => 'all']))->assertOk();
        $this->assertSame(1, ExportLog::latest('id')->first()->row_count, '화면은 1대인데 엑셀이 2대면 조용히 갈린다(§9)');
    }

    // ── ③ 바이어 변경 시 해제 ───────────────────────────────────────

    public function test_changing_the_buyer_clears_the_deal_type_with_an_audit_row(): void
    {
        $this->actingAs($this->admin());
        $sm = $this->salesman();
        $a = $this->buyer($sm);
        $c = $this->buyer($sm);
        $v = $this->vehicle('11가1001', $sm, $a, ['deal_type' => 'DP']);

        $v->update(['note' => 'same buyer']);
        $this->assertSame('DP', $v->fresh()->deal_type, '바이어가 그대로면 안 건드린다');

        $v->update(['buyer_id' => $c->id]);
        $this->assertNull($v->fresh()->deal_type, '재판매(바이어 변경)에 과거 표시를 물려주지 않는다');
        $this->assertSame(1, AuditLog::where('auditable_id', $v->id)->where('action', 'deal_type_cleared_on_buyer_change')->count());

        // 같은 저장에서 사람이 직접 고쳤으면 그 값
        $v->update(['buyer_id' => $a->id, 'deal_type' => 'FD']);
        $this->assertSame('FD', $v->fresh()->deal_type);
    }

    // ── ④ 권한 ────────────────────────────────────────────────────

    public function test_manager_sets_deal_type_from_the_panel_but_sales_cannot_change_it(): void
    {
        $sm = $this->salesman();
        $b = $this->buyer($sm);
        $v = $this->vehicle('11가1001', $sm, $b, ['sale_price' => 1000, 'sale_date' => now()->toDateString()]);

        $this->actingAs($this->manager());
        Volt::test('erp.vehicles.index')->call('openEdit', $v->id)->set('deal_type', 'FD')->call('save')->assertHasNoErrors();
        $this->assertSame('FD', $v->fresh()->deal_type);
        $this->assertSame(1, AuditLog::where('auditable_id', $v->id)->where('column_name', 'deal_type')->where('new_value', 'FD')->count(), 'AUDITED');

        $this->actingAs($this->sales($sm));
        Volt::test('erp.vehicles.index')->call('openEdit', $v->id)->assertSet('deal_type', 'FD')
            ->set('deal_type', '')->call('save')->assertHasNoErrors();
        $this->assertSame('FD', $v->fresh()->deal_type, '영업은 바꿀 수 없다 — 키를 안 실어 값이 보존된다');
    }

    /** role=관리 일반사용자(실제 관리 직원)도 담당 영업 범위 안의 차에 DP/FD 를 지정한다 — 「관리 이상」의 아래 끝. */
    public function test_role_manager_user_with_subordinates_can_set_deal_type(): void
    {
        $manager = User::factory()->create(['permission' => 'user', 'role' => '관리', 'email_verified_at' => now()]);
        $this->assertTrue($manager->canApprove(), 'role=관리 는 관리 이상이다 — 좁히면 실제 관리 직원이 못 쓴다');
        $sm = $this->salesman();
        $salesUser = User::factory()->create(['permission' => 'user', 'role' => '영업', 'manager_user_id' => $manager->id]);
        $sm->update(['user_id' => $salesUser->id]);
        $v = $this->vehicle('11가1001', $sm, $this->buyer($sm), ['sale_price' => 1000, 'sale_date' => now()->toDateString()]);

        $this->actingAs($manager->fresh());
        Volt::test('erp.vehicles.index')->call('openEdit', $v->id)->set('deal_type', 'DP')->call('save')->assertHasNoErrors();
        $this->assertSame('DP', $v->fresh()->deal_type);
    }

    public function test_partner_flag_is_saved_by_manager_and_ignored_for_sales(): void
    {
        $sm = $this->salesman();
        $b = $this->buyer($sm);

        $this->actingAs($this->manager());
        Volt::test('erp.buyers.index')->call('openEdit', $b->id)->set('is_b2b_partner', true)->set('website', 'https://x.test')->call('save')->assertHasNoErrors();
        $this->assertTrue($b->fresh()->is_b2b_partner);
        $this->assertSame('https://x.test', $b->fresh()->website);
        $this->assertSame(1, AuditLog::where('auditable_id', $b->id)->where('action', 'b2b_partner_toggled')->count());

        $this->actingAs($this->sales($sm));
        Volt::test('erp.buyers.index')->call('openEdit', $b->id)->set('is_b2b_partner', false)->set('city', 'Tirana')->call('save')->assertHasNoErrors();
        $this->assertTrue($b->fresh()->is_b2b_partner, '영업은 관리대상을 못 바꾼다');
        $this->assertSame('Tirana', $b->fresh()->city, '도시 같은 일반 칸은 영업도 적는다');
    }

    public function test_b2b_menu_filter_lists_partners_only_even_without_vehicles(): void
    {
        $this->actingAs($this->admin());
        $sm = $this->salesman();
        $partner = $this->buyer($sm, ['name' => 'PARTNER CO', 'is_b2b_partner' => true]);
        $other = $this->buyer($sm, ['name' => 'OTHER CO']);
        $this->vehicle('11가1001', $sm, $other, ['deal_type' => 'DP']);

        Volt::test('erp.buyers.index')->set('b2bFilter', '1')->assertSee('PARTNER CO')->assertDontSee('OTHER CO');
        // 개수는 차량에서 센다 — 관리대상이 아니어도 DP 1 이 보인다(바이어에 플래그 없음)
        $html = Volt::test('erp.buyers.index')->html();
        $this->assertStringContainsString('DP 1', $html);
    }

    // ── ⑤ 계약서 ──────────────────────────────────────────────────

    public function test_contract_upload_replace_keeps_the_original_and_read_is_audited(): void
    {
        Storage::fake('public');
        config(['filesystems.vehicle_docs_disk' => 'public']);
        $sm = $this->salesman();
        $b = $this->buyer($sm, ['is_b2b_partner' => true]);

        $this->actingAs($this->manager());
        $c = Volt::test('erp.buyers.index')->call('openEdit', $b->id)
            ->set('contract_kind', 'base')->set('contract_note', '2026 연간')
            ->set('contractUpload', UploadedFile::fake()->create('base_2026.pdf', 20, 'application/pdf'))
            ->call('addContract')->assertHasNoErrors();
        $first = BuyerDocument::where('buyer_id', $b->id)->first();
        $this->assertNotNull($first);
        Storage::disk('public')->assertExists($first->path);
        $this->assertSame('base_2026.pdf', $first->filename);
        $this->assertSame(1, AuditLog::where('auditable_id', $b->id)->where('action', 'b2b_contract_uploaded')->count());

        // 교체 = 새 행 + 옛 행 superseded_at (삭제 ❌)
        $c->call('startReplaceContract', $first->id)
            ->set('contractUpload', UploadedFile::fake()->create('base_2027.pdf', 20, 'application/pdf'))
            ->call('addContract')->assertHasNoErrors();
        $this->assertSame(2, BuyerDocument::where('buyer_id', $b->id)->count());
        $this->assertNotNull($first->fresh()->superseded_at, '원본은 보관된다');
        Storage::disk('public')->assertExists($first->path);
        $this->assertSame(1, BuyerDocument::where('buyer_id', $b->id)->whereNull('superseded_at')->count());
        $this->assertSame(1, AuditLog::where('auditable_id', $b->id)->where('action', 'b2b_contract_superseded')->count());

        // 열람은 감사에 남는다
        $c->call('openContract', $first->id);
        $this->assertSame(1, AuditLog::where('auditable_id', $b->id)->where('action', 'b2b_contract_read')->count());
    }

    public function test_sales_cannot_upload_contracts(): void
    {
        Storage::fake('public');
        config(['filesystems.vehicle_docs_disk' => 'public']);
        $sm = $this->salesman();
        $b = $this->buyer($sm);
        $this->actingAs($this->sales($sm));

        Volt::test('erp.buyers.index')->call('openEdit', $b->id)
            ->set('contractUpload', UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'))
            ->call('addContract')->assertStatus(403);
        $this->assertSame(0, BuyerDocument::count());
    }

    public function test_partner_flag_and_contracts_do_not_touch_receivables_or_approvals(): void
    {
        $this->actingAs($this->admin());
        $sm = $this->salesman();
        $b = $this->buyer($sm);
        $v = $this->vehicle('11가1001', $sm, $b, ['sale_price' => 12_000, 'sale_date' => now()->toDateString()]);
        $unpaidBefore = $v->fresh()->sale_unpaid_amount;
        $cacheBefore = $v->fresh()->sale_unpaid_amount_krw_cache;

        $b->update(['is_b2b_partner' => true]);
        $v->update(['deal_type' => 'DP']);
        BuyerDocument::create(['buyer_id' => $b->id, 'path' => 'buyers/x/c.pdf', 'kind' => 'base']);

        $v = $v->fresh();
        $this->assertSame($unpaidBefore, $v->sale_unpaid_amount, '뱃지·계약서는 미수를 안 바꾼다(요청서 07)');
        $this->assertSame($cacheBefore, $v->sale_unpaid_amount_krw_cache);
        $this->assertSame('판매중', $v->progress_status);
    }
}
