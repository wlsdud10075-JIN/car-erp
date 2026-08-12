<?php

namespace Tests\Feature;

use App\Models\AlimtalkLog;
use App\Models\PurchaseBalancePayment;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\AccountMask;
use App\Support\AlimtalkConfig;
use App\Support\AlimtalkTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 📨 국내 딜러 「매입대금 입금완료」 알림톡 (jin 2026-08-12) — 매입 탭 수동 버튼.
 *
 * 🧩 계약금·매입잔금이 템플릿 하나(`erp_purchase_paid`)를 `#{구분}` 으로 공유한다.
 *
 * 🚨 이 알림톡은 **돈 얘기를 밖으로 내보낸다**. 틀리면 딜러가 잘못된 금액을 기대하거나,
 *    계좌번호가 엉뚱한 사람에게 새거나, 방향을 반대로 읽고 돈을 보낸다. 그래서 여기서 잡는 것은
 *    "발송되는가" 가 아니라 **"무엇이 실려 나가는가"** 다.
 */
class PurchasePaidNoticeTest extends TestCase
{
    use RefreshDatabase;

    private function configureAlimtalk(): void
    {
        $set = Setting::companyTemplateSet();
        foreach ([
            "alimtalk_enabled_{$set}" => '1',
            "alimtalk_userid_{$set}" => 'heyman',
            "alimtalk_profile_{$set}" => 'PROFILE',
            "alimtalk_tmpl_erp_purchase_paid_{$set}" => 'TMPL_PAID',
        ] as $k => $v) {
            Setting::updateOrCreate(['key' => $k], ['value' => $v, 'type' => 'string']);
        }
    }

    private function finance(): User
    {
        return User::factory()->create(['permission' => 'user', 'role' => '재무', 'email_verified_at' => now()]);
    }

    private function vehicle(): Vehicle
    {
        return Vehicle::create([
            'vehicle_number' => '77거8899',
            'sales_channel' => 'heyman',
            'purchase_date' => '2026-08-01',
            'purchase_price' => 20_000_000,
            'purchase_seller_bank' => '국민',
            'purchase_seller_account' => '123456-78-901234',
            'purchase_seller_holder' => '김딜러',
        ]);
    }

    private function pay(Vehicle $v, string $type, int $amount, bool $confirmed = true): PurchaseBalancePayment
    {
        return $v->purchaseBalancePayments()->create([
            'type' => $type, 'amount' => $amount, 'payment_date' => '2026-08-05',
            'confirmed_at' => $confirmed ? now() : null,
        ]);
    }

    private function okHttp(): void
    {
        Http::fake(['*' => Http::response([['code' => 'success', 'data' => ['msgid' => 'BIZM-PAID']]], 200)]);
    }

    private function send(Vehicle $v, string $kind, ?int $pbpId = null, string $phone = '010-5555-6666')
    {
        return Volt::test('erp.vehicles.index')
            ->set('editingId', $v->id)
            ->set('deregistrationBuyerPhone', $phone)
            ->call('sendPurchasePaidAlimtalk', $kind, $pbpId);
    }

    /** 계약금 — 확정분 금액·구분·마스킹 계좌·입금자명이 본문에 실린다. */
    public function test_down_payment_notice_carries_the_confirmed_amount(): void
    {
        $this->configureAlimtalk();
        $this->okHttp();
        $this->actingAs($this->finance());

        $v = $this->vehicle();
        $this->pay($v, 'down', 3_000_000);

        $this->send($v, 'down');

        $log = AlimtalkLog::where('template_code', 'erp_purchase_paid')->first();
        $this->assertNotNull($log, '발송 로그가 없다');
        $this->assertSame('sent', $log->status);
        $this->assertStringContainsString('계약금', (string) $log->message);
        $this->assertStringContainsString('3,000,000원', (string) $log->message);
        $this->assertStringContainsString('국민 ****1234', (string) $log->message);
        $this->assertStringContainsString(AlimtalkConfig::active()->companyLabel(), (string) $log->message);
    }

    /**
     * 🚨 **핵심 가드 — 잔금은 그 행 하나만 보낸다.**
     *
     * 운영 실측 239대 중 24대가 잔금을 2~3회 분할 지급한다. 합계를 보내면 두 번째 알림이
     * "추가로 그만큼 더 들어왔다"로 읽혀 금액 오해가 난다.
     */
    public function test_balance_notice_sends_only_that_row_not_the_sum(): void
    {
        $this->configureAlimtalk();
        $this->okHttp();
        $this->actingAs($this->finance());

        $v = $this->vehicle();
        $first = $this->pay($v, 'balance', 5_000_000);
        $second = $this->pay($v, 'balance', 7_000_000);

        $this->send($v, 'balance', $second->id);

        $msg = (string) AlimtalkLog::where('template_code', 'erp_purchase_paid')->first()?->message;
        $this->assertStringContainsString('7,000,000원', $msg);
        $this->assertStringNotContainsString('12,000,000', $msg, '잔금 누계가 나갔다 — 받는 사람이 추가 입금으로 읽는다');
        $this->assertStringNotContainsString('5,000,000', $msg);
        $this->assertStringContainsString('매입잔금', $msg);
        $this->assertSame($first->id + 1, $second->id);   // 순서 전제 확인
    }

    /** 미확정 지급은 "줄 예정"이지 "줬다"가 아니다 — 발송 자체를 막는다. */
    public function test_unconfirmed_payment_is_not_sent(): void
    {
        $this->configureAlimtalk();
        Http::fake();
        $this->actingAs($this->finance());

        $v = $this->vehicle();
        $draft = $this->pay($v, 'balance', 5_000_000, confirmed: false);

        $this->send($v, 'balance', $draft->id);

        Http::assertNothingSent();
        $this->assertSame(0, AlimtalkLog::count());
    }

    /** 확정 계약금이 하나도 없으면 발송 안 함(빈 통지 방지). */
    public function test_down_payment_with_nothing_confirmed_is_not_sent(): void
    {
        $this->configureAlimtalk();
        Http::fake();
        $this->actingAs($this->finance());

        $this->send($this->vehicle(), 'down');

        Http::assertNothingSent();
        $this->assertSame(0, AlimtalkLog::count());
    }

    /** 번호가 없으면 못 보낸다 — 수신 번호는 말소 알림톡 칸을 공유한다. */
    public function test_no_phone_blocks_the_send(): void
    {
        $this->configureAlimtalk();
        Http::fake();
        $this->actingAs($this->finance());

        $v = $this->vehicle();
        $this->pay($v, 'down', 3_000_000);

        $this->send($v, 'down', phone: '  ');

        Http::assertNothingSent();
        $this->assertSame(0, AlimtalkLog::count());
    }

    /** 🔒 영업은 못 보낸다 — 지급 사실을 아는 건 재무다(canConfirmFinance). */
    public function test_sales_role_is_forbidden(): void
    {
        $this->configureAlimtalk();
        Http::fake();

        // 지급 기록 자체가 재무 권한이라(PurchaseBalancePayment::creating 가드) 재무로 만들어 두고 역할을 바꾼다.
        $this->actingAs($this->finance());
        $v = $this->vehicle();
        $this->pay($v, 'down', 3_000_000);

        $this->actingAs(User::factory()->create(['permission' => 'user', 'role' => '영업', 'email_verified_at' => now()]));
        $this->send($v, 'down')->assertStatus(403);
        $this->assertSame(0, AlimtalkLog::count());
    }

    /** 알 수 없는 구분은 거절 — `#[Url]`/클라이언트 주입 방어. */
    public function test_unknown_kind_is_rejected(): void
    {
        $this->configureAlimtalk();
        Http::fake();
        $this->actingAs($this->finance());

        $this->send($this->vehicle(), 'nonsense')->assertStatus(422);
        $this->assertSame(0, AlimtalkLog::count());
    }

    // ── 화면 노출 ──────────────────────────────────────────────────────────

    /**
     * 🐌 **큰 HTML 에 `assertSee` 를 쓰지 말 것** (2026-08-12 실측 — jin 제보 "13분").
     *
     * 차량 편집 패널은 렌더 결과가 **153KB** 다. `assertSee` 가 실패하면 PHPUnit 이 그 전체를
     * diff 로 출력하는데, 그 문자열 비교가 **분 단위**로 걸린다(테스트가 느린 게 아니라 실패 출력이 느리다).
     * `str_contains` + 짧은 메시지로 단언하면 실패해도 한 줄만 찍힌다.
     */
    private function assertPanelHas(string $html, string $needle, string $what): void
    {
        $this->assertTrue(str_contains($html, $needle), "패널에 {$what} 가 없다 (HTML ".number_format(strlen($html)).'B)');
    }

    private function assertPanelLacks(string $html, string $needle, string $what): void
    {
        $this->assertFalse(str_contains($html, $needle), "패널에 {$what} 가 보이면 안 된다 (HTML ".number_format(strlen($html)).'B)');
    }

    private function panelHtml(Vehicle $v): string
    {
        return Volt::test('erp.vehicles.index')->call('openEdit', $v->id)->html();
    }

    /**
     * 👀 **버튼이 실제로 그려지는가** — jin 제보("버튼이 잘 안 보인다") 후 추가.
     * 조건부 렌더라 조용히 안 뜰 수 있고, 그러면 기능이 있어도 없는 것과 같다.
     */
    public function test_buttons_render_for_confirmed_payments(): void
    {
        $this->configureAlimtalk();
        $this->actingAs($this->finance());

        $v = $this->vehicle();
        $this->pay($v, 'down', 3_000_000);
        $this->pay($v, 'balance', 5_000_000);

        $html = $this->panelHtml($v);

        // ⚠️ 버튼 **라벨**로 찾지 말 것 — 같은 칸의 안내문구가 라벨을 그대로 인용해 항상 매치된다.
        //    `wire:click` 액션명은 버튼이 실제로 렌더될 때만 나온다.
        $this->assertPanelHas($html, 'sendPurchasePaidAlimtalk', '📨 버튼');
        $this->assertSame(2, substr_count($html, 'sendPurchasePaidAlimtalk'), '계약금 1 + 잔금 1 = 2개여야 한다');
    }

    /**
     * 🚨 **재무도 딜러 전화번호 칸을 봐야 한다.**
     *
     * 그 칸은 `canHandleDeregistration`(영업·통관·관리 — **재무 없음**) 안에 있었다. 그대로 두면
     * 재무에게 📨 버튼은 보이는데 **번호 넣을 칸이 안 보이는** 상태가 된다 — 눌러도 "번호를 입력하세요"
     * 토스트만 뜨고, 그 칸이 화면 어디에도 없다. 2026-08-12 에 블록을 밖으로 뺐다.
     */
    public function test_finance_can_see_the_dealer_phone_field(): void
    {
        $this->configureAlimtalk();
        $this->actingAs($this->finance());
        $v = $this->vehicle();
        $this->pay($v, 'down', 3_000_000);

        $html = $this->panelHtml($v);

        $this->assertPanelHas($html, 'deregistrationBuyerPhone', '딜러 전화번호 입력칸');
        // 말소 발송 버튼은 여전히 그 권한자에게만 — 칸만 공유하고 버튼은 각자 권한이다.
        $this->assertPanelLacks($html, 'sendDeregistrationAlimtalk', '말소 발송 버튼');
    }

    /**
     * 🪞 **계약금 행이 잔금 목록에도 뜨는 건 기존 동작**이다(`openEdit` 이 PBP 를 type 구분 없이 싣는다).
     * 거기에도 📨 를 달면 같은 계약금에 버튼이 2개가 되고, 그중 하나는 눌러도 아무 일이 없다
     * (발송 액션이 `type='balance'` 만 찾으므로). 그래서 잔금 행에만 단다.
     */
    public function test_no_duplicate_button_on_the_down_row_inside_the_balance_list(): void
    {
        $this->configureAlimtalk();
        $this->actingAs($this->finance());

        $v = $this->vehicle();
        $this->pay($v, 'down', 3_000_000);
        $this->pay($v, 'selling_fee', 400_000);
        $this->pay($v, 'balance', 5_000_000);

        $html = $this->panelHtml($v);

        $this->assertSame(2, substr_count($html, 'sendPurchasePaidAlimtalk'),
            '계약금 1(금액칸 옆) + 잔금 1 = 2개여야 한다 — 계약금·매도비 행에도 붙었다');
    }

    /**
     * 🚦 **승인 전에는 버튼이 아예 없다** (jin 2026-08-12 — *"괜히 버튼 있..."*).
     *
     * 눌러도 안 나가는 버튼은 "고장난 기능"으로 읽힌다. 기능설정에 tmplId 가 채워질 때
     * 자동으로 켜진다 — 배포와 승인을 분리할 수 있게 하는 게 이 게이트의 목적이다.
     * ⚠️ 부수 효과로 **karaba 처럼 등록 대상이 아닌 회사**에선 영영 안 뜬다(회사 분기 불필요).
     */
    public function test_button_is_hidden_until_the_template_is_configured(): void
    {
        $this->actingAs($this->finance());
        $v = $this->vehicle();
        $this->pay($v, 'down', 3_000_000);

        // 설정 전 — tmplId 가 비어 있다.
        $this->assertPanelLacks($this->panelHtml($v), 'sendPurchasePaidAlimtalk', '📨 버튼(미설정)');

        $this->configureAlimtalk();
        $this->assertPanelHas($this->panelHtml($v), 'sendPurchasePaidAlimtalk', '📨 버튼(설정 후)');
    }

    /** 개별 토글을 끄면 사라진다 — 승인 후에도 잠시 멈출 수 있어야 한다. */
    public function test_per_template_toggle_hides_the_button(): void
    {
        $this->configureAlimtalk();
        $this->actingAs($this->finance());
        $v = $this->vehicle();
        $this->pay($v, 'down', 3_000_000);

        $set = Setting::companyTemplateSet();
        Setting::updateOrCreate(['key' => "alimtalk_toggle_erp_purchase_paid_{$set}"], ['value' => '0', 'type' => 'boolean']);

        $this->assertPanelLacks($this->panelHtml($v), 'sendPurchasePaidAlimtalk', '📨 버튼(토글 off)');
    }

    /** 확정 지급이 없으면 버튼도 없다 — 누를 수 없는 버튼을 띄우지 않는다. */
    public function test_no_button_without_a_confirmed_payment(): void
    {
        $this->configureAlimtalk();
        $this->actingAs($this->finance());

        $v = $this->vehicle();
        $this->pay($v, 'balance', 5_000_000, confirmed: false);

        $this->assertPanelLacks($this->panelHtml($v), 'sendPurchasePaidAlimtalk', '📨 버튼');
    }

    // ── 계좌 마스킹 ────────────────────────────────────────────────────────

    /** 🔒 뒤 4자리만 나간다 — 전화번호를 잘못 적으면 남의 계좌가 통째로 나가기 때문. */
    public function test_account_is_masked_to_the_last_four_digits(): void
    {
        $this->assertSame('국민 ****1234', AccountMask::format('국민', '123456-78-901234'));
        $this->assertSame('신한 ****7890', AccountMask::format('신한', '110 234 567890'));
    }

    /**
     * ⚠️ 운영 실측 243대 중 계좌가 채워진 건 67대뿐이다 — 없다고 발송을 막지 않는다.
     * 금액·구분만으로도 통지의 목적(입금 사실 알림)은 달성된다.
     */
    public function test_missing_account_degrades_instead_of_blocking(): void
    {
        $this->assertSame('-', AccountMask::format(null, null));
        $this->assertSame('국민', AccountMask::format('국민', ''), '은행만 있으면 은행명이라도 준다');
        $this->assertSame('국민 ****', AccountMask::format('국민', '123'), '4자리 이하는 통째로 가린다');

        $this->configureAlimtalk();
        $this->okHttp();
        $this->actingAs($this->finance());

        $v = $this->vehicle();
        $v->update(['purchase_seller_bank' => null, 'purchase_seller_account' => null]);
        $this->pay($v, 'down', 1_000_000);

        $this->send($v->fresh(), 'down');

        $this->assertSame('sent', AlimtalkLog::where('template_code', 'erp_purchase_paid')->first()?->status);
    }

    /** 🔐 암호화 컬럼이라 모델을 거쳐야 평문이 나온다 — 쿼리로 뽑으면 암호문이 그대로 나간다. */
    public function test_encrypted_account_is_read_through_the_model(): void
    {
        $v = $this->vehicle();

        $raw = (string) DB::table('vehicles')->where('id', $v->id)->value('purchase_seller_account');
        $this->assertNotSame('123456-78-901234', $raw, '계좌가 평문으로 저장돼 있다');
        $this->assertStringContainsString('****1234', AccountMask::forVehicle($v->fresh()));
    }

    // ── 템플릿 무결성 ──────────────────────────────────────────────────────

    /**
     * 🧭 **방향을 못 박은 문구가 살아 있는가.** 이건 "우리가 보냈다" 는 통지다 —
     * 계좌번호가 그냥 적혀 있으면 받는 분이 "여기로 보내라"로 읽는다(SKILLS §8 #54 의 그 사고).
     */
    public function test_body_states_the_direction_of_money(): void
    {
        $body = AlimtalkTemplates::TEMPLATES['erp_purchase_paid']['body'];

        $this->assertStringContainsString('입금받으신 계좌', $body, '계좌 방향이 모호하다');
        $this->assertStringContainsString('입금해 드렸습니다', $body);
        $this->assertStringContainsString('ERP', $body, '수신 근거(시스템명)가 없으면 심사에서 반려된다');
    }

    /** 본문이 쓰는 `#{변수}` 가 전부 vars 에 선언돼 있어야 한다(치환 누락 = 자리표시자 그대로 발송). */
    public function test_every_placeholder_is_declared(): void
    {
        $t = AlimtalkTemplates::TEMPLATES['erp_purchase_paid'];
        preg_match_all('/#\{([^}]+)\}/u', $t['body'], $m);

        foreach (array_unique($m[1]) as $var) {
            $this->assertContains($var, $t['vars'], "본문의 #{{$var}} 가 vars 에 없다");
        }
        $this->assertSame([], array_diff($t['vars'], array_unique($m[1])), '쓰지 않는 vars 가 선언돼 있다');
    }

    /** 기본형이다 — 아이템리스트 카드를 붙이면 title 6자·요약 금액전용 규격에 걸린다(#35·#40). */
    public function test_template_is_plain_not_itemlist(): void
    {
        $this->assertArrayNotHasKey('erp_purchase_paid', AlimtalkTemplates::ITEMLIST);
    }
}
