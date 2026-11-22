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

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.nice.provide_token' => 'gw-test-token', 'services.nice.gateway_tokens' => '']);
    }

    /**
     * 🔐 토큰 검사 (2026-11-22) — 구: 인증 없이 누구나 NICE 유료 조회·소유자 정보를 받을 수 있었다.
     * 헤더가 없거나 틀리면 401 이고 **NICE 를 부르지 않는다**. 설정이 비어 있으면 아무도 못 지난다(fail-closed).
     */
    public function test_rejects_requests_without_the_right_token_and_never_calls_nice(): void
    {
        $this->mock(NiceDirectClient::class, fn ($m) => $m->shouldNotReceive('lookup'));

        $this->postJson('/provide/api/nice-lookup', $this->payload)->assertStatus(401)->assertJson(['success' => false]);
        $this->postJson('/provide/api/nice-lookup', $this->payload, ['X-SSANCAR-API-KEY' => 'wrong'])->assertStatus(401);

        config(['services.nice.provide_token' => '', 'services.nice.gateway_tokens' => '']);
        $this->postJson('/provide/api/nice-lookup', $this->payload, ['X-SSANCAR-API-KEY' => ''])->assertStatus(401);
        $this->postJson('/provide/api/nice-lookup', $this->payload, ['X-SSANCAR-API-KEY' => 'anything'])->assertStatus(401);
    }

    /** 회사마다 다른 토큰을 쓰게 되면 목록(NICE_GATEWAY_TOKENS)으로 받는다 — 목록이 있으면 목록만 본다. */
    public function test_accepts_any_token_in_the_gateway_list(): void
    {
        config(['services.nice.gateway_tokens' => 'tok-a, tok-b']);
        $this->mock(NiceDirectClient::class, function ($m) {
            $m->shouldReceive('lookup')->twice()->andReturn(['success' => true, 'message' => 'ok', 'data' => [], 'status' => 200]);
        });

        $this->postJson('/provide/api/nice-lookup', $this->payload, ['X-SSANCAR-API-KEY' => 'tok-a'])->assertOk();
        $this->postJson('/provide/api/nice-lookup', $this->payload, ['X-SSANCAR-API-KEY' => 'tok-b'])->assertOk();
        $this->postJson('/provide/api/nice-lookup', $this->payload, ['X-SSANCAR-API-KEY' => 'gw-test-token'])->assertStatus(401);
    }

    public function test_proceeds_and_returns_result_when_slot_free(): void
    {
        config(['services.nice.max_concurrent' => 2]);
        $this->mock(NiceDirectClient::class, function ($m) {
            $m->shouldReceive('lookup')->once()
                ->andReturn(['success' => true, 'message' => 'ok', 'data' => ['x' => 1]]);
        });

        $this->postJson('/provide/api/nice-lookup', $this->payload, ['X-SSANCAR-API-KEY' => 'gw-test-token'])
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

        $this->postJson('/provide/api/nice-lookup', $this->payload, ['X-SSANCAR-API-KEY' => 'gw-test-token'])
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

        $this->postJson('/provide/api/nice-lookup', $this->payload, ['X-SSANCAR-API-KEY' => 'gw-test-token'])->assertStatus(200);

        // 요청이 끝나면 slot-1 이 해제돼 다시 잡을 수 있어야 함(누수 없음)
        $lock = Cache::lock('nice-lookup-slot-1', 5);
        $this->assertTrue($lock->get(), '요청 후 슬롯이 해제돼야 함(락 누수 방지)');
        $lock->release();
    }
}
