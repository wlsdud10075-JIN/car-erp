<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // RRN 암호화 준비: 컬럼 확장 + 점진 전환 표식 컬럼
        // - varchar(20) → varchar(500): Laravel AES-256-CBC + base64 + IV + MAC ≈ 200~250자. 여유 마진
        // - nice_reg_owner_rrn_encrypted_at: NULL=평문 / NOT NULL=암호. Specialist B의 idempotent 점진 전환 패턴
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('nice_reg_owner_rrn', 500)->nullable()->change();
            $table->timestamp('nice_reg_owner_rrn_encrypted_at')->nullable()->after('nice_reg_owner_rrn');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('nice_reg_owner_rrn_encrypted_at');
            $table->string('nice_reg_owner_rrn', 20)->nullable()->change();
        });
    }
};
