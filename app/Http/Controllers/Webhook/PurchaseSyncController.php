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
use App\Services\NiceApiService;
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
     * 지원 계약 버전. 미지원 → 422.
     * v2 = attachments[] 추가(전방호환, v1 도 계속 수용).
     * v3 = 금액/바이어/컨사이니 확장 — purchase_price_krw·selling_fee_krw·transport_fee·
     *      sale_price·sale_currency·sale_exchange_rate·buyer_id·consignee_id (모두 optional).
     */
    private const SUPPORTED_VERSIONS = [1, 2, 3];

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
            // 연동 B v3 — 금액/바이어/컨사이니 확장 (모두 optional, 전방호환).
            'purchase_price_krw' => ['nullable', 'integer', 'min:0'],   // 구입금액(차값−할인)만 — final_price 부풀림 교정
            'selling_fee_krw' => ['nullable', 'integer', 'min:0'],      // 매도비 (별도 컬럼)
            'transport_fee' => ['nullable', 'numeric', 'min:0'],        // 운임비 — ⚠️ 판매통화(board 가 환산해 보냄), USD raw 아님
            'sale_price' => ['nullable', 'numeric', 'min:0'],           // pre-fill, 관리 편집
            'sale_currency' => ['nullable', 'string', 'in:'.implode(',', self::SALE_CURRENCIES)],
            'sale_exchange_rate' => ['nullable', 'numeric', 'min:0'],   // pre-fill, 관리가 지정시점 환율로 덮어씀
            'buyer_id' => ['nullable', 'integer'],
            'consignee_id' => ['nullable', 'integer'],
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
            Log::info('[purchase-sync] 멱등 스킵 — 기존 vehicle_number', [
                'vehicle_id' => $existing->id,
                'vehicle_number' => $data['vehicle_number'],
                'attachments_added' => $synced,
            ]);

            return response()->json(['vehicle_id' => $existing->id], 200);
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

        // v3 — 바이어/컨사이니 FK (존재·활성 검증, consignee 는 buyer 하위. 무효면 null).
        [$buyerId, $consigneeId] = $this->resolveBuyerConsignee($data['buyer_id'] ?? null, $data['consignee_id'] ?? null);
        $vehicle->buyer_id = $buyerId;
        $vehicle->consignee_id = $consigneeId;

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

        // 신규 매입 기본 기타비용(말소·면허·탁송) — UI 신규등록과 동일 단일 출처.
        // 운영자가 추후 수정/0 가능, 2차 정산에서 실측치로 정정.
        foreach (Vehicle::DEFAULT_PURCHASE_COSTS as $col => $amount) {
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

        $attachmentsAdded = $this->syncAttachments($vehicle, $data['attachments'] ?? []);

        // ── 매입 도착 알람 ([관리] 대상 — "신규 매입차 도착, 계약금 진행") ──
        // 201(신규) 경로에서만 발화. 멱등 스킵(200)은 위에서 이미 return → 재전송 스팸 없음.
        $this->createArrivalAlarm($vehicle);

        Log::info('[purchase-sync] vehicle 생성', [
            'vehicle_id' => $vehicle->id,
            'vehicle_number' => $data['vehicle_number'],
            'salesman_id' => $salesmanId,
            'source' => $data['source'],
            'nice_filled' => $niceFilled,
            'vin' => $vehicle->nice_reg_vin,
            'attachments_added' => $attachmentsAdded,
        ]);

        return response()->json(['vehicle_id' => $vehicle->id], 201);
    }

    /**
     * 연동 B v2 — board 가 보낸 첨부(S3 키)를 차량 사진/첨부(vehicle_photos)로 연결.
     * S3 접근 = (B) car-erp prefix 로 서버사이드 복사(같은 버킷 heysellcar-erp-docs, 바이트 전송 X).
     * 멱등/방어: target 경로를 source 키로 결정적 생성 → 재전송 시 중복 행 skip. 최대 10건 cap.
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

        $count = VehiclePhoto::where('vehicle_id', $vehicle->id)->count();
        $nextOrder = (int) VehiclePhoto::where('vehicle_id', $vehicle->id)->max('sort_order');
        $created = 0;

        foreach ($attachments as $att) {
            if ($count + $created >= 10) {
                break;   // 첨부 최대 10건 cap
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

                        continue;
                    }
                    if ($sameDisk) {
                        $targetDisk->copy($src, $target);                          // 운영: 같은 디스크 서버사이드 복사
                    } else {
                        $targetDisk->writeStream($target, $sourceDisk->readStream($src));   // 로컬: 교차 디스크 스트림
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('[purchase-sync] 첨부 복사 실패 — skip', ['vehicle_id' => $vehicle->id, 's3_path' => $src, 'error' => $e->getMessage()]);

                continue;
            }

            VehiclePhoto::create([
                'vehicle_id' => $vehicle->id,
                'path' => $target,
                'sort_order' => ++$nextOrder,
            ]);
            $created++;
        }

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

        $salesman = Salesman::where('email', $email)->first();
        if ($salesman) {
            return $salesman->id;
        }

        $user = User::where('email', $email)->first();
        if ($user && $user->salesman) {
            return $user->salesman->id;
        }

        Log::info('[purchase-sync] 영업 매칭 실패 — 수동 배정 대기', ['email' => $email]);

        return null;
    }
}
