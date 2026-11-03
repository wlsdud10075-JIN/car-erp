<?php

namespace App\Support;

use App\Models\Buyer;
use App\Models\Country;

/**
 * 🗺️ 바이어 「포털·지도 정보」 일괄 채움 규칙 (jin 2026-11-01 «미리보기 먼저») — 단일 출처.
 *
 * 기존 바이어 칸(주소·전화)에서 포털 칸(사무실 주소·도시·WhatsApp)을 **비어 있을 때만** 채운다.
 *  - office_address ← address 그대로.
 *  - city ← 주소에서 **확신이 있을 때만** 추출(아래 `cityFromAddress`). 못 가리면 null — 지어내지 않는다. 지도 좌표가 이 칸으로 찍히므로
 *    틀린 도시보다 빈 도시가 낫다(실무자가 채운다). 실측(2026-11-01) ssancarerp 주소 355건은 형식이 제각각이라 보수적으로 간다.
 *  - whatsapp_phone ← contact_phone (옵션 `--whatsapp`). 비워 두면 ssancar 가 전화로 WhatsApp 링크를 만들므로 기본은 안 채운다.
 * 명령 = `buyers:backfill-portal-info`(dry-run 기본 · `--apply`). 운영 미리보기 프로브도 이 클래스를 쓴다.
 */
class BuyerPortalBackfill
{
    /** 국가명 별칭(영문 정식명은 Country::CODE_TO_EN 에서, 한글명은 DB 에서) — 주소 토막에서 국가를 떼어내는 데 쓴다. */
    private const COUNTRY_ALIASES = ['kosovë', 'kosova', 'kosovo', 'shqipëri', 'shqiperia', 'shqiptare', 'albania', 'deutschland', 'germany', 'nederland',
        'netherlands', 'italia', 'italy', 'españa', 'spain', 'türkiye', 'turkey', 'uae', 'u.a.e', 'usa', 'u.s.a', 'korea', 'south korea', 'lithuania',
        'georgia', 'moldova', 'kazakhstan', 'kyrgyzstan', 'azerbaijan', 'russia', 'france', 'norway', 'sweden', 'japan', 'mongolia', 'myanmar',
        'cote d\'ivoire', 'ivory coast', 'belgium', 'poland', 'ukraine', 'uzbekistan', 'tajikistan', 'armenia', 'greece', 'bulgaria', 'romania', 'serbia',
        'north macedonia', 'macedonia', 'montenegro', 'bosnia', 'croatia', 'slovenia', 'austria', 'switzerland', 'denmark', 'finland', 'estonia', 'latvia',
        // 2026-11-03 운영 dry-run 에서 국가가 덜 떨어져 「도시」로 남던 표기
        'kosove', 'kosoves', 'shqiptaree', 'alb', 'the netherlands', 'iceland', 'slovakia', 'slovak republic', 'czech republic', 'czechia', 'chile',
        'paraguay', 'nigeria', 'saudi arabia', 'costa rica', 'congo', 'drc'];

    /**
     * 「도시가 아닌 것」 낱말(통째 단어로만 비교) — 2026-11-03 운영 dry-run 에서 도시 칸으로 새던 것: 양식 글자(POSTAL CODE :·country:·post cod),
     *   기관·가게(OFFICE II POST·lokali Nova Bar·Autoriteti Portual), 지번(Zona Kadastrale), 노르웨이·아랍 거리(vei·st), 주(kaduna state), 세금 표기(MwSt).
     */
    private const NOT_CITY_WORDS = ['postal', 'post', 'code', 'cod', 'country', 'state', 'republic', 'republika', 'zona', 'kadastrale', 'office', 'bar',
        'lokali', 'ambasada', 'autoriteti', 'portual', 'vei', 'veien', 'gata', 'gatan', 'st', 'dist', 'mwst', 'industriale', 'village', 'fshati', 'neighborhood', 'city', 'siti', 'magistrale', 'magjistrale', 'magjistralja'];

    /** 거리·건물·층 같은 「도시가 아닌 것」 표식 — 후보에 섞이면 모른다고 답한다. */
    private const STREET_WORDS = ['rr.', 'rruga', 'rr ', 'str.', 'str ', 'strasse', 'straße', 'street', 'st.', 'plein', 'ulica', 'ul.', 'road', 'rd.', 'ave',
        'avenue', 'lagja', 'lagjja', 'lgj', 'tower', 'floor', 'building', 'bulevardi', 'blvd', 'house', 'ap.', 'apt', 'kv ', 'objekt', 'pasurie', 'nd.', 'h.',
        'poselok', 'r-n', 'district', 'township', 'tax', 'pas:', 'bp ', 'lot ', 'ilot', 'llot', 'g ', 'gatve', 'rue ', 'via ', 'nr.', 'nr ', 'no :', 'km'];

    /** @var array<int,string>|null */
    private static ?array $countries = null;

    /** @return array<int,string> 소문자 국가명 집합 */
    private static function countries(): array
    {
        if (self::$countries === null) {
            $names = array_merge(array_values(Country::CODE_TO_EN), self::COUNTRY_ALIASES);
            try {
                $names = array_merge($names, Country::query()->pluck('name')->all());
            } catch (\Throwable) {
                // DB 없이(단위 테스트·프로브) 불러도 동작
            }
            self::$countries = array_values(array_unique(array_map(fn ($n) => mb_strtolower(trim((string) $n)), $names)));
        }

        return self::$countries;
    }

    private static function isCountry(string $segment): bool
    {
        $s = mb_strtolower(trim($segment, " \t.,"));

        return $s !== '' && in_array($s, self::countries(), true);
    }

    /** 토막 끝(또는 앞)에 붙은 국가 단어를 떼어낸다 — 「Tauragė Lithuania」·「GEORGIA  POTI」. */
    private static function stripCountryWord(string $segment): string
    {
        $words = preg_split('/\s+/u', trim($segment)) ?: [];
        // 「Fushe Kosovo」(Fushë Kosovë)는 국가가 붙은 게 아니라 도시 이름이다
        if (preg_match('/^fush[eë]\s+kosov/iu', trim($segment))) {
            return trim($segment);
        }
        if (count($words) >= 2) {
            if (self::isCountry(end($words))) {
                array_pop($words);
            } elseif (self::isCountry($words[0])) {
                array_shift($words);
            }
        }

        return implode(' ', $words);
    }

    /**
     * 주소 → 도시. 확신 없으면 null.
     *  1) 쉼표·슬래시로 토막 → 국가 토막 제거 → 남은 **마지막** 토막(유럽식 「거리, 우편번호 도시」 순서)
     *  2) 우편번호(숫자·네덜란드식 `3088 GN`)·「p.n./nn/n.n.」(번지 없음) 제거
     *  3) 거리 표식·숫자·기호(@ : ;)·「도시가 아닌 낱말」(NOT_CITY_WORDS)·3단어 초과가 남으면 모른다(null)
     *  4) 국가도 우편번호도 없는 두 토막 이하 · 쉼표 없는 한 토막의 3단어 이상도 모른다(2026-11-03 운영 dry-run 오답 정리)
     */
    public static function cityFromAddress(?string $address): ?string
    {
        // 보이지 않는 공백(NBSP·전각 공백·제로폭)·줄바꿈을 보통 공백으로 — 실데이터에 섞여 있으면 국가 토막 판정이 조용히 빗나간다
        $address = trim((string) preg_replace('/[\x{00A0}\x{2000}-\x{200B}\x{202F}\x{3000}\r\n\t]+/u', ' ', (string) $address));
        if ($address === '') {
            return null;
        }
        $parts = array_values(array_filter(array_map('trim', preg_split('/[,\/]/u', $address) ?: []), fn ($p) => $p !== ''));
        $parts = array_values(array_filter($parts, fn ($p) => ! self::isCountry($p)));
        if ($parts === []) {
            return null;   // 「KOSOVO」 한 토막 = 국가뿐
        }
        $hadCountry = count($parts) < count(array_filter(array_map('trim', preg_split('/[,\/]/u', $address) ?: [])));
        $last = (string) end($parts);
        // 같은 토막 안에 국가 단어가 붙어 있어도 「국가 있음」이다(「Tauragė Lithuania」·「AZERBAIJAN BAKU」·「Vushtrri-Kosovo」)
        $hadCountry = $hadCountry || collect(preg_split('/[\s\-–]+/u', $last) ?: [])->contains(fn ($w) => self::isCountry($w));
        $cand = self::stripCountryWord($last);
        // 번지 없음 표식(「nn - 15000 Obiliq」·「p.n. Rahovec」) — 우편번호보다 먼저 떼야 앞 우편번호 규칙이 닿는다
        $cand = trim((string) preg_replace('/\b(p\.?\s?n\.?|n\.?\s?n\.?)\b/iu', '', $cand), " \t,.-–");
        // 우편번호 — 앞(「27580 Bremerhaven」·「3088 GN Rotterdam」) 또는 뒤(「Podujevë 11000」·「Tokyo 105-0023」)
        $beforePostal = $cand;
        $cand = preg_replace('/^\s*[0-9][0-9\-]{2,}(\s?[A-Z]{2})?\s+/u', '', $cand);
        $cand = preg_replace('/\s+[0-9][0-9\-]{2,}\s*$/u', '', (string) $cand);
        $hadPostal = $cand !== $beforePostal || preg_match('/(^|[\s,])[0-9]{4,6}([\s,]|$)/u', $address) === 1;
        $cand = trim(preg_replace('/\s*[-–]\s*/u', ' ', (string) $cand), " \t,.-");
        // 우편번호·붙임표를 뗀 뒤 다시 국가 단어 — 「Durres Albania 2000」·「Peje Kosovo 30000」·「Vushtrri-Kosovo」
        $cand = self::stripCountryWord((string) $cand);
        $cand = trim(preg_replace('/\s{2,}/u', ' ', $cand));
        if ($cand === '' || mb_strlen($cand) > 100) {
            return null;
        }
        $lower = mb_strtolower($cand);
        foreach (self::STREET_WORDS as $w) {
            if (str_contains(' '.$lower.' ', ' '.$w) || str_contains($lower, $w.' ') || str_ends_with($lower, trim($w))) {
                return null;
            }
        }
        if (preg_match('/[0-9@:;()"]/u', $cand) || count(preg_split('/\s+/u', $cand) ?: []) > 3 || self::isCountry($cand)) {
            return null;
        }
        foreach (preg_split('/[\s.]+/u', $lower) ?: [] as $word) {
            if (in_array($word, self::NOT_CITY_WORDS, true)) {
                return null;
            }
        }
        // 국가도 우편번호도 없는 짧은 주소(「Zona industriale Rr,dervish qollaku」)는 마지막 토막이 거리일 수 있다 — 세 토막 이상일 때만 믿는다
        if (! $hadCountry && ! $hadPostal && count($parts) < 3) {
            return null;
        }
        // 토막이 하나뿐이고 국가도 없었으면 거리인지 도시인지 모른다(「Prishtina Rruga B」는 위에서 걸렸고, 「cerrik elbasan」은 여기서 걸린다)
        if (! $hadCountry && count($parts) === 1) {
            return null;
        }
        // 쉼표 없는 한 토막은 거리 이름이 앞에 붙기 쉽다(「Marije Kraja Tirane Albania 1015」) — 두 단어까지만 믿는다
        if (count($parts) === 1 && count(preg_split('/\s+/u', $cand) ?: []) > 2) {
            return null;
        }

        return $cand;
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
