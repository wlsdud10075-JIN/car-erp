<?php

namespace App\Models;

use App\Support\VehicleDocUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 바이어 계약서 첨부 (B2B 파트너스 1차, jin 2026-11-01) — 1바이어 N건. `vehicle_docs_disk`(로컬 public / 운영 s3).
 *
 * - `kind` = base(기본약정서) | extra(추가약정서). 한 번 등록하고 필요할 때 추가·교체한다(요청서 02 — 매 항차 재업로드 강제 ❌).
 * - 교체 = 새 행 + 옛 행 `superseded_at`. 🚫 행을 지우지 않는다 — 원본 보관이 요청서 조건이다.
 * - 열람 URL 은 `VehicleDocUrl::for`(S3 3분 서명) — 차량 첨부와 같은 관행. 🚫 포털/board 에 내보내지 않는다(요청서 05).
 */
class BuyerDocument extends Model
{
    public const KINDS = ['base', 'extra'];

    public const MAX_PER_BUYER = 30;   // 차량 첨부와 같은 상한

    protected $fillable = ['buyer_id', 'path', 'kind', 'note', 'uploaded_by', 'superseded_at'];

    protected $casts = ['superseded_at' => 'datetime'];

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function getUrlAttribute(): string
    {
        return VehicleDocUrl::for($this->path) ?? '';
    }

    public function getFilenameAttribute(): string
    {
        // 저장명은 `{timestamp}_{원본명}` — 원본명만 보인다.
        return preg_replace('/^\d+_/', '', basename($this->path));
    }

    public function isCurrent(): bool
    {
        return $this->superseded_at === null;
    }
}
