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
        'contact_phone', 'passport_id', 'address', 'memo', 'is_active',
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

    /** 신규 매입 게이트 임계 — 바이어 미수율이 이 값 초과면 신규 차량 등록 차단(관리 승인 우회). */
    public const RECEIVABLE_GATE_THRESHOLD = 0.5;

    /**
     * 바이어 미수금 게이지 — 목록·드로어·매입 게이트 단일 출처 (숫자 일치 보장).
     * 분모: Σ(sale_total_amount × exchange_rate), total>0 && rate>0 인 진행중 차량만.
     * 분자: Σ(sale_unpaid_amount_krw_cache). 환율 미입력 외화는 분모·분자 양쪽 자동 제외.
     * 반환 null = 진행중 판매 이력 없음(분모 0) → 게이지·게이트 미적용.
     *
     * ⚠️ 거래완료(progress_status_cache='거래완료', B/L 발급·완납)는 분모·분자·건수에서 제외 (jin 2026-07-18).
     *   근거: 거래완료 총액이 분모에 섞이면 미수율이 희석돼 "진행중 실제 미수"를 정확히 못 본다.
     *   미수/게이트는 진행중 총액 기준. 거래완료 총액·건수는 completed_krw/completed_count 로 별도 반환(표기용).
     *
     * @param  iterable  $vehicles  sale_total_amount + progress_status_cache 로드된 Vehicle 컬렉션
     */
    public static function computeReceivableGauge(iterable $vehicles): ?array
    {
        $totalKrw = 0;
        $unpaidKrw = 0;
        $count = 0;
        $completedKrw = 0;
        $completedCount = 0;
        foreach ($vehicles as $v) {
            $rate = (float) ($v->exchange_rate ?? 0);
            $total = (float) ($v->sale_total_amount ?? 0);
            $rowKrw = ($total > 0 && $rate > 0) ? (int) ($total * $rate) : 0;

            if (($v->progress_status_cache ?? null) === '거래완료') {
                $completedKrw += $rowKrw;
                $completedCount++;

                continue;   // 진행중 미수 기준에서 분리
            }

            $count++;
            $totalKrw += $rowKrw;
            $unpaidKrw += (int) ($v->sale_unpaid_amount_krw_cache ?? 0);
        }

        if ($totalKrw <= 0) {
            return null;
        }

        $paidKrw = max(0, $totalKrw - $unpaidKrw);

        return [
            'total_krw' => $totalKrw,               // 진행중 총액 (거래완료 제외)
            'unpaid_krw' => $unpaidKrw,
            'paid_krw' => $paidKrw,
            'paid_pct' => max(0, min(100, $paidKrw / $totalKrw * 100)),
            'ratio' => max(0, min(1, $unpaidKrw / $totalKrw)),   // 게이지 채움·게이트 비교 (미수/진행중총)
            'vehicle_count' => $count,              // 진행중 건수
            'completed_krw' => $completedKrw,       // 거래완료 총액 (분리 표기)
            'completed_count' => $completedCount,
        ];
    }

    /** 이 바이어의 미수 게이지 (자기 차량 로딩). 매입 게이트가 사용. */
    public function receivableGauge(): ?array
    {
        return static::computeReceivableGauge($this->vehicles()->get());
    }
}
