<?php

namespace App\Services\Guides;

use Illuminate\Support\Carbon;

/**
 * 업무 가이드 목록 — config/guides.php(무엇이 있나) + resources/guides/{slug}/index.html(본문) 을 합쳐 읽는다.
 *
 * 🔑 상태는 파일이 정한다: 본문 없음 = 「준비 중」(열리지 않음) / 있음 = 「사용 중」, 갱신 뒤 며칠은 「새로 올라옴」.
 * 🔎 검색용 글자(body)는 본문에서 태그를 걷어낸 것 — 접힌 개발 블록(<details>)은 뺀다(jin 11-13 «사용자용 본문만»).
 */
class GuideCatalog
{
    /** @return list<array{no:int,slug:string,group:string,title:string,summary:string,who:list<string>,keywords:string,status:string,updated:?string,body:string,lap:?int}> */
    public static function all(): array
    {
        $lap = array_flip(config('guides.lap', []));
        $en = app()->getLocale() === 'en';
        $out = [];
        $no = 0;
        foreach (config('guides.scenes', []) as $slug => [$group, $title, $summary, $who, $keywords]) {
            $no++;
            $file = self::file($slug);
            $updated = $file ? Carbon::createFromTimestamp(filemtime($file)) : null;
            if ($en) {   // 🇬🇧 제목·요약은 영어로. 한국어 제목은 찾는 말에 남겨 한글로 쳐도 찾게 한다.
                $keywords .= ' '.$title;
                [$title, $summary] = config("guides.scenes_en.$slug", [$title, $summary]);
            }
            $out[] = [
                'no' => $no, 'slug' => $slug, 'group' => $group, 'title' => $title, 'summary' => $summary,
                'who' => $who, 'keywords' => $keywords,
                // 영어 화면인데 영어 본문(index.en.html)이 아직 없어 한국어 본문을 보여주는 씬.
                'koreanOnly' => $en && $file !== null && ! str_ends_with($file, 'index.en.html'),
                'status' => $file === null ? 'wait' : ($updated->diffInDays(now(), true) < (int) config('guides.new_days', 14) ? 'new' : 'ok'),
                'updated' => $updated?->format('m-d'),
                'body' => $file ? self::plainText((string) file_get_contents($file)) : '',
                'lap' => isset($lap[$slug]) ? $lap[$slug] + 1 : null,
            ];
        }

        return $out;
    }

    /** 볼 수 있는 씬이 하나라도 있나 — 사이드바가 매 화면마다 부르므로 파일 존재만 본다(본문을 읽지 않는다). */
    public static function hasAny(): bool
    {
        foreach (array_keys(config('guides.scenes', [])) as $slug) {
            if (self::file($slug) !== null) {
                return true;
            }
        }

        return false;
    }

    /** 볼 수 있는 씬 하나(없거나 준비 중이면 null). */
    public static function find(string $slug): ?array
    {
        foreach (self::all() as $s) {
            if ($s['slug'] === $slug) {
                return $s['status'] === 'wait' ? null : $s;
            }
        }

        return null;
    }

    public static function html(string $slug): string
    {
        return (string) file_get_contents(self::file($slug));
    }

    /** 이전·다음 — 한 바퀴 씬은 한 바퀴 순서로, 그 밖은 볼 수 있는 씬의 목록 순서로. */
    public static function neighbours(string $slug): array
    {
        $open = array_values(array_filter(self::all(), fn ($s) => $s['status'] !== 'wait'));
        $me = collect($open)->firstWhere('slug', $slug);
        if ($me && $me['lap'] !== null) {
            $open = collect($open)->whereNotNull('lap')->sortBy('lap')->values()->all();
        }
        $i = array_search($slug, array_column($open, 'slug'), true);

        return [
            'prev' => $i !== false && $i > 0 ? $open[$i - 1] : null,
            'next' => $i !== false && $i < count($open) - 1 ? $open[$i + 1] : null,
            // 한 바퀴 순번은 **볼 수 있는 씬끼리** 센다 — config 순번을 그대로 찍으면 일부만 올라온 동안 「한 바퀴 6 / 3」이 된다(board 세션 11-13 실측).
            'lapPos' => $me && $me['lap'] !== null && $i !== false ? $i + 1 : null,
            'lapTotal' => $me && $me['lap'] !== null ? count($open) : null,
        ];
    }

    /** 씬 폴더 안의 그림 파일 경로 — 폴더 밖으로 못 나간다(파일명 화이트리스트). */
    public static function asset(string $slug, string $name): ?string
    {
        if (! array_key_exists($slug, config('guides.scenes', [])) || ! preg_match('/^[A-Za-z0-9_-]+\.(png|jpg|webp)$/', $name)) {
            return null;
        }
        $path = config('guides.path').DIRECTORY_SEPARATOR.$slug.DIRECTORY_SEPARATOR.$name;

        return is_file($path) ? $path : null;
    }

    /** 같은 회사의 board 업무 가이드 주소 — board 가 없는 회사·아직 안 열린 동안은 null. */
    public static function boardUrl(): ?string
    {
        if (! config('guides.board_link_live')) {
            return null;
        }

        return config('guides.board_urls.'.config('company.template_set', 'system')) ?: null;
    }

    /** 본문 파일 — 영어 화면이면 index.en.html 을 먼저, 없으면 한국어 본문으로(빈 화면보다 낫다). */
    private static function file(string $slug): ?string
    {
        $dir = config('guides.path').DIRECTORY_SEPARATOR.$slug.DIRECTORY_SEPARATOR;
        $ko = $dir.'index.html';
        if (! is_file($ko)) {
            return null;   // 한국어 본문이 기준이다 — 영어판만 있는 씬은 없는 것으로 본다
        }

        return app()->getLocale() === 'en' && is_file($dir.'index.en.html') ? $dir.'index.en.html' : $ko;
    }

    private static function plainText(string $html): string
    {
        $html = preg_replace('#<details\b.*?</details>#si', ' ', $html) ?? $html;
        $html = preg_replace('#<(script|style)\b.*?</\1>#si', ' ', $html) ?? $html;
        $text = html_entity_decode(strip_tags(preg_replace('#<(/p|/h\d|/li|br\s*/?|/div)>#i', '$0 ', $html) ?? $html), ENT_QUOTES, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
