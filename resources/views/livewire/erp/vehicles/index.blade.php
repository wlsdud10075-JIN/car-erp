<?php

use App\Models\Buyer;
use App\Models\Consignee;
use App\Models\ForwardingCompany;
use App\Models\Salesman;
use App\Models\Vehicle;
use App\Models\FinalPayment;
use App\Models\PurchaseBalancePayment;
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
    #[Url] public string $dateType = 'purchase';
    #[Url] public string $dateFrom = '';
    #[Url] public string $dateTo = '';
    #[Url] public string $channelFilter = '';
    #[Url] public string $progressFilter = '';
    public int $perPage = 20;

    // ── 슬라이드 패널 상태 ────────────────────────────────────────
    public bool $showPanel = false;
    public ?int $editingId = null;

    // ── 기본정보 ──────────────────────────────────────────────────
    public string $vehicle_number = '';
    public string $sales_channel = 'export';
    public bool   $is_disposed   = false;
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

    // ── 매입 ──────────────────────────────────────────────────────
    public string $purchase_date = '';
    public string $salesman_id_str = '';
    public string $purchase_from  = '';
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
    public bool   $is_deregistered = false;
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
    public string $deposit_down_payment_str = '';
    public string $interim_payment_str  = '';
    public string $advance_payment1_str = '';
    public string $advance_payment2_str = '';
    public string $savings_used_str     = '';
    public array  $finalPayments = [];

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
    public bool   $is_export_cleared = false;

    // ── 선적 (B/L) ────────────────────────────────────────────────
    public string $bl_buyer_id_str     = '';
    public string $bl_consignee_id_str = '';
    public string $bl_number           = '';
    public string $container_number    = '';
    public string $bl_loading_location = '';
    public string $vessel_name         = '';
    public string $bl_issue_date       = '';

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

    public function mount(): void
    {
        $this->dateFrom = $this->dateFrom ?: now()->subMonths(2)->format('Y-m-d');
        $this->dateTo   = $this->dateTo   ?: now()->format('Y-m-d');
    }

    public function search(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function vehicles()
    {
        $dateColumn = match ($this->dateType) {
            'sale'     => 'sale_date',
            'shipping' => 'shipping_date',
            'bl'       => 'bl_issue_date',
            default    => 'purchase_date',
        };

        $query = Vehicle::query()
            ->with(['buyer', 'salesman', 'finalPayments', 'purchaseBalancePayments'])
            ->when($this->search, fn($q) => $q->where(fn($q2) =>
                $q2->where('vehicle_number', 'like', "%{$this->search}%")
                   ->orWhere('brand', 'like', "%{$this->search}%")
                   ->orWhere('model_type', 'like', "%{$this->search}%")
                   ->orWhere('nice_reg_owner_name', 'like', "%{$this->search}%")
            ))
            ->when($this->channelFilter, fn($q) => $q->where('sales_channel', $this->channelFilter))
            ->when($this->dateFrom, fn($q) => $q->where($dateColumn, '>=', $this->dateFrom))
            ->when($this->dateTo, fn($q) => $q->where($dateColumn, '<=', $this->dateTo))
            ->latest();

        $paginated = $query->paginate($this->perPage);

        // 진행상태 필터 (computed라 collection 필터)
        if ($this->progressFilter) {
            $all = $query->get();
            $filtered = $all->filter(fn($v) => $v->progress_status === $this->progressFilter);
            return new \Illuminate\Pagination\LengthAwarePaginator(
                $filtered->forPage($this->getPage(), $this->perPage),
                $filtered->count(),
                $this->perPage,
                $this->getPage(),
            );
        }

        return $paginated;
    }

    #[Computed]
    public function buyers() { return Buyer::where('is_active', true)->orderBy('name')->get(); }

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

    #[Computed]
    public function salesmen() { return Salesman::where('is_active', true)->orderBy('name')->get(); }

    public function updatedBuyerIdStr(): void { $this->consignee_id_str = ''; unset($this->consigneesForSale); }
    public function updatedExportBuyerIdStr(): void { $this->export_consignee_id_str = ''; unset($this->consigneesForExport); }
    public function updatedBlBuyerIdStr(): void { $this->bl_consignee_id_str = ''; unset($this->consigneesForBl); }

    // ── 패널 열기/닫기 ────────────────────────────────────────────
    public function openCreate(): void
    {
        $this->resetValidation();
        $this->resetForm();
        $this->editingId = null;
        $this->showPanel = true;
    }

    public function openEdit(int $id): void
    {
        $v = Vehicle::with(['finalPayments', 'purchaseBalancePayments'])->findOrFail($id);
        $this->editingId = $id;

        $this->vehicle_number = $v->vehicle_number;
        $this->sales_channel  = $v->sales_channel;
        $this->is_disposed    = $v->is_disposed;
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
        $this->down_payment_str        = $v->down_payment        ? number_format($v->down_payment)        : '';
        $this->selling_fee_payment_str = $v->selling_fee_payment ? number_format($v->selling_fee_payment) : '';
        $this->purchase_remittance_memo = $v->purchase_remittance_memo ?? '';
        $this->is_deregistered = $v->is_deregistered;
        $this->purchaseBalancePayments = $v->purchaseBalancePayments->map(fn($p) => [
            'id' => $p->id, 'amount' => (string)$p->amount,
            'payment_date' => $p->payment_date?->format('Y-m-d') ?? '', 'note' => $p->note ?? '',
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
        $this->deposit_down_payment_str = $v->deposit_down_payment ? (string)$v->deposit_down_payment : '';
        $this->interim_payment_str  = $v->interim_payment  ? (string)$v->interim_payment  : '';
        $this->advance_payment1_str = $v->advance_payment1 ? (string)$v->advance_payment1 : '';
        $this->advance_payment2_str = $v->advance_payment2 ? (string)$v->advance_payment2 : '';
        $this->savings_used_str     = $v->savings_used     ? (string)$v->savings_used     : '';
        $this->finalPayments = $v->finalPayments->map(fn($p) => [
            'id' => $p->id, 'amount' => (string)$p->amount,
            'payment_date' => $p->payment_date?->format('Y-m-d') ?? '', 'note' => $p->note ?? '',
        ])->toArray();

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
        $this->is_export_cleared = $v->is_export_cleared;

        // 선적
        $this->bl_buyer_id_str     = $v->bl_buyer_id     ? (string)$v->bl_buyer_id     : '';
        $this->bl_consignee_id_str = $v->bl_consignee_id ? (string)$v->bl_consignee_id : '';
        $this->bl_number           = $v->bl_number           ?? '';
        $this->container_number    = $v->container_number    ?? '';
        $this->bl_loading_location = $v->bl_loading_location ?? '';
        $this->vessel_name         = $v->vessel_name         ?? '';
        $this->bl_issue_date       = $v->bl_issue_date ? $v->bl_issue_date->format('Y-m-d') : '';

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

        $this->showPanel = true;
    }

    public function close(): void
    {
        $this->resetValidation();
        $this->showPanel = false;
        $this->editingId = null;
    }

    private function validateVehicleForm(): void
    {
        $nonNegativeNumeric = function (string $attribute, mixed $value, \Closure $fail) {
            if ($value === '' || $value === null) {
                return;
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
            'sale_other_costs_str', 'deposit_down_payment_str',
            'interim_payment_str', 'advance_payment1_str',
            'advance_payment2_str', 'savings_used_str',
            'export_declaration_amount_str', 'dhl_weight_str',
        ];

        $rules = [
            'vehicle_number' => [
                'required', 'string', 'max:20',
                Rule::unique('vehicles', 'vehicle_number')->ignore($this->editingId),
            ],
            'sales_channel'   => ['required', 'in:export,heyman,carpul'],
            'currency'        => ['required', 'in:USD,JPY,EUR,GBP,CNY,KRW'],
            'shipping_method' => ['nullable', 'in:RORO,CONTAINER'],

            'purchase_date'       => ['nullable', 'date'],
            'sale_date'           => ['nullable', 'date'],
            'shipping_date'       => ['nullable', 'date'],
            'eta_date'            => ['nullable', 'date'],
            'bl_issue_date'       => ['nullable', 'date'],
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

            'deregistrationDocFile'    => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'exportDeclarationDocFile' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'blDocFile'                => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],

            'finalPayments.*.amount'           => [$nonNegativeNumeric],
            'finalPayments.*.payment_date'     => ['nullable', 'date'],
            'purchaseBalancePayments.*.amount' => [$nonNegativeNumeric],
            'purchaseBalancePayments.*.payment_date' => ['nullable', 'date'],
        ];

        foreach ($numericFields as $field) {
            $rules[$field] = [$nonNegativeNumeric];
        }

        $attributes = [
            'vehicle_number' => '차량번호',
            'sales_channel'  => '판매채널',
            'currency'       => '통화',
            'shipping_method' => '선적방식',
            'purchase_date'  => '매입일',
            'sale_date'      => '판매일',
            'shipping_date'  => '선적일',
            'eta_date'       => 'ETA',
            'bl_issue_date'  => 'B/L 발행일',
            'nice_reg_first_date' => '최초등록일',
            'nice_reg_date'  => '등록일',
            'salesman_id_str' => '영업담당자',
            'buyer_id_str'    => '판매 바이어',
            'consignee_id_str' => '판매 컨사이니',
            'export_buyer_id_str'     => '수출 바이어',
            'export_consignee_id_str' => '수출 컨사이니',
            'forwarding_company_id_str' => '포워딩사',
            'bl_buyer_id_str'    => 'B/L 바이어',
            'bl_consignee_id_str' => 'B/L 컨사이니',
            'deregistrationDocFile'    => '말소서류',
            'exportDeclarationDocFile' => '수출신고서',
            'blDocFile'                => 'B/L 문서',
            'year_str' => '연식', 'cc_str' => '배기량',
            'weight_kg_str' => '중량', 'mileage_str' => '주행거리',
            'purchase_price_str' => '매입가', 'selling_fee_str' => '매도비',
            'sale_price_str' => '판매가', 'exchange_rate_str' => '환율',
            'export_declaration_amount_str' => '면장금액',
            'dhl_weight_str' => 'DHL 중량',
        ];

        $this->validate($rules, [], $attributes);
    }

    public function save(): void
    {
        $this->validateVehicleForm();

        $toInt = fn(?string $v): int => (int) str_replace(',', '', $v ?? '');
        $toFloat = fn(?string $v): float => (float) str_replace(',', '', $v ?? '');
        $toDate = fn(string $v): ?string => $v !== '' ? $v : null;
        $toId = fn(string $v): ?int => $v !== '' ? (int)$v : null;

        $data = [
            'vehicle_number' => $this->vehicle_number,
            'sales_channel'  => $this->sales_channel,
            'is_disposed'    => $this->is_disposed,
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
            'down_payment'          => $toInt($this->down_payment_str),
            'selling_fee_payment'   => $toInt($this->selling_fee_payment_str),
            'purchase_remittance_memo' => $this->purchase_remittance_memo ?: null,
            'is_deregistered'  => $this->is_deregistered,
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
            'deposit_down_payment' => $toFloat($this->deposit_down_payment_str),
            'interim_payment'  => $toFloat($this->interim_payment_str),
            'advance_payment1' => $toFloat($this->advance_payment1_str),
            'advance_payment2' => $toFloat($this->advance_payment2_str),
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
            'is_export_cleared' => $this->is_export_cleared,
            // 선적
            'bl_buyer_id'     => $toId($this->bl_buyer_id_str),
            'bl_consignee_id' => $toId($this->bl_consignee_id_str),
            'bl_number'           => $this->bl_number           ?: null,
            'container_number'    => $this->container_number    ?: null,
            'bl_loading_location' => $this->bl_loading_location ?: null,
            'vessel_name'         => $this->vessel_name         ?: null,
            'bl_issue_date'       => $toDate($this->bl_issue_date),
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
        ];

        \DB::transaction(function () use ($data, $toInt, $toFloat, $toDate) {
            if ($this->editingId) {
                $vehicle = Vehicle::findOrFail($this->editingId);
                $vehicle->update($data);
            } else {
                $vehicle = Vehicle::create($data);
            }

            // 파일 저장 (업로드된 경우만)
            $fileUpdates = [];
            if ($this->deregistrationDocFile) {
                $fileUpdates['deregistration_document'] =
                    $this->deregistrationDocFile->store("vehicles/{$vehicle->id}", 'public');
            }
            if ($this->exportDeclarationDocFile) {
                $fileUpdates['export_declaration_document'] =
                    $this->exportDeclarationDocFile->store("vehicles/{$vehicle->id}", 'public');
            }
            if ($this->blDocFile) {
                $fileUpdates['bl_document'] =
                    $this->blDocFile->store("vehicles/{$vehicle->id}", 'public');
            }
            if ($fileUpdates) {
                $vehicle->update($fileUpdates);
            }

            // 판매 잔금 동기화 (id-diff)
            $existingFinalIds = $vehicle->finalPayments->pluck('id')->toArray();
            $submittedFinalIds = collect($this->finalPayments)->pluck('id')->filter()->toArray();
            FinalPayment::whereIn('id', array_diff($existingFinalIds, $submittedFinalIds))->delete();
            foreach ($this->finalPayments as $row) {
                if ($row['amount'] === '' && $row['payment_date'] === '') continue;
                $amt = $toFloat($row['amount'] ?? '');
                $dt  = $toDate($row['payment_date'] ?? '');
                if (isset($row['id']) && $row['id']) {
                    FinalPayment::where('id', $row['id'])->update(['amount' => $amt, 'payment_date' => $dt, 'note' => $row['note'] ?? null]);
                } else {
                    $vehicle->finalPayments()->create(['amount' => $amt, 'payment_date' => $dt, 'note' => $row['note'] ?? null]);
                }
            }

            // 매입 잔금 동기화
            $existingPurchaseIds = $vehicle->purchaseBalancePayments->pluck('id')->toArray();
            $submittedPurchaseIds = collect($this->purchaseBalancePayments)->pluck('id')->filter()->toArray();
            PurchaseBalancePayment::whereIn('id', array_diff($existingPurchaseIds, $submittedPurchaseIds))->delete();
            foreach ($this->purchaseBalancePayments as $row) {
                if (($row['amount'] ?? '') === '' && ($row['payment_date'] ?? '') === '') continue;
                $amt = $toInt($row['amount'] ?? '');
                $dt  = $toDate($row['payment_date'] ?? '');
                if (isset($row['id']) && $row['id']) {
                    PurchaseBalancePayment::where('id', $row['id'])->update(['amount' => $amt, 'payment_date' => $dt, 'note' => $row['note'] ?? null]);
                } else {
                    $vehicle->purchaseBalancePayments()->create(['amount' => $amt, 'payment_date' => $dt, 'note' => $row['note'] ?? null]);
                }
            }
        });

        $this->unsetComputedProperties();
        $this->close();
        session()->flash('success', $this->editingId ? '차량 정보가 수정됐습니다.' : '차량이 등록됐습니다.');
    }

    public function delete(int $id): void
    {
        Vehicle::findOrFail($id)->delete();
        $this->unsetComputedProperties();
        session()->flash('success', '차량이 삭제됐습니다.');
    }

    public function lookupNiceApi(): void
    {
        if (empty(trim($this->vehicle_number))) return;

        $result = NiceApiService::fromConfig()->lookupVehicle(trim($this->vehicle_number));
        if ($result === null) {
            session()->flash('notice', 'NICE API 조회 실패 — 수동 입력하세요.');
            return;
        }

        foreach ($result['registration'] ?? [] as $key => $value) {
            $prop = $key . '_str';
            if (property_exists($this, $prop)) $this->$prop = (string)$value;
            elseif (property_exists($this, $key)) $this->$key = (string)$value;
        }
        foreach ($result['spec'] ?? [] as $key => $value) {
            $prop = $key . '_str';
            if (property_exists($this, $prop)) $this->$prop = (string)$value;
            elseif (property_exists($this, $key)) $this->$key = (string)$value;
        }
    }

    public function addFinalPayment(): void
    {
        $this->finalPayments[] = ['id' => null, 'amount' => '', 'payment_date' => '', 'note' => ''];
    }
    public function removeFinalPayment(int $idx): void
    {
        unset($this->finalPayments[$idx]);
        $this->finalPayments = array_values($this->finalPayments);
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
            'nice_reg_first_date','nice_reg_date','nice_reg_owner_name','nice_reg_owner_addr',
            'nice_reg_max_load_str','nice_reg_passengers_str','nice_reg_color',
            'nice_spec_maker','nice_spec_model','nice_spec_year','nice_spec_displacement_str',
            'nice_spec_transmission','nice_spec_drive_type','nice_spec_length_str','nice_spec_width_str',
            'nice_spec_height_str','nice_spec_wheelbase_str','nice_spec_curb_weight_str','nice_spec_fuel_efficiency',
            'purchase_date','salesman_id_str','purchase_from','purchase_price_str','selling_fee_str',
            'cost_deregistration_str','cost_license_str','cost_towing_str','cost_carry_str',
            'cost_shoring_str','cost_insurance_str','cost_transfer_str','cost_extra1_str','cost_extra2_str',
            'down_payment_str','selling_fee_payment_str','purchase_remittance_memo',
            'sale_date','exchange_rate_str','buyer_id_str','consignee_id_str',
            'sale_price_str','tax_dc_str','commission_str','transport_fee_str','auto_loading_str',
            'sale_other_costs_str','deposit_down_payment_str','interim_payment_str',
            'advance_payment1_str','advance_payment2_str','savings_used_str',
            'export_buyer_id_str','export_consignee_id_str','forwarding_company_id_str',
            'export_declaration_amount_str','export_declaration_number','shipping_date','eta_date','shipping_method','port_of_loading',
            'bl_buyer_id_str','bl_consignee_id_str','bl_number','container_number',
            'bl_loading_location','vessel_name','bl_issue_date',
            'dhl_recipient_name','dhl_recipient_address','dhl_recipient_phone',
            'dhl_sender_name','dhl_sender_address','dhl_weight_str','dhl_dimensions','memo',
        ];
        foreach ($defaults as $prop) $this->$prop = '';

        $this->sales_channel = 'export';
        $this->currency = 'USD';
        $this->is_disposed = $this->is_deregistered = $this->is_export_cleared = false;
        $this->dhl_request = false;
        $this->finalPayments = $this->purchaseBalancePayments = [];
        $this->deregistrationDocFile = $this->exportDeclarationDocFile = $this->blDocFile = null;
        $this->deregistration_document_path = $this->export_declaration_document_path = $this->bl_document_path = '';
    }

    private function unsetComputedProperties(): void
    {
        unset($this->vehicles);
    }
}; ?>

<div>
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

<div class="flex h-full flex-col gap-4 p-3 md:p-6">

{{-- ── 페이지 헤더 ─────────────────────────────────────────────── --}}
<div class="flex items-center justify-between">
    <div>
        <h1 class="text-xl font-bold text-gray-800">차량 관리</h1>
        <p class="mt-0.5 text-xs text-gray-500">총 {{ $this->vehicles->total() }}대</p>
    </div>
    <button wire:click="openCreate" class="btn-primary">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
        차량 등록
    </button>
</div>

{{-- ── 필터 바 ─────────────────────────────────────────────────── --}}
<div class="space-y-2">
    {{-- 검색 + 날짜 + 조회 --}}
    <div class="flex flex-wrap items-center gap-2 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2">
        <input wire:model="search" wire:keydown.enter="search" type="text" placeholder="차량번호 · 브랜드 · 차종 · 소유자"
               class="input-filter w-52" />
        <select wire:model="dateType" class="input-filter">
            <option value="purchase">매입일</option>
            <option value="sale">판매일</option>
            <option value="shipping">선적일</option>
            <option value="bl">B/L발행일</option>
        </select>
        <input wire:model="dateFrom" type="date" class="input-filter" />
        <span class="text-gray-400 text-sm">~</span>
        <input wire:model="dateTo" type="date" class="input-filter" />
        <button wire:click="search" class="btn-search">조회</button>
    </div>
    {{-- 빠른 탭 필터 --}}
    <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5">
        <div class="flex gap-1">
            @foreach(['' => '전체', 'export' => '수출', 'heyman' => '헤이맨', 'carpul' => '카풀'] as $val => $label)
            <button wire:click="$set('channelFilter', '{{ $val }}')"
                    class="rounded-full px-3 py-1 text-xs font-medium transition
                           {{ $channelFilter === $val ? 'bg-violet-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
                {{ $label }}
            </button>
            @endforeach
        </div>
        <div class="h-4 w-px bg-gray-200 hidden sm:block"></div>
        <div class="flex flex-wrap gap-1">
            @foreach(['' => '전체', '매입중' => '매입중', '매입완료' => '매입완료', '말소완료' => '말소완료', '판매중' => '판매중', '판매완료' => '판매완료', '수출통관중' => '통관중', '수출통관완료' => '통관완료', '선적중' => '선적중', '선적완료' => '선적완료', '거래완료' => '거래완료', '폐기' => '폐기'] as $val => $label)
            <button wire:click="$set('progressFilter', '{{ $val }}')"
                    class="rounded-full px-2.5 py-0.5 text-xs font-medium transition
                           {{ $progressFilter === $val ? 'bg-violet-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
                {{ $label }}
            </button>
            @endforeach
        </div>
    </div>
</div>

{{-- ── 데스크탑 테이블 ─────────────────────────────────────────── --}}
<div class="hidden sm:block overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="border-b border-gray-200 text-left text-xs text-gray-500">
                <th class="pb-2 pr-4 font-medium">차량번호</th>
                <th class="pb-2 pr-4 font-medium">브랜드/차종</th>
                <th class="pb-2 pr-4 font-medium">진행상태</th>
                <th class="pb-2 pr-4 font-medium">채널</th>
                <th class="pb-2 pr-4 font-medium">매입일</th>
                <th class="pb-2 pr-4 font-medium">담당자</th>
                <th class="pb-2 pr-4 font-medium text-right">판매가</th>
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
                    in_array($status, ['수출통관중','수출통관완료'])    => 'badge-amber',
                    in_array($status, ['선적중','선적완료'])           => 'badge-green',
                    $status === '거래완료'                             => 'badge-gray',
                    $status === '폐기'                                 => 'badge-red',
                    default                                            => 'badge-gray',
                };
                $channelBadge = match($v->sales_channel) {
                    'heyman' => 'badge-teal',
                    'carpul' => 'badge-purple',
                    default  => 'badge-blue',
                };
                $channelLabel = match($v->sales_channel) {
                    'heyman' => '헤이맨',
                    'carpul' => '카풀',
                    default  => '수출',
                };
            @endphp
            <tr class="cursor-pointer hover:bg-gray-50 transition" wire:click="openEdit({{ $v->id }})">
                <td class="py-3 pr-4 font-mono font-medium text-gray-800">{{ $v->vehicle_number }}</td>
                <td class="py-3 pr-4 text-gray-700">
                    {{ $v->brand }} {{ $v->model_type }}
                    @if($v->year)<span class="text-xs text-gray-400">({{ $v->year }})</span>@endif
                </td>
                <td class="py-3 pr-4"><span class="badge {{ $badgeClass }}">{{ $status }}</span></td>
                <td class="py-3 pr-4"><span class="badge {{ $channelBadge }}">{{ $channelLabel }}</span></td>
                <td class="py-3 pr-4 text-gray-500">{{ $v->purchase_date?->format('Y-m-d') ?? '-' }}</td>
                <td class="py-3 pr-4 text-gray-500">{{ $v->salesman?->name ?? '-' }}</td>
                <td class="py-3 pr-4 text-right font-medium text-gray-800">
                    @if($v->sale_price > 0)
                        {{ number_format($v->sale_price) }} <span class="text-xs text-gray-400">{{ $v->currency }}</span>
                    @else -
                    @endif
                </td>
                <td class="py-3 text-right">
                    <button wire:click.stop="delete({{ $v->id }})"
                            wire:confirm="차량 {{ $v->vehicle_number }}을 삭제하시겠습니까?"
                            class="text-xs text-red-400 hover:text-red-600">삭제</button>
                </td>
            </tr>
            @empty
            <tr><td colspan="8" class="py-12 text-center text-sm text-gray-400">차량이 없습니다.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

{{-- ── 모바일 카드 리스트 ───────────────────────────────────────── --}}
<div class="block sm:hidden space-y-2">
    @forelse($this->vehicles as $v)
    @php
        $status = $v->progress_status;
        $badgeClass = match(true) {
            in_array($status, ['매입중','매입완료','말소완료']) => 'badge-blue',
            in_array($status, ['판매중','판매완료'])           => 'badge-purple',
            in_array($status, ['수출통관중','수출통관완료'])    => 'badge-amber',
            in_array($status, ['선적중','선적완료'])           => 'badge-green',
            $status === '거래완료'                             => 'badge-gray',
            $status === '폐기'                                 => 'badge-red',
            default                                            => 'badge-gray',
        };
    @endphp
    <div class="card-tight flex items-center justify-between cursor-pointer" wire:click="openEdit({{ $v->id }})">
        <div class="space-y-0.5">
            <div class="font-mono font-semibold text-gray-800">{{ $v->vehicle_number }}</div>
            <div class="text-xs text-gray-500">{{ $v->brand }} {{ $v->model_type }}</div>
            <div class="flex items-center gap-1.5">
                <span class="badge {{ $badgeClass }}">{{ $status }}</span>
                @if($v->sale_price > 0)
                    <span class="text-xs font-medium text-gray-700">{{ number_format($v->sale_price) }} {{ $v->currency }}</span>
                @endif
            </div>
        </div>
        <div class="text-xs text-gray-400 text-right">
            <div>{{ $v->salesman?->name ?? '-' }}</div>
            <div>{{ $v->purchase_date?->format('m/d') ?? '' }}</div>
        </div>
    </div>
    @empty
    <div class="py-12 text-center text-sm text-gray-400">차량이 없습니다.</div>
    @endforelse
</div>

{{-- ── 페이지네이션 ────────────────────────────────────────────── --}}
<div>{{ $this->vehicles->links() }}</div>

</div>{{-- /flex col --}}

{{-- ══════════════════════════════════════════════════════════════ --}}
{{-- 슬라이드 패널                                                  --}}
{{-- ══════════════════════════════════════════════════════════════ --}}
@if($showPanel)
<div x-data="{ tab: 'basic' }">
{{-- Backdrop --}}
<div class="fixed inset-0 z-40 bg-black/40" wire:click="close"></div>

{{-- Panel --}}
<div class="fixed inset-y-0 right-0 z-50 flex w-full flex-col bg-white shadow-2xl sm:w-[700px] lg:w-[820px]">

    {{-- Panel Header --}}
    <div class="flex items-center justify-between border-b border-gray-200 px-5 py-4">
        <div>
            <h2 class="text-base font-bold text-gray-800">
                {{ $editingId ? '차량 수정' : '차량 등록' }}
            </h2>
            @if($vehicle_number)
            <p class="text-xs text-gray-400 font-mono mt-0.5">{{ $vehicle_number }}</p>
            @endif
        </div>
        <button wire:click="close" class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>

    {{-- Tab Nav --}}
    <div class="flex overflow-x-auto border-b border-gray-200 px-5">
        @foreach([
            ['basic',    '기본정보'],
            ['purchase', '매입'],
            ['sale',     '판매'],
            ['clearance','수출통관'],
            ['bl',       '선적(B/L)'],
            ['dhl',      'DHL'],
            ['docs',     '서류'],
        ] as [$key, $label])
        <button @click="tab = '{{ $key }}'"
                :class="tab === '{{ $key }}' ? 'border-b-2 border-violet-600 text-violet-600' : 'text-gray-500 hover:text-gray-700'"
                class="flex-shrink-0 px-4 py-3 text-sm font-medium transition">
            {{ $label }}
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
                <span class="section-title">기본 정보</span>
            </div>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                <div class="col-span-2 sm:col-span-1">
                    <label class="label-base">차량번호 <span class="text-red-500">*</span></label>
                    <div class="flex gap-1">
                        <input wire:model="vehicle_number" type="text" class="input-base flex-1" placeholder="12가3456"
                               wire:blur="lookupNiceApi" />
                        <button type="button" wire:click="lookupNiceApi"
                                class="rounded-lg border border-gray-300 px-2 py-2 text-xs text-gray-600 hover:bg-gray-50 whitespace-nowrap">
                            조회
                        </button>
                    </div>
                    @error('vehicle_number')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="label-base">판매채널</label>
                    <select wire:model="sales_channel" class="input-base">
                        <option value="export">수출</option>
                        <option value="heyman">헤이맨</option>
                        <option value="carpul">카풀</option>
                    </select>
                </div>
                <div class="flex items-end">
                    <label class="flex items-center gap-2 text-sm text-gray-600 cursor-pointer">
                        <input wire:model="is_disposed" type="checkbox" class="rounded" />
                        폐기
                    </label>
                </div>
                <div>
                    <label class="label-base">제조사</label>
                    <input wire:model="brand" type="text" class="input-base" placeholder="현대" />
                </div>
                <div>
                    <label class="label-base">차종</label>
                    <input wire:model="model_type" type="text" class="input-base" placeholder="쏘나타" />
                </div>
                <div>
                    <label class="label-base">연식</label>
                    <input wire:model="year_str" type="number" class="input-base" placeholder="2020" />
                </div>
                <div>
                    <label class="label-base">배기량 (cc)</label>
                    <input wire:model="cc_str" type="number" class="input-base" placeholder="1991" />
                </div>
                <div>
                    <label class="label-base">중량 (kg)</label>
                    <input wire:model="weight_kg_str" type="number" class="input-base" placeholder="1470" />
                </div>
                <div>
                    <label class="label-base">주행거리 (km)</label>
                    <input wire:model="mileage_str" type="number" class="input-base" placeholder="85000" />
                </div>
                <div>
                    <label class="label-base">색상</label>
                    <input wire:model="color" type="text" class="input-base" placeholder="흰색" />
                </div>
            </div>

            <hr class="section-divider">
            <div class="section-header">
                <span class="section-dot bg-blue-400"></span>
                <span class="section-title">NICE 등록정보 (12)</span>
            </div>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                <div><label class="label-base">차대번호</label><input wire:model="nice_reg_vin" type="text" class="input-base" /></div>
                <div><label class="label-base">원동기형식</label><input wire:model="nice_reg_engine_no" type="text" class="input-base" /></div>
                <div><label class="label-base">연료종류</label><input wire:model="nice_reg_fuel_type" type="text" class="input-base" placeholder="가솔린" /></div>
                <div><label class="label-base">용도</label><input wire:model="nice_reg_use_type" type="text" class="input-base" placeholder="자가용" /></div>
                <div><label class="label-base">차체형상</label><input wire:model="nice_reg_vehicle_form" type="text" class="input-base" /></div>
                <div><label class="label-base">최초등록일</label><input wire:model="nice_reg_first_date" type="date" class="input-base" /></div>
                <div><label class="label-base">등록일</label><input wire:model="nice_reg_date" type="date" class="input-base" /></div>
                <div><label class="label-base">소유자명</label><input wire:model="nice_reg_owner_name" type="text" class="input-base" /></div>
                <div><label class="label-base">최대적재량 (kg)</label><input wire:model="nice_reg_max_load_str" type="number" class="input-base" /></div>
                <div><label class="label-base">승차인원</label><input wire:model="nice_reg_passengers_str" type="number" class="input-base" /></div>
                <div><label class="label-base">차량 색상</label><input wire:model="nice_reg_color" type="text" class="input-base" /></div>
                <div class="col-span-2 sm:col-span-1"><label class="label-base">소유자주소</label><input wire:model="nice_reg_owner_addr" type="text" class="input-base" /></div>
            </div>

            <hr class="section-divider">
            <div class="section-header">
                <span class="section-dot bg-sky-400"></span>
                <span class="section-title">NICE 제원정보 (12)</span>
            </div>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                <div><label class="label-base">제조사</label><input wire:model="nice_spec_maker" type="text" class="input-base" /></div>
                <div><label class="label-base">모델명</label><input wire:model="nice_spec_model" type="text" class="input-base" /></div>
                <div><label class="label-base">연식</label><input wire:model="nice_spec_year" type="text" class="input-base" /></div>
                <div><label class="label-base">배기량 (cc)</label><input wire:model="nice_spec_displacement_str" type="number" class="input-base" /></div>
                <div><label class="label-base">변속기</label><input wire:model="nice_spec_transmission" type="text" class="input-base" placeholder="자동" /></div>
                <div><label class="label-base">구동방식</label><input wire:model="nice_spec_drive_type" type="text" class="input-base" placeholder="2WD" /></div>
                <div><label class="label-base">전장 (mm)</label><input wire:model="nice_spec_length_str" type="number" class="input-base" /></div>
                <div><label class="label-base">전폭 (mm)</label><input wire:model="nice_spec_width_str" type="number" class="input-base" /></div>
                <div><label class="label-base">전고 (mm)</label><input wire:model="nice_spec_height_str" type="number" class="input-base" /></div>
                <div><label class="label-base">축거 (mm)</label><input wire:model="nice_spec_wheelbase_str" type="number" class="input-base" /></div>
                <div><label class="label-base">공차중량 (kg)</label><input wire:model="nice_spec_curb_weight_str" type="number" class="input-base" /></div>
                <div><label class="label-base">연비 (km/L)</label><input wire:model="nice_spec_fuel_efficiency" type="text" class="input-base" /></div>
            </div>
        </div>

        {{-- ─── 매입 탭 ──────────────────────────────────── --}}
        <div x-show="tab === 'purchase'" x-cloak>
            <div class="section-header">
                <span class="section-dot bg-blue-500"></span>
                <span class="section-title">매입 기본</span>
            </div>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                <div><label class="label-base">매입일</label><input wire:model="purchase_date" type="date" class="input-base" /></div>
                <div>
                    <label class="label-base">매입담당자</label>
                    <select wire:model="salesman_id_str" class="input-base">
                        <option value="">-- 선택 --</option>
                        @foreach($this->salesmen as $sm)
                        <option value="{{ $sm->id }}">{{ $sm->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-span-2 sm:col-span-1">
                    <label class="label-base">구입처</label>
                    <input wire:model="purchase_from" type="text" class="input-base" placeholder="경매 / 딜러 / 개인" />
                </div>
                <div><label class="label-base">매입가 (원)</label><input wire:model="purchase_price_str" type="text" class="input-base" placeholder="0" /></div>
                <div><label class="label-base">매도비 (원)</label><input wire:model="selling_fee_str" type="text" class="input-base" placeholder="0" /></div>
            </div>

            <hr class="section-divider">
            <div class="section-header">
                <span class="section-dot bg-blue-300"></span>
                <span class="section-title">비용 9개</span>
            </div>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                <div><label class="label-base">말소비</label><input wire:model="cost_deregistration_str" type="text" class="input-base" placeholder="0" /></div>
                <div><label class="label-base">면허비</label><input wire:model="cost_license_str" type="text" class="input-base" placeholder="0" /></div>
                <div><label class="label-base">탁송비</label><input wire:model="cost_towing_str" type="text" class="input-base" placeholder="0" /></div>
                <div><label class="label-base">캐리비</label><input wire:model="cost_carry_str" type="text" class="input-base" placeholder="0" /></div>
                <div><label class="label-base">쇼링비</label><input wire:model="cost_shoring_str" type="text" class="input-base" placeholder="0" /></div>
                <div><label class="label-base">보험료</label><input wire:model="cost_insurance_str" type="text" class="input-base" placeholder="0" /></div>
                <div><label class="label-base">이전비</label><input wire:model="cost_transfer_str" type="text" class="input-base" placeholder="0" /></div>
                <div><label class="label-base">기타비1</label><input wire:model="cost_extra1_str" type="text" class="input-base" placeholder="0" /></div>
                <div><label class="label-base">기타비2</label><input wire:model="cost_extra2_str" type="text" class="input-base" placeholder="0" /></div>
            </div>

            <hr class="section-divider">
            <div class="section-header">
                <span class="section-dot bg-indigo-400"></span>
                <span class="section-title">매입 지급</span>
            </div>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                <div><label class="label-base">계약금 지급</label><input wire:model="down_payment_str" type="text" class="input-base" placeholder="0" /></div>
                <div><label class="label-base">매도비 지급</label><input wire:model="selling_fee_payment_str" type="text" class="input-base" placeholder="0" /></div>
            </div>
            {{-- 잔금 N건 --}}
            <div class="mt-3 space-y-2">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-gray-500">잔금</span>
                    <button type="button" wire:click="addPurchasePayment" class="text-xs text-violet-600 hover:underline">+ 추가</button>
                </div>
                @foreach($purchaseBalancePayments as $idx => $row)
                <div class="flex gap-2 items-center">
                    <input wire:model="purchaseBalancePayments.{{ $idx }}.amount" type="text" class="input-base w-32" placeholder="금액(원)" />
                    <input wire:model="purchaseBalancePayments.{{ $idx }}.payment_date" type="date" class="input-base flex-1" />
                    <input wire:model="purchaseBalancePayments.{{ $idx }}.note" type="text" class="input-base flex-1" placeholder="메모" />
                    <button type="button" wire:click="removePurchasePayment({{ $idx }})" class="text-red-400 hover:text-red-600">×</button>
                </div>
                @endforeach
            </div>

            <hr class="section-divider">
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="label-base">말소완료</label>
                    <label class="flex items-center gap-2 text-sm cursor-pointer mt-1">
                        <input wire:model="is_deregistered" type="checkbox" class="rounded" /> 말소완료
                    </label>
                </div>
                <div>
                    <label class="label-base">말소신청서</label>
                    <input wire:model="deregistrationDocFile" type="file" accept=".pdf,.jpg,.jpeg,.png"
                           class="block w-full text-xs text-gray-500 file:mr-2 file:rounded file:border-0 file:bg-violet-50 file:px-2 file:py-1 file:text-xs file:text-violet-700" />
                    @if($deregistration_document_path)
                    <a href="{{ Storage::url($deregistration_document_path) }}" target="_blank"
                       class="mt-1 block text-xs text-violet-600 hover:underline">기존 파일 보기</a>
                    @endif
                </div>
                <div class="col-span-2">
                    <label class="label-base">송금메모</label>
                    <textarea wire:model="purchase_remittance_memo" class="input-base" rows="2"></textarea>
                </div>
            </div>
        </div>

        {{-- ─── 판매 탭 ──────────────────────────────────── --}}
        <div x-show="tab === 'sale'" x-cloak>
            <div class="section-header">
                <span class="section-dot bg-purple-500"></span>
                <span class="section-title">판매 기본</span>
            </div>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                <div><label class="label-base">판매일</label><input wire:model="sale_date" type="date" class="input-base" /></div>
                <div>
                    <label class="label-base">통화</label>
                    <select wire:model="currency" class="input-base">
                        @foreach(['USD','JPY','EUR','GBP','CNY','KRW'] as $cur)
                        <option value="{{ $cur }}">{{ $cur }}</option>
                        @endforeach
                    </select>
                </div>
                <div><label class="label-base">환율</label><input wire:model="exchange_rate_str" type="text" class="input-base" placeholder="1350" /></div>
                <div>
                    <label class="label-base">바이어</label>
                    <select wire:model.live="buyer_id_str" class="input-base">
                        <option value="">-- 선택 --</option>
                        @foreach($this->buyers as $b)
                        <option value="{{ $b->id }}">{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="label-base">컨사이니</label>
                    <select wire:model="consignee_id_str" class="input-base"
                            @if($buyer_id_str === '') disabled @endif>
                        <option value="">{{ $buyer_id_str ? '-- 선택 --' : '바이어 먼저 선택' }}</option>
                        @foreach($this->consigneesForSale as $c)
                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div><label class="label-base">판매가</label><input wire:model="sale_price_str" type="text" class="input-base" placeholder="0" /></div>
                <div><label class="label-base">TAX D/C</label><input wire:model="tax_dc_str" type="text" class="input-base" placeholder="0" /></div>
                <div><label class="label-base">Commission</label><input wire:model="commission_str" type="text" class="input-base" placeholder="0" /></div>
                <div><label class="label-base">운임비</label><input wire:model="transport_fee_str" type="text" class="input-base" placeholder="0" /></div>
                <div><label class="label-base">자동하역비</label><input wire:model="auto_loading_str" type="text" class="input-base" placeholder="0" /></div>
                <div><label class="label-base">기타 판매비용</label><input wire:model="sale_other_costs_str" type="text" class="input-base" placeholder="0" /></div>
            </div>

            <hr class="section-divider">
            <div class="section-header">
                <span class="section-dot bg-purple-300"></span>
                <span class="section-title">입금 현황</span>
            </div>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                <div><label class="label-base">계약금 입금</label><input wire:model="deposit_down_payment_str" type="text" class="input-base" placeholder="0" /></div>
                <div><label class="label-base">중도금</label><input wire:model="interim_payment_str" type="text" class="input-base" placeholder="0" /></div>
                <div><label class="label-base">선수금1</label><input wire:model="advance_payment1_str" type="text" class="input-base" placeholder="0" /></div>
                <div><label class="label-base">선수금2</label><input wire:model="advance_payment2_str" type="text" class="input-base" placeholder="0" /></div>
                <div><label class="label-base">적립금 사용</label><input wire:model="savings_used_str" type="text" class="input-base" placeholder="0" /></div>
            </div>
            {{-- 잔금 N건 --}}
            <div class="mt-3 space-y-2">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-gray-500">잔금</span>
                    <button type="button" wire:click="addFinalPayment" class="text-xs text-violet-600 hover:underline">+ 추가</button>
                </div>
                @foreach($finalPayments as $idx => $row)
                <div class="flex gap-2 items-center">
                    <input wire:model="finalPayments.{{ $idx }}.amount" type="text" class="input-base w-32" placeholder="금액" />
                    <input wire:model="finalPayments.{{ $idx }}.payment_date" type="date" class="input-base flex-1" />
                    <input wire:model="finalPayments.{{ $idx }}.note" type="text" class="input-base flex-1" placeholder="메모" />
                    <button type="button" wire:click="removeFinalPayment({{ $idx }})" class="text-red-400 hover:text-red-600">×</button>
                </div>
                @endforeach
            </div>
        </div>

        {{-- ─── 수출통관 탭 ───────────────────────────────── --}}
        <div x-show="tab === 'clearance'" x-cloak>
            <div class="section-header">
                <span class="section-dot bg-amber-500"></span>
                <span class="section-title">수출통관</span>
            </div>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                <div>
                    <label class="label-base">통관 바이어</label>
                    <select wire:model.live="export_buyer_id_str" class="input-base">
                        <option value="">-- 선택 --</option>
                        @foreach($this->buyers as $b)
                        <option value="{{ $b->id }}">{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="label-base">통관 컨사이니</label>
                    <select wire:model="export_consignee_id_str" class="input-base"
                            @if($export_buyer_id_str === '') disabled @endif>
                        <option value="">{{ $export_buyer_id_str ? '-- 선택 --' : '바이어 먼저 선택' }}</option>
                        @foreach($this->consigneesForExport as $c)
                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="label-base">포워딩사</label>
                    <select wire:model="forwarding_company_id_str" class="input-base">
                        <option value="">-- 선택 --</option>
                        @foreach($this->forwardingCompanies as $f)
                        <option value="{{ $f->id }}">{{ $f->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div><label class="label-base">면장금액 (USD)</label><input wire:model="export_declaration_amount_str" type="text" class="input-base" placeholder="0.00" /></div>
                <div><label class="label-base">수출신고번호</label><input wire:model="export_declaration_number" type="text" class="input-base" placeholder="123-12-123456" /></div>
                <div><label class="label-base">선적일</label><input wire:model="shipping_date" type="date" class="input-base" /></div>
                <div><label class="label-base">ETA</label><input wire:model="eta_date" type="date" class="input-base" /></div>
                <div>
                    <label class="label-base">선적방법</label>
                    <select wire:model="shipping_method" class="input-base">
                        <option value="">-- 선택 --</option>
                        <option value="RORO">RORO</option>
                        <option value="CONTAINER">CONTAINER</option>
                    </select>
                </div>
                <div><label class="label-base">선적항</label><input wire:model="port_of_loading" type="text" class="input-base" placeholder="부산항" /></div>
                <div class="flex items-end">
                    <label class="flex items-center gap-2 text-sm cursor-pointer">
                        <input wire:model="is_export_cleared" type="checkbox" class="rounded" /> 수출통관 완료
                    </label>
                </div>
                <div class="col-span-2 sm:col-span-3">
                    <label class="label-base">수출신고서 <span class="text-xs text-gray-400">(업로드 시 수출통관완료 상태 달성 가능)</span></label>
                    <input wire:model="exportDeclarationDocFile" type="file" accept=".pdf,.jpg,.jpeg,.png"
                           class="block w-full text-xs text-gray-500 file:mr-2 file:rounded file:border-0 file:bg-amber-50 file:px-2 file:py-1 file:text-xs file:text-amber-700" />
                    @if($export_declaration_document_path)
                    <a href="{{ Storage::url($export_declaration_document_path) }}" target="_blank"
                       class="mt-1 block text-xs text-violet-600 hover:underline">기존 파일 보기</a>
                    @endif
                </div>
            </div>
        </div>

        {{-- ─── B/L 탭 ────────────────────────────────────── --}}
        <div x-show="tab === 'bl'" x-cloak>
            <div class="section-header">
                <span class="section-dot bg-emerald-500"></span>
                <span class="section-title">선적 (B/L)</span>
            </div>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                <div>
                    <label class="label-base">선적 바이어</label>
                    <select wire:model.live="bl_buyer_id_str" class="input-base">
                        <option value="">-- 선택 --</option>
                        @foreach($this->buyers as $b)
                        <option value="{{ $b->id }}">{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="label-base">선적 컨사이니</label>
                    <select wire:model="bl_consignee_id_str" class="input-base"
                            @if($bl_buyer_id_str === '') disabled @endif>
                        <option value="">{{ $bl_buyer_id_str ? '-- 선택 --' : '바이어 먼저 선택' }}</option>
                        @foreach($this->consigneesForBl as $c)
                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div><label class="label-base">B/L 번호</label><input wire:model="bl_number" type="text" class="input-base" /></div>
                <div><label class="label-base">컨테이너 번호</label><input wire:model="container_number" type="text" class="input-base" /></div>
                <div><label class="label-base">반입지</label><input wire:model="bl_loading_location" type="text" class="input-base" placeholder="부산신항 3부두" /></div>
                <div><label class="label-base">VSL (선박명)</label><input wire:model="vessel_name" type="text" class="input-base" /></div>
                <div><label class="label-base">B/L 발행일</label><input wire:model="bl_issue_date" type="date" class="input-base" /></div>
                <div class="col-span-2 sm:col-span-3">
                    <label class="label-base">B/L 문서 <span class="text-xs text-gray-400">(업로드 시 선적완료 상태 달성 가능)</span></label>
                    <input wire:model="blDocFile" type="file" accept=".pdf,.jpg,.jpeg,.png"
                           class="block w-full text-xs text-gray-500 file:mr-2 file:rounded file:border-0 file:bg-emerald-50 file:px-2 file:py-1 file:text-xs file:text-emerald-700" />
                    @if($bl_document_path)
                    <a href="{{ Storage::url($bl_document_path) }}" target="_blank"
                       class="mt-1 block text-xs text-violet-600 hover:underline">기존 파일 보기</a>
                    @endif
                </div>
            </div>
        </div>

        {{-- ─── DHL 탭 ────────────────────────────────────── --}}
        <div x-show="tab === 'dhl'" x-cloak>
            <div class="section-header">
                <span class="section-dot bg-teal-500"></span>
                <span class="section-title">DHL 수취인</span>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="label-base">수취인 성명</label><input wire:model="dhl_recipient_name" type="text" class="input-base" /></div>
                <div><label class="label-base">수취인 연락처</label><input wire:model="dhl_recipient_phone" type="text" class="input-base" /></div>
                <div class="col-span-2"><label class="label-base">수취인 주소</label><input wire:model="dhl_recipient_address" type="text" class="input-base" /></div>
            </div>
            <hr class="section-divider">
            <div class="section-header">
                <span class="section-dot bg-teal-300"></span>
                <span class="section-title">DHL 발송인</span>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="label-base">발송인 성명</label><input wire:model="dhl_sender_name" type="text" class="input-base" /></div>
                <div class="col-span-2"><label class="label-base">발송인 주소</label><input wire:model="dhl_sender_address" type="text" class="input-base" /></div>
                <div><label class="label-base">중량 (kg)</label><input wire:model="dhl_weight_str" type="text" class="input-base" placeholder="1.5" /></div>
                <div><label class="label-base">크기 (W×H×L cm)</label><input wire:model="dhl_dimensions" type="text" class="input-base" placeholder="30x20x10" /></div>
                <div class="col-span-2 flex items-center gap-2">
                    <label class="flex items-center gap-2 text-sm cursor-pointer">
                        <input wire:model="dhl_request" type="checkbox" class="rounded" /> DHL 발송신청 완료
                    </label>
                </div>
            </div>
        </div>

        {{-- ─── 서류 탭 ───────────────────────────────────── --}}
        <div x-show="tab === 'docs'" x-cloak>
            <div class="flex h-40 items-center justify-center rounded-xl border border-dashed border-gray-300 text-sm text-gray-400">
                서류 자동생성은 추후 구현 예정입니다.
            </div>
        </div>

        {{-- ─── 메모 (공통) ──────────────────────────────── --}}
        <div class="mt-5">
            <label class="label-base">메모</label>
            <textarea wire:model="memo" class="input-base" rows="2" placeholder="내부 메모"></textarea>
        </div>

        </div>
    </div>

    {{-- Panel Footer --}}
    <div class="flex items-center justify-end gap-2 border-t border-gray-200 px-5 py-4">
        <button wire:click="close" type="button"
                class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">
            취소
        </button>
        <button wire:click="save" type="button" class="btn-primary" wire:loading.attr="disabled" wire:target="save">
            <span wire:loading.remove wire:target="save">저장</span>
            <span wire:loading wire:target="save">저장 중...</span>
        </button>
    </div>

</div>{{-- /panel --}}
</div>{{-- /x-data --}}
@endif

</div>{{-- /root --}}
