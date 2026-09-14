<?php

namespace App\Console\Commands;

use App\Models\Vehicle;
use App\Services\BizmAlimtalkService;
use App\Support\AlimtalkRecipients;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 차량 픽업 재촉 알림톡 (erp_pickup_reminder) — 매일, 그 차량 담당 영업에게.
 * 조건 = 매입일(purchase_date) + 2일 경과 & 매입 미완납(purchase_unpaid_amount > 0, 필드 무관)
 *        & **매입취소가 아닌 차**(jin 2026-09-14).
 *   해소 = 매입 완납. 수신 범위는 역할이 정한다(AlimtalkRecipients::scopedFor) — 영업은 본인 담당분만.
 *
 * 🚫 **매입취소 차는 뺀다 — 「가져올 차」가 없기 때문이다.**
 *    매입취소는 마커만 바꾸고 매입가·지급기록을 그대로 두므로(위약금을 채권 배관으로 추적하는 설계)
 *    purchase_unpaid_amount > 0 이 **영원히 참**이다 ⇒ 안 빼면 취소한 차의 독촉이 영구히 나간다.
 *    실측 2026-09-14: heymanerp 대상 10대 중 3대 · 최근 30일 발송 33대 중 3대가 취소분이었다.
 *
 * ⚠️ **매입 미지급 알림톡(AlimtalkPurchaseUnpaid)은 반대로 취소분을 포함한다** — 딜러 대금은
 *    재무가 확인해야 할 돈이라는 jin 2026-09-09 결정이다(Vehicle::NOT_FOR_CANCELLED 주석).
 *    같은 「미지급」 조건인데 소비자가 달라 정답이 다르다. 🚫 둘을 통일하려 하지 말 것.
 *
 * 🧭 이 커맨드가 09-09 매입취소 제외를 못 물려받은 이유 = **조건을 scopeAction 에서 가져오지 않고
 *    직접 옮겨 적었기 때문**이다(SKILLS §8 #38·#45). 조건을 손볼 땐 그 점을 먼저 볼 것.
 */
class AlimtalkPickup extends Command
{
    protected $signature = 'alimtalk:pickup';

    protected $description = '매입일+2일 경과 & 매입 미완납 차량 픽업 재촉 알림톡 — 담당 영업.';

    public function handle(): int
    {
        try {
            $cutoff = now()->subDays(2)->toDateString();
            $candidates = Vehicle::query()
                ->where('purchase_price', '>', 0)
                ->whereNotNull('purchase_date')
                ->where('purchase_date', '<=', $cutoff)
                ->where(fn ($q) => $q->where('progress_status_cache', '!=', '거래완료')
                    ->orWhereNull('progress_status_cache'))
                // 🚫 매입취소 차는 「가져올 차」가 없다 (jin 2026-09-14). 위 docblock 참조.
                ->where('cancel_status', Vehicle::CANCEL_NONE)
                ->with('salesman')
                ->get()
                ->filter(fn (Vehicle $v) => (int) $v->purchase_unpaid_amount > 0);

            if ($candidates->isEmpty()) {
                $this->info('pickup: 대상 0건 — skip.');

                return self::SUCCESS;
            }

            // 🎯 체크가 유일한 스위치, 범위는 역할이 정한다 (jin 2026-08-24).
            //    구 코드는 역할 선택 없이 담당 영업에게만 보냈다 — 화면에 안 보이는 규칙이라
            //    「영업이 왜 안 받지?」 를 확인할 방법이 없었다. 기본값 ['영업'] 으로 동작은 동일.
            $targets = AlimtalkRecipients::scopedFor('erp_pickup_reminder', $candidates);
            if (empty($targets)) {
                $this->info('pickup: 수신자 없음 — skip.');

                return self::SUCCESS;
            }

            $svc = BizmAlimtalkService::active();
            $sent = 0;
            foreach ($targets as $phone => $mine) {
                foreach ($mine as $v) {
                    // ⚠️ Carbon 3 의 diffInDays 는 부호 있는 값($대상 - $기준). now()->diffInDays(과거) = 음수라
                    //    "-42일 경과" 로 발송됐었다(2026-07-28 발견). 매입일 기준 → 오늘 방향으로 계산.
                    $elapsed = (int) $v->purchase_date->copy()->startOfDay()->diffInDays(now()->startOfDay());
                    $svc->send('erp_pickup_reminder', $phone, [
                        '차량번호' => (string) $v->vehicle_number,
                        '구입처' => (string) ($v->purchase_from ?? '-'),
                        '미지급금액' => number_format((int) $v->purchase_unpaid_amount).'원',
                        '매입일' => optional($v->purchase_date)->format('Y-m-d') ?? '-',
                        '경과일' => (string) $elapsed,
                    ], ['vehicle_id' => $v->id]);
                    $sent++;
                }
            }
            $this->info("pickup: {$candidates->count()}대 판정, {$sent}건 발송 시도.");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('alimtalk:pickup 실패', ['error' => $e->getMessage()]);
            $this->error('pickup 실패: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
