<?php

namespace Tests\Feature\Kakao;

use App\Models\KakaoCollectorToken;
use App\Models\KakaoMessage;
use App\Models\KakaoRoom;
use App\Models\Salesman;
use App\Models\Setting;
use App\Models\User;
use App\Services\Kakao\KakaoClassifierClient;
use App\Services\Kakao\KakaoPrompt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 「나」·「우리 회사」를 사람이 고르지 않는다 — ERP 명부(사용자·영업담당자 이름)로 자동 (jin 2026-11-10 «ERP 에 사용자 이름 있잖아? 왜 클릭을 하고 나눠야 하는데?»).
 * 못 맞춘 방만 「이 방에서 나는 누구?」 한 칸을 묻는다.
 */
class KakaoAutoSetupTest extends TestCase
{
    use RefreshDatabase;

    private FakeClassifier $fake;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::updateOrCreate(['key' => 'kakao_assistant_enabled_'.Setting::companyTemplateSet()], ['value' => '1', 'type' => 'boolean']);
        $this->fake = new FakeClassifier;
        $this->fake->plan = fn (array $ids) => array_map(fn ($id) => ['id' => $id, 'type' => '요청', 'to' => KakaoRoom::SIDE_US, 'mention' => null, 'ref' => null, 'vehicle' => null, 'vin_tail' => null, 'amount' => null, 'resolves' => null], $ids);
        $this->app->instance(KakaoClassifierClient::class, $this->fake);
    }

    private const TEXT = "2026년 11월 10일 월요일\n[김다정] [오후 1:05] 입금 확인 부탁드립니다\n[박수정 싼카] [오후 1:06] 네 확인할게요\n[최영숙] [오후 1:07] 처리했습니다\n[Steve] [오후 1:08] thanks\n[이영업] [오후 1:09] 매입 보고";

    public function test_first_read_sets_me_and_colleagues_from_the_erp_roster_and_classifies_at_once(): void
    {
        $me = User::factory()->create(['permission' => 'user', 'role' => '수출통관', 'name' => '김다정']);
        User::factory()->create(['permission' => 'user', 'role' => '재무', 'name' => '최영숙']);
        User::factory()->create(['permission' => 'user', 'role' => '관리', 'name' => '박수정']);          // 카톡 이름 「박수정 싼카」 — 품는 이름도 맞춘다
        User::factory()->create(['permission' => 'super', 'name' => 'Steve']);                            // 시스템관리자는 명부에서 뺀다
        Salesman::create(['name' => '이영업', 'type' => 'employee', 'is_active' => true]);               // 영업담당자 명부(로그인 안 함)
        [, $plain] = KakaoCollectorToken::issue($me, 'PC-1');

        $res = $this->withHeader('Authorization', 'Bearer '.$plain)->postJson('/api/kakao/ingest', ['room' => '수출팀', 'text' => self::TEXT]);

        $res->assertOk()->assertJsonPath('status', 'done');
        $room = KakaoRoom::sole();
        $this->assertSame('김다정', $me->kakaoRooms()->first()->pivot->my_name);
        $this->assertEqualsCanonicalizing(['김다정', '박수정 싼카', '최영숙', '이영업'], $room->sideMembers()[KakaoRoom::SIDE_US]);
        $this->assertSame(['Steve'], $room->sideMembers()[KakaoRoom::SIDE_THEM]);
        // 부서(ERP 역할)도 명부에서 — 프롬프트 「우리」 줄과 행 배지가 쓴다(jin 11-10 «부서별로 나눠서 보여주면»)
        $this->assertEquals(['김다정' => '수출통관', '박수정 싼카' => '관리', '최영숙' => '재무', '이영업' => '영업'], $room->personRoles());
        $this->assertStringContainsString('김다정(수출통관)', KakaoPrompt::system($room->sideMembers(), $room->sideRoles(), $room->personRoles()));
        $this->actingAs($me);
        Volt::test('erp.kakao.index')->assertSee('최영숙(재무)');
        $this->assertSame(1, $this->fake->calls, '설정을 기다리지 않고 바로 분류');
        $this->assertSame(0, KakaoMessage::whereNull('type')->count());
        $this->actingAs($me);
        $this->assertCount(0, Volt::test('erp.kakao.index')->viewData('needSetup'), '「나」 고르기 카드가 안 뜬다');
    }

    public function test_unmatched_name_falls_back_to_a_single_pick_with_colleagues_prefilled(): void
    {
        $me = User::factory()->create(['permission' => 'user', 'role' => '수출통관', 'name' => '홍길동']);   // 카톡 이름이 다르다
        User::factory()->create(['permission' => 'user', 'role' => '재무', 'name' => '최영숙']);
        [, $plain] = KakaoCollectorToken::issue($me, 'PC-1');

        $this->withHeader('Authorization', 'Bearer '.$plain)->postJson('/api/kakao/ingest', ['room' => '수출팀', 'text' => self::TEXT])
            ->assertOk()->assertJsonPath('status', 'pending_setup');
        $this->assertSame(0, $this->fake->calls);

        $this->actingAs($me);
        $room = KakaoRoom::sole();
        $page = Volt::test('erp.kakao.index');
        $this->assertCount(1, $page->viewData('needSetup'));
        $page->assertSee(__('kakao.setup_me_q', ['name' => '홍길동']));
        $this->assertSame(['최영숙'], $page->get('oursFor')[$room->id], '명부와 맞는 사람은 미리 체크');

        $page->set('meFor.'.$room->id, '김다정')->call('saveSetup', $room->id)->assertHasNoErrors();
        $this->assertSame('김다정', $me->kakaoRooms()->first()->pivot->my_name);
        $this->assertEqualsCanonicalizing(['김다정', '최영숙'], $room->fresh()->sideMembers()[KakaoRoom::SIDE_US]);
        $this->assertSame(1, $this->fake->calls);
    }

    public function test_second_user_in_an_already_set_up_room_gets_only_their_me_matched(): void
    {
        $first = User::factory()->create(['permission' => 'user', 'role' => '수출통관', 'name' => '김다정']);
        $second = User::factory()->create(['permission' => 'user', 'role' => '재무', 'name' => '최영숙']);
        [, $t1] = KakaoCollectorToken::issue($first, 'PC-1');
        [, $t2] = KakaoCollectorToken::issue($second, 'PC-2');
        $this->withHeader('Authorization', 'Bearer '.$t1)->postJson('/api/kakao/ingest', ['room' => '수출팀', 'text' => self::TEXT])->assertOk();
        $sidesBefore = KakaoRoom::sole()->sideMembers();

        $this->withHeader('Authorization', 'Bearer '.$t2)->postJson('/api/kakao/ingest', ['room' => '수출팀', 'text' => self::TEXT])->assertOk();

        $this->assertSame('최영숙', $second->kakaoRooms()->first()->pivot->my_name);
        $this->assertSame($sidesBefore, KakaoRoom::sole()->sideMembers(), '편은 방 단위 — 두 번째 사람이 들어와도 그대로');
    }

    public function test_name_matching_rules(): void
    {
        $senders = ['김다정', '박수정 싼카', '최 영숙', '이', 'Steve Kim'];
        $this->assertSame('김다정', KakaoRoom::matchName('김다정', $senders));
        $this->assertSame('박수정 싼카', KakaoRoom::matchName('박수정', $senders));
        $this->assertSame('최 영숙', KakaoRoom::matchName('최영숙', $senders));
        $this->assertSame('Steve Kim', KakaoRoom::matchName('steve', $senders));
        $this->assertNull(KakaoRoom::matchName('이', $senders), '한 글자는 안 맞춘다');
        $this->assertSame('김다정', KakaoRoom::matchName('김다', $senders), '두 글자 이상이면 품는 이름도 맞춘다');
    }
}
