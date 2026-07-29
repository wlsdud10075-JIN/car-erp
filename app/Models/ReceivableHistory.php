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

    /**
     * 회수방법 단일 출처 — 검증·UI·DB enum 이 전부 이 목록을 따른다.
     *
     * 🚨 값을 추가하면 **반드시 마이그레이션으로 DB enum 도 같이 늘릴 것.** 2026-07-28 적립금 배포가
     *    이걸 빠뜨려 3사에서 적립금 사용이 통째로 죽었다(1265 Data truncated → 차량 저장 롤백).
     *    로컬 SQLite 는 enum 을 강제하지 않아 테스트로도 안 잡힌다([[project_db_tier_mismatch]]).
     *    가드 = ReceivableMethodEnumTest (이 상수 ↔ 마이그레이션 enum 문자열 대조).
     */
    public const METHODS = ['deposit', 'cash', 'offset', 'other', 'write_off', 'savings'];

    /**
     * 적립금(method=savings) 행이 vehicles.savings_used 를 갱신하는 걸 건너뛰는 플래그 (2026-07-28).
     * 판매탭에서 savings_used 가 바뀌면 Vehicle H6 가 기록용 미러 행을 만드는데, 그 행이 다시
     * savings_used 를 더하면 이중 반영된다 → Vehicle 이 이 플래그를 try/finally 로 세운다.
     * (deposit ↔ final_payments 미러의 FinalPayment::$skipReceivableSync 와 같은 패턴.)
     */
    public static bool $skipSavingsSync = false;

    protected static function booted(): void
    {
        static::saved(function (ReceivableHistory $h) {
            $h->syncFinalPayment();
            $h->syncSavingsUsed();
            $h->vehicle?->refreshCaches();
        });

        static::deleted(function (ReceivableHistory $h) {
            if ($h->method === 'savings') {
                // 적립금 행 삭제 = 사용 취소 → savings_used 되돌림(음수 delta → SavingsStatus REFUND).
                $h->applySavingsUsedDelta(-(float) $h->amount);

                return;   // applySavingsUsedDelta 안의 vehicle save → refreshCaches 연쇄
            }
            if ($h->final_payment_id) {
                FinalPayment::find($h->final_payment_id)?->delete();

                // FinalPayment::deleted 트리거 → 부모 차량 refreshCaches
                return;
            }
            $h->vehicle?->refreshCaches();
        });
    }

    /**
     * 적립금 회수(method=savings) ↔ vehicles.savings_used 동기화 (2026-07-28, jin).
     *
     * 채권관리 드로어에서 "적립금"으로 회수를 기록하면 그 금액만큼 savings_used 를 증감시킨다.
     * 잔액 차감(SavingsStatus USED/REFUND)·미수 반영은 전부 Vehicle H6 가 delta 를 보고 처리하므로
     * 여기서는 컬럼만 옮기면 된다 — 적립금 잔액 로직을 두 벌 만들지 않는 게 핵심.
     *
     * - 신규(savings) → +amount
     * - 금액 수정      → +(new - old)
     * - 방법 변경(savings → 다른 것) → 이전 금액만큼 되돌림
     */
    public function syncSavingsUsed(): void
    {
        if (self::$skipSavingsSync) {
            return;   // Vehicle H6 가 만든 미러 행 — 이미 savings_used 에 반영된 값이다
        }

        $wasSavings = $this->getOriginal('method') === 'savings';
        $isSavings = $this->method === 'savings';
        if (! $wasSavings && ! $isSavings) {
            return;
        }

        $oldAmount = $wasSavings && $this->exists ? (float) ($this->getOriginal('amount') ?? 0) : 0.0;
        $newAmount = $isSavings ? (float) ($this->amount ?? 0) : 0.0;
        $delta = $newAmount - $oldAmount;

        $this->applySavingsUsedDelta($delta);
    }

    /** savings_used 에 delta 적용 — Vehicle H6 가 잔액(SavingsStatus)·미수를 이어서 처리한다. */
    private function applySavingsUsedDelta(float $delta): void
    {
        if (abs($delta) < 0.01) {
            return;
        }
        $vehicle = $this->vehicle;
        if (! $vehicle) {
            return;
        }

        Vehicle::$skipSavingsHistory = true;   // 미러 행 재생성 방지 (이 행이 곧 그 기록)
        try {
            $vehicle->savings_used = (float) ($vehicle->savings_used ?? 0) + $delta;
            $vehicle->save();
        } finally {
            Vehicle::$skipSavingsHistory = false;
        }
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
