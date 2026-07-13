<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReceivableHistory extends Model
{
    protected $fillable = [
        'vehicle_id', 'final_payment_id', 'collected_at',
        'collector_id', 'method', 'amount', 'exchange_rate', 'note',
    ];

    protected $casts = [
        'collected_at' => 'date',
        'amount' => 'decimal:2',
        'exchange_rate' => 'decimal:4',
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
            // 환율 편집 반영 (Phase 3, 2026-07-13) — 환율이 명시된 경우에만 미러링.
            //   ⚠️ null 을 항상 넣으면 역방향 미러(FinalPayment::created→RH)가 FP 기존 환율을 null 로 덮어씀.
            //   raw update 라 FinalPayment::saving 훅 미발동 → amount_krw 를 훅과 동일 공식으로 직접 계산.
            if ($this->exchange_rate !== null) {
                $rate = (float) $this->exchange_rate;
                $amt = (float) ($this->amount ?? 0);
                $payload['exchange_rate'] = $this->exchange_rate;
                $payload['amount_krw'] = ($rate > 0 && $amt > 0) ? round($amt * $rate, 2) : null;
            }

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
