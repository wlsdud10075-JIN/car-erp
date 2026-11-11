<?php

namespace Tests\Feature\Kakao;

use App\Models\KakaoCollectorToken;
use App\Models\KakaoMessage;
use App\Models\KakaoRoom;
use App\Models\Setting;
use App\Models\User;
use App\Services\Kakao\KakaoClassifierClient;
use App\Services\Kakao\KakaoIngestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $room->users()->attach($user->id, ['my_name' => '수출팀A', 'selected' => true]);

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

    public function test_unknown_room_is_auto_registered_and_held_until_setup(): void
    {
        [$user, $plain] = $this->ready();
        // 다른 방의 카톡 이름이 있으면 그걸 「나」로 쓴다(11-11) — 여기선 「아무 데서도 이름을 모르는」 첫 사용자를 본다
        DB::table('kakao_room_users')->where('user_id', $user->id)->update(['my_name' => '']);

        $res = $this->ingestAs($plain, ['room' => '처음보는방', 'text' => self::TEXT]);

        $res->assertOk()->assertJson(['status' => 'pending_setup', 'new_count' => 2]);
        $this->assertStringContainsString('「나」를 한 번 골라', $res->json('message'));
        $room = KakaoRoom::where('name', '처음보는방')->firstOrFail();
        $this->assertFalse($room->isSetUp());
        $this->assertTrue($room->users()->where('users.id', $user->id)->exists());   // 자동 구독(표시명은 비움)
        $this->assertSame(1, KakaoMessage::where('room_id', $room->id)->whereNull('type')->count());   // 첨부 빼고 미분류로 쌓임
        $this->assertSame(0, $this->fake->calls);

        // 「나」를 고르면 쌓인 것이 분류된다
        $room->setUpSides('재무A', []);
        app(KakaoIngestService::class)->classifyPending($room, $user);
        $this->assertSame(1, $this->fake->calls);
        $this->assertSame(0, KakaoMessage::where('room_id', $room->id)->whereNull('type')->count());
    }

    public function test_collector_reports_open_rooms_and_gets_back_only_ticked_ones(): void
    {
        [$user, $plain, $room] = $this->ready();

        $res = $this->withHeader('Authorization', 'Bearer '.$plain)->postJson('/api/kakao/rooms', ['rooms' => ['무사백-수출팀', '새로열린방', '새로열린방', '']]);

        $res->assertOk()->assertJson(['selected' => ['무사백-수출팀']]);          // 체크된 것만(새 방은 등록만)
        $new = KakaoRoom::where('name', '새로열린방')->firstOrFail();
        $pivot = $new->users()->where('users.id', $user->id)->first()->pivot;
        $this->assertFalse((bool) $pivot->selected);
        $this->assertNotNull($pivot->last_seen_at);
        $this->assertSame(2, KakaoRoom::count());

        $user->kakaoRooms()->updateExistingPivot($new->id, ['selected' => true]);
        $this->withHeader('Authorization', 'Bearer '.$plain)->getJson('/api/kakao/rooms')
            ->assertOk()->assertJson(['selected' => ['무사백-수출팀', '새로열린방'], 'need_setup' => ['새로열린방']]);
    }

    public function test_validation_and_limits_are_422_with_korean_text(): void
    {
        [, $plain] = $this->ready();

        $this->ingestAs($plain, ['room' => '무사백-수출팀', 'text' => ''])->assertStatus(422);

        Setting::updateOrCreate(['key' => 'kakao_max_new_per_read'], ['value' => '50', 'type' => 'integer']);
        $big = "--------------- 2026년 6월 10일 수요일 ---------------\n".implode("\n", array_map(fn ($i) => "[영업A] [오전 9:00] 입금 부탁 {$i}", range(1, 60)));
        // 읽기 1회 상한 — 거부가 아니라 오래된 것부터 상한까지만 저장하고 나머지는 「한 번 더」(jin 11-10 — 바쁜 방 7일 창 591건 실측)
        $res = $this->ingestAs($plain, ['room' => '무사백-수출팀', 'text' => $big]);
        $res->assertOk()->assertJsonPath('leftover', 10)->assertJsonPath('new_count', 50);
        $this->assertStringContainsString('10건은 아직 안 담았습니다', $res->json('message'));
        $this->assertSame(50, KakaoMessage::count());
        $this->assertSame('입금 부탁 50', KakaoMessage::orderByDesc('id')->value('body'), '오래된 것부터 담는다');

        $res2 = $this->ingestAs($plain, ['room' => '무사백-수출팀', 'text' => $big]);   // 한 번 더 — 저장된 건은 건너뛰고 나머지 10건
        $res2->assertOk()->assertJsonPath('leftover', 0)->assertJsonPath('new_count', 10);
        $this->assertSame(60, KakaoMessage::count());
    }

    public function test_model_failure_is_reported_as_needs_review(): void
    {
        [, $plain] = $this->ready();
        $this->fake->plan = fn () => throw new \RuntimeException('down');

        $res = $this->ingestAs($plain, ['room' => '무사백-수출팀', 'text' => self::TEXT]);

        $res->assertOk()->assertJson(['status' => 'failed', 'needs_review' => true]);
        $this->assertStringContainsString('이어서 분류', $res->json('message'));   // 11-11 — 실패분은 화면이 자동으로 다시 시도한다(남은 건수 표시)
        $this->assertSame(1, $res->json('pending_after'));
    }
}
