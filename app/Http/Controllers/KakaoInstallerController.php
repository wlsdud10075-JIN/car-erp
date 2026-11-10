<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\KakaoCollectorToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 📱 카톡자동화 §11 「이 PC 연결하기」 — ERP 가 사용자별 1회용 설치 .bat 을 만들어 내려 준다 (docs/design/kakao-automation.md §11-2).
 *
 * .bat 이 하는 일: ①%LOCALAPPDATA%\KakaoReader 만들기 ②떠 있는 옛 에이전트 종료 ③ERP 에서 KakaoAgent.exe 내려받기(+Unblock-File)
 * ④config.json(erp_url·token·local_secret) 쓰기 ⑤시작프로그램 바로가기 ⑥에이전트 기동 ⑦자기 삭제.
 * 토큰은 이 파일 안에 평문으로 한 번 들어간다 → 받을 때마다 새 토큰이고, 한 번도 안 쓰인(설치 안 한) 옛 토큰은 그 자리에서 폐기한다.
 * ASCII 만 쓴다 — 한국어 Windows 의 .bat 은 CP949 로 돌아 한글 echo 가 깨진다(chcp 65001 로 올려도 파일 저장 인코딩과 어긋나면 깨진다).
 */
class KakaoInstallerController extends Controller
{
    public const EXE_PUBLIC_PATH = 'downloads/KakaoAgent.exe';   // public/ 아래 정적 파일(git 밖 — 배포 스크립트·S3 로 둔다, §11-4)

    public function download(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->canUseKakaoAssistant(), 403, __('kakao.disabled'));

        // 받고 설치 안 한 .bat 의 토큰(한 번도 안 쓰임)은 쌓이지 않게 폐기 — 쓰던 PC 의 토큰은 그대로(다른 PC 를 연결하는 중일 수 있다)
        $user->kakaoCollectorTokens()->whereNull('revoked_at')->whereNull('last_used_at')->update(['revoked_at' => now()]);
        [$token, $plain] = KakaoCollectorToken::issue($user);   // 기기 이름은 에이전트 첫 호출(X-Kakao-Device)이 채운다

        AuditLog::create([
            'user_id' => $user->id, 'auditable_type' => KakaoCollectorToken::class, 'auditable_id' => $token->id,
            'action' => 'kakao_installer_downloaded', 'column_name' => 'kakao_collector_tokens',
            'old_value' => null, 'new_value' => 'token#'.$token->id, 'ip_address' => $request->ip(),
        ]);

        // 회사 주소 = 사용자가 실제로 접속한 출처(www·apex 별칭이 있으면 APP_URL 과 다를 수 있다 — 에이전트는 이 출처의 Origin 만 받는다)
        $bat = self::bat($request->getSchemeAndHttpHost(), $plain, (string) $token->local_secret);

        return response($bat, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="KakaoAgent-setup.bat"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * 이미 떠 있는 에이전트를 지금 계정으로 다시 묶기(.bat 없이) — jin 11-10 «사용자 전환한다고 계속 재설치해야 되면 너무 불편».
     * 페이지 JS 가 이 토큰·비밀값을 127.0.0.1 에이전트의 /rebind 로 보낸다. 발급 규칙은 .bat 과 같다(안 쓰인 토큰 폐기·기기 이름은 에이전트가 채움).
     */
    public function rebind(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->canUseKakaoAssistant(), 403, __('kakao.disabled'));
        $user->kakaoCollectorTokens()->whereNull('revoked_at')->whereNull('last_used_at')->update(['revoked_at' => now()]);
        [$token, $plain] = KakaoCollectorToken::issue($user);
        AuditLog::create([
            'user_id' => $user->id, 'auditable_type' => KakaoCollectorToken::class, 'auditable_id' => $token->id,
            'action' => 'kakao_agent_rebound', 'column_name' => 'kakao_collector_tokens',
            'old_value' => null, 'new_value' => 'token#'.$token->id, 'ip_address' => $request->ip(),
        ]);

        return response()->json(['token' => $plain, 'local_secret' => (string) $token->local_secret, 'erp_url' => $request->getSchemeAndHttpHost()], 200, [], JSON_UNESCAPED_SLASHES)
            ->header('Cache-Control', 'no-store');
    }

    /** .bat 본문 — CRLF, ASCII. 값은 전부 영숫자·URL 이라 따옴표 처리가 필요 없다. */
    public static function bat(string $erpUrl, string $token, string $secret): string
    {
        $exeUrl = $erpUrl.'/'.self::EXE_PUBLIC_PATH;
        $lines = [
            '@echo off',
            'setlocal',
            'title KakaoAgent setup',
            'set "DIR=%LOCALAPPDATA%\\KakaoReader"',
            'set "EXE=%DIR%\\KakaoAgent.exe"',
            'set "ERP='.$erpUrl.'"',
            'echo [1/5] Preparing %DIR%',
            'if not exist "%DIR%" mkdir "%DIR%"',
            // 떠 있는 옛 에이전트 종료 — 11-10 실측: jin PC 에서 taskkill 한 줄로는 안 죽어 새 에이전트가 「포트 사용 중」으로 바로 꺼졌다(원인 미확정). 둘 다 쓰고 1초 기다린다
            'taskkill /F /IM KakaoAgent.exe >nul 2>&1',
            'powershell -NoProfile -ExecutionPolicy Bypass -Command "Stop-Process -Name KakaoAgent -Force -ErrorAction SilentlyContinue" >nul 2>&1',
            'timeout /t 1 >nul',
            'echo [2/5] Downloading agent from %ERP%',
            'curl.exe -fsSL -o "%EXE%" "'.$exeUrl.'"',
            'if errorlevel 1 (',
            '  echo.',
            '  echo Download failed. Check the network and try again from ERP.',
            '  pause',
            '  exit /b 1',
            ')',
            'powershell -NoProfile -ExecutionPolicy Bypass -Command "Unblock-File -LiteralPath \'%EXE%\'" >nul 2>&1',
            'echo [3/5] Writing settings',
            '> "%DIR%\\config.json" echo {"erp_url": "%ERP%", "token": "'.$token.'", "local_secret": "'.$secret.'", "first_read_days": 7, "pageup_batch": 20, "max_pageups": 400, "click_fraction": [0.5, 0.35]}',
            'echo [4/5] Registering autostart',
            'powershell -NoProfile -ExecutionPolicy Bypass -Command "$s=(New-Object -ComObject WScript.Shell).CreateShortcut([Environment]::GetFolderPath(\'Startup\')+\'\\KakaoAgent.lnk\'); $s.TargetPath=\'%EXE%\'; $s.WorkingDirectory=\'%DIR%\'; $s.Description=\'ERP KakaoTalk agent\'; $s.Save()" >nul 2>&1',
            'echo [5/5] Starting agent',
            'start "" "%EXE%"',
            'echo.',
            'echo Done. Go back to ERP and refresh the page. This window closes in 5 seconds.',
            'timeout /t 5 >nul',
            '(goto) 2>nul & del "%~f0"',
        ];

        return implode("\r\n", $lines)."\r\n";
    }
}
