<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => 'sidebar_brand'],
            [
                'value' => 'SSANCAR',
                'type' => 'string',
                'description' => '사이드바 헤더 브랜드 텍스트 (최대 12자)',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'sidebar_brand')->delete();
    }
};
