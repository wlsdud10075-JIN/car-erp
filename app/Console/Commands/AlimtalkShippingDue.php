<?php

namespace App\Console\Commands;

use App\Models\UnpaidExportOverride;
use App\Models\Vehicle;
use App\Services\BizmAlimtalkService;
use App\Support\AlimtalkRecipients;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 선적 임박 미수 요약 알림톡 (erp_shipping_due) — 매일, 관리에게 목록형 1건.
 * 조건 = 선적일(shipping_date) 5일 이내 & 미완납 & B/L 미발급.
 *   <50%(입금 우회 진행)는 [50%미만·사유] 로 강조 — unpaid_ratio>0.5 + 진입 우회(clearance/shipping) 존재.
 */
class AlimtalkShippingDue extends Command
{
    protected $signature = 'alimtalk:shipping-due';

    protected $description = '선적일 5일 전 미완납 차량 목록 알림톡 — 관리.';

    public function handle(): int
    {
        try {
            $recipients = AlimtalkRecipients::managers();
            if (empty($recipients)) {
                $this->info('shipping-due: 수신자(관리) 없음 — skip.');

                return self::SUCCESS;
            }

            $today = now()->startOfDay();
            $rows = Vehicle::query()
                ->where('sales_channel', 'export')
                ->whereNotNull('shipping_date')
                ->whereBetween('shipping_date', [$today->toDateString(), $today->copy()->addDays(5)->toDateString()])
                ->where('sale_unpaid_amount_krw_cache', '>', 0)
                ->whereNull('bl_document')
                ->get();

            if ($rows->isEmpty()) {
                $this->info('shipping-due: 대상 0건 — skip.');

                return self::SUCCESS;
            }

            $lines = $rows->map(function (Vehicle $v) use ($today) {
                $dday = max(0, $today->diffInDays($v->shipping_date, false));
                $ratio = $v->unpaid_ratio;
                $pct = $ratio !== null ? round($ratio * 100) : null;
                $line = "▶ {$v->vehicle_number} · 선적 D-{$dday}".($pct !== null ? " · 미수 {$pct}%" : '');

                // <50%(입금 50% 미만 우회 진행) 강조 — 진입 우회(clearance/shipping) 사유 표시.
                if ($ratio !== null && $ratio > 0.5) {
                    $override = UnpaidExportOverride::query()
                        ->where('vehicle_id', $v->id)
                        ->whereIn('stage', ['clearance', 'shipping'])
                        ->latest('id')->first();
                    if ($override) {
                        $reason = trim((string) $override->reason);
                        $line .= ' [50%미만'.($reason !== '' ? '·사유: '.$reason : '').']';
                    }
                }

                return $line;
            })->implode("\n");

            $vars = ['선적미수목록' => $lines];

            $svc = BizmAlimtalkService::active();
            foreach ($recipients as $phone) {
                $svc->send('erp_shipping_due', $phone, $vars);
            }
            $this->info("shipping-due: {$rows->count()}대, ".count($recipients).'명 발송 시도.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('alimtalk:shipping-due 실패', ['error' => $e->getMessage()]);
            $this->error('shipping-due 실패: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
