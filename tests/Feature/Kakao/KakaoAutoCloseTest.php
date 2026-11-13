<?php

namespace Tests\Feature\Kakao;

use App\Models\KakaoItem;
use App\Models\KakaoMessage;
use App\Models\KakaoRoom;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Kakao\KakaoAutoClose;
use App\Services\Kakao\KakaoTodoBoard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 「이미 끝난 일인데 또 뜬다」 (jin 2026-11-13) — 답글 연결이 놓친 요청·질문을 대화 흐름과 ERP 로 닫는다.
 *
 * 규칙은 운영 대화를 가상 적용해 건별로 읽고 고른 것이다(KakaoAutoClose 머리 주석). 여기 예제는 그때 실제로 나온 모양을
 * 역할 별칭으로 옮긴 것 — 🚫 실무자 실명을 넣지 말 것. 「닫히면 안 되는 것」(같은 부서의 파일·판매 입금·서식 묶음)이 절반이다.
 */
class KakaoAutoCloseTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = '2026-11-12';

    private KakaoRoom $room;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->room = KakaoRoom::create([
            'name' => '영업A-수출팀',
            'sides' => ['우리' => ['수출팀A', '수출팀B', '재경팀A'], '상대' => ['영업A']],
            'roles' => ['_people' => ['수출팀A' => '수출통관', '수출팀B' => '수출통관', '재경팀A' => '재무']],
        ]);
    }

    private function msg(string $time, string $sender, string $type, string $body, ?KakaoMessage $resolves = null, array $extra = []): KakaoMessage
    {
        return KakaoMessage::create(array_merge([
            'room_id' => $this->room->id, 'msg_key' => 'k'.++$this->seq, 'sent_at' => self::DAY.' '.$time.':00',
            'sender' => $sender, 'body' => $body, 'type' => $type, 'to_side' => '우리', 'resolves_id' => $resolves?->id,
        ], $extra));
    }

    private function closed(): array
    {
        return KakaoAutoClose::for(collect([$this->room->fresh()]), Carbon::parse(self::DAY.' 00:00:00'));
    }

    /** 한 사람이 두 줄로 나눠 묻고 답은 둘째 줄에만 붙는다 — 첫 줄도 끝난 일이다. */
    public function test_a_split_question_closes_with_the_line_that_got_the_answer(): void
    {
        $a = $this->msg('16:25', '영업A', '질문', '혹시 carnival');
        $b = $this->msg('16:25', '영업A', '질문', 'Van이에요?');
        $this->msg('16:37', '수출팀A', '대답', '네. VAN 운임입니다', $b);

        $c = $this->closed();
        $this->assertSame('thread', $c[$a->id]['rule']);
        $this->assertStringContainsString('VAN 운임입니다', $c[$a->id]['note']);
        $this->assertArrayNotHasKey($b->id, $c, '답글이 직접 달린 글은 보드가 원래 닫는다 — 여기 넣지 않는다');
    }

    /** 답이 몇 시간 뒤에 와도, 2분 안에 이어 쓴 줄은 한 요청이다(묶음). 서식은 묶지 않는다 — 한 장이 차 한 대다. */
    public function test_lines_written_together_close_together_even_when_the_answer_is_late(): void
    {
        $a = $this->msg('09:00', '영업A', '요청', '이 차 확인 부탁드립니다');
        $b = $this->msg('09:01', '영업A', '요청', '오늘 중으로 꼭이요');
        $this->msg('13:30', '수출팀A', '대답', '확인했습니다. 문제 없습니다', $b);
        $form = fn (string $time, string $no) => $this->msg($time, '영업A', '요청', "차량명 : A6\n차량번호 : {$no}\n차대번호 : WAUZZZFN5TN037263\n차량대금 : 5,400만원");
        $f1 = $form('14:00', '269오6225');
        $f2 = $form('14:01', '153주9157');
        $this->msg('14:30', '재경팀A', '대답', '처리했습니다', $f1);

        $c = $this->closed();
        $this->assertSame('bundle', $c[$a->id]['rule']);
        $this->assertStringContainsString('문제 없습니다', $c[$a->id]['note']);
        $this->assertArrayNotHasKey($f2->id, $c, '옆 서식에 답이 달렸다고 다른 차의 서식을 닫지 않는다');
    }

    /** 30분 뒤 같은 사람이 다시 물은 글에 답이 달리면 앞 글도 닫는다. 다른 사람의 글·60분 넘은 답은 아니다. */
    public function test_an_earlier_message_closes_when_the_same_persons_follow_up_is_answered(): void
    {
        $first = $this->msg('10:59', '영업A', '요청', '차주가 빨리 차 빼라고 합니다 진행 부탁 드립니다');
        $again = $this->msg('11:20', '영업A', '요청', '오늘 안 될까요?');
        $other = $this->msg('11:21', '수출팀B', '질문', '이 차 보험은 들었나요?');
        $this->msg('11:32', '수출팀A', '대답', '배차 하시면 됩니다', $again);
        $old = $this->msg('08:00', '영업A', '요청', '어제 건 확인 부탁드립니다');

        $c = $this->closed();
        $this->assertSame('thread', $c[$first->id]['rule']);
        $this->assertArrayNotHasKey($other->id, $c, '다른 사람의 질문은 그 답으로 닫히지 않는다');
        $this->assertArrayNotHasKey($old->id, $c, '답이 60분 넘어 온 옛 글은 잇지 않는다');
    }

    /** 파일을 달라는 요청은 다른 부서(외부 포함) 사람이 올린 파일로만 닫는다 — 같은 수출팀 동료의 파일은 답이 아니다. */
    public function test_a_file_request_closes_only_on_a_file_from_another_department(): void
    {
        $ask = $this->msg('10:14', '수출팀A', '요청', '11수3569 인보이스 올려주세요~');
        $this->msg('10:20', '수출팀B', '첨부', '사진');
        $c = $this->closed();
        $this->assertArrayNotHasKey($ask->id, $c, '같은 부서 동료가 올린 다른 건의 파일로 닫으면 안 된다');

        $this->msg('11:19', '영업A', '첨부', '사진');
        $this->assertSame('file', $this->closed()[$ask->id]['rule']);

        $giving = $this->msg('16:09', '수출팀B', '요청', '컨테이너 통관서류 보내드립니다. 확인 후 발송여부 알려주세요~');
        $this->msg('16:13', '영업A', '첨부', '사진');
        $pay = $this->msg('18:07', '영업A', '요청', '계약금 100만원 부탁드립니다');
        $this->msg('18:20', '재경팀A', '첨부', '사진');
        $c = $this->closed();
        $this->assertArrayNotHasKey($giving->id, $c, '「보내드립니다」는 파일을 달라는 요청이 아니다');
        $this->assertArrayNotHasKey($pay->id, $c, '입금 요청은 뒤에 사진이 올라왔다고 닫지 않는다(지급 안 된 계약금이 닫혔던 실험)');
    }

    /** 「입금 부탁드립니다」 뒤 재경팀이 차량번호만 적어 답한다(송금증과 함께) — 그 답은 요청 하나만 닫는다. */
    public function test_a_plate_only_answer_closes_one_short_payment_request(): void
    {
        $one = $this->msg('15:03', '영업A', '요청', '입금 부탁드립니다');
        $this->msg('15:05', '재경팀A', '대답', '135도8861');
        $two = $this->msg('15:19', '영업A', '요청', '입금 부탁드립니다');

        $c = $this->closed();
        $this->assertSame('plate', $c[$one->id]['rule']);
        $this->assertArrayNotHasKey($two->id, $c, '뒤에 온 요청은 앞의 답으로 닫히지 않는다');
    }

    /** 차를 아는 매입 서식은 그 차의 **매입** 지급 확정으로만 닫는다 — 판매 입금은 다른 일이다. 서식 두 장은 서로 묶지 않는다. */
    public function test_a_purchase_form_closes_on_that_vehicles_purchase_payment_only(): void
    {
        $sm = Salesman::create(['name' => '영업A', 'is_active' => true, 'type' => 'freelance']);
        $paid = Vehicle::create(['vehicle_number' => '17서8577', 'sales_channel' => 'export', 'salesman_id' => $sm->id, 'purchase_price' => 15_440_000, 'currency' => 'EUR', 'exchange_rate' => 1500, 'sale_date' => self::DAY, 'sale_price' => 9000]);
        $unpaid = Vehicle::create(['vehicle_number' => '49너6302', 'sales_channel' => 'export', 'salesman_id' => $sm->id, 'purchase_price' => 17_000_000, 'currency' => 'EUR', 'exchange_rate' => 1500, 'sale_date' => self::DAY, 'sale_price' => 9000]);
        $form = fn (Vehicle $v, string $time) => $this->msg($time, '영업A', '요청', "차량명 : E220d\n차량번호 : {$v->vehicle_number}\n차대번호 : WDDZF0FB4HA187463\n차량대금 : 1,544만원", null, ['vehicle_id' => $v->id]);
        $a = $form($paid, '16:41');
        $b = $form($unpaid, '16:42');
        $paid->purchaseBalancePayments()->create(['type' => 'balance', 'amount' => 14_000_000, 'payment_date' => '2026-11-13', 'confirmed_at' => '2026-11-13 10:32:00']);
        $unpaid->finalPayments()->create(['type' => 'balance', 'amount' => 9000, 'payment_date' => '2026-11-13', 'confirmed_at' => '2026-11-13 11:00:00']);

        $c = $this->closed();
        $this->assertSame('erp', $c[$a->id]['rule']);
        $this->assertStringContainsString('17서8577 매입 지급 14,000,000', $c[$a->id]['note']);
        $this->assertArrayNotHasKey($b->id, $c, '판매 입금으로 매입 서식을 닫지 않고, 옆 서식이 닫혔다고 같이 닫지도 않는다');
    }

    /** 차를 모르는 짧은 입금 요청 — 방에서 언급된 차의 담당 영업 차에 60분 안 지급. 숫자가 있으면 금액이 같아야 한다. */
    public function test_a_short_payment_request_matches_a_payment_by_time_and_amount(): void
    {
        $sm = Salesman::create(['name' => '영업A', 'is_active' => true, 'type' => 'freelance']);
        $known = Vehicle::create(['vehicle_number' => '55머8467', 'sales_channel' => 'export', 'salesman_id' => $sm->id, 'purchase_price' => 13_390_000]);
        $this->msg('09:00', '영업A', '정보', '55머8467 입고했습니다', null, ['vehicle_id' => $known->id]);   // 이 방이 영업A 의 차를 다룬다는 근거
        $amount = $this->msg('10:44', '영업A', '요청', '934 부탁 드립니다');
        $plain = $this->msg('15:34', '영업A', '요청', '계약금 급합니다');
        $late = $this->msg('17:00', '영업A', '요청', '입금 부탁드립니다');
        $known->purchaseBalancePayments()->create(['type' => 'balance', 'amount' => 9_340_000, 'payment_date' => self::DAY, 'confirmed_at' => self::DAY.' 10:54:00']);
        $known->purchaseBalancePayments()->create(['type' => 'down', 'amount' => 1_000_000, 'payment_date' => self::DAY, 'confirmed_at' => self::DAY.' 15:36:00']);

        $c = $this->closed();
        $this->assertSame('erp_pay', $c[$amount->id]['rule']);
        $this->assertStringContainsString('금액 일치', $c[$amount->id]['note']);
        $this->assertSame('erp_pay', $c[$plain->id]['rule']);
        $this->assertStringContainsString('1,000,000', $c[$plain->id]['note']);
        $this->assertArrayNotHasKey($late->id, $c, '60분 안에 지급이 없으면 열어 둔다');
    }

    /** 연결 없는 「알겠습니다」는 바로 앞 요청 하나만 닫는다 — 그 사이 다른 요청이 있으면 그쪽 몫이다. */
    public function test_an_unlinked_acknowledgement_closes_the_nearest_request_only(): void
    {
        $far = $this->msg('11:00', '수출팀A', '요청', '적재지검사 일정 잡아주세요');
        $near = $this->msg('11:15', '수출팀B', '요청', '내일 오전까지 입고 부탁드립니다');
        $this->msg('11:16', '영업A', '확인', '알겠습니다');

        $c = $this->closed();
        $this->assertSame('ack', $c[$near->id]['rule']);
        $this->assertArrayNotHasKey($far->id, $c);
    }

    /** 보드 — 닫힌 글은 그날의 「오늘 끝난 것」으로만 내려가고, [다시 할 일로] 되돌린 글은 다시 닫지 않는다. */
    public function test_the_board_moves_closed_requests_to_done_and_respects_a_manual_reopen(): void
    {
        $me = User::factory()->create(['permission' => 'user', 'role' => '수출통관']);
        $this->room->users()->attach($me->id, ['my_name' => '수출팀A', 'selected' => true]);
        $a = $this->msg('16:25', '영업A', '질문', '혹시 carnival');
        $b = $this->msg('16:25', '영업A', '질문', 'Van이에요?');
        $this->msg('16:37', '수출팀A', '대답', '네. VAN 운임입니다', $b);
        $open = $this->msg('17:00', '영업A', '요청', '차량 인천항 입고여부 알려주세요');

        $ids = fn (array $board, string $bucket) => array_column($board['buckets'][$bucket], 'id');
        $now = Carbon::parse(self::DAY.' 18:00:00');
        $board = KakaoTodoBoard::forUser($me, $now);
        $this->assertContains($a->id, $ids($board, 'done'));
        $this->assertStringContainsString('이어진 글에 답이 달림', collect($board['buckets']['done'])->firstWhere('id', $a->id)['reason']);
        $this->assertNotContains($a->id, array_merge($ids($board, 'today'), $ids($board, 'now'), $ids($board, 'missed')));
        $this->assertContains($open->id, array_merge($ids($board, 'today'), $ids($board, 'now')), '답이 없는 글은 그대로 할 일');

        $next = KakaoTodoBoard::forUser($me, $now->copy()->addDay());   // 다음 날엔 「오늘 끝난 것」에서도 사라진다
        $this->assertNotContains($a->id, collect($next['buckets'])->flatten(1)->pluck('id')->all());

        KakaoItem::create(['message_id' => $a->id, 'user_id' => $me->id, 'state' => 'todo']);
        $again = KakaoTodoBoard::forUser($me, $now);
        $this->assertNotContains($a->id, $ids($again, 'done'), '되돌린 글을 다시 닫지 않는다');
    }
}
