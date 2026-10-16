<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavingsStatus extends Model
{
    protected $fillable = [
        'buyer_id', 'vehicle_id', 'currency', 'exchange_rate', 'transaction_type',
        'savings', 'balance', 'original_transaction_id', 'note', 'created_by',
    ];

    /**
     * 👤 처리자 + 감사로그 — **생성 시점에 모델이 한 번에** (jin 2026-10-16 «누가 수정했는지도 안 나오네»).
     * 적립금 행을 만드는 경로가 다섯(바이어탭 적립·취소 / 판매탭 사용·환불 H6 / 채권관리 전환 / 현금 원장 전환)이라
     * 경로마다 손으로 넣으면 빠진다(§8 #38). 행은 불변(수정·삭제 없음, 취소 = 역행 추가)이라 생성 이벤트 하나면 전부다.
     * 로그인 없는 경로(배치·연동)는 created_by NULL = 감사 화면의 「시스템」.
     */
    protected static function booted(): void
    {
        static::creating(function (SavingsStatus $row) {
            $row->created_by ??= auth()->id();
        });
        static::created(function (SavingsStatus $row) {
            // 종류별 이벤트 이름 — 사전(config/column_labels.php actions)과 1:1. 가드 = SavingsActorAuditTest.
            $action = match ($row->transaction_type) {
                'EARNED' => 'savings_earned',
                'USED' => 'savings_used',
                'REFUND' => 'savings_refund',
                'CANCELLED' => 'savings_cancelled',
                default => 'savings_adjustment',
            };
            AuditLog::recordEvent($row, $action);
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * ⚠️ `exchange_rate` 는 **적립(유입) 시점 환율**이다 — 사용 시점이 아니다.
     * 크레딧의 원화 가치는 적립될 때 정해지므로, 어느 적립분이 나갔는지(FIFO)가 환산을 좌우한다.
     * 계산은 App\Services\SavingsLedger 단일 출처.
     */
    protected $casts = [
        'savings' => 'decimal:2',
        'balance' => 'decimal:2',
        'exchange_rate' => 'decimal:4',
    ];

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function originalTransaction(): BelongsTo
    {
        return $this->belongsTo(SavingsStatus::class, 'original_transaction_id');
    }
}
