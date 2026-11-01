<?php

namespace App\Support;

use App\Models\Buyer;

/**
 * 🗺️ 바이어 「포털·지도 정보」 일괄 채움 규칙 (jin 2026-11-01 «미리보기 먼저») — 단일 출처.
 *
 * 기존 바이어 칸(주소·전화)에서 포털 칸(사무실 주소·도시·WhatsApp)을 **비어 있을 때만** 채운다.
 *  - office_address ← address 그대로.
 *  - city ← 주소에서 추출: 쉼표로 나눠 **마지막 토막(국가)을 뺀 바로 앞 토막**에서 우편번호(숫자·하이픈)를 뺀 것.
 *    토막이 2개면 앞 토막, 1개면 **모른다(null)** — 지어내지 않는다. 지도 좌표가 이 칸으로 찍히므로 결과는 사람이 본다.
 *  - whatsapp_phone ← contact_phone (옵션 `--whatsapp`). 비워 두면 ssancar 가 전화로 WhatsApp 링크를 만들므로 기본은 안 채운다.
 * 명령 = `buyers:backfill-portal-info`(dry-run 기본 · `--apply`). 운영 미리보기 프로브도 이 클래스를 쓴다.
 */
class BuyerPortalBackfill
{
    /** 주소 → 도시 추출. 못 가리면 null. */
    public static function cityFromAddress(?string $address): ?string
    {
        $address = trim((string) $address);
        if ($address === '') {
            return null;
        }
        $parts = array_values(array_filter(array_map('trim', explode(',', $address)), fn ($p) => $p !== ''));
        if (count($parts) < 2) {
            return null;   // 「Prishtina」 한 토막은 도시인지 거리인지 모른다
        }
        $cand = count($parts) >= 3 ? $parts[count($parts) - 2] : $parts[0];
        // 우편번호·번지 제거: 숫자 덩어리(하이픈 포함)와 남는 쉼표/공백
        $cand = preg_replace('/\b[0-9][0-9\-]*\b/u', '', $cand);
        $cand = trim(preg_replace('/\s{2,}/u', ' ', (string) $cand), " \t,.-");

        return $cand !== '' && mb_strlen($cand) <= 100 ? $cand : null;
    }

    /**
     * 한 바이어에 대해 「채울 값」만 돌려준다(빈 칸만). 아무것도 없으면 [].
     *
     * @return array<string, string>
     */
    public static function plan(Buyer $b, bool $withWhatsapp = false): array
    {
        $out = [];
        $addr = trim((string) $b->address);
        if (blank($b->office_address) && $addr !== '') {
            $out['office_address'] = mb_substr($addr, 0, 200);
        }
        if (blank($b->city) && ($city = self::cityFromAddress($addr)) !== null) {
            $out['city'] = $city;
        }
        $phone = trim((string) $b->contact_phone);
        if ($withWhatsapp && blank($b->whatsapp_phone) && $phone !== '') {
            $out['whatsapp_phone'] = mb_substr($phone, 0, 30);
        }

        return $out;
    }
}
