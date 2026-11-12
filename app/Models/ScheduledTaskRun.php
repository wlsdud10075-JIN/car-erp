<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 예약 작업 실행 1회 — RecordScheduledTaskRun 리스너가 쓴다(관제센터 「루틴」 칸, jin 11-12).
 */
class ScheduledTaskRun extends Model
{
    use MassPrunable;

    public const STATUSES = ['running', 'ok', 'failed'];

    public const KEEP_DAYS = 90;

    public $timestamps = false;

    protected $fillable = ['command', 'expression', 'status', 'exit_code', 'error', 'started_at', 'finished_at', 'duration_ms'];

    protected $casts = ['started_at' => 'datetime', 'finished_at' => 'datetime'];

    public function prunable(): Builder
    {
        return static::where('started_at', '<', now()->subDays(self::KEEP_DAYS));
    }

    /**
     * 작업마다 가장 최근 실행 1줄 + 가장 최근 성공 시각 — 관제센터 표가 쓴다.
     *
     * @return array<string, array{last: self, last_ok_at: ?Carbon}>
     */
    public static function latestByCommand(): array
    {
        $lastIds = static::query()->selectRaw('MAX(id) AS id')->groupBy('command')->pluck('id');
        $lastOk = static::query()->where('status', 'ok')->selectRaw('command, MAX(finished_at) AS at')->groupBy('command')->pluck('at', 'command');
        $out = [];
        foreach (static::whereIn('id', $lastIds)->orderBy('command')->get() as $run) {
            $out[$run->command] = ['last' => $run, 'last_ok_at' => isset($lastOk[$run->command]) ? Carbon::parse($lastOk[$run->command]) : null];
        }

        return $out;
    }
}
