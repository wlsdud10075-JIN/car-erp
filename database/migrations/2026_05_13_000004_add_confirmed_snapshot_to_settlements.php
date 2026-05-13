<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 큐 10 H4 — 정산 retroactive drift 잠금.
        // status=paid 전환 시점의 vehicle 회계 컬럼 + 마진 계산 결과를 snapshot으로 캡처.
        // 이후 vehicle 회계 컬럼 변경은 vehicles/index::save()에서 차단 + UI에 snapshot 표시.
        Schema::table('settlements', function (Blueprint $table) {
            $table->json('confirmed_snapshot')->nullable()->after('paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('settlements', function (Blueprint $table) {
            $table->dropColumn('confirmed_snapshot');
        });
    }
};
