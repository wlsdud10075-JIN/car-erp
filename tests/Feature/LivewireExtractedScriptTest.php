<?php

namespace Tests\Feature;

use Livewire\Compiler\Parser\SingleFileParser;
use ReflectionClass;
use Tests\TestCase;

/**
 * 🧨 **0열 `<script>` 는 Livewire 4 가 뽑아 가고, 그 길엔 Blade 컴파일이 없다** (jin 2026-09-21 제보).
 *
 * jin 이 F12 Console 에서 잡아 줬다:
 *   `erp--vehicles--index.js:22 Uncaught (in promise) SyntaxError: Invalid or unexpected token`
 * 받아 보니 그 JS 에 **`label: @json(__('vehicle.col.brand_model'))` 가 글자 그대로** 있었다(31곳).
 *
 * 원인 = `SingleFileParser::extractScriptPortion()` —
 *   > Only match script tags at column 0 (root-level). Nested scripts are indented and should stay in the view.
 * 0열에 있는 `<script>` 는 **통째로 뽑혀 별도 JS 모듈로 서빙**되는데, 그 경로엔 Blade 가 없다.
 *
 * 🚨 **피해가 조용하고 크다** — 모듈이 SyntaxError 로 죽으면
 *   ① 컬럼 토글 드롭다운이 동작하지 않고
 *   ② `init()` 이 안 돌아 `syncVisibleColumns` 가 **한 번도 안 불리며**
 *   ③ 서버가 「모르면 전부 그린다」 폴백으로 **36칸을 전부 렌더**한다
 *      ⇒ 2026-09-07 의 컬럼 축소(§8 #79, 908KB→584KB)가 **통째로 무효화**된다.
 *   화면은 멀쩡히 뜨고 저장도 된다. 그래서 **기능 테스트로는 원리상 못 잡는다.**
 *
 * ⇒ 이 가드는 **벤더 파서를 실제로 돌려** 추출본에 Blade 가 남는지 본다.
 *    정규식을 흉내 내지 않는다 — Livewire 가 규칙을 바꾸면 그때 같이 따라가야 한다(§8 #44).
 */
class LivewireExtractedScriptTest extends TestCase
{
    /** 벤더 파서가 실제로 뽑아내는 `<script>` 본문. 없으면 null(= 뷰에 남는다 = 안전). */
    private function extractedScript(string $path): ?string
    {
        $rc = new ReflectionClass(SingleFileParser::class);
        if (! $rc->hasMethod('extractScriptPortion')) {
            $this->markTestSkipped('Livewire 파서 구조가 바뀌었다 — 이 가드를 다시 쓸 것');
        }
        $m = $rc->getMethod('extractScriptPortion');
        $m->setAccessible(true);

        // 파서는 @script/@endscript 를 먼저 걷어낸다(그건 Livewire 가 정상 처리한다).
        $contents = preg_replace('/@script\s*.*?@endscript/s', '', file_get_contents($path));

        return $m->invokeArgs(null, [&$contents]);
    }

    /** @return list<string> */
    private function views(): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views/livewire')));
        foreach ($it as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.blade.php')) {
                $out[] = $f->getPathname();
            }
        }
        sort($out);

        return $out;
    }

    /** 🚨 추출되는 `<script>` 안에는 Blade 가 하나도 없어야 한다. */
    public function test_no_blade_directive_survives_into_an_extracted_script_module(): void
    {
        $bad = [];

        foreach ($this->views() as $path) {
            $script = $this->extractedScript($path);
            if ($script === null) {
                continue;   // 0열 script 없음 → 뷰에 남는다 → Blade 정상 컴파일
            }
            foreach (explode("\n", $script) as $i => $line) {
                if (preg_match('/@json\b|@php\b|@lang\b|@class\b|\{\{|\{!!/', $line, $hit)) {
                    $bad[] = basename($path).':'.($i + 1).'  '.trim($line);
                }
            }
        }

        $this->assertSame([], $bad, implode("\n", array_merge(
            ['추출되는 <script> 안에 Blade 가 남아 있다 — 브라우저가 그 글자를 받아 SyntaxError 로 죽는다.',
                '고치는 법 = 값을 PHP 에서 만들어 x-data 로 넘긴다(HTML 본문은 정상 컴파일된다).'],
            $bad
        )));
    }

    /** 차량목록 — 라벨이 실제로 `x-data` 로 넘어가고 JS 는 그걸 인자로 받는다. */
    public function test_the_vehicle_column_labels_travel_through_x_data(): void
    {
        $src = file_get_contents(resource_path('views/livewire/erp/vehicles/index.blade.php'));

        $this->assertStringContainsString('vehicleColumnsToggle(@js($this->columnToggleOptions()))', $src,
            '라벨을 x-data 로 안 넘기면 JS 안에서 다시 Blade 를 쓰게 된다');
        $this->assertStringContainsString('function vehicleColumnsToggle(columns) {', $src,
            'JS 가 라벨을 인자로 안 받는다');
        $this->assertStringContainsString('togglableColumns: columns,', $src);
    }

    /**
     * 🔑 두 목록이 갈리면 「드롭다운엔 있는데 저장이 안 되는」 칸이 생긴다.
     *    PHP `columnToggleOptions()` 키 == JS `defaultVisible` 키.
     */
    public function test_the_php_label_list_and_the_js_default_list_agree(): void
    {
        $src = file_get_contents(resource_path('views/livewire/erp/vehicles/index.blade.php'));

        preg_match('/public function columnToggleOptions\(\): array\s*\{\s*return \[(.*?)\];/s', $src, $m);
        $this->assertNotEmpty($m, 'columnToggleOptions() 를 못 찾았다');
        preg_match_all("/'key' => '([a-z_0-9]+)'/", $m[1], $php);

        preg_match('/const defaultVisible = \{(.*?)\};/s', $src, $d);
        $this->assertNotEmpty($d, 'defaultVisible 을 못 찾았다');
        preg_match_all('/([a-z_0-9]+):\s*(?:true|false)/', $d[1], $js);

        $a = $php[1];
        $b = $js[1];
        sort($a);
        sort($b);
        $this->assertSame($b, $a, '컬럼 목록이 PHP↔JS 에서 갈렸다');
    }
}
