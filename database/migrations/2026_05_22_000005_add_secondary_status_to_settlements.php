<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 회의확장씬 #8 (2026-05-22) — 2차 정산 status 컬럼 신설.
 *
 * 사용자 명세 (2026-05-21):
 *   "엑셀에서의 기타비용(말소, 면허, 탁송, 보험, 이전비, 기타1,2) 이런 비용들이
 *    한달 뒤에 측정되어 오기때문에 2차 정산이 필요함.
 *    1차정산 / 2차정산으로 나뉘어서 수정할 수 있게하고, 2차정산 후 최종마무리"
 *
 * 설계 결정 — 별도 컬럼 (settlement_status enum 마이그 부담 회피):
 *   - settlement_status enum 그대로 유지 (pending/calculating/confirmed/paid)
 *   - secondary_status string 신설 — NULL (1차 진행 중) / pending (2차 대기) / closed (최종)
 *   - 자동 전환: paid 시점에 saving 훅에서 secondary_status='pending' 자동 set
 *   - 수동 전환: [관리]/[재무] 가 settlements/index 에서 '2차 정산 완료' 액션 → 'closed'
 *
 * 상태 흐름:
 *   pending → calculating → confirmed → paid → secondary_status='pending' (자동, 한 달 대기)
 *   → secondary_status='closed' (수동, [관리]/[재무] 가 기타비용 수정 후 마무리)
 *
 * 회계 잠금:
 *   - secondary_status='pending' 동안: [관리]/[재무] 차량 기타비용 9개 수정 가능 (잠금 해제)
 *   - secondary_status='closed' 후: 회계 잠금 (수정 불가, ledger lock 가드)
 *
 * enum 아닌 string 사용 사유:
 *   - SQLite 호환 (운영 MariaDB + 테스트 SQLite)
 *   - 추후 secondary status 종류 추가 시 마이그 부담 회피
 *   - application validation 으로 enum 효과 (Settlement::SECONDARY_STATUSES 상수)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settlements', function (Blueprint $table) {
            $table->string('secondary_status', 20)
                ->nullable()
                ->after('settlement_status');
            // closed 전환 시각 — 회계 감사 추적용
            $table->timestamp('secondary_closed_at')
                ->nullable()
                ->after('secondary_status');
        });
    }

    public function down(): void
    {
        Schema::table('settlements', function (Blueprint $table) {
            $table->dropColumn(['secondary_status', 'secondary_closed_at']);
        });
    }
};
