<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * @deprecated vehicles:rebuild-caches 사용. 호환성을 위해 alias로 유지.
 */
class RebuildProgressCache extends Command
{
    protected $signature = 'vehicles:rebuild-progress-cache';

    protected $description = '[deprecated] vehicles:rebuild-caches로 위임 (모든 캐시 재계산)';

    public function handle(): int
    {
        return $this->call('vehicles:rebuild-caches');
    }
}
