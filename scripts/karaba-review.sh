#!/usr/bin/env bash
set -euo pipefail
cd /var/www/car-erp
php artisan tinker --execute='
use App\Models\Vehicle;
$vs = Vehicle::where("vehicle_number","like","[KARABA]%")->with("salesman","settlements")->orderBy("vehicle_number")->get();
echo "=== 정산 공식/상태 점검 ===\n";
$issues = [];
foreach ($vs as $v) {
    $s = $v->settlements->first();
    if (!$s) { $issues[] = $v->vehicle_number." : settlement 없음"; continue; }
    // 진행상태
    if ($v->progress_status_cache !== "거래완료") $issues[] = $v->vehicle_number." : progress=".$v->progress_status_cache." (거래완료 아님)";
    // 미납/미지급 0 이어야
    if ($v->sale_unpaid_amount > 0) $issues[] = $v->vehicle_number." : 판매미입금 ".$v->sale_unpaid_amount;
    if ($v->purchase_unpaid_amount > 0) $issues[] = $v->vehicle_number." : 매입미지급 ".$v->purchase_unpaid_amount;
    // 정산 금액 계산 (accessor)
    $tm = $s->total_margin; $amt = $s->settlement_amount; $pay = $s->actual_payout;
    printf("%-12s %-4s %-9s type=%-8s 총마진=%s 정산액=%s 실지급=%s\n",
        $v->vehicle_number, $v->currency, $s->settlement_status, $s->settlement_type,
        number_format($tm), number_format($amt), number_format($pay));
    // 음수/0 마진 이상 감지
    if ($tm <= 0) $issues[] = $v->vehicle_number." : 총마진 <= 0";
    if ($amt <= 0) $issues[] = $v->vehicle_number." : 정산액 <= 0";
}
echo "\n=== pending 2대 1차정산 처리가능 점검 ===\n";
foreach ($vs->filter(fn($v)=>optional($v->settlements->first())->settlement_status==="pending") as $v) {
    $s = $v->settlements->first();
    $ok = $s->settlement_status==="pending" && $s->salesman_id && $s->settlement_type;
    printf("%-12s type=%-8s salesman=%s → 1차정산 진입가능: %s\n", $v->vehicle_number, $s->settlement_type, $s->salesman_id, $ok?"YES":"NO");
    if (!$ok) $GLOBALS["bad"][]=$v->vehicle_number;
}
echo "\n=== 캐시 정합성 (progress_status_cache vs 실시간) ===\n";
$mismatch=0;
foreach ($vs as $v) { if ($v->progress_status_cache !== $v->progress_status) { $mismatch++; echo "  MISMATCH ".$v->vehicle_number." cache=".$v->progress_status_cache." live=".$v->progress_status."\n"; } }
echo "캐시 불일치: ".$mismatch."건\n";
echo "\n=== 종합 ===\n";
echo empty($issues) ? "✅ 이슈 없음\n" : ("⚠️ 이슈 ".count($issues)."건:\n  - ".implode("\n  - ",$issues)."\n");
'
