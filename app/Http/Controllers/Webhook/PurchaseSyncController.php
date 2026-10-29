<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Buyer;
use App\Models\Consignee;
use App\Models\Salesman;
use App\Models\Setting;
use App\Models\TaskAlarm;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehiclePhoto;
use App\Services\BizmAlimtalkService;
use App\Services\NiceApiService;
use App\Support\AlimtalkRecipients;
use App\Support\PhoneFormat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/**
 * 연동 B 수신측 — board(매입보드)가 status='won' 낙찰차를 car-erp 매입 재고로
 * 단방향 push 하는 것을 받는다. 발신(권위=payload) = board SKILLS §12.
 * 수신 스펙(권위) = docs/integration/purchase-sync-receiver.md.
 *
 * HMAC 인증은 VerifyPurchaseSyncHmac 미들웨어가 선처리(라우트 레벨). 여기는 검증된
 * 요청만 도달.
 *
 * ⚠️ 매칭/멱등 키 = vehicle_number (VIN 아님). board 는 VIN 을 모른다 — VIN 은 NICE
 *    차량조회로만 나오고 그건 car-erp 책임. board 는 vehicle_number + owner_name 을
 *    보내고, car-erp 가 NICE(차량번호+소유자명)로 VIN·차량정보를 채운다.
 */
class PurchaseSyncController extends Controller
{
    /**
     * 직전 syncAttachments() 에서 **붙이지 못한** 첨부 수.
     *
     * board 는 응답 코드(200/201)만 보고 성공으로 기록하므로, 첨부가 통째로 실패해도
     * 양쪽 다 모르는 상태가 된다(2026-08-10 사고 — 15건이 그렇게 쌓였다).
     * 그래서 응답 바디에 실어 보낸다.
     */
    private int $attachmentsFailed = 0;

    /**
     * 지원 계약 버전. 미지원 → 422.
     * v2 = attachments[] 추가(전방호환, v1 도 계속 수용).
     * v3 = 금액/바이어/컨사이니 확장 — purchase_price_krw·selling_fee_krw·transport_fee·
     *      sale_price·sale_currency·sale_exchange_rate·buyer_id·consignee_id (모두 optional).
     * v4 = 매도비 계좌 3필드 — selling_fee_payee_name/bank/account (매도비 별도 계좌, 모두 optional).
     * v5 = 재고매입(바이어 미정) — buyer_undecided (optional).
     *      ⚠️ 문서의 「연동 B v5 — fill-if-empty」(2026-08-18)와 **다른 것**이다. 그건 contract_version
     *      상향이 없는 기능 이름이고, 여기 5 는 실제 계약 버전이다.
     */
    private const SUPPORTED_VERSIONS = [1, 2, 3, 4, 5];

    /** sale_currency 허용 enum (vehicles.currency 와 동일). */
    private const SALE_CURRENCIES = ['USD', 'JPY', 'EUR', 'GBP', 'CNY', 'KRW'];

    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->json()->all();

        // 전방호환 — contract_version 검사 (모르는 *필드*는 무시하되 *버전*은 게이트).
        $version = (int) ($payload['contract_version'] ?? 0);
        if (! in_array($version, self::SUPPORTED_VERSIONS, true)) {
            Log::warning('[purchase-sync] 미지원 contract_version', ['version' => $version]);

            return response()->json([
                'message' => 'Unsupported contract_version',
                'supported' => self::SUPPORTED_VERSIONS,
            ], 422);
        }

        $validator = Validator::make($payload, [
            'vehicle_number' => ['required', 'string', 'max:255'],
            'owner_name' => ['nullable', 'string', 'max:255'],
            'source' => ['required', 'string', 'in:encar,auction'],
            // v3 는 purchase_price_krw 로 대체 가능 → 둘 중 하나는 필수(전방호환).
            'final_price' => ['required_without:purchase_price_krw', 'nullable', 'integer', 'min:0'],
            'salesman_email' => ['required', 'email'],
            'car_erp_salesman_id' => ['nullable', 'integer'],
            'c_no' => ['nullable', 'string', 'max:255'],
            'payee_name' => ['nullable', 'string', 'max:100'],
            'payee_bank' => ['nullable', 'string', 'max:100'],
            'payee_account' => ['nullable', 'string', 'max:255'],
            // 연동 B v4 — 매도비 계좌 (매입가 계좌와 별도 주체, 모두 optional/전방호환).
            'selling_fee_payee_name' => ['nullable', 'string', 'max:100'],
            'selling_fee_payee_bank' => ['nullable', 'string', 'max:100'],
            'selling_fee_payee_account' => ['nullable', 'string', 'max:255'],
            // 연동 B v3 — 금액/바이어/컨사이니 확장 (모두 optional, 전방호환).
            'purchase_price_krw' => ['nullable', 'integer', 'min:0'],   // 구입금액(차값−할인)만 — final_price 부풀림 교정
            'selling_fee_krw' => ['nullable', 'integer', 'min:0'],      // 매도비 (별도 컬럼)
            'transport_fee' => ['nullable', 'numeric', 'min:0'],        // 운임비 — ⚠️ 판매통화(board 가 환산해 보냄), USD raw 아님
            // 2026-10-22 (jin) — 버전 그대로 optional 2개. 둘 다 메모(계산 미포함). USD 운임비는 정수(달러)로 반올림.
            'transport_fee_usd' => ['nullable', 'numeric', 'min:0'],    // 운임비 USD raw → vehicles.transport_fee_usd
            'usd_exchange_rate' => ['nullable', 'numeric', 'min:0'],    // 그때 USD 환율 → vehicles.usd_exchange_rate (판매통화 환율 아님)
            'sale_price' => ['nullable', 'numeric', 'min:0'],           // pre-fill, 관리 편집
            'sale_currency' => ['nullable', 'string', 'in:'.implode(',', self::SALE_CURRENCIES)],
            'sale_exchange_rate' => ['nullable', 'numeric', 'min:0'],   // pre-fill, 관리가 지정시점 환율로 덮어씀
            'buyer_id' => ['nullable', 'integer'],
            'consignee_id' => ['nullable', 'integer'],
            // 2026-11 ④ — 딜러 서류 OCR 동봉(법인명·대표자·휴대폰). 버전 상향 없음. ERP 는 OCR 하지 않는다 —
            //   영업이 board 드로어에서 [적용]한 값만 온다. 저장 규칙 = purchase-sync-receiver.md 「딜러 서류 OCR 동봉」.
            'seller_company' => ['nullable', 'string', 'max:100'],
            'seller_ceo' => ['nullable', 'string', 'max:50'],
            'seller_phone' => ['nullable', 'string', 'max:20'],   // 숫자만 오는 게 계약이지만 하이픈이 섞여도 PhoneFormat 이 걸러낸다(세트 통째 422 는 과하다)
            // 2026-11 ② — 송금인 Name·ID 동봉(won 전에 적은 경우). 저장·화면·검색 = board-portal-api.md §14. 빈 칸만.
            'remitter_name' => ['nullable', 'string', 'max:100'],
            'remitter_id' => ['nullable', 'string', 'max:64'],   // 숫자만 오는 게 계약이지만 하이픈이 섞여도 PhoneFormat 이 걸러낸다(세트 통째 422 는 과하다)
            // 연동 B v5 — 재고매입(바이어 미정). board 가 「바이어를 일부러 안 정하고 사 두는 매입」임을
            //   명시한다. 🚫 `buyer_id` 가 비었다는 사실만으로는 대신할 수 없다 — 그러면 **실수로 빠뜨린
            //   차**와 구분이 안 되고, 그 구분이 이 플래그의 존재 이유다(vehicles/index.blade.php:4629).
            'buyer_undecided' => ['nullable', 'boolean'],
            // 2026-10-29 (jin, board 세션 결정) — 재전송 금액 **덮어쓰기** 플래그. board 「금액만 ERP 로 보내기」·저장 시 금액 변경 재전송에서만 붙는다.
            //   판매측 6칸(sale_price·sale_currency·sale_exchange_rate·transport_fee·transport_fee_usd·usd_exchange_rate)만. 신규 생성엔 무의미.
            'amounts_overwrite' => ['nullable', 'boolean'],
            // 연동 B v2 — 차량 사진/서류 첨부(공유 S3 키만, 바이트 아님). 전방호환: 없으면 무시.
            'attachments' => ['nullable', 'array', 'max:50'],
            'attachments.*.s3_path' => ['required_with:attachments', 'string', 'max:1024'],
            'attachments.*.original_name' => ['nullable', 'string', 'max:255'],
            'attachments.*.kind' => ['nullable', 'string', 'in:sales_photo,sales_document'],
            'attachments.*.sort' => ['nullable', 'integer'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        // ── 멱등: vehicle_number 사전조회 ───────────────────────────────
        // 동일 차량번호가 이미 있으면 새로 만들지 않고 기존 id 반환(스킵). NICE 재호출 방지. 200.
        $existing = Vehicle::where('vehicle_number', $data['vehicle_number'])->first();
        if ($existing) {
            // 멱등 — 신규 생성/NICE 재호출은 스킵하되, 첨부가 오면 dedup 으로 보강(방어적).
            $synced = $this->syncAttachments($existing, $data['attachments'] ?? []);
            // 2026-08-18 (board 인계 ①) — 급해서 매입가만 보낸 차의 **판매가를 나중에 채워 재전송**하면
            //   비어 있는 칸만 채운다. 이미 값이 있으면 안 건드린다(관리가 ERP 에서 고친 값 보호).
            [$filled, $skipped, $overwritten] = $this->fillEmptyFields($existing, $data);
            Log::info('[purchase-sync] 멱등 스킵 — 기존 vehicle_number', [
                'vehicle_id' => $existing->id,
                'vehicle_number' => $data['vehicle_number'],
                'attachments_added' => $synced,
                'attachments_failed' => $this->attachmentsFailed,
                'fields_filled' => $filled,
                'fields_skipped' => $skipped,
                'fields_overwritten' => $overwritten,
            ]);

            return response()->json([
                'vehicle_id' => $existing->id,
                // board 가 첨부 실패를 알 수 있는 유일한 통로 — 200 만 보고 성공으로 기록하던 것을 막는다.
                'attachments_added' => $synced,
                'attachments_failed' => $this->attachmentsFailed,
                // 같은 이유로 "무엇을 채웠고 무엇을 왜 안 채웠나" 도 응답에 싣는다.
                //   board 가 200 만 보고 "판매가 반영됨" 으로 기록하면 조용한 실패가 된다.
                'fields_filled' => $filled,
                'fields_skipped' => (object) $skipped,
                // 2026-10-29 — amounts_overwrite 로 **값이 있었는데 바뀐** 칸(같은 값이면 안 들어간다). board 배너 「덮어씀」.
                'fields_overwritten' => $overwritten,
            ], 200);
        }

        // ── 영업 매칭 ──────────────────────────────────────────────────
        // car_erp_salesman_id(명시 오버라이드) 우선 → 없으면 salesman_email 매칭.
        // 둘 다 못 찾으면 salesman_id = null(수동 배정 대기).
        $salesmanId = $this->resolveSalesmanId($data['car_erp_salesman_id'] ?? null, $data['salesman_email']);

        // ── vehicle 생성 (매입 단계) ───────────────────────────────────
        // sales_channel 은 set 하지 않음 — enum 이 'export' 단일로 축소됨(2026-05-14). default 사용.
        $vehicle = new Vehicle;
        $vehicle->vehicle_number = $data['vehicle_number'];
        $vehicle->progress_status_rule_version = 4;
        $vehicle->purchase_source = $data['source'];
        $vehicle->c_no = $data['c_no'] ?? null;
        // v3: purchase_price_krw(구입금액만) 우선 → 없으면 final_price(v2 호환, 매도비·배송 포함 부풀림).
        $vehicle->purchase_price = $data['purchase_price_krw'] ?? $data['final_price'] ?? 0;
        $vehicle->salesman_id = $salesmanId;
        $vehicle->purchase_date = now()->toDateString();

        // v3 — 매입측 금액 확장 (값 있을 때만 채움, 관리가 이후 편집).
        if (isset($data['selling_fee_krw'])) {
            $vehicle->selling_fee = $data['selling_fee_krw'];
        }
        if (isset($data['transport_fee'])) {
            // ⚠️ 운임비 = 판매통화 기준. sale_total_amount(미수율 분모) = sale_price + transport_fee + …
            //    뒤에서 단일 exchange_rate 로 곱해짐(Vehicle::getSaleTotalAmountAttribute) → USD raw 저장 시
            //    EUR 판매차에서 EUR 환율로 곱해져 부풀음. board 가 판매통화로 환산해 보냄(2026-06-23 버그수정).
            $vehicle->transport_fee = $data['transport_fee'];
        }
        // 2026-10-22 — USD 운임비·USD 환율 메모. 컨테이너 차는 board 가 비워 보내고, 선적요청 1/N 몫이 나중에 채운다
        //   (ShippingRequestController — 그쪽도 빈 칸만 채움).
        if ((float) ($data['transport_fee_usd'] ?? 0) > 0) {
            $vehicle->transport_fee_usd = (int) round((float) $data['transport_fee_usd']);
        }
        if ((float) ($data['usd_exchange_rate'] ?? 0) > 0) {
            $vehicle->usd_exchange_rate = $data['usd_exchange_rate'];
        }

        // v3 — 바이어/컨사이니 FK (존재·활성 검증, consignee 는 buyer 하위. 무효면 null).
        [$buyerId, $consigneeId] = $this->resolveBuyerConsignee($data['buyer_id'] ?? null, $data['consignee_id'] ?? null);
        $vehicle->buyer_id = $buyerId;
        $vehicle->consignee_id = $consigneeId;

        // v5 — 재고매입(바이어 미정) 표시. 차량목록·편집 패널에 「바이어미정」 뱃지가 붙는다.
        //   해제는 안 건드려도 된다 — 나중에 바이어가 실제로 붙으면 `Vehicle::saving` 이 내린다(Vehicle.php:798).
        //   그래서 board 가 buyer_id 와 함께 true 를 보내도 모순 상태가 안 남는다.
        $vehicle->buyer_undecided = (bool) ($data['buyer_undecided'] ?? false);

        // v3 — 판매 pre-fill (관리 편집). ⚠️ chk_sale_required: sale_price>0 이면 sale_date·exchange_rate>0 필수.
        //   환율 누락 시 sale 필드 통째 보류(= 매입중 유지) — INSERT 실패 방지(SKILLS #25).
        $salePrice = isset($data['sale_price']) ? (float) $data['sale_price'] : 0.0;
        $saleRate = isset($data['sale_exchange_rate']) ? (float) $data['sale_exchange_rate'] : 0.0;
        if ($salePrice > 0 && $saleRate > 0) {
            $vehicle->sale_price = $salePrice;
            $vehicle->exchange_rate = $saleRate;
            $vehicle->currency = $data['sale_currency'] ?? 'KRW';
            $vehicle->sale_date = now()->toDateString();
        } elseif ($salePrice > 0) {
            // 통화 힌트만 무해하게 보존(sale_price·환율 없이) — CHECK 무영향.
            if (isset($data['sale_currency'])) {
                $vehicle->currency = $data['sale_currency'];
            }
            Log::info('[purchase-sync] sale pre-fill 보류 — 환율 누락', [
                'vehicle_number' => $data['vehicle_number'],
                'sale_price' => $salePrice,
            ]);
        }

        // 신규 매입 기본 기타비용(말소·면허·탁송) — UI 신규등록과 동일 단일 출처(회사 프로파일 반영).
        // 운영자가 추후 수정/0 가능, 2차 정산에서 실측치로 정정.
        foreach (Vehicle::defaultPurchaseCosts() as $col => $amount) {
            $vehicle->{$col} = $amount;
        }

        // 소유자명 baseline (NICE 성공 시 resFinalOwner 로 덮어쓸 수 있음).
        $ownerName = trim((string) ($data['owner_name'] ?? ''));
        if ($ownerName !== '') {
            $vehicle->nice_reg_owner_name = $ownerName;
        }

        // payee → 매입탭 정산계좌 (purchase_seller_account 는 모델 cast 로 자동 암호화).
        $vehicle->purchase_seller_holder = $data['payee_name'] ?? null;
        $vehicle->purchase_seller_bank = $data['payee_bank'] ?? null;
        $vehicle->purchase_seller_account = $data['payee_account'] ?? null;

        // v4 — 매도비 계좌 (별도 주체, purchase_fee_account 도 모델 cast 자동 암호화).
        $vehicle->purchase_fee_holder = $data['selling_fee_payee_name'] ?? null;
        $vehicle->purchase_fee_bank = $data['selling_fee_payee_bank'] ?? null;
        $vehicle->purchase_fee_account = $data['selling_fee_payee_account'] ?? null;

        // 2026-11 ④ — 딜러 서류 OCR 동봉: 구입처 「법인명/대표자」 합성 · 말소 알림 번호(하이픈). 신규라 빈 칸 = 그대로 넣는다.
        if (($from = self::composePurchaseFrom($data)) !== null) {
            $vehicle->purchase_from = $from;
        }
        if (($phone = PhoneFormat::format($data['seller_phone'] ?? null)) !== null) {
            $vehicle->deregistration_notice_phone = $phone;
        }
        // 2026-11 ② — 송금인 Name·ID 동봉. 빈 문자열은 「안 보낸 것」. remitter_id 는 모델 cast 로 암호화.
        foreach (['remitter_name', 'remitter_id'] as $col) {
            if (trim((string) ($data[$col] ?? '')) !== '') {
                $vehicle->{$col} = trim((string) $data[$col]);
            }
        }

        // ── NICE 차량조회로 VIN·차량정보 채우기 ─────────────────────────
        // owner_name 없으면 NICE 불가 → vehicle_number 로만 생성(VIN 수동/후속, graceful).
        $niceFilled = $ownerName !== '' ? $this->fillFromNice($vehicle, $data['vehicle_number'], $ownerName) : false;

        $vehicle->save();

        // ── 감사 (inbound 수신 기록 — payee_account 는 마스킹 대상이라 미로깅) ──
        AuditLog::create([
            'user_id' => null,
            'auditable_type' => Vehicle::class,
            'auditable_id' => $vehicle->id,
            'action' => 'inbound_purchase_sync',
            'column_name' => 'c_no',
            'old_value' => null,
            'new_value' => $data['c_no'] ?? null,
            'ip_address' => $request->ip(),
        ]);
        // 🔎 board 가 실어 보낸 판매 pre-fill·바이어도 같은 행 형식으로 남긴다 (jin 2026-10-13).
        //   `created` 이벤트는 값을 안 적어서, 59오5691 처럼 「판매가 5,900 EUR 는 누가 넣었나」를 ERP 감사로그로는
        //   답할 수 없었다(board 까지 뒤져야 했다). 값이 들어간 칸만 — 빈 칸까지 적으면 소음이다.
        foreach (['sale_price', 'currency', 'exchange_rate', 'sale_date', 'buyer_id', 'consignee_id'] as $col) {
            $val = $vehicle->{$col};
            if ($val === null || $val === '' || $val === 0 || $val === 0.0 || $val === '0' || $val === '0.00') {
                continue;
            }
            AuditLog::create([
                'user_id' => null,
                'auditable_type' => Vehicle::class,
                'auditable_id' => $vehicle->id,
                'action' => 'inbound_purchase_sync',
                'column_name' => $col,
                'old_value' => null,
                'new_value' => $val instanceof \DateTimeInterface ? $val->format('Y-m-d') : (string) $val,
                'ip_address' => $request->ip(),
            ]);
        }

        $attachmentsAdded = $this->syncAttachments($vehicle, $data['attachments'] ?? []);

        // ── 매입 도착 알람 ([관리] 대상 — "신규 매입차 도착, 계약금 진행") ──
        // 201(신규) 경로에서만 발화. 멱등 스킵(200)은 위에서 이미 return → 재전송 스팸 없음.
        $this->createArrivalAlarm($vehicle);

        // ── 신규 차량 등록 알림톡 (erp_vehicle_new) → 관리 ──
        // board 경유 신규(201)만 발화(수동 등록 스팸 방지, jin). 서비스는 fire-and-forget·게이트 내장(inert).
        try {
            $svc = BizmAlimtalkService::active();
            $newVars = [
                '차량번호' => (string) $vehicle->vehicle_number,
                '바이어' => (string) ($vehicle->buyer?->name ?? '-'),
                '매입가' => number_format((int) $vehicle->purchase_price).'원',
            ];
            // 🎯 체크 = 받을지, 역할 = 받을 범위 (jin 2026-08-24). 차량 1대짜리라
            //    스코프에 걸리는 사람만 받는다 — 「영업」을 켜도 담당자만 받는다.
            foreach (AlimtalkRecipients::scopedFor('erp_vehicle_new', [$vehicle]) as $phone => $mine) {
                $svc->send('erp_vehicle_new', $phone, $newVars, ['vehicle_id' => $vehicle->id]);
            }
        } catch (\Throwable $e) {
            Log::warning('[purchase-sync] vehicle_new 알림톡 실패', ['error' => $e->getMessage()]);
        }

        Log::info('[purchase-sync] vehicle 생성', [
            'vehicle_id' => $vehicle->id,
            'vehicle_number' => $data['vehicle_number'],
            'salesman_id' => $salesmanId,
            'source' => $data['source'],
            'nice_filled' => $niceFilled,
            'vin' => $vehicle->nice_reg_vin,
            'attachments_added' => $attachmentsAdded,
            'attachments_failed' => $this->attachmentsFailed,
        ]);

        return response()->json([
            'vehicle_id' => $vehicle->id,
            'attachments_added' => $attachmentsAdded,
            'attachments_failed' => $this->attachmentsFailed,
        ], 201);
    }

    /**
     * 연동 B v2 — board 가 보낸 첨부(S3 키)를 차량 사진/첨부(vehicle_photos)로 연결.
     * S3 접근 = (B) car-erp prefix 로 서버사이드 복사(같은 버킷 heysellcar-erp-docs, 바이트 전송 X).
     * 멱등/방어: target 경로를 source 키로 결정적 생성 → 재전송 시 중복 행 skip.
     * cap = VehiclePhoto::MAX_BASIC (화면 기본정보 갤러리와 같은 한도 — 숫자를 여기 직접 쓰면 조용히 갈린다).
     * 원본 누락·복사 실패는 graceful(해당 건만 skip, 동기화 전체는 성공). 스키마는 path 만(원본명·kind 미저장).
     *
     * @param  array<int, array<string, mixed>>  $attachments
     * @return int 생성된 첨부 행 수
     */
    private function syncAttachments(Vehicle $vehicle, array $attachments): int
    {
        if (empty($attachments)) {
            return 0;
        }

        // sort 힌트로 정렬(없으면 0).
        usort($attachments, fn ($a, $b) => ((int) (is_array($a) ? ($a['sort'] ?? 0) : 0)) <=> ((int) (is_array($b) ? ($b['sort'] ?? 0) : 0)));

        // 소스(board 가 올린 키) ↔ 타겟(car-erp 보관) 디스크. 운영=동일(s3) → 서버사이드 복사,
        // 로컬=다름(board_inbound) → 스트림 교차복사. (filesystems.purchase_sync_inbound_disk)
        $targetName = config('filesystems.vehicle_docs_disk');
        $sourceName = config('filesystems.purchase_sync_inbound_disk') ?: $targetName;
        $targetDisk = Storage::disk($targetName);
        $sourceDisk = Storage::disk($sourceName);
        $sameDisk = $sourceName === $targetName;

        // ⚠️ 기본정보 갤러리(category != 'shipping')만 센다 — 선적 사진까지 세면 선적 컷이 많은 차량에서
        //    board 첨부가 조용히 0건으로 잘린다(화면 한도와 다른 것을 세는 셈).
        $count = VehiclePhoto::where('vehicle_id', $vehicle->id)
            ->where(fn ($q) => $q->whereNull('category')->orWhere('category', '!=', 'shipping'))
            ->count();
        $nextOrder = (int) VehiclePhoto::where('vehicle_id', $vehicle->id)->max('sort_order');
        $created = 0;
        $failed = 0;

        foreach ($attachments as $att) {
            if ($count + $created >= VehiclePhoto::MAX_BASIC) {
                break;   // 기본정보 갤러리 한도 도달
            }
            $src = is_array($att) ? ($att['s3_path'] ?? null) : null;
            if (! is_string($src) || $src === '') {
                continue;
            }

            // 결정적 target — 재전송 멱등(같은 source → 같은 target → dedup). basename 으로 확장자 보존.
            $target = 'vehicles/'.$vehicle->id.'/synced/'.substr(md5($src), 0, 8).'_'.basename($src);

            if (VehiclePhoto::where('vehicle_id', $vehicle->id)->where('path', $target)->exists()) {
                continue;   // 이미 연결됨
            }

            try {
                if (! $targetDisk->exists($target)) {
                    if (! $sourceDisk->exists($src)) {
                        Log::warning('[purchase-sync] 첨부 원본 없음 — skip', ['vehicle_id' => $vehicle->id, 's3_path' => $src]);
                        $failed++;

                        continue;
                    }
                    // 🚨 반환값을 반드시 본다 (2026-08-10 사고).
                    //   디스크가 `'throw' => false` 라 실패해도 **예외가 아니라 false** 가 온다.
                    //   예전엔 이 값을 버리고 아래 VehiclePhoto 를 만들어서, S3 엔 파일이 없고 DB 엔
                    //   사진 행만 있는 상태가 조용히 쌓였다(운영 15건). try/catch 는 방어가 안 된다.
                    $ok = $sameDisk
                        ? $targetDisk->copy($src, $target)                                  // 운영: 같은 디스크 서버사이드 복사
                        : $targetDisk->writeStream($target, $sourceDisk->readStream($src)); // 로컬: 교차 디스크 스트림

                    // 복사가 true 를 리턴해도 실제로 올라갔는지 한 번 더 확인한다 —
                    //   "행은 있는데 파일은 없는" 상태를 만드는 경로를 완전히 닫는다.
                    if (! $ok || ! $targetDisk->exists($target)) {
                        Log::warning('[purchase-sync] 첨부 복사 실패 — skip', [
                            'vehicle_id' => $vehicle->id, 's3_path' => $src, 'target' => $target,
                            'returned' => $ok, 'disk' => $targetName,
                        ]);
                        $failed++;

                        continue;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('[purchase-sync] 첨부 복사 실패 — skip', ['vehicle_id' => $vehicle->id, 's3_path' => $src, 'error' => $e->getMessage()]);
                $failed++;

                continue;
            }

            VehiclePhoto::create([
                'vehicle_id' => $vehicle->id,
                'path' => $target,
                'sort_order' => ++$nextOrder,
            ]);
            $created++;
        }

        // 실패 건수를 함께 돌려준다 — board 는 200/201 만 보고 성공으로 기록하므로,
        //   이 값을 응답에 실어주지 않으면 첨부가 통째로 실패해도 양쪽 다 모른다(2026-08-10 사고).
        $this->attachmentsFailed = $failed;

        return $created;
    }

    /**
     * NICE(차량번호+소유자명) 조회 → VIN·등록/제원 정보를 vehicle 에 채움.
     * 미설정(null)·조회 실패 시 graceful — VIN 없이 진행하고 false 반환(에러 아님).
     * UI lookupNiceApi() 와 동일한 registration/spec 적용 규칙.
     */
    private function fillFromNice(Vehicle $vehicle, string $vehicleNumber, string $ownerName): bool
    {
        $result = NiceApiService::fromConfig()->lookupVehicle($vehicleNumber, $ownerName);

        // null = 엔드포인트 미설정(수동 모드) / success=false = 조회 실패. 둘 다 graceful.
        if ($result === null || ($result['success'] ?? false) !== true) {
            Log::info('[purchase-sync] NICE 조회 미적용 — VIN 후속/수동', [
                'vehicle_number' => $vehicleNumber,
                'reason' => $result === null ? 'not_configured' : ($result['message'] ?? 'failed'),
            ]);

            return false;
        }

        // registration/spec 키 = vehicle 컬럼명. fillable 에 있는 키만 적용(안전).
        $fillable = $vehicle->getFillable();
        foreach (array_merge($result['registration'] ?? [], $result['spec'] ?? []) as $key => $value) {
            if (in_array($key, $fillable, true)) {
                $vehicle->{$key} = $value;   // nice_reg_owner_rrn 은 모델 mutator 가 자동 암호화
            }
        }

        // 응답 원본 보존 (미매핑 NICE 필드 — 서류 생성 시 재조회 없이 활용).
        $vehicle->nice_raw = $result['raw'] ?? [];

        return true;
    }

    /**
     * 매입 도착 알람 생성 — [관리]/admin 에게 신규 매입차 도착 통지.
     * 게이트: alarm_enabled(기능설정) ON + task_alarms 테이블 존재. 해소 = 계약금(PBP down)
     * 입력 자동 + 수동 [확인](alarm-center/alarms.index). message_meta whitelist(차량번호만).
     */
    private function createArrivalAlarm(Vehicle $vehicle): void
    {
        if (! Schema::hasTable('task_alarms') || ! (bool) Setting::get('alarm_enabled', false)) {
            return;
        }

        TaskAlarm::create([
            'type' => 'purchase_arrival',
            'vehicle_id' => $vehicle->id,
            'target_role' => '관리',
            'due_date' => now()->toDateString(),
            'message_meta' => TaskAlarm::sanitizeMeta(['vehicle_number' => $vehicle->vehicle_number]),
        ]);
    }

    /**
     * v3 — 바이어/컨사이니 FK 해소. board 드롭다운 선택값을 검증 후 세팅.
     * - buyer: 존재 + is_active. 무효면 null.
     * - consignee: 존재 + is_active + 해당 buyer 하위. buyer 무효거나 소속 불일치면 null.
     *
     * @return array{0: ?int, 1: ?int} [buyer_id, consignee_id]
     */
    /**
     * 멱등 재전송에서 **비어 있는 칸만** 채운다 (2026-08-18, board 인계 ①).
     *
     * 배경: 급한 차는 매입가만 넣고 판매가·통화·운임비 없이 ERP 로 보낸다. 나중에 판매가를 채워
     * 재전송해도 멱등 분기가 **첨부만 받고 금액은 통째로 무시**했다. 그래서 "빈 칸 채우기" 만 연다.
     *
     * 규칙 3가지:
     *  1. **이미 값이 있으면 절대 안 건드린다** — 관리가 ERP 에서 고친 값이 board 재전송으로 되돌아가면
     *     회계가 조용히 틀어진다(운임비 1/N 에서 정한 원칙과 동일).
     *  2. **판매 3종 + sale_date 는 세트다** — `chk_sale_required`(sale_price>0 → sale_date·exchange_rate>0)
     *     때문에 부분 기입은 CHECK 위반으로 죽는데, board 는 200 만 보고 성공으로 기록한다.
     *     ⚠️ `sale_date` 는 **수신일**로 채운다 — 신규 생성 경로가 이미 그렇게 하고 있어 두 경로를 맞춘 것이다
     *        (board 에 판매일 개념이 없다). 정확한 날짜가 필요하면 관리가 ERP 에서 고친다.
     *        곁다리: 채권 유예 기산점이 이 날짜다 → 독촉이 이 날로부터 시작된다.
     *  3. **왜 안 채웠는지 응답에 담는다** — 조용한 실패 금지.
     *
     * 🚫 **`buyer_undecided`(v5)는 여기서 안 건드린다.** 「빈 칸」이 없는 불리언이라 「아직 안 정함」과
     *    「사람이 껐음」을 구분할 수 없다 — 재전송이 ERP 에서 끈 체크를 되살리면 규칙 1 위반이다.
     *    뒤처리도 필요 없다: 재전송이 `buyer_id` 를 채우면 아래 `save()` 에서 `Vehicle::saving` 이 자동 해제한다.
     *
     * 🔀 **2026-10-29 (jin, board 세션에서 결정) — `amounts_overwrite: true` 면 판매측 6칸은 덮어쓴다.**
     *    규칙 1(불가침)의 예외다 — 「금액만 ERP 로 보내기」가 빈 칸만 채우면 쓸모가 없어서. 대상 = sale_price·sale_currency·
     *    sale_exchange_rate·transport_fee·transport_fee_usd·usd_exchange_rate **뿐**(바이어·컨사이니·payee·매입측·첨부는 종전).
     *    - null/0 으로 **지우진 않는다**(board 가 안 보낸 칸은 건너뜀). 같은 값이면 아무 목록에도 안 든다.
     *    - 🔒 안전장치(jin 10-29): 2차 마감 차 = `ledger_closed` · 정산 confirmed/paid 가 있는 차 = `settlement_locked` → 6칸 전부 건너뛰고
     *      `fields_skipped.amounts` 에 사유. 그 차는 ERP 에서 사람이 고친다(마진·미수가 움직이는 값이라).
     *    - 판매가를 덮을 때 환율이 안 왔으면 기존 환율을 쓴다(chk_sale_required). 기존 환율도 0 이면 `missing_exchange_rate`.
     *    - 응답 `fields_overwritten[]` + 감사 행 `purchase_sync_amounts_overwritten`(컬럼 콤마 목록, 출처 = board 재전송·요청 이메일).
     *
     * @return array{0: list<string>, 1: array<string, string>, 2: list<string>} [채운 필드, 필드=>스킵사유, 덮어쓴 필드]
     */
    private function fillEmptyFields(Vehicle $vehicle, array $data): array
    {
        $filled = [];
        $skipped = [];
        $overwritten = [];

        $overwrite = (bool) ($data['amounts_overwrite'] ?? false);
        if ($overwrite && ($lock = $this->amountsLockReason($vehicle)) !== null) {
            $skipped['amounts'] = $lock;
            $overwrite = false;   // 잠긴 차는 종전 규칙(빈 칸만)으로 떨어진다
        }
        // 금액 칸 하나를 「빈 칸만 / 덮어쓰기」 규칙으로 놓는다. 같은 값은 어느 목록에도 안 든다.
        $amount = function (string $col, string $name, float $incoming, callable $cast) use ($vehicle, $overwrite, &$filled, &$skipped, &$overwritten): void {
            if ($incoming <= 0) {
                return;
            }
            $old = (float) $vehicle->{$col};
            if ($old <= 0) {
                $vehicle->{$col} = $cast($incoming);
                $filled[] = $name;
            } elseif (! $overwrite) {
                $skipped[$name] = 'already_set';
            } elseif (abs($old - (float) $cast($incoming)) > 0.000001) {
                $vehicle->{$col} = $cast($incoming);
                $overwritten[] = $name;
            }
        };

        $salePrice = isset($data['sale_price']) ? (float) $data['sale_price'] : 0.0;
        $saleRate = isset($data['sale_exchange_rate']) ? (float) $data['sale_exchange_rate'] : 0.0;

        if ($salePrice > 0) {
            $hasSale = (float) $vehicle->sale_price > 0;
            // 덮어쓸 땐 환율이 안 와도 기존 환율로 세트를 유지한다(chk_sale_required).
            $rate = $saleRate > 0 ? $saleRate : ($hasSale && $overwrite ? (float) $vehicle->exchange_rate : 0.0);
            if ($hasSale && ! $overwrite) {
                $skipped['sale_price'] = 'already_set';
            } elseif ($rate <= 0) {
                // 환율 없이 판매가만 쓰면 CHECK 위반 → 세트 통째 보류(신규 경로와 동일 판단).
                $skipped['sale_price'] = 'missing_exchange_rate';
            } elseif (! $hasSale) {
                $vehicle->sale_price = $salePrice;
                $vehicle->exchange_rate = $rate;
                $vehicle->currency = $data['sale_currency'] ?? 'KRW';
                $vehicle->sale_date = now()->toDateString();
                $filled[] = 'sale_price';
                $filled[] = 'sale_exchange_rate';
                $filled[] = 'sale_currency';
                $filled[] = 'sale_date';
            } else {
                // 덮어쓰기 — 바뀐 칸만 기록. 통화는 보냈을 때만.
                if (abs((float) $vehicle->sale_price - $salePrice) > 0.000001) {
                    $vehicle->sale_price = $salePrice;
                    $overwritten[] = 'sale_price';
                }
                if (abs((float) $vehicle->exchange_rate - $rate) > 0.000001) {
                    $vehicle->exchange_rate = $rate;
                    $overwritten[] = 'sale_exchange_rate';
                }
                if (isset($data['sale_currency']) && $data['sale_currency'] !== $vehicle->currency) {
                    $vehicle->currency = $data['sale_currency'];
                    $overwritten[] = 'sale_currency';
                }
                if (blank($vehicle->sale_date)) {
                    $vehicle->sale_date = now()->toDateString();
                    $filled[] = 'sale_date';
                }
            }
        }

        // ⚠️ 운임비 = **판매통화** 기준(board 가 환산해 보냄). USD raw 컬럼(`transport_fee_usd`)이 따로 있으니
        //    헷갈리지 말 것 — 여기에 USD 를 넣으면 미수율 분모가 부풀어 게이트 판정이 틀어진다.
        $amount('transport_fee', 'transport_fee', (float) ($data['transport_fee'] ?? 0), fn (float $x) => $x);
        // 2026-10-22 — USD 메모 2칸도 같은 규칙. 선적요청 1/N 이 넣은 값은 덮어쓰기 플래그가 와야만 바뀐다.
        $amount('transport_fee_usd', 'transport_fee_usd', (float) ($data['transport_fee_usd'] ?? 0), fn (float $x) => (int) round($x));
        $amount('usd_exchange_rate', 'usd_exchange_rate', (float) ($data['usd_exchange_rate'] ?? 0), fn (float $x) => $x);

        [$buyerId, $consigneeId] = $this->resolveBuyerConsignee($data['buyer_id'] ?? null, $data['consignee_id'] ?? null);

        if (isset($data['buyer_id'])) {
            if ($buyerId === null) {
                $skipped['buyer_id'] = 'invalid_or_inactive';
            } elseif ($vehicle->buyer_id !== null) {
                $skipped['buyer_id'] = 'already_set';
            } else {
                $vehicle->buyer_id = $buyerId;
                $filled[] = 'buyer_id';
            }
        }

        if (isset($data['consignee_id'])) {
            // 컨사이니는 바이어 하위다 — 차량의 바이어와 다른 바이어의 컨사이니를 붙이면 서류가 조용히 틀린다.
            $effectiveBuyerId = $vehicle->buyer_id;
            if (! isset($data['buyer_id'])) {
                $skipped['consignee_id'] = 'buyer_not_sent';   // 컨사이니만 와도 소속을 검증할 수 없다
            } elseif ($consigneeId === null) {
                $skipped['consignee_id'] = 'invalid_or_inactive';
            } elseif ($effectiveBuyerId !== $buyerId) {
                $skipped['consignee_id'] = 'buyer_mismatch';
            } elseif ($vehicle->consignee_id !== null) {
                $skipped['consignee_id'] = 'already_set';
            } else {
                $vehicle->consignee_id = $consigneeId;
                $filled[] = 'consignee_id';
            }
        }

        // 2026-11 ④ — 딜러 서류 OCR 동봉도 빈 칸만(사람이 친 구입처·번호 보호). 이미 있으면 **ERP 컬럼명**으로 already_set.
        if (($from = self::composePurchaseFrom($data)) !== null) {
            if (trim((string) $vehicle->purchase_from) !== '') {
                $skipped['purchase_from'] = 'already_set';
            } else {
                $vehicle->purchase_from = $from;
                $filled[] = 'purchase_from';
            }
        }
        if (($phone = PhoneFormat::format($data['seller_phone'] ?? null)) !== null) {
            if (trim((string) $vehicle->deregistration_notice_phone) !== '') {
                $skipped['deregistration_notice_phone'] = 'already_set';
            } else {
                $vehicle->deregistration_notice_phone = $phone;
                $filled[] = 'deregistration_notice_phone';
            }
        }

        // 2026-11 ② — 송금인 동봉도 빈 칸만(⚠️ 전용 엔드포인트 §14-2 는 덮어쓰기 — 경로마다 규칙이 다르다).
        foreach (['remitter_name', 'remitter_id'] as $col) {
            $incoming = trim((string) ($data[$col] ?? ''));
            if ($incoming === '') {
                continue;
            }
            if (trim((string) $vehicle->{$col}) !== '') {
                $skipped[$col] = 'already_set';
            } else {
                $vehicle->{$col} = $incoming;
                $filled[] = $col;
            }
        }

        if ($filled === [] && $overwritten === []) {
            return [$filled, $skipped, $overwritten];
        }

        // 감사 귀속 — HMAC 경로엔 세션이 없어 `auth()->id()` 가 늘 null 이고, 감사 화면엔 「시스템」으로 찍혀
        //   cron 이 바꾼 것과 구분이 안 된다(SKILLS §8 #56). `buyer_id` 는 감사 대상 컬럼이라 특히 필요하다.
        //   연결된 User 가 없는 영업이면 null 로 남는다(그건 종전과 같음).
        $userId = User::where('email', $data['salesman_email'])->value('id');
        AuditLog::actingAs($userId, function () use ($vehicle, $overwritten, $data) {
            $vehicle->save();
            if ($overwritten !== []) {
                // 출처 표시 한 줄 — 컬럼별 변경 행(updated 훅)과 별도로 「board 재전송이 덮어썼다」를 남긴다(§8 #108 곁다리와 같은 두 줄 패턴).
                AuditLog::create([
                    'user_id' => AuditLog::currentActorId(),
                    'auditable_type' => Vehicle::class, 'auditable_id' => $vehicle->id,
                    'action' => 'purchase_sync_amounts_overwritten',
                    'column_name' => implode(',', self::overwrittenColumns($overwritten)),
                    'old_value' => null, 'new_value' => 'board 재전송 덮어쓰기 ('.$data['salesman_email'].')',
                    'ip_address' => request()?->ip(),
                ]);
            }
        });

        return [$filled, $skipped, $overwritten];
    }

    /** 덮어쓰기를 막는 사유 — 2차 마감(원장 락) · 정산 확정/지급(마진·미수가 움직인다, jin 10-29). null = 허용. */
    private function amountsLockReason(Vehicle $vehicle): ?string
    {
        if ($vehicle->hasClosedSecondarySettlement()) {
            return 'ledger_closed';
        }
        if ($vehicle->settlements()->whereIn('settlement_status', ['confirmed', 'paid'])->exists()) {
            return 'settlement_locked';
        }

        return null;
    }

    /** 응답 이름(board 키) → ERP 컬럼명 — 감사 행의 컬럼 목록은 ERP 사전으로 번역돼야 한다(§8 #41). */
    private static function overwrittenColumns(array $names): array
    {
        $map = ['sale_exchange_rate' => 'exchange_rate', 'sale_currency' => 'currency'];

        return array_map(fn (string $n) => $map[$n] ?? $n, $names);
    }

    /**
     * 2026-11 ④ — 딜러 서류 OCR 동봉의 구입처 합성 = `{법인명}/{대표자}`. 하나만 오면 그것만(「/」 없음),
     * 출처 접두사 없음(출처는 `purchase_source`). 둘 다 비면 null = 「안 보낸 것」.
     * 🚫 `purchase_from` 을 구조화하지 않는다(자유서식 유지) — 합성만 하고 되돌려 주지 않는다.
     */
    private static function composePurchaseFrom(array $data): ?string
    {
        $parts = array_values(array_filter([
            trim((string) ($data['seller_company'] ?? '')),
            trim((string) ($data['seller_ceo'] ?? '')),
        ], fn (string $s) => $s !== ''));

        return $parts === [] ? null : implode('/', $parts);
    }

    private function resolveBuyerConsignee(?int $buyerId, ?int $consigneeId): array
    {
        if ($buyerId === null) {
            return [null, null];   // buyer 없으면 consignee 도 무의미
        }

        $buyer = Buyer::where('id', $buyerId)->where('is_active', true)->first();
        if (! $buyer) {
            Log::info('[purchase-sync] buyer_id 무효 — 무시', ['buyer_id' => $buyerId]);

            return [null, null];
        }

        if ($consigneeId === null) {
            return [$buyer->id, null];
        }

        $consignee = Consignee::where('id', $consigneeId)
            ->where('buyer_id', $buyer->id)   // 반드시 해당 buyer 하위
            ->where('is_active', true)
            ->first();

        if (! $consignee) {
            Log::info('[purchase-sync] consignee_id 무효/소속 불일치 — 무시', [
                'buyer_id' => $buyer->id, 'consignee_id' => $consigneeId,
            ]);

            return [$buyer->id, null];
        }

        return [$buyer->id, $consignee->id];
    }

    /**
     * 영업 매칭. car_erp_salesman_id 명시 오버라이드 우선 → salesman_email 매칭.
     * 매칭: Salesman.email 직접 → User.email → user.salesman. 못 찾으면 null.
     */
    private function resolveSalesmanId(?int $overrideId, string $email): ?int
    {
        if ($overrideId !== null) {
            $salesman = Salesman::find($overrideId);
            if ($salesman) {
                return $salesman->id;
            }
        }

        // 🚫 영업만(sales) — 관리직 「직원」 미러 행이 차량 담당자로 꽂히지 않게(2026-10-14, SalesmanResolver 와 같은 규칙).
        $salesman = Salesman::where('email', $email)->sales()->first();
        if ($salesman) {
            return $salesman->id;
        }

        $user = User::where('email', $email)->first();
        if ($user && $user->salesman?->isSales()) {
            return $user->salesman->id;
        }

        Log::info('[purchase-sync] 영업 매칭 실패 — 수동 배정 대기', ['email' => $email]);

        return null;
    }
}
