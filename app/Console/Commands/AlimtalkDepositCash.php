<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\Vehicle;
use App\Services\BizmAlimtalkService;
use App\Support\AlimtalkRecipients;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 보증금 매입 바이어 입금 독촉 알림톡 (2026-07-23, jin) — 매일 아침.
 *   대상 = 보증금으로 매입(is_deposit_purchase)한 차 중 아직 선적 진입 전(bl_loading_location NULL·거래완료 아님)
 *          이고 판매 입금이 선적 기준(Setting::lockThreshold('shipping_entry'))에 미달해 선적 보류 중인 차.
 *   ⏱ 도장(deposit_purchase_at) 후 경과일 분기:
 *     - D+5 ~ D+10 (독촉)  → erp_deposit_cash_due : 담당 영업(본인 차) + 관리(전체 목록).
 *     - D+10 초과 (에스컬)  → erp_deposit_cash_overdue : 대표(처분 판단 요청). 독촉 대상에선 제외.
 *   ✅ 자동 중단 = 매 실행 시 unpaid_ratio 재계산 → 바이어가 기준 넘기면 목록에서 빠짐(발송 정지).
 *   토글 OFF·tmplId 미입력이면 BizmAlimtalkService 가 skip 로그(발송 0) — 배포≠작동.
 */
class AlimtalkDepositCash extends Command
{
    protected $signature = 'alimtalk:deposit-cash';

    protected $description = '보증금 매입 바이어 입금 독촉(영업·관리) + 초과분 처분요청(대표) 알림톡.';

    public function handle(): int
    {
        try {
            $threshold = Setting::lockThreshold('shipping_entry');       // 미수율 cutoff (기준% → 초과 시 락)
            $reqPct = Setting::lockRequiredPaidPct('shipping_entry');    // 필요 입금률(%)
            $now = now()->startOfDay();

            $candidates = Vehicle::query()
                ->where('is_deposit_purchase', true)
                ->whereNotNull('deposit_purchase_at')
                ->whereNull('bl_loading_location')   // 아직 선적 진입 안 함 = 보류 중
                ->where(fn ($q) => $q->where('progress_status_cache', '!=', '거래완료')
                    ->orWhereNull('progress_status_cache'))
                ->with(['buyer', 'salesman'])
                ->get()
                ->filter(function (Vehicle $v) use ($threshold) {
                    $r = $v->unpaid_ratio;

                    return $r !== null && $r > $threshold;   // 여전히 기준 미달(락 유지)
                });

            if ($candidates->isEmpty()) {
                $this->info('deposit-cash: 대상 0건 — skip.');

                return self::SUCCESS;
            }

            // 경과일 분기
            $due = collect();
            $overdue = collect();
            foreach ($candidates as $v) {
                $days = (int) $v->deposit_purchase_at->copy()->startOfDay()->diffInDays($now);
                if ($days >= Vehicle::DEPOSIT_CASH_OVERDUE_DAYS) {
                    $overdue->push([$v, $days]);
                } elseif ($days >= Vehicle::DEPOSIT_CASH_DUE_DAYS) {
                    $due->push([$v, $days]);
                }
            }

            $cap = 20;   // 본문 1000자 제한 — 목록 상한. 상세는 채권관리.
            $line = fn (Vehicle $v, int $days, bool $showReq): string => sprintf(
                '▶ %s · %s · %d%% 입금 (%s경과 %d일)',
                $v->vehicle_number,
                $v->buyer?->name ?? '-',
                (int) round((1 - (float) $v->unpaid_ratio) * 100),
                $showReq ? "기준 {$reqPct}%, " : '',
                $days
            );
            $listOf = fn ($rows, bool $showReq): string => collect($rows)->take($cap)
                ->map(fn ($x) => $line($x[0], $x[1], $showReq))->implode("\n")
                .(count($rows) > $cap ? "\n▶ 외 ".(count($rows) - $cap).'건' : '');

            $svc = BizmAlimtalkService::active();
            $sent = 0;

            // ① 독촉 (D+5~10) — 관리 전체 + 담당 영업 본인
            if ($due->isNotEmpty()) {
                $mgrList = $listOf($due->all(), true);
                foreach (AlimtalkRecipients::forBroadcast('erp_deposit_cash_due') as $phone) {
                    $svc->send('erp_deposit_cash_due', $phone, ['보증금목록' => $mgrList]);
                    $sent++;
                }
                foreach ($due->groupBy(fn ($x) => $x[0]->salesman_id) as $group) {
                    $recips = AlimtalkRecipients::forVehicleSalesman($group->first()[0]);
                    if (empty($recips)) {
                        continue;
                    }
                    $ownList = $listOf($group->all(), true);
                    foreach ($recips as $phone) {
                        $svc->send('erp_deposit_cash_due', $phone, ['보증금목록' => $ownList]);
                        $sent++;
                    }
                }
            }

            // ② 초과 (D+10~) — 대표 처분 요청
            if ($overdue->isNotEmpty()) {
                $ovList = $listOf($overdue->all(), false);
                foreach (AlimtalkRecipients::forBroadcast('erp_deposit_cash_overdue') as $phone) {
                    $svc->send('erp_deposit_cash_overdue', $phone, ['초과목록' => $ovList]);
                    $sent++;
                }
            }

            $this->info("deposit-cash: 독촉 {$due->count()}대 / 초과 {$overdue->count()}대 → {$sent}건 발송 시도.");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('alimtalk:deposit-cash 실패', ['error' => $e->getMessage()]);
            $this->error('deposit-cash 실패: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
