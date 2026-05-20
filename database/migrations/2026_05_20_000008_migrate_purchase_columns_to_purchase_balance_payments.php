<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 큐 22-C-D Mig B (2026-05-20) — vehicles 매입 2컬럼 → purchase_balance_payments rows 이동.
 *
 * 회의록 2026-05-19-purchase-flow-redesign.md 큐 22-C 컬럼 DROP.
 *
 * 2컬럼:
 *   - down_payment         → PBP type='down'
 *   - selling_fee_payment  → PBP type='selling_fee'
 *
 * 핵심 정책 (22-A Mig B 패턴 미러링):
 *   1. confirmed_at = vehicle.created_at — 기존 2컬럼은 분자 합산 대상 = "Posted" 의미.
 *      NULL Draft로 옮기면 purchase_unpaid 갑자기 증가 → KPI 오작동. created_at으로 Posted 보존.
 *   2. 이벤트 우회 — raw DB::table::insert 사용. PBP::creating 가드(paid + canConfirmFinance) 우회.
 *   3. vehicles 2컬럼은 0으로 clear — 22-C-E Mig C 에서 DROP 전까지 분자 이중 합산 차단.
 *   4. created_by_user_id = null — 시스템 마이그레이션 표시.
 *
 * down: PBP의 type ∈ {'down', 'selling_fee'} rows 삭제 + vehicles 2컬럼 복원 (vehicle_id 단위 합산).
 */
return new class extends Migration
{
    public function up(): void
    {
        $typeMap = [
            'down_payment' => 'down',
            'selling_fee_payment' => 'selling_fee',
        ];

        $now = now()->format('Y-m-d H:i:s');

        DB::table('vehicles')
            ->select(['id', 'created_at', 'down_payment', 'selling_fee_payment'])
            ->orderBy('id')
            ->chunk(500, function ($vehicles) use ($typeMap, $now) {
                $inserts = [];
                $updateIds = [];

                foreach ($vehicles as $v) {
                    $hasAny = false;
                    foreach ($typeMap as $col => $type) {
                        $amount = (float) ($v->{$col} ?? 0);
                        if ($amount <= 0) {
                            continue;
                        }
                        $hasAny = true;
                        $inserts[] = [
                            'vehicle_id' => $v->id,
                            'amount' => $amount,
                            'type' => $type,
                            'payment_date' => null,
                            'confirmed_at' => $v->created_at,
                            'created_by_user_id' => null,
                            'note' => '마이그레이션 — 2컬럼 → PBP type enum 통합 (22-C-D)',
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                    if ($hasAny) {
                        $updateIds[] = $v->id;
                    }
                }

                if (! empty($inserts)) {
                    DB::table('purchase_balance_payments')->insert($inserts);
                }
                if (! empty($updateIds)) {
                    DB::table('vehicles')->whereIn('id', $updateIds)->update([
                        'down_payment' => 0,
                        'selling_fee_payment' => 0,
                    ]);
                }
            });
    }

    public function down(): void
    {
        $typeMap = [
            'down' => 'down_payment',
            'selling_fee' => 'selling_fee_payment',
        ];

        foreach ($typeMap as $type => $col) {
            $sums = DB::table('purchase_balance_payments')
                ->where('type', $type)
                ->whereNotNull('confirmed_at')
                ->groupBy('vehicle_id')
                ->selectRaw('vehicle_id, SUM(amount) AS total')
                ->get();
            foreach ($sums as $row) {
                DB::table('vehicles')->where('id', $row->vehicle_id)->update([
                    $col => $row->total,
                ]);
            }
        }
        DB::table('purchase_balance_payments')->whereIn('type', ['down', 'selling_fee'])->delete();
    }
};
