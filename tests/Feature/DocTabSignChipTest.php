<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\SignedContract;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Documents\PdfConverter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/** 차량 「서류」 탭 — 개별차량 전자서명 상태칩(발급·서명완료 표시). */
class DocTabSignChipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
        App::setLocale('ko');
        Storage::fake(config('filesystems.vehicle_docs_disk'));
        $this->app->instance(PdfConverter::class, new class extends PdfConverter
        {
            public function fromSpreadsheet(Spreadsheet $spreadsheet): string
            {
                return "%PDF-1.4\n% test\n";
            }
        });
        $this->actingAs(User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]));
    }

    private function vehicle(): Vehicle
    {
        $buyer = Buyer::create(['name' => 'TOKYO', 'contact_email' => 'b@t.jp', 'is_active' => true]);

        return Vehicle::create([
            'vehicle_number' => 'D1', 'sales_channel' => 'export', 'currency' => 'USD', 'exchange_rate' => 1300,
            'sale_date' => '2026-06-01', 'sale_price' => 5000, 'buyer_id' => $buyer->id, 'purchase_date' => '2026-06-01',
        ]);
    }

    public function test_doc_tab_requests_signature_for_single_vehicle(): void
    {
        $v = $this->vehicle();

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->assertSee('전자서명 요청')
            ->call('requestSignatureForVehicle')
            ->assertSet('showSignModal', true);

        $c = SignedContract::first();
        $this->assertNotNull($c);
        $this->assertSame([$v->id], $c->vehicle_ids);
    }

    public function test_doc_tab_shows_signed_state(): void
    {
        $v = $this->vehicle();
        $this->signedContract($v);

        Volt::test('erp.vehicles.index')
            ->call('openEdit', $v->id)
            ->assertSee('서명완료');
    }

    public function test_erp_signed_pdf_route_serves_for_authorized_user(): void
    {
        $v = $this->vehicle();
        $c = $this->signedContract($v);
        Storage::disk(config('filesystems.vehicle_docs_disk'))->put($c->signed_pdf_path, "%PDF-1.4\ncontract\n");

        $this->get(route('erp.signed-contracts.pdf', $c->id))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    private function signedContract(Vehicle $v): SignedContract
    {
        return SignedContract::create([
            'buyer_id' => $v->buyer_id, 'vehicle_ids' => [$v->id],
            'contract_no' => 'SC2607-00009', 'currency' => 'USD',
            'snapshot_path' => 'signed-contracts/y.xlsx', 'source_hash' => str_repeat('b', 64),
            'snapshot_data' => ['buyer_name' => 'TOKYO'],
            'status' => SignedContract::STATUS_SIGNED, 'sign_token' => bin2hex(random_bytes(16)),
            'signed_pdf_path' => 'signed-contracts/y.signed.pdf', 'signed_at' => now(),
        ]);
    }
}
