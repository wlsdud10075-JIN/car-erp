<?php

namespace App\Models;

use App\Support\VehicleDocUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 차량 등록 사진 (jpg/png) — 1차량 N장. vehicle_docs_disk(로컬 public / 운영 s3)에 저장.
 */
class VehiclePhoto extends Model
{
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
}
