<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\BizmAlimtalkService;
use App\Support\AlimtalkRecipients;
use App\Support\AlimtalkTemplates;
use App\Support\AlimtalkTestVars;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 📮 대표 알림톡 4종(채권현황·일일요약·주간요약·월결산)에 ERP 링크 버튼 — 후속본 `*_v2` (jin 2026-09-22, 목적지 = 딥링크 09-23).
 *
 * 버튼은 템플릿의 일부라 추가 = BizM 재심사. 그래서 구 코드는 그대로 두고 v2 를 새로 등록하며,
 * tmplId 가 채워지는 회사부터 `activeCode()` 가 전환한다(`erp_purchase_paid_v2` 와 같은 길).
 * 기능 테스트로 못 잡는 자리 = 버튼 URL 이 승인본과 한 글자라도 다르면 **발송이 통째로 K108** — URL 규칙을 여기서 못 박는다.
 */
class AlimtalkAdminLinkButtonsTest extends TestCase
{
    use RefreshDatabase;

    private function configure(array $tmplIds): void
    {
        $set = Setting::companyTemplateSet();
        $pairs = [
            "alimtalk_enabled_{$set}" => '1',
            "alimtalk_userid_{$set}" => 'USER',
            "alimtalk_profile_{$set}" => 'PROFILE',
            "alimtalk_itemlist_{$set}" => '1',
        ];
        foreach ($tmplIds as $code => $id) {
            $pairs["alimtalk_tmpl_{$code}_{$set}"] = $id;
        }
        foreach ($pairs as $k => $v) {
            Setting::updateOrCreate(['key' => $k], ['value' => $v, 'type' => 'string']);
        }
    }

    /** 4종 전부: v2 템플릿·버튼 path·대표 전용 수신·본문의 「아래 버튼」. 하나라도 빠지면 그 회사는 승인 뒤에도 영영 안 나간다. */
    public function test_every_successor_has_a_link_button_admin_recipients_and_matching_vars(): void
    {
        $expected = [
            'erp_receivable_status_v2' => '/erp/receivables',
            'erp_daily_summary_v2' => '/admin/dashboard',
            'erp_weekly_summary_v2' => '/erp/receivables',
            'erp_monthly_closing_v2' => '/erp/settlements',
        ];
        $this->assertSame(array_keys($expected), array_keys(AlimtalkTemplates::SUCCESSOR_OF));

        foreach ($expected as $code => $path) {
            $t = AlimtalkTemplates::TEMPLATES[$code] ?? null;
            $this->assertNotNull($t, "{$code} 템플릿 없음");
            $this->assertSame($path, $t['button'][0]['path'] ?? null, "{$code} 버튼 path");
            $this->assertSame('WL', $t['button'][0]['type'] ?? null);
            $this->assertLessThanOrEqual(14, mb_strlen($t['button'][0]['name']), "{$code} 버튼명은 14자 이하(BizM)");
            $this->assertStringContainsString('아래 버튼', $t['body'], "{$code} 본문이 버튼을 언급하지 않는다 — 카카오는 본문만 읽는다");
            $this->assertStringContainsString('ERP', $t['body']);
            $this->assertSame(['admin'], AlimtalkRecipients::DEFAULT_ROLES[$code] ?? null, "{$code} 는 DEFAULT_ROLES 에 admin 으로 있어야 한다(08-24 실사고)");
            // 변수는 구 코드와 같아야 커맨드의 buildVars() 를 그대로 쓴다
            $base = AlimtalkTemplates::SUCCESSOR_OF[$code];
            $this->assertSame(AlimtalkTemplates::TEMPLATES[$base]['vars'], $t['vars'], "{$code} 변수가 구 코드와 다르다");
            $this->assertArrayNotHasKey($code, AlimtalkTemplates::ITEMLIST, "{$code} 카드를 복사하지 말 것 — 구 코드 것을 공유한다(§8 #45)");
        }
    }

    /** 카드(아이템리스트)는 구 코드와 동일 payload — 등록본도 같은 카드로 낸다. */
    public function test_cards_are_shared_with_the_base_code(): void
    {
        foreach (AlimtalkTemplates::SUCCESSOR_OF as $v2 => $base) {
            $vars = AlimtalkTestVars::for($base);
            $this->assertTrue(AlimtalkTemplates::hasItemList($v2));
            $this->assertSame(AlimtalkTemplates::itemListPayload($base, $vars), AlimtalkTemplates::itemListPayload($v2, $vars), "{$v2} 카드가 구 코드와 다르다");
            $this->assertTrue(AlimtalkTestVars::isRealData($v2), "{$v2} 테스트 발송이 실데이터 빌더를 안 쓴다");
        }
    }

    /** tmplId 한 줄이 스위치 — 비어 있으면 구 코드, 채우면 v2. 회사 분기 없음. */
    public function test_active_code_switches_only_when_the_successor_tmpl_id_is_entered(): void
    {
        $this->configure(['erp_daily_summary' => 'TMPL_DAILY']);
        $this->assertSame('erp_daily_summary', AlimtalkTemplates::activeCode('erp_daily_summary'));

        $this->configure(['erp_daily_summary_v2' => 'TMPL_DAILY_V2']);
        $this->assertSame('erp_daily_summary_v2', AlimtalkTemplates::activeCode('erp_daily_summary'));

        // 후속본이 없는 코드는 자기 자신
        $this->assertSame('erp_capital_weekly', AlimtalkTemplates::activeCode('erp_capital_weekly'));
    }

    /** 버튼 URL = APP_URL + path — 요청 호스트가 아니다(§8 #76). 구 코드는 버튼 없음. */
    public function test_link_buttons_are_built_from_app_url(): void
    {
        config(['app.url' => 'https://heysellcar.com/']);
        $this->assertSame(
            [['name' => '채권관리 바로가기', 'url' => 'https://heysellcar.com/erp/receivables']],
            AlimtalkTemplates::linkButtons('erp_receivable_status_v2'),
        );
        $this->assertSame([], AlimtalkTemplates::linkButtons('erp_receivable_status'));
        // 등록 xlsx 생성기는 회사 도메인을 직접 넘긴다 — 같은 규칙
        $this->assertSame('https://karaba-erp.com/admin/dashboard', AlimtalkTemplates::linkButtons('erp_daily_summary_v2', 'https://karaba-erp.com')[0]['url']);
        // `${URL}` 형(서명 링크)은 여기서 만들지 않는다 — 호출측이 만든다
        $this->assertSame([], AlimtalkTemplates::linkButtons('erp_payout_request'));
    }

    /** 실제 발송 payload 에 button1(WL·등록 URL)이 실린다 — 등록본과 다르면 K108. */
    public function test_send_carries_button1_with_the_registered_url(): void
    {
        config(['app.url' => 'https://heymancar.com']);
        $this->configure(['erp_receivable_status_v2' => 'TMPL_RS_V2']);
        Http::fake(['*' => Http::response([['code' => 'success', 'data' => ['msgid' => 'M1']]], 200)]);

        $code = AlimtalkTemplates::activeCode('erp_receivable_status');
        $this->assertSame('erp_receivable_status_v2', $code);
        BizmAlimtalkService::active()->send($code, '01012345678', AlimtalkTestVars::for($code), [], AlimtalkTemplates::linkButtons($code));

        Http::assertSent(function ($request) {
            $item = $request->data()[0] ?? [];

            return ($item['tmplId'] ?? null) === 'TMPL_RS_V2'
                && ($item['button1']['type'] ?? null) === 'WL'
                && ($item['button1']['name'] ?? null) === '채권관리 바로가기'
                && ($item['button1']['url_mobile'] ?? null) === 'https://heymancar.com/erp/receivables'
                && ($item['button1']['url_pc'] ?? null) === 'https://heymancar.com/erp/receivables'
                && isset($item['header'], $item['items'])   // 카드도 같이 간다
                && str_contains((string) $item['msg'], '아래 버튼');
        });
    }

    /** v2 의 수신자 = 구 코드 행의 설정(역할·개별 지정) 그대로 — 승인 순간 같은 사람에게 간다. v2 행엔 체크박스가 없다. */
    public function test_successor_shares_the_base_recipient_setting(): void
    {
        $admin = User::factory()->create(['permission' => 'admin', 'role' => '관리', 'email_verified_at' => now(), 'phone' => '010-1111-2222']);
        $set = Setting::companyTemplateSet();
        // 구 코드 행에서 「이 사람만」으로 저장한 상태
        Setting::updateOrCreate(['key' => "alimtalk_roles_erp_receivable_status_{$set}"], ['value' => 'user:'.$admin->id, 'type' => 'string']);

        $this->assertSame(['user:'.$admin->id], AlimtalkRecipients::selectedRoles('erp_receivable_status_v2'), 'v2 가 구 코드의 수신자 설정을 안 읽는다');
        $this->assertSame(
            AlimtalkRecipients::forBroadcast('erp_receivable_status'),
            AlimtalkRecipients::forBroadcast('erp_receivable_status_v2'),
            '승인 순간 받는 사람이 달라진다 — 구 코드 설정을 v2 가 공유해야 한다'
        );
        $this->assertNotSame([], AlimtalkRecipients::forBroadcast('erp_receivable_status_v2'));

        // 안내 화면: v2 행은 체크박스 대신 「공유」 안내
        $super = User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]);
        $this->actingAs($super);
        $html = Volt::test('admin.alimtalk-catalog.index')->html();
        $this->assertStringContainsString(__('alimtalk_catalog.shared_recipients', ['base' => '채권현황', 'n' => 1]), $html);
        $this->assertStringNotContainsString('wire:model="roles.erp_receivable_status_v2"', $html, 'v2 행에 아무것도 안 읽는 체크박스가 그려졌다(§8 #60)');
    }

    /** 기능설정 「테스트 발송」도 v2 면 button1 을 싣는다 — 승인 뒤 jin 의 첫 동작이 이것이다. */
    public function test_test_send_of_a_successor_carries_the_button(): void
    {
        config(['app.url' => 'https://heysellcar.com']);
        $this->configure(['erp_monthly_closing_v2' => 'TMPL_MC_V2']);
        Http::fake(['*' => Http::response([['code' => 'success', 'data' => ['msgid' => 'M2']]], 200)]);
        $this->actingAs(User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]));

        BizmAlimtalkService::active()->sendTest('01012345678', 'erp_monthly_closing_v2');

        Http::assertSent(fn ($request) => (($request->data()[0]['button1']['url_mobile'] ?? null) === 'https://heysellcar.com/erp/settlements')
            && (($request->data()[0]['button1']['name'] ?? null) === '정산관리 바로가기'));
    }

    /** 커맨드 4개가 전부 activeCode·linkButtons 를 지난다 — 하나라도 구 코드를 직접 부르면 그 알림만 영영 링크가 없다. */
    public function test_every_admin_command_sends_through_the_switch(): void
    {
        foreach ([
            'AlimtalkReceivableStatus' => 'erp_receivable_status',
            'AlimtalkDailySummary' => 'erp_daily_summary',
            'AlimtalkWeeklySummary' => 'erp_weekly_summary',
            'AlimtalkMonthlyClosing' => 'erp_monthly_closing',
        ] as $class => $base) {
            $src = (string) file_get_contents(app_path("Console/Commands/{$class}.php"));
            $this->assertStringContainsString("AlimtalkTemplates::activeCode('{$base}')", $src, "{$class} 가 activeCode 를 안 쓴다");
            $this->assertStringContainsString('AlimtalkTemplates::linkButtons($code)', $src, "{$class} 가 버튼을 안 싣는다");
            $this->assertStringNotContainsString("->send('{$base}'", $src, "{$class} 가 구 코드를 직접 보낸다");
        }
    }

    /** 등록 xlsx 생성기는 코드가 단일 출처이고 --verify 로 대조한다(손으로 치면 드리프트). */
    public function test_registration_sheet_generator_exists_with_verify(): void
    {
        $src = (string) file_get_contents(base_path('scripts/alimtalk-admin-link-xlsx.php'));
        $this->assertStringContainsString("'--verify'", $src);
        $this->assertStringContainsString('AlimtalkTemplates::linkButtons', $src);
        $this->assertStringContainsString('AlimtalkTemplates::SUCCESSOR_OF', $src);
    }
}
