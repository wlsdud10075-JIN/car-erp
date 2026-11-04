<?php

namespace App\Services\Payout;

use App\Http\Middleware\VerifyErpPeerHmac;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * 🔗 heymanerp 의 승인된 월정산(사람별 실지급)을 읽는다 — ssancarerp 「헤이맨 정산 가져오기」 (jin 2026-11-04).
 *
 * 설정 = `.env` HEYMAN_ERP_URL + ERP_PEER_HMAC_SECRET(heymanerp 와 같은 값). 둘 중 하나라도 비면 버튼이 안 보인다.
 * 서명은 받는 쪽 미들웨어와 같은 함수(VerifyErpPeerHmac::canonical).
 */
class HeymanPayoutClient
{
    public const PATH = '/api/internal/peer/payouts';

    public static function configured(): bool
    {
        return (string) config('services.erp_peer.heyman_url') !== '' && (string) config('services.erp_peer.hmac_secret') !== '';
    }

    /** @return array{month:string, batches:list<array>, people:list<array{salesman_id:int,name:string,type:string,payout:int,phone:?string}>} */
    public static function fetch(string $month): array
    {
        if (! self::configured()) {
            throw new \DomainException('헤이맨 연결 설정이 없습니다(HEYMAN_ERP_URL · ERP_PEER_HMAC_SECRET).');
        }
        $query = ['month' => $month];
        $ts = now()->timestamp;
        $sig = hash_hmac('sha256', VerifyErpPeerHmac::canonical('GET', self::PATH, $query, $ts), (string) config('services.erp_peer.hmac_secret'));

        try {
            $res = Http::timeout(15)->acceptJson()->withHeaders([
                'X-Timestamp' => (string) $ts,
                'X-Nonce' => (string) Str::uuid(),
                'X-Board-Signature' => 'sha256='.$sig,
            ])->get(rtrim((string) config('services.erp_peer.heyman_url'), '/').self::PATH, $query);
        } catch (\Throwable $e) {
            throw new \DomainException('헤이맨 서버에 연결하지 못했습니다: '.$e->getMessage());
        }
        if ($res->status() === 401) {
            throw new \DomainException('헤이맨 서버가 인증을 거부했습니다(두 서버의 ERP_PEER_HMAC_SECRET 이 같은지 확인).');
        }
        if (! $res->successful() || ! is_array($res->json('people'))) {
            throw new \DomainException('헤이맨 서버 응답이 올바르지 않습니다(HTTP '.$res->status().').');
        }

        return $res->json();
    }
}
