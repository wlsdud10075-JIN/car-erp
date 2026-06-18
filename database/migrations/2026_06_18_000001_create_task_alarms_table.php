<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 업무 알람 (task_alarms) — ETA 영구 알람의 영구 저장.
 *
 * Laravel 기본 notifications 테이블과 충돌 회피를 위해 task_alarms 명명.
 * v1 = type 'eta_clearance' 1종. 스키마는 확장형(type/message_meta json)이라
 * 후속(미수·매입미지급) 흡수 시 마이그 재작업 불필요.
 *
 * 회의: docs/meetings/2026-06-18-notification-alarm-subsystem.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_alarms', function (Blueprint $table) {
            $table->id();
            $table->string('type', 60)->default('eta_clearance');     // 확장 키 (eta_clearance / sale_unpaid …)
            $table->foreignId('vehicle_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('target_role', 30);                         // 영업/수출통관/재무/관리
            $table->date('due_date')->nullable();                      // ETA 기준 임박일 (정렬·표시)
            $table->json('message_meta')->nullable();                  // 표시 전용 whitelist — WHERE 조건 금지
            $table->timestamp('confirmed_at')->nullable();             // [확인] = 봤음
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();              // 일 끝남(서류 업로드/거래완료) = 자동 종료
            $table->string('resolved_reason', 60)->nullable();         // document_uploaded / deal_closed / manual
            $table->timestamps();

            // 멱등 조회: 같은 (type, vehicle) open row 존재 확인
            $table->index(['type', 'vehicle_id', 'resolved_at'], 'idx_alarm_open');
            // 벨 카운트 / 알림함 목록: role별 미해소 + 임박순
            $table->index(['target_role', 'resolved_at', 'due_date'], 'idx_alarm_role_open');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_alarms');
    }
};
