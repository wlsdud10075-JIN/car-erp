<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReceivableHistory extends Model
{
    protected $fillable = [
        'vehicle_id', 'final_payment_id', 'collected_at',
        'collector_id', 'method', 'amount', 'note',
    ];

    protected $casts = [
        'collected_at' => 'date',
        'amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::saved(function (ReceivableHistory $h) {
            $h->syncFinalPayment();
            $h->vehicle?->refreshCaches();
        });

        static::deleted(function (ReceivableHistory $h) {
            if ($h->final_payment_id) {
                FinalPayment::find($h->final_payment_id)?->delete();

                // FinalPayment::deleted 트리거 → 부모 차량 refreshCaches
                return;
            }
            $h->vehicle?->refreshCaches();
        });
    }

    /**
     * final_payments와의 양방향 미러링 동기화.
     *
     * - method=deposit + 미연결 → 새 final_payment 생성 + 링크
     * - method=deposit + 연결됨 → 링크된 final_payment 갱신
     * - method!=deposit + 연결됨 → 링크된 final_payment 삭제 (method 변경 케이스)
     * - method!=deposit + 미연결 → 무동작
     */
    public function syncFinalPayment(): void
    {
        if ($this->method === 'deposit') {
            $payload = [
                'amount' => $this->amount,
                'payment_date' => $this->collected_at,
                'note' => '회수: '.($this->note ?? ''),
            ];

            if ($this->final_payment_id) {
                FinalPayment::where('id', $this->final_payment_id)->update($payload);
                // query builder update — 모델 이벤트 미발생. 캐시는 saved 핸들러에서 별도 refresh.
            } else {
                // 큐 10 H5 — FinalPayment::created가 또 ReceivableHistory를 만들지 못하게 flag.
                FinalPayment::$skipReceivableSync = true;
                try {
                    $fp = FinalPayment::create(array_merge($payload, ['vehicle_id' => $this->vehicle_id]));
                } finally {
                    FinalPayment::$skipReceivableSync = false;
                }
                // self-update를 query builder로 처리해서 saved 재진입 방지
                static::query()->where('id', $this->id)->update(['final_payment_id' => $fp->id]);
                $this->final_payment_id = $fp->id;
            }

            return;
        }

        // method가 deposit이 아닌데 링크가 남아 있으면 (method 변경된 경우) 정리
        if ($this->final_payment_id) {
            FinalPayment::find($this->final_payment_id)?->delete();
            static::query()->where('id', $this->id)->update(['final_payment_id' => null]);
            $this->final_payment_id = null;
        }
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collector_id');
    }

    public function finalPayment(): BelongsTo
    {
        return $this->belongsTo(FinalPayment::class);
    }
}
