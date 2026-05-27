<?php

namespace App\Console\Commands;

use Database\Seeders\E2eDemoSeeder;
use Illuminate\Console\Command;

/**
 * E2eDemoSeeder 가 생성한 데모 데이터를 깨끗이 제거 (사용자 요청 2026-05-27).
 *   php artisan e2e:demo-clear
 * 마커([E2E] / E2ED- / e2e-demo-) 기준으로만 삭제 → 운영·기타 데이터 무영향.
 */
class E2eDemoClear extends Command
{
    protected $signature = 'e2e:demo-clear';

    protected $description = 'E2eDemoSeeder 데모 데이터(마커 기준) 클린 제거';

    public function handle(): int
    {
        E2eDemoSeeder::clear();
        $this->info('E2E 데모 데이터 제거 완료 (마커: [E2E] / E2ED- / e2e-demo-).');

        return self::SUCCESS;
    }
}
