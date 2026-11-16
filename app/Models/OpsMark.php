<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * 관제센터 「이 루틴을 했다」 한 줄 (jin 2026-11-16).
 *   쓰는 길 = 관제 화면 [했음] 버튼(button) · `ops:mark` 커맨드(command — GPU PC 가 SSH 로, 개발 PC 가 --push 로) · 서명 POST(api).
 *   뜻(주기·이름)은 OpsRoutines 가 안다 — 여기는 기록만.
 */
class OpsMark extends Model
{
    use MassPrunable;

    public const KEEP_DAYS = 400;

    /** todo = 돌았지만 사람이 할 일이 남음(메모리 정리 후보 등) — 관제에서 주의 */
    public const STATUSES = ['ok', 'failed', 'todo'];

    public const SOURCES = ['button', 'command', 'api'];

    public $timestamps = false;

    protected $fillable = ['key', 'status', 'note', 'source', 'user_id', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(self::KEEP_DAYS));
    }

    /** 키 형식 — 영소문자·숫자·하이픈(OpsRoutines 키와 같은 모양) */
    public static function validKey(string $key): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9-]{1,59}$/', $key) === 1;
    }

    /**
     * 요약 응답용 — 키마다 최근 기록 몇 줄(최신순). 주간 판정에 지난주까지 보이도록 기간으로 자른다.
     *
     * @return array<string, list<array{status: string, at: string, note: ?string, source: string}>>
     */
    public static function recentByKey(int $days = 21, int $perKey = 10): array
    {
        $out = [];
        foreach (static::where('created_at', '>=', now()->subDays($days))->orderByDesc('created_at')->orderByDesc('id')->get() as $m) {
            if (count($out[$m->key] ?? []) >= $perKey) {
                continue;
            }
            $out[$m->key][] = ['status' => $m->status, 'at' => $m->created_at->toIso8601String(), 'note' => $m->note, 'source' => $m->source];
        }

        return $out;
    }
}
