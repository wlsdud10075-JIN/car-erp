<?php

namespace App\Support;

use App\Models\Vehicle;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 🗂️ 감사로그 「구역」 — 기록을 업무 단위로 묶는다 (jin 2026-11-22 «큰 틀에서 변경내역을 볼 수 있다면 더 직관적»).
 *
 * 로그에는 「어느 화면에서 저장했나」가 없다(대상·액션·칸만 남는다). 그래서 화면이 아니라 **무엇이 바뀌었나**로 나눈다 —
 * 같은 판매 입금 줄이 차량 창·채권관리·재무처리 세 곳에서 바뀌어도 전부 「판매·입금」이다.
 *
 * 판정 순서(위에서부터 첫 매칭) — **PHP 판정 `of()` 와 SQL 조건 `scope()` 가 같은 표를 읽는다**:
 *   1. 액션이 `ACTION_AREA` 에 있으면 그 구역 (대상이 무엇이든)
 *   2. 대상이 차량이면 칸 이름으로 (`vehicleColumnArea`) — 못 찾으면 「기본정보」
 *   3. 대상이 `TYPE_AREA` 에 있으면 그 구역
 *   4. 그 밖 = 「설정·기타」
 *
 * 🚨 새 감사 액션·새 대상 모델을 만들면 여기에 넣는다 — 안 넣으면 「설정·기타」(차량이면 「기본정보」)로 떨어져 찾기 어렵다.
 *    가드 = `AuditAreaTest`(코드에 적힌 액션 전부가 분류돼 있는지 정적 검사 · PHP 판정과 SQL 조건이 같은 답을 하는지).
 */
class AuditArea
{
    public const BASIC = 'basic';

    public const PURCHASE = 'purchase';

    public const DEREG = 'dereg';

    public const SALE = 'sale';

    public const EXPORT = 'export';

    public const SETTLE = 'settle';

    public const BUYER = 'buyer';

    public const PEOPLE = 'people';

    public const ETC = 'etc';

    /** 구역 => 화면 이름 (칩 순서 그대로) */
    public const LABELS = [
        self::BASIC => '기본정보',
        self::PURCHASE => '매입',
        self::DEREG => '말소',
        self::SALE => '판매·입금',
        self::EXPORT => '수출·선적',
        self::SETTLE => '정산',
        self::BUYER => '바이어·컨사이니',
        self::PEOPLE => '사람·권한',
        self::ETC => '설정·기타',
    ];

    /** 어느 대상에나 쓰이는 일반 액션 — 구역은 대상·칸이 정한다. */
    public const GENERIC_ACTIONS = ['created', 'updated', 'deleted', 'restored', 'force_deleted'];

    /** 액션 => 구역. 대상이 무엇이든 이 액션이면 그 구역이다. */
    public const ACTION_AREA = [
        // 기본정보
        'nice_mismatch_cleared' => self::BASIC, 'progress_rule_v5_backfill' => self::BASIC, 'vehicle_deleted_with_reason' => self::BASIC,
        // 매입
        'bulk_cost_applied' => self::PURCHASE, 'inbound_purchase_sync' => self::PURCHASE, 'purchase_sync_amounts_overwritten' => self::PURCHASE,
        'purchase_gate_override' => self::PURCHASE, 'purchase_payment_gate_override' => self::PURCHASE,
        'board_purchase_lock_requested' => self::PURCHASE, 'board_purchase_lock_cancelled' => self::PURCHASE,
        'board_purchase_lock_used' => self::PURCHASE, 'board_purchase_lock_overshoot' => self::PURCHASE,
        'purchase_cancel_closed' => self::PURCHASE, 'purchase_cancel_reopened' => self::PURCHASE,
        'purchase_overpay_corrected' => self::PURCHASE, 'purchase_payment_after_paid' => self::PURCHASE, 'purchase_proof_viewed' => self::PURCHASE,
        'board_request_rejected' => self::PURCHASE, 'board_request_resent' => self::PURCHASE,
        // 판매·입금
        'claim_used' => self::SALE, 'board_claim_received' => self::SALE, 'board_buyer_assigned' => self::SALE,
        'overpay_converted_to_company_income' => self::SALE, 'overpay_converted_to_misc_loss' => self::SALE, 'overpay_converted_to_savings' => self::SALE,
        'savings_adjustment' => self::SALE, 'savings_cancelled' => self::SALE, 'savings_earned' => self::SALE, 'savings_refund' => self::SALE, 'savings_used' => self::SALE,
        'deal_type_cleared_on_buyer_change' => self::SALE, 'contract_signed' => self::SALE, 'signing_session_revoked' => self::SALE,
        'payment_type_converted' => self::SALE,
        // 수출·선적
        'bulk_document_uploaded' => self::EXPORT, 'bulk_document_flag_backfill' => self::EXPORT, 'bulk_export_cleared' => self::EXPORT,
        'bulk_shipment_applied' => self::EXPORT, 'bulk_shipping_date_applied' => self::EXPORT,
        'shipment_added' => self::EXPORT, 'shipment_removed' => self::EXPORT, 'freight_usd_import' => self::EXPORT,
        'unpaid_override_approved' => self::EXPORT, 'forwarding_invoice_paid' => self::EXPORT, 'forwarding_invoice_unpaid' => self::EXPORT,
        // 정산
        'ledger_field_unlocked' => self::SETTLE, 'settlement_removed_unpaid_revived' => self::SETTLE, 'settlement_reanchored' => self::SETTLE,
        'settlement_gate_overridden' => self::SETTLE, 'settlement_gate_override_released' => self::SETTLE,
        'approved' => self::SETTLE, 'rejected' => self::SETTLE, 'dummy_resnap' => self::SETTLE,
        'payout_adjustment_added' => self::SETTLE, 'payout_adjustment_removed' => self::SETTLE, 'payout_approved_via_link' => self::SETTLE,
        'payout_rejected_via_link' => self::SETTLE, 'payout_incentive_added_via_link' => self::SETTLE, 'payout_approval_reverted' => self::SETTLE,
        'payout_request_resent' => self::SETTLE, 'payout_finalized' => self::SETTLE,
        'payout_held' => self::SETTLE, 'payout_hold_cancelled' => self::SETTLE, 'payout_hold_paid' => self::SETTLE,
        'payout_hold_queue' => self::SETTLE, 'payout_hold_unqueue' => self::SETTLE,
        'payout_extra_added' => self::SETTLE, 'payout_extra_cancelled' => self::SETTLE, 'payout_extra_paid' => self::SETTLE,
        'payout_extra_queue' => self::SETTLE, 'payout_extra_unqueue' => self::SETTLE,
        // 바이어·컨사이니
        'b2b_contract_read' => self::BUYER, 'b2b_contract_superseded' => self::BUYER, 'b2b_contract_uploaded' => self::BUYER,
        'b2b_partner_toggled' => self::BUYER, 'buyer_deal_flag_toggled' => self::BUYER, 'buyer_rebound' => self::BUYER,
        'buyers_portal_backfill' => self::BUYER, 'board_buyer_contact_read' => self::BUYER, 'board_buyer_credit_read' => self::BUYER,
        'buyer_cash_fee_added' => self::BUYER, 'buyer_cash_fee_deleted' => self::BUYER, 'buyer_cash_receipt_added' => self::BUYER,
        'buyer_cash_receipt_deleted' => self::BUYER, 'buyer_cash_savings_reverted' => self::BUYER, 'buyer_cash_to_savings' => self::BUYER,
        // 사람·권한
        'delegation_activated' => self::PEOPLE, 'delegation_deactivated' => self::PEOPLE, 'dev_user_switch' => self::PEOPLE, 'salesman_handover' => self::PEOPLE,
        // 설정·기타
        'assistant_query' => self::ETC, 'capital_recaptured' => self::ETC, 'capital_report_viewed' => self::ETC,
        'cash_account_backfill' => self::ETC, 'cash_balance_entered' => self::ETC,
        'b2b_partners_toggle_changed' => self::ETC, 'buyer_cash_toggle_changed' => self::ETC, 'domestic_payout_toggle_changed' => self::ETC,
        'domestic_payout_viewers_changed' => self::ETC, 'fx_settings_changed' => self::ETC, 'kakao_assistant_toggle_changed' => self::ETC,
        'kakao_params_changed' => self::ETC, 'kakao_viewers_changed' => self::ETC, 'lock_threshold_changed' => self::ETC,
        'lock_toggle_changed' => self::ETC, 'payroll_viewers_changed' => self::ETC, 'staff_payout_viewers_changed' => self::ETC,
        'unsecured_limit_toggle_changed' => self::ETC,
        'kakao_agent_rebound' => self::ETC, 'kakao_installer_downloaded' => self::ETC, 'kakao_room_fixed' => self::ETC,
    ];

    /** 대상(모델 짧은 이름) => 구역. 차량은 여기 없다 — 칸으로 가른다. `payment_confirmed`·`payment_unconfirmed` 는 대상이 정한다. */
    public const TYPE_AREA = [
        'PurchaseBalancePayment' => self::PURCHASE, 'BoardRequest' => self::PURCHASE,
        'FinalPayment' => self::SALE, 'ReceivableHistory' => self::SALE, 'SavingsStatus' => self::SALE,
        'VehicleClaim' => self::SALE, 'SignedContract' => self::SALE,
        'ForwardingInvoice' => self::EXPORT, 'ShippingRequest' => self::EXPORT, 'VehicleShipment' => self::EXPORT,
        'UnpaidExportOverride' => self::EXPORT, 'ForwardingCompany' => self::EXPORT, 'Port' => self::EXPORT,
        'Settlement' => self::SETTLE, 'SettlementPayoutBatch' => self::SETTLE, 'SettlementPayoutAdjustment' => self::SETTLE, 'PayoutHold' => self::SETTLE,
        'Buyer' => self::BUYER, 'Consignee' => self::BUYER, 'BuyerCashFee' => self::BUYER, 'BuyerCashReceipt' => self::BUYER,
        'User' => self::PEOPLE, 'Salesman' => self::PEOPLE, 'UserDelegation' => self::PEOPLE, 'PayrollEntry' => self::PEOPLE,
        'Setting' => self::ETC, 'CashAccount' => self::ETC, 'CashAccountBalance' => self::ETC, 'CashSnapshot' => self::ETC,
        'AdvanceReceipt' => self::ETC, 'KakaoCollectorToken' => self::ETC, 'KakaoRoom' => self::ETC, 'ApprovalRequest' => self::ETC,
    ];

    /** 차량 칸 — 이름이 이 머리말로 시작하면 그 구역. */
    private const VEHICLE_PREFIX = [
        'purchase_' => self::PURCHASE, 'cost_' => self::PURCHASE, 'cancel_' => self::PURCHASE, 'stock_location' => self::PURCHASE, 'remitter_' => self::PURCHASE,
        'deregistration_' => self::DEREG,
        'sale_' => self::SALE, 'savings_' => self::SALE,
        'bl_' => self::EXPORT, 'export_' => self::EXPORT, 'shipping_' => self::EXPORT, 'dhl_' => self::EXPORT, 'ems_' => self::EXPORT,
    ];

    /** 차량 칸 — 머리말로 안 갈리는 것(DB 에 없는 표식 이름 포함). */
    private const VEHICLE_EXACT = [
        'selling_fee' => self::PURCHASE, 'selling_fee_payment' => self::PURCHASE, 'down_payment' => self::PURCHASE,
        'is_deposit_purchase' => self::PURCHASE, 'is_unsecured_down' => self::PURCHASE, 'has_mortgage' => self::PURCHASE, 'memo_purchase' => self::PURCHASE,
        'is_deregistered' => self::DEREG,
        'exchange_rate' => self::SALE, 'usd_exchange_rate' => self::SALE, 'currency' => self::SALE, 'buyer_id' => self::SALE, 'buyer_undecided' => self::SALE,
        'consignee_id' => self::SALE, 'commission' => self::SALE, 'auto_loading' => self::SALE, 'tax_dc' => self::SALE, 'transport_fee' => self::SALE,
        'transport_fee_usd' => self::SALE, 'deal_type' => self::SALE, 'memo_sale' => self::SALE, 'claim_used' => self::SALE,
        'company_income' => self::SALE, 'misc_loss' => self::SALE, 'unpaid_ratio_cache' => self::SALE, 'receivable_risk' => self::SALE,
        'eta_date' => self::EXPORT, 'vessel_name' => self::EXPORT, 'container_number' => self::EXPORT, 'discharge_port_id' => self::EXPORT,
        'port_of_loading' => self::EXPORT, 'incoterms' => self::EXPORT, 'forwarding_company_id' => self::EXPORT, 'is_export_cleared' => self::EXPORT,
        'checkbill_document' => self::EXPORT, 'c_no' => self::EXPORT, 'shipment_row' => self::EXPORT, 'unpaid_override_stage' => self::EXPORT,
        'memo_shipping' => self::EXPORT, 'memo_clearance' => self::EXPORT, 'memo_bl' => self::EXPORT, 'warehouse_out_date' => self::EXPORT,
        'dhl_request' => self::EXPORT,
        'settlement_id' => self::SETTLE, 'unlock_reason' => self::SETTLE,
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::LABELS);
    }

    public static function label(string $area): string
    {
        return self::LABELS[$area] ?? $area;
    }

    /** 차량 칸 이름 → 구역(못 찾으면 기본정보). 일괄 기록(`a,b,c`)은 첫 칸으로 본다. */
    public static function vehicleColumnArea(?string $column): string
    {
        $c = trim(explode(',', (string) $column)[0]);
        if ($c === '') {
            return self::BASIC;
        }
        if (isset(self::VEHICLE_EXACT[$c])) {
            return self::VEHICLE_EXACT[$c];
        }
        foreach (self::VEHICLE_PREFIX as $prefix => $area) {
            if (str_starts_with($c, $prefix)) {
                return $area;
            }
        }

        return self::BASIC;
    }

    /** 기록 한 줄의 구역 — 화면 표시·건수 집계·컬럼 목록 좁히기가 쓴다. */
    public static function of(?string $type, ?string $action, ?string $column): string
    {
        if ($action !== null && isset(self::ACTION_AREA[$action])) {
            return self::ACTION_AREA[$action];
        }
        $short = class_basename((string) $type);
        if ($short === 'Vehicle') {
            return self::vehicleColumnArea($column);
        }

        return self::TYPE_AREA[$short] ?? self::ETC;
    }

    /**
     * 목록 쿼리에 구역 조건을 건다 — `of()` 와 같은 답이 나와야 한다(가드 `AuditAreaTest`).
     * 차량 칸은 스키마의 칸 이름을 `vehicleColumnArea` 로 미리 갈라 `whereIn` 으로 넘긴다(머리말 LIKE 를 SQL 에 다시 적지 않는다).
     */
    public static function scope($query, string $area): void
    {
        if (! isset(self::LABELS[$area])) {
            return;
        }
        $mapped = array_keys(self::ACTION_AREA);
        $actions = array_keys(self::ACTION_AREA, $area, true);
        $types = self::typeClassesFor($area);
        $vehicle = Vehicle::class;

        $query->where(function ($w) use ($area, $mapped, $actions, $types, $vehicle) {
            // 1) 액션이 정하는 것
            $w->whereIn('action', $actions ?: ['__none__']);
            // 2) 차량 — 액션이 구역을 안 정하는 기록만, 칸으로
            $w->orWhere(function ($v) use ($area, $mapped, $vehicle) {
                $v->where('auditable_type', $vehicle)->whereNotIn('action', $mapped);
                if ($area === self::BASIC) {
                    $v->where(fn ($c) => $c->whereNull('column_name')->orWhere('column_name', '')
                        ->orWhereNotIn(self::firstColumnSql(), self::vehicleColumnsOutside(self::BASIC)));
                } else {
                    $v->whereIn(self::firstColumnSql(), self::vehicleColumnsIn($area) ?: ['__none__']);
                }
            });
            // 3) 그 밖의 대상 — 액션이 구역을 안 정하는 기록만
            $w->orWhere(function ($t) use ($area, $mapped, $types, $vehicle) {
                $t->whereNotIn('action', $mapped);
                if ($area === self::ETC) {
                    // 설정·기타 = 대상이 없거나, 차량도 아니고 다른 구역 대상도 아닌 것(표에 없는 새 대상 포함)
                    $elsewhere = [$vehicle];
                    foreach (self::TYPE_AREA as $short => $a) {
                        if ($a !== self::ETC) {
                            $elsewhere[] = 'App\\Models\\'.$short;
                        }
                    }
                    $t->where(fn ($c) => $c->whereNull('auditable_type')->orWhereNotIn('auditable_type', $elsewhere));
                } else {
                    $t->whereIn('auditable_type', $types ?: ['__none__']);
                }
            });
        });
    }

    /** 일괄 기록(`a,b,c`)의 첫 칸 — SQLite·MySQL 공통 식. */
    private static function firstColumnSql(): Expression
    {
        $driver = DB::connection()->getDriverName();

        return DB::raw($driver === 'sqlite'
            ? "trim(case when instr(column_name, ',') > 0 then substr(column_name, 1, instr(column_name, ',') - 1) else column_name end)"
            : "trim(substring_index(column_name, ',', 1))");
    }

    /** @return list<string> 그 구역 대상의 클래스 전체 이름 */
    private static function typeClassesFor(string $area): array
    {
        return array_map(fn ($s) => 'App\\Models\\'.$s, array_keys(self::TYPE_AREA, $area, true));
    }

    /** @return list<string> 그 구역에 드는 차량 칸 이름(스키마 + 표식 이름) */
    public static function vehicleColumnsIn(string $area): array
    {
        return array_values(array_filter(self::vehicleColumnUniverse(), fn ($c) => self::vehicleColumnArea($c) === $area));
    }

    /** @return list<string> 그 구역 **밖**의 차량 칸 이름 */
    private static function vehicleColumnsOutside(string $area): array
    {
        return array_values(array_filter(self::vehicleColumnUniverse(), fn ($c) => self::vehicleColumnArea($c) !== $area)) ?: ['__none__'];
    }

    /** @var list<string>|null */
    private static ?array $universe = null;

    /** 차량 칸 이름 전체 = 스키마 칸 + `VEHICLE_EXACT` 의 표식 이름. 요청 한 번에 한 번만 읽는다. */
    private static function vehicleColumnUniverse(): array
    {
        return self::$universe ??= array_values(array_unique(array_merge(
            Schema::getColumnListing('vehicles'),
            array_keys(self::VEHICLE_EXACT),
            ['purchase_balance_payments', 'savings_earned', 'savings_used'],
        )));
    }
}
