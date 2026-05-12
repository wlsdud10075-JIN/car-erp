<?php

namespace Tests\Unit;

use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VehicleRrnEncryptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_rrn_is_encrypted_in_database(): void
    {
        $plain = '900101-1234567';
        $vehicle = Vehicle::create([
            'vehicle_number' => '12가1234',
            'sales_channel' => 'export',
            'nice_reg_owner_rrn' => $plain,
        ]);

        $raw = DB::table('vehicles')->where('id', $vehicle->id)->value('nice_reg_owner_rrn');
        $this->assertNotEquals($plain, $raw, 'DB에는 평문이 저장되면 안 됨');
        $this->assertGreaterThan(50, strlen($raw), '암호문은 base64 인코딩으로 평문보다 길어야 함');

        $encryptedAt = DB::table('vehicles')->where('id', $vehicle->id)->value('nice_reg_owner_rrn_encrypted_at');
        $this->assertNotNull($encryptedAt, '암호화 표식 타임스탬프가 기록되어야 함');
    }

    public function test_rrn_is_decrypted_when_accessed_via_model(): void
    {
        $plain = '900101-1234567';
        $vehicle = Vehicle::create([
            'vehicle_number' => '12가5678',
            'sales_channel' => 'export',
            'nice_reg_owner_rrn' => $plain,
        ]);

        $fresh = Vehicle::find($vehicle->id);
        $this->assertEquals($plain, $fresh->nice_reg_owner_rrn);
    }

    public function test_null_rrn_is_safe(): void
    {
        $vehicle = Vehicle::create([
            'vehicle_number' => '12가9999',
            'sales_channel' => 'export',
            'nice_reg_owner_rrn' => null,
        ]);

        $fresh = Vehicle::find($vehicle->id);
        $this->assertNull($fresh->nice_reg_owner_rrn);
        $this->assertNull(DB::table('vehicles')->where('id', $vehicle->id)->value('nice_reg_owner_rrn_encrypted_at'));
    }

    public function test_empty_string_rrn_does_not_trigger_encryption(): void
    {
        $vehicle = Vehicle::create([
            'vehicle_number' => '12가8888',
            'sales_channel' => 'export',
            'nice_reg_owner_rrn' => '',
        ]);

        $this->assertNull(DB::table('vehicles')->where('id', $vehicle->id)->value('nice_reg_owner_rrn_encrypted_at'));
    }

    public function test_legacy_plaintext_row_is_readable(): void
    {
        // 마이그레이션 전 상태 시뮬레이션 — DB에 평문 직접 삽입, encrypted_at = NULL
        $id = DB::table('vehicles')->insertGetId([
            'vehicle_number' => '12가7777',
            'sales_channel' => 'export',
            'nice_reg_owner_rrn' => '900101-1234567',
            'nice_reg_owner_rrn_encrypted_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $fresh = Vehicle::find($id);
        $this->assertEquals('900101-1234567', $fresh->nice_reg_owner_rrn, '평문 row(encrypted_at NULL)는 그대로 읽혀야 함');
    }
}
