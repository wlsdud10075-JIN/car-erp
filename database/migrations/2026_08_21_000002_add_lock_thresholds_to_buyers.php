<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 바이어별 락 필요입금률 (jin 2026-08-21) — 전역 설정보다 **먼저** 적용되는 바이어 전용 기준.
 *
 * 우선순위: ①차량별 승인 우회(unpaid_export_overrides) → ②이 컬럼 → ③전역(lock_threshold_*_{회사}).
 *
 * 🚨 **NULL 만 "미설정"이다. 0 은 유효값**(= 필요입금 0% = 락 없음)이다.
 *    무담보 한도(`unsecured_limit_krw`)는 NULL·0 둘 다 미설정이라 규칙이 다르다 — 헷갈리지 말 것.
 *    0 을 미설정으로 처리하면 "락을 일부러 푼 바이어"가 조용히 전역값으로 돌아간다.
 *
 * ⚠️ B/L 발행(bl_issue)은 화물인도권이라 **100% 고정**이고, 매입지급(purchase_payment)은
 *    이번 범위 밖이다. 그래서 컬럼도 2개뿐이다 — 락별 화이트리스트는
 *    `LockThresholdResolver::PER_BUYER_LOCKS` 가 단일 출처.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buyers', function (Blueprint $table) {
            $table->unsignedTinyInteger('lock_shipping_entry_pct')->nullable()->after('unsecured_limit_krw');
            $table->unsignedTinyInteger('lock_purchase_registration_pct')->nullable()->after('lock_shipping_entry_pct');
        });
    }

    public function down(): void
    {
        Schema::table('buyers', function (Blueprint $table) {
            $table->dropColumn(['lock_shipping_entry_pct', 'lock_purchase_registration_pct']);
        });
    }
};
