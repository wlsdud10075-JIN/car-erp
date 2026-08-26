<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;

/**
 * karaba 비용 칸 재배치 (jin 2026-08-26) — 1회성 데이터 이관.
 *
 * 배경: karaba 매입탭의 12칸 비용 레이아웃을 3사 공통 10칸으로 정리하면서 고정비를 확정했다.
 *   말소비 17,300 / 점검비 80,000 / 주차료 50,000.
 *   그런데 운영 데이터가 그 이름과 어긋나 있었다 —
 *     cost_extra1(기타비1) = 50,000 × 64대  → 실제로는 **주차료**
 *     cost_inspection(점검비) = 80,000 × 31대 → 점검비 맞음
 *   karaba 는 기타비1 칸을 「점검비」로 부르기로 했으므로, 값을 한 칸씩 옮겨야 이름과 금액이 맞는다.
 *
 *     cost_extra1 → cost_parking   (주차료 제자리 찾기)
 *     cost_inspection → cost_extra1 (점검비 = karaba 라벨 「점검비」)
 *
 * 🔑 cost_total 불변 = 마진·정산 불변. 옮기기만 하고 더하거나 빼지 않는다.
 *    (cost_parking 은 getCostTotalAttribute 에 함께 추가됐다.)
 *
 * 🚫 **karaba 전용이다.** heymanerp 는 cost_extra1 에 진짜 기타비1이 7대·614,818원 들어 있어
 *    (2026-08-26 실측) 같이 옮기면 그 회사 회계가 틀어진다. 반드시 isKaraba() 로 게이팅할 것.
 *
 * 멱등: 완료 플래그(Setting)로 재실행을 막는다. 값 기준 판정은 안전하지 않다 —
 *    이관 후 상태가 「기타비1에 점검비가 든」 모습이라 한 번 더 돌리면 그게 주차료로 밀린다.
 */
class KarabaCostRemap
{
    public const DONE_FLAG = 'karaba_cost_remap_done';

    /**
     * @return array{skipped: bool, moved: int} skipped=true 면 karaba 아님 또는 이미 완료
     */
    public static function run(): array
    {
        if (! Setting::isKaraba()) {
            return ['skipped' => true, 'moved' => 0];
        }
        if (Setting::get(self::DONE_FLAG)) {
            return ['skipped' => true, 'moved' => 0];
        }

        $moved = 0;
        $rows = DB::table('vehicles')
            ->where(fn ($q) => $q->where('cost_extra1', '!=', 0)->orWhere('cost_inspection', '!=', 0))
            ->get(['id', 'cost_extra1', 'cost_inspection', 'cost_parking']);

        foreach ($rows as $row) {
            $parking = (int) ($row->cost_extra1 ?? 0);
            $inspection = (int) ($row->cost_inspection ?? 0);

            DB::table('vehicles')->where('id', $row->id)->update([
                'cost_parking' => $parking,
                'cost_extra1' => $inspection,
                'cost_inspection' => 0,
            ]);

            // 감사 추적 — raw update 라 모델 이벤트가 안 뜬다. 행위자는 시스템(null).
            $now = now();
            $log = [];
            foreach ([
                ['cost_parking', (int) ($row->cost_parking ?? 0), $parking],
                ['cost_extra1', $parking, $inspection],
                ['cost_inspection', $inspection, 0],
            ] as [$col, $old, $new]) {
                if ($old === $new) {
                    continue;
                }
                $log[] = [
                    'user_id' => null,
                    'auditable_type' => Vehicle::class,
                    'auditable_id' => $row->id,
                    'action' => 'updated',
                    'column_name' => $col,
                    'old_value' => (string) $old,
                    'new_value' => (string) $new,
                    'created_at' => $now,
                ];
            }
            if ($log !== []) {
                DB::table('audit_logs')->insert($log);
            }
            $moved++;
        }

        // 완료 플래그 — updateOrInsert 로 남긴다(Setting 에 정적 setter 가 없다).
        DB::table('settings')->updateOrInsert(
            ['key' => self::DONE_FLAG],
            ['value' => '1', 'type' => 'string', 'updated_at' => now(), 'created_at' => now()],
        );

        return ['skipped' => false, 'moved' => $moved];
    }
}
