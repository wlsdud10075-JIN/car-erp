<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 카톡자동화 「내 카톡 할 일」 (2026-11-10, docs/design/kakao-automation.md §10).
 *
 * 방(kakao_rooms) 은 회사 안에서 카톡 방 제목으로 식별하고, 메시지는 방 단위로 1회 분류한다(kakao_messages).
 * 방향(내 일/기다림/동료/무관)은 저장하지 않고 보는 사람마다 계산 — 사용자가 건드린 상태만 kakao_items 에 남긴다.
 * kakao_reads = 읽기 회차(비용·토큰·분류 당시 방 구성 스냅샷). kakao_collector_tokens = PC 수집기 토큰(기기별 폐기).
 * ⚠️ 상태값은 enum 이 아니라 string — §8 #4·#36(운영 MySQL 에서만 죽는 enum 추가 방지).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kakao_rooms', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();          // 카톡 방 제목 (회사 DB 안에서 유일)
            $table->json('sides')->nullable();              // 편 구성 {"재무":[이름…],"수출팀":[…],"영업":[…]} + 역할 설명
            $table->timestamp('last_read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('kakao_room_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('kakao_rooms')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('my_name', 80);                  // 그 방에서 「나」의 카톡 표시명
            $table->timestamps();
            $table->unique(['room_id', 'user_id']);
        });

        Schema::create('kakao_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('kakao_rooms')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->json('sides_snapshot')->nullable();     // 분류 당시 방 구성(나중에 구성을 고쳐도 그때 판정의 근거가 남는다)
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('total')->default(0);     // 수집기가 보낸 메시지 수
            $table->unsignedInteger('new_count')->default(0); // 그중 처음 보는 것(분류 대상)
            $table->string('model', 40)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost_krw', 10, 2)->default(0);
            $table->string('status', 16)->default('pending');  // pending | done | failed
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'started_at']);
        });

        Schema::create('kakao_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('kakao_rooms')->cascadeOnDelete();
            $table->foreignId('read_id')->nullable()->constrained('kakao_reads')->nullOnDelete();
            $table->string('msg_key', 40);                  // sha1(날짜|시각|보낸이|본문) — 다시 읽어도 같은 메시지는 한 번만
            $table->dateTime('sent_at');                    // 카톡은 분 단위
            $table->string('sender', 80);
            $table->text('body');
            $table->string('type', 10)->nullable();         // 요청|질문|대답|확인|정보|잡담|첨부 (null = 분류 실패 → 「확인 필요」)
            $table->string('to_side', 20)->nullable();      // 방 구성의 편 이름 또는 없음
            $table->string('mention', 200)->nullable();     // @로 지목된 이름들(쉼표)
            $table->string('ref', 40)->nullable();          // 컨테이너·B/L·부킹 번호
            $table->string('vehicle_no', 20)->nullable();   // 본문의 차량번호
            $table->string('vin_tail', 8)->nullable();      // 차대번호 끝 6자리
            $table->string('amount', 40)->nullable();
            $table->foreignId('resolves_id')->nullable()->constrained('kakao_messages')->nullOnDelete();  // 이 메시지가 답한 앞 요청·질문
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();         // 차량번호·차대번호로 맞은 ERP 차량
            $table->json('ai')->nullable();                 // 모델 원 판정(감사·증류 라벨)
            $table->timestamps();
            $table->unique(['room_id', 'msg_key']);
            $table->index(['room_id', 'sent_at']);
            $table->index(['vehicle_id']);
        });

        Schema::create('kakao_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('kakao_messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('state', 16);                    // todo | waiting | snoozed | done | ignored | not_mine
            $table->string('type_override', 10)->nullable(); // 사용자가 고친 유형(= 증류 라벨)
            $table->dateTime('snooze_until')->nullable();
            $table->string('snooze_reason', 120)->nullable();
            $table->dateTime('done_at')->nullable();
            $table->timestamps();
            $table->unique(['message_id', 'user_id']);
            $table->index(['user_id', 'state']);
        });

        Schema::create('kakao_collector_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();     // sha256(토큰). 토큰 원문은 발급 때 1회만 보여준다
            $table->string('device_name', 80)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kakao_collector_tokens');
        Schema::dropIfExists('kakao_items');
        Schema::dropIfExists('kakao_messages');
        Schema::dropIfExists('kakao_reads');
        Schema::dropIfExists('kakao_room_users');
        Schema::dropIfExists('kakao_rooms');
    }
};
