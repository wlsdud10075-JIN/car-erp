<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * 🔍 검색어 앞뒤 공백은 쿼리에 들어가기 전에 잘라낸다 (jin 2026-09-02 제보).
 *
 * 사고: 바이어 이메일을 복사해 붙이면 끝에 공백이 딸려와 `LIKE '%…icloud.com %'` 가 되어
 *      **분명히 있는 데이터가 조용히 0건**이 된다. 예외도 안내도 없어 "데이터가 없나?" 로 읽힌다.
 *
 * 🚫 Livewire 프로퍼티 자체를 trim 하지 말 것 — 검색칸 절반이 `wire:model.live.debounce` 라
 *    두 단어를 칠 때 **사용자가 방금 친 공백을 서버가 지워버린다.** 자르는 곳은 **쿼리에 넣는 지점**.
 *
 * 단일 출처 = App\Support\SearchTerm (of/like).
 */
class SearchTermTrimTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Buyer::$skipAutoConsignee = true;   // 바이어 저장 훅이 컨사이니를 자동 생성한다 — 이 테스트와 무관.

        return User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
    }

    protected function tearDown(): void
    {
        Buyer::$skipAutoConsignee = false;
        parent::tearDown();
    }

    public function test_pasted_email_with_surrounding_space_still_finds_the_buyer(): void
    {
        $this->actingAs($this->admin());
        Buyer::create(['name' => 'ARI IMERI', 'contact_email' => 'ari.imeri02@icloud.com']);

        foreach (['ari.imeri02@icloud.com ', ' ari.imeri02@icloud.com', '  ari.imeri02@icloud.com  '] as $term) {
            Volt::test('erp.buyers.index')->set('search', $term)->assertSee('ARI IMERI');
        }
    }

    public function test_whitespace_only_search_is_treated_as_no_filter(): void
    {
        $this->actingAs($this->admin());
        Buyer::create(['name' => 'ARI IMERI', 'contact_email' => 'ari.imeri02@icloud.com']);

        Volt::test('erp.buyers.index')->set('search', '   ')->assertSee('ARI IMERI');
    }

    /**
     * 새 검색칸이 정규화 없이 추가되면 그 화면만 조용히 0건이 된다 — 정적으로 막는다.
     * ⚠️ 기능 테스트로는 원리상 못 잡는다(나머지 화면은 전부 멀쩡하다).
     */
    public function test_no_search_property_reaches_a_query_raw(): void
    {
        $bad = [];
        $root = base_path('resources/views/livewire');
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($it as $file) {
            if ($file->isDir() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            $src = (string) file_get_contents($file->getPathname());
            $rel = str_replace($root.DIRECTORY_SEPARATOR, '', $file->getPathname());

            $patterns = [
                '/"%\{\$this->\w*[Ss]earch\w*\}%"/' => 'LIKE 보간에 날값',
                '/->when\(\$this->\w*[Ss]earch\w*\s*,/' => 'when() 조건에 날값',
                '/\'%\'\s*\.\s*\$this->\w*[Ss]earch\w*/' => 'LIKE 연결에 날값',
            ];

            foreach ($patterns as $re => $why) {
                if (preg_match_all($re, $src, $m)) {
                    foreach ($m[0] as $hit) {
                        $bad[] = "{$rel} :: {$why} :: {$hit}";
                    }
                }
            }
        }

        $this->assertSame([], $bad,
            "검색어를 정규화 없이 쿼리에 넣는 곳이 있다 — App\Support\SearchTerm::of()/like() 를 쓸 것:\n".implode("\n", $bad));
    }
}
