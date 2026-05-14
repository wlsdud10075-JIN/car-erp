<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    // 큐 14-1 — '전체' role 삭제. '관리' 신설 (서브관리자 — 승인 권한).
    // admin/super는 role 무관 (permission 기반)이라 임의 값 가능 — 시더에서 '관리'로 통일.
    public const ROLES = ['영업', '통관', '정산', '관리'];

    protected $fillable = [
        'name',
        'email',
        'password',
        'permission',
        'role',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function isSuperAdmin(): bool
    {
        return $this->permission === 'super';
    }

    public function isAdmin(): bool
    {
        return in_array($this->permission, ['super', 'admin'], true);
    }

    public function isUser(): bool
    {
        return $this->permission === 'user';
    }

    public function canAccessAdmin(): bool
    {
        return $this->isAdmin();
    }

    public function canAccessErp(): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return $this->permission === 'user' && in_array($this->role, self::ROLES, true);
    }

    public function canAccessSales(): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        // 큐 14-1 — '전체' 분기 제거. '관리'도 영업 화면 조회 가능 (업무 파악 의도).
        return in_array($this->role, ['영업', '관리'], true);
    }

    public function canAccessClearance(): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return in_array($this->role, ['통관', '관리'], true);
    }

    public function canAccessSettlement(): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return in_array($this->role, ['정산', '관리'], true);
    }

    public function canToggleFeatures(): bool
    {
        return $this->isSuperAdmin();
    }

    /**
     * 큐 2.6 — admin 미입금 우회 승인 권한.
     * admin/super만 가능. 영업/통관/정산 role은 차단.
     */
    public function canApproveUnpaidExport(): bool
    {
        return $this->isAdmin();
    }

    /**
     * 큐 7 확장 C7-a — 차량 회계 민감 컬럼 편집 권한.
     * 매입가·판매가·환율·면장금액·비용9개를 변경할 수 있는 role.
     *
     * 차단 대상: 정산/통관/관리 role — 회계 조작 위험 + SoD(Segregation of Duties).
     * 허용: admin/super, 영업만.
     *
     * (큐 2.5 회의록 §4 C7 + 큐 14 회의록 Security §SoD — "관리가 승인자인데 편집까지 허용하면 본인 등록을 본인 승인 가능")
     */
    public function canEditVehicleFinancialFields(): bool
    {
        return $this->isAdmin() || $this->role === '영업';
    }

    /**
     * 큐 2.6 — 단계 역행/skip 강제 진행 권한 (Security 제안).
     * 단계 의존성(C4·C5·H1·H2) 자체를 우회 — super 전용.
     * admin은 미입금 우회만 가능, 단계 자체 skip은 불가.
     */
    public function canForceStageJump(): bool
    {
        return $this->isSuperAdmin();
    }

    public function salesman(): HasOne
    {
        return $this->hasOne(Salesman::class);
    }

    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->map(fn (string $name) => Str::of($name)->substr(0, 1))
            ->implode('');
    }
}
