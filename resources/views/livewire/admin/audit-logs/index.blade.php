<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Support\ColumnLabel;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component
{
    use WithPagination;

    /**
     * 운영 로그 열람 가드 (jin 2026-07-28) — [관리] 이상.
     * 라우트 'operation-logs' 미들웨어와 이중 방어 (구조상 미들웨어만 믿지 않는다, SKILLS §8 #26).
     */
    public function mount(): void
    {
        abort_unless(auth()->user()?->canViewOperationLogs(), 403);
    }

    #[Url] public string $search = '';

    #[Url] public string $userFilter = '';

    #[Url] public string $actionFilter = '';

    #[Url] public string $columnFilter = '';

    /**
     * `column_name` 에 **컬럼명이 아닌 값**을 넣는 액션 — 「컬럼 전체」 목록에서 뺀다.
     *   assistant_query          질문 유형('guide'·'capital_status(denied)')
     *   vehicle_deleted_with_reason  ★차량번호★ — 삭제된 차는 대상 열에 번호가 안 떠서 여기 적었다
     *
     * ⚠️ 행 표시에서는 빼지 않는다 — 그 값이 어느 차·어떤 질문인지 알려주는 유일한 단서다.
     *    목록에서만 접는다(`buyer:{이름}` 을 「바이어 변경」 한 줄로 접는 것과 같은 처리).
     */
    private const NON_COLUMN_ACTIONS = ['assistant_query', 'vehicle_deleted_with_reason'];

    /** 대상(모델) 필터 — 「통장 잔액」처럼 종류로 찾는 진입점. 종전엔 없어서 컬럼명을 알아야만 찾을 수 있었다. */
    #[Url] public string $typeFilter = '';

    #[Url] public string $dateFrom = '';

    #[Url] public string $dateTo = '';

    #[Url] public int $perPage = 25;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function logs()
    {
        return AuditLog::query()
            ->with(['user', 'approvalRequest'])
            ->when($this->search !== '', fn ($q) => $this->applySearch($q))
            ->when($this->userFilter !== '', fn ($q) => $q->where('user_id', $this->userFilter))
            ->when($this->actionFilter !== '', fn ($q) => $q->where('action', $this->actionFilter))
            ->when($this->typeFilter !== '', fn ($q) => $q->where('auditable_type', $this->typeFilter))
            // 'buyer:*' = 바이어 변경 전체(값이 바이어명이라 개별 나열하면 드롭다운이 바이어 수만큼 늘어난다).
            ->when($this->columnFilter === self::BUYER_ANY, fn ($q) => $q->where('column_name', 'like', 'buyer:%'))
            // 단일 컬럼(`shipping_date`)과 일괄 기록(`shipping_date,eta_date`)을 함께 건진다.
            //   🚫 `like %name%` 만 쓰면 안 된다 — `type` 이 `payment_type` 에도 걸린다.
            //   콤마 경계를 붙여 **목록의 한 항목**으로만 매칭한다.
            ->when($this->columnFilter !== '' && $this->columnFilter !== self::BUYER_ANY,
                fn ($q) => $q->where(fn ($q2) => $q2
                    ->where('column_name', $this->columnFilter)
                    ->orWhereRaw("CONCAT(',', REPLACE(column_name, ' ', ''), ',') LIKE ?",
                        ['%,'.$this->columnFilter.',%'])))
            // 성능(jin 2026-07-23): whereDate 는 DATE(created_at) 로 index 무효 → 범위조건으로 created_at 인덱스 유지.
            //   audit_logs 는 무한 증가 테이블이라 풀스캔 방지 중요. (ssancar 504 교훈 패턴②)
            ->when($this->dateFrom !== '', fn ($q) => $q->where('created_at', '>=', $this->dateFrom.' 00:00:00'))
            ->when($this->dateTo !== '', fn ($q) => $q->where('created_at', '<=', $this->dateTo.' 23:59:59'))
            ->latest('created_at')
            ->paginate($this->perPage);
    }

    /**
     * 검색 = 차량번호(차량·정산·판매잔금·매입잔금 로그 전부) 또는 처리자 이름.
     * 차량번호로 매칭된 차량 → 그 차량의 정산/잔금 로그까지 함께 걸린다.
     */
    private function applySearch($q)
    {
        $term = '%'.$this->search.'%';
        $vehicleIds = \App\Models\Vehicle::where('vehicle_number', 'like', $term)->pluck('id')->all();
        $userIds = User::where('name', 'like', $term)->pluck('id')->all();

        $settlementIds = $vehicleIds ? \App\Models\Settlement::whereIn('vehicle_id', $vehicleIds)->pluck('id')->all() : [];
        $fpIds = $vehicleIds ? \App\Models\FinalPayment::whereIn('vehicle_id', $vehicleIds)->pluck('id')->all() : [];
        $pbpIds = $vehicleIds ? \App\Models\PurchaseBalancePayment::whereIn('vehicle_id', $vehicleIds)->pluck('id')->all() : [];

        return $q->where(function ($q2) use ($vehicleIds, $settlementIds, $fpIds, $pbpIds, $userIds) {
            $matched = false;
            if ($userIds) {
                $q2->orWhereIn('user_id', $userIds);
                $matched = true;
            }
            foreach ([
                [\App\Models\Vehicle::class, $vehicleIds],
                [\App\Models\Settlement::class, $settlementIds],
                [\App\Models\FinalPayment::class, $fpIds],
                [\App\Models\PurchaseBalancePayment::class, $pbpIds],
            ] as [$cls, $ids]) {
                if ($ids) {
                    $q2->orWhere(fn ($s) => $s->where('auditable_type', $cls)->whereIn('auditable_id', $ids));
                    $matched = true;
                }
            }
            if (! $matched) {
                $q2->whereRaw('1 = 0');   // 매칭 없으면 0건 (전체 노출 방지)
            }
        });
    }

    /**
     * 현재 페이지 로그의 차량번호 해석 [logId => 차량번호].
     * 차량 직접 로그 + 정산/판매잔금/매입잔금(→ vehicle_id) 로그를 배치 조회 (N+1 회피).
     */
    #[Computed]
    public function vehicleNumbers(): array
    {
        $byType = ['Vehicle' => [], 'Settlement' => [], 'FinalPayment' => [], 'PurchaseBalancePayment' => []];
        foreach ($this->logs as $log) {
            $short = class_basename($log->auditable_type);
            if (isset($byType[$short]) && $log->auditable_id) {
                $byType[$short][$log->auditable_id][] = $log->id;
            }
        }

        $map = [];

        if ($byType['Vehicle']) {
            // 🔑 `withTrashed` — 삭제 로그는 **지워진 차**를 가리킨다. 빼면 대상 열이 `#170` 으로만 떠서
            //    어느 차였는지 알 수 없고, 그게 차량번호를 column_name 에 적게 만든 원인이었다.
            $nums = \App\Models\Vehicle::withTrashed()
                ->whereIn('id', array_keys($byType['Vehicle']))->pluck('vehicle_number', 'id');
            foreach ($byType['Vehicle'] as $vid => $logIds) {
                foreach ($logIds as $lid) {
                    $map[$lid] = $nums[$vid] ?? null;
                }
            }
        }

        foreach ([
            'Settlement' => \App\Models\Settlement::class,
            'FinalPayment' => \App\Models\FinalPayment::class,
            'PurchaseBalancePayment' => \App\Models\PurchaseBalancePayment::class,
        ] as $short => $cls) {
            if (! $byType[$short]) {
                continue;
            }
            $vehByRecord = $cls::whereIn('id', array_keys($byType[$short]))->pluck('vehicle_id', 'id');
            $vehIds = array_values(array_filter(array_unique($vehByRecord->all())));
            $nums = $vehIds ? \App\Models\Vehicle::whereIn('id', $vehIds)->pluck('vehicle_number', 'id') : collect();
            foreach ($byType[$short] as $rid => $logIds) {
                $vid = $vehByRecord[$rid] ?? null;
                $num = $vid ? ($nums[$vid] ?? null) : null;
                foreach ($logIds as $lid) {
                    $map[$lid] = $num;
                }
            }
        }

        return $map;
    }

    #[Computed]
    public function users()
    {
        return User::orderBy('name')->get(['id', 'name']);
    }

    /** 바이어 변경(buyer:{이름})을 한 항목으로 접는 sentinel. */
    public const BUYER_ANY = 'buyer:*';

    /** 액션 — 값=원문, 라벨=한글. **한글 라벨 기준 정렬**(영문 순으로 두면 화면 순서가 뒤죽박죽으로 보인다). */
    #[Computed]
    public function distinctActions(): array
    {
        $rows = AuditLog::query()->distinct()->pluck('action')->filter()->all();
        $out = [];
        foreach ($rows as $a) {
            $out[$a] = ColumnLabel::action($a);
        }
        asort($out, SORT_LOCALE_STRING);

        return $out;
    }

    /** 대상(모델) — 「통장 잔액」·「전자서명 계약」처럼 종류로 고르는 필터. */
    #[Computed]
    public function distinctTypes(): array
    {
        $rows = AuditLog::query()->whereNotNull('auditable_type')->distinct()->pluck('auditable_type')->filter()->all();
        $out = [];
        foreach ($rows as $t) {
            $out[$t] = ColumnLabel::model($t);
        }
        asort($out, SORT_LOCALE_STRING);

        return $out;
    }

    /**
     * 컬럼 — 값=원문, 라벨=한글. 두 가지를 걸러낸다:
     *   ① 챗봇 질문(action='assistant_query')의 column_name 은 **컬럼이 아니라 질문 유형**이다.
     *      컬럼 목록에 'guide'·'capital_status(denied)' 가 섞이는 게 이 때문이었다(액션 필터로 고르면 된다).
     *   ② 'buyer:{바이어명}' 은 값이 박힌 동적 키라 바이어 수만큼 늘어난다 → 한 항목으로 접는다.
     * 라벨은 (대상, 컬럼) 쌍으로 만든다 — columnAny 는 테이블을 몰라 다른 표의 라벨을 집어올 수 있다.
     */
    #[Computed]
    public function distinctColumns(): array
    {
        $rows = AuditLog::query()
            ->whereNotNull('column_name')
            // 🚫 column_name 에 **컬럼이 아닌 값**이 들어가는 액션은 목록에서 뺀다(액션 필터로 고르면 된다).
            //    안 빼면 값 종류만큼 항목이 늘어난다 — 차를 지울 때마다, 질문할 때마다 하나씩(jin 2026-08-26).
            ->whereNotIn('action', self::NON_COLUMN_ACTIONS)
            ->distinct()
            ->get(['auditable_type', 'column_name']);

        $out = [];
        $hasBuyer = false;
        foreach ($rows as $r) {
            $name = (string) $r->column_name;
            if ($name === '') {
                continue;
            }
            if (str_starts_with($name, 'buyer:')) {
                $hasBuyer = true;

                continue;
            }
            // 🔑 일괄 작업은 컬럼을 콤마로 이어 붙여 기록한다(`shipping_date,eta_date,vessel_name`).
            //    그대로 담으면 **조합마다 목록이 하나씩 늘어난다** — 일괄 대상이 바뀔 때마다
            //    쓸모없는 항목이 쌓이고, 골라도 그 조합인 행만 걸린다(jin 2026-08-26 제보).
            //    쪼개서 개별 컬럼으로 담으면 조합이 몇 가지든 항목 수가 안 늘고,
            //    「선적일」을 고르면 그게 포함된 일괄 기록까지 함께 걸린다(아래 필터가 부분일치).
            foreach (explode(',', $name) as $part) {
                $part = trim($part);
                if ($part === '') {
                    continue;
                }
                $out[$part] = $r->auditable_type
                    ? ColumnLabel::column($r->auditable_type, $part)
                    : ColumnLabel::columnAny($part);
            }
        }
        if ($hasBuyer) {
            $out[self::BUYER_ANY] = '바이어 변경';
        }
        asort($out, SORT_LOCALE_STRING);

        return $out;
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'userFilter', 'actionFilter', 'typeFilter', 'columnFilter', 'dateFrom', 'dateTo']);
        $this->resetPage();
    }
}; ?>

<div wire:poll.30s class="flex h-full flex-col gap-4 p-3 md:p-6">
    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-xl font-bold text-gray-800">{{ __('log.audit_title') }}</h2>
            <p class="mt-0.5 text-xs text-gray-500">{{ __('log.audit_subtitle') }}</p>
        </div>
        <span class="text-xs text-gray-400">{{ __('log.total', ['count' => number_format($this->logs->total())]) }}</span>
    </div>

    {{-- 필터 --}}
    <div class="card flex flex-wrap items-center gap-2">
        <input wire:model.live.debounce.400ms="search" type="text" placeholder="{{ __('log.audit_search') }}"
               class="input-filter w-full sm:w-64" />
        <select wire:model.live="userFilter" class="input-filter">
            <option value="">{{ __('log.all_users') }}</option>
            @foreach($this->users as $u)
                <option value="{{ $u->id }}">{{ $u->name }}</option>
            @endforeach
        </select>
        <select wire:model.live="actionFilter" class="input-filter">
            <option value="">{{ __('log.all_actions') }}</option>
            @foreach($this->distinctActions as $value => $label)
                <option value="{{ $value }}" title="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
        <select wire:model.live="typeFilter" class="input-filter">
            <option value="">{{ __('log.all_types') }}</option>
            @foreach($this->distinctTypes as $value => $label)
                <option value="{{ $value }}" title="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
        <select wire:model.live="columnFilter" class="input-filter">
            <option value="">{{ __('log.all_columns') }}</option>
            @foreach($this->distinctColumns as $value => $label)
                <option value="{{ $value }}" title="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
        <input wire:model.live.debounce.400ms="dateFrom" type="date" class="input-filter" />
        <span class="text-gray-400 text-sm">~</span>
        <input wire:model.live.debounce.400ms="dateTo" type="date" class="input-filter" />
        <button wire:click="resetFilters" class="text-xs text-violet-600 hover:underline">{{ __('log.reset_filters') }}</button>
        <select wire:model.live="perPage" class="input-filter ml-auto">
            <option value="25">{{ __('common.per_page', ['count' => 25]) }}</option>
            <option value="50">{{ __('common.per_page', ['count' => 50]) }}</option>
            <option value="100">{{ __('common.per_page', ['count' => 100]) }}</option>
        </select>
    </div>

    {{-- 테이블 --}}
    <div class="hidden sm:block overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 text-left text-xs text-gray-500">
                    <th class="pb-2 pr-4 font-medium">{{ __('log.audit_col.time') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ __('log.audit_col.user') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ __('log.audit_col.target') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ __('log.audit_col.action') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ __('log.audit_col.column') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ __('log.audit_col.change') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ __('log.audit_col.ip') }}</th>
                    <th class="pb-2 pr-4 font-medium">{{ __('log.audit_col.approval') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($this->logs as $log)
                @php
                    $modelLabel = \App\Support\ColumnLabel::model($log->auditable_type);
                    // 챗봇 질문은 column_name 에 컬럼이 아니라 질문 유형이 들어간다(2026-07-31).
                    $columnLabel = $log->action === 'assistant_query'
                        ? \App\Support\ColumnLabel::assistantIntent($log->column_name)
                        : \App\Support\ColumnLabel::column($log->auditable_type, $log->column_name);
                    $actionLabel = \App\Support\ColumnLabel::action($log->action);
                    $vnum = $this->vehicleNumbers[$log->id] ?? null;
                @endphp
                <tr class="hover:bg-gray-50">
                    <td class="py-2 pr-4 font-mono text-xs text-gray-600 whitespace-nowrap">{{ $log->created_at->format('Y-m-d H:i:s') }}</td>
                    <td class="py-2 pr-4 text-gray-700">{{ $log->user?->name ?? __('log.system') }}</td>
                    <td class="py-2 pr-4 text-xs text-gray-500">
                        <span>{{ $modelLabel }}</span>
                        @if($vnum)
                            <span class="ml-1 font-medium text-gray-800">{{ $vnum }}</span>
                        @else
                            <span class="text-gray-400">#{{ $log->auditable_id }}</span>
                        @endif
                    </td>
                    <td class="py-2 pr-4">
                        @php
                            $actionBadge = match($log->action) {
                                'created' => 'badge-green',
                                'updated' => 'badge-blue',
                                'deleted' => 'badge-red',
                                'restored' => 'badge-amber',
                                'force_deleted' => 'badge-red',
                                default => 'badge-gray',
                            };
                        @endphp
                        <span class="badge {{ $actionBadge }} text-[10px]" title="{{ $log->action }}">{{ $actionLabel }}</span>
                    </td>
                    <td class="py-2 pr-4 text-xs text-gray-700"
                        @if($log->column_name) title="{{ $log->column_name }}" @endif>
                        {{ $columnLabel }}
                    </td>
                    <td class="py-2 pr-4 text-xs text-gray-600 max-w-md">
                        @php
                            $oldV = \App\Support\ColumnLabel::value($log->auditable_type, $log->column_name, $log->old_value);
                            $newV = \App\Support\ColumnLabel::value($log->auditable_type, $log->column_name, $log->new_value);
                        @endphp
                        @if($log->old_value !== null || $log->new_value !== null)
                            <span class="text-red-500 line-through">{{ \Illuminate\Support\Str::limit($oldV ?? '(null)', 40) }}</span>
                            <span class="mx-1 text-gray-400">→</span>
                            <span class="text-emerald-600">{{ \Illuminate\Support\Str::limit($newV ?? '(null)', 40) }}</span>
                        @else
                            <span class="text-gray-300">-</span>
                        @endif
                    </td>
                    <td class="py-2 pr-4 font-mono text-[10px] text-gray-400">{{ $log->ip_address ?? '-' }}</td>
                    <td class="py-2 pr-4 text-xs text-gray-500">
                        @if($log->approval_request_id)
                            <span class="font-mono text-violet-600">#{{ $log->approval_request_id }}</span>
                        @else
                            <span class="text-gray-300">-</span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="8" class="py-12 text-center text-sm text-gray-400">{{ __('log.audit_empty_filtered') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- 모바일 카드 --}}
    <div class="block sm:hidden space-y-2">
        @forelse($this->logs as $log)
        @php
            $modelLabelM = \App\Support\ColumnLabel::model($log->auditable_type);
            $columnLabelM = $log->action === 'assistant_query'
                ? \App\Support\ColumnLabel::assistantIntent($log->column_name)
                : \App\Support\ColumnLabel::column($log->auditable_type, $log->column_name);
            $actionLabelM = \App\Support\ColumnLabel::action($log->action);
            $vnumM = $this->vehicleNumbers[$log->id] ?? null;
        @endphp
        <div class="card-tight">
            <div class="flex items-center justify-between">
                <span class="font-mono text-[10px] text-gray-400">{{ $log->created_at->format('Y-m-d H:i') }}</span>
                <span class="badge badge-blue text-[10px]" title="{{ $log->action }}">{{ $actionLabelM }}</span>
            </div>
            <div class="mt-1 text-sm font-medium text-gray-800">{{ $log->user?->name ?? __('log.system') }}</div>
            <div class="text-xs text-gray-500">
                {{ $modelLabelM }} {{ $vnumM ? $vnumM : '#'.$log->auditable_id }}
                @if($log->column_name) · <span title="{{ $log->column_name }}">{{ $columnLabelM }}</span> @endif
            </div>
            @if($log->old_value !== null || $log->new_value !== null)
            @php
                $oldVM = \App\Support\ColumnLabel::value($log->auditable_type, $log->column_name, $log->old_value);
                $newVM = \App\Support\ColumnLabel::value($log->auditable_type, $log->column_name, $log->new_value);
            @endphp
            <div class="mt-1 text-xs">
                <span class="text-red-500 line-through">{{ \Illuminate\Support\Str::limit($oldVM ?? '(null)', 30) }}</span>
                →
                <span class="text-emerald-600">{{ \Illuminate\Support\Str::limit($newVM ?? '(null)', 30) }}</span>
            </div>
            @endif
        </div>
        @empty
        <div class="py-12 text-center text-sm text-gray-400">{{ __('log.audit_empty') }}</div>
        @endforelse
    </div>

    <div>{{ $this->logs->links() }}</div>
</div>
