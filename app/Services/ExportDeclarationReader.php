<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser;

/**
 * 📄 수출신고필증 PDF 읽기 (jin 2026-11-24 «신고번호는 자동기입, 면장금액은 매칭만»).
 *
 * 관세법인이 전자로 발급한 필증은 글자가 들어 있는 PDF 라 OCR·GPU 없이 그대로 읽힌다.
 * 실측(ssancarerp 최근 30장, 11-24): 신고번호 30/30 · 차별 금액 30/30 찾음 · 26장 ERP 면장금액과 일치
 *   (어긋난 4장 = 그날 면장 재발행 중이던 차). 한 신고서에 여러 대가 실리는 경우가 절반이다.
 *
 * 필증 안의 모양(smalot 추출 기준):
 *   `44475-26-110177X`                                      ← 신고번호, 문서마다 한 종류
 *   `30모델ㆍ규격 … 33단가(EUR) 34금액(EUR)`                    ← 금액 통화
 *   `HYUNDAI I30 2012 KMHD351UBCU073590 * SHIPPING COST …`  ← 차대번호가 든 줄
 *   `1(UN)  4,780  4,780`                                    ← 그 다음 몇 줄 안: 수량 · 단가 · 금액
 *
 * 🔑 금액은 **고치지 않는다** — 면장금액은 판매가 + 운임비 + 기타를 미러한 값이라 비교만 한다(jin).
 * 🔑 차대번호가 필증에 없으면 「다른 차의 필증」으로 본다 — 신고번호도 채우지 않는다(11-03 일괄 업로드 오첨부 4대).
 * 🔑 읽지 못하면 조용히 null — 업로드 자체를 막지 않는다(사람이 직접 넣던 종전과 같다).
 */
class ExportDeclarationReader
{
    /** 이보다 큰 파일은 읽지 않는다 — 필증은 수백 KB 다(실측 중앙값 274KB). 큰 파일은 메모리만 쓴다. */
    public const MAX_BYTES = 20 * 1024 * 1024;

    private const NUMBER = '/\b\d{5}-\d{2}-\d{6,7}[A-Z]?\b/';

    /**
     * 업로드 파일을 읽는다. PDF 가 아니거나 못 읽으면 null.
     *
     * @return array{number:?string, numbers:list<string>, currency:?string, lines:list<string>}|null
     */
    public static function fromUpload(?UploadedFile $file): ?array
    {
        if (! $file || strtolower((string) $file->getClientOriginalExtension()) !== 'pdf' || $file->getSize() > self::MAX_BYTES) {
            return null;
        }
        try {
            $stream = method_exists($file, 'readStream') ? $file->readStream() : fopen($file->getRealPath(), 'rb');
            $bytes = is_resource($stream) ? stream_get_contents($stream) : false;
            if (is_resource($stream)) {
                fclose($stream);
            }

            return $bytes === false ? null : self::fromBytes($bytes);
        } catch (\Throwable $e) {
            Log::info('[export-declaration] 읽기 실패', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /** @return array{number:?string, numbers:list<string>, currency:?string, lines:list<string>}|null */
    public static function fromBytes(string $bytes): ?array
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            return null;
        }
        try {
            $text = (new Parser)->parseContent($bytes)->getText();
        } catch (\Throwable $e) {
            Log::info('[export-declaration] PDF 파싱 실패', ['error' => $e->getMessage()]);

            return null;
        }

        return self::fromText($text);
    }

    /** @return array{number:?string, numbers:list<string>, currency:?string, lines:list<string>}|null */
    public static function fromText(string $text): ?array
    {
        preg_match_all(self::NUMBER, $text, $m);
        $numbers = array_values(array_unique($m[0]));
        if ($numbers === []) {
            return null;   // 필증이 아니거나 그림 PDF — 아무것도 하지 않는다
        }
        $currency = preg_match('/34\s*금액\s*\(\s*([A-Z]{3})\s*\)/u', $text, $c) ? $c[1] : null;
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $text)), fn ($l) => $l !== ''));

        return [
            'number' => count($numbers) === 1 ? $numbers[0] : null,   // 두 종류 이상이면 고르지 않는다
            'numbers' => $numbers,
            'currency' => $currency,
            'lines' => $lines,
        ];
    }

    /**
     * 한 차 기준으로 본다 — 그 차의 차대번호 줄을 찾고 바로 다음 몇 줄에서 「수량 단가 금액」을 읽는다.
     *
     * @return array{vin_found:bool, amount:?float, qty:?int}
     */
    public static function itemFor(array $read, ?string $vin): array
    {
        $vin = strtoupper(preg_replace('/\s+/', '', (string) $vin));
        if (strlen($vin) < 11) {
            return ['vin_found' => false, 'amount' => null, 'qty' => null];
        }
        $lines = $read['lines'];
        foreach ($lines as $i => $line) {
            if (! str_contains(strtoupper(preg_replace('/\s+/', '', $line)), $vin)) {
                continue;
            }
            foreach (array_slice($lines, $i + 1, 4) as $next) {
                if (preg_match('/^(\d+)\s*\(\s*[A-Z]{1,3}\s*\)\s+([\d,]+(?:\.\d+)?)\s+([\d,]+(?:\.\d+)?)/u', $next, $am)) {
                    $qty = (int) $am[1];

                    // 한 줄에 여러 대(수량 2 이상)면 그 차 몫을 가를 수 없다 — 금액은 비교하지 않는다.
                    return ['vin_found' => true, 'amount' => $qty === 1 ? (float) str_replace(',', '', $am[3]) : null, 'qty' => $qty];
                }
            }

            return ['vin_found' => true, 'amount' => null, 'qty' => null];
        }

        return ['vin_found' => false, 'amount' => null, 'qty' => null];
    }

    /**
     * 화면에 그릴 판정 한 덩어리.
     *
     * status: `match`(금액 일치) · `mismatch`(금액 다름) · `other_vehicle`(이 차 차대번호가 필증에 없음) ·
     *         `amount_unknown`(필증 금액을 이 차 몫으로 못 가름 · ERP 금액 없음 · 통화 다름 · 차대번호 없음)
     *
     * @return array{status:string, number:?string, pdf_amount:?float, pdf_currency:?string, erp_amount:?float, erp_currency:?string, reason:?string}
     */
    public static function check(array $read, ?string $vin, ?float $erpAmount, ?string $erpCurrency): array
    {
        $out = [
            'status' => 'amount_unknown', 'number' => $read['number'], 'pdf_amount' => null, 'pdf_currency' => $read['currency'],
            'erp_amount' => $erpAmount, 'erp_currency' => $erpCurrency, 'reason' => null,
        ];
        if (trim((string) $vin) === '') {
            $out['reason'] = 'no_vin';

            return $out;
        }
        $item = self::itemFor($read, $vin);
        if (! $item['vin_found']) {
            $out['status'] = 'other_vehicle';

            return $out;
        }
        $out['pdf_amount'] = $item['amount'];
        if ($item['amount'] === null) {
            $out['reason'] = ($item['qty'] ?? 0) > 1 ? 'shared_line' : 'no_amount';

            return $out;
        }
        if ($erpAmount === null || $erpAmount <= 0) {
            $out['reason'] = 'erp_empty';

            return $out;
        }
        if ($read['currency'] !== null && $erpCurrency !== null && $read['currency'] !== $erpCurrency) {
            $out['reason'] = 'currency';

            return $out;
        }
        $out['status'] = abs($item['amount'] - $erpAmount) < 0.005 ? 'match' : 'mismatch';

        return $out;
    }
}
