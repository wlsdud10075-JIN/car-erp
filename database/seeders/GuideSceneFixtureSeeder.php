<?php

namespace Database\Seeders;

use App\Models\BuyerCashAllocation;
use App\Models\BuyerCashFee;
use App\Models\BuyerCashReceipt;
use App\Models\Buyer;
use App\Models\CashAccount;
use App\Models\FinalPayment;
use App\Models\ReceivableHistory;
use App\Models\Consignee;
use App\Models\SavingsStatus;
use App\Models\Settlement;
use App\Models\SignedContract;
use App\Models\BoardRequest;
use App\Models\KakaoItem;
use App\Models\KakaoMessage;
use App\Models\KakaoRoom;
use App\Models\TaskAlarm;
use App\Models\User;
use App\Models\VehicleClaim;
use App\Models\Vehicle;
use App\Models\VehicleShipment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * 씬 점검(화면 회귀) — 예시 데이터 「씬 시작 전 상태」로 되돌리기. 로컬 전용 · dev 전용(master 제외).
 * 정본 = docs/design/scene-walkthrough.md §8 1단계 / scripts/scene/check/README-check.md
 *
 * 1단계 범위 = 씬 5개(bank-balance · documents · bulk-shipping-dates · shipment-fee · buyer-account)가 바꿔 놓는 것만.
 *
 * 원칙
 *  - 🚫 migrate:fresh · truncate · 표 전체 삭제 금지. **씬이 쓰는 고정 표식**(차량번호 · 바이어명 · 계좌번호 ·
 *    발송번호 · 비고 문구)으로 찾은 행만 지운다. 표식이 없는 행은 건드리지 않는다.
 *  - 고정 id 금지 — 차량번호·이름으로 찾는다(PC 마다 id 가 다르다).
 *  - 예시 차·바이어를 새로 만들지 않는다 — 없으면 분명한 메시지로 멈춘다(예시 데이터는 촬영 때 화면으로 만들었다).
 *  - 몇 번 돌려도 같은 결과(idempotent). 지울 것이 없으면 0건.
 *  - 시더는 로그인 없이 돈다 → `auth()->check()` 로 막힌 자동 생성(정산·알림톡)은 안 울린다.
 *
 * 실행: php artisan db:seed --class=Database\\Seeders\\GuideSceneFixtureSeeder
 */
class GuideSceneFixtureSeeder extends Seeder
{
    private const PLATES = ['00가1113', '00가1131', '00가1141'];

    // ── 4단계(board 연동·카톡) ──
    private const SALES_EMAIL = 'sales@car-erp.test';   // board 쪽 영업 신원(로컬 데모)

    private const BOARD_NOTE_MARK = '[씬점검]';

    private const BOARD_PLATES = ['00가1131', '00가1141', '11가1001', '00가1201', '20가2001'];

    private const DEMO_CLAIM_IDS = [9001, 9002, 9003];

    private const PS_NOTE = '인천 야드 3구역';

    private const STOCK_NOTE = '[예시] 탁송 배차 — 평택 화물터미널';

    private const KAKAO_ROOM = '[데모] 한빛모터스 × 수출팀';

    private const KAKAO_VIEWER = 'guide.manager@car-erp.test';


    // ── 3단계(씬 점검 ERP 단독 나머지) ── 기존 PLATES 는 넓히지 않는다 — 3단계 차가 없는 PC 에서 1단계까지 멈추지 않게. 3단계 차는 따로 찾고 없으면 그 메서드만 건너뛴다.
    private const P3_PLATES = ['00가1121', '00가1122', '00가1151', '00가1152', '00가1161'];

    private const RV_DEPOSIT_NOTE = '11/14 송금 확인';           // receivables 「입금」

    private const RV_CASH_NOTE = '현지 대리인 현금 수령';         // receivables 「현금」

    private const PEN_NOTE = '위약금 입금';                       // purchase-cancel 위약금 입금(채권관리)

    private const P3_RECEIPT_NOTES = [                            // 현금 원장이 켜진 회사에서만 생기는 씬 입금 기재
        '채권관리 예시 입금 (씬 점검)', '위약금 예시 입금 (씬 점검)', '적립금 예시 입금 (씬 점검)',
    ];

    private const GATE_REASON = '바이어 잔금 11/20 송금 예정 확인(메일). 선박 일정상 반입 먼저 진행.';

    private const GATE_CONSIGNEE = 'GUIDE SAMPLE CONSIGNEE';

    private const ESIGN_PLATES = ['00가1151', '00가1152', '00가1122', '00가1121'];   // sales-contract-esign 후보(1131 은 서명 완료라 제외)

    /** 2단계 한 바퀴 — 씬이 만드는 전용 차·바이어·컨사이니(scripts/scene/check/_loop.js 와 글자까지 같아야 한다) */
    private const LOOP_PLATE = '00가2001';

    private const LOOP_BUYER = 'SCENE LOOP TRADING';

    private const LOOP_CONSIGNEE = 'SCENE LOOP CONSIGNEE';

    private const BUYER = 'GUIDE SAMPLE TRADING';

    /** bank-balance 씬이 등록하는 가짜 계좌 */
    private const CASH_BANK = '예시';

    private const CASH_ACCOUNT_NOS = ['000-000-000001', '000-000-000002'];

    /** shipment-fee 씬의 발송 번호 */
    private const SHIPMENT_NOS = ['EE123456789KR', '1234567890'];

    /** buyer-account 씬이 남기는 비고 */
    private const RECEIPT_NOTE = '11/14 송금 (가이드 예시)';

    private const HISTORY_NOTE = '11/14 송금분 일부';

    private const FEE_NOTE = '중계은행 수수료';

    /** bulk-shipping-dates 씬이 채우는 칸 — 비워야 「적용 2대」·「기입 2대」가 나온다 */
    private const BULK_COLUMNS = ['shipping_date', 'eta_date', 'vessel_name', 'export_declaration_number', 'container_number'];

    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new \RuntimeException('GuideSceneFixtureSeeder 는 APP_ENV=local 에서만 돈다.');
        }
        // 🔒 씬 점검 러너가 도는 중이면(잠금 파일) 그 러너가 부른 것만 받는다 — 도중에 다른 곳이 돌리면 예시 차가 통째로 지워진다(11-16 실제로 그랬다).
        $lock = storage_path('logs/scene-check/running.lock');
        if (is_file($lock) && (time() - filemtime($lock)) < 7200 && trim((string) file_get_contents($lock)) !== (string) getenv('SCENE_RUN_TOKEN')) {
            throw new \RuntimeException('씬 점검이 도는 중이다(storage/logs/scene-check/running.lock) — 끝난 뒤 다시. 도중에 되돌리면 그 점검이 무더기로 실패한다.');
        }
        // 화면이 남긴 메모 문구(__())로 행을 찾는 곳이 있다 — 콘솔 기본 언어면 영어 문구로 비교해 못 찾는다(11-16 적립 전환 0건 → 시더 멈춤).
        app()->setLocale('ko');

        $vehicles = Vehicle::whereIn('vehicle_number', self::PLATES)->get()->keyBy('vehicle_number');
        $missing = array_diff(self::PLATES, $vehicles->keys()->all());
        $buyer = Buyer::where('name', self::BUYER)->first();
        if ($missing !== [] || ! $buyer) {
            throw new \RuntimeException('예시 데이터가 없다: '.implode(', ', array_filter([
                $missing !== [] ? '차량 '.implode('·', $missing) : null,
                $buyer ? null : '바이어 '.self::BUYER,
            ])).' — 이 시더는 되돌리기만 한다(만들지 않는다).');
        }
        $v1131 = $vehicles['00가1131'];
        $v1141 = $vehicles['00가1141'];

        $out = [];
        // ── 2단계 한 바퀴: 전용 차를 통째로 지운다 — vehicle-register 가 매번 새로 등록한다 ──
        $out[] = $this->resetLoop();
        // ── board 점검(board 러너가 이어서 돈다): 계정 준비 + 지난번 board 점검이 ERP 에 만든 행 되돌리기 ──
        $out[] = $this->ensureBoardCheckAccount();
        $out[] = $this->resetBoardCheck();
        // ── 3단계 되돌리기 — 1단계 buyer-account 블록보다 앞(FIFO 배분) ──
        $p3 = Vehicle::whereIn('vehicle_number', self::P3_PLATES)->get()->keyBy('vehicle_number');
        $all = collect($vehicles->all() + $p3->all());   // 🚫 Eloquent merge() 는 차량번호 키를 버리고 id 로 다시 묶는다 — 배열 합으로 키 보존   // 1단계 3대(00가1113·1131·1141) + 3단계 5대
        $has = fn (string ...$pl) => $this->hasPlates($all, $pl);
        $out[] =$this->restoreReceivables($all['00가1131'], $buyer);
        if ($has('00가1151', '00가1152')) $out[] = $this->restorePurchaseCancel($all['00가1151'], $all['00가1152']);
        $out[] = $this->restoreSavings($all['00가1131'], $all['00가1141'], $buyer);
        $out[] = $this->deleteSceneReceipts($buyer);                 // 위 셋의 현금 기재(있을 때만)
        $out[] = $this->restoreGateOverride($all['00가1141'], $buyer);
        $docsDriver = config('filesystems.disks.'.config('filesystems.vehicle_docs_disk').'.driver');
        if ($docsDriver !== 's3') $out[] = $this->restoreDeregistration($all['00가1131']);
        $out[] = $this->restoreEsign($all, $buyer);
        if ($has('00가1161')) $out[] = $this->restoreUndecidedAndInventory($all['00가1161'], $buyer);
        if ($has('00가1121', '00가1122')) $out[] = $this->restoreSecondaryAndReadjust($all['00가1121'], $all['00가1122']);

        // ── bank-balance: 예시 계좌 2개 (잔액 행은 FK cascade 로 같이 지워진다). cash_snapshots 는 안 건드린다. ──
        $accounts = CashAccount::where('bank', self::CASH_BANK)->whereIn('account_no', self::CASH_ACCOUNT_NOS)->get();
        foreach ($accounts as $acc) {
            $acc->balances()->delete();
            $acc->delete();
        }
        $out[] = 'bank-balance: 예시 계좌 '.$accounts->count().'개 삭제';

        // ── shipment-fee: 두 발송 번호 줄만. 모델 delete → refreshShippingCaches 훅. ──
        $ships = VehicleShipment::whereIn('vehicle_id', [$v1131->id, $v1141->id])->whereIn('tracking_no', self::SHIPMENT_NOS)->get();
        $shipFailed = 0;
        foreach ($ships as $s) {
            try {
                $s->delete();
            } catch (\Throwable $e) {
                $shipFailed++;   // 2차 정산 마감 차 = guardClosed — 예시 차가 마감되면 씬 전제가 깨진 것
                $this->command?->warn('발송 줄 삭제 실패('.$s->tracking_no.'): '.$e->getMessage());
            }
        }
        $out[] = 'shipment-fee: 발송 줄 '.($ships->count() - $shipFailed).'건 삭제'.($shipFailed ? " / 실패 {$shipFailed}" : '');

        // ── buyer-account: 채권 입금 → (잔금 → 배분 cascade) → 수수료(배분 cascade) → 입금 기재 ──
        $histories = ReceivableHistory::where('vehicle_id', $v1131->id)->where('method', 'deposit')->where('note', self::HISTORY_NOTE)->get();
        $fees = BuyerCashFee::where('buyer_id', $buyer->id)->where('note', self::FEE_NOTE)->get();
        $receipts = BuyerCashReceipt::where('buyer_id', $buyer->id)->where('note', self::RECEIPT_NOTE)->get();
        DB::transaction(function () use ($histories, $fees, $receipts) {
            $prev = FinalPayment::$allowConfirmedMutation;
            FinalPayment::$allowConfirmedMutation = true;   // 확정 잔금 삭제 우회 — try/finally 로만
            try {
                foreach ($histories as $h) {
                    $h->delete();   // deleted 훅 → 연결 FinalPayment 삭제 → buyer_cash_allocations cascade
                }
            } finally {
                FinalPayment::$allowConfirmedMutation = $prev;
            }
            foreach ($fees as $f) {
                $f->delete();   // buyer_cash_allocations.fee_id cascade
            }
            foreach ($receipts as $r) {
                $left = BuyerCashAllocation::where('receipt_id', $r->id)->count();
                if ($left > 0) {
                    // 씬 밖 잔금이 이 입금에 물려 있다 — 지우면 그 잔금이 현금 없이 남는다. 멈춘다.
                    throw new \RuntimeException("입금 기재 #{$r->id} 에 다른 배분 {$left}건이 남아 있다 — 손으로 확인할 것.");
                }
                $r->delete();
            }
        });
        $out[] = 'buyer-account: 채권 입금 '.$histories->count().' · 수수료 '.$fees->count().' · 입금 기재 '.$receipts->count().'건 삭제';

        // ── bulk-shipping-dates: 두 차의 선적 칸 비우기. raw update 뒤 캐시 재계산(§8 #43). ──
        //    ⚠️ 이 칸들은 다른 씬(export-tab 등, 1단계 밖)도 채운다 — 그 씬을 점검에 넣을 때 이 블록을 다시 볼 것.
        DB::table('vehicles')->whereIn('id', [$v1131->id, $v1141->id])
            ->update(array_fill_keys(self::BULK_COLUMNS, null) + ['updated_at' => now()]);
        foreach ([$v1131, $v1141] as $v) {
            $v->refresh();
            $v->refreshProgressCache();
            $v->refreshCaches();
        }
        $out[] = 'bulk-shipping-dates: 00가1131·00가1141 선적 칸 '.count(self::BULK_COLUMNS).'개 비움';

        // ── 4단계(board 연동·카톡) — 블록 하나가 실패해도 1~3단계 씬은 계속(경고만) ──
        foreach ([
            'board 요청' => fn () => $this->resetBoardDemoRequests(),          // board-payment-request · board-sale-payment-check
            '선적요청' => fn () => $this->restoreDemoShippingBundle(),          // shipping-requests
            '클레임' => fn () => $this->resetBoardDemoClaims($buyer),           // buyer-claim
            'board 매입 확정' => fn () => $this->resetBoardPurchaseSyncDemo(),  // board-purchase-sync
            'board 보관위치' => fn () => $this->resetBoardStockLocationDemo(),  // inventory-location-board
            '카톡' => fn () => $this->rebuildKakaoDemoRoom(),                   // kakao-todo (KakaoTodoDemoSeeder 흡수 — 미추적이라 부르지 않는다)
        ] as $label => $fn) {
            try {
                $out[] = $fn();
            } catch (\Throwable $e) {
                // 4단계 블록 하나가 실패해도 시더 전체(= 1~3단계 씬)를 죽이지 않는다. 그 씬은 자기 「사전」 단계에서 멈춘다.
                $out[] = "⚠ {$label}: 되돌리기 실패 — ".mb_substr($e->getMessage(), 0, 160);
            }
        }
        foreach (Vehicle::whereIn('vehicle_number', self::BOARD_PLATES)->pluck('id') as $id) { Cache::forget('vehicle-edit-lock:'.$id); }

        // ── 편집 잠금 — 촬영·점검이 계정을 바꿔 같은 차를 연다 ──
        foreach ($vehicles as $v) {
            Cache::forget('vehicle-edit-lock:'.$v->id);   // 키 = vehicles/index editLockKey()
        }
        $out[] = '편집 잠금 '.$vehicles->count().'대 해제';

        foreach ($out as $line) {
            $this->command?->info($line);
        }
    }

    /**
     * 2단계 한 바퀴(vehicle-register → … → monthly-payout)가 남긴 것을 통째로 지운다.
     *
     * 지우는 것: 전용 번호판 차(소프트 삭제 행까지) · 그 차 정산이 든 월정산 배치(+ 결재 단계·결재·조정·변경 이력 cascade)
     *          · 그 배치가 흡수한 이월 청산 행 · 전용 바이어(현금 입금·배분·수수료 cascade) · 그 바이어의 컨사이니(자동 생성분 포함)
     *          · 업로드 파일 폴더 vehicles/{id}(로컬 디스크일 때만) · 편집 잠금.
     * 차량 삭제가 cascade 로 지우는 것: 정산 · 매입 지급 · 판매 잔금 · 현금 배분 · 채권 이력 · 서류 접근 로그 · 사진 · 발송 줄 · 알람 · 미입금 우회.
     * 남기는 것: audit_logs(감사 기록 — 지우지 않는다) · alimtalk_logs(차 FK 가 SET NULL).
     *
     * 🚫 모델 delete 를 안 쓴다 — 지급완료 정산·최종 확정 배치는 모델 가드(Settlement::deleting 등)가 막는다. 표식(번호판·이름)으로 찾은 행만 raw 로 지운다.
     * 🛑 남의 것이 섞여 있으면 지우지 않고 멈춘다: 그 배치에 다른 차 정산 · 그 배치를 가리키는 지급보류(payout_holds — FK RESTRICT, 되돌릴 수 없음)
     *    · 그 바이어·컨사이니를 쓰는 다른 차.
     */
    private function resetLoop(): string
    {
        $vehicleIds = DB::table('vehicles')->where('vehicle_number', self::LOOP_PLATE)->pluck('id')->all();   // withTrashed — raw
        $buyerIds = DB::table('buyers')->where('name', self::LOOP_BUYER)->pluck('id')->all();
        $consigneeIds = DB::table('consignees')
            ->where(fn ($q) => $q->where('name', self::LOOP_CONSIGNEE)->orWhereIn('buyer_id', $buyerIds ?: [0]))
            ->pluck('id')->all();

        if ($vehicleIds === [] && $buyerIds === [] && $consigneeIds === []) {
            return '한 바퀴: 지울 것 없음';
        }

        // 🛑 그 바이어·컨사이니를 쓰는 다른 차 — 이름이 우연히 겹친 실데이터일 수 있다
        $foreignVehicle = DB::table('vehicles')->whereNotIn('id', $vehicleIds ?: [0])
            ->where(fn ($q) => $q->whereIn('buyer_id', $buyerIds ?: [0])->orWhereIn('bl_buyer_id', $buyerIds ?: [0])->orWhereIn('export_buyer_id', $buyerIds ?: [0])
                ->orWhereIn('consignee_id', $consigneeIds ?: [0])->orWhereIn('bl_consignee_id', $consigneeIds ?: [0])->orWhereIn('export_consignee_id', $consigneeIds ?: [0]))
            ->count();
        if ($foreignVehicle > 0) {
            throw new \RuntimeException("한 바퀴: 바이어 「".self::LOOP_BUYER."」/컨사이니를 다른 차 {$foreignVehicle}대가 쓴다 — 손으로 확인할 것.");
        }

        $settlementIds = DB::table('settlements')->whereIn('vehicle_id', $vehicleIds ?: [0])->pluck('id')->all();
        $batchIds = DB::table('settlements')->whereIn('id', $settlementIds ?: [0])->whereNotNull('payout_batch_id')
            ->distinct()->pluck('payout_batch_id')->all();
        if ($batchIds !== []) {
            $foreign = DB::table('settlements')->whereIn('payout_batch_id', $batchIds)->whereNotIn('id', $settlementIds)->count();
            if ($foreign > 0) {
                throw new \RuntimeException("한 바퀴: 월정산 배치 #".implode(',#', $batchIds)." 에 다른 차 정산 {$foreign}건이 들어 있다 — 지우지 않는다.");
            }
            $holds = DB::table('payout_holds')->where(fn ($q) => $q->whereIn('payout_batch_id', $batchIds)->orWhereIn('released_batch_id', $batchIds))->count();
            if ($holds > 0) {
                throw new \RuntimeException("한 바퀴: 월정산 배치 #".implode(',#', $batchIds)." 를 지급보류·추가 {$holds}건이 가리킨다 — 지우지 않는다.");
            }
        }

        // 업로드 파일 — 로컬 디스크일 때만(점검은 로컬 전용이지만 .env 가 s3 를 가리키면 공유 버킷을 지울 수 있다)
        $disk = config('filesystems.vehicle_docs_disk');
        $files = 0;
        if (config("filesystems.disks.{$disk}.driver") === 'local') {
            foreach ($vehicleIds as $id) {
                \Illuminate\Support\Facades\Storage::disk($disk)->deleteDirectory("vehicles/{$id}");
                $files++;
            }
        }

        DB::transaction(function () use ($vehicleIds, $buyerIds, $consigneeIds, $settlementIds, $batchIds) {
            if ($batchIds !== []) {
                DB::table('carryover_clearances')->whereIn('payout_batch_id', $batchIds)->delete();   // FK 없음 — 이 배치가 흡수한 이월을 미청산으로 되돌린다
                DB::table('settlement_payout_batches')->whereIn('id', $batchIds)->delete();             // steps·approvals·adjustments·changes cascade
            }
            if ($settlementIds !== []) {
                DB::table('approval_requests')->where('target_type', \App\Models\Settlement::class)->whereIn('target_id', $settlementIds)->delete();
            }
            if ($vehicleIds !== []) {
                DB::table('vehicles')->whereIn('id', $vehicleIds)->delete();   // FK cascade(정산·지급·잔금·배분·채권·로그·사진·발송…)
            }
            if ($consigneeIds !== []) {
                DB::table('consignees')->whereIn('id', $consigneeIds)->delete();
            }
            if ($buyerIds !== []) {
                DB::table('buyers')->whereIn('id', $buyerIds)->delete();       // 현금 입금(→배분)·수수료·적립금·서류 cascade
            }
        });

        foreach ($vehicleIds as $id) {
            Cache::forget('vehicle-edit-lock:'.$id);
        }

        return '한 바퀴: 차 '.count($vehicleIds).' · 배치 '.count($batchIds).' · 바이어 '.count($buyerIds)
            .' · 컨사이니 '.count($consigneeIds).' 삭제 · 파일 폴더 '.$files;
    }

    /** board 점검 전용(jin 결정 11-16, board 세션 합의) — board-check@local.test 「점검영업」 · 30가3001~3020 · 「[board점검]」 */
    private const BOARD_CHECK_EMAIL = 'board-check@local.test';

    private const BOARD_CHECK_NAME = '점검영업';

    private const BOARD_CHECK_BUYER = 'BOARD CHECK TRADING';

    /** 씬마다 다른 바이어를 쓰게 3곳(board 요청 11-16 — 한 실행에서 미수 차가 한 바이어에 쌓이면 매입 등록 락이 걸린다) */
    private const BOARD_CHECK_BUYERS = ['BOARD CHECK TRADING', 'BOARD CHECK TRADING 2', 'BOARD CHECK TRADING 3'];

    private const BOARD_CHECK_PLATE_LIKE = '30가30%';

    private const BOARD_CHECK_MARK = '[board점검]';

    /**
     * board 점검이 로컬 ERP 에 쓸 계정 — 영업 로그인 사용자 + 영업(salesman) + 그 영업 담당 바이어. 없으면 만들고, 있으면 그대로.
     * board 쪽은 같은 이메일로 board 사용자를 만든다(purchase-sync 영업 매칭 = 이메일).
     */
    private function ensureBoardCheckAccount(): string
    {
        $made = [];
        $userId = DB::table('users')->where('email', self::BOARD_CHECK_EMAIL)->value('id');
        if (! $userId) {
            $userId = DB::table('users')->insertGetId([
                'name' => self::BOARD_CHECK_NAME, 'email' => self::BOARD_CHECK_EMAIL, 'password' => \Illuminate\Support\Facades\Hash::make('password'),
                'permission' => 'user', 'role' => '영업', 'email_verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $made[] = '사용자';
        }
        $salesmanId = DB::table('salesmen')->where('email', self::BOARD_CHECK_EMAIL)->value('id');
        if (! $salesmanId) {
            $salesmanId = DB::table('salesmen')->insertGetId([
                'user_id' => $userId, 'name' => self::BOARD_CHECK_NAME, 'email' => self::BOARD_CHECK_EMAIL, 'is_active' => true,
                'type' => 'freelance', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $made[] = '영업';
        }
        foreach (self::BOARD_CHECK_BUYERS as $bn) {
            if (! DB::table('buyers')->where('name', $bn)->exists()) {
                DB::table('buyers')->insert(['name' => $bn, 'salesman_id' => $salesmanId, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
                $made[] = '바이어 「'.$bn.'」';
            }
        }
        // 🔓 점검 바이어는 매입 등록 락을 안 건다(board 요청 11-16 — 미수 차 1대만 생겨도 100% 라 락, 한 실행에 판매 확정 씬이 6개).
        //   새 예외 코드 없이 기존 「바이어별 재정의」(LockThresholdResolver, buyers.lock_purchase_registration_pct) = 필요 입금률 0%.
        //   락 막힘 화면은 board 1단계가 다른 데모 바이어로 본다.
        DB::table('buyers')->whereIn('name', self::BOARD_CHECK_BUYERS)->update(['lock_purchase_registration_pct' => 0]);

        return 'board 점검 계정: '.($made ? implode('·', $made).' 만듦' : '있음');
    }

    /**
     * board 점검이 ERP 에 만든 행을 「없던 상태」로 — 30가3001~3020 차(구매확정·재고매입·금액 재전송) · 그 영업/표식의 board 요청 ·
     * 그 영업의 선적요청 · 그 차/바이어의 클레임 미러. 계정·바이어는 남긴다.
     * 🛑 그 차의 정산이 월정산 배치에 들어가 있으면 지우지 않고 멈춘다(배치는 남의 차도 담는다).
     */
    private function resetBoardCheck(): string
    {
        $vehicleIds = DB::table('vehicles')->where('vehicle_number', 'like', self::BOARD_CHECK_PLATE_LIKE)->pluck('id')->all();   // 소프트 삭제 포함(raw)
        $buyerIds = DB::table('buyers')->whereIn('name', self::BOARD_CHECK_BUYERS)->pluck('id')->all();
        $inBatch = $vehicleIds === [] ? 0 : DB::table('settlements')->whereIn('vehicle_id', $vehicleIds)->whereNotNull('payout_batch_id')->count();
        if ($inBatch > 0) {
            throw new \RuntimeException("board 점검: 30가30xx 차 정산 {$inBatch}건이 월정산 배치에 들어가 있다 — 손으로 확인할 것.");
        }

        $n = DB::transaction(function () use ($vehicleIds, $buyerIds) {
            $req = DB::table('board_requests')->where(fn ($q) => $q->whereIn('vehicle_id', $vehicleIds ?: [0])
                ->orWhere('requested_by_email', self::BOARD_CHECK_EMAIL)->orWhere('note', 'like', self::BOARD_CHECK_MARK.'%'))->delete();
            $ship = DB::table('shipping_requests')->where(fn ($q) => $q->whereIn('vehicle_id', $vehicleIds ?: [0])
                ->orWhere('requested_by_email', self::BOARD_CHECK_EMAIL)->orWhere('note', 'like', self::BOARD_CHECK_MARK.'%'))->delete();
            $claims = DB::table('vehicle_claims')->where(fn ($q) => $q->whereIn('vehicle_id', $vehicleIds ?: [0])
                ->orWhere('vehicle_number', 'like', self::BOARD_CHECK_PLATE_LIKE)->orWhereIn('buyer_id', $buyerIds ?: [0]))->delete();
            if ($vehicleIds !== []) {
                DB::table('approval_requests')->where('target_type', \App\Models\Settlement::class)
                    ->whereIn('target_id', DB::table('settlements')->whereIn('vehicle_id', $vehicleIds)->pluck('id'))->delete();
                DB::table('vehicles')->whereIn('id', $vehicleIds)->delete();   // FK cascade
            }

            return compact('req', 'ship', 'claims');
        });
        foreach ($vehicleIds as $id) {
            Cache::forget('vehicle-edit-lock:'.$id);
        }

        return 'board 점검: 차 '.count($vehicleIds).' · 요청 '.$n['req'].' · 선적요청 '.$n['ship'].' · 클레임 '.$n['claims'].' 삭제';
    }


    /** 3단계 예시 차가 다 있나 — 없으면 경고하고 그 씬 되돌리기만 건너뛴다(1단계는 계속). */
    private function hasPlates($all, array $plates): bool
    {
        $missing = array_values(array_filter($plates, fn ($p) => ! isset($all[$p])));
        if ($missing !== []) {
            $this->command?->warn('3단계 예시 차 없음: '.implode('·', $missing).' — 그 씬 되돌리기를 건너뛴다(mk_*.php 로 한 번 만들 것)');
        }

        return $missing === [];
    }

    /** raw update 뒤 캐시 재계산 — 1단계 bulk-shipping-dates 블록과 같은 순서. */
    private function rawVehicle(Vehicle $v, array $cols): void
    {
        DB::table('vehicles')->where('id', $v->id)->update($cols + ['updated_at' => now()]);
        $v->refresh();
        $v->refreshProgressCache();
        $v->refreshCaches();
    }

    /** 확정 잔금이 물린 회수 이력 삭제 — deleted 훅이 연결 FinalPayment(→ 배분 cascade)를 지운다. */
    private function deleteHistories($query): int
    {
        $rows = $query->get();
        $prev = FinalPayment::$allowConfirmedMutation;
        FinalPayment::$allowConfirmedMutation = true;   // try/finally 로만
        try {
            DB::transaction(function () use ($rows) {
                foreach ($rows as $h) {
                    $h->delete();
                }
            });
        } finally {
            FinalPayment::$allowConfirmedMutation = $prev;
        }

        return $rows->count();
    }

    /** receivables — 1131: 입금 2,000(+잔금) · 현금 500 · 채권담당 지정 → 미납 7,000 · 「영업담당자와 같게」. (mk_rv.php 값) */
    private function restoreReceivables(Vehicle $v1131, Buyer $buyer): string
    {
        $dep = $this->deleteHistories(ReceivableHistory::where('vehicle_id', $v1131->id)->where('method', 'deposit')->where('note', self::RV_DEPOSIT_NOTE));
        $cash = $this->deleteHistories(ReceivableHistory::where('vehicle_id', $v1131->id)->where('method', 'cash')->where('note', self::RV_CASH_NOTE));
        $this->rawVehicle($v1131, ['receivable_manager_id' => null]);

        // mk_rv 전제 확인(만들지 않는다): USD 10,000 · 확정 계약금 3,000 만 남아야 한다
        $paid = (float) FinalPayment::where('vehicle_id', $v1131->id)->whereNotNull('confirmed_at')->sum('amount');
        if ((float) $v1131->sale_price !== 10000.0 || abs($paid - 3000) > 0.001) {
            // buyer-account(1단계) 블록이 아직 안 돌아서 그 2,000 이 남아 있을 수 있다 — 여기서는 경고만, 씬 사전조건이 다시 본다.
            $this->command?->warn("00가1131 판매가 {$v1131->sale_price} · 확정 잔금 {$paid} — receivables 기대(10,000 · 3,000)와 다름(1단계 되돌리기 뒤 다시 확인)");
        }

        return "receivables: 00가1131 입금 {$dep} · 현금 {$cash} 이력 삭제 · 채권담당 해제";
    }

    /** purchase-cancel — 1151·1152: 매입취소 · 위약금 500 · 위약금 입금 · 미수 마감 → 매입취소 아님 · USD 10,000. (mk_cancel.php 값) */
    private function restorePurchaseCancel(Vehicle $v1151, Vehicle $v1152): string
    {
        foreach ([$v1151, $v1152] as $v) {
            if ($v->cancel_loss_settled_at) {
                throw new \RuntimeException("{$v->vehicle_number} 미수마감 손실이 이미 월정산에 반영됐다(cancel_loss_settled_at) — 되돌리면 그 배치와 어긋난다. 손으로 확인할 것.");
            }
        }
        $n = 0;
        foreach ([$v1151, $v1152] as $v) {
            $n += $this->deleteHistories(ReceivableHistory::where('vehicle_id', $v->id)->where('method', 'deposit')->where('note', self::PEN_NOTE));
            $left = FinalPayment::where('vehicle_id', $v->id)->count();
            if ($left > 0) {
                throw new \RuntimeException("{$v->vehicle_number} 에 씬 밖 판매 잔금 {$left}건이 남아 있다 — 손으로 확인할 것.");
            }
            $this->rawVehicle($v, [
                'cancel_status' => Vehicle::CANCEL_NONE, 'cancelled_at' => null, 'cancel_shortfall_krw' => null,
                'sale_price' => 10_000, 'sale_date' => '2026-10-25', 'exchange_rate' => 1400,   // chk_sale_required — 세 칸 같이
            ]);
        }

        return "purchase-cancel: 00가1151·1152 매입취소 해제 · 판매가 10,000 · 위약금 입금 {$n}건 삭제";
    }

    /** savings — 1131 적립 100 · 1141 사용 100 → 이력 없음 · 1141 적립금 사용 0. 1141 은 gate 씬의 입금률 20% 전제이기도 하다. */
    private function restoreSavings(Vehicle $v1131, Vehicle $v1141, Buyer $buyer): string
    {
        $rows = SavingsStatus::where('buyer_id', $buyer->id)->where('currency', 'USD')->get();
        $scene = $rows->filter(fn ($r) => ($r->vehicle_id === $v1131->id && $r->transaction_type === 'EARNED' && $r->note === "차량 {$v1131->vehicle_number} 판매 탭 적립금 적립")
            || ($r->vehicle_id === $v1141->id && $r->transaction_type === 'USED' && str_starts_with((string) $r->note, "차량 {$v1141->vehicle_number} savings_used 자동 동기화")));
        if ($scene->count() !== $rows->count()) {
            // 잔액(balance)은 행마다 스냅샷이라 중간 행을 지우면 뒤 행 잔액이 틀어진다 — 예시 밖 행이 있으면 멈춘다.
            throw new \RuntimeException('GUIDE SAMPLE TRADING USD 적립금에 씬 밖 행 '.($rows->count() - $scene->count()).'건 — 손으로 확인할 것.');
        }
        // 현금 원장 적립 전환 행(kind=savings) — 배분은 fee_id cascade
        $fees = BuyerCashFee::where('buyer_id', $buyer->id)->where('kind', BuyerCashFee::KIND_SAVINGS)
            ->where('note', __('vehicle.panel.savings_from_cash_note', ['plate' => $v1131->vehicle_number]))->get();
        foreach ($fees as $f) {
            $f->delete();
        }
        // 🚫 모델 delete 금지 — ReceivableHistory 의 savings 행을 모델로 지우면 syncSavingsUsed 가 REFUND 를 새로 만든다.
        $mirror = DB::table('receivable_histories')->where('vehicle_id', $v1141->id)->where('method', 'savings')
            ->where('note', __('receivable.savings.auto_note'))->delete();
        DB::table('savings_statuses')->whereIn('id', $scene->pluck('id'))->delete();
        $this->rawVehicle($v1141, ['savings_used' => 0]);

        return "savings: 적립금 이력 {$scene->count()} · 미러 {$mirror} · 현금 전환 {$fees->count()}건 삭제 · 00가1141 사용 0";
    }

    /** receivables·purchase-cancel·savings 가 현금 원장이 켜진 회사에서만 남기는 입금 기재. 배분이 남아 있으면 멈춘다(1단계와 같은 규칙). */
    private function deleteSceneReceipts(Buyer $buyer): string
    {
        $receipts = BuyerCashReceipt::where('buyer_id', $buyer->id)->whereIn('note', self::P3_RECEIPT_NOTES)->get();
        foreach ($receipts as $r) {
            $left = BuyerCashAllocation::where('receipt_id', $r->id)->count();
            if ($left > 0) {
                throw new \RuntimeException("씬 입금 기재 #{$r->id}({$r->note})에 배분 {$left}건이 남아 있다 — 손으로 확인할 것.");
            }
            $r->delete();
        }

        return '3단계 현금 입금 기재 '.$receipts->count().'건 삭제';
    }

    /** unpaid-gate-override — 1141: 우회 승인 · 반입지 → 없음. 전제(gate_prep.php) = 통관/B/L 바이어·컨사이니 · RRN. */
    private function restoreGateOverride(Vehicle $v1141, Buyer $buyer): string
    {
        // append-only 모델(UnpaidExportOverride::deleting throw) — gate_prep.php 와 같은 query builder 삭제. 표식(사유)으로만.
        $ov = DB::table('unpaid_export_overrides')->where('vehicle_id', $v1141->id)->where('reason', self::GATE_REASON)->delete();
        $others = DB::table('unpaid_export_overrides')->where('vehicle_id', $v1141->id)->exists();

        $consignee = Consignee::where('name', self::GATE_CONSIGNEE)->where('buyer_id', $buyer->id)->first();
        if (! $consignee) {
            throw new \RuntimeException('예시 컨사이니 '.self::GATE_CONSIGNEE.' 없음 — 만들지 않는다(gate_prep.php 로 한 번 만들 것).');
        }
        $this->rawVehicle($v1141, [
            'bl_loading_location' => null,
            'is_override_active' => $others,
            'export_buyer_id' => $buyer->id, 'export_consignee_id' => $consignee->id,
            'bl_buyer_id' => $buyer->id, 'bl_consignee_id' => $consignee->id,
        ]);
        if (! $v1141->nice_reg_owner_rrn) {
            $v1141->nice_reg_owner_rrn = '900101-1000000';   // gate_prep.php 와 같은 가이드용 가짜 번호(암호화 캐스트라 모델로)
            $v1141->saveQuietly();
        }

        return "unpaid-gate-override: 00가1141 우회 승인 {$ov}건 삭제 · 반입지 비움";
    }

    /** deregistration-notice — 1131 말소 칸 비우기(RRN 은 그대로 — 말소 저장의 전제). */
    private function restoreDeregistration(Vehicle $v1131): string
    {
        $this->rawVehicle($v1131, [
            'is_deregistered' => false, 'deregistration_document' => null,
            'registration_number' => null, 'deregistration_date' => null, 'deregistration_notice_phone' => null,
        ]);
        if (! $v1131->nice_reg_owner_rrn) {
            $this->command?->warn('00가1131 RRN 없음 — deregistration-notice 가 「주민(법인)등록번호 필수」에 막힌다');
        }

        return 'deregistration-notice: 00가1131 말소 칸 비움(파일은 디스크에 남는다)';
    }

    /**
     * sales-contract-esign — 후보 차 한 대짜리 세션 중 **서명 안 된 것만** 지운다(모델 delete — signed 는 deleting 훅이 막는다).
     * 🚫 서명 완료 행(촬영 때 1131·1141 에 만든 것)은 건드리지 않는다 — 지우려면 raw 삭제뿐이고 「법적 증거물」 규칙을 우회한다(jin 결정 사항, touches.md).
     */
    private function restoreEsign($all, Buyer $buyer): string
    {
        $n = 0;
        foreach (self::ESIGN_PLATES as $pl) {
            if (! isset($all[$pl])) {
                continue;
            }
            $rows = SignedContract::where('buyer_id', $buyer->id)->where('vehicle_ids', json_encode([$all[$pl]->id]))
                ->whereIn('status', [SignedContract::STATUS_PENDING, SignedContract::STATUS_VIEWED, SignedContract::STATUS_REVOKED])->get();
            foreach ($rows as $c) {
                $c->delete();
                $n++;
            }
        }

        return "sales-contract-esign: 미서명 세션 {$n}건 삭제";
    }

    /** buyer-undecided + inventory-location — 1161: 바이어 미정 · 판매 무르기 · 야드/A-3 · 출고일 → 판매 9,000+운임 1,000 · 위치·출고일 없음. (mk_undecided.php 값) */
    private function restoreUndecidedAndInventory(Vehicle $v1161, Buyer $buyer): string
    {
        $this->rawVehicle($v1161, [
            'buyer_id' => $buyer->id, 'buyer_undecided' => false,
            'sale_price' => 9_000, 'transport_fee' => 1_000, 'sale_date' => '2026-10-25', 'exchange_rate' => 1400,
            'stock_location' => null, 'stock_location_note' => null, 'warehouse_out_date' => null,
        ]);

        return 'buyer-undecided·inventory-location: 00가1161 판매 복원 · 보관위치·출고일 비움';
    }

    /**
     * secondary-settlement + ledger-readjust — 1121·1122: 탁송비 · 2차 마감 · 이월 · (1121) 운임비 300,000 → 지급완료 · 2차 대기 · 비용 대기.
     * 🚨 이월(carryover_out)이 이미 다음 정산·월정산에 흡수/청산됐으면 되돌리면 그 기록과 어긋난다 → 멈춘다.
     */
    private function restoreSecondaryAndReadjust(Vehicle $v1121, Vehicle $v1122): string
    {
        $sets = Settlement::whereIn('vehicle_id', [$v1121->id, $v1122->id])->get();
        if ($sets->count() !== 2) {
            throw new \RuntimeException('00가1121·1122 정산 2건이 없다 — 만들지 않는다(mk_ss2.php 로 한 번 만들 것).');
        }
        foreach ($sets as $s) {
            if ($s->settlement_status !== 'paid' || $s->payout_batch_id) {
                throw new \RuntimeException("정산 #{$s->id} 가 지급완료·배치 밖이 아니다({$s->settlement_status}/batch {$s->payout_batch_id}) — 손으로 확인할 것.");
            }
        }
        $salesmanIds = $sets->pluck('salesman_id')->unique()->filter();
        $absorbed = Settlement::whereIn('salesman_id', $salesmanIds)->whereNotIn('id', $sets->pluck('id'))->whereNotNull('carryover_in_krw')->exists()
            || DB::table('carryover_clearances')->whereIn('salesman_id', $salesmanIds)->exists();
        if ($absorbed && $sets->contains(fn ($s) => $s->carryover_out_krw !== null)) {
            throw new \RuntimeException('이프리 이월이 이미 다음 정산/월정산에 흡수·청산됐다 — 2차 마감을 되돌리면 그 기록과 어긋난다. 손으로 확인할 것.');
        }

        // 마감 Settlement 는 모델 가드 → query builder. confirmed_snapshot(지급 시점 박제)은 그대로 둔다.
        DB::table('settlements')->whereIn('id', $sets->pluck('id'))->update([
            'secondary_status' => 'pending', 'secondary_closed_at' => null, 'secondary_ready_at' => null,
            'carryover_out_krw' => null, 'exchange_difference_krw' => null, 'exchange_rate_at_close' => null,
            'secondary_fx_krw' => null, 'secondary_cost_krw' => null, 'updated_at' => now(),
        ]);
        $this->rawVehicle($v1122, ['cost_towing' => 0]);
        $this->rawVehicle($v1121, ['cost_towing' => 0, 'transport_fee' => 0]);
        // 면장은 「면장 기준액」을 따라갔다(13,000,000 → 13,300,000) — 운임비를 비운 뒤 기준액으로 되돌린다(§13 표).
        DB::table('vehicles')->where('id', $v1121->id)->update(['export_declaration_amount' => $v1121->declaration_base_amount]);
        foreach ([$v1121, $v1122] as $v) {
            Cache::forget(Vehicle::ledgerUnlockCacheKey($v->id));
        }

        return 'secondary-settlement·ledger-readjust: 정산 2건 2차 대기로 · 탁송비 0 · 00가1121 운임비 0 · 재조정 토큰 삭제';
    }

    /**
     * bl-issue (제안 — run() 에 기본으로 넣지 않는다) — 1113 B/L 칸·체크빌·출고일 비우기 → 「통관완료」.
     * ⚠️ 00가1113 은 2단계(한 바퀴) 사슬의 차다. 2단계 시더가 1113 을 어떻게 되돌리는지에 맞춰 합칠 것.
     * ⚠️ 출고일은 거래완료 진입 때 자동으로 채워진 값이라 비운다 — 다른 씬이 출고일을 직접 찍었다면 그 씬 기준으로 다시 볼 것.
     */
    private function restoreBlIssue(Vehicle $v1113): string
    {
        $this->rawVehicle($v1113, [
            'bl_document' => null, 'checkbill_document' => null, 'bl_number' => null,
            'bl_type' => null, 'bl_issue_date' => null, 'warehouse_out_date' => null,
        ]);

        return "bl-issue: 00가1113 B/L 칸 비움 → {$v1113->progress_status_cache}";
    }

    /**
     * board 요청(입금요청·판매대금확인) — 예시 차 3대에서 영업 sales@ 가 보낸 것 중
     *   ① 이 점검이 남긴 것(note 「[씬점검]…」, 처리 상태 무관) ② 아직 열린 것(촬영 때 남은 #24 같은 것 — 열려 있으면 새 요청이 already_open 으로 건너뛰어진다)
     * 을 지운다. 그 요청이 띄운 알람도 같이(열린 것 전부 + 그 요청 시각 ±5초에 생긴 닫힌 것).
     * 🚫 다른 차(91샘0001·12가3456·RESENDDEMO 등)·다른 영업의 요청은 건드리지 않는다.
     */
    private function resetBoardDemoRequests(): string
    {
        $ids = Vehicle::whereIn('vehicle_number', ['00가1131', '00가1141', '11가1001'])->pluck('id');
        if ($ids->count() !== 3) {
            return '⚠ board 요청: 예시 차 3대 중 '.$ids->count().'대만 있음 — 건너뜀';
        }
        $rows = BoardRequest::query()->whereIn('vehicle_id', $ids)
            ->where('requested_by_email', self::SALES_EMAIL)
            ->where(fn ($q) => $q->where('note', 'like', self::BOARD_NOTE_MARK.'%')->orWhere('status', BoardRequest::STATUS_OPEN))
            ->get();

        $alarms = 0;
        DB::transaction(function () use ($rows, &$alarms) {
            foreach ($rows as $r) {
                $type = BoardRequest::meta($r->type)['alarm'] ?? null;
                if ($type && $r->requested_at) {
                    $alarms += TaskAlarm::where('type', $type)->where('vehicle_id', $r->vehicle_id)
                        ->where(fn ($q) => $q->whereNull('resolved_at')
                            ->orWhereBetween('created_at', [$r->requested_at->copy()->subSeconds(5), $r->requested_at->copy()->addSeconds(5)]))
                        ->delete();
                }
                $r->delete();   // BoardRequest 는 SoftDeletes·삭제 훅 없음(2026-11-16 확인). 감사로그(board_request_*)는 이력이라 남긴다.
            }
        });

        return 'board 요청: '.$rows->count().'건 · 알람 '.$alarms.'건 삭제';
    }

    /**
     * 선적요청 예시 묶음(00가1141·00가1131 CONTAINER, 영업 sales@)을 「요청 · B/L요청 없음 · 변경요청 없음」으로.
     *   씬이 바꾸는 것 = status(in_progress) · bl_status(requested) · change_requested_at/meta. bl_type 은 sync 때 값(original) 그대로 둔다.
     *   신고번호·컨테이너번호는 1단계 bulk-shipping-dates 블록이 이미 비운다(같은 두 칸).
     *   B/L요청·변경요청 알람은 이 두 차 것만 지운다(선적요청 알람 shipping_requested 는 남긴다 — 묶음이 「요청」이면 원래 열려 있는 것).
     * 묶음이 취소·완료돼 있으면 되살리지 않고 경고만(누가 [수락(해제)]·[취소]·sync 를 했다 → 사람이 볼 것).
     * 착수 불가 예시(11가1001 RORO 「요청」)는 확인만 한다.
     */
    private function restoreDemoShippingBundle(): string
    {
        $vids = Vehicle::whereIn('vehicle_number', ['00가1131', '00가1141'])->pluck('id');
        $latest = DB::table('shipping_requests')->whereIn('vehicle_id', $vids)
            ->where('requested_by_email', self::SALES_EMAIL)->where('shipping_method', 'CONTAINER')
            ->orderByDesc('id')->value('batch_id');
        if (! $latest) {
            return '⚠ 선적요청: 예시 CONTAINER 묶음 없음 — 건너뜀';
        }
        $rows = DB::table('shipping_requests')->where('batch_id', $latest)->get();
        if ($rows->count() !== 2 || $rows->pluck('vehicle_id')->diff($vids)->isNotEmpty()) {
            return '⚠ 선적요청: 예시 묶음 구성이 2대(00가1131·00가1141)가 아님 — 손대지 않음';
        }
        if ($rows->contains(fn ($r) => ! in_array($r->status, ['requested', 'in_progress'], true))) {
            return '⚠ 선적요청: 예시 묶음이 취소/완료 상태 — 되살리지 않음(사람이 확인)';
        }

        $changed = DB::table('shipping_requests')->where('batch_id', $latest)->update([
            'status' => 'requested', 'bl_status' => 'none', 'processed_at' => null,
            'change_requested_at' => null, 'change_request_meta' => null, 'updated_at' => now(),
        ]);
        $alarms = TaskAlarm::whereIn('type', ['bl_requested', 'shipping_change_requested'])->whereIn('vehicle_id', $vids)->delete();

        $roro = DB::table('shipping_requests as s')->join('vehicles as v', 'v.id', '=', 's.vehicle_id')
            ->where('v.vehicle_number', '11가1001')->where('s.shipping_method', 'RORO')->where('s.requested_by_email', self::SALES_EMAIL)
            ->orderByDesc('s.id')->value('s.status');

        return '선적요청: 예시 묶음 '.$changed.'행 「요청」으로 · 알람 '.$alarms.'건 삭제'
            .($roro === 'requested' ? '' : ' / ⚠ 11가1001 RORO 묶음 상태 '.($roro ?? '없음').'(착수 불가 예시 깨짐)');
    }

    /**
     * 클레임 — board_claim_id 9001·9002·9003(가짜 번호) 중 GUIDE SAMPLE TRADING 것만 지운다.
     * 🚫 같은 바이어의 다른 클레임(board_claim_id 4 = 로컬 board 에서 실제로 보낸 것)은 그대로.
     */
    private function resetBoardDemoClaims(Buyer $buyer): string
    {
        $other = VehicleClaim::whereIn('board_claim_id', self::DEMO_CLAIM_IDS)->where('buyer_id', '!=', $buyer->id)->count();
        $n = VehicleClaim::whereIn('board_claim_id', self::DEMO_CLAIM_IDS)->where('buyer_id', $buyer->id)->delete();

        return '클레임: 예시 '.$n.'건 삭제'.($other ? " / ⚠ 다른 바이어에 같은 번호 {$other}건(손대지 않음)" : '');
    }

    /**
     * board 매입 확정 예시 00가1201 — 보관위치 비고만 비운다(씬이 board 재전송으로 「빈 칸만 채움」을 본다).
     *   비고가 씬 값과 같을 때만. 다른 값이면 누가 고친 것이라 손대지 않는다.
     *   raw update — 감사로그를 안 남긴다(되돌리기는 이력이 아니다). 캐시 영향 없음(진행상태·미수와 무관한 칸).
     */
    private function resetBoardPurchaseSyncDemo(): string
    {
        $v = DB::table('vehicles')->where('vehicle_number', '00가1201')->whereNull('deleted_at')->first(['id', 'stock_location_note']);
        if (! $v) {
            return '⚠ board 매입 확정: 예시 00가1201 없음 — 건너뜀';
        }
        if ($v->stock_location_note === null) {
            return 'board 매입 확정: 00가1201 비고 이미 비어 있음';
        }
        if ($v->stock_location_note !== self::PS_NOTE) {
            return '⚠ board 매입 확정: 00가1201 비고가 씬 값이 아님 — 손대지 않음';
        }
        DB::table('vehicles')->where('id', $v->id)->update(['stock_location_note' => null, 'updated_at' => now()]);

        return 'board 매입 확정: 00가1201 비고 비움';
    }

    /**
     * board 탁송 보관위치 예시 20가2001 — 위치·비고를 「비움」(씬 전 값)으로. 비고가 씬 표식일 때만.
     */
    private function resetBoardStockLocationDemo(): string
    {
        $v = DB::table('vehicles')->where('vehicle_number', '20가2001')->whereNull('deleted_at')->first(['id', 'stock_location', 'stock_location_note']);
        if (! $v) {
            return '⚠ board 보관위치: 예시 20가2001 없음 — 건너뜀';
        }
        if ($v->stock_location === null && $v->stock_location_note === null) {
            return 'board 보관위치: 20가2001 이미 비어 있음';
        }
        if ($v->stock_location_note !== self::STOCK_NOTE) {
            return '⚠ board 보관위치: 20가2001 비고가 씬 표식이 아님 — 손대지 않음';
        }
        DB::table('vehicles')->where('id', $v->id)->update(['stock_location' => null, 'stock_location_note' => null, 'updated_at' => now()]);

        return 'board 보관위치: 20가2001 비움';
    }

    /**
     * 카톡 데모 방 — KakaoTodoDemoSeeder(미추적·로컬 전용) 그대로 흡수 + 보는 계정의 「담당 분야」 지우기(분야 고르기 창이 다시 뜨게).
     * 🔒 시각은 실행 시점 기준 상대값(표시 기간 3일 안). 매번 지우고 다시 만든다(메시지·구독·항목 cascade).
     * 🚫 분류(type)를 반드시 채운다 — 비면 페이지가 열리자마자 Claude 분류(비용)를 부른다.
     * 🚫 pivot my_name 을 반드시 채운다 — 비면 mount(autoSetUpRooms)가 서버에서 classifyPending 을 부른다.
     */
    private function rebuildKakaoDemoRoom(): string
    {
        $me = User::where('email', self::KAKAO_VIEWER)->first();
        if (! $me) {
            return '⚠ 카톡: 보는 계정 '.self::KAKAO_VIEWER.' 없음 — 건너뜀';
        }

        KakaoRoom::where('name', self::KAKAO_ROOM)->get()->each->delete();
        DB::table('kakao_user_topics')->where('user_id', $me->id)->delete();

        $veh = fn (string $no) => Vehicle::where('vehicle_number', $no)->value('id');
        $us = [$me->name, '이통관', '재무담당'];
        $them = ['한빛 이실장', '한빛 정과장'];
        $room = KakaoRoom::create(['name' => self::KAKAO_ROOM]);
        $room->forceFill(['sides' => [KakaoRoom::SIDE_US => $us, KakaoRoom::SIDE_THEM => $them]])->save();
        $room->users()->attach($me->id, ['my_name' => $me->name, 'selected' => true, 'last_seen_at' => now()]);
        $room->setUpSides($me->name, ['이통관', '재무담당']);

        $now = now()->second(0);
        $made = [];
        $add = function (string $key, int $minutesAgo, string $sender, string $body, string $type, ?string $to, ?string $topic, ?string $vehicleNo = null, ?string $mention = null, ?string $resolves = null, ?string $amount = null) use ($room, $now, $veh, &$made) {
            $made[$key] = KakaoMessage::create([
                'room_id' => $room->id, 'msg_key' => sha1(self::KAKAO_ROOM.'|'.$key), 'sent_at' => $now->copy()->subMinutes($minutesAgo),
                'sender' => $sender, 'body' => $body, 'type' => $type, 'to_side' => $to, 'topic' => $topic, 'mention' => $mention,
                'vehicle_no' => $vehicleNo, 'vehicle_id' => $vehicleNo ? $veh($vehicleNo) : null, 'amount' => $amount,
                'resolves_id' => $resolves ? $made[$resolves]->id : null, 'ai' => ['demo' => true],
            ]);
        };
        // 문구·분 단위 시각 = KakaoTodoDemoSeeder 와 글자 단위로 같게(가이드 사진과 같은 대화). 바꾸면 scripts/scene/check/kakao-todo.js 의 MSG 도.
        $add('a', 50 * 60, '한빛 이실장', '00가1141 미수 7,800불 이번 주 금요일 입금 예정입니다. 들어가면 B/L 서렌더 부탁드려요', '요청', '우리', '입금', '00가1141', null, null, 'USD 7,800');
        $add('b', 26 * 60, '한빛 정과장', '00가1152 말소증 언제쯤 나올까요? 바이어가 서류 먼저 받아보고 싶어 합니다', '질문', '우리', '말소', '00가1152');
        $add('c', 25 * 60, '한빛 이실장', '다음 달 물량 견적표 정리되는 대로 보내드릴게요. 검토 부탁드립니다', '요청', '우리', '기타');
        $add('d', 20 * 60, '한빛 정과장', '환율 기준을 계약일로 할지 입금일로 할지 정리해서 알려주세요', '질문', '우리', '입금');
        $add('e', 180, '한빛 이실장', '11가1001 컨테이너 다음 주 화요일 선적으로 잡혔습니다. 반입지 인천 맞으시죠?', '질문', '우리', '선적', '11가1001');
        $add('f', 150, '한빛 정과장', '인보이스 금액 USD 로 다시 발행 가능할까요?', '질문', '우리', '서류');
        $add('g', 130, $me->name, '네 다시 발행해서 메일로 보내드리겠습니다', '대답', '상대', '서류', null, null, 'f');
        $add('h', 100, $me->name, '00가1131 잔금 2,500불 송금 일정 알려주실 수 있을까요?', '질문', '상대', '입금', '00가1131', null, null, 'USD 2,500');
        $add('i', 70, '이통관', '@한빛 정과장 00가1141 수출신고서 수정본 메일로 보냈습니다. 확인 부탁드려요', '요청', '상대', '통관', '00가1141', '한빛 정과장');
        $add('j', 40, '한빛 이실장', '@한빛 정과장 탁송 기사님 연락처 공유 부탁해요', '요청', '상대', '기타', null, '한빛 정과장');

        $room->setUpSides($me->name, ['이통관', '재무담당']);
        KakaoItem::create(['message_id' => $made['c']->id, 'user_id' => $me->id, 'state' => 'snoozed', 'snooze_until' => $now->copy()->addDay()->setTime(10, 0), 'snooze_reason' => '견적표 받으면 검토']);
        KakaoItem::create(['message_id' => $made['d']->id, 'user_id' => $me->id, 'state' => 'snoozed', 'snooze_until' => $now->copy()->subHour(), 'snooze_reason' => '재무와 상의 후']);

        return '카톡: 데모 방 다시 만듦(메시지 '.count($made).') · 담당 분야 지움';
    }
}
