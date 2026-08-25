<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 포워딩사별 화물추적 URL 템플릿 (2026-08-25, jin).
 *
 * 회사마다 추적 사이트가 다르고 주소도 바뀐다 — 코드에 박으면 바뀔 때마다 배포해야 한다.
 * 값 예: https://www.cigbooking.com/track/{VIN}
 *
 * ⚠️ 비어 있으면 그 포워딩사 차량엔 버튼이 아예 안 뜬다(조용히 없는 게 맞다 — 링크가 없으니까).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forwarding_companies', function (Blueprint $table) {
            $table->string('tracking_url_template', 255)->nullable()->after('address');
        });
    }

    public function down(): void
    {
        Schema::table('forwarding_companies', function (Blueprint $table) {
            $table->dropColumn('tracking_url_template');
        });
    }
};
