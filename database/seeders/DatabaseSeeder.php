<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * 시드 진입점 — 환경에 따라 분기.
 *
 *   - 항상:       ProductionSeeder (국가·항구·설정·관리자 계정 = 운영 필수)
 *   - local 한정: DemoSeeder (더미 바이어/차량/영업담당자/정산 데모)
 *
 * whitelist('local') 사용 — staging/qa/production 어디에도 더미가 들어가지 않게.
 * 운영에서 실수로 `php artisan db:seed` 해도 마스터 데이터 + 관리자 계정만 들어간다.
 *
 * 관리자 계정은 ProductionSeeder 가 .env 환경변수(ADMIN_*, BOSS_* 접두)에서 읽는다.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(ProductionSeeder::class);

        if (app()->environment('local')) {
            $this->call(DemoSeeder::class);
        }
    }
}
