<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Buyer;
use App\Models\FinalPayment;
use App\Models\PurchaseBalancePayment;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\VehicleLedgerUnlockService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * 큐 21 — 차량 본체 Ledger 영향 필드 잠금 테스트.
 * 회의록 docs/meetings/2026-05-18-vehicle-ledger-field-lock.md
 *
 * 검증 범위:
 * - 트리거 옵션 A: confirmed FinalPayment OR PurchaseBalancePayment 1건 이상 → 잠금
 * - 잠금 컬럼 (LEDGER_LOCK_FIELDS 21개) 변경 시도 시 ValidationException
 * - VehicleLedgerUnlockService: admin/super 권한 + 사유 10자 + cache 토큰 1회 소비
 * - 저장 1회 후 자동 재잠금 (cache pull 패턴)
 * - confirmed 잔금 차량 일반 user soft-delete 차단 / admin 우회
 * - AuditLog ledger_field_unlocked 기록
 */
class VehicleLedgerLockTest extends TestCase
{
    use RefreshDatabase;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function makeVehicle(array $overrides = []): Vehicle
    {
        $this->counter++;

        $defaults = [
            'vehicle_number' => 'LLT-'.$this->counter,
            'sales_channel' => 'export',
            'currency' => 'KRW',
            'exchange_rate' => 1,
            'purchase_price' => 1000000,
            'dhl_request' => false,
        ];

        // 2026-05-19 풀회의 안건 E — sale_price > 0 시 sale_date·buyer_id 자동 채움.
        if (($overrides['sale_price'] ?? 0) > 0) {
            if (! array_key_exists('buyer_id', $overrides)) {
                $defaults['buyer_id'] = Buyer::firstOrCreate(['name' => 'TEST BUYER'], ['is_active' => true])->id;
            }
            if (! array_key_exists('sale_date', $overrides)) {
                $defaults['sale_date'] = '2026-05-01';
            }
        }

        // 큐 22-A-3 (2026-05-20) — vehicles 4컬럼 DROP. override 키가 있으면 confirmed FP 자동 생성.
        $sale4Map = [
            'deposit_down_payment' => 'deposit_down',
            'interim_payment' => 'interim',
            'advance_payment1' => 'advance_1',
            'advance_payment2' => 'advance_2',
        ];
        $sale4Inserts = [];
        foreach ($sale4Map as $col => $type) {
            if (array_key_exists($col, $overrides)) {
                if ((float) $overrides[$col] > 0) {
                    $sale4Inserts[] = ['amount' => (float) $overrides[$col], 'type' => $type];
                }
                unset($overrides[$col]);
            }
        }

        $v = Vehicle::create(array_merge($defaults, $overrides));

        foreach ($sale4Inserts as $row) {
            $v->finalPayments()->create([
                'amount' => $row['amount'],
                'type' => $row['type'],
                'confirmed_at' => now(),
            ]);
        }
        if (! empty($sale4Inserts)) {
            $v->refresh();
        }

        return $v;
    }

    private function makeConfirmedFinalPayment(Vehicle $v, int $amount = 500000): FinalPayment
    {
        return FinalPayment::create([
            'vehicle_id' => $v->id,
            'amount' => $amount,
            'payment_date' => '2026-05-01',
            'confirmed_at' => now(),
            'confirmed_by_user_id' => User::factory()->create(['permission' => 'super'])->id,
        ]);
    }

    // ── 잠금 발동 케이스 ───────────────────────────────────────────

    public function test_confirmed_final_payment_locks_ledger_fields(): void
    {
        $admin = User::factory()->create(['permission' => 'admin']);
        $v = $this->makeVehicle();
        $this->makeConfirmedFinalPayment($v);
        $this->actingAs($admin);

        $this->assertTrue($v->fresh()->hasConfirmedPaymentLock());

        $v2 = Vehicle::find($v->id);
        $v2->purchase_price = 2000000;

        $this->expectException(ValidationException::class);
        $v2->save();
    }

    public function test_confirmed_purchase_balance_payment_also_locks(): void
    {
        $admin = User::factory()->create(['permission' => 'admin']);
        // sale_price 초기값 set — 변경 시도 시 lock 발동 검증 (빈값→첫입력은 통과 정책 회피)
        $v = $this->makeVehicle(['sale_price' => 1000000]);
        PurchaseBalancePayment::create([
            'vehicle_id' => $v->id,
            'amount' => 300000,
            'payment_date' => '2026-05-01',
            'confirmed_at' => now(),
            'confirmed_by_user_id' => $admin->id,
        ]);
        $this->actingAs($admin);

        $v2 = Vehicle::find($v->id);
        $v2->sale_price = 2000000;   // 1000000 → 2000000 변경

        $this->expectException(ValidationException::class);
        $v2->save();
    }

    public function test_empty_to_first_input_passes_even_when_locked(): void
    {
        // 운영 정상 흐름: 매입 잔금 confirmed 후 영업이 판매 정보 처음 입력 (sale_price 0 → 1000만, buyer_id null → ID).
        // 회의 의도(retroactive 변경 차단)와 운영 현실 사이 정정 (사용자 검증 2026-05-18).
        $admin = User::factory()->create(['permission' => 'admin']);
        $v = $this->makeVehicle();   // sale_price 미설정 (0/null)
        PurchaseBalancePayment::create([
            'vehicle_id' => $v->id,
            'amount' => 300000,
            'payment_date' => '2026-05-01',
            'confirmed_at' => now(),
            'confirmed_by_user_id' => $admin->id,
        ]);
        $this->actingAs($admin);

        $v2 = Vehicle::find($v->id);
        $v2->sale_price = 1000000;   // 0/null → 1000000 (최초 입력)
        $v2->save();   // 통과 — 빈 값에서 첫 set은 retroactive 변경 아님

        $this->assertSame(1000000, (int) $v2->fresh()->sale_price);
    }

    public function test_unconfirmed_payments_do_not_lock(): void
    {
        // 영업이 잔금 입력만 한 상태 (confirmed_at = null) → 잠금 발동 안 함
        $admin = User::factory()->create(['permission' => 'admin']);
        $v = $this->makeVehicle();
        FinalPayment::create([
            'vehicle_id' => $v->id,
            'amount' => 500000,
            'payment_date' => '2026-05-01',
            // confirmed_at 없음
        ]);
        $this->actingAs($admin);

        $v2 = Vehicle::find($v->id);
        $v2->purchase_price = 2000000;
        $v2->save();   // 통과 — 예외 없음

        $this->assertSame(2000000, (int) $v2->fresh()->purchase_price);
    }

    public function test_unlock_token_allows_one_save_then_relocks(): void
    {
        $admin = User::factory()->create(['permission' => 'admin']);
        $v = $this->makeVehicle();
        $this->makeConfirmedFinalPayment($v);
        $this->actingAs($admin);

        // unlock 토큰 발급
        app(VehicleLedgerUnlockService::class)->unlock(
            $v,
            $admin,
            '영업 매입가 오기 정정 — 5,000,000 → 50,000,000'
        );

        // 1회 저장 통과
        $v2 = Vehicle::find($v->id);
        $v2->purchase_price = 5000000;
        $v2->save();
        $this->assertSame(5000000, (int) $v2->fresh()->purchase_price);

        // 다음 저장은 토큰 소비됨 → 다시 차단
        $v3 = Vehicle::find($v->id);
        $v3->purchase_price = 9999999;
        $this->expectException(ValidationException::class);
        $v3->save();
    }

    // ── VehicleLedgerUnlockService 권한·사유 검증 ─────────────────────

    public function test_unlock_requires_reason_minimum_10_chars(): void
    {
        $admin = User::factory()->create(['permission' => 'admin']);
        $v = $this->makeVehicle();
        $this->makeConfirmedFinalPayment($v);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('10자 이상');
        app(VehicleLedgerUnlockService::class)->unlock($v, $admin, '짧음');
    }

    public function test_unlock_rejects_non_admin_user(): void
    {
        $sales = User::factory()->create(['permission' => 'user', 'role' => '영업']);
        $v = $this->makeVehicle();
        $this->makeConfirmedFinalPayment($v);

        $this->expectException(AuthorizationException::class);
        app(VehicleLedgerUnlockService::class)->unlock(
            $v,
            $sales,
            '영업 정정 시도 — 잘못된 입력 수정'
        );
    }

    public function test_unlock_accepts_admin_and_super(): void
    {
        $admin = User::factory()->create(['permission' => 'admin']);
        $super = User::factory()->create(['permission' => 'super']);

        $v1 = $this->makeVehicle();
        $this->makeConfirmedFinalPayment($v1);
        app(VehicleLedgerUnlockService::class)->unlock(
            $v1,
            $admin,
            'admin 정정 — 매입가 오타 50만 → 500만 수정'
        );
        $this->assertTrue(Cache::has(Vehicle::ledgerUnlockCacheKey($v1->id)));

        $v2 = $this->makeVehicle();
        $this->makeConfirmedFinalPayment($v2);
        app(VehicleLedgerUnlockService::class)->unlock(
            $v2,
            $super,
            'super 정정 — 환율 오타 1300 → 1340 수정'
        );
        $this->assertTrue(Cache::has(Vehicle::ledgerUnlockCacheKey($v2->id)));
    }

    public function test_unlock_creates_audit_log_with_reason(): void
    {
        $admin = User::factory()->create(['permission' => 'admin']);
        $v = $this->makeVehicle();
        $this->makeConfirmedFinalPayment($v);

        $reason = '운영 사고 복구 — 매입가 오기 5,000,000 → 50,000,000 정정';
        app(VehicleLedgerUnlockService::class)->unlock($v, $admin, $reason);

        $log = AuditLog::where('auditable_type', Vehicle::class)
            ->where('auditable_id', $v->id)
            ->where('action', 'ledger_field_unlocked')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame('unlock_reason', $log->column_name);
        $this->assertSame($reason, $log->new_value);
    }

    // ── soft-delete 가드 ───────────────────────────────────────────

    public function test_normal_user_cannot_soft_delete_confirmed_vehicle(): void
    {
        $sales = User::factory()->create(['permission' => 'user', 'role' => '영업']);
        $v = $this->makeVehicle();
        $this->makeConfirmedFinalPayment($v);
        $this->actingAs($sales);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('admin/super만 삭제');
        $v->delete();
    }

    public function test_admin_can_soft_delete_confirmed_vehicle(): void
    {
        $admin = User::factory()->create(['permission' => 'admin']);
        $v = $this->makeVehicle();
        $this->makeConfirmedFinalPayment($v);
        $this->actingAs($admin);

        $v->delete();
        $this->assertSoftDeleted($v);
    }

    // ── buyer_id 잠금 (Tier 2 admin 우회 정책 검증) ─────────────────

    public function test_buyer_id_change_blocked_without_unlock(): void
    {
        $admin = User::factory()->create(['permission' => 'admin']);
        $buyerA = Buyer::create(['name' => 'Buyer A', 'is_active' => true]);
        $buyerB = Buyer::create(['name' => 'Buyer B', 'is_active' => true]);
        $v = $this->makeVehicle(['buyer_id' => $buyerA->id]);
        $this->makeConfirmedFinalPayment($v);
        $this->actingAs($admin);

        $v2 = Vehicle::find($v->id);
        $v2->buyer_id = $buyerB->id;
        $this->expectException(ValidationException::class);
        $v2->save();
    }

    public function test_buyer_id_change_allowed_after_admin_unlock(): void
    {
        $admin = User::factory()->create(['permission' => 'admin']);
        $buyerA = Buyer::create(['name' => 'Buyer A', 'is_active' => true]);
        $buyerB = Buyer::create(['name' => 'Buyer B', 'is_active' => true]);
        $v = $this->makeVehicle(['buyer_id' => $buyerA->id]);
        $this->makeConfirmedFinalPayment($v);
        $this->actingAs($admin);

        app(VehicleLedgerUnlockService::class)->unlock(
            $v,
            $admin,
            'buyer 오기 정정 — Buyer A에서 Buyer B로 교체 (현장 확인 완료)'
        );

        $v2 = Vehicle::find($v->id);
        $v2->buyer_id = $buyerB->id;
        $v2->save();
        $this->assertSame($buyerB->id, (int) $v2->fresh()->buyer_id);
    }
}
