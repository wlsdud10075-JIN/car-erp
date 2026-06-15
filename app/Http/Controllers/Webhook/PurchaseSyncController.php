<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Salesman;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * 연동 B 수신측 — board(매입보드)가 status='won' 낙찰차를 car-erp(heyman) 매입 재고로
 * 단방향 push 하는 것을 받는다. 발신(권위=payload) = board SKILLS §12.
 * 수신 스펙(권위) = docs/integration/purchase-sync-receiver.md.
 *
 * HMAC 인증은 VerifyPurchaseSyncHmac 미들웨어가 선처리(라우트 레벨). 여기는 검증된
 * 요청만 도달 — 멱등(VIN 사전조회) + vehicle 생성 + 영업 매칭 + payee 정산계좌 저장.
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
            'vin' => ['required', 'string', 'max:255'],
            'vehicle_number' => ['required', 'string', 'max:255'],
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

        // ── 멱등: VIN 사전조회 ──────────────────────────────────────────
        // 동일 VIN 차량이 이미 있으면 새로 만들지 않고 기존 id 반환(스킵). 200.
        $existing = Vehicle::where('nice_reg_vin', $data['vin'])->first();
        if ($existing) {
            Log::info('[purchase-sync] 멱등 스킵 — 기존 VIN', [
                'vehicle_id' => $existing->id,
                'vin' => $data['vin'],
            ]);

            return response()->json(['vehicle_id' => $existing->id], 200);
        }

        // ── 영업 매칭 ──────────────────────────────────────────────────
        // car_erp_salesman_id(명시 오버라이드) 우선 → 없으면 salesman_email 매칭.
        // 둘 다 못 찾으면 salesman_id = null(수동 배정 대기).
        $salesmanId = $this->resolveSalesmanId($data['car_erp_salesman_id'] ?? null, $data['salesman_email']);

        // ── vehicle 생성 (매입 단계) ───────────────────────────────────
        $vehicle = new Vehicle;
        $vehicle->vehicle_number = $data['vehicle_number'];
        $vehicle->sales_channel = 'heyman';            // board → heyman 재고 (CLAUDE.md 연동 B)
        $vehicle->progress_status_rule_version = 4;
        $vehicle->nice_reg_vin = $data['vin'];
        $vehicle->purchase_source = $data['source'];
        $vehicle->c_no = $data['c_no'] ?? null;
        $vehicle->purchase_price = $data['final_price'];
        $vehicle->salesman_id = $salesmanId;
        $vehicle->purchase_date = now()->toDateString();

        // payee → 매입탭 정산계좌 (purchase_seller_account 는 모델 cast 로 자동 암호화).
        $vehicle->purchase_seller_holder = $data['payee_name'] ?? null;
        $vehicle->purchase_seller_bank = $data['payee_bank'] ?? null;
        $vehicle->purchase_seller_account = $data['payee_account'] ?? null;

        $vehicle->save();

        // ── 감사 (inbound 수신 기록 — payee_account 는 마스킹) ──────────
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
            'vin' => $data['vin'],
            'salesman_id' => $salesmanId,
            'source' => $data['source'],
        ]);

        return response()->json(['vehicle_id' => $vehicle->id], 201);
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
