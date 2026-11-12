<?php

namespace Tests\Feature\Kakao;

use App\Models\KakaoMessage;
use App\Models\KakaoMessageVehicle;
use App\Models\KakaoRoom;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Kakao\KakaoClassifierClient;
use App\Services\Kakao\KakaoIngestService;
use App\Services\Kakao\KakaoVehicleLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 카톡 메시지 ↔ ERP 차량 연결 (jin 11-11 §14 ②) — 본문의 차를 전부(self), 차 없는 짧은 요청은 30분 안 앞 글의 차를 이어받는다(inherit).
 */
class KakaoVehicleLinkTest extends TestCase
{
    use RefreshDatabase;

    private const SIDES = ['우리' => ['수출팀A', '재무A'], '상대' => ['영업A', '영업B']];

    private const DAY = '--------------- 2026년 11월 12일 목요일 ---------------';

    private FakeClassifier $fake;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::updateOrCreate(['key' => 'kakao_assistant_enabled_'.Setting::companyTemplateSet()], ['value' => '1', 'type' => 'boolean']);
        $this->fake = new FakeClassifier;
        $this->fake->plan = fn (array $ids) => array_map(fn ($id) => ['id' => $id, 'type' => '요청', 'to' => '우리', 'mention' => null, 'ref' => null, 'vehicle' => null, 'vin_tail' => null, 'amount' => null, 'resolves' => null], $ids);
        $this->app->instance(KakaoClassifierClient::class, $this->fake);
    }

    private function ingest(string $lines): void
    {
        $room = KakaoRoom::firstOrCreate(['name' => '무사백-수출팀'], ['sides' => self::SIDES]);
        $u = User::first() ?? User::factory()->create(['permission' => 'user', 'role' => '수출통관']);
        if (! $room->users()->where('users.id', $u->id)->exists()) {
            $room->users()->attach($u->id, ['my_name' => '수출팀A', 'selected' => true]);
        }
        app(KakaoIngestService::class)->ingest($u, $room, self::DAY."\n".$lines);
    }

    private function msg(string $startsWith): KakaoMessage
    {
        return KakaoMessage::where('body', 'like', $startsWith.'%')->firstOrFail();
    }

    /** 실례(ssancarerp 무사백 방) 그대로 — 콜론 있는 서식 */
    private const ARTEON = "차량명 : Arteon 2.0 TDI\n차량번호 :  153주9157\n차대번호 : WVWZZZ3HZKE011066\n차량대금 : 1,600만원";

    public function test_a_form_links_its_vehicle_by_vin_first_ignoring_case(): void
    {
        $v = Vehicle::create(['vehicle_number' => '999하9999', 'nice_reg_vin' => 'wvwzzz3hzke011066', 'sales_channel' => 'export']);   // 번호판이 바뀐 차 — VIN 은 남는다

        $this->ingest('[영업A] [오전 10:00] '.self::ARTEON);

        $links = KakaoMessageVehicle::orderBy('ident')->get();
        $this->assertSame(['P:153주9157', 'VIN:WVWZZZ3HZKE011066'], $links->pluck('ident')->all());   // 끝 6자리(V:)는 VIN 과 겹쳐 안 쓴다
        $this->assertSame($v->id, $links->firstWhere('ident', 'VIN:WVWZZZ3HZKE011066')->vehicle_id);
        $this->assertNull($links->firstWhere('ident', 'P:153주9157')->vehicle_id);
        $this->assertSame(['self'], $links->pluck('via')->unique()->values()->all());
        $this->assertTrue(KakaoVehicleLinks::isForm(self::ARTEON));
        $this->assertTrue(KakaoVehicleLinks::isForm("차량명   Peugeot 508\n차량번호:   259러8403\n차대번호   VR3FHEHZRLY001234"));   // 콜론 없는 줄도
        $this->assertFalse(KakaoVehicleLinks::isForm('48조5021 저당 없습니다'));
    }

    public function test_a_list_message_links_every_vehicle(): void
    {
        $this->ingest("[재무A] [오전 10:00] 서렌더 부탁드립니다\n12가3456\n23나4567\n34다5678\n45라6789\n56마7890");

        $this->assertSame(5, KakaoMessageVehicle::where('via', 'self')->count());
    }

    public function test_a_short_request_inherits_the_plate_only_message_within_thirty_minutes(): void
    {
        $this->ingest("[영업B] [오전 11:30] 28마7558\n[영업A] [오전 11:55] 저당해지 요청드립니다");

        $req = $this->msg('저당해지');
        $link = KakaoMessageVehicle::where('message_id', $req->id)->sole();
        $this->assertSame('inherit', $link->via);
        $this->assertSame('P:28마7558', $link->ident);
        $this->assertSame($this->msg('28마7558')->id, $link->from_message_id);
    }

    public function test_thirty_one_minutes_is_too_far(): void
    {
        $this->ingest("[영업B] [오전 11:24] 28마7558\n[영업A] [오전 11:55] 저당해지 요청드립니다");

        $this->assertSame(0, KakaoMessageVehicle::where('message_id', $this->msg('저당해지')->id)->count());
    }

    public function test_the_same_senders_form_is_a_source_but_a_form_without_plate_is_not(): void
    {
        // 같은 사람의 서식(차량번호 있음) → 이어받음
        $this->ingest('[영업A] [오전 10:00] '.self::ARTEON."\n[영업A] [오전 10:05] 입금 부탁드립니다");
        $this->assertSame('inherit', KakaoMessageVehicle::where('message_id', $this->msg('입금 부탁')->id)->firstOrFail()->via);

        // 차량번호·VIN 없는 매입 서식 → 이어받지 않는다(앞 글의 다른 차를 잘못 집는다 — 실측)
        $this->ingest("[영업B] [오후 2:00] 차량명 : Korea auto impors 7\n매입처 : 무사백\n계약금 : 100\n[영업B] [오후 2:03] 계약금 부탁드립니다");
        $this->assertSame(0, KakaoMessageVehicle::where('message_id', $this->msg('계약금 부탁')->id)->count());
    }

    public function test_someone_elses_form_is_not_a_source(): void
    {
        $this->ingest('[영업B] [오전 10:00] '.self::ARTEON."\n[영업A] [오전 10:05] 입금 부탁드립니다");

        $this->assertSame(0, KakaoMessageVehicle::where('message_id', $this->msg('입금 부탁')->id)->count());
    }

    public function test_the_source_may_be_in_an_earlier_read(): void
    {
        $this->ingest('[영업B] [오전 11:30] 28마7558');
        $this->ingest("[영업B] [오전 11:30] 28마7558\n[영업A] [오전 11:40] 판매보고 올려주세요");   // 두 번째 읽기 — 앞 글은 이미 저장돼 있다

        $this->assertSame('inherit', KakaoMessageVehicle::where('message_id', $this->msg('판매보고')->id)->sole()->via);
    }

    public function test_a_long_request_does_not_inherit(): void
    {
        $this->ingest("[영업B] [오전 11:30] 28마7558\n[영업A] [오전 11:35] 이번 주 금요일까지 다른 바이어 쪽 서류도 같이 정리해서 한꺼번에 보내 주시면 감사하겠습니다");

        $this->assertSame(0, KakaoMessageVehicle::where('message_id', $this->msg('이번 주')->id)->count());
    }

    public function test_backfill_command_is_dry_run_by_default_and_idempotent(): void
    {
        $room = KakaoRoom::create(['name' => '옛방', 'sides' => self::SIDES]);
        $mk = fn ($at, $sender, $body, $type) => KakaoMessage::create(['room_id' => $room->id, 'msg_key' => md5($body), 'sent_at' => $at, 'sender' => $sender, 'body' => $body, 'type' => $type, 'to_side' => '우리']);
        $mk(now()->subHours(2), '영업B', '28마7558', '정보');
        $mk(now()->subHours(2)->addMinutes(5), '영업A', '저당해지 요청', '요청');

        $this->artisan('kakao:link-vehicles')->expectsOutputToContain('이어받을 글(예상) 1건')->assertSuccessful();
        $this->assertSame(0, KakaoMessageVehicle::count());

        $this->artisan('kakao:link-vehicles --apply')->assertSuccessful();
        $this->assertSame(2, KakaoMessageVehicle::count());
        $this->artisan('kakao:link-vehicles --apply')->assertSuccessful();
        $this->assertSame(2, KakaoMessageVehicle::count());
    }
}
