<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 서류 발송 1건 — 우체국 EMS · DHL (jin 2026-08-31).
 *
 * 1행 = 「차량 × 발송」. 한 발송이 N 대를 덮으므로 일괄 기입이 **총액을 N 으로 나눠** 행을 만든다.
 * 같은 차량이 재발송되면 행이 늘어난다(덮어쓰지 않는다) — 앞 발송 금액이 사라지지 않게.
 *
 * 🔑 **금액의 뜻** — `fee` 는 그 차량 몫이지 발송 총액이 아니다.
 *    `0` 은 «회사 부담»: 번호는 남겨 바이어가 조회할 수 있게 하되 담당자 정산에서는 안 뺀다.
 *
 * 🔒 **회계 락** — 2차 정산 마감(`secondary_status='closed'`) 차량의 행은 추가·수정·삭제 불가.
 *    트리거는 차량 회계컬럼과 같은 `Vehicle::hasClosedSecondarySettlement()` 단일 출처이고,
 *    해제도 같은 [🔓 회계 재조정] 토큰을 소비한다 — 발송비 전용 우회로를 새로 만들지 않는다.
 *    시드·artisan(auth 없음)은 통과(대량 적재용) — `FinalPayment::creating` 과 같은 규칙.
 */
class VehicleShipment extends Model
{
    public const CARRIER_EMS = 'ems';

    public const CARRIER_DHL = 'dhl';

    /** 허용 운송사 단일 출처 — DB 는 varchar 라 여기만 보면 된다(enum 금지 이유는 마이그레이션 주석). */
    public const CARRIERS = [self::CARRIER_EMS, self::CARRIER_DHL];

    /** 운송사별 화면 용어 — 번호 라벨이 다르다(등기번호 ↔ 운송장번호). */
    public const CARRIER_META = [
        self::CARRIER_EMS => ['label' => 'EMS', 'cache' => 'ems_tracking_no_cache'],
        self::CARRIER_DHL => ['label' => 'DHL', 'cache' => 'dhl_tracking_no_cache'],
    ];

    /** 시스템 경로(일괄 기입·적재)에서 마감 가드를 우회할 때만 true — try/finally 로 감쌀 것. */
    public static bool $allowClosedMutation = false;

    protected $fillable = ['vehicle_id', 'carrier', 'tracking_no', 'fee', 'sent_date', 'note'];

    protected $casts = [
        'fee' => 'integer',
        'sent_date' => 'date',
    ];

    protected static function booted(): void
    {
        static::saving(function (VehicleShipment $s) {
            $s->carrier = self::normalizeCarrier($s->carrier);
            $s->tracking_no = self::normalizeTrackingNo($s->tracking_no);
        });

        // 마감 차량 보호 — 추가·수정·삭제 전부. 조용히 통과시키면 지급이 끝난 달의 숫자가 바뀐다.
        static::creating(fn (VehicleShipment $s) => $s->guardClosed('추가'));
        static::updating(fn (VehicleShipment $s) => $s->guardClosed('수정'));
        static::deleting(fn (VehicleShipment $s) => $s->guardClosed('삭제'));

        static::saved(fn (VehicleShipment $s) => $s->vehicle?->refreshShippingCaches());
        static::deleted(fn (VehicleShipment $s) => $s->vehicle?->refreshShippingCaches());
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function scopeCarrier(Builder $q, string $carrier): Builder
    {
        return $q->where('carrier', self::normalizeCarrier($carrier));
    }

    /**
     * 허용 목록 밖이면 던진다 — 조용히 저장돼 「목록에 안 뜨는 행」이 되는 것보다 낫다.
     * (varchar 라 DB 가 안 막아준다. 이 함수가 유일한 관문이다.)
     */
    public static function normalizeCarrier(?string $carrier): string
    {
        $c = strtolower(trim((string) $carrier));
        if (! in_array($c, self::CARRIERS, true)) {
            throw new DomainException('알 수 없는 발송 구분: '.var_export($carrier, true).' (허용 = '.implode(', ', self::CARRIERS).')');
        }

        return $c;
    }

    /** 등기번호·운송장번호는 공백·하이픈이 섞여 들어온다(복붙·수기). 대문자 영숫자로 통일해야 매칭·검색이 된다. */
    public static function normalizeTrackingNo(?string $no): ?string
    {
        $v = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $no));

        return $v !== '' ? $v : null;
    }

    public function carrierLabel(): string
    {
        return self::CARRIER_META[$this->carrier]['label'] ?? $this->carrier;
    }

    private function guardClosed(string $what): void
    {
        if (self::$allowClosedMutation || ! auth()->check()) {
            return;
        }
        $vehicle = $this->vehicle;
        if (! $vehicle || ! $vehicle->hasClosedSecondarySettlement()) {
            return;
        }
        // 차량 회계컬럼과 같은 잠금해제 토큰을 소비한다 — 전용 우회로를 만들지 않는다.
        if ($vehicle->consumeLedgerUnlockToken() !== null) {
            return;
        }

        throw new DomainException(
            "2차 정산이 마감된 차량의 발송 내역은 {$what}할 수 없습니다 — 정산 화면의 [🔓 회계 재조정]으로 잠금 해제 후 가능합니다."
        );
    }
}
