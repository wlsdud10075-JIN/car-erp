<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class Vehicle extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'vehicle_number', 'sales_channel', 'is_disposed', 'progress_status_cache',
        'progress_status_rule_version', 'is_override_active',
        'receivable_risk', 'sale_unpaid_amount_krw_cache', 'receivable_manager_id',
        'tax_invoice_1_date', 'tax_invoice_1_amount',
        'tax_invoice_2_date', 'tax_invoice_2_amount', 'agency_fee',
        'brand', 'model_type', 'year', 'cc', 'weight_kg', 'mileage', 'color',
        'nice_reg_vin', 'nice_reg_engine_no', 'nice_reg_fuel_type', 'nice_reg_use_type',
        'nice_reg_vehicle_form', 'nice_reg_first_date', 'nice_reg_date',
        'nice_reg_owner_name', 'nice_reg_owner_addr', 'nice_reg_owner_rrn', 'nice_reg_owner_rrn_encrypted_at', 'nice_reg_max_load',
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
        'is_override_active' => 'boolean',
        'progress_status_rule_version' => 'integer',
        'nice_reg_first_date' => 'date',
        'nice_reg_date' => 'date',
        'purchase_date' => 'date',
        'sale_date' => 'date',
        'shipping_date' => 'date',
        'eta_date' => 'date',
        'bl_issue_date' => 'date',
        'tax_invoice_1_date' => 'date',
        'tax_invoice_2_date' => 'date',
        'nice_reg_owner_rrn_encrypted_at' => 'datetime',
    ];

    // ── RRN 암호화 (개인정보보호법 §29) ─────────────────────────────
    // 표식 컬럼 nice_reg_owner_rrn_encrypted_at 기반 점진 전환:
    // - NULL: 평문 row (마이그레이션 전 또는 신규 마이그레이션 도중 부분 상태)
    // - NOT NULL: 암호화 row (Laravel Crypt — AES-256-CBC + base64 + MAC)
    // accessor는 표식에 따라 분기, mutator는 자동 암호화.
    public function getNiceRegOwnerRrnAttribute(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }
        if (($this->attributes['nice_reg_owner_rrn_encrypted_at'] ?? null) === null) {
            return $value;
        }
        try {
            return Crypt::decryptString($value);
        } catch (\Throwable $e) {
            // APP_KEY 변경·데이터 손상 시 — 화면에는 빈 값으로 표시
            return null;
        }
    }

    public function setNiceRegOwnerRrnAttribute(?string $value): void
    {
        if ($value === null || $value === '') {
            $this->attributes['nice_reg_owner_rrn'] = $value;
            $this->attributes['nice_reg_owner_rrn_encrypted_at'] = null;

            return;
        }
        $this->attributes['nice_reg_owner_rrn'] = Crypt::encryptString($value);
        $this->attributes['nice_reg_owner_rrn_encrypted_at'] = now();
    }

    // ── Boot: 진행상태/채권 캐시 자동 갱신 ─────────────────────────
    protected static function booted(): void
    {
        static::saving(function (Vehicle $vehicle) {
            // 캐시 자동 갱신 — 시드·UI 저장 모두 발동.
            // C4·C5 단계 의존성 검증은 saving 이벤트가 아닌 UI save() 흐름에서만
            // (Vehicle::guardStageOrderForExport()를 vehicles/index::save()가 명시 호출)
            // 시드는 도메인 시뮬레이션이라 검증 우회. UI 사용자 입력만 차단 대상.
            $vehicle->progress_status_cache = $vehicle->progress_status;
            $vehicle->receivable_risk = $vehicle->receivable_risk_computed;
            $krw = $vehicle->sale_unpaid_amount_krw;
            $vehicle->sale_unpaid_amount_krw_cache = $krw !== null ? (int) round($krw) : null;
        });

        // hard delete (forceDelete) 시 첨부 디렉토리를 즉시 삭제하지 않고
        // storage/backups/deleted/{id}-{timestamp}/ 로 이동 (큐 11-2).
        // soft delete는 첨부 유지 — 복구 가능성 보호.
        // 운영 사고 시 storage/backups/deleted/ 에서 수동 복구 가능.
        static::forceDeleted(function (Vehicle $vehicle) {
            $publicSource = storage_path("app/public/vehicles/{$vehicle->id}");
            if (! is_dir($publicSource)) {
                return;
            }
            $timestamp = now()->format('Ymd_His');
            $backupDir = storage_path("backups/deleted/{$vehicle->id}-{$timestamp}");
            File::ensureDirectoryExists(dirname($backupDir));
            File::moveDirectory($publicSource, $backupDir);
        });

        // H7 — soft-delete 후 restore 시 캐시 stale 가능. 복구 직후 재계산.
        static::restored(function (Vehicle $vehicle) {
            $vehicle->refreshCaches();
        });

        // H6 — savings_used delta 감지 → SavingsStatus(USED/REFUND) 자동 생성.
        // 이중 사용 방지 (Vehicle만 차감 표시 + buyer 잔액 미감소 → 다른 차량서 또 사용 가능).
        static::saved(function (Vehicle $vehicle) {
            if (! $vehicle->wasChanged('savings_used')) {
                return;
            }
            if (! $vehicle->buyer_id || ! $vehicle->currency) {
                return;
            }
            $original = (float) ($vehicle->getOriginal('savings_used') ?? 0);
            $current = (float) ($vehicle->savings_used ?? 0);
            $delta = $current - $original;
            if (abs($delta) < 0.01) {
                return;
            }
            $vehicle->syncSavingsUsage($delta);
        });
    }

    /**
     * H6 — savings_used 변화량을 SavingsStatus 거래로 자동 기록.
     * delta > 0 → USED (잔액 차감 / savings 음수)
     * delta < 0 → REFUND (잔액 환원 / savings 양수)
     * 동시성 대비 buyer×currency 잔액에 lockForUpdate.
     */
    public function syncSavingsUsage(float $delta): void
    {
        DB::transaction(function () use ($delta) {
            $latest = SavingsStatus::where('buyer_id', $this->buyer_id)
                ->where('currency', $this->currency)
                ->lockForUpdate()
                ->orderByDesc('id')
                ->first();
            $currentBalance = (float) ($latest?->balance ?? 0);
            $savings = -$delta;
            $newBalance = $currentBalance + $savings;

            SavingsStatus::create([
                'buyer_id' => $this->buyer_id,
                'vehicle_id' => $this->id,
                'currency' => $this->currency,
                'transaction_type' => $delta > 0 ? 'USED' : 'REFUND',
                'savings' => $savings,
                'balance' => $newBalance,
                'note' => "차량 {$this->vehicle_number} savings_used 자동 동기화 (delta {$delta})",
            ]);
        });
    }

    /**
     * H1·H2 + 큐 2.6 — 첨부/전제 조건 + 단계 캐스케이드 검증.
     * 11단계 v2 분류 규칙과 일치하는 UI save 게이트.
     *
     * - H1: dhl_request=true 전환 시 bl_document 비어있으면 차단
     * - H2: is_export_cleared=true 전환 시 export_declaration_document 비어있으면 차단
     * - 큐 2.6 H3: bl_document 업로드 시 bl_loading_location(반입지) 비어있으면 차단
     * - 큐 2.6 H4: bl_loading_location 입력 시 is_export_cleared=false면 차단
     *
     * 시드는 도메인 시뮬레이션이라 검증 우회 — vehicles/index::save()에서만 명시 호출.
     * `is_disposed=true`(폐기) / 비-export 채널은 검증 우회.
     */
    public function guardAttachmentDeps(): void
    {
        if ($this->is_disposed) {
            return;
        }
        if ($this->sales_channel !== 'export') {
            return;
        }

        // 큐 2.6 H4 — 선적 반입지 입력은 통관 완료 처리(체크박스) 후 (캐스케이드 가장 깊은 곳부터 검증)
        if ($this->bl_loading_location && ! $this->is_export_cleared) {
            throw ValidationException::withMessages([
                'bl_loading_location' => '선적 반입지를 입력하려면 수출통관 완료 처리(체크박스)가 먼저 필요합니다.',
            ]);
        }

        // 큐 2.6 H3 — B/L 문서는 반입지 입력 후
        if ($this->bl_document && empty($this->bl_loading_location)) {
            throw ValidationException::withMessages([
                'bl_document' => 'B/L 문서를 업로드하려면 선적 반입지 입력이 먼저 필요합니다.',
            ]);
        }

        // H1 — DHL 발송 신청 시 B/L 문서 강제
        if ($this->dhl_request && empty($this->bl_document)) {
            throw ValidationException::withMessages([
                'dhl_request' => 'DHL 발송 신청을 하려면 B/L 문서 업로드가 먼저 필요합니다.',
            ]);
        }

        // H2 — 수출통관 완료 체크 시 수출신고서 강제
        if ($this->is_export_cleared && empty($this->export_declaration_document)) {
            throw ValidationException::withMessages([
                'is_export_cleared' => '수출통관 완료 처리를 하려면 수출신고서 업로드가 먼저 필요합니다.',
            ]);
        }
    }

    /**
     * C4·C5 — 단계 의존성 검증. 수출 정보 입력 시점에 선행 단계 강제.
     * - C4: 말소(is_deregistered + deregistration_document)가 완료돼야 통관 진입 가능
     * - C5: 판매 미입금 잔존(sale_unpaid_amount_krw_cache > 0)인 상태에서 통관 진입 불가
     *
     * `is_disposed=true`(폐기)는 검증 우회.
     * 캐시 컬럼(`sale_unpaid_amount_krw_cache`) 직접 사용 — accessor의 relations 의존성 회피.
     * 신규 차량(cache=null)은 미입금 자체가 없어 C5 skip.
     */
    public function guardStageOrderForExport(): void
    {
        if ($this->is_disposed) {
            return;
        }
        if ($this->sales_channel !== 'export') {
            return;
        }

        $hasExportInput = $this->export_buyer_id
            || $this->shipping_date
            || $this->export_declaration_document
            || $this->bl_loading_location
            || $this->bl_document
            || $this->dhl_request;

        if (! $hasExportInput) {
            return;
        }

        // C4 — 말소 완료 강제
        if (! $this->is_deregistered || ! $this->deregistration_document) {
            throw ValidationException::withMessages([
                'export_buyer_id' => '말소 처리(체크 + 서류 업로드)를 완료한 후 통관 진입이 가능합니다.',
            ]);
        }

        // C5 — 판매 미입금 잔존 차단. cache 컬럼 우선 사용 (accessor 회피).
        // 큐 2.6 — admin이 unpaid_export_overrides에 해당 stage 승인 레코드를 만들었으면 skip.
        $unpaidCache = $this->sale_unpaid_amount_krw_cache;
        if ($this->sale_price > 0 && $unpaidCache !== null && $unpaidCache > 0) {
            $stage = $this->dhl_request
                ? 'dhl'
                : (($this->bl_loading_location || $this->bl_document) ? 'shipping' : 'clearance');

            $hasOverride = $this->exists && $this->hasUnpaidOverride($stage);
            if (! $hasOverride) {
                throw ValidationException::withMessages([
                    'export_buyer_id' => "판매 미입금이 남은 차량은 {$stage} 단계 진입이 불가합니다. 입금 완료 또는 관리자 승인(미입금 우회) 후 진행하세요.",
                ]);
            }
        }
    }

    /**
     * 잔금 / 회수 이력 변경으로 잔액 의존 캐시가 바뀌었을 때 호출.
     * Eloquent saving 이벤트를 우회하고 컬럼만 직접 갱신해 무한 루프 방지.
     */
    public function refreshCaches(): void
    {
        $this->refresh();
        DB::table('vehicles')->where('id', $this->id)->update([
            'progress_status_cache' => $this->progress_status,
            'receivable_risk' => $this->receivable_risk_computed,
            'sale_unpaid_amount_krw_cache' => ($krw = $this->sale_unpaid_amount_krw) !== null ? (int) round($krw) : null,
        ]);
    }

    /**
     * @deprecated refreshCaches() 사용. 외부 호출자 호환성을 위해 alias로 유지.
     */
    public function refreshProgressCache(): void
    {
        $this->refreshCaches();
    }

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

    public function receivableHistories(): HasMany
    {
        return $this->hasMany(ReceivableHistory::class);
    }

    public function receivableManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'receivable_manager_id');
    }

    public function unpaidExportOverrides(): HasMany
    {
        return $this->hasMany(UnpaidExportOverride::class);
    }

    /**
     * 큐 2.6 — 특정 단계에 대한 admin 미입금 우회 승인 여부.
     * unpaid_export_overrides에 해당 stage 레코드가 1건 이상 있으면 true.
     */
    public function hasUnpaidOverride(string $stage): bool
    {
        return $this->unpaidExportOverrides()
            ->where('stage', $stage)
            ->exists();
    }

    // ── Computed: 진행상태 11단계 ───────────────────────────────────
    // C3 — 통관·선적·DHL 단계는 sales_channel='export' 차량만 평가.
    // 큐 2.6 — rule_version 분기. v1=단일 트리거(grandfather) / v2=이중 트리거 강화.
    //   v2 이중 트리거 (캐스케이드 — 다음 단계 진입 = 이전 단계 트리거 + 현재 단계 트리거):
    //     #5 수출통관완료 = is_export_cleared && export_declaration_document
    //     #4 선적중       = is_export_cleared && bl_loading_location
    //     #3 선적완료     = bl_loading_location && bl_document
    //     #2 거래완료     = bl_document && dhl_request
    public function getProgressStatusAttribute(): string
    {
        if ($this->is_disposed) {
            return '폐기';
        }

        $isExport = $this->sales_channel === 'export';
        $v2 = ((int) ($this->progress_status_rule_version ?? 2)) >= 2;

        // 거래완료(dhl_request) / 선적 / 통관 단계 — export 채널만 진입 가능
        if ($isExport) {
            if ($v2) {
                if ($this->dhl_request && $this->bl_document) {
                    return '거래완료';
                }
                if ($this->bl_document && $this->bl_loading_location) {
                    return '선적완료';
                }
                if ($this->bl_loading_location && $this->is_export_cleared) {
                    return '선적중';
                }
                if ($this->is_export_cleared && $this->export_declaration_document) {
                    return '수출통관완료';
                }
                if ($this->export_buyer_id && $this->shipping_date) {
                    return '수출통관중';
                }
            } else {
                // v1 grandfather — 큐 2.6 마이그 이전 row. 단일 트리거 그대로 평가.
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
            }
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
    // 적립금 사용(savings_used)은 미납 차감에 포함하지 않는다.
    // 적립금은 별도 관리 항목 (Buyer×currency 잔액 추적은 Vehicle::saved 훅에서 유지).
    public function getSaleUnpaidAmountAttribute(): float
    {
        $totalSale = $this->sale_price + $this->transport_fee + $this->sale_other_costs
            + $this->commission + $this->auto_loading - $this->tax_dc;

        $totalReceived = $this->deposit_down_payment + $this->interim_payment
            + $this->advance_payment1 + $this->advance_payment2
            + $this->finalPayments->sum('amount')
            + $this->receivableHistories->where('method', '!=', 'deposit')->sum('amount');

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

    // ── Computed: 채권기준금액 (판매합계 — 통화 단위) ───────────────
    public function getSaleTotalAmountAttribute(): float
    {
        return (float) (
            $this->sale_price + $this->transport_fee + $this->sale_other_costs
            + $this->commission + $this->auto_loading - $this->tax_dc
        );
    }

    // ── Computed: 미납 비율 (게이지·판매탭 % 표시용, 0~1 또는 null) ──
    // 분자 = sale_unpaid_amount (KPI·채권관리와 동일 출처)
    // 분모 = sale_total_amount
    // sale_total_amount <= 0 (매입중·말소완료 등 판매 전) → null = 게이지 미표시
    public function getUnpaidRatioAttribute(): ?float
    {
        $total = (float) $this->sale_total_amount;
        if ($total <= 0) {
            return null;
        }
        $unpaid = (float) $this->sale_unpaid_amount;
        if ($unpaid <= 0) {
            return 0.0;
        }

        return max(0.0, min(1.0, $unpaid / $total));
    }

    // ── Computed: 미납액 원화 환산 (KPI 합산용) ─────────────────────
    public function getSaleUnpaidAmountKrwAttribute(): ?float
    {
        $unpaid = $this->sale_unpaid_amount;
        if ($this->currency === 'KRW') {
            return (float) $unpaid;
        }
        if (! $this->exchange_rate) {
            return null;
        }

        return (float) ($unpaid * $this->exchange_rate);
    }

    /**
     * Computed 채권 위험도. DB 컬럼 receivable_risk와는 다른 이름으로
     * 구분 — 컬럼은 캐시(SQL 필터용), 이건 실시간 계산값.
     *
     * 코드: safe / caution / danger / critical / none
     */
    public function getReceivableRiskComputedAttribute(): string
    {
        $total = $this->sale_total_amount;
        if ($total <= 0) {
            return 'none';
        }

        $unpaid = $this->sale_unpaid_amount;

        // BL 발행 + 미납 잔존 → 즉시 critical (계산식codex.txt 잠정 규칙)
        if ($this->bl_document && $unpaid > 0) {
            return 'critical';
        }

        if ($unpaid <= 0) {
            return 'safe';
        }

        $ratio = ($unpaid / $total) * 100;

        return match (true) {
            $ratio <= 50 => 'caution',
            $ratio <= 70 => 'danger',
            default => 'critical',
        };
    }

    /**
     * UI 라벨 (한국어). 캐시된 receivable_risk 컬럼을 사용.
     */
    public function getReceivableRiskLabelAttribute(): string
    {
        return match ($this->receivable_risk) {
            'safe' => '안전',
            'caution' => '주의',
            'danger' => '위험',
            'critical' => '심각',
            default => '-',
        };
    }

    /**
     * 대시보드 카드 카운트와 vehicles 목록 SQL where를 동일 헬퍼로 통일.
     * SKILLS.md §9 100% 일치 원칙. Laravel local scope — `Vehicle::action('foo')` 또는
     * `$query->action('foo')`로 체이닝. 호출자에서 salesman_id·채널·날짜 등 추가 필터 자유 chain.
     *
     * 14 액션 (영업 5 / 통관 7 / 정산 5) + 관리자 2 = 16 케이스.
     * 통관·선적·DHL 액션은 sales_channel='export' 격리.
     *
     * 주의: `clearance_needed`(영업 라벨) ≡ `clearance_request_needed`(통관 라벨),
     *      `dhl_needed`(영업) ≡ `dhl_dispatch_needed`(통관)는 동일 SQL이고 라벨만 다름.
     *      role별 화면에서 맥락에 맞는 라벨 노출을 위해 의도적으로 별도 키 유지 — 통합 금지.
     */
    public function scopeAction(Builder $q, string $action): Builder
    {
        // active 한정 액션: is_disposed=false AND dhl_request=false
        // 정산 액션 중 settlement_*·receivable_risk는 거래완료·잔여 미수금 대상이라 active 제외
        $activeOnly = [
            'purchase_unpaid', 'sale_unpaid', 'clearance_needed', 'shipping_needed', 'dhl_needed',
            'clearance_request_needed', 'clearance_info_missing', 'forwarding_missing',
            'export_declaration_upload_needed', 'shipping_process_needed', 'bl_upload_needed', 'dhl_dispatch_needed',
            'exchange_rate_missing', 'clearance_stuck',
            // receivable_* 액션은 active 제한 X — 거래완료 차량도 미수금 가능 (위험도는 단계 무관)
        ];
        if (in_array($action, $activeOnly, true)) {
            $q->where('is_disposed', false)->where('dhl_request', false);
        }

        return match ($action) {
            // ── 영업 role (5) ──
            'purchase_unpaid' => $q
                ->where('purchase_price', '>', 0)
                // CAST AS SIGNED — BIGINT UNSIGNED 컬럼의 빼기 결과가 음수면 underflow.
                // 매입가/매도비 < 지급 합계가 가능 (선지급·환불 케이스) → SIGNED로 평가.
                ->whereRaw('(CAST(purchase_price AS SIGNED) + CAST(selling_fee AS SIGNED)
                             - CAST(down_payment AS SIGNED) - CAST(selling_fee_payment AS SIGNED)
                             - COALESCE((SELECT SUM(amount) FROM purchase_balance_payments
                                          WHERE vehicle_id = vehicles.id
                                          AND payment_date IS NOT NULL AND payment_date <= ?), 0)) > 0', [now()->toDateString()]),
            'sale_unpaid' => $q
                ->where('sale_price', '>', 0)
                ->where(fn ($q2) => $q2
                    ->where('sale_unpaid_amount_krw_cache', '>', 0)
                    ->orWhereNull('sale_unpaid_amount_krw_cache')),
            'clearance_needed' => $q
                ->where('sales_channel', 'export')
                ->where('sale_price', '>', 0)
                ->whereNotNull('sale_unpaid_amount_krw_cache')
                ->where('sale_unpaid_amount_krw_cache', '<=', 0)
                ->whereNull('export_declaration_document'),
            'shipping_needed' => $q
                ->where('sales_channel', 'export')
                ->whereNotNull('export_declaration_document')
                ->whereNull('bl_document'),
            'dhl_needed' => $q
                ->where('sales_channel', 'export')
                ->whereNotNull('bl_document'),

            // ── 통관 role (7) — 모두 export 채널 ──
            'clearance_request_needed' => $q
                ->where('sales_channel', 'export')
                ->where('sale_price', '>', 0)
                ->whereNotNull('sale_unpaid_amount_krw_cache')
                ->where('sale_unpaid_amount_krw_cache', '<=', 0)
                ->whereNull('export_declaration_document'),
            'clearance_info_missing' => $q
                ->where('sales_channel', 'export')
                ->where('sale_price', '>', 0)
                ->where(fn ($q2) => $q2
                    ->whereNull('export_buyer_id')
                    ->orWhereNull('shipping_date')),
            'forwarding_missing' => $q
                ->where('sales_channel', 'export')
                ->whereNotNull('export_buyer_id')
                ->whereNotNull('shipping_date')
                ->whereNull('forwarding_company_id'),
            'export_declaration_upload_needed' => $q
                ->where('sales_channel', 'export')
                ->whereNotNull('export_buyer_id')
                ->whereNotNull('shipping_date')
                ->whereNull('export_declaration_document'),
            'shipping_process_needed' => $q
                ->where('sales_channel', 'export')
                ->whereNotNull('export_declaration_document')
                ->whereNull('bl_loading_location'),
            'bl_upload_needed' => $q
                ->where('sales_channel', 'export')
                ->whereNotNull('bl_loading_location')
                ->whereNull('bl_document'),
            'dhl_dispatch_needed' => $q
                ->where('sales_channel', 'export')
                ->whereNotNull('bl_document'),

            // 큐 4 8-7 — 통관 정체 (admin 대시보드 stuck_count와 SQL 100% 일치).
            // 판매완료(unpaid<=0 OR NULL) + 수출신고서 NULL + sale_date 30일 경과.
            'clearance_stuck' => $q
                ->where('sales_channel', 'export')
                ->where('sale_price', '>', 0)
                ->where(fn ($q2) => $q2
                    ->whereNull('sale_unpaid_amount_krw_cache')
                    ->orWhere('sale_unpaid_amount_krw_cache', '<=', 0))
                ->whereNull('export_declaration_document')
                ->whereNotNull('sale_date')
                ->where('sale_date', '<=', now()->subDays(30)->toDateString()),

            // ── 정산 role (5) ──
            'exchange_rate_missing' => $q
                ->where('currency', '!=', 'KRW')
                ->where('sale_price', '>', 0)
                ->where(fn ($q2) => $q2
                    ->whereNull('exchange_rate')
                    ->orWhere('exchange_rate', 0)),
            'settlement_create_needed' => $q
                ->where('dhl_request', true)
                ->whereDoesntHave('settlements'),
            'settlement_confirm_needed' => $q
                ->whereHas('settlements', fn ($q2) => $q2->where('settlement_status', 'pending')),
            'settlement_pay_needed' => $q
                ->whereHas('settlements', fn ($q2) => $q2->where('settlement_status', 'confirmed')),
            'receivable_risk' => $q
                ->whereIn('receivable_risk', ['danger', 'critical']),

            // 큐 4 8-6 — 채권 위험도 카드별 vehicles 라우팅 (admin 대시보드 receivableKpis와 SQL 100% 일치).
            // 미수금 캐시 NULL은 환율 미입력 외화 → 통계 제외 (카운트 정책과 동일).
            'receivable_safe', 'receivable_caution', 'receivable_danger', 'receivable_critical' => $q
                ->where('receivable_risk', str_replace('receivable_', '', $action))
                ->where('sale_unpaid_amount_krw_cache', '>', 0),

            // ── 관리자 액션 ──
            'has_sale' => $q->where('sale_price', '>', 0),
            'has_purchase' => $q->where('purchase_price', '>', 0),

            default => $q,
        };
    }
}
