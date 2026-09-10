<?php

namespace App\Models;

use App\Services\BuyerCashService;
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
    public const METHODS = ['deposit', 'cash', 'offset', 'other', 'write_off', 'savings', 'misc_loss'];

    /**
     * 사람이 회수이력 폼에서 **직접 고를 수 있는** 방법 — 검증이 이 목록을 쓴다.
     *
     * 🚫 `misc_loss`(잡손실)는 여기 없다. 그건 **과입금 정리 버튼만** 만드는 기록이라,
     *    손으로 넣으면 잔금 감액 없이 행만 생겨 실사 때 「정리했다는 기록인데 돈은 그대로」가 된다.
     *    미수 계산에서도 제외되는 미러 항목이라 넣어도 아무 숫자가 안 움직인다.
     *
     * 🧭 목록이 둘인 이유 = SKILLS §8 #59 의 「받을 수 있는 것 / 그릴 수 있는 것」과 같은 갈래.
     *    `METHODS` 는 **DB enum 과 대조하는 전체 목록**이고, 이쪽은 입력 허용분이다.
     */
    public const MANUAL_METHODS = ['deposit', 'cash', 'offset', 'other', 'write_off', 'savings'];

    /**
     * 소급 적재가 「미수를 0 으로 만들려고」 남긴 회수이력의 표식 (2026-08-28 ssancarerp 적재).
     *
     * 그 행은 **실제로 받은 돈이 아니다** — 적재 시점에 미납이던 금액을 기타(other) 로 적어
     * 미수를 0 으로 눕힌 기록이다(jin 확정, 🚫손실처리 아님). 그래서 화면상 완납인데
     * 실제로는 받아야 할 돈이 남아 있는 차가 생긴다(실측 ssancarerp 317 건 — 그중 45 대는
     * 금액이 **운임비와 정확히 일치**한다 = 물건값은 받고 운임만 못 받은 선적 묶음).
     *
     * 🔑 **표식은 이 문자열 하나뿐이다.** 채권관리 「임포트 정리분」 탭과
     *    `ssancarerp:convert-import-receivables` 가 같은 값을 본다 — 옮겨 적지 말 것(SKILLS §8 #45).
     * 🧭 `method='other'` 만으로 고르지 않는 이유 = 기타는 **사람이 실제 회수에도 쓰는** 방법이라
     *    그것만 보면 진짜 받은 돈까지 「안 받은 돈」으로 뜬다(오탐이 나는 목록은 곧 무시당한다).
     */
    public const IMPORT_CLEARED_NOTE_PREFIX = '과거데이터 임포트';

    /** 위 표식이 붙은 「미수 정리」 행 — 단일 출처. */
    public function scopeImportCleared($query)
    {
        return $query->where('method', 'other')
            ->where('note', 'like', self::IMPORT_CLEARED_NOTE_PREFIX.'%');
    }

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
                // 🚨 바이어 현금 원장(2026-09-04) — 모델 훅이 안 뜨므로 배분을 직접 다시 맞춘다.
                //   안 부르면 확정분의 금액만 바뀌고 현금은 그대로라 둘이 조용히 어긋난다.
                $fp = FinalPayment::find($this->final_payment_id);
                if ($fp) {
                    app(BuyerCashService::class)->resyncAfterRawUpdate($fp);
                }
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
