<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Volt 컴포넌트에서 public 프로퍼티와 public 메서드 이름이 겹치면 안 된다 (재발 방지 가드).
 *
 * 왜 위험한가:
 *   `public string $search` + `public function search()` 가 같이 있으면 브라우저에서 `$wire.search` 가
 *   **메서드가 아니라 프로퍼티 값(문자열)** 로 잡힌다. 그래서 `wire:click="search"` ·
 *   `wire:keydown.enter="search"` 가 아무 요청도 안 보내고, **JS 에러도 안 난다**.
 *   화면은 30초 `wire:poll` 이 돌 때만 갱신돼서 "검색이 30초 걸린다" 처럼 보인다.
 *
 * 실제 이력: 2026-05 차량목록에서 한 번 발생(→ applyFilters 로 개명), 그때 고친 화면 외
 *   재고관리·바이어·컨사이니·영업담당·정산 5개에 그대로 남아 2026-07-28 재발.
 *   메서드를 직접 부르는 단위 테스트로는 절대 못 잡는다(테스트는 PHP 메서드를 바로 호출하므로
 *   통과해버림) → 이렇게 정적 스캔으로 막는다.
 */
class VoltPropertyMethodCollisionTest extends TestCase
{
    public function test_no_volt_component_has_property_method_name_collision(): void
    {
        $root = resource_path('views/livewire');
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        $collisions = [];

        foreach ($files as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            $src = file_get_contents($file->getPathname());

            // Volt 단일파일의 PHP 클래스 블록만 대상 (Blade 템플릿 본문 제외).
            //   구분자를 문자열 연결로 조립 — 소스에 PHP 종료 태그가 그대로 박히면 이 파일 자체가 깨진다.
            $open = '<'.'?php';
            $close = '?'.'>';
            $start = strpos($src, $open);
            if ($start === false) {
                continue;
            }
            $end = strpos($src, $close, $start);
            $php = $end === false
                ? substr($src, $start)
                : substr($src, $start, $end - $start);

            // public [static] [?type] $name  — static·타입 힌트·속성(#[Url]) 앞붙음 모두 허용
            preg_match_all('/public\s+(?:static\s+)?(?:readonly\s+)?(?:[\w\\\\|?]+\s+)?\$(\w+)/', $php, $pm);
            preg_match_all('/public\s+function\s+(\w+)\s*\(/', $php, $mm);

            $shared = array_intersect(array_unique($pm[1]), array_unique($mm[1]));
            if ($shared !== []) {
                $rel = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
                $collisions[] = $rel.' → '.implode(', ', $shared);
            }
        }

        $this->assertSame([], $collisions, implode("\n", array_merge(
            ['Volt public 프로퍼티 ↔ 메서드 이름 충돌 (wire:click 이 조용히 죽습니다):'],
            $collisions,
            ['메서드를 다른 이름으로 바꾸세요 (예: search → searchNow / applyFilters).'],
        )));
    }
}
