<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Salesman;
use App\Models\ShippingRequest;
use App\Models\SignedContract;
use App\Models\Vehicle;
use App\Services\Documents\PdfConverter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/**
 * 🚨 "빈 서류" 차단 — board 서류 API·전자서명 (2026-08-18, board 인계 질의).
 *
 * jin: *"403 이 나면 바로 알지만, 내용이 빈 서류가 나오면 아무도 못 잡습니다."*
 *
 * 이 부류가 위험한 이유 = **성공 응답**으로 나간다. 200 + 정상 xlsx 라 로그도 예외도 안 남고,
 * 바이어 손에 들어가서야 발견된다. 그래서 **가드가 없으면 테스트로도 못 잡는다** — 여기서 막는다.
 *
 * ⚠️ 「1바이어」 검사(`unique()->count() > 1`)로는 **못 잡는다** — buyer_id 가 전부 null 이면
 *    unique 가 1이라 통과한다. 그 성질을 test_all_null_buyers_pass_the_mixed_buyer_check 로 박제한다.
 * ⚠️ DB 도 못 잡는다 — 운영 `chk_sale_required` 는 sale_date·exchange_rate 만 보장하고
 *    buyer_id 는 보장하지 않는다(SKILLS §25 2026-08-18 정정).
 */
class BoardDocumentBlankGuardTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'test-board-read-secret';

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = OFF');
        config(['services.board_read.hmac_secret' => $this->secret]);
        Storage::fake(config('filesystems.vehicle_docs_disk'));

        $this->app->instance(PdfConverter::class, new class extends PdfConverter
        {
            public function fromSpreadsheet(Spreadsheet $spreadsheet): string
            {
                return "%PDF-1.4\n% test\n";
            }
        });
    }

    private function signedGet(string $path, array $query)
    {
        ksort($query);
        $ts = now()->timestamp;
        $canonical = "GET\n".$path.'?'.http_build_query($query)."\n".$ts."\n";

        return $this->get($path.'?'.http_build_query($query), [
            'X-Board-Signature' => 'sha256='.hash_hmac('sha256', $canonical, $this->secret),
            'X-Timestamp' => (string) $ts,
            'X-Nonce' => (string) Str::uuid(),
        ]);
    }

    private function signedPost(string $path, array $payload)
    {
        $body = json_encode($payload);
        $ts = now()->timestamp;
        $canonical = "POST\n".$path."?\n".$ts."\n".$body;

        return $this->postJson($path, $payload, [
            'X-Board-Signature' => 'sha256='.hash_hmac('sha256', $canonical, $this->secret),
            'X-Timestamp' => (string) $ts,
            'X-Nonce' => (string) Str::uuid(),
        ]);
    }

    private function salesman(): Salesman
    {
        return Salesman::create([
            'name' => '김영업', 'email' => 'sales@car-erp.test', 'type' => 'freelance', 'is_active' => true,
        ]);
    }

    private function vehicle(int $salesmanId, ?int $buyerId, string $vn, float $salePrice = 5000): Vehicle
    {
        return Vehicle::create([
            'vehicle_number' => $vn, 'sales_channel' => 'export', 'salesman_id' => $salesmanId,
            'buyer_id' => $buyerId, 'currency' => 'USD', 'exchange_rate' => 1300,
            'sale_date' => '2026-06-01', 'sale_price' => $salePrice, 'purchase_date' => '2026-06-01',
        ]);
    }

    private function docs(string $type, array $ids)
    {
        return $this->signedGet('/api/internal/board/documents/'.$type, [
            'salesman_email' => 'sales@car-erp.test',
            'ids' => implode(',', $ids),
        ]);
    }

    // ── 정상 경로 — 선적 계획 후보(shippable)면 그대로 나간다 ─────────────

    public function test_normal_vehicle_still_downloads(): void
    {
        $s = $this->salesman();
        $b = Buyer::create(['name' => 'ATLAS', 'is_active' => true]);
        $v = $this->vehicle($s->id, $b->id, '11가1111');

        $this->docs('sales_contract', [$v->id])->assertOk();
        $this->docs('invoice', [$v->id])->assertOk();
    }

    /**
     * board 인계 질의의 본문 — **묶음(shipping_requests)이 없어도 발급된다.**
     * 서류는 차량 id + 바이어 기준이라 선적 묶음과 무관하다는 것을 박제한다.
     */
    public function test_document_issues_without_any_shipping_request_row(): void
    {
        $s = $this->salesman();
        $b = Buyer::create(['name' => 'ATLAS', 'is_active' => true]);
        $v = $this->vehicle($s->id, $b->id, '11가2222');

        $this->assertSame(0, ShippingRequest::where('vehicle_id', $v->id)->count());
        $this->docs('sales_contract', [$v->id])->assertOk();
    }

    // ── 빈 서류 차단 ──────────────────────────────────────────────

    public function test_vehicle_without_buyer_is_rejected(): void
    {
        $s = $this->salesman();
        $v = $this->vehicle($s->id, null, '11가3333');

        $res = $this->docs('sales_contract', [$v->id]);
        $res->assertStatus(422);
        $this->assertStringContainsString('No buyer', (string) $res->getContent());
    }

    public function test_vehicle_without_sale_price_is_rejected(): void
    {
        $s = $this->salesman();
        $b = Buyer::create(['name' => 'ATLAS', 'is_active' => true]);
        $v = $this->vehicle($s->id, $b->id, '11가4444', 0);

        $res = $this->docs('invoice', [$v->id]);
        $res->assertStatus(422);
        $this->assertStringContainsString('No sale price', (string) $res->getContent());
    }

    /** 선적 4종에도 같은 가드가 걸린다 — 묶음 서류라고 빈 내용이 허용되는 건 아니다. */
    public function test_shipping_documents_are_guarded_too(): void
    {
        $s = $this->salesman();
        $v = $this->vehicle($s->id, null, '11가5555');

        $this->docs('roro_invoice_packing', [$v->id])->assertStatus(422);
        $this->docs('container_contract', [$v->id])->assertStatus(422);
    }

    /** 한 대만 비어 있어도 묶음 전체를 막는다 — 섞이면 그 한 줄이 빈 채로 인쇄된다. */
    public function test_one_bad_vehicle_blocks_the_whole_batch(): void
    {
        $s = $this->salesman();
        $b = Buyer::create(['name' => 'ATLAS', 'is_active' => true]);
        $ok = $this->vehicle($s->id, $b->id, '11가6666');
        $bad = $this->vehicle($s->id, $b->id, '11가7777', 0);

        $this->docs('sales_contract', [$ok->id, $bad->id])->assertStatus(422);
    }

    /**
     * 🔒 왜 별도 가드가 필요한지의 근거 — buyer_id 가 **전부 null 이면 「1바이어」 검사를 통과**한다.
     * 이 성질이 사라지면(예: 검사 방식 변경) 이 테스트가 알려준다.
     */
    public function test_all_null_buyers_pass_the_mixed_buyer_check(): void
    {
        $s = $this->salesman();
        $a = $this->vehicle($s->id, null, '11가8888');
        $c = $this->vehicle($s->id, null, '11가9999');

        $uniqueCount = Vehicle::whereIn('id', [$a->id, $c->id])->pluck('buyer_id')->unique()->count();
        $this->assertSame(1, $uniqueCount, 'null 만 모이면 unique 가 1 — 혼합검사로는 못 잡는다');

        // 그래서 별도 null 검사가 실제로 막아야 한다.
        $this->docs('sales_contract', [$a->id, $c->id])->assertStatus(422);
    }

    // ── 전자서명도 같은 가드 ─────────────────────────────────────

    public function test_signing_rejects_vehicle_without_sale_price(): void
    {
        $s = $this->salesman();
        $b = Buyer::create(['name' => 'ATLAS', 'is_active' => true]);
        $v = $this->vehicle($s->id, $b->id, '22가1111', 0);

        $res = $this->signedPost('/api/internal/board/signing-requests', [
            'salesman_email' => 'sales@car-erp.test',
            'vehicle_ids' => [$v->id],
        ]);

        $res->assertStatus(422);
        $this->assertSame(0, SignedContract::count(), '서명본은 되돌릴 수 없다 — 만들어지면 안 된다');
    }

    public function test_signing_still_works_for_a_normal_vehicle(): void
    {
        $s = $this->salesman();
        $b = Buyer::create(['name' => 'ATLAS', 'is_active' => true]);
        $v = $this->vehicle($s->id, $b->id, '22가2222');

        $this->signedPost('/api/internal/board/signing-requests', [
            'salesman_email' => 'sales@car-erp.test',
            'vehicle_ids' => [$v->id],
        ])->assertOk();
    }
}
