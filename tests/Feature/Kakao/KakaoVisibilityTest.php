<?php

namespace Tests\Feature\Kakao;

use App\Models\AuditLog;
use App\Models\KakaoCollectorToken;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 📱 카톡자동화 = 기능설정 공개 범위 표의 「카톡자동화」 칸에 체크된 사람만 (jin 2026-11-10 «공개하는 인원에게만»).
 * 저장 전 = 종전(회사 토글이 켜져 있으면 로그인하는 전원) · 저장 뒤 = 체크한 사람 · 시스템관리자 = 항상 · 토글 OFF = 아무도.
 * 세 문(사이드바·페이지·수집기 API)이 모두 User::canUseKakaoAssistant 하나를 본다.
 */
class KakaoVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::updateOrCreate(['key' => 'kakao_assistant_enabled_'.Setting::companyTemplateSet()], ['value' => '1', 'type' => 'boolean']);
    }

    private function user(string $permission, string $role = '수출통관'): User
    {
        return User::factory()->create(['permission' => $permission, 'role' => $role, 'password' => 'secret-pw1',
            'phone' => '0107777'.str_pad((string) ++$this->n, 4, '0', STR_PAD_LEFT), 'email_verified_at' => now()]);
    }

    private function save(array $ids): void
    {
        Setting::query()->updateOrCreate(['key' => 'kakao_viewers'], ['value' => json_encode($ids), 'type' => 'string']);
        Setting::flushRiskParamMemo();
    }

    public function test_before_saving_everyone_who_logs_in_can_use_it(): void
    {
        foreach (['수출통관', '재무', '관리'] as $role) {
            $u = $this->user('user', $role);
            $this->assertTrue($u->canUseKakaoAssistant(), $role);
            $this->actingAs($u)->get(route('erp.kakao.index'))->assertOk();
        }
        $this->assertTrue($this->user('manager', '관리')->canUseKakaoAssistant());
    }

    public function test_after_saving_only_checked_people_see_the_menu_page_and_api(): void
    {
        $picked = $this->user('user', '수출통관');
        $other = $this->user('user', '수출통관');
        $this->save([$picked->id]);

        $this->actingAs($picked)->get(route('erp.kakao.index'))->assertOk();
        $this->actingAs($picked)->get(route('erp.dashboard'))->assertOk()->assertSee(__('nav.menu.kakao'));
        $this->actingAs($other)->get(route('erp.kakao.index'))->assertForbidden();
        $this->actingAs($other)->get(route('erp.dashboard'))->assertOk()->assertDontSee(__('nav.menu.kakao'));
        $this->actingAs($this->user('super', '관리'))->get(route('erp.kakao.index'))->assertOk();

        // 수집기 로그인(토큰 발급)도 같은 문 — 공개 범위 밖은 403, 안은 토큰
        $this->postJson('/api/kakao/login', ['email' => $other->email, 'password' => 'secret-pw1', 'device' => 'PC-1'])->assertStatus(403);
        $this->assertSame(0, KakaoCollectorToken::count());
        $this->postJson('/api/kakao/login', ['email' => $picked->email, 'password' => 'secret-pw1', 'device' => 'PC-1'])->assertOk();

        // 이미 발급된 토큰이라도 체크가 빠지면 그 순간부터 403
        [, $plain] = KakaoCollectorToken::issue($other, 'PC-2');
        $this->withHeader('Authorization', 'Bearer '.$plain)->getJson('/api/kakao/rooms')->assertStatus(403)
            ->assertJsonFragment(['message' => '이 계정은 카톡자동화 공개 범위에 없습니다(기능설정).']);
        $this->save([$picked->id, $other->id]);
        $this->withHeader('Authorization', 'Bearer '.$plain)->getJson('/api/kakao/rooms')->assertOk();
    }

    public function test_company_toggle_off_beats_the_column(): void
    {
        $picked = $this->user('user', '수출통관');
        $this->save([$picked->id]);
        Setting::updateOrCreate(['key' => 'kakao_assistant_enabled_'.Setting::companyTemplateSet()], ['value' => '0', 'type' => 'boolean']);
        Setting::flushRiskParamMemo();

        $this->assertFalse($picked->canUseKakaoAssistant());
        $this->assertFalse($this->user('super', '관리')->canUseKakaoAssistant());
    }

    public function test_settings_table_saves_the_column_with_everyone_as_candidates(): void
    {
        $super = $this->user('super', '관리');
        $clearance = $this->user('user', '수출통관');   // 급여 칸 후보는 아니지만 카톡 칸 후보
        $finance = $this->user('user', '재무');

        $this->actingAs($super)->withoutExceptionHandling();
        $component = Volt::test('admin.settings');
        $matrix = $component->get('payrollViewerMatrix');
        $this->assertEqualsCanonicalizing([(string) $clearance->id, (string) $finance->id], $matrix['kakao'], '저장 전 = 전원 체크(시스템관리자는 항상 보므로 줄 없음)');
        $this->assertNotContains((string) $clearance->id, $matrix['employee'], '수출통관은 급여 칸 후보가 아니다');
        $component->assertSee('—');   // 급여 칸이 없는 줄

        $matrix['kakao'] = [(string) $clearance->id];
        $component->set('payrollViewerMatrix', $matrix)->call('savePayrollViewers');

        $this->assertSame([$clearance->id], Setting::kakaoViewerIds());
        $this->assertTrue($clearance->fresh()->canUseKakaoAssistant());
        $this->assertFalse($finance->fresh()->canUseKakaoAssistant());
        $this->assertDatabaseHas('audit_logs', ['action' => 'kakao_viewers_changed', 'column_name' => 'kakao_viewers']);
        $this->assertSame(1, AuditLog::where('action', 'kakao_viewers_changed')->count());

        // 같은 값으로 다시 저장하면 감사 기록이 늘지 않는다
        $component->call('savePayrollViewers');
        $this->assertSame(1, AuditLog::where('action', 'kakao_viewers_changed')->count());
    }

    /** 헤더 클릭 = 그 칸 전체 선택/해제 (jin 11-10 «하나하나 빼기 너무 힘들다») — 저장 전엔 화면 상태만, [공개 범위 저장]으로 확정 */
    public function test_column_header_toggles_the_whole_column(): void
    {
        $super = $this->user('super', '관리');
        $a = $this->user('user', '수출통관');
        $b = $this->user('user', '재무');
        $this->actingAs($super);

        $c = Volt::test('admin.settings');
        $this->assertEqualsCanonicalizing([(string) $a->id, (string) $b->id], $c->get('payrollViewerMatrix')['kakao'], '저장 전 = 전원');
        $c->assertSee('data-viewer-col-toggle="kakao"', false);

        $c->call('toggleViewerColumn', 'kakao');
        $this->assertSame([], $c->get('payrollViewerMatrix')['kakao'], '전원 체크 상태에서 누르면 전체 해제');
        $c->call('toggleViewerColumn', 'kakao');
        $this->assertEqualsCanonicalizing([(string) $a->id, (string) $b->id], $c->get('payrollViewerMatrix')['kakao'], '다시 누르면 전체 선택');

        $c->set('payrollViewerMatrix.kakao', [(string) $a->id])->call('toggleViewerColumn', 'kakao');
        $this->assertEqualsCanonicalizing([(string) $a->id, (string) $b->id], $c->get('payrollViewerMatrix')['kakao'], '일부만 체크면 전체 선택');

        // 급여 칸은 급여 권한자만 — 수출통관은 안 들어간다
        $c->call('toggleViewerColumn', 'employee');
        $this->assertNotContains((string) $a->id, $c->get('payrollViewerMatrix')['employee']);

        $c->call('toggleViewerColumn', 'kakao')->call('savePayrollViewers');
        $this->assertSame([], Setting::kakaoViewerIds(), '해제한 채 저장하면 아무도 못 쓴다(시스템관리자 제외)');
        $this->assertFalse($a->fresh()->canUseKakaoAssistant());
    }

    /** 기능설정 수치 — 하루 상한 등 5개가 Setting::kakao*() 와 같은 키로 저장되고 하한 아래는 올려 잡는다 (jin 11-10 «하루 상한 설정값 없는데?») */
    public function test_kakao_numbers_are_edited_in_feature_settings(): void
    {
        $this->actingAs($this->user('super', '관리'));
        $c = Volt::test('admin.settings')->assertSee('data-kakao-params', false);
        $this->assertSame(2000, $c->get('kakaoParams')['kakao_daily_cost_cap_krw']);

        $c->set('kakaoParams.kakao_daily_cost_cap_krw', 5000)->set('kakaoParams.kakao_window_days', 0)->call('saveKakaoParams');

        $this->assertSame(5000, Setting::kakaoDailyCostCapKrw());
        $this->assertSame(1, Setting::kakaoWindowDays(), '하한(1일) 아래는 하한으로');
        $this->assertSame(500, Setting::kakaoMaxNewPerRead(), '안 바꾼 값은 그대로');
        $this->assertDatabaseHas('audit_logs', ['action' => 'kakao_params_changed']);
        $this->assertSame(1, AuditLog::where('action', 'kakao_params_changed')->count());
        $c->call('saveKakaoParams');
        $this->assertSame(1, AuditLog::where('action', 'kakao_params_changed')->count(), '같은 값 재저장은 감사 없음');
    }
}
