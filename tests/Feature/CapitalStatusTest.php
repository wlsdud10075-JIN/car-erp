<?php

namespace Tests\Feature;

use App\Console\Commands\AlimtalkCapitalWeekly;
use App\Models\AdvanceReceipt;
use App\Models\Buyer;
use App\Models\CashSnapshot;
use App\Models\ForwardingCompany;
use App\Models\ForwardingInvoice;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\CapitalStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 자금 현황 / 손익 (jin 2026-07-23).
 *   통장잔액(수동) + 재고·미수·미지급(ERP 캡처) → 청산가치·굴리는자금·손익.
 *   입력=재무/관리/업무관리자, 손익열람=super/대표, 원금=super(기능설정).
 */
class CapitalStatusTest extends TestCase
{
    use RefreshDatabase;

    private function seedErp(): void
    {
        // 재고 = 매입 완납·미판매·미출고 (inStock)
        $inv = Vehicle::create(['vehicle_number' => 'CAP-INV', 'sales_channel' => 'export', 'purchase_date' => '2026-05-01', 'purchase_price' => 5_000_000]);
        $inv->purchaseBalancePayments()->create(['amount' => 5_000_000, 'type' => 'balance', 'payment_date' => '2026-05-05', 'confirmed_at' => now()]);

        // 미지급 = 매입 미완납 (딜러 줄 돈 7백만)
        $pay = Vehicle::create(['vehicle_number' => 'CAP-PAY', 'sales_channel' => 'export', 'purchase_date' => '2026-05-01', 'purchase_price' => 10_000_000]);
        $pay->purchaseBalancePayments()->create(['amount' => 3_000_000, 'type' => 'down', 'payment_date' => '2026-05-05', 'confirmed_at' => now()]);

        // 미수 = 판매 미입금 (KRW 2천만)
        $buyer = Buyer::create(['name' => 'CAP-BUYER', 'is_active' => true]);
        Vehicle::create(['vehicle_number' => 'CAP-RCV', 'sales_channel' => 'export', 'buyer_id' => $buyer->id, 'sale_date' => '2026-06-01', 'sale_price' => 20_000_000, 'currency' => 'KRW', 'exchange_rate' => 1]);
    }

    public function test_capture_computes_erp_values_and_upserts_one_row_per_date(): void
    {
        $this->seedErp();
        $svc = app(CapitalStatusService::class);

        $s1 = $svc->capture(['krw' => 1_000_000, 'usd' => 0, 'eur' => 0], null, '2026-07-23');
        // 재고 = 미출고·거래완료아님 (완납 무관) → INV 5백 + PAY 1천 = 1천5백만
        $this->assertEquals(15_000_000, $s1->inventory_krw, '재고 = 묶인 자본(완납 무관)');
        $this->assertEquals(7_000_000, $s1->payable_krw, '미지급 = 매입 미완납');
        $this->assertEquals(20_000_000, $s1->receivable_krw, '미수 = 판매 미입금');

        // 같은 날 재입력 → upsert (1건 유지, 값 갱신)
        $svc->capture(['krw' => 2_000_000, 'usd' => 0, 'eur' => 0], null, '2026-07-23');
        $this->assertEquals(1, CashSnapshot::whereDate('snapshot_date', '2026-07-23')->count());
        $this->assertEquals(2_000_000, CashSnapshot::whereDate('snapshot_date', '2026-07-23')->first()->balance_krw);
    }

    // ── 재고 정의 · 이중계상 (jin 2026-07-31) ─────────────────
    /*
     * 2026-07-29 에 출고일 60건을 소급 입력했더니 재고가 17.8억 → 4.97억으로 떨어져
     * 원금대비손익이 +9.85억에서 -5.01억으로 뒤집혔다. 실측하니 그 재고의 92%가 이미 팔린 차였다.
     * 아래 테스트들이 그 사고의 재발을 막는다.
     */

    /**
     * 판매까지 끝난 차 한 대 — 대금 일부 수령.
     * ⚠️ 매입은 **완납 처리**한다. 미지급이 섞이면 아래 테스트들이 재고·선수금이 아니라
     *    미지급 때문에 흔들려서, 무엇을 검증하는지 흐려진다(미지급은 별도 테스트가 있다).
     */
    private function soldVehicle(string $no, int $purchase, int $sale, int $received): Vehicle
    {
        $buyer = Buyer::firstOrCreate(['name' => 'CAP-B'], ['is_active' => true]);
        $v = Vehicle::create([
            'vehicle_number' => $no, 'sales_channel' => 'export', 'purchase_date' => '2026-05-01',
            'purchase_price' => $purchase, 'buyer_id' => $buyer->id, 'sale_date' => '2026-06-01',
            'sale_price' => $sale, 'currency' => 'KRW', 'exchange_rate' => 1,
        ]);
        $v->purchaseBalancePayments()->create(['amount' => $purchase, 'type' => 'balance', 'payment_date' => '2026-05-05', 'confirmed_at' => now()]);
        if ($received > 0) {
            $v->finalPayments()->create(['amount' => $received, 'type' => 'balance', 'payment_date' => '2026-06-10', 'confirmed_at' => now()]);
        }

        return $v->fresh();
    }

    public function test_warehouse_out_date_no_longer_moves_the_liquidation_value(): void
    {
        // 출고일은 위치 이동(우리 야드 → 항구)일 뿐 소유권 이동이 아니다. 자산이 흔들리면 안 된다.
        $v = $this->soldVehicle('CAP-OUT', 8_000_000, 12_000_000, 0);
        $svc = app(CapitalStatusService::class);

        $before = $svc->capture(['krw' => 0], null, '2026-07-30');
        $v->update(['warehouse_out_date' => '2026-07-15']);
        $after = $svc->capture(['krw' => 0], null, '2026-07-31');

        $this->assertSame((int) $before->inventory_krw, (int) $after->inventory_krw,
            '출고일을 찍었다고 재고가 줄면 07-29 사고가 되풀이됩니다.');
    }

    public function test_shipping_date_removes_the_car_from_inventory(): void
    {
        // 한국을 떠나는 건 선적일이다. 그때는 되팔 수 없으므로 청산 자산에서 빠진다.
        $v = $this->soldVehicle('CAP-SHIP', 8_000_000, 12_000_000, 0);
        $svc = app(CapitalStatusService::class);

        $before = $svc->capture(['krw' => 0], null, '2026-07-30');
        $this->assertSame(8_000_000, (int) $before->inventory_krw);

        $v->update(['shipping_date' => '2026-07-20']);
        $after = $svc->capture(['krw' => 0], null, '2026-07-31');
        $this->assertSame(0, (int) $after->inventory_krw);
    }

    public function test_advance_payment_is_deducted_so_the_same_deal_is_not_counted_twice(): void
    {
        // 선적 전인데 대금을 받았다 → 차(재고)와 현금(통장)이 둘 다 자산으로 잡힌다.
        // 청산 시 되팔면 받은 돈은 반환해야 하므로 선수금으로 빼야 한다.
        $this->soldVehicle('CAP-ADV', 8_000_000, 12_000_000, 12_000_000);

        $snap = app(CapitalStatusService::class)->capture(['krw' => 12_000_000], null, '2026-07-31');
        $d = app(CapitalStatusService::class)->derive($snap);

        $this->assertSame(12_000_000, (int) $snap->advance_payment_krw);
        // 현금 1200 + 재고 800 − 선수금 1200 = 800만 (차 원가만 남는 게 맞다)
        $this->assertSame(8_000_000, (int) $d['liquidation_krw'],
            '선수금을 안 빼면 같은 거래가 두 번 세어집니다.');
    }

    public function test_net_worth_uses_unsold_inventory_plus_receivable(): void
    {
        // 팔린 차의 가치는 이미 미수로 옮겨갔다. 재고로 또 세면 이중계상.
        $this->soldVehicle('CAP-NW1', 8_000_000, 12_000_000, 0);          // 팔림 + 미수 1200
        $unsold = Vehicle::create(['vehicle_number' => 'CAP-NW2', 'sales_channel' => 'export',
            'purchase_date' => '2026-05-01', 'purchase_price' => 3_000_000]);  // 안 팔림
        $unsold->purchaseBalancePayments()->create(['amount' => 3_000_000, 'type' => 'balance', 'payment_date' => '2026-05-05', 'confirmed_at' => now()]);

        $svc = app(CapitalStatusService::class);
        $d = $svc->derive($svc->capture(['krw' => 0], null, '2026-07-31'));

        $this->assertSame(3_000_000, (int) $d['unsold_inventory_krw'], '안 팔린 차만 재고로 셉니다.');
        // 순자산 = 미판매재고 300 + 미수 1200 = 1500만
        $this->assertSame(15_000_000, (int) $d['working_capital_krw']);
    }

    public function test_recapture_keeps_the_balance_and_refreshes_erp_values(): void
    {
        // 가수금 성격을 바꾼 뒤 잔액을 다시 치지 않고도 반영되어야 한다(jin 2026-07-31).
        $adv = AdvanceReceipt::create([
            'received_date' => '2026-07-01', 'company_name' => '대표', 'amount' => 5_000_000,
        ]);
        $svc = app(CapitalStatusService::class);
        $svc->capture(['krw' => 9_000_000, 'usd' => 12.5, 'eur' => 0], null, '2026-07-31');

        $adv->update(['nature' => AdvanceReceipt::NATURE_EQUITY]);
        $after = $svc->recapture(null, '2026-07-31');

        $this->assertSame(9_000_000, (int) $after->balance_krw, '통장 잔액은 유지되어야 합니다.');
        $this->assertSame(12.5, (float) $after->balance_usd);
        $this->assertSame(0, (int) $after->advance_krw, '성격 변경이 반영되어야 합니다.');
        $this->assertSame(1, CashSnapshot::whereDate('snapshot_date', '2026-07-31')->count(), '행이 늘면 안 됩니다.');
    }

    public function test_recapture_does_nothing_without_an_existing_snapshot(): void
    {
        // 잔액 기록이 없는 날짜를 0 으로 지어내면 자금현황이 거짓이 된다.
        $this->assertNull(app(CapitalStatusService::class)->recapture(null, '2026-07-01'));
        $this->assertSame(0, CashSnapshot::count());
    }

    public function test_two_profit_figures_are_reported(): void
    {
        /*
         * 손익을 청산 기준 하나만 내면 "미수 전액 손실" 가정이라 실제보다 훨씬 나쁘게 읽힌다.
         * 실측(heymanerp 2026-07-31): 청산 기준 -5.02억 vs 정상 회수 기준 +0.46억 — 5.48억 차이.
         */
        Setting::updateOrCreate(['key' => CapitalStatusService::PRINCIPAL_KEY],
            ['value' => '10000000', 'type' => 'integer']);
        $this->soldVehicle('CAP-2P', 8_000_000, 12_000_000, 0);   // 팔림 + 미수 1200

        $svc = app(CapitalStatusService::class);
        $d = $svc->derive($svc->capture(['krw' => 0], null, '2026-07-31'));

        $this->assertSame((int) $d['liquidation_krw'] - 10_000_000, (int) $d['profit_krw']);
        $this->assertSame((int) $d['working_capital_krw'] - 10_000_000, (int) $d['net_profit_krw']);
        $this->assertGreaterThan((int) $d['profit_krw'], (int) $d['net_profit_krw'],
            '미수가 있으면 정상 회수 기준이 더 커야 합니다.');
    }

    public function test_owner_advance_is_added_to_the_principal(): void
    {
        /*
         * 대표 돈을 부채에서 빼면 청산가치가 그만큼 올라간다. 그 돈도 대표가 넣은 밑천이므로
         * 원금에도 더해야 한다. 한쪽만 반영하면 **차액이 통째로 이익으로 잡힌다**(jin 2026-07-31).
         */
        Setting::updateOrCreate(['key' => CapitalStatusService::PRINCIPAL_KEY],
            ['value' => '65000000', 'type' => 'integer']);
        AdvanceReceipt::create(['received_date' => '2026-07-01', 'company_name' => '대표이사',
            'amount' => 300_000_000, 'nature' => AdvanceReceipt::NATURE_EQUITY]);
        AdvanceReceipt::create(['received_date' => '2026-07-01', 'company_name' => '김진숙',
            'amount' => 50_000_000, 'nature' => AdvanceReceipt::NATURE_LIABILITY]);

        $svc = app(CapitalStatusService::class);

        $this->assertSame(365_000_000, $svc->principal(), '원금 = 설정 6,500만 + 대표 가수금 3억');
        $b = $svc->principalBreakdown();
        $this->assertSame(65_000_000, $b['base_krw']);
        $this->assertSame(300_000_000, $b['owner_advance_krw']);

        // 청산가치에서는 갚을 돈(5천만)만 빠진다.
        $d = $svc->derive($svc->capture(['krw' => 365_000_000], null, '2026-07-31'));
        $this->assertSame(50_000_000, (int) $d['advance_krw']);
        $this->assertSame(315_000_000, (int) $d['liquidation_krw']);
        $this->assertSame(-50_000_000, (int) $d['profit_krw'],
            '대표 돈을 원금에 안 더하면 손익이 +2.5억으로 부풀려집니다.');
    }

    public function test_principal_stays_null_without_a_base_setting(): void
    {
        // 비교 기준이 없으면 손익을 말할 수 없다. 대표 가수금만으로 원금을 만들어내면 안 된다.
        AdvanceReceipt::create(['received_date' => '2026-07-01', 'company_name' => '대표이사',
            'amount' => 100_000_000, 'nature' => AdvanceReceipt::NATURE_EQUITY]);

        $this->assertNull(app(CapitalStatusService::class)->principal());
    }

    public function test_recapture_button_lives_on_the_admin_dashboard(): void
    {
        // 잔고 기입은 업무 대시보드, 보는 건 관리자 대시보드 — 버튼도 보는 쪽에 둔다(jin 2026-07-31).
        $adminSrc = file_get_contents(resource_path('views/livewire/admin/dashboard.blade.php'));
        $workSrc = file_get_contents(resource_path('views/livewire/erp/dashboard.blade.php'));

        $this->assertStringContainsString('recaptureCash', $adminSrc);
        $this->assertStringNotContainsString('recaptureCash', $workSrc,
            '업무 대시보드에는 잔고 기입만 둡니다.');
    }

    public function test_recapture_from_admin_dashboard_refreshes_values(): void
    {
        $adv = AdvanceReceipt::create(['received_date' => '2026-07-01', 'company_name' => '대표', 'amount' => 4_000_000]);
        $svc = app(CapitalStatusService::class);
        $svc->capture(['krw' => 7_000_000], null, now()->toDateString());
        $adv->update(['nature' => AdvanceReceipt::NATURE_EQUITY]);

        Volt::actingAs(User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]))
            ->test('admin.dashboard')
            ->call('recaptureCash');

        $latest = $svc->latest();
        $this->assertSame(7_000_000, (int) $latest->balance_krw, '잔액은 유지되어야 합니다.');
        $this->assertSame(0, (int) $latest->advance_krw, '성격 변경이 반영되어야 합니다.');
    }

    public function test_dashboard_does_not_redefine_the_liquidation_formula(): void
    {
        /*
         * 카드(derive)와 추이 그래프가 각자 계산하면 반드시 어긋난다.
         * 실제로 2026-07-31 선수금 차감을 넣었을 때, 대시보드가 옛 공식을 인라인으로 들고 있어
         * 카드와 그래프가 다른 값을 그렸다. 공식은 derive() 한 곳에만 있어야 한다.
         *
         * ⚠️ 값 비교로는 못 잡는다 — 두 공식이 우연히 같은 값을 낼 수 있고,
         *    실제로 오늘 그런 상태였다가 항목이 하나 늘면서 갈라졌다. 그래서 정적으로 본다.
         */
        $src = file_get_contents(resource_path('views/livewire/admin/dashboard.blade.php'));

        $this->assertStringContainsString('$svc->derive($s', $src,
            '추이는 derive() 를 호출해야 합니다(공식 단일 출처).');
        $this->assertDoesNotMatchRegularExpression('/\$s->cash_krw\s*\+.*inventory_krw/s', $src,
            '대시보드가 청산가치 공식을 다시 정의하고 있습니다. derive() 를 쓰세요.');
    }

    public function test_old_snapshots_fall_back_to_the_previous_formula(): void
    {
        // 과거 스냅샷에는 새 컬럼이 없다. 그 시점 기록을 그대로 읽어야 추적이 깨지지 않는다.
        $s = CashSnapshot::create([
            'snapshot_date' => '2026-07-01', 'balance_krw' => 1_000_000,
            'inventory_krw' => 5_000_000, 'receivable_krw' => 2_000_000, 'payable_krw' => 0,
            'advance_krw' => 0, 'auction_deposit_krw' => 0, 'fx_usd' => 1400, 'fx_eur' => 1600,
        ]);

        $d = app(CapitalStatusService::class)->derive($s);

        $this->assertSame(6_000_000, (int) $d['liquidation_krw'], '선수금 컬럼이 없으면 0으로 본다.');
        $this->assertSame(8_000_000, (int) $d['working_capital_krw'], '구 스냅샷은 청산+미수 로 폴백.');
        $this->assertNull($d['unsold_inventory_krw']);
    }

    public function test_payable_includes_unpaid_forwarding_invoice(): void
    {
        // jin 2026-07-26 — 미지급 완결성: 매입 미지급 + 포워딩 운임 미지급(+정산 지급대기).
        $this->seedErp();
        $svc = app(CapitalStatusService::class);
        $base = $svc->payableKrw();   // 매입 미지급만 = 7,000,000

        $fc = ForwardingCompany::create(['name' => 'FWD CAP', 'is_active' => true]);
        $inv = ForwardingInvoice::create([
            'forwarding_company_id' => $fc->id, 'group_type' => 'container', 'group_key' => 'CAP1',
            'currency' => 'KRW', 'amount' => 500_000, 'invoice_date' => '2026-07-20',
        ]);
        $this->assertSame($base + 500_000, $svc->payableKrw(), '미지급 = 매입 + 미지급 운임');

        // 지급 완료 → 미지급에서 빠짐
        $inv->update(['paid_at' => now(), 'actual_paid_krw' => 500_000]);
        $this->assertSame($base, $svc->payableKrw(), '운임 지급 완료 → 미지급 제외');
    }

    public function test_derive_liquidation_working_and_profit(): void
    {
        $this->seedErp();
        $svc = app(CapitalStatusService::class);
        $snap = $svc->capture(['krw' => 1_000_000, 'usd' => 0, 'eur' => 0], null, '2026-07-23');

        $d = $svc->derive($snap);
        // 청산가치 = 현금1백 + 재고1천5백 − 미지급7백 = 9백만 (미수 제외)
        $this->assertEquals(9_000_000, $d['liquidation_krw']);
        // 굴리는 = 청산 + 미수2천 = 2천9백만
        $this->assertEquals(29_000_000, $d['working_capital_krw']);
        // 원금 미설정 → 손익 null
        $this->assertNull($d['profit_krw']);

        // 원금 1천만 설정 → 손익 = 청산(9백) − 원금(1천) = −1백만
        Setting::updateOrCreate(['key' => CapitalStatusService::PRINCIPAL_KEY], ['value' => '10000000', 'type' => 'integer']);
        $d2 = $svc->derive($svc->latest());
        $this->assertEquals(10_000_000, $d2['principal_krw']);
        $this->assertEquals(-1_000_000, $d2['profit_krw']);
    }

    public function test_permissions(): void
    {
        $super = User::factory()->create(['permission' => 'super']);
        $admin = User::factory()->create(['permission' => 'admin']);
        $manager = User::factory()->create(['permission' => 'manager']);
        $finance = User::factory()->create(['permission' => 'user', 'role' => '재무']);
        $gwan = User::factory()->create(['permission' => 'user', 'role' => '관리']);
        $sales = User::factory()->create(['permission' => 'user', 'role' => '영업']);
        $clear = User::factory()->create(['permission' => 'user', 'role' => '수출통관']);

        // 입력 권한: 재무·관리·업무관리자·admin·super
        foreach ([$super, $admin, $manager, $finance, $gwan] as $u) {
            $this->assertTrue($u->canEnterCashBalance(), $u->permission.'/'.$u->role.' 입력 가능');
        }
        foreach ([$sales, $clear] as $u) {
            $this->assertFalse($u->canEnterCashBalance(), $u->role.' 입력 불가');
        }

        // 손익 열람: super·대표(admin)만
        $this->assertTrue($super->canViewCapital());
        $this->assertTrue($admin->canViewCapital());
        foreach ([$manager, $finance, $gwan, $sales, $clear] as $u) {
            $this->assertFalse($u->canViewCapital(), $u->permission.'/'.$u->role.' 손익 열람 불가');
        }
    }

    public function test_finance_saves_balance_via_work_dashboard(): void
    {
        $this->seedErp();
        $finance = User::factory()->create(['permission' => 'user', 'role' => '재무', 'email_verified_at' => now()]);
        $this->actingAs($finance);

        Volt::test('erp.dashboard')
            ->set('cashDate', '2026-07-23')
            ->set('cashKrw', '20,823,407')
            ->set('cashUsd', '29001.71')
            ->set('cashEur', '53169.73')
            ->call('saveCashBalance')
            ->assertHasNoErrors()
            // 저장 후 입력칸 비움 (jin 2026-07-24)
            ->assertSet('cashKrw', '')
            ->assertSet('cashUsd', '')
            ->assertSet('cashEur', '')
            ->assertSet('cashDate', now()->toDateString());

        $snap = CashSnapshot::whereDate('snapshot_date', '2026-07-23')->first();
        $this->assertNotNull($snap);
        $this->assertEquals(20_823_407, $snap->balance_krw, '콤마 제거 후 저장');
        $this->assertEquals($finance->id, $snap->entered_by);
        $this->assertEquals(15_000_000, $snap->inventory_krw, 'ERP 재고 캡처(완납 무관)');
    }

    public function test_admin_dashboard_renders_capital_widget_with_data(): void
    {
        // advisor: has_data=true 렌더 경로(eok·손익 색·Carbon·추이 @json) 검증
        $this->seedErp();
        $admin = User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
        $svc = app(CapitalStatusService::class);
        $svc->capture(['krw' => 20_000_000, 'usd' => 0, 'eur' => 0], $admin, '2026-07-22');
        $svc->capture(['krw' => 25_000_000, 'usd' => 0, 'eur' => 0], $admin, '2026-07-23');   // 추이 2점
        Setting::updateOrCreate(['key' => CapitalStatusService::PRINCIPAL_KEY], ['value' => '10000000', 'type' => 'integer']);

        // 입력칸은 스냅샷이 있어도 항상 빈칸으로 시작 (jin 2026-07-24) — 최근 입력 날짜만 표시
        $this->actingAs($finance = User::factory()->create(['permission' => 'user', 'role' => '재무', 'email_verified_at' => now()]));
        Volt::test('erp.dashboard')
            ->assertSet('cashKrw', '')
            ->assertSet('cashUsd', '')
            ->assertSet('cashEur', '')
            ->assertSet('cashSavedAt', '2026-07-23');

        $this->actingAs($admin);
        Volt::test('admin.dashboard')
            ->assertOk()
            ->assertSeeHtml('id="capitalTrendChart"')
            // 자금추이 재렌더 fix (jin 2026-07-25): 그리기·재렌더 배선을 app.js 공용 registerChart 로 이관.
            //   컴포넌트 blade 는 더 이상 인라인 morph/livewire:init 스크립트를 갖지 않는다(소멸 버그 근본원인 제거).
            ->assertDontSeeHtml("Livewire.hook('morph.updated'")
            ->assertDontSeeHtml("'livewire:updated'")
            // 집계 단위 토글(일/주/월/년) 노출
            ->assertSeeHtml('wire:click="setTrendGrain');
    }

    public function test_capital_trend_grain_aggregates_to_period_end(): void
    {
        // day = 원본 일별 / month = 기간 말 스냅샷만. 같은 달 2점 → month 는 1점으로 축약.
        $admin = User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
        $svc = app(CapitalStatusService::class);
        $svc->capture(['krw' => 20_000_000, 'usd' => 0, 'eur' => 0], $admin, '2026-07-22');
        $svc->capture(['krw' => 25_000_000, 'usd' => 0, 'eur' => 0], $admin, '2026-07-23');

        $this->assertCount(2, $svc->history(120, 'day'));
        $month = $svc->history(120, 'month');
        $this->assertCount(1, $month);                                   // 같은 달 → 1점
        $this->assertSame('2026-07-23', $month->first()->snapshot_date->format('Y-m-d')); // 기간 말(마지막) 잔액

        // 토글 전환 시 capitalTrend 재계산 (month → 1점이라 <2 → 안내 문구)
        $this->actingAs($admin);
        Volt::test('admin.dashboard')
            ->call('setTrendGrain', 'month')
            ->assertSet('trendGrain', 'month')
            ->assertDontSeeHtml('id="capitalTrendChart"')          // 1점이면 캔버스 미표시
            ->assertSeeHtml(__('cash.trend_accumulating'));
    }

    public function test_chart_render_registry_lives_in_app_js(): void
    {
        // 소멸 버그 근본원인 = 컴포넌트 인라인 스크립트의 livewire:init 래퍼. 재렌더 배선은
        //   반드시 app.js(세션당 1회 로드)의 공용 registerChart 에 있어야 한다(SKILLS §8 #21).
        $appJs = file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString('window.registerChart', $appJs);
        $this->assertStringContainsString("registerChart('capitalTrendChart'", $appJs);
        $this->assertStringContainsString("window.Livewire.hook('morph.updated', () => runAllCharts())", $appJs);
    }

    public function test_sales_cannot_save_balance(): void
    {
        $sales = User::factory()->create(['permission' => 'user', 'role' => '영업', 'email_verified_at' => now()]);
        $this->actingAs($sales);

        Volt::test('erp.dashboard')
            ->set('cashKrw', '999')
            ->call('saveCashBalance')
            ->assertStatus(403);

        $this->assertEquals(0, CashSnapshot::count());
    }

    public function test_super_saves_principal_via_settings(): void
    {
        $super = User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]);
        $this->actingAs($super);

        Volt::test('admin.settings')
            ->set('capitalPrincipal', '2,500,000,000')
            ->call('saveCapitalPrincipal')
            ->assertHasNoErrors();

        $this->assertEquals('2500000000', Setting::get(CapitalStatusService::PRINCIPAL_KEY));
    }

    public function test_alimtalk_capital_weekly_vars(): void
    {
        $this->seedErp();
        $svc = app(CapitalStatusService::class);
        $svc->capture(['krw' => 1_000_000, 'usd' => 0, 'eur' => 0], null, '2026-07-23');
        Setting::updateOrCreate(['key' => CapitalStatusService::PRINCIPAL_KEY], ['value' => '10000000', 'type' => 'integer']);

        $vars = AlimtalkCapitalWeekly::buildVars();
        $this->assertEquals('2026-07-23', $vars['기준일']);
        $this->assertStringStartsWith('−', $vars['손익'], '청산 −1백 − 원금 1천 = 손익 음수');
        $this->assertArrayHasKey('굴리는자금', $vars);
        $this->assertArrayHasKey('미지급', $vars);
    }

    public function test_alimtalk_capital_weekly_empty_and_inert_without_snapshot(): void
    {
        // 스냅샷 없으면 변수 빈 배열 → 크론 skip (배포 inert)
        $this->assertEmpty(AlimtalkCapitalWeekly::buildVars());
        $this->artisan('alimtalk:capital-weekly')->assertExitCode(0);
    }
}
