<?php

namespace Tests\Feature\Kakao;

use App\Models\KakaoMessage;
use App\Models\KakaoRead;
use App\Models\KakaoRoom;
use App\Models\Setting;
use App\Models\User;
use App\Services\Kakao\KakaoClassifierClient;
use App\Services\Kakao\KakaoClassifyException;
use App\Services\Kakao\KakaoIngestService;
use App\Services\Kakao\KakaoTodoBoard;
use App\Services\Kakao\KakaoTopics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 11-11 — 큰 방 503건 「확인 필요」(한 호출 16k 출력 상한에서 잘림)와 「@이름」만·「넵」·같은 본문 15연발이 할 일에 섞이던 것.
 *   ① 분류는 60건씩 나눠 부르고, 한 요청의 시간 예산을 넘기면 나머지는 partial 로 남겨 「이어서 분류」가 끝낸다. 실패해도 앞 조각 결과·토큰은 남는다.
 *   ② 잡담(규칙)은 모델에 안 가고 창에 표식으로만 — 「넵」은 모델로 간다(앞 요청을 닫는 resolves, 실측 ssancarerp 34/137). ③ 「@이름」만은 같은 사람의 앞·뒤 메시지에 멘션으로 붙는다(늦게 온 뒷 메시지 포함).
 *   ④ 같은 사람이 10분 안에 같은 본문을 또 보내면 뒤 건은 「반복」. ⑤ 본문 없는 머리줄로 붙어 버린 옛 행은 kakao:resplit-glued 가 나눈다.
 */
class KakaoRuleLayerTest extends TestCase
{
    use RefreshDatabase;

    private const SIDES = ['우리' => ['수출팀A', '재무A'], '상대' => ['영업A', '포워더A']];

    private FakeClassifier $fake;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-11 10:00');   // 예시 글이 11-10 — 카톡 표시 기간(3일, 11-13)에 묶여 날짜가 지나면 깨진다
        Setting::updateOrCreate(['key' => 'kakao_assistant_enabled_'.Setting::companyTemplateSet()], ['value' => '1', 'type' => 'boolean']);   // 페이지(continueClassifying)는 회사 토글이 켜져야 연다
        $this->fake = new FakeClassifier;
        $this->app->instance(KakaoClassifierClient::class, $this->fake);
        $this->fake->plan = fn (array $ids) => array_map(fn ($id) => ['id' => $id, 'type' => '요청', 'to' => '우리', 'mention' => null, 'ref' => null, 'vehicle' => null, 'vin_tail' => null, 'amount' => null, 'resolves' => null], $ids);
        KakaoIngestService::$budgetSeconds = 40;
    }

    protected function tearDown(): void
    {
        KakaoIngestService::$budgetSeconds = 40;
        parent::tearDown();
    }

    private function room(): KakaoRoom
    {
        return KakaoRoom::create(['name' => '테스트방', 'sides' => self::SIDES]);
    }

    private function user(KakaoRoom $room, string $me = '수출팀A'): User
    {
        $u = User::factory()->create(['permission' => 'user', 'role' => '수출통관']);
        $room->users()->attach($u->id, ['my_name' => $me, 'selected' => true]);

        return $u;
    }

    /** n 건의 서로 다른 요청(분류 대상) — 1분 간격 */
    private function text(int $n, string $date = '2026년 11월 10일 화요일'): string
    {
        $lines = ["--------------- {$date} ---------------"];
        for ($i = 0; $i < $n; $i++) {
            $h = 9 + intdiv($i, 60);
            $m = $i % 60;
            $lines[] = sprintf('[영업A] [오전 %d:%02d] %d번 차량 말소증 부탁드립니다', $h, $m, $i + 1);
        }

        return implode("\n", $lines);
    }

    public function test_big_rooms_are_classified_in_chunks_of_sixty_with_one_read_row(): void
    {
        $room = $this->room();
        $read = app(KakaoIngestService::class)->ingest($this->user($room), $room, $this->text(150));

        $this->assertSame('done', $read->status);
        $this->assertSame(3, $this->fake->calls);   // 60 + 60 + 30
        $this->assertSame(0, $read->pendingAfter);
        $this->assertSame(0, KakaoMessage::whereNull('type')->count());
        $this->assertSame(1, KakaoRead::count());
        $this->assertSame(3 * 1500, $read->output_tokens);   // 조각 사용량이 한 회차에 합산
        $this->assertGreaterThan(0, (float) $read->cost_krw);
        // 두 번째 조각의 창에는 첫 조각의 미해결 요청이 「앞 읽기」로 들어간다 — resolves 가 조각을 넘어 이어진다
        $this->assertStringContainsString('아직 답이 없는 항목', $this->fake->lastUser);
    }

    public function test_time_budget_leaves_the_rest_pending_and_continue_finishes_it(): void
    {
        KakaoIngestService::$budgetSeconds = 0;   // 첫 조각 뒤 바로 예산 초과
        $room = $this->room();
        $user = $this->user($room);
        $read = app(KakaoIngestService::class)->ingest($user, $room, $this->text(150));

        $this->assertSame('partial', $read->status);
        $this->assertSame(1, $this->fake->calls);
        $this->assertSame(90, $read->pendingAfter);
        $this->assertSame(90, KakaoIngestService::pendingCount($room));

        // 화면의 「이어서 분류」 — 예산 안에서 한 방씩, 남은 건수를 돌려준다
        $this->actingAs($user);
        $page = Volt::test('erp.kakao.index');
        $page->call('continueClassifying')->assertReturned(['remaining' => 30, 'failed' => false, 'message' => null]);
        $page->call('continueClassifying')->assertReturned(fn ($r) => $r['remaining'] === 0 && $r['failed'] === false);
        $this->assertSame(3, $this->fake->calls);
        $this->assertSame(0, KakaoMessage::whereNull('type')->count());
    }

    public function test_a_failing_chunk_keeps_earlier_results_and_records_tokens(): void
    {
        $room = $this->room();
        $calls = 0;
        $this->fake->plan = function (array $ids) use (&$calls) {
            if (++$calls === 2) {
                $e = new KakaoClassifyException('모델 출력이 길이 상한에서 잘렸습니다(max_tokens)');
                $e->usage = ['input_tokens' => 100, 'cache_read_tokens' => 0, 'cache_write_tokens' => 0, 'output_tokens' => 5000];
                $e->model = 'claude-sonnet-5-5';
                throw $e;
            }

            return array_map(fn ($id) => ['id' => $id, 'type' => '요청', 'to' => '우리', 'mention' => null, 'ref' => null, 'vehicle' => null, 'vin_tail' => null, 'amount' => null, 'resolves' => null], $ids);
        };
        $read = app(KakaoIngestService::class)->ingest($this->user($room), $room, $this->text(100));

        $this->assertSame('failed', $read->status);
        $this->assertStringContainsString('max_tokens', $read->error);
        $this->assertSame(60, KakaoMessage::where('type', '요청')->count());   // 첫 조각은 남는다
        $this->assertSame(40, KakaoMessage::whereNull('type')->count());
        $this->assertSame(40, $read->pendingAfter);
        $this->assertSame(1500 + 5000, $read->output_tokens);   // 실패한 호출의 토큰도 기록(11-11 전엔 0원으로 남았다)
    }

    public function test_separators_and_repeats_skip_the_model_and_acks_still_resolve(): void
    {
        $room = $this->room();
        $user = $this->user($room);
        $this->fake->plan = function (array $ids) {   // 「넵」은 모델이 본다 — 앞 요청을 닫는 확인으로
            [$req, $ack, $rate, $late] = $ids;

            return [
                ['id' => $req, 'type' => '요청', 'to' => '우리', 'mention' => null, 'ref' => null, 'vehicle' => null, 'vin_tail' => null, 'amount' => '300만원', 'resolves' => null],
                ['id' => $ack, 'type' => '확인', 'to' => '상대', 'mention' => null, 'ref' => null, 'vehicle' => null, 'vin_tail' => null, 'amount' => null, 'resolves' => $req],
                ['id' => $rate, 'type' => '정보', 'to' => '우리', 'mention' => null, 'ref' => null, 'vehicle' => null, 'vin_tail' => null, 'amount' => null, 'resolves' => null],
                ['id' => $late, 'type' => '정보', 'to' => '우리', 'mention' => null, 'ref' => null, 'vehicle' => null, 'vin_tail' => null, 'amount' => null, 'resolves' => null],
            ];
        };
        $text = <<<'TXT'
--------------- 2026년 11월 10일 화요일 ---------------
[영업A] [오후 8:24] 계약금 300 부탁드립니다
[재무A] [오후 8:25] 넵 알겠습니다
[영업A] [오후 8:25] 1480€ / 1320$
[영업A] [오후 8:26] 1480€ / 1320$
[영업A] [오후 8:26] 1480€ / 1320$
[영업A] [오후 8:27] ------------------------------
[영업A] [오후 8:40] 1480€ / 1320$
TXT;
        $read = app(KakaoIngestService::class)->ingest($user, $room, $text);

        $this->assertSame('done', $read->status);
        $byBody = KakaoMessage::orderBy('id')->get();
        $this->assertSame(['요청', '확인', '정보', '반복', '반복', '잡담', '정보'], $byBody->pluck('type')->all());   // 10분 넘은 마지막 건은 새 메시지
        $this->assertSame('repeat', $byBody[3]->ai['rule']);
        $this->assertSame($byBody[2]->id, $byBody[3]->ai['of']);
        $this->assertSame($byBody[0]->id, $byBody[1]->resolves_id);   // 넵이 요청을 닫는다 — 규칙으로 먼저 찍었으면 이 연결이 영영 없었다
        // 모델 창: 분류 대상은 4줄(요청·넵·환율·늦은 환율), 반복·잡담은 표식
        $this->assertStringContainsString('] 넵 알겠습니다', $this->fake->lastUser);
        $this->assertStringContainsString('(규칙·반복:', $this->fake->lastUser);
        $this->assertStringContainsString('(규칙·잡담:', $this->fake->lastUser);
        $this->assertSame(2, substr_count($this->fake->lastUser, '] 1480€ / 1320$'));

        $board = KakaoTodoBoard::forUser($user, now(), true);
        $ids = array_merge(...array_map(fn ($b) => array_column($b, 'id'), array_values($board['buckets'])));
        $this->assertNotContains($byBody[1]->id, $ids);   // 확인
        $this->assertNotContains($byBody[3]->id, $ids);   // 반복
        $this->assertNotContains($byBody[5]->id, $ids);   // 잡담
        $this->assertContains($byBody[0]->id, array_column($board['buckets']['done'], 'id'));   // 답(넵)이 달린 요청 → 「오늘 끝난 것」 자동 완료 후보
    }

    public function test_mention_only_messages_attach_to_the_neighbour_and_drop_out(): void
    {
        $room = $this->room();
        $me = $this->user($room, '재무A');
        $text = <<<'TXT'
--------------- 2026년 11월 10일 화요일 ---------------
[영업A] [오전 9:20] 차량 1대 준비되지 않아, 대체차량 1대 확인 부탁드립니다
[영업A] [오전 9:20] @재무A
[포워더A] [오전 10:17] @수출팀A
[포워더A] [오전 10:18] 캐리어 오더 싼카쪽에서 진행하시는거죠?
[영업A] [오전 11:00] @재무A
TXT;
        app(KakaoIngestService::class)->ingest($me, $room, $text);

        $m = KakaoMessage::orderBy('id')->get();
        $this->assertSame(['요청', '호출', '호출', '요청', '호출'], $m->pluck('type')->all());
        $this->assertSame($m[0]->id, $m[1]->ai['target']);           // 뒤에 붙은 @ → 같은 사람의 바로 앞 메시지
        $this->assertSame('재무A', $m[0]->mention);
        $this->assertSame($m[3]->id, $m[2]->ai['target']);           // 앞에 온 @ → 같은 사람의 다음 메시지
        $this->assertSame('수출팀A', $m[3]->mention);
        $this->assertNull($m[4]->ai['target']);                      // 붙일 곳 없음 — 창에 표식만
        $this->assertStringContainsString('(규칙·호출: @재무A → id='.$m[0]->id.' 에 붙음)', $this->fake->lastUser);
        $this->assertStringNotContainsString('id='.$m[4]->id.' ', $this->fake->lastUser);   // 마지막 분류 대상 뒤의 표식은 창에 안 들어간다(조각 사이만)

        // 재무A 화면: 「@재무A」 행은 없고, 내용이 있는 요청 하나가 내 일로. 수출팀A 요청은 멘션이 다른 사람이라 안 보인다
        $board = KakaoTodoBoard::forUser($me, now());
        $work = array_merge(...array_map(fn ($k) => array_column($board['buckets'][$k], 'id'), ['missed', 'now', 'today', 'waiting', 'teammate']));   // 내 일·동료 — 「나와 무관」은 뺀다
        $this->assertContains($m[0]->id, $work);
        $this->assertNotContains($m[1]->id, $work);
        $this->assertNotContains($m[4]->id, $work);
        $this->assertNotContains($m[3]->id, $work);
        $this->assertContains($m[3]->id, array_column($board['buckets']['none'], 'id'));   // 수출팀A 를 콕 집은 요청 → 재무A 에겐 「나와 무관」

        // 늦게 온 뒷 메시지 — 앞 읽기 끝에 붙일 곳 없던 호출이 다음 읽기의 같은 사람 메시지에 붙는다
        $later = $text."\n[영업A] [오전 11:05] 말소증 부탁드립니다";
        app(KakaoIngestService::class)->ingest($me, $room, $later);
        $new = KakaoMessage::orderBy('id')->get()->last();
        $this->assertSame('말소증 부탁드립니다', $new->body);
        $this->assertSame('재무A', $new->mention);
        $this->assertSame($new->id, $m[4]->fresh()->ai['target']);
    }

    public function test_pending_rows_from_before_the_rule_layer_get_rule_typed_on_continue(): void
    {
        $room = $this->room();
        $user = $this->user($room);
        foreach ([['------', '잡담'], ['ㅁㅁㅁㅁㅁ', '잡담'], ['말소증 부탁드립니다', '요청']] as $i => [$body, $expect]) {
            KakaoMessage::create(['room_id' => $room->id, 'msg_key' => 'k'.$i, 'sent_at' => now()->subHour()->addMinutes($i), 'sender' => '영업A', 'body' => $body]);
        }
        $read = app(KakaoIngestService::class)->classifyPending($room, $user);

        $this->assertSame('done', $read->status);
        $this->assertSame(['잡담', '잡담', '요청'], KakaoMessage::orderBy('id')->pluck('type')->all());
        $this->assertStringNotContainsString('] ------', $this->fake->lastUser);
    }

    public function test_resplit_command_separates_a_header_that_was_glued_into_a_body(): void
    {
        $room = $this->room();
        $glued = KakaoMessage::create([
            'room_id' => $room->id, 'msg_key' => 'g1', 'sent_at' => '2026-11-04 15:22:00', 'sender' => '영업A', 'type' => '요청', 'to_side' => '우리',
            'body' => "사진 5장\n[영업A] [오후 3:22]\n차량명   HYUNDAI SONATA\n차량번호:   371어7482\n[재무A] [오후 3:30]\n계좌번호가 확인이 되야 송금해드려요",
        ]);
        KakaoMessage::create(['room_id' => $room->id, 'msg_key' => 'ok', 'sent_at' => '2026-11-04 15:40:00', 'sender' => '영업A', 'type' => '요청', 'body' => "정상 메시지\n이어지는 줄"]);

        $this->artisan('kakao:resplit-glued')->expectsOutputToContain('대상 1행')->assertSuccessful();
        $this->assertSame(2, KakaoMessage::count());   // dry-run

        $this->artisan('kakao:resplit-glued', ['--apply' => true])->assertSuccessful();
        $rows = KakaoMessage::orderBy('id')->get();
        $this->assertCount(4, $rows);
        $this->assertSame('사진 5장', $glued->fresh()->body);
        $this->assertSame('첨부', $glued->fresh()->type);   // 사진이 요청으로 둔갑했던 것이 첨부로 돌아온다
        $this->assertSame("차량명   HYUNDAI SONATA\n차량번호:   371어7482", $rows[2]->body);
        $this->assertSame('2026-11-04 15:22:00', $rows[2]->sent_at->format('Y-m-d H:i:s'));
        $this->assertNull($rows[2]->type);   // 「이어서 분류」가 분류
        $this->assertSame('재무A', $rows[3]->sender);
        $this->assertSame('2026-11-04 15:30:00', $rows[3]->sent_at->format('Y-m-d H:i:s'));

        $this->artisan('kakao:resplit-glued', ['--apply' => true])->assertSuccessful();   // 두 번 돌려도 그대로
        $this->assertSame(4, KakaoMessage::count());
    }

    /** jin 11-11 «서식 요청은 앞뒤 문맥상 처리됐는지가 중요» — 우리 편이 같은 차량번호·차대번호 끝자리를 적어 올리면 그 요청이 닫힌다(모델이 못 이었어도). */
    public function test_a_report_quoting_the_same_vehicle_closes_the_open_request(): void
    {
        $room = $this->room();
        $me = $this->user($room);
        $this->fake->plan = function (array $ids) {
            [$a, $b, $c, $d, $e, $f] = $ids;
            $row = fn ($id, $type) => ['id' => $id, 'type' => $type, 'to' => '우리', 'mention' => null, 'ref' => null, 'vehicle' => null, 'vin_tail' => null, 'amount' => null, 'resolves' => null];

            return [$row($a, '요청'), $row($b, '요청'), $row($c, '정보'), $row($d, '요청'), $row($e, '대답'), $row($f, '대답')];
        };
        $text = <<<'TXT'
--------------- 2026년 11월 6일 금요일 ---------------
[영업A] [오전 10:32] 차량명   Peugeot 508
차량번호:   259러8403
차대번호   VR3FHEHZRLY001234
계약금: 50만원 입금 부탁드립니다
[영업A] [오전 10:35] 차량명   VOLKSWAGEN Golf
차량번호:   48조5021
차대번호   WVWZZZAUZLW012555
계약금: 100만원 입금 부탁드립니다
[재무A] [오전 11:02] 012555 / CALJA 판매 € 6,130
[재무A] [오전 11:05] 259러8403 저당 있습니다, 확인 부탁드립니다
[영업A] [오전 11:10] 259러8403 입금 확인했습니다
[재무A] [오후 2:00] 259러8403 이체 완료
TXT;
        app(KakaoIngestService::class)->ingest($me, $room, $text);
        $m = KakaoMessage::orderBy('id')->get();

        $this->assertSame($m[1]->id, $m[2]->resolves_id);   // 차대번호 끝 6자리로 Golf 서식을 닫는다(모델은 정보로만 찍고 resolves 없음)
        $this->assertSame('vehicle', $m[2]->fresh()->ai['auto_resolve']);
        $this->assertNull($m[3]->resolves_id);              // 「저당 있습니다, 확인 부탁」 = 요청 — 닫지 않는다
        $this->assertSame($m[3]->id, $m[4]->resolves_id);   // 영업의 「입금 확인했습니다」는 자기 서식이 아니라 재무의 「저당 있습니다, 확인 부탁」(다른 사람의 열린 요청)을 닫는다
        $this->assertSame($m[0]->id, $m[5]->resolves_id);   // 재무의 「이체 완료」가 Peugeot 서식을 닫는다(차량번호) — 자기가 올린 요청은 후보가 아니다

        // 예제 날짜(11-06)가 박혀 있다 — 화면 표시 기간(3일) 안의 시점으로 본다(오늘 날짜에 기대면 며칠 뒤 창 밖으로 나가 깨진다).
        $board = KakaoTodoBoard::forUser($me, Carbon::parse('2026-11-07 09:00'), true);
        $done = array_column($board['buckets']['done'], 'id');
        $this->assertContains($m[0]->id, $done);
        $this->assertContains($m[1]->id, $done);
        $this->assertContains($m[3]->id, $done);   // 재무의 확인 부탁도 영업의 「입금 확인했습니다」로 닫혔다
    }

    public function test_vehicle_identifiers_ignore_amounts(): void
    {
        $this->assertSame(['P:48조5021', 'VIN:WVWZZZAUZLW012555', 'V:012555'], KakaoIngestService::vehicleIdentifiers("차량번호:   48조5021\n차대번호   WVWZZZAUZLW012555\n차량대금:   1,700만원 ⏎ 500000원"));
        $this->assertSame(['V:131538'], KakaoIngestService::vehicleIdentifiers('131538로 들어왔는데 확인 부탁드립니다'));
        $this->assertSame([], KakaoIngestService::vehicleIdentifiers('계약금 300 부탁드립니다 1480€ / 1320$ 500,000원'));
        $this->assertSame(['P:55두8855'], KakaoIngestService::vehicleIdentifiers('헤이55두8855'));
    }

    /** 11-11 — 카톡 방이 아닌 창(저장 대화상자·그림자 창·대화 내보내기 창)은 방으로 등록하지 않는다(김다정 PC 실측 제목). */
    public function test_non_room_window_titles_are_not_rooms(): void
    {
        foreach (['다른 이름으로 저장', 'KakaoTalkShadowWnd', '싼카-수출팀김다정 2026-11-11', '싼카-수출팀 박수정 대리 2026-11-11', '카카오톡', ''] as $t) {
            $this->assertFalse(KakaoRoom::isRoomTitle($t), $t);
        }
        foreach (['! 무사백-수출팀', '# 김연아 - 통관서류,BL,면장 방', '%프롬 - 이용빈', '■■ 수출팀', 'CIG X 싼카 두레스'] as $t) {
            $this->assertTrue(KakaoRoom::isRoomTitle($t), $t);
        }
    }

    /** 11-11 김다정 CIG 컨테이너 — 그 방에서 한 번도 말하지 않은 사람은 보낸이 목록에 없어 「나」를 못 잡았다 → 다른 방에서 잡힌 카톡 표시명을 쓴다. */
    public function test_a_silent_member_gets_their_kakao_name_from_other_rooms(): void
    {
        $user = User::factory()->create(['name' => '김다정', 'permission' => 'user', 'role' => '수출통관']);
        $talked = KakaoRoom::create(['name' => '말한 방']);
        $talked->users()->attach($user->id, ['my_name' => '', 'selected' => true]);
        app(KakaoIngestService::class)->ingest($user, $talked, '--------------- 2026년 11월 10일 화요일 ---------------
[싼카-수출팀김다정] [오전 9:00] 서류 전달드립니다
[영업A] [오전 9:05] 말소증 부탁드립니다');
        $this->assertSame('싼카-수출팀김다정', $talked->users()->first()->pivot->my_name);

        $silent = KakaoRoom::create(['name' => '말 안 한 방']);
        $silent->users()->attach($user->id, ['my_name' => '', 'selected' => true]);
        app(KakaoIngestService::class)->ingest($user, $silent, '--------------- 2026년 11월 10일 화요일 ---------------
[포워더A] [오전 9:00] 운임 안내드립니다
[영업A] [오전 9:05] 부킹 부탁드립니다');
        $silent->refresh();
        $this->assertSame('싼카-수출팀김다정', $silent->users()->first()->pivot->my_name);
        $this->assertContains('싼카-수출팀김다정', $silent->sideMembers()['우리']);
        $this->assertNotContains('포워더A', $silent->sideMembers()['우리']);

        $empty = KakaoRoom::create(['name' => '아직 안 읽은 방']);   // 글 0건 방은 근거가 없어 잡지 않는다
        $empty->users()->attach($user->id, ['my_name' => '', 'selected' => true]);
        $this->assertFalse($empty->autoSetUpFor($user));
    }

    /** jin 11-11 «비용은 넣지 마 · 모달로 크게» — 한도에 닿으면 모달 + 큰 띠, 금액은 안 보인다. */
    public function test_cap_reached_shows_a_modal_without_amounts(): void
    {
        Setting::updateOrCreate(['key' => 'kakao_daily_cost_cap_krw'], ['value' => '100', 'type' => 'integer']);
        $room = $this->room();
        $user = $this->user($room);
        KakaoRead::create(['room_id' => $room->id, 'user_id' => $user->id, 'started_at' => now(), 'cost_krw' => 2080, 'status' => 'done']);
        $this->actingAs($user);
        $html = Volt::test('erp.kakao.index')->html();
        $this->assertStringContainsString('오늘 분류 한도에 닿았습니다', $html);
        preg_match('/data-kakao-capped.*?<\/button>/su', $html, $m);   // 모달 안
        $this->assertNotEmpty($m);
        $this->assertStringNotContainsString('2,080', $m[0]);
        $this->assertStringNotContainsString('원', $m[0]);
    }

    /** 담당 분야(jin 11-11 «본인이 체크해서 보게») — 고른 분야가 아닌 요청은 팀 동료로, @나 는 분야 무관 내 일, 안 고르면 전부 내 일. */
    public function test_topics_route_requests_to_teammate_unless_mine_or_mentioned(): void
    {
        $room = $this->room();
        $me = $this->user($room, '수출팀A');
        $at = now()->subHour();
        $mk = fn (string $body, ?string $topic, ?string $mention = null) => KakaoMessage::create(['room_id' => $room->id, 'msg_key' => md5($body), 'sent_at' => $at, 'sender' => '영업A', 'body' => $body, 'type' => '요청', 'to_side' => '우리', 'topic' => $topic, 'mention' => $mention]);
        $doc = $mk('면장 부탁드립니다', '서류');
        $ins = $mk('보험 부탁드립니다', '보험');
        $call = $mk('@수출팀A 보험 확인 부탁', '보험', '수출팀A');
        $old = $mk('예전 글', null);

        $ids = fn ($b, $k) => array_column($b['buckets'][$k], 'id');
        $before = KakaoTodoBoard::forUser($me, now());   // 안 고름 = 전부 내 일
        $this->assertNull($before['topics']);
        $this->assertEqualsCanonicalizing([$doc->id, $ins->id, $call->id, $old->id], array_merge($ids($before, 'today'), $ids($before, 'now')));

        KakaoTopics::save($me, ['등록', '서류']);
        $after = KakaoTodoBoard::forUser($me, now());
        $mine = array_merge($ids($after, 'today'), $ids($after, 'now'));
        $this->assertEqualsCanonicalizing([$doc->id, $call->id, $old->id], $mine);   // 분야 모르는 옛 글은 놓치느니 보인다
        $this->assertSame([$ins->id], $ids($after, 'teammate'));
        $this->assertSame('다른 분야(보험)', $after['buckets']['teammate'][0]['reason']);

        KakaoTopics::save($me, ['전체']);
        $this->assertSame([], $ids(KakaoTodoBoard::forUser($me, now()), 'teammate'));
    }

    /** 완료 박기(jin 11-11) — 답이 오늘 온 것만 「오늘 끝난 것」, 지난 날 답은 「완료된 것 보기」에서만. */
    public function test_done_shows_only_replies_from_today_unless_asked(): void
    {
        $room = $this->room();
        $me = $this->user($room, '수출팀A');
        $req = fn (string $b, $at) => KakaoMessage::create(['room_id' => $room->id, 'msg_key' => md5($b), 'sent_at' => $at, 'sender' => '영업A', 'body' => $b, 'type' => '요청', 'to_side' => '우리']);
        $rep = fn (string $b, $at, $to) => KakaoMessage::create(['room_id' => $room->id, 'msg_key' => md5($b), 'sent_at' => $at, 'sender' => '수출팀A', 'body' => $b, 'type' => '대답', 'to_side' => '상대', 'resolves_id' => $to->id]);
        $y = $req('어제 요청', now()->subDays(2));
        $rep('어제 답', now()->subDays(1)->startOfDay()->addHours(10), $y);
        $t = $req('오늘 요청', now()->startOfDay()->addMinutes(5));
        $rep('오늘 답', now()->startOfDay()->addMinutes(10), $t);

        $this->assertSame([$t->id], array_column(KakaoTodoBoard::forUser($me, now())['buckets']['done'], 'id'));
        $this->assertEqualsCanonicalizing([$t->id, $y->id], array_column(KakaoTodoBoard::forUser($me, now(), true)['buckets']['done'], 'id'));
    }
}
