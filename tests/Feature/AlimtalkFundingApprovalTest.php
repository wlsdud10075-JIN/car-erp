<?php

namespace Tests\Feature;

use App\Models\AlimtalkLog;
use App\Models\Buyer;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\InterVehicleTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 보증금 매입 선지급 승인 알림톡 (2026-07-23, jin) — 정산 지급 승인 사다리와 동일.
 *   요청: 기안→관리 / 관리승인→재무. 결과: 재무확정→기안자(완료) / 반려→기안자(반려).
 */
class AlimtalkFundingApprovalTest extends TestCase
{
    use RefreshDatabase;

    private InterVehicleTransferService $service;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
        Http::fake(['*bizmsg.kr*' => Http::response([['code' => 'success', 'data' => ['msgid' => 'M'], 'message' => 'K000']], 200)]);
        $this->service = new InterVehicleTransferService;
        $set = Setting::companyTemplateSet();
        Setting::updateOrCreate(['key' => "alimtalk_enabled_{$set}"], ['value' => '1', 'type' => 'boolean']);
        Setting::updateOrCreate(['key' => "alimtalk_userid_{$set}"], ['value' => 'uid', 'type' => 'string']);
        Setting::updateOrCreate(['key' => "alimtalk_profile_{$set}"], ['value' => 'prof', 'type' => 'string']);
        foreach (['erp_deposit_funding_request', 'erp_deposit_funding_done', 'erp_deposit_funding_rejected'] as $c) {
            Setting::updateOrCreate(['key' => "alimtalk_tmpl_{$c}_{$set}"], ['value' => 'T_'.$c, 'type' => 'string']);
        }
    }

    /** 기안자(phone)·관리·재무 3인 + 소스/대상 차량. */
    private function ctx(): array
    {
        $buyer = Buyer::create(['name' => 'TOKYO', 'is_active' => true]);
        $drafter = User::factory()->create(['permission' => 'user', 'role' => '관리', 'phone' => '010-1000-0000', 'email_verified_at' => now()]);
        $manager = User::factory()->create(['permission' => 'manager', 'phone' => '010-2000-0000', 'email_verified_at' => now()]);
        $finance = User::factory()->create(['permission' => 'user', 'role' => '재무', 'phone' => '010-3000-0000', 'email_verified_at' => now()]);
        $admin = User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);   // 재무 확정 권한(SoD 통과용)

        $source = Vehicle::create([
            'vehicle_number' => 'S1', 'sales_channel' => 'export', 'buyer_id' => $buyer->id,
            'sale_date' => '2026-05-01', 'sale_price' => 100_000, 'currency' => 'USD', 'exchange_rate' => 1300,
        ]);
        $source->finalPayments()->create(['amount' => 100_000, 'type' => 'balance', 'payment_date' => '2026-05-02', 'exchange_rate' => 1300, 'confirmed_at' => now()]);
        $source->refresh();

        $target = Vehicle::create([
            'vehicle_number' => 'T3', 'sales_channel' => 'export', 'buyer_id' => $buyer->id,
            'purchase_date' => '2026-05-10', 'purchase_price' => 30_000_000,
        ]);

        return compact('buyer', 'drafter', 'manager', 'finance', 'admin', 'source', 'target');
    }

    public function test_draft_notifies_managers_and_approve_notifies_finance(): void
    {
        ['drafter' => $d, 'manager' => $m, 'source' => $s, 'target' => $t] = $this->ctx();

        $transfer = $this->service->applyPurchaseFunding($s, $t, 30_000_000, $d);
        // 기안 → 관리(업무관리자) 요청 알림
        $this->assertSame(1, AlimtalkLog::where('template_code', 'erp_deposit_funding_request')->where('phone', '01020000000')->count());

        $this->service->approvePurchaseFunding($transfer, $m);
        // 관리 승인 → 재무(role='재무') 확정 요청 알림
        $this->assertSame(1, AlimtalkLog::where('template_code', 'erp_deposit_funding_request')->where('phone', '01030000000')->count());
    }

    public function test_finance_confirm_notifies_drafter_done(): void
    {
        ['drafter' => $d, 'manager' => $m, 'finance' => $f, 'source' => $s, 'target' => $t] = $this->ctx();

        $transfer = $this->service->applyPurchaseFunding($s, $t, 30_000_000, $d);
        $this->service->approvePurchaseFunding($transfer, $m);
        $this->service->confirmPurchaseFundingByFinance($transfer, $f);

        // 재무 확정 → 기안자에게 완료 통보
        $this->assertSame(1, AlimtalkLog::where('template_code', 'erp_deposit_funding_done')->where('phone', '01010000000')->count());
    }

    public function test_finance_reject_notifies_drafter_rejected(): void
    {
        ['drafter' => $d, 'manager' => $m, 'finance' => $f, 'source' => $s, 'target' => $t] = $this->ctx();

        $transfer = $this->service->applyPurchaseFunding($s, $t, 30_000_000, $d);
        $this->service->approvePurchaseFunding($transfer, $m);
        $this->service->rejectByFinance($transfer, $f, '송금 불가 — 계좌 확인 필요');

        // 재무 거부 → 기안자에게 반려 통보
        $this->assertSame(1, AlimtalkLog::where('template_code', 'erp_deposit_funding_rejected')->where('phone', '01010000000')->count());
    }
}
