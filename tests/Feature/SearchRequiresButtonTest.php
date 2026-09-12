<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 🔎 **값을 치는 동안 목록을 다시 조회하지 않는다** — 전 화면 공통 (jin 2026-09-08).
 *
 * 왜 정적 검사인가: `wire:model.live` 로 되돌려도 **화면은 멀쩡히 동작한다**. 검색도 잘 된다.
 * 달라지는 건 「타이핑하는 동안 서버에 요청이 몇 번 가는가」뿐이라 기능 테스트로는 원리상 못 잡는다.
 *
 * 🧭 **허용목록 방식인 이유** — 처음엔 프로퍼티 이름이 `search` 인 칸만 검사했는데,
 *    차량관리의 **발송월 필터(`shipmentMonth`)** 를 그대로 놓쳤다(jin 이 잡았다). 이름으로 거르면
 *    「필터인데 search 라고 안 지은 칸」이 계속 빠져나간다. 그래서 **`.live` 인 텍스트 칸을 전부 잡고**,
 *    목록 조회가 아닌 것만 아래 목록에 사유와 함께 남긴다. 새 칸을 추가하면 여기 오게 되고,
 *    그때 「이건 미리보기인가 조회인가」를 한 번은 생각하게 된다.
 *
 * 비용도 실재한다: 검색은 `LIKE '%…%'` 라 인덱스를 못 타는데 감사로그·알림톡로그는 운영에 수만 행이다.
 * 채권관리·재무처리·차량관리는 `wire:poll` 까지 겹친다.
 *
 * 목록 필터의 올바른 형태:
 *   <input wire:model="x" wire:keydown.enter="searchNow">   +   <button wire:click="searchNow">
 *   ⚠️ 메서드 이름은 `searchNow`(또는 화면 관례인 `applyFilters`) — `search()` 로 지으면
 *      프로퍼티와 겹쳐 **버튼이 요청조차 안 보내고 죽는다**(SKILLS §8 #32, 이 프로젝트에서 2번 발생).
 */
class SearchRequiresButtonTest extends TestCase
{
    /**
     * `.live` 를 유지해도 되는 텍스트 입력 — **목록 조회가 아닌 것**만.
     *
     * 여기 추가하기 전에 답할 것: **이 칸이 목록/집계를 다시 조회하는가?**
     *   - 그렇다 → 허용 안 됨. `wire:model` + Enter + 버튼으로.
     *   - 아니다(입력 즉시 미리보기·자동완성) → 사유를 적고 추가.
     *
     * @var array<string, string> 프로퍼티(블레이드 표현식 제거) => 사유
     */
    private const LIVE_ALLOWED = [
        // 자동완성 — 치는 대로 후보 드롭다운을 띄운다. 버튼을 달면 기능이 죽는다.
        'vehicleSearch' => '정산 신규 모달 차량 찾기 = 자동완성',

        // 입력 즉시 **미리보기**를 다시 그리는 폼 칸 — 목록을 조회하지 않는다.
        'settlement_ratio' => '정산 비율 → 실지급액 미리보기',
        'per_unit_amount' => '건당 금액 → 실지급액 미리보기',
        'other_deduction' => '기타공제 → 실지급액 미리보기',
        'licenseTotal' => '면허비 총액 → n/1 분배 미리보기',
        'invForm..amount' => '포워딩 인보이스 금액 → 원화 환산 미리보기',
        'invForm..manual_rate' => '포워딩 인보이스 환율 → 원화 환산 미리보기',
        'invForm..actual_paid_krw' => '포워딩 실지급 원화 → 차액 미리보기',
        'finalPayments..payment_date' => '잔금 수금일 → 그 날짜 마감환율 자동기입',

        // 알림톡 시각 규칙 편집 — 고칠 때마다 「사람 말로 요약한 문장」을 다시 그린다.
        //   🗑️ 'timeRules...to' 는 2026-09-12 에 제거했다 — 수신자를 손으로 적던 칸이
        //      역할별 접이식 피커(버튼·체크박스)로 바뀌어 자유 입력칸 자체가 없어졌다.
        'timeRules...from' => '알림톡 수신 시각 규칙 미리보기',
        'timeRules...till' => '알림톡 수신 시각 규칙 미리보기',
    ];

    /** 목록 검색칸의 관례 이름 — 실행 수단이 반드시 있어야 한다. */
    private const SEARCH_PROPS = ['search', 'vinSearch'];

    public function test_no_text_filter_queries_while_typing(): void
    {
        $offenders = [];

        foreach ($this->livewireViews() as $path) {
            $src = file_get_contents($path);
            $rel = $this->rel($path);

            preg_match_all('/<(input|textarea)\b[^>]*?wire:model\.live[^>]*?>/s', $src, $tags);
            foreach ($tags[0] as $tag) {
                $type = preg_match('/type="([a-z]+)"/', $tag, $t) ? $t[1] : 'text';
                // 한 번 누르면 끝인 것들 — 「치는 동안」이 없다.
                if (in_array($type, ['checkbox', 'radio', 'date', 'file', 'hidden'], true)) {
                    continue;
                }
                if (! preg_match('/wire:model\.live[^=]*="([^"]+)"/', $tag, $m)) {
                    continue;
                }
                // `finalPayments.{{ $idx }}.payment_date` → `finalPayments..payment_date`
                $prop = preg_replace('/\{\{.*?\}\}/', '', $m[1]);

                if (! array_key_exists($prop, self::LIVE_ALLOWED)) {
                    $offenders[] = $rel.' → wire:model.live="'.$prop.'"';
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", array_merge(
            ['치는 동안 서버로 요청이 가는 텍스트 칸이다.', '',
                '목록/집계를 조회하는 칸이면 버튼 방식으로 바꿀 것:',
                '  <input wire:model="x" wire:keydown.enter="searchNow" ...>',
                '  <button wire:click="searchNow" class="btn-search">{{ __(\'common.search\') }}</button>', '',
                '입력 즉시 미리보기를 그리는 칸이면 LIVE_ALLOWED 에 사유와 함께 추가할 것:', ''],
            $offenders,
        )));
    }

    /**
     * 검색칸이 있으면 **실행 수단**도 있어야 한다.
     * deferred 로만 바꾸고 버튼·Enter 를 안 달면 **검색이 아예 안 되는** 화면이 된다
     * (타이핑해도 아무 일이 없고, 사용자는 「데이터가 없다」로 읽는다).
     */
    public function test_every_search_box_has_a_way_to_run_it(): void
    {
        $missing = [];

        foreach ($this->livewireViews() as $path) {
            $src = file_get_contents($path);

            if (! preg_match('/wire:model=["\'](search|vinSearch)["\']/', $src)) {
                continue;
            }
            $hasEnter = str_contains($src, 'wire:keydown.enter="searchNow"')
                || str_contains($src, 'wire:keydown.enter="applyFilters"');
            $hasButton = str_contains($src, 'wire:click="searchNow"')
                || str_contains($src, 'wire:click="applyFilters"');

            if (! $hasEnter || ! $hasButton) {
                $missing[] = $this->rel($path).' (Enter='.($hasEnter ? 'O' : 'X').' 버튼='.($hasButton ? 'O' : 'X').')';
            }
        }

        $this->assertSame([], $missing,
            "검색칸은 있는데 실행 수단이 없다 — 타이핑해도 아무 일이 안 일어난다:\n".implode("\n", $missing));
    }

    /**
     * 🚨 실행 메서드는 `searchNow` 여야 한다 — `search()` 는 프로퍼티와 이름이 겹쳐
     * `$wire.search` 가 메서드가 아니라 **문자열**로 잡히고, 버튼이 **에러 없이** 죽는다.
     */
    public function test_the_runner_is_never_named_after_the_property(): void
    {
        foreach ($this->livewireViews() as $path) {
            $src = file_get_contents($path);
            if (! str_contains($src, 'public string $search')) {
                continue;
            }
            $this->assertDoesNotMatchRegularExpression(
                '/public function search\s*\(/', $src,
                $this->rel($path).' : $search 프로퍼티와 search() 메서드가 같이 있다 — 버튼이 조용히 죽는다(SKILLS §8 #32)'
            );
        }
    }

    /** 허용목록이 낡지 않게 — 이제 안 쓰는 항목은 지워야 한다(다음 사람이 근거로 삼는다). */
    public function test_allow_list_has_no_dead_entries(): void
    {
        $all = '';
        foreach ($this->livewireViews() as $path) {
            $all .= file_get_contents($path);
        }
        $all = preg_replace('/\{\{.*?\}\}/', '', $all);

        // ⚠️ 판정을 미리 하고 단언에는 bool 만 넘긴다 — `$all` 은 전 Livewire 화면을 이어 붙인
        //    수 MB 짜리다. 그대로 단언 인자로 주면 **실패할 때 그걸 통째로 출력**하느라
        //    테스트가 멈춘 것처럼 보인다(2026-09-12 실측, SKILLS §8 #93).
        $this->assertTrue(str_contains($all, 'wire:model.live'), 'Livewire 화면을 하나도 못 읽었다');

        foreach (array_keys(self::LIVE_ALLOWED) as $prop) {
            $this->assertTrue(
                (bool) preg_match('/wire:model\.live[^=]*="'.preg_quote($prop, '/').'"/', $all),
                "LIVE_ALLOWED 의 '{$prop}' 이 화면에 없다 — 지웠으면 목록에서도 지울 것"
            );
        }
    }

    /** @return list<string> */
    private function livewireViews(): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views/livewire')));
        foreach ($it as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $out[] = $file->getPathname();
            }
        }
        sort($out);

        return $out;
    }

    private function rel(string $path): string
    {
        return str_replace('\\', '/', substr($path, strlen(base_path()) + 1));
    }
}
