<?php

/**
 * heymanerp export JSON → 현재 DB(ssancarerp) import. jin 2026-07-21 테스트 데이터 복제.
 *
 * ⚠️ 대상 DB에 신규 INSERT(새 PK). 기존 데이터 덮어쓰지 않음. 거래처는 이름 기반 firstOrCreate(remap).
 * ⚠️ 삽입된 모든 PK를 매니페스트 파일로 남겨 정확 롤백(클리어) 가능. vehicles.memo 에 [HEYMAN-CLONE ...] 태그.
 * ⚠️ export 단계에서 이미 PII(RRN·주소·전화·계좌·문서·nice_raw) 제외됨.
 *
 * 사용:
 *   php scripts/ssancar-import-vehicles.php /path/heyman-vehicles.json           # 실제 반영
 *   php scripts/ssancar-import-vehicles.php /path/heyman-vehicles.json --dry      # 롤백(검증만)
 *   php scripts/ssancar-import-vehicles.php /path/heyman-vehicles.json --tag=T1   # 태그 커스텀
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

$jsonPath = $argv[1] ?? null;
$dry = in_array('--dry', $argv, true);
$tag = '[HEYMAN-CLONE]';
foreach ($argv as $a) {
    if (str_starts_with($a, '--tag=')) {
        $tag = '['.substr($a, 6).']';
    }
}
if (! $jsonPath || ! is_file($jsonPath)) {
    fwrite(STDERR, "사용: php scripts/ssancar-import-vehicles.php <json경로> [--dry] [--tag=XX]\n");
    exit(1);
}
$data = json_decode(file_get_contents($jsonPath), true);
if (! $data || ! isset($data['vehicles'])) {
    fwrite(STDERR, "JSON 파싱 실패 또는 vehicles 키 없음\n");
    exit(1);
}

// PII 안전망 — RRN 패턴이 JSON 에 있으면 중단(export 가 실수로 흘렸을 경우).
if (preg_match('/"nice_reg_owner_rrn"|"nice_raw"|"purchase_seller_account"/', file_get_contents($jsonPath))) {
    fwrite(STDERR, "⚠️ JSON 에 PII 컬럼 흔적 발견 — 중단. export 재확인 필요.\n");
    exit(1);
}

echo ($dry ? '[DRY-RUN] ' : '').'import 시작 — 대상 vehicles='.count($data['vehicles'])."\n";

$manifest = ['vehicles' => [], 'buyers_created' => [], 'consignees_created' => [], 'salesmen_created' => [], 'forwarders_created' => []];
$warn = [];

// ── 참조 remap 맵 (old id → new id) ──
$countryByOld = [];   // old country_id → target country id (code lookup)
foreach (($data['countries'] ?? []) as $oldId => $code) {
    $c = DB::table('countries')->where('code', $code)->orWhere('name', $code)->first();
    $countryByOld[$oldId] = $c->id ?? null;
}
$portByOld = [];
foreach (($data['ports'] ?? []) as $oldId => $p) {
    $q = DB::table('ports')->where('name', $p['name']);
    if (! empty($p['type'])) {
        $q->where('type', $p['type']);
    }
    $portByOld[$oldId] = optional($q->first())->id;
}

$run = function () use ($data, $tag, &$manifest, &$warn, &$countryByOld, &$portByOld) {
    // salesmen (이름 기준, type 필수)
    $salesmanByOld = [];
    foreach (($data['salesmen'] ?? []) as $oldId => $s) {
        $existing = Salesman::withTrashed()->where('name', $s['name'])->first();
        if ($existing) {
            $salesmanByOld[$oldId] = $existing->id;
        } else {
            $new = Salesman::create([
                'name' => $s['name'], 'initials' => $s['initials'] ?? null,
                'type' => $s['type'] ?: 'ratio', 'is_active' => $s['is_active'] ?? true, 'user_id' => null,
            ]);
            $salesmanByOld[$oldId] = $new->id;
            $manifest['salesmen_created'][] = $new->id;
        }
    }
    // forwarders
    $fwdByOld = [];
    foreach (($data['forwarders'] ?? []) as $oldId => $f) {
        $existing = ForwardingCompany::withTrashed()->where('name', $f['name'])->first();
        if ($existing) {
            $fwdByOld[$oldId] = $existing->id;
        } else {
            $new = ForwardingCompany::create(['name' => $f['name'], 'is_active' => $f['is_active'] ?? true]);
            $fwdByOld[$oldId] = $new->id;
            $manifest['forwarders_created'][] = $new->id;
        }
    }
    // buyers (이름 기준 — 충돌 경고)
    $buyerByOld = [];
    foreach (($data['buyers'] ?? []) as $oldId => $b) {
        $existing = Buyer::withTrashed()->where('name', $b['name'])->first();
        if ($existing) {
            $buyerByOld[$oldId] = $existing->id;
            $warn[] = "바이어 이름 매칭(기존 재사용): {$b['name']} → id {$existing->id} (오연결 주의)";
        } else {
            Buyer::$skipAutoConsignee = true;
            $new = Buyer::create([
                'name' => $b['name'], 'country_id' => $countryByOld[$b['country_id']] ?? null,
                'memo' => $b['memo'] ?? null, 'is_active' => true,
            ]);
            Buyer::$skipAutoConsignee = false;
            $buyerByOld[$oldId] = $new->id;
            $manifest['buyers_created'][] = $new->id;
        }
    }
    // consignees
    $consigneeByOld = [];
    foreach (($data['consignees'] ?? []) as $oldId => $c) {
        $newBuyerId = $buyerByOld[$c['buyer_id']] ?? null;
        $existing = Consignee::withTrashed()->where('name', $c['name'])->where('buyer_id', $newBuyerId)->first();
        if ($existing) {
            $consigneeByOld[$oldId] = $existing->id;
        } else {
            $new = Consignee::create([
                'name' => $c['name'], 'buyer_id' => $newBuyerId,
                'country_id' => $countryByOld[$c['country_id']] ?? null,
                'id_type' => $c['id_type'] ?? null, 'eori_number' => $c['eori_number'] ?? null,
                'tax_number' => $c['tax_number'] ?? null, 'memo' => $c['memo'] ?? null, 'is_active' => true,
            ]);
            $consigneeByOld[$oldId] = $new->id;
            $manifest['consignees_created'][] = $new->id;
        }
    }

    // vehicles
    $fkRemap = [
        'salesman_id' => $salesmanByOld, 'buyer_id' => $buyerByOld, 'consignee_id' => $consigneeByOld,
        'export_buyer_id' => $buyerByOld, 'export_consignee_id' => $consigneeByOld,
        'bl_buyer_id' => $buyerByOld, 'bl_consignee_id' => $consigneeByOld,
        'forwarding_company_id' => $fwdByOld, 'discharge_port_id' => $portByOld,
    ];
    foreach ($data['vehicles'] as $row) {
        $fps = $row['_final_payments'] ?? [];
        $pbps = $row['_pbp'] ?? [];
        unset($row['_final_payments'], $row['_pbp'], $row['id'], $row['receivable_manager_id']);
        foreach ($fkRemap as $col => $map) {
            if (! empty($row[$col])) {
                $row[$col] = $map[$row[$col]] ?? null;
            }
        }
        // chk_sale_required 방어: sale_price>0 인데 필수 결측이면 판매 보류(매입만)
        if (! empty($row['sale_price']) && (empty($row['sale_date']) || empty($row['buyer_id']) || empty($row['exchange_rate']))) {
            $row['sale_price'] = 0;
            $warn[] = "판매정보 결측 → 판매 보류(매입만): {$row['vehicle_number']}";
        }
        $row['progress_status_rule_version'] = 4;
        $row['memo'] = trim(($row['memo'] ?? '')." {$tag}");

        Vehicle::withoutEvents(function () use ($row, $fps, $pbps, &$manifest) {
            $v = new Vehicle;
            $v->forceFill($row);
            $v->save();
            $manifest['vehicles'][] = $v->id;
            foreach ($fps as $p) {
                $v->finalPayments()->create($p);
            }
            foreach ($pbps as $p) {
                $v->purchaseBalancePayments()->create($p);
            }
        });
    }
};

DB::beginTransaction();
try {
    $run();
    if ($dry) {
        DB::rollBack();
        echo "[DRY-RUN] 롤백 완료 (검증만).\n";
    } else {
        DB::commit();
        $manifestPath = dirname($jsonPath).'/import-manifest-'.date('YmdHis').'.json';
        file_put_contents($manifestPath, json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        echo "커밋 완료. 매니페스트: {$manifestPath}\n";
    }
} catch (\Throwable $e) {
    DB::rollBack();
    fwrite(STDERR, '❌ 실패(롤백): '.$e->getMessage()."\n".$e->getFile().':'.$e->getLine()."\n");
    exit(1);
}

echo '삽입 요약 — vehicles='.count($manifest['vehicles'])
    .' buyers신규='.count($manifest['buyers_created'])
    .' consignees신규='.count($manifest['consignees_created'])
    .' salesmen신규='.count($manifest['salesmen_created'])."\n";
if ($warn) {
    echo "⚠️ 경고 ".count($warn)."건:\n  ".implode("\n  ", array_slice($warn, 0, 20))."\n";
}
if (! $dry) {
    echo "\n다음: php artisan vehicles:rebuild-caches && php artisan vehicles:rebuild-progress-cache\n";
}
