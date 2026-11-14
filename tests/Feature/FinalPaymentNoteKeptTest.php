<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\FinalPayment;
use App\Models\ReceivableHistory;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 판매 잔금 비고가 「회수: 판매 잔금 자동 미러링」으로 덮이던 문제 (jin 2026-11-14, 씬 워크스루 중 발견).
 *
 * 잔금 줄을 만들면 회수 줄이 거꾸로 미러되고(FinalPayment::created), 그 회수 줄의 saved 훅이
 * 잔금으로 다시 미러하며 비고를 「회수: 판매 잔금 자동 미러링」으로 썼다 — 실무자가 적은 비고가
 * 저장 직후 사라졌다(3사 10월 이후 1,409줄).
 */
class FinalPaymentNoteKeptTest extends TestCase
{
    use RefreshDatabase;

    private function vehicle(): Vehicle
    {
        return Vehicle::create([
            'vehicle_number' => 'NOTE'.random_int(1000, 9999),
            'sales_channel' => 'export',
            'currency' => 'USD',
            'exchange_rate' => 1400,
            'sale_price' => 9000,
            'sale_date' => '2026-11-13',
            'buyer_id' => Buyer::create(['name' => 'B'.random_int(1000, 9999), 'is_active' => true])->id,
        ]);
    }

    public function test_note_typed_on_a_final_payment_survives_the_mirror(): void
    {
        $v = $this->vehicle();
        $fp = FinalPayment::create([
            'vehicle_id' => $v->id, 'type' => 'balance', 'amount' => 3000,
            'payment_date' => '2026-11-13', 'note' => '1차 송금분',
        ]);

        $this->assertSame('1차 송금분', $fp->fresh()->note);
        $this->assertSame(1, ReceivableHistory::where('final_payment_id', $fp->id)->count(), '회수 줄 미러는 그대로 생긴다');
    }

    public function test_editing_amount_in_receivables_keeps_the_payment_note(): void
    {
        $v = $this->vehicle();
        $fp = FinalPayment::create([
            'vehicle_id' => $v->id, 'type' => 'balance', 'amount' => 3000,
            'payment_date' => '2026-11-13', 'note' => '1차 송금분',
        ]);

        $h = ReceivableHistory::where('final_payment_id', $fp->id)->firstOrFail();
        $h->update(['amount' => 3100]);

        $fresh = $fp->fresh();
        $this->assertEquals(3100, (float) $fresh->amount, '금액은 종전대로 잔금으로 넘어간다');
        $this->assertSame('1차 송금분', $fresh->note, '금액만 고쳤으면 비고는 그대로');
    }

    public function test_editing_the_note_in_receivables_still_flows_to_the_payment(): void
    {
        $v = $this->vehicle();
        $fp = FinalPayment::create([
            'vehicle_id' => $v->id, 'type' => 'balance', 'amount' => 3000, 'payment_date' => '2026-11-13',
        ]);

        ReceivableHistory::where('final_payment_id', $fp->id)->firstOrFail()->update(['note' => '바이어 확인']);

        $this->assertSame('회수: 바이어 확인', $fp->fresh()->note);
    }

    public function test_editing_a_just_created_deposit_in_the_same_request_still_flows(): void
    {
        $v = $this->vehicle();
        $h = ReceivableHistory::create([
            'vehicle_id' => $v->id, 'method' => 'deposit', 'amount' => 500, 'collected_at' => '2026-11-13',
        ]);
        $h->update(['amount' => 700]);   // 같은 인스턴스 — wasRecentlyCreated 가 아직 true

        $this->assertEquals(700, (float) FinalPayment::findOrFail($h->final_payment_id)->amount);
    }

    public function test_deposit_created_in_receivables_still_creates_the_payment_with_note(): void
    {
        $v = $this->vehicle();
        $h = ReceivableHistory::create([
            'vehicle_id' => $v->id, 'method' => 'deposit', 'amount' => 500,
            'collected_at' => '2026-11-13', 'note' => '현장 수금',
        ]);

        $this->assertSame('회수: 현장 수금', FinalPayment::findOrFail($h->fresh()->final_payment_id)->note);
    }
}
