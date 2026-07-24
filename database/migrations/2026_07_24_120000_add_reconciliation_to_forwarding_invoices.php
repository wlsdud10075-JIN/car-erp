<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 포워딩 운임 인보이스 — 차액 정산 3필드 (jin 2026-07-24).
 *
 * 포워딩사가 USD/EUR/KRW 중 한 통화로 청구 → 수기 환율로 KRW 환산 →
 * 실제 송금한 KRW 기입 → 차액(우수리) 버림 처리로 0원 맞춤.
 *
 * ⚠️ 격리 유지: 이 값들은 forwarding_invoices 에만 얹으며 정산·미수 캐시·Vehicle computed 어디에도 연결 안 함
 *    (transport_fee 는 매출측, CLAUDE.md). paid_at 이 여전히 지급여부 단일 출처.
 * ⚠️ 3-DB(SQLite/MariaDB/MySQL8) 안전: 전부 nullable/default, CHECK·enum 없음, 기존 데이터 무영향.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forwarding_invoices', function (Blueprint $table) {
            $table->decimal('manual_rate', 15, 4)->nullable()->after('amount');       // 수기 환율(외화→KRW). KRW 청구면 무의미
            $table->decimal('actual_paid_krw', 15, 2)->nullable()->after('manual_rate'); // 실제 포워딩사에 송금한 KRW
            $table->decimal('write_off_krw', 15, 2)->default(0)->after('actual_paid_krw'); // 버림 처리한 우수리(±)
        });
    }

    public function down(): void
    {
        Schema::table('forwarding_invoices', function (Blueprint $table) {
            $table->dropColumn(['manual_rate', 'actual_paid_krw', 'write_off_krw']);
        });
    }
};
