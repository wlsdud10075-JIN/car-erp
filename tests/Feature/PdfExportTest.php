<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use App\Services\Documents\PdfConverter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 서류 PDF·인쇄 (item①, 2026-07-10). ?format=pdf → LibreOffice 변환 PDF 응답.
 *   컨트롤러 배선은 PdfConverter mock 으로 검증(CI 안전). 실제 soffice 변환은 설치돼 있을 때만.
 */
class PdfExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
    }

    private function actingSuper(): void
    {
        $this->actingAs(User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]));
    }

    private function vehicle(): Vehicle
    {
        return Vehicle::create([
            'vehicle_number' => 'DOC-1',
            'sales_channel' => 'export',
            'vehicle_registration_number' => '36가2483',
        ]);
    }

    private function docUrl(Vehicle $v, string $type): string
    {
        return route('erp.vehicles.documents.show', ['id' => $v->id, 'type' => $type]);
    }

    public function test_default_format_is_xlsx(): void
    {
        $this->actingSuper();
        $v = $this->vehicle();

        $res = $this->get($this->docUrl($v, 'deregistration'));

        $res->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $res->headers->get('content-type'));
    }

    public function test_pdf_format_returns_pdf_attachment(): void
    {
        $this->mock(PdfConverter::class, fn ($m) => $m->shouldReceive('fromSpreadsheet')->once()->andReturn('%PDF-1.4 fake'));
        $this->actingSuper();
        $v = $this->vehicle();

        $res = $this->get($this->docUrl($v, 'deregistration').'?format=pdf');

        $res->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('content-type'));
        $this->assertStringContainsString('attachment', (string) $res->headers->get('content-disposition'));
        $this->assertStringContainsString('.pdf', (string) $res->headers->get('content-disposition'));
    }

    public function test_pdf_inline_disposition_for_print(): void
    {
        $this->mock(PdfConverter::class, fn ($m) => $m->shouldReceive('fromSpreadsheet')->once()->andReturn('%PDF-1.4 fake'));
        $this->actingSuper();
        $v = $this->vehicle();

        $res = $this->get($this->docUrl($v, 'deregistration').'?format=pdf&inline=1');

        $res->assertOk();
        $this->assertStringContainsString('inline', (string) $res->headers->get('content-disposition'));
    }

    public function test_real_libreoffice_conversion_produces_pdf(): void
    {
        $soffice = collect([
            'C:/Program Files/LibreOffice/program/soffice.com',
            '/usr/bin/soffice',
            '/usr/bin/libreoffice',
        ])->first(fn ($p) => is_file($p));

        if (! $soffice) {
            $this->markTestSkipped('LibreOffice 미설치 — 실제 변환 스킵 (CI/서버 미설치 환경).');
        }

        $this->actingSuper();
        $v = $this->vehicle();

        $res = $this->get($this->docUrl($v, 'deregistration').'?format=pdf');

        $res->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', (string) $res->getContent());
    }
}
