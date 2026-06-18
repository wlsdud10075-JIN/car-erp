<?php

namespace App\Providers;

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
        //
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

        // @krw($amount) — 대시보드 금액 억/만 축약 표시(+정확 금액 title 툴팁). 2026-06-11.
        Blade::directive('krw', fn ($expr) => "<?php echo \\App\\Support\\Money::krwTag($expr); ?>");
    }
}
