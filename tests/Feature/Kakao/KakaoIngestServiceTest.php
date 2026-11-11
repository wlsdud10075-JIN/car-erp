<?php

namespace Tests\Feature\Kakao;

use App\Models\KakaoMessage;
use App\Models\KakaoRead;
use App\Models\KakaoRoom;
use App\Models\Salesman;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Kakao\KakaoClassifierClient;
use App\Services\Kakao\KakaoIngestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 적재 서비스 — 파싱 → 중복 제거 → 저장 → 분류(가짜 모델) → 차량 매칭 → 회차 기록.
 * 가짜 모델은 받은 창 텍스트를 기록하고, id 별로 미리 정한 판정을 돌려준다(이름은 가명).
 */
class KakaoIngestServiceTest extends TestCase
{
    use RefreshDatabase;

    private const SIDES = ['재무' => ['재무A'], '수출팀' => ['수출팀A', '수출팀B'], '영업' => ['영업A']];

    private const TEXT = <<<'TXT'
--------------- 2026년 6월 10일 수요일 ---------------
[영업A] [오전 10:58] 1074 송금 부탁 드립니다
[재무A] [오전 11:02] 172가4114
[재무A] [오전 11:02] 사진
[수출팀A] [오후 12:09] 131538로 들어왔는데 혹시 다른거랑 같이 들어온건지 확인 부탁드립니다
TXT;

    private FakeClassifier $fake;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fake = new FakeClassifier;
        $this->app->instance(KakaoClassifierClient::class, $this->fake);
    }

    private function room(): KakaoRoom
    {
        return KakaoRoom::create(['name' => '테스트방', 'sides' => self::SIDES, 'roles' => ['영업' => '차량을 파는 쪽(테스트 설명)']]);
    }

    private function user(): User
    {
        return User::factory()->create(['permission' => 'user', 'role' => '수출통관']);
    }

    public function test_first_read_stores_classifies_links_and_records_cost(): void
    {
        $salesman = Salesman::create(['name' => '담당A', 'is_active' => true, 'type' => 'employee']);
        $vehicle = Vehicle::create(['vehicle_number' => '172가4114', 'sales_channel' => 'export', 'salesman_id' => $salesman->id]);
        $vinVehicle = Vehicle::create(['vehicle_number' => '38누0655', 'sales_channel' => 'export', 'salesman_id' => $salesman->id, 'nice_reg_vin' => 'WAUZZZ8VXG1131538']);

        $room = $this->room();
        $this->fake->plan = function (array $ids) {
            // ids = 분류 대상(첨부 제외) DB id 순서대로: [송금 요청, 이체 보고, 확인 부탁]
            [$req, $reply, $ask] = $ids;

            return [
                ['id' => $req, 'type' => '요청', 'to' => '재무', 'mention' => null, 'ref' => null, 'vehicle' => null, 'vin_tail' => null, 'amount' => '1074만원', 'resolves' => null],
                ['id' => $reply, 'type' => '대답', 'to' => '영업', 'mention' => null, 'ref' => null, 'vehicle' => '172가4114', 'vin_tail' => null, 'amount' => null, 'resolves' => $req],
                ['id' => $ask, 'type' => '요청', 'to' => '영업', 'mention' => null, 'ref' => null, 'vehicle' => null, 'vin_tail' => '131538', 'amount' => null, 'resolves' => 999999],
            ];
        };

        $read = app(KakaoIngestService::class)->ingest($this->user(), $room, self::TEXT);

        $this->assertSame('done', $read->status);
        $this->assertSame(4, $read->total);
        $this->assertSame(4, $read->new_count);
        $this->assertSame(self::SIDES, $read->sides_snapshot);
        $this->assertSame(1, $this->fake->calls);

        $msgs = KakaoMessage::where('room_id', $room->id)->orderBy('id')->get();
        $this->assertCount(4, $msgs);
        [$req, $reply, $photo, $ask] = $msgs;

        $this->assertSame('요청', $req->type);
        $this->assertSame('재무', $req->to_side);
        $this->assertSame('1074만원', $req->amount);

        $this->assertSame('대답', $reply->type);
        $this->assertSame($req->id, $reply->resolves_id);              // resolves = DB id 그대로
        $this->assertSame($vehicle->id, $reply->vehicle_id);           // 차량번호 정확 일치

        $this->assertSame('첨부', $photo->type);                        // 파서가 찍고 모델엔 안 보냄
        $this->assertNull($photo->ai);

        $this->assertSame('131538', $ask->vin_tail);
        $this->assertSame($vinVehicle->id, $ask->vehicle_id);          // 차대번호 끝 6자리
        $this->assertNull($ask->resolves_id);                          // 모르는 id 는 버린다

        // 창 텍스트: DB id 로 적고, 첨부는 표식만
        $this->assertStringContainsString("id={$req->id} 2026-06-10 10:58 [영업A] 1074 송금 부탁 드립니다", $this->fake->lastUser);
        $this->assertStringContainsString("id={$photo->id} 2026-06-10 11:02 [재무A] (첨부: 사진)", $this->fake->lastUser);
        // 시스템 프롬프트 방 구성 + 스키마 to enum 이 같은 sides 에서 나온다
        $this->assertStringContainsString(': 수출팀A, 수출팀B', $this->fake->lastSystem);
        $this->assertStringContainsString('- 수출팀 — 말소·저당', $this->fake->lastSystem);   // 역할 설명이 없으면 기본 설명이 붙는다
        $this->assertStringContainsString('- 영업 — 차량을 파는 쪽(테스트 설명): 영업A', $this->fake->lastSystem);   // 방의 roles 칸이 기본 설명을 덮는다
        $this->assertSame(['재무', '수출팀', '영업', '없음'], $this->fake->lastSchema['properties']['items']['items']['properties']['to']['enum']);

        $this->assertGreaterThan(0, (float) $read->cost_krw);
        $this->assertSame('claude-sonnet-5-5', $read->model);
    }

    public function test_second_read_of_the_same_text_stores_nothing_and_does_not_call_the_model(): void
    {
        $room = $this->room();
        $this->fake->plan = fn (array $ids) => array_map(fn ($id) => ['id' => $id, 'type' => '정보', 'to' => '없음', 'mention' => null, 'ref' => null, 'vehicle' => null, 'vin_tail' => null, 'amount' => null, 'resolves' => null], $ids);
        $svc = app(KakaoIngestService::class);
        $user = $this->user();

        $svc->ingest($user, $room, self::TEXT);
        $second = $svc->ingest($user, $room, self::TEXT);

        $this->assertSame(1, $this->fake->calls);
        $this->assertSame(0, $second->new_count);
        $this->assertSame('done', $second->status);
        $this->assertSame(4, KakaoMessage::where('room_id', $room->id)->count());
    }

    public function test_longer_body_on_a_later_read_updates_instead_of_duplicating(): void
    {
        $room = $this->room();
        $this->fake->plan = fn (array $ids) => array_map(fn ($id) => ['id' => $id, 'type' => '요청', 'to' => '재무', 'mention' => null, 'ref' => null, 'vehicle' => null, 'vin_tail' => null, 'amount' => null, 'resolves' => null], $ids);
        $svc = app(KakaoIngestService::class);
        $user = $this->user();
        $short = "--------------- 2026년 6월 10일 수요일 ---------------\n[영업A] [오전 8:44] 차량명   AUDI A3\n차량번호:   38누0655";
        $long = $short."\n차대번호   WAUZZZ8VXG1078563\n바이어:   DONI";

        $svc->ingest($user, $room, $short);
        $svc->ingest($user, $room, $long);

        $this->assertSame(1, KakaoMessage::where('room_id', $room->id)->count());
        $this->assertStringEndsWith('바이어:   DONI', KakaoMessage::first()->body);
        $this->assertSame(1, $this->fake->calls);
    }

    public function test_model_failure_keeps_messages_and_marks_the_read_failed(): void
    {
        $room = $this->room();
        $this->fake->plan = fn () => throw new \RuntimeException('API down');

        $read = app(KakaoIngestService::class)->ingest($this->user(), $room, self::TEXT);

        $this->assertSame('failed', $read->status);
        $this->assertStringContainsString('API down', $read->error);
        $this->assertSame(4, KakaoMessage::where('room_id', $room->id)->count());
        $this->assertSame(3, KakaoMessage::where('room_id', $room->id)->whereNull('type')->count());   // 「확인 필요」 로 간다
    }

    public function test_limits_reject_before_storing(): void
    {
        $room = $this->room();
        $user = $this->user();
        $svc = app(KakaoIngestService::class);

        Setting::updateOrCreate(['key' => 'kakao_max_new_per_read'], ['value' => '50', 'type' => 'integer']);
        $big = "--------------- 2026년 6월 10일 수요일 ---------------\n".implode("\n", array_map(fn ($i) => "[영업A] [오전 9:00] 입금 부탁 {$i}", range(1, 60)));
        // 읽기 1회 상한은 거부가 아니라 「오래된 것부터 상한까지」(jin 11-10) — 나머지는 다음 읽기에
        $read = $svc->ingest($user, $room, $big);
        $this->assertSame(10, $read->leftover);
        $this->assertSame(50, $read->new_count);
        $this->assertSame(50, KakaoMessage::count());
        $this->assertSame(1, KakaoRead::count());

        // 하루 비용 상한 — 🔀 11-11: 거부하지 않고 저장만, 분류는 미룬다(상한 뒤 처음 읽은 방이 0건으로 남아 「나」도 못 잡던 것)
        Setting::updateOrCreate(['key' => 'kakao_daily_cost_cap_krw'], ['value' => '100', 'type' => 'integer']);
        KakaoRead::create(['room_id' => $room->id, 'user_id' => $user->id, 'started_at' => now(), 'cost_krw' => 150, 'status' => 'done']);
        $calls = $this->fake->calls;
        $capped = $svc->ingest($user, $room, self::TEXT);
        $this->assertSame('capped', $capped->status);
        $this->assertSame($calls, $this->fake->calls);   // 모델 호출 없음
        $this->assertSame(3, $capped->pendingAfter);
        $this->assertSame(54, KakaoMessage::count());   // 저장은 됐다(50 + 새 4)
        $this->assertNull($svc->classifyPending($room, $user));   // 이어서 분류도 상한 안에선 안 돈다
    }

    /** 읽기 범위(jin 11-11 «놓친 것 확인은 3일 전후») — 가장 최근 글 기준 N일 밖의 글은 저장·분류하지 않는다. */
    public function test_read_range_keeps_only_the_last_days_before_the_newest_message(): void
    {
        Setting::updateOrCreate(['key' => 'kakao_read_days'], ['value' => '3', 'type' => 'integer']);
        $room = $this->room();
        $this->fake->plan = fn (array $ids) => array_map(fn ($id) => ['id' => $id, 'type' => '요청', 'to' => '재무', 'mention' => null, 'ref' => null, 'vehicle' => null, 'vin_tail' => null, 'amount' => null, 'resolves' => null], $ids);
        $text = implode('
', [
            '--------------- 2026년 11월 1일 일요일 ---------------', '[영업A] [오전 9:00] 오래된 요청 부탁드립니다',
            '--------------- 2026년 11월 7일 토요일 ---------------', '[영업A] [오전 9:00] 경계 밖 요청 부탁드립니다',
            '--------------- 2026년 11월 8일 일요일 ---------------', '[영업A] [오전 9:00] 경계 첫날 요청 부탁드립니다',
            '--------------- 2026년 11월 11일 수요일 ---------------', '[영업A] [오후 3:00] 오늘 요청 부탁드립니다',
        ]);
        $read = app(KakaoIngestService::class)->ingest($this->user(), $room, $text);
        $this->assertSame(2, $read->new_count);
        $this->assertSame(['경계 첫날 요청 부탁드립니다', '오늘 요청 부탁드립니다'], KakaoMessage::orderBy('sent_at')->pluck('body')->all());
    }
}
