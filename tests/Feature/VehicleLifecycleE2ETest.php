<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Consignee;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * 전체 생애주기 E2E — 차량 등록부터 거래완료까지 v4 워크플로우 전 단계 + 게이트 검증.
 *
 * 2026-05-26 사용자 시나리오 (회의록 docs/meetings/2026-05-26-external-review-audit.md §사용자결정 1):
 *   매입중 → 매입완료 → 말소완료 → 판매중
 *     → [선적 게이트 C5] 입금 40% 막힘 / 50% 통과
 *   → 선적중 → 선적완료 → 통관중
 *     → [B/L 게이트 G1] 입금 100% 미만 막힘 / 100% 통과
 *   → 거래완료
 *
 * 단계명은 v4 cascade(SKILLS §2) 기준. C5(선적 진입)는 UI save() 흐름이라
 * guardStageOrderForExport() 명시 호출로 모사 / G1(B/L)은 saving 훅이라 save()로 발동.
 *
 * ⚠️ '판매완료'(판매 미입금 0 = 100% 완납)는 본 흐름에 나타나지 않음 — 50% 입금 시점에
 *    선적(반입지)으로 진입하면 v4 cascade 우선순위상 '선적중'이 '판매완료'를 덮음 (정상).
 */
class VehicleLifecycleE2ETest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    public function test_full_lifecycle_purchase_to_completion_with_gates(): void
    {
        $admin = User::factory()->create(['permission' => 'admin']);
        $buyer = Buyer::create(['name' => 'E2E BUYER', 'is_active' => true]);
        $consignee = Consignee::create(['buyer_id' => $buyer->id, 'name' => 'E2E CONS', 'is_active' => true]);
        $this->actingAs($admin);

        // ── 1) 매입중 — 차량 등록 (매입가만) ──
        $v = Vehicle::create([
            'vehicle_number' => 'E2E-1',
            'sales_channel' => 'export',
            'currency' => 'KRW',
            'exchange_rate' => 1,
            'progress_status_rule_version' => 4,
            'purchase_date' => '2026-04-01',
            'purchase_price' => 1_000_000,
            'dhl_request' => false,
        ]);
        $this->assertSame('매입중', $v->fresh()->progress_status);

        // ── 2) 매입완료 — 매입 잔금 confirmed (매입 미지급 0) ──
        $v->purchaseBalancePayments()->create([
            'amount' => 1_000_000, 'payment_date' => '2026-04-02',
            'confirmed_at' => now(), 'confirmed_by_user_id' => $admin->id,
        ]);
        $v->refreshCaches();
        $this->assertSame('매입완료', $v->fresh()->progress_status);

        // ── 3) 말소완료 — 말소 체크 + 말소서류 ──
        $v = Vehicle::find($v->id);
        $v->update(['is_deregistered' => true, 'deregistration_document' => 'fake/dereg.pdf']);
        $v->refreshCaches();
        $this->assertSame('말소완료', $v->fresh()->progress_status);

        // ── 4) 판매중 — 판매가 + 바이어 + 선적 컨사이니(선적 선행, jin 2026-07-09 당사자 축소) ──
        $v = Vehicle::find($v->id);
        $v->update([
            'sale_price' => 1_000_000, 'sale_date' => '2026-05-01',
            'buyer_id' => $buyer->id, 'bl_consignee_id' => $consignee->id,
        ]);
        $v->refreshCaches();
        $this->assertSame('판매중', $v->fresh()->progress_status);

        $addPayment = function (int $amount) use ($v, $admin) {
            $v->finalPayments()->create([
                'amount' => $amount, 'exchange_rate' => 1, 'payment_date' => '2026-05-02',
                'confirmed_at' => now(), 'confirmed_by_user_id' => $admin->id,
            ]);
            $v->refreshCaches();
        };

        // ── 5) 입금 40% → 선적 진입 막힘 (C5 50% 게이트) ──
        $addPayment(400_000);   // 40%
        $v5 = Vehicle::find($v->id);
        $v5->bl_loading_location = 'PUSAN';
        try {
            $v5->guardStageOrderForExport();
            $this->fail('40% 입금이면 선적 진입이 막혀야 한다');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('입금률 < 50%', $e->getMessage());
        }
        $this->assertSame('판매중', $v5->fresh()->progress_status, '선적 막힘 → 판매중 유지');

        // ── 6) 입금 50% → 선적 진입 통과 → 선적중 ──
        $addPayment(100_000);   // 누적 50%
        $v6 = Vehicle::find($v->id);
        $v6->bl_loading_location = 'PUSAN';
        $v6->guardStageOrderForExport();   // 통과
        $v6->save();
        $this->assertSame('선적중', $v6->fresh()->progress_status);

        // ── 7) 수출신고서 업로드 → 선적완료 ──
        $v7 = Vehicle::find($v->id);
        $v7->export_declaration_document = 'fake/export-decl.pdf';
        $v7->guardStageOrderForExport();   // 50% 이상이라 통과
        $v7->save();
        $this->assertSame('선적완료', $v7->fresh()->progress_status);

        // ── 8) 통관 처리 → 통관중 ──
        $v8 = Vehicle::find($v->id);
        $v8->is_export_cleared = true;
        $v8->guardStageOrderForExport();   // 통과
        $v8->save();
        $this->assertSame('통관중', $v8->fresh()->progress_status);

        // ── 9) 입금 50% 상태에서 B/L 업로드 막힘 (G1 100% 게이트) → 거래완료 안 됨 ──
        $v9 = Vehicle::find($v->id);
        $v9->bl_document = 'fake/bl.pdf';
        try {
            $v9->save();
            $this->fail('100% 미완납이면 B/L 업로드가 막혀야 한다');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('B/L 발행 차단', $e->getMessage());
            $this->assertStringContainsString('100% 미완납', $e->getMessage());
        }
        $this->assertSame('통관중', $v9->fresh()->progress_status, 'B/L 막힘 → 통관중 유지');

        // ── 10) 입금 100% 완납 → B/L 업로드 통과 → 거래완료 ──
        $addPayment(500_000);   // 누적 100%
        $v10 = Vehicle::find($v->id);
        $v10->bl_document = 'fake/bl.pdf';
        $v10->save();
        $this->assertSame('거래완료', $v10->fresh()->progress_status, 'B/L 발급 = 거래완료(v4)');
    }
}
