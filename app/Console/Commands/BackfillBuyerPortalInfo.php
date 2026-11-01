<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Buyer;
use App\Support\BuyerPortalBackfill;
use Illuminate\Console\Command;

/**
 * 🗺️ 바이어 「포털·지도 정보」 일괄 채움 (jin 2026-11-01 «미리보기 먼저») — 규칙 = App\Support\BuyerPortalBackfill.
 *
 *   php artisan buyers:backfill-portal-info                # dry-run(기본) — 바꿀 값만 표로
 *   php artisan buyers:backfill-portal-info --whatsapp     # WhatsApp 에 전화 복사까지 미리보기
 *   php artisan buyers:backfill-portal-info --apply        # 실제 기입(빈 칸만) + 감사로그 buyers_portal_backfill
 *
 * 🚫 운영 --apply 는 jin 승인 때만. 채운 값은 바이어 편집 창에서 고칠 수 있고, 도시는 지도 좌표의 근거라 결과를 사람이 본다.
 */
class BackfillBuyerPortalInfo extends Command
{
    protected $signature = 'buyers:backfill-portal-info
        {--whatsapp : WhatsApp 칸이 비면 전화번호를 복사(기본 = 안 함, 비면 포털이 전화로 대체)}
        {--apply : 실제 기입 (미지정 = dry-run)}';

    protected $description = '바이어 주소·전화에서 포털·지도 칸(사무실 주소·도시·WhatsApp)을 빈 칸만 채운다 — 기본 dry-run';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $withWa = (bool) $this->option('whatsapp');
        $buyers = Buyer::query()->orderBy('id')->get(['id', 'name', 'address', 'contact_phone', 'office_address', 'city', 'whatsapp_phone']);

        $rows = [];
        $counts = ['office_address' => 0, 'city' => 0, 'whatsapp_phone' => 0, 'city_unknown' => 0];
        foreach ($buyers as $b) {
            $plan = BuyerPortalBackfill::plan($b, $withWa);
            if (blank($b->city) && ! isset($plan['city']) && trim((string) $b->address) !== '') {
                $counts['city_unknown']++;
            }
            if ($plan === []) {
                continue;
            }
            foreach ($plan as $k => $v) {
                $counts[$k]++;
            }
            $rows[] = [$b->id, mb_strimwidth((string) $b->name, 0, 24, '…'), mb_strimwidth((string) $b->address, 0, 40, '…'),
                $plan['city'] ?? ($b->city ?: '(모름)'), isset($plan['office_address']) ? '복사' : '-', $plan['whatsapp_phone'] ?? ($withWa ? '-' : '(안 함)')];
            if ($apply) {
                $before = $b->only(array_keys($plan));
                $b->update($plan);
                foreach ($plan as $col => $val) {
                    AuditLog::recordChange($b, $col, $before[$col] ?? null, $val, 'buyers_portal_backfill');
                }
            }
        }

        $this->info(($apply ? '적용' : '미리보기(dry-run)')." — 바이어 {$buyers->count()}명 중 바꿀 곳 ".count($rows).'명');
        $this->line('  사무실 주소 복사 '.$counts['office_address'].' · 도시 추출 '.$counts['city'].' · 도시 못 가림(주소는 있음) '.$counts['city_unknown']
            .($withWa ? ' · WhatsApp 복사 '.$counts['whatsapp_phone'] : ' · WhatsApp 안 함(비면 포털이 전화로 대체)'));
        if ($rows !== []) {
            $this->table(['id', '바이어', '주소(원문)', '도시(추출)', '사무실주소', 'WhatsApp'], $rows);
        }
        if (! $apply) {
            $this->line('실제 기입 = --apply (빈 칸만 채움, 감사로그 남김)');
        }

        return self::SUCCESS;
    }
}
