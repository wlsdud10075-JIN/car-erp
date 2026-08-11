<?php

namespace Tests\Feature;

use App\Models\AlimtalkLog;
use App\Models\BoardRequest;
use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\AlimtalkRecipients;
use App\Support\AlimtalkTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * board 요청 신호 알림톡 (jin 2026-08-11, 인계 §5).
 *
 * 요청이 들어오면 ERP 가 보낸다 — board 가 아니다. 이유 셋:
 *   ① 수신자(재무·관리·대표) 번호를 가진 쪽이 ERP 다(board 엔 대표 계정이 없다).
 *   ② 트리거가 하나여야 한다 — board 가 보내면 "요청은 already_open 으로 skip 됐는데 카톡은 갔다"가 난다.
 *   ③ board 알림톡 채널은 BizM 재검수 대기라 막혀 있다.
 *
 * 🕑 수신자는 **시각 규칙**으로 갈린다. "17:30 이후엔 대표" 를 예외 분기로 박지 않고 규칙 한 줄로 둔다.
 */
class BoardRequestAlimtalkTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'test-board-read-secret';

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.board_read.hmac_secret' => $this->secret]);
        Http::fake(['*' => Http::response([['code' => 'success', 'data' => ['msgid' => 'M1']]], 200)]);

        // 발송 게이트를 열어 둔다 — 안 열면 전부 skipped 라 라우팅을 검증할 수 없다.
        $set = Setting::companyTemplateSet();
        Setting::updateOrCreate(['key' => "alimtalk_enabled_{$set}"], ['value' => '1', 'type' => 'boolean']);
        Setting::updateOrCreate(['key' => "alimtalk_userid_{$set}"], ['value' => 'uid', 'type' => 'string']);
        Setting::updateOrCreate(['key' => "alimtalk_profile_{$set}"], ['value' => 'pf', 'type' => 'string']);
        Setting::updateOrCreate(['key' => 'alimtalk_tmpl_erp_board_request_'.$set], ['value' => 'T1', 'type' => 'string']);
    }

    private function signedPost(string $path, array $payload)
    {
        $body = json_encode($payload);
        $ts = now()->timestamp;
        $canonical = "POST\n".$path."?\n".$ts."\n".$body;

        return $this->postJson($path, $payload, [
            'X-Board-Signature' => 'sha256='.hash_hmac('sha256', $canonical, $this->secret),
            'X-Timestamp' => (string) $ts,
            'X-Nonce' => (string) Str::uuid(),
        ]);
    }

    private function salesman(): Salesman
    {
        return Salesman::create(['name' => 'S', 'email' => 'a@ex.com', 'is_active' => true]);
    }

    private function vehicle(int $salesmanId): Vehicle
    {
        return Vehicle::create([
            'vehicle_number' => '11가'.(1000 + ++$this->counter),
            'sales_channel' => 'export', 'salesman_id' => $salesmanId,
            'purchase_seller_bank' => '국민', 'purchase_seller_account' => '123456-78-901234',
            'purchase_seller_holder' => '홍길동',
        ]);
    }

    private function manager(string $phone): User
    {
        return User::factory()->create([
            'permission' => 'user', 'role' => '관리', 'phone' => $phone, 'email_verified_at' => now(),
        ]);
    }

    private function boss(string $phone): User
    {
        return User::factory()->create(['permission' => 'admin', 'phone' => $phone, 'email_verified_at' => now()]);
    }

    // ── 발송 트리거 ────────────────────────────────────────────────────────────

    public function test_created_request_sends_one_alimtalk_with_amount_and_account(): void
    {
        Carbon::setTestNow('2026-08-11 10:00:00');   // 화요일 근무시간 → 관리
        $this->manager('010-1111-1111');
        $sm = $this->salesman();
        $v = $this->vehicle($sm->id);

        $this->signedPost('/api/internal/board/requests', [
            'salesman_email' => 'a@ex.com',
            'type' => BoardRequest::TYPE_PURCHASE_DEPOSIT,
            'vehicle_ids' => [$v->id],
            'amount_krw' => 3_000_000,
        ])->assertStatus(201);

        $log = AlimtalkLog::where('template_code', 'erp_board_request')->first();
        $this->assertNotNull($log, 'board 요청 알림톡이 발송되지 않았다');
        $this->assertSame('sent', $log->status);
        $this->assertSame('01011111111', $log->phone);

        // 실제로 나간 본문 — 금액과 계좌가 있어야 받는 사람이 송금할 수 있다.
        $sent = Http::recorded()[0][0]->data()[0]['msg'] ?? '';
        $this->assertStringContainsString('3,000,000원', $sent);
        $this->assertStringContainsString('123456-78-901234', $sent);
        $this->assertStringContainsString($v->vehicle_number, $sent);
    }

    /**
     * 🔒 계좌번호는 **보낼 땐 실려도 로그엔 안 남는다.** `purchase_seller_account` 는 DB 에 암호화
     * 저장되고 감사로그에서도 마스킹되는 컬럼이다 — alimtalk_logs 에 평문으로 박으면 한쪽 문만 잠근 셈.
     */
    public function test_account_number_is_masked_in_the_log_but_not_in_the_message(): void
    {
        Carbon::setTestNow('2026-08-11 10:00:00');
        $this->manager('010-1111-1111');
        $sm = $this->salesman();
        $v = $this->vehicle($sm->id);

        $this->signedPost('/api/internal/board/requests', [
            'salesman_email' => 'a@ex.com',
            'type' => BoardRequest::TYPE_PURCHASE_BALANCE,
            'vehicle_ids' => [$v->id],
            'amount_krw' => 7_000_000,
        ])->assertStatus(201);

        $sent = Http::recorded()[0][0]->data()[0]['msg'] ?? '';
        $logged = (string) AlimtalkLog::where('template_code', 'erp_board_request')->value('message');

        $this->assertStringContainsString('123456-78-901234', $sent, '카톡 본문에 계좌가 빠지면 송금을 못 한다');
        $this->assertStringNotContainsString('123456-78-901234', $logged, '알림톡 로그에 계좌번호가 평문으로 남았다');
    }

    /** 계좌가 비면 빈 줄로 두지 않는다 — 빈 줄이면 받는 사람이 계좌를 못 찾아 카톡으로 되묻는다. */
    public function test_missing_account_is_stated_not_left_blank(): void
    {
        Carbon::setTestNow('2026-08-11 10:00:00');
        $this->manager('010-1111-1111');
        $sm = $this->salesman();
        $v = $this->vehicle($sm->id);
        $v->update(['purchase_seller_bank' => null, 'purchase_seller_account' => null, 'purchase_seller_holder' => null]);

        $this->signedPost('/api/internal/board/requests', [
            'salesman_email' => 'a@ex.com',
            'type' => BoardRequest::TYPE_PURCHASE_DEPOSIT,
            'vehicle_ids' => [$v->id],
            'amount_krw' => 500_000,
        ])->assertStatus(201);

        $this->assertStringContainsString('계좌 미등록', Http::recorded()[0][0]->data()[0]['msg'] ?? '');
    }

    /**
     * 🚫 **판매대금확인엔 매입처 계좌를 싣지 않는다.**
     *
     * 그건 "돈이 **들어왔으니** 확인해달라"는 신호다. 거기에 송금 계좌가 찍히면 받는 사람이
     * 거기로 돈을 보낼 수 있다 — 방향이 반대라 실제 금전 사고가 된다.
     * (한 템플릿으로 세 신호를 다 보내기 때문에 생기는 함정이다.)
     */
    public function test_sale_confirm_carries_no_payee_account(): void
    {
        Carbon::setTestNow('2026-08-11 10:00:00');
        $this->manager('010-1111-1111');
        $sm = $this->salesman();
        $buyer = Buyer::create(['name' => 'ABC', 'is_active' => true]);
        $v = $this->vehicle($sm->id);
        $v->update(['buyer_id' => $buyer->id]);

        $this->signedPost('/api/internal/board/requests', [
            'salesman_email' => 'a@ex.com',
            'type' => BoardRequest::TYPE_SALE_PAYMENT_CONFIRM,
            'buyer_id' => $buyer->id,
            'vehicle_ids' => [$v->id],
        ])->assertStatus(201);

        $sent = Http::recorded()[0][0]->data()[0]['msg'] ?? '';
        $this->assertStringContainsString($v->vehicle_number, $sent);
        $this->assertStringNotContainsString('123456-78-901234', $sent, '판매대금확인에 매입처 계좌가 실렸다 — 반대 방향으로 송금할 수 있다');
        $this->assertStringNotContainsString('국민', $sent);
        $this->assertStringNotContainsString('계좌 미등록', $sent, '계좌 줄 자체가 나오면 안 된다');
    }

    /** ⚠️ skipped(멱등) 에는 안 보낸다 — 중복 카톡의 원인이다. */
    public function test_no_alimtalk_for_skipped_lines(): void
    {
        Carbon::setTestNow('2026-08-11 10:00:00');
        $this->manager('010-1111-1111');
        $sm = $this->salesman();
        $v = $this->vehicle($sm->id);

        $payload = [
            'salesman_email' => 'a@ex.com',
            'type' => BoardRequest::TYPE_PURCHASE_DEPOSIT,
            'vehicle_ids' => [$v->id],
            'amount_krw' => 500_000,
        ];
        $this->signedPost('/api/internal/board/requests', $payload)->assertStatus(201);
        $this->signedPost('/api/internal/board/requests', $payload)   // 재전송 → already_open
            ->assertStatus(201)->assertJsonCount(0, 'created');

        $this->assertSame(
            1,
            AlimtalkLog::where('template_code', 'erp_board_request')->count(),
            '이미 열린 요청에 카톡이 또 갔다 — 재전송할 때마다 알림이 쌓인다'
        );
    }

    /**
     * 💡 금액을 고쳐 다시 보내면 **알림톡을 다시 보내되 「금액 수정」임을 밝힌다.**
     *
     * 다시 안 보내면 받는 사람 카톡엔 옛 금액이, 화면엔 새 금액이 남아 둘이 갈린다.
     * 밝히지 않으면 두 번째 카톡을 새 요청으로 읽고 **두 번 보낸다** — 둘 다 돈 사고다.
     */
    public function test_amount_correction_resends_and_says_it_is_a_correction(): void
    {
        Carbon::setTestNow('2026-08-11 10:00:00');
        $this->manager('010-1111-1111');
        $sm = $this->salesman();
        $v = $this->vehicle($sm->id);

        $send = fn (int $amount) => $this->signedPost('/api/internal/board/requests', [
            'salesman_email' => 'a@ex.com',
            'type' => BoardRequest::TYPE_PURCHASE_DEPOSIT,
            'vehicle_ids' => [$v->id],
            'amount_krw' => $amount,
        ]);

        $send(3_000_000)->assertStatus(201);
        $send(3_500_000)->assertStatus(201);

        $logs = AlimtalkLog::where('template_code', 'erp_board_request')->orderBy('id')->get();
        $this->assertCount(2, $logs, '금액을 고쳤는데 알림톡이 다시 안 갔다 — 카톡엔 옛 금액이 남는다');

        $second = Http::recorded()[1][0]->data()[0]['msg'] ?? '';
        $this->assertStringContainsString('3,500,000원', $second);
        $this->assertStringContainsString('금액 수정', $second, '정정임을 안 밝히면 두 번 보낸다');
        $this->assertStringNotContainsString('3,000,000원', $second);
    }

    /** 같은 금액 재전송(오클릭)엔 알림톡이 안 나간다. */
    public function test_same_amount_resend_sends_no_second_alimtalk(): void
    {
        Carbon::setTestNow('2026-08-11 10:00:00');
        $this->manager('010-1111-1111');
        $sm = $this->salesman();
        $v = $this->vehicle($sm->id);

        $payload = [
            'salesman_email' => 'a@ex.com',
            'type' => BoardRequest::TYPE_PURCHASE_DEPOSIT,
            'vehicle_ids' => [$v->id],
            'amount_krw' => 3_000_000,
        ];
        $this->signedPost('/api/internal/board/requests', $payload)->assertStatus(201);
        $this->signedPost('/api/internal/board/requests', $payload)->assertStatus(201);

        $this->assertSame(1, AlimtalkLog::where('template_code', 'erp_board_request')->count());
    }

    /** 알림톡이 죽어도 신호 생성과 201 응답은 살아야 한다(board 가 실패로 오해해 재전송하지 않게). */
    public function test_alimtalk_failure_does_not_break_the_api(): void
    {
        Carbon::setTestNow('2026-08-11 10:00:00');
        $this->manager('010-1111-1111');
        Http::fake(['*' => Http::response('boom', 500)]);
        $sm = $this->salesman();
        $v = $this->vehicle($sm->id);

        $this->signedPost('/api/internal/board/requests', [
            'salesman_email' => 'a@ex.com',
            'type' => BoardRequest::TYPE_PURCHASE_DEPOSIT,
            'vehicle_ids' => [$v->id],
            'amount_krw' => 500_000,
        ])->assertStatus(201)->assertJsonCount(1, 'created');

        $this->assertSame(1, BoardRequest::count());
    }

    // ── 시각 규칙 ──────────────────────────────────────────────────────────────

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function routingProvider(): array
    {
        return [
            '평일 근무시간 → 담당자' => ['2026-08-11 10:00:00', 'manager'],
            '평일 09:00 정각 → 담당자' => ['2026-08-11 09:00:00', 'manager'],
            '평일 17:30 정각 → 대표' => ['2026-08-11 17:30:00', 'boss'],
            '평일 야간 → 대표' => ['2026-08-11 21:00:00', 'boss'],
            '평일 새벽 → 대표' => ['2026-08-11 03:00:00', 'boss'],
            '토요일 낮 → 대표' => ['2026-08-15 11:00:00', 'boss'],
            '일요일 낮 → 대표' => ['2026-08-16 11:00:00', 'boss'],
        ];
    }

    #[DataProvider('routingProvider')]
    public function test_time_rules_route_to_the_right_person(string $at, string $expected): void
    {
        Carbon::setTestNow($at);
        $this->manager('010-1111-1111');
        $this->boss('010-9999-9999');

        $phones = AlimtalkRecipients::forTimeRules('erp_board_request');
        $this->assertSame(
            $expected === 'manager' ? ['010-1111-1111'] : ['010-9999-9999'],
            $phones,
            "{$at} 수신자가 규칙과 다르다"
        );
    }

    /** 공휴일 = 주말과 동일 취급 — "토·일 종일 대표" 한 줄이 공휴일까지 덮는다. */
    public function test_holiday_is_treated_like_a_weekend(): void
    {
        $this->manager('010-1111-1111');
        $this->boss('010-9999-9999');
        Setting::updateOrCreate(
            ['key' => 'alimtalk_holidays_'.Setting::companyTemplateSet()],
            ['value' => "2026-08-11\n엉터리줄", 'type' => 'string'],
        );

        // 화요일 근무시간이지만 공휴일로 등록됨 → 담당자가 아니라 대표.
        Carbon::setTestNow('2026-08-11 10:00:00');
        $this->assertSame(['010-9999-9999'], AlimtalkRecipients::forTimeRules('erp_board_request'));

        // 형식이 틀린 줄은 버려진다(공휴일 판정이 엉뚱한 날로 새지 않게).
        $this->assertSame(['2026-08-11'], AlimtalkRecipients::holidays());
    }

    /**
     * 🗓️ **고정 공휴일은 코드에 내장** — 매년 손으로 다시 적게 하면 결국 안 적게 되고,
     * 그러면 그날 담당자에게 알림이 가버린다(jin 2026-08-11).
     */
    public function test_fixed_holidays_need_no_manual_entry(): void
    {
        $this->manager('010-1111-1111');
        $this->boss('010-9999-9999');
        // 수기 등록은 비워 둔다 — 그래도 걸려야 한다.

        // 어린이날(05-05)이 평일인 해로 검증한다. 주말이면 요일만으로도 대표라 무엇을 검증했는지 흐려진다.
        Carbon::setTestNow('2026-05-05 10:00:00');
        $this->assertLessThanOrEqual(5, now()->isoWeekday(), '전제: 그해 어린이날은 평일');
        $this->assertSame(
            ['010-9999-9999'],
            AlimtalkRecipients::forTimeRules('erp_board_request'),
            '고정 공휴일인데 근무일로 처리돼 담당자에게 갔다'
        );

        // 바로 다음 평일은 정상 근무일.
        Carbon::setTestNow('2026-05-06 10:00:00');
        $this->assertSame(['010-1111-1111'], AlimtalkRecipients::forTimeRules('erp_board_request'));
    }

    /** 수기 등록은 **날짜가 매년 바뀌는 것**만 — 설날 같은 음력 공휴일. */
    public function test_manual_holiday_supplements_the_fixed_list(): void
    {
        $this->manager('010-1111-1111');
        $this->boss('010-9999-9999');
        Setting::updateOrCreate(
            ['key' => 'alimtalk_holidays_'.Setting::companyTemplateSet()],
            ['value' => '2026-02-17', 'type' => 'string'],   // 설날 (음력이라 고정 목록에 없다)
        );

        Carbon::setTestNow('2026-02-17 10:00:00');
        $this->assertLessThanOrEqual(5, now()->isoWeekday(), '전제: 그 설날은 평일');
        $this->assertSame(['010-9999-9999'], AlimtalkRecipients::forTimeRules('erp_board_request'));
    }

    /**
     * ⚠️ **아무에게도 안 가는 상태를 만들지 않는다.** 규칙이 0명을 가리켜도 대표에게 강제 발송한다 —
     * 조용히 0명에게 가는 게 카톡보다 나쁘다.
     */
    public function test_falls_back_to_the_boss_when_no_rule_matches(): void
    {
        Carbon::setTestNow('2026-08-11 10:00:00');
        $this->boss('010-9999-9999');
        Setting::updateOrCreate(
            ['key' => 'alimtalk_timerules_erp_board_request_'.Setting::companyTemplateSet()],
            // 일요일에만 걸리는 규칙 → 화요일엔 매칭 0
            ['value' => json_encode([['to' => '재무', 'days' => [7], 'from' => '00:00', 'till' => '24:00']]), 'type' => 'string'],
        );

        $this->assertSame(['010-9999-9999'], AlimtalkRecipients::forTimeRules('erp_board_request'));
    }

    /** 깨진 설정으로 수신자가 사라지지 않는다 — 파싱 실패면 기본 규칙으로 되돌린다. */
    public function test_broken_rule_json_falls_back_to_defaults(): void
    {
        Setting::updateOrCreate(
            ['key' => 'alimtalk_timerules_erp_board_request_'.Setting::companyTemplateSet()],
            ['value' => '{{{ not json', 'type' => 'string'],
        );

        $this->assertSame(
            AlimtalkRecipients::DEFAULT_TIME_RULES,
            AlimtalkRecipients::timeRules('erp_board_request')
        );
    }

    /**
     * 자정을 넘긴 구간(17:30~09:00)이 **한 줄**로 표현된다 — 두 줄로 쪼개면 설정 실수가 는다.
     *
     * 수신자를 재무로 두고 대표는 fallback 자리에만 남긴다. 그러면 "규칙에 걸렸다"와
     * "안 걸려서 대표로 떨어졌다"가 번호로 갈려 구간 경계를 실제로 검증할 수 있다.
     */
    public function test_overnight_window_is_one_rule(): void
    {
        $this->boss('010-9999-9999');
        User::factory()->create([
            'permission' => 'user', 'role' => '재무', 'phone' => '010-2222-2222', 'email_verified_at' => now(),
        ]);
        Setting::updateOrCreate(
            ['key' => 'alimtalk_timerules_erp_board_request_'.Setting::companyTemplateSet()],
            ['value' => json_encode([['to' => '재무', 'days' => [1, 2, 3, 4, 5], 'from' => '17:30', 'till' => '09:00']]), 'type' => 'string'],
        );

        // 구간 안 — 저녁·자정 직전·이튿날 새벽까지 한 줄로 이어진다.
        foreach (['2026-08-11 17:30:00', '2026-08-11 23:59:00', '2026-08-11 08:59:00'] as $inside) {
            Carbon::setTestNow($inside);
            $this->assertSame(['010-2222-2222'], AlimtalkRecipients::forTimeRules('erp_board_request'), $inside);
        }

        // 구간 밖(근무시간) — 규칙에 안 걸려 fallback(대표)으로 떨어진다.
        foreach (['2026-08-11 09:00:00', '2026-08-11 12:00:00', '2026-08-11 17:29:00'] as $outside) {
            Carbon::setTestNow($outside);
            $this->assertSame(['010-9999-9999'], AlimtalkRecipients::forTimeRules('erp_board_request'), $outside);
        }
    }

    /**
     * 🕳️ **기본 규칙에 빈 구간이 없다** — 한 주 10,080분을 전부 훑는다 (jin 2026-08-11 질문).
     *
     * jin 물음: "일요일 → 월요일 09:00 까지는 주말 종일에 포함되나, 아니면 월요일에서 누락되나?"
     * 답: **월요일 00:00~08:59 는 「월~금 17:30~익일 09:00」 행이 잡는다**(주말 행이 아니다).
     * ⚠️ **요일은 "그 시각의 요일"로 판정**하므로, 자정을 넘긴 구간은 **끝나는 쪽 요일에도 체크가
     *    있어야** 그 새벽이 덮인다. 야간 행에서 월요일을 빼면 일요일 밤~월요일 새벽이 빈다.
     *
     * 빈 구간이 생겨도 대표 폴백 덕에 발송 자체는 되지만, **의도한 사람이 아닌 사람에게 간다.**
     * 그래서 "폴백이 필요 없는가"를 본다.
     */
    public function test_default_rules_cover_every_minute_of_the_week(): void
    {
        $start = Carbon::parse('2026-08-10 00:00');   // 월요일 00:00
        $gaps = [];

        for ($i = 0; $i < 7 * 24 * 60; $i++) {
            $t = $start->copy()->addMinutes($i);
            if (AlimtalkRecipients::isHoliday($t)) {
                continue;   // 공휴일은 일요일 취급이라 주말 행이 덮는다(별도 테스트)
            }
            if (AlimtalkRecipients::matchingRules('erp_board_request', $t) === []) {
                $gaps[] = $t->format('D H:i');
            }
        }

        $this->assertSame([], array_slice($gaps, 0, 8),
            '기본 규칙에 빈 구간이 있다 — 그 시간대 알림이 의도한 담당자가 아니라 대표 폴백으로 간다. '.
            '총 '.count($gaps).'분');
    }

    /** 일요일 밤 ↔ 월요일 새벽 경계 — 끊기지 않고 같은 수신자로 이어진다. */
    public function test_sunday_night_runs_into_monday_morning_without_a_gap(): void
    {
        $this->manager('010-1111-1111');
        $this->boss('010-9999-9999');

        foreach ([
            '2026-08-16 23:59' => '일요일 밤 (주말 종일 행)',
            '2026-08-17 00:00' => '월요일 자정 (평일 야간 행이 이어받는다)',
            '2026-08-17 08:59' => '월요일 08:59 (아직 야간)',
        ] as $at => $why) {
            Carbon::setTestNow($at);
            $this->assertSame(['010-9999-9999'], AlimtalkRecipients::forTimeRules('erp_board_request'), $why);
        }

        Carbon::setTestNow('2026-08-17 09:00');   // 근무 시작 → 담당자로 넘어간다
        $this->assertSame(['010-1111-1111'], AlimtalkRecipients::forTimeRules('erp_board_request'));
    }

    /** 카드 규격 — 요약(summary)을 두지 않았다(금액 없는 판매대금확인에서 K140 반려를 부른다). */
    public function test_card_has_no_summary_and_declares_every_variable(): void
    {
        $card = AlimtalkTemplates::ITEMLIST['erp_board_request'];
        $this->assertArrayNotHasKey('summary', $card);

        $vars = AlimtalkTemplates::TEMPLATES['erp_board_request']['vars'];
        preg_match_all('/#\{([^}]+)\}/u', json_encode($card, JSON_UNESCAPED_UNICODE), $m);
        foreach (array_unique($m[1]) as $used) {
            $this->assertContains($used, $vars, "카드가 쓰는 #{{$used}} 가 vars 에 없다");
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
