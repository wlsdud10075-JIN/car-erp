<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🔐 board 발 매입 락 해제 요청 (jin 2026-11-19) — 요청자가 ERP 계정 없는 영업일 수 있다.
 *
 * board 요청은 영업(Salesman) 명의로 오는데, 영업은 ERP 에 로그인하지 않아 계정이 없는 회사가 있다
 * (11-19 실측: karabaerp 활성 영업 15명 중 12명). `requester_id` 를 비울 수 있게만 한다 — 외래키·기존 값은 그대로.
 * 누가 요청했는지는 payload(salesman_name · requested_by_name)에 남는다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('requester_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // 되돌리지 않는다 — 비어 있는 행이 생긴 뒤 NOT NULL 로 돌리면 실패한다.
    }
};
