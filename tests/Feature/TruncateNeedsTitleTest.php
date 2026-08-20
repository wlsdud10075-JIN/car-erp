<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * `truncate` 로 잘리는 칸은 **`title` 이 있어야 한다** (jin 2026-08-20).
 *
 * 사고: 바이어 패널 → 적립금 메모가 `max-w-[120px] truncate` 라 「…」로 잘리는데 `title` 이 없어
 *   **호버해도 내용을 볼 방법이 없었다**. 실측 heymanerp: 메모 57건 중 **53건이 잘려 있었고**,
 *   대부분 "차량 114마1731 savings_used 자동 동기화 (delta -200000)" 같은 49자짜리라
 *   차량번호도 금액도 안 보였다. jin: "마우스 갖다대고 가만히 있어도 내용이 잘려서 안보이거든?"
 *   같은 형태가 5곳 더 있었다(승인 중복차량·채권 차대번호/바이어·차량 서류명·포워딩 라벨).
 *
 * ⚠️ **기능 테스트로는 원리상 못 잡는다** — 화면은 정상 렌더되고 「…」도 정상 동작이다.
 *    그래서 정적 스캔으로 막는다.
 *
 * 💡 왜 CSS 툴팁이 아니라 `title` 인가 — 이 칸들은 **슬라이드 패널·테이블 안**에 있어서
 *    `absolute` 툴팁을 쓰면 `overflow` 에 갇혀 또 잘린다. `title` 은 브라우저가 그려서 안 잘린다.
 */
class TruncateNeedsTitleTest extends TestCase
{
    /** 스캔 대상 — 사용자가 데이터를 읽는 화면. */
    private const DIRS = [
        'resources/views/livewire/erp',
        'resources/views/livewire/admin',
    ];

    /**
     * 예외 — 잘려도 되는 것. 전부 **잘린 뒤에도 뜻이 통하거나 원문이 옆에 있는** 경우다.
     * 새로 추가할 땐 "그 칸의 내용을 다른 데서 볼 수 있는가" 를 먼저 확인할 것.
     */
    private const ALLOW = [
        'truncateInvoice',        // 메서드명(포워딩 인보이스 절삭) — 클래스가 아니다
        'rec_truncate',           // 그 버튼 라벨
    ];

    public function test_every_truncated_value_has_a_title_attribute(): void
    {
        $bad = [];

        foreach (self::DIRS as $dir) {
            $path = base_path($dir);
            if (! is_dir($path)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path));
            foreach ($it as $file) {
                if ($file->isDir() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                    continue;
                }
                $lines = file($file->getPathname());
                foreach ($lines as $i => $line) {
                    if (! str_contains($line, 'truncate')) {
                        continue;
                    }
                    // 값을 그리는 줄만 본다 — 정적 문구가 잘리는 건 문제가 아니다.
                    if (! str_contains($line, '{{') || str_contains($line, '{{--')) {
                        continue;
                    }
                    foreach (self::ALLOW as $allow) {
                        if (str_contains($line, $allow)) {
                            continue 2;
                        }
                    }
                    if (str_contains($line, 'title=')) {
                        continue;
                    }
                    $rel = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
                    $bad[] = $rel.':'.($i + 1).'  '.trim($line);
                }
            }
        }

        $this->assertSame([], $bad,
            "truncate 로 잘리는 칸에 title 이 없다 — 호버해도 내용을 볼 수 없다.\n"
            ."해당 요소에 title=\"{{ 원본값 }}\" 을 추가할 것:\n  ".implode("\n  ", $bad));
    }
}
