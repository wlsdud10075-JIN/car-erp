<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 판매계약서 전자서명 세션 (2026-07-10 풀회의 — ERP 직접호스팅 + Certificate of Completion).
 *
 * 설계 원칙 (docs/meetings/2026-07-10-sales-contract-e-signature.md):
 * - vehicles·progress_status·vehicle_photos 완전 분리. 서명 상태는 여기서만 추적(캐시 오염 금지).
 * - 발송 시점 스냅샷 동결: snapshot_path(원본 xlsx 바이트)·source_hash + snapshot_data(표시필드 JSON).
 *   서명 페이지 요약은 snapshot_data 에서만 렌더(Vehicle 재쿼리 금지 — 화면=xlsx=해시 정합).
 * - all-or-nothing: 1 row = 계약 전체(다중차량 vehicle_ids). 부분서명 없음. 차량 변경 = revoke + 재발급.
 * - status: pending(발급) → viewed(열람) → signed(서명완료, 불변) / revoked(무름).
 * - 하드삭제 가드(§27 논리, 법적 증거물이라 confirmed Settlement 보다 엄격 — auth 우회 없이 signed 차단).
 */
class SignedContract extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_VIEWED = 'viewed';

    public const STATUS_SIGNED = 'signed';

    public const STATUS_REVOKED = 'revoked';

    /** 활성(재발급 시 revoke 대상) — signed/revoked 는 종결 상태라 제외. */
    public const ACTIVE_STATUSES = [self::STATUS_PENDING, self::STATUS_VIEWED];

    protected $fillable = [
        'buyer_id', 'vehicle_ids', 'contract_no', 'currency',
        'snapshot_path', 'source_hash', 'snapshot_data',
        'status', 'sign_token', 'token_expires_at', 'recipient_email',
        'signed_pdf_path', 'signed_hash', 'signature_path',
        'signer_name', 'signer_ip', 'signer_ua',
        'sent_at', 'viewed_at', 'signed_at', 'mail_sent_at', 'revoked_at',
        'created_by',
    ];

    protected $casts = [
        'vehicle_ids' => 'array',
        'snapshot_data' => 'array',
        'token_expires_at' => 'datetime',
        'sent_at' => 'datetime',
        'viewed_at' => 'datetime',
        'signed_at' => 'datetime',
        'mail_sent_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // 법적 증거물 — 서명 완료본은 하드삭제 금지(§27 보다 엄격, auth 우회 없음).
        // pending/viewed/revoked(미서명 세션)만 정리 가능.
        static::deleting(function (SignedContract $c) {
            if ($c->status === self::STATUS_SIGNED) {
                throw new \DomainException(__('signed_contract.notify.delete_locked'));
            }
        });
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isSigned(): bool
    {
        return $this->status === self::STATUS_SIGNED;
    }

    public function isRevoked(): bool
    {
        return $this->status === self::STATUS_REVOKED;
    }

    public function isExpired(): bool
    {
        return $this->token_expires_at !== null && $this->token_expires_at->isPast();
    }

    /** 서명 제출 가능 상태 — 미서명·미무름·미만료. GET 열람은 만료 전 반복 허용, 제출만 1회. */
    public function isSignable(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true) && ! $this->isExpired();
    }
}
