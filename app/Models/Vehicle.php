<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class Vehicle extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'vehicle_number', 'sales_channel', 'progress_status_cache',
        'progress_status_rule_version', 'is_override_active',
        'receivable_risk', 'sale_unpaid_amount_krw_cache', 'receivable_manager_id',
        // 큐 16 — 헤이맨/카풀 5컬럼 drop (tax_invoice_1·2_date·amount, agency_fee).
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
        // 큐 20-A — 매입처 계좌 4컬럼 (purchase_seller_account encrypted)
        'purchase_seller_bank', 'purchase_seller_account', 'purchase_seller_holder', 'purchase_bank_memo',
        'cost_deregistration', 'cost_license', 'cost_towing', 'cost_carry',
        'cost_shoring', 'cost_insurance', 'cost_transfer', 'cost_extra1', 'cost_extra2',
        // 큐 22-C-E (2026-05-20) — down_payment / selling_fee_payment DROP.
        // 2컬럼은 purchase_balance_payments.type enum (down/selling_fee) 로 통합.
        'purchase_remittance_memo',
        'is_deregistered', 'deregistration_document',
        'sale_date', 'currency', 'exchange_rate', 'buyer_id', 'consignee_id',
        'sale_price', 'tax_dc', 'commission', 'transport_fee', 'auto_loading',
        // 큐 22-A-3 (2026-05-20) — deposit_down_payment / interim_payment / advance_payment1 / advance_payment2 DROP.
        // 4컬럼은 final_payments.type enum (deposit_down/interim/advance_1/advance_2) 로 통합.
        'sale_other_costs', 'savings_used',
        'export_buyer_id', 'export_consignee_id', 'forwarding_company_id',
        'export_declaration_amount', 'shipping_date', 'eta_date', 'shipping_method',
        'port_of_loading', 'incoterms', 'discharge_port_id',
        'export_declaration_document', 'export_declaration_number', 'is_export_cleared',
        'forwarding_email_sent',
        'bl_buyer_id', 'bl_consignee_id', 'bl_number', 'container_number',
        'bl_loading_location', 'vessel_name', 'bl_document', 'bl_issue_date',
        'dhl_recipient_name', 'dhl_recipient_address', 'dhl_recipient_phone',
        'dhl_sender_name', 'dhl_sender_address', 'dhl_weight', 'dhl_dimensions',
        'dhl_request', 'memo',
    ];

    protected $casts = [
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
        'nice_reg_owner_rrn_encrypted_at' => 'datetime',
        // 큐 20-A — 매입처 계좌번호 자동 암호화 (Laravel Crypt — AES-256-CBC)
        'purchase_seller_account' => 'encrypted',
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
        // 빈 값 정규화
        $value = ($value === null || $value === '') ? null : $value;

        // 큐 11-4: 동일 평문 재할당 시 재암호화 skip.
        // - 매 save마다 random IV로 ciphertext가 달라져 wasChanged()가 false-positive
        // - audit_logs에서 RRN 변경을 정확히 감지하려면 무변동 케이스를 거른다
        $current = $this->getAttribute('nice_reg_owner_rrn');
        if ($value === $current) {
            return;
        }

        if ($value === null) {
            $this->attributes['nice_reg_owner_rrn'] = null;
            $this->attributes['nice_reg_owner_rrn_encrypted_at'] = null;

            return;
        }
        $this->attributes['nice_reg_owner_rrn'] = Crypt::encryptString($value);
        $this->attributes['nice_reg_owner_rrn_encrypted_at'] = now();
    }

    /**
     * 큐 14-4-4 — 같은 바이어 미수 + 신규 거래 검사 (G2).
     *
     * buyer_id 동일 + 미수 잔존(sale_unpaid_amount_krw_cache > 0) 차량 있는 상태에서
     * 신규 차량 등록 시 차단. 영업은 [신규 거래 승인 요청] → 관리 승인 후 등록 재시도.
     *
     * "1 승인 = 1 차량" — 승인은 payload['new_vehicle_number']에 바인딩됨.
     * 승인받은 차량번호와 다른 번호로 저장 시 차단 (보안 결함 수정).
     *
     * 호출 위치: Vehicle::saving 가드. 시드·artisan은 auth()->check() false로 우회.
     */
    public function guardSameBuyerOverlap(): void
    {
        if (! auth()->check() || auth()->user()->canApprove()) {
            return;   // 시드/canApprove는 우회
        }
        if (! $this->buyer_id) {
            return;   // buyer 미지정 — 일반 신규 등록 흐름
        }
        if ($this->exists) {
            return;   // 기존 차량 수정 — 신규 등록만 검사
        }

        $hasOverlap = self::where('buyer_id', $this->buyer_id)
            ->where('sale_unpaid_amount_krw_cache', '>', 0)
            ->whereNull('deleted_at')
            ->exists();

        if (! $hasOverlap) {
            return;
        }

        $currentVehicleNumber = trim((string) $this->vehicle_number);

        // 활성(approved + unused) inter_buyer_overlap 승인 — 차량번호까지 매칭되는 것만
        $activeApproval = ApprovalRequest::where('action_type', ApprovalRequest::TYPE_INTER_BUYER_OVERLAP)
            ->where('target_type', Buyer::class)
            ->where('target_id', $this->buyer_id)
            ->where('status', ApprovalRequest::STATUS_APPROVED)
            ->whereNull('used_at')
            ->latest('id')
            ->get()
            ->first(function (ApprovalRequest $req) use ($currentVehicleNumber) {
                $boundNumber = trim((string) ($req->payload['new_vehicle_number'] ?? ''));

                return $boundNumber !== '' && $boundNumber === $currentVehicleNumber;
            });

        if (! $activeApproval) {
            // 같은 buyer로 다른 차량번호에 묶인 승인이 있는지 확인 — 메시지 분기용
            $mismatchApproval = ApprovalRequest::where('action_type', ApprovalRequest::TYPE_INTER_BUYER_OVERLAP)
                ->where('target_type', Buyer::class)
                ->where('target_id', $this->buyer_id)
                ->where('status', ApprovalRequest::STATUS_APPROVED)
                ->whereNull('used_at')
                ->latest('id')
                ->first();

            if ($mismatchApproval) {
                $bound = $mismatchApproval->payload['new_vehicle_number'] ?? '(미지정)';
                throw ValidationException::withMessages([
                    'buyer_id' => "이 승인은 차량번호 '{$bound}'에 대한 것입니다. 현재 '{$currentVehicleNumber}'로 저장 시도. 차량번호를 일치시키거나 새 승인 요청을 보내세요.",
                ]);
            }

            throw ValidationException::withMessages([
                'buyer_id' => '이 바이어는 미수 잔존 차량이 있습니다. 신규 거래는 관리자 승인이 필요합니다. [신규 거래 승인 요청] 버튼을 사용하세요.',
            ]);
        }

        // 승인 소진 — 차량 saving 직전에 used_at 마킹 (saved 훅에서 처리하면 트랜잭션 분리됨)
        $activeApproval->update(['used_at' => now()]);
    }

    /**
     * 큐 9 확장 — G1 50% B/L 잠금 (회의록 2026-05-14 §G1, SKILLS §13 단일 게이트).
     *
     * 룰: `bl_document` 신규 첨부 시 `unpaid_ratio > 0.5`면 차단.
     * 잔금 50% 이상 입금 후 B/L 발행 가능.
     *
     * 분기:
     *   ① grandfather — 기존에 bl_document 있던 차량은 모든 변경 통과 (수정·교체·삭제 포함)
     *      (사용자 결정 2026-05-18: 이미 운영중 차량은 우회)
     *   ② 환율 미입력 외화 차량(unpaid_ratio = null) → 별도 메시지로 차단
     *   ③ admin `unpaid_export_override` (stage='shipping') 승인 있으면 우회 (큐 2.6 인프라 재사용)
     *
     * 호출 위치: saving 훅 (시드·artisan auth 없으면 우회).
     */
    public function guardBlFiftyPercentRuleOnSaving(): void
    {
        if (! auth()->check()) {
            return;   // 시드/artisan
        }
        if (! $this->isDirty('bl_document')) {
            return;   // bl_document 변경 없음
        }

        $original = $this->getOriginal('bl_document');
        $current = $this->bl_document;

        // grandfather: 기존에 bl_document가 있었으면 통과 (수정·교체·삭제 모두)
        if (! empty($original)) {
            return;
        }

        // null → null 또는 새로 빈 값 — 검사 대상 외
        if (empty($current)) {
            return;
        }

        // 신규 첨부 (null → not null) — G1 평가
        $ratio = $this->unpaid_ratio;

        if ($ratio === null) {
            // 환율 미입력 외화 차량
            throw ValidationException::withMessages([
                'bl_document' => 'B/L 발행 전 환율 입력 필수입니다. 외화 차량 환율 미입력으로 미수율 평가 불가.',
            ]);
        }

        if ($ratio <= 0.5) {
            return;
        }

        // 50% 룰 위반 — admin 미입금 우회 승인 확인 (shipping 단계)
        if ($this->hasUnpaidOverride('shipping')) {
            return;
        }

        $percent = number_format($ratio * 100, 1);
        throw ValidationException::withMessages([
            'bl_document' => "B/L 발행 차단 — 미수율 {$percent}% (50% 초과). 잔금 50% 이상 입금 후 발행 가능. 또는 관리자 미입금 우회 승인(선적 단계) 필요.",
        ]);
    }

    /**
     * 큐 21 — Ledger 영향 컬럼 잠금 가드.
     *
     * 회의록 2026-05-18 vehicle-ledger-field-lock — 사용자 최종 결정 반영:
     *   ① 트리거 = confirmed FinalPayment OR PurchaseBalancePayment 1건 이상
     *   ② 잠금 컬럼 = LEDGER_LOCK_FIELDS (Tier 1·2 통합 21컬럼)
     *   ③ 잠금 해제 권한 = admin + super (User::canAccessAdmin)
     *   ④ 사유 10자 이상 + 저장 1회 완료 즉시 재잠금 (cache token pull 패턴)
     *
     * 신규 차량 등록(exists=false)은 자유 — 잔금 자체가 없음.
     * 시드·artisan(auth 없음)은 우회 — 도메인 시뮬레이션.
     * VehicleLedgerUnlockService::unlock 으로 발급된 cache token이 있으면
     * 1회 소비 후 통과 + AuditLog 자동 기록 (saving 훅 통합 — updated 훅의 recordChange와 중복 회피).
     */
    public function guardLedgerLockOnSaving(): void
    {
        if (! $this->exists) {
            return;   // 신규 차량 — 자유 입력
        }
        if (! auth()->check()) {
            return;   // 시드/artisan
        }

        // isDirty()는 Eloquent strcmp 기반이라 numeric 컬럼에서 false-positive 발생.
        // 예: DB int 1000000 vs 폼 float 1000000.0 → strcmp("1000000","1000000.0") ≠ 0 → dirty=true.
        // 실제 값 차이만 잡기 위해 정밀 비교 적용 (사용자 검증 2026-05-18 발견).
        //
        // 또한 운영 흐름상 "빈 값(0/null) → 첫 입력"은 retroactive 변경이 아니라 최초 set이므로 통과.
        // 예: 매입 잔금 confirm 후 영업이 판매가·바이어 처음 입력하는 정상 흐름 보호.
        // 일단 값이 set된 이후의 변경은 차단 (회의 의도 = retroactive 보호).
        //
        // ⚠️ DB 컬럼이 decimal(15,2)이면 "0.00" string으로 오므로 strict === 비교로는 0 인정 안 됨.
        // numeric 절대값 비교로 강화 (사용자 검증 2026-05-18 재발견).
        $isEmpty = fn ($v) => $v === null || $v === ''
            || (is_numeric($v) && abs((float) $v) < 0.0001);

        $dirtyLocked = [];
        foreach (self::LEDGER_LOCK_FIELDS as $field) {
            if (! $this->isDirty($field)) {
                continue;
            }
            $original = $this->getOriginal($field);
            $current = $this->getAttribute($field);

            // 빈 값 ↔ 빈 값 (PHP 형변환 차이 흡수)
            if ($isEmpty($original) && $isEmpty($current)) {
                continue;
            }

            // 빈 값 → 신규 입력 (최초 set — 운영 정상 흐름 보호)
            if ($isEmpty($original)) {
                continue;
            }

            // numeric은 float 절대차 비교
            if (is_numeric($original) && is_numeric($current)) {
                if (abs((float) $original - (float) $current) < 0.0001) {
                    continue;
                }
            } elseif ((string) $original === (string) $current) {
                continue;
            }

            $dirtyLocked[] = $field;
        }
        if (empty($dirtyLocked)) {
            return;   // 잠금 컬럼 변경 없음
        }

        if (! $this->hasConfirmedPaymentLock()) {
            return;   // confirmed 잔금 없음 — 자유 수정
        }

        // unlock 토큰 1회 소비 시도
        $token = $this->consumeLedgerUnlockToken();
        if ($token !== null) {
            // 통과 — AuditLog는 booted updated 훅의 recordChange가 처리.
            // unlock 자체 이벤트는 VehicleLedgerUnlockService에서 별도 기록(ledger_field_unlocked).
            return;
        }

        // 차단
        throw ValidationException::withMessages([
            $dirtyLocked[0] => '재무 확정 잔금이 있는 차량의 회계 영향 필드는 잠금 해제 후 수정 가능합니다. 시도된 필드: '.implode(', ', $dirtyLocked).'. admin/super가 [🔓 잠금 해제] 버튼으로 사유 입력 후 1회 변경할 수 있습니다.',
        ]);
    }

    /**
     * 큐 21 — confirmed 잔금 존재 여부 (잠금 트리거).
     * finalPayments OR purchaseBalancePayments 중 confirmed_at IS NOT NULL 1건이라도 있으면 true.
     */
    public function hasConfirmedPaymentLock(): bool
    {
        return $this->finalPayments()->whereNotNull('confirmed_at')->exists()
            || $this->purchaseBalancePayments()->whereNotNull('confirmed_at')->exists();
    }

    /**
     * 큐 21 — Ledger unlock 토큰 1회 소비 (저장 1회 후 즉시 재잠금).
     * VehicleLedgerUnlockService::unlock 으로 발급된 cache key를 pull (읽기 + 즉시 삭제).
     * 동일 차량을 추가 수정하려면 다시 잠금 해제 필요.
     */
    public function consumeLedgerUnlockToken(): ?array
    {
        if (! $this->id) {
            return null;
        }

        return Cache::pull(self::ledgerUnlockCacheKey($this->id));
    }

    /**
     * 큐 21 — Cache key 단일 출처. Service / Component / Model 모두 동일 키 사용.
     */
    public static function ledgerUnlockCacheKey(int $vehicleId): string
    {
        return "vehicle_ledger_unlock:{$vehicleId}";
    }

    /**
     * 큐 11-4 G7 — 감사 로그 추적 컬럼 (Vehicle 기준).
     * settlement_status / paid_at는 Settlement 모델에서 별도 추적.
     *
     * 큐 21 — 회계 영향 컬럼(LEDGER_LOCK_FIELDS) 변경 추적 확장.
     * 잠금 해제 후 변경은 반드시 AuditLog 기록 — Specialist F 권고.
     */
    public const AUDITED_COLUMNS = [
        // 기존
        'sale_price',
        'progress_status_cache',
        'nice_reg_owner_rrn',                  // 마스킹 — value 미저장
        // 큐 22-A-3 — 4컬럼(deposit_down_payment / interim_payment / advance_payment1 / advance_payment2) DROP.
        // 변경 추적은 이제 final_payments rows 단위 (FinalPayment 모델 events).
        // 큐 22-C-E (2026-05-20) — down_payment / selling_fee_payment DROP.
        // 추적은 purchase_balance_payments rows 단위 (PBP 모델 events).
        'savings_used',
        // 큐 21 — 회계 영향 컬럼 (LEDGER_LOCK_FIELDS와 동일)
        'purchase_price', 'selling_fee', 'tax_dc', 'commission',
        'transport_fee', 'auto_loading', 'sale_other_costs', 'exchange_rate',
        'export_declaration_amount',
        'cost_deregistration', 'cost_license', 'cost_towing', 'cost_carry',
        'cost_shoring', 'cost_insurance', 'cost_transfer', 'cost_extra1', 'cost_extra2',
        'buyer_id', 'salesman_id',
        // 2026-05-19 풀회의 P0-3 — 말소 처리 actor 책임 추적 (4 role 누구나 처리 시 감사 필수).
        'is_deregistered', 'deregistration_document',
        // 큐 22-C-light (2026-05-20) Security 해소조건 — 매입처 계좌 4컬럼 변경 audit.
        // purchase_seller_account는 AuditLog::MASKED_COLUMNS 통해 마스킹 저장.
        'purchase_seller_bank', 'purchase_seller_account', 'purchase_seller_holder', 'purchase_bank_memo',
    ];

    /**
     * 큐 21 — Ledger 영향 잠금 컬럼.
     * 회의록 2026-05-18 — confirmed FinalPayment OR PurchaseBalancePayment 1건 이상 존재 시
     * 본 컬럼 변경은 admin/super 잠금 해제 후 1회만 가능 (저장 직후 자동 재잠금).
     *
     * Tier 1 (금액 직결) + Tier 2 (관계 식별 buyer_id·salesman_id) 통합 (사용자 결정 2026-05-18).
     */
    public const LEDGER_LOCK_FIELDS = [
        // Tier 1 — 금액 직결 19컬럼
        'purchase_price', 'selling_fee', 'sale_price', 'tax_dc', 'commission',
        'transport_fee', 'auto_loading', 'sale_other_costs', 'exchange_rate',
        'export_declaration_amount',
        'cost_deregistration', 'cost_license', 'cost_towing', 'cost_carry',
        'cost_shoring', 'cost_insurance', 'cost_transfer', 'cost_extra1', 'cost_extra2',
        // Tier 2 — 관계 식별
        'buyer_id', 'salesman_id',
    ];

    // ── Boot: 진행상태/채권 캐시 자동 갱신 ─────────────────────────
    protected static function booted(): void
    {
        static::saving(function (Vehicle $vehicle) {
            // 2026-05-20 사용자 정정 — KRW 통화 시 환율 자동 1 normalize.
            // "한국돈인데 환율 쓸 필요 없음" 직관 보존 + DB CHECK (sale_price > 0 시 exchange_rate > 0) 통과.
            // 진입점 통합 (UI 폼·시드·factory·tinker 모두 동일 정책).
            if ($vehicle->currency === 'KRW' && (float) $vehicle->exchange_rate !== 1.0) {
                $vehicle->exchange_rate = 1;
            }

            // 2026-05-21 사용자 결정 — 면장금액 = sale_price 자동 복사 (미입력 시).
            // 통상 인보이스 금액 = 면장 신고가. 사용자가 별도 입력 안 해도 sale_price 그대로 적용.
            // 명시 입력 시 (CIF/FOB 인코텀즈 차이 등) 그 값 우선 — 현재 값이 빈 경우만 자동 채움.
            if (
                (float) ($vehicle->export_declaration_amount ?? 0) <= 0
                && (float) ($vehicle->sale_price ?? 0) > 0
            ) {
                $vehicle->export_declaration_amount = $vehicle->sale_price;
            }

            // 큐 21 — Ledger 영향 컬럼 잠금 가드 (캐시 갱신 전 최우선 검사).
            // 재무 확정 잔금 있는 차량의 매입가·판매가·환율·면장금액·비용·바이어·담당자 변경은
            // admin/super 잠금 해제 후 1회만 통과 (cache token 1회 소비 → 즉시 재잠금).
            $vehicle->guardLedgerLockOnSaving();

            // 큐 9 확장 — G1 50% B/L 잠금 (SKILLS §13 단일 게이트).
            // bl_document 신규 첨부 시 unpaid_ratio > 0.5면 차단. grandfather + admin 우회 분기.
            $vehicle->guardBlFiftyPercentRuleOnSaving();

            // 큐 14-4-4 — G2 같은 바이어 미수 + 신규 거래 가드.
            // 신규 등록 시 같은 buyer + 미수 잔존 차량 있으면 ApprovalRequest 필요.
            $vehicle->guardSameBuyerOverlap();

            // 캐시 자동 갱신 — 시드·UI 저장 모두 발동.
            // C4·C5 단계 의존성 검증은 saving 이벤트가 아닌 UI save() 흐름에서만
            // (Vehicle::guardStageOrderForExport()를 vehicles/index::save()가 명시 호출)
            // 시드는 도메인 시뮬레이션이라 검증 우회. UI 사용자 입력만 차단 대상.
            $vehicle->progress_status_cache = $vehicle->progress_status;
            $vehicle->receivable_risk = $vehicle->receivable_risk_computed;
            $krw = $vehicle->sale_unpaid_amount_krw;
            $vehicle->sale_unpaid_amount_krw_cache = $krw !== null ? (int) round($krw) : null;
        });

        // 큐 21 — confirmed 잔금 있는 차량 삭제는 admin/super 전용 (Specialist E 권고).
        // soft delete · force delete 둘 다 적용. 시드·artisan(auth 없음)은 우회.
        static::deleting(function (Vehicle $vehicle) {
            if (! auth()->check()) {
                return;   // 시드/artisan 우회
            }
            if (auth()->user()->canAccessAdmin()) {
                return;   // admin/super 우회
            }
            if ($vehicle->hasConfirmedPaymentLock()) {
                throw new \DomainException('재무 확정 잔금이 있는 차량은 admin/super만 삭제할 수 있습니다.');
            }
        });

        // hard delete (forceDelete) 시 첨부 디렉토리를 즉시 삭제하지 않고
        // storage/backups/deleted/{id}-{timestamp}/ 로 이동 (큐 11-2).
        // soft delete는 첨부 유지 — 복구 가능성 보호.
        // 운영 사고 시 storage/backups/deleted/ 에서 수동 복구 가능.
        static::forceDeleted(function (Vehicle $vehicle) {
            $publicSource = storage_path("app/public/vehicles/{$vehicle->id}");
            if (is_dir($publicSource)) {
                $timestamp = now()->format('Ymd_His');
                $backupDir = storage_path("backups/deleted/{$vehicle->id}-{$timestamp}");
                File::ensureDirectoryExists(dirname($backupDir));
                File::moveDirectory($publicSource, $backupDir);
            }
            AuditLog::recordEvent($vehicle, 'force_deleted');
        });

        // H7 — soft-delete 후 restore 시 캐시 stale 가능. 복구 직후 재계산.
        static::restored(function (Vehicle $vehicle) {
            $vehicle->refreshCaches();
            AuditLog::recordEvent($vehicle, 'restored');
        });

        // 큐 11-4 — 라이프사이클 + 컬럼 변경 감사 로그.
        static::created(fn (Vehicle $vehicle) => AuditLog::recordEvent($vehicle, 'created'));
        static::deleted(function (Vehicle $vehicle) {
            // SoftDeletes에서 소프트 삭제·강제 삭제 모두 deleted 발동 → forceDeleting과 분리.
            if (! $vehicle->isForceDeleting()) {
                AuditLog::recordEvent($vehicle, 'deleted');
            }
        });
        static::updated(function (Vehicle $vehicle) {
            foreach (self::AUDITED_COLUMNS as $col) {
                if ($vehicle->wasChanged($col)) {
                    AuditLog::recordChange(
                        $vehicle,
                        $col,
                        $vehicle->getOriginal($col),
                        $vehicle->getAttribute($col),
                    );
                }
            }
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

        // 큐 22-C-light (2026-05-20) — 매입 자동 PBP Draft 생성.
        // 사용자 명세: "영업이 매입가·계좌 입력 → 재무 뱃지 → 재무 입금"
        //   - Trigger: purchase_price > 0 AND PBP 0건 (최초 1회)
        //   - 매입가 변경 시 재생성 X (PO 우려 회피, 영업이 수동 정정)
        //   - amount = 실 미지급 전액 (글로벌 표준 SAP/NetSuite/Odoo/QuickBooks)
        //   - payment_date = 매입일 (사용자 정정 2026-05-20 — 매입 탭 자금 영역 disabled 라 영업이 수정 불가)
        //   - confirmed_at = NULL (Draft form)
        //   - Skip: auth 없음(시드/artisan), 실 미지급 ≤ 0, paid Settlement 차량
        // PBP::saved 훅의 refreshCaches는 DB::table::update로 saving 우회 → 무한 루프 X.
        static::saved(function (Vehicle $vehicle) {
            if (! auth()->check()) {
                return;
            }
            if ($vehicle->purchase_price <= 0) {
                return;
            }

            // 매입일 변경 시 — Draft PBP (confirmed_at NULL) payment_date 동기화.
            // 사용자 정정 2026-05-20: 영업이 매입일 지정 시 자동 PBP Draft 의 지급일도 같은 날짜로 자동 갱신.
            // confirmed Draft 행은 그대로 (FP::updating 잠금이 confirmed_at SET 후 차단).
            if ($vehicle->wasChanged('purchase_date') && $vehicle->purchase_date) {
                $vehicle->purchaseBalancePayments()
                    ->whereNull('confirmed_at')
                    ->update(['payment_date' => $vehicle->purchase_date]);
            }

            if ($vehicle->purchaseBalancePayments()->count() > 0) {
                return;
            }
            if ($vehicle->settlements()->where('settlement_status', 'paid')->exists()) {
                return;
            }
            // 큐 22-C-E (2026-05-20) — down_payment / selling_fee_payment DROP 후 단순화.
            // 자동 Draft 트리거 조건이 'PBP count == 0' 이므로 이 시점엔 confirmed PBP 도 없음.
            $unpaid = (int) ($vehicle->purchase_price + $vehicle->selling_fee);
            if ($unpaid <= 0) {
                return;
            }
            // 큐 22-C 핵심 — canConfirmFinance 가드 우회. 영업이 매입가 입력하면 시스템 자동 생성 (의도된 흐름).
            PurchaseBalancePayment::$skipCreatingGuard = true;
            try {
                PurchaseBalancePayment::create([
                    'vehicle_id' => $vehicle->id,
                    'amount' => $unpaid,
                    // 사용자 정정 2026-05-20 — payment_date = 매입일 (NULL → 매입일 자동 채움).
                    'payment_date' => $vehicle->purchase_date,
                    'confirmed_at' => null,
                    'created_by_user_id' => auth()->id(),
                    'note' => '자동 생성 — 영업 매입 정보 저장 시',
                ]);
            } finally {
                PurchaseBalancePayment::$skipCreatingGuard = false;
            }
        });

        // 2026-05-20 #2-2+2-4 — 거래완료 진입 시 pending Settlement 자동 생성.
        // 2026-05-21 정산 공식 재구조 — type 별 default 값(ratio=50 또는 per_unit=100000) 자동 채움.
        //   사용자 결정: "role 에 따라서 프리랜서랑 사내직원으로 나눈거에서 정산에서 자동으로 될 수 없나?"
        //   재무가 override 필요 시 명시 입력 — 그러면 H3 가드 통과. 기본 흐름은 자동.
        // Skip: auth 없음(시드/artisan), salesman 미지정, 이미 Settlement 존재.
        static::saved(function (Vehicle $vehicle) {
            if (! auth()->check()) {
                return;
            }
            if ($vehicle->progress_status_cache !== '거래완료') {
                return;
            }
            if (! $vehicle->wasChanged('progress_status_cache')) {
                return;
            }
            if ($vehicle->settlements()->exists()) {
                return;
            }
            $salesman = $vehicle->salesman;
            if (! $salesman) {
                return;
            }
            $settlementType = $salesman->defaultSettlementType();
            $vehicle->settlements()->create([
                'salesman_id' => $salesman->id,
                'settlement_type' => $settlementType,
                'settlement_ratio' => $settlementType === 'ratio'
                    ? Settlement::FREELANCE_RATIO_DEFAULT
                    : null,
                'per_unit_amount' => $settlementType === 'per_unit'
                    ? Settlement::EMPLOYEE_PER_UNIT_DEFAULT
                    : null,
                'settlement_status' => 'pending',
                'note' => '자동 생성 — 거래완료 진입 시',
            ]);
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
     * 큐 17 — 폐기 컨셉 제거 (운영상 없음). bypass 제거.
     */
    public function guardAttachmentDeps(): void
    {
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

        // 큐 21 후속 — H2(수출통관 체크↔서류) 강제 차단 제거.
        // vehicles/index::detectDocCheckMismatches 모달 패턴으로 격하 (사용자 결정 2026-05-18).
        // 운영 흐름상 체크/서류 순서가 비순차적이라 강제 차단은 마찰. 모달 confirm으로 인지 강제.
    }

    /**
     * C4·C5 — 단계 의존성 검증. 수출 정보 입력 시점에 선행 단계 강제.
     * - C4: 말소(is_deregistered + deregistration_document)가 완료돼야 통관 진입 가능
     * - C5: 판매 입금률 < 50% (unpaid_ratio > 0.5) 시 통관 진입 불가
     *
     * G 완화 (2026-05-20 회의록 §G, Q4 해석 A) — 입금 100% 임계값을 50%로 완화.
     *   - 입금률 ≥ 50% (unpaid_ratio ≤ 0.5) → 통관 자유, admin 승인 불필요
     *   - 입금률 < 50% (unpaid_ratio > 0.5) → admin unpaid_export_override 승인 필요
     *   - 환율 미입력 외화 차량 (unpaid_ratio = null) → 환율 입력 또는 admin 승인 필요
     *
     * 큐 17 — 폐기 컨셉 제거. 신규 차량(exists=false)은 미입금 자체가 없어 C5 skip.
     */
    public function guardStageOrderForExport(): void
    {
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

        // 회의확장씬 #4 (2026-05-22) — 선적 진입(bl_loading_location) 시 판매 컨사이니 필수.
        // 사용자 명세: "판매에서 바이어나 컨사이니를 추가... 추가/선택 안 하면 선적으로 진입 불가"
        // 사용자 결정 A (2026-05-22 세션): consignee_id (판매 단계). export/bl 컨사이니는 별도 단계.
        if ($this->bl_loading_location && ! $this->consignee_id) {
            throw ValidationException::withMessages([
                'consignee_id' => '선적 진입 전 판매 컨사이니를 지정해야 합니다 (판매 단계).',
            ]);
        }

        // C5 + G 완화 (2026-05-20) — 입금률 < 50% 시만 차단. admin 우회 인프라 그대로 재사용.
        if ($this->sale_price > 0 && $this->exists) {
            $stage = $this->dhl_request
                ? 'dhl'
                : (($this->bl_loading_location || $this->bl_document) ? 'shipping' : 'clearance');

            $hasOverride = $this->hasUnpaidOverride($stage);
            if ($hasOverride) {
                return;   // admin 승인 — 모든 시나리오 우회
            }

            // 외화 환율 미입력 → 미수율 평가 불가
            if ($this->currency !== 'KRW' && ((float) $this->exchange_rate <= 0)) {
                throw ValidationException::withMessages([
                    'export_buyer_id' => '환율 미입력 외화 차량은 통관 진입 불가. 환율 입력 또는 관리자 승인(미입금 우회) 후 진행하세요.',
                ]);
            }

            $ratio = $this->unpaid_ratio;
            if ($ratio !== null && $ratio > 0.5) {
                $percent = number_format($ratio * 100, 1);
                throw ValidationException::withMessages([
                    'export_buyer_id' => "판매 입금률 < 50% (미수율 {$percent}%) 차량은 {$stage} 단계 진입 불가. 50% 이상 입금 또는 관리자 승인(미입금 우회) 후 진행하세요.",
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

    // 2026-05-21 — CIPL 도착항 마스터.
    public function dischargePort(): BelongsTo
    {
        return $this->belongsTo(Port::class, 'discharge_port_id');
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

    // 큐 19-A — 차량 간 자금 이체 양방향 관계 (회의록 v5 §13)
    public function transfersAsSource(): HasMany
    {
        return $this->hasMany(InterVehicleTransfer::class, 'source_vehicle_id');
    }

    public function transfersAsTarget(): HasMany
    {
        return $this->hasMany(InterVehicleTransfer::class, 'target_vehicle_id');
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

    // ── Computed: 진행상태 10단계 ───────────────────────────────────
    // 큐 16 — 채널 단순화 (export 단일). 채널 분기 제거.
    // 큐 17 — 폐기 컨셉 제거 (운영상 없음). 11단계 → 10단계.
    // 큐 2.6 — rule_version 분기. v1=단일 트리거(grandfather) / v2=이중 트리거 강화.
    //   v2 이중 트리거 (캐스케이드 — 다음 단계 진입 = 이전 단계 트리거 + 현재 단계 트리거):
    //     #5 수출통관완료 = is_export_cleared && export_declaration_document
    //     #4 선적중       = is_export_cleared && bl_loading_location
    //     #3 선적완료     = bl_loading_location && bl_document
    //     #2 거래완료     = bl_document && dhl_request
    // 안건 J 본격 (2026-05-20) — v3 거래완료 단순화 (사용자 의도 100% 반영):
    //   v3 거래완료 = bl_document 단독 (DHL 무관, B/L 발급 시점이 거래완료).
    //   DHL 발송 신청은 거래완료 이후 별도 액션(dhl_dispatch_needed 액션 큐).
    //   선적완료·선적중·수출통관완료·수출통관중 = v2 동일 trigger 유지.
    //   부작용: B/L 발급일 ≈ 반입지 입력일이라 cascade 우선순위로 '선적완료' 단계 매칭 짧음 (운영 현실 반영).
    // 회의확장씬 안건 1 (2026-05-21) — v4 워크플로우 순서 변경:
    //   사용자 의도: 반입(선적) → 통관 → B/L → 거래완료 (v3 통관→선적→B/L 순서 정반대).
    //   '선적'의 도메인 의미 = 반입(bl_loading_location 입력). 단계명 swap (수출통관중/완료 → 통관중/완료).
    //   v4 cascade 5단계 (우선순위 높→낮):
    //     1. bl_document 단독                                     → 거래완료 (B/L 발급 = 거래완료, v3 동일)
    //     2. bl_document AND is_export_cleared                    → 통관완료 (실질 도달 불가 — #1 우선)
    //     3. is_export_cleared AND bl_loading_location            → 통관중   (반입 후 통관 신청)
    //     4. bl_loading_location AND export_declaration_document  → 선적완료 (반입 + 수출신고서)
    //     5. bl_loading_location                                  → 선적중   (반입지 입력)
    public function getProgressStatusAttribute(): string
    {
        $ruleVersion = (int) ($this->progress_status_rule_version ?? 4);
        $v4 = $ruleVersion >= 4;
        $v3 = $ruleVersion >= 3;
        $v2 = $ruleVersion >= 2;

        if ($v4) {
            // 안건 1 — 반입 → 통관 → B/L → 거래완료
            if ($this->bl_document) {
                return '거래완료';
            }
            if ($this->bl_document && $this->is_export_cleared) {
                return '통관완료';
            }
            if ($this->is_export_cleared && $this->bl_loading_location) {
                return '통관중';
            }
            if ($this->bl_loading_location && $this->export_declaration_document) {
                return '선적완료';
            }
            if ($this->bl_loading_location) {
                return '선적중';
            }
        } elseif ($v3) {
            // 안건 J 본격 — 거래완료 trigger 만 변경 (bl_document 단독). 나머지 v2 그대로.
            if ($this->bl_document) {
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
        } elseif ($v2) {
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
    //
    // 큐 20-B — 분자 A안 필터: finalPayments 중 confirmed_at IS NOT NULL 행만 합산.
    // SAP/Odoo Draft/Posted 정석 — 영업 입력 = Draft, 재무 확정(confirmed_at SET) = Posted.
    // ledger == sale_unpaid 단일 기준으로 회계 무결성 보장.
    public function getSaleUnpaidAmountAttribute(): float
    {
        $totalSale = $this->sale_price + $this->transport_fee + $this->sale_other_costs
            + $this->commission + $this->auto_loading - $this->tax_dc;

        // 큐 22-A-3 (2026-05-20) — 4컬럼 합산 제거. 단일 출처 = finalPayments(confirmed_at IS NOT NULL).
        $totalReceived = $this->finalPayments->whereNotNull('confirmed_at')->sum('amount')
            + $this->receivableHistories->where('method', '!=', 'deposit')->sum('amount');

        return $totalSale - $totalReceived;
    }

    // ── Computed: 매입 미지급액 ─────────────────────────────────────
    // 큐 20-B — 분자 A안 필터: purchaseBalancePayments 중 confirmed_at IS NOT NULL 행만 합산.
    // payment_date <= today AND confirmed_at IS NOT NULL 동시 만족해야 ledger 반영.
    public function getPurchaseUnpaidAmountAttribute(): int
    {
        // 큐 22-C-E (2026-05-20) — down_payment / selling_fee_payment DROP 후 단순화.
        // type 무관 confirmed PBP rows 만 합산 (22-A-3 FP 분자와 대칭).
        $totalPurchase = $this->purchase_price + $this->selling_fee;
        $totalPaid = $this->purchaseBalancePayments
            ->filter(fn ($p) => $p->payment_date !== null
                && $p->payment_date->lte(now())
                && $p->confirmed_at !== null)
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
     * 큐 16 — 채널 단순화 후 sales_channel='export' 격리는 enum 단일값으로 자동 보장 (where 불필요).
     *
     * 주의: `clearance_needed`(영업 라벨) ≡ `clearance_request_needed`(통관 라벨),
     *      `dhl_needed`(영업) ≡ `dhl_dispatch_needed`(통관)는 동일 SQL이고 라벨만 다름.
     *      role별 화면에서 맥락에 맞는 라벨 노출을 위해 의도적으로 별도 키 유지 — 통합 금지.
     */
    public function scopeAction(Builder $q, string $action): Builder
    {
        // active 한정 액션: progress_status_cache != '거래완료' (v2·v3 호환 단일 출처)
        // 정산 액션 중 settlement_*·receivable_risk는 거래완료·잔여 미수금 대상이라 active 제외
        // 안건 J 본격 (2026-05-20) — dhl_request=false 직접 참조 폐기. v2/v3 cascade 결과가 progress_status_cache 에 string 저장됨.
        $activeOnly = [
            'purchase_unpaid', 'sale_unpaid', 'clearance_needed', 'shipping_needed', 'dhl_needed',
            'deregistration_needed',
            'clearance_request_needed', 'clearance_info_missing', 'forwarding_missing',
            'export_declaration_upload_needed', 'shipping_process_needed', 'bl_upload_needed', 'dhl_dispatch_needed',
            'exchange_rate_missing', 'clearance_stuck',
            // 2026-05-20 #1 피드백 — 수출통관 후보 차량 (말소 대기 + 통관 준비 합집합)
            'clearance_candidates',
            // receivable_* 액션은 active 제한 X — 거래완료 차량도 미수금 가능 (위험도는 단계 무관)
        ];
        if (in_array($action, $activeOnly, true)) {
            $q->where(fn ($q2) => $q2
                ->where('progress_status_cache', '!=', '거래완료')
                ->orWhereNull('progress_status_cache'));
        }

        return match ($action) {
            // ── 영업 role (5) ──
            'purchase_unpaid' => $q
                ->where('purchase_price', '>', 0)
                // 큐 22-C-E (2026-05-20) — 2컬럼 DROP 후 단순화.
                // CAST AS SIGNED — BIGINT UNSIGNED underflow 방지 (환불·선지급 케이스).
                // 큐 20-B 분자 A안 — confirmed_at IS NOT NULL 가드 (재무 승인 우회 차단).
                // getPurchaseUnpaidAmountAttribute 와 정합 (SKILLS §13 분모 단일 출처).
                ->whereRaw('(CAST(purchase_price AS SIGNED) + CAST(selling_fee AS SIGNED)
                             - COALESCE((SELECT SUM(amount) FROM purchase_balance_payments
                                          WHERE vehicle_id = vehicles.id
                                          AND payment_date IS NOT NULL AND payment_date <= ?
                                          AND confirmed_at IS NOT NULL), 0)) > 0', [now()->toDateString()]),
            'sale_unpaid' => $q
                ->where('sale_price', '>', 0)
                ->where(fn ($q2) => $q2
                    ->where('sale_unpaid_amount_krw_cache', '>', 0)
                    ->orWhereNull('sale_unpaid_amount_krw_cache')),
            'clearance_needed' => $q->where('sale_price', '>', 0)
                ->whereNotNull('sale_unpaid_amount_krw_cache')
                ->where('sale_unpaid_amount_krw_cache', '<=', 0)
                ->whereNull('export_declaration_document'),
            'shipping_needed' => $q->whereNotNull('export_declaration_document')
                ->whereNull('bl_document'),
            'dhl_needed' => $q->whereNotNull('bl_document'),

            // 2026-05-20 사용자 요청 — 매입 완료(미지급 ≤ 0) AND 말소 미처리 차량.
            // canHandleDeregistration 사용자(영업·수출통관·관리·admin)의 액션 큐.
            // SQL은 scopeAction('purchase_unpaid') 미지급 식의 부호 반전 (≤ 0).
            'deregistration_needed' => $q->where('purchase_price', '>', 0)
                ->where(fn ($q2) => $q2->where('is_deregistered', false)
                    ->orWhereNull('deregistration_document'))
                // 큐 22-C-E (2026-05-20) — 2컬럼 DROP 후 단순화. purchase_unpaid 부호 반전.
                ->whereRaw('(CAST(purchase_price AS SIGNED) + CAST(selling_fee AS SIGNED)
                             - COALESCE((SELECT SUM(amount) FROM purchase_balance_payments
                                          WHERE vehicle_id = vehicles.id
                                          AND payment_date IS NOT NULL AND payment_date <= ?
                                          AND confirmed_at IS NOT NULL), 0)) <= 0', [now()->toDateString()]),

            // ── 통관 role (8) ──
            // 2026-05-20 #1 피드백 — 수출통관 후보 차량 (말소 대기 + 통관 준비 합집합).
            // 사용자 의도 원문: 수출통관 사이드바에 두 그룹 차량 솔팅.
            //   (a) 매입완료 + 판매 진행 + 말소 안 됨 → 말소 대기 (영업에 푸시 용도)
            //   (b) 말소완료 + 판매 진행 + 입금률 ≥ 50% → 통관 진행 가능
            // 공통: 수출통관 시작 전 (export_declaration_document IS NULL)
            'clearance_candidates' => $q
                ->where('purchase_price', '>', 0)
                ->where('sale_price', '>', 0)
                ->where(fn ($q2) => $q2
                    // (a) 통관 신청 대기 — 말소 안 됨 + 수출신고서 미업로드 (말소 푸시 대상)
                    ->where(fn ($qa) => $qa
                        ->whereNull('export_declaration_document')
                        ->where(fn ($qa2) => $qa2
                            ->where('is_deregistered', false)
                            ->orWhereNull('deregistration_document')))
                    // (b) 통관 신청 가능 — 말소완료 + 입금률 ≥ 50% + 수출신고서 미업로드
                    ->orWhere(fn ($qb) => $qb
                        ->whereNull('export_declaration_document')
                        ->where('is_deregistered', true)
                        ->whereNotNull('deregistration_document')
                        ->whereNotNull('sale_unpaid_amount_krw_cache')
                        ->whereRaw('sale_unpaid_amount_krw_cache <= (CAST(sale_price AS SIGNED) * CAST(COALESCE(exchange_rate, 1) AS DECIMAL(10,4)) * 0.5)'))
                    // (c) 2026-05-21 사용자 피드백 — 통관 후 선적 단계도 노출 (수출통관완료·선적중·선적완료).
                    //     거래완료는 위 active 조건에서 자동 제외 → 진행 중 단계만 사이드바에 카운트.
                    ->orWhereNotNull('export_declaration_document')),

            'clearance_request_needed' => $q->where('sale_price', '>', 0)
                ->whereNotNull('sale_unpaid_amount_krw_cache')
                ->where('sale_unpaid_amount_krw_cache', '<=', 0)
                ->whereNull('export_declaration_document'),
            'clearance_info_missing' => $q->where('sale_price', '>', 0)
                ->where(fn ($q2) => $q2
                    ->whereNull('export_buyer_id')
                    ->orWhereNull('shipping_date')),
            'forwarding_missing' => $q->whereNotNull('export_buyer_id')
                ->whereNotNull('shipping_date')
                ->whereNull('forwarding_company_id'),
            'export_declaration_upload_needed' => $q->whereNotNull('export_buyer_id')
                ->whereNotNull('shipping_date')
                ->whereNull('export_declaration_document'),
            'shipping_process_needed' => $q->whereNotNull('export_declaration_document')
                ->whereNull('bl_loading_location'),
            'bl_upload_needed' => $q->whereNotNull('bl_loading_location')
                ->whereNull('bl_document'),
            'dhl_dispatch_needed' => $q->whereNotNull('bl_document'),

            // 큐 4 8-7 — 통관 정체 (admin 대시보드 stuck_count와 SQL 100% 일치).
            // 판매완료(unpaid<=0 OR NULL) + 수출신고서 NULL + sale_date 30일 경과.
            'clearance_stuck' => $q->where('sale_price', '>', 0)
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
            // 안건 J 본격 (2026-05-20) — v2/v3 호환. progress_status_cache 단일 출처.
            'settlement_create_needed' => $q
                ->where('progress_status_cache', '거래완료')
                ->whereDoesntHave('settlements'),
            // 2026-05-20 #2 피드백 — 거래완료지만 미수금 남은 차량 (정산 진행 차단 상태).
            'settlement_blocked_by_unpaid' => $q
                ->where('progress_status_cache', '거래완료')
                ->whereNotNull('sale_unpaid_amount_krw_cache')
                ->where('sale_unpaid_amount_krw_cache', '>', 0),
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

            // ── 큐 10 확장 — G3 미수 분류 (회의록 v5 §G3, 2026-05-18 사용자 결정) ──
            // 선적전 미수: progress_status_cache ∈ {매입중, 매입완료, 말소완료, 판매중, 판매완료}
            //              AND sale_unpaid_amount > 0
            'receivable_before_shipping' => $q
                ->whereIn('progress_status_cache', ['매입중', '매입완료', '말소완료', '판매중', '판매완료'])
                ->where('sale_unpaid_amount_krw_cache', '>', 0),

            // 안건 1 v4 (2026-05-21) — 단계명 swap: 수출통관중/완료 → 통관중/완료.
            // 선적후 미수: progress_status_cache ∈ {선적중, 선적완료, 통관중, 통관완료}
            //              AND sale_unpaid_amount > 0
            // v3 호환 라벨도 포함 (운영 데이터 0이지만 안전망).
            'receivable_after_shipping' => $q
                ->whereIn('progress_status_cache', ['선적중', '선적완료', '통관중', '통관완료', '수출통관중', '수출통관완료'])
                ->where('sale_unpaid_amount_krw_cache', '>', 0),

            // 디파짓: savings_used > 0 (적립금 사용분)
            'deposit_by_buyer' => $q->where('savings_used', '>', 0),

            default => $q,
        };
    }
}
