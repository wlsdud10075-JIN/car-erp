<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Salesman;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * 직원 명부 백필 (jin 2026-10-14) — 사용자 관리에 **이미 있는** 계정 중 사내직원관리에 행이 없는 사람을 미러한다.
 *
 * 새로 저장하는 계정은 사용자 관리 폼이 `Salesman::mirrorTypeFor()` 로 즉시 미러한다. 이 커맨드는 배포 전에 만들어진
 * 관리·수출통관·재무 일반사용자(실측 2026-10-14: ssancarerp 8 · heymanerp 1 · karabaerp 0)를 한 번 올리는 용도다.
 * 🚫 배포만으로는 그 사람들이 안 보인다(§8 #101) — 배포 → dry-run 실측 → jin 승인 → `--apply`.
 * 판정은 폼과 **같은 함수**(mirrorTypeFor) — 조건을 옮겨 적지 않는다(§8 #44). 기본 dry-run.
 */
class SyncStaffSalesmen extends Command
{
    protected $signature = 'salesmen:sync-staff {--apply : 실제로 만든다 (기본 = 미리보기)}';

    protected $description = '직원 명부 백필 — Salesman 행이 없는 계정(검차·관리·수출통관·재무 일반사용자)을 사내직원관리에 올린다. 기본 dry-run';

    public function handle(): int
    {
        $candidates = User::query()
            ->whereDoesntHave('salesman')
            ->orderBy('name')
            ->get()
            ->map(fn (User $u) => ['user' => $u, 'type' => Salesman::mirrorTypeFor($u)])
            ->filter(fn ($r) => $r['type'] !== null)
            ->values();

        $this->line('대상 '.$candidates->count().'명 (Salesman 행이 없고 미러 대상인 계정)');
        foreach ($candidates as $r) {
            $u = $r['user'];
            $this->line(sprintf('  #%d %s · %s/%s → %s', $u->id, $u->name, $u->permission, $u->role, $r['type']));
        }
        // 🔀 2026-11-04 — 업무관리자도 명부(staff). 종전 규칙이 「미러 안 함 → 비활성」이라 관리→업무관리자로 바꾼 사람의 행이 꺼져 있다.
        //    되살리는 건 **업무관리자 + 퇴사일 없음**만 — 그만둔 영업의 비활성 행까지 켜면 안 된다.
        $revive = Salesman::query()->where('is_active', false)->whereNull('left_at')
            ->whereHas('user', fn ($q) => $q->where('permission', 'manager'))
            ->with('user')->orderBy('name')->get();
        $this->line('되살릴 업무관리자 '.$revive->count().'명 (비활성 · 퇴사일 없음)');
        foreach ($revive as $s) {
            $this->line(sprintf('  #%d %s · %s → 활성', $s->id, $s->name, $s->type));
        }
        if (! $this->option('apply')) {
            $this->warn('dry-run — 실제로 만들려면 --apply');

            return self::SUCCESS;
        }
        foreach ($revive as $s) {
            $s->update(['is_active' => true]);
            AuditLog::recordChange($s, 'is_active', false, true);
        }
        foreach ($candidates as $r) {
            $u = $r['user'];
            Salesman::create([
                'user_id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'phone' => $u->phone,
                'type' => $r['type'], 'is_active' => true,
            ]);
        }
        $this->info($candidates->count().'명 생성');

        return self::SUCCESS;
    }
}
