<?php

namespace App\Models;

use App\Services\LockThresholdResolver;
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
    // 문자열 앞뒤 공백 자동 제거 (jin 2026-08-06) — '19더9065' 와 '19더9065 ' 가 두 값으로 갈려
    //   검색이 나뉘던 문제. 저장 경로(화면·엑셀 import·purchase-sync API) 전부에 걸린다.
    use Concerns\TrimsStringAttributes;
    use SoftDeletes;

    /** 매입취소 상태 (jin 2026-07-18) — 위약금은 sale_price·채권 배관 재사용, progress 오염만 이 마커로 분리. */
    public const CANCEL_NONE = 'none';

    public const CANCEL_ACTIVE = 'cancelled';        // 매입취소 — 위약금 채권 추적(미수>0=진행, 미수0=취소완료 표시)

    public const CANCEL_CLOSED = 'cancelled_closed'; // 미수 마감 — 못 받고 종료(프리랜서 손실 절반 부담)

    /** 매입취소(진행/완료/마감 어느 단계든) 여부. progress·정산·판매KPI 분기 단일 출처. */
    public function isPurchaseCancelled(): bool
    {
        return ($this->cancel_status ?? self::CANCEL_NONE) !== self::CANCEL_NONE;
    }

    /** 매입취소 상태 한글 라벨 (배지·목록용). null=정상. '취소완료'는 미수0에서 계산(별도 저장 안 함). */
    public function getCancelStatusLabelAttribute(): ?string
    {
        return match ($this->cancel_status) {
            self::CANCEL_ACTIVE => $this->sale_unpaid_amount <= 0 ? '취소완료' : '매입취소',
            self::CANCEL_CLOSED => '미수마감',
            default => null,
        };
    }

    /**
     * 미수 마감 시 담당자(프리랜서) 부담 몫 = 동결 부족분(cancel_shortfall_krw)의 절반.
     * 사내직원(employee) / 미마감 / 미동결 = 0 (회사 전액 부담). 월배치 조정 입력 기준값.
     */
    public function getCancelFreelancerLossKrwAttribute(): int
    {
        if ($this->cancel_status !== self::CANCEL_CLOSED || $this->cancel_shortfall_krw === null) {
            return 0;
        }

        return $this->salesman?->type === 'freelance' ? intdiv((int) $this->cancel_shortfall_krw, 2) : 0;
    }

    /**
     * 미반영 매입취소 손실 — 담당자별 프리랜서 부담 몫 (jin 2026-08-06).
     *
     * 정산관리 담당자 카드에 "필터 무관 현재 잔액"으로 노출한다(unconsumed_carryover 와 같은 성격).
     * 실무자가 정산관리만 보고 있어서 월배치 지급 화면의 손실 요약을 놓친다는 제보에서 나왔다.
     *
     * ⚠️ **표시 전용이다.** 실제 차감은 「월배치 지급」의 담당자 조정에서 한 번만 하고,
     *    거기서 「반영 표시」를 누르면 cancel_loss_settled_at 이 찍혀 이 목록에서 빠진다.
     *    정산 합계(actual_payout_sum)에 더하면 월배치와 **이중 청구**가 된다.
     *
     * 기간 필터를 받지 않는다 — 월배치 쪽은 cancelled_at 기간(지급 실행 축)으로 거르지만,
     * 여기는 "지금 남아 있는 미반영 잔액"이 알고 싶은 값이라 축이 다르다.
     *
     * ⚠️ `vehicle_ids` 를 함께 준다 — 호출부가 차량번호로 id 를 되찾으면 안 된다.
     *    운영에 **같은 차량번호가 여러 행** 존재한다(재등록·중복입력). 번호로 조회하면 엉뚱한 차에 도장이 찍힌다.
     *
     * @return array<int, array{sum: int, plates: array<int, string>, vehicle_ids: array<int, int>}>
     */
    public static function unsettledCancelLossBySalesman(): array
    {
        $rows = static::query()
            ->where('cancel_status', self::CANCEL_CLOSED)
            ->whereNull('cancel_loss_settled_at')
            ->whereNotNull('cancel_shortfall_krw')
            ->whereNotNull('salesman_id')
            ->with('salesman:id,type')
            ->get(['id', 'vehicle_number', 'salesman_id', 'cancel_status', 'cancel_shortfall_krw']);

        $out = [];
        foreach ($rows as $v) {
            $half = $v->cancel_freelancer_loss_krw;   // 사내직원 = 0 (회사 전액 부담)
            if ($half <= 0) {
                continue;
            }
            $sid = (int) $v->salesman_id;
            $out[$sid] ??= ['sum' => 0, 'plates' => [], 'vehicle_ids' => []];
            $out[$sid]['sum'] += $half;
            $out[$sid]['plates'][] = $v->vehicle_number;
            $out[$sid]['vehicle_ids'][] = (int) $v->id;
        }

        return $out;
    }

    protected $fillable = [
        'vehicle_number', 'sales_channel', 'progress_status_cache',
        'progress_status_rule_version', 'is_override_active',
        'receivable_risk', 'sale_unpaid_amount_krw_cache', 'receivable_manager_id',
        'cancel_status', 'cancelled_at', 'cancel_shortfall_krw', 'cancel_loss_settled_at',
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
        'purchase_date', 'warehouse_out_date', 'stock_location', 'stock_location_note',
        'salesman_id', 'purchase_from', 'purchase_source', 'c_no', 'purchase_price', 'selling_fee',
        'purchase_evidence_type', 'purchase_partner_type',   // karaba (구) flat — 존치(데이터 안전)
        'purchase_registration_type', 'purchase_evidence_subtype', 'is_dealer_purchase',   // karaba 2단 캐스케이드 + 매매상 체크 (Phase 1, 2026-07-22)
        'is_deposit_purchase', 'deposit_purchase_at',   // 보증금 매입 마커 + 도장 일시 — 재무 C2 선지급 확정 시 자동 set (2026-07-23)
        'is_unsecured_down',   // 무담보로 지급한 계약금 표시 (jin 2026-08-10) — 회사가 바이어 대신 낸 것만 체크
        'has_mortgage',        // 저당 설정 표시 (jin 2026-08-21) — 딜러 입금완료 알림톡의 저당 해지 요청 문구를 가른다
        // 큐 20-A — 매입처 계좌 4컬럼 (purchase_seller_account encrypted)
        'purchase_seller_bank', 'purchase_seller_account', 'purchase_seller_holder', 'purchase_bank_memo',
        // 2026-07-03 — 매도비 계좌 3컬럼 (purchase_fee_account encrypted). 매입가 계좌와 별도 주체.
        'purchase_fee_bank', 'purchase_fee_account', 'purchase_fee_holder',
        'cost_deregistration', 'cost_license', 'cost_towing', 'cost_carry',
        'cost_shoring', 'cost_insurance', 'cost_transfer', 'cost_extra1', 'cost_extra2',
        'cost_inspection', 'cost_performance', 'cost_repair', 'cost_advertising',   // karaba 비용 4개 (Phase 2, 2026-07-22)
        'parts_amount',   // karaba 부품 기록(미추적 — 미수·정산·매출 제외)
        'transport_fee_usd',   // 운임비 USD 기록칸(순수 메모 — 어떤 계산에도 미포함, jin 2026-08-05)
        'purchase_vat_amount',   // karaba 매입세액VAT (Phase 3 — 이익율 정산 영업이익 계산)
        // 큐 22-C-E (2026-05-20) — down_payment / selling_fee_payment DROP.
        // 2컬럼은 purchase_balance_payments.type enum (down/selling_fee) 로 통합.
        'purchase_remittance_memo',
        'registration_number', 'reg_cert_number',
        'is_deregistered', 'deregistration_document', 'deregistration_notice_phone',
        'sale_date', 'currency', 'exchange_rate', 'buyer_id', 'buyer_undecided', 'consignee_id',
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
        'bl_loading_location', 'vessel_name', 'bl_document', 'checkbill_document', 'bl_type', 'bl_issue_date', 'document_deadline_date',
        'dhl_recipient_name', 'dhl_recipient_address', 'dhl_recipient_phone',
        'dhl_sender_name', 'dhl_sender_address', 'dhl_weight', 'dhl_dimensions',
        'dhl_request', 'memo',
        // 탭별 메모 5칸 (2026-08-11) — 목록은 self::TAB_MEMOS 단일 출처.
        'memo_purchase', 'memo_sale', 'memo_clearance', 'memo_shipping', 'memo_bl',
        // Phase 3 서류 자동기입 (2026-05-24) — NICE 원본 보관 + 말소일 + 기통수
        'nice_raw', 'deregistration_date', 'nice_spec_cylinders',
    ];

    protected $casts = [
        'is_deregistered' => 'boolean',
        'is_export_cleared' => 'boolean',
        'forwarding_email_sent' => 'boolean',
        'dhl_request' => 'boolean',
        'is_dealer_purchase' => 'boolean',
        'buyer_undecided' => 'boolean',
        'is_deposit_purchase' => 'boolean',
        'is_unsecured_down' => 'boolean',
        'has_mortgage' => 'boolean',
        'deposit_purchase_at' => 'datetime',
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
        'cancelled_at' => 'datetime',
        'cancel_shortfall_krw' => 'integer',
        'cancel_loss_settled_at' => 'datetime',
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

    /**
     * karaba 전용 매입 기본비용 (jin 2026-07-12) — 말소비 17,300 자동 default,
     * 면허비·탁송비 0(엑셀 명세서 업로드로 나중 기입). heyman/ssancar 는 위 기본값 유지.
     */
    public const DEFAULT_PURCHASE_COSTS_KARABA = [
        'cost_deregistration' => 17300,
        'cost_license' => 0,
        'cost_towing' => 0,
    ];

    /** 회사 프로파일별 매입 기본비용 단일 출처 — karaba면 karaba 세트, 그 외 공통 세트. */
    public static function defaultPurchaseCosts(): array
    {
        return Setting::isKaraba() ? self::DEFAULT_PURCHASE_COSTS_KARABA : self::DEFAULT_PURCHASE_COSTS;
    }

    /**
     * karaba 매입탭 2단 캐스케이드 (Phase 1, 2026-07-22, jin 확정 — 매입탭.png).
     *   1단 매입등록(purchase_registration_type) → 2단 증빙유형(purchase_evidence_subtype) 자동 필터.
     *   매매상은 별도 체크박스(is_dealer_purchase)로 분리 — 캐스케이드와 독립, 잔금 10일 알림 트리거.
     *   ⚠️ 맵은 정본 그대로 — 임의 변경 금지. 구매대행·선적대행은 2단 없음.
     */
    public const KARABA_REGISTRATION_TYPES = ['일반매입', '의제매입', '혼합매입', '리스/캐피탈', '구매대행', '선적대행'];

    public const KARABA_EVIDENCE_CASCADE = [
        '일반매입' => ['세금계산서', '대체서류', '영세율 및 기타'],
        '의제매입' => ['개인', '개인사업자(비사업용 차량확인서)', '간이과세자'],
        '혼합매입' => ['의제매입+세금계산서', '의제매입+대체서류', '세금계산서+대체서류'],
        '리스/캐피탈' => ['세금계산서+의제매입', '승계서+의제매입', '불공제'],
        '구매대행' => [],
        '선적대행' => [],
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
        if (! Setting::lockEnabled('bl_issue')) {
            return;   // 🔒 락 관제 — B/L 발행 락 OFF (super 토글). grandfather·우회는 락 ON일 때만 평가.
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

        if ($ratio <= Setting::lockThreshold('bl_issue')) {
            return;   // 필요 입금률 충족(기본 100%=완납) — 발행 가능
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

        if (! $this->hasClosedSecondarySettlement()) {
            return;   // 2차 정산 마감(closed) 전 — 자유 수정 (정산이 차익으로 흡수, jin 2026-07-24)
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
     * 큐 21 — confirmed 잔금 존재 여부.
     * finalPayments OR purchaseBalancePayments 중 confirmed_at IS NOT NULL 1건이라도 있으면 true.
     * 2026-07-24 정산 락 개편 이후: 차량 회계컬럼 락(guardLedgerLockOnSaving)의 트리거에서는 제외되고,
     *   차량 삭제 가드(파괴적 액션)·삭제 모달 판정에만 사용된다. 금액 수정 락은 hasClosedSecondarySettlement.
     */
    public function hasConfirmedPaymentLock(): bool
    {
        return $this->finalPayments()->whereNotNull('confirmed_at')->exists()
            || $this->purchaseBalancePayments()->whereNotNull('confirmed_at')->exists();
    }

    /**
     * 정산 락 개편 (jin 2026-07-24) — 차량 회계컬럼·잔금 소급 수정 락의 단일 트리거.
     * 2차 정산이 secondary_status='closed'(이월·환차 확정·동결)된 차량만 잠근다.
     *   근거: 마진은 computed라 마감(closed) 전 앞단 수정은 carryover_out 으로 자동 흡수됨.
     *   closed 시점이 이월 확정·동결 지점이라 흡수할 다음 단계가 없어 여기가 최종 락 경계.
     *   confirmed 잔금만 있고 정산이 없거나 아직 안 닫힌 차량은 자유 수정(정산이 차익으로 걸러냄).
     */
    public function hasClosedSecondarySettlement(): bool
    {
        return $this->settlements()->where('secondary_status', 'closed')->exists();
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
        'cost_inspection', 'cost_performance', 'cost_repair', 'cost_advertising', 'purchase_vat_amount',
        'buyer_id', 'salesman_id',
        // 2026-08-09 (jin) — 바이어 미정 매입(투기). 미수 통제를 우회하는 명시적 예외라 누가 켰는지 추적한다.
        'buyer_undecided',
        // 2026-05-19 풀회의 P0-3 — 말소 처리 actor 책임 추적 (4 role 누구나 처리 시 감사 필수).
        'is_deregistered', 'deregistration_document',
        // 큐 22-C-light (2026-05-20) Security 해소조건 — 매입처 계좌 4컬럼 변경 audit.
        // purchase_seller_account는 AuditLog::MASKED_COLUMNS 통해 마스킹 저장.
        'purchase_seller_bank', 'purchase_seller_account', 'purchase_seller_holder', 'purchase_bank_memo',
        // 2026-07-03 — 매도비 계좌 3컬럼 변경 audit (purchase_fee_account 는 MASKED_COLUMNS 마스킹).
        'purchase_fee_bank', 'purchase_fee_account', 'purchase_fee_holder',
        // 2026-08-11 (jin) — 탭별 메모 5칸. 돈·통관·선적 얘기가 적히는 자리라 누가 언제 바꿨는지 남긴다
        //   (`purchase_bank_memo` 가 이미 감사 대상인 것과 같은 이유). 공통 `memo` 는 종전대로 비감사.
        ...self::TAB_MEMOS,
        // 2026-07-28 (jin) — 출고일. 찍는 순간 차량이 재고에서 빠지고(scopeInStock) 선적전/후 미수
        //   분류 pivot 도 바뀌는데 누가 언제 처리했는지 기록이 없었다. 되돌림(비우기)도 추적 대상.
        'warehouse_out_date',
        // 2026-07-28 (jin) — 선적일·ETA. 묶음 단위로 수백 대를 한 번에 바꾸는 일괄 지정이 생겨서,
        //   누가 언제 어느 값으로 돌렸는지 개별 추적이 필요해졌다. (일괄 출처는 bulk_shipping_date_applied 로 별도 기록.)
        //   2026-08-12 — 선박명도 같은 도구에 합류(jin). 잘못 덮으면 다른 배에 실린 차의 배 이름이
        //   수백 대 단위로 날아가는데, 그걸 되짚을 기록이 없었다.
        'shipping_date', 'eta_date', 'vessel_name',
        // 2026-07-30 (jin) — 보증금 매입 마커. 켜는 순간 바이어 입금 독촉 알림톡 타이머가 돌기 시작하고,
        //   끄면 독촉이 멈춘다. 누가 언제 켰는지 없으면 "왜 독촉이 오냐/안 오냐"를 못 따진다.
        'is_deposit_purchase',
        'is_unsecured_down',   // 돈의 출처 기록 — 누가 언제 표시했는지 추적 필요
        // 2026-08-21 (jin) — 저당 표시. 딜러에게 나가는 문장을 좌우하고 해제가 수동이라 추적한다.
        'has_mortgage',
        // 2026-08-12 (board 인계) — board 선적 계획이 원장에 쓰는 첫 두 컬럼.
        //   포워딩사는 채우는 순간 관리 할 일 큐(`forwarding_missing`)에서 그 차가 빠진다 —
        //   "영업이 잘못 골라도 관리가 눈치챌 기회가 사라진다" 는 우려의 유일한 대응이 이 기록이다.
        //   운임비(USD)는 회계 미포함 메모지만 서류·정산 대화에 쓰이므로 같이 남긴다.
        'forwarding_company_id', 'transport_fee_usd',
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
        'cost_inspection', 'cost_performance', 'cost_repair', 'cost_advertising', 'purchase_vat_amount',
        // Tier 2 — 관계 식별
        'buyer_id', 'salesman_id',
    ];

    /**
     * 회수이력 중 **미수 합산에서 빼야 하는** 방법들 (2026-07-28).
     * 둘 다 다른 컬럼으로 이미 미수에 반영되는 "미러" 기록이라, 회수이력 합에 또 넣으면 이중 차감된다.
     *   - deposit  → final_payments 로 미러 (ReceivableHistory::syncFinalPayment)
     *   - savings  → vehicles.savings_used 로 미러 (ReceivableHistory::syncSavingsUsed)
     * 미수 accessor 와 실입금KRW(환차 baseline) 양쪽이 이 상수를 공유한다 — 새 미러 방법이 생기면 여기만 추가.
     */
    public const MIRRORED_RECEIVABLE_METHODS = ['deposit', 'savings'];

    /**
     * 판매탭發 savings_used 변경 시 회수이력 미러 행 생성을 건너뛰는 플래그 (2026-07-28).
     * 채권관리에서 적립금 행을 만들면 그 행이 savings_used 를 갱신하는데, 그때 H6 가 또 미러 행을
     * 만들면 이력이 2배가 된다 → ReceivableHistory 가 이 플래그를 try/finally 로 세운다.
     */
    public static bool $skipSavingsHistory = false;

    /**
     * 차량 편집 **탭별 메모** — `탭 key => 컬럼`. 화면·모델·감사가 전부 이 목록 하나를 쓴다 (jin 2026-08-11).
     *
     * 종전엔 메모가 `memo` 하나뿐이었는데 그 입력칸이 탭 컨테이너 **밖**에 있어 8개 탭 어디서든
     * 같은 박스가 보였다(공유 버그가 아니라 애초에 한 칸). 라벨엔 그 사정이 없어 "탭마다 따로 쓰는
     * 칸"으로 읽혔다 — jin 제보.
     *
     * ⚠️ **공통 `memo` 는 살려 둔다.** 운영 데이터가 들어 있고 "차량 전체에 대한 한마디" 자리는 여전히 쓴다.
     * 🧭 탭을 늘리려면 **여기 한 줄 + 마이그레이션 + 그 탭 blade 한 줄**. 화면이 이 목록을 돌므로
     *    라벨·저장·감사는 자동으로 따라온다(열거를 복제하지 말 것 — SKILLS §8 #45).
     */
    public const TAB_MEMOS = [
        'purchase' => 'memo_purchase',
        'sale' => 'memo_sale',
        'clearance' => 'memo_clearance',
        'shipping' => 'memo_shipping',
        'bl' => 'memo_bl',
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
            // 차량번호 앞뒤 공백 제거 (jin 2026-07-27) — 엑셀 적재·복붙에서 " 239수1388" / "114마1731  "
            //   처럼 공백이 섞여 들어와 검색·중복판정이 어긋났다. 진입점 통합(UI·시드·import 전부).
            if (is_string($vehicle->vehicle_number)) {
                $vehicle->vehicle_number = trim($vehicle->vehicle_number);
            }

            // 바이어 미정 매입 (jin 2026-08-09) — 바이어가 실제로 정해지면 플래그를 자동으로 내린다.
            //   안 내리면 뱃지가 영영 남아 "미정인데 바이어가 있는" 모순 상태로 보인다.
            //   진입점 통합(UI·import·API 전부) — 사람이 체크를 해제하는 걸 잊어도 안전하다.
            if ($vehicle->buyer_id && $vehicle->buyer_undecided) {
                $vehicle->buyer_undecided = false;
            }

            // 🚚 출고일 자동 채움 (jin 2026-08-20) — B/L 이 나왔는데 출고일이 비어 있으면 **선적일**로 채운다.
            //   왜 필요한가: 2026-07-23 에 "거래완료면 출고일 미입력이어도 재고 아님" 으로 정하면서,
            //   B/L 이 먼저 나온 차는 재고 화면에서 사라져 **출고일을 찍을 경로가 없어졌다**
            //   (실측 heymanerp: 거래완료 100대 중 92대가 출고일 공란 — 실무자 잘못이 아니라 화면이 없었다).
            //   그런데 채권 선적전/후 pivot 은 출고일이라, 이미 떠난 차가 「선적전 미수」로 남았다.
            //   ⚠️ **비어 있을 때만** 채운다 — 사람이 넣은 날짜를 저장할 때마다 덮으면 안 된다(SKILLS §8 #38).
            //   ⚠️ 값은 선적일이라 실제 출고보다 늦다(운영 129대 실측: 출고가 평균 10일 먼저).
            //      계산에 쓰이는 곳은 없고(청산가치는 2026-07-31 에 선적일 기준으로 이동) 정렬·표시뿐이라 무해하다.
            //      정확한 날짜를 아는 실무자는 재고관리 출고완료 탭에서 그대로 고칠 수 있다.
            //   ⚠️ 판정은 **진행상태 「거래완료」** 다(jin) — `bl_document` 유무로 보면 grandfather(v1~v3) 차량에서
            //      어긋난다. 그리고 `progress_status_cache` 가 아니라 **computed `progress_status`** 를 본다:
            //      캐시는 이 훅 뒤(742행)에서 갱신되므로 여기선 아직 옛 값이라 한 박자 늦게 채워진다.
            if ($vehicle->progress_status === '거래완료'
                && blank($vehicle->warehouse_out_date)
                && filled($vehicle->shipping_date)) {
                $vehicle->warehouse_out_date = $vehicle->shipping_date;
            }

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
                $oldDecl = (float) $vehicle->getOriginal('export_declaration_amount');
                $oldTotal = (float) (
                    $vehicle->getOriginal('sale_price') + $vehicle->getOriginal('transport_fee')
                    + $vehicle->getOriginal('sale_other_costs') + $vehicle->getOriginal('commission')
                    + $vehicle->getOriginal('auto_loading') - $vehicle->getOriginal('tax_dc')
                );
                // ⚠️ isDirty('export_declaration_amount') 는 쓰지 말 것 — decimal 컬럼은 DB가 "5200.00"(문자열)로
                //   반환하는데 폼 float 5200.0 은 (string) 변환 시 "5200" 이라 Laravel originalIsEquivalent 의
                //   strcmp("5200","5200.00")≠0 → 값이 같아도 항상 dirty 오탐 → 추종(②)이 절대 안 걸림
                //   (2026-07-10 jin 버그신고 "기타비용 넣어도 면장 안 따라옴"). 대신 면장 값의 실제 숫자 변화로 판정.
                $declManuallyChanged = abs($curDecl - $oldDecl) >= 0.01;
                if ($curDecl <= 0) {
                    $vehicle->export_declaration_amount = $newTotal;                              // ① 미입력
                } elseif (! $declManuallyChanged && abs($oldDecl - $oldTotal) < 0.01) {
                    $vehicle->export_declaration_amount = $newTotal;                              // ② 자동복사분 → 추종
                }
                // ③ else 보존 (수동 CIF/FOB 또는 이번 저장에 면장 직접 편집)
            }

            /*
             * 보증금 매입 도장 (jin 2026-07-30) — 독촉 알림톡(erp_deposit_cash_due/overdue) 타이머 기산점.
             *
             * 구 트리거였던 재무 '매입 선지급 확정'(confirmPurchaseFundingByFinance)이 2026-07-29 에
             * 제거되면서 이 마커를 찍는 코드가 사라져 독촉 대상이 영구 0건이 돼 있었다. 이제 매입 탭
             * 체크박스가 유일한 진입점이고, 도장 시각은 여기서 찍는다(진입점 통합 — UI·시드·tinker 동일).
             *
             * ⚠️ 최초 1회만 — 껐다 켜는 게 아니라 계속 켜져 있는 한 첫 도장일을 보존한다(구 훅과 같은 규칙).
             *   D+5/D+10 을 세는 기준이라 저장할 때마다 갱신되면 독촉이 영원히 안 온다.
             * 해제하면 시각도 비운다 — "플래그가 켜져 있을 때만 시각이 있다"는 단순 불변식 유지.
             *   (AlimtalkDepositCash 는 둘 다 요구하므로 잔재가 남아도 오발송은 없지만, 재체크 시
             *    옛 날짜로 즉시 초과 독촉이 나가는 걸 막는다.)
             */
            if ($vehicle->is_deposit_purchase) {
                $vehicle->deposit_purchase_at ??= now();
            } else {
                $vehicle->deposit_purchase_at = null;
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

        /*
         * 정산 동반 삭제 (jin 2026-07-27) — 차량만 지우고 정산이 남아 **고아 정산**이 되는 걸 막는다.
         *   실사고: 2026-07-23 heymanerp 에서 차량 3대를 지우고 같은 번호로 재등록 → 옛 정산 3건이 그대로
         *   남아 정산 목록에 "차량번호 없는 행"으로 뜨고 같은 차가 두 번 계상됐다.
         *
         * ⚠️ 위 가드와 **별도 리스너**로 등록한다 — 위 훅은 시드·admin 에서 early return 하는데,
         *   이번 사고를 낸 것도 admin 이다. 동반 삭제는 누가 지우든 항상 돌아야 한다.
         *
         * 잠긴 정산(confirmed/paid/closed)이 하나라도 있으면 **아무것도 지우지 않고** 중단한다
         * (부분 삭제 방지 — soft delete 는 트랜잭션으로 안 묶여 있어 검사를 먼저 끝낸다).
         * 조건은 Settlement::deleting 가드와 동일하게 유지할 것.
         *
         * restore() 는 정산을 되살리지 않는다 — 거래완료 시 자동 재생성되므로 의도적으로 둔다.
         */
        static::deleting(function (Vehicle $vehicle) {
            $settlements = $vehicle->settlements()->get();

            foreach ($settlements as $s) {
                if (in_array($s->settlement_status, ['confirmed', 'paid'], true)
                    || $s->secondary_status === 'closed') {
                    throw new \DomainException('정산이 확정·지급된 차량은 삭제할 수 없습니다. 정산을 먼저 정리하세요.');
                }
            }

            foreach ($settlements as $s) {
                $s->delete();
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

            // 2026-07-28 (jin) — 적립금 사용을 채권관리에서도 쓰게 되면서, 판매탭에서 바뀐 분도
            //   회수이력에 남긴다. 안 남기면 "이력 합 ≠ savings_used" 가 되고, 판매탭 사용분은
            //   지금처럼 누가 언제 썼는지 추적이 안 된다. 채권관리發 변경은 이미 자기 행이 있으므로 skip.
            if (! self::$skipSavingsHistory) {
                ReceivableHistory::$skipSavingsSync = true;   // 이 행이 savings_used 를 또 건드리면 이중 반영
                try {
                    ReceivableHistory::create([
                        'vehicle_id' => $vehicle->id,
                        'collected_at' => now()->toDateString(),
                        'collector_id' => auth()->id(),
                        'method' => 'savings',
                        'amount' => $delta,
                        'note' => __('receivable.savings.auto_note'),
                    ]);
                } finally {
                    ReceivableHistory::$skipSavingsSync = false;
                }
            }
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

        /**
         * board 매입 신호 자동 해소 — 매입 미지급 0 이면 신호가 스스로 꺼진다.
         *
         * 권위 = docs/integration/board-portal-api.md §11. **완료 버튼을 만들지 않는 것이 핵심**이다
         * (누를 사람이 있으면 결국 카톡으로 돌아간다 — jin 2026-08-07).
         * 매입 잔금은 `PurchaseBalancePayment::saved` → `refreshCaches()` → 저장을 타므로 여기서 잡힌다.
         */
        static::saved(function (Vehicle $vehicle) {
            static $hasBoardRequestTable = null;
            if ($hasBoardRequestTable === null) {
                $hasBoardRequestTable = Schema::hasTable('board_requests');
            }
            if (! $hasBoardRequestTable) {
                return;
            }
            $vehicle->resolveAutoClosingBoardRequests();
        });
    }

    /**
     * 매입 미지급이 0 이하면 **자동소멸 대상 신호**를 닫는다. 사람이 안 누른다(confirmed_by = null).
     * 야간 보정 없이 즉시 반영 — 재무가 지급을 기입하는 순간 뱃지가 사라져야 자연스럽다.
     *
     * 🚫 **대상을 여기서 열거하지 말 것** — 단일 출처는 `BoardRequest::TYPE_META['auto_resolve']` 다.
     *    특히 **계약금(purchase_deposit)은 대상이 아니다**: 미지급 0 = 잔금까지 다 준 상태라,
     *    그때 계약금 신호가 꺼지면 "계약금 아직 안 보냈다"는 거짓 신호가 차 인수 시점까지 남는다.
     *    ERP 는 계약금을 지급했는지 알 방법이 없다(금액을 회계에 안 쓰므로) → 수동 확인만.
     *    가드 = `BoardRequestAutoResolveTest::test_deposit_request_survives_full_payment`.
     */
    public function resolveAutoClosingBoardRequests(): void
    {
        $open = BoardRequest::query()
            ->where('vehicle_id', $this->id)
            ->whereIn('type', BoardRequest::typesWith('auto_resolve'))
            ->open()
            ->get();

        // 열린 요청이 없는 게 대부분 — 인덱스 조회 1회로 끝내고 미지급 계산조차 하지 않는다.
        if ($open->isEmpty()) {
            return;
        }

        // ⚠️ 관계 리로드 필수 — 이 훅은 `PurchaseBalancePayment::saved` → `refreshCaches()` 경로로도
        //    불리는데, 그때 $this 에 이미 로드된 purchaseBalancePayments 는 **방금 만든 잔금을 모른다**.
        //    리로드를 빼면 "전액 지급했는데 뱃지가 안 꺼진다"가 된다(실측으로 밟음).
        $this->load('purchaseBalancePayments');

        if ($this->purchase_unpaid_amount > 0) {
            return;
        }

        $open->each(fn (BoardRequest $r) => $r->markDone());
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

            // 환율 = 이 차량 판매환율. 사용(USED) 행의 원화 가치는 FIFO 로 소진되는 **적립분의 환율**로
            // 정해지므로(SavingsLedger) 여기 값은 참고용이다. REFUND(delta<0)는 되돌아온 크레딧이
            // 다시 유입 lot 이 되는데, 그 원화 가치는 이 차량 환율이 맞다.
            SavingsStatus::create([
                'buyer_id' => $this->buyer_id,
                'vehicle_id' => $this->id,
                'currency' => $this->currency,
                'exchange_rate' => ($this->exchange_rate > 0) ? $this->exchange_rate : null,
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

            // ⭐ 적립 시점 판매환율을 박제한다 (jin 2026-07-29) — 바이어탭 원화 병기의 근거값이고,
            //    나중에 이 lot 이 FIFO 로 소진될 때의 원화 가치도 이 환율로 정해진다.
            SavingsStatus::create([
                'buyer_id' => $this->buyer_id,
                'vehicle_id' => $this->id,
                'currency' => $this->currency,
                'exchange_rate' => ($this->exchange_rate > 0) ? $this->exchange_rate : null,
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
     * - C4: 말소(is_deregistered)가 완료돼야 통관 진입 가능 (2026-07-27 서류 요구 제외 — 아래 주석)
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

        // C4 — 말소 강제 (선적 전 말소 원칙)
        //   ⚠️ 2026-07-27 jin — 판정 기준에서 서류 업로드(deregistration_document) 제외, 말소 체크만 본다.
        //   실무는 말소를 먼저 처리하고 서류 파일은 나중에 올린다(운영 실측: 선적 진입 차량 중
        //   '체크됨+서류없음' 83대 / '체크 안 됨' 0대). 서류까지 요구하면 말소를 실제로 마친 차량이
        //   미완료로 판정돼, 이미 선적 정보가 있는 차량은 판매 탭 잔금 저장까지 통째로 막혔다.
        //   막아야 할 것은 "말소도 안 하고 배에 태우는 것"이지 "서류 업로드가 늦는 것"이 아니다.
        //   서류 자체는 진행상태 cascade('말소완료')와 서류 탭에서 계속 추적된다.
        if (! $this->is_deregistered) {
            throw ValidationException::withMessages([
                'export_buyer_id' => '말소 처리를 완료해야 통관·선적 진입이 가능합니다 — 매입 탭.',
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
        //   🔒 락 관제 — 선적 진입 락 OFF 시 이 블록(50%·환율 평가) 전체 skip. C4 말소완료·컨사이니 필수는 구조라 유지.
        if ($this->sale_price > 0 && $this->exists && Setting::lockEnabled('shipping_entry')) {
            // C5(50%) 진입 게이트 — 통관·선적은 동일 50% 관문이므로 진입 우회 1건(clearance∪shipping)이면 통과.
            //   (2026-07-01 jin: 입력 순서에 따라 stage 라벨이 clearance↔shipping 으로 갈려
            //    같은 미수·같은 50% 인데 우회를 2번 해야 하던 마찰 제거. 서버 실증 = 145나1447.)
            //   ⚠ B/L 발행 100% 우회 'bl' 은 별개 — G1(guardBlFiftyPercentRuleOnSaving)에서만 소비.
            //     진입 우회로는 안 쳐줌 (G1BlLockTest::test_g1_shipping_override_alone_does_not_bypass_bl 가드).
            if ($this->hasEntryUnpaidOverride()) {
                return;   // 진입 우회 승인 — 통관·선적 모두 통과
            }

            // 가드1 (jin 2026-07-18, item 2) — 선적대기 허용 항로: shipping_method=RORO + 도착항 마스터
            //   allow_shipping_wait 플래그. 해당 항로는 우회 없이 통관·선적 진입(선적대기 서류작업) 허용 →
            //   C5(50%) 게이트 skip. 하드코딩('알바니아 두레스') 대신 항구 마스터 데이터로 지정(관리자 편집).
            //   ⚠ 선적된 게 아니라 항구 주차장 대기 = 선적전 미수(warehouse_out_date pivot, item 3)로 유지.
            if ($this->shipping_method === 'RORO'
                && $this->discharge_port_id
                && $this->dischargePort?->allow_shipping_wait) {
                return;
            }

            // 외화 환율 미입력 → 미수율 평가 불가
            if ($this->currency !== 'KRW' && ((float) $this->exchange_rate <= 0)) {
                throw ValidationException::withMessages([
                    'export_buyer_id' => '환율 미입력 외화 차량은 통관·선적 진입 불가 — 판매 탭에서 환율을 입력하세요. (또는 관리자 미입금 우회 승인.)',
                ]);
            }

            $ratio = $this->unpaid_ratio;
            // 바이어별 재정의 우선 (jin 2026-08-21) — 없으면 전역. 승인 우회는 이 위 블록에서 이미 처리.
            $buyerForLock = $this->buyer;
            if ($ratio !== null && $ratio > LockThresholdResolver::threshold($buyerForLock, 'shipping_entry')) {
                $percent = number_format($ratio * 100, 1);
                $needPaid = LockThresholdResolver::requiredPaidPct($buyerForLock, 'shipping_entry');
                throw ValidationException::withMessages([
                    'export_buyer_id' => "판매 입금률 < {$needPaid}% (미수율 {$percent}%) 차량은 통관·선적 진입 불가. {$needPaid}% 이상 입금 또는 관리자 승인(미입금 우회) 후 진행하세요.",
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
     *   귀속월(attributed_month) = settlementAttributionMonth() — 완납월 기준, 단 완납월이 이미
     *     마감된 달이면 현재 열린 달로 이월 (jin 2026-07-18 "마감된 달은 동결" 규칙).
     *   type default(ratio/per_unit)는 null 위임(Setting 기반 자동 산정).
     *   ⚠️ 호출부는 auth()->check() 가드 필수 — 시드·artisan 대량 유입 차단(기존 거래완료 훅과 동일 정책).
     */
    public function createSettlementIfComplete(string $note): void
    {
        // 매입취소 차량은 정산 대상 아님 — 위약금을 sale_price 로 넣어도 정산 자동생성 원천 차단.
        //   (통화·인코텀즈 무관 명시 가드. 이전엔 외화+인코텀즈 미입력 우연 차단에만 의존 — jin 2026-07-18)
        if ($this->isPurchaseCancelled()) {
            return;
        }
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
            'attributed_month' => $this->settlementAttributionMonth(),
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

    /**
     * 정산 귀속월 (jin 2026-07-18) — 완납월 기준. 단 완납월이 이미 마감된 달(승인된 배치 존재)이면
     *   현재 열린 달로 이월한다. 사용자 규칙: "6월 마감되면 그 순간 끝. 6월자 잔금이 7월에 뒤늦게
     *   들어와도 6월에 우겨넣지 않고, 완성된 달(현재 열린 달)에 포함." 현재 달도 마감이면 다음 열린 달로.
     */
    public function settlementAttributionMonth(): string
    {
        $natural = $this->fullPaymentMonth();   // 'Y-m-01'
        if (! SettlementPayoutBatch::isMonthClosed(substr($natural, 0, 7))) {
            return $natural;
        }
        // 완납월이 마감됨 → 현재 열린 달로 이월 (이번 달부터 마감 안 된 첫 달).
        $cursor = now()->startOfMonth();
        while (SettlementPayoutBatch::isMonthClosed($cursor->format('Y-m'))) {
            $cursor->addMonth();
        }

        return $cursor->format('Y-m-d');
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

    /**
     * 실효 컨사이니 — 통관 → 선적 → (레거시) 판매 순 폴백. 목록·필터·엑셀의 단일 출처.
     *
     * 당사자 축소(jin 2026-07-09)로 판매 탭 컨사이니 입력칸이 사라져 `consignee_id` 는
     * 신규 차량에서 항상 빈다. 그런데 읽는 쪽(차량목록·재고목록·재고필터·엑셀)이 그 칸만 보고 있어
     * 컨사이니가 통째로 '-' 로 뜨고 재고 필터는 0건이 됐다(실측 2026-08-07:
     * ssancarerp 14대 전부 / heymanerp 07-09 이후 59대 전부 빈칸).
     * 서류(`DocValue::invoiceConsignee`)가 쓰던 3단 폴백과 같은 규칙으로 통일한다.
     *
     * ⚠️ 마지막 폴백(판매 칸)을 지우지 말 것 — 07-09 이전 차량(heymanerp 76대)은 거기에만 값이 있다.
     * ⚠️ 목록에서 쓸 땐 `with(['consignee','blConsignee','exportConsignee'])` 로 eager load (N+1).
     */
    public function getEffectiveConsigneeAttribute(): ?Consignee
    {
        return $this->exportConsignee ?: $this->blConsignee ?: $this->consignee;
    }

    /** 위 폴백의 SQL 판 — 우선순위 순. 필터(OR)·정렬(COALESCE)이 화면 표시와 갈리지 않게 하는 단일 출처. */
    public const CONSIGNEE_FALLBACK_COLUMNS = ['export_consignee_id', 'bl_consignee_id', 'consignee_id'];

    /** 컨사이니 필터 — 3칸 중 어디에 들어 있든 잡는다. */
    public function scopeWhereEffectiveConsignee(Builder $q, int|string $consigneeId): Builder
    {
        return $q->where(function (Builder $sub) use ($consigneeId) {
            foreach (self::CONSIGNEE_FALLBACK_COLUMNS as $col) {
                $sub->orWhere($col, $consigneeId);
            }
        });
    }

    /** 컨사이니 정렬용 SQL 식 — 표시값과 같은 우선순위. */
    public static function effectiveConsigneeSortExpression(): string
    {
        return 'COALESCE('.implode(', ', self::CONSIGNEE_FALLBACK_COLUMNS).')';
    }

    public function finalPayments(): HasMany
    {
        return $this->hasMany(FinalPayment::class);
    }

    /** board 요청·확인 신호 (§11). 목록에서 쓸 땐 `with(['boardRequests' => fn ($q) => $q->open()])`. */
    public function boardRequests(): HasMany
    {
        return $this->hasMany(BoardRequest::class);
    }

    /**
     * 열려 있는 board 신호 종류 — 차량관리 목록·드로어 뱃지 단일 출처.
     *
     * jin 2026-08-09: 신호는 **차량관리에서 뱃지로 바로 보여야 한다**(보증금 매입 뱃지와 같은 자리).
     * 재무 처리 화면으로 건너가 기입하는 흐름은 실무와 안 맞는다 — 차값·계약금·매도비가 거기엔 없다.
     *
     * @return array<int, string> BoardRequest::TYPE_* (없으면 빈 배열)
     */
    public function getOpenBoardRequestTypesAttribute(): array
    {
        return $this->boardRequests
            ->where('status', BoardRequest::STATUS_OPEN)
            ->pluck('type')->unique()->values()->all();
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
            $this->cost_transfer + $this->cost_extra1 + $this->cost_extra2 +
            $this->cost_inspection + $this->cost_performance + $this->cost_repair + $this->cost_advertising
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
        // ⚠️ 회수이력에서 'savings' 도 제외 (2026-07-28) — 적립금 회수이력은 savings_used 컬럼을 갱신하는
        //    기록용 행이라, 여기서 또 더하면 같은 돈이 두 번 차감된다(deposit 이 final_payments 로
        //    반영되므로 제외되는 것과 같은 이유). 미수 반영은 savings_used 단일 출처로만.
        $totalReceived = $this->finalPayments->whereNotNull('confirmed_at')->sum('amount')
            + $this->receivableHistories->whereNotIn('method', self::MIRRORED_RECEIVABLE_METHODS)->sum('amount')
            + (float) ($this->savings_used ?? 0);

        $unpaid = $totalSale - $totalReceived;

        // 통화 1단위 미만 양수 잔차(외화 소수점, 예: 8397.34 EUR 판매 - 8397 입금 = 0.34)는
        // 회계상 완납으로 스냅 → 0. 여기가 미수 단일 출처(SKILLS §13)라 게이지·채권 KPI·
        // 진행상태(판매완료)·위험도가 전부 일관되게 완납 처리됨 (jin 2026-07-02).
        // 음수(과입금)는 건드리지 않음 — 환급 표시 보존. KRW는 정수라 영향 없음.
        return ($unpaid > 0 && $unpaid < 1) ? 0.0 : $unpaid;
    }

    /**
     * 보증금 매입 마커 뱃지 상태 (2026-07-23, jin). 표시 전용 — 게이트와 무관.
     *   null     = 보증금 매입 아님 (뱃지 없음)
     *   'waiting' = 보증금으로 산 차이나 바이어 판매입금 미완납 (주황 — "바이어 입금 대기")
     *   'paid'    = 보증금 매입 + 판매완료(미수 0) (초록 — "완납"). jin: 판매완료=미수금 없음 → 자동 green.
     * ⚠️ 이 상태는 '바이어 미수' 기준일 뿐 — 매입 미지급([!매입], 셀러 채무)은 실제 지급 기준 별개로 유지.
     */
    public function getDepositPurchaseStateAttribute(): ?string
    {
        if (! $this->is_deposit_purchase) {
            return null;
        }

        return ($this->sale_price > 0 && $this->sale_unpaid_amount <= 0) ? 'paid' : 'waiting';
    }

    /**
     * 정산 적용 환율 (jin 2026-08-06) — 1차 정산을 **실제 입금된 환율**로 계산한다.
     *
     * 구: 1차는 판매환율로 계산하고, 실입금과의 차이는 2차 마감에서 환차로 실지급액에 1:1 가산.
     * 신: 1차 정산 자체를 실효 입금환율로 계산 → 환차가 마진공식(×0.9×비율)을 그대로 통과한다.
     *     2차 정산은 명세서 기입(탁송비·면허비 등 비용 9개) 변동분만 다음달로 이월(carryover).
     *
     * 실효환율 = 실입금KRW ÷ 총판매가(외화) — 전 회수경로의 가중평균.
     *   · 기타회수·적립금은 sale_received_krw_accumulated 가 판매환율로 평가(FX 중립)하므로
     *     그 몫만큼 실효환율이 판매환율 쪽으로 당겨진다. 의도된 동작 — 그 돈엔 FX 사건이 없다.
     *   · 분모가 총판매가(운임비 포함)라 운임비의 환차익·손은 정산 base(운임비 제외)를 안 거친다.
     *     = 운임비 환차는 회사 몫. jin 2026-08-06 확인.
     *
     * ⚠️ **미완납이면 판매환율을 쓴다.** 절반만 입금된 상태에서 나누면 실효환율이 실제의 절반으로
     *    나와 판매금원화가 반토막 난다 — 원금 미수가 환율로 둔갑한다. 2차 마감 완납 게이트와 같은 이유.
     */
    public function getSettlementExchangeRateAttribute(): float
    {
        $saleRate = (float) ($this->exchange_rate ?? 0);

        if ($this->currency === 'KRW' || $saleRate <= 0) {
            return $saleRate;
        }

        $totalFx = (float) $this->sale_total_amount;
        if ($totalFx <= 0 || $this->sale_unpaid_amount > 0) {
            return $saleRate;
        }

        return (float) $this->sale_received_krw_accumulated / $totalFx;
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

        // ② 기타회수 — 판매환율 평가 (FX 중립). 미수 accessor 와 동일하게 미러 방식(deposit·savings) 제외.
        $receivableKrw = (float) $this->receivableHistories
            ->whereNotIn('method', self::MIRRORED_RECEIVABLE_METHODS)
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
    /**
     * 매입 지급액 (확정분) — SKILLS §13 총지급액의 단일 출처.
     * 큐 22-C-E (2026-05-20) — down_payment / selling_fee_payment DROP 후 단순화.
     * type 무관 confirmed PBP rows 만 합산 (22-A-3 FP 분자와 대칭).
     * payment_date <= today AND confirmed_at IS NOT NULL 동시 만족해야 ledger 반영.
     */
    public function getPurchasePaidAmountAttribute(): int
    {
        return (int) $this->purchaseBalancePayments
            ->filter(fn ($p) => $p->payment_date !== null
                && $p->payment_date->lte(now())
                && $p->confirmed_at !== null)
            ->sum('amount');
    }

    public function getPurchaseUnpaidAmountAttribute(): int
    {
        return (int) (($this->purchase_price + $this->selling_fee) - $this->purchase_paid_amount);
    }

    /**
     * 확정 **계약금**만 (jin 2026-08-10) — 무담보 한도가 묶이는 유일한 대상.
     *
     * 무담보는 "국내에 차가 없어 보증금을 못 쓰는 단골이 **새 차 계약금을 걸 때**" 쓰라고 만든 것이라
     * 매입 잔금(`balance`)·매도비(`selling_fee`)에는 쓰지 않는다. 그래서 type='down' 만 센다.
     * 확정 조건은 `purchase_paid_amount` 와 동일(payment_date 도래 + confirmed_at).
     */
    /**
     * 선적 진입 조건(판매대금 N% 입금)을 넘었는가 — **무담보 해제 판정의 단일 출처** (jin 2026-08-10).
     *
     * 무담보에 묶인 계약금은 이 시점에 풀린다. 회사가 대신 낸 돈이 판매대금으로 회수됐다는 뜻이다.
     * `Buyer::computeReceivableGauge` 와 저장 가드가 **같은 이 메서드**를 쓴다 —
     * 각자 계산하면 "화면은 풀렸다는데 저장은 막힌다"가 생긴다.
     *
     * ⚠️ 관계가 아니라 **컬럼만** 본다(`sale_unpaid_amount_krw_cache`). 바이어 목록 게이지는
     *    컬럼을 제한해 차량을 로드하므로, 관계 기반 accessor 를 쓰면 거기서 조용히 틀린다.
     * ⚠️ 임계는 매입 게이트가 아니라 `shipping_entry` 키다(운영에서 값이 다르다).
     */
    public function isShippingEntryMet(?float $threshold = null): bool
    {
        $rate = (float) ($this->exchange_rate ?? 0);
        $total = (float) ($this->sale_total_amount ?? 0);
        $krw = ($total > 0 && $rate > 0) ? (int) ($total * $rate) : 0;
        if ($krw <= 0) {
            return false;   // 판매가·환율 미입력 — 판정 불가라 "아직 아님"으로 본다
        }

        // 🚨 $threshold 를 안 주면 **전역이 아니라 이 차의 바이어**로 해석한다 (jin 2026-08-21).
        //    전역으로 폴백하면 바이어 재정의가 조용히 무시되는데 예외도 로그도 안 난다.
        //    대신 배치(게이지)는 바이어당 1회 해석해 주입할 것 — 안 주면 차량마다 조회라 N+1 이다.
        $threshold ??= LockThresholdResolver::threshold($this->buyer, 'shipping_entry');

        $unpaid = (int) ($this->sale_unpaid_amount_krw_cache ?? 0);

        return ($unpaid / $krw) <= $threshold;
    }

    public function getConfirmedDownPaymentAttribute(): int
    {
        return (int) $this->purchaseBalancePayments
            ->filter(fn ($p) => $p->type === 'down'
                && $p->payment_date !== null
                && $p->payment_date->lte(now())
                && $p->confirmed_at !== null)
            ->sum('amount');
    }

    /**
     * 매매상 잔금 10일 알림 앵커 (karaba, jin 2026-07-12) — 계약금(down PBP) 최초 payment_date.
     * 계약금 미입력이면 null. 알림/목록 배지 단일 출처.
     */
    public function getContractDownDateAttribute(): ?Carbon
    {
        $dates = $this->purchaseBalancePayments
            ->filter(fn ($p) => $p->type === 'down' && (int) $p->amount > 0 && $p->payment_date)
            ->pluck('payment_date');

        return $dates->isEmpty() ? null : $dates->min();
    }

    /**
     * 매매상 잔금 마감까지 남은 일수 (계약금일 + 10). 음수 = 마감 지남. null = 알림 대상 아님.
     * 대상 = 거래처구분 '매매상'(karaba) + 매입가>0 + 매입 미지급>0 + 계약금 입력됨.
     * scopeAction('purchase_balance_due') 와 동일 조건(단일 출처).
     */
    public function getPurchaseBalanceDueDaysAttribute(): ?int
    {
        if (! $this->is_dealer_purchase) {
            return null;
        }
        if ($this->purchase_price <= 0 || $this->purchase_unpaid_amount <= 0) {
            return null;
        }
        $down = $this->contract_down_date;
        if (! $down) {
            return null;
        }
        $leadDays = (int) Setting::get('alarm_balance_due_days', 10);

        return (int) now()->startOfDay()->diffInDays($down->copy()->addDays($leadDays)->startOfDay(), false);
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
        // 로드된 컬렉션(프로퍼티)에서 계산 — 관계 메서드 호출(매행 새 SQL, N+1)을 피함.
        //   getPurchaseUnpaidAmountAttribute 와 동일 패턴. 재고 목록은 purchaseBalancePayments eager load.
        $today = now();
        $last = $this->purchaseBalancePayments
            ->filter(fn ($p) => $p->confirmed_at !== null
                && $p->payment_date !== null
                && $p->payment_date->lte($today))
            ->max('payment_date');

        return $last ? Carbon::parse($last) : null;
    }

    /** 일반재고 권장 매입 상한 — 초과 시 뱃지 표시만(하드 차단 아님, jin 2026-07-18). */
    public const GENERAL_STOCK_PRICE_CAP = 200_000_000;

    /** 일반재고 권장 판매 기한(입고일 기준 개월) — 경과 시 뱃지 표시만. */
    public const GENERAL_STOCK_SELL_MONTHS = 3;

    /**
     * 재고 보관 위치 (jin 2026-07-28) — 재고관리 화면에서 버튼으로 찍는다.
     * 야적장이 늘면 여기에 추가하면 화면 버튼·필터가 함께 늘어난다(컬럼은 string 이라 마이그 불필요).
     */
    public const STOCK_LOCATIONS = ['홈플', '화물', '야드'];

    /** karaba 전용 보관 위치 (jin 2026-08-19) — 홈플·화물 대신 쇼링·항입고. 야드는 공통. */
    public const STOCK_LOCATIONS_KARABA = ['쇼링', '항입고', '야드'];

    /**
     * 회사 프로파일별 보관 위치 단일 출처 — karaba면 karaba 세트, 그 외 공통 세트.
     *
     * ⚠️ 화면 버튼·필터·저장 검증이 **모두 이걸** 봐야 한다. 한 곳만 상수를 직접 보면
     *    "버튼엔 있는데 저장이 안 되는" 형태로 갈린다(SKILLS §8 #45 — 같은 목록의 복제).
     */
    public static function stockLocations(): array
    {
        return Setting::isKaraba() ? self::STOCK_LOCATIONS_KARABA : self::STOCK_LOCATIONS;
    }

    /**
     * 재고 2분류 (jin 2026-07-18):
     *   일반재고(general)   = 재고 중 미판매(투기매입, 바이어 미정) = sale_price ≤ 0.
     *   선적전 재고(pre_ship) = 재고 중 판매됨(항구 대기, 출고 전)   = sale_price > 0.
     * 둘 다 inStock() 기반(매입완납 + 출고 전).
     */
    public function scopeGeneralStock($query)
    {
        return $query->inStock()->where(fn ($q) => $q->whereNull('sale_price')->orWhere('sale_price', '<=', 0));
    }

    public function scopePreShippingStock($query)
    {
        return $query->inStock()->where('sale_price', '>', 0);
    }

    /**
     * 운항 상태 (jin 2026-08-09) — **진행상태와 직교하는 표시 전용 축**.
     *
     * 선적일(= 실제 출항일, jin 확인)과 ETA 가 둘 다 있으면 배가 떴다고 본다.
     * ETA 가 미래면 아직 바다 위(`운항중`), 지났으면 `도착예정`.
     * ⚠️ **'도착'이 아니라 '도착예정'이다** — ETA 는 예정일이고 지연되면 배는 아직 바다에 있다.
     *    실제 입항을 확인하려면 포워더 소스가 필요하다(2026-08-09 현재 ERP 에 없음).
     *
     * 🚫 **`progress_status` cascade 에 넣지 않는다.** 넣으면 두 가지가 조용히 깨진다(실측 확인):
     *   ① 정산 자동생성은 `progress_status_cache` 가 '거래완료'로 **바뀌는 순간**(`wasChanged`)을 보는데,
     *      시간 경과 전이는 `refreshCaches()`(raw update)로만 일어나 **모델 훅이 안 뜬다** → 정산 영구 미생성.
     *      (SKILLS §8 #43 과 같은 형태.)
     *   ② `scopeInStock` 이 "거래완료면 출고일 없어도 재고 제외"를 쓰는데, 거래완료가 아니게 되면
     *      **출고일 미입력 92대(heymanerp 실측)가 재고로 복귀**한다.
     *   ③ ETA 를 미래로 고치면 거래완료 → 운항중으로 **역행**한다(진행상태 단조 전진 원칙 위반).
     *
     * ⚠️ **진행상태로 대상을 좁히지 않는다** — 선적일+ETA 만 본다. 단계 게이트를 두면
     *    v3 grandfather 라벨(`수출통관중` 등)이나 회사별 데이터 차이에서 조용히 빠진다.
     *    실측상 4단계(선적중·선적완료·통관중·거래완료) 밖에도 선적일 보유 차가 24대 있는데,
     *    그건 반입지 미입력 등 **데이터 미비를 드러내는 쪽이 낫다**고 판단해 포함한다.
     *
     * ⚠️ 캐시 컬럼을 만들지 않는다 — 시간 의존이라 저장하는 순간 낡는다. 매 쿼리 날짜 비교.
     */
    public const SAILING_IN_TRANSIT = '운항중';

    /** ⚠️ 라벨이 '도착'이 아니라 '도착예정' 인 이유 — ETA 는 예정일이라 실제 입항 확인이 아니다(jin 2026-08-09). */
    public const SAILING_ARRIVED = '도착예정';

    public const SAILING_PHASES = ['in_transit', 'arrived'];

    public function getSailingStatusAttribute(): ?string
    {
        if (! $this->shipping_date || ! $this->eta_date) {
            return null;
        }

        return $this->eta_date->toDateString() > now()->toDateString()
            ? self::SAILING_IN_TRANSIT
            : self::SAILING_ARRIVED;
    }

    /**
     * 기계용 키 — API·필터 파라미터가 쓰는 값. 표시용 한글 라벨(`sailing_status`)과 짝이다.
     *
     * ⚠️ 라벨을 쿼리 파라미터로 쓰지 말 것 — board 연동은 **쿼리 문자열이 HMAC 서명 대상**이라
     *    한글이 들어가면 인코딩이 한 바이트만 달라도 서명이 깨진다.
     */
    public function getSailingPhaseAttribute(): ?string
    {
        return match ($this->sailing_status) {
            self::SAILING_IN_TRANSIT => 'in_transit',
            self::SAILING_ARRIVED => 'arrived',
            default => null,
        };
    }

    /**
     * 포워딩사 화물추적 링크 — 만들 수 없으면 null(호출부는 버튼을 안 그린다).
     *
     * 🔑 **판정은 여기 한 곳뿐이다.** 화면·API·나중에 ssancar 포털까지 전부 이 값을 받아 쓴다.
     *    조건을 옮겨 적으면 *"ERP 는 열리는데 포털은 안 열린다"* 가 되고 눈으로 못 잡는다
     *    (SKILLS §8 #44 · ssancar 협업에서 같은 형태가 다섯 번 나왔다).
     *
     * 조건 셋 — 하나라도 빠지면 null:
     *   ① 포워딩사에 URL 템플릿이 있다        (없는 회사는 애초에 링크가 없다)
     *   ② 차대번호가 있다                     (템플릿의 `{VIN}` 을 채울 값)
     *   ③ **출항 D+1 이 지났다**              (당일은 선사 준비·데이터 전달 중이라 조회가 안 된다)
     *
     * ⚠️ ③ 을 `sailing_phase` 로 판정하면 안 된다 — 그 값은 **선적일이 미래여도 ETA 만 있으면
     *    `운항중`** 이 된다. 실측 CIG 61대 중 4대가 정확히 그 상태였고, 그 차들은 조회가 안 된다.
     *    「실제 출항」은 `shipping_date` 로만 판정한다.
     */
    public function getTrackingUrlAttribute(): ?string
    {
        if (! $this->shipping_date || ! $this->forwardingCompany) {
            return null;
        }

        $openFrom = $this->shipping_date->copy()->startOfDay()
            ->addDays(ForwardingCompany::TRACKING_ACTIVE_AFTER_DAYS);

        if (now()->startOfDay()->lt($openFrom)) {
            return null;   // 아직 이르다 — 화면은 「출항 다음날부터 조회됩니다」를 보여준다
        }

        return $this->forwardingCompany->trackingUrlFor($this->nice_reg_vin);
    }

    /**
     * 링크가 아직 안 열린 이유 — 화면이 **왜 비활성인지 말해주기 위한** 값이다.
     *
     * 🧭 버튼을 그냥 숨기면 사용자는 이유를 못 봐서 문의가 몰린다(SKILLS §8 #60).
     *    「템플릿 없음」은 그 회사가 추적을 안 하는 것이라 숨기는 게 맞고,
     *    「아직 이르다」·「차대번호 없음」은 **보이되 잠그고 사유를 적는다**.
     */
    public function getTrackingBlockReasonAttribute(): ?string
    {
        if (! $this->forwardingCompany || trim((string) $this->forwardingCompany->tracking_url_template) === '') {
            return null;   // 링크 자체가 없는 회사 — 버튼을 그리지 않는다
        }
        if (trim((string) $this->nice_reg_vin) === '') {
            return 'no_vin';
        }
        if (! $this->shipping_date) {
            return 'not_departed';
        }

        return $this->tracking_url === null ? 'too_early' : null;
    }

    /**
     * board 포털 차량 행의 공통 메타 (인계 2026-08-10) — **차량번호가 보이는 곳이면 같이 보인다**.
     *
     * 🔑 board 는 **정확히 이 세 키**를 읽는다(`vin`·`brand`·`model_type`). 이름을 바꾸면 board 는
     *    조용히 아무것도 안 그린다 — 그래서 emit 지점 6곳이 각자 배열을 짜지 않고 여기를 부른다.
     *    (같은 배열이 여러 곳에 복제되면 한 곳만 고쳤을 때 탭마다 다르게 보인다.)
     *
     * ⚠️ 값이 없으면 `null` — board 는 없는 값에 대시조차 안 그린다(각 필드 독립 degrade).
     *    그래서 car-erp 배포 전에 board 를 올려도 화면이 안 틀어진다.
     *
     * 🔒 `nice_reg_vin` 노출은 §3 PII 화이트리스트 판단을 거쳤다(2026-08-10):
     *    VIN 은 **차량 식별자이지 소유자 식별정보가 아니다**(⛔ 목록 = RRN·소유자명/주소·계좌·마진).
     *    암호화 대상도 아니고, 이 엔드포인트들은 전부 **영업 본인 차량 스코프**라 그 영업이 ERP
     *    기본정보 탭·선적서류에서 이미 보는 값이다. 노출면이 늘지 않는다.
     *    🚫 여기에 소유자·계좌 필드를 얹지 말 것 — 그 순간 판단 근거가 통째로 무너진다.
     */
    public static function portalMeta(?self $vehicle): array
    {
        return [
            'vin' => $vehicle?->nice_reg_vin,
            'brand' => $vehicle?->brand,
            'model_type' => $vehicle?->model_type,
        ];
    }

    /**
     * 위 accessor 와 **같은 판정을 SQL 로**. 둘이 갈리면 화면 카운트와 목록이 어긋난다
     * (가드 = `SailingStatusTest::test_scope_and_accessor_agree`).
     */
    public function scopeSailing(Builder $query, string $phase): Builder
    {
        // ⚠️ 경계는 '오늘 23:59:59' 로 잡는다 — SQLite 는 date 컬럼을 'Y-m-d 00:00:00' 로 저장해서
        //    `eta_date <= '2026-08-09'` 가 오늘 도착 건을 **놓친다**(문자열 비교라 00:00:00 이 더 큼).
        //    whereDate() 는 인덱스를 죽이므로 범위 비교를 쓴다(export 컨트롤러와 같은 관례).
        $endOfToday = now()->toDateString().' 23:59:59';

        return $query->whereNotNull('shipping_date')
            ->whereNotNull('eta_date')
            ->when($phase === 'in_transit', fn ($q) => $q->where('eta_date', '>', $endOfToday))
            ->when($phase === 'arrived', fn ($q) => $q->where('eta_date', '<=', $endOfToday));
    }

    /**
     * 재고 (jin 2026-07-09) = 매입 완납(입고됨) AND 출고일 없음 AND 거래완료 아님.
     *   미완납 = 입고 전(제외) / 출고일 찍힘 = 출고됨(제외).
     *   거래완료(출항) = 출고일 미입력이어도 제외 (jin 2026-07-23) — 거래완료는 확실히 나간 것.
     *     선적중 등 그 외 진행상태는 미출고면 재고 잔존(진행상태 무관 원칙 유지, 거래완료만 예외).
     *     progress_status_cache NULL 은 판정 불가라 재고 잔존(orWhereNull).
     *   매입 미지급 식은 scopeAction('purchase_unpaid') 와 동일 단일 출처(≤ 0 반전).
     */
    /**
     * 매입 미지급 SQL 식 — **단일 출처**. 확정 PBP(payment_date 도래 + confirmed_at) 기준으로
     * `매입가 + 매도비 − 지급합` 을 계산한다. `getPurchaseUnpaidAmountAttribute` 와 정합(SKILLS §13).
     *
     * ⚠️ 이 식을 복사해 쓰지 말 것 — 2026-08-09 기준 이미 4곳에 같은 문자열이 퍼져 있었고,
     *    한 곳만 고치면 화면마다 숫자가 갈린다(회사이익 공식이 3곳에 복제됐던 사고와 같은 형태).
     *    부호만 바꿔 쓴다: 미지급 있음 `> 0` / 완납 `<= 0`.
     * CAST AS SIGNED — BIGINT UNSIGNED underflow 방지(환불·선지급 케이스).
     */
    public static function purchaseUnpaidRawExpr(): string
    {
        return '(CAST(purchase_price AS SIGNED) + CAST(selling_fee AS SIGNED)
                         - COALESCE((SELECT SUM(amount) FROM purchase_balance_payments
                                      WHERE vehicle_id = vehicles.id
                                      AND payment_date IS NOT NULL AND payment_date <= ?
                                      AND confirmed_at IS NOT NULL), 0))';
    }

    /**
     * 지급대기 (jin 2026-08-09) — **매입 대금이 남은 차 = 입고 전**. 재고관리 화면의 첫 탭.
     *
     * `inStock()` 에서 매입완납 조건 하나만 뒤집은 것이라 재고와 **정확히 배타적**이고,
     * 둘을 합치면 "출고 전 매입차 전체"가 된다. 지급을 마치는 순간 여기서 빠져 재고로 넘어간다.
     *
     * 왜 필요한가: board 포털이 「매입내역」(전량조회)을 재고 3분류로 대체하는데,
     * **[입금요청]을 보낼 차량이 정확히 이 집합**이라 재고에만 있으면 버튼을 달 곳이 없다.
     * ERP 에서도 그동안 이 차들은 차량관리 필터로만 볼 수 있었다 — 재고 생애주기의 앞 칸을 채운다.
     */
    public function scopeAwaitingPurchasePayment($query)
    {
        return $query->where('purchase_price', '>', 0)
            ->whereNull('warehouse_out_date')
            ->where(function ($q) {
                $q->where('progress_status_cache', '!=', '거래완료')
                    ->orWhereNull('progress_status_cache');
            })
            ->whereRaw(self::purchaseUnpaidRawExpr().' > 0', [now()->toDateString()]);
    }

    public function scopeInStock($query)
    {
        return $query->where('purchase_price', '>', 0)
            ->whereNull('warehouse_out_date')
            ->where(function ($q) {
                $q->where('progress_status_cache', '!=', '거래완료')
                    ->orWhereNull('progress_status_cache');
            })
            ->whereRaw(self::purchaseUnpaidRawExpr().' <= 0', [now()->toDateString()]);
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

    /**
     * 집계 판매총액(KRW) — 미수율의 **분모**. SKILLS §13 「집계 미수율」 규칙.
     *   Σ (sale_total_amount × 환율)  ← sale_price 가 아니라 **판매총액**(부대비용 포함)
     *   환율 미입력 외화(캐시 null)는 제외 — 분자와 같은 기준이어야 비율이 안 왜곡된다.
     *
     * ℹ️ **현재 호출처 0** (jin 2026-08-20). 대표 알림톡이 유일한 소비자였는데, 그 % 가
     *   「미수 총액 대비 구성비」로 바뀌면서 이 분모를 안 쓰게 됐다. 같은 개념(= 미수 차량끼리의 비율)은
     *   채권관리 KPI 「미납률」로 살아 있다(`receivables/index` `default_ratio_pct`).
     *   ⚠️ 지우지 않은 이유 = §13 의 참조 구현이고, 통화 필터가 없는 집계에서 재사용 가치가 있어서다.
     *   다시 쓸 땐 화면의 미납률과 **같은 값이 나오는지** 먼저 확인할 것 — 두 벌로 갈리면 SKILLS §8 #45 다.
     *
     * @param  Builder  $query
     */
    public static function aggregateSaleTotalKrw($query): int
    {
        $denom = 0.0;

        foreach ((clone $query)->get() as $v) {
            if ($v->sale_unpaid_amount_krw_cache === null) {
                continue;
            }
            $total = (float) $v->sale_total_amount;
            $denom += $v->currency === 'KRW' ? $total : $total * (float) ($v->exchange_rate ?: 0);
        }

        return (int) round($denom);
    }

    /** 집계 미수액(KRW) — 미수율의 **분자**. 환율 미입력(null)은 SUM 에서 자동 제외된다. */
    public static function aggregateUnpaidKrw($query): int
    {
        return (int) (clone $query)->sum('sale_unpaid_amount_krw_cache');
    }

    /**
     * 집계 미수율 % (KRW 기준, 0~100) — 여러 대를 묶어서 볼 때. 대상 없으면 null.
     * 단일 차량이면 이걸 쓰지 말고 `unpaid_ratio` accessor 를 쓸 것.
     *
     * ⚠️ `$denominatorKrw` 를 주면 **그 분모로** 계산한다 — 여러 그룹의 비율을 나란히 보여줄 때
     *    분모를 통일하기 위한 것이다. 통일하지 않으면 그룹마다 모수가 달라 **더할 수 없는 숫자**가 되는데,
     *    나란히 놓이면 사람은 자연히 더해서 읽는다(jin 2026-08-06 — 선적전 31% + 선적후 60% = 91% 로 오독).
     *    같은 분모를 주면 각 % 의 합이 곧 전체 미수율이 되어 그 오독이 정답이 된다.
     *
     * @param  Builder  $query
     * @param  int|null  $denominatorKrw  공유 분모. null 이면 그 쿼리 자체의 판매총액.
     */
    public static function aggregateUnpaidRatioPct($query, ?int $denominatorKrw = null): ?float
    {
        $denom = $denominatorKrw ?? self::aggregateSaleTotalKrw($query);

        return $denom > 0 ? round(self::aggregateUnpaidKrw($query) / $denom * 100, 1) : null;
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
    /**
     * 결제대기(grace) 유예 일수 기본값 — 선적 전 미수는 판매일+이 일수 지나야 채권 (jin 2026-07-06 A안).
     * ⚠️ 실제 판정은 Setting::graceDays()(super 조정, 회사별). 이 상수는 기본값 참조용(2026-07-20).
     */
    public const RECEIVABLE_GRACE_DAYS = 10;

    /** 보증금 매입 바이어 입금 독촉 알림 — 도장 후 N일부터 독촉(영업·관리), M일 초과 시 대표 처분요청 (2026-07-23, jin). */
    public const DEPOSIT_CASH_DUE_DAYS = 5;

    public const DEPOSIT_CASH_OVERDUE_DAYS = 10;

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

        // 결제대기 유예 (jin 2026-07-06, A안) — 선적 전(출고 전 = warehouse_out_date 없음) 미수는
        //   판매일 + RECEIVABLE_GRACE_DAYS 지나야 채권. 그 전엔 'grace'(정상 결제 대기, 채권 아님).
        //   선적 후(출고 = 출항)는 유예 없이 즉시 위험. ⚠️ 캐시 컬럼이라 시간 경과는 야간 rebuild(05:00)로 flip.
        //   pivot=출고일(jin 2026-07-18): 반입지 입력돼도 출고 전이면 항구 주차장=선적전. (구 pivot=bl_loading_location)
        if (blank($this->warehouse_out_date) && $this->sale_date
            && $this->sale_date->copy()->addDays(Setting::graceDays())->startOfDay()->isFuture()) {
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
            'deregistration_needed', 'purchase_balance_due',
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
                ->whereRaw(self::purchaseUnpaidRawExpr().' > 0', [now()->toDateString()]),
            // 매매상 잔금 10일 알림 (karaba) — 매매상 체크(is_dealer_purchase) + 계약금 입력 + 잔금 미납.
            //   is_dealer_purchase 는 karaba 만 채워 자연 격리. due_date = 계약금일+10 (scan 에서 산정).
            //   getPurchaseBalanceDueDaysAttribute 와 동일 조건(단일 출처). alarms:scan 이 생성/자동해소.
            //   (2026-07-22 Phase 1 — 트리거를 구 purchase_partner_type='매매상' 에서 체크박스로 이관.)
            'purchase_balance_due' => $q
                ->where('is_dealer_purchase', true)
                ->where('purchase_price', '>', 0)
                ->whereRaw(self::purchaseUnpaidRawExpr().' > 0', [now()->toDateString()])
                ->whereExists(function ($sub) {
                    $sub->select(DB::raw(1))->from('purchase_balance_payments')
                        ->whereColumn('purchase_balance_payments.vehicle_id', 'vehicles.id')
                        ->where('type', 'down')->where('amount', '>', 0)->whereNotNull('payment_date');
                }),
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
                ->whereRaw(self::purchaseUnpaidRawExpr().' <= 0', [now()->toDateString()]),

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

            // ── 미수 분류 — pivot=「이미 떠났나」 = 출고일 또는 B/L (jin 2026-07-18 → 08-20 보강) ──
            // 선적전 미수: 아직 안 떠남(항구 주차장 대기) AND sale_unpaid_amount > 0. 반입지·진행단계 무관.
            //   결제대기(grace) 제외 — 판매일+10일 미경과 선적전 미수는 아직 채권 아님 (jin 2026-07-06).
            'receivable_before_shipping' => $q
                ->notDeparted()
                ->where('sale_unpaid_amount_krw_cache', '>', 0)
                ->excludeReceivableGrace(),

            // 선적후 미수: 이미 떠남(출고일 또는 B/L) AND sale_unpaid_amount > 0. 유예 없이 즉시 채권.
            'receivable_after_shipping' => $q
                ->departed()
                ->where('sale_unpaid_amount_krw_cache', '>', 0),

            // 디파짓: savings_used > 0 (적립금 사용분)
            'deposit_by_buyer' => $q->where('savings_used', '>', 0),

            default => $q,
        };
    }

    /**
     * 결제대기(grace) 차량을 채권 집계에서 제외 — grace = 선적 전(출고 전) + 판매일+유예일 미경과 미수.
     * jin 2026-07-06: "결제대기는 아직 채권 아님". 채권금액 총액(채권관리·관리자/업무 대시보드)에서 빠져야 함.
     *
     * 판정은 캐시(receivable_risk) 대신 sale_date 로 = fresh(야간 rebuild 대기 없이 판매일+10일 정확 flip),
     * scopeAction('sale_unpaid')·채권관리 before_shipping 탭과 동일 단일 기준(SKILLS §13). grace 는 선적
     * 전에만 성립하므로, 선적 후(출고일 입력 = 출항) 미수는 이 스코프로 절대 제외되지 않는다(즉시 채권).
     * pivot=출고일(jin 2026-07-18, 구 pivot=bl_loading_location). sale_date NULL(판매가 있으나 날짜 미상 —
     * chk_sale_required 상 실질 없음)은 grace 아님으로 간주해 유지한다.
     */
    /**
     * 🚢 이미 떠난 차 — **출고일이 찍혔거나 B/L 이 나왔다**(= 거래완료 = 출항). jin 2026-08-20.
     *
     * 재고 판정과 **같은 규칙**이다: 2026-07-23 `cfd17f6` 에서 "거래완료(출항) = 확실히 나간 것 →
     * 출고일 미입력이어도 재고 아님" 으로 정했는데, 채권 선적전/후 pivot 만 그 규칙을 안 따라와
     * **이미 떠난 차가 「선적전 미수」로 남아 있었다**(실측 heymanerp 11대 881만원).
     * 07-18 에 pivot 을 출고일로 정하고 5일 뒤 재고 규칙을 바꾸면서 채권을 같이 안 고친 것이다.
     *
     * ⚠️ 조건을 옮겨 적지 말고 이 스코프를 쓸 것 — 채권 분류는 화면·대시보드·알림톡 4곳에 흩어져 있다(SKILLS §8 #45).
     * ℹ️ 2026-08-20 부터 `Vehicle::saving` 이 B/L 발급 시 출고일을 선적일로 자동 채우므로 신규 차량엔
     *    `bl_document` 분기가 거의 안 걸린다. 선적일조차 없는 예외를 위한 안전망으로 남긴다.
     */
    public function scopeDeparted(Builder $q): Builder
    {
        return $q->where(fn ($q2) => $q2
            ->whereNotNull('warehouse_out_date')
            ->orWhereNotNull('bl_document'));
    }

    /** 아직 안 떠난 차 — scopeDeparted 의 정확한 여집합(출고일도 B/L 도 없음). */
    public function scopeNotDeparted(Builder $q): Builder
    {
        return $q->whereNull('warehouse_out_date')->whereNull('bl_document');
    }

    public function scopeExcludeReceivableGrace(Builder $q): Builder
    {
        // 유예 = **아직 안 떠난** 차의 미수 중 판매일+유예일 미경과분. 떠난 차는 유예 없이 즉시 채권이다.
        return $q->whereNot(fn ($q2) => $q2
            ->whereNull('warehouse_out_date')
            ->whereNull('bl_document')
            ->whereNotNull('sale_date')
            ->where('sale_date', '>', now()->subDays(Setting::graceDays())->toDateString()));
    }

    /**
     * 결제대기(grace)만 — scopeExcludeReceivableGrace 의 정확한 여집합(선적 전·판매일+유예일 미경과).
     * 대시보드/채권관리 "결제대기" 카드(제외된 미수를 따로 표시)용. 호출측에서 미수>0 필터 추가.
     */
    public function scopeOnlyReceivableGrace(Builder $q): Builder
    {
        return $q->whereNull('warehouse_out_date')
            ->whereNull('bl_document')
            ->whereNotNull('sale_date')
            ->where('sale_date', '>', now()->subDays(Setting::graceDays())->toDateString());
    }
}
