<?php

namespace App\Console\Commands;

use App\Models\Buyer;
use App\Models\Consignee;
use App\Services\BuyerRebindService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 매달린 바이어·컨사이니 참조 점검 + 이관 (jin 2026-09-23, SKILLS §8 #110).
 *
 * 「바이어 등록이 안 됐다」는 NULL 이 아니라 **삭제된 행을 가리키는 것**부터 센다. ssancarerp 실측 — NULL 0대, 삭제된 ATLAS #393 을
 * 가리키는 차 29대. 화면은 `buyer?->name` 이라 빈칸으로만 보인다.
 *
 *   php artisan buyers:check-dangling                      # 3사 각 서버에서 읽기 전용 점검
 *   php artisan buyers:check-dangling --rebind=393:47      # 이관 계획(dry-run)
 *   php artisan buyers:check-dangling --rebind=393:47 --apply
 *
 * 이관 코드는 화면 삭제 모달과 같은 BuyerRebindService 다(§8 #44).
 */
class CheckDanglingBuyers extends Command
{
    protected $signature = 'buyers:check-dangling
        {--rebind= : 삭제된 바이어 ID:살아있는 바이어 ID (예 393:47) — 그 바이어의 차량 3컬럼 + 컨사이니를 넘긴다}
        {--apply : 실제 이관 (미지정=dry-run)}';

    protected $description = '삭제되거나 없는 바이어·컨사이니를 가리키는 차량을 찾고, --rebind 로 살아있는 바이어에게 넘긴다';

    public function handle(): int
    {
        $dangling = 0;

        foreach (array_keys(BuyerRebindService::VEHICLE_COLUMNS) as $col) {
            $dangling += $this->report($col, 'buyers', Buyer::class);
        }
        foreach (BuyerRebindService::CONSIGNEE_COLUMNS as $col) {
            $dangling += $this->report($col, 'consignees', Consignee::class);
        }

        $this->line('');
        $this->info("매달린 참조 합계: {$dangling}건 (차량×컬럼)");

        if ($rebind = (string) $this->option('rebind')) {
            return $this->rebind($rebind, (bool) $this->option('apply'));
        }

        return self::SUCCESS;
    }

    /** 차량 컬럼 하나에 대해 「삭제된 행」·「없는 행」을 가리키는 차량을 표로. 반환 = 건수. */
    private function report(string $col, string $table, string $model): int
    {
        $rows = DB::table('vehicles as v')
            ->leftJoin("{$table} as t", 't.id', '=', "v.{$col}")
            ->whereNotNull("v.{$col}")
            ->where(fn ($q) => $q->whereNull('t.id')->orWhereNotNull('t.deleted_at'))
            ->orderBy("v.{$col}")->orderBy('v.id')
            ->get(['v.id', 'v.vehicle_number', 'v.deleted_at as vehicle_deleted_at', "v.{$col} as ref_id", 't.name as ref_name', 't.deleted_at as ref_deleted_at']);

        if ($rows->isEmpty()) {
            $this->line("  {$col}: 이상 없음");

            return 0;
        }

        $this->warn("── vehicles.{$col} → {$table}: {$rows->count()}대 ──");
        $this->table(
            ['참조 ID', '이름', '참조 삭제일', '차량 ID', '차량번호', '차량 삭제'],
            $rows->map(fn ($r) => [
                $r->ref_id,
                $r->ref_name ?? '(행 없음)',
                $r->ref_deleted_at ?? '(없음)',
                $r->id,
                $r->vehicle_number,
                $r->vehicle_deleted_at ? 'Y' : '',
            ])->all(),
        );

        return $rows->count();
    }

    private function rebind(string $spec, bool $apply): int
    {
        if (! preg_match('/^(\d+):(\d+)$/', $spec, $m)) {
            $this->error("--rebind 형식은 OLD:NEW (예 393:47) — 받은 값: {$spec}");

            return self::FAILURE;
        }

        $from = Buyer::withTrashed()->find((int) $m[1]);
        $to = Buyer::withTrashed()->find((int) $m[2]);
        if (! $from || ! $to) {
            $this->error('바이어를 찾지 못했다 — from='.($from?->id ?? 'X').' to='.($to?->id ?? 'X'));

            return self::FAILURE;
        }

        $this->line('');
        $this->info(sprintf('이관: #%d %s%s → #%d %s%s', $from->id, $from->name, $from->trashed() ? ' (삭제됨)' : '', $to->id, $to->name, $to->trashed() ? ' (삭제됨!)' : ''));

        try {
            $plan = BuyerRebindService::rebind($from, $to, $apply);
        } catch (\DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(['차량 ID', '차량번호', '넘길 컬럼'], collect($plan['vehicles'])->map(fn ($p) => [$p['id'], $p['number'], implode(', ', $p['columns'])])->all());
        $this->info(sprintf('차량 %d대 · 컨사이니 %d건 → %s', count($plan['vehicles']), $plan['consignees'], $apply ? '✅ 이관 완료 (감사로그 기록)' : 'dry-run (--apply 로 실행)'));

        if ($apply) {
            $left = BuyerRebindService::vehiclesOf($from)->count();
            $this->line("검증: #{$from->id} 를 아직 가리키는 차량 = {$left}대");
        }

        return self::SUCCESS;
    }
}
