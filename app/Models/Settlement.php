<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Settlement extends Model
{
    protected $fillable = [
        'vehicle_id', 'salesman_id', 'settlement_type', 'settlement_ratio',
        'per_unit_amount', 'other_deduction', 'settlement_status',
        'confirmed_at', 'paid_at', 'note',
    ];

    protected $casts = [
        'confirmed_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function salesman(): BelongsTo
    {
        return $this->belongsTo(Salesman::class);
    }

    // ── 마진 computed (CLAUDE.md §정산마진공식) ─────────────────────

    public function getSalesAmountKrwAttribute(): int
    {
        $v = $this->vehicle;
        if (! $v) {
            return 0;
        }

        return (int) (((float) ($v->export_declaration_amount ?? 0) - (float) ($v->transport_fee ?? 0)) * (float) ($v->exchange_rate ?? 0));
    }

    public function getSettlementSalesKrwAttribute(): int
    {
        return $this->sales_amount_krw - ($this->vehicle?->cost_total ?? 0);
    }

    public function getSalesMarginAttribute(): int
    {
        return $this->settlement_sales_krw - (int) ($this->vehicle?->purchase_price ?? 0);
    }

    public function getVatMarginAttribute(): int
    {
        return (int) (($this->vehicle?->purchase_price ?? 0) * 0.09);
    }

    public function getTotalMarginAttribute(): int
    {
        return $this->sales_margin + $this->vat_margin;
    }

    public function getSettlementAmountAttribute(): int
    {
        if ($this->settlement_type === 'ratio') {
            return (int) ($this->total_margin * (($this->settlement_ratio ?? 0) / 100));
        }

        return (int) ($this->per_unit_amount ?? 0);
    }

    public function getActualPayoutAttribute(): int
    {
        return $this->settlement_amount - (int) ($this->other_deduction ?? 0);
    }
}
