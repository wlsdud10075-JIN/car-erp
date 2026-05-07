<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vehicle extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'vehicle_number', 'sales_channel', 'is_disposed',
        'brand', 'model_type', 'year', 'cc', 'weight_kg', 'mileage', 'color',
        'nice_reg_vin', 'nice_reg_engine_no', 'nice_reg_fuel_type', 'nice_reg_use_type',
        'nice_reg_vehicle_form', 'nice_reg_first_date', 'nice_reg_date',
        'nice_reg_owner_name', 'nice_reg_owner_addr', 'nice_reg_max_load',
        'nice_reg_passengers', 'nice_reg_color',
        'nice_spec_maker', 'nice_spec_model', 'nice_spec_year', 'nice_spec_displacement',
        'nice_spec_transmission', 'nice_spec_drive_type', 'nice_spec_length',
        'nice_spec_width', 'nice_spec_height', 'nice_spec_wheelbase',
        'nice_spec_curb_weight', 'nice_spec_fuel_efficiency',
        'purchase_date', 'salesman_id', 'purchase_from', 'purchase_price', 'selling_fee',
        'cost_deregistration', 'cost_license', 'cost_towing', 'cost_carry',
        'cost_shoring', 'cost_insurance', 'cost_transfer', 'cost_extra1', 'cost_extra2',
        'down_payment', 'selling_fee_payment', 'purchase_remittance_memo',
        'is_deregistered', 'deregistration_document',
        'sale_date', 'currency', 'exchange_rate', 'buyer_id', 'consignee_id',
        'sale_price', 'tax_dc', 'commission', 'transport_fee', 'auto_loading',
        'sale_other_costs', 'deposit_down_payment', 'interim_payment',
        'advance_payment1', 'advance_payment2', 'savings_used',
        'export_buyer_id', 'export_consignee_id', 'forwarding_company_id',
        'export_declaration_amount', 'shipping_date', 'eta_date', 'shipping_method',
        'port_of_loading', 'export_declaration_document', 'export_declaration_number', 'is_export_cleared',
        'forwarding_email_sent',
        'bl_buyer_id', 'bl_consignee_id', 'bl_number', 'container_number',
        'bl_loading_location', 'vessel_name', 'bl_document', 'bl_issue_date',
        'dhl_recipient_name', 'dhl_recipient_address', 'dhl_recipient_phone',
        'dhl_sender_name', 'dhl_sender_address', 'dhl_weight', 'dhl_dimensions',
        'dhl_request', 'memo',
    ];

    protected $casts = [
        'is_disposed' => 'boolean',
        'is_deregistered' => 'boolean',
        'is_export_cleared' => 'boolean',
        'forwarding_email_sent' => 'boolean',
        'dhl_request' => 'boolean',
        'nice_reg_first_date' => 'date',
        'nice_reg_date' => 'date',
        'purchase_date' => 'date',
        'sale_date' => 'date',
        'shipping_date' => 'date',
        'eta_date' => 'date',
        'bl_issue_date' => 'date',
    ];

    // ── Relations ──────────────────────────────────────────────────
    public function salesman(): BelongsTo
    {
        return $this->belongsTo(Salesman::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class);
    }

    public function consignee(): BelongsTo
    {
        return $this->belongsTo(Consignee::class);
    }

    public function exportBuyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class, 'export_buyer_id');
    }

    public function exportConsignee(): BelongsTo
    {
        return $this->belongsTo(Consignee::class, 'export_consignee_id');
    }

    public function forwardingCompany(): BelongsTo
    {
        return $this->belongsTo(ForwardingCompany::class);
    }

    public function blBuyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class, 'bl_buyer_id');
    }

    public function blConsignee(): BelongsTo
    {
        return $this->belongsTo(Consignee::class, 'bl_consignee_id');
    }

    public function finalPayments(): HasMany
    {
        return $this->hasMany(FinalPayment::class);
    }

    public function purchaseBalancePayments(): HasMany
    {
        return $this->hasMany(PurchaseBalancePayment::class);
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(Settlement::class);
    }

    public function savingsStatuses(): HasMany
    {
        return $this->hasMany(SavingsStatus::class);
    }

    // ── Computed: 진행상태 11단계 ───────────────────────────────────
    public function getProgressStatusAttribute(): string
    {
        if ($this->is_disposed) {
            return '폐기';
        }
        if ($this->dhl_request) {
            return '거래완료';
        }
        if ($this->bl_document) {
            return '선적완료';
        }
        if ($this->bl_loading_location) {
            return '선적중';
        }
        if ($this->export_declaration_document) {
            return '수출통관완료';
        }
        if ($this->export_buyer_id && $this->shipping_date) {
            return '수출통관중';
        }
        if ($this->sale_price > 0 && $this->sale_unpaid_amount <= 0) {
            return '판매완료';
        }
        if ($this->sale_price > 0) {
            return '판매중';
        }
        if ($this->is_deregistered && $this->deregistration_document) {
            return '말소완료';
        }
        if ($this->purchase_price > 0 && $this->purchase_unpaid_amount <= 0) {
            return '매입완료';
        }

        return '매입중';
    }

    // ── Computed: 비용 합계 ─────────────────────────────────────────
    public function getCostTotalAttribute(): int
    {
        return (int) (
            $this->cost_deregistration + $this->cost_license + $this->cost_towing +
            $this->cost_carry + $this->cost_shoring + $this->cost_insurance +
            $this->cost_transfer + $this->cost_extra1 + $this->cost_extra2
        );
    }

    // ── Computed: 판매 미입금액 ─────────────────────────────────────
    public function getSaleUnpaidAmountAttribute(): float
    {
        $totalSale = $this->sale_price + $this->transport_fee + $this->sale_other_costs
            + $this->commission + $this->auto_loading - $this->tax_dc;

        $totalReceived = $this->deposit_down_payment + $this->interim_payment
            + $this->advance_payment1 + $this->advance_payment2 + $this->savings_used
            + $this->finalPayments->sum('amount');

        return $totalSale - $totalReceived;
    }

    // ── Computed: 매입 미지급액 ─────────────────────────────────────
    public function getPurchaseUnpaidAmountAttribute(): int
    {
        $totalPurchase = $this->purchase_price + $this->selling_fee;
        $totalPaid = $this->down_payment + $this->selling_fee_payment
            + $this->purchaseBalancePayments
                ->filter(fn ($p) => $p->payment_date !== null && $p->payment_date->lte(now()))
                ->sum('amount');

        return (int) ($totalPurchase - $totalPaid);
    }
}
