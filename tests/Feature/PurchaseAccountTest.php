<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 큐 20-A — 매입처 계좌 4컬럼 (purchase_seller_*).
 * - account 자동 암호화 (Laravel Crypt::encrypted cast)
 * - bank / holder / memo 평문 저장
 * - AuditLog::MASKED_COLUMNS 에 account 등록 (감사로그 평문 노출 차단)
 */
class PurchaseAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_seller_account_is_encrypted_in_database(): void
    {
        $vehicle = Vehicle::create([
            'vehicle_number' => '20A가0001',
            'sales_channel' => 'export',
            'purchase_seller_bank' => '국민은행',
            'purchase_seller_account' => '123-456-789012',
            'purchase_seller_holder' => '홍길동',
            'purchase_bank_memo' => '월말 송금',
        ]);

        // 평문 컬럼은 그대로
        $this->assertEquals('국민은행', $vehicle->purchase_seller_bank);
        $this->assertEquals('홍길동', $vehicle->purchase_seller_holder);
        $this->assertEquals('월말 송금', $vehicle->purchase_bank_memo);

        // accessor 통해 읽으면 평문
        $this->assertEquals('123-456-789012', $vehicle->purchase_seller_account);

        // DB raw 값은 암호화되어 있어야 함 (평문 검색 불가)
        $raw = DB::table('vehicles')->where('id', $vehicle->id)->value('purchase_seller_account');
        $this->assertNotEquals('123-456-789012', $raw);
        $this->assertNotEmpty($raw);

        // Crypt::decryptString 으로 복호화 가능
        $this->assertEquals('123-456-789012', Crypt::decryptString($raw));
    }

    public function test_purchase_seller_account_null_when_not_set(): void
    {
        $vehicle = Vehicle::create([
            'vehicle_number' => '20A가0002',
            'sales_channel' => 'export',
        ]);

        $this->assertNull($vehicle->fresh()->purchase_seller_account);
        $this->assertNull($vehicle->fresh()->purchase_seller_bank);
    }

    public function test_audit_log_masks_purchase_seller_account(): void
    {
        $this->assertArrayHasKey('purchase_seller_account', AuditLog::MASKED_COLUMNS);
        $this->assertStringContainsString('ENCRYPTED ACCOUNT', AuditLog::MASKED_COLUMNS['purchase_seller_account']);
    }

    public function test_audit_log_record_event_masks_account_value(): void
    {
        $vehicle = Vehicle::create([
            'vehicle_number' => '20A가0003',
            'sales_channel' => 'export',
            'purchase_seller_account' => '999-888-777666',
        ]);

        $log = AuditLog::recordChange($vehicle, 'purchase_seller_account', null, '999-888-777666');

        $this->assertNotNull($log);
        $this->assertEquals('[ENCRYPTED ACCOUNT — value not logged]', $log->new_value);
        $this->assertNull($log->old_value);

        // DB에 평문 계좌번호가 절대 저장되지 않음
        $raw = DB::table('audit_logs')->where('id', $log->id)->value('new_value');
        $this->assertStringNotContainsString('999-888-777666', $raw);
    }

    public function test_account_change_does_not_break_other_fields(): void
    {
        $vehicle = Vehicle::create([
            'vehicle_number' => '20A가0004',
            'sales_channel' => 'export',
            'purchase_seller_bank' => '신한은행',
            'purchase_seller_account' => '111-222-333',
            'purchase_seller_holder' => '김매입',
        ]);

        // 계좌번호만 변경
        $vehicle->update(['purchase_seller_account' => '444-555-666']);
        $vehicle->refresh();

        $this->assertEquals('신한은행', $vehicle->purchase_seller_bank);
        $this->assertEquals('444-555-666', $vehicle->purchase_seller_account);
        $this->assertEquals('김매입', $vehicle->purchase_seller_holder);
    }
}
