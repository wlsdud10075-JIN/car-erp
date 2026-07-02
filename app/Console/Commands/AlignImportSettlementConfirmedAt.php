<?php

namespace App\Console\Commands;

use App\Models\Settlement;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 정산 귀속월 앵커 전환(confirmed_at) 후속 backfill (jin 2026-07-02).
 *
 * 실시간 정산 귀속월이 confirmed_at 기준으로 바뀌면서, 엑셀 CK 일괄 import 분은
 * confirmed_at 이 import 실행일(2026-06-22)로 박혀 있어 전부 6월로 뭉개진다.
 * import 분의 created_at 은 이미 일한月(4/5월)로 백데이트돼 있으므로,
 * confirmed_at 을 created_at 으로 맞춰 4/5월 배치를 보존한다.
 *
 * paid/closed 회계잠금 가드 우회 위해 raw DB update 사용 (RecreateSettlementsFromCk 와 동일 패턴).
 * 대상 = CK 재생성 마커(note LIKE '재생성 CK정산%') 정산만. idempotent.
 */
class AlignImportSettlementConfirmedAt extends Command
{
    protected $signature = 'settlements:align-import-confirmed-at {--dry-run : 변경 없이 대상 건수만 출력}';

    protected $description = 'CK 일괄 import 정산의 confirmed_at 을 created_at(일한月)으로 정렬 — 귀속월 앵커 전환 후속';

    public function handle(): int
    {
        $query = Settlement::query()
            ->where('note', 'like', '재생성 CK정산%')
            ->whereNotNull('confirmed_at')
            ->whereColumn('confirmed_at', '!=', 'created_at');

        $count = $query->count();

        if ($this->option('dry-run')) {
            $this->info("대상 {$count}건 (confirmed_at ≠ created_at 인 CK import 정산). --dry-run — 변경 없음.");

            return self::SUCCESS;
        }

        if ($count === 0) {
            $this->info('정렬 대상 없음 (이미 confirmed_at = created_at 이거나 CK import 정산 없음).');

            return self::SUCCESS;
        }

        // raw update — paid/closed 가드 우회 (Eloquent save 는 booted 가드에 막힘).
        $affected = DB::table('settlements')
            ->where('note', 'like', '재생성 CK정산%')
            ->whereNotNull('confirmed_at')
            ->whereColumn('confirmed_at', '!=', 'created_at')
            ->update(['confirmed_at' => DB::raw('created_at')]);

        $this->info("✅ 완료: {$affected}건 confirmed_at → created_at(일한月) 정렬. 귀속월 배치(4/5월) 보존됨.");

        return self::SUCCESS;
    }
}
