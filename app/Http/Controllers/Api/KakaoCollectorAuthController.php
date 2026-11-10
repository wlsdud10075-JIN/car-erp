<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KakaoCollectorToken;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * 수집기 첫 실행 로그인 — ERP 아이디·비밀번호 한 번으로 수집기 토큰을 받는다 (jin 11-10 «각 PC 에 토큰 발급된 걸 PowerShell 에 넣으라고?»).
 *   POST /api/kakao/login {email, password, device} → {token, name}
 * 비밀번호는 저장하지 않는다. 토큰은 기기마다 하나(해시 저장). 회사 토글이 꺼져 있으면 403. 분당 5회(무차별 대입 방지).
 */
class KakaoCollectorAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'max:120'],
            'password' => ['required', 'string', 'max:200'],
            'device' => ['nullable', 'string', 'max:80'],
        ]);
        if (! Setting::kakaoAssistantEnabled()) {
            return response()->json(['message' => '이 회사는 카톡자동화가 꺼져 있습니다(기능설정).'], 403);
        }
        if (! Auth::validate(['email' => $data['email'], 'password' => $data['password']])) {
            return response()->json(['message' => '아이디 또는 비밀번호가 맞지 않습니다.'], 401);
        }
        $user = User::where('email', $data['email'])->firstOrFail();
        // 같은 기기 이름의 옛 토큰은 폐기(재설치·재로그인 때 토큰이 쌓이지 않게)
        if (! empty($data['device'])) {
            $user->kakaoCollectorTokens()->where('device_name', $data['device'])->whereNull('revoked_at')->update(['revoked_at' => now()]);
        }
        [, $plain] = KakaoCollectorToken::issue($user, $data['device'] ?? null);

        return response()->json(['token' => $plain, 'name' => $user->name, 'message' => "{$user->name} 님으로 연결했습니다."]);
    }
}
