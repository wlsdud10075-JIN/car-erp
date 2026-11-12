<?php

namespace Tests\Feature\Kakao;

use App\Models\KakaoItem;
use App\Models\KakaoMessage;
use App\Models\KakaoMessageVehicle;
use App\Models\KakaoRoom;
use App\Models\PurchaseBalancePayment;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Kakao\KakaoErpCards;
use App\Services\Kakao\KakaoTodoBoard;
use App\Services\Kakao\KakaoTopics;
use App\Services\Kakao\KakaoVehicleLinks;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 할 일 행의 ERP 카드(jin 11-11 §14 ②) + ③ 미등록·ERP 점검·ERP 처리됨 자동 완료.
 * 숫자는 Vehicle accessor 그대로여야 한다(SKILLS §13) · 보드는 render 마다 도니 행이 늘어도 쿼리 수는 그대로여야 한다.
 */
class KakaoErpCardTest extends TestCase
{
    use RefreshDatabase;

    private const SIDES = ['우리' => ['수출팀A', '재무A'], '상대' => ['영업A', '영업B']];

    private KakaoRoom $room;

    private User $me;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::updateOrCreate(['key' => 'kakao_assistant_enabled_'.Setting::companyTemplateSet()], ['value' => '1', 'type' => 'boolean']);
        Carbon::setTestNow('2026-11-12 15:00:00');
        $this->room = KakaoRoom::create(['name' => '무사백-수출팀', 'sides' => self::SIDES]);
        $this->me = $this->user('수출팀A');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $name, ?array $topics = null): User
    {
        $u = User::factory()->create(['permission' => 'user', 'role' => '수출통관']);
        $this->room->users()->attach($u->id, ['my_name' => $name, 'selected' => true]);
        if ($topics !== null) {
            KakaoTopics::save($u, $topics);
        }

        return $u;
    }

    /** 이미 분류된 메시지 + 저장 때처럼 self 연결 */
    private function message(string $body, string $at = '2026-11-12 10:00:00', string $type = '요청', string $sender = '영업A', ?string $topic = null): KakaoMessage
    {
        $m = KakaoMessage::create([
            'room_id' => $this->room->id, 'msg_key' => 'k'.++$this->n, 'sent_at' => $at, 'sender' => $sender,
            'body' => $body, 'type' => $type, 'to_side' => '우리', 'topic' => $topic,
        ]);
        KakaoVehicleLinks::linkSelf(collect([$m]));

        return $m;
    }

    private function vehicle(array $attrs = []): Vehicle
    {
        return Vehicle::create(array_merge(['vehicle_number' => '12가'.(3456 + ++$this->n), 'sales_channel' => 'export', 'brand' => 'BMW', 'model_type' => '520d'], $attrs));
    }

    private function bucketOf(array $board, int $id): ?string
    {
        foreach ($board['buckets'] as $k => $rows) {
            if (in_array($id, array_column($rows, 'id'), true)) {
                return $k;
            }
        }

        return null;
    }

    private function row(array $board, int $id): array
    {
        foreach ($board['buckets'] as $rows) {
            foreach ($rows as $r) {
                if ($r['id'] === $id) {
                    return $r;
                }
            }
        }
        $this->fail("행 {$id} 없음");
    }

    // ───────── ② 카드 ─────────

    public function test_card_numbers_are_the_vehicle_accessors(): void
    {
        $v = $this->vehicle(['currency' => 'USD', 'exchange_rate' => 1300, 'sale_price' => 10000, 'sale_date' => '2026-11-01', 'buyer_undecided' => true,
            'purchase_price' => 15_000_000, 'shipping_method' => 'RORO', 'vessel_name' => 'GLOVIS SUN']);
        $v->finalPayments()->create(['amount' => 6000, 'type' => 'balance', 'payment_date' => '2026-11-05', 'confirmed_at' => now()]);
        $m = $this->message($v->vehicle_number.' 판매보고 올려주세요');

        $card = KakaoErpCards::for(collect([$m]))[$m->id]['cards'][0];
        $v = $v->fresh();
        $this->assertSame((float) $v->sale_unpaid_amount, $card['sale_unpaid']);
        $this->assertSame((int) round((1 - $v->unpaid_ratio) * 100), $card['paid_pct']);
        $this->assertSame(60, $card['paid_pct']);
        $this->assertSame('매입 미지급 '.number_format($v->purchase_unpaid_amount), $card['purchase']);
        $this->assertSame(['포워딩', '선적일', 'ETA'], $card['blank']);   // VSL 은 있음
        $this->assertSame('RORO', $card['method']);
        $this->assertFalse($card['sale_empty']);

        $board = KakaoTodoBoard::forUser($this->me, now());
        $this->assertSame($v->id, $this->row($board, $m->id)['erp']['cards'][0]['id']);
    }

    public function test_empty_sale_price_is_flagged(): void
    {
        $v = $this->vehicle();
        $m = $this->message($v->vehicle_number.' 말소 부탁드립니다');

        $card = KakaoErpCards::for(collect([$m]))[$m->id]['cards'][0];
        $this->assertTrue($card['sale_empty']);
        $this->assertNull($card['paid_pct']);
        $this->assertSame([], $card['blank']);   // 판매 전 차는 선적 칸을 안 따진다
        $this->assertSame('매입가 없음', $card['purchase']);
    }

    public function test_many_vehicles_get_a_summary_and_a_per_vehicle_table(): void
    {
        $plates = [];
        foreach ([['RORO', true], ['RORO', true], ['CONTAINER', false], ['RORO', true], ['CONTAINER', false]] as [$method, $paid]) {
            $v = $this->vehicle(['currency' => 'KRW', 'exchange_rate' => 1, 'sale_price' => 1_000_000, 'sale_date' => '2026-11-01', 'buyer_undecided' => true, 'shipping_method' => $method,
                'vessel_name' => $paid ? 'V1' : null, 'shipping_date' => $paid ? '2026-11-10' : null, 'eta_date' => $paid ? '2026-12-10' : null,
                'forwarding_company_id' => null]);
            if ($paid) {
                $v->finalPayments()->create(['amount' => 1_000_000, 'type' => 'balance', 'payment_date' => '2026-11-05', 'confirmed_at' => now()]);
            }
            $plates[] = $v->vehicle_number;
        }
        $m = $this->message("서렌더 부탁드립니다\n".implode("\n", $plates));

        $e = KakaoErpCards::for(collect([$m]))[$m->id];
        $this->assertCount(5, $e['cards']);
        $this->assertSame('5대 중 5대 선적 칸 부족 · 3대 판매완납 · RORO 3 · 컨테이너 2', $e['summary']);   // 포워딩은 다섯 대 모두 비어 있다

        $html = Volt::actingAs($this->me)->test('erp.kakao.index')->call('toggleAllDone')->html();
        $this->assertStringContainsString('5대 중 5대 선적 칸 부족', $html);
        $this->assertSame(5, substr_count($html, 'data-erp-vehicle='));
    }

    public function test_old_message_without_links_falls_back_to_its_vehicle_id(): void
    {
        $v = $this->vehicle();
        $m = KakaoMessage::create(['room_id' => $this->room->id, 'msg_key' => 'old', 'sent_at' => '2026-11-12 09:00:00', 'sender' => '영업A',
            'body' => '말소 부탁', 'type' => '요청', 'to_side' => '우리', 'vehicle_id' => $v->id]);

        $this->assertSame(0, KakaoMessageVehicle::count());
        $this->assertSame($v->id, KakaoErpCards::for(collect([$m]))[$m->id]['cards'][0]['id']);
    }

    public function test_inherited_card_says_where_it_came_from(): void
    {
        $v = $this->vehicle();
        $src = $this->message($v->vehicle_number, '2026-11-12 11:30:00', '정보', '영업B');
        $req = $this->message('저당해지 요청', '2026-11-12 11:55:00');
        KakaoVehicleLinks::linkInherited(collect([$req]));

        $e = KakaoErpCards::for(collect([$req]))[$req->id];
        $this->assertSame(['sender' => '영업B', 'at' => '11:30', 'title' => $v->vehicle_number], $e['inherit']);
        $this->assertSame($v->id, $e['cards'][0]['id']);
        $this->assertNull($e['flag']);
        $this->assertNotNull($src);
    }

    public function test_query_count_does_not_grow_with_rows(): void
    {
        $make = function (int $k) {
            $ms = collect();
            for ($i = 0; $i < $k; $i++) {
                $v = $this->vehicle(['currency' => 'KRW', 'exchange_rate' => 1, 'sale_price' => 1_000_000, 'sale_date' => '2026-11-01', 'buyer_undecided' => true]);
                $v->finalPayments()->create(['amount' => 500_000, 'type' => 'balance', 'payment_date' => '2026-11-05', 'confirmed_at' => now()]);
                PurchaseBalancePayment::create(['vehicle_id' => $v->id, 'type' => 'down', 'amount' => 100, 'payment_date' => '2026-11-01', 'confirmed_at' => now()]);
                $ms->push($this->message($v->vehicle_number." 말소 부탁\n차대번호 없는 VIN 끝 ".str_pad((string) (100000 + $this->n), 6, '0'), '2026-11-12 10:00:00'));
            }

            return $ms;
        };
        $count = function ($ms) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            KakaoErpCards::for($ms);
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $few = $count($make(3));
        $many = $count(KakaoMessage::all()->concat($make(6))->unique('id')->values());
        $this->assertSame($few, $many, '행이 늘어도 쿼리 수는 같아야 한다(N+1)');
    }

    // ───────── ③ 미등록 · 점검 · ERP 처리됨 ─────────

    private const FORM = "차량명 : Arteon 2.0 TDI\n차량번호 :  153주9157\n차대번호 : WVWZZZ3HZKE011066\n차량대금 : 1,600만원";

    public function test_unregistered_form_is_flagged_and_routed_to_registration_too(): void
    {
        $m = $this->message(self::FORM, '2026-11-12 10:00:00', '요청', '영업A', '입금');
        $reg = $this->user('재무A', ['등록']);
        $pay = $this->user('재무A', ['입금']);
        $ship = $this->user('재무A', ['선적']);

        $this->assertSame('unregistered', KakaoErpCards::for(collect([$m]))[$m->id]['flag']);
        $this->assertNotSame('teammate', $this->bucketOf(KakaoTodoBoard::forUser($reg, now()), $m->id));    // 등록 담당 = 내 일
        $this->assertNotSame('teammate', $this->bucketOf(KakaoTodoBoard::forUser($pay, now()), $m->id));    // 원 분야(입금) 담당에게서도 안 뺀다
        $this->assertSame('teammate', $this->bucketOf(KakaoTodoBoard::forUser($ship, now()), $m->id));      // 선적 담당 = 다른 분야

        $html = Volt::actingAs($reg)->test('erp.kakao.index')->html();
        $this->assertMatchesRegularExpression('/data-erp-flag="unregistered"[^>]*>\s*ERP 미등록/u', $html);
    }

    public function test_one_line_mentions_never_get_the_unregistered_flag(): void
    {
        $m = $this->message('48조5021 저당 없습니다', '2026-11-12 10:00:00', '정보');

        $this->assertNull(KakaoErpCards::for(collect([$m]))[$m->id]['flag']);
    }

    public function test_vehicle_registered_later_closes_the_form_today_only(): void
    {
        $m = $this->message(self::FORM);
        $this->assertSame('unregistered', KakaoErpCards::for(collect([$m]))[$m->id]['flag']);

        $v = $this->vehicle(['vehicle_number' => '153주9157', 'nice_reg_vin' => 'WVWZZZ3HZKE011066']);   // 오늘 ERP 에 등록됨
        $board = KakaoTodoBoard::forUser($this->me, now());
        $this->assertSame('done', $this->bucketOf($board, $m->id));
        $this->assertSame('ERP 등록됨', $this->row($board, $m->id)['reason']);
        $this->assertTrue($this->row($board, $m->id)['auto']);
        $this->assertSame(1, KakaoMessageVehicle::whereNull('vehicle_id')->where('message_id', $m->id)->where('ident', 'P:153주9157')->count(), '박지 않는다 — 화면이 매번 계산');

        DB::table('vehicles')->where('id', $v->id)->update(['created_at' => '2026-11-11 09:00:00']);   // 어제 등록 → 「오늘 끝난 것」에서 내려감
        $this->assertNull($this->bucketOf(KakaoTodoBoard::forUser($this->me, now()), $m->id));
        $this->assertSame('done', $this->bucketOf(KakaoTodoBoard::forUser($this->me, now(), true), $m->id));   // [지난 날 끝난 것도 보기]
    }

    public function test_form_vin_differing_from_erp_is_a_check(): void
    {
        $this->vehicle(['vehicle_number' => '153주9157', 'nice_reg_vin' => 'WVWZZZ3HZKE099999', 'model_type' => '']);
        $m = $this->message(self::FORM);

        $e = KakaoErpCards::for(collect([$m]))[$m->id];
        $this->assertSame('check', $e['flag']);
        $this->assertStringContainsString('차대번호 다름', $e['flag_note']);
        $this->assertStringContainsString('차종 비어 있음', $e['flag_note']);
    }

    public function test_dereg_document_uploaded_after_the_request_closes_it(): void
    {
        $v = $this->vehicle();
        $m = $this->message($v->vehicle_number.' 말소증 부탁드립니다');
        $audit = fn ($at) => DB::table('audit_logs')->insert(['auditable_type' => Vehicle::class, 'auditable_id' => $v->id, 'action' => 'updated',
            'column_name' => 'deregistration_document', 'old_value' => null, 'new_value' => 'x.pdf', 'created_at' => $at]);

        $audit('2026-11-12 09:00:00');   // 요청 전 업로드 — 세지 않는다
        $this->assertSame('today', $this->bucketOf(KakaoTodoBoard::forUser($this->me, now()), $m->id));

        $audit('2026-11-12 13:00:00');   // 요청 뒤
        $board = KakaoTodoBoard::forUser($this->me, now());
        $this->assertSame('done', $this->bucketOf($board, $m->id));
        $this->assertSame('ERP 처리됨 — 말소서류 올라옴', $this->row($board, $m->id)['reason']);

        KakaoItem::create(['message_id' => $m->id, 'user_id' => $this->me->id, 'state' => 'todo']);   // [다시 할 일로] 되돌린 건은 다시 닫지 않는다
        $this->assertSame('today', $this->bucketOf(KakaoTodoBoard::forUser($this->me, now()), $m->id));
    }

    public function test_down_payment_confirmed_after_the_request_closes_it(): void
    {
        $v = $this->vehicle(['purchase_price' => 10_000_000]);
        $m = $this->message($v->vehicle_number.' 계약금 100 부탁드립니다');
        $this->assertSame('now', $this->bucketOf(KakaoTodoBoard::forUser($this->me, now()), $m->id));

        PurchaseBalancePayment::create(['vehicle_id' => $v->id, 'type' => 'down', 'amount' => 1_000_000, 'payment_date' => '2026-11-12', 'confirmed_at' => '2026-11-12 11:00:00']);
        $board = KakaoTodoBoard::forUser($this->me, now());
        $this->assertSame('done', $this->bucketOf($board, $m->id));
        $this->assertSame('ERP 처리됨 — 계약금 지급 확정', $this->row($board, $m->id)['reason']);
    }
}
