<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * i18n Phase 0 — 요청마다 사용자 언어로 setLocale.
 * - 비로그인 / locale 미지정 → 'ko'
 * - 사용자가 'en' 인데 super가 기능설정에서 영어를 끄면 → 'ko' 강제
 *   (super가 끄는 즉시 전사 한국어로 복귀)
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->user()?->locale ?: 'ko';

        if ($locale === 'en' && ! Setting::get('locale_en_enabled', false)) {
            $locale = 'ko';
        }

        if (! in_array($locale, User::LOCALES, true)) {
            $locale = 'ko';
        }

        app()->setLocale($locale);

        return $next($request);
    }
}
