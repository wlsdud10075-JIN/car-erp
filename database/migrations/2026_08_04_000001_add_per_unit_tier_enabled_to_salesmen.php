<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 사내직원 차등정산(tier) 담당자별 on/off (jin 2026-08-04).
 *
 * 종전엔 per_unit(사내직원) 전원이 tier(총마진 100만↑ 20만 / 매입합계 1억↑ 25%)를 탔는데,
 * 실제로는 **특정 인원 한정** 규칙이었다. 끈 사람은 10만원 고정(손해차량은 종전대로 0원).
 *
 * ⚠️ 기본값 false 로만 두면 배포 직후 tier 수혜자가 조용히 10만원으로 떨어진다.
 *    → 과거 tier 상향을 실제로 받은 담당자를 자동 ON 해서 **현행 동작을 보존**한다.
 *    판정은 동결된 컬럼값(paid 시 materialize)만 본다 — accessor 재계산은 모델 부팅·관계 로드가
 *    필요해 마이그레이션에선 부적절하다. 미동결(NULL) 행은 애초에 상향 이력이 아니므로 대상 아님.
 *
 *    실측(2026-08-04): heymanerp=무사백만 해당(20만 35건+25% 4건) / ssancarerp=전부 NULL 이라 0명 /
 *    karabaerp=이익율 정산이라 무관(동결값도 10만뿐).
 */
return new class extends Migration
{
    /** 2026-08 기준 사내직원 기본 건당액. Setting 이 바뀌어도 이 마이그레이션의 판정 기준은 고정. */
    private const BASE_AMOUNT_AT_MIGRATION = 100_000;

    public function up(): void
    {
        Schema::table('salesmen', function (Blueprint $table) {
            $table->boolean('per_unit_tier_enabled')->default(false)->after('type');
        });

        // karaba 는 이익율 정산(Setting::isKaraba())이라 tier 자체를 안 쓴다. 그런데 Settlement::saving 이
        // effective_per_unit_amount 를 무조건 materialize 하는 탓에 20만 동결값이 남아 있어(실측 홍승환 1명),
        // 그대로 두면 쓰이지도 않는 플래그가 켜져 다음 사람을 헷갈리게 한다 → 자동 ON 자체를 건너뛴다.
        $profile = DB::table('settings')->where('key', 'company_template_set')->value('value')
            ?: config('company.template_set', 'system');
        if ($profile === 'karaba') {
            return;
        }

        $ids = DB::table('settlements')
            ->where('settlement_type', 'per_unit')
            ->whereNotNull('salesman_id')
            ->whereNotNull('per_unit_amount')
            ->where('per_unit_amount', '>', self::BASE_AMOUNT_AT_MIGRATION)
            ->distinct()
            ->pluck('salesman_id');

        if ($ids->isNotEmpty()) {
            DB::table('salesmen')->whereIn('id', $ids)->update(['per_unit_tier_enabled' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('salesmen', function (Blueprint $table) {
            $table->dropColumn('per_unit_tier_enabled');
        });
    }
};
