<?php

namespace Tests\Feature;

use App\Services\NiceDirectClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * NICE 게이트웨이 전역 동시 조회 상한 (ProvideNiceLookupController).
 * 슬롯 락 세마포어 — 다 차면 90초 붙잡지 않고 즉시 429, 끝나면 슬롯 해제.
 */
class NiceLookupConcurrencyCapTest extends TestCase
{
    use RefreshDatabase;

    private array $payload = ['vehicle_number' => '12가3456', 'owner_name' => '홍길동'];

    public function test_proceeds_and_returns_result_when_slot_free(): void
    {
        config(['services.nice.max_concurrent' => 2]);
        $this->mock(NiceDirectClient::class, function ($m) {
            $m->shouldReceive('lookup')->once()
                ->andReturn(['success' => true, 'message' => 'ok', 'data' => ['x' => 1]]);
        });

        $this->postJson('/provide/api/nice-lookup', $this->payload)
            ->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_returns_429_when_all_slots_busy(): void
    {
        config(['services.nice.max_concurrent' => 2]);

        // 슬롯 2개 모두 선점 → 다음 요청은 막혀야 함
        $l1 = Cache::lock('nice-lookup-slot-1', 120);
        $l2 = Cache::lock('nice-lookup-slot-2', 120);
        $this->assertTrue($l1->get());
        $this->assertTrue($l2->get());

        // 상한에 막히면 NICE 실호출은 발생하면 안 됨
        $this->mock(NiceDirectClient::class, function ($m) {
            $m->shouldNotReceive('lookup');
        });

        $this->postJson('/provide/api/nice-lookup', $this->payload)
            ->assertStatus(429)
            ->assertJson(['success' => false]);

        $l1->release();
        $l2->release();
    }

    public function test_slot_released_after_request(): void
    {
        config(['services.nice.max_concurrent' => 1]);
        $this->mock(NiceDirectClient::class, function ($m) {
            $m->shouldReceive('lookup')->andReturn(['success' => true, 'data' => []]);
        });

        $this->postJson('/provide/api/nice-lookup', $this->payload)->assertStatus(200);

        // 요청이 끝나면 slot-1 이 해제돼 다시 잡을 수 있어야 함(누수 없음)
        $lock = Cache::lock('nice-lookup-slot-1', 5);
        $this->assertTrue($lock->get(), '요청 후 슬롯이 해제돼야 함(락 누수 방지)');
        $lock->release();
    }
}
