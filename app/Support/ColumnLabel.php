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
     * 테이블을 모를 때 컬럼명만으로 한글 라벨 조회 (audit_logs 컬럼 드롭다운용).
     * 전 테이블 그룹을 순회하며 첫 매칭을 반환. 없으면 영문 컬럼명 fallback.
     * (감사 로그 필터 드롭다운은 auditable_type 없이 column_name distinct 만 가지므로 테이블 특정 불가.)
     */
    public static function columnAny(?string $columnName): string
    {
        if ($columnName === null || $columnName === '') {
            return '-';
        }
        foreach (config('column_labels', []) as $key => $group) {
            if (in_array($key, ['models', 'actions'], true) || ! is_array($group)) {
                continue;
            }
            if (isset($group[$columnName])) {
                return $group[$columnName];
            }
        }

        return $columnName;
    }

    /**
     * 감사 로그 '변경' 열의 enum 원문값 → 한글 (2026-07-09 jin).
     * boolean 컬럼은 1/0 → 예/아니오. 매핑 없으면 원문 그대로 (금액·날짜·자유텍스트).
     */
    public static function value(string $modelOrTable, ?string $columnName, ?string $rawValue): ?string
    {
        if ($rawValue === null || $columnName === null || $columnName === '') {
            return $rawValue;
        }

        if (in_array($columnName, config('column_labels.boolean_columns', []), true)) {
            if ($rawValue === '1') {
                return '예';
            }
            if ($rawValue === '0') {
                return '아니오';
            }
        }

        $table = self::resolveTable($modelOrTable);
        if ($table !== null) {
            $mapped = config("column_labels.value_maps.$table.$columnName.$rawValue");
            if ($mapped !== null) {
                return $mapped;
            }
        }

        return $rawValue;
    }

    /**
     * audit_logs.action → 한글.
     */
    public static function action(string $action): string
    {
        return config("column_labels.actions.$action", $action);
    }
}
