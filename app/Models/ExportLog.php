<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 데이터 export 감사 로그 (append-only). 2026-06-29 라운드테이블 필수 선행조건.
 */
class ExportLog extends Model
{
    public $timestamps = false;   // created_at(useCurrent)만, append-only

    protected $fillable = ['user_id', 'ip_address', 'target', 'scope', 'row_count', 'columns', 'filters'];

    protected $casts = [
        'columns' => 'array',
        'filters' => 'array',
        'created_at' => 'datetime',
    ];
}
