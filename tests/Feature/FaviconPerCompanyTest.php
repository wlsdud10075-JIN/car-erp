<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 브라우저 탭 아이콘 — 회사별 (jin 2026-08-21).
 *
 * 🧭 **왜 선언이 필요한가**: `public/favicon.ico` 는 첫 커밋(스타터킷)부터 **0바이트**였고
 *    `<link rel="icon">` 선언도 없었다. 그래서 3사 모두 아이콘이 없었는데, 크롬이 캐시한
 *    옛 아이콘을 계속 그려서 **있는 것처럼 보였다**(실측: heysellcar 가 빈 파일을 주는데도
 *    탭에는 아이콘이 떴다). 관례(`/favicon.ico`)는 파일명이 고정이라 회사별 분기도 불가능하다.
 *
 * 🚨 이 partial 은 **로그인·에러 페이지 포함 모든 화면**에서 렌더된다 — 여기서 예외가 나면
 *    화면이 통째로 죽는다. 그래서 DB 폴백과 화이트리스트를 검사한다.
 */
class FaviconPerCompanyTest extends TestCase
{
    use RefreshDatabase;

    private const SETS = ['system', 'heyman', 'karaba'];

    /** 세 회사 아이콘 파일이 실제로 있어야 한다 — 없으면 탭에 404 가 뜬다. */
    public function test_every_company_has_an_icon_file(): void
    {
        foreach (self::SETS as $set) {
            $path = public_path("favicon-{$set}.ico");
            $this->assertFileExists($path);
            $this->assertGreaterThan(500, filesize($path), "favicon-{$set}.ico 가 비어 있다");
        }
    }

    /** 회사 프로파일에 맞는 파일을 가리킨다. */
    public function test_declaration_follows_the_company_profile(): void
    {
        foreach (self::SETS as $set) {
            Setting::updateOrCreate(['key' => 'company_template_set'], ['value' => $set, 'type' => 'string']);

            $html = (string) view('partials.head')->render();

            $this->assertStringContainsString("favicon-{$set}.ico", $html, "{$set} 아이콘이 안 걸렸다");
            $this->assertStringContainsString('rel="icon"', $html);
        }
    }

    /**
     * 🚨 값이 예상 밖이어도 존재하지 않는 파일을 가리키지 않는다.
     * 새 회사를 추가하고 아이콘을 안 만들면 탭이 404 가 된다 — 조용히 깨지는 부류다.
     */
    public function test_unknown_profile_falls_back_instead_of_404(): void
    {
        Setting::updateOrCreate(['key' => 'company_template_set'], ['value' => 'nosuchco', 'type' => 'string']);

        $html = (string) view('partials.head')->render();

        $this->assertStringNotContainsString('favicon-nosuchco.ico', $html);
        $this->assertStringContainsString('favicon-system.ico', $html);
    }

    /** 캐시 무효화 쿼리가 붙어 있어야 한다 — 파비콘 캐시는 강제 새로고침으로도 잘 안 지워진다. */
    public function test_declaration_carries_a_cache_buster(): void
    {
        $this->assertMatchesRegularExpression(
            '/favicon-[a-z]+\.ico\?v=\d+/',
            (string) view('partials.head')->render(),
            '캐시 무효화 쿼리가 없으면 아이콘을 바꿔도 옛것이 계속 보인다'
        );
    }
}
