<?php

namespace App\Console\Commands;

use App\Models\Vehicle;
use Illuminate\Console\Command;

/**
 * 거래완료인데 출고일이 비어 있는 차량에 **선적일**을 채운다 (jin 2026-08-20).
 *
 * 왜 생겼나: 2026-07-23 `cfd17f6` 에서 "거래완료면 출고일 미입력이어도 재고 아님" 으로 바꾸면서,
 * B/L 이 먼저 나온 차는 재고 화면에서 사라져 **출고일을 찍을 경로가 없어졌다**
 * (실측 heymanerp: 거래완료 100대 중 92대 공란). 그런데 채권 선적전/후 pivot 이 출고일이라
 * 이미 떠난 차가 「선적전 미수」로 남았다.
 *
 * 앞으로 생기는 차는 `Vehicle::saving` 이 자동으로 채운다 — 이 커맨드는 **기존 데이터 1회 보정**용이다.
 * 모델 save() 를 태워 캐시(progress·미수·위험도)까지 정상 갱신한다(bulk update 는 훅이 안 뜬다, SKILLS §2).
 */
class BackfillWarehouseOutDate extends Command
{
    protected $signature = 'vehicles:backfill-warehouse-out-date {--dry-run : 바꾸지 않고 대상만 보여준다}';

    protected $description = '거래완료 + 출고일 공란 차량의 출고일을 선적일로 채운다 (1회 보정).';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        // 대상 = 거래완료 + 출고일 없음 + 선적일 있음. 진행상태는 computed 라 PHP 에서 거른다
        //   (grandfather v1~v3 도 각자 규칙대로 판정된다).
        $rows = Vehicle::query()
            ->whereNull('warehouse_out_date')
            ->whereNotNull('shipping_date')
            ->get()
            ->filter(fn (Vehicle $v) => $v->progress_status === '거래완료');

        if ($rows->isEmpty()) {
            $this->info('대상 0건 — 보정할 것이 없습니다.');

            return self::SUCCESS;
        }

        $this->info(($dry ? '[dry-run] ' : '').'대상 '.$rows->count().'대');
        $this->table(
            ['차량번호', '선적일 → 출고일', '선박'],
            $rows->take(15)->map(fn (Vehicle $v) => [
                $v->vehicle_number,
                $v->shipping_date->format('Y-m-d'),
                $v->vessel_name ?: '-',
            ])->all()
        );
        if ($rows->count() > 15) {
            $this->line('  … 외 '.($rows->count() - 15).'대');
        }

        if ($dry) {
            $this->comment('dry-run 이므로 아무것도 바꾸지 않았습니다.');

            return self::SUCCESS;
        }

        $done = 0;
        foreach ($rows as $v) {
            // saving 훅이 같은 값을 채우지만, 명시적으로 넣어 의도를 남긴다(훅 조건이 바뀌어도 이 커맨드는 동작).
            $v->warehouse_out_date = $v->shipping_date;
            $v->save();
            $done++;
        }

        $this->info("출고일 보정 완료: {$done}대");

        return self::SUCCESS;
    }
}
