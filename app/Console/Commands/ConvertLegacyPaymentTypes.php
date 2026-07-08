<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\FinalPayment;
use Illuminate\Console\Command;

/**
 * 레거시 입금 유형(계약금 deposit_down / 중도금 interim / 선수금1 advance_1)을 잔금(balance)으로 일괄 전환.
 *
 * 판매탭 입금 단순화(2026-07-06 jin 확정 · 2026-07-08 구현)의 후속 — 판매탭에서 숨겨진 "과거 방식" 행을
 * 잔금으로 통합해 [잔금] N 목록에 노출·편집 가능하게 한다.
 *   - type → 'balance', 비고(note)에 원 유형 표기("구 계약금" 등, 중복 방지)
 *   - exchange_rate 없으면(null/0) 차량 판매환율로 보정 → saving 훅이 amount_krw 재계산(채권 KRW 반영)
 *   - 미수계산(§13 타입무관 차감)·총입금액 불변 — 데이터 정합. 감사로그(payment_type_converted) 기록.
 *   - transfer 링크 행은 스킵(이체 잠금). 확정행 exchange_rate 보정은 $allowConfirmedMutation 시스템 우회.
 *   dry-run 기본, --apply 로 실제 전환. (3사 운영은 수동 SSH 개별 실행 — 별건.)
 */
class ConvertLegacyPaymentTypes extends Command
{
    protected $signature = 'vehicles:convert-legacy-payment-types {--apply : 실제 전환 (미지정=dry-run)}';

    protected $description = '계약금/중도금/선수금1 입금을 잔금(balance)으로 일괄 전환 (판매탭 단순화 후속)';

    /** @var array<string,string> 레거시 type => 한글 라벨 */
    private const LEGACY = ['deposit_down' => '계약금', 'interim' => '중도금', 'advance_1' => '선수금1'];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $rows = FinalPayment::with('vehicle')
            ->whereIn('type', array_keys(self::LEGACY))
            ->get();

        $convert = [];
        $skip = [];
        foreach ($rows as $p) {
            if ($p->transfer_id !== null) {
                $skip[] = $p;   // 이체 링크 — 직접 수정 불가

                continue;
            }
            $convert[] = $p;
        }

        $rateFixCnt = collect($convert)
            ->filter(fn ($p) => ($p->exchange_rate === null || (float) $p->exchange_rate <= 0) && $p->vehicle)
            ->count();

        $this->info(sprintf(
            '레거시 입금행 %d건 · 전환대상 %d건 · 스킵(이체링크) %d건 · 환율보정 %d건',
            $rows->count(), count($convert), count($skip), $rateFixCnt
        ));
        foreach (self::LEGACY as $type => $ko) {
            $c = collect($convert)->where('type', $type)->count();
            if ($c > 0) {
                $this->line(sprintf('  %s(%s): %d건', $ko, $type, $c));
            }
        }
        foreach ($skip as $p) {
            $this->warn(sprintf('  스킵[이체링크] FP#%d (차량 %s)', $p->id, $p->vehicle?->vehicle_number));
        }

        if (empty($convert)) {
            $this->info('전환할 행 없음.');

            return self::SUCCESS;
        }

        if (! $apply) {
            $this->warn('[dry-run] 실제 전환하려면 --apply 를 붙이세요.');

            return self::SUCCESS;
        }

        $n = 0;
        FinalPayment::$allowConfirmedMutation = true;
        try {
            foreach ($convert as $p) {
                $origType = $p->type;
                $tag = '구 '.self::LEGACY[$origType];
                $oldNote = trim((string) ($p->note ?? ''));
                if ($oldNote === '') {
                    $newNote = $tag;
                } elseif (str_contains($oldNote, $tag)) {
                    $newNote = $oldNote;
                } else {
                    $newNote = $oldNote.' ('.$tag.')';
                }

                $p->type = 'balance';
                $p->note = $newNote;
                if (($p->exchange_rate === null || (float) $p->exchange_rate <= 0) && $p->vehicle) {
                    $p->exchange_rate = $p->vehicle->exchange_rate;   // saving 훅이 amount_krw 재계산
                }
                $p->save();

                AuditLog::create([
                    'user_id' => null,
                    'approval_request_id' => null,
                    'auditable_type' => FinalPayment::class,
                    'auditable_id' => $p->id,
                    'action' => 'payment_type_converted',
                    'column_name' => 'type',
                    'old_value' => $origType,
                    'new_value' => 'balance',
                    'ip_address' => null,
                ]);
                $n++;
            }
        } finally {
            FinalPayment::$allowConfirmedMutation = false;
        }

        $this->info("✅ {$n}건 잔금(balance)으로 전환 완료. (미수·총입금 불변, 감사로그 기록)");

        return self::SUCCESS;
    }
}
