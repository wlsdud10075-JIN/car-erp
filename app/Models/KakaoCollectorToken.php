<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * PC 수집기 토큰 — 기기마다 하나, 해시만 저장. 원문은 발급 때 1회 보여준다. 폐기 = revoked_at.
 * users 표에 두지 않는 이유 = 기기별 폐기 + User 감사 훅(§8 #108)이 last_used_at 갱신마다 울리지 않게.
 */
class KakaoCollectorToken extends Model
{
    protected $fillable = ['user_id', 'token_hash', 'device_name', 'local_secret', 'last_used_at', 'revoked_at'];

    protected $casts = ['last_used_at' => 'datetime', 'revoked_at' => 'datetime'];

    /** 페이지로 내려가지 않게(§11 — local_secret 은 그 사용자 본인 세션에만, 직렬화 경로로 새지 않게) */
    protected $hidden = ['token_hash', 'local_secret'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return array{0: self, 1: string} 모델과 토큰 원문(1회)
     *
     * local_secret(§11) = 같은 PC 의 에이전트(127.0.0.1:47321)를 ERP 페이지만 부를 수 있게 하는 값. 설치 .bat 이 토큰과 함께 심는다.
     */
    public static function issue(User $user, ?string $deviceName = null): array
    {
        $plain = 'kck_'.Str::random(48);
        $token = self::create(['user_id' => $user->id, 'token_hash' => self::hashOf($plain), 'device_name' => $deviceName, 'local_secret' => Str::random(40)]);

        return [$token, $plain];
    }

    public static function hashOf(string $plain): string
    {
        return hash('sha256', $plain);
    }

    public static function findActive(string $plain): ?self
    {
        return self::where('token_hash', self::hashOf($plain))->whereNull('revoked_at')->first();
    }
}
