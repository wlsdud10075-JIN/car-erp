<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 퇴사자 바이어 승계 표시 (jin 2026-08-04).
 *
 * 퇴사한 영업의 바이어를 다른 영업이 넘겨받으면, 그 바이어에서 나오는 거래는
 * **사내직원 기준 건당 5만원**(신규 개척이 아니므로). 영구 — 승계 이후 계속 적용.
 * 프리랜서(비율제)는 종전대로 유지한다.
 *
 * `inherited_from_salesman_id` 는 기록용(누구에게서 넘겨받았나) — 정산 계산엔 안 쓴다.
 * 퇴사자 본인이 삭제돼도 승계 사실은 남아야 하므로 nullOnDelete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buyers', function (Blueprint $table) {
            $table->boolean('is_inherited')->default(false)->after('salesman_id');
            $table->foreignId('inherited_from_salesman_id')->nullable()->after('is_inherited')
                ->constrained('salesmen')->nullOnDelete();
            $table->date('inherited_at')->nullable()->after('inherited_from_salesman_id');
        });
    }

    public function down(): void
    {
        Schema::table('buyers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('inherited_from_salesman_id');
            $table->dropColumn(['is_inherited', 'inherited_at']);
        });
    }
};
