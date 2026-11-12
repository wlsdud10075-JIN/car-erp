<?php

namespace App\Services\Ops;

use Illuminate\Support\Facades\File;

/**
 * 세컨드 브레인 목록 만들기 — 개발 PC 에서 돈다 (jin 2026-11-12 관제센터 3단계 «아카이브·메모리·저장문서에 뭐가 저장됐는지 알 수 없었다»).
 *
 * 재료는 운영 서버에 없다: `.md` 는 dev 전용(master 제외), 메모리는 이 PC 의 `~/.claude/projects/<레포>/memory`. 그래서 여기서 목록+본문을 JSON 으로
 * 만들어(`ops:brain-sync --push`) 허브(ssancarerp)에 서명해서 올린다. 화면은 OpsBrain 이 그 JSON 을 읽는다.
 *
 * 덩어리 7개 = 교훈(SKILLS §8 + 아카이브 #1~#95) · 메모리 · 설계 · 연동 스펙 · 운영 기록 · 회의록 · 가드 테스트(제목만).
 * 본문은 덩어리마다 BODY_MAX 로 자른다(큰 운영 기록 1MB+). 🚫 .env·키·개인정보는 재료가 아니다(문서·메모리만).
 */
class BrainCatalog
{
    public const BODY_MAX = 60_000;

    public const CLUSTERS = [
        'skills' => ['label' => '교훈 SKILLS §8', 'color' => '#b28cff'],
        'memory' => ['label' => '메모리', 'color' => '#56d8ff'],
        'tests' => ['label' => '가드 테스트', 'color' => '#4f8dff'],
        'ops' => ['label' => '운영 기록', 'color' => '#ffa25c'],
        'design' => ['label' => '설계 문서', 'color' => '#3dffaa'],
        'meet' => ['label' => '회의록', 'color' => '#ff6fb5'],
        'spec' => ['label' => '연동 스펙', 'color' => '#ffe066'],
    ];

    public static function build(string $repo, ?string $memoryDir): array
    {
        $clusters = [];
        foreach (self::CLUSTERS as $key => $meta) {
            $items = match ($key) {
                'skills' => self::skills($repo),
                'memory' => $memoryDir && is_dir($memoryDir) ? self::memory($memoryDir) : [],
                'tests' => self::tests($repo),
                'ops' => self::docs($repo, 'docs/operations', 'ops'),
                'design' => self::docs($repo, 'docs/design', 'design'),
                'meet' => self::docs($repo, 'docs/meetings', 'meet'),
                'spec' => self::docs($repo, 'docs/integration', 'spec'),
            };
            $clusters[$key] = $meta + ['key' => $key, 'count' => count($items), 'items' => array_values($items)];
        }

        return ['generated_at' => now()->toIso8601String(), 'repo_head' => self::head($repo), 'clusters' => $clusters];
    }

    /** `### 137. 🧩 **제목** (날짜)` 줄 — 본문 SKILLS.md(#96~) + 아카이브(#1~#95). 최신 번호가 먼저. */
    private static function skills(string $repo): array
    {
        $items = [];
        foreach ([$repo.'/SKILLS.md', $repo.'/docs/archive/skills-8-2026-09-22/SKILLS-8-lessons-1-95.md'] as $file) {
            if (! is_file($file)) {
                continue;
            }
            $lines = preg_split('/\r?\n/', (string) file_get_contents($file));
            $cur = null;
            foreach ($lines as $line) {
                if (preg_match('/^### (\d{1,3}(?:-[A-Z])?)\. (.+)$/u', $line, $m) && (int) $m[1] >= 1) {
                    if ($cur) {
                        $items[] = self::finishLesson($cur);
                    }
                    $cur = ['no' => '#'.$m[1], 'title' => $m[2], 'file' => basename($file), 'body' => []];

                    continue;
                }
                if ($cur !== null) {
                    if (preg_match('/^## /', $line)) {   // §8 이 끝나는 자리
                        $items[] = self::finishLesson($cur);
                        $cur = null;

                        continue;
                    }
                    $cur['body'][] = $line;
                }
            }
            if ($cur) {
                $items[] = self::finishLesson($cur);
            }
        }
        usort($items, fn ($a, $b) => (int) ltrim($b['no'], '#') <=> (int) ltrim($a['no'], '#'));

        return $items;
    }

    private static function finishLesson(array $cur): array
    {
        $title = trim(preg_replace('/\*\*/', '', $cur['title']));
        $date = preg_match('/\((\d{4}-\d{2}-\d{2})/u', $title, $m) ? $m[1] : null;

        return ['id' => $cur['no'], 'no' => $cur['no'], 'title' => mb_substr($title, 0, 200), 'meta' => $date ?? '', 'path' => $cur['file'], 'body' => self::cap(implode("\n", $cur['body']))];
    }

    /** 메모리 — frontmatter(name·description·type) + 수정 시각. _archive 는 뺀다(끝난 것). */
    private static function memory(string $dir): array
    {
        $items = [];
        foreach (File::files($dir) as $f) {
            if ($f->getExtension() !== 'md') {
                continue;
            }
            $text = (string) file_get_contents($f->getPathname());
            $name = preg_match('/^name:\s*(.+)$/m', $text, $m) ? trim($m[1]) : $f->getFilenameWithoutExtension();
            $desc = preg_match('/^description:\s*(.+)$/m', $text, $m) ? trim($m[1], " \"'") : '';
            $type = preg_match('/^\s*type:\s*(\w+)/m', $text, $m) ? $m[1] : '';
            $items[] = ['id' => $f->getFilename(), 'no' => $type ?: 'memo', 'title' => mb_substr($desc !== '' ? $desc : $name, 0, 200), 'meta' => date('Y-m-d', $f->getMTime()),
                'path' => $f->getFilename(), 'body' => self::cap($text), 'mtime' => $f->getMTime()];
        }
        usort($items, fn ($a, $b) => $b['mtime'] <=> $a['mtime']);

        return array_map(fn ($i) => array_diff_key($i, ['mtime' => 1]), $items);
    }

    private static function docs(string $repo, string $sub, string $no): array
    {
        $dir = $repo.'/'.$sub;
        if (! is_dir($dir)) {
            return [];
        }
        $items = [];
        foreach (File::allFiles($dir) as $f) {
            if ($f->getExtension() !== 'md') {
                continue;
            }
            $text = (string) file_get_contents($f->getPathname());
            $h1 = preg_match('/^# (.+)$/m', $text, $m) ? trim($m[1]) : $f->getFilenameWithoutExtension();
            $rel = str_replace('\\', '/', $f->getRelativePathname());
            $items[] = ['id' => $rel, 'no' => $no, 'title' => mb_substr($rel.' — '.$h1, 0, 200), 'meta' => date('Y-m-d', $f->getMTime()), 'path' => $sub.'/'.$rel, 'body' => self::cap($text), 'mtime' => $f->getMTime()];
        }
        usort($items, fn ($a, $b) => $b['mtime'] <=> $a['mtime']);

        return array_map(fn ($i) => array_diff_key($i, ['mtime' => 1]), $items);
    }

    /** 가드 테스트 — 클래스명 + docblock 첫 줄(무엇을 막는가). 본문은 안 싣는다. */
    private static function tests(string $repo): array
    {
        $dir = $repo.'/tests';
        if (! is_dir($dir)) {
            return [];
        }
        $items = [];
        foreach (File::allFiles($dir) as $f) {
            if (! str_ends_with($f->getFilename(), 'Test.php')) {
                continue;
            }
            $head = (string) file_get_contents($f->getPathname(), false, null, 0, 4000);
            $doc = preg_match('/\/\*\*\s*\n\s*\*\s*(.+?)\n/u', $head, $m) ? trim($m[1]) : '';
            $rel = str_replace('\\', '/', $f->getRelativePathname());
            $items[] = ['id' => $rel, 'no' => explode('/', $rel)[0], 'title' => mb_substr($f->getFilenameWithoutExtension().($doc !== '' ? ' — '.$doc : ''), 0, 200), 'meta' => date('Y-m-d', $f->getMTime()), 'path' => 'tests/'.$rel, 'body' => '', 'mtime' => $f->getMTime()];
        }
        usort($items, fn ($a, $b) => $b['mtime'] <=> $a['mtime']);

        return array_map(fn ($i) => array_diff_key($i, ['mtime' => 1]), $items);
    }

    private static function cap(string $body): string
    {
        $body = trim($body);

        return mb_strlen($body) > self::BODY_MAX ? mb_substr($body, 0, self::BODY_MAX)."\n\n… (여기서 잘림 — 전문은 레포 파일)" : $body;
    }

    private static function head(string $repo): ?string
    {
        $h = @file_get_contents($repo.'/.git/HEAD');
        if (! $h) {
            return null;
        }
        $h = trim($h);
        if (str_starts_with($h, 'ref: ')) {
            $ref = @file_get_contents($repo.'/.git/'.substr($h, 5));

            return $ref ? substr(trim($ref), 0, 8) : null;
        }

        return substr($h, 0, 8);
    }
}
