<?php

namespace Tests\Feature\Kakao;

use App\Models\KakaoMessage;
use App\Models\KakaoRead;
use App\Models\KakaoRoom;
use App\Models\Setting;
use App\Models\User;
use App\Services\Kakao\KakaoTopics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 읽을 방 고르기 (jin 11-12) — 이름순 고정 · 전체 체크/해제 · 글 0건 방은 「아직 읽힌 글 없음」 · 못 읽은 방은 이유를 화면에.
 * 실례: ssancarerp 김다정 — 「CIG X 싼카 두레스」·1:1 두 방이 한 번도 서버에 안 왔는데(에이전트가 보내기 전에 멈춤) 「나 필요」만 떠
 * 「다시 고르기」를 아무리 눌러도 고를 사람이 없었다.
 */
class KakaoRoomPickTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::updateOrCreate(['key' => 'kakao_assistant_enabled_'.Setting::companyTemplateSet()], ['value' => '1', 'type' => 'boolean']);
        $this->me = User::factory()->create(['permission' => 'user', 'role' => '수출통관']);
    }

    private function room(string $name, bool $selected, string $me = ''): KakaoRoom
    {
        $r = KakaoRoom::create(['name' => $name]);
        $r->users()->attach($this->me->id, ['my_name' => $me, 'selected' => $selected, 'last_seen_at' => now()]);

        return $r;
    }

    public function test_rooms_are_listed_by_name_and_can_be_toggled_all_at_once(): void
    {
        $this->room('다방', true);
        $this->room('가방', false);
        $this->room('나방', false);

        $c = Volt::actingAs($this->me)->test('erp.kakao.index');
        $html = $c->html();
        $this->assertLessThan(strpos($html, 'data-room-name="나방"'), strpos($html, 'data-room-name="가방"'));
        $this->assertLessThan(strpos($html, 'data-room-name="다방"'), strpos($html, 'data-room-name="나방"'));   // 체크된 「다방」이 앞으로 튀지 않는다

        $c->call('setAllRooms', true);
        $this->assertSame(3, $this->me->kakaoRooms()->wherePivot('selected', true)->count());
        $c->call('setAllRooms', false);
        $this->assertSame(0, $this->me->kakaoRooms()->wherePivot('selected', true)->count());
    }

    public function test_a_room_with_no_messages_says_so_instead_of_asking_for_me(): void
    {
        $empty = $this->room('CIG X 싼카 두레스', true);
        $html = Volt::actingAs($this->me)->test('erp.kakao.index')->html();

        $this->assertStringContainsString('data-room-empty="'.$empty->id.'"', $html);
        $this->assertStringContainsString('아직 읽힌 글 없음', $html);
    }

    public function test_read_results_mark_rooms_the_server_never_received(): void
    {
        $ok = $this->room('무사백-수출팀', true, '수출팀A');
        $bad = $this->room('CIG X 싼카 두레스', true);
        KakaoMessage::create(['room_id' => $ok->id, 'msg_key' => 'a', 'sent_at' => now(), 'sender' => '수출팀A', 'body' => 'x', 'type' => '정보']);
        KakaoRead::create(['room_id' => $ok->id, 'user_id' => $this->me->id, 'started_at' => now(), 'status' => 'done']);   // 서버가 받은 방

        $c = Volt::actingAs($this->me)->test('erp.kakao.index')->call('recordReadResults', [
            '10:40 읽기 시작 — 2개 방. 끝날 때까지 키보드·마우스에서 손을 떼 주세요.',
            '10:40 [CIG X 싼카 두레스] 닫혀 있어 카톡에서 엽니다…',
            '10:41 [CIG X 싼카 두레스] 「CIG X 싼카 두레스」 대화 목록에 포커스를 못 잡았습니다(검색칸이 열려 있으면 닫아 주세요).',
            '10:41 [무사백-수출팀] 새 메시지 2건 분류 완료',
        ], now()->subMinutes(2)->format('Y-m-d\TH:i:s'));

        $pivot = fn ($r) => $this->me->kakaoRooms()->where('kakao_rooms.id', $r->id)->first()->pivot;
        $this->assertFalse((bool) $pivot($bad)->last_result_ok);
        $this->assertStringContainsString('포커스를 못 잡았습니다', $pivot($bad)->last_result);
        $this->assertTrue((bool) $pivot($ok)->last_result_ok);

        $html = $c->html();
        $this->assertStringContainsString('data-room-failed="'.$bad->id.'"', $html);
        $this->assertStringNotContainsString('data-room-failed="'.$ok->id.'"', $html);
        $this->assertMatchesRegularExpression('/data-rooms-failed-list[\s\S]*「CIG X 싼카 두레스」 못 읽음[\s\S]*포커스를 못 잡았습니다/u', $html);
    }

    public function test_first_topic_picker_saves_several_at_once(): void
    {
        $this->room('무사백-수출팀', true, '수출팀A');
        $c = Volt::actingAs($this->me)->test('erp.kakao.index');
        $html = $c->html();
        $this->assertStringContainsString('data-kakao-topic-picker', $html);
        $picker = substr($html, strpos($html, 'data-kakao-topic-picker'), 12000);
        $this->assertStringNotContainsString('toggleTopic', $picker, '창 안 칩은 누르는 대로 저장하지 않는다(하나 누르면 창이 닫혔다)');
        $this->assertStringContainsString('data-topic-save', $picker);

        $c->call('saveTopics', ['등록', '서류', '엉뚱한값']);
        $this->assertSame(['등록', '서류'], KakaoTopics::of($this->me));
        $this->assertStringNotContainsString('data-kakao-topic-picker', $c->html());

        $c->call('saveTopics', ['등록', '전체']);
        $this->assertSame(['전체'], KakaoTopics::of($this->me));
    }
}
