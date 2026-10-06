<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 💴 **2차 가능** — 지급(1차) 뒤에 그 차량의 비용 칸(탁송·면허·말소… 10개)이 기입된 시각 (jin 2026-10-06).
 *
 * jin: *「2차 마감을 일괄 업로드로 탁송비·면허비 기타등등 차량관리에서 업로드해서 하는데, 그걸 솔팅할 수 있는
 *      방안을 마련하고 그것만 2차 마감을 하고, 안 된 애들은 별도로 찾아보거나 다른 방안을 마련해야 한다.
 *      꼭 9월 10일에 1차 마감이 되고 2차 마감이 10월에 되리란 보장이 없다.」*
 *
 * - NULL = 2차 대기 중 **비용 대기**(지급 뒤 비용 칸 변경 기록 없음) / 값 = **2차 가능**.
 * - 채우는 곳 = `Vehicle::updated` 훅(비용 칸 실변경, 명세서 기입 일괄·수동 공통) → `Settlement::markSecondaryReadyForVehicle`.
 *   `paid` 전환 때 NULL 로 리셋(1차 전에 넣은 비용은 2차 근거가 아니다).
 * - 기존 2차 대기 행은 배포 뒤 `settlements:backfill-secondary-ready --apply`(감사로그에서 소급).
 * - 판정 컬럼이라 SQL 필터·일괄 마감 대상에 쓰므로 인덱스.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settlements', function (Blueprint $table) {
            $table->timestamp('secondary_ready_at')->nullable()->after('secondary_closed_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('settlements', function (Blueprint $table) {
            $table->dropIndex(['secondary_ready_at']);
            $table->dropColumn('secondary_ready_at');
        });
    }
};
