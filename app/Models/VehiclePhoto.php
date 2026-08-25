<?php

namespace App\Models;

use App\Support\VehicleDocUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 차량 등록 첨부 (사진·PDF·Excel·Word·HWP 등) — 1차량 N건. vehicle_docs_disk(로컬 public / 운영 s3)에 저장.
 * 테이블명·모델명은 history 차원에서 "photo"지만, 이제 일반 첨부도 보관한다.
 */
class VehiclePhoto extends Model
{
    public const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

    /**
     * 갤러리별 첨부 상한 — 화면(차량 편집 패널)·연동(purchase-sync)이 같은 값을 봐야 한다.
     * ⚠️ 여기가 단일 출처다. 다른 곳에 숫자를 직접 쓰면 「화면은 되는데 연동만 조용히 잘리는」 형태로 갈린다.
     * 기본정보 갤러리는 10건이었으나 실무상 부족해 선적과 같은 30건으로 통일(2026-08-25 jin).
     * ⚠️ 이 숫자는 「한 번에 고를 수 있는 개수」가 아니다 — PHP max_file_uploads(3사 20)·post_max_size(40M)가
     *    선택 1회의 상한이라 그 이상은 나눠서 올려야 한다(누적 버퍼라 합산된다).
     */
    public const MAX_BASIC = 30;

    public const MAX_SHIPPING = 30;

    /**
     * 한 번의 선택으로 올릴 수 있는 개수 — 화면 안내에만 쓴다(서버가 강제하는 값은 php.ini 쪽이다).
     * 실측 2026-08-25: 3사 FPM 전부 `max_file_uploads=20` · `post_max_size=40M` · nginx 50M.
     * ⚠️ 서버 설정을 바꾸면 이 값도 같이 고칠 것 — 안 고치면 라벨이 거짓말을 하게 된다(#60 과 같은 부류).
     */
    public const PER_PICK_HINT = 20;

    // category: null/'basic' = 기본정보 탭 차량 사진, 'shipping' = 선적 탭 선박 사진 (2026-07-06 탭별 갤러리 분리).
    protected $fillable = ['vehicle_id', 'path', 'category', 'sort_order'];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /** 표시용 URL — 업로드 디스크 기준(로컬 public 또는 s3). */
    public function getUrlAttribute(): string
    {
        return VehicleDocUrl::for($this->path) ?? '';
    }

    public function getFilenameAttribute(): string
    {
        return basename($this->path);
    }

    public function getExtensionAttribute(): string
    {
        return strtolower(pathinfo($this->path, PATHINFO_EXTENSION));
    }

    public function getIsImageAttribute(): bool
    {
        return in_array($this->extension, self::IMAGE_EXTENSIONS, true);
    }
}
