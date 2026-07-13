<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 알림톡 전송결과 폴링(BizM /v2/sender/report) 저장용 컬럼 추가 (2026-07-13).
 *   발송 시점엔 "BizM 접수 성공(status=sent)"까지만 알 수 있고, 실제 카톡 도달 여부는 모른다.
 *   익일 배치(alimtalk:poll-report)가 msgid 로 결과를 조회해 report_status 에 기록한다.
 *
 * 3-DB 안전(project_db_tier_mismatch): 전부 nullable string/text/timestamp + FK/CHECK 없음
 *   (acknowledged_by 는 SQLite ALTER-add-FK 미지원 회피 위해 FK 제약 없이 저장).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alimtalk_logs', function (Blueprint $table) {
            // 도달 결과: delivered(도달) | undelivered(미도달). null = 미확인(조회 전 또는 report 미준비 → 재조회).
            $table->string('report_status', 20)->nullable()->after('status');
            $table->timestamp('report_checked_at')->nullable()->after('report_status');   // 마지막 폴링 시각
            $table->text('report_raw')->nullable()->after('report_checked_at');            // 원본 응답 JSON(진단용)
            // 미도달 확인(acknowledge) — 확인 전까지 사이드바 배지 유지(24h 창 아님, 완성형 b).
            $table->timestamp('acknowledged_at')->nullable()->after('report_raw');
            $table->unsignedBigInteger('acknowledged_by')->nullable()->after('acknowledged_at');

            $table->index(['status', 'report_status']);   // 배지/필터 쿼리
        });
    }

    public function down(): void
    {
        Schema::table('alimtalk_logs', function (Blueprint $table) {
            $table->dropIndex(['status', 'report_status']);
            $table->dropColumn([
                'report_status', 'report_checked_at', 'report_raw',
                'acknowledged_at', 'acknowledged_by',
            ]);
        });
    }
};
