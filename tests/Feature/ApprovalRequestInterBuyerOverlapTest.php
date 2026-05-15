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

        // 관리자 승인 — 차량번호 99가0004에 바인딩
        $req = ApprovalRequest::create([
            'requester_id' => $sales->id,
            'action_type' => ApprovalRequest::TYPE_INTER_BUYER_OVERLAP,
            'target_type' => Buyer::class,
            'target_id' => $buyer->id,
            'payload' => ['new_vehicle_number' => '99가0004', 'buyer_name' => $buyer->name],
            'status' => ApprovalRequest::STATUS_APPROVED,
            'approver_id' => $manager->id,
            'decided_at' => now(),
        ]);

        $this->actingAs($sales);

        // 차량번호 일치 — 통과 + used_at 마킹
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

    public function test_approved_request_blocks_different_vehicle_number(): void
    {
        $sales = User::factory()->create(['permission' => 'user', 'role' => '영업']);
        $manager = User::factory()->create(['permission' => 'user', 'role' => '관리']);

        $buyer = $this->makeBuyerWithOutstanding();

        // 차량번호 A에 묶인 승인
        ApprovalRequest::create([
            'requester_id' => $sales->id,
            'action_type' => ApprovalRequest::TYPE_INTER_BUYER_OVERLAP,
            'target_type' => Buyer::class,
            'target_id' => $buyer->id,
            'payload' => ['new_vehicle_number' => '11가1111', 'buyer_name' => $buyer->name],
            'status' => ApprovalRequest::STATUS_APPROVED,
            'approver_id' => $manager->id,
            'decided_at' => now(),
        ]);

        $this->actingAs($sales);

        // 다른 차량번호 B로 저장 시도 → 차단 + 메시지에 A 포함
        try {
            Vehicle::create([
                'vehicle_number' => '22가2222',
                'sales_channel' => 'export',
                'buyer_id' => $buyer->id,
            ]);
            $this->fail('차량번호 불일치인데 통과됨');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('11가1111', $e->getMessage());
            $this->assertStringContainsString('22가2222', $e->getMessage());
        }
    }

    public function test_approval_without_vehicle_number_payload_does_not_bypass(): void
    {
        $sales = User::factory()->create(['permission' => 'user', 'role' => '영업']);
        $manager = User::factory()->create(['permission' => 'user', 'role' => '관리']);

        $buyer = $this->makeBuyerWithOutstanding();

        // payload['new_vehicle_number'] 없는 승인 (잘못 생성된 요청) — 통과 안 시킴
        ApprovalRequest::create([
            'requester_id' => $sales->id,
            'action_type' => ApprovalRequest::TYPE_INTER_BUYER_OVERLAP,
            'target_type' => Buyer::class,
            'target_id' => $buyer->id,
            'payload' => ['buyer_name' => $buyer->name],   // new_vehicle_number 누락
            'status' => ApprovalRequest::STATUS_APPROVED,
            'approver_id' => $manager->id,
            'decided_at' => now(),
        ]);

        $this->actingAs($sales);

        $this->expectException(ValidationException::class);
        Vehicle::create([
            'vehicle_number' => '99가0888',
            'sales_channel' => 'export',
            'buyer_id' => $buyer->id,
        ]);
    }

    public function test_rejected_request_does_not_bypass_then_new_request_allowed(): void
    {
        $sales = User::factory()->create(['permission' => 'user', 'role' => '영업']);
        $manager = User::factory()->create(['permission' => 'user', 'role' => '관리']);

        $buyer = $this->makeBuyerWithOutstanding();

        // 거부된 요청
        $rejected = ApprovalRequest::create([
            'requester_id' => $sales->id,
            'action_type' => ApprovalRequest::TYPE_INTER_BUYER_OVERLAP,
            'target_type' => Buyer::class,
            'target_id' => $buyer->id,
            'payload' => ['new_vehicle_number' => '33가3333', 'buyer_name' => $buyer->name],
            'status' => ApprovalRequest::STATUS_REJECTED,
            'approver_id' => $manager->id,
            'decision_note' => '담보금 부족',
            'decided_at' => now(),
        ]);

        $this->actingAs($sales);

        // 거부 후 저장 시도 → 차단
        try {
            Vehicle::create([
                'vehicle_number' => '33가3333',
                'sales_channel' => 'export',
                'buyer_id' => $buyer->id,
            ]);
            $this->fail('거부된 요청인데 통과됨');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        // 동일 buyer + 동일 차량번호로 새 pending 만들 수 있어야 함 (재요청 허용)
        $newReq = ApprovalRequest::create([
            'requester_id' => $sales->id,
            'action_type' => ApprovalRequest::TYPE_INTER_BUYER_OVERLAP,
            'target_type' => Buyer::class,
            'target_id' => $buyer->id,
            'payload' => ['new_vehicle_number' => '33가3333', 'buyer_name' => $buyer->name],
            'status' => ApprovalRequest::STATUS_PENDING,
        ]);
        $this->assertSame('pending', $newReq->status);
    }

    public function test_vehicle_number_trim_matching(): void
    {
        $sales = User::factory()->create(['permission' => 'user', 'role' => '영업']);
        $manager = User::factory()->create(['permission' => 'user', 'role' => '관리']);

        $buyer = $this->makeBuyerWithOutstanding();

        // 승인에 공백 포함된 차량번호
        ApprovalRequest::create([
            'requester_id' => $sales->id,
            'action_type' => ApprovalRequest::TYPE_INTER_BUYER_OVERLAP,
            'target_type' => Buyer::class,
            'target_id' => $buyer->id,
            'payload' => ['new_vehicle_number' => '  44가4444  ', 'buyer_name' => $buyer->name],
            'status' => ApprovalRequest::STATUS_APPROVED,
            'approver_id' => $manager->id,
            'decided_at' => now(),
        ]);

        $this->actingAs($sales);

        // 저장 시 공백 없는 차량번호 — trim 후 일치 → 통과
        $v = Vehicle::create([
            'vehicle_number' => '44가4444',
            'sales_channel' => 'export',
            'buyer_id' => $buyer->id,
        ]);
        $this->assertNotNull($v->fresh());
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
