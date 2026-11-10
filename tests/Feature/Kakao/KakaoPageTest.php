<?php

namespace Tests\Feature\Kakao;

use App\Models\KakaoCollectorToken;
use App\Models\KakaoItem;
use App\Models\KakaoMessage;
use App\Models\KakaoRoom;
use App\Models\Setting;
use App\Models\User;
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
        $room->users()->attach($user->id, ['my_name' => $myName]);

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
        $page->assertSee('상사 딜러 명함 올려주세요')->assertSee('지금 할 일')->assertSee('확인 필요 1건');

        // 같은 방을 수출팀B 가 보면: 동료의 서렌더 요청이 「팀 동료」, 자기 명함 요청이 「답 기다리는 중」
        $other = $this->user();
        $room->users()->attach($other->id, ['my_name' => '수출팀B']);
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
        $otherRoom->users()->attach($stranger->id, ['my_name' => '수출팀A']);
        $foreign = $this->msg($otherRoom, []);
        Volt::test('erp.kakao.index')->call('markDone', $foreign->id)->assertForbidden();
        $this->assertSame(0, KakaoItem::where('message_id', $foreign->id)->count());
    }

    public function test_room_setup_and_subscription_validation(): void
    {
        $me = $this->user();
        $this->actingAs($me);

        $page = Volt::test('erp.kakao.index');
        $this->assertTrue($page->get('showSetup'));   // 방이 없으면 설정이 열린 채

        $page->set('roomName', '무사백-수출팀')
            ->set('roomSides', "재무: 재무A, 재무B\n수출팀: 수출팀A\n영업: 영업A\n잘못된 줄")
            ->set('roomRoles', '재무: 송금 담당')
            ->call('saveRoom')->assertHasNoErrors();
        $room = KakaoRoom::where('name', '무사백-수출팀')->firstOrFail();
        $this->assertSame(['재무' => ['재무A', '재무B'], '수출팀' => ['수출팀A'], '영업' => ['영업A']], $room->sideMembers());
        $this->assertSame('송금 담당', $room->sides['재무__role']);

        // 내 표시명이 편 구성에 없으면 구독 거부
        $page->set('myNameFor.'.$room->id, '없는사람')->call('subscribeRoom', $room->id)->assertHasErrors(['myNameFor.'.$room->id]);
        $this->assertSame(0, $me->kakaoRooms()->count());

        $page->set('myNameFor.'.$room->id, '수출팀A')->call('subscribeRoom', $room->id)->assertHasNoErrors();
        $this->assertSame('수출팀A', $me->kakaoRooms()->first()->pivot->my_name);

        $page->set('roomSides', '')->set('roomName', 'x')->call('saveRoom')->assertHasErrors(['roomSides']);
    }

    public function test_token_is_shown_once_and_can_be_revoked(): void
    {
        $me = $this->user();
        $this->roomWith($me, '수출팀A');
        $this->actingAs($me);

        $page = Volt::test('erp.kakao.index')->call('toggleSetup')->set('deviceName', '사무실 PC')->call('issueToken');
        $plain = $page->get('tokenPlain');
        $this->assertStringStartsWith('kck_', $plain);
        $page->assertSee($plain);
        $token = KakaoCollectorToken::first();
        $this->assertSame('사무실 PC', $token->device_name);
        $this->assertSame(KakaoCollectorToken::hashOf($plain), $token->token_hash);
        $this->assertNotSame($plain, $token->token_hash);

        $page->call('revokeToken', $token->id);
        $this->assertNotNull($token->fresh()->revoked_at);
        $this->assertNull(KakaoCollectorToken::findActive($plain));
        $this->assertNull($page->get('tokenPlain'));
    }
}
