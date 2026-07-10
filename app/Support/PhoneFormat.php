<?php

namespace App\Support;

/**
 * 한국 전화번호 하이픈 정규화 (서버측). resources/js/app.js 의 phoneFormat() 와 동일 규칙.
 *
 * 서버에서 확정 포맷으로 저장·표시하면 클라이언트 JS(실시간 미리보기)의 morph 타이밍과
 * 무관하게 항상 올바른 하이픈이 유지된다(jin 2026-07-10 — 저장 직후 3-3-5 잔상 방지).
 * 발송부(BizmAlimtalkService)는 숫자만 정규화하므로 하이픈 포함 저장값도 발송 호환.
 */
class PhoneFormat
{
    /** 숫자만 뽑아 한국 번호꼴로 하이픈 삽입. 빈 값이면 null. */
    public static function format(?string $raw): ?string
    {
        $d = substr(preg_replace('/\D/', '', (string) $raw), 0, 11);
        if ($d === '') {
            return null;
        }
        if (strlen($d) < 4) {
            return $d;
        }

        // 02 (서울)
        if (str_starts_with($d, '02')) {
            if (strlen($d) <= 5) {
                return preg_replace('/(\d{2})(\d+)/', '$1-$2', $d);
            }
            if (strlen($d) <= 9) {
                return preg_replace('/(\d{2})(\d{3})(\d+)/', '$1-$2-$3', $d);
            }

            return preg_replace('/(\d{2})(\d{4})(\d{4})/', '$1-$2-$3', $d);
        }

        // 010 / 011~019 / 0XX 지역 / 070 등 3자리 prefix
        if (strlen($d) <= 7) {
            return preg_replace('/(\d{3})(\d+)/', '$1-$2', $d);
        }
        if (strlen($d) <= 10) {
            return preg_replace('/(\d{3})(\d{3})(\d+)/', '$1-$2-$3', $d);
        }

        return preg_replace('/(\d{3})(\d{4})(\d{4})/', '$1-$2-$3', $d);
    }
}
