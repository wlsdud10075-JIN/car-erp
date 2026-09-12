<?php

namespace Tests\Feature;

use App\Models\BoardRequest;
use App\Models\Salesman;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\AlimtalkRecipients;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * board 요청 알림톡 — **신호별 수신자** + **사람 지정** (jin 2026-09-12).
 * 정본 = `docs/design/alimtalk-recipient-picker.md`.
 *
 * 배경: 계약금·매입잔금·판매대금확인 3종이 **템플릿 하나를 공유**해서 알림톡 코드만으로는
 * 수신자를 못 가른다. 규칙 행의 `types` 로 가르고, 사람은 `user:{id}` 로 가리킨다.
 *
 * 🚨 여기서 지키는 두 가지가 **조용히 깨지는 부류**다:
 *   ① `types` 가 저장 배열에서 빠지면 화면에선 켜지는데 저장하면 사라진다(`active` 가 그 상태다).
 *   ② 번호를 박아 두면 퇴사해도 계속 발송된다 — id 로 가리켜야 자동으로 빠진다.
 */
class AlimtalkSignalRoutingTest extends TestCase
{
    use RefreshDatabase;

    private const CODE = 'erp_board_request';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-11 10:00:00');   // 화요일 근무시간
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function rules(array $rows): void
    {
        Setting::updateOrCreate(
            ['key' => 'alimtalk_timerules_'.self::CODE.'_'.Setting::companyTemplateSet()],
            ['value' => json_encode($rows, JSON_UNESCAPED_UNICODE), 'type' => 'string'],
        );
    }

    private function user(string $role, string $phone, string $permission = 'user'): User
    {
        return User::factory()->create([
            'permission' => $permission, 'role' => $role, 'phone' => $phone, 'email_verified_at' => now(),
        ]);
    }

    // ── ① 신호별 라우팅 ──────────────────────────────────────────────────

    public function test_each_signal_goes_to_its_own_recipients(): void
    {
        $this->user('관리', '010-1111-1111');
        $this->user('재무', '010-2222-2222');

        $this->rules([
            ['to' => '관리', 'days' => [1, 2, 3, 4, 5], 'from' => '00:00', 'till' => '24:00',
                'types' => [BoardRequest::TYPE_PURCHASE_DEPOSIT]],
            ['to' => '재무', 'days' => [1, 2, 3, 4, 5], 'from' => '00:00', 'till' => '24:00',
                'types' => [BoardRequest::TYPE_PURCHASE_BALANCE]],
        ]);

        $this->assertSame(['010-1111-1111'],
            AlimtalkRecipients::forTimeRules(self::CODE, type: BoardRequest::TYPE_PURCHASE_DEPOSIT),
            '계약금이 계약금 담당에게 안 갔다');
        $this->assertSame(['010-2222-2222'],
            AlimtalkRecipients::forTimeRules(self::CODE, type: BoardRequest::TYPE_PURCHASE_BALANCE),
            '매입잔금이 매입잔금 담당에게 안 갔다');
    }

    /**
     * 🔑 **하위호환의 전부** — 2026-09-12 이전 저장값과 `DEFAULT_TIME_RULES` 에는 `types` 키가 없다.
     *    「없음 = 전 신호」가 아니면 배포 순간 3사의 기존 설정이 통째로 죽는다.
     */
    public function test_a_rule_without_types_still_applies_to_every_signal(): void
    {
        $this->user('관리', '010-1111-1111');
        $this->rules([['to' => '관리', 'days' => [1, 2, 3, 4, 5], 'from' => '00:00', 'till' => '24:00']]);

        foreach (BoardRequest::TYPES as $type) {
            $this->assertSame(['010-1111-1111'],
                AlimtalkRecipients::forTimeRules(self::CODE, type: $type),
                "types 가 없는 규칙이 {$type} 에 안 걸렸다 — 기존 설정이 전부 죽는다");
        }
    }

    public function test_asking_without_a_type_still_matches_every_rule(): void
    {
        $this->user('관리', '010-1111-1111');
        $this->rules([['to' => '관리', 'days' => [1, 2, 3, 4, 5], 'from' => '00:00', 'till' => '24:00',
            'types' => [BoardRequest::TYPE_PURCHASE_DEPOSIT]]]);

        // 신호를 안 넘기는 호출부(화면의 「지금 받는 사람 수」 등)는 전 규칙을 본다.
        $this->assertSame(['010-1111-1111'], AlimtalkRecipients::forTimeRules(self::CODE));
    }

    public function test_a_signal_nobody_claims_falls_back_to_the_top_admin(): void
    {
        $this->user('관리', '010-1111-1111');
        $this->user('', '010-9999-9999', 'admin');
        $this->rules([['to' => '관리', 'days' => [1, 2, 3, 4, 5], 'from' => '00:00', 'till' => '24:00',
            'types' => [BoardRequest::TYPE_PURCHASE_DEPOSIT]]]);

        // 매입잔금은 아무 규칙에도 안 걸린다 → 조용히 0명이 되지 않고 대표에게 간다.
        $this->assertSame(['010-9999-9999'],
            AlimtalkRecipients::forTimeRules(self::CODE, type: BoardRequest::TYPE_PURCHASE_BALANCE));
    }

    // ── ② 사람 지정 ──────────────────────────────────────────────────────

    public function test_a_person_token_resolves_to_that_persons_phone(): void
    {
        $keep = $this->user('관리', '010-1111-1111');
        $this->user('관리', '010-3333-3333');   // 같은 역할인데 안 고른 사람
        $this->rules([['to' => 'user:'.$keep->id, 'days' => [1, 2, 3, 4, 5], 'from' => '00:00', 'till' => '24:00']]);

        $this->assertSame(['010-1111-1111'], AlimtalkRecipients::forTimeRules(self::CODE),
            '개별 지정인데 그 사람만 받지 않았다');
    }

    /** 🚨 이게 「번호를 박지 않는」 이유다 — 계정이 없어지면 자동으로 빠져야 한다. */
    public function test_a_person_who_leaves_stops_receiving(): void
    {
        $leaver = $this->user('관리', '010-1111-1111');
        $this->user('', '010-9999-9999', 'admin');
        $this->rules([['to' => 'user:'.$leaver->id, 'days' => [1, 2, 3, 4, 5], 'from' => '00:00', 'till' => '24:00']]);
        $this->assertSame(['010-1111-1111'], AlimtalkRecipients::forTimeRules(self::CODE));

        $leaver->delete();

        $this->assertSame(['010-9999-9999'], AlimtalkRecipients::forTimeRules(self::CODE),
            '퇴사(계정 삭제) 후에도 그 사람에게 계속 발송된다 — 번호를 박아 둔 것과 같은 상태다');
    }

    /** 번호 없는 사람은 실제로 못 받는다. 화면이 ⚠️ 로 알려주는 것과 같은 판정이어야 한다. */
    public function test_a_member_without_a_phone_is_reported_but_not_sent_to(): void
    {
        $noPhone = $this->user('관리', '');
        $this->user('', '010-9999-9999', 'admin');
        $this->rules([['to' => 'user:'.$noPhone->id, 'days' => [1, 2, 3, 4, 5], 'from' => '00:00', 'till' => '24:00']]);

        $this->assertSame(['010-9999-9999'], AlimtalkRecipients::forTimeRules(self::CODE),
            '번호가 없는데 발송 대상으로 잡혔다');

        // 그런데 **피커 목록에서는 사라지지 않아야** 한다 — 사라지면 왜 안 받는지 아무도 모른다.
        $ids = array_column(AlimtalkRecipients::groupMembers('관리'), 'id');
        $this->assertContains($noPhone->id, $ids,
            '번호 없는 사람이 피커 목록에서 빠졌다 — 화면이 ⚠️ 를 보여줄 수 없다');
    }

    public function test_a_person_token_is_a_valid_target_and_counts(): void
    {
        $u = $this->user('관리', '010-1111-1111');
        $this->assertTrue(AlimtalkRecipients::isValidTarget('user:'.$u->id));
        $this->assertSame($u->id, AlimtalkRecipients::userIdOf('user:'.$u->id));
        $this->assertNull(AlimtalkRecipients::userIdOf('관리'));
        $this->assertSame(1, AlimtalkRecipients::countTargets('user:'.$u->id));
    }

    // ── ③ 저장 왕복 (조용히 증발하는 부류) ────────────────────────────────

    /**
     * 🚨 `active` 는 `ruleMatches` 가 읽는데 `saveTimeRules` 가 안 실어서 **죽은 필드**가 됐다.
     *    `types` 가 같은 함정에 빠지면 화면에선 켜지는데 저장하면 사라진다 — 예외도 로그도 없다.
     */
    public function test_types_survive_a_save_round_trip(): void
    {
        $super = User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]);
        $this->user('관리', '010-1111-1111');

        Volt::actingAs($super)->test('admin.alimtalk-catalog.index')
            ->set('timeRules.'.self::CODE, [[
                'to' => '관리', 'days' => [1, 2, 3, 4, 5], 'from' => '09:00', 'till' => '18:00',
                'types' => [BoardRequest::TYPE_PURCHASE_DEPOSIT],
            ]])
            ->call('saveTimeRules', self::CODE);

        $saved = AlimtalkRecipients::timeRules(self::CODE);
        $this->assertSame([BoardRequest::TYPE_PURCHASE_DEPOSIT], $saved[0]['types'] ?? [],
            '저장하니 types 가 사라졌다 — 화면에선 켜지는데 아무 효과가 없는 상태다');
    }

    /** 전 신호를 고르면 키를 안 남긴다 — 2026-09-12 이전 저장물과 글자 단위로 같아야 한다. */
    public function test_selecting_every_signal_stores_nothing_extra(): void
    {
        $super = User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]);
        $this->user('관리', '010-1111-1111');

        Volt::actingAs($super)->test('admin.alimtalk-catalog.index')
            ->set('timeRules.'.self::CODE, [[
                'to' => '관리', 'days' => [1], 'from' => '09:00', 'till' => '18:00',
                'types' => BoardRequest::TYPES,
            ]])
            ->call('saveTimeRules', self::CODE);

        $this->assertArrayNotHasKey('types', AlimtalkRecipients::timeRules(self::CODE)[0],
            '전 신호인데 types 키가 남았다 — 나중에 신호가 늘어도 그 규칙이 안 따라온다');
    }

    /** 알 수 없는 신호는 저장하지 않는다 — 영원히 안 걸리는 규칙이 남으면 사람이 헤맨다. */
    public function test_an_unknown_signal_is_dropped_on_save(): void
    {
        $super = User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]);
        $this->user('관리', '010-1111-1111');

        Volt::actingAs($super)->test('admin.alimtalk-catalog.index')
            ->set('timeRules.'.self::CODE, [[
                'to' => '관리', 'days' => [1], 'from' => '09:00', 'till' => '18:00',
                'types' => [BoardRequest::TYPE_PURCHASE_DEPOSIT, 'made_up_signal'],
            ]])
            ->call('saveTimeRules', self::CODE);

        $this->assertSame([BoardRequest::TYPE_PURCHASE_DEPOSIT],
            AlimtalkRecipients::timeRules(self::CODE)[0]['types'] ?? []);
    }

    // ── ④ 피커 조작 ──────────────────────────────────────────────────────

    private const ADDR = 'rule:'.self::CODE.':0';

    public function test_picking_a_person_turns_off_the_whole_role(): void
    {
        $super = User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]);
        $u = $this->user('관리', '010-1111-1111');

        $c = Volt::actingAs($super)->test('admin.alimtalk-catalog.index')
            ->set('timeRules.'.self::CODE, [['to' => '관리', 'days' => [1], 'from' => '09:00', 'till' => '18:00']])
            ->call('toggleMemberAt', self::ADDR, '관리', $u->id);

        $to = $c->get('timeRules')[self::CODE][0]['to'];
        $this->assertSame('user:'.$u->id, $to,
            '개별을 고르면 「역할 전체」가 함께 켜져 있으면 안 된다 — 둘이 겹치면 뜻이 모호해진다');
    }

    public function test_turning_the_role_back_on_clears_individual_picks(): void
    {
        $super = User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]);
        $u = $this->user('관리', '010-1111-1111');

        $c = Volt::actingAs($super)->test('admin.alimtalk-catalog.index')
            ->set('timeRules.'.self::CODE, [['to' => 'user:'.$u->id, 'days' => [1], 'from' => '09:00', 'till' => '18:00']])
            ->call('toggleGroupAt', self::ADDR, '관리');

        $this->assertSame('관리', $c->get('timeRules')[self::CODE][0]['to']);
    }

    /** 그 그룹에 없는 사용자 id 는 무시한다 — 클라이언트가 값을 주입할 수 있다(§8 #26). */
    public function test_a_user_outside_the_group_cannot_be_injected(): void
    {
        $super = User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]);
        $sales = $this->user('영업', '010-5555-5555');

        $c = Volt::actingAs($super)->test('admin.alimtalk-catalog.index')
            ->set('timeRules.'.self::CODE, [['to' => '관리', 'days' => [1], 'from' => '09:00', 'till' => '18:00']])
            ->call('toggleMemberAt', self::ADDR, '관리', $sales->id);

        $this->assertSame('관리', $c->get('timeRules')[self::CODE][0]['to'],
            '다른 역할의 사용자가 관리 그룹 토큰으로 들어갔다');
    }

    // ── ⑤ 역할 선택형 알림톡 16종에도 같은 피커 (jin 2026-09-12 «전체 적용») ──────────

    /** 🔑 같은 조각·같은 메서드가 「role:{code}」 주소로도 동작해야 한다 — 화면 두 벌은 갈린다(§8 #44). */
    public function test_the_same_picker_works_for_plain_role_notifications(): void
    {
        $super = User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]);
        $keep = $this->user('관리', '010-1111-1111');
        $this->user('관리', '010-3333-3333');   // 같은 역할인데 안 고른 사람

        // ⚠️ 기본값이 ['관리','manager'] 라 먼저 상태를 명시한다 — 안 하면 다른 역할이 섞여
        //    「무엇을 검사하는 테스트인지」가 흐려진다.
        Volt::actingAs($super)->test('admin.alimtalk-catalog.index')
            ->set('roles.erp_vehicle_new', ['관리'])
            ->call('toggleMemberAt', 'role:erp_vehicle_new', '관리', $keep->id)
            ->call('saveRoles', 'erp_vehicle_new');

        $this->assertSame(['user:'.$keep->id], AlimtalkRecipients::selectedRoles('erp_vehicle_new'),
            '역할 선택형에서 개별 지정이 저장되지 않았다 (또는 「관리 전체」가 같이 남았다)');
        $this->assertSame(['010-1111-1111'], AlimtalkRecipients::forBroadcast('erp_vehicle_new'),
            '개별 지정인데 그 사람만 받지 않았다');
    }

    /** 저장 검증 — 피커에 안 뜨는 계정은 버린다(영영 안 걸리는 수신자를 남기지 않는다). */
    public function test_an_unpickable_account_is_dropped_on_save(): void
    {
        $super = User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]);
        // role 이 ROLES 밖(레거시 '전체') → 어느 역할 그룹에도 안 잡힌다
        $ghost = User::factory()->create(['permission' => 'user', 'role' => '전체', 'phone' => '010-7777-7777', 'email_verified_at' => now()]);

        Volt::actingAs($super)->test('admin.alimtalk-catalog.index')
            ->set('roles.erp_vehicle_new', ['관리', 'user:'.$ghost->id])
            ->call('saveRoles', 'erp_vehicle_new');

        $this->assertSame(['관리'], AlimtalkRecipients::selectedRoles('erp_vehicle_new'));
    }

    /**
     * 🚨 **계정 없는 영업담당자**(salesmen 만 있는 사람)는 「영업 전체」일 때만 받는다.
     *    개별 지정으로 바꾸면 빠지는 게 맞다 — 고를 수 있는 대상이 아니기 때문이다.
     *    이 구분이 사라지면 「고른 적 없는 사람이 받는」 상태가 된다.
     */
    public function test_orphan_salespeople_follow_the_whole_role_only(): void
    {
        // 계정 없는 영업담당자 — salesmen 행만 있고 users 가 없다(운영에 실재).
        $orphan = Salesman::create([
            'name' => '계정없는영업', 'type' => 'freelance', 'is_active' => true,
            'phone' => '010-8888-8888',
        ]);
        $v = Vehicle::create([
            'vehicle_number' => '99가9999', 'sales_channel' => 'export', 'currency' => 'USD',
            'exchange_rate' => 1350, 'dhl_request' => false, 'salesman_id' => $orphan->id,
            'sale_price' => 10000, 'sale_date' => now()->toDateString(),
        ]);

        $code = 'erp_sale_unpaid';
        $key = 'alimtalk_roles_'.$code.'_'.Setting::companyTemplateSet();

        // ① 역할 전체 → 계정 없는 영업담당자도 받는다(구 동작 보존).
        Setting::updateOrCreate(['key' => $key], ['value' => '영업', 'type' => 'string']);
        $this->assertArrayHasKey('010-8888-8888', AlimtalkRecipients::scopedFor($code, [$v]),
            '「영업」 전체를 켰는데 계정 없는 영업담당자가 빠졌다 — 새 영업을 계정 없이 추가한 날부터 끊긴다');

        // ② 개별 지정 → 빠진다. 고를 수 있는 대상이 아니므로 끼워 넣으면 「고른 적 없는 사람이 받는」 상태다.
        $picked = $this->user('영업', '010-1111-1111');
        Setting::updateOrCreate(['key' => $key], ['value' => 'user:'.$picked->id, 'type' => 'string']);
        $this->assertArrayNotHasKey('010-8888-8888', AlimtalkRecipients::scopedFor($code, [$v]),
            '개별 지정인데 계정 없는 영업담당자까지 받는다 — 고른 적 없는 사람에게 나간다');
    }

    /** 단계별 확대가 붙은 알림(말소 재촉)은 역할 체크박스 그대로 — jin 2026-09-12 «말소재촉은 냅둬». */
    public function test_the_escalating_notification_keeps_the_plain_checkboxes(): void
    {
        $this->assertTrue(AlimtalkRecipients::supportsEscalation('erp_deregistration_reminder'));

        $super = User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]);
        $html = Volt::actingAs($super)->test('admin.alimtalk-catalog.index')->html();

        // ⚠️ 렌더된 화면 전체를 단언 인자로 넘기지 않는다 — 실패 시 그걸 통째로 찍느라
        //    테스트가 멈춘 것처럼 보인다(SKILLS §8 #93, 이 파일을 쓰면서 실제로 밟았다).
        $this->assertFalse(
            str_contains($html, "toggleGroupAt('role:erp_deregistration_reminder'"),
            '말소 재촉에 인원 피커가 붙었다 — 단계별 확대 일수가 역할 단위라 개별 지정과 안 맞는다',
        );
        $this->assertTrue(
            str_contains($html, 'escalate.erp_deregistration_reminder'),
            '말소 재촉의 단계별 확대 숫자칸이 사라졌다',
        );
    }
}
