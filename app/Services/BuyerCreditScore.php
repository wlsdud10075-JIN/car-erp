<?php

namespace App\Services;

use App\Models\Buyer;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 바이어 신용도 — **제안만 한다. 아무것도 자동으로 바꾸지 않는다.** (jin 2026-08-21)
 *
 * 🚨 자동 연동하지 않는 이유:
 *    락은 차가 나가느냐 마느냐를 결정한다. 점수가 조용히 내려가 선적이 막히면 영업은 이유를 모른다
 *    (board 에 「왜 막혔는지」를 아직 안 보낸다). 게다가 이 프로젝트는 자동 판정으로 두 번 데었다 —
 *    계약금 자동 차감(SKILLS §49)과 karaba 비율칸 동결(자동값을 저장해 얼어붙음).
 *    → 점수와 권장값을 **보여주기만** 하고, 사람이 [적용]을 눌러야 바이어 컬럼에 들어간다.
 *
 * 배점은 super 가 기능설정에서 조정한다(회사별). 등급 커트라인과 축 내부 구간은 코드에 둔다 —
 * 전부 열면 화면이 복잡해지고 잘못 만지면 등급이 통째로 뒤집힌다.
 *
 * ⚠️ **바이어 목록에서 부르지 말 것.** 지급 행태가 차량별 MAX(payment_date) 집계라 목록에서
 *    돌리면 N+1 이다(ssancarerp 바이어 368명). 편집 패널·드로어처럼 한 명을 볼 때만 쓴다.
 */
class BuyerCreditScore
{
    /** 배점 기본값 (jin 2026-08-21). 합 100. */
    public const DEFAULT_WEIGHTS = [
        'trade' => 30,       // 거래 이력 — 얼마나 오래·많이
        'loss' => 30,        // 손실 이력 — 떼먹은 적 있나
        'exposure' => 20,    // 익스포저 — 지금 물려 있는 돈
        'payment' => 20,     // 지급 행태 — 얼마나 늦게 주나
    ];

    /** 등급 커트라인 (총점 기준, 내림차순). */
    public const GRADES = ['A' => 85, 'B' => 70, 'C' => 55, 'D' => 40, 'E' => 0];

    /**
     * 등급별 권장 필요입금률(%) — 신용이 좋을수록 낮다(= 느슨하다).
     * B 가 현행 전역값(선적 60 / 매입 50)과 같도록 맞춰 두었다.
     */
    public const RECOMMENDED = [
        'A' => ['shipping_entry' => 45, 'purchase_registration' => 40],
        'B' => ['shipping_entry' => 60, 'purchase_registration' => 50],
        'C' => ['shipping_entry' => 75, 'purchase_registration' => 65],
        'D' => ['shipping_entry' => 90, 'purchase_registration' => 80],
        'E' => ['shipping_entry' => 100, 'purchase_registration' => 95],
    ];

    /**
     * 손실 이력이 있으면 등급 상한을 씌운다 — 거래량으로 손실을 희석하지 못하게.
     * 실측(heymanerp 2026-08-21): write_off 1건 / 1,166원 = 실질 0건.
     * ⇒ 지금은 아무도 안 걸리고 전원이 손실 만점이다. 그래서 **화면에 만점 이유를 함께 찍는다** —
     *   안 그러면 "72점 B등급"이 실제보다 근거 있어 보인다.
     */
    public const LOSS_GRADE_CAP = 'C';

    public const LOSS_GRADE_CAP_SEVERE = 'D';

    /** 배점 (회사별, super 조정). 합이 100 이 아니면 그 합으로 정규화한다. */
    public static function weights(): array
    {
        $raw = Setting::get('credit_score_weights_'.Setting::companyTemplateSet());
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        $out = [];
        foreach (self::DEFAULT_WEIGHTS as $key => $default) {
            $v = is_array($decoded) ? ($decoded[$key] ?? null) : null;
            $out[$key] = is_numeric($v) ? max(0, min(100, (int) $v)) : $default;
        }

        return $out;
    }

    /**
     * 한 바이어의 신용도.
     *
     * @return array{
     *   available: bool, score: int, grade: string, axes: array, recommended: array,
     *   weights: array, capped_by_loss: bool
     * }
     *   available=false → 거래 이력이 없어 평가 불가. **전역값을 쓰라는 뜻이지 0점이 아니다** —
     *   신규 바이어를 E 로 떨어뜨리면 첫 거래가 통째로 막힌다.
     */
    public static function for(Buyer $buyer): array
    {
        $weights = self::weights();
        $facts = self::facts($buyer);

        if (! $facts['has_history']) {
            return [
                'available' => false, 'score' => 0, 'grade' => '-',
                'axes' => [], 'recommended' => [], 'weights' => $weights,
                'capped_by_loss' => false, 'facts' => $facts,
            ];
        }

        $axes = [
            'trade' => self::tradeAxis($facts, $weights['trade']),
            'loss' => self::lossAxis($facts, $weights['loss']),
            'exposure' => self::exposureAxis($facts, $weights['exposure']),
            'payment' => self::paymentAxis($facts, $weights['payment']),
        ];

        $earned = array_sum(array_column($axes, 'score'));
        $total = array_sum($weights);
        // 배점 합이 100 이 아니어도 100점 만점으로 환산 — super 가 한 축만 올리고 싶을 수 있다.
        $score = $total > 0 ? (int) round($earned / $total * 100) : 0;

        $grade = self::gradeOf($score);
        $capped = false;
        if ($facts['loss_ratio'] > 0.02) {
            [$grade, $capped] = [self::worseGrade($grade, self::LOSS_GRADE_CAP_SEVERE), true];
        } elseif ($facts['loss_krw'] > 0) {
            [$grade, $capped] = [self::worseGrade($grade, self::LOSS_GRADE_CAP), true];
        }

        return [
            'available' => true,
            'score' => $score,
            'grade' => $grade,
            'axes' => $axes,
            'recommended' => self::RECOMMENDED[$grade] ?? self::RECOMMENDED['B'],
            'weights' => $weights,
            'capped_by_loss' => $capped,
            'facts' => $facts,
        ];
    }

    /**
     * 원자료 — 쿼리는 여기 한 곳에 모은다.
     *
     * ⚠️ 지급 행태는 **완납 차량**만 본다. 아직 안 낸 차를 섞으면 "늦다"가 아니라 "미수"인데
     *    그건 익스포저 축이 이미 본다. 두 축이 같은 사실을 두 번 세면 점수가 왜곡된다.
     */
    public static function facts(Buyer $buyer): array
    {
        $buyerId = $buyer->getKey();

        // ① 거래완료 건수
        $completed = (int) DB::table('vehicles')->whereNull('deleted_at')
            ->where('buyer_id', $buyerId)->where('progress_status_cache', '거래완료')->count();

        // ② 손실 이력 — 누적 판매액 대비
        $lossKrw = (float) DB::table('receivable_histories as rh')
            ->join('vehicles as v', 'v.id', '=', 'rh.vehicle_id')
            ->whereNull('v.deleted_at')->where('v.buyer_id', $buyerId)
            ->where('rh.method', 'write_off')->sum('rh.amount');

        $soldKrw = (float) DB::table('vehicles')->whereNull('deleted_at')
            ->where('buyer_id', $buyerId)->where('sale_price', '>', 0)
            ->where('exchange_rate', '>', 0)
            ->selectRaw('COALESCE(SUM(sale_price * exchange_rate), 0) as s')->value('s');

        // ③ 익스포저 — 진행중·선적후 미수 합 / 그 총액
        $gauge = $buyer->receivableGauge();
        $unpaid = (int) (($gauge['unpaid_krw'] ?? 0) + ($gauge['shipped_unpaid_krw'] ?? 0));
        $base = (int) (($gauge['total_krw'] ?? 0) + ($gauge['shipped_krw'] ?? 0));

        // ④ 지급 행태 — 완납 차량의 판매일 → 최종 확정입금일 (중앙값)
        $days = DB::table('vehicles as v')
            ->join('final_payments as fp', function ($j) {
                $j->on('fp.vehicle_id', '=', 'v.id')->whereNotNull('fp.confirmed_at');
            })
            ->whereNull('v.deleted_at')->where('v.buyer_id', $buyerId)
            ->where('v.sale_price', '>', 0)->whereNotNull('v.sale_date')
            ->where(fn ($q) => $q->where('v.sale_unpaid_amount_krw_cache', '<=', 0)
                ->orWhereNull('v.sale_unpaid_amount_krw_cache'))
            ->groupBy('v.id', 'v.sale_date')
            ->selectRaw('v.sale_date, MAX(fp.payment_date) as paid_at')
            ->get()
            ->map(fn ($r) => $r->paid_at ? (int) Carbon::parse($r->sale_date)
                ->startOfDay()->diffInDays(Carbon::parse($r->paid_at)->startOfDay()) : null)
            ->filter(fn ($d) => $d !== null)->sort()->values();

        return [
            'has_history' => $completed > 0 || $days->isNotEmpty(),
            'completed_count' => $completed,
            'loss_krw' => (int) round($lossKrw),
            'loss_ratio' => $soldKrw > 0 ? $lossKrw / $soldKrw : 0.0,
            'unpaid_krw' => $unpaid,
            'exposure_ratio' => $base > 0 ? min(1.0, $unpaid / $base) : 0.0,
            'paid_sample' => $days->count(),
            'paid_median_days' => $days->isEmpty() ? null : (int) $days[(int) floor($days->count() / 2)],
        ];
    }

    // ── 축별 계산 ────────────────────────────────────────────────
    //   구간은 운영 실측(heymanerp 2026-08-21)에 맞췄다.

    /**
     * 거래 이력 — **구간**이지 건수 비례가 아니다.
     * 비례로 주면 최대 거래처만 만점이고 나머지는 바닥이 된다
     * (실측: R.S.H 60건 · 2위 15건 · 중앙 2건 · 13명 중 6명이 1건).
     */
    private static function tradeAxis(array $f, int $max): array
    {
        $n = $f['completed_count'];
        [$ratio, $why] = match (true) {
            $n >= 21 => [1.0, "거래완료 {$n}건"],
            $n >= 6 => [0.8, "거래완료 {$n}건"],
            $n >= 2 => [0.5, "거래완료 {$n}건"],
            $n >= 1 => [0.17, "거래완료 {$n}건 — 이력이 얕다"],
            default => [0.0, '거래완료 없음'],
        };

        return ['label' => '거래 이력', 'score' => (int) round($max * $ratio), 'max' => $max, 'why' => $why];
    }

    private static function lossAxis(array $f, int $max): array
    {
        $r = $f['loss_ratio'];
        [$ratio, $why] = match (true) {
            $f['loss_krw'] <= 0 => [1.0, '손실 처리 이력 없음'],
            $r <= 0.005 => [0.6, '손실 '.number_format($f['loss_krw']).'원 ('.round($r * 100, 2).'%)'],
            $r <= 0.02 => [0.2, '손실 '.number_format($f['loss_krw']).'원 ('.round($r * 100, 2).'%)'],
            default => [0.0, '손실 '.number_format($f['loss_krw']).'원 ('.round($r * 100, 1).'%) — 등급 상한 적용'],
        };

        return ['label' => '손실 이력', 'score' => (int) round($max * $ratio), 'max' => $max, 'why' => $why];
    }

    private static function exposureAxis(array $f, int $max): array
    {
        $r = $f['exposure_ratio'];
        $pct = round($r * 100, 1);
        [$ratio, $why] = match (true) {
            $f['unpaid_krw'] <= 0 => [1.0, '미수 없음'],
            $r <= 0.2 => [1.0, "미수 {$pct}% (".number_format($f['unpaid_krw']).'원)'],
            $r <= 0.4 => [0.7, "미수 {$pct}% (".number_format($f['unpaid_krw']).'원)'],
            $r <= 0.6 => [0.4, "미수 {$pct}% (".number_format($f['unpaid_krw']).'원)'],
            $r <= 0.8 => [0.2, "미수 {$pct}% (".number_format($f['unpaid_krw']).'원)'],
            default => [0.0, "미수 {$pct}% (".number_format($f['unpaid_krw']).'원)'],
        };

        return ['label' => '익스포저', 'score' => (int) round($max * $ratio), 'max' => $max, 'why' => $why];
    }

    /**
     * 지급 행태 — 판매일 → 최종 입금일 중앙값.
     * 실측(heymanerp 완납 177대): 중앙 8일 · p25 −2일(선입금 33%) · p75 25일 · 91일 초과 4대.
     * ⚠️ 선적 후에 지급하는 계약 조건인 바이어는 구조적으로 길게 나온다. 그래서 배점 자체를
     *    20 으로 낮춰 잡았다(jin) — 구간을 더 관대하게 만들어 변별력을 죽이지는 않는다.
     */
    private static function paymentAxis(array $f, int $max): array
    {
        $d = $f['paid_median_days'];
        if ($d === null) {
            return ['label' => '지급 행태', 'score' => 0, 'max' => $max, 'why' => '완납 이력 없음 — 평가 자료 없음'];
        }
        $n = $f['paid_sample'];
        [$ratio, $why] = match (true) {
            $d <= 0 => [1.0, "중앙 {$d}일 (선입금, {$n}대)"],
            $d <= 10 => [0.85, "중앙 {$d}일 ({$n}대)"],
            $d <= 30 => [0.6, "중앙 {$d}일 ({$n}대)"],
            $d <= 60 => [0.3, "중앙 {$d}일 ({$n}대)"],
            default => [0.0, "중앙 {$d}일 ({$n}대) — 회수가 느리다"],
        };

        return ['label' => '지급 행태', 'score' => (int) round($max * $ratio), 'max' => $max, 'why' => $why];
    }

    // ── 등급 ────────────────────────────────────────────────────

    public static function gradeOf(int $score): string
    {
        foreach (self::GRADES as $grade => $min) {
            if ($score >= $min) {
                return $grade;
            }
        }

        return 'E';
    }

    /** 두 등급 중 나쁜 쪽 (손실 상한 적용용). */
    private static function worseGrade(string $a, string $b): string
    {
        $order = array_keys(self::GRADES);

        return array_search($a, $order, true) >= array_search($b, $order, true) ? $a : $b;
    }
}
