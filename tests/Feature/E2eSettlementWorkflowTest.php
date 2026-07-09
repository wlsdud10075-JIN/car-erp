<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * E2E 정산 워크플로우 점검 (사용자 요청 2026-05-27) — loop.
 *
 * 목적: [관리]1 + 영업5(KRW1·EUR1·USD3) 차량을 등록→...→거래완료→정산 까지 태우고
 *       ① 정산 금액이 정확한지(손계산 대조) ② 단계 누락이 없는지 집중 검증.
 *
 * 금액 공식 (CLAUDE.md §5 / SKILLS §13, 코드 실측):
 *   sales_amount_krw     = (sale_price + commission + auto_loading - tax_dc) × exchange_rate
 *   settlement_sales_krw = sales_amount_krw - cost_total
 *   sales_margin         = settlement_sales_krw - (purchase_price + selling_fee)
 *   vat_margin           = (int)(purchase_price × 0.09)
 *   total_margin         = (int)((sales_margin + vat_margin) × 0.9)
 *   settlement_amount    = ratio ? (int)(total_margin × ratio/100) : per_unit_amount
 *   document_fee         = ratio ? 50,000 : 0
 *   actual_payout        = settlement_amount - document_fee - other_deduction (+환차 2차 closed·ratio)
 *
 * 이 첫 케이스(KRW 사내직원)로 워크플로우 구동 + 금액 단언 접근을 검증한다.
 */
class E2eSettlementWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $requester;

    private Buyer $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['permission' => 'super', 'role' => '관리']);
        $this->requester = User::factory()->create(['permission' => 'user', 'role' => '재무']);
        $this->buyer = Buyer::create(['name' => 'E2E BUYER', 'is_active' => true]);
        $this->actingAs($this->admin);
    }

    /** 1차: pending→confirmed(직접) → 지급 승인 요청 → 승인·execute → paid (+2차 pending 자동). */
    private function confirmAndPay(Settlement $st): void
    {
        $st->update(['settlement_status' => 'confirmed']);

        $req = ApprovalRequest::create([
            'requester_id' => $this->requester->id,
            'action_type' => ApprovalRequest::TYPE_SETTLEMENT_PAY,
            'target_type' => Settlement::class,
            'target_id' => $st->id,
            'status' => ApprovalRequest::STATUS_PENDING,
            'reason' => 'E2E paid 요청',
        ]);
        $req->update(['status' => ApprovalRequest::STATUS_APPROVED, 'approver_id' => $this->admin->id, 'decided_at' => now()]);
        $req->execute();

        $st->refresh();
        $this->assertSame('paid', $st->settlement_status, 'paid 전환 실패');
        $this->assertSame('pending', $st->secondary_status, 'paid 후 2차 pending 자동전환 누락');
        $this->assertNotEmpty($st->confirmed_snapshot, 'paid 시점 스냅샷 누락');
    }

    /** 2차: closeSecondarySettlement (2026-07-06 재피벗 — 잔금 수령환율 vs 판매환율 차이 = 환차). */
    private function closeSecondary(Settlement $st): void
    {
        Volt::test('erp.settlements.index')->call('closeSecondarySettlement', $st->id);
        $st->refresh();
        $this->assertSame('closed', $st->secondary_status, '2차 closed 실패');
    }

    /** USD 3종(이익/손실/0) 공통 — 1차 금액 + 환차 + 2차 실지급액 손계산 대조. */
    private function runUsdCase(string $name, float $collectRate, int $expectedDiff, int $expectedPayout2nd): void
    {
        $s = $this->salesman($name, 'freelance');
        // 잔금을 $collectRate 에 수령 (판매환율 1300 과의 차이가 2차 환차).
        $v = $this->driveToTradeComplete($s, 'USD', 1300.0, ['purchase_price' => 15_000_000, 'sale_price' => 20_000], '', $collectRate);
        $st = Settlement::where('vehicle_id', $v->id)->firstOrFail();
        $this->assertSame('ratio', $st->settlement_type);

        // 1차 손계산 (3 케이스 공통):
        //  sales_amount_krw=20,000×1300=26,000,000 / sales_margin=26,000,000-15,000,000=11,000,000
        //  vat_margin=15,000,000×0.09=1,350,000 / total_margin=(11,000,000+1,350,000)×0.9=11,115,000
        //  settlement_amount=11,115,000×0.5=5,557,500 / actual_payout=5,557,500-50,000=5,507,500
        $this->assertSame(26_000_000, $st->sales_amount_krw, "$name sales_amount_krw");
        $this->assertSame(11_000_000, $st->sales_margin, "$name sales_margin");
        $this->assertSame(1_350_000, $st->vat_margin, "$name vat_margin");
        $this->assertSame(11_115_000, $st->total_margin, "$name total_margin");
        $this->assertSame(5_557_500, $st->settlement_amount, "$name settlement_amount");
        $this->assertSame(5_507_500, $st->actual_payout, "$name 1차 actual_payout");

        $this->confirmAndPay($st);
        $this->closeSecondary($st);

        $st->refresh();
        //  환차 = 20,000×collectRate(실수령KRW) − 20,000×1300(판매환율 baseline) / 2차 payout = 1차 + 환차
        $this->assertSame($expectedDiff, (int) $st->exchange_difference_krw, "$name 환차 불일치");
        $this->assertSame($expectedPayout2nd, $st->actual_payout, "$name 2차 실지급액 불일치");
    }

    private function salesman(string $name, string $type): Salesman
    {
        return Salesman::create(['name' => $name, 'type' => $type, 'is_active' => true]);
    }

    /**
     * 차량 1대를 매입→말소→판매입금(완납)→통관→선적→B/L→거래완료 까지 구동.
     * 각 단계 진행상태를 검증(누락 점검). 거래완료 시 정산 자동 생성.
     */
    private function driveToTradeComplete(Salesman $s, string $currency, float $rate, array $fin, string $tag = '', ?float $paymentRate = null): Vehicle
    {
        // 1) 매입 단계 — 차량 등록
        $v = Vehicle::create([
            'vehicle_number' => 'E2E-'.$s->id.$tag,
            'sales_channel' => 'export',
            'incoterms' => 'FOB',
            'salesman_id' => $s->id,
            'buyer_id' => $this->buyer->id,
            'purchase_date' => '2026-05-01',
            'purchase_price' => $fin['purchase_price'],
            'selling_fee' => $fin['selling_fee'] ?? 0,
            'cost_deregistration' => $fin['cost_deregistration'] ?? 0,
            'currency' => $currency,
            'exchange_rate' => $rate,
        ]);
        $this->assertContains($v->fresh()->progress_status, ['매입중', '매입완료'], '매입 단계 진입 실패');

        // 2) 말소 완료
        $v->update(['is_deregistered' => true, 'deregistration_document' => 'dereg/'.$v->id.'.pdf']);
        $this->assertSame('말소완료', $v->fresh()->progress_status, '말소완료 누락');

        // 3) 판매 입력 + 완납 (확정 FP = 판매총액)
        $v->update([
            'sale_date' => '2026-05-10',
            'sale_price' => $fin['sale_price'],
            'commission' => 0, 'auto_loading' => 0, 'tax_dc' => 0,
            'transport_fee' => 0, 'sale_other_costs' => 0,
        ]);
        $v->finalPayments()->create([
            'amount' => $fin['sale_price'],   // sale_total = sale_price (부대비용 0) → 완납
            'type' => 'balance',
            'payment_date' => '2026-05-10',
            'exchange_rate' => $paymentRate ?? $rate,   // 실제 수령 환율 (판매환율과 다르면 2차 환차 발생)
            'confirmed_at' => now(),
        ]);
        $v->refresh();
        $this->assertSame(0, (int) $v->sale_unpaid_amount, '판매 완납 안 됨');
        $this->assertSame('판매완료', $v->progress_status, '판매완료 누락');

        // 4) 통관 (말소 후 + 완납이라 C4·C5 통과)
        $v->update(['export_buyer_id' => $this->buyer->id, 'shipping_date' => '2026-05-15',
            'export_declaration_document' => 'exp/'.$v->id.'.pdf', 'is_export_cleared' => true]);

        // 5) 선적(반입)
        $v->update(['bl_loading_location' => '부산항']);
        $this->assertSame('통관중', $v->fresh()->progress_status, '통관/선적 단계 누락(v4 통관중 기대)');

        // 6) B/L 발급 (완납이라 G1 100% 통과) → 거래완료
        $v->update(['bl_document' => 'bl/'.$v->id.'.pdf']);
        $this->assertSame('거래완료', $v->fresh()->progress_status, '거래완료 누락');

        return $v->fresh();
    }

    public function test_krw_employee_settlement_amounts_exact(): void
    {
        $s = $this->salesman('S1 한화 사내', 'employee');
        $v = $this->driveToTradeComplete($s, 'KRW', 1.0, [
            'purchase_price' => 10_000_000,
            'selling_fee' => 1_000_000,
            'cost_deregistration' => 100_000,
            'sale_price' => 13_000_000,
        ]);

        $st = Settlement::where('vehicle_id', $v->id)->firstOrFail();

        // 자동 생성 검증 (누락 점검)
        $this->assertSame('per_unit', $st->settlement_type, '사내직원 → per_unit 자동분기 실패');
        $this->assertSame('pending', $st->settlement_status);

        // 손계산:
        //  sales_amount_krw = 13,000,000 × 1 = 13,000,000
        //  settlement_sales_krw = 13,000,000 - 100,000 = 12,900,000
        //  sales_margin = 12,900,000 - (10,000,000 + 1,000,000) = 1,900,000
        //  vat_margin = 10,000,000 × 0.09 = 900,000
        //  total_margin = (1,900,000 + 900,000) × 0.9 = 2,520,000
        //  per_unit 차등 tier(2026-06-22): 매입 1천만<1억, 총마진 252만≥100만 → 건당 20만
        //  actual_payout = 200,000 (서류비 0, 기타공제 0)
        $this->assertSame(13_000_000, $st->sales_amount_krw, 'sales_amount_krw 불일치');
        $this->assertSame(12_900_000, $st->settlement_sales_krw, 'settlement_sales_krw 불일치');
        $this->assertSame(1_900_000, $st->sales_margin, 'sales_margin 불일치');
        $this->assertSame(900_000, $st->vat_margin, 'vat_margin 불일치');
        $this->assertSame(2_520_000, $st->total_margin, 'total_margin 불일치');
        $this->assertSame(200_000, $st->settlement_amount, '사내직원 차등 정산액(총마진≥100만→20만) 불일치');
        $this->assertSame(0, $st->document_fee, '사내직원 서류비는 0이어야');
        $this->assertSame(200_000, $st->actual_payout, '실지급액 불일치');
    }

    public function test_eur_freelance_settlement_with_exchange_gain(): void
    {
        $s = $this->salesman('S2 유로 프리', 'freelance');
        $v = $this->driveToTradeComplete($s, 'EUR', 1400.0, [
            'purchase_price' => 8_000_000,
            'sale_price' => 10_000,
        ], '', 1450.0);   // 잔금 1450 에 수령 → 2차 환차익 10,000×(1450−1400)=+500,000
        $st = Settlement::where('vehicle_id', $v->id)->firstOrFail();
        $this->assertSame('ratio', $st->settlement_type, '프리랜서 → ratio 자동분기 실패');

        // 1차 손계산:
        //  sales_amount_krw=10,000×1400=14,000,000 / sales_margin=14,000,000-8,000,000=6,000,000
        //  vat_margin=8,000,000×0.09=720,000 / total_margin=(6,000,000+720,000)×0.9=6,048,000
        //  settlement_amount=6,048,000×0.5=3,024,000 / actual_payout=3,024,000-50,000=2,974,000
        $this->assertSame(14_000_000, $st->sales_amount_krw);
        $this->assertSame(6_000_000, $st->sales_margin);
        $this->assertSame(720_000, $st->vat_margin);
        $this->assertSame(6_048_000, $st->total_margin);
        $this->assertSame(3_024_000, $st->settlement_amount);
        $this->assertSame(50_000, $st->document_fee);
        $this->assertSame(2_974_000, $st->actual_payout, 'EUR 1차 실지급액 불일치');

        $this->confirmAndPay($st);
        $this->closeSecondary($st);   // 환차익: 10,000×1450(수령) − 10,000×1400(판매환율) = +500,000

        $st->refresh();
        $this->assertSame(500_000, (int) $st->exchange_difference_krw, 'EUR 환차익 불일치');
        $this->assertSame(3_474_000, $st->actual_payout, 'EUR 2차 실지급액(환차 반영) 불일치');
    }

    public function test_usd_exchange_gain(): void
    {
        $this->runUsdCase('S3 USD 이익', 1350.0, 1_000_000, 6_507_500);
    }

    public function test_usd_exchange_loss(): void
    {
        $this->runUsdCase('S4 USD 손실', 1250.0, -1_000_000, 4_507_500);
    }

    public function test_usd_exchange_zero(): void
    {
        $this->runUsdCase('S5 USD 동일', 1300.0, 0, 5_507_500);
    }

    /**
     * 환차익 이월 검증 (사용자 지적 2026-05-27) — 핵심.
     *
     * 사용자 모델: "1차 실지급 = 1차 정산금액(환차 없음). 환차는 다음달로 이월되어 +-".
     * 코드: 2차 closed 시 carryover_out_krw = 환차 → 같은 영업담당자의 다음 정산 carryover_in 으로 흡수.
     * → 환차는 2차에 또 지급되는 게 아니라 '다음 정산금에 +-'되어 한 번만 지급됨. 이중지급 없음.
     *
     * 같은 영업담당자 차량 2대(A: 환차 +500,000 발생 → B: 다음 정산)로 실측.
     */
    public function test_exchange_difference_carries_over_to_next_settlement(): void
    {
        $s = $this->salesman('S6 이월검증', 'freelance');

        // ── 차량 A: USD, 환차익 +500,000 (판매환율 1300 → 잔금 1350 수령, 10,000 USD) ──
        $vA = $this->driveToTradeComplete($s, 'USD', 1300.0,
            ['purchase_price' => 8_000_000, 'sale_price' => 10_000], 'A', 1350.0);
        $stA = Settlement::where('vehicle_id', $vA->id)->firstOrFail();

        // 1차 실지급(= 1차 정산금액, 환차 없음) 손계산:
        //  sales_amount_krw=10,000×1300=13,000,000 / sales_margin=13,000,000-8,000,000=5,000,000
        //  vat_margin=8,000,000×0.09=720,000 / total_margin=(5,000,000+720,000)×0.9=5,148,000
        //  settlement_amount=5,148,000×0.5=2,574,000 / 1차 actual_payout=2,574,000-50,000=2,524,000
        $payout1st = 2_524_000;
        $this->assertSame($payout1st, $stA->actual_payout, 'A 1차 실지급(환차 전) 불일치');

        $this->confirmAndPay($stA);
        // paid 스냅샷 = 1차 실지급액 (환차 없음) — '실제 지급한 금액' 박제
        $this->assertSame($payout1st, (int) ($stA->fresh()->confirmed_snapshot['actual_payout'] ?? -1),
            '1차 paid 스냅샷이 환차 없는 1차 정산금액이어야');

        $this->closeSecondary($stA);   // 환차 +500,000 (10,000×1350 − 10,000×1300)
        $stA->refresh();

        // 핵심 ①: 2차 closed 시 환차(+500,000)가 carryover_out 으로 '이월' 표시 (2차에 재지급 X)
        $this->assertSame(500_000, (int) $stA->exchange_difference_krw, 'A 환차 불일치');
        $this->assertSame(500_000, (int) $stA->carryover_out_krw, '환차가 carryover_out(다음달 이월)으로 안 잡힘');

        // ── 차량 B: 같은 영업담당자 다음 정산 — A의 환차가 carryover_in 으로 +- 되어야 ──
        $vB = $this->driveToTradeComplete($s, 'KRW', 1.0,
            ['purchase_price' => 10_000_000, 'sale_price' => 13_000_000], 'B');
        $stB = Settlement::where('vehicle_id', $vB->id)->firstOrFail();

        // 핵심 ②: A의 환차 +500,000 이 B의 carryover_in 으로 흡수 (다음달 이월 실현)
        $this->assertSame(500_000, (int) $stB->carryover_in_krw, 'A 환차가 B(다음 정산) carryover_in 으로 이월 안 됨');

        // B 1차 손계산(KRW): total_margin=(3,000,000+900,000)×0.9=3,510,000
        //  settlement_amount=3,510,000×0.5=1,755,000 / base=1,755,000-50,000=1,705,000
        //  + carryover_in(A 환차 500,000) → B actual_payout=2,205,000  ← 환차가 여기서 한 번 지급
        $this->assertSame(2_205_000, $stB->actual_payout, 'B 실지급에 A 환차 이월(+500,000)이 반영 안 됨');

        // 이중지급 검증: 환차 500,000 은 A 2차에 지급된 게 아니라 B 정산금에 1회만 +-.
        // (A 실제 지급 = 1차 2,524,000 / B 실제 지급 = 2,205,000 = B기본 1,705,000 + 이월 500,000)
    }
}
