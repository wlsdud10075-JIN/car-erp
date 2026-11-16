<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 🚨 Livewire 4 — `wire:model.blur` 는 칸을 벗어나도 **서버로 요청을 보내지 않는다**(브라우저 값만 맞춘다).
 *   서버로 보내려면 `wire:model.live.blur`. 근거 = vendor/livewire/livewire/dist/livewire.esm.js
 *   (`shouldSendNetwork = isLive || hasLazyWithoutLive`, `.blur` 만이면 false).
 *
 * 실사고(2026-11-16 씬 점검이 찾음): 딜러 번호 칸이 `wire:model.blur` 라서 `updatedDeregistrationBuyerPhone()`
 * (하이픈 정리 · 「휴대폰 번호 형식이 아닙니다」 경고, 11-13 배포)가 칸을 벗어날 때 한 번도 안 돌았다 — 저장할 때만.
 * 기능 테스트(Volt `->set()`)는 훅을 직접 부르므로 원리상 못 잡는다 → 정적 검사.
 *
 * 규칙: `updated{Prop}` 훅이 있는 프로퍼티를 `wire:model.blur`(live 없이)로 묶지 말 것.
 */
class LivewireBlurNeedsLiveTest extends TestCase
{
    public function test_blur_bound_properties_with_server_hooks_use_live(): void
    {
        $violations = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views')));
        foreach ($it as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            $src = (string) file_get_contents($file->getPathname());
            preg_match_all('/wire:model\.blur="([A-Za-z_][\w.]*)"/', $src, $m);
            foreach (array_unique($m[1]) as $prop) {
                $base = explode('.', $prop)[0];
                $hook = 'updated'.ucfirst($base);
                if (preg_match('/function\s+'.preg_quote($hook, '/').'\s*\(/', $src)) {
                    $violations[] = str_replace(resource_path(), 'resources', $file->getPathname())." — {$prop} (훅 {$hook}() 이 칸을 벗어날 때 안 돈다 → wire:model.live.blur)";
                }
            }
        }

        $this->assertSame([], $violations, "서버 훅이 있는데 wire:model.blur 로 묶였다(Livewire 4 는 요청을 안 보낸다):\n".implode("\n", $violations));
    }
}
