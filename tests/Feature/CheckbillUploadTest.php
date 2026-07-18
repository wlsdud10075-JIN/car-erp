<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * item 4 (jin 2026-07-18) — B/L 탭 체크빌(발급 전 확인용) 업로드 + seawaybill B/L 방식.
 * checkbill_document 는 최종 bl_document 와 별개 컬럼.
 */
class CheckbillUploadTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['permission' => 'admin', 'role' => '관리', 'email_verified_at' => now()]);
    }

    public function test_checkbill_upload_and_remove(): void
    {
        Storage::fake(config('filesystems.vehicle_docs_disk'));
        $this->actingAs($this->admin());
        $v = Vehicle::create(['vehicle_number' => 'CB1', 'sales_channel' => 'export']);
        $pdf = UploadedFile::fake()->create('checkbill.pdf', 10, 'application/pdf');

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('checkbillFile', $pdf)
            ->call('save')
            ->assertHasNoErrors();

        $v->refresh();
        $this->assertNotNull($v->checkbill_document, '체크빌 저장됨');
        Storage::disk(config('filesystems.vehicle_docs_disk'))->assertExists($v->checkbill_document);
        $this->assertNull($v->bl_document, '최종 B/L 은 별개(미첨부)');

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->call('removeCheckbill')
            ->call('save')
            ->assertHasNoErrors();
        $this->assertNull($v->fresh()->checkbill_document, '체크빌 제거됨');
    }

    public function test_seawaybill_bl_type_saves(): void
    {
        $this->actingAs($this->admin());
        $v = Vehicle::create(['vehicle_number' => 'CB2', 'sales_channel' => 'export']);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->set('bl_type', 'seawaybill')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('seawaybill', $v->fresh()->bl_type);
    }
}
