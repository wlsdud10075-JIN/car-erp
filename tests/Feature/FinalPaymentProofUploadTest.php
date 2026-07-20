<?php

namespace Tests\Feature;

use App\Models\FinalPayment;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 전시문(전신환 송금증) 잔금별 업로드 (jin 2026-07-20, item2).
 * 판매 잔금 금액별로 증빙 파일을 첨부 — proof_path(회계 잠금 대상 아님).
 */
class FinalPaymentProofUploadTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['permission' => 'admin', 'role' => '관리', 'email_verified_at' => now()]);
    }

    public function test_upload_proof_to_final_payment(): void
    {
        Storage::fake(config('filesystems.vehicle_docs_disk'));
        $v = Vehicle::create(['vehicle_number' => 'PRF1', 'sales_channel' => 'export']);
        $fp = FinalPayment::create([
            'vehicle_id' => $v->id, 'amount' => 1_000_000, 'type' => 'balance', 'payment_date' => '2026-05-05',
        ]);
        $this->actingAs($this->admin());
        $pdf = UploadedFile::fake()->create('wire.pdf', 20, 'application/pdf');

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('finalPaymentProofFiles.0', $pdf)
            ->call('save')
            ->assertHasNoErrors();

        $fp->refresh();
        $this->assertNotNull($fp->proof_path, '전시문 경로 저장됨');
        Storage::disk(config('filesystems.vehicle_docs_disk'))->assertExists($fp->proof_path);
    }

    public function test_remove_proof_clears_path_and_file(): void
    {
        $disk = config('filesystems.vehicle_docs_disk');
        Storage::fake($disk);
        $v = Vehicle::create(['vehicle_number' => 'PRF2', 'sales_channel' => 'export']);
        $path = "vehicles/{$v->id}/payment-proofs/existing.pdf";
        Storage::disk($disk)->put($path, 'dummy');
        $fp = FinalPayment::create([
            'vehicle_id' => $v->id, 'amount' => 500_000, 'type' => 'balance',
            'payment_date' => '2026-05-05', 'proof_path' => $path,
        ]);
        $this->actingAs($this->admin());

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->call('removeFinalPaymentProof', 0)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull($fp->fresh()->proof_path, '전시문 경로 제거됨');
        Storage::disk($disk)->assertMissing($path);
    }

    public function test_proof_filename_visible_on_locked_payment(): void
    {
        // jin 발견 버그 — 저장된 잔금은 채권관리 미러링으로 locked 렌더. 전시문 다운로드가 locked 행에도 보여야.
        $disk = config('filesystems.vehicle_docs_disk');
        Storage::fake($disk);
        $v = Vehicle::create(['vehicle_number' => 'PRF4', 'sales_channel' => 'export']);
        $path = "vehicles/{$v->id}/payment-proofs/9/송금증_wire.pdf";
        Storage::disk($disk)->put($path, 'dummy');
        FinalPayment::create([
            'vehicle_id' => $v->id, 'amount' => 700_000, 'type' => 'balance',
            'payment_date' => '2026-05-05', 'proof_path' => $path,
        ]);
        $this->actingAs($this->admin());

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->assertSee('송금증_wire.pdf');   // locked 행 아래 전시문 파일명 노출
    }

    public function test_proof_path_not_ledger_locked_on_confirmed_payment(): void
    {
        // proof_path 는 회계 잠금 대상 아님 — 재무 확정된 잔금에도 첨부 가능(amount/date/rate 만 잠금).
        $v = Vehicle::create(['vehicle_number' => 'PRF3', 'sales_channel' => 'export']);
        $fp = FinalPayment::create([
            'vehicle_id' => $v->id, 'amount' => 300_000, 'type' => 'balance',
            'payment_date' => '2026-05-05', 'confirmed_at' => now(),
        ]);

        $fp->update(['proof_path' => 'vehicles/1/payment-proofs/x.pdf']);   // 예외 없이 통과해야
        $this->assertSame('vehicles/1/payment-proofs/x.pdf', $fp->fresh()->proof_path);
    }
}
