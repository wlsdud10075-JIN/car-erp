<?php

namespace App\Console\Commands;

use App\Models\PurchaseBalancePayment;
use App\Models\Vehicle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * import 차량 매입대금 — O열 송금내역을 계약금/잔금/매도비 구조대로 PBP 입력 (2026-06-22).
 *
 * 배경: `vehicles:import` 는 매입 입금이력(PBP)을 적재 안 해 import 차량 전부가 매입 미지급으로
 *       떠 재무처리 대기를 채운다(예 43머3974·21버4376 — Vehicle::saved 가 전액 draft 자동생성).
 *
 * jin 결정(2026-06-22): O 메모를 파싱해 ERP 구조대로 입력(전액 lump 아님).
 *   - O "계약금/계약 N"  → type=down(계약금)
 *   - O "잔금/대금/입금/중도금 N" → type=balance(잔금)
 *   - O "매도비 N"        → type=selling_fee
 *   판별 기준 = "1. 헤이맨 수출차량현황표.xlsx" 의 S열(재고/판매단계)+O열+BX열.
 *
 * 차량당 처리:
 *   - 취소(O="취소") 또는 BX=0 → skip.
 *   - 파싱합계 ≈ 파일 매입합계(±3%)  OR  S∈{입고/선적대기/선적완료/입금대기/완료} → **완납**:
 *       계약금/매도비는 파싱값, 잔금 = 서버 매입합계 - 계약금 - 매도비 (차액 채워 미지급 0).
 *       (빈칸·한글숫자 "2억5백만원"·오타 "14,3000,000" 등 파싱불완전도 S단계로 보정)
 *   - 그 외(S∈{매입대기,빈} + 계약금만) → **부분**: 계약금(+매도비)만 입력, 잔금은 미지급 유지.
 *
 * 금액 기준 = 서버 현재 매입합계(purchase_price+selling_fee). 파일 = 명단/구조 판별용.
 * 기존 미확정(auto-draft) PBP 는 삭제 후 구조 입력(중복 방지). 멱등: note 마커 있으면 skip.
 *
 * 가드: artisan(auth 없음) → PBP creating 가드 자동 우회. 미확정 draft 삭제는 deleting 가드 통과.
 *
 *   php artisan vehicles:mark-import-purchase-paid "경로/1. 헤이맨 수출차량현황표.xlsx"            # dry-run
 *   php artisan vehicles:mark-import-purchase-paid "..." --apply                                   # 실제 처리
 */
class MarkImportPurchasePaid extends Command
{
    protected $signature = 'vehicles:mark-import-purchase-paid
        {path : 판별 기준 xlsx 경로 (1. 헤이맨 수출차량현황표.xlsx)}
        {--sheet=수출차량매입-2026 : 시트명}
        {--apply : 실제 처리 (미지정 시 dry-run — 명단/계획만, 쓰기 없음)}';

    protected $description = 'import 차량 매입대금을 O열 계약금/잔금 구조대로 PBP 입력 (S열 보정). 기본 dry-run.';

    private const NOTE_MARKER = 'import 매입(구조)';

    /** S열 매입 다운스트림(매입 완료 이후) 단계 — 도달했으면 매입대금 지급된 것으로 본다. */
    private const S_DONE = ['입고', '선적대기', '선적완료', '입금대기', '완료'];

    public function handle(): int
    {
        $path = $this->argument('path');
        if (! is_file($path)) {
            $this->error("파일 없음: {$path}");

            return self::FAILURE;
        }

        $parsed = $this->parseFile($path);   // [vno => row]
        $cancelled = count(array_filter($parsed, fn ($r) => $r['cancelled']));
        $this->info('파일: '.count($parsed).'행 (취소/BX0 '.$cancelled.'대 제외)');
        $this->newLine();

        $plans = [];
        $unmatched = [];
        $already = 0;

        foreach ($parsed as $vno => $row) {
            if ($row['cancelled']) {
                continue;
            }
            $v = Vehicle::where('vehicle_number', $vno)->first();
            if (! $v) {
                $unmatched[] = $vno;

                continue;
            }
            // 멱등: 이미 구조 입력된 차량 skip
            if ($v->purchaseBalancePayments()->where('note', 'like', self::NOTE_MARKER.'%')->exists()) {
                $already++;

                continue;
            }
            $unpaid = $v->purchase_unpaid_amount;
            if ($unpaid <= 0) {
                $already++;

                continue;
            }
            $plans[$vno] = $this->buildPlan($v, $row, $unpaid);
        }

        // ── 요약 ──
        $fullCnt = count(array_filter($plans, fn ($p) => $p['full']));
        $partCnt = count($plans) - $fullCnt;
        $sumDown = array_sum(array_column($plans, 'down'));
        $sumBal = array_sum(array_column($plans, 'balance'));
        $sumSell = array_sum(array_column($plans, 'selling'));
        $this->info('처리 대상: '.count($plans)."대  (완납 {$fullCnt} / 부분(계약금만) {$partCnt})");
        $this->line('  입력 합계 — 계약금 '.number_format($sumDown).' / 잔금 '.number_format($sumBal).' / 매도비 '.number_format($sumSell));
        $this->line("  이미 처리됨 skip: {$already}대 / 서버에 없음: ".count($unmatched).'대'.(count($unmatched) ? ' — '.implode(', ', array_slice($unmatched, 0, 15)).(count($unmatched) > 15 ? ' …' : '') : ''));
        $this->newLine();

        $this->line('처리 계획 (샘플 20):');
        $i = 0;
        foreach ($plans as $vno => $p) {
            $this->line(sprintf('  %-11s %-6s 계약금=%-10s 잔금=%-11s 매도비=%-9s 잔여미지급=%s',
                $vno, $p['full'] ? '완납' : '부분',
                number_format($p['down']), number_format($p['balance']), number_format($p['selling']),
                number_format($p['unpaidAfter'])));
            if (++$i >= 20) {
                $this->line('  …');
                break;
            }
        }
        $this->newLine();

        if (! $this->option('apply')) {
            $this->warn('[DRY-RUN] 쓰기 없음. 실제 처리하려면 --apply 추가.');

            return self::SUCCESS;
        }

        $today = now()->toDateString();
        $rowsCreated = 0;
        $draftsDeleted = 0;
        DB::transaction(function () use ($plans, $today, &$rowsCreated, &$draftsDeleted) {
            foreach ($plans as $p) {
                $v = $p['vehicle'];
                $payDate = $v->purchase_date?->toDateString() ?? $today;

                // 기존 미확정 auto-draft 삭제 (중복 방지)
                foreach ($v->purchaseBalancePayments()->whereNull('confirmed_at')->get() as $draft) {
                    $draft->delete();
                    $draftsDeleted++;
                }

                foreach (['down' => $p['down'], 'balance' => $p['balance'], 'selling_fee' => $p['selling']] as $type => $amt) {
                    if ($amt <= 0) {
                        continue;
                    }
                    PurchaseBalancePayment::create([
                        'vehicle_id' => $v->id,
                        'amount' => $amt,
                        'type' => $type,
                        'payment_date' => $payDate,
                        'confirmed_at' => now(),
                        'confirmed_by_user_id' => null,
                        'created_by_user_id' => null,
                        'note' => self::NOTE_MARKER,
                        'finance_note' => self::NOTE_MARKER.' '.$today,
                    ]);
                    $rowsCreated++;
                }

                $v->refreshCaches();
            }
        });

        $this->info("✅ 완료: PBP {$rowsCreated}건 생성 (계약금/잔금/매도비), draft {$draftsDeleted}건 삭제. 처리 ".count($plans).'대.');

        return self::SUCCESS;
    }

    /**
     * 차량 1대 처리 계획 산정.
     *
     * @return array{vehicle:Vehicle, full:bool, down:int, balance:int, selling:int, unpaidAfter:int}
     */
    private function buildPlan(Vehicle $v, array $row, int $unpaid): array
    {
        $down = min($row['down'], $unpaid);
        $selling = min($row['selling'], max(0, $unpaid - $down));

        $ratioOk = $row['fileTotal'] > 0 && $row['parsedSum'] / $row['fileTotal'] >= 0.97 && $row['parsedSum'] / $row['fileTotal'] <= 1.03;
        $full = $ratioOk || in_array($row['s'], self::S_DONE, true);

        if ($full) {
            $balance = max(0, $unpaid - $down - $selling);  // 차액 채워 완납
        } else {
            $balance = min($row['balance'], max(0, $unpaid - $down - $selling)); // 파싱된 잔금만 (보통 0)
        }

        return [
            'vehicle' => $v,
            'full' => $full,
            'down' => $down,
            'balance' => $balance,
            'selling' => $selling,
            'unpaidAfter' => $unpaid - $down - $balance - $selling,
        ];
    }

    /**
     * xlsx → [vno => ['s','o','fileTotal','bx','down','balance','selling','parsedSum','cancelled']]
     *
     * @return array<string, array<string, mixed>>
     */
    private function parseFile(string $path): array
    {
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $ss = $reader->load($path);
        $ws = $ss->getSheetByName($this->option('sheet')) ?? $ss->getSheet(0);
        $high = $ws->getHighestRow();

        $num = fn ($v) => (int) round((float) str_replace([',', ' ', '원'], '', (string) $v));

        $out = [];
        for ($r = 3; $r <= $high; $r++) {
            $vno = trim((string) $ws->getCell('D'.$r)->getCalculatedValue());
            if ($vno === '') {
                continue;
            }
            $s = trim((string) $ws->getCell('S'.$r)->getCalculatedValue());
            $o = str_replace(["\n", "\r"], ' ', trim((string) $ws->getCell('O'.$r)->getCalculatedValue()));
            $fileTotal = $num($ws->getCell('P'.$r)->getCalculatedValue()) + $num($ws->getCell('Q'.$r)->getCalculatedValue());
            $bx = $num($ws->getCell('BX'.$r)->getCalculatedValue());

            $cancelled = mb_strpos($o, '취소') !== false || $bx === 0;
            $pays = $this->parsePayments($o);
            $down = array_sum(array_map(fn ($p) => $p['label'] === 'down' ? $p['amount'] : 0, $pays));
            $balance = array_sum(array_map(fn ($p) => in_array($p['label'], ['balance', 'lump', 'unknown'], true) ? $p['amount'] : 0, $pays));
            $selling = array_sum(array_map(fn ($p) => $p['label'] === 'selling' ? $p['amount'] : 0, $pays));

            $out[$vno] = compact('s', 'o', 'fileTotal', 'bx', 'down', 'balance', 'selling', 'cancelled')
                + ['parsedSum' => $down + $balance + $selling];
        }

        return $out;
    }

    /**
     * O 메모 → [['label'=>down|balance|selling|lump|unknown, 'amount'=>int], ...]
     *
     * @return list<array{label:string, amount:int}>
     */
    private function parsePayments(string $o): array
    {
        $o = str_replace(["\n", "\r"], ' / ', $o);
        $o = preg_replace('#(?<!\d)\d{1,2}/\d{1,2}(?!\d)#', ' ', $o); // 날짜(M/D) 제거

        // 금액 추출 — 좌표 일치 위해 '만' 토큰은 동일 길이 공백으로 마스킹(오프셋 보존).
        $hits = [];
        if (preg_match_all('/[0-9][0-9,]*\s*만\s*원?/u', $o, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as $g) {
                $hits[] = ['amt' => (int) preg_replace('/[^0-9]/', '', explode('만', $g[0])[0]) * 10000, 'c' => $g[1] + strlen($g[0]) / 2];
            }
        }
        $masked = preg_replace_callback('/[0-9][0-9,]*\s*만\s*원?/u', fn ($mm) => str_repeat(' ', strlen($mm[0])), $o);
        if (preg_match_all('/[0-9]{1,3}(?:,[0-9]{3})+|[0-9]{6,}/u', $masked, $m2, PREG_OFFSET_CAPTURE)) {
            foreach ($m2[0] as $g) {
                $hits[] = ['amt' => (int) str_replace(',', '', $g[0]), 'c' => $g[1] + strlen($g[0]) / 2];
            }
        }

        // 라벨 위치 수집 (계약→down 등)
        $labels = [];
        foreach (['계약' => 'down', '잔금' => 'balance', '중도금' => 'balance', '매도비' => 'selling', '대금' => 'lump', '입금' => 'lump', '송금' => 'lump'] as $kw => $type) {
            if (preg_match_all('/'.$kw.'/u', $o, $mm, PREG_OFFSET_CAPTURE)) {
                foreach ($mm[0] as $g) {
                    $labels[] = ['c' => $g[1] + strlen($kw) / 2, 'type' => $type];
                }
            }
        }

        // 각 금액 → 가장 가까운 라벨 (거리순)
        $out = [];
        foreach ($hits as $h) {
            $best = 'unknown';
            $bestD = PHP_INT_MAX;
            foreach ($labels as $l) {
                $d = abs($l['c'] - $h['c']);
                if ($d < $bestD) {
                    $bestD = $d;
                    $best = $l['type'];
                }
            }
            $out[] = ['label' => $best, 'amount' => $h['amt']];
        }

        return $out;
    }
}
