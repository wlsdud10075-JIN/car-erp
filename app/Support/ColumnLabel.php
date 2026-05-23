<?php

namespace App\Support;

/**
 * 회의확장씬 보강 (2026-05-23) — 컬럼·모델 영문 식별자 → 한글 라벨 변환.
 *
 * 사용처:
 *   - audit_logs UI (column_name, auditable_type, action 한글 표시)
 *   - validation 메시지 (attribute 라벨)
 *   - exception/notify 메시지 컬럼 언급 시
 *
 * 매핑 사전: config/column_labels.php
 * 매핑 없는 컬럼은 영문 그대로 fallback.
 */
class ColumnLabel
{
    /**
     * 모델 클래스명 또는 짧은 이름 → 한글 라벨.
     */
    public static function model(string $classOrShort): string
    {
        $short = class_basename($classOrShort);

        return config("column_labels.models.$short", $short);
    }

    /**
     * 모델(클래스명 또는 짧은 이름) + 컬럼명 → 한글 라벨.
     * 매핑 없으면 영문 컬럼명 fallback.
     */
    public static function column(string $modelOrTable, ?string $columnName): string
    {
        if ($columnName === null || $columnName === '') {
            return '-';
        }
        $table = self::resolveTable($modelOrTable);
        if ($table === null) {
            return $columnName;
        }

        return config("column_labels.$table.$columnName", $columnName);
    }

    /**
     * 모델 클래스명(FQN 또는 short) → 테이블 키 (config 키).
     */
    public static function resolveTable(string $modelOrTable): ?string
    {
        // 이미 테이블 키면 그대로
        if (config("column_labels.$modelOrTable") !== null) {
            return $modelOrTable;
        }
        $short = class_basename($modelOrTable);

        // 클래스 단축명 → table 추정 (Eloquent convention)
        $map = [
            'Vehicle' => 'vehicles',
            'FinalPayment' => 'final_payments',
            'PurchaseBalancePayment' => 'purchase_balance_payments',
            'Settlement' => 'settlements',
            'Buyer' => 'buyers',
            'Consignee' => 'consignees',
            'SavingsStatus' => 'savings_statuses',
            'User' => 'users',
            'ApprovalRequest' => 'approval_requests',
        ];

        return $map[$short] ?? null;
    }

    /**
     * audit_logs.action → 한글.
     */
    public static function action(string $action): string
    {
        return config("column_labels.actions.$action", $action);
    }
}
