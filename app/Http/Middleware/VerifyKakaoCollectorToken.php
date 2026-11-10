<?php

namespace App\Http\Middleware;

use App\Models\KakaoCollectorToken;
use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * PC 수집기 → ERP 인증. 순서 = 토큰 조회 → 사용자 활성 → 회사 토글(403) → (라우트의 throttle).
 * 토큰 원문은 로그에 남기지 않는다. last_used_at 은 1분에 한 번만 쓴다(읽기마다 UPDATE 하지 않게).
 */
class VerifyKakaoCollectorToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->bearerToken();
        if (! $plain || ! str_starts_with($plain, 'kck_')) {
            return response()->json(['message' => '수집기 토큰이 없습니다. ERP 「내 카톡 할 일」에서 [수집기 연결] 로 발급하세요.'], 401);
        }
        $token = KakaoCollectorToken::findActive($plain);
        if (! $token || ! $token->user) {   // 사용자가 삭제되면 토큰 행도 cascade 로 사라진다
            return response()->json(['message' => '토큰이 폐기됐거나 사용자가 없습니다. 다시 발급하세요.'], 401);
        }
        if (! Setting::kakaoAssistantEnabled()) {
            return response()->json(['message' => '이 회사는 카톡자동화가 꺼져 있습니다(기능설정).'], 403);
        }
        if (! $token->last_used_at || $token->last_used_at->lt(now()->subMinute())) {
            $token->forceFill(['last_used_at' => now()])->saveQuietly();
        }
        $request->attributes->set('kakao_user', $token->user);
        $request->attributes->set('kakao_token', $token);

        return $next($request);
    }
}
