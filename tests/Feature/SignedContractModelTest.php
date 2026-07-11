<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\SignedContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 판매계약서 전자서명 Phase 1 — SignedContract 모델·캐스트·하드삭제 가드.
 * 회의록 docs/meetings/2026-07-10-sales-contract-e-signature.md
 */
class SignedContractModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function make(array $overrides = []): SignedContract
    {
        $buyer = Buyer::create(['name' => 'ABC TRADING', 'is_active' => true, 'country_id' => null]);

        return SignedContract::create(array_merge([
            'buyer_id' => $buyer->id,
            'vehicle_ids' => [1215, 1216],
            'contract_no' => 'SC2607-01215',
            'currency' => 'USD',
            'snapshot_path' => 'signed-contracts/snap.xlsx',
            'source_hash' => str_repeat('a', 64),
            'snapshot_data' => ['buyer' => 'ABC TRADING', 'vehicle_count' => 2],
            'status' => SignedContract::STATUS_PENDING,
            'sign_token' => bin2hex(random_bytes(16)),
            'token_expires_at' => now()->addDays(7),
            'recipient_email' => 'buyer@example.com',
        ], $overrides));
    }

    public function test_casts_json_and_dates(): void
    {
        $c = $this->make()->fresh();

        $this->assertSame([1215, 1216], $c->vehicle_ids);
        $this->assertSame('ABC TRADING', $c->snapshot_data['buyer']);
        $this->assertTrue($c->token_expires_at->isFuture());
    }

    public function test_signed_row_cannot_be_hard_deleted(): void
    {
        $c = $this->make(['status' => SignedContract::STATUS_SIGNED, 'signed_at' => now()]);

        $this->expectException(\DomainException::class);
        $c->delete();
    }

    public function test_unsigned_sessions_are_deletable(): void
    {
        foreach ([SignedContract::STATUS_PENDING, SignedContract::STATUS_VIEWED, SignedContract::STATUS_REVOKED] as $status) {
            $c = $this->make(['status' => $status]);
            $c->delete();
            $this->assertModelMissing($c);
        }
    }

    public function test_signable_only_when_active_and_unexpired(): void
    {
        $this->assertTrue($this->make(['status' => SignedContract::STATUS_PENDING])->isSignable());
        $this->assertTrue($this->make(['status' => SignedContract::STATUS_VIEWED])->isSignable());
        $this->assertFalse($this->make(['status' => SignedContract::STATUS_SIGNED, 'signed_at' => now()])->isSignable());
        $this->assertFalse($this->make(['status' => SignedContract::STATUS_REVOKED, 'revoked_at' => now()])->isSignable());
        $this->assertFalse($this->make(['status' => SignedContract::STATUS_PENDING, 'token_expires_at' => now()->subDay()])->isSignable());
    }
}
