<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>{{ $title ?? config('app.name') }}</title>

{{-- 브라우저 탭 아이콘 — 회사별 (jin 2026-08-21).
     ⚠️ 선언이 없으면 브라우저는 `/favicon.ico` 관례에만 의존한다. 그 파일은 **첫 커밋부터
        0바이트**(스타터킷 잔재)였고, 그래서 지금까지 3사 모두 아이콘이 없었다.
        파일명이 고정이라 관례에 의존하는 한 **회사별 분기 자체가 불가능**하다 — 그래서 선언한다.
     ⚠️ `?v=` 는 캐시 무효화용이다. 파비콘 캐시는 `Ctrl+Shift+R` 로도 잘 안 지워진다
        (실측: heysellcar 는 빈 파일을 주는데도 크롬이 옛 아이콘을 계속 그리고 있었다).
        아이콘을 바꾸면 이 숫자를 올릴 것. --}}
@php
    // 🚨 이 partial 은 **로그인·에러 페이지를 포함한 모든 화면**에서 렌더된다.
    //    `Setting::get()` 은 캐시 없이 매번 DB 를 치므로, DB 장애나 마이그레이션 전 상태에서
    //    예외가 나면 «에러 페이지를 그리다가 또 에러» 가 된다. 아이콘 하나 때문에 화면을
    //    통째로 죽일 이유가 없으니 config(.env) 로 폴백한다.
    // ⚠️ Setting 을 우선 보는 이유 = 로컬에서 기능설정으로 회사를 바꿔가며 확인하기 때문
    //    (config 는 .env 라 그때 안 따라온다).
    try {
        $favicon = \App\Models\Setting::companyTemplateSet();
    } catch (\Throwable $e) {
        $favicon = config('company.template_set', 'system');
    }
    // 화이트리스트 — 값이 예상 밖이면 존재하지 않는 파일을 가리켜 404 가 된다.
    $favicon = in_array($favicon, ['system', 'heyman', 'karaba'], true) ? $favicon : 'system';
@endphp
<link rel="icon" href="{{ asset('favicon-'.$favicon.'.ico') }}?v=1" sizes="any" />

<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance
