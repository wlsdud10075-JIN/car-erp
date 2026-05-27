<?php

namespace Tests\Feature;

use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * claudereview D — forceDelete 시 첨부(서류+사진)를 같은 디스크 deleted/ prefix 로 보존 이동.
 * 기존 로컬 File:: 이동은 운영 S3 미처리라 orphan 발생 → Storage 추상화로 교체.
 * Storage::fake 로 디스크 분기 없이 동작 검증(운영 S3도 같은 코드 경로).
 */
class VehicleForceDeleteAttachmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_force_delete_moves_attachments_to_deleted_prefix(): void
    {
        config(['filesystems.vehicle_docs_disk' => 'public']);
        Storage::fake('public');
        $disk = Storage::disk('public');

        $v = Vehicle::create(['vehicle_number' => '33다3333', 'sales_channel' => 'export']);
        $disk->put("vehicles/{$v->id}/deregistration.xlsx", 'DOC');
        $disk->put("vehicles/{$v->id}/photos/1.jpg", 'IMG');

        $v->forceDelete();

        // 원본 prefix 비워짐 (orphan 없음)
        $this->assertEmpty($disk->allFiles("vehicles/{$v->id}"), '원본 첨부가 남아있음(orphan)');

        // deleted/ 로 2건 보존 이동 (서류 + 사진 — 상대경로 보존)
        $backed = $disk->allFiles('deleted');
        $this->assertCount(2, $backed);
        $this->assertTrue(collect($backed)->contains(fn ($p) => str_ends_with($p, 'deregistration.xlsx')), '서류 백업 누락');
        $this->assertTrue(collect($backed)->contains(fn ($p) => str_ends_with($p, 'photos/1.jpg')), '사진 백업 누락(상대경로)');
    }

    public function test_force_delete_no_attachments_is_noop(): void
    {
        config(['filesystems.vehicle_docs_disk' => 'public']);
        Storage::fake('public');

        $v = Vehicle::create(['vehicle_number' => '44라4444', 'sales_channel' => 'export']);
        $v->forceDelete();   // 첨부 없음 — 예외 없이 통과

        $this->assertEmpty(Storage::disk('public')->allFiles('deleted'));
        $this->assertDatabaseMissing('vehicles', ['id' => $v->id]);
    }
}
