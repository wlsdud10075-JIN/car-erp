<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `warehouse_out_date` 인덱스 (board 인계 2026-08-09 요청 ④).
 *
 * 재고 3분류 중 **출고완료(`shipped_out`)만 영원히 단조증가**한다 — 나머지 둘은 출고되는 순간
 * 이 탭으로 빠져나가 유한하게 유지된다. 그런데 그 탭은 필터(`whereNotNull`)도 정렬(`orderByDesc`)도
 * 전부 이 컬럼에 걸리는데 인덱스가 없었다(실측: `progress_status_cache`·`salesman_id`·`stock_location` 은 있음).
 *
 * 지금은 데이터가 작아 티가 안 나지만(heymanerp 239대), 누적되는 유일한 축이라 여기부터 아프다.
 * 인덱스 추가는 비파괴 — MySQL 8 은 온라인 DDL 로 잠금 없이 붙는다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->index('warehouse_out_date');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropIndex(['warehouse_out_date']);
        });
    }
};
