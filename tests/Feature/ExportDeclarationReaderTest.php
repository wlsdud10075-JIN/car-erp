<?php

namespace Tests\Feature;

use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\BulkVehicleDocumentService;
use App\Services\ExportDeclarationReader;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 📄 수출신고필증 읽기 (jin 2026-11-24 «신고번호는 자동기입, 면장금액은 매칭만»).
 *
 * 지키는 것: ①신고번호·이 차 금액을 차대번호 줄로 찾는다(여러 대 신고서 포함) ②면장금액은 비교만 — 맞음/다름
 *            ③이 차 차대번호가 없으면 「다른 차의 필증」, 신고번호를 넣지 않는다(11-03 오첨부)
 *            ④수량 2 이상 한 줄·ERP 금액 없음·통화 다름 = 비교 못 함 ⑤그림·엑셀은 아무것도 안 한다
 *            ⑥차량 수출 탭: 고른 순간 신고번호 칸이 채워지고 판정 상자 ⑦일괄 업로드: 빈 신고번호만 채운다.
 *
 * 실제 필증은 레포에 넣지 않는다 — 운영 필증에서 본 글자 모양(smalot 추출)을 그대로 흉내 낸다.
 * 한글 줄(「34금액(EUR)」)은 테스트 PDF 글꼴에 없어 글자 테스트(fromText)로만 본다.
 */
class ExportDeclarationReaderTest extends TestCase
{
    use RefreshDatabase;

    private const VIN_A = 'KMHD351UBCU073590';

    private const VIN_B = 'WDC0G0FB7JF329307';

    private function text(): string
    {
        return implode("\n", [
            '수출신고필증(적재전, 갑지)',
            '44475-26-110177X',
            '30모델ㆍ규격	31성분	32수량(단위) 33단가(EUR) 34금액(EUR)',
            '(NO.01)',
            'HYUNDAI I30 2012 '.self::VIN_A.' * SHIPPING COST : EUR 1,080',
            '1(UN)	4,780	4,780',
            '(NO.02)',
            'BENZ GLC220 D 4MATIC 2018 '.self::VIN_B.' * SHIPPING COST :',
            'EUR 1,911',
            '1(UN)	16,950	16,950',
            '(NO.03)',
            'KIA MORNING 2015 KNABE511BFT000001 KNABE511BFT000002',
            '2(UN)	2,000	4,000',
        ]);
    }

    /** ASCII 만 담은 진짜 PDF — 라이브러리가 바이트에서 줄을 그대로 뽑는지 본다. */
    private function pdfBytes(string $number = '44475-26-110177X', string $vin = self::VIN_A, string $amount = '4,780'): string
    {
        $lines = ['EXPORT DECLARATION', $number, '(NO.01)', 'HYUNDAI I30 2012 '.$vin.' * SHIPPING COST : EUR 1,080', '1(UN) '.$amount.' '.$amount];

        return Pdf::loadHTML('<html><body>'.implode('', array_map(fn ($l) => '<div>'.e($l).'</div>', $lines)).'</body></html>')->output();
    }

    public function test_reads_number_currency_and_each_vehicles_amount(): void
    {
        $r = ExportDeclarationReader::fromText($this->text());
        $this->assertSame('44475-26-110177X', $r['number']);
        $this->assertSame('EUR', $r['currency']);
        $this->assertSame(['vin_found' => true, 'amount' => 4780.0, 'qty' => 1], ExportDeclarationReader::itemFor($r, self::VIN_A));
        $this->assertSame(16950.0, ExportDeclarationReader::itemFor($r, self::VIN_B)['amount'], '금액이 다음다음 줄에 있어도');
        $this->assertFalse(ExportDeclarationReader::itemFor($r, 'KNAXX000000000000')['vin_found']);
    }

    public function test_check_statuses(): void
    {
        $r = ExportDeclarationReader::fromText($this->text());

        $this->assertSame('match', ExportDeclarationReader::check($r, self::VIN_A, 4780, 'EUR')['status']);
        $mis = ExportDeclarationReader::check($r, self::VIN_A, 4736, 'EUR');
        $this->assertSame('mismatch', $mis['status']);
        $this->assertSame(4780.0, $mis['pdf_amount']);
        $this->assertSame('other_vehicle', ExportDeclarationReader::check($r, 'KNAXX000000000000', 4780, 'EUR')['status']);

        $this->assertSame('shared_line', ExportDeclarationReader::check($r, 'KNABE511BFT000001', 2000, 'EUR')['reason'], '한 줄에 2대 — 이 차 몫을 못 가른다');
        $this->assertSame('erp_empty', ExportDeclarationReader::check($r, self::VIN_A, null, 'EUR')['reason']);
        $this->assertSame('currency', ExportDeclarationReader::check($r, self::VIN_A, 4780, 'USD')['reason']);
        $this->assertSame('no_vin', ExportDeclarationReader::check($r, '', 4780, 'EUR')['reason']);
    }

    public function test_two_different_numbers_fill_nothing_and_non_declarations_return_null(): void
    {
        $two = ExportDeclarationReader::fromText($this->text()."\n44475-26-999999X");
        $this->assertNull($two['number']);
        $this->assertCount(2, $two['numbers']);

        $this->assertNull(ExportDeclarationReader::fromText('INVOICE / PACKING LIST'));
        $this->assertNull(ExportDeclarationReader::fromBytes('not a pdf'));
        $this->assertNull(ExportDeclarationReader::fromUpload(UploadedFile::fake()->create('photo.jpg', 10, 'image/jpeg')));
    }

    public function test_reads_a_real_pdf(): void
    {
        $r = ExportDeclarationReader::fromBytes($this->pdfBytes());
        $this->assertSame('44475-26-110177X', $r['number']);
        $this->assertSame(4780.0, ExportDeclarationReader::itemFor($r, self::VIN_A)['amount']);
    }

    private function vehicle(array $attrs = []): Vehicle
    {
        $sm = Salesman::firstOrCreate(['name' => 'TESTMAN'], ['type' => 'employee', 'is_active' => true]);

        return Vehicle::create(array_merge([
            'vehicle_number' => '11가'.random_int(1000, 9999), 'sales_channel' => 'export', 'currency' => 'EUR', 'exchange_rate' => 1500,
            'dhl_request' => false, 'salesman_id' => $sm->id, 'purchase_price' => 5_000_000, 'purchase_date' => now()->toDateString(),
            'nice_reg_vin' => self::VIN_A, 'export_declaration_amount' => 4780,
        ], $attrs));
    }

    private function admin(): User
    {
        return User::factory()->create(['permission' => 'admin', 'role' => '관리', 'email_verified_at' => now()]);
    }

    public function test_export_tab_fills_the_number_and_shows_the_amount_check(): void
    {
        Storage::fake((string) config('filesystems.vehicle_docs_disk'));
        $this->actingAs($this->admin());
        $v = $this->vehicle();

        $c = Volt::test('erp.vehicles.index')->call('openEdit', $v->id)
            ->set('exportDeclarationDocFile', UploadedFile::fake()->createWithContent('decl.pdf', $this->pdfBytes()))
            ->assertSet('export_declaration_number', '44475-26-110177X');
        $this->assertSame('match', $c->get('declCheck')['status']);
        $c->assertSee('면장금액 일치');

        // 금액이 다른 필증 — 신고번호는 넣고, 면장금액은 그대로 두고 「다름」.
        $c->set('exportDeclarationDocFile', UploadedFile::fake()->createWithContent('decl2.pdf', $this->pdfBytes('44475-26-120000X', self::VIN_A, '4,824')))
            ->assertSet('export_declaration_number', '44475-26-120000X')
            ->assertSet('export_declaration_amount_str', fn ($s) => str_replace(',', '', (string) $s) === '' || (float) str_replace(',', '', (string) $s) === 4780.0);
        $this->assertSame('mismatch', $c->get('declCheck')['status']);
        $this->assertStringContainsString('44475-26-110177X', $c->get('declCheck')['number_note'], '무엇에서 바뀌었는지 보여 준다');
    }

    public function test_export_tab_does_not_fill_the_number_from_another_vehicles_declaration(): void
    {
        Storage::fake((string) config('filesystems.vehicle_docs_disk'));
        $this->actingAs($this->admin());
        $v = $this->vehicle(['export_declaration_number' => '44475-26-110183X']);

        $c = Volt::test('erp.vehicles.index')->call('openEdit', $v->id)
            ->set('exportDeclarationDocFile', UploadedFile::fake()->createWithContent('decl.pdf', $this->pdfBytes('44475-26-110177X', self::VIN_B)))
            ->assertSet('export_declaration_number', '44475-26-110183X');
        $this->assertSame('other_vehicle', $c->get('declCheck')['status']);
        $c->assertSee('다른 차의 필증일 수 있습니다');
    }

    public function test_bulk_upload_fills_only_empty_numbers_and_previews_the_check(): void
    {
        Storage::fake((string) config('filesystems.vehicle_docs_disk'));
        $user = User::factory()->create(['permission' => 'user', 'role' => '수출통관', 'email_verified_at' => now()]);
        $this->actingAs($user);
        $empty = $this->vehicle();
        $kept = $this->vehicle(['export_declaration_number' => 'KEEP-1']);
        $other = $this->vehicle(['nice_reg_vin' => 'KNAXX000000000000']);

        $c = Volt::test('erp.vehicles.index')
            ->set('shipDocIds', [(string) $empty->id, (string) $kept->id, (string) $other->id])
            ->call('openBulkDoc')
            ->set('bulkDocType', 'export_declaration')
            ->set('bulkDocFile', UploadedFile::fake()->createWithContent('decl.pdf', $this->pdfBytes()));
        $check = $c->get('bulkDeclCheck');
        $this->assertSame(['match' => 2, 'mismatch' => 0, 'other_vehicle' => 1, 'amount_unknown' => 0], $check['counts']);
        $this->assertSame('other_vehicle', $check['rows'][0]['status']);

        $c->set('bulkDocMixedAck', true)->call('applyBulkDoc')->assertSet('bulkDocOpen', false);
        $this->assertSame('44475-26-110177X', $empty->fresh()->export_declaration_number, '빈 칸은 채운다');
        $this->assertNotNull($empty->fresh()->export_declaration_document);
        $this->assertSame('KEEP-1', $kept->fresh()->export_declaration_number, '있는 번호는 일괄로 안 바꾼다');
        $this->assertNotNull($kept->fresh()->export_declaration_document, '번호가 있어도 필증과 맞으면 올린다');
        $this->assertNull($other->fresh()->export_declaration_number, '필증에 없는 차는 안 채운다');
        $this->assertNull($other->fresh()->export_declaration_document, '🔒 필증에 없는 차는 파일도 안 붙인다(jin 2026-11-24)');
    }

    /**
     * ✏️ 안 맞는 차는 창에서 고치거나 빼고 올린다 (jin 2026-11-24 «틀린 걸 보여 주고 수정하게 해서 일괄로»).
     * 면장금액은 회계 칸이라 고치기는 관리 이상·영업만(차량 창과 같다) — 수출통관은 버튼 대신 안내, 빼기는 된다.
     */
    public function test_bulk_mismatch_can_be_fixed_or_excluded_before_upload(): void
    {
        Storage::fake((string) config('filesystems.vehicle_docs_disk'));
        $this->actingAs($this->admin());
        $diff = $this->vehicle(['export_declaration_amount' => 4736]);
        $other = $this->vehicle(['nice_reg_vin' => 'KNAXX000000000000']);

        $c = Volt::test('erp.vehicles.index')
            ->set('shipDocIds', [(string) $diff->id, (string) $other->id])
            ->call('openBulkDoc')
            ->set('bulkDocType', 'export_declaration')
            ->set('bulkDocFile', UploadedFile::fake()->createWithContent('decl.pdf', $this->pdfBytes()));
        $this->assertSame(['match' => 0, 'mismatch' => 1, 'other_vehicle' => 1, 'amount_unknown' => 0], $c->get('bulkDeclCheck')['counts']);
        $c->assertSee('필증 금액으로 고치기');

        $c->call('fixBulkDeclAmount', $diff->id);
        $this->assertEquals(4780, (float) $diff->fresh()->export_declaration_amount, '필증을 다시 읽어 그 차 금액으로');
        $c->call('excludeFromBulkDoc', $other->id);
        $this->assertSame(['match' => 1, 'mismatch' => 0, 'other_vehicle' => 0, 'amount_unknown' => 0], $c->get('bulkDeclCheck')['counts']);

        $c->call('applyBulkDoc')->assertSet('bulkDocOpen', false);
        $this->assertNotNull($diff->fresh()->export_declaration_document);
        $this->assertSame('44475-26-110177X', $diff->fresh()->export_declaration_number);
        $this->assertNull($other->fresh()->export_declaration_document, '뺀 차는 손대지 않는다');
    }

    public function test_clearance_cannot_fix_the_declared_amount_but_can_exclude(): void
    {
        Storage::fake((string) config('filesystems.vehicle_docs_disk'));
        $this->actingAs(User::factory()->create(['permission' => 'user', 'role' => '수출통관', 'email_verified_at' => now()]));
        $diff = $this->vehicle(['export_declaration_amount' => 4736]);

        $c = Volt::test('erp.vehicles.index')
            ->set('shipDocIds', [(string) $diff->id])
            ->call('openBulkDoc')
            ->set('bulkDocType', 'export_declaration')
            ->set('bulkDocFile', UploadedFile::fake()->createWithContent('decl.pdf', $this->pdfBytes()))
            ->assertDontSee('필증 금액으로 고치기')
            ->assertSee('면장금액 수정 권한 없음')
            ->call('fixBulkDeclAmount', $diff->id);
        $this->assertEquals(4736, (float) $diff->fresh()->export_declaration_amount, '권한 없으면 안 바뀐다');

        $c->call('applyBulkDoc');
        $this->assertNull($diff->fresh()->export_declaration_document, '안 맞는 차는 안 올라간다');
    }

    public function test_service_alone_fills_numbers_too(): void
    {
        Storage::fake((string) config('filesystems.vehicle_docs_disk'));
        $user = User::factory()->create(['permission' => 'user', 'role' => '수출통관', 'email_verified_at' => now()]);
        $v = $this->vehicle();

        app(BulkVehicleDocumentService::class)->applyShared([$v->id], 'export_declaration',
            UploadedFile::fake()->createWithContent('decl.pdf', $this->pdfBytes()), $user, false, 'test');

        $this->assertSame('44475-26-110177X', $v->fresh()->export_declaration_number);
    }
}
