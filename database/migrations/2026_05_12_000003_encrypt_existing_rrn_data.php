<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * 기존 평문 RRN을 일괄 암호화.
     * - encrypted_at IS NULL 필터로 idempotent (재실행 안전)
     * - 빈 문자열/NULL은 skip
     * - DB::table() 사용해서 Eloquent saving 이벤트·accessor·mutator 우회
     * - chunkById로 메모리 부담 최소화
     */
    public function up(): void
    {
        DB::table('vehicles')
            ->whereNotNull('nice_reg_owner_rrn')
            ->where('nice_reg_owner_rrn', '!=', '')
            ->whereNull('nice_reg_owner_rrn_encrypted_at')
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('vehicles')
                        ->where('id', $row->id)
                        ->update([
                            'nice_reg_owner_rrn' => Crypt::encryptString($row->nice_reg_owner_rrn),
                            'nice_reg_owner_rrn_encrypted_at' => now(),
                        ]);
                }
            });
    }

    /**
     * 롤백 — 암호화 RRN을 평문으로 복원.
     * APP_KEY 변경/분실 시 복호 실패하는 row는 로그 남기고 skip.
     */
    public function down(): void
    {
        DB::table('vehicles')
            ->whereNotNull('nice_reg_owner_rrn_encrypted_at')
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    try {
                        DB::table('vehicles')
                            ->where('id', $row->id)
                            ->update([
                                'nice_reg_owner_rrn' => Crypt::decryptString($row->nice_reg_owner_rrn),
                                'nice_reg_owner_rrn_encrypted_at' => null,
                            ]);
                    } catch (Throwable $e) {
                        Log::warning('RRN decryption failed during rollback', [
                            'vehicle_id' => $row->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });
    }
};
