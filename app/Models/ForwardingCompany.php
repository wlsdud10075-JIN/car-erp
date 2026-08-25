<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ForwardingCompany extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'contact_name', 'email', 'phone', 'address', 'memo', 'is_active',
        'tracking_url_template',
    ];

    protected $casts = ['is_active' => 'boolean'];

    /** 템플릿에서 차대번호가 들어갈 자리. 지금은 이것 하나뿐이다. */
    public const TRACKING_PLACEHOLDER = '{VIN}';

    /**
     * 출항 며칠 뒤부터 추적 링크를 여는가 (jin 2026-08-25).
     *
     * 선사도 선적 후 준비·데이터 전달에 시간이 걸린다. 당일엔 아직 조회가 안 될 수 있어
     * **출항 다음날(D+1)부터** 연다. 그래야 「눌렀는데 없다」가 안 생긴다.
     *
     * ⚠️ 이 숫자는 실측으로 못 정했다 — CIG 53대 중 가장 최근 출항이 D+18 이라 D+1~D+17 구간에
     *    표본이 없었다. 운영에서 "이틀 뒤에야 되더라"가 나오면 **이 상수만** 올리면 된다.
     *    포워딩사마다 다른 것으로 밝혀지면 그때 컬럼으로 내린다(지금은 CIG 한 곳뿐이라 전역).
     */
    public const TRACKING_ACTIVE_AFTER_DAYS = 1;

    /**
     * 추적 URL 을 만든다. 만들 수 없으면 null — 호출부는 null 이면 버튼을 그리지 않는다.
     *
     * 🚫 서버가 이 URL 을 호출하지 않는다. 사용자 브라우저가 새 탭으로 열 뿐이다
     *    (스크래핑하면 상대 화면 구조가 바뀔 때 조용히 깨진다 — 그건 만들지 않기로 했다).
     */
    public function trackingUrlFor(?string $vin): ?string
    {
        $template = trim((string) $this->tracking_url_template);
        $vin = trim((string) $vin);

        if ($template === '' || $vin === '' || ! str_contains($template, self::TRACKING_PLACEHOLDER)) {
            return null;
        }

        return str_replace(self::TRACKING_PLACEHOLDER, rawurlencode($vin), $template);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }
}
