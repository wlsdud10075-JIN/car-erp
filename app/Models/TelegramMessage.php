<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * 텔레그램으로 나간 알림의 사본 (관제센터, jin 2026-11-16 «전체 보여주고 접었다 폈다»).
 * TelegramNotifier::send 가 남긴다 — GPU PC 야간 보고도 heymanerp `notify:send` 를 거치므로 여기 남는다.
 */
class TelegramMessage extends Model
{
    use MassPrunable;

    public const KEEP_DAYS = 60;

    public $timestamps = false;

    protected $fillable = ['title', 'body', 'sent', 'created_at'];

    protected $casts = ['sent' => 'boolean', 'created_at' => 'datetime'];

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(self::KEEP_DAYS));
    }
}
