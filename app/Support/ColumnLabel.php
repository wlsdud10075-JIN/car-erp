<?php

namespace App\Support;

use App\Models\Vehicle;

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
        if (($dynamic = self::dynamicKey($columnName)) !== null) {
            return $dynamic;
        }
        // 🔑 일괄 작업은 컬럼을 **콤마로 이어 붙여** 기록한다(`shipping_date,eta_date,vessel_name`).
        //    쪼개서 각각 번역하지 않으면 화면에 영문이 그대로 뜨고, 무엇보다 **조합마다 사전 항목이
        //    하나씩 더 필요**해진다 — 일괄 대상이 늘 때마다 사람이 사전을 고쳐야 한다는 뜻이다.
        //    쪼개 두면 조합이 몇 가지든 컬럼 사전 하나로 전부 풀린다.
        if (str_contains($columnName, ',')) {
            $parts = array_filter(array_map('trim', explode(',', $columnName)), fn ($p) => $p !== '');

            return $parts === []
                ? $columnName
                : implode(', ', array_map(fn ($p) => self::column($modelOrTable, $p), $parts));
        }
        $table = self::resolveTable($modelOrTable);
        if ($table === null) {
            return $columnName;
        }

        // 회사 프로파일별 비용 라벨(karaba 점검비·기타비)은 Vehicle::costLabel 이 단일 출처다.
        //   여기서 사전을 그대로 읽으면 감사로그만 「기타비1」로 떠서 화면과 이름이 갈린다.
        if ($table === 'vehicles' && isset(Vehicle::COST_LABEL_KEYS_KARABA[$columnName])) {
            return Vehicle::costLabel($columnName);
        }

        $label = config("column_labels.$table.$columnName", $columnName);

        // 방어 — 사전에 중첩 구조가 들어와도 500 대신 영문 fallback (columnAny 와 동일 정책).
        return is_string($label) ? $label : $columnName;
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
            // 2026-07-31 — 감사로그에 실제로 쌓이는데 표가 없어 컬럼명이 영문으로 새던 것들.
            'CashSnapshot' => 'cash_snapshots',
            'Setting' => 'settings',
            'SettlementPayoutBatch' => 'settlement_payout_batches',
            'SignedContract' => 'signed_contracts',
            // 2026-08-04 — 차등정산 on/off 가 감사로그에 쌓이기 시작(정산 금액 직결).
            'Salesman' => 'salesmen',
            // 2026-08-07 — 휴가 대리 위임(스코프·승인 계단을 넘기므로 감사 필수).
            'UserDelegation' => 'user_delegations',
            // 2026-08-12 — 가수금·경매보증금(청산가치에 억 단위로 들어간다).
            'AdvanceReceipt' => 'advance_receipts',
            'AuctionDeposit' => 'auction_deposits',
        ];

        return $map[$short] ?? null;
    }

    /**
     * 챗봇 질문 유형(intent) → 한글 (2026-07-31).
     *
     * action='assistant_query' 감사로그는 column_name 에 컬럼이 아니라 intent 를 넣는다.
     * 권한 밖 질문은 '{intent}(denied)' 로 저장되므로 접미사를 떼고 조회한 뒤 「(권한없음)」을 붙인다.
     */
    public static function assistantIntent(?string $raw): string
    {
        if ($raw === null || $raw === '') {
            return '-';
        }
        $denied = str_ends_with($raw, '(denied)');
        $intent = $denied ? substr($raw, 0, -strlen('(denied)')) : $raw;
        $label = config("column_labels.assistant_intents.$intent", $intent);
        $label = is_string($label) ? $label : $intent;

        return $denied ? $label.' (권한없음)' : $label;
    }

    /**
     * 테이블을 모를 때 컬럼명만으로 한글 라벨 조회 (audit_logs 컬럼 드롭다운용).
     * 전 테이블 그룹을 순회하며 첫 매칭을 반환. 없으면 영문 컬럼명 fallback.
     * (감사 로그 필터 드롭다운은 auditable_type 없이 column_name distinct 만 가지므로 테이블 특정 불가.)
     */
    /**
     * 값이 박힌 동적 키 → 한글 (2026-07-31).
     *
     * 일부 이벤트는 column_name 에 고정 컬럼이 아니라 대상 이름을 붙여 쓴다(예: 'buyer:AUTO SCOUT').
     * 사전에 바이어를 하나씩 넣을 수는 없으므로 **접두사 패턴**으로 처리한다. 해당 없으면 null.
     */
    private static function dynamicKey(string $columnName): ?string
    {
        if (str_starts_with($columnName, 'buyer:')) {
            $name = trim(substr($columnName, strlen('buyer:')));

            return $name === '' ? '바이어' : "바이어 ({$name})";
        }

        return null;
    }

    public static function columnAny(?string $columnName): string
    {
        if ($columnName === null || $columnName === '') {
            return '-';
        }
        if (($dynamic = self::dynamicKey($columnName)) !== null) {
            return $dynamic;
        }
        // ⚠️ 'value_maps' 제외 필수 (2026-07-28 운영 500 수정).
        //   value_maps 는 "컬럼→라벨" 이 아니라 "테이블→(컬럼→값매핑)" 2단 구조라 값이 배열이다.
        //   그래서 column_name 이 테이블명과 겹치면(예: purchase_payment_after_paid 이벤트가
        //   column_name 에 'purchase_balance_payments' 를 넣음) 배열을 반환해 감사로그 화면이 500 났다.
        //   is_string 방어도 함께 — 사전에 새 2단 그룹이 추가돼도 화면이 죽지 않게.
        foreach (config('column_labels', []) as $key => $group) {
            if (in_array($key, ['models', 'actions', 'value_maps'], true) || ! is_array($group)) {
                continue;
            }
            if (isset($group[$columnName]) && is_string($group[$columnName])) {
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
