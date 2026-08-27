<?php

use App\Models\Vehicle;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 앞 마이그(`..._000001`)에서 **선사(vessel_name) 칸이 비어** 빠진 차량 보완 (jin 2026-08-27).
 *
 * jin 의 기준은 「ETA 7/31 이전 · 완납이면 거래완료」였는데, 첫 설명에 나온 "선사를 기입한 게 있지만"을
 * 내가 **필수 조건으로 오해**해 넣었다. 그 한 줄 때문에 9대가 남았다 — 전부 완납·출고일 있음·정산 paid.
 * 선사는 「배가 떴다」의 방증일 뿐이고, **출고일 + ETA 경과**가 이미 같은 사실을 더 확실히 말한다.
 *
 * 🧭 조건을 넓히는 변경이므로 **순수 확대**다 — 앞 마이그가 잡은 20대는 그대로 두고(이미 rule_version=5),
 *   선사 조건만 뺀 나머지를 추가로 잡는다. 실측 heymanerp 9대 / ssancarerp·karabaerp 0대.
 *
 * 규칙(v5)·되돌리기·감사로그는 `..._000001` 과 동일. 상세 배경은 그 파일과 Vehicle::getProgressStatusAttribute 주석.
 */
return new class extends Migration
{
    private const MARK = 5;

    public function up(): void
    {
        $cutoff = '2026-07-31';
        $endOfToday = now()->toDateString().' 23:59:59';

        $candidates = Vehicle::query()
            ->whereNotNull('shipping_date')
            ->whereNotNull('eta_date')
            ->where('eta_date', '<=', $endOfToday)
            ->where('eta_date', '<', $cutoff)
            ->whereNull('bl_document')
            ->whereNotNull('warehouse_out_date')
            // ⚠️ 선사(vessel_name) 조건 없음 — 이게 이 마이그의 전부다.
            ->where(fn ($q) => $q->where('progress_status_cache', '!=', '거래완료')
                ->orWhereNull('progress_status_cache'))
            ->where('progress_status_rule_version', '<', self::MARK)
            ->get();

        $now = now();
        foreach ($candidates as $v) {
            if ((float) $v->sale_unpaid_amount > 0) {
                continue;   // 완납만 (jin)
            }

            $old = (int) ($v->progress_status_rule_version ?? 4);

            DB::table('vehicles')->where('id', $v->id)->update([
                'progress_status_rule_version' => self::MARK,
            ]);
            $fresh = Vehicle::find($v->id);
            DB::table('vehicles')->where('id', $v->id)->update([
                'progress_status_cache' => $fresh->progress_status,
            ]);

            DB::table('audit_logs')->insert([
                [
                    'user_id' => null,
                    'auditable_type' => Vehicle::class,
                    'auditable_id' => $v->id,
                    'action' => 'updated',
                    'column_name' => 'progress_status_rule_version',
                    'old_value' => (string) $old,
                    'new_value' => (string) self::MARK,
                    'created_at' => $now,
                ],
                [
                    'user_id' => null,
                    'auditable_type' => Vehicle::class,
                    'auditable_id' => $v->id,
                    'action' => 'updated',
                    'column_name' => 'progress_status_cache',
                    'old_value' => (string) $v->progress_status_cache,
                    'new_value' => (string) $fresh->progress_status,
                    'created_at' => $now,
                ],
            ]);
        }
    }

    public function down(): void
    {
        // `..._000001` 의 down() 이 rule_version=5 전체를 4로 되돌린다. 여기서 또 되돌리면 이중 처리라 no-op.
    }
};
