{{-- 진행중 차량 — 서류 열(수출통관) / 서류 + 돈 열(관리). $withMoney 로 분기. --}}
@php
    $rows = $this->documentTable;
    $t = fn (string $k, array $r = []) => __("dashboard.v.clearance.$k", $r);
    $yes = fn () => '<span class="badge badge-green">'.e(__('dashboard.v.has')).'</span>';
    $no = fn () => '<span class="badge badge-red">'.e(__('dashboard.v.missing')).'</span>';
@endphp
<div class="card" data-document-table>
    <div class="mb-3 flex items-center justify-between">
        <div class="section-header"><span class="section-dot bg-gray-300"></span><span class="section-title">{{ $withMoney ? __('dashboard.v.manage.table.title') : $t('table.title') }}</span></div>
        <div class="flex items-center gap-3">
            <select wire:model.live="perPage" class="input-filter">
                @foreach([10, 30, 50, 100] as $n)<option value="{{ $n }}">{{ __('dashboard.per_page', ['count' => $n]) }}</option>@endforeach
            </select>
            <a href="{{ route('erp.vehicles.index') }}" wire:navigate class="text-xs text-primary-text hover:underline">{{ $t('table.link') }}</a>
        </div>
    </div>
    @if($rows->isEmpty())
    <p class="text-sm text-gray-400">{{ $t('table.empty') }}</p>
    @else
    <div class="overflow-x-auto">
        <table class="w-full min-w-[760px] text-xs tabular-nums">
            <thead><tr class="border-b border-gray-100 text-left text-[11px] text-gray-400">
                <th class="py-1.5 pr-3 font-medium">{{ $t('table.col.vehicle') }}</th><th class="py-1.5 pr-3 font-medium">{{ $t('table.col.buyer') }}</th>
                <th class="py-1.5 pr-3 font-medium">{{ __('dashboard.col.status') }}</th>
                <th class="py-1.5 pr-3 font-medium">{{ $t('table.col.location') }}</th><th class="py-1.5 pr-3 font-medium">{{ $t('table.col.ship') }}</th>
                <th class="py-1.5 pr-3 font-medium">{{ $t('table.col.decl') }}</th><th class="py-1.5 pr-3 font-medium">{{ $t('table.col.cleared') }}</th><th class="py-1.5 pr-3 font-medium">{{ $t('table.col.bl') }}</th>
                <th class="py-1.5 pr-3 font-medium">{{ $t('table.col.eta') }}</th>
                @if($withMoney)<th class="py-1.5 pr-3 text-right font-medium">{{ __('dashboard.v.manage.table.col.unpaid_sale') }}</th><th class="py-1.5 pr-3 text-right font-medium">{{ __('dashboard.v.manage.table.col.unpaid_purchase') }}</th>@endif
                <th class="py-1.5 font-medium">{{ $t('table.col.next') }}</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-50 whitespace-nowrap text-gray-800">
            @foreach($rows as $v)
            @php
                $next = trans()->has('domain.next_action.'.$v->progress_status) ? __('domain.next_action.'.$v->progress_status) : __('domain.next_action.none');
                $su = $withMoney ? $v->sale_unpaid_amount : 0; $pu = $withMoney ? $v->purchase_unpaid_amount : 0;
            @endphp
            <tr class="hover:bg-gray-50">
                <td class="py-2 pr-3"><a href="{{ $this->vehicleLink($v->id) }}" wire:navigate class="font-medium text-gray-900 hover:text-violet-700">{{ $v->vehicle_number }}</a></td>
                <td class="py-2 pr-3 text-gray-600">{{ $v->exportBuyer?->name ?? $v->buyer?->name ?? '—' }}</td>
                <td class="py-2 pr-3"><span class="badge {{ $this->progressBadge($v->progress_status_cache) }}">{{ $v->progress_status_label }}</span></td>
                <td class="py-2 pr-3 text-gray-600">{{ $v->bl_loading_location ?: '—' }}</td>
                <td class="py-2 pr-3 text-gray-600">{{ $v->shipping_date?->format('m-d') ?? '—' }}</td>
                <td class="py-2 pr-3">{!! $v->export_declaration_document ? $yes() : $no() !!}</td>
                <td class="py-2 pr-3">@if($v->is_export_cleared)<span class="badge badge-green">{{ __('dashboard.v.done') }}</span>@elseif($v->export_declaration_document)<span class="badge badge-amber">{{ __('dashboard.v.waiting') }}</span>@else —@endif</td>
                <td class="py-2 pr-3">{!! $v->bl_document ? $yes() : ($v->is_export_cleared ? $no() : '—') !!}</td>
                <td class="py-2 pr-3 {{ ($v->shipping_date && ! $v->eta_date) ? 'text-red-700' : 'text-gray-600' }}">{{ $v->eta_date?->format('m-d') ?? (($v->shipping_date) ? __('dashboard.v.missing') : '—') }}</td>
                @if($withMoney)
                <td class="py-2 pr-3 text-right {{ $su > 0 ? 'font-semibold text-amber-700' : 'text-gray-300' }}">{{ $su > 0 ? number_format($su, 0).' '.$v->currency : __('dashboard.none') }}</td>
                <td class="py-2 pr-3 text-right {{ $pu > 0 ? 'font-semibold text-red-700' : 'text-gray-300' }}">{{ $pu > 0 ? '₩'.number_format($pu) : __('dashboard.none') }}</td>
                @endif
                <td class="py-2"><a href="{{ $this->vehicleLink($v->id) }}" wire:navigate class="text-primary-text hover:underline">{{ $next }} →</a></td>
            </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    @endif
</div>
