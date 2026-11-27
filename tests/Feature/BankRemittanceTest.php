<?php

namespace Tests\Feature;

use App\Mail\VehicleDocumentMail;
use App\Models\AuditLog;
use App\Models\BankRemittance;
use App\Models\Buyer;
use App\Models\MailDeliveryLog;
use App\Models\Salesman;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Assistant\OllamaClient;
use App\Services\BankRemittanceService;
use App\Services\Documents\PdfConverter;
use App\Services\RemitterNameReader;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * 🏦 은행 서류 (jin 2026-11-24) — 전신문 → 송금인 이름(GPU PC, 이름 하나만) → 기본정보 덮어쓰기 → 은행용 Proforma(Name 칸만) → 은행 메일.
 */
class BankRemittanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('filesystems.vehicle_docs_disk'));
        // 🔀 은행용 PI 는 PDF 로 만들지 않는다(jin 11-26 «수정이 어렵다, 엑셀로») — 불리면 테스트가 깨지게
        $this->app->instance(PdfConverter::class, new class extends PdfConverter
        {
            public function fromSpreadsheet(Spreadsheet $s): string
            {
                throw new \LogicException('bank PI must stay xlsx');
            }

            public function fromFileBytes(string $bytes, string $ext = 'xlsx'): string
            {
                return '%PDF-1.4 converted-from-'.$ext.':'.$bytes;
            }
        });
    }

    /** 쪽마다 정해진 답을 주는 가짜 GPU PC — 실측(11-24)대로 1쪽에서 Debtor 를 물으면 은행 BIC 를 답한다. */
    private function fakeOcr(array $byPrompt): void
    {
        $this->app->instance('remitter-ocr.client', new class($byPrompt) extends OllamaClient
        {
            public array $calls = [];

            public function __construct(private array $answers)
            {
                parent::__construct('http://fake', 1);
            }

            public function vision(string $model, string $prompt, string $imageBase64, int $numCtx): string
            {
                $this->calls[] = [$model, $numCtx, $imageBase64];
                $key = str_contains($prompt, '송금의뢰인') ? 'prefix' : 'name';
                $page = base64_decode($imageBase64);

                return $this->answers[$key][$page] ?? '{"debtor_name": null}';
            }
        });
    }

    private function page(string $tag): string
    {
        return base64_encode($tag);   // 가짜 클라이언트는 그림 내용을 안 본다 — 쪽 구분용 표식
    }

    public function test_reader_reads_page_three_only_and_never_asks_page_one(): void
    {
        $this->fakeOcr([
            'name' => ['P1' => '{"debtor_name": "JPMORGAN CHASE BANK"}', 'P3' => '{"debtor_name": "GOLDEN BRIDGE TRADING LLC"}'],
        ]);
        $r = (new RemitterNameReader)->read([$this->page('P1'), $this->page('P2'), $this->page('P3')]);
        $this->assertSame(['name' => 'GOLDEN BRIDGE TRADING LLC', 'status' => 'ok'], $r);

        $client = app('remitter-ocr.client');
        $this->assertSame([['qwen2.5vl:7b', 4096]], array_values(array_unique(array_map(fn ($c) => [$c[0], $c[1]], $client->calls), SORT_REGULAR)),
            'board OCR 과 같은 모델·num_ctx 로만 부른다(다르면 GPU PC 가 모델을 다시 올린다)');
        $this->assertSame(['P3'], array_map(fn ($c) => base64_decode($c[2]), $client->calls),
            '3쪽 한 번만 묻는다 — 1쪽 대조는 뺐다(jin 11-24, 팩스라 1쪽 칸을 잘못 읽어 「실패」로 떴다)');

        // 한 장짜리 그림이면 그 쪽
        $this->fakeOcr(['name' => ['P1' => '{"debtor_name": "JOHN DOE"}']]);
        $this->assertSame(['name' => 'JOHN DOE', 'status' => 'ok'], (new RemitterNameReader)->read([$this->page('P1')]));
    }

    public function test_reader_rejects_bank_code_own_company_and_korean_then_tries_next_pages_or_offline(): void
    {
        $this->fakeOcr([
            'name' => ['P1' => '{"debtor_name": "SHOULD NOT ASK"}', 'P3' => '{"debtor_name": "HEY MAN CO., LTD"}', 'P4' => '{"debtor_name": "CHASUS33XXX"}', 'P2' => '{"debtor_name": "(주)헤이맨 조태신"}'],
        ]);
        $this->assertSame(['name' => null, 'status' => 'none'], (new RemitterNameReader)->read([$this->page('P1'), $this->page('P2'), $this->page('P3'), $this->page('P4')]),
            '우리 회사 이름·은행 코드·한글은 후보가 아니고, 1쪽은 묻지 않는다');

        $this->fakeOcr(['name' => ['P3' => '{"debtor_name": "HEY MAN CO., LTD"}', 'P4' => '{"debtor_name": "JOHN SMITH"}']]);
        $this->assertSame(['name' => 'JOHN SMITH', 'status' => 'ok'], (new RemitterNameReader)->read([$this->page('P1'), $this->page('P2'), $this->page('P3'), $this->page('P4')]));

        $this->app->instance('remitter-ocr.client', new class extends OllamaClient
        {
            public function __construct()
            {
                parent::__construct('http://fake', 1);
            }

            public function vision(string $model, string $prompt, string $imageBase64, int $numCtx): string
            {
                throw new \RuntimeException('Ollama 연결 실패');
            }
        });
        $this->assertSame('offline', (new RemitterNameReader)->read([$this->page('P1'), $this->page('P2'), $this->page('P3')])['status']);
    }

    public function test_text_pdf(): void
    {
        $this->assertSame('JOHN DOE', RemitterNameReader::fromText("BIC :X\n< Debtor >\nName :JOHN DOE\nTown Name :A\n< Creditor >\nName :SSANCAR LTD"));
        $this->assertNull(RemitterNameReader::fromText("< Creditor >\nName :SSANCAR LTD"));
    }

    private array $made = [];

    private function cars(int $n = 2, array $over = []): array
    {
        $sm = Salesman::create(['name' => '담당', 'is_active' => true, 'type' => 'employee']);
        $buyer = Buyer::create(['name' => 'BUYER ONE', 'is_active' => true]);
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $out[] = Vehicle::create(array_merge([
                'vehicle_number' => '00가95'.$i.$i, 'sales_channel' => 'export', 'salesman_id' => $sm->id, 'buyer_id' => $buyer->id,
                'currency' => 'USD', 'exchange_rate' => 1400, 'sale_price' => 5000, 'sale_date' => '2026-11-01', 'remitter_name' => 'OLD NAME',
            ], $over));
        }

        return $out;
    }

    private function finance(): User
    {
        return User::factory()->create(['permission' => 'user', 'role' => '재무', 'email_verified_at' => now()]);
    }

    private function textAdvice(string $name): UploadedFile
    {
        $html = '<html><body><div>BIC :SHBKKRSEXXX</div><div>&lt; Debtor &gt;</div><div>Name :'.$name.'</div><div>&lt; Creditor &gt;</div><div>Name :HEYMAN CO LTD</div></body></html>';

        return UploadedFile::fake()->createWithContent('advice.pdf', Pdf::loadHTML($html)->output());
    }

    public function test_permission_and_same_buyer_rules(): void
    {
        [$a, $b] = $this->cars();
        $sales = User::factory()->create(['permission' => 'user', 'role' => '영업', 'email_verified_at' => now()]);
        $this->assertSame('vehicle.bank.err_permission', BankRemittanceService::vehiclesFor($sales, [$a->id]));

        $other = Vehicle::create(['vehicle_number' => '00가9599', 'sales_channel' => 'export', 'buyer_id' => Buyer::create(['name' => 'B2', 'is_active' => true])->id, 'currency' => 'USD']);
        $fin = $this->finance();
        $this->assertSame('vehicle.bank.err_buyers', BankRemittanceService::vehiclesFor($fin, [$a->id, $other->id]));
        $this->assertCount(2, BankRemittanceService::vehiclesFor($fin, [$a->id, $b->id]));
    }

    public function test_modal_reads_text_pdf_overwrites_remitter_builds_bank_invoice_and_mails(): void
    {
        Mail::fake();
        $set = Setting::companyTemplateSet();
        Setting::updateOrCreate(['key' => "mail_channel_{$set}"], ['value' => 'ses', 'type' => 'string']);
        Setting::updateOrCreate(['key' => "mail_from_address_{$set}"], ['value' => 'ops@company.test', 'type' => 'string']);
        Setting::updateOrCreate(['key' => "bank_mail_to_{$set}"], ['value' => 'officer@bank.test', 'type' => 'string']);
        [$a, $b] = $this->cars();
        $this->actingAs($this->finance());

        $t = Volt::test('erp.vehicles.bank-remittance')
            ->call('openBank', [$a->id, $b->id])
            ->assertSet('bankOpen', true)
            ->set('bankFile', $this->textAdvice('JOHN SAMPLE'))
            ->assertSet('ocrStatus', 'text')
            ->assertSet('remitterInput', 'JOHN SAMPLE')
            ->call('createBankRecord')
            ->assertHasNoErrors()
            ->assertDispatched('bank-remittance-done')
            ->assertSet('mailTo', 'officer@bank.test');

        foreach ([$a, $b] as $v) {
            $this->assertSame('JOHN SAMPLE', $v->fresh()->remitter_name, '기본정보 송금인 덮어쓰기');
        }
        $this->assertSame(2, AuditLog::where('column_name', 'remitter_name')->where('new_value', 'JOHN SAMPLE')->count(), '모델 저장 = 감사로그');

        $rec = BankRemittance::firstOrFail();
        $this->assertSame([$a->id, $b->id], $rec->vehicles()->orderBy('vehicles.id')->pluck('vehicles.id')->all());
        $this->assertStringEndsWith('.xlsx', $rec->invoice_name, '은행용 PI 는 엑셀(고쳐 쓸 수 있게)');
        $disk = Storage::disk(config('filesystems.vehicle_docs_disk'));
        $tmp = tempnam(sys_get_temp_dir(), 'pi').'.xlsx';
        file_put_contents($tmp, $disk->get($rec->invoice_path));
        $sheet = IOFactory::load($tmp)->getSheetByName('Invoice');
        $this->assertSame('JOHN SAMPLE', $sheet->getCell('E5')->getValue(), '은행용 PI 는 Name 칸만 송금인');
        $this->assertSame('Name', trim((string) $sheet->getCell('D5')->getValue()));
        // 금액 칸은 소수점 두 자리 서식 — 정수 서식(#,##0 뒤에 . 없음)이 하나도 남지 않는다(jin 11-26)
        $amountFormats = collect($sheet->getCellCollection()->getCoordinates())
            ->map(fn ($c) => (string) $sheet->getStyle($c)->getNumberFormat()->getFormatCode())
            ->filter(fn ($f) => str_contains($f, '#,##0'));
        $this->assertNotEmpty($amountFormats->all(), '금액 서식 칸이 있어야 한다');
        $this->assertSame([], $amountFormats->reject(fn ($f) => str_contains($f, '#,##0.00'))->unique()->values()->all(), '소수점 없는 금액 서식이 남았다');
        // 합계 3칸은 수식 — 차량 줄을 고치면 소수점까지 따라 바뀐다(jin 11-26). 2대 = 18·19행.
        $formulas = collect($sheet->getCellCollection()->getCoordinates())
            ->filter(fn ($c) => str_starts_with($c, 'E') && str_starts_with((string) $sheet->getCell($c)->getValue(), '='))
            ->map(fn ($c) => (string) $sheet->getCell($c)->getValue())->values();
        $this->assertSame('=SUM(E18:F19)', $formulas->first(), 'SUB TOTAL = 차량 줄 E:F 합');
        $this->assertCount(3, $formulas, 'SUB TOTAL · TOTAL · BALANCE MONEY');
        $sheet->getCell('E18')->setValue((float) $sheet->getCell('E18')->getValue() + 0.5);
        $subCoord = collect($sheet->getCellCollection()->getCoordinates())->first(fn ($c) => (string) $sheet->getCell($c)->getValue() === '=SUM(E18:F19)');
        $expected = (float) $sheet->getCell('E18')->getValue() + (float) $sheet->getCell('F18')->getValue() + (float) $sheet->getCell('E19')->getValue() + (float) $sheet->getCell('F19')->getValue();
        $this->assertEqualsWithDelta($expected, (float) $sheet->getCell($subCoord)->getCalculatedValue(), 0.001, '고친 줄의 소수점이 합계에 반영');
        @unlink($tmp);

        $t->call('sendBankMail')->assertHasNoErrors()->assertSet('bankOpen', false);
        Mail::assertSent(VehicleDocumentMail::class, fn ($m) => $m->hasTo('officer@bank.test') && count($m->storedFiles) === 2);
        $this->assertSame(2, MailDeliveryLog::where('to_email', 'officer@bank.test')->where('status', 'sent')->count(), '차마다 로그');
        $this->assertNotNull($rec->fresh()->mailed_at);

        // 서류탭 은행 영역 · 파일 받기
        $this->get(route('erp.bank-remittances.file', [$rec->id, 'invoice']))->assertOk();
        $this->actingAs(User::factory()->create(['permission' => 'user', 'role' => '영업', 'email_verified_at' => now()]))
            ->get(route('erp.bank-remittances.file', [$rec->id, 'remittance']))->assertForbidden();
    }

    public function test_recipient_list_from_settings_is_a_dropdown_and_only_registered_addresses_are_sent(): void
    {
        Mail::fake();
        $set = Setting::companyTemplateSet();
        Setting::updateOrCreate(['key' => "mail_channel_{$set}"], ['value' => 'ses', 'type' => 'string']);
        Setting::updateOrCreate(['key' => "mail_from_address_{$set}"], ['value' => 'ops@company.test', 'type' => 'string']);

        // 기능설정 — 은행·이름·메일 줄 목록. 빈 줄은 버리고, 잘못된 주소·중복은 막는다.
        $this->actingAs(User::factory()->create(['permission' => 'super', 'email_verified_at' => now()]));
        Volt::test('admin.settings')
            ->set('bankRecipients', [['bank' => '신한은행', 'name' => '김신한', 'email' => 'not-mail']])
            ->call('saveBankMailTo')->assertHasErrors('bankRecipients')
            ->set('bankRecipients', [['bank' => '', 'name' => '', 'email' => 'a@shinhan.test'], ['bank' => 'X', 'name' => '', 'email' => 'A@shinhan.test']])
            ->call('saveBankMailTo')->assertHasErrors('bankRecipients')
            ->set('bankRecipients', [
                ['bank' => '신한은행', 'name' => '김신한', 'email' => 'kim@shinhan.test'],
                ['bank' => '', 'name' => '', 'email' => ''],
                ['bank' => '기업은행', 'name' => '박기업', 'email' => 'park@ibk.test'],
            ])
            ->call('saveBankMailTo')->assertHasNoErrors()
            ->assertSet('bankRecipients', [
                ['bank' => '신한은행', 'name' => '김신한', 'email' => 'kim@shinhan.test'],
                ['bank' => '기업은행', 'name' => '박기업', 'email' => 'park@ibk.test'],
            ]);
        // settings.type 은 MySQL enum(boolean·string·integer) — SQLite 는 안 막는다(§8 #36, 로컬 저장 실패 11-24)
        $this->assertContains(Setting::where('key', "bank_mail_recipients_{$set}")->value('type'), ['boolean', 'string', 'integer']);
        $this->assertSame(['kim@shinhan.test' => '신한은행 · 김신한 <kim@shinhan.test>', 'park@ibk.test' => '기업은행 · 박기업 <park@ibk.test>'], BankRemittanceService::recipientOptions());

        [$a] = $this->cars(1);
        $this->actingAs($this->finance());
        $t = Volt::test('erp.vehicles.bank-remittance')
            ->call('openBank', [$a->id])
            ->set('bankFile', $this->textAdvice('JOHN DOE'))
            ->call('createBankRecord')
            ->assertSet('mailTo', '', '여럿이면 미리 고르지 않는다 — 엉뚱한 은행으로 안 가게')
            ->assertSee('data-bank-to', false)
            ->assertSee('data-bank-file', false);

        $t->set('mailTo', 'someone@else.test')->call('sendBankMail')->assertHasErrors('mailTo')->assertSet('bankOpen', true);
        Mail::assertNothingSent();

        $t->set('mailTo', 'park@ibk.test')->call('sendBankMail')->assertHasNoErrors()->assertSet('bankOpen', false);
        Mail::assertSent(VehicleDocumentMail::class, fn ($m) => $m->hasTo('park@ibk.test'));

        // 서류탭 기록 줄 — 은행·이름이 같이 보이고 파일은 버튼
        $html = Volt::test('erp.vehicles.index')->call('openEdit', $a->id)->html();
        $this->assertStringContainsString('기업은행 · 박기업', $html);
        $this->assertStringContainsString('data-bank-file', $html);
    }

    public function test_docs_tab_x_removes_record_from_that_vehicle_and_files_only_with_the_last_one(): void
    {
        [$a, $b] = $this->cars();
        $disk = Storage::disk(config('filesystems.vehicle_docs_disk'));
        $disk->put('bank-remittances/t/advice.tif', 'II*');
        $disk->put('bank-remittances/t/BANK_pi.xlsx', 'PK');
        $rec = BankRemittance::create(['remittance_path' => 'bank-remittances/t/advice.tif', 'remittance_name' => 'advice.tif', 'remitter_name' => 'JOHN SAMPLE',
            'invoice_path' => 'bank-remittances/t/BANK_pi.xlsx', 'invoice_name' => 'BANK_pi.xlsx', 'mailed_to' => 'o@b.test', 'mailed_at' => now()]);
        $rec->vehicles()->attach([$a->id, $b->id]);
        Vehicle::whereKey([$a->id, $b->id])->update(['remitter_name' => 'JOHN SAMPLE']);

        // 권한 없는 사람(영업)은 못 지운다
        $sales = User::factory()->create(['permission' => 'user', 'role' => '영업', 'email_verified_at' => now()]);
        try {
            BankRemittanceService::removeFromVehicle($sales, $a, $rec->id);
            $this->fail('영업은 지울 수 없어야 한다');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->actingAs($this->finance());
        Volt::test('erp.vehicles.index')->call('openEdit', $a->id)
            ->assertSee('data-bank-remove', false)
            ->call('removeBankRemittance', $rec->id)
            ->assertDispatched('notify');

        $this->assertSame([$b->id], $rec->fresh()->vehicles()->pluck('vehicles.id')->all(), '다른 차에는 남는다');
        $this->assertTrue($disk->exists('bank-remittances/t/advice.tif'), '아직 다른 차가 쓰므로 파일은 남는다');
        $this->assertSame('JOHN SAMPLE', $a->fresh()->remitter_name, '기본정보 송금인은 그대로');
        $log = AuditLog::where('action', 'bank_remittance_removed')->where('auditable_id', $a->id)->firstOrFail();
        $this->assertStringContainsString('JOHN SAMPLE', (string) $log->old_value);

        Volt::test('erp.vehicles.index')->call('openEdit', $b->id)->call('removeBankRemittance', $rec->id);
        $this->assertNull(BankRemittance::find($rec->id), '마지막 차였으면 기록이 지워진다');
        $this->assertFalse($disk->exists('bank-remittances/t/advice.tif'));
        $this->assertFalse($disk->exists('bank-remittances/t/BANK_pi.xlsx'));
    }

    public function test_docs_tab_shows_bank_area_and_list_has_bulk_button(): void
    {
        [$a] = $this->cars(1);
        $this->actingAs($this->finance());
        $rec = BankRemittance::create(['remittance_path' => 'x.tif', 'remittance_name' => 'x.tif', 'remitter_name' => 'GOLDEN BRIDGE TRADING LLC', 'mailed_to' => 'o@b.test', 'mailed_at' => now()]);
        $rec->vehicles()->attach($a->id);

        $html = Volt::test('erp.vehicles.index')->call('openEdit', $a->id)->html();
        $this->assertStringContainsString('data-bank-area', $html);
        $this->assertStringContainsString('GOLDEN BRIDGE TRADING LLC', $html);
        $this->assertStringContainsString('data-bank-open', $html);
    }

    /** 안 보낸 기록 1건 — 전신문·은행용 PI 파일까지 디스크에 만든다. */
    private function pendingRecord(Vehicle $v, string $name, string $tag): BankRemittance
    {
        $disk = Storage::disk(config('filesystems.vehicle_docs_disk'));
        $disk->put("bank-remittances/t/{$tag}.tif", 'II*'.$tag);
        $disk->put("bank-remittances/t/BANK_{$tag}.xlsx", 'PK'.$tag);
        $rec = BankRemittance::create(['remittance_path' => "bank-remittances/t/{$tag}.tif", 'remittance_name' => "{$tag}.tif", 'remitter_name' => $name,
            'invoice_path' => "bank-remittances/t/BANK_{$tag}.xlsx", 'invoice_name' => "BANK_{$tag}.xlsx"]);
        $rec->vehicles()->attach($v->id);

        return $rec;
    }

    /** 화면이 합친 PDF 를 전용 주소로 올리고 표(token)를 받는다 — 실제 길 그대로. */
    private function uploadMerged(string $content = '%PDF-1.4 merged'): string
    {
        return $this->post(route('erp.bank-remittances.merged'), ['file' => UploadedFile::fake()->createWithContent('merged.pdf', $content)], ['Accept' => 'application/json'])
            ->assertOk()->json('token');
    }

    private function mailSettings(): void
    {
        $set = Setting::companyTemplateSet();
        Setting::updateOrCreate(['key' => "mail_channel_{$set}"], ['value' => 'ses', 'type' => 'string']);
        Setting::updateOrCreate(['key' => "mail_from_address_{$set}"], ['value' => 'ops@company.test', 'type' => 'string']);
        Setting::updateOrCreate(['key' => "bank_mail_to_{$set}"], ['value' => 'officer@bank.test', 'type' => 'string']);
    }

    // ✏️ 수정본 올리기 (jin 2026-11-26 «수정하고 나서 올리는 게 없었네») — 고친 파일이 은행으로 가는 파일이 된다.
    public function test_revised_invoice_replaces_the_file_that_is_downloaded_and_mailed(): void
    {
        [$a] = $this->cars(1);
        $rec = $this->pendingRecord($a, 'JOHN SAMPLE', 'one');
        $disk = Storage::disk(config('filesystems.vehicle_docs_disk'));
        $fin = $this->finance();
        $this->actingAs($fin);

        // 서류탭에 버튼이 있다
        $this->assertStringContainsString('data-bank-replace-open', Volt::test('erp.vehicles.index')->call('openEdit', $a->id)->html());

        // 엑셀·PDF 가 아니면 거절하고 원래 파일 그대로
        Volt::test('erp.vehicles.bank-remittance')->call('startReplace', $rec->id)->assertSet('replaceId', $rec->id)
            ->set('replaceFile', UploadedFile::fake()->createWithContent('memo.txt', 'x'))
            ->assertHasErrors('replaceFile');
        $this->assertSame('BANK_one.xlsx', $rec->fresh()->invoice_name);

        Volt::test('erp.vehicles.bank-remittance')->call('startReplace', $rec->id)
            ->set('replaceFile', UploadedFile::fake()->createWithContent('BANK_one_fixed.xlsx', 'PK-fixed'))
            ->assertHasNoErrors()->assertSet('replaceId', null)->assertDispatched('bank-remittance-done');

        $rec->refresh();
        $this->assertSame('BANK_one_fixed.xlsx', $rec->invoice_name);
        $this->assertSame('PK-fixed', $disk->get($rec->invoice_path));
        $this->assertFalse($disk->exists('bank-remittances/t/BANK_one.xlsx'), '먼저 있던 파일은 지운다');
        $this->assertNotNull($rec->invoice_revised_at);
        $this->assertSame($fin->id, $rec->invoice_revised_by);
        $log = AuditLog::where('action', 'bank_invoice_replaced')->where('auditable_id', $a->id)->firstOrFail();
        $this->assertSame(['BANK_one.xlsx', 'BANK_one_fixed.xlsx'], [$log->old_value, $log->new_value]);
        $html = Volt::test('erp.vehicles.index')->call('openEdit', $a->id)->html();
        $this->assertStringContainsString('data-bank-revised', $html);
        $this->assertMatchesRegularExpression('/data-bank-revised-name>BANK_one_fixed\.xlsx</', $html, '수정본 표시 옆에 올린 파일 이름');

        // 안 보낸 건이 있는 차로 또 만들려 하면 알려 준다(막지는 않는다) — 보낸 뒤에는 안 뜬다
        Volt::test('erp.vehicles.bank-remittance')->call('openBank', [$a->id])->assertSet('bankOpen', true)
            ->assertSee('data-bank-dup', false)->assertSee('송금인 JOHN SAMPLE');
        $rec->update(['mailed_to' => 'officer@bank.test', 'mailed_at' => now()]);
        Volt::test('erp.vehicles.bank-remittance')->call('openBank', [$a->id])->assertDontSee('data-bank-dup', false);

        // 권한 없는 사람(영업)은 못 바꾼다
        $this->actingAs(User::factory()->create(['permission' => 'user', 'role' => '영업', 'email_verified_at' => now()]));
        Volt::test('erp.vehicles.bank-remittance')->call('startReplace', $rec->id)->assertForbidden();
    }

    // 📤 모아 보내기 (jin 2026-11-26 «한 번에 모아서 보낸다 · 며칠 치일 수 있다») — 안 보낸 건을 메일 한 통으로.
    public function test_batch_sends_unsent_records_in_one_mail_with_numbered_files(): void
    {
        Mail::fake();
        $this->mailSettings();
        [$a, $b, $c] = $this->cars(3);
        $r1 = $this->pendingRecord($a, 'JOHN SAMPLE', 'one');
        $r2 = $this->pendingRecord($b, 'MARY SAMPLE', 'two');
        $r3 = $this->pendingRecord($c, 'THIRD SAMPLE', 'three');
        $sent = $this->pendingRecord($a, 'ALREADY SENT', 'old');
        $sent->update(['mailed_to' => 'officer@bank.test', 'mailed_at' => now()->subDay()]);
        BankRemittance::whereKey($r1->id)->update(['created_at' => now()->subDays(3)]);   // 며칠 치가 섞여도 된다
        $this->actingAs($this->finance());

        // 목록 버튼에 안 보낸 건수
        $html = Volt::test('erp.vehicles.index')->html();
        $this->assertStringContainsString('data-bank-batch-open', $html);
        $this->assertMatchesRegularExpression('/data-bank-batch-badge>3</', $html);

        $t = Volt::test('erp.vehicles.bank-remittance')->call('openBatch')
            ->assertSet('batchOpen', true)
            ->assertSet('batchPick', [$r1->id, $r2->id, $r3->id])   // 안 보낸 것만, 전부 체크된 채로
            ->assertSet('batchTo', 'officer@bank.test')
            ->assertSet('batchMode', 'merged')   // 기본 = PDF 한 파일로 합쳐 보내기(jin 11-27)
            ->set('batchMode', 'files')
            ->assertDontSee('ALREADY SENT')
            ->set('batchPick', [(string) $r1->id, (string) $r3->id])   // 2번은 뺀다
            ->assertSee('02. 송금인 THIRD SAMPLE');

        // 등록 안 된 주소로는 안 나간다
        $t->set('batchTo', 'someone@else.test')->call('sendBatchMail')->assertHasErrors('batchTo');
        Mail::assertNothingSent();

        $t->set('batchTo', 'officer@bank.test')->call('sendBatchMail')->assertHasNoErrors()->assertSet('batchOpen', false);

        Mail::assertSent(VehicleDocumentMail::class, 1);
        Mail::assertSent(VehicleDocumentMail::class, function ($m) {
            $this->assertSame(['01_one.tif', '01_BANK_one.xlsx', '02_three.tif', '02_BANK_three.xlsx'], array_column($m->storedFiles, 'name'));
            $this->assertStringContainsString('01. 송금인 JOHN SAMPLE — 차량 00가9511', $m->bodyText);
            $this->assertStringContainsString('02. 송금인 THIRD SAMPLE', $m->bodyText);
            $this->assertStringNotContainsString('MARY SAMPLE', $m->bodyText);

            return $m->hasTo('officer@bank.test');
        });
        $this->assertNotNull($r1->fresh()->mailed_at);
        $this->assertNotNull($r3->fresh()->mailed_at);
        $this->assertNull($r2->fresh()->mailed_at, '뺀 건은 안 보낸 채로 남는다');
        $this->assertSame(2, MailDeliveryLog::where('status', 'sent')->count(), '차마다 로그');
        $this->assertSame(['01_one.tif', '01_BANK_one.xlsx'], MailDeliveryLog::where('vehicle_id', $a->id)->firstOrFail()->document_names);
        $this->assertSame([$r2->id], BankRemittanceService::pending(auth()->user())->pluck('id')->all());

        // 아무것도 안 고르면 안 나간다
        Volt::test('erp.vehicles.bank-remittance')->call('openBatch')->set('batchMode', 'files')->set('batchPick', [])->call('sendBatchMail')->assertHasErrors('batchPick');
        Mail::assertSent(VehicleDocumentMail::class, 1);
    }

    // 📎 PDF 한 파일로 합쳐 보내기 (jin 2026-11-27 «싼카에서 PDF 합쳐진 파일처럼 · 두 가지 공존 · 내려받아 검사»)
    public function test_merged_mode_sends_one_pdf_keeps_it_and_requires_a_fresh_merge(): void
    {
        Mail::fake();
        $this->mailSettings();
        [$a, $b] = $this->cars();
        $r1 = $this->pendingRecord($a, 'JOHN SAMPLE', 'one');
        $r2 = $this->pendingRecord($b, 'MARY SAMPLE', 'two');
        $disk = Storage::disk(config('filesystems.vehicle_docs_disk'));
        $this->actingAs($this->finance());

        $t = Volt::test('erp.vehicles.bank-remittance')->call('openBatch')->assertSet('batchMode', 'merged')
            ->assertSee('data-bank-merge-btn', false);

        // 합치지 않고는 못 보낸다
        $t->call('sendBatchMail')->assertHasErrors('mergedFile');
        Mail::assertNothingSent();

        // 화면이 합친 PDF 를 올리고 「이 목록으로 만들었다」를 적는다 — 그 목록 그대로일 때만 준비됨
        $t->call('setMerged', $this->uploadMerged(), $t->viewData('batchKey'))->assertHasNoErrors();
        $this->assertTrue($t->viewData('mergedReady'));

        // 합친 뒤 건을 빼면 다시 합쳐야 한다
        $t->set('batchPick', [(string) $r1->id]);
        $this->assertFalse($t->viewData('mergedReady'));
        $t->call('sendBatchMail')->assertHasErrors('mergedFile');
        Mail::assertNothingSent();

        // 합친 뒤 수정본을 올려도 다시 합쳐야 한다(파일이 바뀌었다)
        $t->set('batchPick', [(string) $r1->id, (string) $r2->id]);
        $this->assertTrue($t->viewData('mergedReady'));
        BankRemittanceService::replaceInvoice($r2->fresh(), auth()->user(), UploadedFile::fake()->createWithContent('BANK_two_fixed.xlsx', 'PK-fixed'));
        $t->call('sendBatchMail')->assertHasErrors('mergedFile');

        // PDF 가 아닌 것은 받지 않는다 · 없는 표는 무시된다 · 영업은 올릴 수 없다
        $this->post(route('erp.bank-remittances.merged'), ['file' => UploadedFile::fake()->createWithContent('merged.txt', 'x')], ['Accept' => 'application/json'])->assertStatus(422);
        $t->call('setMerged', (string) Str::uuid(), $t->viewData('batchKey'));
        $this->assertFalse($t->viewData('mergedReady'));
        $t->call('setMerged', '../../.env', $t->viewData('batchKey'));
        $this->assertFalse($t->viewData('mergedReady'));

        // 다시 합쳐 보낸다 — 첨부는 합친 PDF 하나
        $token = $this->uploadMerged();
        $t->call('setMerged', $token, $t->viewData('batchKey'))
            ->call('sendBatchMail')->assertHasNoErrors()->assertSet('batchOpen', false);
        $this->assertFileDoesNotExist(BankRemittanceService::mergedTmpPath($token), '보낸 뒤 임시 파일은 치운다');

        $name = BankRemittanceService::bundleName(2);
        Mail::assertSent(VehicleDocumentMail::class, 1);
        Mail::assertSent(VehicleDocumentMail::class, function ($m) use ($name) {
            $this->assertSame([$name], array_column($m->storedFiles, 'name'));
            $this->assertStringContainsString('01. 송금인 JOHN SAMPLE', $m->bodyText);
            $this->assertStringContainsString('02. 송금인 MARY SAMPLE', $m->bodyText);

            return $m->hasTo('officer@bank.test');
        });
        [$r1, $r2] = [$r1->fresh(), $r2->fresh()];
        $this->assertNotNull($r1->mailed_at);
        $this->assertSame($r1->sent_bundle_path, $r2->sent_bundle_path, '같이 보낸 건은 같은 합친 PDF 를 가리킨다');
        $this->assertSame('%PDF-1.4 merged', $disk->get($r1->sent_bundle_path), '은행에 간 파일을 보관한다');
        $this->assertSame([$name], MailDeliveryLog::where('vehicle_id', $a->id)->firstOrFail()->document_names);

        // 서류탭에서 보낸 합친 PDF 를 받는다
        $this->assertStringContainsString('data-bank-bundle', Volt::test('erp.vehicles.index')->call('openEdit', $a->id)->html());
        $this->get(route('erp.bank-remittances.file', [$r1->id, 'bundle']))->assertOk();

        // 한 건을 지워도 다른 건이 쓰는 합친 PDF 는 남고, 마지막 건을 지우면 같이 지워진다
        $bundle = $r1->sent_bundle_path;
        BankRemittanceService::removeFromVehicle(auth()->user(), $a, $r1->id);
        $this->assertTrue($disk->exists($bundle));
        BankRemittanceService::removeFromVehicle(auth()->user(), $b, $r2->id);
        $this->assertFalse($disk->exists($bundle));
    }

    // 🪟 합치는 동안 창을 닫아도 이어진다 (jin 2026-11-27 «닫기 누르면 백그라운드, 취소 버튼»)
    public function test_closing_the_window_keeps_the_merge_and_cancel_clears_it(): void
    {
        $this->mailSettings();
        [$a, $b] = $this->cars();
        $r1 = $this->pendingRecord($a, 'JOHN SAMPLE', 'one');
        $r2 = $this->pendingRecord($b, 'MARY SAMPLE', 'two');
        $this->actingAs($this->finance());

        // 한 건만 고르고 합치기 시작 → 창 닫기 → 다시 열어도 고른 목록이 그대로다
        $t = Volt::test('erp.vehicles.bank-remittance')->call('openBatch')
            ->set('batchPick', [(string) $r2->id])
            ->call('mergeStarted')->assertSet('mergeBusy', true)
            ->call('closeBatch')->assertSet('batchOpen', false)
            ->assertSee('data-bank-merge-chip', false)
            ->call('openBatch')->assertSet('batchOpen', true)->assertSet('batchPick', [(string) $r2->id]);

        // 다 합쳐 올린 뒤 닫았다 열어도 「합침」 그대로
        $token = $this->uploadMerged();
        $t->call('setMerged', $token, $t->viewData('batchKey'))->assertSet('mergeBusy', false)
            ->call('closeBatch')->call('openBatch')->assertSet('batchPick', [(string) $r2->id]);
        $this->assertTrue($t->viewData('mergedReady'));

        // 취소(또는 실패)하면 합친 것이 없고, 다음에 열면 처음처럼 전부 체크된다
        $t->call('mergeStarted')->call('mergeStopped')->assertSet('mergeBusy', false)->assertSet('mergedKey', '');
        $this->assertFalse($t->viewData('mergedReady'));
        $this->assertFileDoesNotExist(BankRemittanceService::mergedTmpPath($token), '취소하면 임시 파일도 치운다');
        $t->call('closeBatch')->call('openBatch')->assertSet('batchPick', [$r1->id, $r2->id]);
    }

    public function test_merge_part_route_gives_the_proforma_as_pdf(): void
    {
        [$a] = $this->cars(1);
        $rec = $this->pendingRecord($a, 'JOHN SAMPLE', 'one');
        $this->actingAs($this->finance());

        // 엑셀 Proforma → 그 자리에서 PDF 로 (테스트 변환기 = 가짜 PDF)
        $res = $this->get(route('erp.bank-remittances.merge-part', [$rec->id, 'invoice-pdf']))->assertOk();
        $this->assertStringStartsWith('%PDF-1.4 converted-from-xlsx:PK', $res->streamedContent());

        // 수정본을 PDF 로 올렸으면 변환 없이 그대로
        BankRemittanceService::replaceInvoice($rec, auth()->user(), UploadedFile::fake()->createWithContent('fixed.pdf', '%PDF-1.4 by-hand'));
        $this->assertSame('%PDF-1.4 by-hand', $this->get(route('erp.bank-remittances.merge-part', [$rec->id, 'invoice-pdf']))->assertOk()->streamedContent());

        // 전신문 원본도 같은 길로 받는다 · 권한 없는 사람은 못 받는다
        $this->get(route('erp.bank-remittances.merge-part', [$rec->id, 'remittance']))->assertOk();
        $this->actingAs(User::factory()->create(['permission' => 'user', 'role' => '영업', 'email_verified_at' => now()]))
            ->get(route('erp.bank-remittances.merge-part', [$rec->id, 'invoice-pdf']))->assertForbidden();
    }

    public function test_batch_lists_only_records_the_user_can_see_and_blocks_others(): void
    {
        [$a] = $this->cars(1);
        $this->pendingRecord($a, 'JOHN SAMPLE', 'one');

        // 관리 — 담당 영업이 없으면 그 차를 못 본다 → 목록이 빈다
        $mgr = User::factory()->create(['permission' => 'user', 'role' => '관리', 'email_verified_at' => now()]);
        $this->assertCount(0, BankRemittanceService::pending($mgr));
        $this->assertCount(1, BankRemittanceService::pending($this->finance()));

        // 영업 — 열 수 없다
        $this->actingAs(User::factory()->create(['permission' => 'user', 'role' => '영업', 'email_verified_at' => now()]));
        Volt::test('erp.vehicles.bank-remittance')->call('openBatch')->assertForbidden();
    }

    public function test_batch_refuses_when_attachments_are_too_large(): void
    {
        Mail::fake();
        $this->mailSettings();
        [$a] = $this->cars(1);
        $rec = $this->pendingRecord($a, 'JOHN SAMPLE', 'one');
        Storage::disk(config('filesystems.vehicle_docs_disk'))->put($rec->remittance_path, str_repeat('x', BankRemittanceService::BATCH_MAX_BYTES + 1));

        $this->expectExceptionMessage('too_large');
        BankRemittanceService::sendBatch(collect([$rec]), $this->finance(), 'officer@bank.test', 's', 'b');
    }

    public function test_tif_waits_for_browser_pages(): void
    {
        $this->fakeOcr([
            'prefix' => ['P1' => '{"remitter": "JOHN SA**"}'],
            'name' => ['P3' => '{"debtor_name": "JOHN SAMPLE"}'],
        ]);
        [$a] = $this->cars(1);
        $this->actingAs($this->finance());
        $png = function (string $tag) {
            $im = imagecreatetruecolor(20, 20);
            imagestring($im, 1, 1, 1, $tag, imagecolorallocate($im, 255, 255, 255));
            ob_start();
            imagepng($im);

            return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
        };

        // 가짜 클라이언트는 쪽 표식을 base64 디코드로 찾으므로, GD 로 다시 그린 그림 대신 순서로 답하게 바꾼다
        $this->app->instance('remitter-ocr.client', new class extends OllamaClient
        {
            private int $n = 0;

            public function __construct()
            {
                parent::__construct('http://fake', 1);
            }

            public function vision(string $model, string $prompt, string $imageBase64, int $numCtx): string
            {
                return str_contains($prompt, '송금의뢰인') ? '{"remitter": "JOHN SA**"}' : '{"debtor_name": "JOHN SAMPLE"}';
            }
        });

        Volt::test('erp.vehicles.bank-remittance')
            ->call('openBank', [$a->id])
            ->assertSee('data-bank-reading', false)     // 읽는 중 가림막(조작 막음)
            ->assertSee('data-bank-lightbox', false)    // 쪽 그림 보기 창(넘기기·확대)
            ->assertDontSee("toDataURL('image/jpeg'", false)   // 팩스 글씨가 뭉개지는 JPEG 로 보내지 않는다
            ->set('bankFile', UploadedFile::fake()->createWithContent('83202.tif', 'II*'))
            ->assertSet('ocrStatus', 'reading')
            ->call('readPages', [$png('1'), $png('2'), $png('3')])
            ->assertSet('ocrStatus', 'ok')
            ->assertSet('remitterInput', 'JOHN SAMPLE');
    }
}
