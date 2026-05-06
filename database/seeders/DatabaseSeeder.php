<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@car-erp.test'],
            User::factory()->raw([
                'name' => '시스템관리자',
                'email' => 'admin@car-erp.test',
                'permission' => 'super',
                'role' => '전체',
            ])
        );
    }
}
