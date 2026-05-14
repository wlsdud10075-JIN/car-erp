<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\Buyer;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ApprovalRequestInterBuyerOverlapTest extends TestCase
{
    use RefreshDatabase;

    private function makeBuyerWithOutstanding(int $unpaidKrw = 5_000_000): Buyer
    {
        $buyer = Buyer::create(['name' => 'TOKYO AUTO', 'is_active' => true]);

        // 기존 차량 — 미수 잔존 (sale_unpaid_amount_krw_cache > 0)
        $existing = Vehicle::create([
            'vehicle_number' => '99가0001',
            'sales_channel' => 'export',
            'buyer_id' => $buyer->id,
            'sale_price' => 10_000_000,
            'currency' => 'KRW',
        ]);
        // 캐시 직접 설정 (saving 훅이 자동 계산하지만 명시)
        $existing->sale_unpaid_amount_krw_cache = $unpaidKrw;
        $existing->saveQuietly();

        return $buyer;
    }

    public function test_sales_user_blocked_when_same_buyer_has_outstanding(): void
    {
        $sales = User::factory()->create(['permission' => 'user', 'role' => '영업']);
        $this->actingAs($sales);

        $buyer = $this->makeBuyerWithOutstanding();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('미수 잔존');
        Vehicle::create([
            'vehicle_number' => '99가0002',
            'sales_channel' => 'export',
            'buyer_id' => $buyer->id,
        ]);
    }

    public function test_admin_can_register_same_buyer_without_approval(): void
    {
        $admin = User::factory()->create(['permission' => 'admin', 'role' => '관리']);
        $this->actingAs($admin);

        $buyer = $this->makeBuyerWithOutstanding();

        // 차단 없이 통과
        $v = Vehicle::create([
            'vehicle_number' => '99가0003',
            'sales_channel' => 'export',
            'buyer_id' => $buyer->id,
        ]);

        $this->assertNotNull($v->fresh());
    }

    public function test_approved_request_allows_one_registration_then_marks_used(): void
    {
        $sales = User::factory()->create(['permission' => 'user', 'role' => '영업']);
        $manager = User::factory()->create(['permission' => 'user', 'role' => '관리']);

        $buyer = $this->makeBuyerWithOutstanding();

        // 관리자 승인 (approved + used_at NULL)
        $req = ApprovalRequest::create([
            'requester_id' => $sales->id,
            'action_type' => ApprovalRequest::TYPE_INTER_BUYER_OVERLAP,
            'target_type' => Buyer::class,
            'target_id' => $buyer->id,
            'status' => ApprovalRequest::STATUS_APPROVED,
            'approver_id' => $manager->id,
            'decided_at' => now(),
        ]);

        $this->actingAs($sales);

        // 1번째 등록 — 통과 + used_at 마킹
        $v1 = Vehicle::create([
            'vehicle_number' => '99가0004',
            'sales_channel' => 'export',
            'buyer_id' => $buyer->id,
        ]);
        $this->assertNotNull($v1->fresh());
        $this->assertNotNull($req->fresh()->used_at, '승인 소진 시 used_at 마킹');

        // 2번째 등록 — used 됐으므로 다시 차단
        $this->expectException(ValidationException::class);
        Vehicle::create([
            'vehicle_number' => '99가0005',
            'sales_channel' => 'export',
            'buyer_id' => $buyer->id,
        ]);
    }

    public function test_no_outstanding_passes_freely(): void
    {
        $sales = User::factory()->create(['permission' => 'user', 'role' => '영업']);
        $this->actingAs($sales);

        $buyer = Buyer::create(['name' => 'FRESH AUTO', 'is_active' => true]);

        // 동일 buyer지만 미수 없음 — 가드 우회
        $v = Vehicle::create([
            'vehicle_number' => '99가0006',
            'sales_channel' => 'export',
            'buyer_id' => $buyer->id,
        ]);

        $this->assertNotNull($v->fresh());
    }

    public function test_existing_vehicle_update_bypasses_overlap_guard(): void
    {
        $sales = User::factory()->create(['permission' => 'user', 'role' => '영업']);

        $buyer = $this->makeBuyerWithOutstanding();

        // 가드 우회 (시드 컨텍스트로 등록) — buyer 미지정 신규 차량
        $v = Vehicle::create([
            'vehicle_number' => '99가0007',
            'sales_channel' => 'export',
        ]);

        // 영업이 로그인 + buyer 연결 (기존 차량 수정 — guard skip 대상)
        $this->actingAs($sales);
        $v->buyer_id = $buyer->id;
        $v->save();

        $this->assertSame($buyer->id, $v->fresh()->buyer_id);
    }
}
