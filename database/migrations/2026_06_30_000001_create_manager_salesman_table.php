<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 관리↔영업 다대다 배정 (2026-06-30 jin) — 영업 1명을 [관리] 여러 명이 함께 담당 가능.
 *
 * 기존: users.manager_user_id 단일 FK (영업 1명 = 관리 1명).
 * 변경: manager_salesman pivot (관리 user ↔ 영업 user, N:N). 스코프 단일 출처
 *   User::getSubordinateSalesmanIds() 가 이 pivot 을 사용 → 차량/buyers/재고/알람/export 전 영역 자동 적용.
 *   기존 manager_user_id 값은 pivot 으로 이관(데이터 보존). 컬럼 자체는 유지(레거시·primary).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manager_salesman', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manager_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('salesman_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['manager_user_id', 'salesman_user_id']);
        });

        // 기존 단일 배정(users.manager_user_id) → pivot 이관.
        if (Schema::hasColumn('users', 'manager_user_id')) {
            $now = now();
            DB::table('users')
                ->whereNotNull('manager_user_id')
                ->orderBy('id')
                ->chunk(200, function ($rows) use ($now) {
                    $insert = [];
                    foreach ($rows as $r) {
                        $insert[] = [
                            'manager_user_id' => $r->manager_user_id,
                            'salesman_user_id' => $r->id,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                    if ($insert) {
                        DB::table('manager_salesman')->insertOrIgnore($insert);
                    }
                });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('manager_salesman');
    }
};
