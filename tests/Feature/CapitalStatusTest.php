<?php

namespace Tests\Feature;

use App\Console\Commands\AlimtalkCapitalWeekly;
use App\Models\Buyer;
use App\Models\CashSnapshot;
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
            ->assertHasNoErrors();

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

        $this->actingAs($admin);
        Volt::test('admin.dashboard')->assertOk();
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
