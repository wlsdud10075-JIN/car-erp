<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CashAccount;
use App\Models\CashAccountBalance;
use App\Models\CashSnapshot;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * 계좌별 잔액 → 날짜 스냅샷 (jin 2026-10-14).
 *
 * 저장 = 그 계좌·그 날짜의 통화별 잔액 upsert → 그 날짜 합계(통화별) → 기존 `CapitalStatusService::capture()` 그대로.
 * 스냅샷 3컬럼·감사로그·청산가치 공식은 손대지 않는다(아래 층이 그대로 돈다).
 *
 * 이어쓰기(jin «추천대로») — 그 날 입력 안 한 계좌는 **그 날짜 이하 최신값**을 쓴다. 0 취급하면 청산가치가 그날 뚝 떨어진다.
 * 🚫 소급 입력해도 **그 날짜 한 건만** 캡처한다 — 뒤 날짜마다 capture() 를 돌리면 과거 스냅샷의 재고·미수가 오늘 값으로 덮인다.
 */
class CashAccountBalanceService
{
    public function __construct(private CapitalStatusService $capital) {}

    /**
     * @param  array<string, float>  $amounts  통화 → 잔액 (그 계좌가 가진 통화만)
     * @param  bool  $audit  수정(✏️)으로 고친 값이면 감사로그를 남긴다 — 이미 있던 값을 바꾼 것만(jin 2026-10-22)
     */
    public function save(CashAccount $account, string $date, array $amounts, ?User $user = null, bool $audit = false): CashSnapshot
    {
        // 어느 계좌든 원화·달러·유로 세 칸 전부 — 계좌별 통화 목록(`currencies`)은 쓰지 않는다(jin 2026-10-14 «신한이라고 한화만?»).
        foreach (CashAccount::CURRENCIES as $cur) {
            if (! array_key_exists($cur, $amounts)) {
                continue;
            }
            $new = round((float) $amounts[$cur], 2);
            $row = CashAccountBalance::where('cash_account_id', $account->id)->whereDate('snapshot_date', $date)->where('currency', $cur)->first();
            if ($audit && $row && (float) $row->balance !== $new) {
                AuditLog::recordChange($account, self::auditColumn($cur), $date.' '.$row->balance, $date.' '.number_format($new, 2, '.', ''));
            }
            // 위에서 whereDate 로 찾은 행을 그대로 고친다 — updateOrCreate 의 문자열 날짜 비교는 드라이버마다 달라(SQLite 는 시각까지 저장) 중복 행을 만들 수 있다.
            if ($row) {
                $row->update(['balance' => $new, 'entered_by' => $user?->id]);
            } else {
                CashAccountBalance::create(['cash_account_id' => $account->id, 'snapshot_date' => $date, 'currency' => $cur, 'balance' => $new, 'entered_by' => $user?->id]);
            }
        }

        return $this->captureTotals($date, $user);
    }

    /**
     * 그 계좌·그 날짜의 통화 값을 지운다(수정에서 비운 칸 · [이 날짜 기록 삭제]). 지운 뒤엔 앞 날짜 값을 이어 쓴다.
     * 합계 재캡처는 부르는 쪽이 한 번 한다. 지운 값은 감사로그에 남긴다.
     *
     * @param  list<string>  $currencies
     */
    public function clearCurrencies(CashAccount $account, string $date, array $currencies, ?User $user = null): int
    {
        $rows = CashAccountBalance::where('cash_account_id', $account->id)->whereDate('snapshot_date', $date)
            ->whereIn('currency', $currencies)->get();
        foreach ($rows as $row) {
            AuditLog::recordChange($account, self::auditColumn($row->currency), $date.' '.$row->balance, null, 'deleted');
            $row->delete();
        }

        return $rows->count();
    }

    private static function auditColumn(string $currency): string
    {
        return 'cash_balance_'.strtolower($currency);
    }

    /** 그 날짜 합계(이어쓰기 포함)를 스냅샷으로. */
    public function captureTotals(string $date, ?User $user = null): CashSnapshot
    {
        $t = $this->totals($date);

        return $this->capital->capture(['krw' => $t['KRW'], 'usd' => $t['USD'], 'eur' => $t['EUR']], $user, $date);
    }

    /**
     * 날짜 합계 — 활성 계좌 × 통화마다 「날짜 ≤ D 최신값」을 더한다.
     *
     * @return array{KRW: float, USD: float, EUR: float}
     */
    public function totals(string $date): array
    {
        $sum = ['KRW' => 0.0, 'USD' => 0.0, 'EUR' => 0.0];
        foreach ($this->rowsFor($date) as $row) {
            if ($row['balance'] !== null) {
                $sum[$row['currency']] += (float) $row['balance'];
            }
        }

        return $sum;
    }

    /**
     * 화면용 — 활성 계좌 × 통화별 「이 날짜에 적용되는 값」과 그 값의 입력 날짜(이어쓴 값은 날짜가 다르다).
     *
     * @return Collection<int, array{account: CashAccount, currency: string, balance: ?float, as_of: ?string, carried: bool}>
     */
    public function rowsFor(string $date): Collection
    {
        $accounts = CashAccount::active()->get();
        if ($accounts->isEmpty()) {
            return collect();
        }
        // 계좌·통화마다 날짜 ≤ D 최신 1건 — 한 쿼리로 가져와 PHP 에서 고른다(계좌 수가 작다).
        $latest = CashAccountBalance::query()
            ->whereIn('cash_account_id', $accounts->pluck('id'))
            ->whereDate('snapshot_date', '<=', $date)
            ->orderByDesc('snapshot_date')->orderByDesc('id')
            ->get()
            ->unique(fn ($b) => $b->cash_account_id.'|'.$b->currency)
            ->keyBy(fn ($b) => $b->cash_account_id.'|'.$b->currency);

        $rows = collect();
        foreach ($accounts as $acc) {
            foreach (CashAccount::CURRENCIES as $cur) {
                $b = $latest->get($acc->id.'|'.$cur);
                $asOf = $b?->snapshot_date->toDateString();
                $rows->push([
                    'account' => $acc,
                    'currency' => $cur,
                    'balance' => $b ? (float) $b->balance : null,
                    'as_of' => $asOf,
                    'carried' => $b !== null && $asOf !== $date,
                ]);
            }
        }

        return $rows;
    }
}
