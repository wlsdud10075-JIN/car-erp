<?php

namespace App\Console\Commands;

use App\Models\Settlement;
use Illuminate\Console\Command;

/**
 * jin 2026-07-18 — 마감된 달로 뒤늦게 귀속된 "미지급" 정산을 현재 열린 달로 이월.
 *
 * 배경: 귀속월 = 완납월(fullPaymentMonth)로 back-date 되던 탓에, 이미 마감(배치 지급)된
 *   달로 6월자 잔금이 7월에 입력되면 마감된 6월 그룹에 사후 합류했다. 사용자 규칙(2026-07-18):
 *   "마감된 달은 그 순간 동결. 늦게 완성된 건은 완성된 달(현재 열린 달)에 포함."
 *
 * 대상(안전): settlement_status ∈ {pending, confirmed} && payout_batch_id IS NULL
 *   && attributed_month 의 달이 마감(승인된 배치 존재).
 *   ⇒ 이미 배치·지급(paid)된 정산은 절대 안 건드림 (마감 배치 동결 보존).
 *
 * dry-run 기본. --apply 로 실제 이월. 순수 attributed_month 컬럼만 갱신(모델 이벤트 우회).
 */
class ReanchorClosedMonthSettlements extends Command
{
    protected $signature = 'settlements:reanchor-closed-month {--apply : 실제 이월 (미지정=dry-run)}';

    protected $description = '마감된 달로 뒤늦게 귀속된 미지급 정산을 현재 열린 달로 이월';

    public function handle(): int
    {
        // 🔀 2026-11-20 (jin (가)) — 판정을 Settlement::reanchorReleased 한 곳으로 옮겼다. 대상이 좁아졌다:
        //    확정 · 미배치 · 마감된 달 귀속 · **지금 보류가 아님** · 지급 대상 담당자 · 퇴사 배치 몫 아님.
        //    (구: pending·지급 제외 계정까지 옮겼다 — 헤이맨 정산이 달이 마감될 때마다 앞으로 굴러간다.)
        //    입금·회수 줄 저장 때는 그 차만 즉시 옮기고(Vehicle::refreshCaches), 여기는 그 밖의 경로를 매일 보정한다.
        $apply = (bool) $this->option('apply');
        $n = Settlement::reanchorReleased(null, ! $apply);
        $open = Settlement::firstOpenMonth()->format('Y-m');
        $this->info(($apply ? '' : '[dry-run] ')."보류가 풀려 마감된 달에 남은 정산 {$n}건 → {$open}".($apply ? ' 이동 완료.' : ' (실제 이동은 --apply)'));

        return self::SUCCESS;
    }
}
