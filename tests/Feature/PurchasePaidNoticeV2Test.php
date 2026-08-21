<?php

namespace Tests\Feature;

use App\Models\AlimtalkLog;
use App\Models\PurchaseBalancePayment;
use App\Models\Salesman;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\AlimtalkTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 📨 매입대금 입금완료 **v2** — 저당 안내 + 담당영업 동시 수신 (jin 2026-08-21).
 *
 * 🚦 **승인과 배포를 분리한다.** 본문이 다르므로 BizM 재등록·재승인 대상이고, 회사마다
 *    tmplId 가 채워지는 순간 전환된다. 그 전에는 옛 `erp_purchase_paid` 로 계속 나가야
 *    버튼이 죽지 않는다(SKILLS §8 #54-B).
 *
 * 🚨 여기서 잡는 것은 «발송되는가» 가 아니라 **«전환이 정확한 시점에 일어나는가»** 와
 *    **«전환 전후로 무엇이 실려 나가는가»** 다. 둘 다 조용히 틀리는 부류다 —
 *    저당 문구가 빠져도, 영업이 못 받아도 예외는 안 난다.
 */
class PurchasePaidNoticeV2Test extends TestCase
{
    use RefreshDatabase;

    /** 옛 템플릿만 설정된 상태 — 아직 v2 승인 전인 회사. */
    private function configureV1(): void
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

    /** v2 tmplId 입력 = 승인 완료 — 이 한 줄로 전환된다(코드에 회사 분기가 없다). */
    private function configureV2(): void
    {
        $set = Setting::companyTemplateSet();
        Setting::updateOrCreate(
            ['key' => "alimtalk_tmpl_erp_purchase_paid_v2_{$set}"],
            ['value' => 'TMPL_PAID_V2', 'type' => 'string']
        );
    }

    private function finance(): User
    {
        return User::factory()->create(['permission' => 'user', 'role' => '재무', 'email_verified_at' => now()]);
    }

    private function vehicle(?string $salesmanPhone = null, bool $mortgage = false): Vehicle
    {
        $v = Vehicle::create([
            'vehicle_number' => '77거8899',
            'sales_channel' => 'heyman',
            'purchase_date' => '2026-08-01',
            'purchase_price' => 20_000_000,
            'purchase_seller_bank' => '국민',
            'purchase_seller_account' => '123456-78-901234',
            'purchase_seller_holder' => '김딜러',
            'has_mortgage' => $mortgage,
        ]);

        if ($salesmanPhone !== null) {
            $v->salesman_id = Salesman::create([
                'name' => '담당영업', 'is_active' => true, 'type' => 'employee', 'phone' => $salesmanPhone,
            ])->id;
            $v->save();
        }

        return $v->fresh();
    }

    private function pay(Vehicle $v, int $amount = 1_000_000): PurchaseBalancePayment
    {
        return $v->purchaseBalancePayments()->create([
            'type' => 'down', 'amount' => $amount, 'payment_date' => '2026-08-05', 'confirmed_at' => now(),
        ]);
    }

    private function okHttp(): void
    {
        Http::fake(['*' => Http::response([['code' => 'success', 'data' => ['msgid' => 'BIZM-PAID']]], 200)]);
    }

    /**
     * 화면에서 차량을 열면 `openEdit` 이 폼을 DB 값으로 채운다 — 그 상태를 모사한다.
     * ⚠️ `has_mortgage` 를 안 맞추면 **미저장 가드가 발동해 발송이 막힌다**(그게 정상 동작이다).
     *    그 가드 자체는 `test_unsaved_mortgage_toggle_blocks_sending` 이 따로 검증한다.
     */
    private function send(Vehicle $v, string $phone = '010-5555-6666')
    {
        return Volt::test('erp.vehicles.index')
            ->set('editingId', $v->id)
            ->set('has_mortgage', (bool) $v->has_mortgage)
            ->set('deregistrationBuyerPhone', $phone)
            ->call('sendPurchasePaidAlimtalk', 'down');
    }

    private function digits(?string $s): string
    {
        return preg_replace('/\D/', '', (string) $s) ?? '';
    }

    /** tmplId 가 채워질 때 비로소 v2 로 전환된다 — 그 전엔 옛 템플릿이 그대로 나간다. */
    public function test_v2_takes_over_only_once_its_template_id_is_filled(): void
    {
        $this->configureV1();
        $this->okHttp();
        $this->actingAs($this->finance());

        $v = $this->vehicle();
        $this->pay($v);

        $this->send($v);
        $this->assertSame('erp_purchase_paid', AlimtalkLog::latest('id')->first()?->template_code);

        $this->configureV2();
        $this->send($v);
        $this->assertSame('erp_purchase_paid_v2', AlimtalkLog::latest('id')->first()?->template_code);
    }

    /** 저당을 켜면 해지 요청 문구가 실려 나간다. */
    public function test_mortgage_line_is_carried_when_flagged(): void
    {
        $this->configureV1();
        $this->configureV2();
        $this->okHttp();
        $this->actingAs($this->finance());

        $v = $this->vehicle(null, true);
        $this->pay($v);

        $this->send($v);

        $this->assertStringContainsString(
            AlimtalkTemplates::MORTGAGE_NOTICE,
            (string) AlimtalkLog::latest('id')->first()?->message
        );
    }

    /**
     * 🚨 `#{특이사항}` 은 **절대 비지 않는다** — 빈 변수는 반려 위험이다.
     * 저당이 없으면 「특이사항 없음」 이 들어가고 해지 요청 문구는 없어야 한다.
     */
    public function test_mortgage_variable_is_never_empty(): void
    {
        $this->configureV1();
        $this->configureV2();
        $this->okHttp();
        $this->actingAs($this->finance());

        $v = $this->vehicle();
        $this->pay($v);

        $this->send($v);

        $msg = (string) AlimtalkLog::latest('id')->first()?->message;
        $this->assertStringContainsString(AlimtalkTemplates::MORTGAGE_NONE, $msg);
        $this->assertStringNotContainsString('저당 해지', $msg);
        $this->assertStringNotContainsString('#{', $msg, '치환이 안 된 자리표시자가 나갔다');
    }

    /** 담당영업도 **같은 내용**을 받는다 — 「보냈구나」 확인용이라 본문을 따로 만들지 않는다. */
    public function test_salesman_receives_the_same_message_on_v2(): void
    {
        $this->configureV1();
        $this->configureV2();
        $this->okHttp();
        $this->actingAs($this->finance());

        $v = $this->vehicle('010-1111-2222');
        $this->pay($v);

        $this->send($v, '010-5555-6666');

        $logs = AlimtalkLog::where('template_code', 'erp_purchase_paid_v2')->get();
        $this->assertCount(2, $logs, '딜러·담당영업 두 통이어야 한다');
        $this->assertEqualsCanonicalizing(
            ['01055556666', '01011112222'],
            $logs->map(fn ($l) => $this->digits($l->phone))->all()
        );
        $this->assertSame(1, $logs->pluck('message')->unique()->count(), '두 통의 내용이 다르다');
    }

    /**
     * 🚨 **옛 템플릿으로는 영업에게 안 보낸다.** 그 본문은 수신 대상을 «딜러» 로 못 박고 있어
     * 영업이 받으면 그 문장이 거짓이 된다 — 카카오는 본문만 읽고 수신자를 판별한다
     * (2026-07-30 자금보고가 「수신 대상 불명확」으로 반려된 그 지점).
     */
    public function test_old_template_never_reaches_the_salesman(): void
    {
        $this->configureV1();
        $this->okHttp();
        $this->actingAs($this->finance());

        $v = $this->vehicle('010-1111-2222');
        $this->pay($v);

        $this->send($v);

        $this->assertCount(1, AlimtalkLog::all(), '옛 템플릿인데 두 통이 나갔다');
        $this->assertStringContainsString(
            '딜러)로 등록된 분께',
            AlimtalkTemplates::TEMPLATES['erp_purchase_paid']['body'],
            '옛 본문의 수신 대상 문구가 바뀌었다 — 라이브 승인본이라 손대면 안 된다'
        );
    }

    /** 영업 전화번호가 없어도 딜러 발송은 막지 않는다(비면 조용히 skip 되는 부류라 토스트로 알린다). */
    public function test_missing_salesman_phone_does_not_block_the_dealer_notice(): void
    {
        $this->configureV1();
        $this->configureV2();
        $this->okHttp();
        $this->actingAs($this->finance());

        $v = $this->vehicle(null);
        $this->pay($v);

        $this->send($v);

        $this->assertCount(1, AlimtalkLog::all());
        $this->assertSame('sent', AlimtalkLog::first()?->status);
    }

    /** 딜러 번호와 영업 번호가 같으면 한 통이면 충분하다(같은 사람에게 두 번 가면 오해를 부른다). */
    public function test_same_number_is_not_notified_twice(): void
    {
        $this->configureV1();
        $this->configureV2();
        $this->okHttp();
        $this->actingAs($this->finance());

        $v = $this->vehicle('010-5555-6666');
        $this->pay($v);

        $this->send($v, '010-5555-6666');

        $this->assertCount(1, AlimtalkLog::all());
    }

    /**
     * 🚨 토글만 하고 저장 안 한 채 보내면 막는다.
     * 발송은 **DB 값**을 읽으므로, 안 막으면 «저당을 켜고 보냈는데 문구가 없는» 발송이 조용히 나간다.
     */
    public function test_unsaved_mortgage_toggle_blocks_sending(): void
    {
        $this->configureV1();
        $this->configureV2();
        $this->okHttp();
        $this->actingAs($this->finance());

        $v = $this->vehicle();
        $this->pay($v);

        Volt::test('erp.vehicles.index')
            ->set('editingId', $v->id)
            ->set('deregistrationBuyerPhone', '010-5555-6666')
            ->set('has_mortgage', true)          // 화면에서만 켠 상태 (DB 는 false)
            ->call('sendPurchasePaidAlimtalk', 'down');

        $this->assertCount(0, AlimtalkLog::all(), '미저장 상태로 발송됐다');
    }

    /** 저당 표시는 딜러에게 나가는 문장을 좌우하고 해제가 수동이라 감사로그에 남긴다. */
    public function test_mortgage_flag_is_audited(): void
    {
        $this->assertContains('has_mortgage', Vehicle::AUDITED_COLUMNS);
    }

    /**
     * 차량을 열면 저당 표시가 DB 값으로 채워진다.
     * 이게 끊기면 화면은 늘 «꺼짐» 으로 보이고, 그대로 보내려 하면 미저장 가드에 막혀
     * 「저당을 켜둔 차인데 발송이 안 된다」 가 된다 — 값이 비어도 화면은 정상 렌더돼 눈으로는 못 잡는다.
     */
    public function test_open_edit_loads_the_flag_from_the_database(): void
    {
        $this->configureV1();
        $this->actingAs($this->finance());

        $v = $this->vehicle(null, true);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->assertSet('has_mortgage', true);
    }

    /** 저장하면 DB 에 남는다 — 저장 경로가 끊기면 토글이 매번 되돌아간다. */
    public function test_saving_persists_the_flag(): void
    {
        $this->configureV1();
        $this->actingAs($this->finance());

        // 저장 검증은 `sales_channel` 을 `in:export` 로 강제한다(채널 단일화). 다른 테스트는
        // 모델을 직접 만들어 검증을 안 타므로 여기서만 맞춰준다.
        $v = $this->vehicle();
        $v->update(['sales_channel' => 'export']);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('has_mortgage', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue((bool) $v->fresh()->has_mortgage);
    }

    /** v2 본문도 자리표시자·수신 대상·길이 규격을 지킨다. */
    public function test_v2_body_declares_every_placeholder_and_both_recipients(): void
    {
        $t = AlimtalkTemplates::TEMPLATES['erp_purchase_paid_v2'];
        preg_match_all('/#\{([^}]+)\}/u', $t['body'], $m);

        foreach (array_unique($m[1]) as $var) {
            $this->assertContains($var, $t['vars'], "본문의 #{{$var}} 가 vars 에 없다");
        }
        $this->assertSame([], array_diff($t['vars'], array_unique($m[1])), '쓰지 않는 vars 가 선언돼 있다');

        $this->assertContains('특이사항', $t['vars']);
        $this->assertStringContainsString('사내 담당 임직원', $t['body'], '영업도 받는데 수신 대상이 딜러로만 적혀 있다');
        $this->assertStringContainsString('입금받으신 계좌', $t['body'], '계좌 방향이 모호하다');
        $this->assertStringContainsString('ERP', $t['body'], '수신 근거(시스템명)가 없으면 심사에서 반려된다');
        $this->assertLessThanOrEqual(1000, mb_strlen($t['body']), 'BizM 본문 1,000자 제한');
        $this->assertArrayNotHasKey('erp_purchase_paid_v2', AlimtalkTemplates::ITEMLIST);
    }

    /**
     * 🔒 **승인이 끝난 본문은 이제 못 고친다** — 2026-08-21 에 3사 심사 제출 완료.
     * 한 글자라도 바뀌면 등록본과 어긋나 발송 시점에 반려된다(SKILLS §8 #40).
     */
    public function test_v2_body_is_frozen_after_submission(): void
    {
        $this->assertSame(
            "[매입대금 입금 안내] #{차량번호}\n\n차량 매입 거래처(딜러)와 사내 담당 임직원에게 발송되는 입금 통지입니다.\n\n▶ 차량번호: #{차량번호}\n▶ 구분: #{구분}\n▶ 입금 금액: #{금액}\n▶ 입금받으신 계좌: #{계좌}\n▶ 입금자명: #{입금자명}\n#{특이사항}\n\n위 금액을 입금해 드렸습니다. 입금받으신 계좌에서 내역을 확인해 주세요.\n본 안내는 차량관리 ERP에서 담당자가 입금 처리 후 발송합니다.",
            AlimtalkTemplates::TEMPLATES['erp_purchase_paid_v2']['body'],
            'BizM 등록본과 어긋난다 — 고치려면 재등록·재승인이다'
        );
    }
}
