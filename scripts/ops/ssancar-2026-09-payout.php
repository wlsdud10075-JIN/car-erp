<?php
/**
 * 2026-09-10 지급분 ssancarerp 반영 (jin 2026-09-17 승인)
 *   · 프리랜서 = 엑셀 CS(환율추가정산후 실지급액) 에 실지급액을 맞춘다 (차액은 other_deduction)
 *   · 사내직원 = ERP 건당 정책값 그대로 (차등지급 체크는 ERP 설정을 따른다) — other_deduction 0
 *   · 2차 정산은 열어둔다 (paid → secondary_status='pending' 자동)
 * APPLY=1 일 때만 기록. 기본은 dry-run.
 */
use App\Models\Vehicle; use App\Models\Salesman; use App\Models\Settlement;
use App\Models\AuditLog; use App\Models\User;
use Illuminate\Support\Facades\DB;

$APPLY = getenv('APPLY') === '1';
$PAID  = '2026-09-10';
$MONTH = '2026-09-01';
$NOTE  = '2026-09-10 지급분 (엑셀 「2026.09.17 수출정산 9월」 반영). 프리랜서=환율추가정산후 실지급액에 맞춤(차액 기타공제) · 사내직원=ERP 건당 정책.';

// 제외 — 엑셀 행번호 기준
$EXCLUDE = [
  '111'=>'ERP 판매 미입력 + 엑셀 취소행', '150'=>'ERP 판매 미입력', '152'=>'ERP 판매 미입력',
  '156'=>'ERP 판매 미입력', '157'=>'ERP 판매 미입력',
  '362'=>'ERP 매입취소 차량', '432'=>'엑셀 취소행', '500'=>'엑셀 취소행', '501'=>'엑셀 취소행',
  '175'=>'엑셀 내수인데 ERP 수출바이어(USD)', '177'=>'엑셀 내수인데 ERP 수출바이어(USD)', '179'=>'엑셀 내수인데 ERP 수출바이어(EUR)',
];

$actor = User::where('permission','super')->orderBy('id')->first()
      ?? User::where('permission','admin')->orderBy('id')->first();
echo "행위자: #{$actor?->id} {$actor?->name} ({$actor?->permission})\n";
echo $APPLY ? "*** APPLY 모드 — 실제로 기록합니다 ***\n\n" : "--- DRY RUN (쓰기 없음) ---\n\n";

$fh=fopen('/tmp/sep_settle.csv','r'); $hdr=fgetcsv($fh,0,',','"','');
$rows=[]; while($r=fgetcsv($fh,0,',','"','')){ $rows[]=array_combine($hdr,$r);} fclose($fh);
$sms = Salesman::withTrashed()->get()->keyBy('id');

$plan=[]; $skip=[];
foreach ($rows as $r) {
  if (isset($EXCLUDE[$r['row']])) { $skip[]=[$r['row'],$r['plate'],$r['man'],$EXCLUDE[$r['row']]]; continue; }
  $v = Vehicle::withTrashed()->with(['salesman','buyer'])
        ->where('nice_reg_vin',trim($r['vin']))
        ->where('vehicle_number',preg_replace('/\s+/','',$r['plate']))->first();
  if (!$v) { $skip[]=[$r['row'],$r['plate'],$r['man'],'ERP 에 차량 없음']; continue; }
  $sm=$sms->get($v->salesman_id);
  if (!$sm) { $skip[]=[$r['row'],$r['plate'],$r['man'],'담당자 없음']; continue; }
  $plan[]=['r'=>$r,'v'=>$v,'sm'=>$sm,'type'=>$sm->type==='employee'?'per_unit':'ratio'];
}
echo "처리 대상 ".count($plan)."건 / 제외 ".count($skip)."건\n\n";
echo "=== 제외 ===\n";
foreach ($skip as $s) echo sprintf("  r%-4s %-12s %-8s %s\n", $s[0],$s[1],$s[2],$s[3]);

$agg=[]; $errs=[]; $made=0; $upd=0;
$run = function () use ($plan,$APPLY,$PAID,$MONTH,$NOTE,&$agg,&$errs,&$made,&$upd) {
  foreach ($plan as $p) {
    $v=$p['v']; $r=$p['r']; $type=$p['type'];
    $s = Settlement::where('vehicle_id',$v->id)->orderBy('id')->first();
    $new = ! $s;
    if ($new) {
      $s = new Settlement(['vehicle_id'=>$v->id,'salesman_id'=>$v->salesman_id]);
      $s->is_domestic = (bool) $v->isDomesticSale();
    }
    $s->settlement_type = $type;
    $s->setRelation('vehicle',$v); $s->setRelation('salesman',$p['sm']);

    // 목표 실지급액
    $target = $type==='ratio' ? (int) round((float) $r['fx_after']) : null;

    // 기타공제 = (정산액 − 서류비 − 발송비 + 이월) − 목표
    $s->other_deduction = 0;
    if ($target !== null) {
      $natural = $s->settlement_amount - $s->document_fee - $s->shipping_fee
               + (int) ($s->carryover_in_krw ?? 0);
      $s->other_deduction = $natural - $target;
    }
    $s->settlement_status = 'paid';
    $s->attributed_month  = $MONTH;
    $s->confirmed_at      = $PAID.' 00:00:00';
    $s->paid_at           = $PAID.' 00:00:00';
    $s->note              = trim(($s->note ? $s->note."\n" : '').$NOTE);

    $payout = (int) $s->actual_payout;
    $want   = $target ?? $payout;
    if ($payout !== $want) $errs[] = "r{$r['row']} {$v->vehicle_number} 목표 {$want} ≠ 계산 {$payout}";

    $k=$r['man'];
    $agg[$k] ??= ['t'=>$type,'n'=>0,'pay'=>0,'ded'=>0,'new'=>0];
    $agg[$k]['n']++; $agg[$k]['pay']+=$payout; $agg[$k]['ded']+=(int)$s->other_deduction;
    if ($new) { $agg[$k]['new']++; $made++; } else { $upd++; }

    if ($APPLY) { $s->save(); }
  }
};
if ($APPLY) { AuditLog::actingAs($actor?->id, fn () => DB::transaction($run)); } else { $run(); }

echo "\n=== 인원별 ===\n";
printf("%-15s %-9s %5s %5s %15s %15s\n",'담당','타입','건수','신규','실지급액','기타공제');
$T=['n'=>0,'new'=>0,'pay'=>0,'ded'=>0];
foreach ($agg as $k=>$a) {
  printf("%-15s %-9s %5d %5d %15s %15s\n",$k,$a['t']==='ratio'?'프리랜서':'사내직원',$a['n'],$a['new'],
    number_format($a['pay']),number_format($a['ded']));
  foreach(['n','new','pay','ded'] as $f) $T[$f]+=$a[$f];
}
printf("%-15s %-9s %5d %5d %15s %15s\n",'합계','',$T['n'],$T['new'],number_format($T['pay']),number_format($T['ded']));
echo "\n신규 생성 $made 건 · 기존 갱신 $upd 건\n";
echo "금액 불일치: ".count($errs)."건\n";
foreach (array_slice($errs,0,20) as $e) echo "  $e\n";
