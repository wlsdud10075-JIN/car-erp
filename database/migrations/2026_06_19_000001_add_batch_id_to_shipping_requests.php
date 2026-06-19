<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipping_requests', function (Blueprint $table) {
            $table->string('batch_id', 36)->nullable()->index()->after('id');
        });

        // 기존 행 백필 — 1요청(POST) 묶음 = 같은 (요청영업·바이어·컨사이니·방식·요청일(분)) 으로 추정.
        // batch_id NULL 이면 선적요청 화면이 다시 차량별로 흩어져 보이므로 합성키로 그룹핑해 uuid 부여.
        $groups = [];
        foreach (DB::table('shipping_requests')->whereNull('batch_id')->get() as $r) {
            $key = implode('|', [
                $r->requested_by_email,
                $r->buyer_id ?? '-',
                $r->consignee_id ?? '-',
                $r->shipping_method,
                $r->requested_at ? substr((string) $r->requested_at, 0, 16) : '-',
            ]);
            $groups[$key] ??= (string) Str::uuid();
            DB::table('shipping_requests')->where('id', $r->id)->update(['batch_id' => $groups[$key]]);
        }
    }

    public function down(): void
    {
        Schema::table('shipping_requests', function (Blueprint $table) {
            $table->dropIndex(['batch_id']);
            $table->dropColumn('batch_id');
        });
    }
};
