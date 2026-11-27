<?php

use App\Models\BankRemittance;
use App\Services\BankRemittanceService;
use App\Services\RemitterNameReader;
use App\Support\CompanyMailConfig;
use Livewire\Attributes\On;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

/**
 * 🏦 은행 서류 모달 (jin 2026-11-24) — 차량 패널 서류탭 [은행 서류](1대) · 목록 선택 [은행 서류 일괄](N대)이 같은 모달을 연다.
 *   전신문 올리기 → 송금인 이름 읽기(GPU PC — TIF 는 브라우저가 쪽 그림으로 바꿔 보낸다) → [은행용 Proforma 만들기]
 *   (이름을 기본정보 송금인 칸에 덮어쓰고 기록 저장) → 받는 사람·첨부 확인 → [은행으로 보내기].
 *   권한 = 재무 + 관리 이상(BankRemittanceService::canUse) + 차마다 canScopeVehicle.
 */
new class extends Component {
    use WithFileUploads;

    public bool $bankOpen = false;

    /** @var list<int> */
    public array $bankIds = [];

    public $bankFile = null;

    public string $remitterInput = '';

    public ?string $ocrStatus = null;


    public ?string $ocrRead = null;

    public ?int $recordId = null;

    public string $mailTo = '';

    public string $mailSubject = '';

    public string $mailBody = '';

    public string $bankError = '';

    // ── 📤 모아 보내기 (jin 2026-11-26) — 아직 안 보낸 기록을 골라 메일 한 통으로. 며칠 치가 섞여도 된다.
    public bool $batchOpen = false;

    /** @var list<int|string> 체크한 기록 id */
    public array $batchPick = [];

    public string $batchTo = '';

    public string $batchSubject = '';

    public string $batchBody = '';

    /** 보내는 방식 — merged = 전신문 → Proforma 순서로 PDF 한 파일(jin 2026-11-27) · files = 건마다 파일 따로 */
    public string $batchMode = 'merged';

    /** 화면(브라우저)이 합쳐 올린 PDF 의 표 — 파일은 서버 임시 폴더에 있다(BankRemittanceService::mergedTmpPath) */
    public string $mergedToken = '';

    /** 그 PDF 가 어떤 목록으로 만들어졌는지 — 합친 뒤 체크·수정본이 바뀌면 다시 합쳐야 한다 */
    public string $mergedKey = '';

    /** 화면이 지금 합치는 중 — 창을 닫았다 다시 열어도 고른 목록을 그대로 둔다(백그라운드 합치기, jin 2026-11-27) */
    public bool $mergeBusy = false;

    // ── ✏️ 수정본 올리기 (jin 2026-11-26) — 엑셀로 받아 고친 Proforma 를 그 기록에 바꿔 넣는다. 서류탭·모아 보내기 양쪽에서 연다.
    public ?int $replaceId = null;

    public $replaceFile = null;

    #[On('open-bank-batch')]
    public function openBatch(): void
    {
        abort_unless(BankRemittanceService::canUse(auth()->user()), 403);
        $this->resetErrorBag();
        // 합치는 중이거나, 합쳐 둔 PDF 가 지금 고른 목록 그대로면 — 다시 열 때 아무것도 지우지 않는다(닫아 두고 다른 일을 하다 돌아온 것).
        if ($this->mergeBusy || ($this->hasMerged() && $this->mergedKey !== '' && $this->mergedKey === $this->batchKey($this->batchRecords()))) {
            $this->reset(['replaceId', 'replaceFile']);
            $this->bankOpen = false;
            $this->batchOpen = true;

            return;
        }
        $this->dropMerged();
        $this->reset(['replaceId', 'replaceFile', 'batchMode']);
        $pending = BankRemittanceService::pending(auth()->user());
        $this->batchPick = $pending->take(BankRemittanceService::BATCH_MAX)->pluck('id')->all();
        $company = CompanyMailConfig::active();
        $list = BankRemittanceService::recipients();
        $this->batchTo = count($list) === 1 ? $list[0]['email'] : '';
        $this->batchSubject = __('vehicle.bank.batch_subject', ['company' => $company->companyLabel()]);
        $this->batchBody = __('vehicle.bank.batch_body', ['company' => $company->companyLabel()]);
        $this->bankOpen = false;
        $this->batchOpen = true;
    }

    public function closeBatch(): void
    {
        // 합친 PDF·고른 목록은 남긴다 — 합치는 동안 닫아 두고 다른 일을 할 수 있게(다시 열면 이어진다). 보내면 그때 비운다.
        $this->batchOpen = false;
        $this->reset(['replaceId', 'replaceFile']);
    }

    private function hasMerged(): bool
    {
        $path = BankRemittanceService::mergedTmpPath($this->mergedToken);

        return $path !== null && is_file($path);
    }

    /** 합쳐 둔 PDF 를 버린다(임시 파일까지). */
    private function dropMerged(): void
    {
        BankRemittanceService::forgetMergedTmp($this->mergedToken);
        $this->reset(['mergedToken', 'mergedKey']);
    }

    public function mergeStarted(): void
    {
        abort_unless(BankRemittanceService::canUse(auth()->user()), 403);
        $this->resetErrorBag('mergedFile');
        $this->dropMerged();
        $this->mergeBusy = true;
    }

    /** 취소했거나 실패했다 — 합친 것은 없다. */
    public function mergeStopped(): void
    {
        $this->mergeBusy = false;
        $this->dropMerged();
    }

    /** 합칠 목록의 지문 — 건 id 와 그 파일 경로(수정본을 올리면 경로가 바뀐다)를 순서대로. */
    private function batchKey($recs): string
    {
        return md5($recs->map(fn ($r) => $r->id.'|'.$r->remittance_path.'|'.$r->invoice_path)->implode(';'));
    }

    /** 화면이 합친 PDF 를 올린 뒤 부른다 — 받은 표와, 어떤 목록으로 만든 것인지 적어 둔다. */
    public function setMerged(string $token, string $key): void
    {
        abort_unless(BankRemittanceService::canUse(auth()->user()), 403);
        $this->resetErrorBag('mergedFile');
        $this->dropMerged();
        $this->mergeBusy = false;
        $path = BankRemittanceService::mergedTmpPath($token);
        if ($path && is_file($path)) {
            $this->mergedToken = $token;
            $this->mergedKey = $key;
        }
    }

    public function updatedBatchMode(): void
    {
        $this->batchMode = $this->batchMode === 'files' ? 'files' : 'merged';
        $this->resetErrorBag();
    }

    /** 체크한 것 중 지금도 「안 보냄 · 내가 볼 수 있음」인 기록만, 만든 순서로. */
    private function batchRecords()
    {
        $pick = array_map('intval', $this->batchPick);

        return BankRemittanceService::pending(auth()->user())->filter(fn ($r) => in_array($r->id, $pick, true))->values();
    }

    /** 본문 끝에 붙는 목록 — 화면 미리보기와 실제 메일이 같은 함수를 쓴다. */
    private function batchListText($recs): string
    {
        return $recs->values()->map(fn ($r, $i) => __('vehicle.bank.batch_line', [
            'no' => sprintf('%02d', $i + 1), 'name' => $r->remitter_name, 'cars' => $r->vehicles->pluck('vehicle_number')->implode(', '),
        ]))->implode("\n");
    }

    public function sendBatchMail(): void
    {
        abort_unless(BankRemittanceService::canUse(auth()->user()), 403);
        $this->resetErrorBag();
        $recs = $this->batchRecords();
        if ($recs->isEmpty()) {
            $this->addError('batchPick', __('vehicle.bank.batch_none'));

            return;
        }
        if ($recs->count() > BankRemittanceService::BATCH_MAX) {
            $this->addError('batchPick', __('vehicle.bank.batch_max', ['n' => BankRemittanceService::BATCH_MAX]));

            return;
        }
        $to = trim($this->batchTo);
        if (! array_key_exists($to, BankRemittanceService::recipientOptions())) {
            $this->addError('batchTo', __('vehicle.bank.pick_recipient'));

            return;
        }
        $merged = null;
        if ($this->batchMode === 'merged') {
            // 합친 PDF 가 「지금 체크한 목록 그대로」 만든 것일 때만 보낸다 — 합친 뒤 건을 빼거나 수정본을 올렸으면 다시 합친다.
            if (! $this->hasMerged() || $this->mergedKey !== $this->batchKey($recs)) {
                $this->addError('mergedFile', __('vehicle.bank.merge_needed'));

                return;
            }
            $merged = new \Illuminate\Http\UploadedFile(BankRemittanceService::mergedTmpPath($this->mergedToken), 'merged.pdf', 'application/pdf', null, true);
        }
        $body = rtrim($this->batchBody)."\n\n".$this->batchListText($recs);

        try {
            BankRemittanceService::sendBatch($recs, auth()->user(), $to, $this->batchSubject, $body, $merged);
        } catch (\DomainException $e) {
            $key = match ($e->getMessage()) {
                'too_large' => 'vehicle.bank.batch_too_large',
                'merged_invalid' => 'vehicle.bank.merge_needed',
                'file_missing' => 'vehicle.bank.batch_file_missing',
                'not_configured' => 'vehicle.mail.not_configured',
                default => 'vehicle.mail.send_failed',
            };
            $this->dispatch('notify', message: __($key), type: 'warning');

            return;
        } catch (\Throwable $e) {
            report($e);
            $this->dispatch('notify', message: __('vehicle.mail.send_failed'), type: 'error');

            return;
        }
        $this->batchOpen = false;
        $this->dropMerged();
        $this->reset(['mergeBusy']);
        $this->dispatch('bank-remittance-merge-cleared');
        $this->dispatch('bank-remittance-done', ids: $recs->flatMap(fn ($r) => $r->vehicles->pluck('id'))->unique()->values()->all());
        $this->dispatch('notify', message: __('vehicle.bank.batch_sent', ['n' => $recs->count(), 'to' => BankRemittanceService::recipientLabel($to)]), type: 'success');
    }

    #[On('open-bank-replace')]
    public function startReplace(int $id): void
    {
        $rec = BankRemittance::with('vehicles')->find($id);
        abort_unless($rec && BankRemittanceService::canTouch(auth()->user(), $rec), 403);
        $this->resetErrorBag('replaceFile');
        $this->replaceFile = null;
        $this->replaceId = $rec->id;
    }

    public function cancelReplace(): void
    {
        $this->reset(['replaceId', 'replaceFile']);
    }

    /** 파일을 고르는 순간 바꿔 넣는다 — 버튼을 한 번 더 누르게 하지 않는다. */
    public function updatedReplaceFile(): void
    {
        $rec = $this->replaceId ? BankRemittance::with('vehicles')->find($this->replaceId) : null;
        abort_unless($rec && BankRemittanceService::canTouch(auth()->user(), $rec), 403);
        $this->validate(['replaceFile' => ['required', 'file', 'max:'.\App\Models\Vehicle::DOCUMENT_MAX_KB]], [], ['replaceFile' => __('vehicle.bank.invoice')]);

        try {
            BankRemittanceService::replaceInvoice($rec, auth()->user(), $this->replaceFile);
        } catch (\DomainException) {
            $this->replaceFile = null;
            $this->addError('replaceFile', __('vehicle.bank.err_invoice_ext'));

            return;
        } catch (\Throwable $e) {
            report($e);
            $this->replaceFile = null;
            $this->dispatch('notify', message: __('vehicle.bank.create_failed'), type: 'error');

            return;
        }
        $this->reset(['replaceId', 'replaceFile']);
        $this->dispatch('bank-remittance-done', ids: $rec->vehicles->pluck('id')->all());
        $this->dispatch('notify', message: __('vehicle.bank.replaced'), type: 'success');
    }

    #[On('open-bank-remittance')]
    public function openBank(array $ids = []): void
    {
        $this->resetErrorBag();
        $this->reset(['bankFile', 'remitterInput', 'ocrStatus', 'ocrRead', 'recordId', 'mailTo', 'mailSubject', 'mailBody', 'bankError']);
        $vehicles = BankRemittanceService::vehiclesFor(auth()->user(), $ids);
        if (is_string($vehicles)) {
            $this->dispatch('notify', message: __($vehicles), type: 'warning');

            return;
        }
        $this->bankIds = $vehicles->pluck('id')->all();
        $this->bankOpen = true;
    }

    public function closeBank(): void
    {
        // 만들고 안 보낸 채 닫으면 「은행 서류 보내기」 목록에 남는다 — 어디로 갔는지 말해 준다(jin 11-26 «연결 고리가 있어야»).
        if ($this->bankOpen && $this->recordId && BankRemittance::whereKey($this->recordId)->whereNull('mailed_at')->exists()) {
            $this->dispatch('notify', message: __('vehicle.bank.queued'), type: 'info');
        }
        $this->bankOpen = false;
    }

    /** @return \Illuminate\Support\Collection<int, \App\Models\Vehicle> */
    private function bankVehicles()
    {
        $v = BankRemittanceService::vehiclesFor(auth()->user(), $this->bankIds);
        abort_if(is_string($v), 403, __((string) $v));

        return $v;
    }

    private function fileExt(): string
    {
        return $this->bankFile ? strtolower($this->bankFile->getClientOriginalExtension()) : '';
    }

    /** 올리는 순간 — PDF·그림은 서버가 바로 읽는다. TIF 는 브라우저가 쪽 그림을 만들어 readPages 를 부른다. */
    public function updatedBankFile(): void
    {
        $this->bankVehicles();
        $this->reset(['ocrStatus', 'ocrRead']);
        $this->validate(['bankFile' => ['required', 'file', 'max:'.\App\Models\Vehicle::DOCUMENT_MAX_KB, function ($a, $v, $fail) {
            if (! in_array($this->fileExt(), BankRemittanceService::ACCEPT, true)) {
                $fail(__('vehicle.bank.err_ext'));
            }
        }]]);
        $ext = $this->fileExt();
        if (in_array($ext, ['tif', 'tiff'], true)) {
            $this->ocrStatus = 'reading';   // 브라우저가 이어서 readPages 를 부른다

            return;
        }
        $bytes = (string) file_get_contents($this->bankFile->getRealPath());
        if ($ext === 'pdf') {
            $max = (int) config('assistant.remitter_ocr.max_pages', 6);
            $p = RemitterNameReader::pdfPages($bytes, $max);
            if ($name = RemitterNameReader::fromText($p['text'])) {
                $this->applyOcr(['name' => $name, 'status' => 'text']);

                return;
            }
            $pages = array_map(fn ($b) => RemitterNameReader::normalizeImage($b), $p['images']);
        } else {
            $pages = [RemitterNameReader::normalizeImage($bytes)];
        }
        $this->applyOcr(array_filter($pages) === [] ? ['name' => null, 'status' => 'none'] : (new RemitterNameReader)->read($pages));
    }

    /** TIF — 브라우저(UTIF)가 만든 쪽 PNG(base64). 서버는 GD 로 다시 그려서만 보낸다. */
    public function readPages(array $pages): void
    {
        $this->bankVehicles();
        if (! $this->bankFile || ! in_array($this->fileExt(), ['tif', 'tiff'], true)) {
            return;
        }
        $max = (int) config('assistant.remitter_ocr.max_pages', 6);
        // 키 = 쪽 번호(0부터). Livewire 요청 한도(1MB) 때문에 브라우저가 일부 쪽만 보낼 수 있다 — 빠진 쪽은 null.
        $norm = array_fill(0, $max, null);
        foreach ($pages as $i => $b64) {
            if (! is_numeric($i) || (int) $i < 0 || (int) $i >= $max || ! is_string($b64)) {
                continue;
            }
            $bin = base64_decode(preg_replace('#^data:image/\w+;base64,#', '', $b64) ?? '', true);
            $norm[(int) $i] = $bin ? RemitterNameReader::normalizeImage($bin) : null;
        }
        while ($norm !== [] && end($norm) === null) {
            array_pop($norm);
        }
        $this->applyOcr(array_filter($norm) === [] ? ['name' => null, 'status' => 'none'] : (new RemitterNameReader)->read($norm));
    }

    public function tifUnreadable(): void
    {
        $this->ocrStatus = 'none';
    }

    private function applyOcr(array $r): void
    {
        $this->ocrStatus = $r['status'];
        $this->ocrRead = $r['name'] ?? null;
        if ($r['name']) {
            $this->remitterInput = $r['name'];
        }
    }

    /** 이름 확정 → 기본정보 송금인 덮어쓰기 + 기록 + 은행용 Proforma. */
    public function createBankRecord(): void
    {
        $vehicles = $this->bankVehicles();
        $this->remitterInput = trim(preg_replace('/\s+/u', ' ', $this->remitterInput) ?? '');
        $this->validate([
            'bankFile' => ['required', 'file'],
            'remitterInput' => ['required', 'string', 'max:100'],
        ], [], ['bankFile' => __('vehicle.bank.file'), 'remitterInput' => __('vehicle.bank.name')]);
        if (! in_array($this->fileExt(), BankRemittanceService::ACCEPT, true)) {
            $this->addError('bankFile', __('vehicle.bank.err_ext'));

            return;
        }

        try {
            $rec = BankRemittanceService::create(auth()->user(), $vehicles, $this->bankFile, $this->remitterInput, ['name' => $this->ocrRead, 'status' => $this->ocrStatus]);
        } catch (\Throwable $e) {
            report($e);
            $this->dispatch('notify', message: __('vehicle.bank.create_failed'), type: 'error');

            return;
        }

        $this->recordId = $rec->id;
        $company = CompanyMailConfig::active();
        // 받는 사람이 한 명만 등록돼 있으면 미리 고른다. 여럿이면 사람이 고른다(엉뚱한 은행으로 안 가게).
        $list = BankRemittanceService::recipients();
        $this->mailTo = count($list) === 1 ? $list[0]['email'] : '';
        $this->mailSubject = __('vehicle.bank.mail_subject', ['company' => $company->companyLabel(), 'name' => $rec->remitter_name]);
        $this->mailBody = __('vehicle.bank.mail_body', ['company' => $company->companyLabel(), 'name' => $rec->remitter_name, 'cars' => $vehicles->pluck('vehicle_number')->implode(', ')]);
        $this->dispatch('bank-remittance-done', ids: $this->bankIds, name: $rec->remitter_name);
        $this->dispatch('notify', message: __('vehicle.bank.created'), type: 'success');
    }

    public function sendBankMail(): void
    {
        $this->bankVehicles();
        $rec = BankRemittance::find($this->recordId);
        abort_unless($rec && $rec->vehicles()->whereIn('vehicles.id', $this->bankIds)->exists(), 404);
        $this->resetErrorBag('mailTo');
        $to = trim($this->mailTo);
        // 기능설정에 등록된 사람에게만 보낸다(드롭박스 선택 — 화면 밖 값은 서버가 거른다).
        if (! array_key_exists($to, BankRemittanceService::recipientOptions())) {
            $this->addError('mailTo', __('vehicle.bank.pick_recipient'));

            return;
        }

        try {
            BankRemittanceService::send($rec, auth()->user(), $to, $this->mailSubject, $this->mailBody);
        } catch (\DomainException) {
            $this->dispatch('notify', message: __('vehicle.mail.not_configured'), type: 'warning');

            return;
        } catch (\Throwable $e) {
            report($e);
            $this->dispatch('notify', message: __('vehicle.mail.send_failed'), type: 'error');

            return;
        }
        $this->bankOpen = false;
        $this->dispatch('bank-remittance-done', ids: $this->bankIds, name: $rec->remitter_name);
        $this->dispatch('notify', message: __('vehicle.bank.sent', ['to' => BankRemittanceService::recipientLabel($to)]), type: 'success');
    }

    public function with(): array
    {
        $pending = ($this->batchOpen || $this->mergeBusy || $this->mergedToken !== '') ? BankRemittanceService::pending(auth()->user()) : collect();
        $pick = array_map('intval', $this->batchPick);
        $picked = $pending->filter(fn ($r) => in_array($r->id, $pick, true))->values();
        $batch = [
            'pending' => $pending,
            'pickedCount' => $picked->count(),
            'batchList' => $this->batchListText($picked),
            'batchKey' => $this->batchKey($picked),
            'mergedReady' => $this->hasMerged() && $picked->isNotEmpty() && $this->mergedKey === $this->batchKey($picked),
            'mergeItems' => $picked->map(fn ($r) => [
                'label' => $r->remitter_name,
                'rem' => route('erp.bank-remittances.merge-part', [$r->id, 'remittance']),
                'remExt' => strtolower(pathinfo((string) $r->remittance_path, PATHINFO_EXTENSION)),
                'inv' => $r->invoice_path ? route('erp.bank-remittances.merge-part', [$r->id, 'invoice-pdf']) : null,
            ])->all(),
            'bundleName' => BankRemittanceService::bundleName($picked->count()),
            'replaceRec' => $this->replaceId ? BankRemittance::find($this->replaceId) : null,
        ];
        if (! $this->bankOpen) {
            return $batch + ['dups' => collect(), 'cars' => collect(), 'rec' => null, 'mailReady' => $this->batchOpen && CompanyMailConfig::active()->isConfigured()];
        }

        // 고른 차에 이어진 「안 보낸」 기록 — 같은 송금으로 두 번 만드는 것을 알아채게(막지는 않는다).
        $dups = $this->recordId ? collect() : BankRemittance::with('vehicles:id,vehicle_number')->whereNull('mailed_at')
            ->whereHas('vehicles', fn ($q) => $q->whereIn('vehicles.id', $this->bankIds))->orderBy('id')->get();

        return $batch + [
            'dups' => $dups,
            'cars' => \App\Models\Vehicle::whereIn('id', $this->bankIds)->get(['id', 'vehicle_number', 'buyer_id', 'currency', 'remitter_name']),
            'rec' => $this->recordId ? BankRemittance::find($this->recordId) : null,
            'mailReady' => CompanyMailConfig::active()->isConfigured(),
        ];
    }
}; ?>

<div>
@if($bankOpen)
<div class="fixed inset-0 z-[110] flex items-center justify-center bg-black/50 p-3" data-bank-modal
     x-data="bankRemit()" @keydown.escape.window="onEscape()"
     @keydown.arrow-left.window="lbStep(-1)" @keydown.arrow-right.window="lbStep(1)"
     @mousemove.window="lbDragMove($event)" @mouseup.window="lbDragEnd()">
    <div class="card w-full max-w-2xl shadow-2xl max-h-[90vh] overflow-y-auto" @click.stop>
        <div class="flex items-start justify-between gap-3">
            <div>
                <h3 class="text-base font-semibold text-gray-900">🏦 {{ __('vehicle.bank.title') }}</h3>
                <p class="mt-0.5 text-xs text-gray-500">{{ __('vehicle.bank.subtitle') }}</p>
            </div>
            <button type="button" wire:click="closeBank" class="text-gray-400 hover:text-gray-700">&times;</button>
        </div>

        <div class="mt-3 flex flex-wrap gap-1.5" data-bank-cars>
            @foreach($cars as $c)
                <span class="rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-medium text-indigo-800">{{ $c->vehicle_number }}</span>
            @endforeach
            <span class="text-xs text-gray-400">{{ __('vehicle.bank.cars', ['n' => $cars->count()]) }}</span>
        </div>

        @if(! $rec)
        @if($dups->isNotEmpty())
            {{-- ⚠️ 이 차에 안 보낸 은행 서류가 이미 있다 (jin 2026-11-26) — 막지는 않는다(계약금·잔금처럼 송금이 여러 번일 수 있다). --}}
            <div class="mt-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800" data-bank-dup>
                <div class="font-semibold">{{ __('vehicle.bank.dup_title') }}</div>
                <ul class="mt-1 space-y-0.5">
                    @foreach($dups as $d)
                        <li>· {{ __('vehicle.bank.dup_line', ['name' => $d->remitter_name, 'cars' => $d->vehicles->pluck('vehicle_number')->implode(', '), 'at' => $d->created_at?->format('m-d H:i')]) }}</li>
                    @endforeach
                </ul>
                <div class="mt-1">{{ __('vehicle.bank.dup_hint') }}</div>
            </div>
        @endif
        {{-- ① 전신문 --}}
        <div class="mt-4">
            <div class="mb-1 text-sm font-medium text-gray-700">① {{ __('vehicle.bank.step_file') }}</div>
            <div @change="pick($event)">
                <x-erp.file-drop model="bankFile" accept=".tif,.tiff,.pdf,.png,.jpg,.jpeg" :label="__('vehicle.bank.file_label')">
                    @if($bankFile)
                        <p class="mt-1 break-all text-xs text-gray-700">📄 {{ $bankFile->getClientOriginalName() }}</p>
                    @endif
                </x-erp.file-drop>
            </div>
            @error('bankFile')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
        </div>

        {{-- ② 송금인 이름 --}}
        <div class="mt-4">
            <div class="mb-1 text-sm font-medium text-gray-700">② {{ __('vehicle.bank.step_name') }}</div>
            @if($ocrStatus && $ocrStatus !== 'reading')
                @php
                    $tone = match ($ocrStatus) { 'ok', 'text' => 'border-green-200 bg-green-50 text-green-800', default => 'border-gray-200 bg-gray-50 text-gray-700' };
                @endphp
                <div class="mb-2 rounded-md border px-3 py-2 text-xs {{ $tone }}" data-bank-ocr="{{ $ocrStatus }}">
                    {{ __('vehicle.bank.ocr_'.$ocrStatus) }}
                </div>
            @endif
            <input type="text" wire:model="remitterInput" maxlength="100" class="input-base w-full font-mono uppercase" placeholder="{{ __('vehicle.bank.name_ph') }}" data-bank-name>
            <p class="mt-1 text-[11px] text-gray-500">{{ __('vehicle.bank.name_hint') }}</p>
            @error('remitterInput')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
            <template x-if="previews.length">
                <div class="mt-2" data-bank-previews>
                    <p class="mb-1 text-[11px] text-gray-500">{{ __('vehicle.bank.preview_hint') }}</p>
                    <div class="grid grid-cols-3 gap-2">
                        <template x-for="(src, i) in previews" :key="i">
                            <button type="button" @click="lbOpen(i)" class="relative block overflow-hidden rounded border border-gray-200 hover:border-primary">
                                <img :src="src" class="w-full" alt="">
                                <span class="absolute bottom-1 right-1 rounded bg-black/60 px-1.5 py-0.5 text-[10px] text-white" x-text="(i + 1) + @js(__('vehicle.bank.page_suffix'))"></span>
                            </button>
                        </template>
                    </div>
                </div>
            </template>
        </div>

        <div class="mt-4 flex justify-end gap-2">
            <button type="button" wire:click="closeBank" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-50">{{ __('common.close') }}</button>
            <button type="button" wire:click="createBankRecord" wire:loading.attr="disabled" wire:target="createBankRecord,bankFile,readPages"
                    class="rounded-lg bg-primary px-4 py-1.5 text-sm font-semibold text-white hover:opacity-90 disabled:opacity-50" data-bank-create>
                {{ __('vehicle.bank.create_btn') }}
            </button>
        </div>
        <p wire:loading wire:target="createBankRecord" class="mt-2 text-right text-xs text-sky-700">{{ __('vehicle.bank.creating') }}</p>
        @else
        {{-- ③ 만든 것 + 메일 --}}
        <div class="mt-4 rounded-md border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-800" data-bank-done>
            {{ __('vehicle.bank.done_line', ['name' => $rec->remitter_name]) }}
            <div class="mt-2 flex flex-wrap gap-2 text-xs">
                {{-- 파일은 버튼으로 — 작은 밑줄 글씨는 「눌러서 받는 것」인지 안 보였다(jin 11-24) --}}
                <a href="{{ route('erp.bank-remittances.file', [$rec->id, 'remittance']) }}" class="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-2.5 py-1.5 font-medium text-gray-700 shadow-sm hover:bg-gray-50" data-bank-file>
                    📄 {{ __('vehicle.bank.remittance') }} <span class="text-gray-400">{{ $rec->remittance_name }}</span> <span class="text-primary-text">⬇ {{ __('vehicle.bank.download') }}</span>
                </a>
                @if($rec->invoice_path)
                    <a href="{{ route('erp.bank-remittances.file', [$rec->id, 'invoice']) }}" class="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-2.5 py-1.5 font-medium text-gray-700 shadow-sm hover:bg-gray-50" data-bank-invoice data-bank-file>
                        📄 {{ __('vehicle.bank.invoice') }} <span class="text-gray-400">{{ $rec->invoice_name }}</span> <span class="text-primary-text">⬇ {{ __('vehicle.bank.download') }}</span>
                    </a>
                @endif
            </div>
        </div>

        <div class="mt-4 space-y-2">
            <div class="text-sm font-medium text-gray-700">③ {{ __('vehicle.bank.step_mail') }}</div>
            @unless($mailReady)
                <p class="rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-700">{{ __('vehicle.mail.not_configured') }}</p>
            @endunless
            <label class="block text-xs text-gray-500">{{ __('vehicle.bank.mail_to') }}</label>
            @php $bankTo = \App\Services\BankRemittanceService::recipientOptions(); @endphp
            <div data-bank-to>
                <x-erp.combobox model="mailTo" :options="[]" :extra="$bankTo" :selected="$mailTo"
                                :placeholder="__('vehicle.bank.pick_recipient')" wire:key="bank-to-{{ $recordId }}-{{ $mailTo }}" class="w-full" />
            </div>
            @if($bankTo === [])<p class="text-[11px] text-amber-700">{{ __('vehicle.bank.no_recipient') }}</p>@endif
            @error('mailTo')<p class="text-xs text-red-500">{{ $message }}</p>@enderror
            <label class="block text-xs text-gray-500">{{ __('vehicle.bank.mail_subject_label') }}</label>
            <input type="text" wire:model="mailSubject" class="input-base w-full">
            <label class="block text-xs text-gray-500">{{ __('vehicle.bank.mail_body_label') }}</label>
            <textarea wire:model="mailBody" rows="5" class="input-base w-full"></textarea>
            <p class="text-[11px] text-gray-500">{{ __('vehicle.bank.attach_note') }}</p>
            <p class="rounded-md bg-sky-50 px-3 py-2 text-xs text-sky-800" data-bank-queue-hint>{{ __('vehicle.bank.queue_hint') }}</p>
        </div>

        <div class="mt-4 flex justify-end gap-2">
            <button type="button" wire:click="closeBank" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-50">{{ __('vehicle.bank.later') }}</button>
            <button type="button" wire:click="sendBankMail" wire:loading.attr="disabled" wire:confirm="{{ __('vehicle.bank.send_confirm', ['to' => \App\Services\BankRemittanceService::recipientLabel($mailTo)]) }}"
                    class="rounded-lg bg-primary px-4 py-1.5 text-sm font-semibold text-white hover:opacity-90 disabled:opacity-50" data-bank-send>
                ✉ {{ __('vehicle.bank.send_btn') }}
            </button>
        </div>
        @endif
    </div>

    {{-- ⏳ 읽는 중 가림막 — 멈춘 건지 헷갈리지 않게, 끝날 때까지 다른 조작을 막는다(jin 11-24).
         TIF = 브라우저 변환 + 읽기 전체(converting) · PDF·그림 = 서버가 올리면서 바로 읽는 동안(wire:loading) --}}
    <div x-show="converting" x-cloak class="fixed inset-0 z-[115] flex items-center justify-center bg-black/40" data-bank-reading>
        <div class="card flex items-center gap-3 px-5 py-4 shadow-2xl">
            <svg class="h-6 w-6 animate-spin text-primary" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25"/><path d="M22 12a10 10 0 0 1-10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
            <div>
                <div class="text-sm font-semibold text-gray-800">{{ __('vehicle.bank.reading_title') }}</div>
                <div class="text-xs text-gray-500">{{ __('vehicle.bank.reading_hint') }}</div>
            </div>
        </div>
    </div>
    <div wire:loading.flex wire:target="bankFile,readPages" class="fixed inset-0 z-[115] items-center justify-center bg-black/40">
        <div class="card flex items-center gap-3 px-5 py-4 shadow-2xl">
            <svg class="h-6 w-6 animate-spin text-primary" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25"/><path d="M22 12a10 10 0 0 1-10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
            <div>
                <div class="text-sm font-semibold text-gray-800">{{ __('vehicle.bank.reading_title') }}</div>
                <div class="text-xs text-gray-500">{{ __('vehicle.bank.reading_hint') }}</div>
            </div>
        </div>
    </div>

    {{-- 🔍 쪽 그림 보기 — 기본탭 사진 창처럼 좌우 넘기기(‹ › · ←→ · 밀기). 휠 = Ctrl 없이 바로 확대 · 누르면 확대 · 확대 중 끌기 · 더블클릭 = 원래 크기 --}}
    <div x-show="lb.open" x-cloak class="fixed inset-0 z-[120] flex flex-col bg-black/85" data-bank-lightbox @click.self="lbClose()">
        <div class="flex items-center justify-between px-4 py-3 text-white">
            <span class="text-sm"><span class="text-white/70" x-text="(lb.i + 1) + ' / ' + previews.length"></span><span class="ml-3 hidden text-xs text-white/60 sm:inline">{{ __('vehicle.bank.zoom_hint') }}</span></span>
            <div class="flex items-center gap-2">
                <button type="button" @click="lbZoomBy(1 / 1.3)" class="h-8 w-8 rounded-full bg-white/20 text-lg leading-none hover:bg-white/30">−</button>
                <span class="w-12 text-center text-xs" x-text="Math.round(lb.z * 100) + '%'"></span>
                <button type="button" @click="lbZoomBy(1.3)" class="h-8 w-8 rounded-full bg-white/20 text-lg leading-none hover:bg-white/30">+</button>
                <button type="button" @click="lbClose()" class="ml-2 rounded-full bg-white/20 px-2.5 py-0.5 text-lg leading-none hover:bg-white/30">×</button>
            </div>
        </div>
        <div class="relative flex flex-1 items-center justify-center overflow-hidden p-4" @click.self="lbClose()"
             @wheel.prevent="lbWheel($event)" @touchstart.passive="lbTouchStart($event)" @touchend="lbTouchEnd($event)">
            <button type="button" x-show="previews.length > 1" @click="lbStep(-1)" class="absolute left-2 top-1/2 z-10 h-11 w-11 -translate-y-1/2 rounded-full bg-white/20 text-2xl leading-none text-white hover:bg-white/35">&lsaquo;</button>
            <button type="button" x-show="previews.length > 1" @click="lbStep(1)" class="absolute right-2 top-1/2 z-10 h-11 w-11 -translate-y-1/2 rounded-full bg-white/20 text-2xl leading-none text-white hover:bg-white/35">&rsaquo;</button>
            <img :src="previews[lb.i]" alt="" class="max-h-full max-w-full select-none bg-white object-contain" draggable="false" data-bank-lb-img
                 @mousedown="lbDragStart($event)" @dblclick="lbReset()"
                 :style="'transform: translate(' + lb.x + 'px,' + lb.y + 'px) scale(' + lb.z + '); cursor: ' + (lb.z > 1 ? (lb.drag ? 'grabbing' : 'grab') : 'zoom-in')" />
        </div>
    </div>
</div>
@endif

{{-- 📤 모아 보내기 (jin 2026-11-26) — 아직 안 보낸 은행 서류를 골라 메일 한 통으로. 며칠 치가 섞여도 된다. --}}
@if($batchOpen)
<div class="fixed inset-0 z-[110] flex items-center justify-center bg-black/50 p-3" data-bank-batch
     x-data="bankBatch()" @keydown.escape.window="$wire.replaceId ? $wire.cancelReplace() : $wire.closeBatch()">
    <div class="card w-full max-w-3xl shadow-2xl max-h-[90vh] overflow-y-auto" @click.stop>
        <div class="flex items-start justify-between gap-3">
            <div>
                <h3 class="text-base font-semibold text-gray-900">📤 {{ __('vehicle.bank.batch_title') }}</h3>
                <p class="mt-0.5 text-xs text-gray-500">{{ __('vehicle.bank.batch_subtitle') }}</p>
            </div>
            <button type="button" wire:click="closeBatch" class="text-gray-400 hover:text-gray-700">&times;</button>
        </div>

        @if($pending->isEmpty())
            <p class="mt-6 rounded-md bg-gray-50 px-3 py-6 text-center text-sm text-gray-500" data-bank-batch-empty>{{ __('vehicle.bank.batch_empty') }}</p>
            <div class="mt-4 flex justify-end">
                <button type="button" wire:click="closeBatch" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-50">{{ __('common.close') }}</button>
            </div>
        @else
        <div class="mt-4">
            <div class="mb-1 flex flex-wrap items-center justify-between gap-2 text-sm font-medium text-gray-700">
                <span>① {{ __('vehicle.bank.batch_step_pick') }}</span>
                <span class="text-xs font-normal text-gray-500" data-bank-batch-count>{{ __('vehicle.bank.batch_count', ['n' => $pickedCount, 'total' => $pending->count()]) }}</span>
            </div>
            <div class="space-y-2">
                @foreach($pending as $p)
                    <div class="card-tight text-xs" wire:key="bank-batch-{{ $p->id }}" data-bank-batch-row>
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                            <label class="flex min-w-0 flex-1 cursor-pointer items-center gap-2">
                                <input type="checkbox" wire:model.live="batchPick" value="{{ $p->id }}" class="h-4 w-4 rounded border-gray-300">
                                <span class="font-semibold text-gray-800">{{ $p->remitter_name }}</span>
                                <span class="text-gray-500">{{ $p->vehicles->pluck('vehicle_number')->implode(', ') }}</span>
                            </label>
                            <span class="text-gray-400">{{ $p->created_at?->format('m-d H:i') }}@if($p->creator) · {{ $p->creator->name }}@endif</span>
                        </div>
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <a href="{{ route('erp.bank-remittances.file', [$p->id, 'remittance']) }}" class="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-2.5 py-1.5 font-medium text-gray-700 shadow-sm hover:bg-gray-50">
                                📄 {{ __('vehicle.bank.remittance') }} <span class="text-primary-text">⬇</span>
                            </a>
                            @if($p->invoice_path)
                                <a href="{{ route('erp.bank-remittances.file', [$p->id, 'invoice']) }}" class="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-2.5 py-1.5 font-medium text-gray-700 shadow-sm hover:bg-gray-50">
                                    📄 {{ __('vehicle.bank.invoice') }} <span class="text-primary-text">⬇</span>
                                </a>
                            @endif
                            <button type="button" wire:click="startReplace({{ $p->id }})" class="inline-flex items-center gap-1.5 rounded-md border border-amber-300 bg-amber-50 px-2.5 py-1.5 font-semibold text-amber-800 hover:bg-amber-100" data-bank-replace-open>
                                ✏️ {{ __('vehicle.bank.replace_btn') }}
                            </button>
                            @if($p->invoice_revised_at)
                                <span class="rounded-full bg-emerald-100 px-2 py-0.5 font-medium text-emerald-800" data-bank-revised>{{ __('vehicle.bank.revised', ['at' => $p->invoice_revised_at->format('m-d H:i')]) }}</span>
                            @endif
                        </div>
                        {{-- 수정본 파일 이름 — 버튼 줄 아래 작은 한 줄로 고정(jin 11-26 «중구난방» — 이름 길이에 따라 옆·아래로 오가던 것) --}}
                        @if($p->invoice_revised_at)
                            <p class="mt-1 break-all text-[11px] text-gray-500" data-bank-revised-name>{{ $p->invoice_name }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
            @error('batchPick')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
        </div>

        {{-- ② 보내는 방식 (jin 2026-11-27 «두 가지 공존») — 합친 PDF 한 파일 / 건마다 파일 따로 --}}
        <div class="mt-4" data-bank-batch-mode="{{ $batchMode }}">
            <div class="mb-1 text-sm font-medium text-gray-700">② {{ __('vehicle.bank.step_mode') }}</div>
            <div class="flex flex-wrap gap-x-5 gap-y-1 text-sm text-gray-700">
                <label class="flex cursor-pointer items-center gap-1.5"><input type="radio" wire:model.live="batchMode" value="merged" class="h-4 w-4"> {{ __('vehicle.bank.mode_merged') }}</label>
                <label class="flex cursor-pointer items-center gap-1.5"><input type="radio" wire:model.live="batchMode" value="files" class="h-4 w-4"> {{ __('vehicle.bank.mode_files') }}</label>
            </div>
            @if($batchMode === 'merged')
                <div class="mt-2 rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-xs text-gray-700" data-bank-merge>
                    <span x-ref="mergeData" class="hidden" data-key="{{ $batchKey }}" data-name="{{ $bundleName }}" data-upload="{{ route('erp.bank-remittances.merged') }}" data-csrf="{{ csrf_token() }}" data-items="{{ json_encode($mergeItems, JSON_UNESCAPED_UNICODE) }}"
                          data-msgs="{{ json_encode(['convert' => __('vehicle.bank.merge_err_convert'), 'fetch' => __('vehicle.bank.merge_err_fetch'), 'read' => __('vehicle.bank.merge_err_read'), 'upload' => __('vehicle.bank.merge_err_upload'), 'uploading' => __('vehicle.bank.merge_uploading'), 'remittance' => __('vehicle.bank.remittance'), 'invoice' => __('vehicle.bank.invoice'), 'finished' => __('vehicle.bank.merge_finished'), 'failed' => __('vehicle.bank.merge_failed')], JSON_UNESCAPED_UNICODE) }}"></span>
                    <p>{{ __('vehicle.bank.merge_hint') }}</p>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <button type="button" @click="mergeAll()" :disabled="$store.bankMerge.merging" @disabled($pickedCount === 0)
                                class="inline-flex items-center gap-1.5 rounded-md border border-amber-300 bg-amber-50 px-3 py-1.5 font-semibold text-amber-800 hover:bg-amber-100 disabled:opacity-50" data-bank-merge-btn>
                            📎 {{ $mergedReady ? __('vehicle.bank.merge_again') : __('vehicle.bank.merge_btn') }}
                        </button>
                        <button type="button" x-show="$store.bankMerge.merging" x-cloak @click="$store.bankMerge.stop()"
                                class="rounded-md border border-gray-300 bg-white px-2.5 py-1.5 font-medium text-gray-600 hover:bg-gray-50" data-bank-merge-cancel>
                            {{ __('vehicle.bank.merge_cancel') }}
                        </button>
                        <span x-show="$store.bankMerge.merging" x-cloak class="text-sky-700" data-bank-merge-progress>{{ __('vehicle.bank.merging') }} <span x-text="$store.bankMerge.step"></span> · {{ __('vehicle.bank.merge_bg_hint') }}</span>
                        @if($mergedReady)
                            <a x-show="$store.bankMerge.url && ! $store.bankMerge.merging" x-cloak :href="$store.bankMerge.url" download="{{ $bundleName }}"
                               class="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-2.5 py-1.5 font-medium text-gray-700 shadow-sm hover:bg-gray-50" data-bank-merge-download>
                                📄 {{ $bundleName }} <span class="text-primary-text">⬇ {{ __('vehicle.bank.merge_download') }}</span>
                            </a>
                            <span x-show="! $store.bankMerge.merging" class="rounded-full bg-emerald-100 px-2 py-0.5 font-medium text-emerald-800" data-bank-merge-ready>{{ __('vehicle.bank.merge_ready') }}<span x-show="$store.bankMerge.pages" x-text="' · ' + $store.bankMerge.pages + @js(__('vehicle.bank.merge_pages')) + ' · ' + $store.bankMerge.size"></span></span>
                        @endif
                    </div>
                    <p x-show="$store.bankMerge.error" x-cloak x-text="$store.bankMerge.error" class="mt-2 text-red-600" data-bank-merge-error></p>
                    @error('mergedFile')<p class="mt-2 text-red-600">{{ $message }}</p>@enderror
                </div>
            @endif
        </div>

        <div class="mt-4 space-y-2">
            <div class="text-sm font-medium text-gray-700">③ {{ __('vehicle.bank.step_mail') }}</div>
            @unless($mailReady)
                <p class="rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-700">{{ __('vehicle.mail.not_configured') }}</p>
            @endunless
            <label class="block text-xs text-gray-500">{{ __('vehicle.bank.mail_to') }}</label>
            @php $batchToOptions = \App\Services\BankRemittanceService::recipientOptions(); @endphp
            <div data-bank-batch-to>
                <x-erp.combobox model="batchTo" :options="[]" :extra="$batchToOptions" :selected="$batchTo"
                                :placeholder="__('vehicle.bank.pick_recipient')" wire:key="bank-batch-to-{{ $batchTo }}" class="w-full" />
            </div>
            @if($batchToOptions === [])<p class="text-[11px] text-amber-700">{{ __('vehicle.bank.no_recipient') }}</p>@endif
            @error('batchTo')<p class="text-xs text-red-500">{{ $message }}</p>@enderror
            <label class="block text-xs text-gray-500">{{ __('vehicle.bank.mail_subject_label') }}</label>
            <input type="text" wire:model="batchSubject" class="input-base w-full">
            <label class="block text-xs text-gray-500">{{ __('vehicle.bank.mail_body_label') }}</label>
            <textarea wire:model="batchBody" rows="3" class="input-base w-full"></textarea>
            <label class="block text-xs text-gray-500">{{ __($batchMode === 'merged' ? 'vehicle.bank.batch_list_label_merged' : 'vehicle.bank.batch_list_label') }}</label>
            <pre class="max-h-40 overflow-y-auto whitespace-pre-wrap rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-xs text-gray-700" data-bank-batch-list>{{ $batchList }}</pre>
            <p class="text-[11px] text-gray-500">{{ __($batchMode === 'merged' ? 'vehicle.bank.batch_attach_note_merged' : 'vehicle.bank.batch_attach_note', ['name' => $bundleName]) }}</p>
        </div>

        <div class="mt-4 flex justify-end gap-2">
            <button type="button" wire:click="closeBatch" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-50">{{ __('common.close') }}</button>
            <button type="button" wire:click="sendBatchMail" wire:loading.attr="disabled" wire:target="sendBatchMail"
                    wire:confirm="{{ __('vehicle.bank.batch_confirm', ['n' => $pickedCount, 'to' => \App\Services\BankRemittanceService::recipientLabel($batchTo)]) }}"
                    @disabled($pickedCount === 0 || ($batchMode === 'merged' && ! $mergedReady))
                    class="rounded-lg bg-primary px-4 py-1.5 text-sm font-semibold text-white hover:opacity-90 disabled:opacity-50" data-bank-batch-send>
                ✉ {{ __('vehicle.bank.batch_send_btn', ['n' => $pickedCount]) }}
            </button>
        </div>
        <p wire:loading wire:target="sendBatchMail" class="mt-2 text-right text-xs text-sky-700">{{ __('vehicle.bank.batch_sending') }}</p>
        @endif
    </div>
</div>
@endif

{{-- ✏️ 수정본 올리기 (jin 2026-11-26) — 엑셀로 받아 고친 Proforma 로 바꿔 넣는다. 서류탭 · 모아 보내기 양쪽에서 연다. --}}
@if($replaceRec)
<div class="fixed inset-0 z-[120] flex items-center justify-center bg-black/50 p-3" data-bank-replace
     x-data @keydown.escape.window="$wire.cancelReplace()">
    <div class="card w-full max-w-md shadow-2xl max-h-[90vh] overflow-y-auto" @click.stop>
        <div class="flex items-start justify-between gap-3">
            <div>
                <h3 class="text-base font-semibold text-gray-900">✏️ {{ __('vehicle.bank.replace_title') }}</h3>
                <p class="mt-0.5 text-xs text-gray-500">{{ __('vehicle.bank.replace_subtitle', ['name' => $replaceRec->remitter_name]) }}</p>
            </div>
            <button type="button" wire:click="cancelReplace" class="text-gray-400 hover:text-gray-700">&times;</button>
        </div>
        <p class="mt-3 break-all rounded-md bg-gray-50 px-3 py-2 text-xs text-gray-600">
            {{ __('vehicle.bank.replace_current') }} <span class="font-medium text-gray-800">{{ $replaceRec->invoice_name ?: '—' }}</span>
        </p>
        <div class="mt-3">
            <x-erp.file-drop model="replaceFile" accept=".xlsx,.xls,.pdf" :label="__('vehicle.bank.replace_label')" />
            @error('replaceFile')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
            <p class="mt-2 text-[11px] text-gray-500">{{ __('vehicle.bank.replace_hint') }}</p>
        </div>
        <p wire:loading wire:target="replaceFile" class="mt-2 text-xs text-sky-700">{{ __('vehicle.bank.replace_saving') }}</p>
        <div class="mt-4 flex justify-end">
            <button type="button" wire:click="cancelReplace" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-50">{{ __('common.close') }}</button>
        </div>
    </div>
</div>
@endif

{{-- 🪟 합치는 동안 창을 닫아 두면 화면 위 가운데에 뜨는 띠(아래는 페이지 번호와 겹쳤다 — jin 같은 날) (jin 2026-11-27 «닫기 누르면 백그라운드») — 열기 · 취소.
     🚫 이 띠 안에서는 $wire 를 쓰지 않는다 — 차량 목록이 다시 그려진 뒤 띠의 반응식이 $wire 를 찾다 「Component not found」 로 죽고
        그 예외가 진행 중이던 요청 처리까지 끊어 합치기가 「저장 중」에서 멈췄다(같은 날 실측). 창 열림 여부 = 저장소의 open. --}}
<div x-data x-cloak x-show="$store.bankMerge && ! $store.bankMerge.open && ($store.bankMerge.merging || $store.bankMerge.done)"
     style="position:fixed;left:50%;top:12px;transform:translateX(-50%);z-index:100" data-bank-merge-chip
     x-on:bank-remittance-merge-cleared.window="$store.bankMerge.done = false">
    <div class="flex items-center gap-2 rounded-full border border-sky-200 bg-white px-4 py-2 text-xs text-gray-700 shadow-lg">
        {{-- 🚫 <template x-if> 를 쓰지 않는다 — x-if 가 끼워 넣은 노드 때문에 다음 응답의 morph 가 요소를 갈아 끼우다
             「Component not found」 로 죽었다(호출 경로: swapElements → added → findComponentByEl, 같은 날 실측). x-show 로 자리만 숨긴다. --}}
        <span x-show="$store.bankMerge.merging" class="flex items-center gap-2">
                <svg class="h-4 w-4 animate-spin text-primary" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25"/><path d="M22 12a10 10 0 0 1-10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                <span>📎 {{ __('vehicle.bank.merge_chip_running') }} <b x-text="$store.bankMerge.step"></b></span>
                <button type="button" @click="$store.bankMerge.stop()" class="rounded-md border border-gray-300 px-2 py-0.5 text-gray-600 hover:bg-gray-50" data-bank-merge-chip-cancel>{{ __('vehicle.bank.merge_cancel') }}</button>
        </span>
        <span x-show="! $store.bankMerge.merging && $store.bankMerge.done">✅ {{ __('vehicle.bank.merge_chip_done') }}</span>
        <button type="button" @click="$store.bankMerge.done = false; Livewire.dispatch('open-bank-batch')" class="rounded-md bg-primary px-2.5 py-0.5 font-semibold text-white hover:opacity-90" data-bank-merge-chip-open>{{ __('vehicle.bank.merge_chip_open') }}</button>
    </div>
</div>

@script
<script>
    // 📎 PDF 한 파일로 합치기 (jin 2026-11-27) — 건마다 「전신문 → Proforma」 순서로 이어 붙인다.
    //   브라우저가 한다: 은행 팩스(TIF)를 풀 수 있는 곳이 브라우저뿐이다(서버는 GD 만). Proforma 엑셀은 서버가 PDF 로 바꿔 준다.
    //   다 합치면 서버에 올려 두고(mergedFile) 어떤 목록으로 만들었는지 적는다(mergedKey) — 그 뒤 목록이 바뀌면 다시 합쳐야 보낼 수 있다.
    //   🪟 창을 닫아도 계속 돈다(jin 같은 날 «닫기 누르면 백그라운드, 취소 버튼») — 상태를 창이 아니라 Alpine 저장소에 둔다.
    //      창이 닫혀 있는 동안은 화면 위 가운데의 작은 띠가 진행을 보여 준다. 차량관리 화면을 떠나면(다른 메뉴) 멈춘다.
    if (! Alpine.store('bankMerge')) {
        Alpine.store('bankMerge', {
            merging: false, step: '', error: '', url: null, pages: 0, size: '', done: false, stopAsked: false, msgs: {}, open: false,
            async loadScript(src, ready) {
                if (ready()) return;
                await new Promise((ok, no) => { const s = document.createElement('script'); s.src = src; s.onload = ok; s.onerror = no; document.head.appendChild(s); });
            },
            async bytesOf(url, what) {
                const r = await fetch(url, { credentials: 'same-origin' });
                if (! r.ok) throw new Error(what + ' — ' + (r.status === 422 ? this.msgs.convert : this.msgs.fetch));
                return new Uint8Array(await r.arrayBuffer());
            },
            addImagePage(out, img) {
                const W = 595.28, H = 841.89, s = Math.min(W / img.width, H / img.height), w = img.width * s, h = img.height * s;
                out.addPage([W, H]).drawImage(img, { x: (W - w) / 2, y: (H - h) / 2, width: w, height: h });
            },
            check() { if (this.stopAsked) { const e = new Error('stopped'); e.stopped = true; throw e; } },
            async addFile(out, url, ext, what) {
                this.check();
                const bytes = await this.bytesOf(url, what);
                this.check();
                try {
                    if (ext === 'pdf') {
                        const src = await PDFLib.PDFDocument.load(bytes, { ignoreEncryption: true });
                        (await out.copyPages(src, src.getPageIndices())).forEach(p => out.addPage(p));
                    } else if (ext === 'tif' || ext === 'tiff') {
                        await this.loadScript('/vendor/utif/UTIF.js', () => !! window.UTIF);
                        const buf = bytes.buffer;
                        for (const ifd of UTIF.decode(buf)) {
                            this.check();
                            UTIF.decodeImage(buf, ifd);
                            const c = document.createElement('canvas'); c.width = ifd.width; c.height = ifd.height;
                            c.getContext('2d').putImageData(new ImageData(new Uint8ClampedArray(UTIF.toRGBA8(ifd)), ifd.width, ifd.height), 0, 0);
                            const png = await new Promise(ok => c.toBlob(b => b.arrayBuffer().then(ok), 'image/png'));
                            this.addImagePage(out, await out.embedPng(png));
                        }
                    } else {
                        this.addImagePage(out, ext === 'png' ? await out.embedPng(bytes) : await out.embedJpg(bytes));
                    }
                } catch (e) {
                    if (e.stopped) throw e;
                    console.error(e);
                    throw new Error(what + ' — ' + this.msgs.read);
                }
            },
            stop() { if (this.merging) this.stopAsked = true; },
            async run(wire, items, key, name, msgs, uploadUrl, csrf) {
                if (this.merging || ! items.length) return;
                this.msgs = msgs; this.merging = true; this.stopAsked = false; this.done = false; this.error = ''; this.step = '';
                try {
                    await wire.mergeStarted();
                    await this.loadScript('/vendor/pdf-lib/pdf-lib.min.js', () => !! window.PDFLib);
                    const out = await PDFLib.PDFDocument.create();
                    for (let i = 0; i < items.length; i++) {
                        const it = items[i], no = String(i + 1).padStart(2, '0');
                        this.step = (i + 1) + ' / ' + items.length;
                        await this.addFile(out, it.rem, it.remExt, no + '. ' + it.label + ' ' + msgs.remittance);
                        if (it.inv) await this.addFile(out, it.inv, 'pdf', no + '. ' + it.label + ' ' + msgs.invoice);
                    }
                    this.check();
                    const file = new File([await out.save()], name, { type: 'application/pdf' });
                    this.step = msgs.uploading;
                    // Livewire 파일 올리기(wire.upload)를 쓰지 않는다 — 차량 창이 열려 있으면 그 창의 주기 갱신 요청과 겹쳐
                    // 올리기 마무리가 끝나지 않았다(2026-11-27 실측). 전용 주소로 직접 올리고 받은 표만 넘긴다.
                    const form = new FormData(); form.append('file', file);
                    const up = await fetch(uploadUrl, { method: 'POST', body: form, credentials: 'same-origin', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' } });
                    if (! up.ok) throw new Error(msgs.upload);
                    const token = (await up.json()).token;
                    this.check();
                    await wire.setMerged(token, key);
                    if (this.url) URL.revokeObjectURL(this.url);
                    this.url = URL.createObjectURL(file);
                    this.pages = out.getPageCount();
                    this.size = file.size > 1048576 ? (file.size / 1048576).toFixed(1) + 'MB' : Math.max(1, Math.round(file.size / 1024)) + 'KB';
                    this.done = true;
                    if (! this.open) window.dispatchEvent(new CustomEvent('notify', { detail: { message: msgs.finished, type: 'success' } }));
                } catch (e) {
                    this.error = e.stopped ? '' : (e.message || String(e));
                    if (this.url) URL.revokeObjectURL(this.url);
                    this.url = null; this.done = false;
                    await wire.mergeStopped();
                    if (! e.stopped && ! this.open) window.dispatchEvent(new CustomEvent('notify', { detail: { message: msgs.failed, type: 'error' } }));
                } finally {
                    this.merging = false; this.stopAsked = false;
                }
            },
        });
    }
    Alpine.data('bankBatch', () => ({
        init() { Alpine.store('bankMerge').open = true; },
        destroy() { Alpine.store('bankMerge').open = false; },
        mergeAll() {
            const el = this.$refs.mergeData;
            if (! el) return;
            Alpine.store('bankMerge').run($wire, JSON.parse(el.dataset.items), el.dataset.key, el.dataset.name, JSON.parse(el.dataset.msgs), el.dataset.upload, el.dataset.csrf);
        },
    }));

    // TIF(은행 팩스, Group3)는 서버(GD)가 못 푼다 — 브라우저가 UTIF 로 쪽마다 긴 변 1100px PNG 를 만들어 보낸다.
    Alpine.data('bankRemit', () => ({
        converting: false,
        previews: [],
        lb: { open: false, i: 0, z: 1, x: 0, y: 0, drag: null, tx: null, ty: null },
        onEscape() {
            if (this.converting) return;                       // 읽는 중엔 닫지 않는다
            if (this.lb.open) { this.lbClose(); return; }       // 보기 창부터 닫는다
            $wire.closeBank();
        },
        lbOpen(i) { this.lb.open = true; this.lb.i = i; this.lbReset(); },
        lbClose() { this.lb.open = false; this.lbReset(); },
        lbReset() { this.lb.z = 1; this.lb.x = 0; this.lb.y = 0; this.lb.drag = null; },
        lbStep(d) {
            const n = this.previews.length;
            if (! this.lb.open || n < 2) return;
            this.lb.i = (this.lb.i + d + n) % n; this.lbReset();
        },
        lbZoomBy(k, cx = null, cy = null) {
            const z = this.lb.z, nz = Math.min(6, Math.max(1, z * k));
            if (nz === 1) { this.lbReset(); return; }
            const img = this.$root.querySelector('img[data-bank-lb-img]');
            if (cx !== null && img) {   // 마우스가 가리킨 곳이 그 자리에 남게
                const r = img.getBoundingClientRect(), f = 1 - nz / z;
                this.lb.x += (cx - (r.left + r.width / 2)) * f; this.lb.y += (cy - (r.top + r.height / 2)) * f;
            }
            this.lb.z = nz;
        },
        lbWheel(e) { this.lbZoomBy(e.deltaY < 0 ? 1.2 : 1 / 1.2, e.clientX, e.clientY); },
        lbDragStart(e) {
            e.preventDefault();
            if (this.lb.z <= 1) { this.lbZoomBy(2.5, e.clientX, e.clientY); return; }   // 누르면 바로 확대
            this.lb.drag = { x: e.clientX - this.lb.x, y: e.clientY - this.lb.y };
        },
        lbDragMove(e) { if (this.lb.drag) { this.lb.x = e.clientX - this.lb.drag.x; this.lb.y = e.clientY - this.lb.drag.y; } },
        lbDragEnd() { this.lb.drag = null; },
        lbTouchStart(e) { this.lb.tx = e.changedTouches[0].clientX; this.lb.ty = e.changedTouches[0].clientY; },
        lbTouchEnd(e) {
            if (this.lb.tx === null || this.lb.z > 1) return;
            const dx = e.changedTouches[0].clientX - this.lb.tx, dy = e.changedTouches[0].clientY - this.lb.ty;
            this.lb.tx = null;
            if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy)) this.lbStep(dx < 0 ? 1 : -1);
        },
        async loadUtif() {
            if (window.UTIF) return;
            await new Promise((ok, no) => { const s = document.createElement('script'); s.src = '/vendor/utif/UTIF.js'; s.onload = ok; s.onerror = no; document.head.appendChild(s); });
        },
        async pick(e) {
            const f = e.target.files && e.target.files[0];
            this.previews = [];
            if (! f || ! /\.tiff?$/i.test(f.name)) return;
            this.converting = true;
            try {
                await this.loadUtif();
                const buf = await f.arrayBuffer();
                const ifds = UTIF.decode(buf).slice(0, 6);
                const pages = [];
                for (const ifd of ifds) {
                    UTIF.decodeImage(buf, ifd);
                    const rgba = UTIF.toRGBA8(ifd);
                    const c = document.createElement('canvas'); c.width = ifd.width; c.height = ifd.height;
                    c.getContext('2d').putImageData(new ImageData(new Uint8ClampedArray(rgba), ifd.width, ifd.height), 0, 0);
                    const sc = Math.min(1, 1100 / Math.max(c.width, c.height));
                    const d = document.createElement('canvas'); d.width = Math.round(c.width * sc); d.height = Math.round(c.height * sc);
                    const ctx = d.getContext('2d'); ctx.imageSmoothingQuality = 'high'; ctx.drawImage(c, 0, 0, d.width, d.height);
                    // PNG — JPEG(75%)는 팩스의 가는 글씨를 뭉갰다(11-24 실측). 크기는 쪽당 약 0.2MB 로 비슷하다.
                    pages.push(d.toDataURL('image/png'));
                }
                this.previews = pages;
                // Livewire 요청 한도 1MB — 이름이 있는 3쪽을 먼저, 남는 만큼만 4·2·5·6쪽(쪽 번호를 키로). 1쪽은 한 장짜리일 때만.
                const send = {}; let size = 0;
                for (const i of (pages.length === 1 ? [0] : [2, 3, 1, 4, 5])) {
                    if (! pages[i] || size + pages[i].length > 900000) continue;
                    send[i] = pages[i]; size += pages[i].length;
                }
                // 업로드(wire:model)가 끝난 뒤 읽게 한다 — 파일이 서버에 있어야 readPages 가 받아 준다.
                const wait = () => new Promise(r => { const t = setInterval(() => { if ($wire.ocrStatus === 'reading') { clearInterval(t); r(); } }, 200); setTimeout(() => { clearInterval(t); r(); }, 60000); });
                await wait();
                await $wire.readPages(send);
            } catch (err) {
                console.error(err);
                $wire.tifUnreadable();
            } finally {
                this.converting = false;
            }
        },
    }));
</script>
@endscript
</div>
