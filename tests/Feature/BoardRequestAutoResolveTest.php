<?php

namespace Tests\Feature;

use App\Models\BoardRequest;
use App\Models\PurchaseBalancePayment;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\PaymentConfirmationService;
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
        $req = BoardRequest::raise($v->id, BoardRequest::TYPE_PURCHASE_PAYMENT, 'sales@ex.com');

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
        $req = BoardRequest::raise($v->id, BoardRequest::TYPE_PURCHASE_PAYMENT, 'sales@ex.com');

        $v->purchaseBalancePayments()->create([
            'amount' => 400_000, 'payment_date' => '2026-08-05', 'confirmed_at' => now(),
        ]);

        $this->assertSame(BoardRequest::STATUS_OPEN, $req->fresh()->status, '부분지급인데 신호가 꺼졌다');
    }

    /** 판매대금확인은 매입과 무관 — 자동으로 꺼지면 재무가 통장을 안 보고 넘어간다. */
    public function test_sale_confirm_is_never_auto_resolved(): void
    {
        $v = $this->vehicle(1_000_000);
        $sale = BoardRequest::raise($v->id, BoardRequest::TYPE_SALE_PAYMENT_CONFIRM, 'sales@ex.com');

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

        $req = BoardRequest::raise($v->id, BoardRequest::TYPE_PURCHASE_PAYMENT, 'sales@ex.com');
        $this->assertSame(BoardRequest::STATUS_OPEN, $req->fresh()->status);

        $v->fresh()->resolveAutoClosingBoardRequests();

        $this->assertSame(BoardRequest::STATUS_DONE, $req->fresh()->status);
    }

    /** 매입잔금 요청은 구 [입금요청]과 같은 조건으로 닫힌다 — 미지급 0. */
    public function test_purchase_balance_request_closes_when_fully_paid(): void
    {
        $v = $this->vehicle(1_000_000);
        $req = BoardRequest::raise($v->id, BoardRequest::TYPE_PURCHASE_BALANCE, 'sales@ex.com', amountKrw: 700_000);

        $v->purchaseBalancePayments()->create([
            'amount' => 1_000_000, 'payment_date' => '2026-08-05', 'confirmed_at' => now(),
        ]);

        $this->assertSame(BoardRequest::STATUS_DONE, $req->fresh()->status, '미지급 0 인데 매입잔금 요청이 안 닫혔다');
    }

    /**
     * 🔒 **계약금은 미지급 0 으로 닫히면 안 된다** (인계 §3, jin 2026-08-11).
     *
     * 미지급 0 = 잔금까지 다 준 상태다. 그때 계약금 신호가 비로소 꺼지면
     * "계약금 아직 안 보냈다"는 **거짓 신호**가 차 인수 시점까지 화면에 남는다.
     * ERP 는 계약금을 실제로 보냈는지 알 방법이 없다(금액을 회계에 안 쓰므로) → 수동 확인만.
     */
    public function test_deposit_request_survives_full_payment(): void
    {
        $v = $this->vehicle(1_000_000);
        $deposit = BoardRequest::raise($v->id, BoardRequest::TYPE_PURCHASE_DEPOSIT, 'sales@ex.com', amountKrw: 300_000);

        $v->purchaseBalancePayments()->create([
            'amount' => 1_000_000, 'payment_date' => '2026-08-05', 'confirmed_at' => now(),
        ]);

        $this->assertSame(
            BoardRequest::STATUS_OPEN,
            $deposit->fresh()->status,
            '계약금 요청이 매입 완납으로 닫혔다 — 사람이 확인하지 않았는데 신호가 사라진다'
        );
    }

    /**
     * 계약금이 열려 있어도 잔금 요청이 통과해야 한다 — 멱등키가 `(vehicle_id, type)` 이라
     * type 이 갈리는 것이 이 설계의 전제다(하위구분 필드였다면 여기서 조용히 skip 됐다).
     */
    public function test_deposit_and_balance_coexist_on_one_vehicle(): void
    {
        $v = $this->vehicle(1_000_000);

        $deposit = BoardRequest::raise($v->id, BoardRequest::TYPE_PURCHASE_DEPOSIT, 'sales@ex.com', amountKrw: 300_000);
        $balance = BoardRequest::raise($v->id, BoardRequest::TYPE_PURCHASE_BALANCE, 'sales@ex.com', amountKrw: 700_000);

        $this->assertNotNull($deposit);
        $this->assertNotNull($balance, '계약금이 열려 있다고 잔금 요청이 skip 됐다 — 잔금 요청이 도착하지 않는다');
        $this->assertSame(2, BoardRequest::query()->where('vehicle_id', $v->id)->open()->count());
    }

    /**
     * 🔒 **운영 경로** 검증 — 재무가 실제로 쓰는 흐름(신규 입력 + 재무 확정)에서도 닫히는가.
     *
     * 위 테스트들은 관계로 PBP 를 직접 만든다. 운영은 `transfers::createNewPbp` 가
     * `PurchaseBalancePayment::create()` 후 `PaymentConfirmationService::confirmPurchasePayment()`
     * 로 확정하고, **미지급은 확정 시점에야 줄어든다**. 그 경로가 훅을 안 타면
     * 테스트만 초록이고 운영에선 뱃지가 영영 안 꺼진다(완료 버튼이 없으니 탈출구도 없다).
     */
    public function test_closes_through_real_finance_confirmation_path(): void
    {
        $finance = User::factory()->create([
            'permission' => 'user', 'role' => '재무', 'email_verified_at' => now(),
        ]);
        $v = $this->vehicle(1_000_000);
        $req = BoardRequest::raise($v->id, BoardRequest::TYPE_PURCHASE_PAYMENT, 'sales@ex.com');

        // ① 재무가 지급 행을 만든다 — 아직 미확정이라 미지급은 그대로다.
        $pbp = PurchaseBalancePayment::create([
            'vehicle_id' => $v->id, 'amount' => 1_000_000, 'payment_date' => '2026-08-05',
        ]);
        $this->assertSame(BoardRequest::STATUS_OPEN, $req->fresh()->status, '미확정 지급인데 신호가 꺼졌다');

        // ② 재무 확정 → 미지급 0 → 신호 자동 소멸
        app(PaymentConfirmationService::class)->confirmPurchasePayment($pbp, $finance);

        $this->assertSame(
            BoardRequest::STATUS_DONE,
            $req->fresh()->status,
            '재무 확정 경로가 자동 해소를 안 태웠다 — 운영에서 뱃지가 안 꺼진다'
        );
    }
}
