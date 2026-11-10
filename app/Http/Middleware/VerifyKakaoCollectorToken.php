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
        if (! $token->user->canUseKakaoAssistant()) {   // 회사 토글 OFF 또는 공개 범위 밖
            return response()->json(['message' => Setting::kakaoAssistantEnabled() ? '이 계정은 카톡자동화 공개 범위에 없습니다(기능설정).' : '이 회사는 카톡자동화가 꺼져 있습니다(기능설정).'], 403);
        }
        $touch = [];
        if (! $token->last_used_at || $token->last_used_at->lt(now()->subMinute())) {
            $touch['last_used_at'] = now();
        }
        // §11 — 설치 .bat 은 기기 이름을 모른다(ERP 가 만들어 준다) → 에이전트가 첫 호출에 PC 이름을 헤더로 보내면 그때 채운다
        $device = mb_substr(trim((string) $request->header('X-Kakao-Device', '')), 0, 80);
        if ($device !== '' && trim((string) $token->device_name) === '') {
            $touch['device_name'] = $device;
        }
        if ($touch) {
            $token->forceFill($touch)->saveQuietly();
        }
        $request->attributes->set('kakao_user', $token->user);
        $request->attributes->set('kakao_token', $token);

        return $next($request);
    }
}
