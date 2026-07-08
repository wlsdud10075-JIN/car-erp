<?php

namespace Tests\Feature;

use App\Models\Salesman;
use App\Models\Settlement;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * settlements:backfill-missing — A-3 방식 개편 검증 (2026-07-08).
 *
 * 갭: A-3 정산 트리거(FinalPayment::saved 완납 감지)는 배포 후 완납만 잡음 → 배포 전 완납/거래완료는
 *   정산 누락. 백필이 완납월(--month) 스코프로 판매완료/거래완료+완납+담당자 있음+정산없음 차량에만
 *   pending 정산을 attributed_month 고정으로 멱등 생성하는지.
 */
class BackfillMissingSettlementsTest extends TestCase
{
    use RefreshDatabase;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    /**
     * auth 없이(=CLI/import 모사) 완납 차량 생성 → 자동 정산 안 생김.
     * 완납월 = 확정 잔금(payment_date)의 달. bl 지정 시 거래완료, 없으면 판매완료.
     */
    private function paidNoAuth(?int $salesmanId, string $paymentDate, bool $withBl = false): Vehicle
    {
        $v = Vehicle::create([
            'vehicle_number' => 'BF-'.++$this->counter,
            'sales_channel' => 'export', 'currency' => 'KRW', 'exchange_rate' => 1,
            'dhl_request' => false,
            'sale_price' => 400, 'sale_date' => '2026-03-01', 'purchase_date' => '2026-01-01',
            'salesman_id' => $salesmanId,
            'bl_document' => $withBl ? 'bl/doc-'.$this->counter.'.pdf' : null,
        ]);
        $v->finalPayments()->create([
            'amount' => 400, 'type' => 'balance', 'payment_date' => $paymentDate, 'confirmed_at' => now(),
        ]);
        $v->refresh();

        return $v;
    }

    public function test_backfills_only_paid_with_salesman_in_target_month(): void
    {
        $freelancer = Salesman::create(['name' => '프리', 'type' => 'freelance']);
        $employee = Salesman::create(['name' => '사내', 'type' => 'employee']);

        $a = $this->paidNoAuth($freelancer->id, '2026-07-05');            // 판매완료·완납월 7월
        $b = $this->paidNoAuth($employee->id, '2026-07-09', withBl: true); // 거래완료·완납월 7월
        $noSalesman = $this->paidNoAuth(null, '2026-07-05');              // 담당자 없음 → 백필 불가
        $otherMonth = $this->paidNoAuth($freelancer->id, '2026-05-05');   // 완납월 5월 → 대상 아님

        // 무인증 생성이라 자동 정산 0건.
        $this->assertSame(0, Settlement::count());

        $this->artisan('settlements:backfill-missing', ['--month' => '2026-07', '--force' => true])
            ->assertSuccessful();

        // 7월 완납 + 담당자 있는 2대만 백필.
        $this->assertSame(2, Settlement::count());
        $this->assertSame('ratio', Settlement::where('vehicle_id', $a->id)->value('settlement_type'));
        $this->assertSame('per_unit', Settlement::where('vehicle_id', $b->id)->value('settlement_type'));
        $this->assertSame('pending', Settlement::where('vehicle_id', $a->id)->value('settlement_status'));
        // 귀속월 = 완납월(7월) 1일 고정.
        $this->assertSame('2026-07-01', Settlement::where('vehicle_id', $a->id)->first()->attributed_month->format('Y-m-d'));
        // 담당자 없음 / 다른 완납월은 백필 안 됨.
        $this->assertSame(0, Settlement::where('vehicle_id', $noSalesman->id)->count());
        $this->assertSame(0, Settlement::where('vehicle_id', $otherMonth->id)->count());
    }

    public function test_idempotent_no_duplicate_on_rerun(): void
    {
        $sm = Salesman::create(['name' => '프리2', 'type' => 'freelance']);
        $this->paidNoAuth($sm->id, '2026-07-05');

        $this->artisan('settlements:backfill-missing', ['--month' => '2026-07', '--force' => true])->assertSuccessful();
        $this->artisan('settlements:backfill-missing', ['--month' => '2026-07', '--force' => true])->assertSuccessful();

        $this->assertSame(1, Settlement::count());
    }

    public function test_dry_run_creates_nothing(): void
    {
        $sm = Salesman::create(['name' => '프리3', 'type' => 'freelance']);
        $this->paidNoAuth($sm->id, '2026-07-05');

        $this->artisan('settlements:backfill-missing', ['--month' => '2026-07'])->assertSuccessful();

        $this->assertSame(0, Settlement::count());
    }

    public function test_force_without_month_is_refused(): void
    {
        $sm = Salesman::create(['name' => '프리4', 'type' => 'freelance']);
        $this->paidNoAuth($sm->id, '2026-07-05');

        $this->artisan('settlements:backfill-missing', ['--force' => true])->assertFailed();

        $this->assertSame(0, Settlement::count(), '완납월 미지정 --force 는 아무것도 생성하지 않음');
    }

    public function test_partial_payment_not_backfilled(): void
    {
        $sm = Salesman::create(['name' => '프리5', 'type' => 'freelance']);
        // 부분입금(미완납) — 완납 아님 → 대상 아님.
        $v = Vehicle::create([
            'vehicle_number' => 'BF-P'.++$this->counter, 'sales_channel' => 'export',
            'currency' => 'KRW', 'exchange_rate' => 1, 'dhl_request' => false,
            'sale_price' => 400, 'sale_date' => '2026-03-01', 'salesman_id' => $sm->id,
        ]);
        $v->finalPayments()->create([
            'amount' => 100, 'type' => 'balance', 'payment_date' => '2026-07-05', 'confirmed_at' => now(),
        ]);

        $this->artisan('settlements:backfill-missing', ['--month' => '2026-07', '--force' => true])->assertSuccessful();

        $this->assertSame(0, Settlement::count());
    }
}
