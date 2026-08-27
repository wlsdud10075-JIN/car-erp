<?php

namespace App\Providers;

use App\Services\Assistant\OllamaClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // 사내 업무 도우미 — Ollama 클라이언트를 config(.env) 기반으로 바인딩 (이식성).
        $this->app->bind(OllamaClient::class,
            fn () => OllamaClient::fromConfig());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // claudereview A — 문서 다운로드 Rate Limiting (정책 D 유지의 보상통제).
        // 사용자당 분당 제한 + (이론상) 미인증 시 IP fallback. 정상 사용(하루 수~수십 건)은 무영향.
        // 다중차량(showMulti)은 1요청=최대 30대라 분당 횟수를 더 낮게 잡아 대량열람 억제.
        RateLimiter::for('vehicle-docs', fn ($request) => Limit::perMinute(30)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('vehicle-docs-multi', fn ($request) => Limit::perMinute(10)->by($request->user()?->id ?: $request->ip()));

        // board 영업 포털 읽기 API — board 단일 IP 라 영업별(salesman_email) 키로 제한
        // (by(IP) 면 전 영업이 한 한도 공유). HMAC 으로 이미 인증되므로 상한은 넉넉히.
        RateLimiter::for('board-read', fn ($request) => Limit::perMinute(120)->by((string) $request->query('salesman_email', $request->ip())));
        // 포털은 호출자 파라미터가 없다 — board 의 `salesman_email` 키는 **호출자가 스스로 채우는 값**이라
        //   바꿔가며 부르면 한도가 무력해진다(v1.2 Q6 이 지적한 그 결함). IP 로 건다.
        //   6시간마다 전량 1회라 낮게 잡아도 충분하다.
        RateLimiter::for('portal-read', fn ($request) => Limit::perMinute(30)->by((string) $request->ip()));
        // 🚨 서류 통로는 **따로 센다**(2026-08-27). 같은 버킷에 두면 바이어가 화면을 몇 번 열 때
        //    30/분을 다 써서 **정기 전량 pull 이 429** 로 굶는다. 그 pull 이 미러의 전부이고,
        //    429 는 부분 응답이 아니라 **무응답**이라 `complete:false` 안전핀이 발동조차 못 한다.
        //    (SKILLS §15 NICE 게이트웨이와 같은 형태 — 무거운 호출자 하나가 나머지를 굶긴다.)
        //  · `files` 는 사진이 몇 장이든 **1회 호출**이라 실제 구동량은 「차량 페이지를 연 횟수」다.
        //  · `clearance-set` 은 927KB 양식 7시트를 매번 생성한다 — 상한이 워커 보호선이기도 하다.
        RateLimiter::for('portal-docs', fn ($request) => Limit::perMinute(60)->by((string) $request->ip()));

        // 차량 데이터 export — 2026-06-29 라운드테이블 조건(분3/일100). 파일 반출이라 억제.
        RateLimiter::for('data-export', fn ($request) => [
            Limit::perMinute(3)->by($request->user()?->id ?: $request->ip()),
            Limit::perDay(100)->by($request->user()?->id ?: $request->ip()),
        ]);

        // @krw($amount) — 대시보드 금액 억/만 축약 표시(+정확 금액 title 툴팁). 2026-06-11.
        Blade::directive('krw', fn ($expr) => "<?php echo \\App\\Support\\Money::krwTag($expr); ?>");
    }
}
