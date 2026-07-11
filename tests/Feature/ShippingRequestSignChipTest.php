<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\ShippingRequest;
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

/** 선적요청 묶음서류 행 — 전자서명 상태칩(발급→모달, 서명완료→서명본 링크). */
class ShippingRequestSignChipTest extends TestCase
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
    }

    private function batch(): array
    {
        $buyer = Buyer::create(['name' => 'TOKYO', 'contact_email' => 'b@t.jp', 'is_active' => true]);
        $vehicles = collect(['B1', 'B2'])->map(fn ($vn) => Vehicle::create([
            'vehicle_number' => $vn, 'sales_channel' => 'export', 'currency' => 'USD', 'exchange_rate' => 1300,
            'sale_date' => '2026-06-01', 'sale_price' => 5000, 'buyer_id' => $buyer->id, 'purchase_date' => '2026-06-01',
        ]));
        foreach ($vehicles as $v) {
            ShippingRequest::create([
                'batch_id' => 'BATCH1', 'vehicle_id' => $v->id, 'buyer_id' => $buyer->id,
                'shipping_method' => 'RORO', 'status' => 'requested', 'requested_at' => now(),
                'requested_by_email' => 'ops@ssancar.test',
            ]);
        }

        return [$buyer, $vehicles];
    }

    public function test_chip_shows_request_then_issues_session(): void
    {
        $this->actingAs(User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]));
        [$buyer, $vehicles] = $this->batch();

        Volt::test('erp.shipping-requests.index')
            ->assertSee('전자서명 요청')
            ->call('requestSignatureForBatch', 'BATCH1')
            ->assertSet('showSignModal', true);

        $c = SignedContract::first();
        $this->assertNotNull($c);
        $this->assertSame(SignedContract::STATUS_PENDING, $c->status);
        $this->assertEqualsCanonicalizing($vehicles->pluck('id')->all(), $c->vehicle_ids);
    }

    public function test_chip_shows_signed_when_session_signed_for_batch_set(): void
    {
        $this->actingAs(User::factory()->create(['permission' => 'admin', 'email_verified_at' => now()]));
        [$buyer, $vehicles] = $this->batch();

        SignedContract::create([
            'buyer_id' => $buyer->id,
            'vehicle_ids' => $vehicles->pluck('id')->all(),
            'contract_no' => 'SC2607-00001', 'currency' => 'USD',
            'snapshot_path' => 'signed-contracts/x.xlsx', 'source_hash' => str_repeat('a', 64),
            'snapshot_data' => ['buyer_name' => 'TOKYO'],
            'status' => SignedContract::STATUS_SIGNED, 'sign_token' => bin2hex(random_bytes(16)),
            'signed_pdf_path' => 'signed-contracts/x.signed.pdf', 'signed_at' => now(),
        ]);

        Volt::test('erp.shipping-requests.index')
            ->assertSee('서명완료')
            ->assertDontSee('전자서명 요청');
    }
}
