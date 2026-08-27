<?php

use App\Models\Vehicle;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 도착·완납했는데 B/L 파일만 없어서 「진행중」으로 남은 옛 차량 소급 정리 (jin 2026-08-27, 1회성).
 *
 * 배경: 엑셀 임포트로 들어온 차량들이 선적일·ETA·선사·출고일까지 다 있고 완납·정산(paid)까지 끝났는데
 *   **B/L 서류 파일만 ERP 에 없어서** 진행상태가 판매완료/선적중에 머물렀다. 그 값이 ssancar.com 포털에
 *   「아직 진행중」으로 나가 데이터가 이상해졌다. 배가 떴으니 B/L 은 실제로 발급됐고 파일만 없는 것이다.
 *   서류는 실무자가 찾아서 개별 업로드하기로 했다(jin) — 올라오면 v4 규칙으로도 거래완료라 결과가 같다.
 *
 * 🔑 **진행상태는 계산값이다.** `progress_status_cache` 를 직접 써봐야 다음 저장이나 다음날 05:00
 *   `vehicles:rebuild-caches` 가 덮어쓴다. 그래서 값이 아니라 **판정 규칙**을 그 행에만 바꾼다
 *   (`progress_status_rule_version = 5`, v1~v3 grandfather 와 같은 방식).
 *
 * 대상 = jin 이 지정한 조건 그대로. 실측(2026-08-26 heymanerp) 20대, ssancarerp·karabaerp 0대.
 *   ⚓도착(선적일·ETA 있고 ETA 경과) · 거래완료 아님 · B/L 서류 없음 · 선사 기입 · 출고일 있음
 *   · **ETA < 2026-07-31** · **완납**(미수 ≤ 0)
 *   ⚠️ ETA 8/1 이후는 손대지 않는다 — 실무자가 진행하면서 처리한다(jin).
 *
 * 되돌리기: down() 이 rule_version 을 4 로 되돌리고 캐시를 재계산한다.
 */
return new class extends Migration
{
    /** 이 마이그레이션이 건드린 행 (down 에서 정확히 그것만 되돌리려고 표시해 둔다). */
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
            ->whereNotNull('vessel_name')
            ->where('vessel_name', '!=', '')
            ->whereNotNull('warehouse_out_date')
            ->where(fn ($q) => $q->where('progress_status_cache', '!=', '거래완료')
                ->orWhereNull('progress_status_cache'))
            ->where('progress_status_rule_version', '<', self::MARK)
            ->get();

        $now = now();
        foreach ($candidates as $v) {
            // 완납만 — 미수는 accessor(단일 출처)로 판정한다. SQL 로 옮겨 적으면 갈린다(SKILLS §13).
            if ((float) $v->sale_unpaid_amount > 0) {
                continue;
            }

            $old = (int) ($v->progress_status_rule_version ?? 4);

            // 규칙 번호를 바꾼 뒤 캐시를 다시 계산한다 — 순서가 바뀌면 옛 규칙으로 캐시가 굳는다.
            DB::table('vehicles')->where('id', $v->id)->update([
                'progress_status_rule_version' => self::MARK,
            ]);
            $fresh = Vehicle::find($v->id);
            DB::table('vehicles')->where('id', $v->id)->update([
                'progress_status_cache' => $fresh->progress_status,
            ]);

            // 감사 추적 — raw update 라 모델 이벤트가 안 뜬다. 행위자는 시스템(null).
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
        foreach (Vehicle::where('progress_status_rule_version', self::MARK)->get() as $v) {
            DB::table('vehicles')->where('id', $v->id)->update([
                'progress_status_rule_version' => 4,
            ]);
            $fresh = Vehicle::find($v->id);
            DB::table('vehicles')->where('id', $v->id)->update([
                'progress_status_cache' => $fresh->progress_status,
            ]);
        }
    }
};
