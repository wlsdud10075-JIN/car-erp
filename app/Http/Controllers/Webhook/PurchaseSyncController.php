<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\NiceApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
    /** 현재 지원하는 계약 버전. 미지원 버전 → 422. */
    private const SUPPORTED_VERSION = 1;

    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->json()->all();

        // 전방호환 — contract_version 검사 (모르는 *필드*는 무시하되 *버전*은 게이트).
        $version = (int) ($payload['contract_version'] ?? 0);
        if ($version !== self::SUPPORTED_VERSION) {
            Log::warning('[purchase-sync] 미지원 contract_version', ['version' => $version]);

            return response()->json([
                'message' => 'Unsupported contract_version',
                'supported' => self::SUPPORTED_VERSION,
            ], 422);
        }

        $validator = Validator::make($payload, [
            'vehicle_number' => ['required', 'string', 'max:255'],
            'owner_name' => ['nullable', 'string', 'max:255'],
            'source' => ['required', 'string', 'in:encar,auction'],
            'final_price' => ['required', 'integer', 'min:0'],
            'salesman_email' => ['required', 'email'],
            'car_erp_salesman_id' => ['nullable', 'integer'],
            'c_no' => ['nullable', 'string', 'max:255'],
            'payee_name' => ['nullable', 'string', 'max:100'],
            'payee_bank' => ['nullable', 'string', 'max:100'],
            'payee_account' => ['nullable', 'string', 'max:255'],
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
            Log::info('[purchase-sync] 멱등 스킵 — 기존 vehicle_number', [
                'vehicle_id' => $existing->id,
                'vehicle_number' => $data['vehicle_number'],
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
        $vehicle->purchase_price = $data['final_price'];
        $vehicle->salesman_id = $salesmanId;
        $vehicle->purchase_date = now()->toDateString();

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

        Log::info('[purchase-sync] vehicle 생성', [
            'vehicle_id' => $vehicle->id,
            'vehicle_number' => $data['vehicle_number'],
            'salesman_id' => $salesmanId,
            'source' => $data['source'],
            'nice_filled' => $niceFilled,
            'vin' => $vehicle->nice_reg_vin,
        ]);

        return response()->json(['vehicle_id' => $vehicle->id], 201);
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
