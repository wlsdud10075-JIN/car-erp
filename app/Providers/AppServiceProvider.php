<?php

namespace App\Providers;

use App\Listeners\NotifyScheduledTaskOutcome;
use App\Services\Assistant\OllamaClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Livewire\Features\SupportIslands\SupportIslands;

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

        // 정기 작업 실패 → 시스템관리자 텔레그램 (jin 2026-09-11, 3단계).
        // Laravel 이 이미 이벤트를 발행하는데 듣는 사람이 없었다 — 리스너 하나로 전 스케줄 잡을 덮는다.
        // 🚨 Finished 는 exit code 검사 「전」에 발행된다(리스너 docblock) — 성공으로 단정하지 말 것.
        Event::listen(ScheduledTaskFailed::class, [NotifyScheduledTaskOutcome::class, 'onFailed']);
        Event::listen(ScheduledTaskFinished::class, [NotifyScheduledTaskOutcome::class, 'onFinished']);

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

        $this->moveIslandPrecompilerAfterVolt();
    }

    /**
     * 🏝️ Livewire 「섬(@island)」 precompiler 를 Volt 의 템플릿 추출 「뒤」로 옮긴다 (2026-09-22).
     *
     * 두 패키지가 같은 Blade 훅(prepareStringsForCompilationUsing)에 등록되는데 Livewire 가 먼저 부팅돼
     * 섬 컴파일러가 **Volt 파일의 PHP 클래스 부분까지** Blade 지시어 정규식으로 훑는다.
     * 차량관리(741KB, 클래스 372KB)는 그 한 번이 **66초**였고 HTML 만이면 **0.04초**다(실측 2026-09-22).
     * Volt 추출이 먼저 돌면 섬 컴파일러는 HTML 만 본다. 순서만 바꾸고 동작은 그대로다.
     * 가드 = VehiclePanelIslandTest::test_the_island_precompiler_runs_after_volt_extracts_the_template.
     */
    protected function moveIslandPrecompilerAfterVolt(): void
    {
        $compiler = $this->app->make('blade.compiler');
        $prop = new \ReflectionProperty($compiler, 'prepareStringsForCompilationUsing');
        $islands = [];
        $others = [];
        foreach ($prop->getValue($compiler) as $callback) {
            $scope = $callback instanceof \Closure
                ? (new \ReflectionFunction($callback))->getClosureScopeClass()?->getName()
                : null;
            if ($scope === SupportIslands::class) {
                $islands[] = $callback;
            } else {
                $others[] = $callback;
            }
        }
        $prop->setValue($compiler, [...$others, ...$islands]);
    }
}
