<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class NiceApiService
{
    public function __construct(
        private string $apiKey = '',
        private string $apiSecret = '',
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            apiKey: config('services.nice.key', ''),
            apiSecret: config('services.nice.secret', ''),
        );
    }

    public function lookupVehicle(string $vehicleNumber): ?array
    {
        if (empty($this->apiKey)) {
            return null; // API 키 미설정 → 수동 입력 모드
        }

        return Cache::remember(
            "nice_vehicle_{$vehicleNumber}",
            300,
            fn () => $this->fetch($vehicleNumber),
        );
    }

    private function fetch(string $vehicleNumber): ?array
    {
        try {
            // TODO: 실제 NICE API 엔드포인트 연동
            return null;
        } catch (\Throwable $e) {
            Log::warning('NICE API failed', ['vehicle' => $vehicleNumber, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
