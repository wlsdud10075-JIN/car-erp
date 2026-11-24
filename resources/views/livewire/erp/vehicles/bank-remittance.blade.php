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

    public ?string $ocrPrefix = null;

    public ?string $ocrRead = null;

    public ?int $recordId = null;

    public string $mailTo = '';

    public string $mailSubject = '';

    public string $mailBody = '';

    public string $bankError = '';

    #[On('open-bank-remittance')]
    public function openBank(array $ids = []): void
    {
        $this->resetErrorBag();
        $this->reset(['bankFile', 'remitterInput', 'ocrStatus', 'ocrPrefix', 'ocrRead', 'recordId', 'mailTo', 'mailSubject', 'mailBody', 'bankError']);
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
        $this->reset(['ocrStatus', 'ocrPrefix', 'ocrRead']);
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
                $this->applyOcr(['name' => $name, 'prefix' => null, 'status' => 'text']);

                return;
            }
            $pages = array_map(fn ($b) => RemitterNameReader::normalizeImage($b), $p['images']);
        } else {
            $pages = [RemitterNameReader::normalizeImage($bytes)];
        }
        $this->applyOcr(array_filter($pages) === [] ? ['name' => null, 'prefix' => null, 'status' => 'none'] : (new RemitterNameReader)->read($pages));
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
        $this->applyOcr(array_filter($norm) === [] ? ['name' => null, 'prefix' => null, 'status' => 'none'] : (new RemitterNameReader)->read($norm));
    }

    public function tifUnreadable(): void
    {
        $this->ocrStatus = 'none';
    }

    private function applyOcr(array $r): void
    {
        $this->ocrStatus = $r['status'];
        $this->ocrPrefix = $r['prefix'] ?? null;
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
        $this->mailTo = BankRemittanceService::recipient();
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
        $to = trim($this->mailTo);
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->addError('mailTo', __('vehicle.mail.to_invalid'));

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
        $this->dispatch('notify', message: __('vehicle.bank.sent', ['to' => $to]), type: 'success');
    }

    public function with(): array
    {
        if (! $this->bankOpen) {
            return ['cars' => collect(), 'rec' => null, 'mailReady' => false];
        }

        return [
            'cars' => \App\Models\Vehicle::whereIn('id', $this->bankIds)->get(['id', 'vehicle_number', 'buyer_id', 'currency', 'remitter_name']),
            'rec' => $this->recordId ? BankRemittance::find($this->recordId) : null,
            'mailReady' => CompanyMailConfig::active()->isConfigured(),
        ];
    }
}; ?>

<div>
@if($bankOpen)
<div class="fixed inset-0 z-[110] flex items-center justify-center bg-black/50 p-3" data-bank-modal
     x-data="bankRemit()" @keydown.escape.window="$wire.closeBank()">
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
            <div wire:loading wire:target="bankFile,readPages" class="mb-2 rounded-md bg-sky-50 px-3 py-2 text-xs text-sky-700">{{ __('vehicle.bank.reading') }}</div>
            <div x-show="converting" x-cloak class="mb-2 rounded-md bg-sky-50 px-3 py-2 text-xs text-sky-700">{{ __('vehicle.bank.reading') }}</div>
            @if($ocrStatus && $ocrStatus !== 'reading')
                @php
                    $tone = match ($ocrStatus) { 'ok', 'text' => 'border-green-200 bg-green-50 text-green-800', 'unverified', 'mismatch' => 'border-amber-200 bg-amber-50 text-amber-800', default => 'border-gray-200 bg-gray-50 text-gray-700' };
                @endphp
                <div class="mb-2 rounded-md border px-3 py-2 text-xs {{ $tone }}" data-bank-ocr="{{ $ocrStatus }}">
                    {{ __('vehicle.bank.ocr_'.$ocrStatus, ['prefix' => $ocrPrefix]) }}
                </div>
            @endif
            <input type="text" wire:model="remitterInput" maxlength="100" class="input-base w-full font-mono uppercase" placeholder="{{ __('vehicle.bank.name_ph') }}" data-bank-name>
            <p class="mt-1 text-[11px] text-gray-500">{{ __('vehicle.bank.name_hint') }}</p>
            @error('remitterInput')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
            <template x-if="previews.length">
                <div class="mt-2 grid grid-cols-3 gap-2">
                    <template x-for="(src, i) in previews" :key="i">
                        <a :href="src" target="_blank" class="block overflow-hidden rounded border border-gray-200"><img :src="src" class="w-full" alt=""></a>
                    </template>
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
            <div class="mt-1 flex flex-wrap gap-3 text-xs">
                <a href="{{ route('erp.bank-remittances.file', [$rec->id, 'remittance']) }}" class="text-primary-text underline">📄 {{ $rec->remittance_name }}</a>
                @if($rec->invoice_path)
                    <a href="{{ route('erp.bank-remittances.file', [$rec->id, 'invoice']) }}" class="text-primary-text underline" data-bank-invoice>📄 {{ $rec->invoice_name }}</a>
                @endif
            </div>
        </div>

        <div class="mt-4 space-y-2">
            <div class="text-sm font-medium text-gray-700">③ {{ __('vehicle.bank.step_mail') }}</div>
            @unless($mailReady)
                <p class="rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-700">{{ __('vehicle.mail.not_configured') }}</p>
            @endunless
            <label class="block text-xs text-gray-500">{{ __('vehicle.bank.mail_to') }}</label>
            <input type="email" wire:model.live.blur="mailTo" class="input-base w-full" data-bank-to>
            @if($mailTo === '')<p class="text-[11px] text-amber-700">{{ __('vehicle.bank.no_recipient') }}</p>@endif
            @error('mailTo')<p class="text-xs text-red-500">{{ $message }}</p>@enderror
            <label class="block text-xs text-gray-500">{{ __('vehicle.bank.mail_subject_label') }}</label>
            <input type="text" wire:model="mailSubject" class="input-base w-full">
            <label class="block text-xs text-gray-500">{{ __('vehicle.bank.mail_body_label') }}</label>
            <textarea wire:model="mailBody" rows="5" class="input-base w-full"></textarea>
            <p class="text-[11px] text-gray-500">{{ __('vehicle.bank.attach_note') }}</p>
        </div>

        <div class="mt-4 flex justify-end gap-2">
            <button type="button" wire:click="closeBank" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-50">{{ __('vehicle.bank.later') }}</button>
            <button type="button" wire:click="sendBankMail" wire:loading.attr="disabled" wire:confirm="{{ __('vehicle.bank.send_confirm', ['to' => $mailTo]) }}"
                    class="rounded-lg bg-primary px-4 py-1.5 text-sm font-semibold text-white hover:opacity-90 disabled:opacity-50" data-bank-send>
                ✉ {{ __('vehicle.bank.send_btn') }}
            </button>
        </div>
        @endif
    </div>
</div>
@endif

@script
<script>
    // TIF(은행 팩스, Group3)는 서버(GD)가 못 푼다 — 브라우저가 UTIF 로 쪽마다 긴 변 1100px PNG 를 만들어 보낸다.
    Alpine.data('bankRemit', () => ({
        converting: false,
        previews: [],
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
                    pages.push(d.toDataURL('image/jpeg', 0.75));
                }
                this.previews = pages;
                // Livewire 요청 한도 1MB — 대조할 1쪽과 이름이 있는 3쪽을 먼저, 남는 만큼만 2·4쪽…(쪽 번호를 키로).
                const send = {}; let size = 0;
                for (const i of [0, 2, 1, 3, 4, 5]) {
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
