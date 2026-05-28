<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\PurchaseBalancePayment;
use App\Models\UnpaidExportOverride;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * G1 B/L 100% 발급 게이트 테스트 (2026-05-26 외부리뷰 감사 회의 결정).
 * 회의록 docs/meetings/2026-05-26-external-review-audit.md §사용자결정 1, SKILLS §13.
 *
 * 규칙 변경: bl_document 신규 첨부 시 unpaid_ratio > 0(=미완납)이면 차단.
 *   - 통관·선적 진입(C5)은 50% 유지 / B/L 발급만 100% 완납 필수.
 *   - 부족분은 관리/관리자 미입금 우회 승인(stage='shipping')으로 발급 가능.
 *
 * 검증 범위:
 * - 미완납(70% 미수) + 신규 bl_document 첨부 → 차단
 * - 부분입금(40% 미수, 구 50% 룰이면 통과했던 구간) + 신규 첨부 → 차단 (50→100 변경 핵심)
 * - 완납(미수 0) + 신규 bl_document 첨부 → 통과
 * - grandfather: 기존 bl_document 있는 차량 → 모든 변경 통과
 * - 판매가 미입력 (unpaid_ratio = null ⟺ sale_total_amount ≤ 0) → 별도 메시지 차단
 * - 미입금 우회 승인(stage='shipping') 우회 통과
 * - bl_document 삭제(빈 값) → 통과
 */
class G1BlLockTest extends TestCase
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
            'vehicle_number' => 'G1T-'.$this->counter,
            'sales_channel' => 'export',
            'currency' => 'KRW',
            'exchange_rate' => 1,
            'sale_price' => 1000000,
            'dhl_request' => false,
        ];

        // 2026-05-19 풀회의 안건 E — sale_price > 0 시 sale_date·buyer_id 자동 채움.
        $salePrice = $overrides['sale_price'] ?? $defaults['sale_price'];
        if ($salePrice > 0) {
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
            'advance_payment2' => 'fee',
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

        // 큐 22-C-E (2026-05-20) — vehicles 2컬럼 DROP. override 키가 있으면 confirmed PBP 자동 생성.
        $purchase2Map = [
            'down_payment' => 'down',
            'selling_fee_payment' => 'selling_fee',
        ];
        $purchase2Inserts = [];
        foreach ($purchase2Map as $col => $type) {
            if (array_key_exists($col, $overrides)) {
                if ((float) $overrides[$col] > 0) {
                    $purchase2Inserts[] = ['amount' => (float) $overrides[$col], 'type' => $type];
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
        if (! empty($purchase2Inserts)) {
            PurchaseBalancePayment::$skipCreatingGuard = true;
            try {
                foreach ($purchase2Inserts as $row) {
                    $v->purchaseBalancePayments()->create([
                        'amount' => $row['amount'],
                        'type' => $row['type'],
                        'payment_date' => now()->subDay()->toDateString(),
                        'confirmed_at' => now(),
                    ]);
                }
            } finally {
                PurchaseBalancePayment::$skipCreatingGuard = false;
            }
        }
        if (! empty($sale4Inserts) || ! empty($purchase2Inserts)) {
            $v->refresh();
        }

        return $v;
    }

    public function test_g1_blocks_bl_upload_when_not_fully_paid(): void
    {
        $admin = User::factory()->create(['permission' => 'admin']);
        // 판매가 100만, 입금 30만 → 미수 70만, 미수율 70% → 미완납 차단
        $v = $this->makeVehicle(['sale_price' => 1000000, 'deposit_down_payment' => 300000]);
        $this->actingAs($admin);

        $v2 = Vehicle::find($v->id);
        $v2->bl_document = 'bl/test.pdf';

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('B/L 발행 차단');
        $v2->save();
    }

    public function test_g1_blocks_bl_upload_when_partially_paid_above_50_percent(): void
    {
        // 50→100 변경 핵심: 구 50% 룰이면 통과했던 60% 입금(40% 미수)도 이제 차단.
        $admin = User::factory()->create(['permission' => 'admin']);
        // 판매가 100만, 입금 60만 → 미수 40만, 미수율 40% (50% 이하지만 완납 아님)
        $v = $this->makeVehicle(['sale_price' => 1000000, 'deposit_down_payment' => 600000]);
        $this->actingAs($admin);

        $v2 = Vehicle::find($v->id);
        $v2->bl_document = 'bl/test.pdf';

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('B/L 발행 차단');
        $v2->save();
    }

    public function test_g1_allows_bl_upload_when_fully_paid(): void
    {
        $admin = User::factory()->create(['permission' => 'admin']);
        // 판매가 100만, 입금 100만 → 미수 0, 완납 → 통과
        $v = $this->makeVehicle(['sale_price' => 1000000, 'deposit_down_payment' => 1000000]);
        $this->actingAs($admin);

        $v2 = Vehicle::find($v->id);
        $v2->bl_document = 'bl/test.pdf';
        $v2->save();   // 완납 — 예외 없음

        $this->assertSame('bl/test.pdf', $v2->fresh()->bl_document);
    }

    public function test_g1_grandfather_existing_bl_passes(): void
    {
        // 기존에 이미 bl_document 가진 차량 — 사용자 결정 grandfather 통과
        $admin = User::factory()->create(['permission' => 'admin']);
        $v = $this->makeVehicle([
            'sale_price' => 1000000,
            'deposit_down_payment' => 100000,  // 미수율 90%
            'bl_document' => 'bl/old.pdf',
        ]);
        $this->actingAs($admin);

        // 같은 컬럼을 다른 파일로 교체 시도 — grandfather라 통과
        $v2 = Vehicle::find($v->id);
        $v2->bl_document = 'bl/replaced.pdf';
        $v2->save();

        $this->assertSame('bl/replaced.pdf', $v2->fresh()->bl_document);
    }

    public function test_g1_blocks_bl_upload_when_unpaid_ratio_null_no_sale_price(): void
    {
        $admin = User::factory()->create(['permission' => 'admin']);
        // unpaid_ratio는 통화 비의존 — sale_total_amount에 환율 안 곱함. null ⟺ sale_total_amount ≤ 0.
        // 진정 null인 케이스 = 판매가 미입력: sale_price=0 + tax_dc만 → sale_total_amount = -100 ≤ 0.
        $v = $this->makeVehicle([
            'sale_price' => 0,                // 판매가 미입력
            'tax_dc' => 100,                  // 할인만 있음 → sale_total_amount = -100 ≤ 0
        ]);
        $this->actingAs($admin);

        $v2 = Vehicle::find($v->id);
        $v2->bl_document = 'bl/test.pdf';

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('판매 정보(판매가) 입력 필수');
        $v2->save();
    }

    public function test_g1_admin_shipping_override_bypasses_lock(): void
    {
        $admin = User::factory()->create(['permission' => 'admin']);
        // 미수율 90% — 정상이면 차단
        $v = $this->makeVehicle(['sale_price' => 1000000, 'deposit_down_payment' => 100000]);

        // admin 미입금 우회 승인 (shipping 단계)
        UnpaidExportOverride::create([
            'vehicle_id' => $v->id,
            'stage' => 'shipping',
            'approved_by' => $admin->id,
            'reason' => '바이어 신용 확인 + 잔금 5/30 입금 약정 확보. 컨테이너 출항 일정 압박.',
            'approved_at' => now(),
            'ip_address' => '127.0.0.1',
            'sale_unpaid_amount_snapshot' => 900000,
        ]);

        $this->actingAs($admin);
        $v2 = Vehicle::find($v->id);
        $v2->bl_document = 'bl/test.pdf';
        $v2->save();   // override 있으니 통과

        $this->assertSame('bl/test.pdf', $v2->fresh()->bl_document);
    }

    public function test_g1_allows_bl_document_removal(): void
    {
        // 기존 bl_document 있는 차량의 삭제 시도 — grandfather라 통과
        $admin = User::factory()->create(['permission' => 'admin']);
        $v = $this->makeVehicle([
            'sale_price' => 1000000,
            'deposit_down_payment' => 100000,  // 미수율 90%
            'bl_document' => 'bl/old.pdf',
        ]);
        $this->actingAs($admin);

        $v2 = Vehicle::find($v->id);
        $v2->bl_document = null;
        $v2->save();

        $this->assertNull($v2->fresh()->bl_document);
    }

    public function test_g1_seeder_unauthenticated_bypasses(): void
    {
        // auth()->check() false → 시드/artisan 우회. 미수율 무관 통과.
        $v = $this->makeVehicle(['sale_price' => 1000000, 'deposit_down_payment' => 100000]);

        // actingAs 호출 안 함 — 시드 시뮬레이션
        $v2 = Vehicle::find($v->id);
        $v2->bl_document = 'bl/seed.pdf';
        $v2->save();   // 시드 우회 통과

        $this->assertSame('bl/seed.pdf', $v2->fresh()->bl_document);
    }
}
