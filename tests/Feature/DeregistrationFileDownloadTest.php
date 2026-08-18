<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 업로드 말소신청서 개별 다운로드 파일명 — 말소신청서_{차량번호}_{차대번호 뒤 6자리} (jin 2026-08-18).
 *
 * 선적묶음 ⋯더보기에서 N개를 연속으로 받으므로, 받는 쪽이 파일명만 보고 차를 대조할 수 있어야 한다.
 */
class DeregistrationFileDownloadTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): User
    {
        return User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]);
    }

    private function vehicleWithDoc(?string $vin): Vehicle
    {
        $disk = Storage::fake(config('filesystems.vehicle_docs_disk'));
        $path = 'vehicles/dereg-'.uniqid().'.pdf';
        $disk->put($path, '%PDF-1.4');

        return Vehicle::create([
            'vehicle_number' => '18누0304',
            'sales_channel' => 'export',
            'nice_reg_vin' => $vin,
            'deregistration_document' => $path,
        ]);
    }

    private function filenameOf($response): string
    {
        $disposition = $response->headers->get('content-disposition');
        // Laravel 은 ASCII fallback 과 filename*=UTF-8'' 를 함께 보낸다 — 원문은 후자에만 남는다.
        $this->assertMatchesRegularExpression("/filename\*=utf-8''/i", $disposition);
        preg_match("/filename\*=utf-8''([^;]+)/i", $disposition, $m);

        return rawurldecode($m[1]);
    }

    public function test_filename_carries_plate_and_last_six_of_vin(): void
    {
        $v = $this->vehicleWithDoc('SAJAB4BN9JCP35933');

        $res = $this->actingAs($this->manager())
            ->get(route('erp.vehicles.deregistration-file', ['id' => $v->id]));

        $res->assertOk();
        $this->assertSame('말소신청서_18누0304_P35933.pdf', $this->filenameOf($res));
    }

    public function test_missing_vin_leaves_no_dangling_separator(): void
    {
        $v = $this->vehicleWithDoc(null);

        $res = $this->actingAs($this->manager())
            ->get(route('erp.vehicles.deregistration-file', ['id' => $v->id]));

        $res->assertOk();
        $this->assertSame('말소신청서_18누0304.pdf', $this->filenameOf($res));
    }
}
