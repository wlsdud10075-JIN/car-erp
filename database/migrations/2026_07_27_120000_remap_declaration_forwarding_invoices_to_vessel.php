<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 운임 인보이스 묶음 기준 변경(container › vessel › declaration, 2026-07-27) 후속 데이터 정리.
 *
 * 화면은 인보이스를 (forwarding_company_id, group_type, group_key) 로 찾는다. 우선순위가 바뀌면
 * declaration 으로 저장된 기존 인보이스는 새로 렌더되는 vessel 키와 안 맞아 **화면에서 사라진다**
 * (행은 남지만 조회가 안 됨 = 지급완료 기록이 안 보임). 그래서 안전한 것만 vessel 키로 옮긴다.
 *
 * ⚠️ 보수적으로 — "1:1 로 명확한" 인보이스만 옮긴다. 아래 중 하나라도 걸리면 건드리지 않는다:
 *   ① 해당 수출신고번호의 차량들이 선박명을 2개 이상 갖는 경우(인보이스 1장이 배 2척에 걸침)
 *   ② 선박명이 비어 있는 경우(옮길 대상이 없음 — declaration 폴백으로 계속 유효)
 *   ③ 같은 (회사, 선박명) 으로 옮겨질 declaration 인보이스가 2건 이상인 경우
 *      (1묶음=1인보이스 구조라 합칠지 말지는 사람이 판단할 몫)
 *   ④ 그 (회사, 선박명) 키를 이미 다른 인보이스가 점유한 경우
 * 남겨진 행은 실무자가 화면에서 배 단위로 재입력해 정리한다. 데이터는 지우지 않는다.
 *
 * 멱등 — declaration 행이 없으면 no-op(ssancarerp·karabaerp 는 대상 0건).
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('forwarding_invoices')->where('group_type', 'declaration')->get();
        if ($rows->isEmpty()) {
            return;
        }

        // 각 인보이스 → 옮겨갈 vessel 키 산출 (모호하면 null)
        $targets = [];
        foreach ($rows as $inv) {
            $vessels = DB::table('vehicles')
                ->where('export_declaration_number', $inv->group_key)
                ->whereNotNull('vessel_name')
                ->where('vessel_name', '!=', '')
                ->distinct()
                ->pluck('vessel_name');

            // ①② — 선박명이 정확히 하나일 때만 대상
            $targets[$inv->id] = $vessels->count() === 1 ? $vessels->first() : null;
        }

        // ③ — 같은 (회사|선박명) 으로 몰리는 건 전부 제외
        $bucket = [];
        foreach ($rows as $inv) {
            if ($targets[$inv->id] !== null) {
                $bucket[$inv->forwarding_company_id.'|'.$targets[$inv->id]][] = $inv->id;
            }
        }
        foreach ($bucket as $ids) {
            if (count($ids) > 1) {
                foreach ($ids as $id) {
                    $targets[$id] = null;
                }
            }
        }

        $moved = 0;
        foreach ($rows as $inv) {
            $vessel = $targets[$inv->id];
            if ($vessel === null) {
                continue;
            }

            // ④ — 대상 키를 이미 누가 쓰고 있으면 건드리지 않음
            $occupied = DB::table('forwarding_invoices')
                ->where('forwarding_company_id', $inv->forwarding_company_id)
                ->where('group_type', 'vessel')
                ->where('group_key', $vessel)
                ->exists();
            if ($occupied) {
                continue;
            }

            DB::table('forwarding_invoices')
                ->where('id', $inv->id)
                ->update(['group_type' => 'vessel', 'group_key' => $vessel]);
            $moved++;
        }

        echo "  forwarding_invoices: declaration → vessel 로 {$moved}건 이관 (전체 declaration ".$rows->count()."건)\n";
    }

    public function down(): void
    {
        // 되돌리지 않는다 — 원래 group_key(수출신고번호)를 복원할 근거가 이 테이블에 남지 않는다.
        // 되돌려야 하면 배포 전 백업본을 보고 수동 UPDATE 할 것.
    }
};
