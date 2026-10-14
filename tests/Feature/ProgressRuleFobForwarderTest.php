<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\FinalPayment;
use App\Models\ForwardingCompany;
use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 🚢 FOB + 「FOB포워더」(바이어가 직접 고른 포워딩사, B/L 을 바이어가 발급) + 판매 완납 ⇒ 거래완료 (jin 2026-10-14).
 *
 * 우리가 운임을 안 내고 B/L 도 바이어가 뽑으니 ERP 에 B/L 파일이 올 일이 없다 — 종전 규칙으로는 영영 판매완료.
 * 판정은 포워딩사 플래그(`buyer_handles_bl`)로, 이름이 아니다. 순수 확대: 플래그 없는 차는 한 글자도 안 바뀐다.
 */
class ProgressRuleFobForwarderTest extends TestCase
{
    use RefreshDatabase;

    private function vehicle(array $over = [], bool $paid = true): Vehicle
    {
        $buyer = Buyer::firstOrCreate(['name' => 'FOB BUYER'], ['is_active' => true]);
        $v = Vehicle::create(array_merge([
            'vehicle_number' => 'FOB-'.fake()->unique()->numberBetween(1000, 9999),
            'sales_channel' => 'export', 'currency' => 'USD', 'exchange_rate' => 1400,
            'buyer_id' => $buyer->id, 'sale_date' => '2026-10-01', 'sale_price' => 10000,
            'purchase_price' => 1_000_000, 'purchase_date' => '2026-09-01',
        ], $over));
        if ($paid) {
            FinalPayment::create([
                'vehicle_id' => $v->id, 'amount' => 10000, 'type' => 'balance',
                'payment_date' => '2026-10-05', 'exchange_rate' => 1400, 'confirmed_at' => now(),
            ]);
        }

        return $v->fresh();
    }

    private function fobForwarder(): ForwardingCompany
    {
        return ForwardingCompany::create(['name' => 'FOB포워더', 'is_active' => true, 'buyer_handles_bl' => true]);
    }

    public function test_fob_with_buyer_forwarder_and_full_payment_is_completed(): void
    {
        $v = $this->vehicle(['incoterms' => 'FOB', 'forwarding_company_id' => $this->fobForwarder()->id]);

        $this->assertSame('거래완료', $v->progress_status);
        $this->assertSame('거래완료', $v->progress_status_cache, '캐시도 같이 간다(목록·대시보드가 읽는 값)');
    }

    public function test_each_condition_alone_is_not_enough(): void
    {
        $fob = $this->fobForwarder();
        $plain = ForwardingCompany::create(['name' => 'CIG', 'is_active' => true]);

        $this->assertSame('판매완료', $this->vehicle(['incoterms' => 'CFR', 'forwarding_company_id' => $fob->id])->progress_status, 'CFR 은 그대로');
        $this->assertSame('판매완료', $this->vehicle(['incoterms' => 'FOB', 'forwarding_company_id' => $plain->id])->progress_status, '일반 포워딩사는 그대로(이름이 아니라 플래그)');
        $this->assertSame('판매완료', $this->vehicle(['incoterms' => 'FOB'])->progress_status, '포워딩사 없음');
        $this->assertSame('판매중', $this->vehicle(['incoterms' => 'FOB', 'forwarding_company_id' => $fob->id], paid: false)->progress_status, '미완납');
    }

    public function test_rule_wins_over_loading_location_but_bl_document_still_closes_anyone(): void
    {
        $fob = $this->fobForwarder();
        // 반입지를 적어 둔 FOB포워더 차 — B/L 경로가 없으니 완납이면 끝난 거래
        $this->assertSame('거래완료', $this->vehicle(['incoterms' => 'FOB', 'forwarding_company_id' => $fob->id, 'bl_loading_location' => '평택'])->progress_status);
        // 종전 규칙은 그대로 — B/L 파일이면 누구든 거래완료
        $this->assertSame('거래완료', $this->vehicle(['incoterms' => 'CFR', 'bl_document' => 'bl.pdf'], paid: false)->progress_status);
    }

    public function test_completion_creates_the_pending_settlement(): void
    {
        $this->actingAs(User::factory()->create(['permission' => 'admin', 'role' => '관리']));
        $sm = Salesman::create(['name' => '김영업', 'type' => 'employee', 'is_active' => true]);
        $fob = $this->fobForwarder();
        $v = $this->vehicle(['incoterms' => 'CFR', 'forwarding_company_id' => $fob->id, 'salesman_id' => $sm->id, 'transport_fee' => 0]);
        $this->assertSame(0, Settlement::where('vehicle_id', $v->id)->count(), 'CFR+운임 0 = 운임 미확정이라 정산 대기');

        $v->update(['incoterms' => 'FOB']);   // 실무자가 FOB 로 바꾸는 순간

        $this->assertSame('거래완료', $v->fresh()->progress_status_cache);
        $this->assertSame(1, Settlement::where('vehicle_id', $v->id)->count(), '거래완료 진입 + FOB(운임 확정) → 정산 자동 생성');
    }

    public function test_forwarding_screen_saves_the_flag_and_the_memo_follows(): void
    {
        $this->actingAs(User::factory()->create(['permission' => 'admin', 'role' => '관리']));
        $fc = ForwardingCompany::create(['name' => '바이어지정', 'is_active' => true]);
        $this->assertSame([], ForwardingCompany::buyerHandledIds());

        Volt::test('erp.forwarding-companies.index')->call('openEdit', $fc->id)->set('buyer_handles_bl', true)->call('save')->assertHasNoErrors();

        $this->assertTrue($fc->fresh()->buyer_handles_bl);
        $this->assertSame([$fc->id], ForwardingCompany::buyerHandledIds(), '저장하면 요청 단위 메모가 비워져 바로 반영');
    }

    /** 순수 확대 — 플래그 켜진 포워딩사가 없으면 어떤 차도 안 움직인다(실측 10-14: 3사 FOB포워더 차량 0대 → 배포 직후 변화 0). */
    public function test_without_a_flagged_forwarder_nothing_changes(): void
    {
        $plain = ForwardingCompany::create(['name' => 'CIG', 'is_active' => true]);
        $cases = [
            [['incoterms' => 'FOB', 'forwarding_company_id' => $plain->id], true, '판매완료'],
            [['incoterms' => 'FOB', 'forwarding_company_id' => $plain->id, 'bl_loading_location' => '평택'], true, '선적중'],
            [['incoterms' => 'FOB'], false, '판매중'],
        ];
        foreach ($cases as [$over, $paid, $expected]) {
            $this->assertSame($expected, $this->vehicle($over, $paid)->progress_status);
        }
    }
}
