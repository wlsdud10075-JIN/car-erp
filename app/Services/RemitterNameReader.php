<?php

namespace App\Services;

use App\Services\Assistant\OllamaClient;
use Illuminate\Support\Facades\Cache;
use Smalot\PdfParser\Parser;
use Smalot\PdfParser\XObject\Image;

/**
 * 🏦 신한 「타발해외송금 내도통지」 전신문에서 **송금인 이름 하나만** 읽는다 (jin 2026-11-24 — 금액은 안 읽는다).
 *
 * 전신문 = 은행 팩스(TIF, Group3) 또는 그걸 PDF 로 바꾼 것/메일 인쇄 스캔 — 셋 다 **그림**이다(글자층 0).
 *   - 글자가 살아 있는 PDF 면 글자에서 바로 읽는다(GPU 안 씀).
 *   - 그림이면 회사 GPU PC 의 board OCR 모델(qwen2.5vl)에 쪽 그림을 보낸다. TIF 는 서버(GD)가 못 풀어서
 *     **브라우저가 쪽 PNG 로 바꿔** 보낸다. PDF 안의 JPEG 는 서버가 꺼낸다.
 *
 * 🚨 실측(11-24, 샘플 4건) — 모델에게 「Debtor 이름」을 물으면 Debtor 가 없는 쪽(1쪽)에서 BIC·은행 이름·우리 회사
 *    이름을 답했다. 그래서 ①1쪽 요약표의 「송금의뢰인」(가린 앞글자, 예 `XHEVAHIR NE**`)을 따로 읽고
 *    ②Debtor 후보는 그 앞글자와 맞는 것만 받는다. 앞글자를 못 읽었으면 「확인 필요」로 돌려준다.
 *    결과는 언제나 **사람이 모달에서 보고 고칠 수 있는 제안**이다.
 */
class RemitterNameReader
{
    public const PROMPT_NAME = 'This image is one page of a Korean bank SWIFT incoming remittance notice. '
        .'If the page contains a section header "< Debtor >", return the value of the "Name" line directly under it, exactly as printed. '
        .'Never return the Creditor name or the Korean customer name. '
        .'Reply with JSON only: {"debtor_name": "..."} or {"debtor_name": null} if there is no "< Debtor >" section on this page.';

    public const PROMPT_PREFIX = 'This image is the first page of a Korean bank incoming remittance notice. It has a summary table with columns '
        .'구분 | REF-NO | VALUE DATE | 통화 | 금액 | 송금의뢰인. Return the 송금의뢰인 (remitter) cell exactly as printed, including any * characters. '
        .'Reply with JSON only: {"remitter": "..."} or {"remitter": null} if there is no such table.';

    /** 우리 회사 이름 — 송금인 후보에서 뺀다(Creditor 를 잘못 집은 것). */
    private const OWN_NAMES = ['SSANCAR', 'HEYMAN', 'HEY MAN', 'KARABA'];

    public function __construct(private ?OllamaClient $client = null) {}

    /**
     * PDF 바이트 → 글자층 + 쪽마다 그림(JPEG/PNG 바이너리) — 그림이 없거나 못 읽으면 빈 배열.
     *
     * @return array{text: string, images: list<string>}
     */
    public static function pdfPages(string $bytes, int $maxPages): array
    {
        try {
            $pdf = (new Parser)->parseContent($bytes);
        } catch (\Throwable) {
            return ['text' => '', 'images' => []];
        }
        $text = '';
        $images = [];
        foreach (array_slice($pdf->getPages(), 0, $maxPages) as $page) {
            try {
                $text .= $page->getText()."\n";
            } catch (\Throwable) {
            }
            $best = null;
            foreach ($page->getXObjects() as $x) {
                if ($x instanceof Image) {
                    $c = (string) $x->getContent();
                    if ($best === null || strlen($c) > strlen($best)) {
                        $best = $c;   // 쪽의 가장 큰 그림 = 스캔 본문
                    }
                }
            }
            $images[] = $best ?? '';
        }

        return ['text' => $text, 'images' => $images];
    }

    /** 글자가 살아 있는 전신문 — `< Debtor >` 다음 `Name :` 줄. */
    public static function fromText(string $text): ?string
    {
        if (preg_match('/<\s*Debtor\s*>\s*Name\s*[:.]\s*([^\r\n]+)/iu', $text, $m)) {
            $name = self::clean($m[1]);

            return $name !== '' ? $name : null;
        }

        return null;
    }

    /**
     * 그림 바이너리 → 긴 변 max_px PNG base64 (GD 로 다시 그려 보낸다 — 브라우저가 준 그림도 그대로 믿지 않는다).
     */
    public static function normalizeImage(string $binary): ?string
    {
        if ($binary === '' || strlen($binary) > 25 * 1024 * 1024) {
            return null;
        }
        $src = @imagecreatefromstring($binary);
        if (! $src) {
            return null;
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $max = (int) config('assistant.remitter_ocr.max_px', 1100);
        $scale = min(1, $max / max($w, $h));
        $dst = imagecreatetruecolor(max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, imagesx($dst), imagesy($dst), $w, $h);
        imagefilter($dst, IMG_FILTER_GRAYSCALE);
        ob_start();
        imagepng($dst);
        $png = (string) ob_get_clean();
        imagedestroy($src);
        imagedestroy($dst);

        return base64_encode($png);
    }

    /**
     * 쪽 그림(PNG base64, 이미 normalizeImage 를 거친 것)에서 송금인 이름을 찾는다.
     *
     * @param  list<string|null>  $pages  1쪽부터 순서대로
     * @return array{name: ?string, prefix: ?string, status: string}
     *                                                               status = ok(앞글자와 맞음) · unverified(앞글자를 못 읽음) · mismatch(맞는 후보 없음) · none(못 찾음) · offline(GPU PC 불통) · busy(다른 사람이 읽는 중)
     */
    public function read(array $pages): array
    {
        $lock = Cache::lock('remitter-ocr', 90);
        if (! $lock->get()) {
            return ['name' => null, 'prefix' => null, 'status' => 'busy'];
        }
        try {
            return $this->readUnlocked(array_values($pages));
        } finally {
            $lock->release();
        }
    }

    private function readUnlocked(array $pages): array
    {
        $client = $this->client
            ?? (app()->bound('remitter-ocr.client') ? app('remitter-ocr.client') : null)   // 테스트가 가짜를 꽂는 자리
            ?? new OllamaClient((string) config('assistant.ollama'), (int) config('assistant.remitter_ocr.timeout', 20));
        $model = (string) config('assistant.remitter_ocr.model');
        $ctx = (int) config('assistant.remitter_ocr.num_ctx');
        $started = microtime(true);

        $prefix = null;
        $candidates = [];
        try {
            if (! empty($pages[0])) {
                $prefix = self::clean((string) (self::json($client->vision($model, self::PROMPT_PREFIX, $pages[0], $ctx))['remitter'] ?? '')) ?: null;
            }
            // 이름이 있는 쪽은 실측 3쪽 — 3쪽부터, 그다음 4·2·5·6쪽. 1쪽은 Debtor 가 없다(한 장짜리 그림만 예외).
            $order = array_values(array_filter([2, 3, 1, 4, 5, 0], fn ($i) => $i < count($pages) && ($i > 0 || count($pages) === 1)));   // 한 장짜리 그림이면 그 쪽
            foreach ($order as $i) {
                if (empty($pages[$i]) || microtime(true) - $started > 24) {   // 운영 PHP 30초 안에서 끝낸다
                    continue;
                }
                $name = self::clean((string) (self::json($client->vision($model, self::PROMPT_NAME, $pages[$i], $ctx))['debtor_name'] ?? ''));
                if (! self::plausible($name)) {
                    continue;
                }
                if ($prefix !== null && self::prefixMatches($prefix, $name)) {
                    return ['name' => $name, 'prefix' => $prefix, 'status' => 'ok'];
                }
                $candidates[] = $name;
                if ($prefix === null) {
                    break;   // 대조할 앞글자가 없다 — 첫 후보를 「확인 필요」로
                }
            }
        } catch (\Throwable $e) {
            report($e);

            return ['name' => $candidates[0] ?? null, 'prefix' => $prefix, 'status' => 'offline'];
        }

        if ($candidates === []) {
            return ['name' => null, 'prefix' => $prefix, 'status' => 'none'];
        }

        return ['name' => $candidates[0], 'prefix' => $prefix, 'status' => $prefix === null ? 'unverified' : 'mismatch'];
    }

    /** 「XHEVAHIR NE**」 ↔ 「XHEVAHIR NEZIRI」 — 글자·숫자만 비교, 앞글자 길이 중 한 글자 오독까지 허용(I↔| 등). */
    public static function prefixMatches(string $masked, string $name): bool
    {
        $p = self::letters(preg_replace('/\*+.*$/u', '', $masked) ?? '');
        $n = self::letters($name);
        if (mb_strlen($p) < 3) {
            return false;
        }
        $head = mb_substr($n, 0, mb_strlen($p));

        return mb_strlen($head) === mb_strlen($p) && levenshtein($p, $head) <= 1;
    }

    private static function letters(string $s): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper(strtr($s, ['|' => 'I', '!' => 'I']))) ?? '';
    }

    private static function plausible(string $name): bool
    {
        if (mb_strlen($name) < 3 || preg_match('/[\x{AC00}-\x{D7A3}]|\(주\)/u', $name)) {
            return false;   // 한글·(주) = 우리 쪽 고객명
        }
        if (preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $name)) {
            return false;   // BIC (CHASUS33XXX)
        }
        $up = strtoupper($name);
        foreach (self::OWN_NAMES as $own) {
            if (str_contains($up, $own)) {
                return false;
            }
        }

        return true;
    }

    private static function clean(string $s): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');

        return trim($s, " \t:.]|");
    }

    private static function json(string $raw): array
    {
        if (preg_match('/\{.*\}/su', $raw, $m)) {
            $d = json_decode($m[0], true);

            return is_array($d) ? $d : [];
        }

        return [];
    }
}
