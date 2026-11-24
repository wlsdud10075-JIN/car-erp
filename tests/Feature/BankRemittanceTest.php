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
        // PDF 변환(LibreOffice)은 테스트에서 돌리지 않는다 — 실패 → xlsx 로 떨어지는 길을 탄다
        $this->app->instance(PdfConverter::class, new class extends PdfConverter
        {
            public function fromSpreadsheet(Spreadsheet $s): string
            {
                throw new \RuntimeException('no soffice in tests');
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
        $this->assertStringEndsWith('.xlsx', $rec->invoice_name, 'PDF 변환 실패 → xlsx');
        $disk = Storage::disk(config('filesystems.vehicle_docs_disk'));
        $tmp = tempnam(sys_get_temp_dir(), 'pi').'.xlsx';
        file_put_contents($tmp, $disk->get($rec->invoice_path));
        $sheet = IOFactory::load($tmp)->getSheetByName('Invoice');
        $this->assertSame('JOHN SAMPLE', $sheet->getCell('E5')->getValue(), '은행용 PI 는 Name 칸만 송금인');
        $this->assertSame('Name', trim((string) $sheet->getCell('D5')->getValue()));
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
