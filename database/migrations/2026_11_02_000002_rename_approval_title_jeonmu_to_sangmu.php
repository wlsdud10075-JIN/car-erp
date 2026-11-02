<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 결재 직급 「전무」 → 「상무」 (jin 2026-11-02 «전무라는 이름이 상무로 바뀌어야 해 — 결재트리도, 사용자관리에도 전부»).
 *
 * 직급은 라벨이 아니라 **저장값 그대로 화면에 찍힌다**(users.approval_title · settlement_payout_batch_steps.title) —
 * 상수만 바꾸면 기존 사람·기존 결재선이 「전무」로 남고, 검증(in:APPROVAL_TITLES)에서 그 사람을 저장할 수도 없게 된다.
 * 그래서 값 자체를 옮긴다. 같은 자리의 이름이 바뀐 것이라 지난 결재선 기록도 상무로 읽는 게 맞다(jin «전부»).
 * 감사로그의 옛 문자열은 기록이므로 건드리지 않는다.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->where('approval_title', '전무')->update(['approval_title' => '상무']);
        DB::table('settlement_payout_batch_steps')->where('title', '전무')->update(['title' => '상무']);
    }

    public function down(): void
    {
        DB::table('users')->where('approval_title', '상무')->update(['approval_title' => '전무']);
        DB::table('settlement_payout_batch_steps')->where('title', '상무')->update(['title' => '전무']);
    }
};
