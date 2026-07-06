<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Buyer extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'country_id', 'salesman_id',
        'contact_name', 'contact_email',
        'contact_phone', 'address', 'memo', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    /**
     * 대량 임포트/시더에서 바이어→컨사이니 자동생성을 끄는 플래그 (안전판).
     * 기본 가드는 auth()->check() (아래 booted). ConsigneeImport 처럼 auth 컨텍스트에서
     * 바이어+컨사이니를 함께 만드는 경로가 생기면 이 플래그로 명시 차단.
     */
    public static bool $skipAutoConsignee = false;

    /**
     * jin 2026-07-06 quick win ⑥ — 바이어 등록 시 컨사이니 1번 자동 생성.
     * 근거: 바이어가 본인 수령하는 경우가 있어, 등록 시 동일 정보(이름/주소/이메일/전화/국가)로
     * 컨사이니 1건을 바로 만들어 두면 편하다(추후 컨사이니는 바이어 매칭으로 별도 추가).
     * 자동 생성분은 독립 — 이후 바이어 수정해도 동기화하지 않는다(단순).
     * 가드: 대량 임포트/시더(artisan, auth 없음)는 제외 — 일괄업로드가 컨사이니를 함께 만들 때 중복 방지.
     */
    protected static function booted(): void
    {
        static::created(function (Buyer $buyer) {
            if (self::$skipAutoConsignee || ! auth()->check()) {
                return;
            }

            $buyer->consignees()->create([
                'name' => $buyer->name,
                'country_id' => $buyer->country_id,
                'contact_name' => $buyer->contact_name,
                'contact_email' => $buyer->contact_email,
                'contact_phone' => $buyer->contact_phone,
                'address' => $buyer->address,
                'is_active' => $buyer->is_active,
            ]);
        });
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    // 회의확장씬 #5-1 (2026-05-22) — 바이어 영업담당자 직접 지정.
    // [관리] 솔팅에서 buyers.salesman_id IN subordinates_salesman_ids 로 직접 사용.
    public function salesman(): BelongsTo
    {
        return $this->belongsTo(Salesman::class);
    }

    public function consignees(): HasMany
    {
        return $this->hasMany(Consignee::class);
    }

    public function savingsStatuses(): HasMany
    {
        return $this->hasMany(SavingsStatus::class);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }
}
