<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Guides\GuideCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 📖 업무 가이드(/guides) 틀 — jin 2026-11-13 (목업 docs/design/mock/2026-11-13/guides-page.html).
 *
 * 본문 파일이 곧 상태다: 없으면 「준비 중」(안 열림), 있으면 목록·검색·[보기]에 올라온다.
 * 테스트는 씬 폴더를 임시 경로로 돌려 실제 resources/guides 내용과 무관하게 돈다.
 */
class GuidesPageTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/guides_'.uniqid());
        File::ensureDirectoryExists($this->dir);
        config(['guides.path' => $this->dir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function staff(string $role = '재무'): User
    {
        return User::factory()->create(['permission' => 'user', 'role' => $role, 'email_verified_at' => now()]);
    }

    private function scene(string $slug, string $html): void
    {
        File::ensureDirectoryExists($this->dir.'/'.$slug);
        File::put($this->dir.'/'.$slug.'/index.html', $html);
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/guides')->assertRedirect(route('login'));
    }

    public function test_every_scene_is_listed_and_a_scene_without_a_body_is_waiting(): void
    {
        $this->scene('purchase-payment', '<div class="gd-step"><p>확정한 줄 옆 딜러알림을 누르면 이체확인증을 붙여 넣는 창이 뜹니다.</p></div>');

        $all = collect(GuideCatalog::all())->keyBy('slug');
        $this->assertCount(29, $all);
        $this->assertSame('new', $all['purchase-payment']['status']);
        $this->assertSame('wait', $all['vehicle-register']['status']);
        $this->assertSame(range(1, 29), $all->pluck('no')->values()->all());

        $this->actingAs($this->staff())->get('/guides')->assertOk()
            ->assertSee('업무 가이드')
            ->assertSee('매입 지급과 딜러 입금완료 알림')
            ->assertSee('이체확인증을 붙여 넣는 창');   // 본문 글자가 검색 색인으로 내려간다
    }

    /** 검색은 본문에서도 찾는다 — 단, 접힌 개발 블록은 색인에 넣지 않는다. */
    public function test_search_index_carries_the_body_but_not_the_folded_developer_block(): void
    {
        $this->scene('purchase-payment', '<p>이체확인증을 붙입니다.</p><details><summary>개발 상세</summary><pre>erp_purchase_paid_v3</pre></details><script>var x = 1;</script>');

        $body = collect(GuideCatalog::all())->firstWhere('slug', 'purchase-payment')['body'];
        $this->assertStringContainsString('이체확인증을 붙입니다', $body);
        $this->assertStringNotContainsString('erp_purchase_paid_v3', $body);
        $this->assertStringNotContainsString('var x', $body);
    }

    public function test_a_ready_scene_opens_and_a_waiting_or_unknown_one_is_404(): void
    {
        $this->scene('purchase-payment', '<div class="gd-step"><h3>잔금 줄을 넣고 확정합니다</h3><img src="01.png"></div>');
        File::put($this->dir.'/purchase-payment/01.png', 'PNG');
        $user = $this->staff('수출통관');

        $this->actingAs($user)->get('/guides/purchase-payment')->assertOk()
            ->assertSee('잔금 줄을 넣고 확정합니다')
            ->assertSee(url('guides/purchase-payment').'/01.png', false);   // 그림 주소가 이 씬 폴더로 바뀐다
        $this->actingAs($user)->get('/guides/purchase-payment/01.png')->assertOk()->assertHeader('X-Robots-Tag', 'noindex');
        $this->actingAs($user)->get('/guides/vehicle-register')->assertNotFound();     // 준비 중
        $this->actingAs($user)->get('/guides/no-such-scene')->assertNotFound();
        $this->actingAs($user)->get('/guides/purchase-payment/index.html')->assertNotFound();   // 그림만 내보낸다
        $this->get('/guides/purchase-payment/01.png');   // (로그인 상태 유지 — 아래는 게스트로 다시)
        auth()->logout();
        $this->get('/guides/purchase-payment/01.png')->assertRedirect(route('login'));
    }

    /** 한 바퀴 씬은 한 바퀴 순서로 이전·다음이 이어진다 — 목록 순서가 아니라. */
    public function test_lap_scenes_chain_in_lap_order(): void
    {
        foreach (['vehicle-register', 'purchase-payment', 'sale-register', 'buyer-account'] as $slug) {
            $this->scene($slug, '<p>본문</p>');
        }

        $near = GuideCatalog::neighbours('purchase-payment');
        $this->assertSame('vehicle-register', $near['prev']['slug']);
        $this->assertSame('sale-register', $near['next']['slug'], '한 바퀴 다음은 판매 등록 — 사이의 다른 씬을 건너뛴다');
        $this->assertSame(3, $near['lapTotal']);
        $this->assertSame(2, $near['lapPos']);
        // 화면 순번은 볼 수 있는 씬끼리 — 일부만 올라온 동안 config 순번을 찍으면 「6 / 4」가 된다
        $this->scene('monthly-payout', '<p>본문</p>');   // config 로는 여섯째 — 지금 볼 수 있는 한 바퀴 씬은 넷
        $this->actingAs($this->staff())->get('/guides/monthly-payout')->assertOk()->assertSee('한 바퀴 4 / 4')->assertDontSee('한 바퀴 6 / 4');
        $this->assertNull(GuideCatalog::neighbours('buyer-account')['lapPos']);

        $this->assertSame('sale-register', GuideCatalog::neighbours('buyer-account')['prev']['slug'] ?? null);   // 한 바퀴 밖은 목록 순서
    }

    /** 사이드바 — 씬이 0장이면 종전 Notion 링크, 하나라도 올라오면 앱 안 가이드로. */
    public function test_sidebar_switches_from_notion_to_the_in_app_guide_once_a_scene_exists(): void
    {
        $user = $this->staff();
        $this->actingAs($user)->get(route('erp.dashboard'))->assertOk()
            ->assertSee('notion.site', false)->assertDontSee(route('guides.index'), false);

        $this->scene('purchase-payment', '<p>본문</p>');
        $this->actingAs($user)->get(route('erp.dashboard'))->assertOk()
            ->assertSee(route('guides.index'), false)->assertDontSee('notion.site', false);
    }

    /** board 링크 — 같은 회사 짝이 있고 board 쪽이 열렸을 때만. karaba 는 board 가 없다. */
    public function test_board_link_is_drawn_only_for_companies_with_a_board_once_it_is_live(): void
    {
        config(['company.template_set' => 'system']);
        $this->assertNull(GuideCatalog::boardUrl(), 'board 배포 전엔 링크를 그리지 않는다(404 로 보내지 않는다)');

        config(['guides.board_link_live' => true]);
        $this->assertSame('https://board.heymancar.com/guides', GuideCatalog::boardUrl());
        config(['company.template_set' => 'heyman']);
        $this->assertSame('https://board.heysellcar.com/guides', GuideCatalog::boardUrl());
        config(['company.template_set' => 'karaba']);
        $this->assertNull(GuideCatalog::boardUrl());
    }

    /**
     * 🇬🇧 언어를 영어로 바꾸면 가이드도 영어로 (jin 11-13) — 화면 문구·제목은 영어, 본문은 index.en.html 이 있으면 그것,
     * 없으면 한국어 본문을 「Korean only」 표시와 함께(빈 화면으로 두지 않는다).
     */
    public function test_english_locale_shows_english_titles_and_falls_back_to_the_korean_body(): void
    {
        $this->scene('purchase-payment', '<p>이체확인증을 붙입니다.</p>');
        $this->scene('sale-register', '<p>판매가를 넣습니다.</p>');
        File::put($this->dir.'/sale-register/index.en.html', '<p>Enter the sale price.</p>');
        app()->setLocale('en');

        $all = collect(GuideCatalog::all())->keyBy('slug');
        $this->assertSame('Register a sale', $all['sale-register']['title']);
        $this->assertStringContainsString('Enter the sale price', $all['sale-register']['body']);
        $this->assertFalse($all['sale-register']['koreanOnly']);
        $this->assertTrue($all['purchase-payment']['koreanOnly']);
        $this->assertStringContainsString('이체확인증', $all['purchase-payment']['body']);
        $this->assertStringContainsString('판매 등록', $all['sale-register']['keywords'], '영어 화면에서도 한글 제목으로 찾을 수 있다');
        $this->assertStringContainsString('Enter the sale price', GuideCatalog::html('sale-register'));

        File::ensureDirectoryExists($this->dir.'/savings');
        File::put($this->dir.'/savings/index.en.html', '<p>orphan</p>');
        $this->assertSame('wait', collect(GuideCatalog::all())->firstWhere('slug', 'savings')['status'], '한국어 본문이 기준 — 영어판만으로는 열리지 않는다');
    }

    public function test_every_scene_has_an_english_title(): void
    {
        $this->assertSame(array_keys(config('guides.scenes')), array_keys(config('guides.scenes_en')), '씬을 추가하면 scenes_en 에도 같은 순서로 넣는다');
        foreach (config('guides.scenes_en') as $slug => $pair) {
            $this->assertCount(2, $pair, $slug);
            $this->assertDoesNotMatchRegularExpression('/[가-힣]/u', implode(' ', $pair), "$slug 영어 제목에 한글이 섞였다");
        }
        $this->assertSame(array_keys(__('guides.groups', [], 'ko')), array_keys(config('guides.groups')));
        $this->assertSame(array_keys(__('guides', [], 'ko')), array_keys(__('guides', [], 'en')));
    }

    /** 시스템관리자 일은 업무가이드에 넣지 않는다 (jin 11-13). */
    public function test_no_system_admin_scene_is_registered(): void
    {
        $text = json_encode(config('guides.scenes'), JSON_UNESCAPED_UNICODE);
        foreach (['알림톡 로그', '확인 필요', '관제', '기능설정'] as $word) {
            $this->assertStringNotContainsString($word, $text);
        }
    }
}
