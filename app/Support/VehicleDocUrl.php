<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * 차량 서류/사진 표시 URL 생성 (단일 출처).
 *
 * 운영 private S3(퍼블릭 차단)에서는 `->url()` 이 403 AccessDenied 라 표시가 깨진다.
 * → providesTemporaryUrls() 로 분기: S3 면 15분 임시 서명 URL(temporaryUrl),
 *   로컬 public 디스크면 일반 URL. 양쪽(로컬/운영) 모두 동작.
 *
 * 디스크는 vehicle_docs_disk (로컬 public / 운영 s3). 표시 URL 만드는 곳은 전부 여기 호출.
 */
class VehicleDocUrl
{
    public static function for(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        $disk = Storage::disk(config('filesystems.vehicle_docs_disk'));

        // claudefinalreview 3-4 — 임시 URL 유효시간 15→3분 단축 (감사 우회 창 축소).
        // 3분 = URL 생성~다운로드 시작까지 충분. 뷰에 렌더된 링크를 열어둔 채 3분 넘겨 클릭하면
        // 403 만료 → 새로고침 시 재발급되므로 정상 동작(만료 시 여기를 확인).
        return $disk->providesTemporaryUrls()
            ? $disk->temporaryUrl($path, now()->addMinutes(3))
            : $disk->url($path);
    }
}
