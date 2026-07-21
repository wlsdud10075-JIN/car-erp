<?php

/**
 * heymanerp 차량관리 데이터 READ-ONLY export → JSON (PII 제외). jin 2026-07-21 테스트 데이터 복제용.
 *
 * ⚠️ 읽기 전용 — DB 를 절대 수정하지 않는다. heymanerp 서버에서 실행 후 생성된 JSON 을 scp 로 회수.
 * ⚠️ RRN·주소·전화·계좌·문서경로·nice_raw 는 SELECT 결과에서 제거(반출 경계를 heyman 서버 안쪽에 둠).
 *
 * 사용: php scripts/heyman-export-vehicles.php > /tmp/heyman-vehicles.json
 *   (또는 인자로 파일경로: php scripts/heyman-export-vehicles.php /tmp/heyman-vehicles.json)
 */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Buyer;
use App\Models\Consignee;
use App\Models\ForwardingCompany;
use App\Models\Salesman;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;

// ── 제외 컬럼(PII·깨지는 참조) — 실제 스키마 기준 정확한 컬럼명 ──
$VEHICLE_EXCLUDE = [
    // RRN·소유자 개인정보 (jin 명시: 주민번호·주소 제외)
    'nice_reg_owner_rrn', 'nice_reg_owner_rrn_encrypted_at', 'nice_reg_owner_name', 'nice_reg_owner_addr',
    // NICE 원본 JSON — 소유자 개인정보 포함 가능 → 통째 제외
    'nice_raw',
    // 딜러 실번호(알림톡 트리거) + DHL 개인정보
    'deregistration_notice_phone',
    'dhl_recipient_name', 'dhl_recipient_address', 'dhl_recipient_phone', 'dhl_sender_name', 'dhl_sender_address',
    // 매도자·매도비 계좌(금융 PII)
    'purchase_seller_bank', 'purchase_seller_account', 'purchase_seller_holder', 'purchase_bank_memo',
    'purchase_fee_bank', 'purchase_fee_account', 'purchase_fee_holder',
    // 문서 경로(회사별 S3 버킷 분리 → 링크 깨짐 + RRN/주소 시각 포함)
    'deregistration_document', 'export_declaration_document', 'bl_document', 'checkbill_document',
    // 사용자 FK(타깃 users 와 매칭 불가)
    'receivable_manager_id',
    // 타임스탬프·soft delete 는 import 에서 재생성
    'deleted_at',
];

$strip = function (array $row, array $exclude): array {
    foreach ($exclude as $c) {
        unset($row[$c]);
    }

    return $row;
};

// ── 참조 테이블: 원본 id → [name, ...] (import 가 name 으로 lookup-or-create) ──
$buyers = Buyer::withTrashed()->get()->mapWithKeys(fn ($b) => [$b->id => [
    'name' => $b->name,
    'country_id' => $b->country_id,   // import 에서 code 기반 remap (country 는 별도 처리)
    'memo' => $b->memo,
    // PII 제외: contact_email, contact_phone, passport_id, address, contact_name
]])->all();

$consignees = Consignee::withTrashed()->get()->mapWithKeys(fn ($c) => [$c->id => [
    'name' => $c->name,
    'buyer_id' => $c->buyer_id,        // 원본 buyer id (import 가 buyer remap 후 연결)
    'country_id' => $c->country_id,
    'id_type' => $c->id_type,
    'eori_number' => $c->eori_number,  // 사업 식별번호(준공개) — 유지
    'tax_number' => $c->tax_number,
    'memo' => $c->memo,
    // PII 제외: id_value(RRN/여권/사업자), contact_email, contact_phone, address, contact_name
]])->all();

$salesmen = Salesman::withTrashed()->get()->mapWithKeys(fn ($s) => [$s->id => [
    'name' => $s->name,
    'initials' => $s->initials,
    'type' => $s->type,                // 정산 로직 의존(ratio/per_unit) — 반드시 유지
    'is_active' => $s->is_active,
    // 제외: user_id(FK), phone, email
]])->all();

$forwarders = ForwardingCompany::withTrashed()->get()->mapWithKeys(fn ($f) => [$f->id => [
    'name' => $f->name,
    'is_active' => $f->is_active,
    // 제외: contact_name, email, phone, address
]])->all();

// country id → code (import 가 code 로 lookup) — 참조 무결성용
$countries = DB::table('countries')->get()->mapWithKeys(fn ($c) => [$c->id => $c->code ?? $c->name])->all();
$ports = DB::table('ports')->get()->mapWithKeys(fn ($p) => [$p->id => ['name' => $p->name, 'type' => $p->type ?? null]])->all();

// ── vehicles + 자식(final_payments, purchase_balance_payments, settlements) ──
$vehicles = [];
Vehicle::withTrashed()->with(['finalPayments', 'purchaseBalancePayments', 'settlements'])
    ->chunk(200, function ($chunk) use (&$vehicles, $strip, $VEHICLE_EXCLUDE) {
        foreach ($chunk as $v) {
            $row = $strip($v->getAttributes(), $VEHICLE_EXCLUDE);
            $row['_final_payments'] = $v->finalPayments->map(fn ($p) => [
                'amount' => $p->amount, 'type' => $p->type, 'payment_date' => optional($p->payment_date)->format('Y-m-d'),
                'exchange_rate' => $p->exchange_rate, 'note' => $p->note, 'confirmed_at' => optional($p->confirmed_at)?->format('Y-m-d H:i:s'),
                // 제외: transfer_id(FK), confirmed_by_user_id(FK), proof_path(S3)
            ])->all();
            $row['_pbp'] = $v->purchaseBalancePayments->map(fn ($p) => [
                'amount' => $p->amount, 'type' => $p->type, 'payment_date' => optional($p->payment_date)->format('Y-m-d'),
                'note' => $p->note, 'confirmed_at' => optional($p->confirmed_at)?->format('Y-m-d H:i:s'),
            ])->all();
            $vehicles[] = $row;
        }
    });

$out = [
    'exported_at' => date('Y-m-d H:i:s'),
    'source' => 'heymanerp',
    'buyers' => $buyers,
    'consignees' => $consignees,
    'salesmen' => $salesmen,
    'forwarders' => $forwarders,
    'countries' => $countries,
    'ports' => $ports,
    'vehicles' => $vehicles,
    'counts' => [
        'vehicles' => count($vehicles),
        'buyers' => count($buyers),
        'consignees' => count($consignees),
        'salesmen' => count($salesmen),
    ],
];

$json = json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

$path = $argv[1] ?? null;
if ($path) {
    file_put_contents($path, $json);
    fwrite(STDERR, "wrote {$path} — vehicles={$out['counts']['vehicles']} buyers={$out['counts']['buyers']}\n");
} else {
    echo $json;
}
