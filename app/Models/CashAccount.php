<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 회사 계좌 (jin 2026-10-14) — 업무 대시보드 잔액 입력의 드롭다운 항목. 통화는 여러 개일 수 있다(외화 통장 = USD+EUR).
 */
class CashAccount extends Model
{
    public const CURRENCIES = ['KRW', 'USD', 'EUR'];

    /** `is_foreign` (2026-10-22) = 외화 계좌 — 원화를 넣으면 한 번 묻는다(막지 않음). */
    protected $fillable = ['bank', 'account_no', 'label', 'is_foreign', 'currencies', 'sort', 'is_active'];

    /** `currencies` 는 2026-10-14 저녁부터 **쓰지 않는다** — 어느 계좌든 KRW·USD·EUR 세 칸을 넣는다(jin). 컬럼은 남겨 두고 전부 저장만. */
    protected $attributes = ['currencies' => '["KRW","USD","EUR"]'];

    protected $casts = ['currencies' => 'array', 'is_active' => 'boolean', 'is_foreign' => 'boolean', 'sort' => 'integer'];

    public function balances(): HasMany
    {
        return $this->hasMany(CashAccountBalance::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort')->orderBy('id');
    }

    /** 드롭다운 표시 — 「하나(외화) 430-910007-816638」 */
    public function getDisplayNameAttribute(): string
    {
        return trim($this->bank.($this->label ? "({$this->label})" : '').' '.$this->account_no);
    }
}
