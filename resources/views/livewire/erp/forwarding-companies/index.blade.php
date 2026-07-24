<?php

use App\Models\AuditLog;
use App\Models\ForwardingCompany;
use App\Models\ForwardingInvoice;
use App\Models\Vehicle;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {

    // item 7 (jin 2026-07-07) — 마스터 CRUD → "포워딩사별 선적 현황" 개편.
    //   기간(선적일/BL발행일) 필터 + 운임비 통화별 합산 + 선박명/컨테이너/수출신고번호 검색.
    //   CRUD(추가/편집/삭제)는 슬라이드 패널로 보존.
    public string $search = '';
    #[Url] public string $dateType = 'shipping';   // shipping = shipping_date / bl = bl_issue_date
    #[Url] public string $dateFrom = '';
    #[Url] public string $dateTo = '';

    // ── 운임 인보이스(지급 청산) — jin 2026-07-16 ──────────────────────────
    //   포워딩사가 준 인보이스 실금액 기입 + "줬나/안줬나" 청산. 통화는 재환산 없이 그대로.
    #[Url] public string $displayCurrency = '';   // '' 전체(통화별 병렬) / 통화코드(그 통화 원금액만)
    public array $invForm = [];                    // [fkey => ['amount'=>, 'currency'=>, 'memo'=>]]
    public bool $hideSettled = false;              // 지급완료 묶음 숨기고 미지급만 보기

    // ── 스케줄 달력 (③) — jin 2026-07-16. 선적일→도착일 기간 막대, 같은 묶음 동일 색. 기본 접힘. ──
    public bool $showCalendar = false;
    #[Url] public string $calMonth = '';           // 'YYYY-MM' (기본=이번 달)

    public bool $showPanel = false;
    public ?int $editingId = null;

    // CRUD 폼
    public string $name         = '';
    public string $contact_name = '';
    public string $email        = '';
    public string $phone        = '';
    public string $address      = '';
    public string $memo         = '';
    public bool   $is_active    = true;

    // 포워딩사(선적현황) — admin + [관리] (canManageForwarding). 라우트 'auth, verified' + mount 가드 (2026-07-08 jin).
    public function mount(): void
    {
        abort_unless(auth()->user()?->canManageForwarding(), 403);
        if ($this->calMonth === '') {
            $this->calMonth = now()->format('Y-m');
        }
    }

    public function searchNow(): void
    {
        unset($this->shipments, $this->companies);
    }

    /** 필터된 선적 차량 → forwarding_company_id 별 그룹. 통화별 합산·목록의 단일 출처. */
    #[Computed]
    public function shipments()
    {
        $col = $this->dateType === 'bl' ? 'bl_issue_date' : 'shipping_date';
        $term = trim($this->search);

        return Vehicle::query()
            ->whereNotNull('forwarding_company_id')
            ->whereNull('deleted_at')
            ->when($this->dateFrom !== '', fn ($q) => $q->where($col, '>=', $this->dateFrom))
            ->when($this->dateTo !== '', fn ($q) => $q->where($col, '<=', $this->dateTo))
            ->when($term !== '', fn ($q) => $q->where(fn ($q2) => $q2
                ->where('vehicle_number', 'like', "%{$term}%")
                ->orWhere('vessel_name', 'like', "%{$term}%")
                ->orWhere('container_number', 'like', "%{$term}%")
                ->orWhere('export_declaration_number', 'like', "%{$term}%")))
            ->orderByDesc($col)
            ->get(['id', 'forwarding_company_id', 'vehicle_number', 'shipping_date', 'bl_issue_date', 'eta_date',
                'vessel_name', 'shipping_method', 'container_number', 'export_declaration_number', 'transport_fee', 'currency'])
            ->groupBy('forwarding_company_id');
    }

    /** 표시할 포워딩사 — 차량 필터(검색/기간)가 있으면 매칭 선적이 있는 곳만. 없으면 전체. */
    #[Computed]
    public function companies()
    {
        $companies = ForwardingCompany::query()->orderBy('name')->get();
        $hasVehicleFilter = $this->search !== '' || $this->dateFrom !== '' || $this->dateTo !== '';

        if ($hasVehicleFilter) {
            $shipments = $this->shipments;

            return $companies->filter(fn ($fc) => $shipments->has($fc->id))->values();
        }

        return $companies;
    }

    public function openCreate(): void
    {
        $this->resetValidation();
        $this->resetForm();
        $this->editingId = null;
        $this->showPanel = true;
    }

    public function openEdit(int $id): void
    {
        $fc = ForwardingCompany::findOrFail($id);
        $this->editingId    = $id;
        $this->name         = $fc->name;
        $this->contact_name = $fc->contact_name ?? '';
        $this->email        = $fc->email        ?? '';
        $this->phone        = $fc->phone        ?? '';
        $this->address      = $fc->address      ?? '';
        $this->memo         = $fc->memo         ?? '';
        $this->is_active    = $fc->is_active;
        $this->showPanel    = true;
    }

    public function close(): void
    {
        $this->resetValidation();
        $this->showPanel = false;
        $this->editingId = null;
    }

    public function save(): void
    {
        $this->validate(['name' => 'required|string|max:100']);

        $data = [
            'name'         => $this->name,
            'contact_name' => $this->contact_name ?: null,
            'email'        => $this->email        ?: null,
            'phone'        => $this->phone        ?: null,
            'address'      => $this->address      ?: null,
            'memo'         => $this->memo         ?: null,
            'is_active'    => $this->is_active,
        ];

        if ($this->editingId) {
            ForwardingCompany::findOrFail($this->editingId)->update($data);
        } else {
            ForwardingCompany::create($data);
        }

        unset($this->companies);
        $this->close();
        session()->flash('success', __('forwarding.saved'));
    }

    public function delete(int $id): void
    {
        ForwardingCompany::findOrFail($id)->delete();
        unset($this->companies, $this->shipments);
        session()->flash('success', __('forwarding.deleted'));
    }

    private function resetForm(): void
    {
        $this->name = $this->contact_name = $this->email = $this->phone = $this->address = $this->memo = '';
        $this->is_active = true;
    }

    // ── 운임 인보이스 로직 ────────────────────────────────────────────────

    /** 차량 → [group_type, group_key]. 우선순위 container › declaration › vessel (데이터로 자동 분류). */
    private function groupKeyOf($v): array
    {
        if (! empty($v->container_number)) {
            return ['container', $v->container_number];
        }
        if (! empty($v->export_declaration_number)) {
            return ['declaration', $v->export_declaration_number];
        }
        if (! empty($v->vessel_name)) {
            return ['vessel', $v->vessel_name];
        }

        return ['none', ''];
    }

    /** 화면에 뜬 포워딩사들의 인보이스 [companyId|type|key => ForwardingInvoice]. paid_at 이 지급여부 단일 출처. */
    #[Computed]
    public function invoices()
    {
        return ForwardingInvoice::query()
            ->whereIn('forwarding_company_id', $this->companies->pluck('id'))
            ->get()
            ->keyBy(fn ($i) => $i->forwarding_company_id.'|'.$i->group_type.'|'.$i->group_key);
    }

    /** 포워딩사별 → 묶음(그룹)별 소분류 + 통화별 예상운임. UI 렌더용. */
    #[Computed]
    public function groupedShipments()
    {
        return $this->shipments->map(function ($ships) {
            return $ships->groupBy(function ($v) {
                [$t, $k] = $this->groupKeyOf($v);

                return $t.'|'.$k;
            })->map(function ($group, $composite) {
                [$type, $key] = explode('|', $composite, 2);

                return [
                    'type' => $type,
                    'key' => $key,
                    'vehicles' => $group,
                    'feeByCurrency' => $group->groupBy('currency')->map(fn ($g) => (int) $g->sum('transport_fee'))->filter(),
                ];
            })->values();
        });
    }

    /** 그룹 인보이스 저장(+선택 청산) — 그룹당 1건 upsert. 재인가 매번(SKILLS #26). */
    public function saveInvoice(int $companyId, string $groupType, string $groupKey, bool $settle = false): void
    {
        abort_unless(auth()->user()?->canManageForwarding(), 403);
        abort_unless(in_array($groupType, ForwardingInvoice::GROUP_TYPES, true) && $groupKey !== '', 422);

        $fkey = md5($companyId.'|'.$groupType.'|'.$groupKey);   // wire:model 키 안전화(선박명 공백·한글 대비)
        $f = $this->invForm[$fkey] ?? [];
        $inv = ForwardingInvoice::firstOrNew([
            'forwarding_company_id' => $companyId, 'group_type' => $groupType, 'group_key' => $groupKey,
        ]);
        $num = fn ($v) => (float) str_replace([',', ' '], '', (string) $v);
        $inv->amount = isset($f['amount']) ? $num($f['amount']) : ($inv->amount ?? 0);
        $inv->currency = $f['currency'] ?? $inv->currency ?? 'USD';
        // 차액 정산 3필드 (jin 2026-07-24) — 격리(정산·미수 무연결)
        $inv->manual_rate = isset($f['manual_rate']) ? ($num($f['manual_rate']) ?: null) : $inv->manual_rate;
        $inv->actual_paid_krw = isset($f['actual_paid_krw']) ? ($num($f['actual_paid_krw']) ?: null) : $inv->actual_paid_krw;
        $inv->write_off_krw = isset($f['write_off_krw']) ? $num($f['write_off_krw']) : ($inv->write_off_krw ?? 0);
        $inv->memo = ($f['memo'] ?? $inv->memo) ?: null;
        $inv->created_by = $inv->created_by ?? auth()->id();

        $justSettled = false;
        if ($settle && $inv->paid_at === null) {
            $inv->paid_at = now();
            $justSettled = true;
        }
        $inv->save();

        if ($justSettled) {
            $this->logPaid($inv, true);
        }
        unset($this->invoices);
        session()->flash('success', __($justSettled ? 'forwarding.inv_paid' : 'forwarding.inv_saved'));
    }

    /** 버림 — 환산KRW − 실송금KRW 우수리(±)를 write_off 로 잡아 차액 0 맞춤 (jin 2026-07-24). 저장 전 미리보기용. */
    public function truncateInvoice(int $companyId, string $groupType, string $groupKey): void
    {
        abort_unless(auth()->user()?->canManageForwarding(), 403);
        $fkey = md5($companyId.'|'.$groupType.'|'.$groupKey);
        $f = $this->invForm[$fkey] ?? [];
        $inv = $this->invoices[$companyId.'|'.$groupType.'|'.$groupKey] ?? null;
        $num = fn ($v) => (float) str_replace([',', ' '], '', (string) $v);

        $amount = isset($f['amount']) ? $num($f['amount']) : (float) ($inv->amount ?? 0);
        $currency = $f['currency'] ?? ($inv->currency ?? 'USD');
        $rate = isset($f['manual_rate']) ? $num($f['manual_rate']) : (float) ($inv->manual_rate ?? 0);
        $paid = isset($f['actual_paid_krw']) ? $num($f['actual_paid_krw']) : (float) ($inv->actual_paid_krw ?? 0);

        $converted = $currency === 'KRW' ? (int) floor($amount) : (int) floor($amount * $rate);
        $this->invForm[$fkey]['write_off_krw'] = (string) ($converted - (int) round($paid));   // 우수리(±) 그대로
    }

    /** 청산 취소 — paid_at 해제(금액 기록은 유지). 감사로그 남김. */
    public function unsettleInvoice(int $invoiceId): void
    {
        abort_unless(auth()->user()?->canManageForwarding(), 403);
        $inv = ForwardingInvoice::findOrFail($invoiceId);
        if ($inv->paid_at !== null) {
            $inv->paid_at = null;
            $inv->save();
            $this->logPaid($inv, false);
        }
        unset($this->invoices);
        session()->flash('success', __('forwarding.inv_unpaid'));
    }

    /** 청산/취소 감사로그 — 관리자 화면 한글(column_labels). */
    private function logPaid(ForwardingInvoice $inv, bool $paid): void
    {
        AuditLog::create([
            'user_id' => auth()->id(),
            'auditable_type' => ForwardingInvoice::class,
            'auditable_id' => $inv->id,
            'action' => $paid ? 'forwarding_invoice_paid' : 'forwarding_invoice_unpaid',
            'new_value' => $paid ? $inv->currency.' '.number_format((float) $inv->amount, 2).' 지급' : '청산 취소',
            'ip_address' => request()->ip(),
        ]);
    }

    // ── 스케줄 달력 로직 (③) ───────────────────────────────────────────────
    public function shiftMonth(int $delta): void
    {
        $this->calMonth = \Illuminate\Support\Carbon::createFromFormat('Y-m', $this->calMonth ?: now()->format('Y-m'))
            ->addMonths($delta)->format('Y-m');
    }

    /** 묶음 구분 색(절제된 8색). group_key 해시로 안정 배정, 미분류=회색. */
    private const CAL_COLORS = ['#7c6fcd', '#2b9d8f', '#e08a3c', '#4a89dc', '#c65b7c', '#5aa469', '#b08968', '#8367c7'];

    private function eventColor(string $key): string
    {
        return $key === '' ? '#9ca3af' : self::CAL_COLORS[crc32($key) % count(self::CAL_COLORS)];
    }

    /** 선적일·도착일 둘 다 있는 선적 건(전 포워딩사, 필터 반영) — 달력 막대 원천. */
    #[Computed]
    public function calendarEvents(): \Illuminate\Support\Collection
    {
        $events = $this->shipments->flatten(1)
            ->filter(fn ($v) => $v->shipping_date && $v->eta_date)
            ->values();

        // 묶음별 색 — 등장 순서대로 팔레트 순환(crc32 몰림 방지, 인접 묶음이 확실히 다른 색).
        $colorMap = [];
        $idx = 0;
        foreach ($events as $v) {
            $key = $this->groupKeyOf($v)[1];
            if (! array_key_exists($key, $colorMap)) {
                $colorMap[$key] = $key === '' ? '#9ca3af' : self::CAL_COLORS[$idx++ % count(self::CAL_COLORS)];
            }
        }

        return $events->map(function ($v) use ($colorMap) {
            [$type, $key] = $this->groupKeyOf($v);
            $groupLabel = match ($type) {
                'container' => __('forwarding.grp_container'),
                'declaration' => __('forwarding.grp_declaration'),
                'vessel' => __('forwarding.grp_vessel'),
                default => __('forwarding.grp_none'),
            };
            $inv = $this->invoices[$v->forwarding_company_id.'|'.$type.'|'.$key] ?? null;

            return [
                'vehicle_number' => $v->vehicle_number,
                'start' => $v->shipping_date->copy()->startOfDay(),
                'end' => $v->eta_date->copy()->startOfDay(),
                'group_key' => $key,
                'color' => $colorMap[$key],
                'tip' => [
                    'vehicle' => $v->vehicle_number,
                    'ship_date' => $v->shipping_date->format('Y-m-d'),
                    'eta_date' => $v->eta_date->format('Y-m-d').' ('.((int) $v->shipping_date->diffInDays($v->eta_date) + 1).__('forwarding.tip_days').')',
                    'group' => $key !== '' ? $groupLabel.' '.$key : $groupLabel,
                    'vessel' => $v->vessel_name ?: '-',
                    'company' => optional($this->companies->firstWhere('id', $v->forwarding_company_id))->name ?? '-',
                    'paid' => $inv && $inv->paid_at ? __('forwarding.paid') : __('forwarding.tip_unpaid'),
                ],
            ];
        })->values();
    }

    /** 월간 42칸(6주) — 각 날짜의 선적(ship)·도착(arrive) 이벤트 점. */
    #[Computed]
    public function calendarDays(): array
    {
        $first = \Illuminate\Support\Carbon::createFromFormat('Y-m', $this->calMonth ?: now()->format('Y-m'))->startOfMonth();
        $gridStart = $first->copy()->startOfWeek(\Carbon\CarbonInterface::SUNDAY);
        $events = $this->calendarEvents;
        $out = [];

        for ($n = 0; $n < 42; $n++) {
            $day = $gridStart->copy()->addDays($n);
            $dstr = $day->format('Y-m-d');
            $items = [];
            foreach ($events as $ev) {
                if ($ev['start']->format('Y-m-d') === $dstr) {
                    $items[] = ['type' => 'ship', 'label' => $ev['vehicle_number'], 'color' => $ev['color'], 'tip' => $ev['tip']];
                }
                if ($ev['end']->format('Y-m-d') === $dstr) {
                    $items[] = ['type' => 'arrive', 'label' => $ev['vehicle_number'], 'color' => $ev['color'], 'tip' => $ev['tip']];
                }
            }
            $out[] = [
                'day' => (int) $day->format('j'),
                'inMonth' => $day->format('Y-m') === $first->format('Y-m'),
                'items' => $items,
            ];
        }

        return $out;
    }
}; ?>

<div wire:poll.60s>
@if(session('success'))
<div x-data="{show:true}" x-show="show" x-init="setTimeout(()=>show=false,3000)"
     class="fixed top-4 right-4 z-50 rounded-lg bg-green-600 px-4 py-3 text-sm text-white shadow-lg">
    {{ session('success') }}
</div>
@endif

<div class="flex h-full flex-col gap-4 p-3 md:p-6">

{{-- 헤더 --}}
<div class="flex items-center justify-between">
    <div>
        <h1 class="text-xl font-bold text-gray-800">{{ __('forwarding.title') }}</h1>
        <p class="mt-0.5 text-xs text-gray-500">{{ __('common.total', ['count' => $this->companies->count()]) }}</p>
    </div>
    <button wire:click="openCreate" class="btn-primary">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
        {{ __('forwarding.add') }}
    </button>
</div>

{{-- 필터바: 기간 + 검색 --}}
<div class="flex flex-wrap items-center gap-2 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2">
    <select wire:model.live="dateType" class="input-filter">
        <option value="shipping">{{ __('forwarding.date_shipping') }}</option>
        <option value="bl">{{ __('forwarding.date_bl') }}</option>
    </select>
    <input wire:model.live="dateFrom" type="text" data-date class="input-filter w-32" placeholder="{{ __('forwarding.date_from') }}" />
    <span class="text-gray-400 text-sm">~</span>
    <input wire:model.live="dateTo" type="text" data-date class="input-filter w-32" placeholder="{{ __('forwarding.date_to') }}" />
    <input wire:model="search" wire:keydown.enter="searchNow" type="text" placeholder="{{ __('forwarding.search_ph') }}"
           class="input-filter w-64" />
    <button wire:click="searchNow" class="btn-search">{{ __('common.search') }}</button>
    <label class="ml-2 flex items-center gap-1 text-xs text-gray-500">
        <input type="checkbox" wire:model.live="hideSettled" class="rounded border-gray-300" />
        {{ __('forwarding.hide_settled') }}
    </label>
</div>

{{-- 스케줄 달력 (③) — 접이식. 선적일→도착일 기간 막대, 같은 묶음 동일 색. 기본 접힘. --}}
<div class="card-tight">
    <button type="button" wire:click="$toggle('showCalendar')" class="flex w-full items-center gap-2 text-left">
        <svg class="h-4 w-4 text-gray-400 transition-transform {{ $showCalendar ? 'rotate-90' : '' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
        <span class="font-semibold text-gray-700">{{ __('forwarding.calendar') }}</span>
        <span class="text-[11px] text-gray-400">{{ __('forwarding.calendar_hint') }}</span>
    </button>
    @if($showCalendar)
    @php $cm = \Illuminate\Support\Carbon::createFromFormat('Y-m', $calMonth ?: now()->format('Y-m')); @endphp
    <div class="mt-3" x-data="{ tip: null, ttype: '', tx: 0, ty: 0 }">
        {{-- 월 네비 --}}
        <div class="mb-2 flex items-center justify-center gap-4">
            <button wire:click="shiftMonth(-1)" class="rounded px-2 py-0.5 text-gray-500 hover:bg-gray-100">‹</button>
            <span class="text-sm font-semibold text-gray-700">{{ $cm->format('Y') }}.{{ $cm->format('m') }}</span>
            <button wire:click="shiftMonth(1)" class="rounded px-2 py-0.5 text-gray-500 hover:bg-gray-100">›</button>
        </div>
        {{-- 요일 --}}
        <div class="grid grid-cols-7 text-center text-[11px] font-medium text-gray-400">
            @foreach(['일','월','화','수','목','금','토'] as $i => $wd)<div class="py-1 {{ $i === 0 ? 'text-red-400' : ($i === 6 ? 'text-blue-400' : '') }}">{{ $wd }}</div>@endforeach
        </div>
        {{-- 6주 그리드 — 각 날짜에 선적(● 채운 점)·도착(○ 빈 점) + 차량번호 --}}
        <div class="grid grid-cols-7 overflow-hidden rounded-lg border border-gray-200">
            @foreach($this->calendarDays as $i => $d)
            <div class="min-h-[88px] border-b border-r border-gray-100 p-1 {{ $d['inMonth'] ? '' : 'bg-gray-50/50' }}">
                <div class="text-[11px] {{ ! $d['inMonth'] ? 'text-gray-300' : ($i % 7 === 0 ? 'text-red-400' : ($i % 7 === 6 ? 'text-blue-400' : 'text-gray-500')) }}">{{ $d['day'] }}</div>
                <div class="mt-0.5 space-y-0.5">
                    @foreach(array_slice($d['items'], 0, 4) as $it)
                    <div class="flex cursor-pointer items-center gap-1 truncate leading-tight"
                         @mouseenter="tip = {{ \Illuminate\Support\Js::from($it['tip']) }}; ttype = '{{ $it['type'] }}'; tx = $event.clientX; ty = $event.clientY"
                         @mousemove="tx = $event.clientX; ty = $event.clientY" @mouseleave="tip = null">
                        @if($it['type'] === 'ship')
                        <span class="h-2 w-2 shrink-0 rounded-full" style="background-color: {{ $it['color'] }};"></span>
                        @else
                        <span class="h-2 w-2 shrink-0 rounded-full border-2 bg-white" style="border-color: {{ $it['color'] }};"></span>
                        @endif
                        <span class="truncate text-[10px] text-gray-600">{{ $it['label'] }}</span>
                    </div>
                    @endforeach
                    @if(count($d['items']) > 4)
                    <div class="pl-1 text-[9px] text-gray-400">+{{ count($d['items']) - 4 }}{{ __('forwarding.more_count') }}</div>
                    @endif
                </div>
            </div>
            @endforeach
        </div>
        <p class="mt-1.5 text-center text-[11px] text-gray-400">{{ __('forwarding.calendar_legend') }}</p>

        {{-- 커스텀 호버 툴팁 (막대 위 마우스) --}}
        <div x-show="tip" x-cloak class="pointer-events-none fixed z-50 w-60 rounded-lg bg-gray-900/95 px-3 py-2 text-[11px] text-white shadow-xl"
             :style="`left: ${Math.min(tx + 14, window.innerWidth - 250)}px; top: ${ty + 14}px`">
            <div class="mb-1 flex items-center gap-1.5 border-b border-white/15 pb-1">
                <span class="text-sm font-bold" x-text="tip?.vehicle"></span>
                <span class="rounded px-1 text-[10px]" :class="ttype === 'ship' ? 'bg-emerald-500' : 'bg-sky-500'" x-text="ttype === 'ship' ? '{{ __('forwarding.ship') }}' : '{{ __('forwarding.arrive') }}'"></span>
            </div>
            <div class="space-y-0.5">
                <div class="flex gap-1.5"><span class="w-14 shrink-0 text-gray-400">{{ __('forwarding.tip_ship') }}</span><span x-text="tip?.ship_date"></span></div>
                <div class="flex gap-1.5"><span class="w-14 shrink-0 text-gray-400">{{ __('forwarding.tip_arrive') }}</span><span x-text="tip?.eta_date"></span></div>
                <div class="flex gap-1.5"><span class="w-14 shrink-0 text-gray-400">{{ __('forwarding.tip_group') }}</span><span x-text="tip?.group"></span></div>
                <div class="flex gap-1.5"><span class="w-14 shrink-0 text-gray-400">{{ __('vehicle.field.vessel') }}</span><span x-text="tip?.vessel"></span></div>
                <div class="flex gap-1.5"><span class="w-14 shrink-0 text-gray-400">{{ __('forwarding.tip_company') }}</span><span x-text="tip?.company"></span></div>
                <div class="flex gap-1.5"><span class="w-14 shrink-0 text-gray-400">{{ __('forwarding.tip_pay') }}</span><span class="font-medium" x-text="tip?.paid"></span></div>
            </div>
        </div>
    </div>
    @endif
</div>

{{-- 포워딩사별 선적 현황 카드 --}}
<div class="space-y-2">
    @forelse($this->companies as $fc)
        @php
            $ships = $this->shipments[$fc->id] ?? collect();
            $feeByCurrency = $ships->groupBy('currency')->map(fn ($g) => (int) $g->sum('transport_fee'))->filter();
            $dcol = $dateType === 'bl' ? 'bl_issue_date' : 'shipping_date';
            // 지급 집계 — 묶음(청산 단위) 기준. key 없는(미분류) 묶음은 청산 대상 아니라 제외.
            $grps = $this->groupedShipments[$fc->id] ?? collect();
            $settledCount = 0; $unpaidCount = 0;
            foreach ($grps as $g) {
                if ($g['key'] === '') { continue; }
                $iv = $this->invoices[$fc->id.'|'.$g['type'].'|'.$g['key']] ?? null;
                if ($iv && $iv->paid_at) { $settledCount++; } else { $unpaidCount++; }
            }
        @endphp
        <div class="card-tight" x-data="{ open: false }">
            {{-- 헤더 행 --}}
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                <button type="button" @click="open = !open" class="flex flex-1 items-center gap-2 text-left">
                    <svg class="h-4 w-4 text-gray-400 transition-transform" :class="open ? 'rotate-90' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                    <span class="font-semibold text-gray-800">{{ $fc->name }}</span>
                    <span class="badge {{ $fc->is_active ? 'badge-green' : 'badge-gray' }}">{{ $fc->is_active ? __('common.active') : __('common.inactive') }}</span>
                    <span class="pill-count">{{ __('forwarding.shipment_count', ['count' => $ships->count()]) }}</span>
                    @if($unpaidCount > 0)<span class="badge badge-red text-[10px]">{{ __('forwarding.unpaid_count', ['count' => $unpaidCount]) }}</span>@endif
                    @if($settledCount > 0)<span class="badge badge-green text-[10px]">{{ __('forwarding.settled_count', ['count' => $settledCount]) }}</span>@endif
                </button>
                {{-- 운임비 통화별 합계 --}}
                <div class="flex flex-wrap items-center gap-1.5">
                    @forelse($feeByCurrency as $cur => $sum)
                        <span class="badge badge-blue">{{ $cur }} {{ number_format($sum) }}</span>
                    @empty
                        <span class="text-xs text-gray-400">{{ __('forwarding.no_fee') }}</span>
                    @endforelse
                </div>
                <div class="flex items-center gap-2">
                    <button wire:click="openEdit({{ $fc->id }})" class="text-xs text-gray-500 hover:text-violet-700">{{ __('common.edit') }}</button>
                    <button wire:click="delete({{ $fc->id }})" wire:confirm="{{ __('forwarding.delete_confirm', ['name' => $fc->name]) }}" class="text-xs text-red-400 hover:text-red-600">{{ __('common.delete') }}</button>
                </div>
            </div>
            {{-- 선적 차량 목록 (아코디언) — 묶음(컨테이너/신고번호/선박)별 그룹 + 운임 인보이스 청산 --}}
            <div x-show="open" x-cloak class="mt-3 border-t border-gray-100 pt-3">
                @php $groups = $this->groupedShipments[$fc->id] ?? collect(); @endphp
                @if($groups->isEmpty())
                    <p class="py-3 text-center text-xs text-gray-400">{{ __('forwarding.no_shipment') }}</p>
                @else
                @php
                    // 미지급 먼저, 지급완료는 아래로.
                    $sortedGroups = $groups->sortBy(function ($g) use ($fc) {
                        $iv = $this->invoices[$fc->id.'|'.$g['type'].'|'.$g['key']] ?? null;

                        return ($iv && $iv->paid_at) ? 1 : 0;
                    })->values();
                @endphp
                <div class="space-y-2.5">
                    @foreach($sortedGroups as $grp)
                    @php
                        $fkey = md5($fc->id.'|'.$grp['type'].'|'.$grp['key']);
                        $inv = $this->invoices[$fc->id.'|'.$grp['type'].'|'.$grp['key']] ?? null;
                        $grpLabel = match($grp['type']) {
                            'container' => __('forwarding.grp_container'),
                            'declaration' => __('forwarding.grp_declaration'),
                            'vessel' => __('forwarding.grp_vessel'),
                            default => __('forwarding.grp_none'),
                        };
                        $isPaid = $inv && $inv->paid_at;
                    @endphp
                    @if($hideSettled && $isPaid) @continue @endif
                    <div class="rounded-lg border {{ $isPaid ? 'border-green-200 bg-green-50/40' : 'border-gray-200' }} p-2.5" x-data="{ gopen: false }">
                        {{-- 그룹 헤더: 묶음(클릭=차량 펼침) · 예상운임(통화별) · 인보이스 실금액 청산 --}}
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                            <button type="button" @click="gopen = !gopen" class="flex items-center gap-1.5 text-left">
                                <svg class="h-3.5 w-3.5 text-gray-400 transition-transform" :class="gopen ? 'rotate-90' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                <span class="badge badge-gray text-[10px]">{{ $grpLabel }}</span>
                                <span class="font-mono text-xs font-medium text-gray-700">{{ $grp['key'] ?: __('forwarding.grp_unassigned') }}</span>
                                <span class="text-[11px] text-gray-400">{{ __('forwarding.shipment_count', ['count' => $grp['vehicles']->count()]) }}</span>
                            </button>
                            <div class="flex items-center gap-1">
                                <span class="text-[10px] text-gray-400">{{ __('forwarding.expected_fee') }}</span>
                                @forelse($grp['feeByCurrency'] as $cur => $sum)
                                    <span class="badge badge-blue text-[10px]">{{ $cur }} {{ number_format($sum) }}</span>
                                @empty
                                    <span class="text-[11px] text-gray-300">-</span>
                                @endforelse
                            </div>
                            @if($grp['key'] !== '')
                            <div class="ml-auto">
                                @if($isPaid)
                                    <div class="flex flex-wrap items-center justify-end gap-1.5">
                                        <span class="badge badge-green text-[10px]">{{ __('forwarding.paid') }} · {{ $inv->currency }} {{ number_format($inv->amount) }}</span>
                                        @if($inv->currency !== 'KRW' && $inv->manual_rate)
                                            <span class="text-[10px] text-gray-400">→ ₩{{ number_format($inv->converted_krw) }}</span>
                                        @endif
                                        @if($inv->actual_paid_krw !== null)
                                            <span class="text-[10px] text-gray-500">{{ __('forwarding.rec_paid') }} ₩{{ number_format((int) $inv->actual_paid_krw) }}</span>
                                        @endif
                                        @if((int) $inv->write_off_krw !== 0)
                                            <span class="badge badge-gray text-[10px]">{{ __('forwarding.rec_writeoff') }} {{ number_format((int) $inv->write_off_krw) }}</span>
                                        @endif
                                        <button wire:click="unsettleInvoice({{ $inv->id }})" class="text-[11px] text-gray-400 hover:text-red-500">{{ __('forwarding.unsettle') }}</button>
                                    </div>
                                    @if($inv->memo)<p class="mt-1 text-right text-[10px] text-gray-400">{{ $inv->memo }}</p>@endif
                                @else
                                    @php
                                        $fv = $invForm[$fkey] ?? [];
                                        $num = fn ($v) => (float) str_replace([',', ' '], '', (string) ($v ?? '0'));
                                        $amt = $num($fv['amount'] ?? 0);
                                        $curSel = $fv['currency'] ?? 'USD';
                                        $rate = $num($fv['manual_rate'] ?? 0);
                                        $paid = $num($fv['actual_paid_krw'] ?? 0);
                                        $woff = $num($fv['write_off_krw'] ?? 0);
                                        $converted = $curSel === 'KRW' ? (int) floor($amt) : (int) floor($amt * $rate);
                                        $diff = $converted - (int) round($paid) - (int) round($woff);
                                    @endphp
                                    <div class="flex flex-col items-end gap-1.5">
                                        {{-- 1행: 통화 + 인보이스금액 (× 수기환율 = 환산KRW) --}}
                                        <div class="flex flex-wrap items-center justify-end gap-1.5">
                                            <select wire:model.live="invForm.{{ $fkey }}.currency" class="input-filter h-7 w-16 text-xs">
                                                @foreach(['USD','JPY','EUR','GBP','CNY','KRW'] as $c)
                                                    <option value="{{ $c }}" @selected($curSel === $c)>{{ $c }}</option>
                                                @endforeach
                                            </select>
                                            <input wire:model.live.debounce.500ms="invForm.{{ $fkey }}.amount" type="text" data-money
                                                   placeholder="{{ __('forwarding.inv_amount_ph') }}" class="input-filter h-7 w-24 text-right text-xs" />
                                            @if($curSel !== 'KRW')
                                                <span class="text-[10px] text-gray-400">×</span>
                                                <input wire:model.live.debounce.500ms="invForm.{{ $fkey }}.manual_rate" type="text" inputmode="decimal"
                                                       placeholder="{{ __('forwarding.rec_rate') }}" class="input-filter h-7 w-16 text-right text-xs" />
                                                <span class="text-[10px] text-gray-600">= ₩{{ number_format($converted) }}</span>
                                            @endif
                                        </div>
                                        {{-- 2행: 실송금KRW + 차액 (+버림) + 청산 --}}
                                        <div class="flex flex-wrap items-center justify-end gap-1.5">
                                            <span class="text-[10px] text-gray-400">{{ __('forwarding.rec_paid') }}</span>
                                            <input wire:model.live.debounce.500ms="invForm.{{ $fkey }}.actual_paid_krw" type="text" data-money
                                                   placeholder="₩" class="input-filter h-7 w-24 text-right text-xs" />
                                            <span class="text-[10px] {{ $diff === 0 ? 'text-gray-400' : 'text-amber-600' }}">
                                                {{ __('forwarding.rec_diff') }} {{ $diff > 0 ? '+' : '' }}{{ number_format($diff) }}
                                            </span>
                                            @if($diff !== 0)
                                                <button wire:click="truncateInvoice({{ $fc->id }}, '{{ $grp['type'] }}', @js($grp['key']))"
                                                        class="badge badge-amber text-[10px] hover:opacity-80">{{ __('forwarding.rec_truncate') }}</button>
                                            @elseif((int) $woff !== 0)
                                                <span class="badge badge-gray text-[10px]">{{ __('forwarding.rec_writeoff') }} {{ number_format((int) $woff) }}</span>
                                            @endif
                                            <button wire:click="saveInvoice({{ $fc->id }}, '{{ $grp['type'] }}', @js($grp['key']), true)"
                                                    class="btn-primary h-7 px-2 text-[11px]">{{ __('forwarding.settle') }}</button>
                                        </div>
                                        {{-- 비고 --}}
                                        <input wire:model="invForm.{{ $fkey }}.memo" type="text"
                                               placeholder="{{ __('forwarding.rec_memo') }}" class="input-filter h-7 w-56 text-xs" />
                                    </div>
                                @endif
                            </div>
                            @endif
                        </div>
                        {{-- 그룹 차량 (헤더 클릭 시 펼침 — 대량 나열 방지) --}}
                        <div x-show="gopen" x-cloak class="mt-2 overflow-x-auto">
                        <table class="w-full text-xs">
                            <thead>
                                <tr class="border-b border-gray-100 text-left text-[11px] text-gray-400">
                                    <th class="pb-1 pr-3 font-medium">{{ __('vehicle.col.number') }}</th>
                                    <th class="pb-1 pr-3 font-medium">{{ __('forwarding.col_ship_date') }}</th>
                                    <th class="pb-1 pr-3 font-medium">{{ __('forwarding.col_eta') }}</th>
                                    <th class="pb-1 pr-3 font-medium">{{ __('vehicle.field.vessel') }}</th>
                                    <th class="pb-1 pr-3 text-right font-medium">{{ __('forwarding.col_transport_fee') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50">
                                @foreach($grp['vehicles'] as $v)
                                <tr class="hover:bg-gray-50">
                                    <td class="py-1.5 pr-3 font-medium text-gray-700"><a href="{{ route('erp.vehicles.index', ['openVehicle' => $v->id]) }}" wire:navigate class="hover:text-violet-700">{{ $v->vehicle_number }}</a></td>
                                    <td class="py-1.5 pr-3 text-gray-500">{{ $v->$dcol?->format('Y-m-d') ?? '-' }}</td>
                                    <td class="py-1.5 pr-3 text-gray-500">{{ $v->eta_date?->format('Y-m-d') ?? '-' }}</td>
                                    <td class="py-1.5 pr-3 text-gray-500">{{ $v->vessel_name ?: '-' }}</td>
                                    <td class="py-1.5 pr-3 text-right tabular-nums text-gray-700">{{ $v->transport_fee ? $v->currency.' '.number_format($v->transport_fee) : '-' }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                        </div>
                    </div>
                    @endforeach
                </div>
                @endif
            </div>
        </div>
    @empty
        <div class="py-12 text-center text-sm text-gray-400">{{ __('forwarding.empty') }}</div>
    @endforelse
</div>

</div>

{{-- ══ 슬라이드 패널 (CRUD 보존) ══ --}}
@if($showPanel)
<div x-data="{
    dirty: false,
    confirmOpen: false,
    attemptClose() {
        if (this.confirmOpen) { this.confirmOpen = false; return; }
        if (this.dirty) { this.confirmOpen = true; } else { $wire.close(); }
    },
    confirmDiscard() { this.confirmOpen = false; $wire.close(); },
}" @keyup.escape.window="attemptClose()">
<div class="fixed inset-0 z-40 bg-black/40" @click="attemptClose()"></div>
<div class="fixed inset-y-0 right-0 z-50 flex w-full flex-col bg-white shadow-2xl sm:w-[480px]"
     @input="dirty = true" @change="dirty = true">

    <div class="flex items-center justify-between border-b px-5 py-4">
        <h2 class="text-base font-bold text-gray-800">{{ $editingId ? __('forwarding.panel_edit') : __('forwarding.add') }}</h2>
        <button @click="attemptClose()" class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>

    <div class="flex-1 overflow-y-auto px-5 py-5 space-y-3">
        <div>
            <label class="label-base">{{ __('forwarding.field_name') }} <span class="text-red-500">*</span></label>
            <input wire:model="name" type="text" class="input-base" placeholder="SSANCAR LOGISTICS" />
            @error('name')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
        </div>
        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="label-base">{{ __('forwarding.field_contact') }}</label>
                <input wire:model="contact_name" type="text" class="input-base" />
            </div>
            <div>
                <label class="label-base">{{ __('common.phone') }}</label>
                <input wire:model="phone" type="text" class="input-base" />
            </div>
        </div>
        <div>
            <label class="label-base">{{ __('common.email') }}</label>
            <input wire:model="email" type="email" class="input-base" />
        </div>
        <div>
            <label class="label-base">{{ __('common.address') }}</label>
            <input wire:model="address" type="text" class="input-base" />
        </div>
        <div>
            <label class="label-base">{{ __('common.memo') }}</label>
            <textarea wire:model="memo" class="input-base" rows="2"></textarea>
        </div>
        <div>
            <label class="flex items-center gap-2 text-sm text-gray-600 cursor-pointer">
                <input wire:model="is_active" type="checkbox" class="rounded" /> {{ __('common.active') }}
            </label>
        </div>
    </div>

    <div class="flex items-center justify-end gap-2 border-t px-5 py-4">
        <button @click="attemptClose()" class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">{{ __('common.cancel') }}</button>
        <button wire:click="save" class="btn-primary" wire:loading.attr="disabled" wire:target="save">
            <span wire:loading.remove wire:target="save">{{ __('common.save') }}</span><span wire:loading wire:target="save">{{ __('common.saving') }}</span>
        </button>
    </div>

</div>

<div x-show="confirmOpen" x-cloak x-transition.opacity
     class="fixed inset-0 z-[60] flex items-center justify-center bg-black/50"
     @click.self="confirmOpen = false">
    <div class="card max-w-sm mx-4 shadow-2xl">
        <h3 class="text-base font-semibold text-gray-900">{{ __('common.unsaved_title') }}</h3>
        <p class="mt-2 text-sm text-gray-600">{{ __('common.unsaved_body') }}</p>
        <div class="mt-5 flex justify-end gap-2">
            <button @click="confirmOpen = false" class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">{{ __('common.cancel') }}</button>
            <button @click="confirmDiscard()" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700">{{ __('common.close') }}</button>
        </div>
    </div>
</div>

</div>
@endif
</div>
