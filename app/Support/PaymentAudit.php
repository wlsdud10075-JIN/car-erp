<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\FinalPayment;
use App\Models\PurchaseBalancePayment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 💳 판매 입금(FinalPayment)·매입 지급(PurchaseBalancePayment) 줄의 감사 기록 (jin 2026-10-23).
 *
 * jin: «로그 기록에 계약금·매입잔금·판매잔금 기록한 게 없다 — N행 생성·기입·수정도 남아야 하지 않나».
 * 실측(ssancarerp 30일) = 판매 입금 733줄·매입 지급 890줄이 생겼는데 지급 관련 감사로그 4건이었다.
 * 종전엔 「확정 뒤 잠금 해제 정정」만 남겼다.
 *
 * 남기는 것 = 줄 추가 · 금액/날짜/환율/구분/비고 수정 · 줄 삭제 · 재무 확정/확정 취소.
 * - 모델 훅(created·updated·deleted)이 기본 경로다.
 * - ⚠️ 차량 패널 저장은 **일부러 bulk 쿼리**(whereIn()->delete()·where()->update())로 지운다·고친다 — 잠금 가드를
 *   안 태우려는 것이라 바꾸지 않는다. 그 대신 그 자리에서 이 클래스의 `deletingMany()`·`updatingRow()` 를 부른다.
 * - 값 비교는 `AuditLog::valuesDiffer`(§8 #108 — 「1000.00 → 1000」 소음 제외).
 */
class PaymentAudit
{
    /** 수정 시 비교하는 칸. 증빙 파일(proof_path)·확정 메타는 따로 다룬다. */
    public const COLUMNS = [
        FinalPayment::class => ['amount', 'exchange_rate', 'payment_date', 'type', 'note'],
        PurchaseBalancePayment::class => ['amount', 'payment_date', 'type', 'note'],
    ];

    /** 한 줄 요약 — 「계약금 1,000,000원 · 2026-10-22」. */
    public static function summary(Model $p): string
    {
        $type = (string) ($p->type ?? '');
        $typeLabel = $type !== '' ? (ColumnLabel::value($p::class, 'type', $type) ?? $type) : '';
        $isSale = $p instanceof FinalPayment;
        $cur = $isSale ? ((string) ($p->vehicle?->currency ?? '')) : 'KRW';
        $amt = (float) ($p->amount ?? 0);
        $amount = $cur === 'KRW' || $cur === ''
            ? number_format($amt).'원'
            : rtrim(rtrim(number_format($amt, 2), '0'), '.').' '.$cur;
        $date = $p->payment_date ? Carbon::parse($p->payment_date)->format('Y-m-d') : '';

        return trim(implode(' · ', array_filter([trim($typeLabel.' '.$amount), $date])));
    }

    public static function created(Model $p): void
    {
        AuditLog::recordChange($p, 'payment_row', null, self::summary($p), 'created');
        if ($p->confirmed_at !== null) {
            self::confirmed($p);
        }
    }

    public static function deleted(Model $p): void
    {
        AuditLog::recordChange($p, 'payment_row', self::summary($p), null, 'deleted');
    }

    /** 모델 updated 훅 — 바뀐 칸마다 한 줄, 확정 전환은 별도 액션. */
    public static function updated(Model $p): void
    {
        foreach (self::COLUMNS[$p::class] ?? [] as $col) {
            if (AuditLog::isRealChange($p, $col)) {
                AuditLog::recordChange($p, $col, $p->getOriginal($col), $p->getAttribute($col));
            }
        }
        $was = $p->getOriginal('confirmed_at');
        $now = $p->confirmed_at;
        if ($was === null && $now !== null) {
            self::confirmed($p);
        } elseif ($was !== null && $now === null) {
            AuditLog::recordChange($p, 'payment_row', self::summary($p), null, 'payment_unconfirmed');
        }
    }

    /** bulk delete 직전 — 지워질 줄을 하나씩 남긴다. */
    public static function deletingMany(Collection $rows): void
    {
        foreach ($rows as $p) {
            self::deleted($p);
        }
    }

    /** bulk update 직전 — 지금 값과 새 값을 비교해 바뀐 칸만 남긴다. */
    public static function updatingRow(?Model $p, array $new): void
    {
        if ($p === null) {
            return;
        }
        foreach ($new as $col => $value) {
            if (! in_array($col, self::COLUMNS[$p::class] ?? [], true)) {
                continue;
            }
            $old = $p->getAttribute($col);
            if ($col === 'payment_date') {
                $old = $old ? Carbon::parse($old)->format('Y-m-d') : null;
                $value = $value ? Carbon::parse($value)->format('Y-m-d') : null;
            }
            if (AuditLog::valuesDiffer($old, $value)) {
                AuditLog::recordChange($p, $col, $old, $value);
            }
        }
    }

    private static function confirmed(Model $p): void
    {
        $by = $p->confirmed_by_user_id ? User::find($p->confirmed_by_user_id)?->name : null;
        AuditLog::recordChange($p, 'payment_row', null, self::summary($p).($by ? ' · 확정 '.$by : ''), 'payment_confirmed');
    }
}
