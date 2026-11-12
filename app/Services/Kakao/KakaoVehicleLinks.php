<?php

namespace App\Services\Kakao;

use App\Models\KakaoMessage;
use App\Models\KakaoMessageVehicle;
use App\Models\Vehicle;
use Illuminate\Support\Collection;

/**
 * 카톡 메시지 ↔ ERP 차량 연결 (jin 11-11, 설계 §14 ②). 표 = kakao_message_vehicles.
 *
 *  self    — 본문의 차량번호 · 17자 차대번호 · 끝 6자리를 **전부** 뽑아 한 줄씩(목록 글 5대 = 5줄). 저장 때 쓴다(분류와 무관 → 미등록 판정이 모델에 안 묶인다).
 *  inherit — 「저당해지 요청」「입금 부탁」처럼 차가 없는 **짧은 요청·질문**만, 같은 방 30분 안 앞 글 중
 *            「차량번호만 있는 짧은 글」(누구든) 또는 「같은 사람의 서식」(차량번호·VIN 이 있는 것)의 차를 이어받는다. 분류 뒤에 쓴다.
 *            실측(김다정 열린 요청 31건) — 자기 식별자 5 + 이어받기 9(맞음 6·의심 3). 🚫 차량번호 없는 매입 서식(「Korea auto impors 7 … 계약금 100」)은
 *            이어받기 원천이 아니다(앞 글의 다른 차를 잘못 집는다) — 그건 「서식인데 차량번호·VIN 없음」이라 위 조건에서 저절로 빠진다.
 *  vehicle_id 가 비면 「ERP 에 아직 없음」 — 화면(KakaoErpCards)이 매번 다시 찾는다(그 뒤 등록된 차는 「ERP 등록됨」).
 */
class KakaoVehicleLinks
{
    public const INHERIT_MINUTES = 30;

    /** 짧은 요청 = 이 글자 수 이하 + 서식 아님 + 자기 식별자 없음 */
    public const SHORT_REQUEST_CHARS = 40;

    /** 「차량번호만 있는 짧은 글」 = 식별자를 뺀 나머지가 이 글자 수 이하 */
    public const PLATE_ONLY_REST_CHARS = 10;

    /** 서식 = 「차량명 : … / 차량번호 : … / 차대번호 : …」 라벨로 시작하는 줄이 2개 이상(실례 ssancarerp 무사백 방 — Arteon·520d·Tiguan·E220d · 콜론 없는 「차량명   Peugeot 508」) */
    public static function isForm(string $body): bool
    {
        return preg_match_all('/^\s*(차량명|차명|차종|모델명|차량번호|차대번호|VIN)\s*[:：]?\s+\S|^\s*(차량명|차명|차종|모델명|차량번호|차대번호|VIN)\s*[:：]\S/mu', $body) >= 2;   // 콜론 있음·없음 둘 다(「차량명   Peugeot 508」)
    }

    /**
     * 본문 식별자 — KakaoIngestService::vehicleIdentifiers 와 같은 출처. 17자 VIN 이 있으면 그 끝 6자리(V:)는 겹치므로 뺀다.
     *
     * @return array<int, string> 'VIN:WVWZZZ3HZKE011066' · 'P:153주9157' · 'V:011066'
     */
    public static function identifiers(string $body): array
    {
        $ids = KakaoIngestService::vehicleIdentifiers($body);
        $vinTails = [];
        foreach ($ids as $id) {
            if (str_starts_with($id, 'VIN:')) {
                $vinTails[] = 'V:'.substr($id, -6);
            }
        }

        return array_values(array_diff($ids, $vinTails));
    }

    /** 「28마7558」처럼 차량번호(·VIN·끝자리)만 있는 짧은 글 */
    public static function isPlateOnly(string $body): bool
    {
        if (self::identifiers($body) === []) {
            return false;
        }
        $rest = preg_replace('/\d{2,3}\s?[가-힣]\s?\d{4}|\b[A-HJ-NPR-Z0-9]{17}\b|\d{6}/iu', '', $body);
        $rest = preg_replace('/[\s\p{P}\p{S}]+/u', '', (string) $rest);

        return mb_strlen((string) $rest) <= self::PLATE_ONLY_REST_CHARS;
    }

    public static function isShortRequest(KakaoMessage $m): bool
    {
        return in_array($m->type, ['요청', '질문'], true)
            && ! self::isForm($m->body)
            && self::identifiers($m->body) === []
            && mb_strlen(trim($m->body)) <= self::SHORT_REQUEST_CHARS;
    }

    /** 식별자 하나 → ERP 차량 id. VIN 전체 정확 일치(대소문자 무시) → 차량번호 → 끝 6자리(둘 이상이면 모름). */
    public static function resolve(string $ident): ?int
    {
        if (str_starts_with($ident, 'VIN:')) {
            $vin = strtoupper(substr($ident, 4));
            $id = Vehicle::whereRaw('UPPER(nice_reg_vin) = ?', [$vin])->value('id');

            return $id ? (int) $id : null;
        }
        if (str_starts_with($ident, 'P:')) {
            return KakaoIngestService::matchVehicle(substr($ident, 2), null);
        }
        if (str_starts_with($ident, 'V:')) {
            return KakaoIngestService::matchVehicle(null, substr($ident, 2));
        }

        return null;
    }

    /** 저장 때 — 본문 식별자를 self 줄로. 다시 불러도 같은 결과(ident 단위 갱신). */
    public static function linkSelf(Collection $messages): void
    {
        foreach ($messages as $m) {
            foreach (self::identifiers((string) $m->body) as $ident) {
                KakaoMessageVehicle::updateOrCreate(
                    ['message_id' => $m->id, 'ident' => mb_substr($ident, 0, 30)],
                    ['vehicle_id' => self::resolve($ident), 'via' => 'self', 'from_message_id' => null],
                );
            }
        }
    }

    /** 분류 뒤 — 짧은 요청·질문이 30분 안 앞 글의 차를 이어받는다. 이미 줄이 있는 글은 건드리지 않는다. */
    public static function linkInherited(Collection $messages): void
    {
        foreach ($messages as $m) {
            if (! $m->sent_at || ! self::isShortRequest($m) || KakaoMessageVehicle::where('message_id', $m->id)->exists()) {
                continue;
            }
            $source = self::inheritSource($m);
            if (! $source) {
                continue;
            }
            foreach (KakaoMessageVehicle::where('message_id', $source->id)->where('via', 'self')->get() as $l) {
                KakaoMessageVehicle::updateOrCreate(
                    ['message_id' => $m->id, 'ident' => $l->ident],
                    ['vehicle_id' => $l->vehicle_id, 'via' => 'inherit', 'from_message_id' => $source->id],
                );
            }
        }
    }

    /** 같은 방 30분 안 앞 글(지난 읽기 포함, DB) 중 가장 가까운 「차량번호만 있는 짧은 글」 또는 「같은 사람의 서식(차량번호·VIN 있음)」 */
    public static function inheritSource(KakaoMessage $m): ?KakaoMessage
    {
        $candidates = KakaoMessage::where('room_id', $m->room_id)
            ->where('id', '!=', $m->id)
            ->where('sent_at', '>=', $m->sent_at->copy()->subMinutes(self::INHERIT_MINUTES))
            ->where(fn ($q) => $q->where('sent_at', '<', $m->sent_at)->orWhere(fn ($q2) => $q2->where('sent_at', $m->sent_at)->where('id', '<', $m->id)))
            ->orderByDesc('sent_at')->orderByDesc('id')
            ->limit(40)
            ->get();
        foreach ($candidates as $c) {
            if (self::isPlateOnly($c->body)) {
                return $c;
            }
            if ($c->sender === $m->sender && self::isForm($c->body)) {
                $strong = array_filter(self::identifiers($c->body), fn ($i) => ! str_starts_with($i, 'V:'));   // 차량번호·VIN 이 있는 서식만
                if ($strong !== []) {
                    return $c;
                }
            }
        }

        return null;
    }
}
