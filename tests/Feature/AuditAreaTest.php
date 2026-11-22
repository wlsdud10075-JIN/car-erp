<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\AuditArea;
use App\Support\ColumnLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 🗂️ 감사로그 구역 (jin 2026-11-22 «변경된 구역을 큰 틀에서 볼 수 있게» · «개발하고 추가될 때마다 영어 이름 남고 한글화 안 되고»).
 *
 * 분류표는 `App\Support\AuditArea` 한 곳이다. 여기서 지키는 것:
 *   ① 코드에 적힌 감사 액션은 전부 분류돼 있다(안 넣으면 「설정·기타」로 조용히 떨어진다)
 *   ② 분류표의 대상·액션은 전부 한글 이름이 있다(드롭박스에 영문이 남지 않게)
 *   ③ PHP 판정(`of`)과 SQL 조건(`scope`)이 같은 답을 한다 — 갈리면 칩의 건수와 목록이 어긋난다
 *   ④ 화면 — 칩을 누르면 그 구역만 남고, 컬럼 고르기도 그 구역 칸만 남는다
 */
class AuditAreaTest extends TestCase
{
    use RefreshDatabase;

    private function hasKorean(string $s): bool
    {
        return (bool) preg_match('/[가-힣]/u', $s);
    }

    /** @return array<string, string> 액션 => 처음 발견한 파일 */
    private function actionsWrittenInCode(): array
    {
        $files = array_merge(
            glob(app_path('{,*/,*/*/,*/*/*/}*.php'), GLOB_BRACE),
            glob(resource_path('views/livewire/{,*/,*/*/,*/*/*/}*.blade.php'), GLOB_BRACE),
        );
        $found = [];
        foreach ($files as $f) {
            $s = file_get_contents($f);
            if (! str_contains($s, 'AuditLog')) {
                continue;
            }
            preg_match_all("/'action'\\s*=>\\s*'([a-z0-9_]+)'/", $s, $m1);
            preg_match_all("/recordEvent\\([^,]+,\\s*'([a-z0-9_]+)'\\)/", $s, $m2);
            preg_match_all("/recordChange\\([^;]*?,\\s*'([a-z0-9_]+)'\\)\\s*;/s", $s, $m3);
            foreach (array_merge($m1[1], $m2[1], $m3[1]) as $a) {
                $found[$a] ??= basename($f);
            }
        }

        return $found;
    }

    public function test_every_action_written_in_code_is_classified(): void
    {
        $free = array_merge(AuditArea::GENERIC_ACTIONS, ['payment_confirmed', 'payment_unconfirmed']);   // 대상·칸이 구역을 정한다
        $missing = [];
        foreach ($this->actionsWrittenInCode() as $action => $file) {
            if (! in_array($action, $free, true) && ! isset(AuditArea::ACTION_AREA[$action])) {
                $missing[$action] = $file;
            }
        }

        $this->assertSame([], $missing, '구역이 없는 감사 액션 — App\Support\AuditArea::ACTION_AREA 에 넣을 것(안 넣으면 「설정·기타」로 떨어진다)');
    }

    public function test_everything_in_the_table_has_a_korean_name(): void
    {
        $bad = [];
        foreach (array_keys(AuditArea::ACTION_AREA) as $action) {
            if (! $this->hasKorean(ColumnLabel::action($action))) {
                $bad[] = "액션 {$action}";
            }
        }
        foreach (array_merge(array_keys(AuditArea::TYPE_AREA), ['Vehicle']) as $model) {
            if (! $this->hasKorean(ColumnLabel::model($model))) {
                $bad[] = "대상 {$model}";
            }
        }
        foreach (AuditArea::LABELS as $label) {
            $this->assertTrue($this->hasKorean($label));
        }

        $this->assertSame([], $bad, '한글 이름이 없다 — config/column_labels.php 의 actions / models 에 추가할 것');
    }

    /** 차량에서 실제로 감사하는 칸은 전부 한글 이름이 있다 — 감사로그 「컬럼」 드롭박스에 영문이 남지 않게. */
    public function test_audited_vehicle_columns_have_korean_names(): void
    {
        $bad = [];
        foreach (Vehicle::AUDITED_COLUMNS as $col) {
            if (! $this->hasKorean(ColumnLabel::column(Vehicle::class, $col))) {
                $bad[] = $col;
            }
        }

        $this->assertSame([], $bad, '차량 감사 칸에 한글 이름이 없다 — config/column_labels.php vehicles 에 추가할 것');
    }

    public function test_vehicle_columns_land_in_the_expected_area(): void
    {
        $this->assertSame(AuditArea::PURCHASE, AuditArea::vehicleColumnArea('purchase_price'));
        $this->assertSame(AuditArea::PURCHASE, AuditArea::vehicleColumnArea('cost_towing'));
        $this->assertSame(AuditArea::DEREG, AuditArea::vehicleColumnArea('is_deregistered'));
        $this->assertSame(AuditArea::DEREG, AuditArea::vehicleColumnArea('deregistration_document'));
        $this->assertSame(AuditArea::SALE, AuditArea::vehicleColumnArea('sale_price'));
        $this->assertSame(AuditArea::SALE, AuditArea::vehicleColumnArea('exchange_rate'));
        $this->assertSame(AuditArea::SALE, AuditArea::vehicleColumnArea('buyer_id'));
        $this->assertSame(AuditArea::EXPORT, AuditArea::vehicleColumnArea('shipping_date,eta_date,vessel_name'), '일괄 기록은 첫 칸으로');
        $this->assertSame(AuditArea::EXPORT, AuditArea::vehicleColumnArea('bl_number'));
        $this->assertSame(AuditArea::BASIC, AuditArea::vehicleColumnArea('nice_reg_owner_name'));
        $this->assertSame(AuditArea::BASIC, AuditArea::vehicleColumnArea(null));

        // 액션이 먼저다 — 차량의 일괄 비용 기입은 칸이 무엇이든 「매입」, 회계 재조정은 「정산」
        $this->assertSame(AuditArea::PURCHASE, AuditArea::of(Vehicle::class, 'bulk_cost_applied', 'memo'));
        $this->assertSame(AuditArea::SETTLE, AuditArea::of(Vehicle::class, 'ledger_field_unlocked', 'unlock_reason'));
        $this->assertSame(AuditArea::SETTLE, AuditArea::of('App\\Models\\Salesman', 'payout_held', 'payout_hold'), '지급보류는 사람이 아니라 정산');
        $this->assertSame(AuditArea::PEOPLE, AuditArea::of('App\\Models\\Salesman', 'updated', 'left_at'));
        $this->assertSame(AuditArea::SALE, AuditArea::of('App\\Models\\FinalPayment', 'payment_confirmed', 'payment_row'));
        $this->assertSame(AuditArea::PURCHASE, AuditArea::of('App\\Models\\PurchaseBalancePayment', 'payment_confirmed', 'payment_row'));
        $this->assertSame(AuditArea::ETC, AuditArea::of('App\\Models\\SomethingNew', 'updated', 'x'), '표에 없는 새 대상은 설정·기타');
        $this->assertSame(AuditArea::ETC, AuditArea::of(null, 'updated', null));
    }

    /** @return list<array{0: ?string, 1: string, 2: ?string}> */
    private function sampleRows(): array
    {
        return [
            [Vehicle::class, 'updated', 'purchase_price'], [Vehicle::class, 'updated', 'cost_insurance'], [Vehicle::class, 'updated', 'is_deregistered'],
            [Vehicle::class, 'updated', 'sale_price'], [Vehicle::class, 'updated', 'buyer_id'], [Vehicle::class, 'updated', 'eta_date'],
            [Vehicle::class, 'updated', 'memo'], [Vehicle::class, 'updated', 'nice_reg_owner_name'], [Vehicle::class, 'created', null],
            [Vehicle::class, 'updated', ''], [Vehicle::class, 'updated', 'claim_used'], [Vehicle::class, 'updated', 'shipment_row'],
            [Vehicle::class, 'bulk_shipping_date_applied', 'shipping_date,eta_date'], [Vehicle::class, 'bulk_cost_applied', 'cost_towing'],
            [Vehicle::class, 'ledger_field_unlocked', 'unlock_reason'], [Vehicle::class, 'unpaid_override_approved', 'unpaid_override_stage'],
            [Vehicle::class, 'vehicle_deleted_with_reason', '12가3456'], [Vehicle::class, 'updated', 'bl_number, bl_issue_date'],
            ['App\\Models\\FinalPayment', 'created', 'payment_row'], ['App\\Models\\FinalPayment', 'payment_confirmed', 'payment_row'],
            ['App\\Models\\PurchaseBalancePayment', 'updated', 'amount'], ['App\\Models\\ReceivableHistory', 'deleted', 'history_row'],
            ['App\\Models\\Settlement', 'updated', 'settlement_status'], ['App\\Models\\Settlement', 'settlement_gate_overridden', 'gate_override_reason'],
            ['App\\Models\\SettlementPayoutBatch', 'payout_finalized', 'finalized_at'], ['App\\Models\\Salesman', 'payout_held', 'payout_hold'],
            ['App\\Models\\Salesman', 'updated', 'left_at'], ['App\\Models\\Buyer', 'updated', 'buyer:TOKYO AUTO'], ['App\\Models\\Buyer', 'b2b_partner_toggled', 'is_b2b_partner'],
            ['App\\Models\\BuyerCashReceipt', 'buyer_cash_receipt_added', null], ['App\\Models\\User', 'assistant_query', 'guide'], ['App\\Models\\User', 'deleted', null],
            ['App\\Models\\Setting', 'lock_toggle_changed', 'lock_shipping_entry'], ['App\\Models\\CashSnapshot', 'updated', 'balance_krw'],
            ['App\\Models\\KakaoRoom', 'kakao_room_fixed', 'sides'], ['App\\Models\\SomethingNew', 'updated', 'x'],
            ['App\\Models\\BoardRequest', 'board_request_rejected', 'reject_reason'], ['App\\Models\\VehicleClaim', 'board_claim_received', 'confirmed_amount'],
            ['App\\Models\\ForwardingInvoice', 'forwarding_invoice_paid', null], ['App\\Models\\SavingsStatus', 'savings_used', 'savings'],
        ];
    }

    public function test_php_and_sql_agree_and_every_row_lands_in_exactly_one_area(): void
    {
        $user = User::factory()->create();
        $expect = [];
        foreach ($this->sampleRows() as [$type, $action, $col]) {
            $log = AuditLog::create(['user_id' => $user->id, 'action' => $action, 'auditable_type' => $type, 'auditable_id' => 1, 'column_name' => $col]);
            $expect[AuditArea::of($type, $action, $col)][] = $log->id;
        }

        $seen = [];
        foreach (AuditArea::keys() as $area) {
            $q = AuditLog::query();
            AuditArea::scope($q, $area);
            $ids = $q->orderBy('id')->pluck('id')->all();
            $want = $expect[$area] ?? [];
            sort($want);
            $this->assertSame($want, $ids, '「'.AuditArea::label($area).'」 — SQL 조건과 PHP 판정이 다른 줄을 고른다');
            $seen = array_merge($seen, $ids);
        }
        sort($seen);
        $this->assertSame(AuditLog::query()->orderBy('id')->pluck('id')->all(), $seen, '모든 줄이 정확히 한 구역에 들어가야 한다(빠지거나 겹치면 안 된다)');
        $this->assertGreaterThanOrEqual(8, count($expect), '표본이 구역을 고루 덮어야 한다');
    }

    public function test_the_screen_filters_by_area_and_narrows_the_column_list(): void
    {
        $admin = User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]);
        $mk = fn (string $type, string $action, ?string $col, string $mark) => AuditLog::create([
            'user_id' => $admin->id, 'action' => $action, 'auditable_type' => $type, 'auditable_id' => 1, 'column_name' => $col, 'new_value' => $mark,
        ]);
        $mk(Vehicle::class, 'updated', 'purchase_price', 'MARK-PURCHASE');
        $mk(Vehicle::class, 'updated', 'sale_price', 'MARK-SALE');
        $mk('App\\Models\\FinalPayment', 'created', 'payment_row', 'MARK-PAYMENT');
        $mk('App\\Models\\Settlement', 'updated', 'settlement_status', 'MARK-SETTLE');

        $this->actingAs($admin);
        $c = Volt::test('admin.audit-logs.index');
        $html = $c->html();
        $this->assertStringContainsString('data-audit-area="sale"', $html);
        $this->assertTrue(str_contains($html, 'MARK-PURCHASE') && str_contains($html, 'MARK-SETTLE'));
        $counts = array_filter($c->instance()->areaCounts);
        ksort($counts);
        $this->assertSame(['_all' => 4, 'purchase' => 1, 'sale' => 2, 'settle' => 1], $counts, '칩 건수');

        $html = $c->call('pickArea', 'sale')->html();
        $this->assertTrue(str_contains($html, 'MARK-SALE') && str_contains($html, 'MARK-PAYMENT'), '판매·입금 = 차량의 판매 칸 + 판매 입금 줄');
        $this->assertFalse(str_contains($html, 'MARK-PURCHASE') || str_contains($html, 'MARK-SETTLE'));
        $cols = array_keys($c->instance()->distinctColumns);
        sort($cols);
        $this->assertSame(['payment_row', 'sale_price'], $cols, '컬럼 고르기도 그 구역 칸만 남는다');

        $c->call('pickArea', 'sale');   // 같은 칩을 다시 누르면 풀린다
        $this->assertSame('', $c->get('areaFilter'));
    }

    public function test_the_vehicle_panel_links_to_its_change_history_for_those_who_may_see_logs(): void
    {
        $v = Vehicle::create(['vehicle_number' => '77가7001', 'sales_channel' => 'export']);

        $this->actingAs(User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]));
        $html = Volt::test('erp.vehicles.index')->call('openEdit', $v->id)->html();
        $this->assertTrue(str_contains($html, 'data-vehicle-history-link'), '로그를 볼 수 있는 사람에게 링크가 없다');
        $this->assertTrue(str_contains($html, 'audit-logs?search='.rawurlencode('77가7001')), '차번호로 걸러 가는 주소가 아니다');

        $this->actingAs(User::factory()->create(['permission' => 'user', 'role' => '재무', 'email_verified_at' => now()]));
        $html = Volt::test('erp.vehicles.index')->call('openEdit', $v->id)->html();
        $this->assertFalse(str_contains($html, 'data-vehicle-history-link'), '로그를 못 보는 사람(재무)에게 링크가 보인다');
    }
}
