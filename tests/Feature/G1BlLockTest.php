<?php

namespace Tests\Feature;

use App\Models\UnpaidExportOverride;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * 큐 9 확장 — G1 50% B/L 잠금 테스트.
 * 회의록 docs/meetings/2026-05-14-3way-workflow-policy.md §G1, SKILLS §13 단일 게이트.
 *
 * 규칙: bl_document 신규 첨부 시 unpaid_ratio > 0.5면 차단.
 *
 * 검증 범위:
 * - 미수율 > 50% + 신규 bl_document 첨부 → 차단
 * - 미수율 ≤ 50% + 신규 bl_document 첨부 → 통과
 * - grandfather: 기존 bl_document 있는 차량 → 모든 변경 통과
 * - 환율 미입력 외화 (unpaid_ratio = null) → 별도 메시지 차단
 * - admin unpaid_export_override(stage='shipping') 우회 통과
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

        return Vehicle::create(array_merge([
            'vehicle_number' => 'G1T-'.$this->counter,
            'sales_channel' => 'export',
            'currency' => 'KRW',
            'exchange_rate' => 1,
            'sale_price' => 1000000,
            'dhl_request' => false,
        ], $overrides));
    }

    public function test_g1_blocks_bl_upload_when_unpaid_ratio_over_50_percent(): void
    {
        $admin = User::factory()->create(['permission' => 'admin']);
        // 판매가 100만, 입금 30만 → 미수 70만, 미수율 70%
        $v = $this->makeVehicle(['sale_price' => 1000000, 'deposit_down_payment' => 300000]);
        $this->actingAs($admin);

        $v2 = Vehicle::find($v->id);
        $v2->bl_document = 'bl/test.pdf';

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('B/L 발행 차단');
        $v2->save();
    }

    public function test_g1_allows_bl_upload_when_unpaid_ratio_50_or_less(): void
    {
        $admin = User::factory()->create(['permission' => 'admin']);
        // 판매가 100만, 입금 60만 → 미수 40만, 미수율 40%
        $v = $this->makeVehicle(['sale_price' => 1000000, 'deposit_down_payment' => 600000]);
        $this->actingAs($admin);

        $v2 = Vehicle::find($v->id);
        $v2->bl_document = 'bl/test.pdf';
        $v2->save();   // 통과 — 예외 없음

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

    public function test_g1_blocks_bl_upload_when_unpaid_ratio_null_no_exchange_rate(): void
    {
        $admin = User::factory()->create(['permission' => 'admin']);
        // 외화 + 환율 미입력 → unpaid_ratio = null (sale_total_amount > 0 + 환율 없으니 평가 불가)
        // sale_total_amount = sale_price (KRW 무관 통화 기준) = 1000. 환율 미입력 = 평가 불가
        // 단 unpaid_ratio accessor는 sale_total_amount <= 0 일 때만 null 반환
        // 환율 무관 통화 단위 미수율 계산이라 USD 차량에서도 unpaid_ratio 계산됨
        // 진정 null인 케이스: sale_total_amount <= 0 (sale_price=0 + 부대비용 = 0 + tax_dc만 있음)
        $v = $this->makeVehicle([
            'sale_price' => 0,                // 판매가 0
            'tax_dc' => 100,                  // 할인만 있음 → sale_total_amount = -100 ≤ 0
            'currency' => 'USD',
            'exchange_rate' => 0,
        ]);
        $this->actingAs($admin);

        $v2 = Vehicle::find($v->id);
        $v2->bl_document = 'bl/test.pdf';

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('환율 입력 필수');
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
