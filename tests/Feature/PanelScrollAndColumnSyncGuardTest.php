<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 2026-09-11 에 jin 이 제보해 고친 두 가지를 지키는 가드 (SKILLS §8 #91-B · #91-C).
 *
 * 🚨 **기능 테스트로는 원리상 못 잡는다.** 둘 다 되돌아가도
 *    ①화면은 정상 렌더되고 ②저장도 되고 ③기존 테스트는 전부 초록이다.
 *    사람이 "스크롤이 튄다" / "새로고침을 두 번 해야 한다" 고 다시 제보해야 알게 된다.
 *    그래서 **소스 문자열을 정적으로** 검사한다.
 *
 * 조사(2026-09-12)에서 이 둘을 검증하는 테스트가 **0건**이라 신설했다.
 */
class PanelScrollAndColumnSyncGuardTest extends TestCase
{
    private const VIEW = 'resources/views/livewire/erp/vehicles/index.blade.php';

    private const JS = 'resources/js/app.js';

    /**
     * ⚠️ **단언에 거대한 소스를 넘기지 말 것.** PHPUnit 은 실패 메시지에 haystack 을 통째로 찍는다 —
     *    11,500줄 blade 를 `assertStringContainsString` 에 넘기면 실패 한 번이 **수 분간 멈춘 것처럼**
     *    보인다(2026-09-12 실측: 120초 타임아웃에 걸렸다). 판정은 str_contains / preg_match 로 미리 하고
     *    단언에는 bool 과 사람이 읽을 메시지만 넘긴다.
     */
    private function src(string $rel): string
    {
        $path = base_path($rel);
        $this->assertFileExists($path, "가드 대상 파일이 사라졌다: {$rel}");

        return (string) file_get_contents($path);
    }

    // ── #91-B 패널 스크롤 위치 보존 ──────────────────────────────────────

    public function test_the_panel_scroll_container_still_carries_the_marker(): void
    {
        $blade = $this->src(self::VIEW);

        $this->assertTrue(
            (bool) preg_match('/<div[^>]*overflow-y-auto[^>]*data-panel-scroll=/', $blade),
            '패널 스크롤 컨테이너에서 data-panel-scroll 표식이 사라졌다 — '
            ."app.js 가 복원 대상을 못 찾아 **매 왕복마다 맨 위로 튄다**(SKILLS §8 #91-B).\n"
            .'  표식은 overflow-y-auto 를 가진 그 div 에 있어야 한다.',
        );

        // ⚠️ 표식 **값**이 핵심이다 — 편집 중인 차량이 바뀌면 복원하지 않아야 한다.
        //    값을 상수로 바꾸면 다른 차를 열었을 때 엉뚱한 위치로 스크롤된다.
        $this->assertTrue(
            str_contains($blade, 'data-panel-scroll="{{ $editingId ?? \'new\' }}"'),
            '표식 값이 편집 중인 차량 id 가 아니다 — 다른 차를 열어도 이전 스크롤 위치로 되돌아간다.',
        );
    }

    public function test_the_commit_hook_restores_scroll_only_for_the_same_vehicle(): void
    {
        $js = $this->src(self::JS);

        $this->assertTrue(str_contains($js, "Livewire.hook('commit'"),
            '스크롤 복원이 걸려 있던 commit 훅이 사라졌다(SKILLS §8 #91-B).');

        // succeed 안에서 돌아야 한다 — 벤더 실측상 그게 morph 뒤 rAF 라 한 프레임도 안 깜빡인다.
        $this->assertTrue((bool) preg_match('/succeed\(\(\)\s*=>/', $js),
            '복원이 succeed 콜백 밖으로 나갔다 — morph 전에 되돌리면 그 값이 곧바로 덮인다.');

        $this->assertTrue(str_contains($js, 'scrollTop'), 'scrollTop 복원 코드가 없다.');

        // 🔑 다른 차를 열었으면 복원하지 않는다. 이 비교가 빠지면 「엉뚱한 위치」가 된다.
        $this->assertTrue(
            (bool) preg_match("/getAttribute\('data-panel-scroll'\)\s*!==\s*key/", $js),
            '표식 대조가 사라졌다 — 다른 차량을 열어도 이전 스크롤 위치를 복원해버린다.',
        );
    }

    public function test_the_date_field_only_notifies_livewire_when_the_value_actually_changed(): void
    {
        $js = $this->src(self::JS);

        $start = strpos($js, "document.addEventListener('focusout'");
        $this->assertNotFalse($start, '날짜칸 focusout 정규화 핸들러가 사라졌다 — 8자리 입력이 1970 이 된다.');
        $block = substr($js, $start, 900);

        // 🚨 예전엔 **무조건** input 을 재발행했다. 그 사이 날짜칸이 wire:model.live 가 되면서
        //    "칸을 눌렀다 빠져나오기만 해도" 서버 왕복이 나가 스크롤이 튀었다.
        $this->assertTrue(
            (bool) preg_match('/if\s*\(\s*el\.value\s*!==\s*before\s*\)\s*el\.dispatchEvent/', $block),
            "날짜칸 focusout 이 값 변화와 무관하게 input 을 재발행한다 — \n"
            .'  값을 안 바꿔도 서버 왕복이 나가 패널 스크롤이 맨 위로 튄다(SKILLS §8 #91-B).',
        );
    }

    // ── #91-C 컬럼 동기화 ────────────────────────────────────────────────

    public function test_the_column_sync_stamps_only_after_it_actually_sent(): void
    {
        $blade = $this->src(self::VIEW);

        $start = strpos($blade, 'pushToServer() {');
        $this->assertNotFalse($start, 'pushToServer 가 사라졌다 — 서버가 표시 컬럼을 영영 모른다.');
        $body = substr($blade, $start, 1600);

        $guard = strpos($body, 'if (!this.$wire)');
        $send = strpos($body, '$wire.syncVisibleColumns');
        $stamp = strpos($body, '_pushed = now');

        $this->assertNotFalse($guard, '$wire 준비 확인이 사라졌다.');
        $this->assertNotFalse($send, 'syncVisibleColumns 호출이 사라졌다.');
        $this->assertNotFalse($stamp, '_pushed 도장이 사라졌다.');

        // 🚨 순서가 전부다 — 도장을 먼저 찍으면 유실이 **그 페이지 수명 동안 영구 래치**된다.
        //    Alpine 이 Livewire 보다 먼저 살아난 페이지에서 「새로고침 두 번」이 그래서 났다.
        $this->assertGreaterThan($guard, $send,
            '$wire 확인보다 전송이 먼저다 — 순서가 뒤집혔다.');
        $this->assertGreaterThan($send, $stamp,
            "도장(_pushed)을 **보내기 전에** 찍고 있다 — 못 보낸 회차가 보낸 것으로 기록돼\n"
            .'  재시도가 영영 안 일어난다. 그게 「새로고침 2번」의 원인이었다(SKILLS §8 #91-C).');
    }

    public function test_a_failed_first_push_still_has_a_way_to_wake_up(): void
    {
        $blade = $this->src(self::VIEW);

        // 첫 시도가 유실됐을 때 깨어날 신호가 하나는 있어야 한다.
        $this->assertTrue(str_contains($blade, "document.addEventListener('livewire:initialized'"),
            "첫 전송이 유실됐을 때 다시 시도할 신호가 없다 — 그 페이지에서는 영영 안 보내진다.\n"
            .'  (도장을 안 찍는 것만으로는 부족하다. 아무도 다시 부르지 않기 때문이다.)');

        $this->assertTrue(str_contains($blade, 'queueMicrotask'),
            '즉시 재시도(queueMicrotask)가 사라졌다 — livewire:initialized 가 이미 지난 뒤면 못 깨어난다.');
    }

    public function test_the_list_does_not_poll_twice_while_the_panel_is_open(): void
    {
        $blade = $this->src(self::VIEW);

        // 목록과 패널이 한 컴포넌트라 wire:poll 이 **두 태그**에 있으면 둘 다 전체를 다시 그린다.
        // 30초마다 11,500줄 렌더가 2회가 된다(2026-09-12 정리).
        // ⚠️ 세는 단위는 「속성」이 아니라 「태그」다 — 루트 한 태그 안의 @if/@else 는 배타라 1회다.
        //    주석에도 같은 문자열이 나오므로 blade 주석을 먼저 걷어낸다.
        $stripped = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $blade);
        preg_match_all('/<[a-zA-Z][^>]*wire:poll[^>]*>/', $stripped, $m);
        $this->assertLessThanOrEqual(1, count($m[0]),
            'wire:poll 을 가진 태그가 '.count($m[0])."개다 — 같은 컴포넌트라 폴링마다 화면 전체를 다시 그린다.\n"
            .'  패널 하트비트는 루트 폴링이 겸해야 한다.  발견: '.implode(' | ', $m[0]));

        // 🔑 폴링 대상 메서드가 하트비트를 **실제로 부르는지**까지 본다.
        //    하나로 합치면서 하트비트를 흘리면 편집 잠금이 30초 뒤 만료돼 남이 같은 차를 연다.
        $this->assertTrue(str_contains($blade, 'wire:poll.30s="tick"'),
            '루트 폴링이 tick() 을 안 부른다 — 편집 잠금 하트비트가 갈 길이 없다.');

        $start = strpos($blade, 'public function tick(): void');
        $this->assertNotFalse($start, 'tick() 이 사라졌다 — 폴링이 가리키는 메서드가 없다.');
        $this->assertTrue(
            str_contains(substr($blade, $start, 400), '$this->heartbeat()'),
            'tick() 이 heartbeat() 를 안 부른다 — 편집 중 잠금이 만료돼 남이 끼어든다.',
        );
    }
}
