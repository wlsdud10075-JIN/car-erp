<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🏦 은행 서류 (jin 2026-11-24) — 해외송금 전신문 1장 + 은행용 Proforma Invoice + 신한 담당자 메일 기록.
 * 송금 한 건이 여러 대 값이면 같은 기록을 고른 차 모두에 잇는다(피벗). 서류탭 「은행」 영역이 차마다 이걸 보여 준다.
 * 판매탭 입금 줄의 전신문 칸과는 별개다(입금 줄은 은행 확인 뒤에 생긴다).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_remittances', function (Blueprint $table) {
            $table->id();
            $table->string('remittance_path');                  // 전신문 원본(TIF·PDF) — vehicle_docs_disk
            $table->string('remittance_name', 200);             // 올린 파일 이름
            $table->string('remitter_name', 100)->nullable();   // 확정한 송금인 이름(기본정보 탭 송금인 칸에도 덮어씀)
            $table->string('ocr_name', 100)->nullable();        // GPU PC 가 읽은 값(사람이 고쳤는지 대조용)
            $table->string('ocr_status', 12)->nullable();       // ok · unverified · mismatch · none · offline · busy · text
            $table->string('invoice_path')->nullable();         // 은행용 Proforma(PDF, 실패 시 xlsx)
            $table->string('invoice_name', 200)->nullable();
            $table->string('mailed_to', 150)->nullable();
            $table->dateTime('mailed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('bank_remittance_vehicle', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_remittance_id')->constrained('bank_remittances')->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->unique(['bank_remittance_id', 'vehicle_id']);
            $table->index('vehicle_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_remittance_vehicle');
        Schema::dropIfExists('bank_remittances');
    }
};
