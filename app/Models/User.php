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

    /**
     * 큐 19-F — 자금 이체 재무 확정 권한 (회의록 2026-05-16).
     *
     * SoD 분리: 관리(승인) ≠ 재무(실물 처리·확정).
     * 허용: super / admin / role='정산'. ⚠️ '관리' role 명시적 차단 (canAccessSettlement 와 분리).
     *
     * canAccessSettlement 가 ['정산','관리'] 모두 통과시키는 것과 의도적으로 다름 —
     * 박관리(관리 role)는 자기 승인을 직접 재무 확정할 수 없어야 함.
     */
    public function canConfirmFinanceTransfer(): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return $this->role === '정산';
    }

    /**
     * 큐 20-B — 매입·판매 잔금 재무 확정 범용 alias.
     * canConfirmFinanceTransfer 와 동일 권한 (super/admin/정산 role).
     * PaymentConfirmationService 에서 사용 — 자금 이체 외 잔금 확정에도 동일 SoD 적용.
     */
    public function canConfirmFinance(): bool
    {
        return $this->canConfirmFinanceTransfer();
    }

    public function canToggleFeatures(): bool
    {
        return $this->isSuperAdmin();
    }

    /**
     * 큐 14-2 — 4 승인 액션 권한 (회의록 v5.1 §9-2 + 2026-05-14 회의 합의안).
     *
     * 대상 4 액션:
     *   1. G2 같은 바이어 미수 + 신규 거래
     *   2. 정산 confirmed → paid 전환
     *   3. 민감 액션 (차량 폐기 / RRN 수정 / B/L 수동 발행)
     *   4. 50% 룰 예외 진행 (선수금 50% 미달 통관 진입)
     *
     * 허용: super / admin / role='관리'.
     */
    public function canApprove(): bool
    {
        return $this->isAdmin() || $this->role === '관리';
    }

    /**
     * 큐 14-2 — '관리' role의 /admin/dashboard read-only 조회 권한.
     * Security 권고: settings·users·기능 토글은 차단, dashboard KPI만 허용.
     */
    public function canViewAdminDashboard(): bool
    {
        return $this->isAdmin() || $this->role === '관리';
    }

    /**
     * 큐 14-2 보강 — 채권관리(/erp/receivables) 접근 권한.
     * 회의록 14 §누락 보강: 정산 user(미수금 회수 책임)와 관리 user(채권 위험 모니터링)도
     * 접근 가능해야 함. 회의록 §6 결정 #6과 동일 원칙 — 모니터링은 광범위 허용, 편집 권한은 분리.
     */
    public function canViewReceivables(): bool
    {
        return $this->isAdmin() || in_array($this->role, ['정산', '관리'], true);
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
