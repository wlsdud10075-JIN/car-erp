<?php

namespace Tests\Feature\Kakao;

use App\Models\KakaoCollectorToken;
use App\Models\KakaoMessage;
use App\Models\KakaoRoom;
use App\Models\Setting;
use App\Models\User;
use App\Services\Kakao\KakaoClassifierClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * POST /api/kakao/ingest — 토큰 인증 · 회사 토글 · 방 등록/구독 · 상한 · 정상 응답.
 */
class KakaoIngestApiTest extends TestCase
{
    use RefreshDatabase;

    private const TEXT = "--------------- 2026년 6월 10일 수요일 ---------------\n[영업A] [오전 10:58] 1074 송금 부탁 드립니다\n[재무A] [오전 11:02] 사진";

    private FakeClassifier $fake;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fake = new FakeClassifier;
        $this->fake->plan = fn (array $ids) => array_map(fn ($id) => ['id' => $id, 'type' => '요청', 'to' => '재무', 'mention' => null, 'ref' => null, 'vehicle' => null, 'vin_tail' => null, 'amount' => '1074만원', 'resolves' => null], $ids);
        $this->app->instance(KakaoClassifierClient::class, $this->fake);
        Setting::updateOrCreate(['key' => 'kakao_assistant_enabled_'.Setting::companyTemplateSet()], ['value' => '1', 'type' => 'boolean']);
    }

    /** @return array{User, string, KakaoRoom} */
    private function ready(): array
    {
        $user = User::factory()->create(['permission' => 'user', 'role' => '수출통관']);
        [, $plain] = KakaoCollectorToken::issue($user, '사무실 PC');
        $room = KakaoRoom::create(['name' => '무사백-수출팀', 'sides' => ['재무' => ['재무A'], '수출팀' => ['수출팀A'], '영업' => ['영업A']]]);
        $room->users()->attach($user->id, ['my_name' => '수출팀A']);

        return [$user, $plain, $room];
    }

    private function ingestAs(string $plain, array $body)
    {
        return $this->withHeader('Authorization', 'Bearer '.$plain)->postJson('/api/kakao/ingest', $body);
    }

    public function test_happy_path_returns_counts_and_korean_message(): void
    {
        [$user, $plain, $room] = $this->ready();

        $res = $this->ingestAs($plain, ['room' => '무사백-수출팀', 'text' => self::TEXT]);

        $res->assertOk()->assertJson(['total' => 2, 'new_count' => 2, 'status' => 'done', 'needs_review' => false]);
        $this->assertStringContainsString('새 메시지 2건 분류 완료', $res->json('message'));
        $this->assertSame(2, KakaoMessage::where('room_id', $room->id)->count());
        $this->assertSame(1, $this->fake->calls);
        $this->assertNotNull(KakaoCollectorToken::first()->last_used_at);

        // 같은 본문 다시 → 새 메시지 0, 모델 호출 없음
        $again = $this->ingestAs($plain, ['room' => '무사백-수출팀', 'text' => self::TEXT]);
        $again->assertOk()->assertJson(['new_count' => 0]);
        $this->assertStringContainsString('새 메시지가 없습니다', $again->json('message'));
        $this->assertSame(1, $this->fake->calls);
    }

    public function test_token_gates(): void
    {
        [$user, $plain] = $this->ready();

        $this->postJson('/api/kakao/ingest', ['room' => 'x', 'text' => 'y'])->assertStatus(401);
        $this->ingestAs('kck_wrong', ['room' => 'x', 'text' => 'y'])->assertStatus(401);

        KakaoCollectorToken::first()->update(['revoked_at' => now()]);
        $this->ingestAs($plain, ['room' => '무사백-수출팀', 'text' => self::TEXT])->assertStatus(401);
        $this->assertSame(0, $this->fake->calls);
    }

    public function test_company_toggle_off_is_403(): void
    {
        [, $plain] = $this->ready();
        Setting::updateOrCreate(['key' => 'kakao_assistant_enabled_'.Setting::companyTemplateSet()], ['value' => '0', 'type' => 'boolean']);

        $res = $this->ingestAs($plain, ['room' => '무사백-수출팀', 'text' => self::TEXT]);

        $res->assertStatus(403);
        $this->assertStringContainsString('꺼져', $res->json('message'));
    }

    public function test_unknown_room_is_404_and_unsubscribed_room_is_403(): void
    {
        [$user, $plain] = $this->ready();

        $this->ingestAs($plain, ['room' => '없는방', 'text' => self::TEXT])->assertStatus(404);

        KakaoRoom::create(['name' => '다른방', 'sides' => ['재무' => ['재무A']]]);
        $res = $this->ingestAs($plain, ['room' => '다른방', 'text' => self::TEXT]);
        $res->assertStatus(403);
        $this->assertStringContainsString('구독하지 않았습니다', $res->json('message'));
        $this->assertSame(0, KakaoMessage::count());
    }

    public function test_validation_and_limits_are_422_with_korean_text(): void
    {
        [, $plain] = $this->ready();

        $this->ingestAs($plain, ['room' => '무사백-수출팀', 'text' => ''])->assertStatus(422);

        Setting::updateOrCreate(['key' => 'kakao_max_new_per_read'], ['value' => '50', 'type' => 'integer']);
        $big = "--------------- 2026년 6월 10일 수요일 ---------------\n".implode("\n", array_map(fn ($i) => "[영업A] [오전 9:00] 입금 부탁 {$i}", range(1, 60)));
        $res = $this->ingestAs($plain, ['room' => '무사백-수출팀', 'text' => $big]);
        $res->assertStatus(422);
        $this->assertStringContainsString('상한', $res->json('message'));
        $this->assertSame(0, KakaoMessage::count());
    }

    public function test_model_failure_is_reported_as_needs_review(): void
    {
        [, $plain] = $this->ready();
        $this->fake->plan = fn () => throw new \RuntimeException('down');

        $res = $this->ingestAs($plain, ['room' => '무사백-수출팀', 'text' => self::TEXT]);

        $res->assertOk()->assertJson(['status' => 'failed', 'needs_review' => true]);
        $this->assertStringContainsString('확인 필요', $res->json('message'));
    }
}
