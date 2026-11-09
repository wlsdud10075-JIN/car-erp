<?php

namespace Tests\Feature;

use App\Models\Salesman;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 📝 사내직원관리 목록 「메모」 열 (jin 2026-11-09 «드로어 메모가 각 행에 표시되었으면 — 길면 호버로 전체»).
 * 데스크탑 = 한 줄로 자르고 title 에 전체 / 폰 = 카드에 2줄 clamp(호버가 없다, SKILLS §8 #98).
 */
class SalesmanMemoColumnTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_shows_the_memo_truncated_with_the_full_text_in_title_and_blank_when_empty(): void
    {
        $long = str_repeat('긴메모 ', 40).'끝';
        $with = Salesman::create(['name' => '메모있음', 'type' => 'employee', 'is_active' => true, 'memo' => $long]);
        $without = Salesman::create(['name' => '메모없음', 'type' => 'employee', 'is_active' => true, 'memo' => null]);

        $this->actingAs(User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]));
        $html = Volt::test('erp.salesmen.index')->html();

        $this->assertStringContainsString(__('common.memo'), $html, '헤더에 메모 열');
        // 데스크탑 셀 — 자르는 건 CSS(truncate), 전체는 title
        $this->assertMatchesRegularExpression('/data-memo-cell="'.$with->id.'" title="'.preg_quote(e($long), '/').'">/u', $html);
        $this->assertStringContainsString('truncate', $html);
        // 메모 없음 = 빈 칸, title 없음
        $this->assertMatchesRegularExpression('/data-memo-cell="'.$without->id.'">\s*<\/td>/u', $html);
        // 폰 카드 — 2줄 clamp 로 본문이 그대로(호버 없음)
        $this->assertMatchesRegularExpression('/line-clamp-2[^>]*data-memo-card="'.$with->id.'">'.preg_quote(e($long), '/').'</u', $html);
        $this->assertStringNotContainsString('data-memo-card="'.$without->id.'"', $html);
    }

    /** 새 유틸 클래스는 빌드 CSS 에 있어야 먹는다(§8 #50) — 없으면 잘리지도 clamp 되지도 않은 채 화면은 멀쩡해 보인다. */
    public function test_memo_classes_exist_in_the_built_css(): void
    {
        $manifest = public_path('build/manifest.json');
        if (! is_file($manifest)) {
            $this->markTestSkipped('빌드 산출물 없음');
        }
        $entry = collect(json_decode(file_get_contents($manifest), true))->first(fn ($v, $k) => str_ends_with($k, 'app.css'));
        $css = file_get_contents(public_path('build/'.$entry['file']));
        foreach (['.truncate', '.line-clamp-2', '.max-w-\\[200px\\]', '.break-all'] as $cls) {
            $this->assertStringContainsString($cls, $css, "$cls 가 빌드 CSS 에 없다");
        }
    }
}
