<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 계좌별 통장 잔액 (jin 2026-10-14 «싼카는 계좌가 많아서 드롭박스로 계좌를 선택하고 잔액을 기입»).
 *
 * - cash_accounts: 회사가 쓰는 계좌 목록(은행·번호·통화 목록). 한 계좌가 여러 통화를 가질 수 있다(하나 외화 = USD+EUR).
 * - cash_account_balances: 계좌 × 날짜 × 통화 잔액. 날짜 합계(통화별)가 종전 cash_snapshots 의 balance_krw/usd/eur 로 들어간다.
 *   입력 안 한 계좌는 그 날짜 이전 최신값을 이어 쓴다(CashAccountBalanceService). 스냅샷·청산가치 공식은 그대로.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('bank', 40);
            $table->string('account_no', 60);
            $table->string('label', 60)->nullable();
            $table->json('currencies');            // ['KRW'] · ['USD','EUR'] …
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('cash_account_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_account_id')->constrained('cash_accounts')->cascadeOnDelete();
            $table->date('snapshot_date');
            $table->string('currency', 3);
            $table->decimal('balance', 18, 2)->default(0);
            $table->unsignedBigInteger('entered_by')->nullable();
            $table->timestamps();
            $table->unique(['cash_account_id', 'snapshot_date', 'currency'], 'cab_account_date_currency_unique');
            $table->index(['snapshot_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_account_balances');
        Schema::dropIfExists('cash_accounts');
    }
};
