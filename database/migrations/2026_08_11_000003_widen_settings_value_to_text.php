<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `settings.value` varchar(255) → TEXT (2026-08-11).
 *
 * 실측으로 두 곳이 이미 벽에 닿았다:
 *  ① 공휴일 자동 수집 — 한 해치 JSON 이 ~700자. 저장이 `SQLSTATE[22001] 1406 Data too long` 로 깨졌다.
 *  ② 알림톡 **수신 시각 규칙** — 기본 3행이 벌써 196자다. **행을 하나만 더 추가하면 저장이 깨진다.**
 *     ②는 오늘 넣은 편집기의 잠재 결함이었다(화면에서 [＋규칙 추가]를 두 번 누르면 재현).
 *
 * 설정값은 원래 "짧은 스칼라" 가정이었지만, JSON 을 담는 키가 늘면서 그 가정이 깨졌다.
 * TEXT 는 varchar 보다 허용 범위만 넓어지므로 기존 값·코드에 영향이 없다(읽기·쓰기 모두 문자열).
 *
 * ⚠️ 인덱스는 `key` 에만 있고 `value` 엔 없다 — TEXT 전환에 걸림돌 없음(실측).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->text('value')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            // ⚠️ 되돌리면 255자 초과 값이 잘린다. 공휴일 자동 수집분은 다시 받으면 되지만
            //    시각 규칙은 사람이 넣은 설정이라, 롤백 전에 백업할 것.
            $table->string('value', 255)->nullable()->change();
        });
    }
};
