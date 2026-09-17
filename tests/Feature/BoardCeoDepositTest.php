<?php

namespace Tests\Feature;

use App\Models\BoardRequest;
use App\Models\Salesman;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\AlimtalkRecipients;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 👔 **대표계약금** — board 요청을 시각 규칙 없이 대표에게만 보내는 별개 신호 (jin 2026-09-17).
 *
 * 배경: 입금요청 알림톡은 시각 규칙을 타서 근무시간엔 담당자 1~2명에게만 간다. 담당자가 휴가·외근이면
 * 요청이 거기 멈춰 jin 이 사람 손으로 확인해 줘야 했다. board 세션이 신호를 추가하고
 * (board dev `537a547`), ERP 가 받아서 대표에게 직행시킨다.
 *
 * 🚫 **시각 규칙 표로 처리하지 않았다** — 기존 3행은 `types` 가 비어 **전 신호에 적용**된다(하위호환,
 *    §8 #94). 그 표에 이 신호를 얹으면 평일 낮엔 「담당자」 행이 걸려 **대표에게 안 가는 정반대 결과**가
 *    되고, 맞추려고 요일을 넓히면 기존 3종이 평일에도 대표에게 가기 시작한다(jin 이 그 방식을 떠올렸고
 *    실제로 그렇게 됐을 것이다).
 */
class BoardCeoDepositTest extends TestCase
{
    use RefreshDatabase;

    private function ceo(string $phone = '010-1111-2222'): User
    {
        return User::factory()->create([
            'permission' => 'admin', 'phone' => $phone, 'email_verified_at' => now(),
        ]);
    }

    private function staff(string $phone = '010-3333-4444'): User
    {
        return User::factory()->create([
            'permission' => 'user', 'role' => '관리', 'phone' => $phone, 'email_verified_at' => now(),
        ]);
    }

    /** 🔒 목록이 모델 상수를 가리키는가 — 문자열을 두 곳에 적으면 오타가 조용히 「직행 아님」이 된다. */
    public function test_ceo_direct_list_matches_the_model_constant(): void
    {
        $this->assertSame(
            [BoardRequest::TYPE_PURCHASE_DEPOSIT_CEO],
            AlimtalkRecipients::CEO_DIRECT_TYPES
        );
        $this->assertTrue(AlimtalkRecipients::isCeoDirect('purchase_deposit_ceo'));
        $this->assertFalse(AlimtalkRecipients::isCeoDirect(BoardRequest::TYPE_PURCHASE_DEPOSIT));
        $this->assertFalse(AlimtalkRecipients::isCeoDirect(null));
    }

    /** 받는 목록(TYPES)에 들어 있어야 API 가 그 type 을 받는다 — 여기 없으면 board 가 422 만 받는다. */
    public function test_the_type_is_accepted_and_has_complete_meta(): void
    {
        $this->assertContains(BoardRequest::TYPE_PURCHASE_DEPOSIT_CEO, BoardRequest::TYPES);

        $meta = BoardRequest::meta(BoardRequest::TYPE_PURCHASE_DEPOSIT_CEO);
        foreach (['badge', 'title', 'action', 'alarm', 'payee', 'task', 'color', 'manual_confirm', 'auto_resolve', 'amount'] as $k) {
            $this->assertArrayHasKey($k, $meta, "메타 {$k} 누락 — 뱃지·알람·본문 조립이 그 키를 읽는다");
        }
        // 매입 요청이라 입금 계좌를 싣는다(판매대금확인과 갈리는 자리 — §8 #54).
        $this->assertTrue($meta['payee']);
        // 계약금과 동일 — 「매입 미지급 0」 자동소멸 금지.
        $this->assertTrue($meta['manual_confirm']);
        $this->assertFalse($meta['auto_resolve']);
        $this->assertTrue($meta['amount']);
        // 일반 계약금과 한 줄에 나란히 뜨므로 색이 달라야 사람이 구분한다.
        $this->assertNotSame(
            BoardRequest::meta(BoardRequest::TYPE_PURCHASE_DEPOSIT)['color'],
            $meta['color'],
            '일반 계약금과 같은 색이면 두 뱃지를 구분할 수 없다'
        );
        // 라벨이 실제로 번역된다(키 문자열이 화면에 새지 않게 — §8 #73).
        $this->assertSame('대표계약금', __($meta['badge']));
    }

    /**
     * ✅ **시각·요일과 무관하게 대표에게만** — 평일 근무시간(담당자 시간대)에도 대표가 받는다.
     *    이게 이 기능의 본체다.
     */
    public function test_it_goes_to_the_ceo_regardless_of_time(): void
    {
        $ceo = $this->ceo();
        $this->staff();   // 대조군 — 담당자는 이 신호를 안 받는다

        // 평일(수) 14:00 = 「월~금 09:00~17:30 → 담당자」 행이 걸리는 시간대
        $weekdayNoon = new \DateTimeImmutable('2026-09-16 14:00:00');
        $phones = AlimtalkRecipients::forTimeRules('erp_board_request', $weekdayNoon, BoardRequest::TYPE_PURCHASE_DEPOSIT_CEO);

        $this->assertSame([$ceo->phone], $phones, '평일 낮에도 대표에게만 가야 한다');

        // 주말도 같다(분기 자체가 없다)
        $sunday = new \DateTimeImmutable('2026-09-20 03:00:00');
        $this->assertSame(
            $phones,
            AlimtalkRecipients::forTimeRules('erp_board_request', $sunday, BoardRequest::TYPE_PURCHASE_DEPOSIT_CEO),
            '요일·시각에 따라 갈리면 시각 규칙을 타고 있는 것'
        );
    }

    /** 🚫 기존 3종은 종전대로 시각 규칙을 탄다 — 이 변경이 그쪽을 건드리지 않았다는 증거. */
    public function test_the_existing_three_signals_still_follow_the_time_rules(): void
    {
        $ceo = $this->ceo();
        $staff = $this->staff();

        $weekdayNoon = new \DateTimeImmutable('2026-09-16 14:00:00');
        $night = new \DateTimeImmutable('2026-09-16 22:00:00');

        foreach ([
            BoardRequest::TYPE_PURCHASE_DEPOSIT,
            BoardRequest::TYPE_PURCHASE_BALANCE,
            BoardRequest::TYPE_SALE_PAYMENT_CONFIRM,
        ] as $type) {
            $day = AlimtalkRecipients::forTimeRules('erp_board_request', $weekdayNoon, $type);
            $late = AlimtalkRecipients::forTimeRules('erp_board_request', $night, $type);

            $this->assertContains($staff->phone, $day, "{$type} 가 평일 낮에 담당자에게 안 간다");
            $this->assertNotContains($ceo->phone, $day, "{$type} 가 평일 낮에 대표에게 갔다 — 기존 라우팅이 깨졌다");
            $this->assertContains($ceo->phone, $late, "{$type} 가 야간에 대표에게 안 간다");
        }
    }

    /** ⚙️ 컨트롤이 실제로 동작하는가 — 끄면 알림톡 수신자가 0명(= 안 보냄)이 된다. */
    public function test_the_screen_toggle_turns_the_alimtalk_off(): void
    {
        $ceo = $this->ceo();
        $type = BoardRequest::TYPE_PURCHASE_DEPOSIT_CEO;
        $at = new \DateTimeImmutable('2026-09-16 14:00:00');

        $this->assertTrue(AlimtalkRecipients::ceoDirectEnabled($type), '기본값은 ON 이어야 한다');
        $this->assertSame([$ceo->phone], AlimtalkRecipients::forTimeRules('erp_board_request', $at, $type));

        Setting::updateOrCreate(
            ['key' => 'alimtalk_ceo_direct_'.$type.'_'.Setting::companyTemplateSet()],
            ['value' => '0', 'type' => 'string'],
        );
        $this->assertFalse(AlimtalkRecipients::ceoDirectEnabled($type));
        $this->assertSame([], AlimtalkRecipients::forTimeRules('erp_board_request', $at, $type),
            '껐는데 수신자가 남아 있다');
    }

    /**
     * 🚫 **행별 「적용 신호」 목록에 대표계약금이 뜨면 안 된다** — 뜨면 거기서 고른 값이 무시되고,
     *    기존 3행(`types` 비어 있음)이 이 신호까지 덮어 정반대 라우팅이 된다.
     */
    public function test_the_per_rule_signal_picker_excludes_the_ceo_type(): void
    {
        $this->actingAs(User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]));

        $c = Volt::test('admin.alimtalk-catalog.index');

        $this->assertNotContains(BoardRequest::TYPE_PURCHASE_DEPOSIT_CEO, $c->instance()->boardTypes(),
            '행별 신호 목록에 대표계약금이 들어갔다');
        $this->assertSame([BoardRequest::TYPE_PURCHASE_DEPOSIT_CEO], $c->instance()->ceoDirectTypes());
        // 기존 3종은 그대로 고를 수 있어야 한다
        foreach ([BoardRequest::TYPE_PURCHASE_DEPOSIT, BoardRequest::TYPE_PURCHASE_BALANCE, BoardRequest::TYPE_SALE_PAYMENT_CONFIRM] as $t) {
            $this->assertContains($t, $c->instance()->boardTypes());
        }
    }

    /**
     * 🖥️ **컨트롤이 화면에 실제로 그려지는가** — 정적 검사가 아니라 렌더 결과를 본다.
     *    화면에 없는 규칙이 사고가 된 전례 때문이다(§8 #60·#62).
     */
    public function test_the_control_row_is_rendered_with_the_recipient_count(): void
    {
        $this->ceo();
        $this->actingAs(User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]));

        Volt::test('admin.alimtalk-catalog.index')
            ->assertSee('대표계약금')
            ->assertSee(__('alimtalk_catalog.ceo_direct_desc'))
            ->assertSee(__('alimtalk_catalog.ceo_direct_count', ['n' => 1]));
    }

    /**
     * 🗣️ **안내가 실제 출처를 가리키는가** (jin 2026-09-17 지적).
     *
     * 처음엔 「번호는 기능설정의 대표 번호에서 바꿉니다」라고 적었는데 **그 화면이 없다** —
     * `alimtalk_recipients_admin_{set}` 는 코드가 읽기만 하고 **쓰는 UI 가 0곳**이다(DB 직접 주입용).
     * 실질 출처는 **사용자관리의 최고관리자(`permission='admin'`) 휴대폰번호**다.
     * 실측 2026-09-17 heymanerp: override 0건 · 최고관리자 1명(010-****-9977).
     * ⇒ 없는 화면을 가리키는 안내는 「거기 가서 바꿨는데 왜 그대로지」를 만든다(§8 #60 의 뒤집힌 형태).
     */
    public function test_the_hint_points_at_the_real_source(): void
    {
        $this->ceo();
        $this->actingAs(User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]));

        $html = Volt::test('admin.alimtalk-catalog.index')->html();

        $this->assertStringContainsString('사용자관리', $html, '안내가 실제 출처를 안 가리킨다');
        $this->assertStringNotContainsString('기능설정의 대표 번호', $html, '없는 화면을 가리키는 안내가 남아 있다');
        $this->assertFalse(AlimtalkRecipients::adminOverrideSet(), '기본 상태에서는 override 가 없어야 한다');
    }

    /** 🔀 수신 번호가 별도 지정된 회사에서는 **다른 문장**이 나온다 — 화면이 거짓말하지 않게. */
    public function test_an_explicit_recipient_list_changes_the_hint(): void
    {
        $this->ceo();
        Setting::updateOrCreate(
            ['key' => 'alimtalk_recipients_admin_'.Setting::companyTemplateSet()],
            ['value' => '010-0000-0000', 'type' => 'string'],
        );
        $this->assertTrue(AlimtalkRecipients::adminOverrideSet());

        $this->actingAs(User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]));
        Volt::test('admin.alimtalk-catalog.index')
            ->assertSee(__('alimtalk_catalog.ceo_direct_count_override', ['n' => 1]))
            ->assertDontSee(__('alimtalk_catalog.ceo_direct_count', ['n' => 1]));
    }

    /** ⚠️ 받을 대표가 0명이면 화면이 **경고**해야 한다 — 조용히 0명에게 가는 게 최악이다(§8 #62). */
    public function test_zero_ceo_recipients_is_warned_on_screen(): void
    {
        // 대표(permission=admin) 없이 super 만 — super 는 admins() 에 안 들어간다
        $this->actingAs(User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]));

        Volt::test('admin.alimtalk-catalog.index')
            ->assertSee(__('alimtalk_catalog.ceo_direct_none'));
    }

    /**
     * 🎨 **두 뱃지가 실제로 다른 색으로 렌더되는가** — 메타 값만 보면 안 된다.
     *
     * ⚠️ 뱃지 렌더가 원래 `purple ? 보라 : 파랑` **2분기**라, 메타에 amber 를 넣어도 **화면은 파랑**이었다.
     *    메타만 비교하는 단언은 그 상태에서도 초록이다(실제로 그렇게 통과했다) — 그래서 **렌더 결과**를 본다.
     *    빌드 CSS 존재도 확인함: `bg-amber-100`·`text-amber-700`(§8 #50).
     */
    public function test_the_two_badges_render_in_different_colors(): void
    {
        $sm = Salesman::create(['name' => '담당', 'type' => 'employee', 'is_active' => true]);
        $v = Vehicle::create([
            'vehicle_number' => '22나2222', 'sales_channel' => 'export', 'currency' => 'KRW',
            'exchange_rate' => 1, 'purchase_price' => 5_000_000, 'salesman_id' => $sm->id,
        ]);
        BoardRequest::raise($v->id, BoardRequest::TYPE_PURCHASE_DEPOSIT, 'a@x.com');
        BoardRequest::raise($v->id, BoardRequest::TYPE_PURCHASE_DEPOSIT_CEO, 'a@x.com');

        $this->actingAs(User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]));
        $html = Volt::test('erp.vehicles.index')->html();

        // ⚠️ **클래스 문자열이 페이지에 있는지만 보면 안 된다** — 그 색은 이 화면 다른 칩에도 쓰여서
        //    렌더 분기를 지워도 초록이 나온다(실제로 그렇게 헛통과했다). **그 뱃지 안의 라벨까지** 묶어 본다.
        $badge = fn (string $cls, string $label) => (bool) preg_match(
            '/'.preg_quote($cls, '/').'"[\s\S]{0,400}?>\s*'.preg_quote($label, '/').'\s*</u', $html
        );

        $this->assertTrue($badge('bg-amber-100 text-amber-700', '대표계약금'),
            '대표계약금 뱃지가 amber 로 안 그려졌다 — 렌더 분기가 없는 것');
        $this->assertTrue($badge('bg-blue-100 text-blue-700', '계약금'),
            '일반 계약금 뱃지가 파랑으로 안 그려졌다');
    }

    /**
     * 🔑 같은 차에 일반 계약금 + 대표계약금이 **동시에 열린다** — 멱등키가 `(vehicle_id, type)` 이라
     *    막히지 않는다. 그게 이 신호의 존재 이유다(평시 요청이 안 먹혀 대표에게 다시 보내는 것).
     */
    public function test_both_deposit_signals_can_be_open_on_the_same_vehicle(): void
    {
        $sm = Salesman::create(['name' => '담당', 'type' => 'employee', 'is_active' => true]);
        $v = Vehicle::create([
            'vehicle_number' => '11가1111', 'sales_channel' => 'export', 'currency' => 'KRW',
            'exchange_rate' => 1, 'purchase_price' => 5_000_000, 'salesman_id' => $sm->id,
        ]);

        $a = BoardRequest::raise($v->id, BoardRequest::TYPE_PURCHASE_DEPOSIT, 'a@x.com');
        $b = BoardRequest::raise($v->id, BoardRequest::TYPE_PURCHASE_DEPOSIT_CEO, 'a@x.com');

        $this->assertNotNull($a);
        $this->assertNotNull($b, '대표계약금이 일반 계약금 때문에 멱등에 걸려 버려졌다');
        $this->assertSame(2, BoardRequest::query()->where('vehicle_id', $v->id)->open()->count());
    }
}
