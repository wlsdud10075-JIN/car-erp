<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * 로컬 전용 「사용자 전환」(jin 2026-11-04 — board 088c877 과 같은 구조, 로컬에서만 쓴다).
 * 시스템관리자가 다른 계정으로 바로 들어가 보고 [원래 계정으로] 돌아온다. 전환 뒤에도 원래 super 를
 * 세션(`dev_switch_from`)에 들고 있어 계속 바꿀 수 있다.
 *
 * 🔒 `config('app.dev_user_switch')` 가 꺼져 있으면 404 — 기본값은 APP_ENV=local 일 때만 켜진다(운영 = production → 없음).
 *    운영에서 남의 계정으로 들어가는 기능은 감사·책임 추적을 깨뜨리므로 켜지 말 것.
 * 🚫 판정은 isSuperAdmin() — isAdmin() 은 최고관리자(admin)까지 포함한다.
 */
class DevUserSwitchController extends Controller
{
    private function guard(Request $request): User
    {
        abort_unless(config('app.dev_user_switch'), 404);
        $origin = User::find($request->session()->get('dev_switch_from')) ?? $request->user();
        abort_unless($origin && $origin->isSuperAdmin(), 403);

        return $origin;
    }

    public function switch(Request $request): RedirectResponse
    {
        $origin = $this->guard($request);
        // 검차·내수직원은 로그인하지 않는 사람이라 대상에서 뺀다.
        $target = User::whereNotIn('role', User::NO_LOGIN_ROLES)->findOrFail((int) $request->input('user_id'));

        if ($target->id === $origin->id) {
            return $this->back($request);
        }
        $from = $request->user();
        Auth::login($target);
        $request->session()->regenerate();
        $request->session()->put('dev_switch_from', $origin->id);
        $this->log($origin, $from, $target);

        return redirect()->route('dashboard');
    }

    public function back(Request $request): RedirectResponse
    {
        $origin = $this->guard($request);
        $from = $request->user();
        Auth::login($origin);
        $request->session()->regenerate();
        $request->session()->forget('dev_switch_from');
        if ($from && $from->id !== $origin->id) {
            $this->log($origin, $from, $origin);
        }

        return redirect()->route('dashboard');
    }

    /** 행위자 = 원래 super(로그인된 계정이 아니라). auditable = 전환해 들어간 계정. */
    private function log(User $origin, ?User $from, User $to): void
    {
        AuditLog::create([
            'user_id' => $origin->id,
            'auditable_type' => User::class,
            'auditable_id' => $to->id,
            'action' => 'dev_user_switch',
            'column_name' => null,
            'old_value' => $from?->name,
            'new_value' => $to->name,
            'ip_address' => request()?->ip(),
        ]);
    }
}
