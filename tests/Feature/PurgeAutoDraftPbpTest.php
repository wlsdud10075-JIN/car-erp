<?php

namespace Tests\Feature;

use App\Models\PurchaseBalancePayment;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurgeAutoDraftPbpTest extends TestCase
{
    use RefreshDatabase;

    private function pbp(Vehicle $v, array $attrs): PurchaseBalancePayment
    {
        PurchaseBalancePayment::$skipCreatingGuard = true;
        try {
            return $v->purchaseBalancePayments()->create(array_merge([
                'amount' => 1_000_000, 'type' => 'balance', 'payment_date' => '2026-06-01',
            ], $attrs));
        } finally {
            PurchaseBalancePayment::$skipCreatingGuard = false;
        }
    }

    public function test_purges_only_unconfirmed_auto_drafts(): void
    {
        $v = Vehicle::create([
            'vehicle_number' => 'PURGE-1', 'sales_channel' => 'export',
            'currency' => 'KRW', 'exchange_rate' => 1, 'purchase_price' => 5_000_000, 'dhl_request' => false,
        ]);

        $note = PurchaseBalancePayment::AUTO_DRAFT_NOTE;
        $unconfirmedDraft = $this->pbp($v, ['note' => $note, 'confirmed_at' => null, 'amount' => 5_000_000]);
        $confirmedDraft = $this->pbp($v, ['note' => $note, 'confirmed_at' => now(), 'amount' => 2_000_000]);
        $unrelated = $this->pbp($v, ['note' => '수기 잔금', 'confirmed_at' => null, 'amount' => 1_000_000]);

        $this->artisan('pbp:purge-auto-drafts --apply')->assertExitCode(0);

        // 미확정 자동 Draft 만 삭제
        $this->assertDatabaseMissing('purchase_balance_payments', ['id' => $unconfirmedDraft->id]);
        // 확정 자동 Draft(실지급) + 무관 PBP 는 보존
        $this->assertDatabaseHas('purchase_balance_payments', ['id' => $confirmedDraft->id]);
        $this->assertDatabaseHas('purchase_balance_payments', ['id' => $unrelated->id]);
    }

    public function test_dry_run_deletes_nothing(): void
    {
        $v = Vehicle::create([
            'vehicle_number' => 'PURGE-2', 'sales_channel' => 'export',
            'currency' => 'KRW', 'exchange_rate' => 1, 'purchase_price' => 5_000_000, 'dhl_request' => false,
        ]);
        $draft = $this->pbp($v, ['note' => PurchaseBalancePayment::AUTO_DRAFT_NOTE, 'confirmed_at' => null]);

        $this->artisan('pbp:purge-auto-drafts')->assertExitCode(0);

        $this->assertDatabaseHas('purchase_balance_payments', ['id' => $draft->id]);
    }
}
