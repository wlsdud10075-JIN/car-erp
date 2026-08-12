<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Models\BoardRequest;
use App\Models\Vehicle;
use App\Services\BizmAlimtalkService;
use App\Services\SalesmanResolver;
use App\Support\AlimtalkRecipients;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * board → erp 요청·확인 신호 (카톡 대체). 권위 스펙 = docs/integration/board-portal-api.md §11.
 *
 * board 영업이 보내는 말은 세 가지다 — "이 차 계약금 N원 보내주세요"(purchase_deposit) /
 * "이 차 잔금 N원 보내주세요"(purchase_balance) /
 * "이 바이어 차 N대 대금 넣었으니 확인해주세요"(sale_payment_confirm).
 *
 * 💰 **금액을 받는다 (2026-08-11 개정, §11-2).** 매입 2종은 `amount_krw` 필수 — 받는 사람이 얼마를
 *    보내야 하는지 모르면 신호가 일을 못 끝낸다. 🚫 다만 **표시 전용**이라 회계 컬럼엔 안 쓴다(§11-5).
 * ⚠️ 구 `purchase_payment` 는 deprecated 지만 **계속 수신한다** — board 운영(master)이 아직 그걸
 *    보내는 구버전이고 ERP 가 먼저 배포된다. 여기서 튕기면 board 운영의 입금요청이 통째로 죽는다.
 * - IDOR = vehicle.salesman_id == 해소 영업(SalesmanResolver). 남의 차는 **전량 거부 대신 부분 skip**
 *   — 한 대 때문에 묶음 전체가 죽으면 영업이 원인을 못 찾고 카톡으로 돌아간다.
 * - 멱등 = `BoardRequest::raise()` 단일 지점(같은 차+type 에 open 이 있으면 null).
 *   ⚠️ 멱등키가 `(vehicle_id, type)` 이라 **type 이 갈려야** 계약금이 열린 채로 잔금 요청이 통과한다.
 *   하나의 type 에 하위구분(subtype)을 얹는 설계는 여기서 조용히 skip 돼 작동하지 않는다.
 */
class BoardRequestController extends Controller
{
    private function salesman(Request $request)
    {
        return SalesmanResolver::resolveActiveOrFail(
            (string) ($request->input('salesman_email') ?? $request->query('salesman_email', ''))
        );
    }

    /**
     * POST /requests — 신호 보내기.
     *
     * 매입 신호(계약금·잔금·구 입금요청)는 단위가 차량 1대라 vehicle_ids 를 여러 개 주면
     * **각각 별개 묶음**이 된다. sale_payment_confirm 만 바이어 1명 + N대가 한 묶음(batch_id 공유).
     */
    public function store(Request $request): JsonResponse
    {
        $salesman = $this->salesman($request);

        // 🛑 폐기된 구 통합 신호(2026-08-12 수신 중단). 아래 validate 의 `in:` 로도 막히지만
        //    그 메시지("selected type is invalid")로는 board 쪽에서 원인을 못 찾는다 —
        //    무엇으로 바꿔 보내야 하는지까지 돌려준다.
        if ($request->input('type') === BoardRequest::TYPE_PURCHASE_PAYMENT) {
            return response()->json([
                'error' => 'type_retired',
                'message' => '[입금요청]은 폐기됐습니다. purchase_deposit(계약금) 또는 purchase_balance(매입잔금)로 보내세요.',
            ], 422);
        }

        $data = $request->validate([
            'type' => ['required', 'in:'.implode(',', BoardRequest::TYPES)],
            'vehicle_ids' => ['required', 'array', 'min:1'],
            'vehicle_ids.*' => ['integer'],
            'buyer_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:200'],
            // 표시 전용 금액. 상한 = 1조 미만(오타 방어). 회계엔 안 쓰지만 화면에 그대로 뜨므로
            // 말도 안 되는 자릿수가 들어오면 목록이 깨진다.
            'amount_krw' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
        ]);

        $isSaleConfirm = $data['type'] === BoardRequest::TYPE_SALE_PAYMENT_CONFIRM;
        $buyerId = $data['buyer_id'] ?? null;
        $amountKrw = $data['amount_krw'] ?? null;

        if ($isSaleConfirm && ! $buyerId) {
            return response()->json([
                'error' => 'buyer_required',
                'message' => '판매대금확인은 바이어를 지정해야 합니다.',
            ], 422);
        }

        // 금액을 받는 type 은 금액이 **이 기능의 전부**다. 비면 받는 사람이 얼마를 보낼지 알 수 없어
        // 신호가 카톡으로 되돌아간다 ⇒ 조용히 null 로 넘기지 말고 여기서 거절한다.
        if ((BoardRequest::meta($data['type'])['amount'] ?? false) && ! $amountKrw) {
            return response()->json([
                'error' => 'amount_required',
                'message' => '요청 금액을 입력해야 합니다.',
            ], 422);
        }

        // 본인 차량만 (IDOR). 남의 차·없는 차는 아래에서 skipped 로 응답.
        $vehicles = Vehicle::whereIn('id', $data['vehicle_ids'])
            ->where('salesman_id', $salesman->id)
            ->get()->keyBy('id');

        // 오배치 방지 — 한 묶음에 서로 다른 바이어의 차를 담지 못한다(§11-3).
        if ($isSaleConfirm) {
            $mismatch = $vehicles->filter(fn (Vehicle $v) => (int) $v->buyer_id !== (int) $buyerId);
            if ($mismatch->isNotEmpty()) {
                return response()->json([
                    'error' => 'buyer_mismatch',
                    'message' => '지정한 바이어의 차량이 아닙니다: '.$mismatch->pluck('vehicle_number')->implode(', '),
                ], 422);
            }
        }

        $batchId = (string) Str::uuid();
        $created = [];
        $createdRows = [];
        $updated = [];
        $updatedIds = [];
        $skipped = [];

        DB::transaction(function () use ($data, $vehicles, $salesman, $buyerId, $amountKrw, $isSaleConfirm, $batchId, &$created, &$createdRows, &$updated, &$updatedIds, &$skipped) {
            foreach ($data['vehicle_ids'] as $vid) {
                $vehicle = $vehicles->get($vid);
                if (! $vehicle) {
                    $skipped[] = ['vehicle_id' => (int) $vid, 'reason' => 'forbidden'];

                    continue;
                }

                $row = BoardRequest::raise(
                    vehicleId: $vehicle->id,
                    type: $data['type'],
                    requestedByEmail: (string) $salesman->email,
                    buyerId: $isSaleConfirm ? $buyerId : null,
                    // 입금요청은 1대 = 1묶음이라 차량마다 새 uuid. 판매대금확인만 batch 를 공유한다.
                    batchId: $isSaleConfirm ? $batchId : null,
                    note: $data['note'] ?? null,
                    amountKrw: $amountKrw,
                );

                if ($row === null) {
                    // 이미 열려 있다 — 금액을 고쳐 다시 보낸 것이면 그 금액만 갱신한다(오타 정정).
                    $row = BoardRequest::refreshAmount($vehicle->id, $data['type'], $amountKrw);
                    if ($row === null) {
                        $skipped[] = ['vehicle_number' => $vehicle->vehicle_number, 'reason' => 'already_open'];

                        continue;
                    }
                    $updated[] = $vehicle->vehicle_number;
                    $updatedIds[] = $row->id;
                }

                // ⚠️ 갱신분도 `created` 에 담는다 — board 는 `created`/`skipped` 만 읽으므로,
                //    별도 키로만 돌려주면 board 화면이 "0건 전송"으로 보인다(ERP 가 먼저 배포된다).
                //    `updated` 는 그 부분집합이다: board 가 나중에 "N건 중 M건 금액 수정"을 쓰고 싶을 때만 본다.
                $created[] = $vehicle->vehicle_number;
                $createdRows[] = $row;
            }
        });

        // 🔔 알림톡은 **트랜잭션이 끝난 뒤** 보낸다 — 롤백됐는데 카톡만 나가면 되돌릴 수가 없다.
        //    `skipped`(already_open·forbidden)에는 안 보낸다: 중복 카톡의 원인이다.
        // 카드에는 이름을 쓴다 — 아이템 내용이 20자에서 잘려 이메일이 뭉개진다(이름 없으면 이메일 폴백).
        $this->notifyCreated($data['type'], $createdRows, (string) ($salesman->name ?: $salesman->email), $updatedIds);

        return response()->json([
            'batch_id' => $isSaleConfirm ? $batchId : null,
            'created' => $created,
            'updated' => $updated,   // created 의 **부분집합** — 금액만 갱신된 차량
            'skipped' => $skipped,
        ], 201);
    }

    /**
     * 새로 만들어진 신호를 알림톡 1건으로 알린다 (jin 2026-08-11).
     *
     * 🕑 **수신자는 시각 규칙**으로 갈린다 — 근무시간엔 담당자, 그 밖(야간·주말·공휴일)엔 대표.
     *    판정은 `AlimtalkRecipients::forTimeRules()` 서버 시각 단일 판정이다.
     *    ⚠️ **board 가 미리 판정해 힌트를 실어 보내지 않는다** — 판정 지점이 둘로 갈리면 반드시 어긋난다.
     *
     * 발송은 fire-and-forget: `BizmAlimtalkService` 가 게이트·예외를 흡수하므로 실패해도 API 응답은
     * 정상이다. ⚠️ 그래도 여기서 예외가 새면 **요청은 만들어졌는데 201 이 안 나가** board 가 실패로
     * 오해하고 재전송한다(그건 멱등에 걸려 already_open 이 된다) — 그래서 통째로 감싼다.
     *
     * @param  array<int, BoardRequest>  $rows
     * @param  array<int, int>  $updatedIds  그중 **금액만 갱신된** 행 id — 본문에 정정임을 밝힌다.
     *                                       안 밝히면 받는 사람이 두 번째 카톡을 새 요청으로 읽고 **두 번 보낸다**.
     */
    private function notifyCreated(string $type, array $rows, string $requester, array $updatedIds = []): void
    {
        if ($rows === []) {
            return;
        }

        try {
            $code = 'erp_board_request';
            $phones = AlimtalkRecipients::forTimeRules($code);
            if ($phones === []) {
                return;
            }

            $label = __(BoardRequest::meta($type)['badge'] ?? '') ?: $type;
            $total = array_sum(array_map(fn (BoardRequest $r) => (int) $r->amount_krw, $rows));
            $numbers = array_values(array_filter(array_map(fn (BoardRequest $r) => $r->vehicle?->vehicle_number, $rows)));

            // 본문의 가변 목록 = 한 변수에 개행으로 담는다(담당자실적 패턴과 동일).
            //   계좌는 **ERP 가 이미 갖고 있다**(연동 B 가 purchase_seller_* 로 넣어둔다) — board 가 다시
            //   실어 보내지 않는다(노출면 불변). ⚠️ 비어 있으면 빈 줄로 두지 말고 명시한다 —
            //   빈 줄이면 받는 사람이 계좌를 못 찾아 결국 카톡으로 되묻는다(기능의 목적이 무너진다).
            // 🚫 판매대금확인엔 계좌를 싣지 않는다 — 돈이 **들어온** 걸 확인해달라는 신호라
            //    매입처 계좌가 찍히면 받는 사람이 거기로 돈을 보낼 수 있다(방향이 반대다).
            $showPayee = BoardRequest::meta($type)['payee'] ?? false;

            $lines = [];
            $secrets = [];
            foreach ($rows as $r) {
                $v = $r->vehicle;
                // 금액 정정분은 반드시 밝힌다 — 안 밝히면 두 번째 카톡을 새 요청으로 읽고 두 번 보낸다.
                $mark = in_array($r->id, $updatedIds, true) ? ' (금액 수정)' : '';
                $lines[] = '■ '.$label.$mark.' · '.($v?->vehicle_number ?? '-');
                if ($r->amount_krw !== null) {
                    $lines[] = '  '.number_format($r->amount_krw).'원';
                }
                if ($showPayee) {
                    $lines[] = '  '.$this->payeeLine($v);
                    // 계좌번호는 카톡 본문엔 실려야 하지만 alimtalk_logs 에는 남기지 않는다(암호화 컬럼).
                    $secrets[] = (string) ($v?->purchase_seller_account ?? '');
                }
            }

            // 카드 하이라이트도 정정임을 밝힌다(전부 정정일 때만 — 섞이면 본문 줄이 가른다).
            //   ⚠️ 하이라이트 설명은 19자 상한이다. '매입잔금 수정' = 7자로 여유가 있다.
            $allUpdated = count($updatedIds) === count($rows);

            $vars = [
                '건수' => count($rows),
                '구분' => $label.($allUpdated ? ' 수정' : ''),
                '차량' => count($numbers) > 1
                    ? ($numbers[0].' 외 '.(count($numbers) - 1).'대')
                    : ($numbers[0] ?? '-'),
                '금액' => $total > 0 ? number_format($total).'원' : '-',
                '요청자' => $requester,
                '요청내역' => implode("\n", $lines),
            ];

            $svc = BizmAlimtalkService::active();
            foreach ($phones as $phone) {
                $svc->send($code, $phone, $vars, [
                    'vehicle_id' => $rows[0]->vehicle_id,
                    'mask' => $secrets,
                ]);
            }
        } catch (\Throwable $e) {
            // 신호는 이미 만들어졌다 — 알림 실패로 201 을 깨뜨리지 않는다. 무음 실패 방지로 로그만.
            Log::warning('board request alimtalk failed', ['type' => $type, 'error' => $e->getMessage()]);
        }
    }

    /** 송금 계좌 한 줄. 비어 있으면 "계좌 미등록" 을 명시한다(빈 줄 금지 — 위 주석). */
    private function payeeLine(?Vehicle $vehicle): string
    {
        $bank = trim((string) ($vehicle?->purchase_seller_bank ?? ''));
        $account = trim((string) ($vehicle?->purchase_seller_account ?? ''));
        $holder = trim((string) ($vehicle?->purchase_seller_holder ?? ''));

        if ($bank === '' && $account === '') {
            return '계좌 미등록';
        }

        return trim($bank.' '.$account).($holder !== '' ? ' ('.$holder.')' : '');
    }

    /**
     * GET /requests — 상태 폴링(board 칩 갱신).
     *
     * 마진·PII 없음(§3 화이트리스트) — 차량번호·상태·시각 + **요청 금액**뿐.
     * ⚠️ `amount_krw` 는 반드시 실어 준다 — board 는 전송 성공 시 입력칸을 비우므로, 응답에 금액이
     *    없으면 **요청한 본인이 "얼마 요청했지?" 를 어디에서도 볼 수 없다**(금액이 이 기능의 전부인데).
     *    board 가 자기 화면에 저장해 두게 하는 건 판정 지점이 둘로 갈리는 길이라 하지 않는다.
     */
    public function index(Request $request): JsonResponse
    {
        $salesman = $this->salesman($request);
        $status = (string) $request->query('status', 'open');

        $rows = BoardRequest::query()
            ->whereHas('vehicle', fn ($q) => $q->where('salesman_id', $salesman->id))
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->with(['vehicle:id,vehicle_number', 'buyer:id,name'])
            ->orderByDesc('id')
            ->get();

        $data = $rows->groupBy('batch_id')->map(function ($lines) {
            $head = $lines->first();

            return [
                'batch_id' => $head->batch_id,
                'type' => $head->type,
                'status' => BoardRequest::batchStatus($lines),
                'buyer_name' => $head->buyer?->name,
                // 매입 신호는 1대 = 1묶음이라 묶음 금액 = 그 한 줄의 금액. 판매대금확인은 null.
                // 라인에도 같은 값을 싣는다 — board 가 어느 쪽을 읽든 같은 숫자가 나오게.
                'amount_krw' => $head->amount_krw,
                'requested_at' => $head->requested_at?->toIso8601String(),
                'vehicles' => $lines->map(fn (BoardRequest $r) => [
                    'vehicle_number' => $r->vehicle?->vehicle_number,
                    'status' => $r->status,
                    'amount_krw' => $r->amount_krw,
                    'confirmed_at' => $r->confirmed_at?->toIso8601String(),
                ])->values(),
            ];
        })->values();

        return response()->json(['count' => $data->count(), 'requests' => $data]);
    }

    /**
     * POST /requests/{batch}/cancel — 오클릭 무름.
     * open 라인만 취소하고 이미 done 인 라인은 남긴다(회신 기록 보존).
     */
    public function cancel(Request $request, string $batchId): JsonResponse
    {
        $salesman = $this->salesman($request);

        $lines = BoardRequest::where('batch_id', $batchId)
            ->whereHas('vehicle', fn ($q) => $q->where('salesman_id', $salesman->id))
            ->open()->get();

        if ($lines->isEmpty()) {
            return response()->json([
                'error' => 'nothing_to_cancel',
                'message' => '취소할 열린 요청이 없습니다.',
            ], 409);
        }

        // bulk update 는 모델 이벤트가 안 뜬다(SKILLS §2) — 건별 update 로 정상 경로 유지.
        foreach ($lines as $line) {
            $line->update(['status' => BoardRequest::STATUS_CANCELLED]);
            $line->resolveTaskAlarm('cancelled');   // 취소한 일이 벨에 계속 남지 않게
        }

        return response()->json(['ok' => true, 'cancelled' => $lines->count()]);
    }
}
