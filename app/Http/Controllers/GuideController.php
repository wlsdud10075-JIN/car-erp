<?php

namespace App\Http\Controllers;

use App\Services\Guides\GuideCatalog;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * 업무 가이드(/guides) — 로그인한 전 직원이 본다 (jin 2026-11-13, 목업 docs/design/mock/2026-11-13/guides-page.html).
 * 본문·그림은 public 이 아니라 resources/guides 에 있고 여기서만 내보낸다(검색엔진 차단 헤더).
 */
class GuideController extends Controller
{
    public function index(): View
    {
        return view('guides.index', [
            // 묶음 순서는 config, 이름·화면 문구는 언어 파일(영어 화면이면 영어로).
            'payload' => [
                'scenes' => GuideCatalog::all(),
                'groups' => array_map(fn ($k) => __('guides.groups.'.$k), array_combine(array_keys(config('guides.groups')), array_keys(config('guides.groups')))),
                'base' => url('guides'),
                't' => __('guides'),
            ],
            'boardUrl' => GuideCatalog::boardUrl(),
        ]);
    }

    public function show(string $slug): View
    {
        $scene = GuideCatalog::find($slug);
        abort_if($scene === null, 404);

        return view('guides.show', [
            'scene' => $scene,
            // 본문은 그림을 파일명만으로 적는다(src="01.png") — 여기서 이 씬의 그림 주소로 바꾼다.
            //   ?v=수정시각 — 그림은 1시간 캐시라, 같은 이름으로 그림을 갈면 브라우저가 옛 그림을 새 글 옆에 보여 준다
            //   (2026-11-13 첫 씬에서 실제로 글과 그림이 어긋나 보였다).
            'html' => preg_replace_callback(
                '/src="([A-Za-z0-9_-]+\.(?:png|jpg|webp))"/',
                fn ($m) => 'src="'.url('guides/'.$slug).'/'.$m[1].'?v='.(@filemtime((string) GuideCatalog::asset($slug, $m[1])) ?: 0).'"',
                GuideCatalog::html($slug),
            ),
            'near' => GuideCatalog::neighbours($slug),
        ]);
    }

    public function asset(string $slug, string $file): BinaryFileResponse
    {
        $path = GuideCatalog::asset($slug, $file);
        abort_if($path === null || GuideCatalog::find($slug) === null, 404);

        return response()->file($path, ['X-Robots-Tag' => 'noindex', 'Cache-Control' => 'private, max-age=3600']);
    }
}
