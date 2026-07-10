<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 판매계약서 전자서명 세션 (2026-07-10 풀회의 — ERP 직접호스팅 + Certificate of Completion).
     *
     * 설계 원칙 (docs/meetings/2026-07-10-sales-contract-e-signature.md):
     * - vehicles·progress_status·vehicle_photos 완전 분리. 서명 상태는 여기서만 추적(캐시 오염 금지).
     * - 발송 시점 스냅샷 동결: snapshot_path(원본 xlsx 바이트)·source_hash + snapshot_data(표시필드 JSON).
     *   sales_contract 는 매 GET 즉석 생성(영속 row 없음)이라, 발급 후 차량이 바뀌어도 "무엇에 서명했나"가
     *   재현되려면 발급 순간의 데이터를 동결해야 함. 서명 페이지 요약은 snapshot_data 에서만 렌더(Vehicle 재쿼리 금지).
     * - all-or-nothing: 1 row = 계약 전체(다중차량 vehicle_ids). 부분서명 없음. 차량 변경 = revoke + 재발급.
     * - status: pending(발급) → viewed(열람) → signed(서명완료, 불변) / revoked(무름). 하드삭제 가드는 모델.
     */
    public function up(): void
    {
        Schema::create('signed_contracts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('buyer_id')->nullable()->constrained('buyers')->nullOnDelete();
            $t->json('vehicle_ids');                 // 계약에 묶인 차량 id 배열 (all-or-nothing)
            $t->string('contract_no', 40);           // SC{ym}-{id} (발급 시점 고정)
            $t->string('currency', 3)->default('USD');

            // 발송 시점 동결 — 증거의 뿌리
            $t->string('snapshot_path');             // 원본 계약서 xlsx (발급 순간 렌더본)
            $t->string('source_hash', 64);           // snapshot xlsx 바이트 SHA-256
            $t->json('snapshot_data');               // 표시필드 동결(바이어명·차량별 plate/brand/model/vin/fob·푸터 합계)

            // 서명 세션
            $t->string('status', 12)->default('pending');   // pending/viewed/signed/revoked
            $t->string('sign_token', 64)->unique();          // 공개 URL 핸들(추측불가) — signed 미들웨어와 병행
            $t->timestamp('token_expires_at')->nullable();
            $t->string('recipient_email')->nullable();       // 발급 시 기본=바이어 이메일, 서명 시 바이어가 확정 입력

            // 서명본 + 서명자 캡처 (3전이 증거)
            $t->string('signed_pdf_path')->nullable();       // CoC PDF (서명본)
            $t->string('signed_hash', 64)->nullable();       // CoC PDF 바이트 SHA-256
            $t->string('signature_path')->nullable();        // 서명 이미지(canvas PNG)
            $t->string('signer_name')->nullable();
            $t->string('signer_ip', 45)->nullable();
            $t->text('signer_ua')->nullable();

            $t->timestamp('sent_at')->nullable();
            $t->timestamp('viewed_at')->nullable();
            $t->timestamp('signed_at')->nullable();
            $t->timestamp('mail_sent_at')->nullable();       // 서명본 증거메일 발송 성공 시각(실패=null, 재발송 대상)
            $t->timestamp('revoked_at')->nullable();

            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->index('status');
            $t->index('buyer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signed_contracts');
    }
};
