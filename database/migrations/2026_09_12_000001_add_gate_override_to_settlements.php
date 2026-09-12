<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 신규 정산 게이트 예외 — 정산 행에 붙는 사유 기반 예외 (jin 2026-09-12).
 * 정본 = `docs/design/settlement-gate-exception.md`.
 *
 * 🔑 **왜 정산 행에 붙나** — 막히는 것이 「이 차의 정산」이지 「이 차」가 아니다. 한 차에 정산이
 *    여러 개일 수 있고(담당자 승계·재생성), 예외는 그중 특정 정산에만 걸린다.
 *
 * 🚫 `unpaid_export_overrides` 재사용 부적합 — `vehicle_id` 하드 FK 에 stage 축이 다르고,
 *    값을 늘리려면 enum ALTER 가 필요하다(SKILLS §8 #36). 대신 **설계를 본떴다**:
 *    사유 + 승인자 + 시각 + 금액 스냅샷.
 *
 * 🚫 **스냅샷으로 막지 않는다**(jin 2026-09-12 «미수가 얼마든 예외처리»). 화면에
 *    「예외 당시 1,312 EUR → 현재 520만원」을 보여주는 용도다 — 판정에 쓰면 임계가 되고,
 *    임계는 곧 뚫린다(§8 #83).
 *
 * ⚠️ 전부 nullable 이라 기존 행 무영향. 예외 여부 판정 = `gate_override_at` 유무 하나
 *    (상태 컬럼을 따로 안 둔다 — 어긋날 여지를 만들지 않는다, §8 #80).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settlements', function (Blueprint $table) {
            $table->text('gate_override_reason')->nullable()->after('note');
            $table->foreignId('gate_override_by')->nullable()->after('gate_override_reason')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('gate_override_at')->nullable()->after('gate_override_by');
            // 그때 무엇을 넘겼나 — 나중에 사유를 읽는 사람이 「무엇이 막혀 있었는지」를 알아야 한다.
            $table->json('gate_override_blockers')->nullable()->after('gate_override_at');
            // 그때의 미수액(차량 통화 기준). 보여주기 전용 — 판정에 쓰지 않는다.
            $table->decimal('gate_override_unpaid_amount', 18, 2)->nullable()->after('gate_override_blockers');
        });
    }

    public function down(): void
    {
        Schema::table('settlements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('gate_override_by');
            $table->dropColumn([
                'gate_override_reason', 'gate_override_at',
                'gate_override_blockers', 'gate_override_unpaid_amount',
            ]);
        });
    }
};
