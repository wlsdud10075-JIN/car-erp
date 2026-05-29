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

    protected $fillable = ['vehicle_id', 'path', 'sort_order'];

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
