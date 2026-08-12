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
