<?php

namespace Tests\Feature;

use App\Models\BoardRequest;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * board↔erp 요청·확인 신호 C단계 — [입금요청] 자동 해소.
 *
 * 핵심 = **완료 버튼이 없다**(jin 2026-08-07: 누를 사람이 있으면 카톡으로 돌아간다).
 * 관리가 매입 지급을 기입해 미지급이 0 이 되는 순간 신호가 스스로 꺼져야 한다.
 *
 * ⚠️ [판매대금확인]은 자동으로 꺼지면 안 된다 — 부분입금이 흔해 기계 판정이 불가하고,
 *    "재무가 통장을 봤다"는 사람의 행위가 이 기능의 존재 이유다.
 */
class BoardRequestAutoResolveTest extends TestCase
{
    use RefreshDatabase;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function vehicle(int $purchasePrice = 1_000_000): Vehicle
    {
        return Vehicle::create([
            'vehicle_number' => 'BAR-'.++$this->counter,
            'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'dhl_request' => false,
            'purchase_date' => '2026-08-01', 'purchase_price' => $purchasePrice,
        ]);
    }

    public function test_request_closes_when_purchase_is_fully_paid(): void
    {
        $v = $this->vehicle(1_000_000);
        $req = BoardRequest::open($v->id, BoardRequest::TYPE_PURCHASE_PAYMENT, 'sales@ex.com');

        $this->assertSame(BoardRequest::STATUS_OPEN, $req->fresh()->status);

        // 관리가 매입 잔금 전액 지급 기입 → PBP::saved 가 부모 차량 저장을 태운다.
        $v->purchaseBalancePayments()->create([
            'amount' => 1_000_000, 'payment_date' => '2026-08-05', 'confirmed_at' => now(),
        ]);

        $req->refresh();
        $this->assertSame(BoardRequest::STATUS_DONE, $req->status, '미지급 0 인데 입금요청이 안 닫혔다');
        $this->assertNull($req->confirmed_by_id, '자동 해소는 사람이 누른 게 아니다');
        $this->assertNotNull($req->confirmed_at);
    }

    public function test_partial_payment_keeps_request_open(): void
    {
        $v = $this->vehicle(1_000_000);
        $req = BoardRequest::open($v->id, BoardRequest::TYPE_PURCHASE_PAYMENT, 'sales@ex.com');

        $v->purchaseBalancePayments()->create([
            'amount' => 400_000, 'payment_date' => '2026-08-05', 'confirmed_at' => now(),
        ]);

        $this->assertSame(BoardRequest::STATUS_OPEN, $req->fresh()->status, '부분지급인데 신호가 꺼졌다');
    }

    /** 판매대금확인은 매입과 무관 — 자동으로 꺼지면 재무가 통장을 안 보고 넘어간다. */
    public function test_sale_confirm_is_never_auto_resolved(): void
    {
        $v = $this->vehicle(1_000_000);
        $sale = BoardRequest::open($v->id, BoardRequest::TYPE_SALE_PAYMENT_CONFIRM, 'sales@ex.com');

        $v->purchaseBalancePayments()->create([
            'amount' => 1_000_000, 'payment_date' => '2026-08-05', 'confirmed_at' => now(),
        ]);

        $this->assertSame(
            BoardRequest::STATUS_OPEN,
            $sale->fresh()->status,
            '판매대금확인이 매입 지급으로 닫혔다 — 재무 확인 없이 회신된 셈이다'
        );
    }

    /** 이미 완납된 차에 요청이 들어오면, 다음 저장에서 즉시 닫힌다(유령 뱃지 방지). */
    public function test_request_on_already_paid_vehicle_closes_on_next_save(): void
    {
        $v = $this->vehicle(1_000_000);
        $v->purchaseBalancePayments()->create([
            'amount' => 1_000_000, 'payment_date' => '2026-08-05', 'confirmed_at' => now(),
        ]);

        $req = BoardRequest::open($v->id, BoardRequest::TYPE_PURCHASE_PAYMENT, 'sales@ex.com');
        $this->assertSame(BoardRequest::STATUS_OPEN, $req->fresh()->status);

        $v->fresh()->resolveOpenPurchasePaymentRequests();

        $this->assertSame(BoardRequest::STATUS_DONE, $req->fresh()->status);
    }
}
