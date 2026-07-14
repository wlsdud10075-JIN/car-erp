<?php

use App\Models\ApprovalRequest;
use App\Models\Buyer;
use App\Models\Consignee;
use App\Models\Country;
use App\Models\FinalPayment;
use App\Models\ForwardingCompany;
use App\Models\InterVehicleTransfer;
use App\Models\PurchaseBalancePayment;
use App\Models\Salesman;
use App\Models\ShippingRequest;
use App\Models\TaskAlarm;
use App\Models\Vehicle;
use App\Services\InterVehicleTransferService;
use App\Services\NiceApiService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination, WithFileUploads;

    // ── 목록 필터 ─────────────────────────────────────────────────
    #[Url] public string $search = '';
    #[Url] public string $dateType = 'all';
    #[Url] public string $dateFrom = '';
    #[Url] public string $dateTo = '';
    // 큐 16 — channelFilter Url 파라미터 제거 (채널 단일).
    #[Url] public string $progressFilter = '';
    // 진행상태 pill 3상태 순환: 미선택(회색) → 이것만(보라, progressFilter) → 제외(빨강, excludeStatuses).
    // 전체 유지 + 완료건 제외 같은 다중 제외 지원. progress_status_cache 인덱스로 whereNotIn.
    #[Url(as: 'exclude')] public array $excludeStatuses = [];
    // 대시보드 처리 필요 액션 카드에서 진입 시 동일 산정 로직으로 필터링.
    // 값: purchase_unpaid / sale_unpaid / clearance_needed / shipping_needed / dhl_needed
    #[Url] public string $action = '';
    #[Url] public string $salesmanId = '';
    // 2026-06-19 — 선적요청 배치 딥링크: ?ids=1,2,3 → 그 차량만 조회(입금률·게이트 한눈에 + 묶음 처리).
    #[Url] public string $ids = '';
    // 정산 등 외부 화면에서 ?openVehicle=ID 로 진입 → 해당 차량 편집 패널 자동 오픈.
    #[Url] public ?int $openVehicle = null;
    // 회의확장씬 #3 Phase 2-4 (2026-05-23) — 필터바 바이어 select.
    #[Url] public string $buyerId = '';
    #[Url] public int $perPage = 10;

    // #3 다중차량 선적 서류 — 체크박스로 선택한 차량 id (export 차량만). 선택 N대 → 1서류.
    public array $shipDocIds = [];

    public function clearShipDocSelection(): void
    {
        $this->shipDocIds = [];
        unset($this->selectedShipVehicles);
    }

    // 전자서명 요청 (2026-07-10) — 선택 export 차량(동일 바이어·통화)으로 서명 세션 발급 후 링크 표시(복사→바이어 전달).
    public bool $showSignModal = false;

    public ?string $signUrl = null;

    public ?string $signContractNo = null;

    public function requestSignature(): void
    {
        $ids = collect($this->shipDocIds)->map(fn ($x) => (int) $x)->filter()->unique()->values();
        $byId = \App\Models\Vehicle::whereIn('id', $ids)->get()->keyBy('id');
        $vehicles = $ids->map(fn (int $id) => $byId->get($id))->filter()->values();

        $user = auth()->user();
        if ($vehicles->isEmpty() || ! $vehicles->every(fn ($v) => $user->canScopeVehicle($v))) {
            $this->dispatch('notify', message: __('signed_contract.notify.scope_denied'), type: 'error');

            return;
        }

        try {
            $result = app(\App\Services\Documents\SigningSessionService::class)->issue($vehicles, null, $user->id);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->dispatch('notify', message: $e->validator->errors()->first(), type: 'warning');

            return;
        }

        $this->signUrl = $result['url'];
        $this->signContractNo = $result['contract']->contract_no;
        $this->showSignModal = true;
        $this->dispatch('notify', message: __('signed_contract.notify.issued'), type: 'success');
    }

    /** 서류탭 — 편집 중인 차량 1대의 현 서명 상태(칩). */
    #[Computed]
    public function editingSign(): array
    {
        if (! $this->editingId) {
            return ['status' => 'none'];
        }
        $v = \App\Models\Vehicle::find($this->editingId);
        if (! $v) {
            return ['status' => 'none'];
        }
        $sessions = \App\Models\SignedContract::where('buyer_id', $v->buyer_id)
            ->whereIn('status', [
                \App\Models\SignedContract::STATUS_SIGNED,
                \App\Models\SignedContract::STATUS_VIEWED,
                \App\Models\SignedContract::STATUS_PENDING,
            ])->latest('id')->get();
        $c = \App\Models\SignedContract::pickForSet($sessions, [$this->editingId]);

        return $c ? ['status' => $c->status, 'id' => $c->id] : ['status' => 'none'];
    }

    /** 서류탭 — 편집 중인 차량 1대로 서명 세션 발급. */
    public function requestSignatureForVehicle(): void
    {
        $v = \App\Models\Vehicle::find($this->editingId);
        $user = auth()->user();
        if (! $v || ! $user->canScopeVehicle($v)) {
            $this->dispatch('notify', message: __('signed_contract.notify.scope_denied'), type: 'error');

            return;
        }
        try {
            $result = app(\App\Services\Documents\SigningSessionService::class)->issue(collect([$v]), null, $user->id);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->dispatch('notify', message: $e->validator->errors()->first(), type: 'warning');

            return;
        }
        $this->signUrl = $result['url'];
        $this->signContractNo = $result['contract']->contract_no;
        $this->showSignModal = true;
        unset($this->editingSign);
        $this->dispatch('notify', message: __('signed_contract.notify.issued'), type: 'success');
    }

    /** 활성 세션 링크 재표시(복사) — batch/서류탭 공용. */
    public function showSignLink(int $contractId): void
    {
        $c = \App\Models\SignedContract::find($contractId);
        if (! $c || $c->isSigned()) {
            return;
        }
        $user = auth()->user();
        $vehicles = \App\Models\Vehicle::whereIn('id', $c->vehicle_ids ?? [])->get();
        if ($vehicles->isEmpty() || ! $vehicles->every(fn ($v) => $user->canScopeVehicle($v))) {
            $this->dispatch('notify', message: __('signed_contract.notify.scope_denied'), type: 'error');

            return;
        }
        $this->signContractNo = $c->contract_no;
        $this->signUrl = $c->signingUrl();
        $this->showSignModal = true;
    }

    /**
     * ② 하이브리드 — 선택한 export 차량들이 "한 선적 묶음(batch)"에 온전히 속하면 그 batch_id 반환.
     * 여러 묶음에 걸치거나 묶음 없으면 null → 면허비 딥링크 비노출(완전묶음만 허용, 부분/혼합 실수 차단).
     */
    #[Computed]
    public function selectedBundle(): ?string
    {
        if (empty($this->shipDocIds)) {
            return null;
        }
        $batches = ShippingRequest::whereIn('vehicle_id', $this->shipDocIds)
            ->where('status', '!=', ShippingRequest::STATUS_CANCELLED)
            ->pluck('batch_id')->unique()->values();

        return $batches->count() === 1 ? (string) $batches->first() : null;
    }

    // ── 누적검색 (차량번호로 하나씩 검색 → 선택셋 shipDocIds 에 누적) ──────────
    //   접이식 검색란. 목록 검색·페이지를 바꿔도 shipDocIds 는 유지되므로(리셋 없음) 여러 검색에 걸쳐 누적된다.
    //   누적된 셋이 곧 「선적요청으로 묶기」 대상. (jin 2026-07-08 — board 지연 대비 ERP 자체 묶음.)
    public bool $accumSearchOpen = false;

    public string $accumSearchTerm = '';

    public function toggleAccumSearch(): void
    {
        $this->accumSearchOpen = ! $this->accumSearchOpen;
    }

    /**
     * 누적검색 (jin 2026-07-09 (a)안) — 담기 아니라 "목록 검색". 입력어로 메인 목록을 필터링하고,
     * 결과에서 체크박스로 선택하면 shipDocIds 에 담겨 칩(태그)으로 누적된다. 검색을 바꿔도 칩은 유지.
     */
    public function searchAccum(): void
    {
        $this->search = trim($this->accumSearchTerm);
        $this->resetPage();
    }

    public function removeFromAccumulation(int $id): void
    {
        $this->shipDocIds = array_values(array_filter(
            array_map('intval', $this->shipDocIds),
            fn ($x) => $x !== $id
        ));
        unset($this->selectedShipVehicles);
    }

    /** 선택된 선적 묶기 대상 차량 (칩 표시용). 검색·페이지를 바꿔도 유지되는 누적 선택셋. */
    #[\Livewire\Attributes\Computed]
    public function selectedShipVehicles()
    {
        $ids = array_values(array_filter(array_map('intval', $this->shipDocIds)));
        if (empty($ids)) {
            return collect();
        }

        return Vehicle::whereIn('id', $ids)->get(['id', 'vehicle_number']);
    }

    /**
     * 누적/선택한 차량을 하나의 선적요청 묶음(batch)으로 생성 → 「선적요청」 화면으로 이동.
     * board 지연 대비 ERP 자체 묶음(jin 2026-07-08). board 발과 동일 파이프라인 — 이후 씬은 동일.
     *  - 권한 = canAccessClearance (관리·수출통관·admin·manager). 차량별 canScopeVehicle 재인가(IDOR).
     *  - export 채널만. 이미 open 묶음(requested/in_progress)인 차량은 skip(이중 묶음 방지).
     *  - 값 = 각 차량 export 값 그대로(buyer/consignee/method). method 미지정이면 RORO(컬럼 NOT NULL). status=requested.
     */
    public function bundleToShipping(): void
    {
        $user = auth()->user();
        abort_unless((bool) $user?->canAccessClearance(), 403);

        $ids = array_values(array_unique(array_map('intval', $this->shipDocIds)));
        if (empty($ids)) {
            $this->dispatch('notify', message: __('vehicle.accum.bundle_empty'), type: 'error');

            return;
        }

        $vehicles = Vehicle::whereIn('id', $ids)->get();
        $batchId = (string) \Illuminate\Support\Str::uuid();
        $created = [];
        $skipped = [];

        \Illuminate\Support\Facades\DB::transaction(function () use ($vehicles, $user, $batchId, &$created, &$skipped) {
            foreach ($vehicles as $vehicle) {
                if (! $user->canScopeVehicle($vehicle)) {
                    $skipped[] = $vehicle->vehicle_number;   // 스코프 밖

                    continue;
                }
                if ($vehicle->sales_channel !== 'export') {
                    $skipped[] = $vehicle->vehicle_number;   // 수출 채널만

                    continue;
                }
                $inOpen = ShippingRequest::where('vehicle_id', $vehicle->id)
                    ->whereIn('status', ShippingRequest::OPEN_STATUSES)
                    ->exists();
                if ($inOpen) {
                    $skipped[] = $vehicle->vehicle_number;   // 이미 진행중 묶음 있음

                    continue;
                }

                ShippingRequest::create([
                    'batch_id' => $batchId,
                    'vehicle_id' => $vehicle->id,
                    'buyer_id' => $vehicle->export_buyer_id ?: null,
                    'consignee_id' => $vehicle->export_consignee_id ?: null,
                    // 컬럼 NOT NULL — 미지정 차량은 RORO 기본값(선적요청 화면/차량편집에서 정정 가능)
                    'shipping_method' => in_array($vehicle->shipping_method, ShippingRequest::METHODS, true)
                        ? $vehicle->shipping_method : ShippingRequest::METHODS[0],
                    'requested_by_email' => $user->email,
                    'status' => ShippingRequest::STATUS_REQUESTED,
                    'requested_at' => now(),
                ]);
                $this->fireShippingRequestAlarm($vehicle);
                $created[] = $vehicle->id;
            }
        });

        if (empty($created)) {
            $this->dispatch('notify', message: __('vehicle.accum.bundle_all_skipped', ['skipped' => implode(', ', $skipped)]), type: 'error');

            return;
        }

        $this->shipDocIds = [];
        $this->accumSearchOpen = false;

        session()->flash('bundle_created', [
            'count' => count($created),
            'skipped' => $skipped,
        ]);

        $this->redirect(route('erp.shipping-requests.index', ['search' => $batchId]), navigate: true);
    }

    /** 선적요청 알람 — 수출통관(board store() 와 동일). 관리도 scopeVisibleTo 로 가시. */
    private function fireShippingRequestAlarm(Vehicle $vehicle): void
    {
        $alarm = TaskAlarm::firstOrNew([
            'type' => 'shipping_requested', 'vehicle_id' => $vehicle->id, 'resolved_at' => null,
        ]);
        $alarm->target_role = '수출통관';
        $alarm->due_date = now();
        $alarm->message_meta = TaskAlarm::sanitizeMeta([
            'vehicle_number' => $vehicle->vehicle_number,
            'shipping_method' => $vehicle->shipping_method,
        ]);
        $alarm->save();
    }

    // ── 탁송비 명세서 일괄 기입 (건바이건 비용 — 위카 등 업체 명세서 붙여넣기 → 차량번호 매칭) ──
    //   면허비(묶음 n/1)와 정반대 축: 묶음 무관, 전체 차량 대상(canApprove). 비용 컬럼만 기입(BulkVehicleCostService).
    public bool $showCostImport = false;

    public string $costImportColumn = 'cost_towing';

    public string $costImportCompany = 'wika';   // 거래처(서식) — 회사별 좌표 파서 분기

    public string $costImportRaw = '';

    public $costImportFile = null;   // xlsx 직접 업로드 (위카 등 업체 명세서 파일)

    // ['matched' => [['id','number','model','current','amount'], ...], 'unmatched' => [['number','amount'], ...]]
    public array $costImportParsed = [];

    public function openCostImport(): void
    {
        abort_unless((bool) auth()->user()?->canApprove(), 403);
        $this->reset(['costImportRaw', 'costImportFile', 'costImportParsed']);
        $this->costImportColumn = 'cost_towing';
        $this->costImportCompany = 'wika';
        $this->showCostImport = true;
    }

    public function closeCostImport(): void
    {
        $this->showCostImport = false;
        $this->reset(['costImportRaw', 'costImportFile', 'costImportParsed']);
    }

    /** 대상 비용 전환 시 미리보기 초기화 + 거래처를 해당 비용의 기본값으로 리셋(현대A1 선택 후 면허 전환 시 stale 방지). */
    public function updatedCostImportColumn(): void
    {
        $this->costImportCompany = $this->costImportColumn === 'cost_license' ? 'mutual' : 'wika';
        $this->reset(['costImportRaw', 'costImportFile', 'costImportParsed']);
    }

    /** 거래처(서식) 전환 시 미리보기 초기화 — 회사별 파서 포맷이 달라 잔여 미리보기 혼선 방지. */
    public function updatedCostImportCompany(): void
    {
        $this->reset(['costImportRaw', 'costImportFile', 'costImportParsed']);
    }

    /** 거래처 화이트리스트 검증 — costImportCompany 는 wire:model 이라 클라 주입 가능(IDOR 방어, SKILLS #26). */
    private function assertCostCompanyValid(): void
    {
        $valid = \App\Models\Vehicle::COST_IMPORT_COMPANIES[$this->costImportColumn] ?? [];
        abort_unless(in_array($this->costImportCompany, $valid, true), 422);
    }

    /** 붙여넣은 명세서 파싱 — 줄 단위. */
    public function parseCostImport(): void
    {
        abort_unless((bool) auth()->user()?->canApprove(), 403);
        abort_unless(in_array($this->costImportColumn, \App\Models\Vehicle::BULK_COST_UPLOAD_FIELDS, true), 422);
        // 붙여넣기는 위카(범용 파서)만 — 좌표 파서 회사는 셀 위치 고정이라 xlsx 전용(차종 숫자 오파싱 방지).
        abort_unless($this->costImportColumn === 'cost_towing' && $this->costImportCompany === 'wika', 422);

        $rows = [];
        foreach (preg_split('/\r\n|\r|\n/', trim($this->costImportRaw)) as $line) {
            $parsed = $this->parseCostLine($line);
            if ($parsed) {
                $rows[] = $parsed;
            }
        }
        $this->applyCostRowsToPreview($rows);
    }

    /** 파일 선택 즉시 자동 파싱 — 「파일 읽기」 누락으로 미리보기 안 뜨는 마찰 제거. */
    public function updatedCostImportFile(): void
    {
        if ($this->costImportFile) {
            $this->parseCostImportFile();
        }
    }

    /** xlsx 업로드 파싱 — 각 행의 셀을 이어붙여 줄 단위 파서에 넣음(위카 시트0: E=차량번호·J=합계 자동). */
    public function parseCostImportFile(): void
    {
        abort_unless((bool) auth()->user()?->canApprove(), 403);
        abort_unless(in_array($this->costImportColumn, \App\Models\Vehicle::BULK_COST_UPLOAD_FIELDS, true), 422);
        $this->assertCostCompanyValid();
        $this->validate(['costImportFile' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240']]);

        // 면허비 — 뮤추얼만 xlsx 파서(2행 1레코드·수출신고번호 n/1). 성지는 선적요청 딥링크라 업로드 없음.
        if ($this->costImportColumn === 'cost_license') {
            abort_if($this->costImportCompany === 'seongji', 422);
            $this->parseLicenseFile();

            return;
        }

        // 탁송비 — 구천육·현대A1 은 좌표 고정 파서, 위카는 기존 범용 파서.
        if (isset(\App\Models\Vehicle::TOWING_IMPORT_LAYOUTS[$this->costImportCompany])) {
            $rows = $this->parseTowingByLayout(\App\Models\Vehicle::TOWING_IMPORT_LAYOUTS[$this->costImportCompany]);
        } else {
            try {
                $rows = [];
                $ss = \PhpOffice\PhpSpreadsheet\IOFactory::load($this->costImportFile->getRealPath());
                foreach ($ss->getSheet(0)->getRowIterator() as $row) {
                    $cells = [];
                    $it = $row->getCellIterator();
                    $it->setIterateOnlyExistingCells(false);
                    foreach ($it as $cell) {
                        $cells[] = trim((string) $cell->getCalculatedValue());
                    }
                    $parsed = $this->parseCostLine(implode(' ', $cells));
                    if ($parsed) {
                        $rows[] = $parsed;
                    }
                }
            } catch (\Throwable $e) {
                $this->dispatch('notify', message: __('vehicle.cost_import.file_error'), type: 'error');

                return;
            }
        }
        $this->applyCostRowsToPreview($rows);
    }

    /**
     * 회사별 좌표 고정 탁송비 파서 — 지정 시작행부터 차량번호열·금액 성분열을 직접 읽는다.
     * 범용 '마지막 숫자' 파서의 차종 숫자(아우디 Q5→5)·비고 오파싱을 피한다. 금액=성분열 합(수식셀 의존 X).
     */
    private function parseTowingByLayout(array $layout): array
    {
        $rows = [];
        try {
            $ss = \PhpOffice\PhpSpreadsheet\IOFactory::load($this->costImportFile->getRealPath());
            $sheet = $ss->getSheet(0);
            $highest = $sheet->getHighestRow();
            for ($r = $layout['start']; $r <= $highest; $r++) {
                // 차량번호 — 앞뒤·내부 공백 제거 후 정규식 검증(합계행·빈행·헤더 skip).
                $plate = preg_replace('/\s+/u', '', (string) $sheet->getCell($layout['plate'].$r)->getCalculatedValue());
                if (! preg_match('/^\d{2,3}[가-힣]\d{4}$/u', $plate)) {
                    continue;
                }
                // 금액 = 성분열 합(구천육 F+G, 현대A1 I+J) — 총액 정의를 코드에 명시, 계산엔진 의존 제거.
                $amount = 0;
                foreach ($layout['amount'] as $col) {
                    $amount += (int) str_replace(',', '', (string) $sheet->getCell($col.$r)->getCalculatedValue());
                }
                if ($amount <= 0) {
                    continue;
                }
                $rows[] = ['plate' => $plate, 'amount' => $amount];
            }
        } catch (\Throwable $e) {
            $this->dispatch('notify', message: __('vehicle.cost_import.file_error'), type: 'error');

            return [];
        }

        return $rows;
    }

    /** 한 줄에서 차량번호(2~3숫자+한글+4숫자) + 금액(차량번호 제외 마지막 숫자=합계) 추출. */
    private function parseCostLine(string $line): ?array
    {
        $line = trim($line);
        if ($line === '' || ! preg_match('/(\d{2,3}\s*[가-힣]\s*\d{4})/u', $line, $m)) {
            return null;
        }
        $plate = preg_replace('/\s+/u', '', $m[1]);
        $rest = str_replace($m[1], ' ', $line);
        if (! preg_match_all('/[\d,]+/', $rest, $nums) || empty($nums[0])) {
            return null;
        }

        return ['plate' => $plate, 'amount' => (int) str_replace(',', '', end($nums[0]))];
    }

    /** 추출 행(plate/amount) → 차량번호 매칭 → 미리보기(matched/unmatched). 같은 차량번호 첫 줄만. */
    private function applyCostRowsToPreview(array $rows): void
    {
        // 같은 차량번호가 여러 줄(취소 후 재진행 등)이면 금액 합산 (jin 2026-07-03). 등장 순서 유지.
        $sums = [];
        $order = [];
        foreach ($rows as $r) {
            if (! isset($sums[$r['plate']])) {
                $order[] = $r['plate'];
                $sums[$r['plate']] = 0;
            }
            $sums[$r['plate']] += $r['amount'];
        }

        $matched = [];
        $unmatched = [];
        foreach ($order as $plate) {
            $amount = $sums[$plate];
            $vehicle = \App\Models\Vehicle::where('vehicle_number', $plate)->first();
            if ($vehicle) {
                $matched[] = [
                    'id' => $vehicle->id,
                    'number' => $vehicle->vehicle_number,
                    'model' => trim(($vehicle->brand ?? '').' '.($vehicle->model_type ?? '')),
                    'current' => (int) $vehicle->{$this->costImportColumn},
                    'amount' => $amount,
                    // 2차 정산 마감 차량 = 재업로드로 안 건드림(보호). 미리보기에서 회색 '마감' 표시.
                    'finalized' => $vehicle->settlements()->where('secondary_status', 'closed')->exists(),
                ];
            } else {
                $unmatched[] = ['number' => $plate, 'amount' => $amount];
            }
        }

        $this->costImportParsed = ['matched' => $matched, 'unmatched' => $unmatched];

        if (empty($matched) && empty($unmatched)) {
            $this->dispatch('notify', message: __('vehicle.cost_import.parse_empty'), type: 'warning');
        }
    }

    /**
     * 면허비 명세서 파싱 — 통관 면허비 시트(2행 1레코드) → 수출신고번호로 ERP 차량 묶음 매칭 → 합계 n/1 분배.
     *   레코드 = 홀수행(신고번호 B·품명 E) + 짝수행(수량 E·합계 J). 합계 = 수수료+부가세(통관 면허비 총액).
     *   n/1 분모 = 파일 수량(짝수행 E). 매칭수 ≠ 수량이면 미리보기에 경고(수량 기준 분배 유지 — 개별 몫 왜곡 방지).
     */
    private function parseLicenseFile(): void
    {
        try {
            $ss = \PhpOffice\PhpSpreadsheet\IOFactory::load($this->costImportFile->getRealPath());
            $records = $this->extractLicenseRecords($ss->getSheet(0));
        } catch (\Throwable $e) {
            $this->dispatch('notify', message: __('vehicle.cost_import.file_error'), type: 'error');

            return;
        }
        $this->applyLicenseRecordsToPreview($records);
    }

    /** 면허비 시트에서 레코드 추출 — B열 숫자 10자리 이상 = 신고번호 행(홀수), 다음 행(짝수)에서 수량(E)·합계(J). */
    private function extractLicenseRecords(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): array
    {
        $records = [];
        $highest = $sheet->getHighestRow();
        for ($r = 1; $r <= $highest; $r++) {
            $decl = trim((string) $sheet->getCell('B'.$r)->getCalculatedValue());
            // 신고번호 판별: 숫자 10자리 이상(날짜 8자리·헤더 텍스트와 구분).
            if (strlen(preg_replace('/\D/', '', $decl)) < 10) {
                continue;
            }
            $qty = (int) preg_replace('/\D/', '', (string) $sheet->getCell('E'.($r + 1))->getCalculatedValue());
            $total = (int) str_replace(',', '', (string) $sheet->getCell('J'.($r + 1))->getCalculatedValue());
            if ($total <= 0) {
                continue;
            }
            $records[] = [
                'decl' => $decl,
                'car_name' => trim((string) $sheet->getCell('E'.$r)->getCalculatedValue()),
                'qty' => $qty,
                'total' => $total,
            ];
        }

        return $records;
    }

    /** 추출 레코드 → 수출신고번호 정규화 매칭 → 수량 n/1 → 미리보기(matched 평탄화 + groups 요약 + unmatched). */
    private function applyLicenseRecordsToPreview(array $records): void
    {
        $matched = [];
        $unmatched = [];
        $groups = [];
        foreach ($records as $rec) {
            $norm = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $rec['decl']));
            if ($norm === '') {
                continue;
            }
            // 저장 포맷 변동(대시·공백·끝문자) 흡수 — 정규화 후 비교.
            $vehicles = \App\Models\Vehicle::whereRaw(
                "UPPER(REPLACE(REPLACE(REPLACE(export_declaration_number,'-',''),' ',''),'.','')) = ?",
                [$norm]
            )->get();

            $count = $vehicles->count();
            // 분모 = 파일 수량 우선(진짜 대수), 없으면 매칭수. 매칭 0 이면 미매칭 처리.
            $divisor = $rec['qty'] > 0 ? $rec['qty'] : $count;
            if ($count === 0 || $divisor === 0) {
                $unmatched[] = ['number' => $rec['decl'], 'amount' => $rec['total']];

                continue;
            }

            $per = intdiv($rec['total'], $divisor);
            // 정수나눗셈 반올림 잔여는 파일수량==매칭수(전부 ERP에 존재)일 때만 첫 차량에 흡수.
            // 수량 불일치(매칭<파일수량)면 누락 차 몫은 버림 → 미리보기 per 와 기입값 일치 (jin 2026-07-02).
            $remainder = $count === $divisor ? $rec['total'] - $per * $divisor : 0;
            $i = 0;
            foreach ($vehicles as $v) {
                $matched[] = [
                    'id' => $v->id,
                    'number' => $v->vehicle_number,
                    'model' => trim(($v->brand ?? '').' '.($v->model_type ?? '')),
                    'current' => (int) $v->cost_license,
                    'amount' => $per + ($i === 0 ? $remainder : 0),
                    'finalized' => $v->settlements()->where('secondary_status', 'closed')->exists(),
                    'decl' => $rec['decl'],
                ];
                $i++;
            }
            $groups[] = [
                'decl' => $rec['decl'],
                'car_name' => $rec['car_name'],
                'qty' => $rec['qty'],
                'matched' => $count,
                'total' => $rec['total'],
                'per' => $per,
                'mismatch' => $rec['qty'] > 0 && $rec['qty'] !== $count,
            ];
        }

        $this->costImportParsed = ['matched' => $matched, 'unmatched' => $unmatched, 'mode' => 'license', 'groups' => $groups];

        if (empty($matched) && empty($unmatched)) {
            $this->dispatch('notify', message: __('vehicle.cost_import.lic_parse_empty'), type: 'warning');
        }
    }

    /** 미리보기 확정 → 매칭 차량에 비용 일괄 기입(fleet-wide, 잠금해제 자동 + 감사). */
    public function applyCostImport(): void
    {
        abort_unless((bool) auth()->user()?->canApprove(), 403);

        $amounts = [];
        $finalizedCount = 0;
        foreach ($this->costImportParsed['matched'] ?? [] as $row) {
            if (! empty($row['finalized'])) {
                $finalizedCount++;   // 2차 마감 = 재업로드로 안 건드림(제외)

                continue;
            }
            $amt = (int) round((float) str_replace(',', '', (string) ($row['amount'] ?? 0)));
            if ($amt < 0) {
                continue;
            }
            $amounts[(int) $row['id']] = $amt;
        }
        if (empty($amounts)) {
            $this->dispatch('notify', message: __('vehicle.cost_import.no_matched'), type: 'error');

            return;
        }

        $label = __('vehicle.field.'.$this->costImportColumn);
        $reason = $label.' 명세서 일괄 기입 ('.now()->format('Y-m-d').', '.count($amounts).'대)';
        try {
            $res = app(\App\Services\BulkVehicleCostService::class)
                ->apply($this->costImportColumn, $amounts, auth()->user(), $reason, true);
        } catch (\Throwable $e) {
            // 한 대라도 저장 가드/제약에 걸리면 전체 롤백 — 사유를 토스트로 노출(무반응 방지).
            $this->dispatch('notify', message: __('vehicle.cost_import.apply_failed', ['msg' => $e->getMessage()]), type: 'error');

            return;
        }

        $this->closeCostImport();
        $msg = __('vehicle.cost_import.applied', ['count' => $res['applied']]);
        if (($res['unchanged'] ?? 0) > 0) {
            $msg .= ' '.__('vehicle.cost_import.unchanged_suffix', ['count' => $res['unchanged']]);
        }
        if ($finalizedCount > 0) {
            $msg .= ' '.__('vehicle.cost_import.finalized_suffix', ['count' => $finalizedCount]);
        }
        $this->dispatch('notify', message: $msg, type: 'success');
    }

    // 회의확장씬 #10 Phase 2-3 (2026-05-23) — 헤더 클릭 정렬.
    // localStorage 가 캐시 (Alpine), 진실은 Livewire property (server-side orderBy).
    public string $sortColumn = 'created_at';

    public string $sortDirection = 'desc';

    // DB 컬럼만 정렬 허용 (accessor 정렬 X — 페이지네이션 + accessor 호환 X).
    private const SORTABLE_COLUMNS = [
        'vehicle_number', 'brand', 'progress_status_cache',
        'purchase_date', 'sale_date', 'shipping_date', 'bl_issue_date',
        'salesman_id', 'sale_price', 'purchase_price',
        'currency', 'exchange_rate', 'sales_channel', 'buyer_id', 'created_at',
    ];

    public function setSort(string $col): void
    {
        if (! in_array($col, self::SORTABLE_COLUMNS, true)) {
            return;
        }
        if ($this->sortColumn === $col) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortColumn = $col;
            $this->sortDirection = 'asc';
        }
    }

    // ── 슬라이드 패널 상태 ────────────────────────────────────────
    public bool $showPanel = false;
    public ?int $editingId = null;

    // 동시 편집 잠금 (2026-06-30) — 한 차량을 두 사람이 동시에 수정해 덮어쓰는 사고 방지.
    //   캐시 TTL 잠금 + wire:poll 하트비트. 2번째로 연 사람은 읽기 전용(저장·승인·삭제는 서버에서 423 차단).
    //   클라이언트가 못 바꾸도록 #[Locked].
    #[\Livewire\Attributes\Locked]
    public bool $editLockedByOther = false;

    #[\Livewire\Attributes\Locked]
    public string $editLockOwnerName = '';
    // 큐 14-4-4 — 신규 등록 직후 같은 패널이 편집 모드로 재로드될 때(H14 next-step 동선),
    // 헤더 배지·차량번호 readonly 강조로 "이미 저장됨"을 시각적으로 알리기 위한 마커.
    // close()/openEdit() 진입 시 reset.
    public ?int $justCreatedId = null;

    // ── 큐 21 — Ledger 잠금 상태 (회의록 2026-05-18) ───────────────
    // confirmed FinalPayment OR PurchaseBalancePayment 1건 이상 → isLedgerLocked = true.
    // super/admin·본인 팀 관리가 [잠금 해제] 모달에서 사유 입력 → unlock 토큰 발급 → hasLedgerUnlockToken = true.
    // 저장 1회 후 토큰 소비되어 다시 false로. openEdit / save 후 갱신.
    public bool $isLedgerLocked = false;
    public bool $hasLedgerUnlockToken = false;
    // 잠금 해제 권한 = super/admin(전체) + role '관리'(본인 팀). refreshLedgerLockState 에서 편집 차량 기준 갱신.
    public bool $canUnlockLedger = false;
    public bool $showLedgerUnlockModal = false;
    public string $ledgerUnlockReason = '';

    // ── 큐 21 후속 — 말소·수출통관 체크/문서 mismatch 확인 모달 (사용자 결정 2026-05-18) ──
    // 운영 흐름상 체크와 서류 업로드 순서가 비순차적. 강제 차단 대신 모달로 인지 강제.
    public bool $showDocCheckModal = false;
    public bool $userConfirmedDocCheckMismatch = false;
    public array $docCheckMismatches = [];

    // ── 기본정보 ──────────────────────────────────────────────────
    public string $vehicle_number = '';
    public string $sales_channel = 'export';
    public string $brand      = '';
    public string $model_type = '';
    public string $year_str   = '';
    public string $cc_str     = '';
    public string $weight_kg_str = '';
    public string $mileage_str   = '';
    public string $color = '';

    // ── NICE API 등록정보 12 ──────────────────────────────────────
    public string $nice_reg_vin          = '';
    public string $nice_reg_engine_no    = '';
    public string $nice_reg_fuel_type    = '';
    public string $nice_reg_use_type     = '';
    public string $nice_reg_vehicle_form = '';
    public string $nice_reg_first_date   = '';
    public string $nice_reg_date         = '';
    public string $nice_reg_owner_name   = '';
    public string $nice_reg_owner_addr   = '';
    public string $nice_reg_owner_rrn    = '';
    public string $nice_reg_max_load_str = '';
    public string $nice_reg_passengers_str = '';
    public string $nice_reg_color        = '';

    // ── NICE API 제원정보 12 ──────────────────────────────────────
    public string $nice_spec_maker           = '';
    public string $nice_spec_model           = '';
    public string $nice_spec_year            = '';
    public string $nice_spec_displacement_str = '';
    public string $nice_spec_transmission    = '';
    public string $nice_spec_drive_type      = '';
    public string $nice_spec_length_str      = '';
    public string $nice_spec_width_str       = '';
    public string $nice_spec_height_str      = '';
    public string $nice_spec_wheelbase_str   = '';
    public string $nice_spec_curb_weight_str = '';
    public string $nice_spec_fuel_efficiency = '';

    // NICE 조회 응답 원본(ssancar data) — 저장 시 nice_raw 컬럼에 보존 (미매핑 필드 재조회 없이 활용).
    public array $niceRaw = [];

    // ── 매입 ──────────────────────────────────────────────────────
    public string $purchase_date = '';
    public string $salesman_id_str = '';
    public string $purchase_from  = '';
    // karaba 전용 매입 기본 (Setting::isKaraba() 게이팅) — 매입증빙 자유입력 / 거래처구분 드롭박스
    public string $purchase_evidence_type = '';
    public string $purchase_partner_type  = '';
    // 큐 20-A/C — 매입처 계좌 4컬럼 (account는 모델 cast로 자동 암호화)
    public string $purchase_seller_bank    = '';
    public string $purchase_seller_account = '';
    public string $purchase_seller_holder  = '';
    public string $purchase_bank_memo      = '';
    // 매도비 계좌 (매입가 계좌와 별도 주체, 2026-07-03)
    public string $purchase_fee_bank    = '';
    public string $purchase_fee_account = '';
    public string $purchase_fee_holder  = '';
    public string $purchase_price_str    = '';
    public string $selling_fee_str       = '';
    public string $cost_deregistration_str = '';
    public string $cost_license_str   = '';
    public string $cost_towing_str    = '';
    public string $cost_carry_str     = '';
    public string $cost_shoring_str   = '';
    public string $cost_insurance_str = '';
    public string $cost_transfer_str  = '';
    public string $cost_extra1_str    = '';
    public string $cost_extra2_str    = '';
    public string $down_payment_str        = '';
    public string $selling_fee_payment_str = '';
    public string $purchase_remittance_memo = '';
    public string $registration_number = '';
    public string $reg_cert_number = '';
    public bool   $is_deregistered = false;
    public string $deregistration_date = '';   // 말소등록일 (NICE 비제공 수동입력, 통관 구매리스트 B7)
    public array  $purchaseBalancePayments = [];

    // ── 판매 ──────────────────────────────────────────────────────
    public string $sale_date    = '';
    public string $currency     = 'USD';
    public string $exchange_rate_str = '';
    public string $buyer_id_str     = '';
    public string $consignee_id_str = '';
    public string $sale_price_str       = '';
    public string $tax_dc_str           = '';
    public string $commission_str       = '';
    public string $transport_fee_str    = '';
    public string $auto_loading_str     = '';
    public string $sale_other_costs_str = '';
    // 22-A-3a 사용자 정정 (2026-05-20) — 4 항목 _str 복귀.
    // 4 input 은 입금 분류 메타데이터 (계약금/중도금/선수금) — 재무·관리자만 입력.
    // 실 저장은 final_payments.type 별 confirmed row. 화면 표시는 type별 합산.
    public string $deposit_down_payment_str = '';
    public string $interim_payment_str = '';
    public string $advance_payment1_str = '';
    public string $fee_str               = '';   // 2026-05-28 — 구 선수금2(advance_2) → 송금 수수료(fee) 재용도화 (셀러 부담)
    public string $savings_used_str     = '';
    // 회의확장씬 #12 (2026-05-22) — 적립금 적립 입력 (한 번 저장 → SavingsStatus EARNED 거래 → reset).
    // 누적 표시는 buyerSavingsBalance computed (SavingsStatus 단일 출처).
    public string $savings_deposit_str  = '';
    public array  $finalPayments = [];

    // 판매탭 미납률 표시 (수정 불가, openEdit 시 갱신)
    //   null  = 판매 전 (sale_total_amount=0) → "—"
    //   0     = 완납
    //   > 0   = 미납 (0~1)
    public ?float $panelUnpaidRatio = null;

    // 판매탭 총판매가·남은잔금 표시 (수정 불가, openEdit 시 갱신 — panelUnpaidRatio 와 동일 스냅샷)
    //   null = 판매 전 (sale_total_amount <= 0) → "—"
    public ?float $panelSaleTotal = null;   // sale_total_amount (SKILLS §13 미수율 분모, 통화 단위)
    public ?float $panelSaleUnpaid = null;  // sale_unpaid_amount (남은 잔금, 통화 단위)

    // 매입탭 미지급 요약 (판매탭 미러링, openEdit 스냅샷). null = 매입 전 (purchase_price <= 0) → "—"
    //   총매입액 = purchase_price + selling_fee / 미지급 = purchase_unpaid_amount(SKILLS §13, KRW)
    public ?int $panelPurchaseTotal = null;
    public ?int $panelPurchasePaid = null;
    public ?int $panelPurchaseUnpaid = null;
    // karaba 요약 항목별 완납 (구입금액 / 매도비 각각 owed vs paid) — A3
    public ?int $panelPurchasePriceTotal = null;   // 구입금액 owed = purchase_price
    public ?int $panelPurchasePricePaid = null;    // 구입금액 paid = 계약금 + 확정 잔금(PBP)
    public ?int $panelSellingFeeTotal = null;       // 매도비 owed = selling_fee
    public ?int $panelSellingFeePaid = null;        // 매도비 paid = 매도비 지급

    // 매입 과입금 정정 사유 (인라인, 재무 권한 · 사유 필수). correctPurchaseOverpay 에서 소비.
    public string $purchaseOverpayReason = '';

    // "저장하고 계속" — 저장 후 패널을 닫지 않고 재로드(스냅샷 즉시 갱신). 확정 모달 바운스 넘어서 유지되도록 프로퍼티.
    public bool $keepPanelOpen = false;

    // 큐 19-C — 차량 간 자금 이체 요청 모달 상태
    public bool $showTransferRequestModal = false;

    public string $transferTargetVehicleId = '';

    public string $transferAmountStr = '';

    public string $transferReason = '';

    public string $transferNotes = '';

    // 큐 19-E — 이체 취소(void) 요청 모달 상태
    public bool $showTransferVoidModal = false;

    public ?int $voidTransferId = null;

    public string $voidReason = '';

    // UX #3 (2026-05-20) — 영업 저장 확인 모달.
    // 사용자 정정: 영업 [저장] → 모달에 매입+판매 필수 항목 미리보기 → [확인] 시 실제 save.
    // 다른 role (admin/재무/관리/통관) 은 모달 없이 직접 save (빠른 처리).
    public bool $showSaveConfirmModal = false;
    // 회의확장씬 (2026-05-22) — 저장 확인 모달 탭별 미리보기 분기.
    //   'purchase' / 'sale' / 'bl' / 'clearance' — 그 외(basic/dhl/docs) 즉시 save (모달 X).
    public string $activeTabForSave = 'purchase';

    // 큐 16 — 카풀/헤이맨 계산서 5 properties 제거 (DB 5컬럼 drop과 동기).

    // ── 수출통관 ──────────────────────────────────────────────────
    public string $export_buyer_id_str     = '';
    public string $export_consignee_id_str = '';
    public string $forwarding_company_id_str = '';
    public string $export_declaration_amount_str  = '';
    public string $export_declaration_number      = '';
    public string $shipping_date     = '';
    public string $eta_date          = '';
    public string $shipping_method   = '';
    public string $port_of_loading   = '';
    // 2026-05-21 — CIPL 이식 — 인코텀즈 + 도착항 마스터 FK
    public string $incoterms              = '';
    public string $discharge_port_id_str  = '';
    public bool   $is_export_cleared = false;

    // ── 작업2 (2026-05-27) — 바이어·컨사이니 인라인 quick-add (패널 안 닫고 즉석 등록) ──
    public bool   $quickAddOpen     = false;
    public string $quickAddType     = '';   // 'buyer' | 'consignee'
    public string $quickAddContext  = '';   // 'sale' | 'export' | 'bl'
    public string $quickAddBuyerName = '';   // consignee 등록 시 종속 바이어명 표시용
    public string $qaName         = '';
    public string $qaCountryId    = '';
    public string $qaSalesmanId   = '';
    public string $qaContactName  = '';
    public string $qaContactPhone = '';

    // ── 미수 매입 게이트 (2026-07-08) — 바이어 미수율 초과 시 신규 등록 차단 + 관리 인라인 승인 ──
    //   게이트는 등록 시점(신규 or 바이어 교체)만 발동 → 지속 우회 토큰 불필요. (가)=그 차 1건만 통과 자동충족.
    public bool   $showPurchaseGate     = false;
    public array  $purchaseGateInfo     = [];   // ['buyer','ratio','unpaid','count']
    public string $purchaseGateReason   = '';
    public bool   $purchaseGateApproved = false;

    // ── 선적 (B/L) ────────────────────────────────────────────────
    public string $bl_buyer_id_str     = '';
    public string $bl_consignee_id_str = '';
    public string $bl_number           = '';
    public string $container_number    = '';
    public string $bl_loading_location = '';
    public string $vessel_name         = '';
    public string $bl_type             = '';   // 오리지널/써랜더 — 이중가드 관리 확인값(영업 요청 = shipping_requests.bl_type)
    public string $bl_issue_date       = '';
    public string $document_deadline_date = '';   // item 6 — 선적 서류마감일 (5일전 알람 트리거)

    // ── DHL ───────────────────────────────────────────────────────
    public string $dhl_recipient_name    = '';
    public string $dhl_recipient_address = '';
    public string $dhl_recipient_phone   = '';
    public string $dhl_sender_name       = '';
    public string $dhl_sender_address    = '';
    public string $dhl_weight_str        = '';
    public string $dhl_dimensions        = '';
    public bool   $dhl_request           = false;

    public string $memo = '';

    // ── 파일 업로드 ───────────────────────────────────────────────
    public $deregistrationDocFile    = null;
    public $exportDeclarationDocFile = null;
    public $blDocFile                = null;

    // 기존 파일 경로 (수정 시 표시용)
    public string $deregistration_document_path     = '';
    public string $export_declaration_document_path = '';
    public string $bl_document_path                 = '';

    // 국내 딜러 말소등록증 알림톡 전달용 번호. 사용자가 직접 입력·저장하며 deregistration_notice_phone
    // 컬럼에 유지된다(바이어 번호 아님 — 국내 딜러 대상, jin 2026-07-10).
    public string $deregistrationBuyerPhone         = '';

    // "기존 파일 삭제" 액션 플래그 (UI 버튼 → save 시 컬럼 null + 디스크 삭제)
    public bool $clearDeregistrationDoc    = false;
    public bool $clearExportDeclarationDoc = false;
    public bool $clearBlDoc                = false;

    // ── 차량 첨부 (사진·PDF·Excel·Word·HWP 등, 최대 10건 — vehicle_photos 테이블) ──────
    public const MAX_PHOTOS = 10;

    public array $photoFiles = [];      // 누적 버퍼 — 여러 번 선택해도 합쳐짐 (updatedPhotoUpload 가 merge)
    public array $photoUpload = [];     // input 바인딩 (선택 즉시 photoFiles 로 누적 후 비움)
    public array $existingPhotos = [];  // 저장된 사진 [['id'=>, 'url'=>], ...] (편집 시 표시)
    public array $deletePhotoIds = [];  // 개별 삭제 staged → save 시 DB row + 디스크 제거

    // ── 선적 탭 선박 사진 (vehicle_photos category='shipping', 최대 30건) ──
    // 기본정보 탭 차량사진과 같은 machinery, 별도 갤러리로 분리 (2026-07-06 jin).
    // 선적 사진은 여러 컷이 필요해 별도 한도 30건 (2026-07-07 jin, 기본정보 갤러리는 10건 유지).
    public const MAX_SHIP_PHOTOS = 30;

    public array $shipPhotoFiles = [];
    public array $shipPhotoUpload = [];
    public array $existingShipPhotos = [];
    public array $deleteShipPhotoIds = [];

    // 메일 발송 (서류 탭 → 바이어에게 업로드 문서 전달)
    public bool $showMailModal = false;

    public string $mailTo = '';

    public string $mailSubject = '';

    public string $mailBody = '';

    public array $mailDocIds = [];       // 선택된 첨부 key ('photo:{id}' | 'file:{col}' | 'gen:{type}')

    public array $mailDocsUpload = [];   // 기본정보 탭 업로드 사진·첨부 (category != shipping) [['key'=>, 'name'=>], ...]

    public array $mailDocsShip = [];     // 선적 탭 선박 사진·첨부 (category = shipping)

    public array $mailDocsFile = [];     // 단계 업로드 파일(말소·수출신고서·B/L)

    public array $mailDocsGen = [];      // 자동생성 서류(서류 탭)

    public ?string $mailNotice = null;   // 발신 설정 미완 등 안내

    // 메일 첨부 후보 — 단계 업로드 파일 컬럼 / 자동생성 서류 type (VehicleDocumentController 와 정합)
    private const MAIL_STAGE_FILES = ['deregistration_document', 'export_declaration_document', 'bl_document'];

    private const MAIL_GEN_ALL = ['deregistration', 'deregistration_contract', 'poa', 'clearance'];

    private const MAIL_GEN_EXPORT = ['invoice', 'sales_contract', 'container_invoice_packing', 'container_contract', 'roro_invoice_packing', 'roro_contract'];

    public function mount(): void
    {
        // 정산 등에서 ?openVehicle=ID 진입 → 해당 차량 편집 패널 자동 오픈 + 매입 탭(기타비용 수정 동선).
        if ($this->openVehicle) {
            try {
                $this->openEdit($this->openVehicle);
                $this->dispatch('switch-tab', tab: 'purchase');
            } catch (\Throwable $e) {
                // 접근 불가(스코프/미존재) — 조용히 목록만 표시
            }
        }

        // 대시보드에서 진입한 경우 — action(처리 필요 카드) 또는 progressFilter(파이프라인 스트립):
        // 날짜 기본 필터를 적용하지 않는다. 카드 카운트와 목록 카운트의 정합성을 위해
        // 산정 로직(전체 기간)과 동일 범위에서 목록을 보여줘야 함.
        if ($this->action !== '' || $this->progressFilter !== '' || $this->ids !== '') {
            return;
        }

        $this->dateFrom = $this->dateFrom ?: now()->subYear()->format('Y-m-d');
        $this->dateTo = $this->dateTo ?: now()->format('Y-m-d');
    }

    public function applyFilters(): void
    {
        unset($this->vehicles);
        $this->resetPage();
    }

    /** 초기화 — 차량관리 진입 직후 상태로 원복(검색·기간·진행·담당·바이어·누적·정렬 전부 리셋). */
    public function resetFilters(): void
    {
        $this->reset([
            'search', 'dateType', 'progressFilter', 'excludeStatuses', 'action',
            'salesmanId', 'ids', 'buyerId', 'shipDocIds', 'accumSearchTerm', 'accumSearchOpen',
            'sortColumn', 'sortDirection',
        ]);
        // dateType='all' 로 리셋되므로 기간 필터는 무시되지만, 진입 시 기본값과 동일하게 채워둔다.
        $this->dateFrom = now()->subYear()->format('Y-m-d');
        $this->dateTo = now()->format('Y-m-d');
        unset($this->vehicles);
        $this->resetPage();
    }

    // 자동생성 서류 type → 서류 탭 라벨(vehicle.docs.*) 매핑
    private function mailGenLabel(string $type): string
    {
        $map = [
            'deregistration' => 'deregistration', 'deregistration_contract' => 'derg_contract', 'poa' => 'poa',
            'invoice' => 'invoice', 'sales_contract' => 'sales_contract',
            'container_invoice_packing' => 'container_invoice_packing', 'container_contract' => 'container_contract',
            'roro_invoice_packing' => 'roro_invoice_packing', 'roro_contract' => 'roro_contract',
            'clearance' => 'clearance_set',
        ];

        return __('vehicle.docs.'.($map[$type] ?? $type));
    }

    private function mailStageLabel(string $col): string
    {
        return match ($col) {
            'deregistration_document' => __('vehicle.mail.file_deregistration'),
            'export_declaration_document' => __('vehicle.mail.file_export_declaration'),
            'bl_document' => __('vehicle.mail.file_bl'),
            default => $col,
        };
    }

    private function mailGenAllowed(\App\Models\Vehicle $v, string $type): bool
    {
        if (in_array($type, self::MAIL_GEN_ALL, true)) {
            return true;
        }

        return in_array($type, self::MAIL_GEN_EXPORT, true) && $v->sales_channel === 'export';
    }

    /**
     * 매입 과입금 인라인 정정 (jin 2026-07-10) — 국내 지급이라 실과지급은 거의 없고 99% 단순 오기입.
     * 판매(적립금 전환)와 달리 매입엔 크레딧 개념이 없어, 초과분만큼 확정 PBP 를 깎아 완납으로 정정하고
     * 사유를 남긴다. 매입 PBP 는 정산 마진·환차·이월과 무관(마진=purchase_price 기준)이라 secondary-closed
     * 가드 불필요(판매 convertOverpayToSavings 와 이 점이 다름).
     *   권한 = canConfirmFinance(관리·업무관리자·재무·admin) + canScopeVehicle 재인가(IDOR #26). 사유 필수.
     *   초과분이 확정 PBP 총액을 넘으면 차단(다른 출처 과지급 — 개별 확인). PBP saved 훅이 캐시 자동 갱신(매입완료 flip).
     */
    public function correctPurchaseOverpay(): void
    {
        if ($this->editingId === null) {
            return;
        }
        $user = auth()->user();
        $vehicle = \App\Models\Vehicle::find($this->editingId);
        if (! $vehicle || ! $user?->canScopeVehicle($vehicle)) {
            abort(403, __('vehicle.toast.edit_own_only'));
        }
        abort_unless((bool) $user->canConfirmFinance(), 403);

        $reason = trim($this->purchaseOverpayReason);
        if ($reason === '') {
            $this->dispatch('notify', message: __('vehicle.overpay.reason_required'), type: 'warning');

            return;
        }

        $excess = -1 * (int) $vehicle->purchase_unpaid_amount;   // 과입금 = 음수 미지급의 절대값 (매입=KRW 정수)
        if ($excess <= 0) {
            $this->dispatch('notify', message: __('vehicle.overpay.not_overpaid'), type: 'warning');

            return;
        }

        // 확정 PBP(최근분부터)로 초과분 커버. 초과분이 확정 PBP 총액 초과 시 차단(다른 출처 과지급).
        $confirmedPbps = $vehicle->purchaseBalancePayments()
            ->whereNotNull('confirmed_at')
            ->where('amount', '>', 0)
            ->orderByDesc('id')
            ->get();
        if ($excess > (int) $confirmedPbps->sum('amount')) {
            $this->dispatch('notify', message: __('vehicle.overpay.exceeds_confirmed'), type: 'warning');

            return;
        }

        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($vehicle, $confirmedPbps, $excess, $user, $reason) {
                $remaining = $excess;
                \App\Models\PurchaseBalancePayment::$allowConfirmedMutation = true;
                try {
                    foreach ($confirmedPbps as $pbp) {
                        if ($remaining <= 0) {
                            break;
                        }
                        $cut = min((int) $pbp->amount, $remaining);
                        // 사유를 finance_note 에 append — 재무가 transfers 매입 잔금에서 "왜 줄었나" 바로 확인.
                        $note = trim(($pbp->finance_note ? $pbp->finance_note."\n" : '')
                            .'[과입금정정 '.now()->format('Y-m-d').'] '.$reason.' (−'.number_format($cut).')');
                        $pbp->update(['amount' => (int) $pbp->amount - $cut, 'finance_note' => $note]);
                        $remaining -= $cut;
                    }
                } finally {
                    \App\Models\PurchaseBalancePayment::$allowConfirmedMutation = false;
                }

                \App\Models\AuditLog::create([
                    'user_id' => $user->id,
                    'auditable_type' => \App\Models\Vehicle::class,
                    'auditable_id' => $vehicle->id,
                    'action' => 'purchase_overpay_corrected',
                    'column_name' => 'purchase_balance_payments',
                    'old_value' => '과입금 '.number_format($excess),
                    'new_value' => $reason,
                    'ip_address' => request()?->ip(),
                ]);
            });
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('correctPurchaseOverpay failed', ['vehicle' => $vehicle->id, 'msg' => $e->getMessage()]);
            $this->dispatch('notify', message: __('vehicle.overpay.failed'), type: 'error');

            return;
        }

        // 매입 요약 스냅샷 갱신 (openEdit 매입 파트와 동일 규칙).
        $v = $vehicle->fresh();
        $purchaseTotal = (int) ($v->purchase_price ?? 0) + (int) ($v->selling_fee ?? 0);
        $this->panelPurchaseTotal = $purchaseTotal > 0 ? $purchaseTotal : null;
        $this->panelPurchaseUnpaid = $purchaseTotal > 0 ? (int) $v->purchase_unpaid_amount : null;
        $this->panelPurchasePaid = $purchaseTotal > 0 ? $purchaseTotal - $this->panelPurchaseUnpaid : null;
        // karaba 항목별 (구입금액/매도비) — 매도비 paid=selling_fee_payment, 구입금액 paid=총지급-매도비지급
        $this->panelPurchasePriceTotal = $purchaseTotal > 0 ? (int) $v->purchase_price : null;
        $this->panelSellingFeeTotal = $purchaseTotal > 0 ? (int) $v->selling_fee : null;
        $this->panelSellingFeePaid = $purchaseTotal > 0 ? (int) $v->selling_fee_payment : null;
        $this->panelPurchasePricePaid = $purchaseTotal > 0 ? $this->panelPurchasePaid - (int) $v->selling_fee_payment : null;
        $this->purchaseOverpayReason = '';

        $this->dispatch('notify', message: __('vehicle.overpay.done', ['amount' => number_format($excess)]), type: 'success');
    }

    // 메일 발송 모달 열기 — 첨부 후보 3그룹(업로드/단계파일/자동생성) + 바이어 이메일 프리필.
    public function openMailModal(): void
    {
        if ($this->editingId === null) {
            return;
        }
        $vehicle = \App\Models\Vehicle::with(['buyer', 'photos'])->find($this->editingId);
        if (! $vehicle || ! auth()->user()?->canScopeVehicle($vehicle)) {
            abort(403, __('vehicle.toast.edit_own_only'));
        }

        // ① 업로드 사진·첨부
        // 업로드 사진첨부 = 기본정보(category != shipping) / 선적(category = shipping) 그룹 분리 (jin 2026-07-06)
        $this->mailDocsUpload = $vehicle->photos->where('category', '!=', 'shipping')
            ->map(fn ($p) => ['key' => 'photo:'.$p->id, 'name' => $p->filename])->values()->all();
        $this->mailDocsShip = $vehicle->photos->where('category', 'shipping')
            ->map(fn ($p) => ['key' => 'photo:'.$p->id, 'name' => $p->filename])->values()->all();

        // ② 단계 업로드 파일 (있는 것만)
        $stage = [];
        foreach (self::MAIL_STAGE_FILES as $col) {
            if (! empty($vehicle->$col)) {
                $stage[] = ['key' => 'file:'.$col, 'name' => $this->mailStageLabel($col).' ('.basename($vehicle->$col).')'];
            }
        }
        $this->mailDocsFile = $stage;

        // ③ 자동생성 서류 (채널별)
        $genTypes = self::MAIL_GEN_ALL;
        if ($vehicle->sales_channel === 'export') {
            $genTypes = array_merge($genTypes, self::MAIL_GEN_EXPORT);
        }
        $this->mailDocsGen = array_map(fn ($t) => ['key' => 'gen:'.$t, 'name' => $this->mailGenLabel($t)], $genTypes);

        $this->mailDocIds = [];
        $this->mailTo = $vehicle->buyer?->contact_email ?? '';

        $company = \App\Support\CompanyMailConfig::active();
        $this->mailSubject = $company->companyLabel().' - '.$vehicle->vehicle_number;
        $this->mailBody = __('vehicle.mail.body_default', ['company' => $company->companyLabel()]);
        $this->mailNotice = $company->isConfigured() ? null : __('vehicle.mail.not_configured');
        $this->resetErrorBag(['mailTo']);
        $this->showMailModal = true;
    }

    public function closeMailModal(): void
    {
        $this->showMailModal = false;
    }

    // 발송 — 회사 방식(Gmail/SES)대로. 선택 첨부는 이 차량 소속만(IDOR 방지). 결과는 로그 기록.
    public function sendVehicleMail(): void
    {
        if ($this->editingId === null) {
            return;
        }
        $vehicle = \App\Models\Vehicle::with('photos')->find($this->editingId);
        if (! $vehicle || ! auth()->user()?->canScopeVehicle($vehicle)) {
            abort(403, __('vehicle.toast.edit_own_only'));
        }

        $to = trim($this->mailTo);
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->addError('mailTo', __('vehicle.mail.to_invalid'));

            return;
        }

        $company = \App\Support\CompanyMailConfig::active();
        if (! $company->isConfigured()) {
            $this->dispatch('notify', message: __('vehicle.mail.not_configured'), type: 'warning');

            return;
        }

        // 선택 key 해석 — 이 차량 소속만(IDOR 방지)
        $storedFiles = [];   // 업로드 사진·단계 파일
        $genTypes = [];      // 자동생성 서류 type
        $docNames = [];
        foreach ($this->mailDocIds as $key) {
            if (str_starts_with($key, 'photo:')) {
                $p = $vehicle->photos->firstWhere('id', (int) substr($key, 6));
                if ($p) {
                    $storedFiles[] = ['path' => $p->path, 'name' => $p->filename];
                    $docNames[] = $p->filename;
                }
            } elseif (str_starts_with($key, 'file:')) {
                $col = substr($key, 5);
                if (in_array($col, self::MAIL_STAGE_FILES, true) && ! empty($vehicle->$col)) {
                    $storedFiles[] = ['path' => $vehicle->$col, 'name' => basename($vehicle->$col)];
                    $docNames[] = basename($vehicle->$col);
                }
            } elseif (str_starts_with($key, 'gen:')) {
                $type = substr($key, 4);
                if ($this->mailGenAllowed($vehicle, $type)) {
                    $genTypes[] = $type;
                }
            }
        }

        // 자동생성 서류 → xlsx 바이너리 (실패 시 발송 중단)
        $dataFiles = [];
        try {
            foreach ($genTypes as $type) {
                $filler = new \App\Services\Documents\DocumentFiller($vehicle);
                $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($filler->spreadsheet($type));
                $writer->setPreCalculateFormulas(false);
                ob_start();
                $writer->save('php://output');
                $binary = ob_get_clean();
                $name = $filler->filename($type);
                $dataFiles[] = ['data' => $binary, 'name' => $name, 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
                $docNames[] = $name;
            }
        } catch (\Throwable $e) {
            $this->dispatch('notify', message: __('vehicle.mail.gen_failed'), type: 'error');

            return;
        }

        $subject = trim($this->mailSubject) !== '' ? trim($this->mailSubject) : $company->companyLabel();
        $mailable = (new \App\Mail\VehicleDocumentMail($subject, (string) $this->mailBody, $storedFiles, $dataFiles))->to($to);

        $logBase = [
            'vehicle_id' => $vehicle->id,
            'user_id' => auth()->id(),
            'channel' => $company->channel,
            'from_address' => $company->fromAddress,
            'to_email' => $to,
            'subject' => $subject,
            'document_names' => $docNames,
        ];

        try {
            $company->send($mailable);
        } catch (\Throwable $e) {
            \App\Models\MailDeliveryLog::create($logBase + ['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)]);
            $this->dispatch('notify', message: __('vehicle.mail.send_failed'), type: 'error');

            return;
        }

        \App\Models\MailDeliveryLog::create($logBase + ['status' => 'sent', 'error' => null]);
        $this->showMailModal = false;
        $this->dispatch('notify', message: __('vehicle.mail.sent'), type: 'success');
    }

    public function updatedChannelFilter(): void
    {
        unset($this->vehicles);
        $this->resetPage();
    }

    public function updatedProgressFilter(): void
    {
        unset($this->vehicles);
        $this->resetPage();
    }

    /**
     * 진행상태 pill 3상태 순환.
     *  - '전체'('') 클릭 → 포함·제외 전부 리셋
     *  - 회색(미선택) → 보라(이것만 보기, progressFilter 단일)  ※ 제외 목록도 클리어
     *  - 보라(이것만)  → 빨강(제외, excludeStatuses 에 추가)
     *  - 빨강(제외)    → 회색(미선택, excludeStatuses 에서 제거)
     * 제외는 다중 가능, 포함(이것만)은 단일. 둘은 상호 배타.
     */
    public function cycleProgress(string $val): void
    {
        if ($val === '') {
            $this->progressFilter = '';
            $this->excludeStatuses = [];
        } elseif ($this->progressFilter === $val) {
            $this->progressFilter = '';
            $this->excludeStatuses = array_values(array_unique([...$this->excludeStatuses, $val]));
        } elseif (in_array($val, $this->excludeStatuses, true)) {
            $this->excludeStatuses = array_values(array_filter($this->excludeStatuses, fn ($s) => $s !== $val));
        } else {
            $this->progressFilter = $val;
            $this->excludeStatuses = [];
        }

        unset($this->vehicles);
        $this->resetPage();
    }

    public function updatedSalesmanId(): void
    {
        unset($this->vehicles);
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        if (! in_array($this->perPage, [10, 30, 50, 100], true)) {
            $this->perPage = 10;
        }
        unset($this->vehicles);
        $this->resetPage();
    }

    #[Computed]
    public function vehicles()
    {
        $dateColumn = match ($this->dateType) {
            'sale'           => 'sale_date',
            'deregistration' => 'deregistration_date',
            'shipping'       => 'shipping_date',
            'bl'             => 'bl_issue_date',
            default          => 'purchase_date',
        };
        // 선적 묶기(jin 2026-07-09 (a)안): 검색과 선택 분리 — 목록은 항상 검색/필터 결과를 보여주고,
        //   선택(shipDocIds)은 위쪽 칩(태그)으로 별도 표시. 목록을 담긴 것만으로 하이재킹하지 않는다.
        // dateType='all' → 날짜 무관 전체조회 (기간 필터 skip). 'balance' → 잔금입금 whereHas 별도.
        $applyDateFilter = $this->dateType !== 'all' && $this->dateType !== 'balance';

        // 2026-05-20 #3 — 영업 role 본인 차량 한정. admin/super/관리/통관/재무는 전체.
        // 편집 권한 (L590~) 과 정합 — 본 사용자 차량만 노출 → 다른 영업 차량 클릭 시도 자체 차단.
        $user = auth()->user();
        $restrictToOwnSalesman = $user && ! $user->isAdmin() && ! $user->isManager() && $user->role === '영업' && $user->salesman;

        // 회의확장씬 #11 (2026-05-22) — [관리] 본인 담당 영업의 차량만.
        // admin/super 전체. 영업은 위 restrictToOwnSalesman 우선. [관리]만 subordinates 의 salesman.
        // subordinates 0명 → whereIn([]) → 빈 결과 (의도. /admin/users 에서 배정 안내 필요).
        $restrictToManagerScope = $user && ! $user->isAdmin() && ! $user->isManager() && $user->role === '관리';
        $managerScopeSalesmanIds = $restrictToManagerScope ? $user->getSubordinateSalesmanIds() : [];

        return Vehicle::query()
            ->with(['buyer', 'salesman', 'finalPayments', 'purchaseBalancePayments', 'receivableHistories'])
            ->when($restrictToOwnSalesman, fn ($q) => $q->where('salesman_id', $user->salesman->id))
            ->when($restrictToManagerScope, fn ($q) => $q->whereIn('salesman_id', $managerScopeSalesmanIds))
            ->when($this->search, fn ($q) => $q->where(fn ($q2) => $q2
                ->where('vehicle_number', 'like', "%{$this->search}%")
                ->orWhere('brand', 'like', "%{$this->search}%")
                ->orWhere('model_type', 'like', "%{$this->search}%")
                ->orWhere('nice_reg_owner_name', 'like', "%{$this->search}%")
                ->orWhere('export_declaration_number', 'like', "%{$this->search}%")
                ->orWhere('nice_reg_vin', 'like', "%{$this->search}%")   // 차대번호 — 끝 6자리 등 부분 검색
                ->orWhere('vessel_name', 'like', "%{$this->search}%")       // 선박명(VSL)
                ->orWhere('container_number', 'like', "%{$this->search}%")  // 컨테이너번호
                ->orWhere('purchase_from', 'like', "%{$this->search}%")     // 구입처(매입처)
            ))
            ->when($this->ids !== '', fn ($q) => $q->whereIn('id', array_filter(array_map('intval', explode(',', $this->ids)))))
            ->when($this->progressFilter, fn ($q) => $q->where('progress_status_cache', $this->progressFilter))
            ->when($this->excludeStatuses, fn ($q) => $q->whereNotIn('progress_status_cache', $this->excludeStatuses))
            ->when($this->salesmanId !== '', fn ($q) => $q->where('salesman_id', $this->salesmanId))
            ->when($this->buyerId !== '', fn ($q) => $q->where('buyer_id', $this->buyerId))
            ->when($this->action !== '', fn ($q) => $this->applyActionFilter($q))
            ->when($applyDateFilter && $this->dateFrom, fn ($q) => $q->where($dateColumn, '>=', $this->dateFrom))
            ->when($applyDateFilter && $this->dateTo, fn ($q) => $q->where($dateColumn, '<=', $this->dateTo))
            // 잔금입금 모드 — 선택 기간에 판매 잔금(final_payments)이 입금된 차량만 (날짜별 잔금입금내역 조회)
            ->when($this->dateType === 'balance', fn ($q) => $q->whereHas('finalPayments', fn ($fp) => $fp
                ->when($this->dateFrom, fn ($x) => $x->whereDate('payment_date', '>=', $this->dateFrom))
                ->when($this->dateTo, fn ($x) => $x->whereDate('payment_date', '<=', $this->dateTo))))
            ->orderBy($this->sortColumn, $this->sortDirection)
            ->paginate($this->perPage);
    }

    /**
     * 대시보드 카드 카운트와 vehicles 목록 SQL where 100% 일치.
     * 14 액션(영업 5 / 통관 7 / 정산 5) + 관리자 2 = 16 케이스를
     * Vehicle::scopeAction()에서 통합 정의. SKILLS.md §9 action 파라미터 패턴.
     */
    private function applyActionFilter($q)
    {
        return $q->action($this->action);
    }

    // jin 2026-07-06 quick win ⑦ — 차량편집 바이어 드롭다운도 [관리]/영업 팀 스코프로 솔팅.
    //   buyersForFilter/buyers/index 와 동일 규칙: admin=전체 / 영업=본인(직접 salesman_id + 차량 간접) / 관리=subordinate.
    //   ⚠ 편집 중 차량의 현재 바이어(판매/통관/B-L)는 스코프 밖이어도 항상 포함 — 드롭다운에서 사라져
    //     저장 시 선택이 소실되는 것 방지. (buyer 없으면 salesman 매칭 필수 = 운영 규칙, jin 수용.)
    #[Computed]
    public function buyers()
    {
        $user = auth()->user();
        $restrictToOwnSalesman = $user && ! $user->isAdmin() && ! $user->isManager() && $user->role === '영업' && $user->salesman;
        $restrictToManagerScope = $user && ! $user->isAdmin() && ! $user->isManager() && $user->role === '관리';
        $managerScopeSalesmanIds = $restrictToManagerScope ? $user->getSubordinateSalesmanIds() : [];

        $selectedIds = array_values(array_filter([
            (int) ($this->buyer_id_str ?: 0),
            (int) ($this->export_buyer_id_str ?: 0),
            (int) ($this->bl_buyer_id_str ?: 0),
        ]));

        return Buyer::query()
            ->where(function ($outer) use ($restrictToOwnSalesman, $restrictToManagerScope, $user, $managerScopeSalesmanIds, $selectedIds) {
                $outer->where(function ($q) use ($restrictToOwnSalesman, $restrictToManagerScope, $user, $managerScopeSalesmanIds) {
                    $q->where('is_active', true);
                    if ($restrictToOwnSalesman) {
                        $ownId = $user->salesman->id;
                        $q->where(fn ($s) => $s->where('salesman_id', $ownId)
                            ->orWhereHas('vehicles', fn ($v) => $v->where('salesman_id', $ownId)));
                    } elseif ($restrictToManagerScope) {
                        $q->where(fn ($s) => $s->whereIn('salesman_id', $managerScopeSalesmanIds)
                            ->orWhereHas('vehicles', fn ($v) => $v->whereIn('salesman_id', $managerScopeSalesmanIds)));
                    }
                });
                if (! empty($selectedIds)) {
                    $outer->orWhereIn('id', $selectedIds);
                }
            })
            ->orderBy('name')
            ->get();
    }

    // 작업2 (2026-05-27) — quick-add 폼 국가 드롭다운용.
    #[Computed]
    public function countries() { return Country::orderBy('name')->get(); }

    // 2026-05-21 — CIPL 드롭다운 (Port 마스터 type 별 활성 목록)
    #[Computed]
    public function loadingPorts() { return \App\Models\Port::ofType('loading')->get(); }

    #[Computed]
    public function unloadingPorts() { return \App\Models\Port::ofType('unloading')->get(); }

    #[Computed]
    public function dischargePorts() { return \App\Models\Port::ofType('discharge')->get(); }

    #[Computed]
    public function consigneesForSale()
    {
        if ($this->buyer_id_str === '') return collect();
        return Consignee::where('buyer_id', $this->buyer_id_str)->where('is_active', true)->orderBy('name')->get();
    }

    #[Computed]
    public function consigneesForExport()
    {
        if ($this->export_buyer_id_str === '') return collect();
        return Consignee::where('buyer_id', $this->export_buyer_id_str)->where('is_active', true)->orderBy('name')->get();
    }

    #[Computed]
    public function consigneesForBl()
    {
        if ($this->bl_buyer_id_str === '') return collect();
        return Consignee::where('buyer_id', $this->bl_buyer_id_str)->where('is_active', true)->orderBy('name')->get();
    }

    #[Computed]
    public function forwardingCompanies() { return ForwardingCompany::where('is_active', true)->orderBy('name')->get(); }

    // 회의확장씬 #12 (2026-05-22) — 현재 편집 중 차량의 buyer × currency 누적 적립금.
    // SavingsStatus 단일 출처 (vehicles 판매 탭 적립금 + 바이어 페이지 적립금 둘 다 누적).
    // null = 차량 미선택 또는 buyer 미지정.
    #[Computed]
    public function buyerSavingsBalance(): ?float
    {
        if (! $this->editingId) {
            return null;
        }
        $v = Vehicle::find($this->editingId);
        if (! $v || ! $v->buyer_id) {
            return null;
        }
        $latest = \App\Models\SavingsStatus::where('buyer_id', $v->buyer_id)
            ->where('currency', $v->currency)
            ->orderByDesc('id')
            ->first();

        return (float) ($latest?->balance ?? 0);
    }

    // 회의확장씬 #11 (2026-05-22) — [관리]는 본인 담당 영업만 select 노출.
    // admin/super 전체 / 영업 전체 (본인 차량 한정은 query 측 restrictToOwnSalesman 분리).
    // 필터바 select (L2392) + 편집 패널 select (L2802) 양쪽 자동 반영.
    #[Computed]
    public function salesmen()
    {
        $q = Salesman::where('is_active', true)->orderBy('name');

        $user = auth()->user();
        if ($user && ! $user->isAdmin() && ! $user->isManager() && $user->role === '관리') {
            $q->whereIn('id', $user->getSubordinateSalesmanIds());
        }

        return $q->get();
    }

    /**
     * 회의확장씬 #3 Phase 2-4 (2026-05-23) — 필터바 바이어 select 옵션.
     * - admin/super/통관/재무: 전체 바이어
     * - 관리: 본인 담당 영업의 바이어만 (direct salesman_id + indirect vehicles.salesman_id)
     * - 영업: 본인 영업의 바이어만 (#11 패턴 동일)
     */
    #[Computed]
    public function buyersForFilter()
    {
        $q = Buyer::orderBy('name');
        $user = auth()->user();

        if (! $user || $user->isAdmin() || $user->isManager()) {
            return $q->get();
        }

        if ($user->role === '관리') {
            $subIds = $user->getSubordinateSalesmanIds();
            $q->where(function ($q2) use ($subIds) {
                $q2->whereIn('salesman_id', $subIds)
                    ->orWhereHas('vehicles', fn ($q3) => $q3->whereIn('salesman_id', $subIds));
            });
        } elseif ($user->role === '영업' && $user->salesman) {
            $ownId = $user->salesman->id;
            $q->where(function ($q2) use ($ownId) {
                $q2->where('salesman_id', $ownId)
                    ->orWhereHas('vehicles', fn ($q3) => $q3->where('salesman_id', $ownId));
            });
        }

        return $q->get();
    }

    /**
     * 큐 2번 — 편집 패널 1대용 흐름도 7노드.
     * 매입 / 말소 / 판매 / 입금 / 선적(반입) / 통관 / B/L. (DHL 흐름 제외 2026-07-04)
     * 상태: done(✓) / warn(!) / progress(진행중) / pending(-).
     * 큐 17 — 폐기 컨셉 제거. disabled 상태 사용처 없어짐.
     * 큐 6 잔여 H13 — warn/pending/progress 노드에 reason 텍스트 부착 (tooltip 안내).
     */
    #[Computed]
    public function progressFlow(): ?array
    {
        if (! $this->editingId) {
            return null;
        }
        $v = Vehicle::with(['finalPayments', 'purchaseBalancePayments', 'receivableHistories'])
            ->find($this->editingId);
        if (! $v) {
            return null;
        }

        // 매입
        $purchaseStatus = $v->purchase_price > 0
            ? ($v->purchase_unpaid_amount <= 0 ? 'done' : 'warn')
            : 'pending';
        $purchaseReason = match (true) {
            $purchaseStatus === 'warn' => __('vehicle.reason.purchase_warn', ['amount' => number_format($v->purchase_unpaid_amount)]),
            $purchaseStatus === 'pending' => __('vehicle.reason.purchase_pending'),
            default => null,
        };

        // 말소
        $deregStatus = $v->is_deregistered && $v->deregistration_document
            ? 'done'
            : ($v->is_deregistered ? 'warn' : 'pending');
        $deregReason = match (true) {
            $deregStatus === 'warn' => __('vehicle.reason.dereg_warn'),
            $deregStatus === 'pending' => __('vehicle.reason.dereg_pending'),
            default => null,
        };

        // 판매
        $saleStatus = $v->sale_price > 0 ? 'done' : 'pending';
        $saleReason = $saleStatus === 'pending' ? __('vehicle.reason.sale_pending') : null;

        // 입금
        $paymentStatus = $v->sale_price > 0
            ? ($v->sale_unpaid_amount <= 0 ? 'done' : 'warn')
            : 'pending';
        $paymentReason = match (true) {
            $paymentStatus === 'warn' => __('vehicle.reason.payment_warn', ['amount' => number_format($v->sale_unpaid_amount)]),
            $paymentStatus === 'pending' => __('vehicle.reason.payment_pending'),
            default => null,
        };

        // 통관 — 큐 2.6 잔여 통합: 체크박스 + 문서 둘 다 누락 시 명시 안내
        $clearanceStatus = $v->export_declaration_document ? 'done'
            : ($v->export_buyer_id && $v->shipping_date ? 'progress' : 'pending');
        $clearanceReason = match (true) {
            $clearanceStatus === 'progress' => __('vehicle.reason.clearance_progress'),
            $clearanceStatus === 'pending' => __('vehicle.reason.clearance_pending'),
            default => null,
        };

        // 선적(반입) — 반입지 입력 = 완료 (B/L 발급과 분리, 2026-07-04)
        $shippingStatus = $v->bl_loading_location ? 'done' : 'pending';
        $shippingReason = $shippingStatus === 'pending' ? __('vehicle.reason.shipping_pending') : null;

        // B/L — 문서 업로드 = 완료(거래완료). 통관완료 + 반입지 있으면 진행중.
        $blStatus = $v->bl_document ? 'done'
            : ($v->bl_loading_location && $v->is_export_cleared ? 'progress' : 'pending');
        $blReason = match (true) {
            $blStatus === 'progress' => __('vehicle.reason.bl_progress'),
            $blStatus === 'pending' => __('vehicle.reason.bl_pending'),
            default => null,
        };

        // 실제 업무 순서: 매입 → 말소 → 판매 → 입금 → 선적(반입) → 통관 → B/L(최종).
        // DHL 은 흐름도에서 제외(사용 불명확, 탭은 유지, jin 2026-07-04).
        return [
            ['key' => 'purchase',       'label' => __('vehicle.panel.flow.purchase'),       'tab' => 'purchase',  'status' => $purchaseStatus,  'reason' => $purchaseReason],
            ['key' => 'deregistration', 'label' => __('vehicle.panel.flow.deregistration'), 'tab' => 'purchase',  'status' => $deregStatus,     'reason' => $deregReason],
            ['key' => 'sale',           'label' => __('vehicle.panel.flow.sale'),           'tab' => 'sale',      'status' => $saleStatus,      'reason' => $saleReason],
            ['key' => 'payment',        'label' => __('vehicle.panel.flow.payment'),        'tab' => 'sale',      'status' => $paymentStatus,   'reason' => $paymentReason],
            ['key' => 'shipping',       'label' => __('vehicle.panel.flow.shipping'),  'tab' => 'shipping',  'status' => $shippingStatus,  'reason' => $shippingReason],
            ['key' => 'clearance',      'label' => __('vehicle.panel.flow.clearance'), 'tab' => 'clearance', 'status' => $clearanceStatus, 'reason' => $clearanceReason],
            ['key' => 'bl',             'label' => __('vehicle.panel.flow.bl'),        'tab' => 'bl',        'status' => $blStatus,        'reason' => $blReason],
        ];
    }

    // 미입금 게이트 상태 — 선적·통관·B/L 탭 배너 공유. export 채널만, 그 외/미편집 null.
    #[Computed]
    public function exportGateStatus(): ?array
    {
        if (! $this->editingId) {
            return null;
        }
        $v = Vehicle::with('unpaidExportOverrides')->find($this->editingId);
        if (! $v || $v->sales_channel !== 'export') {
            return null;
        }
        $ratio = $v->unpaid_ratio;

        return [
            'ratio' => $ratio,   // null = 환율 미입력
            'paid_pct' => $ratio === null ? null : round((1 - $ratio) * 100, 1),
            'ratio_pct' => $ratio === null ? null : round($ratio * 100, 1),
            'entry_override' => $v->hasEntryUnpaidOverride(),
            'has_bl' => ! empty($v->bl_document),
        ];
    }

    // 당사자 축소 (jin 2026-07-09) — 판매 바이어 = 단일 소스 → 선적·통관 바이어 이어받기(라이브).
    //   바이어 바뀌면 선적 컨사이니도 리셋(consignee.buyer_id 종속 무결성).
    public function updatedBuyerIdStr(): void { $this->consignee_id_str = ''; $this->bl_consignee_id_str = ''; $this->inheritParties(); unset($this->consigneesForSale); }
    public function updatedExportBuyerIdStr(): void { $this->export_consignee_id_str = ''; unset($this->consigneesForExport); }
    public function updatedBlBuyerIdStr(): void { $this->bl_consignee_id_str = ''; unset($this->consigneesForBl); }

    /**
     * 당사자 이어받기 (jin 2026-07-09) — 바이어: 판매 → 선적·통관 / 컨사이니: 선적 → 통관.
     * 판매=바이어만·선적=컨사이니만·통관=이어받기만 정책의 단일 authoritative 전파.
     * 방향1(2026-07-08)로 export_buyer_id 는 C5 게이트 트리거가 아니므로 자유 세팅 안전.
     */
    private function inheritParties(): void
    {
        if ($this->buyer_id_str !== '') {
            $this->bl_buyer_id_str = $this->buyer_id_str;
            $this->export_buyer_id_str = $this->buyer_id_str;
        }
        $this->export_consignee_id_str = $this->bl_consignee_id_str;
        unset($this->consigneesForBl, $this->consigneesForExport);
    }

    // 판매 바이어/컨사이니 둘 다 set 된 순간, 선적(B/L) 쌍이 비어있으면 동일 값 자동 전파.
    // 이미 선적에 값이 있으면 존중(덮어쓰지 않음). 쌍 단위 판정 — buyer/consignee 한쪽만
    // 채우는 일이 없어야 종속관계(consignee.buyer_id) 무결성 유지.
    //
    // 2026-06-01 — 통관(export) 당사자 자동 전파 제거.
    //   export_buyer_id 는 C4/C5 게이트의 '통관 진입 신호'(guardStageOrderForExport 의 $hasExportInput,
    //   ManagementWorkflowChecklistTest:375 등이 명시 검증)이기도 해서, 판매 시점에 자동으로 채우면
    //   <50% 입금 차량의 판매 저장이 통째로 막히는 회귀가 발생. 통관 바이어는 실제 통관 단계에서
    //   입력(말소+50% 충족 시점) — 게이트 의도와 정합. B/L 당사자는 게이트 트리거가 아니라 전파 유지.
    public function updatedConsigneeIdStr(): void { $this->propagateSaleParty(); }
    public function updatedBlConsigneeIdStr(): void { $this->inheritParties(); }

    private function propagateSaleParty(): void
    {
        if ($this->buyer_id_str === '' || $this->consignee_id_str === '') {
            return;
        }
        if ($this->bl_buyer_id_str === '' && $this->bl_consignee_id_str === '') {
            $this->bl_buyer_id_str = $this->buyer_id_str;
            $this->bl_consignee_id_str = $this->consignee_id_str;
            unset($this->consigneesForBl);
        }
    }

    // item 8 (jin 2026-07-07) — 선적(bl) 당사자 지정 시 수출통관(export) 당사자도 동일 적용.
    //   근거: 50% 진입 우회가 선적·수출통관 통합(clearance∪shipping) → 선적탭에서 통관 당사자 채움이 도메인상 정합.
    //   ⚠️ export 가 비어있을 때만(명시 통관 당사자 보존). updated 훅은 클라 편집에서만 발화 →
    //      sale→bl 서버 전파가 bl→export 로 연쇄되지 않음(SKILLS #24 판매탭 회귀 회피).
    //   ⚠️ export_buyer_id 는 guardStageOrderForExport 의 통관 진입 신호 — <50% 미우회 차량은 저장이
    //      C5 로 차단되며 이는 의도된 게이트(선적탭에서 통관 당사자 = 통관 진입).
    private function propagateBlToExport(): void
    {
        if ($this->bl_buyer_id_str === '' || $this->bl_consignee_id_str === '') {
            return;
        }
        if ($this->export_buyer_id_str === '' && $this->export_consignee_id_str === '') {
            $this->export_buyer_id_str = $this->bl_buyer_id_str;
            $this->export_consignee_id_str = $this->bl_consignee_id_str;
            unset($this->consigneesForExport);
        }
    }

    // ── 작업2 (2026-05-27) — 차량 패널 내 바이어·컨사이니 즉석 등록 ──────────
    //   context = 'sale' | 'export' | 'bl' (어느 드롭다운에서 호출됐는지).
    //   consignee 는 해당 context 의 바이어가 선택돼 있어야 함 (buyer_id 종속).
    //   ⚠️ 저장 후 *_id_str 를 서버에서 직접 할당 → updatedXxx 훅 안 뜸 →
    //      종속 consignee 리셋·computed unset 을 saveQuickAdd 에서 수동 처리.
    private function contextBuyerId(string $context): string
    {
        return match ($context) {
            'sale'   => $this->buyer_id_str,
            'export' => $this->export_buyer_id_str,
            'bl'     => $this->bl_buyer_id_str,
            default  => '',
        };
    }

    public function openQuickAdd(string $type, string $context): void
    {
        if (! in_array($type, ['buyer', 'consignee'], true) || ! in_array($context, ['sale', 'export', 'bl'], true)) {
            return;
        }

        if ($type === 'consignee') {
            $buyerId = $this->contextBuyerId($context);
            if ($buyerId === '') {
                $this->dispatch('notify', message: __('vehicle.toast.buyer_first'), type: 'warning');

                return;
            }
            $this->quickAddBuyerName = (string) (Buyer::find($buyerId)?->name ?? '');
        }

        $this->quickAddType = $type;
        $this->quickAddContext = $context;
        $this->resetQuickAddForm();

        // 바이어 quick-add: 차량 패널의 현재 선택 영업담당자를 기본값으로 prefill (수정 가능)
        if ($type === 'buyer') {
            $this->qaSalesmanId = $this->salesman_id_str;
        }

        $this->quickAddOpen = true;
    }

    public function saveQuickAdd(): void
    {
        $this->validate([
            'qaName'       => 'required|string|max:100',
            'qaCountryId'  => 'nullable|integer|exists:countries,id',
            'qaSalesmanId' => 'nullable|integer|exists:salesmen,id',
        ]);

        $countryId = $this->qaCountryId !== '' ? (int) $this->qaCountryId : null;

        if ($this->quickAddType === 'buyer') {
            $buyer = Buyer::create([
                'name'          => $this->qaName,
                'country_id'    => $countryId,
                'salesman_id'   => $this->qaSalesmanId !== '' ? (int) $this->qaSalesmanId : null,
                'contact_name'  => $this->qaContactName ?: null,
                'contact_phone' => $this->qaContactPhone ?: null,
                'is_active'     => true,
            ]);
            $this->selectNewBuyer($this->quickAddContext, (string) $buyer->id);
            unset($this->buyers);
            $this->dispatch('notify', message: __('vehicle.toast.buyer_created'), type: 'success');
        } else {
            $buyerId = $this->contextBuyerId($this->quickAddContext);
            if ($buyerId === '') {
                $this->dispatch('notify', message: __('vehicle.toast.buyer_first'), type: 'warning');

                return;
            }
            $consignee = Consignee::create([
                'name'          => $this->qaName,
                'buyer_id'      => (int) $buyerId,
                'country_id'    => $countryId,
                'contact_name'  => $this->qaContactName ?: null,
                'contact_phone' => $this->qaContactPhone ?: null,
                'is_active'     => true,
            ]);
            $this->selectNewConsignee($this->quickAddContext, (string) $consignee->id);
            $this->dispatch('notify', message: __('vehicle.toast.consignee_created'), type: 'success');
        }

        $this->quickAddOpen = false;
        $this->resetQuickAddForm();

        // 패널 dirty 마킹 — morphdom 프로그램적 select 변경은 input/change 이벤트 안 쏨 →
        // 자동선택 후 패널이 clean 으로 보여 ESC/backdrop 닫힘 시 새 항목이 차량에 안 붙는 footgun 방지.
        $this->dispatch('panel-mark-dirty');
    }

    private function selectNewBuyer(string $context, string $id): void
    {
        if ($context === 'sale') {
            $this->buyer_id_str = $id;
            $this->consignee_id_str = '';
            unset($this->consigneesForSale);
        } elseif ($context === 'export') {
            $this->export_buyer_id_str = $id;
            $this->export_consignee_id_str = '';
            unset($this->consigneesForExport);
        } elseif ($context === 'bl') {
            $this->bl_buyer_id_str = $id;
            $this->bl_consignee_id_str = '';
            unset($this->consigneesForBl);
        }
    }

    private function selectNewConsignee(string $context, string $id): void
    {
        // unset 먼저 → 재렌더 시 computed 가 신규 컨사이니 포함하도록 재계산.
        if ($context === 'sale') {
            unset($this->consigneesForSale);
            $this->consignee_id_str = $id;
        } elseif ($context === 'export') {
            unset($this->consigneesForExport);
            $this->export_consignee_id_str = $id;
        } elseif ($context === 'bl') {
            unset($this->consigneesForBl);
            $this->bl_consignee_id_str = $id;
        }
    }

    public function cancelQuickAdd(): void
    {
        $this->quickAddOpen = false;
        $this->resetQuickAddForm();
    }

    private function resetQuickAddForm(): void
    {
        $this->qaName = '';
        $this->qaCountryId = '';
        $this->qaSalesmanId = '';
        $this->qaContactName = '';
        $this->qaContactPhone = '';
        $this->resetErrorBag(['qaName', 'qaCountryId', 'qaSalesmanId']);
    }

    // ── 미수 매입 게이트 (2026-07-08) ────────────────────────────────
    private function purchaseGateBuyerId(): int
    {
        return (int) ($this->buyer_id_str ?: 0);
    }

    /** 게이트 발동 대상? 신규 등록 OR 편집 중 바이어를 다른 바이어로 교체할 때만 (레거시 무바이어 편집은 미발동). */
    private function shouldCheckPurchaseGate(): bool
    {
        if ($this->purchaseGateBuyerId() <= 0) {
            return false;
        }
        if ($this->editingId === null) {
            return true;
        }
        $original = \App\Models\Vehicle::find($this->editingId);

        return $original && (int) $original->buyer_id !== $this->purchaseGateBuyerId();
    }

    /** 관리/admin 인라인 승인 → 사유 기록 후 이 저장 1회 통과. */
    public function approvePurchaseGate(): void
    {
        abort_unless(auth()->user()?->canApproveUnpaidExport(), 403);
        $this->validate([
            'purchaseGateReason' => ['required', 'string', 'min:5', 'max:500'],
        ], [], [
            'purchaseGateReason' => __('vehicle.purchase_gate.reason'),
        ]);
        $this->purchaseGateApproved = true;
        $this->showPurchaseGate = false;
        $this->save();   // 재진입 — 게이트 통과 + save() 내에서 AuditLog 기록
    }

    public function cancelPurchaseGate(): void
    {
        $this->showPurchaseGate = false;
        $this->purchaseGateReason = '';
        $this->purchaseGateApproved = false;
        $this->resetErrorBag(['purchaseGateReason']);
    }

    // C1 — 통화가 KRW로 변경되면 환율 reset (KRW 차량에 환율값 dirty 방지).
    public function updatedCurrency(): void
    {
        if ($this->currency === 'KRW') {
            $this->exchange_rate_str = '';
        }
    }

    // 큐 16 — updatedSalesChannel hook 제거 (sales_channel은 enum 'export' 단일값으로 변경 불가).

    // ── 패널 열기/닫기 ────────────────────────────────────────────
    public function openCreate(): void
    {
        $this->resetValidation();
        $this->resetForm();
        $this->editingId = null;
        // 회의확장씬 #9 (2026-05-22) — 신규 차량 기타비용 기본기재 (사용자 명세).
        // 운영자가 수정 가능 (또는 0 으로 비움). 2차 정산 단계에서 [관리]/[재무] 가
        // 한 달 뒤 측정된 실제 비용으로 정정 (Phase 1-3 흐름).
        // 회사 프로파일별 기본비용 (karaba=말소 17,300·면허 0·탁송 0 / 그 외=24,000·11,000·30,000)
        $defaultCosts = Vehicle::defaultPurchaseCosts();
        $this->cost_deregistration_str = number_format($defaultCosts['cost_deregistration']);
        $this->cost_license_str = number_format($defaultCosts['cost_license']);
        $this->cost_towing_str = number_format($defaultCosts['cost_towing']);
        $this->showPanel = true;
    }

    // ── 큐 7 확장 C7-a — 회계 민감 컬럼 silent restore ─────────────────
    // 정산/통관/관리 role은 아래 컬럼 변경 시 원값 자동 복원.
    // 입금/지급 컬럼(down_payment, deposit_down_payment, final_payments, purchase_balance_payments)은
    // 정산 role의 정상 업무라 제외.
    private const FINANCIAL_FIELD_MAP = [
        'purchase_price_str' => 'purchase_price',
        'selling_fee_str' => 'selling_fee',
        'sale_price_str' => 'sale_price',
        'tax_dc_str' => 'tax_dc',
        'commission_str' => 'commission',
        'transport_fee_str' => 'transport_fee',
        'auto_loading_str' => 'auto_loading',
        'sale_other_costs_str' => 'sale_other_costs',
        'exchange_rate_str' => 'exchange_rate',
        'export_declaration_amount_str' => 'export_declaration_amount',
        'cost_deregistration_str' => 'cost_deregistration',
        'cost_license_str' => 'cost_license',
        'cost_towing_str' => 'cost_towing',
        'cost_carry_str' => 'cost_carry',
        'cost_shoring_str' => 'cost_shoring',
        'cost_insurance_str' => 'cost_insurance',
        'cost_transfer_str' => 'cost_transfer',
        'cost_extra1_str' => 'cost_extra1',
        'cost_extra2_str' => 'cost_extra2',
    ];

    private function restoreFinancialFieldsFromOriginal(): void
    {
        $original = Vehicle::find($this->editingId);
        if (! $original) {
            return;
        }
        $restored = 0;
        foreach (self::FINANCIAL_FIELD_MAP as $formField => $dbField) {
            $current = (float) str_replace(',', '', (string) ($this->{$formField} ?? '0'));
            $originalValue = (float) ($original->{$dbField} ?? 0);
            if (abs($current - $originalValue) > 0.001) {
                $this->{$formField} = (string) $originalValue;
                $restored++;
            }
        }

        // 2026-05-19 풀회의 P0-1 — RRN(주민/법인등록번호) silent restore.
        // string 컬럼이라 FINANCIAL_FIELD_MAP의 float 비교 패턴 불가 → 별도 분기.
        // accessor가 자동 복호화하므로 평문 비교 가능.
        //
        // Day 5 보강 (안건 C — 말소 [everyone]) — canHandleDeregistration() 사용자는
        // 말소 처리 시 RRN 입력 필수(H10) → restore 대상에서 제외.
        if (! auth()->user()?->canHandleDeregistration()) {
            $currentRrn = (string) ($this->nice_reg_owner_rrn ?? '');
            $originalRrn = (string) ($original->nice_reg_owner_rrn ?? '');
            if ($currentRrn !== $originalRrn) {
                $this->nice_reg_owner_rrn = $originalRrn;
                $restored++;
            }
        }

        if ($restored > 0) {
            $this->dispatch(
                'notify',
                message: __('vehicle.toast.fields_restored', ['count' => $restored]),
                type: 'warning'
            );
        }
    }

    /**
     * 큐 10 H4 — paid 정산이 있는 차량의 회계 민감 컬럼 변경 차단.
     * 회계 마감된 정산의 표시값(snapshot 기반)과 vehicle 컬럼 사이 drift 방지.
     */
    private function guardFinancialDriftAfterPaid(): void
    {
        $existing = Vehicle::find($this->editingId);
        if (! $existing) {
            return;
        }
        $hasPaid = $existing->settlements()->where('settlement_status', 'paid')->exists();
        if (! $hasPaid) {
            return;
        }

        // 회의확장씬 #8 (2026-05-22) — 2차 정산 대기 동안 [재무]/[관리]/admin 잠금 해제.
        // paid 후 한 달 뒤 측정되는 기타비용(말소·면허·탁송·보험·이전비·기타1,2) 수정 대기.
        // secondary_status='closed' 후 다시 잠금 (회계 무결성 복구).
        $user = auth()->user();
        $isFinanceOrManager = $user && ($user->isAdmin() || in_array($user->role, ['재무', '관리'], true));
        if ($isFinanceOrManager) {
            $hasSecondaryPending = $existing->settlements()
                ->where('settlement_status', 'paid')
                ->where('secondary_status', 'pending')
                ->exists();
            if ($hasSecondaryPending) {
                return;   // 2차 정산 대기 동안 수정 허용 (회의확장씬 #8)
            }
        }

        $changed = [];
        foreach (self::FINANCIAL_FIELD_MAP as $formField => $dbField) {
            $newVal = (float) str_replace(',', '', (string) ($this->{$formField} ?? '0'));
            $oldVal = (float) ($existing->{$dbField} ?? 0);
            if (abs($newVal - $oldVal) > 0.001) {
                $changed[] = $dbField;
            }
        }
        if (! empty($changed)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'purchase_price_str' => __('vehicle.toast.paid_locked', ['fields' => implode(', ', $changed)]),
            ]);
        }
    }

    // ── 큐 2.6 — admin 미입금 우회 승인 (per-stage append-only) ────────
    public string $overrideStage = '';
    public string $overrideReason = '';

    public function approveUnpaidOverride(): void
    {
        abort_unless(auth()->user()?->canApproveUnpaidExport(), 403, __('vehicle.toast.override_no_perm'));
        abort_unless($this->editingId, 422, __('vehicle.toast.save_first_approve'));
        $this->assertEditable(); // 동시 편집 잠금 — 타인이 잠근 차량엔 우회 승인 차단

        // attribute 라벨(단계·사유)은 validation.php attributes 전역 맵에서 해석 (양쪽 언어).
        $this->validate([
            'overrideStage' => ['required', Rule::in(['clearance', 'shipping', 'bl'])],
            'overrideReason' => ['required', 'string', 'min:20'],
        ]);

        $v = Vehicle::findOrFail($this->editingId);

        \App\Models\UnpaidExportOverride::create([
            'vehicle_id' => $v->id,
            'stage' => $this->overrideStage,
            'approved_by' => auth()->id(),
            'reason' => $this->overrideReason,
            'approved_at' => now(),
            'ip_address' => request()->ip(),
            'sale_unpaid_amount_snapshot' => $v->sale_unpaid_amount_krw_cache,
        ]);

        $this->overrideStage = '';
        $this->overrideReason = '';

        $this->dispatch('notify', message: __('vehicle.toast.override_done'), type: 'success');
    }

    // ── 동시 편집 잠금 (2026-06-30) ──────────────────────────────────────
    private const EDIT_LOCK_TTL = 90; // 초 — 하트비트로 갱신, 자리 비우면(브라우저 닫힘 등) 자동 만료

    private function editLockKey(int $id): string
    {
        return "vehicle-edit-lock:{$id}";
    }

    /** 차량 $id 편집 잠금 획득 시도 — 타인이 보유 중이면 읽기 전용 플래그만 세움(점유 안 함). */
    private function acquireEditLock(int $id): void
    {
        $lock = \Illuminate\Support\Facades\Cache::get($this->editLockKey($id));
        if ($lock && (int) ($lock['user_id'] ?? 0) !== auth()->id()) {
            $this->editLockedByOther = true;
            $this->editLockOwnerName = (string) ($lock['name'] ?? __('vehicle.lock.someone'));

            return;
        }
        \Illuminate\Support\Facades\Cache::put(
            $this->editLockKey($id),
            ['user_id' => auth()->id(), 'name' => (string) auth()->user()->name],
            self::EDIT_LOCK_TTL,
        );
        $this->editLockedByOther = false;
        $this->editLockOwnerName = '';
    }

    /** 내 잠금이면 해제 (다른 차량으로 이동·패널 닫기 시). 타인 잠금은 건드리지 않음. */
    private function releaseEditLock(?int $id): void
    {
        if (! $id) {
            return;
        }
        $lock = \Illuminate\Support\Facades\Cache::get($this->editLockKey($id));
        if ($lock && (int) ($lock['user_id'] ?? 0) === auth()->id()) {
            \Illuminate\Support\Facades\Cache::forget($this->editLockKey($id));
        }
    }

    /** 변경 액션 가드 — 타인이 잠근 차량이면 423 차단 (읽기전용 UI 우회·클라이언트 변조 방지). */
    private function assertEditable(?int $id = null): void
    {
        $id ??= $this->editingId;
        if (! $id) {
            return;
        }
        $lock = \Illuminate\Support\Facades\Cache::get($this->editLockKey($id));
        if ($lock && (int) ($lock['user_id'] ?? 0) !== auth()->id()) {
            abort(423, __('vehicle.lock.locked_by', ['name' => (string) ($lock['name'] ?? __('vehicle.lock.someone'))]));
        }
    }

    /** wire:poll 하트비트 — 패널 열린 동안 내 잠금 TTL 갱신. 타인 잠금이 만료됐으면 점유로 전환. */
    public function heartbeat(): void
    {
        if (! $this->editingId) {
            return;
        }
        if ($this->editLockedByOther) {
            // 앞사람이 자리를 비워 잠금이 만료됐으면 내가 이어받아 편집 가능 전환.
            $lock = \Illuminate\Support\Facades\Cache::get($this->editLockKey($this->editingId));
            if (! $lock || (int) ($lock['user_id'] ?? 0) === auth()->id()) {
                $this->acquireEditLock($this->editingId);
                if (! $this->editLockedByOther) {
                    $this->dispatch('notify', message: __('vehicle.lock.now_editable'), type: 'success');
                }
            }

            return;
        }
        \Illuminate\Support\Facades\Cache::put(
            $this->editLockKey($this->editingId),
            ['user_id' => auth()->id(), 'name' => (string) auth()->user()->name],
            self::EDIT_LOCK_TTL,
        );
    }

    public function openEdit(int $id): void
    {
        $v = Vehicle::with(['finalPayments', 'purchaseBalancePayments'])->findOrFail($id);

        // C7-b — 영업 role은 본인 담당 차량만 편집 가능.
        // 관리 role은 본인 팀(부하 영업담당) 차량만 — 목록 스코프(managerScopeSalesmanIds)와 동일 기준.
        // admin/super, 통관/재무는 우회. (claudereview B — openEdit 관리 미스코핑 보정)
        $user = auth()->user();
        if (! $user->isAdmin() && ! $user->isManager() && $user->role === '영업') {
            abort_unless($v->salesman_id === $user->salesman?->id, 403, __('vehicle.toast.edit_own_only'));
        } elseif (! $user->isAdmin() && ! $user->isManager() && $user->role === '관리') {
            abort_unless(in_array($v->salesman_id, $user->getSubordinateSalesmanIds(), true), 403, __('vehicle.toast.edit_team_only'));
        }

        // 동시 편집 잠금 — 다른 차량을 보고 있었으면 그 잠금부터 해제, 그다음 이 차량 잠금 획득.
        if ($this->editingId && $this->editingId !== $id) {
            $this->releaseEditLock($this->editingId);
        }
        $this->acquireEditLock($id);

        // save() 신규 등록 직후 호출인지(justCreatedId == $id) 보존,
        // 다른 차량 열기 시엔 reset.
        if ($this->justCreatedId !== $id) {
            $this->justCreatedId = null;
        }
        $this->editingId = $id;

        $lockedFinalIds = \App\Models\ReceivableHistory::where('vehicle_id', $id)
            ->whereNotNull('final_payment_id')
            ->pluck('final_payment_id')
            ->toArray();

        // 큐 19-C 보강 — 자금 이체로 생성된 final_payment는 append-only (locked + transfer 메타)
        $transferLinkedPayments = FinalPayment::where('vehicle_id', $id)
            ->whereNotNull('transfer_id')
            ->with('transfer.sourceVehicle:id,vehicle_number', 'transfer.targetVehicle:id,vehicle_number')
            ->get()
            ->keyBy('id');
        $lockedFinalIds = array_unique(array_merge($lockedFinalIds, $transferLinkedPayments->keys()->all()));

        // 큐 19-E — 각 transfer에 pending void ApprovalRequest 있는지 확인 (취소 요청 중 표시용)
        $transferIds = $transferLinkedPayments->pluck('transfer_id')->unique()->filter()->all();
        $pendingVoidTransferIds = empty($transferIds) ? [] : ApprovalRequest::query()
            ->where('action_type', ApprovalRequest::TYPE_INTER_VEHICLE_TRANSFER_VOID)
            ->where('status', ApprovalRequest::STATUS_PENDING)
            ->where('target_type', InterVehicleTransfer::class)
            ->whereIn('target_id', $transferIds)
            ->pluck('target_id')
            ->all();

        $this->vehicle_number = $v->vehicle_number;
        $this->sales_channel  = $v->sales_channel;
        $this->brand      = $v->brand ?? '';
        $this->model_type = $v->model_type ?? '';
        $this->year_str      = $v->year      ? (string)$v->year      : '';
        $this->cc_str        = $v->cc        ? (string)$v->cc        : '';
        $this->weight_kg_str = $v->weight_kg ? (string)$v->weight_kg : '';
        $this->mileage_str   = $v->mileage   ? (string)$v->mileage   : '';
        $this->color = $v->color ?? '';

        // NICE 등록
        $this->nice_reg_vin          = $v->nice_reg_vin          ?? '';
        $this->nice_reg_engine_no    = $v->nice_reg_engine_no    ?? '';
        $this->nice_reg_fuel_type    = $v->nice_reg_fuel_type    ?? '';
        $this->nice_reg_use_type     = $v->nice_reg_use_type     ?? '';
        $this->nice_reg_vehicle_form = $v->nice_reg_vehicle_form ?? '';
        $this->nice_reg_first_date   = $v->nice_reg_first_date   ? $v->nice_reg_first_date->format('Y-m-d') : '';
        $this->nice_reg_date         = $v->nice_reg_date         ? $v->nice_reg_date->format('Y-m-d') : '';
        $this->nice_reg_owner_name   = $v->nice_reg_owner_name   ?? '';
        $this->nice_reg_owner_addr   = $v->nice_reg_owner_addr   ?? '';
        $this->nice_reg_owner_rrn    = $v->nice_reg_owner_rrn    ?? '';
        $this->nice_reg_max_load_str    = $v->nice_reg_max_load    ? (string)$v->nice_reg_max_load    : '';
        $this->nice_reg_passengers_str  = $v->nice_reg_passengers  ? (string)$v->nice_reg_passengers  : '';
        $this->nice_reg_color           = $v->nice_reg_color        ?? '';

        // NICE 제원
        $this->nice_spec_maker           = $v->nice_spec_maker           ?? '';
        $this->nice_spec_model           = $v->nice_spec_model           ?? '';
        $this->nice_spec_year            = $v->nice_spec_year            ?? '';
        $this->nice_spec_displacement_str = $v->nice_spec_displacement    ? (string)$v->nice_spec_displacement : '';
        $this->nice_spec_transmission    = $v->nice_spec_transmission    ?? '';
        $this->nice_spec_drive_type      = $v->nice_spec_drive_type      ?? '';
        $this->nice_spec_length_str      = $v->nice_spec_length      ? (string)$v->nice_spec_length      : '';
        $this->nice_spec_width_str       = $v->nice_spec_width       ? (string)$v->nice_spec_width       : '';
        $this->nice_spec_height_str      = $v->nice_spec_height      ? (string)$v->nice_spec_height      : '';
        $this->nice_spec_wheelbase_str   = $v->nice_spec_wheelbase   ? (string)$v->nice_spec_wheelbase   : '';
        $this->nice_spec_curb_weight_str = $v->nice_spec_curb_weight ? (string)$v->nice_spec_curb_weight : '';
        $this->nice_spec_fuel_efficiency = $v->nice_spec_fuel_efficiency ?? '';

        // 매입
        $this->purchase_date       = $v->purchase_date ? $v->purchase_date->format('Y-m-d') : '';
        $this->salesman_id_str     = $v->salesman_id   ? (string)$v->salesman_id : '';
        $this->purchase_from       = $v->purchase_from ?? '';
        $this->purchase_evidence_type = $v->purchase_evidence_type ?? '';
        $this->purchase_partner_type  = $v->purchase_partner_type  ?? '';
        // 큐 20-A/C — 매입처 계좌 4컬럼 (account는 모델 decrypt accessor에서 평문)
        $this->purchase_seller_bank    = $v->purchase_seller_bank    ?? '';
        $this->purchase_seller_account = $v->purchase_seller_account ?? '';
        $this->purchase_seller_holder  = $v->purchase_seller_holder  ?? '';
        $this->purchase_bank_memo      = $v->purchase_bank_memo      ?? '';
        $this->purchase_fee_bank       = $v->purchase_fee_bank       ?? '';
        $this->purchase_fee_account    = $v->purchase_fee_account    ?? '';
        $this->purchase_fee_holder     = $v->purchase_fee_holder     ?? '';
        $this->purchase_price_str  = $v->purchase_price ? number_format($v->purchase_price) : '';
        $this->selling_fee_str     = $v->selling_fee    ? number_format($v->selling_fee)    : '';
        $this->cost_deregistration_str = $v->cost_deregistration ? number_format($v->cost_deregistration) : '';
        $this->cost_license_str    = $v->cost_license   ? number_format($v->cost_license)   : '';
        $this->cost_towing_str     = $v->cost_towing    ? number_format($v->cost_towing)    : '';
        $this->cost_carry_str      = $v->cost_carry     ? number_format($v->cost_carry)     : '';
        $this->cost_shoring_str    = $v->cost_shoring   ? number_format($v->cost_shoring)   : '';
        $this->cost_insurance_str  = $v->cost_insurance ? number_format($v->cost_insurance) : '';
        $this->cost_transfer_str   = $v->cost_transfer  ? number_format($v->cost_transfer)  : '';
        $this->cost_extra1_str     = $v->cost_extra1    ? number_format($v->cost_extra1)    : '';
        $this->cost_extra2_str     = $v->cost_extra2    ? number_format($v->cost_extra2)    : '';
        // 22-C-E (2026-05-20) — 2 _str = type별 confirmed PBP 합산 (down/selling_fee).
        $confirmedPbp = $v->purchaseBalancePayments->whereNotNull('confirmed_at');
        $sumPbpByType = function (string $type) use ($confirmedPbp): string {
            $sum = $confirmedPbp->where('type', $type)->sum('amount');

            return $sum > 0 ? number_format((int) $sum) : '';
        };
        $this->down_payment_str        = $sumPbpByType('down');
        $this->selling_fee_payment_str = $sumPbpByType('selling_fee');
        $this->purchase_remittance_memo = $v->purchase_remittance_memo ?? '';
        $this->registration_number = $v->registration_number ?? '';
        $this->reg_cert_number = $v->reg_cert_number ?? '';
        $this->is_deregistered = $v->is_deregistered;
        $this->deregistration_date = $v->deregistration_date ? $v->deregistration_date->format('Y-m-d') : '';
        $this->purchaseBalancePayments = $v->purchaseBalancePayments->map(fn($p) => [
            'id' => $p->id, 'amount' => (string)$p->amount,
            'payment_date' => $p->payment_date?->format('Y-m-d') ?? '', 'note' => $p->note ?? '',
            // 큐 20-C — 분자 A안 시각화
            'confirmed_at' => $p->confirmed_at?->format('Y-m-d H:i'),
            'finance_confirmer' => $p->financeConfirmer?->name,
        ])->toArray();

        // 판매
        $this->sale_date         = $v->sale_date ? $v->sale_date->format('Y-m-d') : '';
        $this->currency          = $v->currency ?? 'USD';
        $this->exchange_rate_str = $v->exchange_rate ? (string)$v->exchange_rate : '';
        $this->buyer_id_str      = $v->buyer_id     ? (string)$v->buyer_id     : '';
        $this->consignee_id_str  = $v->consignee_id ? (string)$v->consignee_id : '';
        $this->sale_price_str       = $v->sale_price       ? (string)$v->sale_price       : '';
        $this->tax_dc_str           = $v->tax_dc           ? (string)$v->tax_dc           : '';
        $this->commission_str       = $v->commission       ? (string)$v->commission       : '';
        $this->transport_fee_str    = $v->transport_fee    ? (string)$v->transport_fee    : '';
        $this->auto_loading_str     = $v->auto_loading     ? (string)$v->auto_loading     : '';
        $this->sale_other_costs_str = $v->sale_other_costs ? (string)$v->sale_other_costs : '';
        // 22-A-3a 사용자 정정 (2026-05-20) — 4 _str = type별 confirmed FP 합산.
        $confirmedFp = $v->finalPayments->whereNotNull('confirmed_at');
        $sumByType = function (string $type) use ($confirmedFp): string {
            $sum = $confirmedFp->where('type', $type)->sum('amount');

            return $sum > 0 ? (string) (int) $sum : '';
        };
        $this->deposit_down_payment_str = $sumByType('deposit_down');
        $this->interim_payment_str = $sumByType('interim');
        $this->advance_payment1_str = $sumByType('advance_1');
        $this->fee_str               = $sumByType('fee');
        $this->savings_used_str     = $v->savings_used     ? (string)$v->savings_used     : '';
        $this->savings_deposit_str  = '';   // 입력란은 항상 빈 값 (누적은 buyerSavingsBalance computed)
        $this->finalPayments = $v->finalPayments->map(function ($p) use ($lockedFinalIds, $transferLinkedPayments, $pendingVoidTransferIds) {
            $row = [
                'id' => $p->id, 'amount' => (string) $p->amount,
                // 회의확장씬 #7 (2026-05-22) — 잔금 row 별 입금 시점 환율
                'exchange_rate' => $p->exchange_rate ? (string) $p->exchange_rate : '',
                'payment_date' => $p->payment_date?->format('Y-m-d') ?? '', 'note' => $p->note ?? '',
                'locked' => in_array($p->id, $lockedFinalIds),
                'transfer' => null,
                // 큐 20-C — 분자 A안 시각화: confirmed_at 유무로 row 색 분기
                'confirmed_at' => $p->confirmed_at?->format('Y-m-d H:i'),
                'finance_confirmer' => $p->financeConfirmer?->name,
            ];
            if ($linked = $transferLinkedPayments->get($p->id)) {
                $t = $linked->transfer;
                $isExecuted = $t->status === \App\Models\InterVehicleTransfer::STATUS_EXECUTED;
                $pendingVoid = in_array($t->id, $pendingVoidTransferIds, true);
                // 큐 19-E — void 가능 조건: status=executed AND 영업/관리/admin 권한 AND pending void 없음
                $user = auth()->user();
                $canVoid = $isExecuted && ! $pendingVoid && $user && ($user->canApprove() || $user->role === '영업');
                $row['transfer'] = [
                    'id' => $t->id,
                    'amount' => (float) $t->amount,
                    'currency' => $t->currency,
                    'status' => $t->status,
                    'approval_request_id' => $t->approval_request_id,
                    'direction' => $p->amount < 0 ? 'outgoing' : 'incoming',  // 이 차량 기준 출/입
                    'counterpart_id' => $p->amount < 0 ? $t->target_vehicle_id : $t->source_vehicle_id,
                    'counterpart_number' => $p->amount < 0
                        ? $t->targetVehicle?->vehicle_number
                        : $t->sourceVehicle?->vehicle_number,
                    'can_void' => $canVoid,
                    'pending_void' => $pendingVoid,
                ];
            }

            return $row;
        })->toArray();

        // 카풀/헤이맨 계산서
        // 큐 16 — tax_invoice_*·agency_fee 로드 제거 (DB drop됨).

        // 수출통관
        $this->export_buyer_id_str       = $v->export_buyer_id       ? (string)$v->export_buyer_id       : '';
        $this->export_consignee_id_str   = $v->export_consignee_id   ? (string)$v->export_consignee_id   : '';
        $this->forwarding_company_id_str = $v->forwarding_company_id ? (string)$v->forwarding_company_id : '';
        $this->export_declaration_amount_str = $v->export_declaration_amount ? (string)$v->export_declaration_amount : '';
        $this->export_declaration_number     = $v->export_declaration_number ?? '';
        $this->shipping_date   = $v->shipping_date ? $v->shipping_date->format('Y-m-d') : '';
        $this->eta_date        = $v->eta_date      ? $v->eta_date->format('Y-m-d')      : '';
        $this->shipping_method = $v->shipping_method ?? '';
        $this->port_of_loading = $v->port_of_loading ?? '';
        $this->incoterms = $v->incoterms ?? '';
        $this->discharge_port_id_str = $v->discharge_port_id ? (string) $v->discharge_port_id : '';
        $this->is_export_cleared = $v->is_export_cleared;

        // 선적
        $this->bl_buyer_id_str     = $v->bl_buyer_id     ? (string)$v->bl_buyer_id     : '';
        $this->bl_consignee_id_str = $v->bl_consignee_id ? (string)$v->bl_consignee_id : '';
        $this->bl_number           = $v->bl_number           ?? '';
        $this->container_number    = $v->container_number    ?? '';
        $this->bl_loading_location = $v->bl_loading_location ?? '';
        $this->vessel_name         = $v->vessel_name         ?? '';
        $this->bl_type             = $v->bl_type             ?? '';
        $this->bl_issue_date       = $v->bl_issue_date ? $v->bl_issue_date->format('Y-m-d') : '';
        $this->document_deadline_date = $v->document_deadline_date ? $v->document_deadline_date->format('Y-m-d') : '';

        // DHL
        $this->dhl_recipient_name    = $v->dhl_recipient_name    ?? '';
        $this->dhl_recipient_address = $v->dhl_recipient_address ?? '';
        $this->dhl_recipient_phone   = $v->dhl_recipient_phone   ?? '';
        $this->dhl_sender_name       = $v->dhl_sender_name       ?? '';
        $this->dhl_sender_address    = $v->dhl_sender_address    ?? '';
        $this->dhl_weight_str        = $v->dhl_weight ? (string)$v->dhl_weight : '';
        $this->dhl_dimensions        = $v->dhl_dimensions ?? '';
        $this->dhl_request           = $v->dhl_request;

        $this->memo = $v->memo ?? '';

        $this->deregistration_document_path     = $v->deregistration_document     ?? '';
        $this->export_declaration_document_path = $v->export_declaration_document ?? '';
        $this->bl_document_path                 = $v->bl_document                 ?? '';
        $this->deregistrationDocFile = $this->exportDeclarationDocFile = $this->blDocFile = null;
        $this->deregistrationBuyerPhone         = $v->deregistration_notice_phone ?? '';
        $this->clearDeregistrationDoc = $this->clearExportDeclarationDoc = $this->clearBlDoc = false;

        // 차량 사진 로드 (편집 패널 갤러리) — 탭별 분리: 기본정보=차량사진(category != 'shipping'), 선적=선박사진.
        $mapPhoto = fn ($p) => [
            'id'       => $p->id,
            'url'      => $p->url,
            'is_image' => $p->is_image,
            'filename' => $p->filename,
            'ext'      => $p->extension,
        ];
        $this->photoFiles = [];
        $this->photoUpload = [];
        $this->deletePhotoIds = [];
        $this->existingPhotos = $v->photos->where('category', '!=', 'shipping')->map($mapPhoto)->values()->all();

        // 선적 탭 선박 사진
        $this->shipPhotoFiles = [];
        $this->shipPhotoUpload = [];
        $this->deleteShipPhotoIds = [];
        $this->existingShipPhotos = $v->photos->where('category', 'shipping')->map($mapPhoto)->values()->all();

        $this->panelUnpaidRatio = $v->unpaid_ratio;
        // 판매 전(분모 0) 이면 null → "—" (unpaid_ratio 와 동일 게이팅)
        $saleTotal = (float) $v->sale_total_amount;
        $this->panelSaleTotal = $saleTotal > 0 ? $saleTotal : null;
        $this->panelSaleUnpaid = $saleTotal > 0 ? (float) $v->sale_unpaid_amount : null;

        // 매입탭 미지급 요약 스냅샷 (KRW). 총매입액 = 매입가 + 매도비. 미지급 = purchase_unpaid_amount(§13 단일 출처).
        $purchaseTotal = (int) ($v->purchase_price ?? 0) + (int) ($v->selling_fee ?? 0);
        if ($purchaseTotal > 0) {
            $this->panelPurchaseTotal = $purchaseTotal;
            $this->panelPurchaseUnpaid = (int) $v->purchase_unpaid_amount;
            $this->panelPurchasePaid = $purchaseTotal - $this->panelPurchaseUnpaid;
            // karaba 항목별 (구입금액/매도비)
            $this->panelPurchasePriceTotal = (int) $v->purchase_price;
            $this->panelSellingFeeTotal = (int) $v->selling_fee;
            $this->panelSellingFeePaid = (int) $v->selling_fee_payment;
            $this->panelPurchasePricePaid = $this->panelPurchasePaid - (int) $v->selling_fee_payment;
        } else {
            $this->panelPurchaseTotal = $this->panelPurchasePaid = $this->panelPurchaseUnpaid = null;
            $this->panelPurchasePriceTotal = $this->panelPurchasePricePaid = null;
            $this->panelSellingFeeTotal = $this->panelSellingFeePaid = null;
        }

        // 큐 21 — Ledger 잠금 상태 갱신
        $this->refreshLedgerLockState($v);

        $this->showPanel = true;
    }

    public function close(): void
    {
        $this->resetValidation();
        $this->showPanel = false;
        $this->keepPanelOpen = false;
        // 동시 편집 잠금 — 내 잠금 해제 후 editingId 비움 (순서 중요: 해제는 editingId 기준).
        $this->releaseEditLock($this->editingId);
        $this->editLockedByOther = false;
        $this->editLockOwnerName = '';
        $this->editingId = null;
        $this->justCreatedId = null;
        $this->panelUnpaidRatio = null;
        // 큐 19-C — 자금 이체 모달도 동일 정리
        $this->resetTransferRequestForm();
        // 큐 21 — Ledger 잠금 모달도 정리
        $this->showLedgerUnlockModal = false;
        $this->ledgerUnlockReason = '';
        $this->isLedgerLocked = false;
        $this->hasLedgerUnlockToken = false;
        // 큐 21 후속 — 말소·수출통관 mismatch 모달 정리
        $this->showDocCheckModal = false;
        $this->userConfirmedDocCheckMismatch = false;
        $this->docCheckMismatches = [];
    }

    // ── 큐 21 후속 — 말소·수출통관 mismatch 감지 + 모달 (사용자 결정 2026-05-18) ────────
    private function detectDocCheckMismatches(): array
    {
        $mismatches = [];

        // 말소: is_deregistered ↔ deregistration_document
        $hasDeregDoc = ($this->deregistrationDocFile !== null)
            || ($this->deregistration_document_path !== '' && ! $this->clearDeregistrationDoc);
        if ($this->is_deregistered xor $hasDeregDoc) {
            $mismatches[] = [
                'stage' => 'deregistration',
                'label' => '말소',
                'tab' => 'purchase',
                'checked' => $this->is_deregistered,
                'has_doc' => $hasDeregDoc,
            ];
        }

        // 수출통관: is_export_cleared ↔ export_declaration_document
        $hasExportDoc = ($this->exportDeclarationDocFile !== null)
            || ($this->export_declaration_document_path !== '' && ! $this->clearExportDeclarationDoc);
        if ($this->is_export_cleared xor $hasExportDoc) {
            $mismatches[] = [
                'stage' => 'export_clearance',
                'label' => '수출통관',
                'tab' => 'export',
                'checked' => $this->is_export_cleared,
                'has_doc' => $hasExportDoc,
            ];
        }

        return $mismatches;
    }

    public function confirmSaveWithDocMismatch(): void
    {
        $this->userConfirmedDocCheckMismatch = true;
        $this->showDocCheckModal = false;
        $this->save();
    }

    public function dismissDocCheckModal(string $jumpToTab = ''): void
    {
        $this->showDocCheckModal = false;
        $this->docCheckMismatches = [];
        $this->userConfirmedDocCheckMismatch = false;
        if ($jumpToTab !== '') {
            $this->dispatch('switch-tab', tab: $jumpToTab);
        }
    }

    // ── 큐 21 — Ledger 잠금 상태 메서드 (회의록 2026-05-18) ────────────
    private function refreshLedgerLockState(?Vehicle $vehicle = null): void
    {
        $this->canUnlockLedger = false;
        if (! $this->editingId) {
            $this->isLedgerLocked = false;
            $this->hasLedgerUnlockToken = false;

            return;
        }
        $v = $vehicle ?: Vehicle::find($this->editingId);
        if (! $v) {
            $this->isLedgerLocked = false;
            $this->hasLedgerUnlockToken = false;

            return;
        }
        $this->isLedgerLocked = $v->hasConfirmedPaymentLock();
        $this->hasLedgerUnlockToken = \Illuminate\Support\Facades\Cache::has(
            Vehicle::ledgerUnlockCacheKey($v->id)
        );
        $this->canUnlockLedger = auth()->user()?->canUnlockLedger($v) ?? false;
    }

    public function openLedgerUnlockModal(): void
    {
        if (! $this->editingId) {
            return;
        }
        // 권한 재확인 — super/admin(전체) + role '관리'(본인 팀). editingId 는 클라이언트 주입 가능 → 매번 차량 기준 재인가.
        $v = Vehicle::find($this->editingId);
        if (! $v || ! auth()->user()?->canUnlockLedger($v)) {
            $this->dispatch('notify', message: __('vehicle.toast.unlock_no_perm'), type: 'error');

            return;
        }
        $this->ledgerUnlockReason = '';
        $this->showLedgerUnlockModal = true;
    }

    public function closeLedgerUnlockModal(): void
    {
        $this->showLedgerUnlockModal = false;
        $this->ledgerUnlockReason = '';
    }

    public function submitLedgerUnlock(): void
    {
        $this->validate(
            ['ledgerUnlockReason' => ['required', 'string', 'min:10']],
            ['ledgerUnlockReason.required' => __('vehicle.valmsg.unlock_reason_required'),
                'ledgerUnlockReason.min' => __('vehicle.valmsg.unlock_reason_min')]
        );

        try {
            $v = Vehicle::findOrFail($this->editingId);
            app(\App\Services\VehicleLedgerUnlockService::class)->unlock(
                $v,
                auth()->user(),
                $this->ledgerUnlockReason
            );
        } catch (\Throwable $e) {
            $this->dispatch('notify', message: __('vehicle.toast.unlock_failed', ['error' => $e->getMessage()]), type: 'error');

            return;
        }

        $this->refreshLedgerLockState();
        $this->closeLedgerUnlockModal();
        $this->dispatch('notify',
            message: __('vehicle.toast.unlock_done'),
            type: 'success');
    }

    private function resetTransferRequestForm(): void
    {
        $this->showTransferRequestModal = false;
        $this->transferTargetVehicleId = '';
        $this->transferAmountStr = '';
        $this->transferReason = '';
        $this->transferNotes = '';
        $this->resetErrorBag(['transferTargetVehicleId', 'transferAmountStr', 'transferReason']);
        $this->resetTransferVoidForm();
    }

    private function resetTransferVoidForm(): void
    {
        $this->showTransferVoidModal = false;
        $this->voidTransferId = null;
        $this->voidReason = '';
        $this->resetErrorBag('voidReason');
    }

    public function removeDeregistrationDoc(): void
    {
        $this->clearDeregistrationDoc = true;
        $this->deregistrationDocFile = null;
        $this->deregistration_document_path = '';
    }

    // 딜러 번호 blur 시 서버에서 하이픈 정규화 — 클라 JS(실시간 미리보기)의 morph 타이밍과
    // 무관하게 확정 포맷을 반영(jin 2026-07-10 — 저장 직후 3-3-5 잔상 방지).
    public function updatedDeregistrationBuyerPhone(): void
    {
        $this->deregistrationBuyerPhone = \App\Support\PhoneFormat::format($this->deregistrationBuyerPhone) ?? '';
    }

    // 국내 바이어에게 말소등록증 전달 알림톡 발송 (수동 버튼). 만료 서명 링크(3일)를 본문에 담아 보낸다.
    // 발송 안전(fire-and-forget)은 BizmAlimtalkService 가 보장 — 여기선 결과 상태만 토스트.
    public function sendDeregistrationAlimtalk(): void
    {
        // 신규 등록(미저장) 상태에선 서명 링크를 만들 차량 id·저장된 말소증이 없어 발송 불가.
        // 블록은 등록 화면에도 노출(번호 미리 입력)하되, 발송은 최초 저장 후 재열람에서만 — 조용한 no-op 대신 안내.
        if ($this->editingId === null) {
            $this->dispatch('notify', message: __('vehicle.deregnotice.save_first'), type: 'warning');

            return;
        }
        $vehicle = \App\Models\Vehicle::find($this->editingId);
        abort_unless($vehicle && auth()->user()->canScopeVehicle($vehicle), 403);

        if (blank($vehicle->deregistration_document)) {
            $this->dispatch('notify', message: __('vehicle.deregnotice.no_doc'), type: 'error');

            return;
        }
        $phone = trim($this->deregistrationBuyerPhone);
        if ($phone === '') {
            $this->dispatch('notify', message: __('vehicle.deregnotice.no_phone'), type: 'error');

            return;
        }

        $link = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'buyer.deregistration', now()->addDays(3), ['vehicle' => $vehicle->id],
        );

        $log = \App\Services\BizmAlimtalkService::active()->send('erp_deregistration_notice', $phone, [
            '차량번호' => $vehicle->vehicle_number,
            '링크' => $link,
        ], ['vehicle_id' => $vehicle->id, 'user_id' => auth()->id()]);

        if ($log->status === 'sent') {
            $this->dispatch('notify', message: __('vehicle.deregnotice.sent'), type: 'success');
        } else {
            $this->dispatch('notify', message: __('vehicle.deregnotice.failed', ['reason' => $log->error ?: $log->status]), type: 'error');
        }
    }

    public function removeExportDeclarationDoc(): void
    {
        $this->clearExportDeclarationDoc = true;
        $this->exportDeclarationDocFile = null;
        $this->export_declaration_document_path = '';
    }

    public function removeBlDoc(): void
    {
        $this->clearBlDoc = true;
        $this->blDocFile = null;
        $this->bl_document_path = '';
    }

    /**
     * 파일 선택 시 photoFiles 버퍼에 누적.
     * Livewire 는 input 재선택 시 바인딩을 교체하므로, 한 번에 여러 개 선택하거나
     * 여러 번 나눠 선택해도 모두 합쳐지도록 매 선택을 버퍼로 옮기고 input 을 비운다.
     */
    public function updatedPhotoUpload(): void
    {
        $this->validate([
            'photoUpload.*' => ['file', 'mimes:jpeg,jpg,png,gif,webp,bmp,pdf,xlsx,xls,csv,docx,doc,hwp,hwpx,pptx,ppt,txt,zip', 'max:10240'],
        ]);

        foreach ($this->photoUpload as $file) {
            if (count($this->existingPhotos) + count($this->photoFiles) >= self::MAX_PHOTOS) {
                $this->dispatch('notify', message: __('vehicle.toast.max_photos', ['max' => self::MAX_PHOTOS]), type: 'warning');
                break;
            }
            $this->photoFiles[] = $file;
        }

        $this->photoUpload = [];
    }

    /** 신규 업로드 미리보기에서 한 장 제거 (저장 전). */
    public function removeNewPhoto(int $idx): void
    {
        unset($this->photoFiles[$idx]);
        $this->photoFiles = array_values($this->photoFiles);
    }

    /** 기존 사진 한 장 삭제 staged — save 시 DB row + 디스크에서 제거. */
    public function removeExistingPhoto(int $id): void
    {
        if (! in_array($id, $this->deletePhotoIds, true)) {
            $this->deletePhotoIds[] = $id;
        }
        $this->existingPhotos = array_values(array_filter(
            $this->existingPhotos,
            fn ($p) => $p['id'] !== $id,
        ));
    }

    /** 선적 탭 선박 사진 — 파일 선택 누적(기본정보 사진과 동일 방식, 별도 버퍼·30건 한도). */
    public function updatedShipPhotoUpload(): void
    {
        $this->validate([
            'shipPhotoUpload.*' => ['file', 'mimes:jpeg,jpg,png,gif,webp,bmp,pdf,xlsx,xls,csv,docx,doc,hwp,hwpx,pptx,ppt,txt,zip', 'max:10240'],
        ]);

        foreach ($this->shipPhotoUpload as $file) {
            if (count($this->existingShipPhotos) + count($this->shipPhotoFiles) >= self::MAX_SHIP_PHOTOS) {
                $this->dispatch('notify', message: __('vehicle.toast.max_photos', ['max' => self::MAX_SHIP_PHOTOS]), type: 'warning');
                break;
            }
            $this->shipPhotoFiles[] = $file;
        }

        $this->shipPhotoUpload = [];
    }

    public function removeNewShipPhoto(int $idx): void
    {
        unset($this->shipPhotoFiles[$idx]);
        $this->shipPhotoFiles = array_values($this->shipPhotoFiles);
    }

    public function removeExistingShipPhoto(int $id): void
    {
        if (! in_array($id, $this->deleteShipPhotoIds, true)) {
            $this->deleteShipPhotoIds[] = $id;
        }
        $this->existingShipPhotos = array_values(array_filter(
            $this->existingShipPhotos,
            fn ($p) => $p['id'] !== $id,
        ));
    }

    // 버그 2 fix (2026-05-18) — 새 파일 업로드 시 clear flag reset (safety net).
    // [삭제] → 새 파일 업로드 흐름에서 clearBlDoc stale 상태로 인한 save 사이클 충돌 방지.
    public function updatedDeregistrationDocFile(): void
    {
        if ($this->deregistrationDocFile !== null) {
            $this->clearDeregistrationDoc = false;
        }
    }

    public function updatedExportDeclarationDocFile(): void
    {
        if ($this->exportDeclarationDocFile !== null) {
            $this->clearExportDeclarationDoc = false;
        }
    }

    public function updatedBlDocFile(): void
    {
        if ($this->blDocFile !== null) {
            $this->clearBlDoc = false;
        }
    }

    private function validateVehicleForm(): void
    {
        $nonNegativeNumeric = function (string $attribute, mixed $value, \Closure $fail) {
            // 앞뒤 공백 방어 — NICE/붙여넣기로 들어온 공백-only 값은 trim 후 빈 값 취급(미입력 허용).
            // (behavior change: ' ' 가 이전엔 '숫자 아님' 에러였으나 이제 빈 칸으로 통과)
            if (is_string($value)) {
                $value = trim($value);
            }
            if ($value === '' || $value === null) {
                return;
            }
            // 큐 19-F/20-C 보강 — finalPayments.*.amount 의 경우 transfer_id 있는 row는 skip.
            // 자금 이체 페어(음수 row)는 UI에서 readonly로 노출되고 영업이 입력한 게 아님.
            if (preg_match('/^finalPayments\.(\d+)\.amount$/', $attribute, $m)) {
                $idx = (int) $m[1];
                $row = $this->finalPayments[$idx] ?? null;
                if (is_array($row) && ! empty($row['transfer'])) {
                    return;
                }
            }
            $cleaned = str_replace(',', '', (string) $value);
            if (! is_numeric($cleaned) || (float) $cleaned < 0) {
                $fail(':attribute은(는) 0 이상의 숫자여야 합니다.');
            }
        };

        $numericFields = [
            'year_str', 'cc_str', 'weight_kg_str', 'mileage_str',
            'nice_reg_max_load_str', 'nice_reg_passengers_str',
            'nice_spec_displacement_str', 'nice_spec_length_str',
            'nice_spec_width_str', 'nice_spec_height_str',
            'nice_spec_wheelbase_str', 'nice_spec_curb_weight_str',
            'purchase_price_str', 'selling_fee_str',
            'cost_deregistration_str', 'cost_license_str', 'cost_towing_str',
            'cost_carry_str', 'cost_shoring_str', 'cost_insurance_str',
            'cost_transfer_str', 'cost_extra1_str', 'cost_extra2_str',
            'down_payment_str', 'selling_fee_payment_str',
            'exchange_rate_str', 'sale_price_str', 'tax_dc_str',
            'commission_str', 'transport_fee_str', 'auto_loading_str',
            'sale_other_costs_str', 'savings_used_str',
            // 22-A-3a 사용자 정정 — 4 _str 복귀 (재무·관리자 입력)
            'deposit_down_payment_str', 'interim_payment_str',
            'advance_payment1_str', 'fee_str',
            'export_declaration_amount_str', 'dhl_weight_str',
        ];

        $rules = [
            'vehicle_number' => [
                'required', 'string', 'max:20',
                // C6 — soft-delete된 row 제외. closure로 동적 메시지 제공 (방금 저장된 차량인지 힌트 포함).
                function (string $attribute, mixed $value, \Closure $fail) {
                    $q = \App\Models\Vehicle::where('vehicle_number', $value)->whereNull('deleted_at');
                    if ($this->editingId) {
                        $q->where('id', '!=', $this->editingId);
                    }
                    $existing = $q->first(['id', 'vehicle_number', 'created_at']);
                    if (! $existing) {
                        return;
                    }
                    $minutesAgo = (int) $existing->created_at->diffInMinutes(now());
                    $hint = $minutesAgo <= 5 ? __('vehicle.toast.dup_hint') : '';
                    $fail(__('vehicle.toast.dup_vehicle', ['value' => $value, 'id' => $existing->id, 'hint' => $hint]));
                },
            ],
            // 큐 16 — sales_channel은 enum 'export' 단일값. DB 레벨 강제 + 폼은 hidden 유지.
            'sales_channel'   => ['required', 'in:export'],
            'currency'        => ['required', 'in:USD,JPY,EUR,GBP,CNY,KRW'],
            'shipping_method' => ['nullable', 'in:RORO,CONTAINER'],

            'purchase_date'       => ['nullable', 'date'],
            'sale_date'           => ['nullable', 'date'],
            'shipping_date'       => ['nullable', 'date'],
            'eta_date'            => ['nullable', 'date'],
            'bl_issue_date'       => ['nullable', 'date'],
            'document_deadline_date' => ['nullable', 'date'],
            'nice_reg_first_date' => ['nullable', 'date'],
            'nice_reg_date'       => ['nullable', 'date'],

            'salesman_id_str'           => [Rule::when($this->salesman_id_str !== '', ['exists:salesmen,id'])],
            'buyer_id_str'              => [Rule::when($this->buyer_id_str !== '', ['exists:buyers,id'])],
            'consignee_id_str'          => [Rule::when($this->consignee_id_str !== '', ['exists:consignees,id'])],
            'export_buyer_id_str'       => [Rule::when($this->export_buyer_id_str !== '', ['exists:buyers,id'])],
            'export_consignee_id_str'   => [Rule::when($this->export_consignee_id_str !== '', ['exists:consignees,id'])],
            'forwarding_company_id_str' => [Rule::when($this->forwarding_company_id_str !== '', ['exists:forwarding_companies,id'])],
            'bl_buyer_id_str'           => [Rule::when($this->bl_buyer_id_str !== '', ['exists:buyers,id'])],
            'bl_consignee_id_str'       => [Rule::when($this->bl_consignee_id_str !== '', ['exists:consignees,id'])],

            'deregistrationDocFile'    => ['nullable', 'file', 'mimes:jpeg,jpg,png,gif,webp,bmp,pdf,xlsx,xls,csv,docx,doc,hwp,hwpx,pptx,ppt,txt,zip', 'max:10240'],
            'exportDeclarationDocFile' => ['nullable', 'file', 'mimes:jpeg,jpg,png,gif,webp,bmp,pdf,xlsx,xls,csv,docx,doc,hwp,hwpx,pptx,ppt,txt,zip', 'max:10240'],
            'blDocFile'                => ['nullable', 'file', 'mimes:jpeg,jpg,png,gif,webp,bmp,pdf,xlsx,xls,csv,docx,doc,hwp,hwpx,pptx,ppt,txt,zip', 'max:10240'],

            // 차량 첨부 — 사진(jpg/png/gif/webp/bmp) + 사무 파일(pdf·xlsx·xls·csv·docx·doc·hwp·hwpx·pptx·ppt·txt·zip), 건당 10MB.
            // 총 건수 제한은 아래 별도 가드. .exe / .php 같은 실행 파일은 mimes 화이트리스트로 자연 차단.
            'photoFiles.*' => ['file', 'mimes:jpeg,jpg,png,gif,webp,bmp,pdf,xlsx,xls,csv,docx,doc,hwp,hwpx,pptx,ppt,txt,zip', 'max:10240'],
            'shipPhotoFiles.*' => ['file', 'mimes:jpeg,jpg,png,gif,webp,bmp,pdf,xlsx,xls,csv,docx,doc,hwp,hwpx,pptx,ppt,txt,zip', 'max:10240'],

            // H9 — RRN(주민/법인등록번호) 형식 검증. 000000-0000000 패턴 강제.
            // 말소신청서·등록증재발급·양도증명서 PDF에 RRN 정확한 입력 필수.
            'nice_reg_owner_rrn' => ['nullable', 'string', 'regex:/^\d{6}-\d{7}$/'],

            'finalPayments.*.amount'           => [$nonNegativeNumeric],
            'finalPayments.*.payment_date'     => ['nullable', 'date'],
            'purchaseBalancePayments.*.amount' => [$nonNegativeNumeric],
            // C2 — 매입 잔금 payment_date 필수 (NULL이면 미지급 계산에서 제외되어 매입완료 오인)
            'purchaseBalancePayments.*.payment_date' => ['required', 'date'],
        ];

        foreach ($numericFields as $field) {
            $rules[$field] = [$nonNegativeNumeric];
        }

        // C1 + 2026-05-19 풀회의 안건 E — 판매 정보 입력(sale_price > 0) 시 필수 필드 강화.
        //   - 회의 명세: "판매일, 바이어, 통화, 판매가, 환율은 반드시 기입"
        //   - currency는 select 강제(default 'USD') → 별도 추가 불필요
        //   - 2026-05-20 사용자 정정: KRW는 환율 입력 불필요 (한국돈). Vehicle::saving 훅이 자동 1 normalize.
        //   - 외화만 사용자 명시 입력 강제 (C1 원형 보존).
        //   - 2026-05-26: buyer_id_str 'required' 는 이제 이 규칙의 단일 enforcement.
        //     (DB CHECK chk_sale_required 가 MySQL 8 error 3823 — FK 컬럼 CHECK 금지 —
        //      때문에 buyer_id 를 제외함. 마이그레이션 2026_05_20_000002 docblock 참조.)
        $salePrice = (float) str_replace(',', '', $this->sale_price_str ?: '0');
        if ($salePrice > 0) {
            $rules['sale_date'] = ['required', 'date'];
            $rules['buyer_id_str'] = ['required', 'exists:buyers,id'];
            if ($this->currency !== 'KRW') {
                $rules['exchange_rate_str'] = ['required', 'numeric', 'gt:0'];
            }
        }

        $attributes = [
            'vehicle_number' => __('vehicle.attr.vehicle_number'),
            'sales_channel'  => __('vehicle.attr.sales_channel'),
            'currency'       => __('vehicle.attr.currency'),
            'shipping_method' => __('vehicle.attr.shipping_method'),
            'purchase_date'  => __('vehicle.attr.purchase_date'),
            'sale_date'      => __('vehicle.attr.sale_date'),
            'shipping_date'  => __('vehicle.attr.shipping_date'),
            'eta_date'       => __('vehicle.attr.eta_date'),
            'bl_issue_date'  => __('vehicle.attr.bl_issue_date'),
            'nice_reg_first_date' => __('vehicle.attr.nice_reg_first_date'),
            'nice_reg_date'  => __('vehicle.attr.nice_reg_date'),
            'salesman_id_str' => __('vehicle.attr.salesman'),
            'buyer_id_str'    => __('vehicle.attr.buyer'),
            'consignee_id_str' => __('vehicle.attr.consignee'),
            'export_buyer_id_str'     => __('vehicle.attr.export_buyer'),
            'export_consignee_id_str' => __('vehicle.attr.export_consignee'),
            'forwarding_company_id_str' => __('vehicle.attr.forwarder'),
            'bl_buyer_id_str'    => __('vehicle.attr.bl_buyer'),
            'bl_consignee_id_str' => __('vehicle.attr.bl_consignee'),
            'deregistrationDocFile'    => __('vehicle.attr.derg_doc'),
            'exportDeclarationDocFile' => __('vehicle.attr.export_decl_doc'),
            'blDocFile'                => __('vehicle.attr.bl_doc'),
            'year_str' => __('vehicle.attr.year'), 'cc_str' => __('vehicle.attr.cc'),
            'weight_kg_str' => __('vehicle.attr.weight'), 'mileage_str' => __('vehicle.attr.mileage'),
            'purchase_price_str' => __('vehicle.attr.purchase_price'), 'selling_fee_str' => __('vehicle.attr.selling_fee'),
            'sale_price_str' => __('vehicle.attr.sale_price'), 'exchange_rate_str' => __('vehicle.attr.exchange_rate'),
            'export_declaration_amount_str' => __('vehicle.attr.export_decl_amount'),
            'dhl_weight_str' => __('vehicle.attr.dhl_weight'),
            'nice_reg_owner_rrn' => __('vehicle.attr.owner_rrn'),
            'purchaseBalancePayments.*.amount'       => __('vehicle.attr.purchase_balance_amount'),
            'purchaseBalancePayments.*.payment_date' => __('vehicle.attr.purchase_balance_date'),
            'finalPayments.*.amount'                 => __('vehicle.attr.final_payment_amount'),
            'finalPayments.*.payment_date'           => __('vehicle.attr.final_payment_date'),
        ];

        $messages = [
            'nice_reg_owner_rrn.regex' => __('vehicle.toast.rrn_format'),
        ];

        $this->validate($rules, $messages, $attributes);

        // 판매 필수 3종 — chk_sale_required(DB CHECK: 판매가>0 → 판매일·바이어·환율>0) 선제.
        //   신규·편집 모두. DB 위반 raw 500(SQLSTATE 노출)을 "무엇을·어느 탭에서" 친절 메시지로 대체.
        //   근거: 환율 0/빈값이면 정산 판매금원화=0 → 마이너스 정산(jin 2026-07-08 실측). KRW는 saving 훅이 1 normalize.
        $salePriceNum = (float) str_replace(',', '', $this->sale_price_str ?: '0');
        $rateNum = (float) str_replace(',', '', $this->exchange_rate_str ?: '0');
        if ($salePriceNum > 0) {
            if ($this->sale_date === '') {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'sale_date' => __('vehicle.valmsg.sale_date_required'),
                ]);
            }
            if ($this->buyer_id_str === '') {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'buyer_id_str' => __('vehicle.valmsg.sale_buyer_required'),
                ]);
            }
            if ($this->currency !== 'KRW' && $rateNum <= 0) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'exchange_rate_str' => __('vehicle.valmsg.exchange_rate_required'),
                ]);
            }
        }

        // 차량 첨부 총 건수 가드 — 유지될 기존(existingPhotos) + 신규(photoFiles) ≤ MAX_PHOTOS.
        if (count($this->existingPhotos) + count($this->photoFiles) > self::MAX_PHOTOS) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'photoFiles' => __('vehicle.toast.max_photos', ['max' => self::MAX_PHOTOS]),
            ]);
        }
        // 선박 사진(선적 탭)도 별도 갤러리라 자체 30건 한도.
        if (count($this->existingShipPhotos) + count($this->shipPhotoFiles) > self::MAX_SHIP_PHOTOS) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'shipPhotoFiles' => __('vehicle.toast.max_photos', ['max' => self::MAX_SHIP_PHOTOS]),
            ]);
        }
    }

    /**
     * UX #3 (2026-05-20) — 영업 저장 확인 모달 트리거.
     *
     * 영업 role: 모달 열기 → 매입+판매 필수 항목 미리보기 → [확인] 시 confirmAndSave() 호출 → save() 실행.
     * 다른 role (admin/재무/관리/통관): 모달 없이 즉시 save() (빠른 처리).
     */
    public function requestSave(string $tab = 'purchase'): void
    {
        // 회의확장씬 (2026-05-22) — 영업·관리 모달 노출. 4 탭(매입/판매/선적/통관) 만 모달.
        // 그 외(basic/dhl/docs) 즉시 save.
        // admin/super/재무/수출통관: 즉시 save (빠른 처리).
        $user = auth()->user();
        $modalTabs = ['purchase', 'sale', 'bl', 'clearance'];
        if ($user && in_array($user->role, ['영업', '관리'], true) && in_array($tab, $modalTabs, true)) {
            $this->activeTabForSave = $tab;
            $this->showSaveConfirmModal = true;

            return;
        }

        $this->save();
    }

    // "저장하고 계속" — 일반 저장과 동일 가드(서류불일치 확정 모달 포함) 통과.
    // keepPanelOpen 플래그를 세운 뒤 requestSave 로 위임 → 확정 모달을 거쳐도 플래그 유지 → save() 종료 시 close 대신 재로드.
    public function saveAndContinue(string $tab = 'purchase'): void
    {
        $this->keepPanelOpen = true;
        $this->requestSave($tab);
    }

    public function confirmAndSave(): void
    {
        $this->showSaveConfirmModal = false;
        $this->save();
    }

    public function closeSaveConfirmModal(): void
    {
        $this->showSaveConfirmModal = false;
        // 사용자가 모달을 취소하면 "저장하고 계속" 플래그도 해제 (다음 일반 저장에 잔존 방지).
        $this->keepPanelOpen = false;
    }

    public function save(): void
    {
        // "저장하고 계속" 플래그 캡처 후 즉시 해제 (조기 return·예외 경로에서도 잔존 안 하도록).
        $keepOpen = $this->keepPanelOpen;
        $this->keepPanelOpen = false;

        // C7-b 회의확장씬 (2026-05-22) — 신규 등록 권한: 영업·관리 role 또는 admin/super.
        // 사용자 헤더 명세 "[관리]가 차량등록부터 거래완료까지 모든 씬 진행" 완전 충족.
        // 수출통관·재무 role 은 신규 등록 차단 (admin/관리 가 만든 차량의 자기 영역만 편집).
        $user = auth()->user();
        if ($this->editingId === null && ! $user->isAdmin()
            && ! in_array($user->role, ['영업', '관리'], true)) {
            abort(403, __('vehicle.toast.create_perm'));
        }

        // Review.md #4 (2026-06-09) — 편집 분기 스코프 재인가.
        // openEdit 가 가드돼 있어도 editingId 를 클라이언트가 직접 주입할 수 있으므로
        // save() 에서 본인/팀 차량인지 다시 확인 (타 담당 차량 변조 IDOR 차단).
        if ($this->editingId) {
            $editingVehicle = \App\Models\Vehicle::find($this->editingId);
            abort_unless($editingVehicle && $user->canScopeVehicle($editingVehicle), 403, __('vehicle.toast.edit_own_only'));
            // 동시 편집 잠금 — 타인이 잠근 차량이면 저장 차단(읽기전용 UI 우회 방지).
            $this->assertEditable();
        }

        // 큐 14-4-4 후속 — 신규 등록 시 누락된 핵심 메타 자동 채움.
        // A) 영업 role이 본인 담당자 미지정으로 등록 → 자동으로 본인 salesman 적용.
        //    (영업 외 role/admin은 명시적 선택 필요 — 다른 영업 대신 등록할 수 있어 자동 set X)
        // B) 매입가/판매가가 입력됐는데 해당 날짜가 비면 오늘로 채움 (신규 저장 한정, jin 2026-07-07).
        //    - "해당 가격 있을 때만" — 매입가>0 → 매입일, 판매가>0 → 판매일. 가격 없으면 날짜 안 채움.
        //    - 편집·import 는 isNew 가드로 제외 → 과거행 날짜 오염·dateType 필터 왜곡 방지.
        //    - 판매일은 chk_sale_required(판매가>0 시 판매일 required) 검증 前에 채워야 통과.
        if ($this->editingId === null) {
            if ($this->salesman_id_str === '' && $user->role === '영업' && $user->salesman) {
                $this->salesman_id_str = (string) $user->salesman->id;
            }
            $purchasePrice = (float) str_replace(',', '', $this->purchase_price_str ?: '0');
            $salePrice = (float) str_replace(',', '', $this->sale_price_str ?: '0');
            if ($this->purchase_date === '' && $purchasePrice > 0) {
                $this->purchase_date = now()->format('Y-m-d');
            }
            if ($this->sale_date === '' && $salePrice > 0) {
                $this->sale_date = now()->format('Y-m-d');
            }
        }

        // ① 신규 등록 시 영업담당자·바이어 필수 (미수 게이트 전제 + 당사자 정본).
        //   import(ImportVehicles)·board(PurchaseSyncController)는 Vehicle::create 직접 호출 = save() 미경유라 자동 면제.
        //   기존(레거시) 무바이어 차량 편집은 editingId 있어 미적용 — 편집이 막히지 않음.
        //   안내: 담당자=기본정보 탭 / 바이어=판매 탭 — 어느 탭에서 지정하는지 토스트(어느 탭이든 보임)+인라인 에러.
        if ($this->editingId === null && ($this->salesman_id_str === '' || $this->buyer_id_str === '')) {
            $this->dispatch('notify', message: __('vehicle.valmsg.party_required'), type: 'warning');
            if ($this->salesman_id_str === '') {
                $this->addError('salesman_id_str', __('vehicle.valmsg.salesman_required'));
            }
            if ($this->buyer_id_str === '') {
                $this->addError('buyer_id_str', __('vehicle.valmsg.buyer_required'));
            }

            return;
        }

        // ② 미수 매입 게이트 — 신규 등록(또는 편집 중 바이어 교체) 시 바이어 총미수율 > 임계면 차단.
        //   관리/admin 이 모달에서 사유 입력·승인하면 이 저장 1회 통과((가) — 다음 차는 또 발동). 지속 토큰 없음.
        //   🔒 락 관제 — 매입 등록 락 OFF(super 토글) 시 게이트 skip.
        if (! $this->purchaseGateApproved && \App\Models\Setting::lockEnabled('purchase_registration') && $this->shouldCheckPurchaseGate()) {
            $buyer = \App\Models\Buyer::find($this->purchaseGateBuyerId());
            $gauge = $buyer?->receivableGauge();
            if ($gauge && $gauge['ratio'] > \App\Models\Buyer::RECEIVABLE_GATE_THRESHOLD) {
                $this->purchaseGateInfo = [
                    'buyer' => $buyer->name,
                    'ratio' => round($gauge['ratio'] * 100, 1),
                    'unpaid' => $gauge['unpaid_krw'],
                    'count' => $gauge['vehicle_count'],
                ];
                $this->purchaseGateReason = '';
                $this->showPurchaseGate = true;

                return;   // 저장 중단 — 차단/승인 모달 표시
            }
        }

        // C7-a — 회계 민감 컬럼 (매입가·판매가·환율·면장금액·비용9개) 변경 권한.
        // 정산/통관/관리 role이 변경 시도하면 silent restore + 토스트 안내.
        if ($this->editingId && ! $user->canEditVehicleFinancialFields()) {
            $this->restoreFinancialFieldsFromOriginal();
        }

        // H4 — paid 정산이 있는 차량의 회계 민감 컬럼 변경 차단 (retroactive drift 잠금).
        // admin도 변경 불가. 회계 마감된 정산의 표시값 보호.
        if ($this->editingId) {
            $this->guardFinancialDriftAfterPaid();
        }

        $this->validateVehicleForm();

        // 당사자 축소 (jin 2026-07-09) — 저장 시 authoritative 이어받기.
        //   바이어: 판매 → 선적·통관 / 컨사이니: 선적 → 통관. 방향1로 export_buyer_id 는 게이트 트리거 아님.
        $this->inheritParties();

        // C4·C5 — UI 저장 시점에 단계 의존성 검증 (시드/raw create는 우회).
        // 임시 Vehicle 인스턴스에 현재 form 값을 채워 guardStageOrderForExport 호출.
        $previewVehicle = $this->editingId
            ? \App\Models\Vehicle::find($this->editingId)?->replicate() ?? new \App\Models\Vehicle
            : new \App\Models\Vehicle;
        $previewVehicle->sales_channel = $this->sales_channel;
        $previewVehicle->is_deregistered = $this->is_deregistered;
        $previewVehicle->deregistration_document = $this->deregistration_document_path ?: ($this->deregistrationDocFile ? 'pending' : null);
        $previewVehicle->sale_price = (float) str_replace(',', '', $this->sale_price_str ?: '0');
        $previewVehicle->export_buyer_id = $this->export_buyer_id_str !== '' ? (int) $this->export_buyer_id_str : null;
        $previewVehicle->shipping_date = $this->shipping_date ?: null;
        $previewVehicle->export_declaration_document = $this->export_declaration_document_path ?: ($this->exportDeclarationDocFile ? 'pending' : null);
        $previewVehicle->bl_loading_location = $this->bl_loading_location ?: null;
        // 당사자 축소 (jin 2026-07-09) — 선적 컨사이니 가드가 form 입력값을 보도록 반영
        //   (미반영 시 replicate()의 DB 옛값/신규 null 로 판정돼 방금 입력한 선적 컨사이니가 무시됨).
        $previewVehicle->bl_consignee_id = $this->bl_consignee_id_str !== '' ? (int) $this->bl_consignee_id_str : null;
        $previewVehicle->bl_document = $this->bl_document_path ?: ($this->blDocFile ? 'pending' : null);
        $previewVehicle->dhl_request = $this->dhl_request;
        $previewVehicle->is_export_cleared = $this->is_export_cleared;
        // sale_unpaid_amount accessor는 finalPayments/receivableHistories를 보지만, save 단계에선
        // 현재 form의 deposit/잔금 입력값으로 임시 계산. 단순화 — 미입금 잔존은 DB 저장 후 정확.
        // 여기선 ID 있는 차량의 기존 sale_unpaid 캐시를 활용해 1차 검증만.
        if ($this->editingId) {
            // replicate()는 exists=false·id=null로 만들어주는데,
            // 이 상태에서 hasUnpaidOverride() 쿼리가 빈 결과를 반환해 admin이 발급한
            // 미입금 우회 승인이 무시되던 버그. 원본 차량 식별자를 복원해서 관계 쿼리 가능하게.
            $previewVehicle->id = $this->editingId;
            $previewVehicle->exists = true;

            $existing = \App\Models\Vehicle::find($this->editingId);
            if ($existing && $existing->sale_unpaid_amount_krw_cache !== null) {
                // 기존 차량의 미입금 캐시로 C5 평가
                $previewVehicle->setRawAttributes(array_merge(
                    $previewVehicle->getAttributes(),
                    ['sale_unpaid_amount_krw_cache' => $existing->sale_unpaid_amount_krw_cache]
                ));
            }
        }
        $previewVehicle->guardStageOrderForExport();
        $previewVehicle->guardAttachmentDeps();

        // H10 — 말소 처리(is_deregistered=true) 시 RRN 필수.
        // 말소신청서·등록증재발급·양도증명서 PDF가 RRN 필드 사용. 빈칸 발급 차단.
        if ($this->is_deregistered && empty($this->nice_reg_owner_rrn)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'nice_reg_owner_rrn' => __('vehicle.toast.rrn_required'),
            ]);
        }

        // 큐 21 후속 — 말소·수출통관 체크↔서류 mismatch 모달 (사용자 결정 2026-05-18).
        // 모든 validation 통과 후 마지막 단계 — 한쪽만 있으면 단계 진입 안 되므로 사용자 인지 강제.
        if (! $this->userConfirmedDocCheckMismatch) {
            $mismatches = $this->detectDocCheckMismatches();
            if (! empty($mismatches)) {
                $this->docCheckMismatches = $mismatches;
                $this->showDocCheckModal = true;

                return;   // save 보류 — 모달 후 confirmSaveWithDocMismatch에서 재호출
            }
        }

        $toInt = fn(?string $v): int => (int) str_replace(',', '', $v ?? '');
        $toFloat = fn(?string $v): float => (float) str_replace(',', '', $v ?? '');
        $toDate = fn(string $v): ?string => $v !== '' ? $v : null;
        $toId = fn(string $v): ?int => $v !== '' ? (int)$v : null;

        $data = [
            'vehicle_number' => $this->vehicle_number,
            'sales_channel'  => $this->sales_channel,
            'brand'      => $this->brand      ?: null,
            'model_type' => $this->model_type ?: null,
            'year'       => $this->year_str      !== '' ? (int)$this->year_str      : null,
            'cc'         => $this->cc_str        !== '' ? (int)$this->cc_str        : null,
            'weight_kg'  => $this->weight_kg_str !== '' ? (int)$this->weight_kg_str : null,
            'mileage'    => $this->mileage_str   !== '' ? (int)$this->mileage_str   : null,
            'color'      => $this->color ?: null,
            // NICE 등록
            'nice_reg_vin'          => $this->nice_reg_vin          ?: null,
            'nice_reg_engine_no'    => $this->nice_reg_engine_no    ?: null,
            'nice_reg_fuel_type'    => $this->nice_reg_fuel_type    ?: null,
            'nice_reg_use_type'     => $this->nice_reg_use_type     ?: null,
            'nice_reg_vehicle_form' => $this->nice_reg_vehicle_form ?: null,
            'nice_reg_first_date'   => $toDate($this->nice_reg_first_date),
            'nice_reg_date'         => $toDate($this->nice_reg_date),
            'nice_reg_owner_name'   => $this->nice_reg_owner_name   ?: null,
            'nice_reg_owner_addr'   => $this->nice_reg_owner_addr   ?: null,
            'nice_reg_owner_rrn'    => $this->nice_reg_owner_rrn    ?: null,
            'nice_reg_max_load'     => $this->nice_reg_max_load_str   !== '' ? (int)$this->nice_reg_max_load_str   : null,
            'nice_reg_passengers'   => $this->nice_reg_passengers_str !== '' ? (int)$this->nice_reg_passengers_str : null,
            'nice_reg_color'        => $this->nice_reg_color ?: null,
            // NICE 제원
            'nice_spec_maker'           => $this->nice_spec_maker           ?: null,
            'nice_spec_model'           => $this->nice_spec_model           ?: null,
            'nice_spec_year'            => $this->nice_spec_year            ?: null,
            'nice_spec_displacement'    => $this->nice_spec_displacement_str !== '' ? (int)$this->nice_spec_displacement_str : null,
            'nice_spec_transmission'    => $this->nice_spec_transmission    ?: null,
            'nice_spec_drive_type'      => $this->nice_spec_drive_type      ?: null,
            'nice_spec_length'          => $this->nice_spec_length_str      !== '' ? (int)$this->nice_spec_length_str      : null,
            'nice_spec_width'           => $this->nice_spec_width_str       !== '' ? (int)$this->nice_spec_width_str       : null,
            'nice_spec_height'          => $this->nice_spec_height_str      !== '' ? (int)$this->nice_spec_height_str      : null,
            'nice_spec_wheelbase'       => $this->nice_spec_wheelbase_str   !== '' ? (int)$this->nice_spec_wheelbase_str   : null,
            'nice_spec_curb_weight'     => $this->nice_spec_curb_weight_str !== '' ? (int)$this->nice_spec_curb_weight_str : null,
            'nice_spec_fuel_efficiency' => $this->nice_spec_fuel_efficiency ?: null,
            // 매입
            'purchase_date'    => $toDate($this->purchase_date),
            'salesman_id'      => $toId($this->salesman_id_str),
            'purchase_from'    => $this->purchase_from ?: null,
            'purchase_evidence_type' => $this->purchase_evidence_type ?: null,
            'purchase_partner_type'  => $this->purchase_partner_type  ?: null,
            // 큐 20-A/C — 매입처 계좌 4컬럼
            'purchase_seller_bank'    => $this->purchase_seller_bank    ?: null,
            'purchase_seller_account' => $this->purchase_seller_account ?: null,
            'purchase_seller_holder'  => $this->purchase_seller_holder  ?: null,
            'purchase_bank_memo'      => $this->purchase_bank_memo      ?: null,
            'purchase_fee_bank'       => $this->purchase_fee_bank       ?: null,
            'purchase_fee_account'    => $this->purchase_fee_account    ?: null,
            'purchase_fee_holder'     => $this->purchase_fee_holder     ?: null,
            'purchase_price'   => $toInt($this->purchase_price_str),
            'selling_fee'      => $toInt($this->selling_fee_str),
            'cost_deregistration' => $toInt($this->cost_deregistration_str),
            'cost_license'     => $toInt($this->cost_license_str),
            'cost_towing'      => $toInt($this->cost_towing_str),
            'cost_carry'       => $toInt($this->cost_carry_str),
            'cost_shoring'     => $toInt($this->cost_shoring_str),
            'cost_insurance'   => $toInt($this->cost_insurance_str),
            'cost_transfer'    => $toInt($this->cost_transfer_str),
            'cost_extra1'      => $toInt($this->cost_extra1_str),
            'cost_extra2'      => $toInt($this->cost_extra2_str),
            // 큐 22-C-E (2026-05-20) — down_payment / selling_fee_payment DROP.
            // _str ↔ PBP type='down'/'selling_fee' confirmed row 동기화는 vehicle save 이후 별도 처리.
            'purchase_remittance_memo' => $this->purchase_remittance_memo ?: null,
            'registration_number' => $this->registration_number ?: null,
            'reg_cert_number' => $this->reg_cert_number ?: null,
            'is_deregistered'  => $this->is_deregistered,
            'deregistration_notice_phone' => \App\Support\PhoneFormat::format($this->deregistrationBuyerPhone),
            'deregistration_date' => $toDate($this->deregistration_date),
            // 판매
            'sale_date'    => $toDate($this->sale_date),
            'currency'     => $this->currency,
            'exchange_rate' => $toFloat($this->exchange_rate_str),
            'buyer_id'     => $toId($this->buyer_id_str),
            'consignee_id' => $toId($this->consignee_id_str),
            'sale_price'       => $toFloat($this->sale_price_str),
            'tax_dc'           => $toFloat($this->tax_dc_str),
            'commission'       => $toFloat($this->commission_str),
            'transport_fee'    => $toFloat($this->transport_fee_str),
            'auto_loading'     => $toFloat($this->auto_loading_str),
            'sale_other_costs' => $toFloat($this->sale_other_costs_str),
            // 큐 22-A-3 — 4컬럼 save 라인 제거. final_payments rows 로 통합.
            'savings_used'     => $toFloat($this->savings_used_str),
            // 수출통관
            'export_buyer_id'       => $toId($this->export_buyer_id_str),
            'export_consignee_id'   => $toId($this->export_consignee_id_str),
            'forwarding_company_id' => $toId($this->forwarding_company_id_str),
            'export_declaration_amount' => $this->export_declaration_amount_str !== '' ? $toFloat($this->export_declaration_amount_str) : null,
            'export_declaration_number' => $this->export_declaration_number ?: null,
            'shipping_date'    => $toDate($this->shipping_date),
            'eta_date'         => $toDate($this->eta_date),
            'shipping_method'  => $this->shipping_method  ?: null,
            'port_of_loading'  => $this->port_of_loading  ?: null,
            'incoterms'        => $this->incoterms ?: null,
            'discharge_port_id' => $this->discharge_port_id_str !== '' ? (int) $this->discharge_port_id_str : null,
            'is_export_cleared' => $this->is_export_cleared,
            // 선적
            'bl_buyer_id'     => $toId($this->bl_buyer_id_str),
            'bl_consignee_id' => $toId($this->bl_consignee_id_str),
            'bl_number'           => $this->bl_number           ?: null,
            'container_number'    => $this->container_number    ?: null,
            'bl_loading_location' => $this->bl_loading_location ?: null,
            'vessel_name'         => $this->vessel_name         ?: null,
            'bl_type'             => $this->bl_type             ?: null,
            'bl_issue_date'       => $toDate($this->bl_issue_date),
            'document_deadline_date' => $toDate($this->document_deadline_date),
            // DHL
            'dhl_recipient_name'    => $this->dhl_recipient_name    ?: null,
            'dhl_recipient_address' => $this->dhl_recipient_address ?: null,
            'dhl_recipient_phone'   => $this->dhl_recipient_phone   ?: null,
            'dhl_sender_name'       => $this->dhl_sender_name       ?: null,
            'dhl_sender_address'    => $this->dhl_sender_address    ?: null,
            'dhl_weight'     => $this->dhl_weight_str !== '' ? $toFloat($this->dhl_weight_str) : null,
            'dhl_dimensions' => $this->dhl_dimensions ?: null,
            'dhl_request'    => $this->dhl_request,
            'memo' => $this->memo ?: null,
            // 카풀/헤이맨 계산서 (channel != carpul/heyman 이어도 컬럼은 nullable이라 OK)
            // 큐 16 — tax_invoice_*·agency_fee persist 제거.
        ];

        // NICE 조회 원본 보존 — 이번에 조회한 경우에만 기입(편집 시 빈 niceRaw 가 기존 값을 덮어쓰지 않도록 조건부).
        if (! empty($this->niceRaw)) {
            $data['nice_raw'] = $this->niceRaw;
        }

        // 파일 정리 추적용 (트랜잭션 성공·실패 분기)
        $newlyStoredPaths = [];   // 트랜잭션 실패 시 디스크에서 제거
        $pathsToDelete    = [];   // 트랜잭션 성공 시 디스크에서 제거 (옛 파일 / 사용자가 삭제 클릭)

        $fileFields = [
            ['col' => 'deregistration_document',     'fileProp' => 'deregistrationDocFile',    'clearProp' => 'clearDeregistrationDoc'],
            ['col' => 'export_declaration_document', 'fileProp' => 'exportDeclarationDocFile', 'clearProp' => 'clearExportDeclarationDoc'],
            ['col' => 'bl_document',                 'fileProp' => 'blDocFile',                'clearProp' => 'clearBlDoc'],
        ];

        $wasCreating = $this->editingId === null;
        $vehicle = null;
        // 22-C-light 후속 fix (2026-05-20) — Vehicle save 전에 PBP existing id 캡처.
        // Vehicle::saved 훅이 자동 PBP Draft 생성하는데, 그 후 sync 에서 existing - submitted = 자동 Draft 가 delete 대상에 포함되어 사라지는 버그.
        // 캡처 시점을 Vehicle 저장 전으로 옮기면 자동 PBP 보호.
        $existingPurchaseIdsBefore = $this->editingId
            ? PurchaseBalancePayment::where('vehicle_id', $this->editingId)->pluck('id')->toArray()
            : [];
        try {
            \DB::transaction(function () use ($data, $toInt, $toFloat, $toDate, $fileFields, $existingPurchaseIdsBefore, &$newlyStoredPaths, &$pathsToDelete, &$vehicle) {
                if ($this->editingId) {
                    $vehicle = Vehicle::findOrFail($this->editingId);
                    $vehicle->update($data);
                } else {
                    $vehicle = Vehicle::create($data);
                }

                // 파일: 업로드 / 삭제 / 교체 통합 처리
                $fileUpdates = [];
                foreach ($fileFields as $f) {
                    $oldPath = $vehicle->{$f['col']};
                    if ($this->{$f['fileProp']}) {
                        $newPath = $this->{$f['fileProp']}->store("vehicles/{$vehicle->id}", config('filesystems.vehicle_docs_disk'));
                        $newlyStoredPaths[] = $newPath;
                        $fileUpdates[$f['col']] = $newPath;
                        if ($oldPath && $oldPath !== $newPath) {
                            $pathsToDelete[] = $oldPath;
                        }
                    } elseif ($this->{$f['clearProp']} && $oldPath) {
                        $fileUpdates[$f['col']] = null;
                        $pathsToDelete[] = $oldPath;
                    }
                }
                if ($fileUpdates) {
                    $vehicle->update($fileUpdates);
                }

                // 차량 사진 — 개별 삭제(staged) + 신규 업로드. 디스크는 vehicle_docs_disk(로컬 public / 운영 s3).
                // orphan 정리: 삭제 path → $pathsToDelete(tx 성공 시), 신규 path → $newlyStoredPaths(tx 실패 시).
                if ($this->deletePhotoIds) {
                    $delPhotos = \App\Models\VehiclePhoto::where('vehicle_id', $vehicle->id)
                        ->whereIn('id', $this->deletePhotoIds)->get();
                    foreach ($delPhotos as $photo) {
                        $pathsToDelete[] = $photo->path;
                    }
                    \App\Models\VehiclePhoto::whereIn('id', $delPhotos->pluck('id'))->delete();
                }
                if ($this->photoFiles) {
                    $nextOrder = (int) \App\Models\VehiclePhoto::where('vehicle_id', $vehicle->id)->max('sort_order');
                    foreach ($this->photoFiles as $photo) {
                        $photoPath = $photo->store("vehicles/{$vehicle->id}/photos", config('filesystems.vehicle_docs_disk'));
                        $newlyStoredPaths[] = $photoPath;
                        \App\Models\VehiclePhoto::create([
                            'vehicle_id' => $vehicle->id,
                            'path' => $photoPath,
                            'sort_order' => ++$nextOrder,
                        ]);
                    }
                }

                // 선적 탭 선박 사진 (category='shipping') — 기본정보 사진과 동일 흐름, 별도 갤러리.
                if ($this->deleteShipPhotoIds) {
                    $delShip = \App\Models\VehiclePhoto::where('vehicle_id', $vehicle->id)
                        ->where('category', 'shipping')
                        ->whereIn('id', $this->deleteShipPhotoIds)->get();
                    foreach ($delShip as $photo) {
                        $pathsToDelete[] = $photo->path;
                    }
                    \App\Models\VehiclePhoto::whereIn('id', $delShip->pluck('id'))->delete();
                }
                if ($this->shipPhotoFiles) {
                    $nextShipOrder = (int) \App\Models\VehiclePhoto::where('vehicle_id', $vehicle->id)
                        ->where('category', 'shipping')->max('sort_order');
                    foreach ($this->shipPhotoFiles as $photo) {
                        $photoPath = $photo->store("vehicles/{$vehicle->id}/ship-photos", config('filesystems.vehicle_docs_disk'));
                        $newlyStoredPaths[] = $photoPath;
                        \App\Models\VehiclePhoto::create([
                            'vehicle_id' => $vehicle->id,
                            'path' => $photoPath,
                            'category' => 'shipping',
                            'sort_order' => ++$nextShipOrder,
                        ]);
                    }
                }

            // 판매 잔금 동기화 (id-diff)
            // 채권 화면에서 생성된 잔금(locked)은 이 패널에서 수정/삭제 불가 — 채권관리가 원천
            $lockedFinalIds = \App\Models\ReceivableHistory::where('vehicle_id', $vehicle->id)
                ->whereNotNull('final_payment_id')->pluck('final_payment_id')->toArray();
            $existingFinalIds = $vehicle->finalPayments->pluck('id')->toArray();
            $submittedFinalIds = collect($this->finalPayments)->pluck('id')->filter()->toArray();
            $toDeleteIds = array_diff($existingFinalIds, $submittedFinalIds);
            FinalPayment::whereIn('id', array_diff($toDeleteIds, $lockedFinalIds))->delete();

            // 회의확장씬 #6 (2026-05-22) — canConfirmFinance(재무·관리·admin) 직접 추가 시 즉시 확정.
            // 사용자 명세: "[관리]가 직접 할 경우 요청씬 없이 추가되고 즉시 승인 가능"
            // 영업 추가 → Draft (confirmed_at=NULL) → 재무가 transfers 에서 별도 확정
            // 신규 row 만 자동 확정 — 기존 Draft 수정은 transfers 흐름 유지 (의도)
            $authUser = auth()->user();
            $autoConfirmFields = $authUser?->canConfirmFinance() ? [
                'confirmed_at' => now(),
                'confirmed_by_user_id' => $authUser->id,
            ] : [];

            foreach ($this->finalPayments as $row) {
                if (!empty($row['locked'])) continue;
                if ($row['amount'] === '' && $row['payment_date'] === '') continue;
                $amt = $toFloat($row['amount'] ?? '');
                $dt  = $toDate($row['payment_date'] ?? '');
                // 회의확장씬 #7 (2026-05-22) — 잔금 row 별 환율 저장.
                $rateVal = ($row['exchange_rate'] ?? '') !== '' ? (float) str_replace(',', '', $row['exchange_rate']) : null;
                if (isset($row['id']) && $row['id']) {
                    if (in_array($row['id'], $lockedFinalIds)) continue;
                    FinalPayment::where('id', $row['id'])->update([
                        'amount' => $amt,
                        'exchange_rate' => $rateVal,
                        'payment_date' => $dt,
                        'note' => $row['note'] ?? null,
                    ]);
                } else {
                    $vehicle->finalPayments()->create(array_merge([
                        'amount' => $amt,
                        'exchange_rate' => $rateVal,
                        'payment_date' => $dt,
                        'note' => $row['note'] ?? null,
                    ], $autoConfirmFields));
                }
            }

            // 22-A-3a 사용자 정정 (2026-05-20) — 4 항목 (계약금/중도금/선수금) FP type별 sync.
            // 재무·관리자 입력 — 각 _str 값과 type별 confirmed 합산 비교 후 변경분만 row 재생성.
            // confirmed_at SET row 잠금 우회 ($allowConfirmedMutation flag) — 4 항목은 분류 메타데이터.
            if (auth()->user()?->canManagePaymentBreakdown()) {
                $typeStrMap = [
                    'deposit_down' => $this->deposit_down_payment_str,
                    'interim' => $this->interim_payment_str,
                    'advance_1' => $this->advance_payment1_str,
                    'fee' => $this->fee_str,
                ];
                foreach ($typeStrMap as $type => $str) {
                    // 콤마 제거 후 파싱 — 매입 2항목(down/selling_fee)과 동일. '4,000,000' → 4 절단 방지.
                    $newAmount = $str === '' ? 0.0 : (float) str_replace(',', '', $str);
                    $existingSum = (float) $vehicle->finalPayments()
                        ->where('type', $type)
                        ->whereNotNull('confirmed_at')
                        ->sum('amount');
                    if (abs($newAmount - $existingSum) < 0.01) {
                        continue;   // 변경 없음
                    }
                    FinalPayment::$allowConfirmedMutation = true;
                    try {
                        $vehicle->finalPayments()
                            ->where('type', $type)
                            ->whereNotNull('confirmed_at')
                            ->delete();
                    } finally {
                        FinalPayment::$allowConfirmedMutation = false;
                    }
                    if ($newAmount > 0) {
                        // 2026-05-28 fix — 4항목 row 의 exchange_rate snapshot.
                        // FinalPayment::saving 훅이 amount × exchange_rate = amount_krw 자동 계산.
                        // 미설정 시 amount_krw=null → buyerFees / 채권관리 KRW 합산에서 누락.
                        $vehicle->finalPayments()->create([
                            'amount' => $newAmount,
                            'type' => $type,
                            'payment_date' => today(),
                            'exchange_rate' => $vehicle->exchange_rate,
                            'confirmed_at' => now(),
                            'confirmed_by_user_id' => auth()->id(),
                            'note' => match ($type) {
                                'deposit_down' => '계약금',
                                'interim' => '중도금',
                                'advance_1' => '선수금1',
                                'fee' => '송금 수수료',
                            },
                        ]);
                    }
                }
            }

            // 회의확장씬 #12 (2026-05-22) — canConfirmFinance 입력 시 SavingsStatus EARNED 거래 추가.
            // 한 번 입력 → 거래 추가 → 입력란 reset. 누적은 SavingsStatus 단일 출처 (buyerSavingsBalance 자동 반영).
            // 영업·통관 입력은 disabled — 만약 ui 우회로 값 들어와도 canConfirmFinance 가드로 차단.
            if (auth()->user()?->canConfirmFinance()) {
                $depositAmt = $this->savings_deposit_str === ''
                    ? 0.0
                    : (float) str_replace(',', '', $this->savings_deposit_str);
                if ($depositAmt > 0 && $vehicle->buyer_id) {
                    $vehicle->syncSavingsDeposit($depositAmt);
                    $this->savings_deposit_str = '';
                    unset($this->buyerSavingsBalance);   // computed 캐시 무효화 → 즉시 반영
                }
            }

            // 22-C-E 사용자 정정 (2026-05-20) — 2 항목 (계약금/매도비) PBP type별 sync.
            // 재무·admin 입력 (canConfirmFinance) — 각 _str 값과 type별 confirmed 합산 비교 후 변경분만 row 재생성.
            // confirmed_at SET row 잠금 우회 ($allowConfirmedMutation flag) — 2 항목은 분류 메타데이터.
            if (auth()->user()?->canConfirmFinance()) {
                $pbpTypeStrMap = [
                    'down' => $this->down_payment_str,
                    'selling_fee' => $this->selling_fee_payment_str,
                ];
                foreach ($pbpTypeStrMap as $type => $str) {
                    $newAmount = $str === '' ? 0.0 : (float) str_replace(',', '', $str);
                    $existingSum = (float) $vehicle->purchaseBalancePayments()
                        ->where('type', $type)
                        ->whereNotNull('confirmed_at')
                        ->sum('amount');
                    if (abs($newAmount - $existingSum) < 0.01) {
                        continue;
                    }
                    PurchaseBalancePayment::$allowConfirmedMutation = true;
                    try {
                        $vehicle->purchaseBalancePayments()
                            ->where('type', $type)
                            ->whereNotNull('confirmed_at')
                            ->delete();
                    } finally {
                        PurchaseBalancePayment::$allowConfirmedMutation = false;
                    }
                    if ($newAmount > 0) {
                        $vehicle->purchaseBalancePayments()->create([
                            'amount' => $newAmount,
                            'type' => $type,
                            'payment_date' => today(),
                            'confirmed_at' => now(),
                            'confirmed_by_user_id' => auth()->id(),
                            'note' => match ($type) {
                                'down' => '계약금',
                                'selling_fee' => '매도비',
                            },
                        ]);
                    }
                }
            }

            // 매입 잔금 동기화 (type='balance' default)
            // 22-C-light 후속 fix — Vehicle save 전 캡처한 $existingPurchaseIdsBefore 사용.
            // Vehicle::saved 자동 PBP Draft 는 existing 에 없으므로 삭제 대상 X (보호).
            $submittedPurchaseIds = collect($this->purchaseBalancePayments)->pluck('id')->filter()->toArray();
            PurchaseBalancePayment::whereIn('id', array_diff($existingPurchaseIdsBefore, $submittedPurchaseIds))->delete();
            foreach ($this->purchaseBalancePayments as $row) {
                if (($row['amount'] ?? '') === '' && ($row['payment_date'] ?? '') === '') continue;
                $amt = $toInt($row['amount'] ?? '');
                $dt  = $toDate($row['payment_date'] ?? '');
                if (isset($row['id']) && $row['id']) {
                    PurchaseBalancePayment::where('id', $row['id'])->update(['amount' => $amt, 'payment_date' => $dt, 'note' => $row['note'] ?? null]);
                } else {
                    // 회의확장씬 #6 (2026-05-22) — canConfirmFinance 직접 추가 시 즉시 확정 (판매 잔금과 동일 정책).
                    $vehicle->purchaseBalancePayments()->create(array_merge([
                        'amount' => $amt,
                        'payment_date' => $dt,
                        'note' => $row['note'] ?? null,
                    ], $autoConfirmFields));
                }
            }

            // 매입 자동 PBP Draft 재조정 (2026-06-01) — 확정 입금과 중복되는 phantom 제거.
            // Vehicle::saved 훅은 $vehicle->update/create 시점(폼 동기화 前)에 전액 자동 Draft 를 만든다.
            // 같은 저장에서 계약금/잔금 확정 행이 추가되면 자동 Draft(전액, 대기)가 중복으로 남아
            // 재무처리 대기에 잔존(이중 계상 위험). 폼 동기화가 끝난 이 시점에 확정 합과 대조한다.
            //   - 확정 입금이 매입합계(매입가+매도비)를 전액 커버 → 자동 Draft 삭제
            //   - 일부만 커버 → 남은 미지급으로 축소 (confirmed_at=NULL 유지 = 대기)
            // 확정 입금이 0이면(순수 Draft) 손대지 않음 — 매입가 변경 시 영업이 수동 정정하는 현행 유지.
            $autoDraft = $vehicle->purchaseBalancePayments()
                ->whereNull('confirmed_at')
                ->where('note', PurchaseBalancePayment::AUTO_DRAFT_NOTE)
                ->first();
            if ($autoDraft) {
                // 확정 합산 필터는 미지급 accessor(getPurchaseUnpaidAmountAttribute, SKILLS §13 단일 출처)와 정합.
                // payment_date <= now() (NULL 자동 제외) → 미래일자 확정 입금은 미지급 정의와 동일하게 미반영.
                $confirmedOthers = (int) $vehicle->purchaseBalancePayments()
                    ->whereNotNull('confirmed_at')
                    ->where('payment_date', '<=', now())
                    ->sum('amount');
                if ($confirmedOthers > 0) {
                    $remaining = (int) ($vehicle->purchase_price + $vehicle->selling_fee) - $confirmedOthers;
                    if ($remaining <= 0) {
                        $autoDraft->delete();
                    } elseif ((int) $autoDraft->amount !== $remaining) {
                        $autoDraft->amount = $remaining;
                        $autoDraft->save();
                    }
                }
            }

            // ② 미수 매입 게이트 관리 승인 감사 기록 — 어떤 바이어·미수율 몇 %에 누가 왜 승인했는지 영구 보존.
            if ($this->purchaseGateApproved) {
                \App\Models\AuditLog::create([
                    'user_id' => auth()->id(),
                    'auditable_type' => Vehicle::class,
                    'auditable_id' => $vehicle->id,
                    'action' => 'purchase_gate_override',
                    'column_name' => 'buyer:'.($this->purchaseGateInfo['buyer'] ?? ''),
                    'old_value' => '미수율 '.($this->purchaseGateInfo['ratio'] ?? '').'% / 미수 ₩'.number_format((int) ($this->purchaseGateInfo['unpaid'] ?? 0)).' / '.($this->purchaseGateInfo['count'] ?? 0).'대',
                    'new_value' => $this->purchaseGateReason,
                    'ip_address' => request()->ip(),
                ]);
            }

            // 잔금 bulk delete/update는 모델 이벤트가 안 뜸 → 명시적으로 캐시 갱신
            $vehicle->refreshCaches();
            });
        } catch (\DomainException $e) {
            // #1 (2026-05-20) — paid Settlement·SoD·회계 무결성 등 비즈니스 룰 위반.
            // 화이트스크린 대신 토스트로 사용자에게 사유 노출. 트랜잭션은 이미 rollback됨.
            foreach ($newlyStoredPaths as $p) {
                Storage::disk(config('filesystems.vehicle_docs_disk'))->delete($p);
            }
            $this->dispatch('notify', message: $e->getMessage(), type: 'error');

            return;
        } catch (\Illuminate\Database\QueryException $e) {
            // 안전망 (2026-07-08) — chk_sale_required 등 DB 제약 위반이 앞단 검증을 뚫고 온 경우.
            //   SQLSTATE/SQL 이 그대로 노출되는 500 Ignition 대신 친절 토스트 + 실제 원인은 로그.
            foreach ($newlyStoredPaths as $p) {
                Storage::disk(config('filesystems.vehicle_docs_disk'))->delete($p);
            }
            \Log::warning('Vehicle save QueryException', ['vehicle_id' => $this->editingId, 'sqlstate' => $e->getCode(), 'msg' => $e->getMessage()]);
            $this->dispatch('notify', message: __('vehicle.toast.db_constraint'), type: 'error');

            return;
        } catch (\Throwable $e) {
            // 트랜잭션 실패: 새로 저장된 파일 정리 후 재예외
            foreach ($newlyStoredPaths as $p) {
                Storage::disk(config('filesystems.vehicle_docs_disk'))->delete($p);
            }
            throw $e;
        }

        // 트랜잭션 성공: 옛 파일(교체·삭제 대상) 디스크에서 제거
        foreach ($pathsToDelete as $p) {
            Storage::disk(config('filesystems.vehicle_docs_disk'))->delete($p);
        }

        $this->clearDeregistrationDoc = false;
        $this->clearExportDeclarationDoc = false;
        $this->clearBlDoc = false;

        // ② 미수 게이트 승인 플래그·정보 리셋 (다음 저장 시 재평가).
        $this->purchaseGateApproved = false;
        $this->purchaseGateReason = '';
        $this->purchaseGateInfo = [];

        // 큐 21 후속 — mismatch confirm flag 리셋 (다음 save 진입 시 재검사)
        $this->userConfirmedDocCheckMismatch = false;

        $this->unsetComputedProperties();

        // 큐 6 H14 — 신규 차량 등록 시 다음 단계로 동선 안내.
        // 패널을 닫지 않고 방금 만든 차량을 다시 로드(openEdit)한 뒤,
        // 흐름도에서 첫 warn/pending/progress 노드의 탭으로 자동 전환 + 토스트로 사유 안내.
        // 수정 저장은 기존 close() 흐름 유지 (탭 자동 전환은 편집 중 사용자 흐름을 끊음).
        $nextStep = $wasCreating && $vehicle ? $this->resolveNextStep($vehicle->id) : null;

        if ($wasCreating && $nextStep) {
            $newId = $vehicle->id;
            // 큐 14-4-4 — openEdit() 진입 시 justCreatedId가 일치하면 보존되도록 먼저 set.
            $this->justCreatedId = $newId;
            $this->openEdit($newId);
            $this->dispatch('switch-tab', tab: $nextStep['tab']);
            $this->dispatch(
                'notify',
                message: __('vehicle.toast.created_next', ['label' => $nextStep['label'], 'reason' => $nextStep['reason']]),
                type: 'success',
            );
            return;
        }

        // "저장하고 계속" — 창을 닫지 않고 방금 저장한 차량을 재로드.
        //   openEdit 가 폼·스냅샷(총판매가·남은잔금·미납률 등)을 DB 최신값으로 다시 채우고, 내 편집잠금을 재획득한다.
        //   Alpine 탭 상태는 클라이언트 측이라 재로드에도 현재 탭 유지 (switch-tab 미발행).
        if ($keepOpen && $vehicle) {
            $this->openEdit($vehicle->id);
            $this->dispatch(
                'notify',
                message: $wasCreating ? __('vehicle.toast.created') : __('vehicle.toast.updated'),
                type: 'success',
            );

            return;
        }

        $this->close();
        session()->flash('success', $wasCreating ? __('vehicle.toast.created') : __('vehicle.toast.updated'));
    }

    /**
     * 큐 6 H14 — 신규 차량 등록 후 다음 단계 노드 산정.
     * progressFlow 순서대로 첫 번째 warn/pending/progress 노드 반환. disabled/done은 스킵.
     * 모든 비-done 노드가 disabled면 (헤이맨/카풀 채널 + 매입·말소·판매·입금 모두 done 등) null 반환.
     */
    private function resolveNextStep(int $vehicleId): ?array
    {
        $savedEditingId = $this->editingId;
        $this->editingId = $vehicleId;
        unset($this->progressFlow);
        $flow = $this->progressFlow;
        $this->editingId = $savedEditingId;
        unset($this->progressFlow);

        if (! $flow) {
            return null;
        }
        foreach ($flow as $node) {
            if (in_array($node['status'], ['warn', 'pending', 'progress'], true)) {
                return $node;
            }
        }
        return null;
    }

    // ── 삭제 사유 모달 (2026-07-08 jin) — 회계 연관 차량 삭제 시 사유 입력·AuditLog 강제 ──
    public bool $showDeleteGate = false;

    public ?int $deleteTargetId = null;

    public array $deleteTargetInfo = [];   // ['number','reason_ctx']

    public string $deleteReason = '';

    public function delete(int $id): void
    {
        $vehicle = Vehicle::findOrFail($id);

        // Review.md #4 (2026-06-09) — openEdit 와 동일 스코프 재인가.
        // 변경 액션은 읽기 진입과 별개로 매번 검사해야 IDOR(타 담당 차량 삭제) 차단.
        abort_unless(auth()->user()->canScopeVehicle($vehicle), 403, __('vehicle.toast.edit_own_only'));
        $this->assertEditable($id); // 동시 편집 잠금 — 누군가 편집 중인 차량은 삭제 차단

        // 회계 연관 차량(확정 잔금·정산) → 사유 모달. 권한을 모달 前에 검사(헛수고 방지).
        if ($vehicle->requiresDeleteReason()) {
            if ($vehicle->hasConfirmedPaymentLock() && ! auth()->user()->canAccessAdmin()) {
                $this->dispatch('notify', message: __('vehicle.delete_gate.admin_only'), type: 'error');

                return;
            }
            $this->deleteTargetId = $id;
            $this->deleteTargetInfo = [
                'number' => $vehicle->vehicle_number,
                'ctx' => $vehicle->hasConfirmedPaymentLock() ? __('vehicle.delete_gate.ctx_locked') : __('vehicle.delete_gate.ctx_settlement'),
            ];
            $this->deleteReason = '';
            $this->resetErrorBag(['deleteReason']);
            $this->showDeleteGate = true;

            return;
        }

        // 일반 차량 — 즉시 삭제 (사유 불필요).
        $this->performVehicleDelete($vehicle, null);
    }

    /** 회계 연관 차량 삭제 확정 — 사유 필수 + AuditLog. */
    public function confirmDeleteWithReason(): void
    {
        if (! $this->deleteTargetId) {
            return;
        }
        $this->validate(['deleteReason' => ['required', 'string', 'min:5', 'max:500']], [], [
            'deleteReason' => __('vehicle.delete_gate.reason'),
        ]);
        $vehicle = Vehicle::findOrFail($this->deleteTargetId);
        abort_unless(auth()->user()->canScopeVehicle($vehicle), 403, __('vehicle.toast.edit_own_only'));
        $this->assertEditable($this->deleteTargetId);

        $this->performVehicleDelete($vehicle, $this->deleteReason);
    }

    public function cancelDeleteGate(): void
    {
        $this->showDeleteGate = false;
        $this->deleteTargetId = null;
        $this->deleteReason = '';
        $this->deleteTargetInfo = [];
        $this->resetErrorBag(['deleteReason']);
    }

    private function performVehicleDelete(Vehicle $vehicle, ?string $reason): void
    {
        $vehicleId = $vehicle->id;
        $vehicleNumber = $vehicle->vehicle_number;
        try {
            $vehicle->delete();
        } catch (\DomainException $e) {
            // [관리] 등 권한 부족(재무확정 잔금 있는 차량은 admin/super만 삭제) → 500 Ignition 대신 토스트.
            $this->dispatch('notify', message: $e->getMessage(), type: 'error');

            return;
        }

        // 회계 연관 삭제는 사유·행위자 영구 기록.
        if ($reason !== null) {
            \App\Models\AuditLog::create([
                'user_id' => auth()->id(),
                'auditable_type' => Vehicle::class,
                'auditable_id' => $vehicleId,
                'action' => 'vehicle_deleted_with_reason',
                'column_name' => $vehicleNumber,
                'new_value' => $reason,
                'ip_address' => request()->ip(),
            ]);
        }

        $this->showDeleteGate = false;
        $this->deleteTargetId = null;
        $this->deleteReason = '';
        $this->deleteTargetInfo = [];
        $this->unsetComputedProperties();
        session()->flash('success', __('vehicle.deleted'));
    }

    // 2026-07-09 — 옛 G2 승인요청 모달/메서드 제거(ERP 죽은 락). 미수 바이어 신규거래는 미수 매입 게이트(②)가 담당.

    // 큐 19-C — 차량 간 자금 이체 요청 ────────────────────────────────

    /**
     * 자금 이체 요청 모달 열기. editingId 차량을 source로 가정.
     */
    public function openTransferRequestModal(): void
    {
        if ($this->editingId === null) {
            $this->dispatch('notify', message: __('vehicle.toast.save_first_transfer'), type: 'warning');

            return;
        }
        $ctx = $this->transferContext;
        if (! empty($ctx['pending'])) {
            $this->dispatch('notify', message: __('vehicle.toast.pending_transfer_exists'), type: 'warning');

            return;
        }
        if (! $ctx['eligible']) {
            $this->dispatch('notify', message: $ctx['reason'], type: 'warning');

            return;
        }
        $this->transferTargetVehicleId = '';
        $this->transferAmountStr = '';
        $this->transferReason = '';
        $this->transferNotes = '';
        $this->resetErrorBag(['transferTargetVehicleId', 'transferAmountStr', 'transferReason']);
        $this->showTransferRequestModal = true;
    }

    public function closeTransferRequestModal(): void
    {
        $this->resetTransferRequestForm();
    }

    /**
     * 자금 이체 요청 제출 → InterVehicleTransferService::request() 호출.
     * 안전 가드 5종 (동일 바이어·동일 currency·ratio≤0.5·금액 한도·paid Settlement 없음)은
     * service 내부에서 재검증. UI는 사용자 입력만 검증.
     */
    public function submitTransferRequest(InterVehicleTransferService $service): void
    {
        if ($this->editingId === null) {
            return;
        }
        $this->validate([
            'transferTargetVehicleId' => ['required', 'integer'],
            'transferAmountStr' => ['required', 'string'],
            'transferReason' => ['required', 'string', 'min:5'],
        ], [
            'transferTargetVehicleId.required' => __('vehicle.valmsg.transfer_target_required'),
            'transferAmountStr.required' => __('vehicle.valmsg.transfer_amount_required'),
            'transferReason.required' => __('vehicle.valmsg.approval_reason_required'),
            'transferReason.min' => __('vehicle.valmsg.reason_min5'),
        ]);

        $source = Vehicle::find($this->editingId);
        $target = Vehicle::find((int) $this->transferTargetVehicleId);
        $amount = (float) str_replace(',', '', $this->transferAmountStr);

        if (! $source || ! $target) {
            $this->dispatch('notify', message: __('vehicle.toast.transfer_vehicle_not_found'), type: 'warning');

            return;
        }

        try {
            $service->request(
                source: $source,
                target: $target,
                amount: $amount,
                requester: auth()->user(),
                reason: trim($this->transferReason),
                notes: trim($this->transferNotes) ?: null,
            );
        } catch (\DomainException $e) {
            $this->addError('transferAmountStr', $e->getMessage());

            return;
        }

        $this->dispatch('notify', message: __('vehicle.toast.transfer_sent'), type: 'success');
        $this->resetTransferRequestForm();
    }

    // 큐 19-E — 이체 취소(void) 요청 ────────────────────────────────

    public function openTransferVoidModal(int $transferId): void
    {
        $transfer = InterVehicleTransfer::find($transferId);
        if (! $transfer || $transfer->status !== InterVehicleTransfer::STATUS_EXECUTED) {
            $this->dispatch('notify', message: __('vehicle.toast.void_only_executed'), type: 'warning');

            return;
        }
        $this->voidTransferId = $transferId;
        $this->voidReason = '';
        $this->resetErrorBag('voidReason');
        $this->showTransferVoidModal = true;
    }

    public function closeTransferVoidModal(): void
    {
        $this->resetTransferVoidForm();
    }

    public function submitTransferVoidRequest(InterVehicleTransferService $service): void
    {
        if ($this->voidTransferId === null) {
            return;
        }
        $this->validate([
            'voidReason' => ['required', 'string', 'min:5'],
        ], [
            'voidReason.required' => __('vehicle.valmsg.void_reason_required'),
            'voidReason.min' => __('vehicle.valmsg.reason_min5'),
        ]);

        $transfer = InterVehicleTransfer::find($this->voidTransferId);
        if (! $transfer) {
            $this->dispatch('notify', message: __('vehicle.toast.transfer_not_found'), type: 'warning');

            return;
        }

        $submittedTransferId = $this->voidTransferId;

        try {
            $service->voidRequest($transfer, auth()->user(), trim($this->voidReason));
        } catch (\DomainException $e) {
            $this->addError('voidReason', $e->getMessage());

            return;
        }

        // 잔금 row의 transfer 메타를 즉시 갱신 (DB 재쿼리 없이 메모리에서)
        // → 모달 닫힘과 동시에 amber 박스 + "취소 요청 중" 시각화
        foreach ($this->finalPayments as $idx => $row) {
            if (! empty($row['transfer']) && (int) ($row['transfer']['id'] ?? 0) === $submittedTransferId) {
                $this->finalPayments[$idx]['transfer']['pending_void'] = true;
                $this->finalPayments[$idx]['transfer']['can_void'] = false;
            }
        }

        $this->dispatch('notify', message: __('vehicle.toast.void_sent'), type: 'success');
        $this->resetTransferVoidForm();
    }

    /**
     * 자금 이체 가능성·한도·후보 차량 산출.
     * eligible 조건:
     *   - editingId 있음 + source 차량 존재
     *   - source.unpaid_ratio ≤ 0.5 (50% 이상 받음)
     *   - source.buyer_id 있음
     *   - source/target에 paid Settlement 없음
     *   - 후보 차량 ≥ 1 (동일 buyer + 동일 currency + 자신 제외 + paid Settlement 없음)
     *
     * 추가 메타 (큐 19-C 보강 — 2026-05-15):
     *   - pending: pending인 InterVehicleTransfer 1건 정보 (있으면 amber 박스 + 버튼 비활성)
     *   - lastDecided: 가장 최근 결정된 ApprovalRequest 1건 (status=approved/rejected/cancelled),
     *     pending이 있으면 노출 안 함. 사용자가 마지막 처리 결과를 한눈에 확인 가능.
     */
    #[Computed]
    public function transferContext(): array
    {
        $base = ['eligible' => false, 'reason' => '', 'received' => 0.0, 'limit' => 0.0, 'candidates' => collect(),
            'pending' => null, 'lastDecided' => null];
        if ($this->editingId === null) {
            $base['reason'] = '차량 저장 후 이용 가능합니다.';

            return $base;
        }
        $source = Vehicle::find($this->editingId);
        if (! $source) {
            return $base;
        }

        // 최근 ApprovalRequest 조회 (이 차량이 source인 transfer 관련)
        $recentRequests = ApprovalRequest::where('action_type', ApprovalRequest::TYPE_INTER_VEHICLE_TRANSFER)
            ->where('target_type', Vehicle::class)
            ->where('target_id', $source->id)
            ->orderByDesc('created_at')
            ->take(5)
            ->get();

        // 큐 19-G — InterVehicleTransfer 기준 미처리 상태 차단 (회의록 부록 A Step 4 발견 버그).
        // pending / approved_awaiting_finance / voided_awaiting_finance 중 연결된 ApprovalRequest가
        // 활성(pending/approved)인 것만 차단. rejected/cancelled는 stale 처리.
        $inProgressTransfer = InterVehicleTransfer::where('source_vehicle_id', $source->id)
            ->whereIn('status', [
                InterVehicleTransfer::STATUS_PENDING,
                InterVehicleTransfer::STATUS_APPROVED_AWAITING_FINANCE,
                InterVehicleTransfer::STATUS_VOIDED_AWAITING_FINANCE,
            ])
            ->whereHas('approvalRequest', fn ($q) => $q->whereIn('status', [
                ApprovalRequest::STATUS_PENDING,
                ApprovalRequest::STATUS_APPROVED,
            ]))
            ->latest('id')
            ->first();

        $pendingReq = $recentRequests->firstWhere('status', ApprovalRequest::STATUS_PENDING);
        if ($pendingReq) {
            $p = $pendingReq->payload ?? [];
            $base['pending'] = [
                'approval_request_id' => $pendingReq->id,
                'target_vehicle_number' => $p['target_vehicle_number'] ?? '#'.($p['target_vehicle_id'] ?? '?'),
                'amount' => (float) ($p['amount'] ?? 0),
                'currency' => $p['currency'] ?? $source->currency,
                'created_at' => $pendingReq->created_at,
                'reason' => $pendingReq->reason,
            ];
        }

        // 큐 19-I — transfer + void 결정 중 가장 최근(decided_at) 1건만 lastDecided 로 표시.
        // 19-C 보강 #2 "혼란 없이 최신 상태" 일관성 — 사용자 결정 2026-05-16.
        // type 필드로 view 분기 ('transfer' 5상태 / 'void' rejected·cancelled).
        if (! $pendingReq) {
            $transferDecided = $recentRequests->first(fn ($r) => in_array($r->status, [
                ApprovalRequest::STATUS_APPROVED,
                ApprovalRequest::STATUS_REJECTED,
                ApprovalRequest::STATUS_CANCELLED,
            ], true));

            $transferIds = InterVehicleTransfer::where('source_vehicle_id', $source->id)->pluck('id');
            $voidDecided = $transferIds->isNotEmpty()
                ? ApprovalRequest::where('action_type', ApprovalRequest::TYPE_INTER_VEHICLE_TRANSFER_VOID)
                    ->where('target_type', InterVehicleTransfer::class)
                    ->whereIn('target_id', $transferIds)
                    ->whereIn('status', [
                        ApprovalRequest::STATUS_APPROVED,
                        ApprovalRequest::STATUS_REJECTED,
                        ApprovalRequest::STATUS_CANCELLED,
                    ])
                    ->orderByDesc('decided_at')
                    ->first()
                : null;

            // decided_at 가장 늦은 1건 선택
            $mostRecent = collect([$transferDecided, $voidDecided])
                ->filter()
                ->sortByDesc(fn ($r) => $r->decided_at?->timestamp ?? 0)
                ->first();

            // 큐 19-J — void approved 인 경우 transferDecided 로 fallback.
            // transfer.status 가 voided / voided_awaiting_finance 로 변하므로
            // transferDecided 의 'approved:voided*' 5상태 분기로 표시하는 것이 정확.
            // (void approved 메타를 별도로 보여줄 필요 없음 — transfer 5상태가 이미 의미 포함)
            //
            // 큐 19-L — 단 void approved + transfer.void_finance_rejected_at IS NOT NULL 인 경우
            // "재무가 취소 거부" 케이스. transfer.status 는 executed 로 복귀했지만 void 시도가
            // 거부됐다는 정보가 의미 있음 → fallback 우회, voidDecided 그대로 'void:finance_rejected' 분기.
            if ($mostRecent
                && $mostRecent->action_type === ApprovalRequest::TYPE_INTER_VEHICLE_TRANSFER_VOID
                && $mostRecent->status === ApprovalRequest::STATUS_APPROVED
                && $transferDecided) {
                $voidTransfer = InterVehicleTransfer::find($mostRecent->target_id);
                if (! $voidTransfer || $voidTransfer->void_finance_rejected_at === null) {
                    $mostRecent = $transferDecided;
                }
            }

            if ($mostRecent && $mostRecent->action_type === ApprovalRequest::TYPE_INTER_VEHICLE_TRANSFER) {
                $p = $mostRecent->payload ?? [];
                $relatedTransfer = InterVehicleTransfer::where('approval_request_id', $mostRecent->id)
                    ->with(['financeConfirmer', 'financeRejecter'])->first();
                $base['lastDecided'] = [
                    'type' => 'transfer',
                    'approval_request_id' => $mostRecent->id,
                    'status' => $mostRecent->status,
                    'target_vehicle_number' => $p['target_vehicle_number'] ?? '#'.($p['target_vehicle_id'] ?? '?'),
                    'amount' => (float) ($p['amount'] ?? 0),
                    'currency' => $p['currency'] ?? $source->currency,
                    'decision_note' => $mostRecent->decision_note,
                    'decided_at' => $mostRecent->decided_at,
                    'approver_name' => $mostRecent->approver?->name,
                    // 큐 19-F — transfer 5상태 분기를 위한 메타 부착
                    'transfer_status' => $relatedTransfer?->status,
                    'finance_confirmer_name' => $relatedTransfer?->financeConfirmer?->name,
                    'confirmed_at' => $relatedTransfer?->confirmed_at,
                    'finance_note' => $relatedTransfer?->finance_note,
                    // 큐 19-K — finance_rejected 분기 메타
                    'finance_rejecter_name' => $relatedTransfer?->financeRejecter?->name,
                    'finance_rejected_at' => $relatedTransfer?->finance_rejected_at,
                    'finance_reject_reason' => $relatedTransfer?->finance_reject_reason,
                ];
            } elseif ($mostRecent && $mostRecent->action_type === ApprovalRequest::TYPE_INTER_VEHICLE_TRANSFER_VOID) {
                $p = $mostRecent->payload ?? [];
                $voidTransfer = InterVehicleTransfer::with('financeVoidRejecter')
                    ->find($p['transfer_id'] ?? $mostRecent->target_id);
                $base['lastDecided'] = [
                    'type' => 'void',
                    'approval_request_id' => $mostRecent->id,
                    'status' => $mostRecent->status,
                    'transfer_id' => $p['transfer_id'] ?? $mostRecent->target_id,
                    'target_vehicle_number' => $p['target_vehicle_number'] ?? '#'.($p['target_vehicle_id'] ?? '?'),
                    'amount' => (float) ($p['amount'] ?? 0),
                    'currency' => $p['currency'] ?? $source->currency,
                    'decision_note' => $mostRecent->decision_note,
                    'reason' => $mostRecent->reason,
                    'decided_at' => $mostRecent->decided_at,
                    'approver_name' => $mostRecent->approver?->name,
                    // 큐 19-L — void 재무 거부 메타
                    'void_finance_rejecter_name' => $voidTransfer?->financeVoidRejecter?->name,
                    'void_finance_rejected_at' => $voidTransfer?->void_finance_rejected_at,
                    'void_finance_reject_reason' => $voidTransfer?->void_finance_reject_reason,
                ];
            }
        }

        if ($source->buyer_id === null) {
            $base['reason'] = '바이어가 지정된 차량만 자금 이체 가능합니다.';

            return $base;
        }

        // 큐 19-G — 미처리 transfer 있으면 신규 요청 차단 (한도 이중 부과 방지).
        // lastDecided 박스가 이미 상태 표시하지만, 일반 이체 박스도 reason으로 한 번 더 안내.
        if ($inProgressTransfer) {
            $label = InterVehicleTransfer::STATUSES[$inProgressTransfer->status] ?? $inProgressTransfer->status;
            $base['reason'] = "미처리 자금 이체가 있습니다 ({$label}). 처리 완료/거부 후 재시도하세요.";

            return $base;
        }

        $ratio = $source->unpaid_ratio;
        if ($ratio === null || $ratio > 0.5) {
            $pct = $ratio === null ? '—' : round($ratio * 100, 1).'%';
            $base['reason'] = "출처 차량에 50% 이상 입금되어야 합니다 (현재 미수율 {$pct}).";

            return $base;
        }
        if ($source->settlements()->where('settlement_status', 'paid')->exists()) {
            $base['reason'] = '출처 차량에 paid 정산이 있어 이체할 수 없습니다.';

            return $base;
        }
        $received = (float) $source->sale_total_amount - (float) $source->sale_unpaid_amount;
        $limit = round(max(0.0, $received) * 0.5, 2);

        // 후보 차량 — 동일 buyer, 동일 currency, paid Settlement 없음, 자신 제외
        $candidates = Vehicle::where('buyer_id', $source->buyer_id)
            ->where('currency', $source->currency)
            ->where('id', '!=', $source->id)
            ->whereNull('deleted_at')
            ->whereDoesntHave('settlements', fn ($q) => $q->where('settlement_status', 'paid'))
            ->orderBy('vehicle_number')
            ->get(['id', 'vehicle_number', 'sale_price', 'sale_unpaid_amount_krw_cache']);

        if ($candidates->isEmpty()) {
            $base['reason'] = '동일 바이어 + 동일 통화의 다른 차량이 없습니다.';
            $base['received'] = $received;
            $base['limit'] = $limit;

            return $base;
        }

        $base['eligible'] = true;
        $base['received'] = $received;
        $base['limit'] = $limit;
        $base['candidates'] = $candidates;

        return $base;
    }

    // 2026-07-09 — 옛 G2 배너 computed(sameBuyerOverlap) 제거. 미수 바이어 신규거래는 미수 매입 게이트(②)가 담당.

    public function lookupNiceApi(): void
    {
        // NICE 1단계가 소유자명 필수 — 차량번호만 있고 소유자명 비면 안내 후 중단.
        if (empty(trim($this->vehicle_number))) {
            return;
        }
        if (empty(trim($this->nice_reg_owner_name))) {
            $this->dispatch('notify', message: __('vehicle.toast.nice_need_owner'), type: 'warning');

            return;
        }

        $result = NiceApiService::fromConfig()->lookupVehicle(
            trim($this->vehicle_number),
            trim($this->nice_reg_owner_name),
        );

        // null = 엔드포인트 미설정(수동 입력 모드) / success=false = 조회 실패(미들웨어 원문 메시지 노출)
        if ($result === null) {
            $this->dispatch('notify', message: __('vehicle.toast.nice_not_configured'), type: 'warning');

            return;
        }
        if (($result['success'] ?? false) !== true) {
            // 미들웨어 실제 사유 노출 (예: "소유주명이 일치하지 않습니다 E901") — 실패를 명확히 인지.
            $this->dispatch('notify', message: $result['message'] ?? __('vehicle.toast.nice_failed'), type: 'error');

            return;
        }

        foreach ($result['registration'] ?? [] as $key => $value) {
            $prop = $key.'_str';
            if (property_exists($this, $prop)) {
                $this->$prop = (string) $value;
            } elseif (property_exists($this, $key)) {
                $this->$key = (string) $value;
            }
        }
        foreach ($result['spec'] ?? [] as $key => $value) {
            $prop = $key.'_str';
            if (property_exists($this, $prop)) {
                $this->$prop = (string) $value;
            } elseif (property_exists($this, $key)) {
                $this->$key = (string) $value;
            }
        }

        // 응답 원본 보존 — 저장 시 nice_raw 로 기입 (미매핑 필드 재조회 없이 활용).
        $this->niceRaw = $result['raw'] ?? [];

        // 성공 토스트 — 조회 성공/실패를 사용자가 명확히 인지하도록(저장 전 단계라 헷갈리기 쉬움).
        $count = count($result['registration'] ?? []) + count($result['spec'] ?? []);
        $this->dispatch('notify', message: __('vehicle.toast.nice_success', ['count' => $count]), type: 'success');
    }

    public function addFinalPayment(): void
    {
        // 회의확장씬 #7 (2026-05-22) — 잔금 추가 시 차량 currency 의 실시간 환율 자동 기입.
        // KRW: 1.0 / 외화: ExchangeRateService::getRate (실패 시 빈 값 → 수동 입력 fallback).
        // payment_date 도 today 자동 (입금 날짜 = 추가 시점 자연).
        $autoRate = '';
        if ($this->currency === 'KRW') {
            $autoRate = '1';
        } else {
            $rate = app(\App\Services\ExchangeRateService::class)->getRate($this->currency);
            if ($rate !== null) {
                $autoRate = (string) $rate;
            }
        }

        $this->finalPayments[] = [
            'id' => null,
            'amount' => '',
            'exchange_rate' => $autoRate,
            'payment_date' => today()->toDateString(),
            'note' => '',
        ];
    }
    public function removeFinalPayment(int $idx): void
    {
        unset($this->finalPayments[$idx]);
        $this->finalPayments = array_values($this->finalPayments);
    }

    /**
     * 잔금 날짜 변경 시 그 날짜의 마감환율 자동 기입 (2026-07-13).
     *   - 미확정(confirmed_at 없음) 행 + 외화만. 확정 잔금은 소급 방지로 자동채움 안 함.
     *   - 오늘/미래 = 실시간 네이버(이력 미보유), 과거 = daily_exchange_rates 마감환율(주말=직전영업일).
     *   - 조회 실패/미보유(4월 이전·미보유 통화) = 기존값 유지(수기입력).
     */
    public function updatedFinalPayments($value, $key): void
    {
        if ($this->currency === 'KRW' || ! str_ends_with((string) $key, '.payment_date')) {
            return;
        }
        $idx = (int) explode('.', (string) $key)[0];
        $row = $this->finalPayments[$idx] ?? null;
        if (! $row || ! empty($row['confirmed_at'])) {
            return;   // 확정 잔금은 자동채움 X (2차 정산 소급 방지)
        }
        $date = trim((string) $value);
        if ($date === '') {
            return;
        }

        $service = app(\App\Services\ExchangeRateService::class);
        $rate = $date >= today()->toDateString()
            ? $service->getRate($this->currency)                         // 오늘/미래 = 실시간
            : $service->getRateForDate($this->currency, $date);          // 과거 = 마감환율 이력
        if ($rate !== null) {
            $this->finalPayments[$idx]['exchange_rate'] = (string) $rate;
        }
    }

    public function addPurchasePayment(): void
    {
        $this->purchaseBalancePayments[] = ['id' => null, 'amount' => '', 'payment_date' => '', 'note' => ''];
    }
    public function removePurchasePayment(int $idx): void
    {
        unset($this->purchaseBalancePayments[$idx]);
        $this->purchaseBalancePayments = array_values($this->purchaseBalancePayments);
    }

    private function resetForm(): void
    {
        $defaults = [
            'vehicle_number','brand','model_type','color','year_str','cc_str','weight_kg_str','mileage_str',
            'nice_reg_vin','nice_reg_engine_no','nice_reg_fuel_type','nice_reg_use_type','nice_reg_vehicle_form',
            'nice_reg_first_date','nice_reg_date','nice_reg_owner_name','nice_reg_owner_addr','nice_reg_owner_rrn',
            'nice_reg_max_load_str','nice_reg_passengers_str','nice_reg_color',
            'nice_spec_maker','nice_spec_model','nice_spec_year','nice_spec_displacement_str',
            'nice_spec_transmission','nice_spec_drive_type','nice_spec_length_str','nice_spec_width_str',
            'nice_spec_height_str','nice_spec_wheelbase_str','nice_spec_curb_weight_str','nice_spec_fuel_efficiency',
            'purchase_date','salesman_id_str','purchase_from','purchase_evidence_type','purchase_partner_type',
            'purchase_seller_bank','purchase_seller_account','purchase_seller_holder','purchase_bank_memo',
            'purchase_fee_bank','purchase_fee_account','purchase_fee_holder',
            'purchase_price_str','selling_fee_str',
            'cost_deregistration_str','cost_license_str','cost_towing_str','cost_carry_str',
            'cost_shoring_str','cost_insurance_str','cost_transfer_str','cost_extra1_str','cost_extra2_str',
            'down_payment_str','selling_fee_payment_str','purchase_remittance_memo','registration_number','reg_cert_number','deregistration_date','deregistrationBuyerPhone',
            'sale_date','exchange_rate_str','buyer_id_str','consignee_id_str',
            'sale_price_str','tax_dc_str','commission_str','transport_fee_str','auto_loading_str',
            'sale_other_costs_str','savings_used_str','savings_deposit_str',
            'deposit_down_payment_str','interim_payment_str','advance_payment1_str','fee_str',
            'export_buyer_id_str','export_consignee_id_str','forwarding_company_id_str',
            'export_declaration_amount_str','export_declaration_number','shipping_date','eta_date','shipping_method','port_of_loading',
            'incoterms','discharge_port_id_str',
            'bl_buyer_id_str','bl_consignee_id_str','bl_number','container_number',
            'bl_loading_location','vessel_name','bl_issue_date','document_deadline_date',
            'dhl_recipient_name','dhl_recipient_address','dhl_recipient_phone',
            'dhl_sender_name','dhl_sender_address','dhl_weight_str','dhl_dimensions','memo',
        ];
        foreach ($defaults as $prop) $this->$prop = '';

        // 저장 후 표시용 요약 스냅샷(수정 불가 박스) — openEdit 에서만 채워지므로 신규 등록 전 초기화 필수.
        // 누락 시 직전 편집 차량의 총매입액·총판매가·미지급이 신규 등록 화면에 잔존(jin 2026-07-14).
        // openEdit 은 매번 panel* 전부 재대입하므로 편집 표시엔 영향 없음.
        $this->panelUnpaidRatio = $this->panelSaleTotal = $this->panelSaleUnpaid = null;
        $this->panelPurchaseTotal = $this->panelPurchasePaid = $this->panelPurchaseUnpaid = null;
        $this->panelPurchasePriceTotal = $this->panelPurchasePricePaid = null;
        $this->panelSellingFeeTotal = $this->panelSellingFeePaid = null;

        $this->niceRaw = [];
        $this->sales_channel = 'export';
        $this->currency = 'USD';
        $this->is_deregistered = $this->is_export_cleared = false;
        $this->dhl_request = false;
        $this->finalPayments = $this->purchaseBalancePayments = [];
        $this->deregistrationDocFile = $this->exportDeclarationDocFile = $this->blDocFile = null;
        $this->deregistration_document_path = $this->export_declaration_document_path = $this->bl_document_path = '';
        $this->clearDeregistrationDoc = $this->clearExportDeclarationDoc = $this->clearBlDoc = false;
        $this->photoFiles = $this->photoUpload = $this->existingPhotos = $this->deletePhotoIds = [];
        $this->shipPhotoFiles = $this->shipPhotoUpload = $this->existingShipPhotos = $this->deleteShipPhotoIds = [];
    }

    private function unsetComputedProperties(): void
    {
        unset($this->vehicles);
    }
}; ?>

{{-- UX #6 (2026-05-20) — wire:poll.30s — 사이드바 뱃지 + 페이지 데이터 30초 자동 갱신. --}}
<div wire:poll.30s>
{{-- ── 플래시 메시지 ────────────────────────────────────────────── --}}
@if(session('success'))
    <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 3000)"
         class="fixed top-4 right-4 z-50 rounded-lg bg-green-600 px-4 py-3 text-sm text-white shadow-lg">
        {{ session('success') }}
    </div>
@endif
@if(session('notice'))
    <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 4000)"
         class="fixed top-4 right-4 z-50 rounded-lg bg-amber-500 px-4 py-3 text-sm text-white shadow-lg">
        {{ session('notice') }}
    </div>
@endif

{{-- 토스트 리스너는 layouts/app/sidebar.blade.php 에 글로벌 추출됨 --}}

<div class="flex h-full flex-col gap-4 p-3 md:p-6">

{{-- ── 페이지 헤더 ─────────────────────────────────────────────── --}}
<div class="flex items-center justify-between">
    <div>
        <h1 class="text-xl font-bold text-gray-800">{{ __('vehicle.title') }}</h1>
        <p class="mt-0.5 text-xs text-gray-500">{{ __('vehicle.total', ['count' => $this->vehicles->total()]) }}</p>
    </div>
    <div class="flex items-center gap-2">
        <select wire:model.live="perPage" class="input-filter">
            <option value="10">{{ __('vehicle.per_page', ['count' => 10]) }}</option>
            <option value="30">{{ __('vehicle.per_page', ['count' => 30]) }}</option>
            <option value="50">{{ __('vehicle.per_page', ['count' => 50]) }}</option>
            <option value="100">{{ __('vehicle.per_page', ['count' => 100]) }}</option>
        </select>
        <button wire:click="openCreate" class="btn-primary">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            {{ __('vehicle.create_btn') }}
        </button>
    </div>
</div>

{{-- 선적요청 배치 딥링크 활성 — 그 차량만 조회 중임을 안내 + 전체 복귀 --}}
@if($ids !== '')
<div class="flex items-center justify-between rounded-lg border border-teal-200 bg-teal-50 px-3 py-2 text-sm">
    <span class="font-semibold text-teal-800">{{ __('vehicle.batch_filter', ['count' => count(array_filter(explode(',', $ids)))]) }}</span>
    <a href="{{ route('erp.vehicles.index') }}" wire:navigate class="rounded-md border border-teal-300 bg-white px-2.5 py-1 text-xs font-semibold text-teal-700 hover:bg-teal-100">
        {{ __('vehicle.batch_filter_clear') }}
    </a>
</div>
@endif

{{-- ── 필터 바 ─────────────────────────────────────────────────── --}}
<div class="space-y-2">
    {{-- 검색 + 날짜 + 조회 --}}
    <div class="flex flex-wrap items-center gap-2 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2">
        <input wire:model="search" wire:keydown.enter="applyFilters" type="text" placeholder="{{ __('vehicle.search_placeholder') }}"
               class="input-filter w-52" />
        <select wire:model="dateType" class="input-filter">
            <option value="purchase">{{ __('vehicle.date_type.purchase') }}</option>
            <option value="deregistration">{{ __('vehicle.date_type.deregistration') }}</option>
            <option value="sale">{{ __('vehicle.date_type.sale') }}</option>
            <option value="shipping">{{ __('vehicle.date_type.shipping') }}</option>
            <option value="bl">{{ __('vehicle.date_type.bl') }}</option>
            <option value="balance">{{ __('vehicle.date_type.balance') }}</option>
            <option value="all">{{ __('vehicle.date_type.all') }}</option>
        </select>
        <input wire:model="dateFrom" type="text" data-date class="input-filter" />
        <span class="text-gray-400 text-sm">~</span>
        <input wire:model="dateTo" type="text" data-date class="input-filter" />
        <select wire:model.live="salesmanId" class="input-filter">
            <option value="">{{ __('vehicle.all_salesmen') }}</option>
            @foreach($this->salesmen as $s)
                <option value="{{ $s->id }}">{{ $s->name }}</option>
            @endforeach
        </select>
        {{-- 회의확장씬 #3 Phase 2-4 (2026-05-23) — 바이어 select 필터 --}}
        <select wire:model.live="buyerId" class="input-filter">
            <option value="">{{ __('vehicle.all_buyers') }}</option>
            @foreach($this->buyersForFilter as $b)
                <option value="{{ $b->id }}">{{ $b->name }}</option>
            @endforeach
        </select>
        <button wire:click="applyFilters" class="btn-search">{{ __('vehicle.search_btn') }}</button>
        <button type="button" wire:click="resetFilters"
                class="rounded border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-600 hover:bg-gray-50"
                title="{{ __('vehicle.reset_hint') }}">{{ __('vehicle.reset_btn') }}</button>
    </div>
    {{-- 빠른 탭 필터 — 큐 16: 채널 pill 제거 (단일 채널) --}}
    <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5">
        <div class="flex flex-wrap gap-1">
            {{-- 안건 1 v4 (2026-05-21) — 워크플로우 순서: 선적(반입) → 통관 → B/L → 거래완료. v3 호환 키는 매핑에서 같은 라벨로 흡수 --}}
            @foreach(['', '매입중', '매입완료', '말소완료', '판매중', '판매완료', '선적중', '선적완료', '통관중', '통관완료', '수출통관중', '수출통관완료', '거래완료'] as $val)
            @php
                if ($val === '') {
                    $isInclude = $progressFilter === '' && count($excludeStatuses) === 0;
                    $isExclude = false;
                } else {
                    $isInclude = $progressFilter === $val;
                    $isExclude = in_array($val, $excludeStatuses, true);
                }
            @endphp
            <button wire:click="cycleProgress('{{ $val }}')"
                    class="rounded-full px-2.5 py-0.5 text-xs font-medium transition
                           @if($isInclude) bg-violet-600 text-white @elseif($isExclude) bg-red-500 text-white line-through @else bg-gray-100 text-gray-600 hover:bg-gray-200 @endif">
                {{ $val === '' ? __('vehicle.filter_all') : __('domain.progress.'.$val) }}
            </button>
            @endforeach
        </div>
    </div>
</div>

{{-- ── 누적검색 — 차량번호로 하나씩 검색해 선적 묶음 셋에 누적 (jin 2026-07-08, board 지연 대비 ERP 자체 묶음) ── --}}
<div class="mb-2">
    <button type="button" wire:click="toggleAccumSearch"
            class="flex w-full items-center justify-between rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-2 text-sm font-semibold text-indigo-800 hover:bg-indigo-100">
        <span class="flex items-center gap-2">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/></svg>
            {{ __('vehicle.accum.title') }}
            @if(count($shipDocIds) > 0)
                <span class="pill-count">{{ count($shipDocIds) }}</span>
            @endif
        </span>
        <svg class="h-4 w-4 transition-transform {{ $accumSearchOpen ? 'rotate-180' : '' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
    </button>

    @if($accumSearchOpen)
    <div class="mt-1 space-y-3 rounded-lg border border-indigo-200 bg-white p-3">
        <p class="text-xs text-gray-500">{{ __('vehicle.accum.hint') }}</p>
        <div class="flex flex-wrap items-center gap-2">
            <input wire:model="accumSearchTerm" wire:keydown.enter.prevent="searchAccum" type="text"
                   placeholder="{{ __('vehicle.accum.placeholder') }}" class="input-filter w-52" />
            <button type="button" wire:click="searchAccum" class="btn-search">{{ __('vehicle.accum.search_btn') }}</button>
        </div>
    </div>
    @endif
</div>

{{-- (a)안 (jin 2026-07-09) — 목록은 검색결과, 선택은 아래 액션바의 칩(태그)으로 별도 누적. --}}

{{-- #3 다중차량 선적 서류 — 체크박스 선택(export 차량) 시 노출. 선택 N대를 1서류에 자동 기입(최대 30대). --}}
@if(count($shipDocIds) > 0)
@php
    $shipIds = implode(',', $shipDocIds);
    $shipCnt = count($shipDocIds);
    $shipDocs = ['container_invoice_packing', 'container_contract', 'roro_invoice_packing', 'roro_contract'];
@endphp
<div class="card-tight mb-3 border-amber-300 bg-amber-50">
    {{-- 선택 칩 (jin 2026-07-09 (a)안) — 검색·페이지 바꿔도 유지되는 누적 선택. X로 개별 제거. --}}
    <div class="mb-2 flex flex-wrap gap-1.5">
        @foreach($this->selectedShipVehicles as $sv)
            <span class="inline-flex items-center gap-1 rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-medium text-indigo-800">
                {{ $sv->vehicle_number }}
                <button type="button" wire:click="removeFromAccumulation({{ $sv->id }})" class="leading-none text-indigo-500 hover:text-indigo-900">&times;</button>
            </span>
        @endforeach
    </div>
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
    <div class="flex items-center gap-2 text-sm">
        <span class="font-semibold text-amber-800">{{ __('vehicle.selected', ['count' => $shipCnt]) }}</span>
        @if($shipCnt > 30)
            <span class="text-xs text-red-600">{{ __('vehicle.max30') }}</span>
        @endif
        <button type="button" wire:click="clearShipDocSelection" class="text-xs text-gray-500 hover:underline">{{ __('vehicle.clear_selection') }}</button>
    </div>
    <div class="flex flex-wrap gap-2">
        {{-- ⓪ 선적요청으로 묶기 — 선택 N대를 새 batch 로 선적요청 생성(관리·통관). board 발과 동일 파이프라인. --}}
        @if(auth()->user()?->canAccessClearance())
            <button type="button" wire:click="bundleToShipping" wire:confirm="{{ __('vehicle.accum.bundle_confirm') }}"
                    class="rounded border border-indigo-300 bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700">
                → {{ __('vehicle.accum.bundle_btn') }}
            </button>
        @endif
        {{-- ② 면허비 n/1 딥링크 — 선택 차량이 한 묶음일 때만(완전묶음). 선적 묶음 화면 2차 비용 탭 자동 오픈. --}}
        @if(auth()->user()?->canApprove() && $this->selectedBundle)
            <a href="{{ route('erp.shipping-requests.index', ['focus' => $this->selectedBundle]) }}" wire:navigate
               class="rounded border border-violet-300 bg-violet-50 px-3 py-1.5 text-xs font-medium text-violet-700 hover:bg-violet-100">
                → {{ __('vehicle.shipdoc_license_link') }}
            </a>
        @endif
        @foreach($shipDocs as $type)
            <a href="{{ $shipCnt <= 30 ? route('erp.vehicles.documents.multi', ['type' => $type, 'ids' => $shipIds]) : '#' }}"
               target="_blank"
               class="rounded border border-amber-300 bg-white px-3 py-1.5 text-xs font-medium text-amber-700 hover:bg-amber-100 {{ $shipCnt > 30 ? 'pointer-events-none opacity-50' : '' }}">
                ↓ {{ __('vehicle.shipdoc.'.$type) }}
            </a>
        @endforeach
        {{-- 판매계약서 (다중차량) — 동일 바이어·통화일 때만 활성(컨트롤러 가드와 동일). --}}
        @php
            $scRows = \App\Models\Vehicle::whereIn('id', $shipDocIds)->get(['buyer_id', 'currency']);
            $scOk = $scRows->isNotEmpty()
                && $scRows->pluck('buyer_id')->unique()->count() === 1
                && $scRows->pluck('currency')->unique()->count() === 1;
        @endphp
        <a href="{{ ($scOk && $shipCnt <= 30) ? route('erp.vehicles.documents.multi', ['type' => 'sales_contract', 'ids' => $shipIds]) : '#' }}"
           target="_blank"
           title="{{ $scOk ? '' : __('vehicle.sales_contract_homogeneous_hint') }}"
           class="rounded border border-purple-300 bg-white px-3 py-1.5 text-xs font-medium text-purple-700 hover:bg-purple-100 {{ (! $scOk || $shipCnt > 30) ? 'pointer-events-none opacity-50' : '' }}">
            ↓ {{ __('vehicle.shipdoc.sales_contract') }}
        </a>
        {{-- 전자서명 요청 — 판매계약서와 동일 조건(동일 바이어·통화·export). 발급 후 링크 모달. --}}
        <button type="button" wire:click="requestSignature"
                title="{{ $scOk ? '' : __('vehicle.sales_contract_homogeneous_hint') }}"
                @disabled(! $scOk || $shipCnt > 30)
                class="rounded border border-purple-400 bg-purple-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-purple-700 {{ (! $scOk || $shipCnt > 30) ? 'opacity-50 cursor-not-allowed' : '' }}">
            ✍ {{ __('signed_contract.request_btn') }}
        </button>
    </div>
    </div>
</div>
@endif

{{-- 전자서명 링크 발급 모달 — 발급된 signed URL 복사(바이어에게 카톡/이메일로 전달). --}}
@if($showSignModal)
<div class="fixed inset-0 z-[120] flex items-center justify-center bg-black/40 p-4" wire:click.self="$set('showSignModal', false)">
    <div class="w-full max-w-lg rounded-xl bg-white p-5 shadow-xl" x-data="{ copied: false }">
        <div class="mb-3 flex items-center justify-between">
            <h3 class="text-base font-bold text-gray-800">✍ {{ __('signed_contract.request_btn') }} · {{ $signContractNo }}</h3>
            <button type="button" wire:click="$set('showSignModal', false)" class="text-gray-400 hover:text-gray-600">✕</button>
        </div>
        <p class="mb-2 text-sm text-gray-600">{{ __('signed_contract.modal.hint') }}</p>
        <div class="flex gap-2">
            <input type="text" readonly value="{{ $signUrl }}" x-ref="signUrl"
                   class="w-full rounded border border-gray-300 bg-gray-50 px-2 py-1.5 text-xs text-gray-700" />
            <button type="button"
                    @click="$refs.signUrl.select(); navigator.clipboard.writeText($refs.signUrl.value); copied = true; setTimeout(() => copied = false, 1500)"
                    class="shrink-0 rounded bg-purple-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-purple-700">
                <span x-show="!copied">{{ __('signed_contract.modal.copy') }}</span>
                <span x-show="copied" x-cloak>✓</span>
            </button>
        </div>
        <p class="mt-3 text-xs text-gray-400">{{ __('signed_contract.modal.expire') }}</p>
    </div>
</div>
@endif

{{-- ── 데스크탑 테이블 (회의확장씬 #10 컬럼 토글 + 정렬) ─────── --}}
<div class="hidden sm:block"
     x-data="vehicleColumnsToggle()"
     x-init="init()">

    {{-- 컬럼 토글 드롭다운 --}}
    <div class="mb-2 flex justify-end relative">
        <button type="button" @click="open = !open"
                class="rounded border border-gray-300 bg-white px-3 py-1 text-xs text-gray-700 hover:bg-gray-50">
            {{ __('vehicle.columns') }} <span x-text="open ? '▲' : '▼'"></span>
        </button>
        <div x-show="open" x-cloak @click.outside="open = false"
             class="absolute right-0 top-8 z-10 w-56 rounded-lg border border-gray-200 bg-white py-2 shadow-lg">
            <div class="px-3 pb-1 text-[10px] font-semibold uppercase text-gray-400">{{ __('vehicle.show_columns') }}</div>
            <template x-for="col in togglableColumns" :key="col.key">
                <label class="flex items-center gap-2 px-3 py-1.5 text-xs text-gray-700 hover:bg-gray-50 cursor-pointer">
                    <input type="checkbox" :checked="visible[col.key]" @change="toggle(col.key)" class="rounded" />
                    <span x-text="col.label"></span>
                </label>
            </template>
            <div class="border-t border-gray-100 mt-1 px-3 py-1">
                <button @click="resetDefaults()" class="text-[11px] text-violet-600 hover:underline">{{ __('vehicle.reset_defaults') }}</button>
            </div>
        </div>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-sm border-separate border-spacing-0">
        <thead>
            @php
                $sortBtn = function ($col, $label, $align = 'left') {
                    $isActive = $this->sortColumn === $col;
                    $indicator = $isActive ? ($this->sortDirection === 'asc' ? '▲' : '▼') : '';
                    $alignCls = $align === 'right' ? 'justify-end ml-auto' : '';

                    return '<button type="button" wire:click="setSort(\''.$col.'\')" class="flex items-center gap-1 hover:text-gray-700 '.$alignCls.'">'
                        .htmlspecialchars($label).' <span class="text-[10px]">'.$indicator.'</span></button>';
                };
            @endphp
            <tr class="border-b border-gray-200 text-left text-xs text-gray-500">
                <th class="w-6 pb-2 pr-2 font-medium" title="{{ __('vehicle.shipdoc_select_title') }}"></th>
                <th class="pb-2 pr-4 font-medium">{!! $sortBtn('vehicle_number', __('vehicle.col.number')) !!}</th>
                <th class="pb-2 pr-4 font-medium" x-show="visible['brand_model']">{!! $sortBtn('brand', __('vehicle.col.brand_model')) !!}</th>
                <th class="pb-2 pr-4 font-medium" x-show="visible['vin']">{!! $sortBtn('nice_reg_vin', __('vehicle.col.vin')) !!}</th>
                <th class="pb-2 pr-4 font-medium">{!! $sortBtn('progress_status_cache', __('vehicle.col.status')) !!}</th>
                <th class="pb-2 pr-4 font-medium" x-show="visible['purchase_date']">{!! $sortBtn('purchase_date', __('vehicle.col.purchase_date')) !!}</th>
                <th class="pb-2 pr-4 font-medium" x-show="visible['sale_date']">{!! $sortBtn('sale_date', __('vehicle.col.sale_date')) !!}</th>
                <th class="pb-2 pr-4 font-medium" x-show="visible['shipping_date']">{!! $sortBtn('shipping_date', __('vehicle.col.shipping_date')) !!}</th>
                <th class="pb-2 pr-4 font-medium" x-show="visible['eta_date']">{!! $sortBtn('eta_date', __('vehicle.col.eta_date')) !!}</th>
                <th class="pb-2 pr-4 font-medium" x-show="visible['bl_issue_date']">{!! $sortBtn('bl_issue_date', __('vehicle.col.bl_issue_date')) !!}</th>
                <th class="pb-2 pr-4 font-medium" x-show="visible['deregistration_date']">{!! $sortBtn('deregistration_date', __('vehicle.col.deregistration_date')) !!}</th>
                <th class="pb-2 pr-4 font-medium" x-show="visible['export_declaration_number']">{{ __('vehicle.col.export_declaration_number') }}</th>
                <th class="pb-2 pr-4 font-medium" x-show="visible['container_number']">{{ __('vehicle.col.container_number') }}</th>
                <th class="pb-2 pr-4 font-medium">{!! $sortBtn('salesman_id', __('vehicle.col.salesman')) !!}</th>
                <th class="pb-2 pr-4 font-medium" x-show="visible['buyer']">{!! $sortBtn('buyer_id', __('vehicle.col.buyer')) !!}</th>
                <th class="pb-2 pr-4 font-medium" x-show="visible['sales_channel']">{!! $sortBtn('sales_channel', __('vehicle.col.channel')) !!}</th>
                <th class="pb-2 pr-4 font-medium text-right" x-show="visible['currency_rate']">{{ __('vehicle.col.currency_rate') }}</th>
                <th class="pb-2 pr-4 font-medium text-right" x-show="visible['purchase_price']">{!! $sortBtn('purchase_price', __('vehicle.col.purchase_price'), 'right') !!}</th>
                <th class="pb-2 pr-4 font-medium text-right" x-show="visible['sale_price']">{!! $sortBtn('sale_price', __('vehicle.col.sale_price'), 'right') !!}</th>
                <th class="pb-2 pr-4 font-medium text-right" x-show="visible['sale_total']">{{ __('vehicle.col.sale_total') }}</th>
                <th class="pb-2 pr-4 font-medium text-right" x-show="visible['unpaid_amount']">{{ __('vehicle.col.unpaid_amount') }}</th>
                <th class="pb-2 pr-4 font-medium text-right" x-show="visible['unpaid_ratio']">{{ __('vehicle.col.unpaid_ratio') }}</th>
                <th class="pb-2 font-medium"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse($this->vehicles as $v)
            @php
                $status = $v->progress_status;
                $badgeClass = match(true) {
                    in_array($status, ['매입중','매입완료','말소완료']) => 'badge-blue',
                    in_array($status, ['판매중','판매완료'])           => 'badge-purple',
                    in_array($status, ['선적중','선적완료'])            => 'badge-amber',
                    in_array($status, ['통관중','통관완료'])             => 'badge-green',
                    in_array($status, ['수출통관중','수출통관완료'])     => 'badge-amber',
                    $status === '거래완료'                             => 'badge-gray',
                    default                                            => 'badge-gray',
                };
            @endphp
            @php
                $unpaidRatio = $v->unpaid_ratio;
                $unpaidAmount = $v->sale_unpaid_amount;
            @endphp
            <tr class="cursor-pointer transition {{ $unpaidRatio === null ? 'hover:bg-gray-50' : '' }}"
                wire:click="openEdit({{ $v->id }})"
                @if($unpaidRatio !== null)
                    data-ratio="{{ number_format($unpaidRatio, 6, '.', '') }}"
                    data-unpaid="{{ (int) round($v->sale_unpaid_amount) }}"
                    data-total="{{ (int) round($v->sale_total_amount) }}"
                    data-currency="{{ $v->currency }}"
                @endif
            >
                <td class="py-3 pr-2" @click.stop>
                    @if($v->sales_channel === 'export')
                        <input type="checkbox" wire:model.live="shipDocIds" value="{{ $v->id }}" class="rounded border-gray-300" title="{{ __('vehicle.shipdoc_select_title') }}" />
                    @endif
                </td>
                <td class="py-3 pr-4 font-mono font-medium text-gray-800">
                    {{ $v->vehicle_number }}
                    {{-- karaba(2026-07-12): 매매상 잔금 10일 임박 배지 (거래처구분 매매상 + 계약금 + 미납) --}}
                    @php $balDays = $v->purchase_balance_due_days; @endphp
                    @if($balDays !== null)
                        <span class="ml-1 whitespace-nowrap rounded-full bg-red-100 px-1.5 py-0.5 text-[10px] font-bold text-red-700" title="{{ __('vehicle.field.purchase_partner_type') }}: 매매상">
                            {{ $balDays >= 0 ? __('vehicle.balance_badge', ['d' => $balDays]) : __('vehicle.balance_badge_overdue') }}
                        </span>
                    @endif
                </td>
                <td class="py-3 pr-4 text-gray-700" x-show="visible['brand_model']">
                    {{ $v->brand }} {{ $v->model_type }}
                    @if($v->year)<span class="text-xs text-gray-400">({{ $v->year }})</span>@endif
                </td>
                <td class="py-3 pr-4 font-mono text-xs text-gray-600" x-show="visible['vin']">{{ $v->nice_reg_vin ?: '-' }}</td>
                <td class="py-3 pr-4"><span class="badge {{ $badgeClass }}">{{ __('domain.progress.'.$status) }}</span></td>
                <td class="py-3 pr-4 text-gray-500" x-show="visible['purchase_date']">{{ $v->purchase_date?->format('Y-m-d') ?? '-' }}</td>
                <td class="py-3 pr-4 text-gray-500" x-show="visible['sale_date']">{{ $v->sale_date?->format('Y-m-d') ?? '-' }}</td>
                <td class="py-3 pr-4 text-gray-500" x-show="visible['shipping_date']">{{ $v->shipping_date?->format('Y-m-d') ?? '-' }}</td>
                <td class="py-3 pr-4 text-gray-500" x-show="visible['eta_date']">{{ $v->eta_date?->format('Y-m-d') ?? '-' }}</td>
                <td class="py-3 pr-4 text-gray-500" x-show="visible['bl_issue_date']">{{ $v->bl_issue_date?->format('Y-m-d') ?? '-' }}</td>
                <td class="py-3 pr-4 text-gray-500" x-show="visible['deregistration_date']">{{ $v->deregistration_date?->format('Y-m-d') ?? '-' }}</td>
                <td class="py-3 pr-4 font-mono text-xs text-gray-600" x-show="visible['export_declaration_number']">{{ $v->export_declaration_number ?: '-' }}</td>
                <td class="py-3 pr-4 font-mono text-xs text-gray-600" x-show="visible['container_number']">{{ $v->container_number ?: '-' }}</td>
                <td class="py-3 pr-4 text-gray-500">{{ $v->salesman?->name ?? '-' }}</td>
                <td class="py-3 pr-4 text-gray-500" x-show="visible['buyer']">{{ $v->buyer?->name ?? '-' }}</td>
                <td class="py-3 pr-4 text-gray-500" x-show="visible['sales_channel']">{{ $v->sales_channel ? __('domain.channel.'.$v->sales_channel) : '-' }}</td>
                <td class="py-3 pr-4 text-right text-gray-500 text-xs" x-show="visible['currency_rate']">
                    {{ $v->currency }}
                    @if($v->exchange_rate && $v->exchange_rate != 1)
                        <br><span class="text-[10px]">{{ number_format($v->exchange_rate, 2) }}</span>
                    @endif
                </td>
                <td class="py-3 pr-4 text-right text-gray-600" x-show="visible['purchase_price']">
                    @if($v->purchase_price > 0)₩{{ number_format($v->purchase_price) }}@else -@endif
                </td>
                <td class="py-3 pr-4 text-right font-medium text-gray-800" x-show="visible['sale_price']">
                    @if($v->sale_price > 0)
                        {{ number_format($v->sale_price) }} <span class="text-xs text-gray-400">{{ $v->currency }}</span>
                    @else -
                    @endif
                </td>
                <td class="py-3 pr-4 text-right font-medium text-gray-700" x-show="visible['sale_total']">
                    @if($v->sale_price > 0)
                        {{ number_format($v->sale_total_amount) }} <span class="text-xs text-gray-400">{{ $v->currency }}</span>
                    @else -
                    @endif
                </td>
                <td class="py-3 pr-4 text-right text-gray-600" x-show="visible['unpaid_amount']">
                    @if($unpaidAmount > 0)₩{{ number_format($unpaidAmount) }}@else -@endif
                </td>
                <td class="py-3 pr-4 text-right text-xs" x-show="visible['unpaid_ratio']">
                    @if($unpaidRatio === null)<span class="text-gray-300">-</span>
                    @elseif($unpaidRatio <= 0)<span class="text-green-600 font-medium">{{ __('vehicle.fully_paid') }}</span>
                    @else <span class="text-amber-600">{{ number_format((1 - $unpaidRatio) * 100, 0) }}%</span>
                    @endif
                </td>
                <td class="py-3 text-right">
                    <button wire:click.stop="delete({{ $v->id }})"
                            wire:confirm="{{ __('vehicle.delete_confirm', ['number' => $v->vehicle_number]) }}"
                            class="text-xs text-red-400 hover:text-red-600">{{ __('vehicle.delete') }}</button>
                </td>
            </tr>
            @empty
            <tr><td colspan="20" class="py-12 text-center text-sm text-gray-400">{{ __('vehicle.empty') }}</td></tr>
            @endforelse
        </tbody>
        </table>
    </div>
</div>

{{-- 회의확장씬 #10 Phase 2-3 (2026-05-23) — 컬럼 토글 Alpine 컴포넌트 (localStorage 캐시). --}}
<script>
function vehicleColumnsToggle() {
    const STORAGE_KEY = 'car_erp_vehicles_columns_v3';   // v3: 기본=브랜드/차종·매입일·말소일·판매총액 (jin 2026-07-07)
    const defaultVisible = {
        brand_model: true, purchase_date: true, deregistration_date: true, sale_total: true,
        vin: false, sale_price: false,
        sale_date: false, shipping_date: false, eta_date: false, bl_issue_date: false,
        export_declaration_number: false, container_number: false,
        currency_rate: false, purchase_price: false,
        unpaid_amount: false, unpaid_ratio: false,
        buyer: false, sales_channel: false,
    };
    return {
        open: false,
        visible: {},
        togglableColumns: [
            { key: 'brand_model',    label: @json(__('vehicle.col.brand_model')) },
            { key: 'vin',            label: @json(__('vehicle.col.vin')) },
            { key: 'purchase_date',  label: @json(__('vehicle.col.purchase_date')) },
            { key: 'sale_date',      label: @json(__('vehicle.col.sale_date')) },
            { key: 'shipping_date',  label: @json(__('vehicle.col.shipping_date')) },
            { key: 'eta_date',       label: @json(__('vehicle.col.eta_date')) },
            { key: 'bl_issue_date',  label: @json(__('vehicle.col.bl_issue_date')) },
            { key: 'deregistration_date',       label: @json(__('vehicle.col.deregistration_date')) },
            { key: 'export_declaration_number', label: @json(__('vehicle.col.export_declaration_number')) },
            { key: 'container_number',          label: @json(__('vehicle.col.container_number')) },
            { key: 'buyer',          label: @json(__('vehicle.col.buyer')) },
            { key: 'sales_channel',  label: @json(__('vehicle.col.channel')) },
            { key: 'currency_rate',  label: @json(__('vehicle.col.currency_rate')) },
            { key: 'purchase_price', label: @json(__('vehicle.col.purchase_price')) },
            { key: 'sale_price',     label: @json(__('vehicle.col.sale_price')) },
            { key: 'sale_total',     label: @json(__('vehicle.col.sale_total')) },
            { key: 'unpaid_amount',  label: @json(__('vehicle.col.unpaid_amount')) },
            { key: 'unpaid_ratio',   label: @json(__('vehicle.col.unpaid_ratio')) },
        ],
        init() {
            const saved = localStorage.getItem(STORAGE_KEY);
            const parsed = saved ? JSON.parse(saved) : {};
            for (const key in defaultVisible) {
                this.visible[key] = parsed[key] !== undefined ? parsed[key] : defaultVisible[key];
            }
        },
        toggle(key) {
            this.visible[key] = !this.visible[key];
            localStorage.setItem(STORAGE_KEY, JSON.stringify(this.visible));
        },
        resetDefaults() {
            this.visible = { ...defaultVisible };
            localStorage.setItem(STORAGE_KEY, JSON.stringify(this.visible));
        },
    };
}
</script>

{{-- ── 모바일 카드 리스트 ───────────────────────────────────────── --}}
<div class="block sm:hidden space-y-2">
    @forelse($this->vehicles as $v)
    @php
        $status = $v->progress_status;
        $badgeClass = match(true) {
            in_array($status, ['매입중','매입완료','말소완료']) => 'badge-blue',
            in_array($status, ['판매중','판매완료'])           => 'badge-purple',
            in_array($status, ['선적중','선적완료'])            => 'badge-amber',
            in_array($status, ['통관중','통관완료'])             => 'badge-green',
            in_array($status, ['수출통관중','수출통관완료'])     => 'badge-amber',
            $status === '거래완료'                             => 'badge-gray',
            default                                            => 'badge-gray',
        };
    @endphp
    <div class="card-tight flex items-center justify-between cursor-pointer" wire:click="openEdit({{ $v->id }})">
        <div class="flex items-center gap-2">
            @if($v->sales_channel === 'export')
                <input type="checkbox" wire:model.live="shipDocIds" value="{{ $v->id }}" @click.stop
                       class="rounded border-gray-300" title="{{ __('vehicle.shipdoc_select_title') }}" />
            @endif
        <div class="space-y-0.5">
            <div class="font-mono font-semibold text-gray-800">
                {{ $v->vehicle_number }}
                @php $balDaysM = $v->purchase_balance_due_days; @endphp
                @if($balDaysM !== null)
                    <span class="ml-1 whitespace-nowrap rounded-full bg-red-100 px-1.5 py-0.5 text-[10px] font-bold text-red-700">
                        {{ $balDaysM >= 0 ? __('vehicle.balance_badge', ['d' => $balDaysM]) : __('vehicle.balance_badge_overdue') }}
                    </span>
                @endif
            </div>
            <div class="text-xs text-gray-500">{{ $v->brand }} {{ $v->model_type }}</div>
            <div class="flex items-center gap-1.5">
                <span class="badge {{ $badgeClass }}">{{ __('domain.progress.'.$status) }}</span>
                @if($v->sale_price > 0)
                    <span class="text-xs font-medium text-gray-700">{{ number_format($v->sale_price) }} {{ $v->currency }}</span>
                @endif
            </div>
        </div>
        </div>
        <div class="text-xs text-gray-400 text-right">
            <div>{{ $v->salesman?->name ?? '-' }}</div>
            <div>{{ $v->purchase_date?->format('m/d') ?? '' }}</div>
        </div>
    </div>
    @empty
    <div class="py-12 text-center text-sm text-gray-400">{{ __('vehicle.empty') }}</div>
    @endforelse
</div>

{{-- ── 페이지네이션 (좌: 데이터 도구 / 우: 페이저) ───────────────────── --}}
<div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
    <div class="flex items-center gap-2">
        @if(auth()->user()->canAccessAdmin())
        {{-- 차량 일괄적재 빈 양식 다운로드 (super/admin 마이그레이션 도구). 데이터 없는 빈 양식이라 PII·회계 0. --}}
        <a href="{{ route('erp.vehicles.import-template') }}"
           class="inline-flex items-center gap-1 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3"/></svg>
            {{ __('vehicle.import_template_btn') }}
        </a>
        @endif
        {{-- 데이터 내려받기 팝오버 (전 ERP role — canScopeVehicle 스코핑). 범위(현재필터/전체) + 컬럼 선택.
             정산 그룹은 정산 접근 role(재무·관리·admin·super)에게만 노출·허용. 선택 컬럼은 서버 화이트리스트 교집합. --}}
        @php $allowSettlement = auth()->user()->canAccessSettlement();
             $exportCols = (new \App\Services\VehicleExportService)->columnsForUi($allowSettlement);
             $exportAllKeys = array_merge(...array_map('array_keys', array_values($exportCols))); @endphp
        <div class="relative" x-data="{
                open: false, scope: 'current', showCols: false,
                cols: Object.fromEntries(@js($exportAllKeys).map(k => [k, true])),
                allChecked(keys) { return keys.every(k => this.cols[k]); },
                setGroup(keys, v) { keys.forEach(k => this.cols[k] = v); },
                download() {
                    const sel = Object.keys(this.cols).filter(k => this.cols[k]);
                    if (!sel.length) { alert(@js(__('vehicle.export_pick_col'))); return; }
                    const p = new URLSearchParams({ scope: this.scope, cols: sel.join(',') });
                    if (this.scope === 'current') {
                        if ($wire.search) p.set('q', $wire.search);
                        if ($wire.progressFilter) p.set('progress', $wire.progressFilter);
                        if ($wire.excludeStatuses && $wire.excludeStatuses.length) p.set('exclude', $wire.excludeStatuses.join(','));
                        p.set('dateType', $wire.dateType || 'purchase');
                        if ($wire.dateFrom) p.set('dateFrom', $wire.dateFrom);
                        if ($wire.dateTo) p.set('dateTo', $wire.dateTo);
                        if ($wire.salesmanId) p.set('salesmanId', $wire.salesmanId);
                    }
                    window.location.href = '{{ route('erp.vehicles.export') }}?' + p.toString();
                    this.open = false;
                }
             }">
            <button type="button" @click="open = !open"
                    class="inline-flex items-center gap-1 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg>
                {{ __('vehicle.export_btn') }}
                <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
            </button>
            <div x-show="open" x-cloak @click.outside="open = false"
                 class="absolute bottom-full left-0 z-30 mb-1 w-72 rounded-lg border border-gray-200 bg-white p-3 text-sm shadow-lg">
                {{-- 범위 --}}
                <div class="mb-1 flex items-center gap-3">
                    <span class="text-xs font-semibold text-gray-500">{{ __('vehicle.export_scope') }}</span>
                    <label class="flex items-center gap-1"><input type="radio" value="current" x-model="scope"> {{ __('vehicle.export_scope_current') }}</label>
                    <label class="flex items-center gap-1"><input type="radio" value="all" x-model="scope"> {{ __('vehicle.export_scope_all') }}</label>
                </div>
                <p class="mb-2 text-[11px] leading-snug text-gray-400">{{ __('vehicle.export_scope_hint') }}</p>
                <hr class="my-2 border-gray-100">
                {{-- 그룹 + 개별 컬럼 --}}
                <div class="max-h-64 space-y-1 overflow-y-auto">
                    @foreach($exportCols as $groupLabel => $colmap)
                        @php $keys = array_keys($colmap); @endphp
                        <label class="flex items-center gap-2 font-medium text-gray-700">
                            <input type="checkbox" :checked="allChecked(@js($keys))"
                                   @change="setGroup(@js($keys), $event.target.checked)">
                            {{ $groupLabel }}
                            <span class="text-xs font-normal text-gray-400">({{ count($colmap) }})</span>
                        </label>
                        <div x-show="showCols" class="ml-5 space-y-0.5">
                            @foreach($colmap as $key => $label)
                                <label class="flex items-center gap-2 text-xs text-gray-600">
                                    <input type="checkbox" x-model="cols['{{ $key }}']"> {{ $label }}
                                </label>
                            @endforeach
                        </div>
                    @endforeach
                </div>
                <button type="button" @click="showCols = !showCols" class="mt-1 text-xs text-primary-text hover:underline"
                        x-text="showCols ? '{{ __('vehicle.export_cols_collapse') }}' : '{{ __('vehicle.export_cols_expand') }}'"></button>
                <hr class="my-2 border-gray-100">
                <button type="button" @click="download()" class="btn-primary w-full justify-center">{{ __('vehicle.export_do') }}</button>
            </div>
        </div>
        {{-- 탁송비 명세서 일괄 기입 (건바이건 비용 — 관리/admin, 전체 차량). 면허비는 묶음화면. --}}
        @if(auth()->user()?->canApprove())
        <button type="button" wire:click="openCostImport"
                class="inline-flex items-center gap-1 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
            {{ __('vehicle.cost_import.btn') }}
        </button>
        @endif
    </div>
    <div>{{ $this->vehicles->links() }}</div>
</div>

</div>{{-- /flex col --}}

{{-- ══════════════════════════════════════════════════════════════ --}}
{{-- 슬라이드 패널                                                  --}}
{{-- ══════════════════════════════════════════════════════════════ --}}
@if($showPanel)
{{-- 큐 18: close confirm — 기존 tab/switch-tab Alpine과 dirty 추적 병합 --}}
<div x-data="{
    tab: 'basic',
    dirty: false,
    confirmOpen: false,
    lightbox: { open: false, url: '', name: '', kind: '' },
    openLightbox(url, name, kind) { this.lightbox = { open: true, url: url, name: name, kind: kind }; },
    closeLightbox() { this.lightbox.open = false; },
    attemptClose() {
        if ($wire.showPurchaseGate) { $wire.cancelPurchaseGate(); return; }   // 미수 게이트 모달이 최상위(z-110)
        if ($wire.quickAddOpen) { $wire.cancelQuickAdd(); return; }     // 바이어/컨사이니 quick-add 모달이 최상위 — ESC는 그것부터 닫음
        if ($wire.showMailModal) { $wire.closeMailModal(); return; }   // 메일 모달이 최상위 — ESC는 그것부터 닫음
        if (this.lightbox.open) { this.lightbox.open = false; return; }
        if (this.confirmOpen) { this.confirmOpen = false; return; }
        if (this.dirty) { this.confirmOpen = true; } else { $wire.close(); }
    },
    confirmDiscard() { this.confirmOpen = false; $wire.close(); },
}" x-on:switch-tab.window="tab = $event.detail.tab" @panel-mark-dirty.window="dirty = true" @keyup.escape.window="attemptClose()">
{{-- Backdrop --}}
<div class="fixed inset-0 z-40 bg-black/40" @click="attemptClose()"></div>

{{-- Panel --}}
<div class="fixed inset-y-0 right-0 z-50 flex w-full flex-col bg-white shadow-2xl sm:w-[700px] lg:w-[820px]"
     @input="dirty = true" @change="dirty = true">

    {{-- Panel Header — 큐 14-4-4: 신규 등록 직후엔 "✓ 등록 완료" 배지로 시각 강화 --}}
    @php $justCreated = $justCreatedId !== null && $justCreatedId === $editingId; @endphp
    <div class="flex items-center justify-between border-b {{ $justCreated ? 'border-emerald-300 bg-emerald-50' : 'border-gray-200' }} px-5 py-4">
        <div class="flex items-center gap-2">
            @if($justCreated)
            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-600 px-2.5 py-1 text-xs font-semibold text-white">
                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                {{ __('vehicle.panel.created_badge') }}
            </span>
            @endif
            <div>
                <h2 class="text-base font-bold {{ $justCreated ? 'text-emerald-800' : 'text-gray-800' }}">
                    @if($justCreated)
                        {{ __('vehicle.panel.edit_next') }}
                    @else
                        {{ $editingId ? __('vehicle.panel.edit_title') : __('vehicle.panel.create_title') }}
                    @endif
                </h2>
                @if($vehicle_number)
                <p class="text-xs {{ $justCreated ? 'text-emerald-600' : 'text-gray-400' }} font-mono mt-0.5">{{ $vehicle_number }}</p>
                @endif
            </div>
        </div>
        <button @click="attemptClose()" class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>

    {{-- 동시 편집 잠금 — 패널 열린 동안 하트비트(내 잠금 갱신) + 타인 점유 시 읽기전용 배너 --}}
    @if($editingId !== null)
    <div wire:poll.30s="heartbeat"></div>
    @endif
    @if($editLockedByOther)
    <div class="flex items-center gap-2 border-b border-amber-300 bg-amber-50 px-5 py-2.5 text-sm text-amber-800">
        <span class="text-base">🔒</span>
        <span>{{ __('vehicle.lock.banner', ['name' => $editLockOwnerName]) }}</span>
    </div>
    @endif

    {{-- 큐 2번 — 1대 흐름도 스트립 (편집 모드에서만). 큐 6 H13 — warn/pending/progress에 reason tooltip. --}}
    @if($this->progressFlow)
    <div class="border-b border-gray-100 bg-gray-50/50 px-5 py-3">
        <div class="overflow-x-auto">
            <div class="flex min-w-max items-center gap-1">
                @foreach($this->progressFlow as $i => $node)
                @php
                    $statusBadge = match($node['status']) {
                        'done'     => ['icon' => '✓', 'cls' => 'bg-green-100 text-green-700 border-green-300'],
                        'warn'     => ['icon' => '!', 'cls' => 'bg-amber-100 text-amber-700 border-amber-300'],
                        'progress' => ['icon' => '⋯', 'cls' => 'bg-blue-100 text-blue-700 border-blue-300'],
                        'pending'  => ['icon' => '-', 'cls' => 'bg-gray-100 text-gray-500 border-gray-200'],
                        'disabled' => ['icon' => '×', 'cls' => 'bg-gray-50 text-gray-300 border-gray-100'],
                    };
                @endphp
                {{-- tooltip은 native title만 사용 — 부모 overflow-x-auto가 absolute 자식 y-axis 클리핑 발동 --}}
                <button type="button" @click="tab = '{{ $node['tab'] }}'"
                    @if(!empty($node['reason'])) title="{{ $node['reason'] }}" @endif
                    class="flex flex-shrink-0 items-center gap-1.5 rounded-md border px-2.5 py-1 text-xs transition hover:brightness-95 {{ $statusBadge['cls'] }}">
                    <span class="font-bold">{{ $statusBadge['icon'] }}</span>
                    <span class="font-medium">{{ $node['label'] }}</span>
                </button>
                @if($i < count($this->progressFlow) - 1)
                <span class="flex-shrink-0 text-gray-300" aria-hidden="true">›</span>
                @endif
                @endforeach
            </div>
        </div>
    </div>
    @endif

    {{-- 큐 21 — Ledger 잠금 배너 (회의록 2026-05-18). 회계 영향 컬럼 21개(매입가/판매가/환율/면장금액/비용9개/관련 5컬럼/바이어/담당자) 잠금 표시. --}}
    @if($isLedgerLocked)
    {{-- 접기/펼치기 (기본 접힘) — 🔒/🔓 상태는 헤더에 항상 노출, 설명·잠금해제 버튼은 쓸 때만 펼침.
         색상은 미입금 우회 승인 섹션과 동일한 앰버로 통일 (잠긴 회색은 사용자 눈에 안 띔 — jin 2026-07-01). --}}
    <div x-data="{ open: false }" class="border-b border-amber-200 bg-amber-50 px-5 py-2">
        <button type="button" @click="open = ! open" class="flex w-full items-center gap-2 text-left">
            <span class="text-base leading-none">{{ $hasLedgerUnlockToken ? '🔓' : '🔒' }}</span>
            <span class="flex-1 text-xs font-semibold text-amber-800">
                {{ $hasLedgerUnlockToken ? __('vehicle.panel.ledger.unlocked_title') : __('vehicle.panel.ledger.locked_title') }}
            </span>
            <svg class="h-4 w-4 flex-shrink-0 text-amber-500 transition-transform" :class="open && 'rotate-180'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
        </button>
        <div x-show="open" x-cloak class="mt-2 flex flex-wrap items-center justify-between gap-2 pl-6">
            <p class="text-xs text-amber-700">
                {{ $hasLedgerUnlockToken ? __('vehicle.panel.ledger.unlocked_desc') : __('vehicle.panel.ledger.locked_desc') }}
            </p>
            @if(! $hasLedgerUnlockToken && $canUnlockLedger)
            <button type="button" wire:click="openLedgerUnlockModal"
                    class="flex-shrink-0 rounded-md bg-amber-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-amber-700">
                🔓 {{ __('vehicle.panel.ledger.unlock_btn') }}
            </button>
            @endif
        </div>
    </div>
    @endif

    {{-- Validation 에러 박스 (guard 메서드 throw + Livewire validate 모두 표시) --}}
    @if($errors->any())
    <div class="border-b border-red-200 bg-red-50 px-5 py-3">
        <div class="flex items-start gap-2">
            <svg class="mt-0.5 h-4 w-4 flex-shrink-0 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <div class="flex-1">
                <p class="text-xs font-semibold text-red-700">{{ __('vehicle.panel.validation_title') }}</p>
                <ul class="mt-1 space-y-0.5 text-xs text-red-600">
                    @foreach($errors->all() as $msg)
                    <li>· {{ $msg }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
    @endif

    {{-- Tab Nav --}}
    {{-- 회의확장씬 #1 v4 (2026-05-21) — 워크플로우 순서 swap: 선적 → 수출통관 --}}
    <div class="flex overflow-x-auto border-b border-gray-200 px-5">
        @foreach(['basic', 'purchase', 'sale', 'shipping', 'clearance', 'bl', 'dhl', 'docs'] as $key)
        <button @click="tab = '{{ $key }}'"
                :class="tab === '{{ $key }}' ? 'border-b-2 border-violet-600 text-violet-600' : 'text-gray-500 hover:text-gray-700'"
                class="flex-shrink-0 px-4 py-3 text-sm font-medium transition">
            {{ __('vehicle.panel.tab.'.$key) }}
        </button>
        @endforeach
    </div>

    {{-- Tab Content --}}
    <div class="flex-1 overflow-y-auto px-5 py-5">
        <div>

        {{-- ─── 기본정보 탭 ─────────────────────────────── --}}
        <div x-show="tab === 'basic'" x-cloak>
            <div class="section-header">
                <span class="section-dot bg-violet-500"></span>
                <span class="section-title">{{ __('vehicle.panel.sec.basic') }}</span>
            </div>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                <div class="col-span-2 sm:col-span-1">
                    <label class="label-base">
                        {{ __('vehicle.field.vehicle_number') }} <span class="text-red-500">*</span>
                        @if($editingId)
                        <span class="ml-1 text-[10px] font-normal text-gray-400">{{ __('vehicle.panel.readonly_note') }}</span>
                        @endif
                    </label>
                    <div class="flex gap-1">
                        @if($editingId)
                            {{-- 편집 모드: 차량번호 readonly. 식별의 핵심이라 변경 차단(차량번호 변경 필요 시 별도 액션). --}}
                            <input wire:model="vehicle_number" type="text"
                                   class="input-base flex-1 bg-gray-100 text-gray-600 cursor-not-allowed"
                                   placeholder="12가3456" readonly />
                        @else
                            {{-- NICE 조회는 버튼 클릭으로만 (blur 자동조회 제거 — 의도 안 한 건당 과금 방지, jin 2026-06-24) --}}
                            <input wire:model="vehicle_number" type="text" class="input-base flex-1" placeholder="12가3456" />
                            <button type="button" wire:click="lookupNiceApi"
                                    wire:loading.attr="disabled" wire:target="lookupNiceApi"
                                    class="rounded-lg border border-gray-300 px-2 py-2 text-xs text-gray-600 hover:bg-gray-50 whitespace-nowrap disabled:opacity-50">
                                <span wire:loading.remove wire:target="lookupNiceApi">{{ __('vehicle.panel.lookup') }}</span>
                                <span wire:loading wire:target="lookupNiceApi">{{ __('vehicle.panel.lookup_ing') }}</span>
                            </button>
                        @endif
                    </div>
                    @unless($editingId)
                        {{-- NICE 1단계가 소유자명 필수 → 차량번호와 함께 입력. nice_reg_owner_name 에 바인딩(아래 등록정보 소유자명과 동기화). --}}
                        <input wire:model="nice_reg_owner_name" type="text" class="input-base mt-1 w-full" autocomplete="off"
                               placeholder="{{ __('vehicle.panel.owner_name_ph') }}" />
                    @endunless
                    @error('vehicle_number')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
                </div>
                {{-- 큐 16 — 판매채널 select 제거. sales_channel은 hidden 'export' 고정. --}}
                <input type="hidden" wire:model="sales_channel" />
                {{-- 큐 17 — 폐기 체크박스 제거 (운영상 폐기 없음). --}}
                <div>
                    <label class="label-base">{{ __('vehicle.field.brand') }}</label>
                    <input wire:model="brand" type="text" class="input-base" placeholder="{{ __('vehicle.ph.brand') }}" />
                </div>
                <div>
                    <label class="label-base">{{ __('vehicle.field.model_type') }}</label>
                    <input wire:model="model_type" type="text" class="input-base" placeholder="{{ __('vehicle.ph.model_type') }}" />
                </div>
                <div>
                    <label class="label-base">{{ __('vehicle.field.year') }}</label>
                    <input wire:model="year_str" type="number" class="input-base" placeholder="2020" />
                </div>
                <div>
                    <label class="label-base">{{ __('vehicle.field.cc') }}</label>
                    <input wire:model="cc_str" type="number" class="input-base" placeholder="1991" />
                </div>
                <div>
                    <label class="label-base">{{ __('vehicle.field.weight_kg') }}</label>
                    <input wire:model="weight_kg_str" type="number" class="input-base" placeholder="1470" />
                </div>
                <div>
                    <label class="label-base">{{ __('vehicle.field.mileage') }}</label>
                    <input wire:model="mileage_str" type="number" class="input-base" placeholder="85000" />
                </div>
                <div>
                    <label class="label-base">{{ __('vehicle.field.color') }}</label>
                    <input wire:model="color" type="text" class="input-base" placeholder="{{ __('vehicle.ph.color') }}" />
                </div>
                {{-- 영업담당자 — 등록 시 지정 누락 방지 위해 매입 탭에서 기본정보로 이동 (2026-06-04).
                     옵션은 $this->salesmen (관리 role 은 본인 팀 영업만 노출). --}}
                <div>
                    <label class="label-base">{{ __('vehicle.field.salesman') }}</label>
                    <select wire:model="salesman_id_str" class="input-base">
                        <option value="">{{ __('vehicle.panel.select_placeholder') }}</option>
                        @foreach($this->salesmen as $sm)
                        <option value="{{ $sm->id }}">{{ $sm->name }}</option>
                        @endforeach
                    </select>
                    @error('salesman_id_str')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
                </div>
            </div>

            <hr class="section-divider">
            <div class="section-header">
                <span class="section-dot bg-blue-400"></span>
                <span class="section-title">{{ __('vehicle.panel.sec.nice_reg') }}</span>
            </div>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                <div><label class="label-base">{{ __('vehicle.field.vin') }}</label><input wire:model="nice_reg_vin" type="text" class="input-base" /></div>
                <div><label class="label-base">{{ __('vehicle.field.engine_no') }}</label><input wire:model="nice_reg_engine_no" type="text" class="input-base" /></div>
                <div><label class="label-base">{{ __('vehicle.field.fuel_type') }}</label><input wire:model="nice_reg_fuel_type" type="text" class="input-base" placeholder="{{ __('vehicle.ph.fuel_type') }}" /></div>
                <div><label class="label-base">{{ __('vehicle.field.use_type') }}</label><input wire:model="nice_reg_use_type" type="text" class="input-base" placeholder="{{ __('vehicle.ph.use_type') }}" /></div>
                <div><label class="label-base">{{ __('vehicle.field.vehicle_form') }}</label><input wire:model="nice_reg_vehicle_form" type="text" class="input-base" /></div>
                <div><label class="label-base">{{ __('vehicle.field.first_date') }}</label><input wire:model="nice_reg_first_date" type="text" data-date class="input-base" /></div>
                <div><label class="label-base">{{ __('vehicle.field.reg_date') }}</label><input wire:model="nice_reg_date" type="text" data-date class="input-base" /></div>
                <div>
                    <label class="label-base">{{ __('vehicle.field.owner_name') }}</label>
                    <input wire:model="nice_reg_owner_name" type="text" class="input-base" autocomplete="off" />
                    {{-- 편집 모드 NICE 재조회 — 신규등록 모드의 조회 버튼이 편집엔 없어 import/기존 차량은 제원을 못 채웠음(2026-06-11).
                         lookupNiceApi 는 차량번호+소유자명(둘 다 편집모드에서 채워짐)으로 동작 → 채움 후 저장해야 반영. --}}
                    @if($editingId)
                        <button type="button" wire:click="lookupNiceApi"
                                wire:loading.attr="disabled" wire:target="lookupNiceApi"
                                class="mt-1 w-full rounded-lg border border-blue-300 bg-blue-50 px-2 py-1.5 text-xs font-medium text-blue-700 hover:bg-blue-100 disabled:opacity-50">
                            <span wire:loading.remove wire:target="lookupNiceApi">{{ __('vehicle.panel.lookup_nice') }}</span>
                            <span wire:loading wire:target="lookupNiceApi">{{ __('vehicle.panel.lookup_ing') }}</span>
                        </button>
                        <p class="mt-1 text-[11px] leading-tight text-gray-400">{{ __('vehicle.panel.lookup_edit_hint') }}</p>
                    @endif
                </div>
                <div x-data="{ show: false }">
                    <label class="label-base">{{ __('vehicle.field.owner_rrn') }}</label>
                    <div class="relative">
                        {{-- UX #4 (2026-05-20) — Alpine x-on:input + $store.rrnMask 로 자동 mask. wire:model.blur 로 blur 시점 sync. --}}
                        <input wire:model.blur="nice_reg_owner_rrn" :type="show ? 'text' : 'password'"
                               x-on:input="$el.value = $store.rrnMask.apply($el.value)"
                               class="input-base pr-10 font-mono" placeholder="000000-0000000" autocomplete="off" maxlength="14" />
                        <button type="button" @click="show = !show"
                                class="absolute inset-y-0 right-0 flex w-9 items-center justify-center text-gray-400 hover:text-gray-600"
                                :title="show ? '{{ __('vehicle.panel.hide') }}' : '{{ __('vehicle.panel.show') }}'"
                            <svg x-show="!show" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                            <svg x-show="show" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.542-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.542 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/></svg>
                        </button>
                    </div>
                </div>
                <div><label class="label-base">{{ __('vehicle.field.max_load') }}</label><input wire:model="nice_reg_max_load_str" type="number" class="input-base" /></div>
                <div><label class="label-base">{{ __('vehicle.field.passengers') }}</label><input wire:model="nice_reg_passengers_str" type="number" class="input-base" /></div>
                <div><label class="label-base">{{ __('vehicle.field.reg_color') }}</label><input wire:model="nice_reg_color" type="text" class="input-base" /></div>
                <div class="col-span-2 sm:col-span-1"><label class="label-base">{{ __('vehicle.field.owner_addr') }}</label><input wire:model="nice_reg_owner_addr" type="text" class="input-base" /></div>
            </div>

            <hr class="section-divider">
            <div class="section-header">
                <span class="section-dot bg-sky-400"></span>
                <span class="section-title">{{ __('vehicle.panel.sec.nice_spec') }}</span>
            </div>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                <div><label class="label-base">{{ __('vehicle.field.spec_maker') }}</label><input wire:model="nice_spec_maker" type="text" class="input-base" /></div>
                <div><label class="label-base">{{ __('vehicle.field.spec_model') }}</label><input wire:model="nice_spec_model" type="text" class="input-base" /></div>
                <div><label class="label-base">{{ __('vehicle.field.spec_year') }}</label><input wire:model="nice_spec_year" type="text" class="input-base" /></div>
                <div><label class="label-base">{{ __('vehicle.field.spec_displacement') }}</label><input wire:model="nice_spec_displacement_str" type="number" class="input-base" /></div>
                <div><label class="label-base">{{ __('vehicle.field.spec_transmission') }}</label><input wire:model="nice_spec_transmission" type="text" class="input-base" placeholder="{{ __('vehicle.ph.transmission') }}" /></div>
                <div><label class="label-base">{{ __('vehicle.field.spec_drive_type') }}</label><input wire:model="nice_spec_drive_type" type="text" class="input-base" placeholder="{{ __('vehicle.ph.drive_type') }}" /></div>
                <div><label class="label-base">{{ __('vehicle.field.spec_length') }}</label><input wire:model="nice_spec_length_str" type="number" class="input-base" /></div>
                <div><label class="label-base">{{ __('vehicle.field.spec_width') }}</label><input wire:model="nice_spec_width_str" type="number" class="input-base" /></div>
                <div><label class="label-base">{{ __('vehicle.field.spec_height') }}</label><input wire:model="nice_spec_height_str" type="number" class="input-base" /></div>
                <div><label class="label-base">{{ __('vehicle.field.spec_wheelbase') }}</label><input wire:model="nice_spec_wheelbase_str" type="number" class="input-base" /></div>
                <div><label class="label-base">{{ __('vehicle.field.spec_curb_weight') }}</label><input wire:model="nice_spec_curb_weight_str" type="number" class="input-base" /></div>
                <div><label class="label-base">{{ __('vehicle.field.spec_fuel_efficiency') }}</label><input wire:model="nice_spec_fuel_efficiency" type="text" class="input-base" /></div>
            </div>

            {{-- 차량등록증 자동차등록번호 ↔ 차량 첨부 — 나란히(2열) 배치로 세로 공간 절약 --}}
            <hr class="section-divider">
            <div>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    {{-- 차량등록증 자동차등록번호 — 통관SET 구매리스트 G3(한글/영문등록증). 매입 등록번호와 별개. --}}
                    <div>
                        <label class="label-base">{{ __('vehicle.field.reg_cert_number') }}</label>
                        <input type="text" wire:model="reg_cert_number" class="input-base" placeholder="{{ __('vehicle.field.reg_cert_number_ph') }}" maxlength="50" />
                        <p class="mt-1 text-xs text-gray-400">{{ __('vehicle.field.reg_cert_number_hint') }}</p>
                    </div>
                    {{-- 차량 첨부 (사진·PDF·Excel·Word·HWP 등 · 최대 10건 — vehicle_photos, 운영 시 S3). 여러 건 한 번에 선택. --}}
                    <div>
                        <label class="label-base">{{ __('vehicle.panel.sec.photos') }}</label>
                        <input type="file" wire:model="photoUpload" multiple
                               accept=".jpg,.jpeg,.png,.gif,.webp,.bmp,.pdf,.xlsx,.xls,.csv,.docx,.doc,.hwp,.hwpx,.pptx,.ppt,.txt,.zip"
                               class="input-base text-sm" />
                        <p class="mt-1 text-xs text-gray-400">{{ __('vehicle.panel.photo_multi_hint') }}</p>
                        <div wire:loading wire:target="photoUpload" class="mt-1 text-xs text-gray-400">{{ __('vehicle.panel.uploading') }}</div>
                        @error('photoUpload')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
                        @error('photoUpload.*')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
                    </div>
                </div>

                @if(count($existingPhotos) || count($photoFiles))
                <div class="mt-3 grid grid-cols-3 gap-2 sm:grid-cols-5">
                        @foreach($existingPhotos as $p)
                        <div class="relative aspect-square overflow-hidden rounded border border-gray-200">
                            @if($p['is_image'])
                                <img src="{{ $p['url'] }}" class="h-full w-full cursor-zoom-in object-cover"
                                     alt="{{ $p['filename'] }}"
                                     @click="openLightbox({{ \Illuminate\Support\Js::from($p['url']) }}, {{ \Illuminate\Support\Js::from($p['filename']) }}, 'image')" />
                            @elseif($p['ext'] === 'pdf')
                                <button type="button"
                                        @click="openLightbox({{ \Illuminate\Support\Js::from($p['url']) }}, {{ \Illuminate\Support\Js::from($p['filename']) }}, 'pdf')"
                                        class="flex h-full w-full cursor-zoom-in flex-col items-center justify-center gap-1 bg-gray-50 p-2 text-center hover:bg-gray-100">
                                    <span class="rounded bg-gray-200 px-2 py-1 text-[10px] font-bold text-gray-700">PDF</span>
                                    <span class="line-clamp-2 break-all text-[10px] text-gray-700">{{ $p['filename'] }}</span>
                                </button>
                            @else
                                <a href="{{ $p['url'] }}" target="_blank" rel="noopener"
                                   class="flex h-full w-full flex-col items-center justify-center gap-1 bg-gray-50 p-2 text-center hover:bg-gray-100">
                                    <span class="rounded bg-gray-200 px-2 py-1 text-[10px] font-bold text-gray-700">{{ strtoupper($p['ext']) ?: 'FILE' }}</span>
                                    <span class="line-clamp-2 break-all text-[10px] text-gray-700">{{ $p['filename'] }}</span>
                                </a>
                            @endif
                            <button type="button" wire:click="removeExistingPhoto({{ $p['id'] }})"
                                    class="absolute right-1 top-1 rounded-full bg-black/60 px-1.5 text-xs leading-none text-white hover:bg-red-600">×</button>
                        </div>
                        @endforeach
                        @foreach($photoFiles as $idx => $photo)
                        @php
                            $_ext = strtolower($photo->getClientOriginalExtension());
                            $_isImg = in_array($_ext, \App\Models\VehiclePhoto::IMAGE_EXTENSIONS, true);
                        @endphp
                        <div class="relative aspect-square overflow-hidden rounded border border-violet-300">
                            @if($_isImg)
                                <img src="{{ $photo->temporaryUrl() }}" class="h-full w-full cursor-zoom-in object-cover"
                                     alt="{{ __('vehicle.panel.new') }}"
                                     @click="openLightbox({{ \Illuminate\Support\Js::from($photo->temporaryUrl()) }}, {{ \Illuminate\Support\Js::from($photo->getClientOriginalName()) }}, 'image')" />
                            @else
                                <div class="flex h-full w-full flex-col items-center justify-center gap-1 bg-violet-50 p-2 text-center">
                                    <span class="rounded bg-violet-200 px-2 py-1 text-[10px] font-bold text-violet-800">{{ strtoupper($_ext) ?: 'FILE' }}</span>
                                    <span class="line-clamp-2 break-all text-[10px] text-gray-700">{{ $photo->getClientOriginalName() }}</span>
                                </div>
                            @endif
                            <span class="absolute left-1 top-1 rounded bg-violet-600 px-1 text-[10px] text-white">{{ __('vehicle.panel.new') }}</span>
                            <button type="button" wire:click="removeNewPhoto({{ $idx }})"
                                    class="absolute right-1 top-1 rounded-full bg-black/60 px-1.5 text-xs leading-none text-white hover:bg-red-600">×</button>
                        </div>
                        @endforeach
                </div>
                @endif
                <p class="mt-1 text-xs text-gray-400">{{ __('vehicle.panel.photo_count', ['count' => count($existingPhotos) + count($photoFiles)]) }}</p>
            </div>
        </div>

        {{-- ─── 매입 탭 ──────────────────────────────────── --}}
        <div x-show="tab === 'purchase'" x-cloak>
            @php $isKaraba = \App\Models\Setting::isKaraba(); @endphp
            <div class="section-header">
                <span class="section-dot bg-blue-500"></span>
                <span class="section-title">{{ __('vehicle.panel.sec.purchase_basic') }}</span>
            </div>
            {{-- UX #1 (2026-05-20) — 매입 필수 입력란 노랑 배경. 영업이 입력 누락 방지. --}}
            {{-- karaba(2026-07-12): 구입처 자유텍스트 → 거래처구분 드롭박스로 대체 + 매입증빙(자유입력). 매도비 옆 배치. --}}
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                <div><label class="label-base">{{ __('vehicle.field.purchase_date') }} </label><input wire:model="purchase_date" type="text" data-date class="input-base input-required" /></div>
                {{-- 영업담당자 select 는 기본정보 탭(색상 옆)으로 이동 (2026-06-04). --}}
                @unless($isKaraba)
                <div class="col-span-2 sm:col-span-1">
                    <label class="label-base">{{ __('vehicle.field.purchase_from') }} </label>
                    <input wire:model="purchase_from" type="text" class="input-base input-required" placeholder="{{ __('vehicle.ph.purchase_from') }}" />
                </div>
                @endunless
                <div><label class="label-base">{{ __('vehicle.field.purchase_price') }} </label><input wire:model="purchase_price_str" type="text" data-money class="input-base input-required" placeholder="0" /></div>
                <div><label class="label-base">{{ __('vehicle.field.selling_fee') }} </label><input wire:model="selling_fee_str" type="text" data-money class="input-base input-required" placeholder="0" /></div>
                @if($isKaraba)
                <div>
                    <label class="label-base">{{ __('vehicle.field.purchase_evidence_type') }}</label>
                    <select wire:model="purchase_evidence_type" class="input-base">
                        <option value="">{{ __('vehicle.panel.select_placeholder') }}</option>
                        @foreach(\App\Models\Vehicle::KARABA_EVIDENCE_TYPES as $ev)
                        <option value="{{ $ev }}">{{ $ev }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="label-base">{{ __('vehicle.field.purchase_partner_type') }}</label>
                    <select wire:model="purchase_partner_type" class="input-base">
                        <option value="">{{ __('vehicle.panel.select_placeholder') }}</option>
                        @foreach(\App\Models\Vehicle::KARABA_PARTNER_TYPES as $pt)
                        <option value="{{ $pt }}">{{ $pt }}</option>
                        @endforeach
                    </select>
                </div>
                @endif
            </div>

            {{-- karaba(2026-07-12): 계좌란(예금주/은행/계좌번호 + 계좌메모) 전체 숨김 — 입금계좌 사진 첨부로 대체. --}}
            @unless($isKaraba)
            {{-- 큐 20-A/C — 매입처 계좌 4컬럼 (계좌번호 자동 암호화 + AuditLog 마스킹) --}}
            {{-- UX #5 (2026-05-20) — 은행명 datalist 자동완성 (13개) + 계좌번호 동적 mask ($store.koreanBanks) --}}
            <hr class="section-divider">
            <div class="section-header">
                <span class="section-dot bg-blue-400"></span>
                <span class="section-title">{{ __('vehicle.panel.sec.purchase_account') }}</span>
            </div>
            <div x-data class="grid grid-cols-2 gap-3 sm:grid-cols-2">
                <div>
                    <label class="label-base">{{ __('vehicle.field.bank_name') }} </label>
                    <input x-ref="bankInput" wire:model.blur="purchase_seller_bank" type="text" list="korean-banks-list"
                           class="input-base input-required" placeholder="{{ __('vehicle.ph.bank_name') }}" maxlength="100" autocomplete="off"
                           x-on:input="$refs.accountInput.value = $store.koreanBanks.applyMask($el.value, $refs.accountInput.value)" />
                    <datalist id="korean-banks-list">
                        <template x-for="bank in $store.koreanBanks.names()" :key="bank">
                            <option :value="bank"></option>
                        </template>
                    </datalist>
                </div>
                <div>
                    <label class="label-base">{{ __('vehicle.field.account_holder') }} </label>
                    <input wire:model="purchase_seller_holder" type="text" class="input-base input-required" placeholder="{{ __('vehicle.ph.account_holder') }}" maxlength="100" />
                </div>
                <div class="col-span-2">
                    <label class="label-base flex items-center gap-1">
                        {{ __('vehicle.field.account_no') }} <span class="text-[10px] font-normal text-violet-600">{{ __('vehicle.panel.encrypted_note') }}</span>
                        <span class="text-[10px] font-normal text-gray-400">{{ __('vehicle.panel.auto_hyphen') }}</span>
                    </label>
                    <input x-ref="accountInput" wire:model.blur="purchase_seller_account" type="text"
                           class="input-base input-required font-mono" placeholder="123-456-789012" autocomplete="off"
                           x-on:input="$el.value = $store.koreanBanks.applyMask($refs.bankInput.value, $el.value)" />
                </div>
                <div class="col-span-2">
                    <label class="label-base">{{ __('vehicle.field.account_memo') }}</label>
                    <textarea wire:model="purchase_bank_memo" class="input-base" rows="2" placeholder="{{ __('vehicle.ph.account_memo') }}"></textarea>
                </div>
            </div>

            {{-- 매도비 계좌 (매입가 계좌와 별도 주체, 2026-07-03) — 기존 매입처 계좌 UX 미러 --}}
            <hr class="section-divider">
            <div class="section-header">
                <span class="section-dot bg-blue-400"></span>
                <span class="section-title">{{ __('vehicle.panel.sec.fee_account') }}</span>
            </div>
            <div class="mb-2 text-[11px] text-gray-400">{{ __('vehicle.panel.fee_account_hint') }}</div>
            <div x-data class="grid grid-cols-2 gap-3 sm:grid-cols-2">
                <div>
                    <label class="label-base">{{ __('vehicle.field.bank_name') }}</label>
                    <input x-ref="feeBankInput" wire:model.blur="purchase_fee_bank" type="text" list="korean-banks-list"
                           class="input-base" placeholder="{{ __('vehicle.ph.bank_name') }}" maxlength="100" autocomplete="off"
                           x-on:input="$refs.feeAccountInput.value = $store.koreanBanks.applyMask($el.value, $refs.feeAccountInput.value)" />
                </div>
                <div>
                    <label class="label-base">{{ __('vehicle.field.account_holder') }}</label>
                    <input wire:model="purchase_fee_holder" type="text" class="input-base" placeholder="{{ __('vehicle.ph.account_holder') }}" maxlength="100" />
                </div>
                <div class="col-span-2">
                    <label class="label-base flex items-center gap-1">
                        {{ __('vehicle.field.account_no') }} <span class="text-[10px] font-normal text-violet-600">{{ __('vehicle.panel.encrypted_note') }}</span>
                        <span class="text-[10px] font-normal text-gray-400">{{ __('vehicle.panel.auto_hyphen') }}</span>
                    </label>
                    <input x-ref="feeAccountInput" wire:model.blur="purchase_fee_account" type="text"
                           class="input-base font-mono" placeholder="123-456-789012" autocomplete="off"
                           x-on:input="$el.value = $store.koreanBanks.applyMask($refs.feeBankInput.value, $el.value)" />
                </div>
            </div>
            @endunless

            <hr class="section-divider">
            <div class="section-header">
                <span class="section-dot bg-blue-300"></span>
                <span class="section-title">{{ __('vehicle.panel.sec.costs') }}</span>
            </div>

            {{-- 회의확장씬 #9 보강 안내 (2026-05-23) — 2차 정산 대기 동안 실측 정정 가이드 --}}
            @php
                $hasSecondaryPending = $editingId && \App\Models\Settlement::where('vehicle_id', $editingId)
                    ->where('settlement_status', 'paid')
                    ->where('secondary_status', 'pending')
                    ->exists();
                $canEditCostNow = auth()->user()?->isAdmin()
                    || in_array(auth()->user()?->role, ['재무', '관리'], true);
            @endphp
            @if($hasSecondaryPending && $canEditCostNow)
            <div class="mb-2 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                <div class="font-semibold">{{ __('vehicle.panel.secondary_pending_title') }}</div>
                <div class="mt-0.5 text-amber-700">
                    {{ __('vehicle.panel.secondary_pending_desc') }}
                </div>
            </div>
            @endif

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                <div><label class="label-base">{{ __('vehicle.field.cost_deregistration') }}</label><input wire:model="cost_deregistration_str" type="text" data-money class="input-base" placeholder="0" /></div>
                <div><label class="label-base">{{ __('vehicle.field.cost_license') }}</label><input wire:model="cost_license_str" type="text" data-money class="input-base" placeholder="0" /></div>
                <div><label class="label-base">{{ __('vehicle.field.cost_towing') }}</label><input wire:model="cost_towing_str" type="text" data-money class="input-base" placeholder="0" /></div>
                <div><label class="label-base">{{ __('vehicle.field.cost_carry') }}</label><input wire:model="cost_carry_str" type="text" data-money class="input-base" placeholder="0" /></div>
                <div><label class="label-base">{{ __('vehicle.field.cost_shoring') }}</label><input wire:model="cost_shoring_str" type="text" data-money class="input-base" placeholder="0" /></div>
                <div><label class="label-base">{{ __('vehicle.field.cost_insurance') }}</label><input wire:model="cost_insurance_str" type="text" data-money class="input-base" placeholder="0" /></div>
                <div><label class="label-base">{{ __('vehicle.field.cost_transfer') }}</label><input wire:model="cost_transfer_str" type="text" data-money class="input-base" placeholder="0" /></div>
                <div><label class="label-base">{{ __('vehicle.field.cost_extra1') }}</label><input wire:model="cost_extra1_str" type="text" data-money class="input-base" placeholder="0" /></div>
                <div><label class="label-base">{{ __('vehicle.field.cost_extra2') }}</label><input wire:model="cost_extra2_str" type="text" data-money class="input-base" placeholder="0" /></div>
            </div>

            <hr class="section-divider">
            <div class="section-header">
                <span class="section-dot bg-indigo-400"></span>
                <span class="section-title">{{ __('vehicle.panel.sec.purchase_payment') }}</span>
            </div>
            {{-- 큐 22-C 핵심 (2026-05-20) — 자금 영역 권한 분기. 영업은 read-only, 재무·admin 만 입력. SoD 회의록 정합. --}}
            @php $canConfirmFinance = auth()->user()?->canConfirmFinance() ?? false; @endphp
            @unless($canConfirmFinance)
            <div class="mb-2 rounded border border-blue-200 bg-blue-50 px-3 py-2 text-[11px] text-blue-800">
                {{ __('vehicle.panel.finance_area_desc') }}
            </div>
            @endunless
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                <div>
                    <label class="label-base">{{ __('vehicle.field.down_payment') }}</label>
                    <input wire:model="down_payment_str" type="text" data-money
                           class="input-base {{ $canConfirmFinance ? '' : 'bg-gray-100 text-gray-500' }}"
                           placeholder="0" @if(!$canConfirmFinance) disabled @endif />
                </div>
                <div>
                    <label class="label-base">{{ __('vehicle.field.selling_fee_payment') }}</label>
                    <input wire:model="selling_fee_payment_str" type="text" data-money
                           class="input-base {{ $canConfirmFinance ? '' : 'bg-gray-100 text-gray-500' }}"
                           placeholder="0" @if(!$canConfirmFinance) disabled @endif />
                </div>
            </div>
            {{-- 잔금 N건 --}}
            <div class="mt-3 space-y-2">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-gray-500">{{ __('vehicle.field.balance') }}</span>
                    @if($canConfirmFinance)
                    <button type="button" wire:click="addPurchasePayment" class="text-xs text-violet-600 hover:underline">{{ __('vehicle.panel.add') }}</button>
                    @else
                    <span class="text-[10px] text-gray-400">{{ __('vehicle.panel.finance_only_add') }}</span>
                    @endif
                </div>
                @foreach($purchaseBalancePayments as $idx => $row)
                @php
                    // 큐 20-C — confirmed_at 유무로 분자 A안 ledger 반영 상태 시각화
                    $isPbpConfirmed = !empty($row['confirmed_at']);
                    $pbpRowBg = $isPbpConfirmed ? 'bg-emerald-50/40 border-emerald-200' : (!empty($row['id']) ? 'bg-amber-50/40 border-amber-200' : 'border-transparent');
                @endphp
                <div class="flex gap-2 items-center rounded border px-2 py-1 {{ $pbpRowBg }}">
                    <input wire:model="purchaseBalancePayments.{{ $idx }}.amount" type="text" data-money
                           class="input-base {{ $canConfirmFinance ? '' : 'bg-gray-100 text-gray-500' }}"
                           style="width: 96px; flex: none;"
                           placeholder="{{ __('vehicle.ph.amount_won') }}" @if(!$canConfirmFinance) disabled @endif />
                    <input wire:model="purchaseBalancePayments.{{ $idx }}.payment_date" type="text" data-date
                           class="input-base {{ $canConfirmFinance ? '' : 'bg-gray-100 text-gray-500' }}"
                           style="width: 112px; flex: none;"
                           @if(!$canConfirmFinance) disabled @endif />
                    <input wire:model="purchaseBalancePayments.{{ $idx }}.note" type="text"
                           class="input-base flex-1 {{ $canConfirmFinance ? '' : 'bg-gray-100 text-gray-500' }}"
                           style="min-width: 0;"
                           placeholder="{{ __('vehicle.ph.note') }}" @if(!$canConfirmFinance) disabled @endif />
                    @if(!empty($row['id']))
                        @if($isPbpConfirmed)
                        <span class="text-[10px] font-semibold text-emerald-700 whitespace-nowrap"
                              title="{{ __('vehicle.panel.confirmed_title', ['at' => $row['confirmed_at'], 'by' => $row['finance_confirmer'] ?? '?']) }}">
                            {{ __('vehicle.panel.confirmed') }}
                        </span>
                        @else
                        <span class="text-[10px] font-semibold text-amber-700 whitespace-nowrap"
                              title="{{ __('vehicle.panel.pending_title') }}">
                            {{ __('vehicle.panel.pending') }}
                        </span>
                        @endif
                    @endif
                    @if($canConfirmFinance)
                    <button type="button" wire:click="removePurchasePayment({{ $idx }})" class="text-red-400 hover:text-red-600">×</button>
                    @endif
                </div>
                @endforeach
            </div>

            {{-- 매입 미지급 요약 (판매탭 미러링, 저장 후 반영 스냅샷) — 매입가·매도비 입금 시 차감 --}}
            <div class="mt-3 rounded-lg border border-indigo-100 bg-indigo-50/40 p-3">
                <div class="mb-1.5 flex items-center gap-2">
                    <span class="text-xs font-semibold text-indigo-700">{{ __('vehicle.panel.purchase_unpaid_summary') }}</span>
                    <span class="text-[10px] text-gray-400">{{ __('vehicle.panel.after_save_note') }}</span>
                </div>
                @if($panelPurchaseTotal === null)
                    <div class="text-sm text-gray-400">—</div>
                @else
                    <div class="space-y-1 text-sm">
                        @if($isKaraba)
                        {{-- karaba(2026-07-12): 항목별 완납 표시 — 구입금액·매도비 각각 owed vs paid. --}}
                        @foreach([
                            ['label' => __('vehicle.panel.item_purchase_price'), 'owed' => $panelPurchasePriceTotal, 'paid' => $panelPurchasePricePaid],
                            ['label' => __('vehicle.panel.item_selling_fee'),    'owed' => $panelSellingFeeTotal,    'paid' => $panelSellingFeePaid],
                        ] as $item)
                        <div class="flex justify-between">
                            <span class="text-gray-600">{{ $item['label'] }}
                                <span class="text-[10px] text-gray-400">₩{{ number_format($item['owed']) }} · {{ __('vehicle.panel.item_paid') }} ₩{{ number_format($item['paid']) }}</span>
                            </span>
                            @if($item['paid'] >= $item['owed'])
                            <span class="font-medium text-emerald-700">{{ __('vehicle.panel.fully_paid') }}</span>
                            @else
                            <span class="font-medium text-amber-800">{{ __('vehicle.panel.item_unpaid') }} ₩{{ number_format($item['owed'] - $item['paid']) }}</span>
                            @endif
                        </div>
                        @endforeach
                        @else
                        <div class="flex justify-between text-gray-600">
                            <span>{{ __('vehicle.panel.purchase_total') }} <span class="text-[10px] text-gray-400">{{ __('vehicle.panel.purchase_total_sub') }}</span></span>
                            <span>₩{{ number_format($panelPurchaseTotal) }}</span>
                        </div>
                        <div class="flex justify-between text-gray-600">
                            <span>{{ __('vehicle.panel.purchase_paid') }} <span class="text-[10px] text-gray-400">{{ __('vehicle.panel.purchase_paid_sub') }}</span></span>
                            <span>₩{{ number_format($panelPurchasePaid) }}</span>
                        </div>
                        @endif
                        <hr class="border-indigo-100" />
                        <div class="flex justify-between font-semibold">
                            <span class="text-gray-700">{{ __('vehicle.panel.purchase_unpaid') }}</span>
                            @if($panelPurchaseUnpaid > 0)
                            <span class="text-amber-800">₩{{ number_format($panelPurchaseUnpaid) }}</span>
                            @elseif($panelPurchaseUnpaid < 0)
                            <span class="text-red-600">+₩{{ number_format(abs($panelPurchaseUnpaid)) }} · {{ __('vehicle.panel.overpaid') }}</span>
                            @else
                            <span class="text-emerald-700">₩0 · {{ __('vehicle.panel.fully_paid') }}</span>
                            @endif
                        </div>
                        {{-- 매입 과입금 인라인 정정 (오기입 정정 — 재무 권한 · 사유 필수). jin 2026-07-10. 판매와 달리 적립금 아님. --}}
                        @if($panelPurchaseUnpaid !== null && $panelPurchaseUnpaid < 0 && auth()->user()?->canConfirmFinance())
                        <div class="mt-2 rounded border border-red-200 bg-red-50 p-2">
                            <div class="mb-1 text-[11px] text-red-700">{{ __('vehicle.panel.overpay_correct_hint') }}</div>
                            <div class="flex gap-2">
                                <input type="text" wire:model="purchaseOverpayReason" class="input-base flex-1 text-xs" placeholder="{{ __('vehicle.panel.overpay_reason_ph') }}" />
                                <button type="button" wire:click="correctPurchaseOverpay"
                                        class="whitespace-nowrap rounded bg-red-600 px-2 py-1 text-xs font-medium text-white hover:bg-red-700">
                                    {{ __('vehicle.panel.overpay_correct') }}
                                </button>
                            </div>
                        </div>
                        @endif
                    </div>
                @endif
            </div>

            <hr class="section-divider">
            <div class="grid grid-cols-2 gap-3">
                {{-- 2026-05-19 풀회의 안건 C — 말소 [everyone]. 재무 role 제외 (canHandleDeregistration). --}}
                @if(auth()->user()->canHandleDeregistration())
                <div>
                    <label class="label-base">{{ __('vehicle.field.deregistered') }}</label>
                    <label class="flex items-center gap-2 text-sm cursor-pointer mt-1">
                        <input wire:model="is_deregistered" type="checkbox" class="rounded" /> {{ __('vehicle.field.deregistered') }}
                    </label>
                </div>
                <div>
                    <label class="label-base">{{ __('vehicle.field.derg_doc') }}</label>
                    <input wire:model="deregistrationDocFile" type="file" accept=".jpg,.jpeg,.png,.gif,.webp,.bmp,.pdf,.xlsx,.xls,.csv,.docx,.doc,.hwp,.hwpx,.pptx,.ppt,.txt,.zip"
                           class="block w-full text-xs text-gray-500 file:mr-2 file:rounded file:border-0 file:bg-violet-50 file:px-2 file:py-1 file:text-xs file:text-violet-700" />
                    <div wire:loading wire:target="deregistrationDocFile" class="mt-1 text-xs text-gray-400">{{ __('vehicle.panel.uploading') }}</div>
                    @if($deregistrationDocFile)
                    <p class="mt-1 break-all text-xs text-gray-700">📄 {{ $deregistrationDocFile->getClientOriginalName() }} <span class="text-gray-400">{{ __('vehicle.panel.before_save') }}</span></p>
                    @elseif($deregistration_document_path)
                    <div class="mt-1 flex items-center gap-3">
                        <a href="{{ \App\Support\VehicleDocUrl::for($deregistration_document_path) }}" target="_blank"
                           class="break-all text-xs text-violet-600 hover:underline">📄 {{ basename($deregistration_document_path) }}</a>
                        <button type="button" wire:click="removeDeregistrationDoc"
                                class="text-xs text-red-500 hover:underline">{{ __('vehicle.delete') }}</button>
                    </div>
                    @endif
                </div>
                {{-- 국내 딜러 말소등록증 알림톡 전달 (수동) — 차량등록 화면 포함 항상 노출(번호 미리 입력, jin 2026-07-14).
                     발송은 저장된 말소증 링크가 필요해 최초 저장 후 가능. 미저장 클릭 시 '저장 먼저' 토스트로 안내. --}}
                <div class="col-span-2">
                    <div class="rounded-md border border-yellow-100 bg-yellow-50 px-3 py-2.5">
                        <div class="text-xs font-semibold text-yellow-800">{{ __('vehicle.deregnotice.label') }}</div>
                        <p class="mt-0.5 text-[11px] text-yellow-700">{{ __('vehicle.deregnotice.hint') }}</p>
                        <div class="mt-2 flex gap-2">
                            <input wire:model.blur="deregistrationBuyerPhone" data-phone type="tel" class="input-base text-sm" placeholder="010-0000-0000" autocomplete="off" />
                            <button type="button" wire:click="sendDeregistrationAlimtalk" class="btn-primary shrink-0 whitespace-nowrap">{{ __('vehicle.deregnotice.send_btn') }}</button>
                        </div>
                    </div>
                </div>
                @endif
                {{-- karaba(2026-07-12): 메모 3→1 — 계좌메모·송금메모 숨김. 맨 아래 '메모(공통·내부메모)'만 남김. --}}
                @unless($isKaraba)
                <div class="col-span-2">
                    <label class="label-base">{{ __('vehicle.field.remittance_memo') }}</label>
                    <textarea wire:model="purchase_remittance_memo" class="input-base" rows="2"></textarea>
                </div>
                @endunless
                <div>
                    <label class="label-base">{{ __('vehicle.field.registration_number') }}</label>
                    <input type="text" wire:model="registration_number" class="input-base" placeholder="{{ __('vehicle.field.registration_number_ph') }}" />
                    <p class="mt-1 text-xs text-gray-400">{{ __('vehicle.field.registration_number_hint') }}</p>
                </div>
                <div>
                    <label class="label-base">{{ __('vehicle.field.deregistration_date') }}</label>
                    <input type="text" data-date wire:model="deregistration_date" class="input-base" />
                    <p class="mt-1 text-xs text-gray-400">{{ __('vehicle.field.deregistration_date_hint') }}</p>
                </div>
            </div>
        </div>

        {{-- ─── 판매 탭 ──────────────────────────────────── --}}
        <div x-show="tab === 'sale'" x-cloak>
            <div class="section-header">
                <span class="section-dot bg-purple-500"></span>
                <span class="section-title">{{ __('vehicle.panel.sec.sale_basic') }}</span>
            </div>

            {{-- 2026-07-09 — 옛 G2 미수 잔존 안내 배너 제거(ERP 죽은 락). 미수 매입 게이트(②)가 담당. --}}

            {{-- UX #1 (2026-05-20) — 판매 필수 입력란 노랑 배경 (KRW 환율은 자동 1 normalize 라 강조 X). --}}
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                <div><label class="label-base">{{ __('vehicle.field.sale_date') }} </label><input wire:model="sale_date" type="text" data-date class="input-base input-required" />@error('sale_date')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror</div>
                <div>
                    <label class="label-base">{{ __('vehicle.field.currency') }} </label>
                    <select wire:model.live="currency" class="input-base input-required">
                        @foreach(['USD','JPY','EUR','GBP','CNY','KRW'] as $cur)
                        <option value="{{ $cur }}">{{ $cur }}</option>
                        @endforeach
                    </select>
                </div>
                <div><label class="label-base">{{ __('vehicle.field.exchange_rate') }} @if($currency !== 'KRW')  @endif</label><input wire:model="exchange_rate_str" type="text" class="input-base {{ $currency !== 'KRW' ? 'input-required' : '' }}" placeholder="1350" />@error('exchange_rate_str')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror</div>
                <div>
                    <div class="flex items-center justify-between">
                        <label class="label-base">{{ __('vehicle.field.buyer') }} </label>
                        <button type="button" wire:click="openQuickAdd('buyer','sale')"
                                class="mb-1 text-[11px] text-primary-text hover:underline">{{ __('vehicle.panel.add_new') }}</button>
                    </div>
                    <x-erp.combobox model="buyer_id_str" :options="$this->buyers" :selected="$buyer_id_str"
                        :required="true" placeholder="{{ __('vehicle.panel.select_placeholder') }}"
                        wire:key="cbx-buyer-sale-{{ $buyer_id_str }}" />
                    @error('buyer_id_str')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
                </div>
                {{-- 당사자 축소 (jin 2026-07-09) — 판매=바이어만. 컨사이니는 선적 탭에서 입력. --}}
                <div><label class="label-base">{{ __('vehicle.field.sale_price') }} </label><input wire:model="sale_price_str" type="text" data-money class="input-base input-required" placeholder="0" /></div>
                <div><label class="label-base">TAX D/C</label><input wire:model="tax_dc_str" type="text" data-money class="input-base" placeholder="0" /></div>
                <div><label class="label-base">Commission</label><input wire:model="commission_str" type="text" data-money class="input-base" placeholder="0" /></div>
                <div><label class="label-base">{{ __('vehicle.field.transport_fee') }}</label><input wire:model="transport_fee_str" type="text" data-money class="input-base" placeholder="0" /></div>
                <div><label class="label-base">{{ __('vehicle.field.auto_loading') }}</label><input wire:model="auto_loading_str" type="text" data-money class="input-base" placeholder="0" /></div>
                <div><label class="label-base">{{ __('vehicle.field.sale_other_costs') }}</label><input wire:model="sale_other_costs_str" type="text" data-money class="input-base" placeholder="0" /></div>
                <div>
                    <label class="label-base">{{ __('vehicle.field.sale_total_display') }} <span class="text-[10px] text-gray-400">{{ __('vehicle.panel.after_save_note') }}</span></label>
                    @if($panelSaleTotal === null)
                        <div class="input-base bg-gray-50 text-gray-400">—</div>
                    @else
                        <div class="input-base bg-purple-50 font-medium text-purple-800">{{ $currency }} {{ number_format($panelSaleTotal) }}</div>
                    @endif
                </div>
            </div>

            <hr class="section-divider">
            <div class="section-header">
                <span class="section-dot bg-purple-300"></span>
                <span class="section-title">{{ __('vehicle.panel.sec.payment_status') }}</span>
            </div>
            {{-- 22-A-3a 사용자 정정 (2026-05-20) — 4 항목 (계약금/중도금/선수금) 입력 권한: 재무·관리·admin. 영업·수출통관은 disabled. --}}
            @php $canManagePayBreakdown = auth()->user()?->canManagePaymentBreakdown() ?? false; @endphp
            <div class="mb-2 rounded border border-blue-200 bg-blue-50 px-3 py-2 text-[11px] text-blue-800">
                {{ __('vehicle.panel.breakdown_banner') }}
            </div>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                {{-- 계약금·중도금·선수금1 = 판매탭 신규입력 중단 (jin 2026-07-06 확정 — 잔금으로 일원화). --}}
                {{-- 레거시(과거 입력) 값만 read-only 표시. 미수계산은 §13 타입무관 그대로 반영. props 불변이라 저장 시 no-op. --}}
                @php
                    $legacyPay = array_filter([
                        __('vehicle.field.deposit_down') => (int) str_replace(',', '', (string) ($deposit_down_payment_str ?: '0')),
                        __('vehicle.field.interim') => (int) str_replace(',', '', (string) ($interim_payment_str ?: '0')),
                        __('vehicle.field.advance1') => (int) str_replace(',', '', (string) ($advance_payment1_str ?: '0')),
                    ], fn ($v) => $v > 0);
                @endphp
                @if(!empty($legacyPay))
                <div class="col-span-2 rounded border border-gray-200 bg-gray-50 px-3 py-2 sm:col-span-3">
                    <div class="mb-1 text-[10px] font-semibold uppercase text-gray-400">{{ __('vehicle.panel.legacy_breakdown') }}</div>
                    <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-600">
                        @foreach($legacyPay as $label => $amt)
                            <span>{{ $label }} <b class="text-gray-800">{{ number_format($amt) }}</b></span>
                        @endforeach
                    </div>
                </div>
                @endif
                <div>
                    <label class="label-base">{{ __('vehicle.field.fee') }} <span class="text-xs text-gray-400">{{ __('vehicle.panel.fee_note') }}</span></label>
                    <input wire:model="fee_str" type="text" data-money
                           class="input-base {{ $canManagePayBreakdown ? '' : 'bg-gray-100 text-gray-500' }}"
                           placeholder="0" @if(!$canManagePayBreakdown) disabled @endif />
                </div>
                <div><label class="label-base">{{ __('vehicle.field.savings_used') }}</label><input wire:model="savings_used_str" type="text" data-money class="input-base" placeholder="0" /></div>
                {{-- 회의확장씬 #12 (2026-05-22) — 적립금 적립 입력 + 누적 표시 --}}
                @php $canConfirmFinanceLocal = auth()->user()?->canConfirmFinance() ?? false; @endphp
                <div>
                    <label class="label-base">{{ __('vehicle.field.savings_deposit') }} <span class="text-[10px] text-gray-400">{{ __('vehicle.panel.savings_deposit_note') }}</span></label>
                    <input wire:model="savings_deposit_str" type="text" data-money
                           class="input-base {{ $canConfirmFinanceLocal ? '' : 'bg-gray-100 text-gray-500' }}"
                           placeholder="0" @if(!$canConfirmFinanceLocal) disabled @endif />
                </div>
                <div>
                    <label class="label-base">{{ __('vehicle.field.savings_balance') }} <span class="text-[10px] text-gray-400">{{ __('vehicle.panel.savings_balance_note') }}</span></label>
                    <div class="input-base bg-gray-50 text-gray-700">
                        @if($this->buyerSavingsBalance === null)
                            —
                        @else
                            {{ $currency }} {{ number_format($this->buyerSavingsBalance) }}
                        @endif
                    </div>
                </div>
            </div>

            {{-- 판매 미입금 요약 (매입 미지급 요약과 동일 스타일, 저장 후 반영 스냅샷) — 미입금률·잔여잔금 통합 --}}
            <div class="mt-3 rounded-lg border border-purple-100 bg-purple-50/40 p-3">
                <div class="mb-1.5 flex items-center gap-2">
                    <span class="text-xs font-semibold text-purple-700">{{ __('vehicle.panel.sale_unpaid_summary') }}</span>
                    <span class="text-[10px] text-gray-400">{{ __('vehicle.panel.after_save_note') }}</span>
                </div>
                @if($panelSaleTotal === null)
                    <div class="text-sm text-gray-400">—</div>
                @else
                @php $salePaid = (float) $panelSaleTotal - (float) $panelSaleUnpaid; @endphp
                    <div class="space-y-1 text-sm">
                        <div class="flex justify-between text-gray-600">
                            <span>{{ __('vehicle.panel.sale_total') }} <span class="text-[10px] text-gray-400">{{ __('vehicle.panel.sale_total_sub') }}</span></span>
                            <span>{{ $currency }} {{ number_format($panelSaleTotal) }}</span>
                        </div>
                        <div class="flex justify-between text-gray-600">
                            <span>{{ __('vehicle.panel.sale_paid') }} <span class="text-[10px] text-gray-400">{{ __('vehicle.panel.sale_paid_sub') }}</span></span>
                            <span>{{ $currency }} {{ number_format($salePaid) }}</span>
                        </div>
                        <hr class="border-purple-100" />
                        <div class="flex justify-between font-semibold">
                            <span class="text-gray-700">{{ __('vehicle.panel.sale_unpaid') }}
                                @if($panelUnpaidRatio !== null && $panelUnpaidRatio > 0)
                                <span class="text-[10px] font-normal text-gray-400">({{ number_format($panelUnpaidRatio * 100, 1) }}%)</span>
                                @endif
                            </span>
                            @if($panelSaleUnpaid > 0)
                            <span class="text-amber-800">{{ $currency }} {{ number_format($panelSaleUnpaid) }}</span>
                            @elseif($panelSaleUnpaid <= -1)
                            {{-- 음수 epsilon 대칭: 외화 반올림 잔차(-1<x<0)는 완납으로, ≤-1 만 과입금 플래그(거짓 과입금 방지). --}}
                            <span class="text-red-600">+{{ $currency }} {{ number_format(abs($panelSaleUnpaid)) }} · {{ __('vehicle.panel.overpaid') }}</span>
                            @else
                            <span class="text-emerald-700">{{ $currency }} 0 · {{ __('vehicle.panel.fully_paid') }}</span>
                            @endif
                        </div>
                        {{-- 판매 과입금 = 플래그만. 정정은 채권관리 「적립금 전환」(convertOverpayToSavings). jin 2026-07-10. --}}
                        @if($panelSaleUnpaid !== null && $panelSaleUnpaid <= -1)
                        <div class="mt-1 text-right text-[10px] text-gray-400">{{ __('vehicle.panel.sale_overpay_hint') }}</div>
                        @endif
                    </div>
                @endif
            </div>
            {{-- 잔금 N건 --}}
            <div class="mt-3 space-y-2">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-gray-500">{{ __('vehicle.field.balance') }}</span>
                    <button type="button" wire:click="addFinalPayment" class="text-xs text-violet-600 hover:underline">{{ __('vehicle.panel.add') }}</button>
                </div>
                @foreach($finalPayments as $idx => $row)
                @if(!empty($row['transfer']))
                {{-- 큐 19-C — 차량 간 자금 이체로 자동 생성된 잔금 (append-only). --}}
                @php
                    $tStatus = $row['transfer']['status'] ?? null;
                    $isVoided = $tStatus === \App\Models\InterVehicleTransfer::STATUS_VOIDED;
                    $pendingVoid = !empty($row['transfer']['pending_void']);
                    // 박스 배경: voided 회색 / pending_void amber / 일반 violet
                    $boxClass = $isVoided
                        ? 'bg-gray-100 border-gray-300'
                        : ($pendingVoid ? 'bg-amber-50 border-amber-300' : 'bg-violet-50 border-violet-200');
                    $textMutedClass = $isVoided ? 'text-gray-500' : ($pendingVoid ? 'text-amber-800' : 'text-violet-800');
                    $textMetaClass = $isVoided ? 'text-gray-400' : ($pendingVoid ? 'text-amber-600' : 'text-violet-600');
                @endphp
                <div class="flex gap-2 items-center rounded {{ $boxClass }} px-2 py-1.5 border">
                    <span class="text-xs">{{ $isVoided ? '⊘' : ($pendingVoid ? '⏳' : '🔁') }}</span>
                    <span class="w-24 text-sm font-semibold {{ $isVoided ? 'text-gray-500 line-through' : ($row['transfer']['direction'] === 'outgoing' ? 'text-red-600' : 'text-emerald-700') }}">
                        {{ number_format((float)$row['amount']) }} {{ $row['transfer']['currency'] }}
                    </span>
                    <span class="flex-1 text-xs {{ $textMutedClass }}">
                        @php $cpNum = $row['transfer']['counterpart_number'] ?? '#'.$row['transfer']['counterpart_id']; @endphp
                        @if($row['transfer']['direction'] === 'outgoing')
                            {!! __('vehicle.panel.transfer_out', ['number' => '<span class="font-mono">'.e($cpNum).'</span>']) !!}
                        @else
                            {!! __('vehicle.panel.transfer_in', ['number' => '<span class="font-mono">'.e($cpNum).'</span>']) !!}
                        @endif
                        @if($isVoided)
                            <span class="ml-1 text-[10px] text-gray-500">{{ __('vehicle.panel.transfer_voided') }}</span>
                        @elseif($pendingVoid)
                            <span class="ml-1 text-[10px] font-semibold text-amber-700">{{ __('vehicle.panel.transfer_pending_void') }}</span>
                        @endif
                    </span>
                    <span class="text-xs {{ $textMetaClass }} whitespace-nowrap">
                        {{ $row['payment_date'] ?: '-' }}
                        · 승인 #{{ $row['transfer']['approval_request_id'] }}
                    </span>
                    @if($pendingVoid)
                    <span class="text-[11px] text-amber-600 whitespace-nowrap">{{ __('vehicle.panel.void_requesting') }}</span>
                    @elseif(!$isVoided && !empty($row['transfer']['can_void']))
                    <button type="button" wire:click="openTransferVoidModal({{ $row['transfer']['id'] }})"
                            class="text-[11px] text-red-500 hover:underline whitespace-nowrap">
                        {{ __('vehicle.panel.void_request_btn') }}
                    </button>
                    @endif
                </div>
                @elseif(!empty($row['locked']))
                <div class="flex gap-2 items-center rounded bg-gray-50 px-2 py-1.5 border border-gray-200">
                    <span class="text-xs text-gray-400">🔒</span>
                    <span class="w-24 text-sm text-gray-600">{{ number_format((float)$row['amount']) }}</span>
                    <span class="w-28 text-sm text-gray-600">{{ $row['payment_date'] ?: '-' }}</span>
                    <span class="flex-1 min-w-0 text-xs text-gray-400 truncate" title="{{ $row['note'] ?: '' }}">{{ $row['note'] ?: '' }}</span>
                    <a href="{{ route('erp.receivables.index', ['openVehicle' => $this->editingId]) }}" wire:navigate
                       class="text-xs text-violet-500 hover:underline whitespace-nowrap">{{ __('vehicle.panel.edit_in_receivables') }}</a>
                </div>
                @else
                @php
                    // 큐 20-C — confirmed_at 유무로 분자 A안 ledger 반영 상태 시각화
                    $isConfirmed = !empty($row['confirmed_at']);
                    $rowBg = $isConfirmed ? 'bg-emerald-50/40 border-emerald-200' : ($row['id'] ? 'bg-amber-50/40 border-amber-200' : 'border-transparent');
                @endphp
                <div class="flex gap-2 items-center rounded border px-2 py-1 {{ $rowBg }}">
                    {{-- 순서: 날짜 / 환율 / 금액 / 환율변환금액 / 비고 (jin 2026-07-13). --}}
                    {{-- 날짜 = wire:model.live → 그 날짜 마감환율 자동기입(updatedFinalPayments, 미확정·외화만). --}}
                    <input wire:model.live="finalPayments.{{ $idx }}.payment_date" type="text" data-date class="input-base"
                           style="width: 112px; flex: none;" />
                    {{-- 환율 (외화만, 자동 기입 + 수정 가능) --}}
                    @if($currency !== 'KRW')
                    <input wire:model="finalPayments.{{ $idx }}.exchange_rate" type="text" class="input-base"
                           style="width: 80px; flex: none;" placeholder="{{ __('vehicle.ph.rate') }}" title="{{ __('vehicle.panel.rate_at_payment') }}" />
                    @endif
                    {{-- 금액 --}}
                    <input wire:model="finalPayments.{{ $idx }}.amount" type="text" data-money class="input-base"
                           style="width: 96px; flex: none;" placeholder="{{ __('vehicle.ph.amount') }}" />
                    {{-- 환율변환금액 (외화만, readonly — FinalPayment::saving 훅이 DB amount_krw 저장) --}}
                    @if($currency !== 'KRW')
                    @php
                        $rowAmt = (float) str_replace(',', '', $row['amount'] ?? '0');
                        $rowRate = (float) str_replace(',', '', $row['exchange_rate'] ?? '0');
                        $rowKrw = $rowAmt * $rowRate;
                    @endphp
                    <input type="text" class="input-base text-right"
                           style="width: 130px; flex: none; background-color: #f9fafb; color: #4b5563;"
                           readonly tabindex="-1"
                           value="{{ $rowAmt > 0 && $rowRate > 0 ? '₩'.number_format($rowKrw) : '' }}"
                           placeholder="₩0" title="{{ __('vehicle.panel.krw_converted') }}" />
                    @endif
                    {{-- 비고 --}}
                    <input wire:model="finalPayments.{{ $idx }}.note" type="text" class="input-base flex-1"
                           style="min-width: 0;" placeholder="{{ __('vehicle.ph.note') }}" />
                    @if($row['id'])
                        @if($isConfirmed)
                        <span class="text-[10px] font-semibold text-emerald-700 whitespace-nowrap"
                              title="{{ __('vehicle.panel.confirmed_title', ['at' => $row['confirmed_at'], 'by' => $row['finance_confirmer'] ?? '?']) }}">
                            {{ __('vehicle.panel.confirmed') }}
                        </span>
                        @else
                        <span class="text-[10px] font-semibold text-amber-700 whitespace-nowrap"
                              title="{{ __('vehicle.panel.pending_title') }}">
                            {{ __('vehicle.panel.pending') }}
                        </span>
                        @endif
                    @endif
                    <button type="button" wire:click="removeFinalPayment({{ $idx }})" class="text-red-400 hover:text-red-600">×</button>
                </div>
                @endif
                @endforeach
            </div>

            {{-- 큐 16 — 카풀/헤이맨 계산서 섹션 제거 (5컬럼 drop과 동기). --}}

            {{-- 큐 19-C — 차량 간 자금 이체 (회의록 v5 §13) ──────────────── --}}
            @php $transferCtx = $this->transferContext; @endphp
            @if($editingId !== null)
            <div class="mt-5">
                <div class="section-header">
                    <span class="section-dot bg-violet-500"></span>
                    <span class="section-title">{{ __('vehicle.transfer.section') }}</span>
                </div>

                {{-- pending 자금 이체 요청 — amber 박스 (관리 승인 대기 중) --}}
                @if(!empty($transferCtx['pending']))
                <div class="rounded-md border border-amber-300 bg-amber-50 p-3 text-xs text-amber-900">
                    <div class="flex items-center gap-2">
                        <span>⏳</span>
                        <strong>{{ __('vehicle.transfer.pending_title') }}</strong>
                    </div>
                    <div class="mt-1 space-y-0.5 text-amber-800">
                        <div>
                            {{ __('vehicle.transfer.target_vehicle') }} <span class="font-mono">{{ $transferCtx['pending']['target_vehicle_number'] }}</span>
                            · {{ __('vehicle.transfer.amount') }} <strong>{{ number_format($transferCtx['pending']['amount']) }}</strong> {{ $transferCtx['pending']['currency'] }}
                        </div>
                        <div class="text-[11px] text-amber-700">
                            {{ __('vehicle.transfer.req_date') }} {{ $transferCtx['pending']['created_at']?->format('Y-m-d H:i') }}
                            · {{ __('vehicle.transfer.req_no') }} #{{ $transferCtx['pending']['approval_request_id'] }}
                        </div>
                        @if($transferCtx['pending']['reason'])
                        <div class="text-[11px] text-amber-700">{{ __('vehicle.transfer.reason') }}: "{{ $transferCtx['pending']['reason'] }}"</div>
                        @endif
                    </div>
                </div>
                @else
                    {{-- 최근 결정 상태 표시 (pending 없을 때만) — 큐 19-F 5상태 분기.
                         status=approved 인 경우 transfer.status 에 따라 박스 색·라벨 달라짐:
                           approved_awaiting_finance → 파랑 (관리 승인 — 재무 처리 대기)
                           executed                  → 에메랄드 (이체 완료, 재무 확정)
                           voided_awaiting_finance   → 앰버 (취소 승인 — 재무 처리 대기)
                           voided                    → 회색 (이체 취소됨) --}}
                    @if(!empty($transferCtx['lastDecided']))
                    @php
                        $ld = $transferCtx['lastDecided'];
                        $isVoid = ($ld['type'] ?? 'transfer') === 'void';
                        if ($isVoid) {
                            $ldKey = 'void:'.$ld['status'];
                            // 큐 19-L — void approved + finance 거부 케이스
                            if ($ld['status'] === 'approved' && ! empty($ld['void_finance_rejected_at'])) {
                                $ldKey = 'void:finance_rejected';
                            }
                        } else {
                            $ldKey = $ld['status'];
                            if ($ld['status'] === 'approved' && ! empty($ld['transfer_status'])) {
                                $ldKey = 'approved:'.$ld['transfer_status'];
                            }
                        }
                        $ts = fn (string $k) => __('vehicle.transfer.s.'.$k);
                        $tm = fn (string $k) => __('vehicle.transfer.memo.'.$k);
                        $ldClass = match($ldKey) {
                            'approved:approved_awaiting_finance' => ['border-blue-200', 'bg-blue-50', 'text-blue-900', 'text-blue-800', 'text-blue-700', 'border-blue-100', '⏳', $ts('approved_awaiting_finance'), $tm('approval')],
                            'approved:executed' => ['border-emerald-200', 'bg-emerald-50', 'text-emerald-900', 'text-emerald-800', 'text-emerald-700', 'border-emerald-100', '✓', $ts('executed'), $tm('approval')],
                            'approved:voided_awaiting_finance' => ['border-amber-200', 'bg-amber-50', 'text-amber-900', 'text-amber-800', 'text-amber-700', 'border-amber-100', '⏳', $ts('voided_awaiting_finance'), $tm('approval')],
                            'approved:voided' => ['border-gray-300', 'bg-gray-50', 'text-gray-700', 'text-gray-600', 'text-gray-500', 'border-gray-200', '⊘', $ts('voided'), $tm('approval')],
                            // 큐 19-K — 재무 정방향 거부 (관리는 승인했지만 재무가 송금 불가 사유로 거부)
                            'approved:finance_rejected' => ['border-red-200', 'bg-red-50', 'text-red-900', 'text-red-800', 'text-red-700', 'border-red-100', '❌', $ts('finance_rejected'), $tm('approval')],
                            'approved' => ['border-emerald-200', 'bg-emerald-50', 'text-emerald-900', 'text-emerald-800', 'text-emerald-700', 'border-emerald-100', '✓', $ts('approved'), $tm('approval')],
                            'rejected'  => ['border-red-200', 'bg-red-50', 'text-red-900', 'text-red-800', 'text-red-700', 'border-red-100', '❌', $ts('rejected'), $tm('reject')],
                            'cancelled' => ['border-gray-300', 'bg-gray-50', 'text-gray-700', 'text-gray-600', 'text-gray-500', 'border-gray-200', '⊘', $ts('cancelled'), $tm('note')],
                            'void:rejected'  => ['border-red-200', 'bg-red-50', 'text-red-900', 'text-red-800', 'text-red-700', 'border-red-100', '❌', $ts('void_rejected'), $tm('reject')],
                            'void:cancelled' => ['border-gray-300', 'bg-gray-50', 'text-gray-700', 'text-gray-600', 'text-gray-500', 'border-gray-200', '⊘', $ts('void_cancelled'), $tm('note')],
                            // 큐 19-L — void 재무 거부: 관리는 승인했지만 재무가 환불 불가로 거부 → transfer 살아있음
                            'void:finance_rejected' => ['border-red-200', 'bg-red-50', 'text-red-900', 'text-red-800', 'text-red-700', 'border-red-100', '❌', $ts('void_finance_rejected'), $tm('approval')],
                            default     => ['border-gray-300', 'bg-gray-50', 'text-gray-700', 'text-gray-600', 'text-gray-500', 'border-gray-200', 'ℹ', $ts('default'), $tm('note')],
                        };
                    @endphp
                    <div class="rounded-md border {{ $ldClass[0] }} {{ $ldClass[1] }} p-3 text-xs {{ $ldClass[2] }} mb-2">
                        <div class="flex items-center gap-2">
                            <span>{{ $ldClass[6] }}</span>
                            <strong>{{ $ldClass[7] }}</strong>
                        </div>
                        <div class="mt-1 space-y-0.5 {{ $ldClass[3] }}">
                            @if($isVoid)
                                <div>
                                    {{ __('vehicle.transfer.transfer_no') }} #{{ $ld['transfer_id'] }}
                                    · {{ number_format($ld['amount']) }} {{ $ld['currency'] }}
                                    · {{ $ld['approver_name'] ?? __('vehicle.transfer.admin_fallback') }}
                                    ({{ $ld['decided_at']?->format('Y-m-d H:i') }})
                                </div>
                                @if($ld['reason'] ?? null)
                                <div class="rounded bg-white/60 px-2 py-1 text-[11px] {{ $ldClass[4] }} border {{ $ldClass[5] }}">
                                    <span class="font-semibold">{{ __('vehicle.transfer.void_reason_label') }}:</span> {{ $ld['reason'] }}
                                </div>
                                @endif
                            @else
                                <div>
                                    {{ __('vehicle.transfer.target') }} <span class="font-mono">{{ $ld['target_vehicle_number'] }}</span>
                                    · {{ number_format($ld['amount']) }} {{ $ld['currency'] }}
                                    · {{ $ld['approver_name'] ?? __('vehicle.transfer.admin_fallback') }}
                                    ({{ $ld['decided_at']?->format('Y-m-d H:i') }})
                                </div>
                            @endif
                            <div class="rounded bg-white/60 px-2 py-1 text-[11px] {{ $ldClass[4] }} border {{ $ldClass[5] }}">
                                <span class="font-semibold">{{ $ldClass[8] }}:</span> {{ $ld['decision_note'] ?: __('vehicle.transfer.no_memo') }}
                            </div>
                            @if(! $isVoid && in_array($ldKey, ['approved:executed', 'approved:voided'], true) && ! empty($ld['finance_confirmer_name']))
                            <div class="rounded bg-white/60 px-2 py-1 text-[11px] {{ $ldClass[4] }} border {{ $ldClass[5] }}">
                                <span class="font-semibold">{{ __('vehicle.transfer.finance_confirmed_label') }}:</span>
                                {{ $ld['finance_confirmer_name'] }}
                                ({{ $ld['confirmed_at']?->format('Y-m-d H:i') }})
                                @if($ld['finance_note'])
                                · {{ $ld['finance_note'] }}
                                @endif
                            </div>
                            @endif
                            {{-- 큐 19-K — 재무 거부 사유 표시 (approved:finance_rejected 한정) --}}
                            @if(! $isVoid && $ldKey === 'approved:finance_rejected' && ! empty($ld['finance_rejecter_name']))
                            <div class="rounded bg-white/60 px-2 py-1 text-[11px] {{ $ldClass[4] }} border {{ $ldClass[5] }}">
                                <span class="font-semibold">{{ __('vehicle.transfer.finance_rejected_label') }}:</span>
                                {{ $ld['finance_rejecter_name'] }}
                                ({{ $ld['finance_rejected_at']?->format('Y-m-d H:i') }})
                            </div>
                            @if(! empty($ld['finance_reject_reason']))
                            <div class="rounded bg-white/60 px-2 py-1 text-[11px] {{ $ldClass[4] }} border {{ $ldClass[5] }}">
                                <span class="font-semibold">{{ __('vehicle.transfer.reject_reason_label') }}:</span> {{ $ld['finance_reject_reason'] }}
                            </div>
                            @endif
                            @endif
                            {{-- 큐 19-L — void 재무 거부 사유 표시 (void:finance_rejected 한정) --}}
                            @if($isVoid && $ldKey === 'void:finance_rejected' && ! empty($ld['void_finance_rejecter_name']))
                            <div class="rounded bg-white/60 px-2 py-1 text-[11px] {{ $ldClass[4] }} border {{ $ldClass[5] }}">
                                <span class="font-semibold">{{ __('vehicle.transfer.void_finance_rejected_label') }}:</span>
                                {{ $ld['void_finance_rejecter_name'] }}
                                ({{ $ld['void_finance_rejected_at']?->format('Y-m-d H:i') }})
                            </div>
                            @if(! empty($ld['void_finance_reject_reason']))
                            <div class="rounded bg-white/60 px-2 py-1 text-[11px] {{ $ldClass[4] }} border {{ $ldClass[5] }}">
                                <span class="font-semibold">{{ __('vehicle.transfer.reject_reason_label') }}:</span> {{ $ld['void_finance_reject_reason'] }}
                            </div>
                            @endif
                            @endif
                        </div>
                    </div>
                    @endif

                    {{-- 일반 자금 이체 박스 --}}
                    @if($transferCtx['eligible'])
                    @php
                        // 마지막 결정이 rejected이거나 큐 19-K 재무 거부면 "다시 요청"
                        $ldStatus = $transferCtx['lastDecided']['status'] ?? null;
                        $ldTransferStatus = $transferCtx['lastDecided']['transfer_status'] ?? null;
                        $isRetryCase = $ldStatus === 'rejected'
                            || ($ldStatus === 'approved' && $ldTransferStatus === 'finance_rejected');
                        $btnLabel = $isRetryCase ? __('vehicle.transfer.retry') : __('vehicle.transfer.request_btn');
                    @endphp
                    <div class="rounded-md border border-violet-200 bg-violet-50 p-3 text-xs text-violet-900">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="space-y-0.5">
                                <div>{{ __('vehicle.transfer.received') }} <strong>{{ number_format($transferCtx['received']) }}</strong> {{ $currency ?: 'KRW' }}</div>
                                <div>{{ __('vehicle.transfer.limit') }} <strong class="text-violet-700">{{ number_format($transferCtx['limit']) }}</strong> {{ $currency ?: 'KRW' }} <span class="text-violet-500">{{ __('vehicle.transfer.limit_note') }}</span></div>
                                <div class="text-violet-700">{{ __('vehicle.transfer.candidates', ['count' => $transferCtx['candidates']->count()]) }}</div>
                            </div>
                            <button type="button" wire:click="openTransferRequestModal"
                                    class="rounded-md bg-violet-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-violet-700">
                                {{ $btnLabel }}
                            </button>
                        </div>
                    </div>
                    @else
                    <div class="rounded-md border border-gray-200 bg-gray-50 p-3 text-xs text-gray-500">
                        {{ $transferCtx['reason'] ?: __('vehicle.transfer.not_eligible') }}
                    </div>
                    @endif
                @endif
            </div>
            @endif
        </div>

        {{-- ─── 수출통관 탭 ───────────────────────────────── --}}
        <div x-show="tab === 'clearance'" x-cloak>
            <div class="section-header">
                <span class="section-dot bg-amber-500"></span>
                <span class="section-title">{{ __('vehicle.panel.sec.clearance') }}</span>
            </div>

            {{-- 미입금 진입 게이트(C5 50%) 안내 — 선적·통관 공유 (2026-07-04) --}}
            @php $gateC = $this->exportGateStatus; @endphp
            @if($gateC && ! $gateC['has_bl'])
            <div class="mb-3 rounded-md border px-3 py-2 text-xs
                {{ $gateC['ratio'] === null ? 'border-amber-200 bg-amber-50 text-amber-800'
                    : ($gateC['ratio'] > 0.5
                        ? ($gateC['entry_override'] ? 'border-blue-200 bg-blue-50 text-blue-800' : 'border-red-200 bg-red-50 text-red-800')
                        : 'border-emerald-200 bg-emerald-50 text-emerald-800') }}">
                @if($gateC['ratio'] === null)
                    {{ __('vehicle.panel.c5.rate_missing') }}
                @elseif($gateC['ratio'] > 0.5)
                    {{ $gateC['entry_override'] ? __('vehicle.panel.c5.override', ['paid' => $gateC['paid_pct']]) : __('vehicle.panel.c5.blocked', ['paid' => $gateC['paid_pct']]) }}
                @else
                    {{ __('vehicle.panel.c5.ok', ['paid' => $gateC['paid_pct']]) }}
                @endif
                <span class="mt-0.5 block text-[11px] opacity-80">{{ __('vehicle.panel.c5.bl_note') }}</span>
            </div>
            @endif

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                {{-- 당사자 축소 (jin 2026-07-09) — 통관 당사자는 이어받기만(읽기전용): 바이어=판매 / 컨사이니=선적. --}}
                <div>
                    <label class="label-base">{{ __('vehicle.field.export_buyer') }} <span class="text-[10px] text-gray-400">{{ __('vehicle.panel.inherit_from_sale') }}</span></label>
                    @php $expBuyerName = $this->buyers->firstWhere('id', (int) ($buyer_id_str ?: 0))?->name; @endphp
                    <div class="input-base bg-gray-50 text-gray-600">{{ $expBuyerName ?: '—' }}</div>
                </div>
                <div>
                    <label class="label-base">{{ __('vehicle.field.export_consignee') }} <span class="text-[10px] text-gray-400">{{ __('vehicle.panel.inherit_from_bl') }}</span></label>
                    @php $expConsName = $this->consigneesForBl->firstWhere('id', (int) ($bl_consignee_id_str ?: 0))?->name; @endphp
                    <div class="input-base bg-gray-50 text-gray-600">{{ $expConsName ?: '—' }}</div>
                </div>
                <div>
                    <label class="label-base">{{ __('vehicle.field.forwarder') }}</label>
                    <select wire:model="forwarding_company_id_str" class="input-base">
                        <option value="">{{ __('vehicle.panel.select_placeholder') }}</option>
                        @foreach($this->forwardingCompanies as $f)
                        <option value="{{ $f->id }}">{{ $f->name }}</option>
                        @endforeach
                    </select>
                </div>
                {{-- 2026-05-21 — 면장금액 미입력 시 sale_price 자동 복사 (Vehicle::saving 훅). 인코텀즈 차이 등 명시 입력 시 그 값 우선 --}}
                <div>
                    <label class="label-base">{{ __('vehicle.field.export_decl_amount') }}</label>
                    <input wire:model="export_declaration_amount_str" type="text" data-money class="input-base" placeholder="{{ __('vehicle.ph.export_decl_amount') }}" />
                    <p class="mt-1 text-[11px] text-gray-400">{{ __('vehicle.panel.export_decl_amount_note') }}</p>
                </div>
                <div><label class="label-base">{{ __('vehicle.field.export_decl_number') }}</label><input wire:model="export_declaration_number" type="text" class="input-base" placeholder="123-12-123456" /></div>
                <div><label class="label-base">{{ __('vehicle.field.shipping_date') }}</label><input wire:model="shipping_date" type="text" data-date class="input-base" /></div>
                <div><label class="label-base">ETA</label><input wire:model="eta_date" type="text" data-date class="input-base" /></div>
                <div>
                    <label class="label-base">{{ __('vehicle.field.shipping_method') }}</label>
                    <select wire:model="shipping_method" class="input-base">
                        <option value="">{{ __('vehicle.panel.select_placeholder') }}</option>
                        <option value="RORO">RORO</option>
                        <option value="CONTAINER">CONTAINER</option>
                    </select>
                </div>
                {{-- 2026-05-21 CIPL 이식 — 선적항(Port of Loading) 드롭다운 --}}
                <div>
                    <label class="label-base">{{ __('vehicle.field.port_loading') }}</label>
                    <select wire:model="port_of_loading" class="input-base">
                        <option value="">{{ __('vehicle.panel.select_placeholder') }}</option>
                        @foreach($this->loadingPorts as $p)
                        <option value="{{ $p->name }}">{{ $p->display_name }}</option>
                        @endforeach
                    </select>
                </div>
                {{-- 2026-05-21 — 인코텀즈 (FOB/CFR) — CIPL C32/C37 셀에 사용 --}}
                <div>
                    <label class="label-base">{{ __('vehicle.field.incoterms') }}</label>
                    <select wire:model="incoterms" class="input-base">
                        <option value="">{{ __('vehicle.panel.select_placeholder') }}</option>
                        <option value="FOB">FOB</option>
                        <option value="CFR">CFR</option>
                    </select>
                </div>
                {{-- 2026-05-21 — Discharge Port (도착항) FK — CIPL E16/F16 셀 --}}
                <div>
                    <label class="label-base">{{ __('vehicle.field.discharge_port') }}</label>
                    <select wire:model="discharge_port_id_str" class="input-base">
                        <option value="">{{ __('vehicle.panel.discharge_auto') }}</option>
                        @foreach($this->dischargePorts as $p)
                        <option value="{{ $p->id }}">{{ $p->display_name }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-[11px] text-gray-400">{{ __('vehicle.panel.discharge_note') }}</p>
                </div>
                <div class="flex items-end">
                    <label class="flex items-center gap-2 text-sm cursor-pointer">
                        <input wire:model="is_export_cleared" type="checkbox" class="rounded" /> {{ __('vehicle.field.export_cleared') }}
                    </label>
                </div>
                <div class="col-span-2 sm:col-span-3">
                    <label class="label-base">{{ __('vehicle.field.export_decl_doc') }} <span class="text-xs text-gray-400">{{ __('vehicle.panel.upload_enables_loaded') }}</span></label>
                    <input wire:model="exportDeclarationDocFile" type="file" accept=".jpg,.jpeg,.png,.gif,.webp,.bmp,.pdf,.xlsx,.xls,.csv,.docx,.doc,.hwp,.hwpx,.pptx,.ppt,.txt,.zip"
                           class="block w-full text-xs text-gray-500 file:mr-2 file:rounded file:border-0 file:bg-amber-50 file:px-2 file:py-1 file:text-xs file:text-amber-700" />
                    <div wire:loading wire:target="exportDeclarationDocFile" class="mt-1 text-xs text-gray-400">{{ __('vehicle.panel.uploading') }}</div>
                    @if($exportDeclarationDocFile)
                    <p class="mt-1 break-all text-xs text-gray-700">📄 {{ $exportDeclarationDocFile->getClientOriginalName() }} <span class="text-gray-400">{{ __('vehicle.panel.before_save') }}</span></p>
                    @elseif($export_declaration_document_path)
                    <div class="mt-1 flex items-center gap-3">
                        <a href="{{ \App\Support\VehicleDocUrl::for($export_declaration_document_path) }}" target="_blank"
                           class="break-all text-xs text-violet-600 hover:underline">📄 {{ basename($export_declaration_document_path) }}</a>
                        <button type="button" wire:click="removeExportDeclarationDoc"
                                class="text-xs text-red-500 hover:underline">{{ __('vehicle.delete') }}</button>
                    </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- ─── B/L 탭 ────────────────────────────────────── --}}
        {{-- ─── 선적 탭 (반입) — B/L 발급과 분리 (2026-07-04). 반입지·선박·컨테이너·선적당사자 --}}
        <div x-show="tab === 'shipping'" x-cloak>
            <div class="section-header">
                <span class="section-dot bg-emerald-500"></span>
                <span class="section-title">{{ __('vehicle.panel.sec.shipping') }}</span>
            </div>

            {{-- 미입금 진입 게이트(C5 50%) 안내 — 선적·통관 공유 (2026-07-04) --}}
            @php $gate = $this->exportGateStatus; @endphp
            @if($gate && ! $gate['has_bl'])
            <div class="mb-3 rounded-md border px-3 py-2 text-xs
                {{ $gate['ratio'] === null ? 'border-amber-200 bg-amber-50 text-amber-800'
                    : ($gate['ratio'] > 0.5
                        ? ($gate['entry_override'] ? 'border-blue-200 bg-blue-50 text-blue-800' : 'border-red-200 bg-red-50 text-red-800')
                        : 'border-emerald-200 bg-emerald-50 text-emerald-800') }}">
                @if($gate['ratio'] === null)
                    {{ __('vehicle.panel.c5.rate_missing') }}
                @elseif($gate['ratio'] > 0.5)
                    {{ $gate['entry_override'] ? __('vehicle.panel.c5.override', ['paid' => $gate['paid_pct']]) : __('vehicle.panel.c5.blocked', ['paid' => $gate['paid_pct']]) }}
                @else
                    {{ __('vehicle.panel.c5.ok', ['paid' => $gate['paid_pct']]) }}
                @endif
                <span class="mt-0.5 block text-[11px] opacity-80">{{ __('vehicle.panel.c5.bl_note') }}</span>
            </div>
            @endif

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                {{-- 당사자 축소 (jin 2026-07-09) — 선적 바이어는 판매에서 이어받기(읽기전용). 선적=컨사이니만 입력. --}}
                <div>
                    <label class="label-base">{{ __('vehicle.field.bl_buyer') }} <span class="text-[10px] text-gray-400">{{ __('vehicle.panel.inherit_from_sale') }}</span></label>
                    @php $blBuyerName = $this->buyers->firstWhere('id', (int) ($buyer_id_str ?: 0))?->name; @endphp
                    <div class="input-base bg-gray-50 text-gray-600">{{ $blBuyerName ?: '—' }}</div>
                </div>
                <div>
                    <div class="flex items-center justify-between">
                        <label class="label-base">{{ __('vehicle.field.bl_consignee') }}</label>
                        <button type="button" wire:click="openQuickAdd('consignee','bl')"
                                @if($bl_buyer_id_str === '') disabled @endif
                                class="mb-1 text-[11px] text-primary-text hover:underline disabled:cursor-not-allowed disabled:text-gray-300 disabled:no-underline">{{ __('vehicle.panel.add_new') }}</button>
                    </div>
                    <x-erp.combobox model="bl_consignee_id_str" :options="$this->consigneesForBl" :selected="$bl_consignee_id_str"
                        :disabled="$bl_buyer_id_str === ''"
                        placeholder="{{ $bl_buyer_id_str ? __('vehicle.panel.select_placeholder') : __('vehicle.panel.buyer_first') }}"
                        wire:key="cbx-cons-bl-{{ $bl_buyer_id_str }}-{{ $bl_consignee_id_str }}" />
                </div>
                {{-- 2026-05-21 CIPL 이식 — 반입지 드롭다운 --}}
                <div>
                    <label class="label-base">{{ __('vehicle.field.loading_location') }}</label>
                    <select wire:model="bl_loading_location" class="input-base">
                        <option value="">{{ __('vehicle.panel.select_placeholder') }}</option>
                        @foreach($this->unloadingPorts as $p)
                        <option value="{{ $p->name }}">{{ $p->display_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div><label class="label-base">{{ __('vehicle.field.vessel') }}</label><input wire:model="vessel_name" type="text" class="input-base" /></div>
                <div><label class="label-base">{{ __('vehicle.field.container_number') }}</label><input wire:model="container_number" type="text" class="input-base" /></div>
                <div><label class="label-base">{{ __('vehicle.field.document_deadline') }}</label><input wire:model="document_deadline_date" type="text" data-date class="input-base" /><p class="mt-1 text-xs text-gray-400">{{ __('vehicle.field.document_deadline_hint') }}</p></div>
            </div>

            {{-- 선박 사진/첨부 (category='shipping' · 최대 30건 — vehicle_photos, 운영 시 S3). 기본정보 차량사진과 별도 갤러리. --}}
            <div class="mt-4">
                <label class="label-base">{{ __('vehicle.panel.sec.ship_photos') }}</label>
                <input type="file" wire:model="shipPhotoUpload" multiple
                       accept=".jpg,.jpeg,.png,.gif,.webp,.bmp,.pdf,.xlsx,.xls,.csv,.docx,.doc,.hwp,.hwpx,.pptx,.ppt,.txt,.zip"
                       class="input-base text-sm" />
                <p class="mt-1 text-xs text-gray-400">{{ __('vehicle.panel.photo_multi_hint') }}</p>
                <div wire:loading wire:target="shipPhotoUpload" class="mt-1 text-xs text-gray-400">{{ __('vehicle.panel.uploading') }}</div>
                @error('shipPhotoUpload')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
                @error('shipPhotoUpload.*')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror

                @if(count($existingShipPhotos) || count($shipPhotoFiles))
                <div class="mt-3 grid grid-cols-3 gap-2 sm:grid-cols-5">
                        @foreach($existingShipPhotos as $p)
                        <div class="relative aspect-square overflow-hidden rounded border border-gray-200">
                            @if($p['is_image'])
                                <img src="{{ $p['url'] }}" class="h-full w-full cursor-zoom-in object-cover"
                                     alt="{{ $p['filename'] }}"
                                     @click="openLightbox({{ \Illuminate\Support\Js::from($p['url']) }}, {{ \Illuminate\Support\Js::from($p['filename']) }}, 'image')" />
                            @elseif($p['ext'] === 'pdf')
                                <button type="button"
                                        @click="openLightbox({{ \Illuminate\Support\Js::from($p['url']) }}, {{ \Illuminate\Support\Js::from($p['filename']) }}, 'pdf')"
                                        class="flex h-full w-full cursor-zoom-in flex-col items-center justify-center gap-1 bg-gray-50 p-2 text-center hover:bg-gray-100">
                                    <span class="rounded bg-gray-200 px-2 py-1 text-[10px] font-bold text-gray-700">PDF</span>
                                    <span class="line-clamp-2 break-all text-[10px] text-gray-700">{{ $p['filename'] }}</span>
                                </button>
                            @else
                                <a href="{{ $p['url'] }}" target="_blank" rel="noopener"
                                   class="flex h-full w-full flex-col items-center justify-center gap-1 bg-gray-50 p-2 text-center hover:bg-gray-100">
                                    <span class="rounded bg-gray-200 px-2 py-1 text-[10px] font-bold text-gray-700">{{ strtoupper($p['ext']) ?: 'FILE' }}</span>
                                    <span class="line-clamp-2 break-all text-[10px] text-gray-700">{{ $p['filename'] }}</span>
                                </a>
                            @endif
                            <button type="button" wire:click="removeExistingShipPhoto({{ $p['id'] }})"
                                    class="absolute right-1 top-1 rounded-full bg-black/60 px-1.5 text-xs leading-none text-white hover:bg-red-600">×</button>
                        </div>
                        @endforeach
                        @foreach($shipPhotoFiles as $idx => $photo)
                        @php
                            $_ext = strtolower($photo->getClientOriginalExtension());
                            $_isImg = in_array($_ext, \App\Models\VehiclePhoto::IMAGE_EXTENSIONS, true);
                        @endphp
                        <div class="relative aspect-square overflow-hidden rounded border border-violet-300">
                            @if($_isImg)
                                <img src="{{ $photo->temporaryUrl() }}" class="h-full w-full cursor-zoom-in object-cover"
                                     alt="{{ __('vehicle.panel.new') }}"
                                     @click="openLightbox({{ \Illuminate\Support\Js::from($photo->temporaryUrl()) }}, {{ \Illuminate\Support\Js::from($photo->getClientOriginalName()) }}, 'image')" />
                            @else
                                <div class="flex h-full w-full flex-col items-center justify-center gap-1 bg-violet-50 p-2 text-center">
                                    <span class="rounded bg-violet-200 px-2 py-1 text-[10px] font-bold text-violet-800">{{ strtoupper($_ext) ?: 'FILE' }}</span>
                                    <span class="line-clamp-2 break-all text-[10px] text-gray-700">{{ $photo->getClientOriginalName() }}</span>
                                </div>
                            @endif
                            <span class="absolute left-1 top-1 rounded bg-violet-600 px-1 text-[10px] text-white">{{ __('vehicle.panel.new') }}</span>
                            <button type="button" wire:click="removeNewShipPhoto({{ $idx }})"
                                    class="absolute right-1 top-1 rounded-full bg-black/60 px-1.5 text-xs leading-none text-white hover:bg-red-600">×</button>
                        </div>
                        @endforeach
                </div>
                @endif
            </div>
        </div>

        {{-- ─── B/L 탭 (발급) — 수출통관 다음, 실제 업무 최종 단계 (2026-07-04) --}}
        <div x-show="tab === 'bl'" x-cloak>
            <div class="section-header">
                <span class="section-dot bg-emerald-500"></span>
                <span class="section-title">{{ __('vehicle.panel.sec.bl') }}</span>
            </div>

            {{-- G1 100% B/L 게이트 상태 표시 (2026-05-26 회의). 기존 bl_document가 없는 차량만 검사 (grandfather). --}}
            @if($editingId)
            @php
                $g1Vehicle = \App\Models\Vehicle::with('unpaidExportOverrides')->find($editingId);
                $g1Ratio = $g1Vehicle?->unpaid_ratio;
                $g1HasExistingBl = $g1Vehicle && ! empty($g1Vehicle->bl_document);
                $g1HasShippingOverride = $g1Vehicle?->hasUnpaidOverride('bl') ?? false;   // B/L 발행 우회는 'bl' 단계
            @endphp
            @if(! $g1HasExistingBl)
            <div class="mb-3 rounded-md border px-3 py-2 text-xs
                {{ $g1Ratio === null
                    ? 'border-amber-200 bg-amber-50 text-amber-800'
                    : ($g1Ratio > 0
                        ? ($g1HasShippingOverride ? 'border-blue-200 bg-blue-50 text-blue-800' : 'border-red-200 bg-red-50 text-red-800')
                        : 'border-emerald-200 bg-emerald-50 text-emerald-800') }}">
                @if($g1Ratio === null)
                    {{ __('vehicle.panel.g1.no_price') }}
                @elseif($g1Ratio > 0)
                    @if($g1HasShippingOverride)
                        {{ __('vehicle.panel.g1.override', ['ratio' => number_format($g1Ratio * 100, 1)]) }}
                    @else
                        {{ __('vehicle.panel.g1.locked', ['ratio' => number_format($g1Ratio * 100, 1)]) }}
                    @endif
                @else
                    {{ __('vehicle.panel.g1.ok') }}
                @endif
            </div>
            @endif
            @endif

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                <div><label class="label-base">{{ __('vehicle.field.bl_number') }}</label><input wire:model="bl_number" type="text" class="input-base" /></div>
                <div><label class="label-base">{{ __('vehicle.field.bl_issue_date') }}</label><input wire:model="bl_issue_date" type="text" data-date class="input-base" /></div>
                {{-- B/L 방식(오리지널/써랜더) + 이중가드 — 영업 요청(shipping_requests.bl_type) vs 관리 확인(vehicles.bl_type) --}}
                @php
                    $reqBlType = $editingId
                        ? \App\Models\ShippingRequest::where('vehicle_id', $editingId)
                            ->where('status', '!=', 'cancelled')->whereNotNull('bl_type')
                            ->orderByDesc('id')->value('bl_type')
                        : null;
                @endphp
                <div>
                    <label class="label-base">{{ __('shipping.bl.field_type') }}
                        @if($reqBlType) <span class="text-[10px] text-gray-400">({{ __('shipping.bl.requested_hint') }}: {{ __('shipping.bl.type.'.$reqBlType) }})</span> @endif
                    </label>
                    <select wire:model.live="bl_type" class="input-base">
                        <option value="">{{ __('vehicle.panel.select_placeholder') }}</option>
                        <option value="original">{{ __('shipping.bl.type.original') }}</option>
                        <option value="surrender">{{ __('shipping.bl.type.surrender') }}</option>
                    </select>
                </div>
                @if($reqBlType && $bl_type && $reqBlType !== $bl_type)
                <div class="col-span-2 sm:col-span-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs font-semibold text-red-700">
                    {{ __('shipping.bl.guard_mismatch', ['req' => __('shipping.bl.type.'.$reqBlType), 'cur' => __('shipping.bl.type.'.$bl_type)]) }}
                </div>
                @endif
                <div class="col-span-2 sm:col-span-3">
                    <label class="label-base">{{ __('vehicle.field.bl_doc') }} <span class="text-xs text-gray-400">{{ __('vehicle.panel.upload_enables_loaded') }}</span></label>
                    <input wire:model="blDocFile" type="file" accept=".jpg,.jpeg,.png,.gif,.webp,.bmp,.pdf,.xlsx,.xls,.csv,.docx,.doc,.hwp,.hwpx,.pptx,.ppt,.txt,.zip"
                           class="block w-full text-xs text-gray-500 file:mr-2 file:rounded file:border-0 file:bg-emerald-50 file:px-2 file:py-1 file:text-xs file:text-emerald-700" />
                    <div wire:loading wire:target="blDocFile" class="mt-1 text-xs text-gray-400">{{ __('vehicle.panel.uploading') }}</div>
                    @if($blDocFile)
                    <p class="mt-1 break-all text-xs text-gray-700">📄 {{ $blDocFile->getClientOriginalName() }} <span class="text-gray-400">{{ __('vehicle.panel.before_save') }}</span></p>
                    @elseif($bl_document_path)
                    <div class="mt-1 flex items-center gap-3">
                        <a href="{{ \App\Support\VehicleDocUrl::for($bl_document_path) }}" target="_blank"
                           class="break-all text-xs text-violet-600 hover:underline">📄 {{ basename($bl_document_path) }}</a>
                        <button type="button" wire:click="removeBlDoc"
                                class="text-xs text-red-500 hover:underline">{{ __('vehicle.delete') }}</button>
                    </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- ─── DHL 탭 ────────────────────────────────────── --}}
        <div x-show="tab === 'dhl'" x-cloak>
            <div class="section-header">
                <span class="section-dot bg-teal-500"></span>
                <span class="section-title">{{ __('vehicle.panel.sec.dhl_recipient') }}</span>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="label-base">{{ __('vehicle.field.dhl_recipient_name') }}</label><input wire:model="dhl_recipient_name" type="text" class="input-base" /></div>
                <div><label class="label-base">{{ __('vehicle.field.dhl_recipient_phone') }}</label><input wire:model="dhl_recipient_phone" type="text" class="input-base" /></div>
                <div class="col-span-2"><label class="label-base">{{ __('vehicle.field.dhl_recipient_address') }}</label><input wire:model="dhl_recipient_address" type="text" class="input-base" /></div>
            </div>
            <hr class="section-divider">
            <div class="section-header">
                <span class="section-dot bg-teal-300"></span>
                <span class="section-title">{{ __('vehicle.panel.sec.dhl_sender') }}</span>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="label-base">{{ __('vehicle.field.dhl_sender_name') }}</label><input wire:model="dhl_sender_name" type="text" class="input-base" /></div>
                <div class="col-span-2"><label class="label-base">{{ __('vehicle.field.dhl_sender_address') }}</label><input wire:model="dhl_sender_address" type="text" class="input-base" /></div>
                <div><label class="label-base">{{ __('vehicle.field.dhl_weight') }}</label><input wire:model="dhl_weight_str" type="text" class="input-base" placeholder="1.5" /></div>
                <div><label class="label-base">{{ __('vehicle.field.dhl_dimensions') }}</label><input wire:model="dhl_dimensions" type="text" class="input-base" placeholder="30x20x10" /></div>
                <div class="col-span-2 flex items-center gap-2">
                    <label class="flex items-center gap-2 text-sm cursor-pointer">
                        <input wire:model="dhl_request" type="checkbox" class="rounded" /> {{ __('vehicle.field.dhl_request_done') }}
                    </label>
                </div>
            </div>
        </div>

        {{-- ─── 서류 탭 ───────────────────────────────────── --}}
        <div x-show="tab === 'docs'" x-cloak>
            @php
                // 큐 16 — sales_channel 단일화로 isExport 분기 제거 (영문 서류 항상 노출).
                $hasId = $editingId !== null;
                $url = fn (string $type) => $hasId
                    ? route('erp.vehicles.documents.show', ['id' => $editingId, 'type' => $type])
                    : '#';
                // PDF·인쇄 모달용 서류 목록 (엑셀 카드와 동일 세트). export 단일 채널이라 전 서류 노출.
                $pdfDocs = [
                    ['type' => 'deregistration', 'label' => __('vehicle.docs.deregistration')],
                    ['type' => 'deregistration_contract', 'label' => __('vehicle.docs.derg_contract')],
                    ['type' => 'poa', 'label' => __('vehicle.docs.poa')],
                    ['type' => 'invoice', 'label' => __('vehicle.docs.invoice')],
                    ['type' => 'sales_contract', 'label' => __('vehicle.docs.sales_contract')],
                    ['type' => 'container_invoice_packing', 'label' => __('vehicle.docs.container_invoice_packing')],
                    ['type' => 'container_contract', 'label' => __('vehicle.docs.container_contract')],
                    ['type' => 'roro_invoice_packing', 'label' => __('vehicle.docs.roro_invoice_packing')],
                    ['type' => 'roro_contract', 'label' => __('vehicle.docs.roro_contract')],
                    ['type' => 'clearance', 'label' => __('vehicle.docs.clearance_set')],
                ];
                $pdfDocsJs = $hasId
                    ? collect($pdfDocs)->map(fn ($d) => ['label' => $d['label'], 'url' => $url($d['type'])])->values()->all()
                    : [];
            @endphp

            @unless ($hasId)
                <div class="card-tight mb-4 border-amber-200 bg-amber-50 text-sm text-amber-800">
                    {{ __('vehicle.docs.save_first') }}
                </div>
            @endunless

            {{-- 📄 PDF · 🖨 인쇄 — 서류 선택 모달. 엑셀은 아래 카드에서 다운로드(현행). 인쇄=숨은 iframe→인쇄창. --}}
            @if ($hasId)
            <div class="mb-4" x-data="docOutput(@js($pdfDocsJs))">
                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" @click="openModal('pdf')"
                            class="inline-flex items-center gap-1.5 rounded-md border border-rose-200 bg-rose-50 px-3 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-100">
                        📄 {{ __('vehicle.docs.output_pdf') }}
                    </button>
                    <button type="button" @click="openModal('print')"
                            class="inline-flex items-center gap-1.5 rounded-md border border-sky-200 bg-sky-50 px-3 py-1.5 text-xs font-semibold text-sky-700 hover:bg-sky-100">
                        🖨 {{ __('vehicle.docs.output_print') }}
                    </button>
                    <span class="text-[11px] text-gray-400">{{ __('vehicle.docs.output_hint') }}</span>
                </div>

                {{-- 서류 선택 모달 --}}
                <div x-show="show" x-cloak @keydown.escape.window="closeModal()"
                     class="fixed inset-0 z-[100] flex items-center justify-center bg-black/50" @click.self="closeModal()">
                    <div class="card w-full max-w-sm mx-4 shadow-2xl" @click.stop>
                        <h3 class="text-sm font-semibold text-gray-900">
                            <span x-show="mode==='pdf'">{{ __('vehicle.docs.output_modal_pdf') }}</span>
                            <span x-show="mode==='print'" x-cloak>{{ __('vehicle.docs.output_modal_print') }}</span>
                        </h3>
                        <div class="mt-3 max-h-[55vh] space-y-1 overflow-y-auto">
                            <template x-for="d in docs" :key="d.url">
                                <button type="button" @click="pick(d)" :disabled="busy"
                                        class="flex w-full items-center justify-between rounded-md border border-gray-100 px-3 py-2 text-left text-sm text-gray-700 hover:border-primary hover:bg-primary-light disabled:cursor-not-allowed disabled:opacity-50">
                                    <span x-text="d.label"></span>
                                    <span class="text-xs text-gray-400" x-text="mode==='print' ? '🖨' : '↓'"></span>
                                </button>
                            </template>
                        </div>
                        <p x-show="busy" x-cloak class="mt-3 rounded-md bg-sky-50 px-3 py-2 text-xs text-sky-700">{{ __('vehicle.docs.output_generating') }}</p>
                        <div class="mt-4 flex justify-end">
                            <button type="button" @click="closeModal()" :disabled="busy"
                                    class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs text-gray-600 hover:bg-gray-50 disabled:opacity-50">{{ __('common.close') }}</button>
                        </div>
                    </div>
                </div>
            </div>
            @endif

            {{-- 매입 서류 (전 채널) — system xlsx 자동기입 (노란칸만 채우고 노란 제거) --}}
            <div class="section-header">
                <span class="section-dot bg-blue-500"></span>
                <span class="section-title">{{ __('vehicle.docs.sec_purchase') }}</span>
            </div>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <a href="{{ $url('deregistration') }}"
                   class="card-tight flex items-center justify-between hover:border-violet-400 hover:bg-violet-50 transition {{ $hasId ? '' : 'pointer-events-none opacity-50' }}">
                    <div>
                        <div class="text-sm font-semibold text-gray-800">{{ __('vehicle.docs.deregistration') }}</div>
                        <div class="text-xs text-gray-500">{{ __('vehicle.docs.deregistration_sub') }}</div>
                    </div>
                    <span class="text-xs text-violet-600">↓</span>
                </a>
                <a href="{{ $url('deregistration_contract') }}"
                   class="card-tight flex items-center justify-between hover:border-violet-400 hover:bg-violet-50 transition {{ $hasId ? '' : 'pointer-events-none opacity-50' }}">
                    <div>
                        <div class="text-sm font-semibold text-gray-800">{{ __('vehicle.docs.derg_contract') }}</div>
                        <div class="text-xs text-gray-500">{{ __('vehicle.docs.sub_purchase') }}</div>
                    </div>
                    <span class="text-xs text-violet-600">↓</span>
                </a>
                <a href="{{ $url('poa') }}"
                   class="card-tight flex items-center justify-between hover:border-violet-400 hover:bg-violet-50 transition {{ $hasId ? '' : 'pointer-events-none opacity-50' }}">
                    <div>
                        <div class="text-sm font-semibold text-gray-800">{{ __('vehicle.docs.poa') }}</div>
                        <div class="text-xs text-gray-500">{{ __('vehicle.docs.sub_purchase') }}</div>
                    </div>
                    <span class="text-xs text-violet-600">↓</span>
                </a>
            </div>

            {{-- 영문 서류 (수출 단일 채널) ─────────────────── --}}
            <hr class="section-divider mt-5">
            <div class="section-header">
                <span class="section-dot bg-emerald-500"></span>
                <span class="section-title">{{ __('vehicle.docs.sec_sale') }}</span>
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <a href="{{ $url('invoice') }}"
                   class="card-tight flex items-center justify-between hover:border-emerald-400 hover:bg-emerald-50 transition {{ $hasId ? '' : 'pointer-events-none opacity-50' }}">
                    <div>
                        <div class="text-sm font-semibold text-gray-800">{{ __('vehicle.docs.invoice') }}</div>
                        <div class="text-xs text-gray-500">{{ __('vehicle.docs.sub_sale') }}</div>
                    </div>
                    <span class="text-xs text-emerald-600">↓</span>
                </a>
                {{-- 판매계약서 — Proforma Invoice 옆. 이 버튼은 이 차량 1대. 여러 대 → 1서류는 차량목록 체크박스. --}}
                <a href="{{ $url('sales_contract') }}"
                   class="card-tight flex items-center justify-between hover:border-emerald-400 hover:bg-emerald-50 transition {{ $hasId ? '' : 'pointer-events-none opacity-50' }}">
                    <div>
                        <div class="text-sm font-semibold text-gray-800">{{ __('vehicle.docs.sales_contract') }}</div>
                        <div class="text-xs text-gray-500">{{ __('vehicle.docs.sub_sale') }}</div>
                    </div>
                    <span class="text-xs text-emerald-600">↓</span>
                </a>
                {{-- 전자서명 — 이 차량 1대 계약 서명 상태/요청 (묶음은 선적요청 화면) --}}
                @if($hasId)
                    <div class="mt-1 flex items-center gap-2 px-1">
                        <span class="text-[11px] font-semibold text-gray-400">{{ __('signed_contract.esign_label') }}</span>
                        <x-erp.esign-chip
                            :status="$this->editingSign['status']"
                            :contract-id="$this->editingSign['id'] ?? null"
                            request-click="requestSignatureForVehicle"
                            link-click="showSignLink({{ $this->editingSign['id'] ?? 0 }})" />
                    </div>
                @endif
            </div>

            {{-- 선적 서류 (컨테이너/RORO × Invoice&Packing·Contract). 이 버튼은 이 차량 1대. 여러 대 → 1서류는 차량목록 체크박스. --}}
            <div class="section-header mt-5">
                <span class="section-dot bg-amber-500"></span>
                <span class="section-title">{{ __('vehicle.docs.sec_shipping') }}</span>
            </div>
            <p class="mb-2 text-[11px] text-gray-500">{{ __('vehicle.docs.multi_hint') }}</p>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <a href="{{ $url('container_invoice_packing') }}"
                   class="card-tight flex items-center justify-between hover:border-amber-400 hover:bg-amber-50 transition {{ $hasId ? '' : 'pointer-events-none opacity-50' }}">
                    <div>
                        <div class="text-sm font-semibold text-gray-800">{{ __('vehicle.docs.container_invoice_packing') }}</div>
                        <div class="text-xs text-gray-500">{{ __('vehicle.docs.sub_container') }}</div>
                    </div>
                    <span class="text-xs text-amber-600">↓</span>
                </a>
                <a href="{{ $url('container_contract') }}"
                   class="card-tight flex items-center justify-between hover:border-amber-400 hover:bg-amber-50 transition {{ $hasId ? '' : 'pointer-events-none opacity-50' }}">
                    <div>
                        <div class="text-sm font-semibold text-gray-800">{{ __('vehicle.docs.container_contract') }}</div>
                        <div class="text-xs text-gray-500">{{ __('vehicle.docs.sub_container') }}</div>
                    </div>
                    <span class="text-xs text-amber-600">↓</span>
                </a>
                <a href="{{ $url('roro_invoice_packing') }}"
                   class="card-tight flex items-center justify-between hover:border-amber-400 hover:bg-amber-50 transition {{ $hasId ? '' : 'pointer-events-none opacity-50' }}">
                    <div>
                        <div class="text-sm font-semibold text-gray-800">{{ __('vehicle.docs.roro_invoice_packing') }}</div>
                        <div class="text-xs text-gray-500">{{ __('vehicle.docs.sub_roro') }}</div>
                    </div>
                    <span class="text-xs text-amber-600">↓</span>
                </a>
                <a href="{{ $url('roro_contract') }}"
                   class="card-tight flex items-center justify-between hover:border-amber-400 hover:bg-amber-50 transition {{ $hasId ? '' : 'pointer-events-none opacity-50' }}">
                    <div>
                        <div class="text-sm font-semibold text-gray-800">{{ __('vehicle.docs.roro_contract') }}</div>
                        <div class="text-xs text-gray-500">{{ __('vehicle.docs.sub_roro') }}</div>
                    </div>
                    <span class="text-xs text-amber-600">↓</span>
                </a>
            </div>

            {{-- 통관 서류 (통관 SET — 구매리스트 1장 → 8시트 자동연동) --}}
            <div class="section-header mt-5">
                <span class="section-dot bg-green-500"></span>
                <span class="section-title">{{ __('vehicle.docs.sec_clearance') }}</span>
            </div>
            <div class="grid grid-cols-1 gap-3">
                <a href="{{ $url('clearance') }}"
                   class="card-tight flex items-center justify-between hover:border-green-400 hover:bg-green-50 transition {{ $hasId ? '' : 'pointer-events-none opacity-50' }}">
                    <div>
                        <div class="text-sm font-semibold text-gray-800">{{ __('vehicle.docs.clearance_set') }}</div>
                        <div class="text-xs text-gray-500">{{ __('vehicle.docs.clearance_set_sub') }}</div>
                    </div>
                    <span class="text-xs text-green-600">↓</span>
                </a>
            </div>

            <div class="mt-5 text-xs text-gray-500 leading-relaxed">
                {{ __('vehicle.docs.note_pdf') }}<br>
                {{ __('vehicle.docs.note_empty') }}
            </div>

            {{-- 메일 발송 — 업로드 문서를 바이어에게 전달 --}}
            <hr class="section-divider mt-5">
            <div class="section-header">
                <span class="section-dot bg-sky-500"></span>
                <span class="section-title">{{ __('vehicle.mail.section') }}</span>
            </div>
            <p class="mb-2 text-[11px] text-gray-500">{{ __('vehicle.mail.section_hint') }}</p>
            <button type="button" wire:click="openMailModal"
                    class="btn-primary {{ $hasId ? '' : 'pointer-events-none opacity-50' }}"
                    @disabled(! $hasId)>
                {{ __('vehicle.mail.open_btn') }}
            </button>
        </div>

        {{-- ─── 메모 (공통) ──────────────────────────────── --}}
        <div class="mt-5">
            <label class="label-base">{{ __('vehicle.field.memo') }}</label>
            <textarea wire:model="memo" class="input-base" rows="2" placeholder="{{ __('vehicle.ph.memo') }}"></textarea>
        </div>

        </div>
    </div>

    {{-- 큐 2.6 — admin 미입금 우회 승인 (편집 모드 + 권한자 + 수출 채널) --}}
    @if($editingId && auth()->user()?->canApproveUnpaidExport())
    @php
        $editingVehicle = \App\Models\Vehicle::with('unpaidExportOverrides')->find($editingId);
        $existingOverrides = $editingVehicle?->unpaidExportOverrides ?? collect();
        $unpaidKrw = $editingVehicle?->sale_unpaid_amount_krw_cache;
    @endphp
    {{-- 접기/펼치기 (기본 접힘) — 모바일 공간 절약. 헤더는 항상 노출, 폼은 쓸 때만 펼침. --}}
    <div x-data="{ open: false }" class="border-t border-amber-200 bg-amber-50 px-5 py-2">
        <button type="button" @click="open = ! open" class="flex w-full items-center gap-2 text-left">
            <svg class="h-4 w-4 flex-shrink-0 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M5 19h14a2 2 0 001.84-2.75L13.74 4a2 2 0 00-3.48 0l-7.1 12.25A2 2 0 005 19z"/></svg>
            <span class="flex-1 text-xs font-semibold text-amber-800">{{ __('vehicle.override.title') }}</span>
            @if($existingOverrides->count() > 0)
            <span class="rounded-full bg-amber-200 px-1.5 py-0.5 text-[10px] font-medium text-amber-800">{{ __('vehicle.override.existing_badge', ['count' => $existingOverrides->count()]) }}</span>
            @endif
            <svg class="h-4 w-4 flex-shrink-0 text-amber-500 transition-transform" :class="open && 'rotate-180'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
        </button>
        <div x-show="open" x-cloak class="mt-2 pl-6">
            <p class="text-[11px] text-amber-700">
                {{ __('vehicle.override.unpaid_left') }} {{ $unpaidKrw !== null ? number_format($unpaidKrw).' '.__('vehicle.override.won') : __('vehicle.override.none') }}
                @if($existingOverrides->count() > 0)
                · {{ __('vehicle.override.existing', ['count' => $existingOverrides->count()]) }} {{ $existingOverrides->pluck('stage')->unique()->implode(' / ') }}
                @endif
            </p>
            <div class="mt-2 flex flex-wrap items-end gap-2">
                <div>
                    <label class="block text-[10px] text-amber-700">{{ __('vehicle.override.stage_label') }}</label>
                    <select wire:model="overrideStage" class="input-filter">
                        <option value="">{{ __('vehicle.override.stage_select') }}</option>
                        <option value="shipping">{{ __('vehicle.override.stage_entry') }}</option>
                        <option value="bl">{{ __('vehicle.override.stage_bl') }}</option>
                    </select>
                </div>
                <div class="flex-1 min-w-[200px]">
                    <label class="block text-[10px] text-amber-700">{{ __('vehicle.override.reason_label') }}</label>
                    <input wire:model="overrideReason" type="text" class="input-filter w-full"
                           placeholder="{{ __('vehicle.override.reason_ph') }}" />
                </div>
                <button wire:click="approveUnpaidOverride" type="button"
                        class="rounded-lg bg-amber-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-amber-700">
                    {{ __('vehicle.override.approve_btn') }}
                </button>
            </div>
        </div>
    </div>
    @endif

    {{-- Panel Footer --}}
    <div class="flex items-center justify-end gap-2 border-t border-gray-200 px-5 py-4">
        <button @click="attemptClose()" type="button"
                class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">
            {{ __('vehicle.footer.cancel') }}
        </button>
        {{-- 회의확장씬 (2026-05-22) — 영업·관리 + 4 탭(매입/판매/선적/통관) 시 모달. 현재 활성 탭을 인자로 전달 --}}
        {{-- 저장하고 계속 — 창을 닫지 않고 저장(스냅샷 총판매가·남은잔금·미납률 즉시 갱신). 편집 모드만 노출. --}}
        @if($editingId)
        <button x-on:click="$wire.saveAndContinue(tab)" type="button"
                class="rounded-lg border border-primary px-4 py-2 text-sm font-medium text-primary-text hover:bg-primary-light disabled:cursor-not-allowed disabled:opacity-50"
                wire:loading.attr="disabled" wire:target="saveAndContinue,save,confirmAndSave" @disabled($editLockedByOther)>
            <span wire:loading.remove wire:target="saveAndContinue,save,confirmAndSave">{{ __('vehicle.footer.save_continue') }}</span>
            <span wire:loading wire:target="saveAndContinue,save,confirmAndSave">{{ __('vehicle.footer.saving') }}</span>
        </button>
        @endif
        <button x-on:click="$wire.requestSave(tab)" type="button" class="btn-primary disabled:cursor-not-allowed disabled:opacity-50" wire:loading.attr="disabled" wire:target="save,requestSave,confirmAndSave" @disabled($editLockedByOther)>
            <span wire:loading.remove wire:target="save,requestSave,confirmAndSave">{{ $editingId ? __('vehicle.footer.save_edit') : __('vehicle.footer.save_create') }}</span>
            <span wire:loading wire:target="save,requestSave,confirmAndSave">{{ __('vehicle.footer.saving') }}</span>
        </button>
    </div>

</div>{{-- /panel --}}

{{-- 차량 첨부 미리보기 라이트박스 (이미지·PDF) --}}
<div x-show="lightbox.open" x-cloak x-transition.opacity
     class="fixed inset-0 z-[100] flex flex-col bg-black/80"
     @click.self="closeLightbox()">
    <div class="flex items-center justify-between px-4 py-3 text-white">
        <span class="truncate text-sm" x-text="lightbox.name"></span>
        <div class="flex items-center gap-3">
            <a :href="lightbox.url" target="_blank" rel="noopener"
               class="rounded border border-white/40 px-3 py-1 text-xs hover:bg-white/10">{{ __('vehicle.panel.open_new_tab') }}</a>
            <button type="button" @click="closeLightbox()"
                    class="rounded-full bg-white/20 px-2.5 py-0.5 text-lg leading-none hover:bg-white/30">×</button>
        </div>
    </div>
    <div class="flex flex-1 items-center justify-center overflow-auto p-4" @click.self="closeLightbox()">
        <template x-if="lightbox.kind === 'image'">
            <img :src="lightbox.url" :alt="lightbox.name" class="max-h-full max-w-full object-contain" />
        </template>
        <template x-if="lightbox.kind === 'pdf'">
            <iframe :src="lightbox.url" class="h-full w-full rounded bg-white sm:w-[800px]"></iframe>
        </template>
    </div>
</div>

{{-- 큐 18: close confirm 모달 (.card) --}}
<div x-show="confirmOpen" x-cloak x-transition.opacity
     class="fixed inset-0 z-[60] flex items-center justify-center bg-black/50"
     @click.self="confirmOpen = false">
    <div class="card max-w-sm mx-4 shadow-2xl">
        <h3 class="text-base font-semibold text-gray-900">{{ __('vehicle.modal.close_title') }}</h3>
        <p class="mt-2 text-sm text-gray-600">{{ __('vehicle.modal.close_body') }}</p>
        <div class="mt-5 flex justify-end gap-2">
            <button @click="confirmOpen = false" class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">{{ __('vehicle.modal.cancel') }}</button>
            <button @click="confirmDiscard()" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700">{{ __('vehicle.modal.close_discard') }}</button>
        </div>
    </div>
</div>


</div>{{-- /x-data --}}
@endif

{{-- 메일 발송 모달 (서류 탭) — 바이어에게 업로드 문서 전달.
     ⚠️ 슬라이드 패널 stacking context 밖에 배치 (@if($showPanel) 래퍼 안에 두면 패널 뒤로 감). --}}
@if ($showMailModal)
<div class="fixed inset-0 z-[110] flex items-center justify-center bg-black/50 p-4" wire:key="mail-modal" wire:click.self="closeMailModal">
    <div class="card w-full max-w-lg max-h-[90vh] overflow-y-auto">
        <div class="mb-3 flex items-center justify-between">
            <h3 class="text-base font-bold text-gray-800">{{ __('vehicle.mail.modal_title') }}</h3>
            <button type="button" wire:click="closeMailModal" class="text-xl leading-none text-gray-400 hover:text-gray-600">×</button>
        </div>

        @if ($mailNotice)
            <div class="mb-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">{{ $mailNotice }}</div>
        @endif

        <label class="label-base">{{ __('vehicle.mail.to_label') }}</label>
        <input wire:model="mailTo" type="email" class="input-base w-full" placeholder="buyer@example.com" autocomplete="off" />
        @error('mailTo') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror

        <label class="label-base mt-3">{{ __('vehicle.mail.subject_label') }}</label>
        <input wire:model="mailSubject" type="text" maxlength="150" class="input-base w-full" />

        <label class="label-base mt-3">{{ __('vehicle.mail.body_label') }}</label>
        <textarea wire:model="mailBody" rows="5" class="input-base w-full" placeholder="{{ __('vehicle.mail.body_ph') }}"></textarea>

        <label class="label-base mt-3">{{ __('vehicle.mail.docs_label') }}</label>
        {{-- 그룹별 개별 접기 (jin 2026-07-06): 자동생성=펼침 / 단계파일·기본정보사진·선박사진=접힘 --}}
        @php $mailGroups = [
            ['label' => __('vehicle.mail.group_generated'), 'items' => $mailDocsGen,    'open' => true],
            ['label' => __('vehicle.mail.group_file'),      'items' => $mailDocsFile,   'open' => false],
            ['label' => __('vehicle.mail.group_upload'),    'items' => $mailDocsUpload, 'open' => false],
            ['label' => __('vehicle.mail.group_ship'),      'items' => $mailDocsShip,   'open' => false],
        ]; @endphp
        @if (count($mailDocsGen) + count($mailDocsFile) + count($mailDocsUpload) + count($mailDocsShip) > 0)
            <div class="mt-1 space-y-2">
                @foreach ($mailGroups as $grp)
                    @if (count($grp['items']))
                        <div class="rounded-md border border-gray-100" x-data="{ open: {{ $grp['open'] ? 'true' : 'false' }} }">
                            <button type="button" @click="open = !open"
                                    class="flex w-full items-center justify-between gap-1.5 px-2 py-1.5 text-[11px] font-semibold text-gray-500 hover:bg-gray-50">
                                <span class="flex items-center gap-1.5">
                                    <span class="text-[10px]" x-text="open ? '▼' : '▶'"></span>
                                    {{ $grp['label'] }}
                                </span>
                                <span class="rounded-full bg-gray-100 px-1.5 text-[10px] font-normal text-gray-500">{{ count($grp['items']) }}</span>
                            </button>
                            <div x-show="open" x-cloak class="space-y-1 px-2 pb-2">
                                @foreach ($grp['items'] as $doc)
                                    <label class="flex items-center gap-2 text-sm text-gray-700">
                                        <input type="checkbox" wire:model="mailDocIds" value="{{ $doc['key'] }}" class="rounded border-gray-300" />
                                        <span class="truncate">{{ $doc['name'] }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>
            <p class="mt-1 text-[11px] text-gray-400">{{ __('vehicle.mail.gen_hint') }}</p>
        @else
            <p class="mt-1 text-xs text-gray-400">{{ __('vehicle.mail.no_docs') }}</p>
        @endif

        <div class="mt-4 flex items-center justify-end gap-2">
            <button type="button" wire:click="closeMailModal" class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">{{ __('vehicle.footer.cancel') }}</button>
            <button type="button" wire:click="sendVehicleMail" class="btn-primary" wire:loading.attr="disabled" wire:target="sendVehicleMail">
                <span wire:loading.remove wire:target="sendVehicleMail">{{ __('vehicle.mail.send_btn') }}</span>
                <span wire:loading wire:target="sendVehicleMail">{{ __('vehicle.mail.sending') }}</span>
            </button>
        </div>
    </div>
</div>
@endif

{{-- 큐 21 후속 — 말소·수출통관 체크↔서류 mismatch 확인 모달 (사용자 결정 2026-05-18).
     운영 흐름상 체크/서류 순서가 비순차적이라 강제 차단 대신 모달로 인지 강제.
     슬라이드 패널 stacking context 밖에 배치. close()/save() 끝에서 정리됨. --}}
@if($showDocCheckModal && ! empty($docCheckMismatches))
<div class="fixed inset-0 z-[100] flex items-center justify-center bg-black/60"
     wire:key="doc-check-mismatch-modal">
    <div class="card max-w-lg mx-4 shadow-2xl" @click.stop>
        <h3 class="text-base font-semibold text-gray-900">{{ __('vehicle.modal.doc_title') }}</h3>
        <p class="mt-1 text-xs text-gray-500">{{ __('vehicle.modal.doc_desc') }}</p>

        <div class="mt-3 space-y-2">
            @foreach($docCheckMismatches as $m)
            <div class="rounded-md border border-amber-200 bg-amber-50 p-3 text-xs">
                <div class="font-semibold text-amber-800">{{ $m['label'] }}</div>
                <div class="mt-1 grid grid-cols-2 gap-1 text-amber-700">
                    <div>{{ $m['checked'] ? __('vehicle.modal.doc_checked') : __('vehicle.modal.doc_unchecked') }}</div>
                    <div>{{ $m['has_doc'] ? __('vehicle.modal.doc_uploaded') : __('vehicle.modal.doc_not_uploaded') }}</div>
                </div>
                <div class="mt-1.5 text-[11px] text-amber-600">
                    @if($m['checked'] && ! $m['has_doc'])
                        {{ __('vehicle.modal.doc_checked_no_doc', ['label' => $m['label']]) }}
                    @elseif(! $m['checked'] && $m['has_doc'])
                        {{ __('vehicle.modal.doc_doc_no_check', ['label' => $m['label']]) }}
                    @endif
                </div>
                <button type="button" wire:click="dismissDocCheckModal('{{ $m['tab'] }}')"
                        class="mt-2 text-[11px] text-amber-700 hover:underline">{{ __('vehicle.modal.doc_goto', ['label' => $m['label']]) }}</button>
            </div>
            @endforeach
        </div>

        <div class="mt-3 rounded-md bg-gray-50 border border-gray-200 p-2.5 text-[11px] text-gray-600">
            {{ __('vehicle.modal.doc_note') }}
        </div>

        <div class="mt-4 flex justify-end gap-2">
            <button wire:click="dismissDocCheckModal" type="button"
                    class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">{{ __('vehicle.modal.doc_cancel') }}</button>
            <button wire:click="confirmSaveWithDocMismatch" type="button"
                    class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-700">
                {{ __('vehicle.modal.doc_save_anyway') }}
            </button>
        </div>
    </div>
</div>
@endif

{{-- 큐 21 — Ledger 잠금 해제 모달 (회의록 2026-05-18 + 2026-06-22 jin override = super/admin + 본인 팀 관리).
     슬라이드 패널 stacking context 밖에 배치. close()에서 정리됨. --}}
@if($showLedgerUnlockModal)
<div class="fixed inset-0 z-[100] flex items-center justify-center bg-black/60"
     wire:click.self="closeLedgerUnlockModal"
     wire:key="ledger-unlock-modal">
    <div class="card max-w-md mx-4 shadow-2xl" @click.stop>
        <h3 class="text-base font-semibold text-gray-900">{{ __('vehicle.modal.ledger_title') }}</h3>
        <p class="mt-1 text-xs text-gray-500">{{ __('vehicle.modal.ledger_desc') }}</p>

        <div class="mt-3 rounded-md bg-amber-50 border border-amber-200 p-2.5 text-xs text-amber-800">
            <p>{{ __('vehicle.modal.ledger_warn') }}</p>
        </div>

        <div class="mt-3">
            <label class="label-base">{{ __('vehicle.modal.ledger_reason_label') }} <span class="text-red-500">*</span> <span class="text-gray-400">{{ __('vehicle.modal.ledger_reason_hint') }}</span></label>
            <textarea wire:model="ledgerUnlockReason" rows="4"
                      class="input-base"
                      placeholder="{{ __('vehicle.modal.ledger_reason_ph') }}"></textarea>
            @error('ledgerUnlockReason')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
        </div>

        <div class="mt-4 flex justify-end gap-2">
            <button wire:click="closeLedgerUnlockModal" type="button"
                    class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">{{ __('vehicle.modal.cancel') }}</button>
            <button wire:click="submitLedgerUnlock" type="button"
                    class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-700"
                    wire:loading.attr="disabled" wire:target="submitLedgerUnlock">
                <span wire:loading.remove wire:target="submitLedgerUnlock">{{ __('vehicle.modal.ledger_submit') }}</span>
                <span wire:loading wire:target="submitLedgerUnlock">{{ __('vehicle.footer.processing') }}</span>
            </button>
        </div>
    </div>
</div>
@endif

{{-- 2026-07-09 — 옛 G2 승인요청 모달 제거(ERP 죽은 락). 미수 매입 게이트(②)가 담당. --}}

{{-- UX #3 (2026-05-20) — 영업 저장 확인 모달.
     매입+판매 필수 항목 미리보기 → [확인] 시 save() 실행. 영업이 입력 누락 마지막 검증. --}}
@if($showSaveConfirmModal)
<div class="fixed inset-0 z-[100] flex items-center justify-center bg-black/60"
     wire:click.self="closeSaveConfirmModal"
     wire:key="save-confirm-modal">
    <div class="card max-w-2xl mx-4 shadow-2xl" @click.stop>
        @php
            $tabLabel = in_array($activeTabForSave, ['purchase','sale','bl','clearance'], true)
                ? __('vehicle.panel.tab.'.$activeTabForSave)
                : __('vehicle.save.tab_default');
        @endphp
        <h3 class="text-base font-semibold text-gray-900">{{ __('vehicle.save.title', ['tab' => $tabLabel]) }}</h3>
        <p class="mt-1 text-xs text-gray-500">{{ __('vehicle.save.desc') }}</p>

        <div class="mt-4">
            {{-- 회의확장씬 (2026-05-22) — 4 탭별 미리보기 분기 --}}
            @if($activeTabForSave === 'purchase')
            <div class="rounded-lg border border-blue-200 bg-blue-50/40 p-3">
                <h4 class="mb-2 text-xs font-semibold text-blue-900">{{ __('vehicle.save.preview_purchase') }}</h4>
                <dl class="space-y-1 text-xs text-gray-700">
                    <div class="flex justify-between"><dt class="text-gray-500">{{ __('vehicle.save.f.purchase_date') }}</dt><dd class="font-medium">{{ $purchase_date ?: __('vehicle.save.val_empty') }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">{{ __('vehicle.save.f.purchase_from') }}</dt><dd class="font-medium">{{ $purchase_from ?: __('vehicle.save.val_empty') }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">{{ __('vehicle.save.f.purchase_price') }}</dt><dd class="font-medium">{{ $purchase_price_str ? number_format((float) str_replace(',', '', $purchase_price_str)) : __('vehicle.save.val_empty') }} {{ __('vehicle.save.won') }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">{{ __('vehicle.save.f.selling_fee') }}</dt><dd class="font-medium">{{ $selling_fee_str ? number_format((float) str_replace(',', '', $selling_fee_str)) : '0' }} {{ __('vehicle.save.won') }}</dd></div>
                    <hr class="border-blue-200 my-1">
                    <div class="flex justify-between"><dt class="text-gray-500">{{ __('vehicle.save.f.bank') }}</dt><dd class="font-medium">{{ $purchase_seller_bank ?: __('vehicle.save.val_empty') }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">{{ __('vehicle.save.f.holder') }}</dt><dd class="font-medium">{{ $purchase_seller_holder ?: __('vehicle.save.val_empty') }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">{{ __('vehicle.save.f.account') }}</dt><dd class="font-mono font-medium">{{ $purchase_seller_account ?: __('vehicle.save.val_empty') }}</dd></div>
                    @if($purchase_fee_bank || $purchase_fee_holder || $purchase_fee_account)
                    <hr class="border-blue-200 my-1">
                    <div class="flex justify-between"><dt class="text-gray-500">{{ __('vehicle.panel.sec.fee_account') }}</dt><dd></dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">{{ __('vehicle.save.f.bank') }}</dt><dd class="font-medium">{{ $purchase_fee_bank ?: __('vehicle.save.val_empty') }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">{{ __('vehicle.save.f.holder') }}</dt><dd class="font-medium">{{ $purchase_fee_holder ?: __('vehicle.save.val_empty') }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">{{ __('vehicle.save.f.account') }}</dt><dd class="font-mono font-medium">{{ $purchase_fee_account ?: __('vehicle.save.val_empty') }}</dd></div>
                    @endif
                </dl>
            </div>
            @elseif($activeTabForSave === 'sale')
            <div class="rounded-lg border border-purple-200 bg-purple-50/40 p-3">
                <h4 class="mb-2 text-xs font-semibold text-purple-900">{{ __('vehicle.save.preview_sale') }}</h4>
                <dl class="space-y-1 text-xs text-gray-700">
                    <div class="flex justify-between"><dt class="text-gray-500">{{ __('vehicle.save.f.sale_date') }}</dt><dd class="font-medium">{{ $sale_date ?: __('vehicle.save.val_empty') }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">{{ __('vehicle.save.f.currency') }}</dt><dd class="font-medium">{{ $currency }}</dd></div>
                    @if($currency !== 'KRW')
                    <div class="flex justify-between"><dt class="text-gray-500">{{ __('vehicle.save.f.rate') }}</dt><dd class="font-medium">{{ $exchange_rate_str ?: __('vehicle.save.val_empty') }}</dd></div>
                    @endif
                    <div class="flex justify-between"><dt class="text-gray-500">{{ __('vehicle.save.f.sale_price') }}</dt><dd class="font-medium">{{ $sale_price_str ? number_format((float) str_replace(',', '', $sale_price_str)) : __('vehicle.save.val_empty') }} {{ $currency }}</dd></div>
                    <hr class="border-purple-200 my-1">
                    <div class="flex justify-between">
                        <dt class="text-gray-500">{{ __('vehicle.save.f.buyer') }}</dt>
                        <dd class="font-medium">
                            @php $selectedBuyer = $this->buyers->firstWhere('id', (int) $buyer_id_str); @endphp
                            {{ $selectedBuyer?->name ?: __('vehicle.save.val_unselected') }}
                        </dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500">{{ __('vehicle.save.f.consignee') }}</dt>
                        <dd class="font-medium">
                            @php $selectedConsignee = $this->consigneesForSale->firstWhere('id', (int) $consignee_id_str); @endphp
                            {{ $selectedConsignee?->name ?: __('vehicle.save.val_unselected') }}
                        </dd>
                    </div>
                </dl>
            </div>
            @elseif($activeTabForSave === 'bl')
            <div class="rounded-lg border border-amber-200 bg-amber-50/40 p-3">
                <h4 class="mb-2 text-xs font-semibold text-amber-900">{{ __('vehicle.save.preview_bl') }}</h4>
                <dl class="space-y-1 text-xs text-gray-700">
                    <div class="flex justify-between"><dt class="text-gray-500">{{ __('vehicle.save.f.loading') }}</dt><dd class="font-medium">{{ $bl_loading_location ?: __('vehicle.save.val_empty') }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">{{ __('vehicle.save.f.bl_number') }}</dt><dd class="font-medium">{{ $bl_number ?? '' ?: __('vehicle.save.val_empty') }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">{{ __('vehicle.save.f.container') }}</dt><dd class="font-medium">{{ $container_number ?? '' ?: __('vehicle.save.val_empty') }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">{{ __('vehicle.save.f.vsl') }}</dt><dd class="font-medium">{{ $bl_vsl ?? '' ?: __('vehicle.save.val_empty') }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">{{ __('vehicle.save.f.bl_doc') }}</dt><dd class="font-medium">{{ ($bl_document_path ?? '') || ($blDocFile ?? null) ? __('vehicle.save.val_attached') : __('vehicle.save.val_not_attached') }}</dd></div>
                </dl>
            </div>
            @elseif($activeTabForSave === 'clearance')
            <div class="rounded-lg border border-green-200 bg-green-50/40 p-3">
                <h4 class="mb-2 text-xs font-semibold text-green-900">{{ __('vehicle.save.preview_clearance') }}</h4>
                <dl class="space-y-1 text-xs text-gray-700">
                    <div class="flex justify-between">
                        <dt class="text-gray-500">{{ __('vehicle.save.f.exp_buyer') }}</dt>
                        <dd class="font-medium">
                            @php $expBuyer = $this->buyers->firstWhere('id', (int) ($export_buyer_id_str ?? 0)); @endphp
                            {{ $expBuyer?->name ?: __('vehicle.save.val_unselected') }}
                        </dd>
                    </div>
                    <div class="flex justify-between"><dt class="text-gray-500">{{ __('vehicle.save.f.ship_date') }}</dt><dd class="font-medium">{{ $shipping_date ?: __('vehicle.save.val_empty') }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">{{ __('vehicle.save.f.forwarder') }}</dt>
                        <dd class="font-medium">
                            @php $fc = ($this->forwardingCompanies ?? collect())->firstWhere('id', (int) ($forwarding_company_id_str ?? 0)); @endphp
                            {{ $fc?->name ?: __('vehicle.save.val_unselected') }}
                        </dd>
                    </div>
                    <div class="flex justify-between"><dt class="text-gray-500">{{ __('vehicle.save.f.decl_amount') }}</dt><dd class="font-medium">{{ ($export_declaration_amount_str ?? '') !== '' ? number_format((float) str_replace(',', '', $export_declaration_amount_str)).' '.$currency : __('vehicle.save.val_empty') }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">{{ __('vehicle.save.f.decl_doc') }}</dt><dd class="font-medium">{{ ($export_declaration_document_path ?? '') || ($exportDeclarationDocFile ?? null) ? __('vehicle.save.val_attached') : __('vehicle.save.val_not_attached') }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">{{ __('vehicle.save.f.cleared_check') }}</dt><dd class="font-medium">{{ ($is_export_cleared ?? false) ? __('vehicle.save.val_checked') : __('vehicle.save.val_unchecked') }}</dd></div>
                </dl>
            </div>
            @endif
        </div>

        <div class="mt-3 rounded border border-amber-200 bg-amber-50 px-3 py-2 text-[11px] text-amber-800">
             {{ __('vehicle.save.note') }}
        </div>

        <div class="mt-5 flex justify-end gap-2">
            <button wire:click="closeSaveConfirmModal" type="button"
                    class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">
                {{ __('vehicle.save.back') }}
            </button>
            <button wire:click="confirmAndSave" wire:loading.attr="disabled" wire:target="confirmAndSave,save"
                    class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-medium text-white hover:bg-violet-700">
                <span wire:loading.remove wire:target="confirmAndSave,save">{{ __('vehicle.save.confirm') }}</span>
                <span wire:loading wire:target="confirmAndSave,save">{{ __('vehicle.footer.saving') }}</span>
            </button>
        </div>
    </div>
</div>
@endif

{{-- 큐 19-C — 차량 간 자금 이체 요청 모달 (회의록 v5 §13).
     슬라이드 패널 stacking context 밖에 배치 (overlap 모달과 동일 패턴). --}}
@if($showTransferRequestModal)
@php $transferCtx = $this->transferContext; @endphp
<div class="fixed inset-0 z-[100] flex items-center justify-center bg-black/60"
     wire:click.self="closeTransferRequestModal"
     wire:key="transfer-request-modal">
    <div class="card max-w-md mx-4 shadow-2xl" @click.stop>
        <h3 class="text-base font-semibold text-gray-900">{{ __('vehicle.modal.transfer_req_title') }}</h3>
        <p class="mt-1 text-xs text-gray-500">{{ __('vehicle.modal.transfer_req_desc') }}</p>

        <div class="mt-3 rounded-md bg-violet-50 border border-violet-200 p-2.5 text-xs text-violet-900 space-y-0.5">
            <div>{{ __('vehicle.transfer.received') }} <strong>{{ number_format($transferCtx['received']) }}</strong> {{ $currency ?: 'KRW' }}</div>
            <div>{{ __('vehicle.transfer.limit') }} <strong class="text-violet-700">{{ number_format($transferCtx['limit']) }}</strong> {{ $currency ?: 'KRW' }} {{ __('vehicle.transfer.limit_note') }}</div>
        </div>

        <div class="mt-3 space-y-2">
            <div>
                <label class="label-base">이체 대상 차량 <span class="text-red-500">*</span></label>
                <select wire:model="transferTargetVehicleId" class="input-base">
                    <option value="">{{ __('vehicle.panel.select_placeholder') }}</option>
                    @foreach($transferCtx['candidates'] as $cand)
                    <option value="{{ $cand->id }}">{{ $cand->vehicle_number }} ({{ __('vehicle.modal.sell_price_label') }} {{ number_format($cand->sale_price) }})</option>
                    @endforeach
                </select>
                @error('transferTargetVehicleId')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="label-base">{{ __('vehicle.modal.transfer_amount_label') }} <span class="text-red-500">*</span></label>
                <input wire:model="transferAmountStr" type="text" class="input-base"
                       placeholder="{{ __('vehicle.modal.transfer_amount_ph', ['max' => number_format($transferCtx['limit']), 'currency' => $currency ?: 'KRW']) }}" />
                @error('transferAmountStr')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="label-base">{{ __('vehicle.modal.overlap_reason_label') }} <span class="text-red-500">*</span></label>
                <textarea wire:model="transferReason" rows="3" class="input-base"
                          placeholder="{{ __('vehicle.modal.transfer_reason_ph') }}"></textarea>
                @error('transferReason')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="label-base">{{ __('vehicle.modal.memo_optional') }} <span class="text-[10px] text-gray-400">{{ __('vehicle.modal.optional') }}</span></label>
                <input wire:model="transferNotes" type="text" class="input-base" placeholder="{{ __('vehicle.modal.transfer_notes_ph') }}" />
            </div>
        </div>

        <div class="mt-5 flex justify-end gap-2">
            <button type="button" wire:click="closeTransferRequestModal"
                    class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">{{ __('vehicle.modal.cancel') }}</button>
            <button type="button" wire:click="submitTransferRequest"
                    wire:loading.attr="disabled"
                    class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-medium text-white hover:bg-violet-700 disabled:opacity-50">
                {{ __('vehicle.modal.overlap_send') }}
            </button>
        </div>
    </div>
</div>
@endif

{{-- 큐 19-E — 이체 취소(void) 요청 모달. --}}
@if($showTransferVoidModal && $voidTransferId)
@php
    $voidTarget = \App\Models\InterVehicleTransfer::with('sourceVehicle:id,vehicle_number', 'targetVehicle:id,vehicle_number')->find($voidTransferId);
@endphp
<div class="fixed inset-0 z-[100] flex items-center justify-center bg-black/60"
     wire:click.self="closeTransferVoidModal"
     wire:key="transfer-void-modal">
    <div class="card max-w-md mx-4 shadow-2xl" @click.stop>
        <h3 class="text-base font-semibold text-red-700">{{ __('vehicle.modal.void_title') }}</h3>
        <p class="mt-1 text-xs text-gray-500">{{ __('vehicle.modal.void_desc') }}</p>

        @if($voidTarget)
        <div class="mt-3 rounded-md bg-red-50 border border-red-200 p-2.5 text-xs text-red-900 space-y-0.5">
            <div>{{ __('vehicle.modal.source') }} <span class="font-mono">{{ $voidTarget->sourceVehicle?->vehicle_number ?? '#'.$voidTarget->source_vehicle_id }}</span>
                 → {{ __('vehicle.modal.target') }} <span class="font-mono">{{ $voidTarget->targetVehicle?->vehicle_number ?? '#'.$voidTarget->target_vehicle_id }}</span></div>
            <div>{{ __('vehicle.modal.amount') }} <strong>{{ number_format((float)$voidTarget->amount) }}</strong> {{ $voidTarget->currency }}</div>
        </div>
        @endif

        <div class="mt-3">
            <label class="label-base">{{ __('vehicle.modal.void_reason_label') }} <span class="text-red-500">*</span></label>
            <textarea wire:model="voidReason" rows="3" class="input-base"
                      placeholder="{{ __('vehicle.modal.void_reason_ph') }}"></textarea>
            @error('voidReason')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
        </div>

        <div class="mt-5 flex justify-end gap-2">
            <button type="button" wire:click="closeTransferVoidModal"
                    class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">{{ __('vehicle.modal.cancel') }}</button>
            <button type="button" wire:click="submitTransferVoidRequest"
                    wire:loading.attr="disabled"
                    class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-50">
                {{ __('vehicle.modal.void_send') }}
            </button>
        </div>
    </div>
</div>
@endif

{{-- 작업2 (2026-05-27) — 차량 등록/수정 중 바이어·컨사이니 인라인 quick-add.
     패널 안 닫고 즉석 등록 → 자동 선택. 슬라이드 패널 stacking context 밖(z-100)에 배치. --}}
@if($quickAddOpen)
<div class="fixed inset-0 z-[100] flex items-center justify-center bg-black/60" wire:key="quick-add-modal">
    <div class="card w-full max-w-md mx-4 shadow-2xl" @click.stop>
        <h3 class="text-base font-semibold text-gray-900">
            {{ $quickAddType === 'buyer' ? __('vehicle.modal.qa_buyer_title') : __('vehicle.modal.qa_consignee_title') }}
            <span class="ml-1 text-xs font-normal text-gray-400">
                ({{ ['sale' => __('vehicle.modal.qa_ctx_sale'), 'export' => __('vehicle.modal.qa_ctx_export'), 'bl' => __('vehicle.modal.qa_ctx_bl')][$quickAddContext] ?? '' }})
            </span>
        </h3>
        @if($quickAddType === 'consignee')
        <p class="mt-1 text-xs text-gray-500">{!! __('vehicle.modal.qa_consignee_note', ['buyer' => '<span class="font-medium text-gray-700">'.e($quickAddBuyerName).'</span>']) !!}</p>
        @endif

        <div class="mt-4 space-y-3">
            <div>
                <label class="label-base">{{ $quickAddType === 'buyer' ? __('vehicle.modal.qa_buyer_name') : __('vehicle.modal.qa_consignee_name') }} <span class="text-red-500">*</span></label>
                <input wire:model="qaName" type="text" class="input-base" autofocus />
                @error('qaName')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="label-base">{{ __('vehicle.modal.qa_country') }}</label>
                {{-- 바이어탭과 동일 — 드롭다운 + 자동완성 타이핑 겸용 (2026-07-08). --}}
                <x-country-picker name="qaCountryId" :value="$qaCountryId" wire:key="qa-country-{{ $quickAddType }}-{{ $quickAddContext }}" />
            </div>
            @if($quickAddType === 'buyer')
            <div>
                <label class="label-base">{{ __('vehicle.field.salesman') }}</label>
                <select wire:model="qaSalesmanId" class="input-base">
                    <option value="">{{ __('vehicle.panel.select_placeholder') }}</option>
                    @foreach($this->salesmen as $s)
                    <option value="{{ $s->id }}">{{ $s->name }}</option>
                    @endforeach
                </select>
            </div>
            @endif
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="label-base">{{ __('vehicle.modal.qa_contact') }}</label>
                    <input wire:model="qaContactName" type="text" class="input-base" />
                </div>
                <div>
                    <label class="label-base">{{ __('vehicle.modal.qa_phone') }}</label>
                    <input wire:model="qaContactPhone" type="text" class="input-base" />
                </div>
            </div>
            <p class="text-[11px] text-gray-400">{{ __('vehicle.modal.qa_more') }}</p>
        </div>

        <div class="mt-5 flex justify-end gap-2">
            <button type="button" wire:click="cancelQuickAdd"
                    class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">{{ __('vehicle.modal.cancel') }}</button>
            <button type="button" wire:click="saveQuickAdd" wire:loading.attr="disabled"
                    class="btn-primary px-4 py-2 text-sm">{{ __('vehicle.modal.qa_save') }}</button>
        </div>
    </div>
</div>
@endif

{{-- ══ 미수 매입 게이트 모달 (2026-07-08) — 바이어 미수율 초과 시 신규 등록 차단/승인 ══
     z-[110] + 패널(z-50) 바깥에 렌더 — 새 stacking context에 안 갇히게(뒤로 감 방지, a3cdc30 교훈). --}}
@if($showPurchaseGate)
@php $pg = $this->purchaseGateInfo; $canApprovePg = auth()->user()?->canApproveUnpaidExport(); @endphp
<div class="fixed inset-0 z-[110] flex items-center justify-center bg-black/60 p-4" wire:key="purchase-gate-modal">
    <div class="w-full max-w-md rounded-xl bg-white shadow-2xl" @click.stop>
        <div class="flex items-start gap-3 border-b border-gray-100 px-5 py-4">
            <span class="mt-0.5 text-2xl">🚫</span>
            <div>
                <h3 class="text-base font-bold text-gray-900">{{ __('vehicle.purchase_gate.title') }}</h3>
                <p class="mt-0.5 text-xs text-gray-500">{{ __('vehicle.purchase_gate.subtitle') }}</p>
            </div>
        </div>

        <div class="px-5 py-4">
            {{-- 미수 현황 요약 (드로어/목록 게이지와 동일 수치) --}}
            <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm">
                <div class="font-semibold text-red-800">{{ $pg['buyer'] ?? '' }}</div>
                <div class="mt-1 flex items-center justify-between text-red-700">
                    <span>{{ __('vehicle.purchase_gate.unpaid', ['amount' => number_format((int) ($pg['unpaid'] ?? 0)), 'count' => $pg['count'] ?? 0]) }}</span>
                    <span class="font-bold">{{ __('vehicle.purchase_gate.ratio', ['pct' => $pg['ratio'] ?? 0]) }}</span>
                </div>
            </div>

            @if($canApprovePg)
            <div class="mt-4">
                <label class="label-base">{{ __('vehicle.purchase_gate.reason') }} <span class="text-red-500">*</span></label>
                <textarea wire:model="purchaseGateReason" rows="2" class="input-base"
                          placeholder="{{ __('vehicle.purchase_gate.reason_ph') }}"></textarea>
                @error('purchaseGateReason')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
            </div>
            @else
            <p class="mt-4 text-xs text-gray-500">{{ __('vehicle.purchase_gate.need_manager') }}</p>
            @endif
        </div>

        <div class="flex justify-end gap-2 border-t border-gray-100 px-5 py-4">
            <button type="button" wire:click="cancelPurchaseGate"
                    class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">{{ __('vehicle.modal.cancel') }}</button>
            @if($canApprovePg)
            <button type="button" wire:click="approvePurchaseGate" wire:loading.attr="disabled"
                    class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700">{{ __('vehicle.purchase_gate.approve_btn') }}</button>
            @endif
        </div>
    </div>
</div>
@endif

{{-- ══ 삭제 사유 모달 (2026-07-08) — 회계 연관 차량(확정 잔금·정산) 삭제 시 사유+로그 ══
     리스트 행에서 뜨므로 패널 attemptClose 밖 = 자체 ESC/backdrop 닫기. --}}
@if($showDeleteGate)
<div class="fixed inset-0 z-[110] flex items-center justify-center bg-black/60 p-4" wire:key="delete-gate-modal"
     x-data @keyup.escape.window="$wire.cancelDeleteGate()">
    <div class="fixed inset-0" @click="$wire.cancelDeleteGate()"></div>
    <div class="relative w-full max-w-md rounded-xl bg-white shadow-2xl">
        <div class="flex items-start gap-3 border-b border-gray-100 px-5 py-4">
            <span class="mt-0.5 text-2xl">🗑️</span>
            <div>
                <h3 class="text-base font-bold text-gray-900">{{ __('vehicle.delete_gate.title') }}</h3>
                <p class="mt-0.5 text-xs text-gray-500">{{ $deleteTargetInfo['ctx'] ?? '' }}</p>
            </div>
        </div>
        <div class="px-5 py-4">
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-medium text-amber-800">
                {{ __('vehicle.delete_gate.target', ['number' => $deleteTargetInfo['number'] ?? '']) }}
            </div>
            <div class="mt-4">
                <label class="label-base">{{ __('vehicle.delete_gate.reason') }} <span class="text-red-500">*</span></label>
                <textarea wire:model="deleteReason" rows="2" class="input-base"
                          placeholder="{{ __('vehicle.delete_gate.reason_ph') }}"></textarea>
                @error('deleteReason')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
            </div>
        </div>
        <div class="flex justify-end gap-2 border-t border-gray-100 px-5 py-4">
            <button type="button" wire:click="cancelDeleteGate"
                    class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">{{ __('vehicle.modal.cancel') }}</button>
            <button type="button" wire:click="confirmDeleteWithReason" wire:loading.attr="disabled"
                    class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700">{{ __('vehicle.delete_gate.confirm_btn') }}</button>
        </div>
    </div>
</div>
@endif

{{-- ══ 탁송비 명세서 일괄 기입 모달 (건바이건 비용 — 차량번호 매칭) ══ --}}
@if($showCostImport)
<div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4" wire:key="cost-import-modal">
    <div class="mt-8 w-full max-w-3xl rounded-xl bg-white shadow-2xl">
        {{-- 헤더 --}}
        <div class="flex items-center justify-between border-b border-gray-200 px-5 py-4">
            <div>
                <h3 class="text-base font-bold text-gray-800">{{ __('vehicle.cost_import.title') }}</h3>
                <p class="mt-0.5 text-[11px] text-gray-500">{{ __('vehicle.cost_import.subtitle') }}</p>
            </div>
            <button type="button" wire:click="closeCostImport" class="text-gray-400 hover:text-gray-600">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <div class="max-h-[70vh] overflow-y-auto px-5 py-4">
            {{-- 대상 비용 컬럼 + 거래처(서식) --}}
            <div class="mb-3 flex flex-wrap items-end gap-3">
                <div>
                    <label class="label-base">{{ __('vehicle.cost_import.target_col') }}</label>
                    <select wire:model.live="costImportColumn" class="input-base">
                        @foreach(\App\Models\Vehicle::BULK_COST_UPLOAD_FIELDS as $col)
                        <option value="{{ $col }}">{{ __('vehicle.field.'.$col) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="label-base">{{ __('vehicle.cost_import.company_label') }}</label>
                    <select wire:model.live="costImportCompany" class="input-base">
                        @foreach(\App\Models\Vehicle::COST_IMPORT_COMPANIES[$costImportColumn] ?? [] as $co)
                        <option value="{{ $co }}">{{ __('vehicle.cost_import.company.'.$co) }}</option>
                        @endforeach
                    </select>
                </div>
                <p class="flex-1 text-[11px] text-gray-500">{{ $costImportColumn === 'cost_license' ? __('vehicle.cost_import.lic_col_hint') : __('vehicle.cost_import.col_hint') }}</p>
            </div>

            @if($costImportColumn === 'cost_license' && $costImportCompany === 'seongji')
            {{-- 성지 면허비 = 서류 매핑 안 함(연 1~2회). 선적요청 「2차 비용」 탭에서 총액 n/1 로 진행. --}}
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-4">
                <p class="text-sm leading-relaxed text-amber-800">{{ __('vehicle.cost_import.seongji_notice') }}</p>
                <a href="{{ route('erp.shipping-requests.index', ['tab' => 'cost']) }}" wire:navigate
                   class="mt-3 inline-flex items-center gap-1.5 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover">
                    {{ __('vehicle.cost_import.seongji_goto') }}
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                </a>
            </div>
            @else
            {{-- ① 엑셀 파일 업로드 (권장) — 눈에 띄는 선택 버튼 + 파일명 --}}
            <label class="label-base">{{ $costImportColumn === 'cost_license' ? __('vehicle.cost_import.lic_file_label') : __('vehicle.cost_import.file_label') }}</label>
            <div class="flex flex-wrap items-center gap-2">
                <label class="inline-flex cursor-pointer items-center gap-2 rounded-lg border-2 border-dashed border-primary bg-primary-light px-4 py-2.5 text-sm font-semibold text-primary-text hover:brightness-95">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.9A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                    {{ __('vehicle.cost_import.file_choose') }}
                    <input type="file" wire:model="costImportFile" accept=".xlsx,.xls" class="hidden" />
                </label>
                @if($costImportFile)
                    <span class="inline-flex items-center gap-1 text-xs text-gray-700">
                        <span wire:loading.remove wire:target="costImportFile">📄 {{ $costImportFile->getClientOriginalName() }}</span>
                        <span wire:loading wire:target="costImportFile" class="text-gray-400">{{ __('vehicle.panel.uploading') }}</span>
                    </span>
                @endif
                <button type="button" wire:click="parseCostImportFile" wire:loading.attr="disabled" wire:target="parseCostImportFile,costImportFile"
                        @disabled(! $costImportFile)
                        class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-hover disabled:cursor-not-allowed disabled:opacity-50">
                    <span wire:loading.remove wire:target="parseCostImportFile,costImportFile">{{ __('vehicle.cost_import.file_btn') }}</span>
                    <span wire:loading wire:target="parseCostImportFile,costImportFile">{{ __('vehicle.panel.uploading') }}</span>
                </button>
            </div>
            @error('costImportFile')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror

            {{-- ② 또는 붙여넣기 (위카 탁송비만 — 좌표 파서 회사·면허비는 셀 위치 고정이라 파일 전용) --}}
            @if($costImportColumn === 'cost_towing' && $costImportCompany === 'wika')
            <div class="mt-3 border-t border-gray-100 pt-3">
                <label class="label-base">{{ __('vehicle.cost_import.paste_label') }}</label>
                <textarea wire:model="costImportRaw" rows="4" class="input-base font-mono text-xs"
                          placeholder="{{ __('vehicle.cost_import.paste_ph') }}"></textarea>
                <div class="mt-2 flex justify-end">
                    <button type="button" wire:click="parseCostImport" wire:loading.attr="disabled" wire:target="parseCostImport"
                            class="rounded-lg border border-primary px-3 py-1.5 text-xs font-medium text-primary-text hover:bg-primary-light">
                        {{ __('vehicle.cost_import.parse_btn') }}
                    </button>
                </div>
            </div>
            @endif
            @endif

            {{-- 미리보기 --}}
            @php $matched = $costImportParsed['matched'] ?? []; $unmatched = $costImportParsed['unmatched'] ?? []; @endphp
            @if(count($matched) > 0 || count($unmatched) > 0)
            @php $finalizedCnt = collect($matched)->where('finalized', true)->count(); @endphp
            <hr class="my-3 border-gray-100">
            <div class="mb-2 flex flex-wrap items-center gap-3 text-xs">
                <span class="font-semibold text-emerald-700">{{ __('vehicle.cost_import.matched', ['count' => count($matched) - $finalizedCnt]) }}</span>
                @if($finalizedCnt > 0)
                <span class="font-semibold text-gray-500">{{ __('vehicle.cost_import.finalized', ['count' => $finalizedCnt]) }}</span>
                @endif
                @if(count($unmatched) > 0)
                <span class="font-semibold text-red-600">{{ __('vehicle.cost_import.unmatched', ['count' => count($unmatched)]) }}</span>
                @endif
            </div>

            {{-- 면허비: 수출신고번호별 묶음 요약 (수량 대조 + n/1 분배) --}}
            @if(($costImportParsed['mode'] ?? '') === 'license' && count($costImportParsed['groups'] ?? []) > 0)
            <div class="mb-3 overflow-hidden rounded-lg border border-gray-200">
                <table class="w-full text-xs">
                    <thead class="bg-gray-50 text-gray-500">
                        <tr>
                            <th class="px-3 py-2 text-left font-medium">{{ __('vehicle.cost_import.lic_decl') }}</th>
                            <th class="px-3 py-2 text-left font-medium">{{ __('vehicle.cost_import.lic_carname') }}</th>
                            <th class="px-3 py-2 text-right font-medium">{{ __('vehicle.cost_import.lic_qty') }}</th>
                            <th class="px-3 py-2 text-right font-medium">{{ __('vehicle.cost_import.lic_matched') }}</th>
                            <th class="px-3 py-2 text-right font-medium">{{ __('vehicle.cost_import.lic_total') }}</th>
                            <th class="px-3 py-2 text-right font-medium">{{ __('vehicle.cost_import.lic_per') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($costImportParsed['groups'] as $g)
                        <tr @class(['bg-amber-50/60' => $g['mismatch']])>
                            <td class="px-3 py-1.5 font-mono text-gray-800">{{ $g['decl'] }}</td>
                            <td class="px-3 py-1.5 text-gray-600">{{ $g['car_name'] }}</td>
                            <td class="px-3 py-1.5 text-right text-gray-500">{{ $g['qty'] }}</td>
                            <td class="px-3 py-1.5 text-right {{ $g['mismatch'] ? 'font-semibold text-amber-700' : 'text-gray-700' }}">
                                {{ $g['matched'] }}@if($g['mismatch']) ⚠@endif
                            </td>
                            <td class="px-3 py-1.5 text-right text-gray-700">{{ number_format($g['total']) }}</td>
                            <td class="px-3 py-1.5 text-right font-medium text-primary-text">{{ number_format($g['per']) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if(collect($costImportParsed['groups'])->firstWhere('mismatch', true))
            <p class="mb-2 text-[11px] text-amber-700">{{ __('vehicle.cost_import.lic_mismatch_hint') }}</p>
            @endif
            @endif

            @if(count($matched) > 0)
            <div class="overflow-hidden rounded-lg border border-gray-200">
                <table class="w-full text-xs">
                    <thead class="bg-gray-50 text-gray-500">
                        <tr>
                            <th class="px-3 py-2 text-left font-medium">{{ __('vehicle.col.number') }}</th>
                            <th class="px-3 py-2 text-left font-medium">{{ __('vehicle.col.brand_model') }}</th>
                            <th class="px-3 py-2 text-right font-medium">{{ __('vehicle.cost_import.current') }}</th>
                            <th class="px-3 py-2 text-right font-medium">{{ __('vehicle.cost_import.new_amount') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($matched as $i => $row)
                        @php $isFinal = ! empty($row['finalized']); @endphp
                        <tr @class(['bg-gray-50 text-gray-400' => $isFinal, 'bg-amber-50/50' => ! $isFinal && (int) $row['current'] !== (int) $row['amount']])>
                            <td class="px-3 py-1.5 font-mono font-medium {{ $isFinal ? 'text-gray-400' : 'text-gray-800' }}">
                                {{ $row['number'] }}
                                @if($isFinal)<span class="ml-1 rounded bg-gray-200 px-1 py-0.5 text-[9px] text-gray-500">{{ __('vehicle.cost_import.finalized_badge') }}</span>@endif
                            </td>
                            <td class="px-3 py-1.5 {{ $isFinal ? 'text-gray-400' : 'text-gray-600' }}">{{ $row['model'] }}</td>
                            <td class="px-3 py-1.5 text-right text-gray-400">{{ number_format($row['current']) }}</td>
                            <td class="px-3 py-1.5 text-right">
                                @if($isFinal)
                                    <span class="text-[11px] text-gray-400">{{ __('vehicle.cost_import.protected') }}</span>
                                @else
                                    <input type="text" wire:model="costImportParsed.matched.{{ $i }}.amount"
                                           class="w-24 rounded border border-gray-300 px-2 py-0.5 text-right text-xs focus:border-primary focus:outline-none" />
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @endif

            @if(count($unmatched) > 0)
            <div class="mt-3 rounded-lg border border-red-200 bg-red-50 p-3">
                <p class="mb-1 text-[11px] font-semibold text-red-700">{{ ($costImportParsed['mode'] ?? '') === 'license' ? __('vehicle.cost_import.lic_unmatched_title') : __('vehicle.cost_import.unmatched_title') }}</p>
                <div class="flex flex-wrap gap-1.5">
                    @foreach($unmatched as $u)
                    <span class="rounded bg-white px-2 py-0.5 font-mono text-[11px] text-red-600">{{ $u['number'] }} ({{ number_format($u['amount']) }})</span>
                    @endforeach
                </div>
            </div>
            @endif
            @endif
        </div>

        {{-- 푸터 --}}
        <div class="flex items-center justify-end gap-2 border-t border-gray-200 px-5 py-4">
            <button type="button" wire:click="closeCostImport"
                    class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">{{ __('vehicle.modal.cancel') }}</button>
            <button type="button" wire:click="applyCostImport" wire:loading.attr="disabled" wire:target="applyCostImport"
                    @disabled(count($costImportParsed['matched'] ?? []) === 0)
                    class="btn-primary px-4 py-2 text-sm disabled:cursor-not-allowed disabled:opacity-50">
                {{ __('vehicle.cost_import.apply_btn') }}
            </button>
        </div>
    </div>
</div>
@endif

</div>{{-- /root --}}
