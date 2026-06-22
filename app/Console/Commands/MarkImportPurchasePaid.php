<?php

namespace App\Console\Commands;

use App\Models\PurchaseBalancePayment;
use App\Models\Vehicle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * import 차량 매입대금 일괄 "기지급" 처리 (2026-06-22).
 *
 * 배경: `vehicles:import` 는 매입 입금이력(PBP)을 적재하지 않아 import 차량 전부가
 *       매입 미지급으로 떠 재무처리 대기를 채운다(예: 43머3974·21버4376). 헤이맨 과거
 *       데이터는 매입이 이미 완납된 차량이라, 지급 완료분만 골라 confirmed PBP 로 0 만든다.
 *
 * jin 결정(2026-06-22, S열 반영 정밀화):
 *   - 판별 기준 = "1. 헤이맨 수출차량현황표.xlsx" 의 S열(재고/판매 단계) + O열(송금내역) + BX열.
 *     핵심 통찰: S열이 매입 다운스트림 단계(입고/선적대기/선적완료/입금대기/완료)면 차를
 *     선적·판매하려고 매입+말소가 끝난 것 → 매입대금 지급됨(O 메모 누락·한글숫자여도 PAID).
 *   - 제외(EXCLUDE) = ① O="취소" 또는 BX=0  ② S∈{매입대기, 빈} AND (O 계약금만 OR O 빈칸).
 *     → 진짜 미지급/취소만 빠지고 재무처리에 유지. 나머지 전부 PAID.
 *   - 실측: PAID 160 / 제외 8(취소·BX0 3 + 매입대기·계약금만 5).
 *   - 금액은 "파일"이 아니라 "서버 현재 매입합계(purchase_price+selling_fee)" 기준으로 0 만듦.
 *     파일은 어느 차량을 처리할지 명단 판별용으로만 사용(차량번호 매칭).
 *
 * 동작(차량당):
 *   - 미지급 ≤ 0 → 이미 처리됨 skip (멱등).
 *   - 기존 draft(미확정) PBP 있으면 confirmed 로 전환(행 재사용 — 자동 Draft 중복 방지).
 *   - 확정 후에도 미지급 > 0 이면 잔액만큼 confirmed PBP 신규 생성.
 *   - payment_date 는 반드시 채움(purchase_date ?? today) — 미지급 accessor 가 non-null 요구.
 *
 * 마커: note='import 매입 기지급' / finance_note 에 일자 — 감사·멱등 식별용.
 *
 * 가드: artisan 컨텍스트(auth 없음)라 PBP creating 가드 자동 우회. draft 의 confirmed_at 최초
 *       설정은 updating 가드 통과(원래 confirmed_at=null). $allowConfirmedMutation 불필요.
 *
 *   php artisan vehicles:mark-import-purchase-paid "경로/1. 헤이맨 수출차량현황표.xlsx"           # dry-run
 *   php artisan vehicles:mark-import-purchase-paid "..." --apply                                  # 실제 처리
 */
class MarkImportPurchasePaid extends Command
{
    protected $signature = 'vehicles:mark-import-purchase-paid
        {path : 판별 기준 xlsx 경로 (1. 헤이맨 수출차량현황표.xlsx)}
        {--sheet=수출차량매입-2026 : 시트명}
        {--apply : 실제 처리 (미지정 시 dry-run — 명단/계획만, 쓰기 없음)}';

    protected $description = 'import 차량 매입대금 일괄 기지급 처리 (1.파일 O/BX 판별, 서버 매입합계 기준). 기본 dry-run.';

    private const NOTE_MARKER = 'import 매입 기지급';

    /** S열 매입 다운스트림(매입 완료 이후) 단계 — 도달했으면 매입대금 지급된 것으로 본다. */
    private const S_DONE = ['입고', '선적대기', '선적완료', '입금대기', '완료'];

    /** O열 잔금 지급 증거 키워드 (계약금만 vs 완납 구분용) */
    private const BALANCE_KEYWORDS = ['잔금', '완료', '차량대금', '차량 대금', '중도금', '대금', '입금', '매도비'];

    public function handle(): int
    {
        $path = $this->argument('path');
        if (! is_file($path)) {
            $this->error("파일 없음: {$path}");

            return self::FAILURE;
        }

        // ── 1. 파일에서 PAID 차량번호 판별 ──
        [$paidNumbers, $leaveCount, $cancelCount] = $this->classifyFromFile($path);
        $this->info('파일 판별: PAID '.count($paidNumbers)."대 / 제외(취소 {$cancelCount} + 빈칸·계약금만 ".($leaveCount - $cancelCount).'대)');
        $this->newLine();

        // ── 2. 서버 차량 매칭 + 처리 계획 ──
        $plans = [];      // [vno => ['vehicle'=>, 'unpaid'=>, 'drafts'=>count, 'action'=>]]
        $unmatched = [];
        $already = 0;

        foreach ($paidNumbers as $vno) {
            $v = Vehicle::where('vehicle_number', $vno)->first();
            if (! $v) {
                $unmatched[] = $vno;

                continue;
            }
            $unpaid = $v->purchase_unpaid_amount;
            if ($unpaid <= 0) {
                $already++;

                continue;
            }
            $drafts = $v->purchaseBalancePayments()->whereNull('confirmed_at')->count();
            $plans[$vno] = ['vehicle' => $v, 'unpaid' => $unpaid, 'drafts' => $drafts];
        }

        $sumUnpaid = array_sum(array_map(fn ($p) => $p['unpaid'], $plans));
        $this->info('처리 대상: '.count($plans).'대 (미지급 합계 '.number_format($sumUnpaid).'원)');
        $this->line("  이미 지급처리됨(미지급≤0) skip: {$already}대");
        $this->line('  서버에 없는 차량번호: '.count($unmatched).'대'.(count($unmatched) ? ' — '.implode(', ', array_slice($unmatched, 0, 20)).(count($unmatched) > 20 ? ' …' : '') : ''));
        $this->newLine();

        // 계획 샘플
        $this->line('처리 계획 (샘플 15):');
        $i = 0;
        foreach ($plans as $vno => $p) {
            $how = $p['drafts'] > 0 ? "draft {$p['drafts']}건 확정".($p['unpaid'] - $this->draftSum($p['vehicle']) > 0 ? ' + 잔액 생성' : '') : 'confirmed PBP 신규생성';
            $this->line(sprintf('  %-11s %-8s 미지급=%-11s → %s', $vno, $p['vehicle']->progress_status_cache, number_format($p['unpaid']), $how));
            if (++$i >= 15) {
                $this->line('  …');
                break;
            }
        }
        $this->newLine();

        if (! $this->option('apply')) {
            $this->warn('[DRY-RUN] 쓰기 없음. 실제 처리하려면 --apply 추가.');

            return self::SUCCESS;
        }

        // ── 3. 실제 처리 ──
        $today = now()->toDateString();
        $created = 0;
        $confirmed = 0;
        DB::transaction(function () use ($plans, $today, &$created, &$confirmed) {
            foreach ($plans as $p) {
                $v = $p['vehicle'];
                $payDate = $v->purchase_date?->toDateString() ?? $today;

                // 기존 draft 확정
                foreach ($v->purchaseBalancePayments()->whereNull('confirmed_at')->get() as $draft) {
                    $draft->confirmed_at = now();
                    $draft->payment_date = $draft->payment_date ?? $payDate;
                    $draft->finance_note = trim(($draft->finance_note ? $draft->finance_note.' / ' : '').self::NOTE_MARKER.' '.$today);
                    $draft->save();
                    $confirmed++;
                }

                // 확정 후 남은 미지급 → 신규 confirmed PBP
                $v->refresh();
                $remain = $v->purchase_unpaid_amount;
                if ($remain > 0) {
                    PurchaseBalancePayment::create([
                        'vehicle_id' => $v->id,
                        'amount' => $remain,
                        'type' => 'balance',
                        'payment_date' => $payDate,
                        'confirmed_at' => now(),
                        'confirmed_by_user_id' => null,
                        'created_by_user_id' => null,
                        'note' => self::NOTE_MARKER,
                        'finance_note' => self::NOTE_MARKER.' '.$today,
                    ]);
                    $created++;
                }

                $v->refreshCaches();
            }
        });

        $this->info("✅ 완료: draft 확정 {$confirmed}건 + 신규 confirmed PBP {$created}건. 처리 차량 ".count($plans).'대.');

        return self::SUCCESS;
    }

    private function draftSum(Vehicle $v): int
    {
        return (int) $v->purchaseBalancePayments()->whereNull('confirmed_at')->sum('amount');
    }

    /**
     * @return array{0: list<string>, 1: int, 2: int} [paidNumbers, leaveTotal, cancelCount]
     */
    private function classifyFromFile(string $path): array
    {
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $ss = $reader->load($path);
        $ws = $ss->getSheetByName($this->option('sheet')) ?? $ss->getSheet(0);
        $high = $ws->getHighestRow();

        $num = fn ($v) => (int) round((float) str_replace([',', ' ', '원'], '', (string) $v));

        $paid = [];
        $leave = 0;
        $cancel = 0;

        for ($r = 3; $r <= $high; $r++) {
            $vno = trim((string) $ws->getCell('D'.$r)->getCalculatedValue());
            if ($vno === '') {
                continue;
            }
            $s = trim((string) $ws->getCell('S'.$r)->getCalculatedValue());
            $o = str_replace(["\n", "\r"], ' ', trim((string) $ws->getCell('O'.$r)->getCalculatedValue()));
            $bx = $num($ws->getCell('BX'.$r)->getCalculatedValue());

            // ① 취소 / BX=0 → 제외
            if (mb_strpos($o, '취소') !== false || $bx === 0) {
                $cancel++;
                $leave++;

                continue;
            }

            // ② 매입 전(매입대기·빈 단계) + (계약금만 OR 빈칸) → 진짜 미지급, 제외
            $sPre = ($s === '' || $s === '매입대기');
            $hasBalanceEvidence = false;
            foreach (self::BALANCE_KEYWORDS as $kw) {
                if (mb_strpos($o, $kw) !== false) {
                    $hasBalanceEvidence = true;
                    break;
                }
            }
            $onlyDepositOrBlank = ! $hasBalanceEvidence; // 잔금 증거 없음 = 계약금만 또는 빈칸
            if ($sPre && $onlyDepositOrBlank) {
                $leave++;

                continue;
            }

            // ③ 그 외 전부 PAID — S 다운스트림(선적/입금) = 매입 완료 단계, 또는 O 잔금 완납.
            $paid[] = $vno;
        }

        return [$paid, $leave, $cancel];
    }
}
