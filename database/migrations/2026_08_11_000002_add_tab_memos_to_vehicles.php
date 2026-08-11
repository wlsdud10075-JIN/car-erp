<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 차량 편집 탭별 메모 5칸 (jin 2026-08-11).
 *
 * 종전엔 메모가 `vehicles.memo` 하나뿐이었고, 그 입력칸이 **탭 컨테이너 밖**에 있어
 * 8개 탭 어디를 눌러도 같은 박스가 보였다. 코드상 공유 버그가 아니라 애초에 한 칸이었는데,
 * 화면엔 그냥 「메모」라고만 적혀 있어 "탭마다 따로 쓰는 칸"으로 읽혔다(jin 제보).
 *
 * ⚠️ **`memo`(공통)는 그대로 둔다.** 운영 데이터가 들어 있고, "차량 전체에 대한 한마디"는
 *    여전히 쓸 자리가 있다. 지우면 기존 메모가 통째로 사라진다.
 *
 * 대상 5탭 = 매입·판매·수출통관·선적·B/L (jin 지정). 기본정보·DHL·서류는 제외 —
 * 적을 일이 없다고 판단. 나중에 늘리려면 컬럼 + 화면 한 곳씩만 추가하면 된다.
 */
return new class extends Migration
{
    /** 탭 key => 컬럼. 화면·모델이 같은 목록을 쓰도록 Vehicle::TAB_MEMOS 와 짝을 맞춘다. */
    private const COLUMNS = [
        'memo_purchase',
        'memo_sale',
        'memo_clearance',
        'memo_shipping',
        'memo_bl',
    ];

    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $after = 'memo';
            foreach (self::COLUMNS as $col) {
                $table->text($col)->nullable()->after($after);
                $after = $col;
            }
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn(self::COLUMNS);
        });
    }
};
