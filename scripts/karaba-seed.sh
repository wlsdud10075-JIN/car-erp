#!/usr/bin/env bash
set -euo pipefail
cd /var/www/car-erp
echo "===== 시드 실행 ====="
php artisan db:seed --class='Database\Seeders\KarabaDemoSeeder' --force
echo "===== 검증 ====="
php artisan tinker --execute='
use App\Models\Vehicle;
$vs = Vehicle::where("vehicle_number","like","[KARABA]%")->with("salesman","settlements")->get();
$paid=0;$pending=0;
foreach($vs as $v){ $s=$v->settlements->first(); if(($s?->settlement_status)==="paid")$paid++; if(($s?->settlement_status)==="pending")$pending++; }
echo "차량=".$vs->count()." paid=".$paid." pending=".$pending."\n";
echo "바이어=".App\Models\Buyer::where("name","like","%[KARABA]%")->count()." 컨사이니=".App\Models\Consignee::where("name","like","%[KARABA]%")->count()." 영업=".App\Models\Salesman::where("name","like","%[KARABA]%")->count()."\n";
'
