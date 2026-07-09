<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
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
        'purchase_date', 'warehouse_out_date', 'salesman_id', 'purchase_from', 'purchase_source', 'c_no', 'purchase_price', 'selling_fee',
        // 큐 20-A — 매입처 계좌 4컬럼 (purchase_seller_account encrypted)
        'purchase_seller_bank', 'purchase_seller_account', 'purchase_seller_holder', 'purchase_bank_memo',
        // 2026-07-03 — 매도비 계좌 3컬럼 (purchase_fee_account encrypted). 매입가 계좌와 별도 주체.
        'purchase_fee_bank', 'purchase_fee_account', 'purchase_fee_holder',
        'cost_deregistration', 'cost_license', 'cost_towing', 'cost_carry',
        'cost_shoring', 'cost_insurance', 'cost_transfer', 'cost_extra1', 'cost_extra2',
        // 큐 22-C-E (2026-05-20) — down_payment / selling_fee_payment DROP.
        // 2컬럼은 purchase_balance_payments.type enum (down/selling_fee) 로 통합.
        'purchase_remittance_memo',
        'registration_number', 'reg_cert_number',
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
        'bl_loading_location', 'vessel_name', 'bl_document', 'bl_type', 'bl_issue_date', 'document_deadline_date',
        'dhl_recipient_name', 'dhl_recipient_address', 'dhl_recipient_phone',
        'dhl_sender_name', 'dhl_sender_address', 'dhl_weight', 'dhl_dimensions',
        'dhl_request', 'memo',
        // Phase 3 서류 자동기입 (2026-05-24) — NICE 원본 보관 + 말소일 + 기통수
        'nice_raw', 'deregistration_date', 'nice_spec_cylinders',
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
        'deregistration_date' => 'date',
        'nice_raw' => 'array',
        'purchase_date' => 'date',
        'warehouse_out_date' => 'date',
        'sale_date' => 'date',
        'shipping_date' => 'date',
        'eta_date' => 'date',
        'bl_issue_date' => 'date',
        'document_deadline_date' => 'date',
        'nice_reg_owner_rrn_encrypted_at' => 'datetime',
        // 큐 20-A — 매입처 계좌번호 자동 암호화 (Laravel Crypt — AES-256-CBC)
        'purchase_seller_account' => 'encrypted',
        'purchase_fee_account' => 'encrypted',
    ];

    /**
     * 신규 매입 차량 기본 기타비용 (회의확장씬 #9, 2026-05-22 — 사용자 명세).
     * 운영자가 수정/0 가능, 2차 정산에서 실측치로 정정. UI 신규등록(openCreate)과
     * 연동 B 수신(PurchaseSyncController) **양쪽이 이 단일 출처를 참조** — drift 방지.
     */
    public const DEFAULT_PURCHASE_COSTS = [
        'cost_deregistration' => 24000,
        'cost_license' => 11000,
        'cost_towing' => 30000,
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

    // 2026-07-09 — 옛 G2 guardSameBuyerOverlap 제거(ERP 죽은 락). 미수 바이어 신규거래 차단은
    //   UI save() 의 미수 매입 게이트(②, Buyer::computeReceivableGauge 미수율>50%)가 단일 담당.
    //   ApprovalRequest::TYPE_INTER_BUYER_OVERLAP 상수·실행핸들러는 과거기록 보존 위해 존치(신규 생성 없음).

    /**
     * G1 — B/L 100% 발급 게이트 (2026-05-26 외부리뷰 감사 회의 §사용자결정 1).
     *
     * 룰: `bl_document` 신규 첨부 시 `unpaid_ratio > 0`(미완납)이면 차단.
     * 잔금 100% 완납 후 B/L 발행 가능. (통관·선적 진입 C5는 50% 유지 — 별개 게이트.)
     *
     * ⚠️ 2026-05-14 도입 시엔 50% 게이트였으나, 사용자 워크플로우 실제 의도
     *    ("통관·선적은 50%, B/L(화물 인도권)은 100%")에 맞춰 100%로 상향.
     *
     * 분기:
     *   ① grandfather — 기존에 bl_document 있던 차량은 모든 변경 통과 (수정·교체·삭제 포함)
     *      (사용자 결정 2026-05-18: 이미 운영중 차량은 우회)
     *   ② 판매가 미입력(unpaid_ratio = null ⟺ sale_total_amount ≤ 0) → 별도 메시지로 차단
     *      (unpaid_ratio는 통화 비의존 — sale_total_amount에 환율 안 곱함. 환율 누락과 무관.)
     *   ③ `unpaid_export_override` (stage='bl') 승인 있으면 우회 — 관리/관리자 승인
     *      ⚠ 선적 진입 우회('shipping', C5 50%)와 **별개** — B/L 발행은 'bl' 우회만 통과(2026-06-23 jin).
     *      (큐 2.6 인프라 재사용. 승인 권한 = User::canApproveUnpaidExport)
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
            // 판매가 미입력 (sale_total_amount ≤ 0) — 미수율 평가 불가
            throw ValidationException::withMessages([
                'bl_document' => 'B/L 발행 전 판매가 입력이 필요합니다 — 판매 탭. (판매가 미입력으로 미수율 평가 불가.)',
            ]);
        }

        if ($ratio <= 0) {
            return;   // 완납 — 발행 가능
        }

        // 100% 미완납 — 미입금 우회 승인 확인 ('bl'(B/L 발행) 단계, 관리/관리자)
        //   ⚠ 'shipping'(선적 진입) 우회로는 안 뚫림 — B/L 발행은 별도 'bl' 승인 필요(2026-06-23 jin).
        if ($this->hasUnpaidOverride('bl')) {
            return;
        }

        $percent = number_format($ratio * 100, 1);
        throw ValidationException::withMessages([
            'bl_document' => "B/L 발행 차단 — 미수율 {$percent}% (잔금 100% 미완납). 완납 후 발행 가능. 또는 관리/관리자 미입금 우회 승인('B/L 발행' 단계) 필요.",
        ]);
    }

    /**
     * 큐 21 — Ledger 영향 컬럼 잠금 가드.
     *
     * 회의록 2026-05-18 vehicle-ledger-field-lock — 사용자 최종 결정 반영:
     *   ① 트리거 = confirmed FinalPayment OR PurchaseBalancePayment 1건 이상
     *   ② 잠금 컬럼 = LEDGER_LOCK_FIELDS (Tier 1·2 통합 21컬럼)
     *   ③ 잠금 해제 권한 = super/admin + role '관리'(본인 팀, User::canUnlockLedger) — 2026-06-22 jin override
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
     * 삭제 시 사유 모달 + AuditLog 필요 대상 — 회계 연관 차량 (2026-07-08 jin).
     * 확정 잔금(회계잠금) 또는 정산 이력이 있으면 "그냥 삭제" 대신 사유 입력·기록을 강제.
     * (권한 자체는 Vehicle::deleting 가드가 별도 판정 — confirmed 잔금은 admin/super 전용.)
     */
    public function requiresDeleteReason(): bool
    {
        return $this->hasConfirmedPaymentLock() || $this->settlements()->exists();
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
        // 2026-07-03 — 매도비 계좌 3컬럼 변경 audit (purchase_fee_account 는 MASKED_COLUMNS 마스킹).
        'purchase_fee_bank', 'purchase_fee_account', 'purchase_fee_holder',
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

    /**
     * 2차 정산 비용 일괄 기입 대상 컬럼 화이트리스트 (9개 비용만).
     * 면허비 묶음 n/1·탁송비 명세서 매칭 도구는 **이 컬럼만** 건드릴 수 있음.
     * → fleet-wide(전체 차량) 권한이어도 판매가·환율·매입가·바이어·담당자 등 민감 21필드는 봉인.
     */
    public const BULK_COST_FIELDS = [
        'cost_deregistration', 'cost_license', 'cost_towing', 'cost_carry',
        'cost_shoring', 'cost_insurance', 'cost_transfer', 'cost_extra1', 'cost_extra2',
    ];

    // 명세서 엑셀 일괄 업로드가 지원되는 비용 컬럼 (「명세서 기입」 도구 대상비용 드롭박스).
    //   - cost_towing  : 업체 월명세서, 차량번호 건바이건 매칭
    //   - cost_license : 통관 면허비 월명세서, 수출신고번호로 묶어 합계 n/1 분배
    // ⚠️ 봉인 화이트리스트는 BULK_COST_FIELDS(9개) 그대로 — 이건 UI 노출/파서 분기용 축소 목록.
    public const BULK_COST_UPLOAD_FIELDS = ['cost_towing', 'cost_license'];

    // 명세서 기입 — 대상비용별 거래처(서식) 목록. 회사마다 엑셀 서식이 달라 좌표 파서를 분기한다.
    //   탁송비: wika(기존 범용) / gucheonyuk / hyundai_a1  — 면허비: mutual(기존 xlsx n/1) / seongji(→선적요청 딥링크)
    public const COST_IMPORT_COMPANIES = [
        'cost_towing' => ['wika', 'gucheonyuk', 'hyundai_a1'],
        'cost_license' => ['mutual', 'seongji'],
    ];

    // 탁송비 회사별 좌표 고정 파서 맵 — start=데이터 시작행, plate=차량번호열, amount=합산할 금액 성분열.
    //   (범용 '마지막 숫자' 파서는 차종 숫자[아우디 Q5→5]·비고 오염 위험 → 좌표 고정. wika 는 좌표 미검증이라 기존 범용 유지.)
    public const TOWING_IMPORT_LAYOUTS = [
        'gucheonyuk' => ['start' => 2, 'plate' => 'J', 'amount' => ['F', 'G']],   // 탁송비 F + 주유 G = 총액
        'hyundai_a1' => ['start' => 13, 'plate' => 'M', 'amount' => ['I', 'J']],  // 탁송 I + 추가 J = 총액
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

            // 2026-07-03 사용자 결정 — 면장금액 = 총판매가(sale_total_amount) 자동 추종.
            //   (2026-05-21 최초엔 sale_price 였으나 jin "면장금액=총판매가가 맞다" → 부대비용 포함 총액으로 교체.)
            //   총판매가 = sale_price + transport_fee + sale_other_costs + commission + auto_loading - tax_dc.
            //   추종 규칙(2026-07-08 jin 버그신고 "총판매가 바꿔도 면장 안 변함"):
            //     ① 면장 비었으면 채움  ② 면장이 (구)총판매가와 일치 = 자동복사분이면 신 총판매가로 갱신(추종)
            //     ③ 이번 저장에 면장 직접 편집(수동) or 총판매가와 다른 값(CIF/FOB 수기)이면 보존.
            if ((float) ($vehicle->sale_price ?? 0) > 0) {
                $newTotal = (float) $vehicle->sale_total_amount;
                $curDecl = (float) ($vehicle->export_declaration_amount ?? 0);
                $oldTotal = (float) (
                    $vehicle->getOriginal('sale_price') + $vehicle->getOriginal('transport_fee')
                    + $vehicle->getOriginal('sale_other_costs') + $vehicle->getOriginal('commission')
                    + $vehicle->getOriginal('auto_loading') - $vehicle->getOriginal('tax_dc')
                );
                if ($curDecl <= 0) {
                    $vehicle->export_declaration_amount = $newTotal;                              // ① 미입력
                } elseif (! $vehicle->isDirty('export_declaration_amount') && abs($curDecl - $oldTotal) < 0.01) {
                    $vehicle->export_declaration_amount = $newTotal;                              // ② 자동복사분 → 추종
                }
                // ③ else 보존 (수동 CIF/FOB 또는 이번 저장에 면장 직접 편집)
            }

            // 큐 21 — Ledger 영향 컬럼 잠금 가드 (캐시 갱신 전 최우선 검사).
            // 재무 확정 잔금 있는 차량의 매입가·판매가·환율·면장금액·비용·바이어·담당자 변경은
            // admin/super 잠금 해제 후 1회만 통과 (cache token 1회 소비 → 즉시 재잠금).
            $vehicle->guardLedgerLockOnSaving();

            // G1 — B/L 100% 발급 게이트 (2026-05-26 회의 §사용자결정 1, SKILLS §13).
            // bl_document 신규 첨부 시 unpaid_ratio > 0(미완납)면 차단. grandfather + 관리/관리자 우회 분기.
            $vehicle->guardBlFiftyPercentRuleOnSaving();

            // 2026-07-09 — 옛 G2(guardSameBuyerOverlap) 제거. ERP 신규 등록자(관리·업무관리자·admin)는
            //   전부 canApprove 라 이 가드를 늘 우회 = ERP에선 죽은 락. 미수 바이어 신규거래 차단은
            //   UI save() 의 미수 매입 게이트(②, Buyer 미수율>50%)가 단일 담당. 영업 경로는 추후 board.

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

        // hard delete(forceDelete) 시 첨부(서류+사진)를 즉시 삭제하지 않고
        // 같은 디스크의 deleted/{id}-{timestamp}/ 로 보존 이동 (큐 11-2, 사고 복구).
        // soft delete는 첨부 유지 — 복구 가능성 보호.
        //
        // 서류·사진 모두 vehicles/{id}/ 아래 저장되므로 prefix 하나로 전부 커버.
        // 디스크 = vehicle_docs_disk (로컬 public / 운영 private S3) — 양쪽 동일 동작.
        // (claudereview D — 기존 로컬 File:: 이동은 storage_path 기반이라 운영 S3 미처리 →
        //  S3 서류·사진 orphan + 삭제 백업 누락. Storage 추상화로 교체해 S3도 보존 이동.)
        static::forceDeleted(function (Vehicle $vehicle) {
            $disk = Storage::disk(config('filesystems.vehicle_docs_disk'));
            $srcPrefix = "vehicles/{$vehicle->id}";
            $timestamp = now()->format('Ymd_His');

            foreach ($disk->allFiles($srcPrefix) as $from) {
                $rel = ltrim(substr($from, strlen($srcPrefix)), '/');
                $to = "deleted/{$vehicle->id}-{$timestamp}/{$rel}";

                // 복사 실패 시 원본을 삭제하지 않는다 (데이터 보존 우선).
                try {
                    if (! $disk->copy($from, $to)) {
                        throw new \RuntimeException('copy returned false');
                    }
                } catch (\Throwable $e) {
                    Log::critical('forceDelete 첨부 백업 복사 실패 — 원본 보존', [
                        'vehicle' => $vehicle->id, 'path' => $from, 'error' => $e->getMessage(),
                    ]);

                    continue;
                }

                // 복사 성공 후 원본 삭제 실패는 백업본이 있어 치명적이지 않으나 기록.
                try {
                    $disk->delete($from);
                } catch (\Throwable $e) {
                    Log::critical('forceDelete 원본 삭제 실패 — 백업본 존재', [
                        'vehicle' => $vehicle->id, 'path' => $from, 'error' => $e->getMessage(),
                    ]);
                }
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

        // 매입 저장 훅 — 미확정 매입 잔금 payment_date 동기화 (매입일 변경 시).
        // ⚠️ 자동 PBP Draft 생성 제거 (jin 2026-07-03) — 단순 저장(매입가/매도비 입력)이 재무처리 큐로
        //   자동 유입되지 않도록. 매입 미지급은 accessor(확정 PBP 기준, getPurchaseUnpaidAmountAttribute)라
        //   대시보드 매입 미지급 KPI·매입 미지급 요약 박스에 그대로 노출됨. 재무는 실제 지급 시
        //   transfers 매입 잔금 탭 '신규 입력'(createNewPbp)으로 직접 기록·확정.
        //   (구 큐 22-C 자동 Draft 흐름 폐기. AUTO_DRAFT_NOTE 상수·reconcile 가드는 레거시 Draft 대비 유지.)
        static::saved(function (Vehicle $vehicle) {
            if (! auth()->check()) {
                return;
            }
            if ($vehicle->purchase_price <= 0) {
                return;
            }
            // 매입일 변경 시 — 미확정(대기) 매입 잔금 payment_date 동기화.
            if ($vehicle->wasChanged('purchase_date') && $vehicle->purchase_date) {
                $vehicle->purchaseBalancePayments()
                    ->whereNull('confirmed_at')
                    ->update(['payment_date' => $vehicle->purchase_date]);
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
            // A-3 (2026-07-08) — 정산은 판매완료(완납) 시 FinalPayment::saved 에서 우선 생성.
            //   거래완료 훅은 안전망(완납+거래완료 동시 저장 등 완납 트리거 못 탄 경우).
            //   createSettlementIfComplete 가 완납·담당자·정산없음(재귀속 금지) 가드.
            if ($vehicle->progress_status_cache !== '거래완료' || ! $vehicle->wasChanged('progress_status_cache')) {
                return;
            }
            $vehicle->createSettlementIfComplete('자동 생성 — 거래완료 진입 시');
        });

        // 운임 확정 게이트 재트리거 (jin 2026-07-09) — 완납이지만 운임 미확정으로 대기하던 차량이
        //   인코텀즈(FOB/CFR) 또는 운임비 입력으로 확정되면 그 저장 시점에 정산 자동 생성.
        //   createSettlementIfComplete 가 완납·담당자·정산없음·운임확정 전부 재가드 → 조건 미달이면 no-op.
        //   (FinalPayment 로 완납되는 경로는 FinalPayment::saved 가 이미 담당 — 여긴 차량 필드 변경 경로.)
        static::saved(function (Vehicle $vehicle) {
            if (! auth()->check()) {
                return;
            }
            if (! $vehicle->wasChanged('incoterms') && ! $vehicle->wasChanged('transport_fee')) {
                return;
            }
            $vehicle->createSettlementIfComplete('자동 생성 — 운임/인코텀즈 확정 시');
        });

        // 2026-06-18 ETA 알람 즉시 자동해소 (Hybrid — 매일 alarms:scan 보정과 별개).
        //   수출신고서 업로드/거래완료 시 24h 기다리지 않고 즉시 해소 → obsolete 알람 노출 방지.
        static::saved(function (Vehicle $vehicle) {
            static $hasAlarmTable = null;
            if ($hasAlarmTable === null) {
                $hasAlarmTable = Schema::hasTable('task_alarms');
            }
            if (! $hasAlarmTable) {
                return;
            }
            if (! $vehicle->export_declaration_document && $vehicle->progress_status_cache !== '거래완료') {
                return;
            }
            TaskAlarm::where('type', 'eta_clearance')
                ->where('vehicle_id', $vehicle->id)
                ->whereNull('resolved_at')
                ->update(['resolved_at' => now(), 'resolved_reason' => 'document_uploaded']);
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
     * 회의확장씬 #12 (2026-05-22) — 판매 탭 적립금 적립 입력 시 SavingsStatus EARNED 거래 추가.
     *
     * 사용자 명세: "1번차 잔금 300 + 적립금 50 → 1번차 미수금엔 50 차감 X, 바이어 적립금에만 50 누적".
     * 적립금은 FinalPayment 가 아닌 SavingsStatus 직접 거래로 기록 — sale_unpaid_amount 분자 자연 제외.
     *
     * 호출자: vehicles/index::save() 가 canConfirmFinance 사용자 입력 시 호출.
     * 동시성: syncSavingsUsage 패턴 동일 — buyer×currency lockForUpdate + 잔액 누적.
     */
    public function syncSavingsDeposit(float $amount): void
    {
        if ($amount <= 0 || ! $this->buyer_id) {
            return;
        }

        DB::transaction(function () use ($amount) {
            $latest = SavingsStatus::where('buyer_id', $this->buyer_id)
                ->where('currency', $this->currency)
                ->lockForUpdate()
                ->orderByDesc('id')
                ->first();
            $currentBalance = (float) ($latest?->balance ?? 0);
            $newBalance = $currentBalance + $amount;

            SavingsStatus::create([
                'buyer_id' => $this->buyer_id,
                'vehicle_id' => $this->id,
                'currency' => $this->currency,
                'transaction_type' => 'EARNED',
                'savings' => $amount,
                'balance' => $newBalance,
                'note' => "차량 {$this->vehicle_number} 판매 탭 적립금 적립",
            ]);
        });
    }

    /**
     * H1·H3 + 큐 2.6 — 첨부/전제 조건 + 단계 캐스케이드 검증.
     *
     * 회의확장씬 #1 v4 (2026-05-21) — 워크플로우 순서: 선적 → 통관 → B/L → 거래완료.
     * 사용자 보고 (2026-05-22): H4 가드 ↔ #4 컨사이니 가드 도돌이표 형성.
     *
     * 가드 정리 (v4 cascade 정합):
     *   - 큐 2.6 H3: bl_document 업로드 시 bl_loading_location(반입지) 필수 (B/L 발행 = 선적 후)
     *   - H1: dhl_request=true 시 bl_document 필수 (DHL 발송 = B/L 후)
     *
     * 폐기 (v4 정합 X):
     *   - 큐 2.6 H4 (bl_loading_location → is_export_cleared 필요) — v3 가정 (통관 → 선적).
     *     v4 에서는 선적이 통관보다 먼저라 의미 없음. 회의확장씬 #4 컨사이니 가드와
     *     순환 차단 형성 → 폐기. (사용자 보고 2026-05-22)
     *   - H2 (is_export_cleared → export_declaration_document) — 큐 21 모달 격하 (별건)
     */
    public function guardAttachmentDeps(): void
    {
        // 큐 2.6 H3 — B/L 문서는 반입지 입력 후 (v4: 선적 → 통관 → B/L)
        if ($this->bl_document && empty($this->bl_loading_location)) {
            throw ValidationException::withMessages([
                'bl_document' => 'B/L 문서를 업로드하려면 반입지 입력이 먼저 필요합니다 — 선적 탭.',
            ]);
        }

        // H1 — DHL 발송 신청 시 B/L 문서 강제
        if ($this->dhl_request && empty($this->bl_document)) {
            throw ValidationException::withMessages([
                'dhl_request' => 'DHL 발송 신청을 하려면 B/L 문서 업로드가 먼저 필요합니다 — B/L 탭.',
            ]);
        }
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
        // 2026-07-08 (방향1) — 당사자 배정(export_buyer_id)은 게이트 트리거에서 제외.
        //   바이어 이름 배정/전파(item8 propagateBlToExport 포함)는 "통관 행위"가 아니라 데이터일 뿐인데,
        //   트리거에 있으면 배정만으로 C4(말소완료 강제)/C5(50% 입금)가 오발동해 판매/말소 저장이 통째 차단됨
        //   (말소 도중이면 "말소 완료 후 통관 진입" 닭-달걀). 실제 통관/선적 행위 5개에만 게이트 발동.
        //   에러 메시지의 'export_buyer_id' 키는 사용자 표시 앵커로 존치. (SKILLS §8 #24 근본 해소)
        $hasExportInput = $this->shipping_date
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
                'export_buyer_id' => '말소 처리(체크 + 서류 업로드)를 완료해야 통관·선적 진입이 가능합니다 — 매입 탭.',
            ]);
        }

        // 선적 진입(bl_loading_location) 시 선적 컨사이니(bl_consignee_id) 필수.
        //   당사자 축소 (jin 2026-07-09) — 판매탭 컨사이니 입력칸 제거로 옛 consignee_id 가 항상 비어
        //   오발동("판매 탭에서 컨사이니 등록" 데드엔드)하던 것을, 실제 입력 필드(선적 탭 bl_consignee_id)로 전환.
        //   컨사이니는 선적에서 입력 → 통관 이어받음(export_consignee_id). B/L·선적서류에 필요.
        if ($this->bl_loading_location && ! $this->bl_consignee_id) {
            throw ValidationException::withMessages([
                'bl_consignee_id_str' => '선적하려면 컨사이니를 지정해야 합니다 — 선적 탭.',
            ]);
        }

        // C5 + G 완화 (2026-05-20) — 입금률 < 50% 시만 차단. admin 우회 인프라 그대로 재사용.
        if ($this->sale_price > 0 && $this->exists) {
            // C5(50%) 진입 게이트 — 통관·선적은 동일 50% 관문이므로 진입 우회 1건(clearance∪shipping)이면 통과.
            //   (2026-07-01 jin: 입력 순서에 따라 stage 라벨이 clearance↔shipping 으로 갈려
            //    같은 미수·같은 50% 인데 우회를 2번 해야 하던 마찰 제거. 서버 실증 = 145나1447.)
            //   ⚠ B/L 발행 100% 우회 'bl' 은 별개 — G1(guardBlFiftyPercentRuleOnSaving)에서만 소비.
            //     진입 우회로는 안 쳐줌 (G1BlLockTest::test_g1_shipping_override_alone_does_not_bypass_bl 가드).
            if ($this->hasEntryUnpaidOverride()) {
                return;   // 진입 우회 승인 — 통관·선적 모두 통과
            }

            // 외화 환율 미입력 → 미수율 평가 불가
            if ($this->currency !== 'KRW' && ((float) $this->exchange_rate <= 0)) {
                throw ValidationException::withMessages([
                    'export_buyer_id' => '환율 미입력 외화 차량은 통관·선적 진입 불가 — 판매 탭에서 환율을 입력하세요. (또는 관리자 미입금 우회 승인.)',
                ]);
            }

            $ratio = $this->unpaid_ratio;
            if ($ratio !== null && $ratio > 0.5) {
                $percent = number_format($ratio * 100, 1);
                throw ValidationException::withMessages([
                    'export_buyer_id' => "판매 입금률 < 50% (미수율 {$percent}%) 차량은 통관·선적 진입 불가. 50% 이상 입금 또는 관리자 승인(미입금 우회) 후 진행하세요.",
                ]);
            }
        }
    }

    /**
     * 잔금 / 회수 이력 변경으로 잔액 의존 캐시가 바뀌었을 때 호출.
     * Eloquent saving 이벤트를 우회하고 컬럼만 직접 갱신해 무한 루프 방지.
     */
    /**
     * A-3 (2026-07-08) — 판매완료(완납) 또는 거래완료 시 pending 정산 자동 생성.
     *   조건: sale_price>0 && 미입금≤0(완납) && 담당자 있음 && 정산 없음(재귀속 금지).
     *   귀속월(attributed_month) = 완납월 1일 고정 — 이후 거래완료돼도 불변.
     *   type default(ratio/per_unit)는 null 위임(Setting 기반 자동 산정).
     *   ⚠️ 호출부는 auth()->check() 가드 필수 — 시드·artisan 대량 유입 차단(기존 거래완료 훅과 동일 정책).
     */
    public function createSettlementIfComplete(string $note): void
    {
        if ((float) ($this->sale_price ?? 0) <= 0) {
            return;
        }
        if ($this->sale_unpaid_amount > 0) {
            return;   // 아직 미완납
        }
        if (! $this->salesman_id || $this->settlements()->exists()) {
            return;   // 담당자 없음 또는 이미 정산(재귀속 금지)
        }
        if (! $this->isFreightConfirmedForSettlement()) {
            return;   // 운임 미확정 — 대기 (인코텀즈/운임비 확정 시 재트리거)
        }
        $salesman = $this->salesman;
        if (! $salesman) {
            return;
        }
        $this->settlements()->create([
            'salesman_id' => $salesman->id,
            'settlement_type' => $salesman->defaultSettlementType(),
            'settlement_ratio' => null,
            'per_unit_amount' => null,
            'settlement_status' => 'pending',
            'attributed_month' => $this->fullPaymentMonth(),
            'note' => $note,
        ]);
    }

    /**
     * 정산 자동생성 운임 확정 게이트 (jin 2026-07-09).
     *   KRW(원화 정산) → 국제 운임/인코텀즈 개념 없음 → 완납 즉시 통과 (국내판매 동결 방지).
     *   FOB          → 운임비 0원이 정상 → 통과.
     *   CFR + 운임비>0 → 운임비 기입+수금(미수 분모 포함) 완료 → 통과.
     *   그 외(외화 + (CFR+운임0 · incoterms 미입력)) → 대기 (사람이 인코텀즈/운임비 확정 시 재트리거).
     * 전 차량 export 단일채널이라 채널 분기 없음. 구분은 currency(원화 vs 외화).
     * ⚠️ 아래 scopeAwaitingFreightConfirm 의 SQL 부정조건과 동일 정의 — 함께 유지.
     */
    public function isFreightConfirmedForSettlement(): bool
    {
        if ($this->currency === 'KRW') {
            return true;
        }
        if ($this->incoterms === 'FOB') {
            return true;
        }

        return $this->incoterms === 'CFR' && (float) ($this->transport_fee ?? 0) > 0;
    }

    /**
     * 운임/인코텀즈 확정 대기 큐 — 완납인데 운임 게이트에 막혀 정산이 안 뜬 차량 (jin 2026-07-09).
     * isFreightConfirmedForSettlement()의 SQL 부정형. 대시보드 카드·목록 필터·카운트 단일 출처.
     *   완납 = sale_unpaid_amount_krw_cache <= 0 (환율 미입력 NULL 은 완납 판정 불가 → 제외).
     *   외화만 대상 (KRW 는 게이트 자동통과 → 대기 아님). currency 는 NOT NULL(default USD).
     *   freight 미확정 = incoterms NULL  OR  (CFR AND 운임비 ≤ 0).  (FOB / CFR+운임>0 은 정산됨)
     */
    public function scopeAwaitingFreightConfirm($query)
    {
        return $query->where('sale_price', '>', 0)
            ->whereNotNull('sale_unpaid_amount_krw_cache')
            ->where('sale_unpaid_amount_krw_cache', '<=', 0)
            ->whereNotNull('salesman_id')
            ->where('currency', '!=', 'KRW')
            ->whereDoesntHave('settlements')
            ->where(function ($w) {
                $w->whereNull('incoterms')
                    ->orWhere(function ($c) {
                        $c->where('incoterms', 'CFR')
                            ->where(function ($t) {
                                $t->whereNull('transport_fee')->orWhere('transport_fee', '<=', 0);
                            });
                    });
            });
    }

    /** A-3 — 완납월(그 달 1일). 완납일 ≈ 최근 확정 잔금(입금)일, 없으면 sale_date, 그것도 없으면 오늘. */
    public function fullPaymentMonth(): string
    {
        $last = $this->finalPayments()->whereNotNull('confirmed_at')->max('payment_date')
            ?: $this->finalPayments()->max('payment_date')
            ?: $this->sale_date;
        $date = $last ? Carbon::parse($last) : now();

        return $date->copy()->startOfMonth()->format('Y-m-d');
    }

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

    /** 차량 등록 사진 (N장, jpg/png) — 업로드 순서대로. */
    public function photos(): HasMany
    {
        return $this->hasMany(VehiclePhoto::class)->orderBy('sort_order')->orderBy('id');
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

    /**
     * C5(50%) 진입 우회 — 통관·선적은 같은 50% 관문이라 하나로 취급.
     * clearance ∪ shipping 중 하나라도 승인돼 있으면 통관·선적 진입 모두 통과.
     * (bl(100%)은 제외 — B/L 발행은 G1 에서 hasUnpaidOverride('bl')로만 소비. 2026-07-01 jin.)
     */
    public function hasEntryUnpaidOverride(): bool
    {
        return $this->unpaidExportOverrides()
            ->whereIn('stage', ['clearance', 'shipping'])
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

    // i18n — 진행상태 표시 라벨 (현재 locale). 키(progress_status)는 한글 그대로, 표시만 번역.
    public function getProgressStatusLabelAttribute(): string
    {
        return (string) trans('domain.progress.'.$this->progress_status);
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
    // 적립금 사용(savings_used)도 이 차량 잔금 결제로 반영한다 (jin 2026-07-09).
    //   적립금은 원 차량 초과분/선수금을 옮겨둔 buyer×currency 크레딧 풀 — 이미 원 차량 입금에서
    //   빠진 돈이라, 이 차량에 쓰면(savings_used) 그만큼 잔금(미수)이 줄어야 회계가 맞다(이중계상 없음).
    //   savings_used 는 이 차량 통화 기준(Vehicle::saved 가 currency 매칭 USED 거래) → 미수도 동일 통화.
    //   ⚠️ 미수 단일 출처(SKILLS §13) — 게이지·채권·진행상태(판매완료)·지급보류·운임게이트 전부 여기 따라옴.
    //
    // 큐 20-B — 분자 A안 필터: finalPayments 중 confirmed_at IS NOT NULL 행만 합산.
    // SAP/Odoo Draft/Posted 정석 — 영업 입력 = Draft, 재무 확정(confirmed_at SET) = Posted.
    // ledger == sale_unpaid 단일 기준으로 회계 무결성 보장.
    public function getSaleUnpaidAmountAttribute(): float
    {
        $totalSale = $this->sale_price + $this->transport_fee + $this->sale_other_costs
            + $this->commission + $this->auto_loading - $this->tax_dc;

        // 큐 22-A-3 (2026-05-20) — 4컬럼 합산 제거. 단일 출처 = finalPayments(confirmed_at IS NOT NULL).
        // + 적립금 사용(savings_used) = 크레딧으로 잔금 결제 (2026-07-09).
        $totalReceived = $this->finalPayments->whereNotNull('confirmed_at')->sum('amount')
            + $this->receivableHistories->where('method', '!=', 'deposit')->sum('amount')
            + (float) ($this->savings_used ?? 0);

        $unpaid = $totalSale - $totalReceived;

        // 통화 1단위 미만 양수 잔차(외화 소수점, 예: 8397.34 EUR 판매 - 8397 입금 = 0.34)는
        // 회계상 완납으로 스냅 → 0. 여기가 미수 단일 출처(SKILLS §13)라 게이지·채권 KPI·
        // 진행상태(판매완료)·위험도가 전부 일관되게 완납 처리됨 (jin 2026-07-02).
        // 음수(과입금)는 건드리지 않음 — 환급 표시 보존. KRW는 정수라 영향 없음.
        return ($unpaid > 0 && $unpaid < 1) ? 0.0 : $unpaid;
    }

    /**
     * 회의확장씬 #7 (2026-05-22) — 실제 받은 KRW 누적 (입금 시점 환율 반영).
     *
     * 사용자 명세: "당시 실시간 환율로 계산되어진 한국 금액을 옆에 표시"
     * 회계 (SKILLS §13 실입금 단일출처 — 2026-07-06 재피벗 규칙 #2):
     *   ① 잔금(final_payments, confirmed) = Σ(amount × row 환율). 환율 없으면 판매환율 fallback.
     *   ② 기타회수(receivable_histories, method≠deposit) = Σ(amount) × 판매환율.
     *      ReceivableHistory 엔 환율 컬럼이 없고, 기타회수는 소액·소수점 잔차의 회사 자체흡수분이라
     *      baseline 과 동일한 판매환율로 평가 → FX 중립(환차 0 기여). 프리랜서 2차 환차분에 새지 않음.
     *
     * 2차 정산 환차(재피벗) = 이 값(실입금KRW) − (sale_total_amount × 판매환율) baseline.
     * sale_unpaid_amount (외화) 와 별개 — KPI 분모 단일 출처 (SKILLS §13) 위반 없음.
     */
    public function getSaleReceivedKrwAccumulatedAttribute(): int
    {
        $saleRate = (float) ($this->exchange_rate ?? 1);

        // ① 잔금 — row 별 입금 시점 환율
        $finalKrw = $this->finalPayments
            ->whereNotNull('confirmed_at')
            ->sum(function ($p) use ($saleRate) {
                $rate = $p->exchange_rate !== null ? (float) $p->exchange_rate : $saleRate;

                return (float) $p->amount * $rate;
            });

        // ② 기타회수 — 판매환율 평가 (FX 중립)
        $receivableKrw = (float) $this->receivableHistories
            ->where('method', '!=', 'deposit')
            ->sum('amount') * $saleRate;

        // ③ 적립금 사용 — 크레딧이라 사용 시점 새 FX 없음 → 판매환율 평가(FX 중립).
        //   미수 accessor 가 savings_used 를 실입금으로 잡으므로(2026-07-09), 환차 baseline 과 대칭 맞춤.
        //   빠지면 적립금 결제분이 실입금KRW 에서 누락돼 환차가 거짓 손실로 계산됨.
        $savingsKrw = (float) ($this->savings_used ?? 0) * $saleRate;

        return (int) ($finalKrw + $receivableKrw + $savingsKrw);
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

    /**
     * 입고일 (재고관리, jin 2026-07-09) = 매입 완납일. 미완납/미등록이면 null(입고 전).
     * 완납일 ≈ 매입잔금을 0으로 만든 마지막 확정 지급일(payment_date ≤ today).
     */
    public function getWarehouseInDateAttribute(): ?Carbon
    {
        if ($this->purchase_price <= 0 || $this->purchase_unpaid_amount > 0) {
            return null;
        }
        $last = $this->purchaseBalancePayments()
            ->whereNotNull('confirmed_at')
            ->whereNotNull('payment_date')
            ->where('payment_date', '<=', now()->toDateString())
            ->max('payment_date');

        return $last ? Carbon::parse($last) : null;
    }

    /**
     * 재고 (jin 2026-07-09) = 매입 완납(입고됨) AND 출고일 없음. 진행상태 무관.
     *   미완납 = 입고 전(제외) / 출고일 찍힘 = 출고됨(제외).
     *   매입 미지급 식은 scopeAction('purchase_unpaid') 와 동일 단일 출처(≤ 0 반전).
     */
    public function scopeInStock($query)
    {
        return $query->where('purchase_price', '>', 0)
            ->whereNull('warehouse_out_date')
            ->whereRaw('(CAST(purchase_price AS SIGNED) + CAST(selling_fee AS SIGNED)
                         - COALESCE((SELECT SUM(amount) FROM purchase_balance_payments
                                      WHERE vehicle_id = vehicles.id
                                      AND payment_date IS NOT NULL AND payment_date <= ?
                                      AND confirmed_at IS NOT NULL), 0)) <= 0', [now()->toDateString()]);
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
        // 1단위 미만 외화 잔차는 sale_unpaid_amount 단일 출처에서 이미 0 스냅됨 (§13).
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
    /** 결제대기(grace) 유예 일수 — 선적 전 미수는 판매일+이 일수 지나야 채권 (jin 2026-07-06 A안). */
    public const RECEIVABLE_GRACE_DAYS = 10;

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

        // 결제대기 유예 (jin 2026-07-06, A안) — 선적 전(반입 전 = bl_loading_location 없음) 미수는
        //   판매일 + RECEIVABLE_GRACE_DAYS 지나야 채권. 그 전엔 'grace'(정상 결제 대기, 채권 아님).
        //   선적 후는 유예 없이 즉시 위험. ⚠️ 캐시 컬럼이라 시간 경과는 야간 rebuild(05:00)로 flip.
        if (blank($this->bl_loading_location) && $this->sale_date
            && $this->sale_date->copy()->addDays(self::RECEIVABLE_GRACE_DAYS)->startOfDay()->isFuture()) {
            return 'grace';
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
            'grace' => '결제대기',
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
            // 2026-06-18 ETA 영구 알람 — 알람 생성/자동해소 + 보정 섹션 (단일출처)
            'eta_clearance_reminder', 'eta_missing',
            // item 6 (2026-07-07) 서류마감 임박 알람 — 거래완료 시 자동해소
            'document_deadline_reminder',
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
                    ->orWhereNull('sale_unpaid_amount_krw_cache'))
                // 결제대기 유예 — 선적 전(bl_loading_location 없음)은 판매일+10일 지난 것만 알림 대상.
                //   grace(유예 중)는 제외. 선적 후는 즉시. (scopeExcludeReceivableGrace 단일 출처)
                ->excludeReceivableGrace(),
            'clearance_needed' => $q->where('sale_price', '>', 0)
                ->whereNotNull('sale_unpaid_amount_krw_cache')
                ->where('sale_unpaid_amount_krw_cache', '<=', 0)
                ->whereNull('export_declaration_document'),
            'shipping_needed' => $q->whereNotNull('export_declaration_document')
                ->whereNull('bl_document'),
            'dhl_needed' => $q->whereNotNull('bl_document'),

            // 운임/인코텀즈 확정 대기 (jin 2026-07-09) — 완납이지만 운임 게이트에 막혀 정산 미생성.
            //   activeOnly 아님: 거래완료여도 운임 미확정이면 정산 안 떠서 여기 남아야 함.
            'freight_confirm_pending' => $q->awaitingFreightConfirm(),

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

            // 2026-06-18 ETA 영구 알람 (v1) — 단일출처. alarms:scan 이 생성/자동해소 둘 다 이 스코프로 판정.
            //   도착(eta_date) N일 이내 + 수출신고서 미업로드 + export 채널 (active = 거래완료 제외).
            //   리드데이 N = Setting('alarm_eta_lead_days', 기본 10).
            'eta_clearance_reminder' => $q
                ->where('sales_channel', 'export')
                ->whereNotNull('eta_date')
                ->where('eta_date', '<=', now()->addDays((int) Setting::get('alarm_eta_lead_days', 10))->toDateString())
                ->whereNull('export_declaration_document'),

            // item 6 (2026-07-07) 선적 서류마감 임박 — 마감일 N일 이내(기본 5). '관리' 대상 알람.
            //   active 한정(거래완료 제외, $activeOnly). 서류마감일 입력된 차량만. 마감 지나도 유지(overdue 표시).
            'document_deadline_reminder' => $q
                ->whereNotNull('document_deadline_date')
                ->where('document_deadline_date', '<=', now()->addDays((int) Setting::get('alarm_doc_deadline_lead_days', 5))->toDateString()),

            // 2026-06-18 데이터 보정 — 선적(반입)됐는데 도착일(ETA) 미입력 (수출통관 보드 보정 섹션).
            //   '알림' 아닌 '데이터 품질' — 벨 알람으로 안 띄움. ETA 채우면 자동으로 목록에서 빠짐.
            'eta_missing' => $q
                ->where('sales_channel', 'export')
                ->whereNotNull('shipping_date')
                ->whereNull('eta_date'),
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
                ->where('sale_unpaid_amount_krw_cache', '>', 0)
                // 결제대기(grace) 제외 — 판매일+10일 미경과 선적전 미수는 아직 채권 아님 (jin 2026-07-06).
                ->excludeReceivableGrace(),

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

    /**
     * 결제대기(grace) 차량을 채권 집계에서 제외 — grace = 선적 전(반입지 없음) + 판매일+유예일 미경과 미수.
     * jin 2026-07-06: "결제대기는 아직 채권 아님". 채권금액 총액(채권관리·관리자/업무 대시보드)에서 빠져야 함.
     *
     * 판정은 캐시(receivable_risk) 대신 sale_date 로 = fresh(야간 rebuild 대기 없이 판매일+10일 정확 flip),
     * scopeAction('sale_unpaid')·채권관리 before_shipping 탭과 동일 단일 기준(SKILLS §13). grace 는 선적
     * 전에만 성립하므로, 선적 후(반입지 입력) 미수는 이 스코프로 절대 제외되지 않는다(즉시 채권). sale_date
     * NULL(판매가 있으나 날짜 미상 — chk_sale_required 상 실질 없음)은 grace 아님으로 간주해 유지한다.
     */
    public function scopeExcludeReceivableGrace(Builder $q): Builder
    {
        return $q->whereNot(fn ($q2) => $q2
            ->whereNull('bl_loading_location')
            ->whereNotNull('sale_date')
            ->where('sale_date', '>', now()->subDays(self::RECEIVABLE_GRACE_DAYS)->toDateString()));
    }

    /**
     * 결제대기(grace)만 — scopeExcludeReceivableGrace 의 정확한 여집합(선적 전·판매일+유예일 미경과).
     * 대시보드/채권관리 "결제대기" 카드(제외된 미수를 따로 표시)용. 호출측에서 미수>0 필터 추가.
     */
    public function scopeOnlyReceivableGrace(Builder $q): Builder
    {
        return $q->whereNull('bl_loading_location')
            ->whereNotNull('sale_date')
            ->where('sale_date', '>', now()->subDays(self::RECEIVABLE_GRACE_DAYS)->toDateString());
    }
}
