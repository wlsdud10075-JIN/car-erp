<?php

namespace Tests\Feature\Kakao;

use App\Models\KakaoCollectorToken;
use App\Models\KakaoItem;
use App\Models\KakaoMessage;
use App\Models\KakaoRead;
use App\Models\KakaoRoom;
use App\Models\Setting;
use App\Models\User;
use App\Services\Kakao\KakaoClassifierClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 「내 카톡 할 일」 페이지 — 토글 게이트 · 묶음 렌더(보는 사람 시점) · 행 동작은 내 구독 방만(IDOR) · 방 등록/구독 · 토큰 1회 표시.
 */
class KakaoPageTest extends TestCase
{
    use RefreshDatabase;

    private const SIDES = ['재무' => ['재무A'], '수출팀' => ['수출팀A', '수출팀B'], '영업' => ['영업A']];

    protected function setUp(): void
    {
        parent::setUp();
        Setting::updateOrCreate(['key' => 'kakao_assistant_enabled_'.Setting::companyTemplateSet()], ['value' => '1', 'type' => 'boolean']);
    }

    private function user(string $role = '수출통관'): User
    {
        return User::factory()->create(['permission' => 'user', 'role' => $role, 'email_verified_at' => now()]);
    }

    private function roomWith(User $user, string $myName): KakaoRoom
    {
        $room = KakaoRoom::create(['name' => '테스트방', 'sides' => self::SIDES]);
        $room->users()->attach($user->id, ['my_name' => $myName, 'selected' => true]);

        return $room;
    }

    private function msg(KakaoRoom $room, array $attrs): KakaoMessage
    {
        static $n = 0;
        $n++;

        return KakaoMessage::create(array_merge([
            'room_id' => $room->id, 'msg_key' => 'k'.$n, 'sent_at' => now()->subHours(2), 'sender' => '영업A',
            'body' => '본문 '.$n, 'type' => '요청', 'to_side' => '수출팀',
        ], $attrs));
    }

    public function test_toggle_off_hides_menu_and_blocks_page(): void
    {
        Setting::updateOrCreate(['key' => 'kakao_assistant_enabled_'.Setting::companyTemplateSet()], ['value' => '0', 'type' => 'boolean']);
        $this->actingAs($this->user());

        $this->get(route('erp.kakao.index'))->assertStatus(403);
        $this->get(route('erp.dashboard'))->assertOk()->assertDontSee(__('nav.menu.kakao'));
    }

    public function test_buckets_follow_the_viewer(): void
    {
        $me = $this->user();
        $room = $this->roomWith($me, '수출팀A');
        $mine = $this->msg($room, ['body' => '상사 딜러 명함 올려주세요', 'sender' => '영업A', 'to_side' => '수출팀']);                 // 내 일(돈·마감 아님 → 오늘 안에)
        $urgent = $this->msg($room, ['body' => '입금 확인 부탁드립니다', 'sender' => '재무A', 'to_side' => '수출팀']);                   // 내 일 + 입금 → 지금 할 일
        $waiting = $this->msg($room, ['body' => '서렌더 요청드립니다', 'sender' => '수출팀A', 'to_side' => '영업']);                  // 내가 보냄 → 답 기다리는 중
        $teammate = $this->msg($room, ['body' => '명함 올려주세요', 'sender' => '수출팀B', 'to_side' => '영업']);                   // 동료 → 팀 동료 진행 중
        $review = $this->msg($room, ['body' => '뜻 모를 말', 'type' => null, 'to_side' => null]);                                   // 분류 실패 → 확인 필요
        $this->msg($room, ['body' => '사진', 'type' => '첨부', 'to_side' => '없음']);                                                // 안 보임

        $this->actingAs($me);
        $page = Volt::test('erp.kakao.index');
        $board = $page->viewData('board');

        $this->assertSame([$urgent->id], array_column($board['buckets']['now'], 'id'));
        $this->assertSame([$mine->id], array_column($board['buckets']['today'], 'id'));
        $this->assertSame([$waiting->id], array_column($board['buckets']['waiting'], 'id'));
        $this->assertSame([$teammate->id], array_column($board['buckets']['teammate'], 'id'));
        $this->assertSame([$review->id], array_column($board['buckets']['review'], 'id'));
        $this->assertSame(0, $board['counts']['none']);
        $page->assertSee('상사 딜러 명함 올려주세요')->assertSee('지금 할 일')->assertSee('아직 분류 안 된 메시지 1건')->assertSee('이어서 분류');   // type null = 아직 분류 전 → 이어서 분류 버튼(11-11)

        // 같은 방을 수출팀B 가 보면: 동료의 서렌더 요청이 「팀 동료」, 자기 명함 요청이 「답 기다리는 중」
        $other = $this->user();
        $room->users()->attach($other->id, ['my_name' => '수출팀B', 'selected' => true]);
        $this->actingAs($other);
        $board2 = Volt::test('erp.kakao.index')->viewData('board');
        $this->assertSame([$waiting->id], array_column($board2['buckets']['teammate'], 'id'));
        $this->assertSame([$teammate->id], array_column($board2['buckets']['waiting'], 'id'));
    }

    public function test_row_actions_write_items_only_for_my_rooms(): void
    {
        $me = $this->user();
        $room = $this->roomWith($me, '수출팀A');
        $m = $this->msg($room, []);
        $this->actingAs($me);

        Volt::test('erp.kakao.index')->call('markDone', $m->id);
        $item = KakaoItem::where('message_id', $m->id)->where('user_id', $me->id)->first();
        $this->assertSame('done', $item->state);
        $this->assertNotNull($item->done_at);
        $this->assertSame('요청', $m->fresh()->type);   // 메시지 본체 불변

        $page = Volt::test('erp.kakao.index')->call('openSnooze', $m->id)->set('snoozeReason', '서류 오면')->call('snoozeItem', $m->id, 'tomorrow10');
        $item->refresh();
        $this->assertSame('snoozed', $item->state);
        $this->assertSame('10:00', $item->snooze_until->format('H:i'));
        $this->assertSame('서류 오면', $item->snooze_reason);
        $this->assertSame([$m->id], array_column($page->viewData('board')['buckets']['snoozed'], 'id'));

        Volt::test('erp.kakao.index')->call('snoozeItem', $m->id, 'reply');
        $this->assertNull($item->fresh()->snooze_until);
        $this->assertSame('상대가 답하면', $item->fresh()->snooze_reason);

        Volt::test('erp.kakao.index')->call('openFix', $m->id)->set('fixType', '잡담')->call('overrideType', $m->id);
        $this->assertSame('잡담', $item->fresh()->type_override);
        $this->assertSame('요청', $m->fresh()->type);

        // 남의 방 메시지는 403 — id 는 사용자 입력이다
        $stranger = $this->user();
        $otherRoom = KakaoRoom::create(['name' => '남의방', 'sides' => self::SIDES]);
        $otherRoom->users()->attach($stranger->id, ['my_name' => '수출팀A', 'selected' => true]);
        $foreign = $this->msg($otherRoom, []);
        Volt::test('erp.kakao.index')->call('markDone', $foreign->id)->assertForbidden();
        $this->assertSame(0, KakaoItem::where('message_id', $foreign->id)->count());
    }

    public function test_first_read_room_asks_only_for_me_then_classifies_stored_messages(): void
    {
        $me = $this->user();
        $this->actingAs($me);
        $fake = new FakeClassifier;
        $fake->plan = fn (array $ids) => array_map(fn ($id) => ['id' => $id, 'type' => '요청', 'to' => KakaoRoom::SIDE_US, 'mention' => null, 'ref' => null, 'vehicle' => null, 'vin_tail' => null, 'amount' => null, 'resolves' => null], $ids);
        $this->app->instance(KakaoClassifierClient::class, $fake);

        // 수집기가 처음 보낸 방 = 편 구성 없음·내 표시명 비어 있음·메시지만 쌓임(type null)
        $room = KakaoRoom::create(['name' => '처음방']);
        $room->users()->attach($me->id, ['my_name' => '', 'selected' => true]);
        $this->msg($room, ['sender' => '상대A', 'type' => null, 'to_side' => null, 'body' => '입금 부탁드립니다']);
        $this->msg($room, ['sender' => '나임', 'type' => null, 'to_side' => null, 'body' => '넵']);
        $this->msg($room, ['sender' => '동료B', 'type' => null, 'to_side' => null, 'body' => '확인했습니다']);

        $page = Volt::test('erp.kakao.index');
        $this->assertCount(1, $page->viewData('needSetup'));
        $this->assertEqualsCanonicalizing(['동료B', '나임', '상대A'], $page->viewData('senders')[$room->id]);
        $page->assertSee('「나」를 골라 주세요');

        // 「나」를 안 고르면 거부
        $page->call('saveSetup', $room->id)->assertHasErrors(['meFor.'.$room->id]);
        $this->assertSame(0, $fake->calls);

        // 나 + 우리 회사 1명 → 우리/상대 자동 구성 + 쌓인 메시지 즉시 분류
        $page->set('meFor.'.$room->id, '나임')->set('oursFor.'.$room->id, ['동료B'])->call('saveSetup', $room->id)->assertHasNoErrors();
        $room->refresh();
        $this->assertSame([KakaoRoom::SIDE_US => ['나임', '동료B'], KakaoRoom::SIDE_THEM => ['상대A']], $room->sideMembers());
        $this->assertSame('나임', $me->kakaoRooms()->first()->pivot->my_name);
        $this->assertSame(1, $fake->calls);
        $this->assertSame(0, KakaoMessage::where('room_id', $room->id)->whereNull('type')->count());
        $this->assertCount(0, Volt::test('erp.kakao.index')->viewData('needSetup'));

        // 다시 고르기 → 설정 카드가 다시 뜬다(메시지·판정은 그대로)
        Volt::test('erp.kakao.index')->call('resetSetup', $room->id);
        $this->assertFalse($room->fresh()->isSetUp());
        $this->assertSame(3, KakaoMessage::where('room_id', $room->id)->whereNotNull('type')->count());
    }

    public function test_rooms_reported_by_collector_are_picked_with_a_checkbox(): void
    {
        $me = $this->user();
        $this->actingAs($me);
        $a = KakaoRoom::create(['name' => '방A']);
        $b = KakaoRoom::create(['name' => '방B']);
        $a->users()->attach($me->id, ['my_name' => '', 'selected' => false, 'last_seen_at' => now()]);
        $b->users()->attach($me->id, ['my_name' => '', 'selected' => false, 'last_seen_at' => now()]);

        $page = Volt::test('erp.kakao.index');
        $this->assertCount(2, $page->viewData('allRooms'));
        $this->assertCount(0, $page->viewData('board')['rooms']);   // 체크 전엔 안 읽는다
        $page->assertSee('읽을 방')->assertSee('방A')->assertSee('방B');

        $page->call('toggleRoom', $a->id);
        $this->assertTrue((bool) $me->kakaoRooms()->where('kakao_rooms.id', $a->id)->first()->pivot->selected);
        $this->assertSame(['방A'], Volt::test('erp.kakao.index')->viewData('board')['rooms']->pluck('name')->all());

        $page->call('toggleRoom', $a->id);
        $this->assertFalse((bool) $me->kakaoRooms()->where('kakao_rooms.id', $a->id)->first()->pivot->selected);

        // 남의 방은 403
        $stranger = $this->user();
        $c = KakaoRoom::create(['name' => '방C']);
        $c->users()->attach($stranger->id, ['my_name' => '', 'selected' => true]);
        Volt::test('erp.kakao.index')->call('toggleRoom', $c->id)->assertForbidden();
    }

    public function test_connect_this_pc_downloads_a_one_time_installer_and_devices_can_be_revoked(): void
    {
        $user = User::factory()->create(['permission' => 'user', 'role' => '수출통관']);
        $this->actingAs($user);

        // §11 — 토큰은 화면에 안 보인다. 설정 탭의 [이 PC 연결하기]가 .bat 을 내려 주고 그 안에 토큰·local_secret 이 박힌다
        $page = Volt::test('erp.kakao.index')->set('showSetup', true)
            ->assertSee(route('erp.kakao.installer'), false)->assertSee(__('kakao.token_connect'))->assertDontSee('kck_');
        $res = $this->post(route('erp.kakao.installer'));
        $res->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="KakaoAgent-setup.bat"');
        $token = KakaoCollectorToken::first();
        $this->assertNotNull($token->local_secret);
        $this->assertStringContainsString('"token": "kck_', $res->getContent());
        $this->assertStringContainsString('"local_secret": "'.$token->local_secret.'"', $res->getContent());
        $this->assertStringNotContainsString($token->token_hash, $res->getContent());

        // 페이지 JS 가 쓸 내 기기 비밀값은 본인 세션에만 — 다른 사용자 화면엔 없다
        $page = Volt::test('erp.kakao.index')->set('showSetup', true)->assertSee('data-kakao-read', false);
        $this->assertSame([$token->local_secret], array_column($page->viewData('agent')['secrets'], 'secret'));
        $this->actingAs(User::factory()->create(['permission' => 'user', 'role' => '수출통관']));
        $this->assertSame([], Volt::test('erp.kakao.index')->viewData('agent')['secrets']);

        $this->actingAs($user);
        $page = Volt::test('erp.kakao.index')->set('showSetup', true)->call('revokeToken', $token->id);
        $this->assertNotNull($token->fresh()->revoked_at);
        $this->assertSame([], $page->viewData('agent')['secrets']);
    }

    /** 💰 API 사용액은 시스템관리자만 본다 — 회사 DB 전체 합(오늘·이번 달·누적·이번 달 사람별) (jin 11-10 «얼마나 사용했는지») */
    public function test_api_spend_card_is_for_super_admins_only(): void
    {
        $super = User::factory()->create(['permission' => 'super', 'role' => '관리']);
        $staff = User::factory()->create(['permission' => 'user', 'role' => '수출통관', 'name' => '수출팀A']);
        $room = KakaoRoom::create(['name' => '방']);
        KakaoRead::create(['room_id' => $room->id, 'user_id' => $staff->id, 'started_at' => now(), 'total' => 3, 'new_count' => 3, 'status' => 'done', 'cost_krw' => 12.5, 'created_at' => now()]);
        KakaoRead::create(['room_id' => $room->id, 'user_id' => $staff->id, 'started_at' => now(), 'total' => 1, 'new_count' => 1, 'status' => 'done', 'cost_krw' => 7.5])
            ->forceFill(['created_at' => now()->subMonths(2)])->saveQuietly();   // create() 는 created_at 을 못 넣는다(§8 #11)

        $this->actingAs($super);
        $page = Volt::test('erp.kakao.index');
        $u = $page->viewData('usage');
        $this->assertSame(12.5, $u['today']);
        $this->assertSame(12.5, $u['month']);
        $this->assertSame(20.0, $u['total']);
        $this->assertSame('수출팀A', $u['by_user'][0]->name);
        $page->assertSee('data-kakao-usage', false)->assertSee('API 사용액');

        $this->actingAs($staff);
        $page = Volt::test('erp.kakao.index');
        $this->assertNull($page->viewData('usage'));
        $page->assertDontSee('data-kakao-usage', false);
    }
}
