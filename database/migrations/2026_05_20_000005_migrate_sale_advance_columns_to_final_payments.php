<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 큐 22-A Mig B (2026-05-20) — vehicles 입금 4컬럼 → final_payments rows 이동.
 *
 * 회의록 사용자 정정 2026-05-19 새회의 6번 해석 B:
 * "입금/입금현황은 매입도 그렇고 판매도 그렇고 재무만 손댈 수 있는 게 맞을 것 같아."
 *
 * 4컬럼:
 *   - deposit_down_payment → FP type='deposit_down'
 *   - interim_payment      → FP type='interim'
 *   - advance_payment1     → FP type='advance_1'
 *   - advance_payment2     → FP type='advance_2'
 *
 * 핵심 정책 (advisor 권고):
 *   1. confirmed_at = vehicle.created_at — 기존 4컬럼은 분자 합산 대상 = "Posted" 의미.
 *      NULL Draft로 옮기면 sale_unpaid 갑자기 증가 → KPI 오작동. created_at으로 Posted 보존.
 *   2. 이벤트 우회 — raw DB::table::insert 사용. FinalPayment::created 훅이
 *      ReceivableHistory 자동 미러링하면 운영 채권 이력 부풀음.
 *   3. vehicles 4컬럼은 0으로 clear — 분자 호환성 코드(분자에 4컬럼+FP 동시 합산)에서
 *      이중 합산 차단. 다음 세션 Mig C에서 DROP.
 *
 * down: FP의 type ≠ 'balance' rows 삭제 + vehicles 4컬럼 복원 (vehicle_id 단위 합산).
 */
return new class extends Migration
{
    public function up(): void
    {
        $typeMap = [
            'deposit_down_payment' => 'deposit_down',
            'interim_payment' => 'interim',
            'advance_payment1' => 'advance_1',
            'advance_payment2' => 'advance_2',
        ];

        $now = now()->format('Y-m-d H:i:s');

        DB::table('vehicles')
            ->select(['id', 'created_at', 'deposit_down_payment', 'interim_payment',
                'advance_payment1', 'advance_payment2'])
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
                            'note' => '마이그레이션 — 4컬럼 → FP type enum 통합 (22-A)',
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                    if ($hasAny) {
                        $updateIds[] = $v->id;
                    }
                }

                if (! empty($inserts)) {
                    // raw insert — FinalPayment::created 훅 우회 (ReceivableHistory 자동 미러링 차단).
                    DB::table('final_payments')->insert($inserts);
                }
                if (! empty($updateIds)) {
                    // 4컬럼 0으로 clear — 분자 이중 합산 차단.
                    DB::table('vehicles')->whereIn('id', $updateIds)->update([
                        'deposit_down_payment' => 0,
                        'interim_payment' => 0,
                        'advance_payment1' => 0,
                        'advance_payment2' => 0,
                    ]);
                }
            });
    }

    public function down(): void
    {
        // type ≠ 'balance' FP rows를 vehicles 4컬럼으로 복원.
        $typeMap = [
            'deposit_down' => 'deposit_down_payment',
            'interim' => 'interim_payment',
            'advance_1' => 'advance_payment1',
            'advance_2' => 'advance_payment2',
        ];

        foreach ($typeMap as $type => $col) {
            $sums = DB::table('final_payments')
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
        DB::table('final_payments')->where('type', '!=', 'balance')->delete();
    }
};
