<?php

namespace App\Console\Commands;

use App\Models\Vehicle;
use App\Services\BizmAlimtalkService;
use App\Support\AlimtalkRecipients;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 차량 픽업 재촉 알림톡 (erp_pickup_reminder) — 매일, 그 차량 담당 영업에게.
 * 조건 = 매입일(purchase_date) + 2일 경과 & 매입 미완납(purchase_unpaid_amount > 0, 필드 무관).
 *   해소 = 매입 완납. 전체 영업 아님 — 담당자에게만(AlimtalkRecipients::forVehicleSalesman).
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
                ->with('salesman')
                ->get()
                ->filter(fn (Vehicle $v) => (int) $v->purchase_unpaid_amount > 0);

            if ($candidates->isEmpty()) {
                $this->info('pickup: 대상 0건 — skip.');

                return self::SUCCESS;
            }

            $svc = BizmAlimtalkService::active();
            $sent = 0;
            foreach ($candidates as $v) {
                $recipients = AlimtalkRecipients::forVehicleSalesman($v);
                if (empty($recipients)) {
                    continue;   // 담당 영업 전화 없음 — skip
                }
                $elapsed = now()->startOfDay()->diffInDays($v->purchase_date);
                $vars = [
                    '차량번호' => (string) $v->vehicle_number,
                    '구입처' => (string) ($v->purchase_from ?? '-'),
                    '미지급금액' => number_format((int) $v->purchase_unpaid_amount).'원',
                    '매입일' => optional($v->purchase_date)->format('Y-m-d') ?? '-',
                    '경과일' => (string) $elapsed,
                ];
                foreach ($recipients as $phone) {
                    $svc->send('erp_pickup_reminder', $phone, $vars, ['vehicle_id' => $v->id]);
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
