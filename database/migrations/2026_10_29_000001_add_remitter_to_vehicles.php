<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 송금인(바이어 고객) Name·ID — 2026-11 주말 기획 ② (jin 2026-10-28 결정, `board-portal-api.md` §14).
 *
 * 바이어 밑의 실제 손님(송금증에 찍히는 사람)이 차마다 달라 **차량 단위**로 기록한다 — 바이어 `passport_id`·
 * 컨사이니 `id_value` 와 다른 사람일 수 있어 동기화하지 않는다. ERP 용도는 차량관리 검색 「송금인 이름」뿐이다.
 *
 * - `remitter_name` 평문(검색용, LIKE) · `remitter_id` 는 모델 `encrypted` cast(여권/ID 번호 — `purchase_seller_account` 와 같은 관행)
 *   라 암호문이 길어 text. 둘 다 nullable 추가만 — 3-DB 안전.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $t) {
            $t->string('remitter_name', 100)->nullable()->after('purchase_bank_memo');
            $t->text('remitter_id')->nullable()->after('remitter_name');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $t) {
            $t->dropColumn(['remitter_name', 'remitter_id']);
        });
    }
};
