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
 * 🧭 이름은 **3쪽에서만** 읽는다(jin 11-24 «1쪽 대조 빼고 3쪽만»). 3쪽에 없을 때만 4·2·5·6쪽, 1쪽은 한 장짜리 그림일 때만.
 *    1쪽 요약표의 가린 앞글자(`ABCDEF**`)와 맞춰 보던 대조는 뺐다 — 팩스 글씨라 모델이 그 칸을 `ABCDE^^`·옆 칸 REF-NO 로
 *    읽어, 이름은 맞는데 「실패」로 뜨는 일이 났다(11-24 실측). Debtor 가 없는 쪽에서 나오는 BIC·우리 회사·한글 이름은
 *    `plausible()` 이 거른다. 결과는 언제나 **사람이 모달에서 보고 고칠 수 있는 제안**이다.
 */
class RemitterNameReader
{
    public const PROMPT_NAME = 'This image is one page of a Korean bank SWIFT incoming remittance notice. '
        .'If the page contains a section header "< Debtor >", return the value of the "Name" line directly under it, exactly as printed. '
        .'Never return the Creditor name or the Korean customer name. '
        .'Reply with JSON only: {"debtor_name": "..."} or {"debtor_name": null} if there is no "< Debtor >" section on this page.';

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
     * @param  list<string|null>  $pages  1쪽부터 순서대로(보내지 않은 쪽은 null)
     * @return array{name: ?string, status: string}
     *                                              status = ok(읽음) · none(못 찾음) · offline(GPU PC 불통) · busy(다른 사람이 읽는 중)
     */
    public function read(array $pages): array
    {
        $lock = Cache::lock('remitter-ocr', 90);
        if (! $lock->get()) {
            return ['name' => null, 'status' => 'busy'];
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

        // 3쪽부터, 없으면 4·2·5·6쪽. 1쪽은 Debtor 가 없어서(은행 코드를 답한다) 한 장짜리 그림일 때만.
        $order = count($pages) === 1 ? [0] : array_values(array_filter([2, 3, 1, 4, 5], fn ($i) => $i < count($pages)));
        try {
            foreach ($order as $i) {
                if (empty($pages[$i]) || microtime(true) - $started > 24) {   // 운영 PHP 30초 안에서 끝낸다
                    continue;
                }
                $name = self::clean((string) (self::json($client->vision($model, self::PROMPT_NAME, $pages[$i], $ctx))['debtor_name'] ?? ''));
                if (self::plausible($name)) {
                    return ['name' => $name, 'status' => 'ok'];
                }
            }
        } catch (\Throwable $e) {
            report($e);

            return ['name' => null, 'status' => 'offline'];
        }

        return ['name' => null, 'status' => 'none'];
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
