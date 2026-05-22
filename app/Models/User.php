<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
    public const ROLES = ['영업', '수출통관', '재무', '관리'];

    // 2026-05-21 — 정산 분류 (role='영업' 일 때만 사용).
    // 사용자 결정: Salesman.type 단일 관리 → User.type 으로 이동. /admin/users 폼에서 입력.
    // 저장 시 연결된 Salesman.type 미러링 (Vehicle::saved 훅 호환 위해).
    public const TYPES = [
        'employee' => '사내직원',
        'freelance' => '프리랜서',
    ];

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'permission',
        'role',
        'type',
        'manager_user_id',
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

        return in_array($this->role, ['수출통관', '관리'], true);
    }

    public function canAccessSettlement(): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return in_array($this->role, ['재무', '관리'], true);
    }

    /**
     * 큐 19-F — 자금 이체 재무 확정 권한 (회의록 2026-05-16).
     *
     * 2026-05-21 사용자 결정 — 19-F SoD 정책 직접 변경 ('회의 하지 마, 중간 관리자라 그래'):
     *   '관리' role 을 중간 관리자로 정의 → 재무 확정/거부 등 일상 운영 허용.
     *   삭제 등 파괴적 액션만 별도 차단 (transfers 페이지엔 직접 삭제 없어서 추가 가드 없음).
     *   SoD 회의록 19-F 결정은 사용자 직접 결정으로 무효 — 메모리 project_pending_tasks 처리 완료.
     *
     * 허용: super / admin / role∈{재무, 관리}.
     */
    public function canConfirmFinanceTransfer(): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return in_array($this->role, ['재무', '관리'], true);
    }

    /**
     * 큐 20-B — 매입·판매 잔금 재무 확정 범용 alias.
     * canConfirmFinanceTransfer 와 동일 권한 (super/admin/재무 role).
     * PaymentConfirmationService 에서 사용 — 자금 이체 외 잔금 확정에도 동일 SoD 적용.
     */
    public function canConfirmFinance(): bool
    {
        return $this->canConfirmFinanceTransfer();
    }

    /**
     * 22-A-3a 사용자 정정 (2026-05-20) — 4 항목(계약금/중도금/선수금1/2) 입력 권한.
     *
     * 사용자 의도: "재무와 관리자의 역할을 하는 사람들만 기입" — 영업은 못 건드림.
     * canConfirmFinanceTransfer 와 다른 점: 관리(manager) role 포함.
     *   - 4 항목은 입금 분류 메타데이터 성격 (자금 이체 SoD 와 다름)
     *   - 관리자는 자기 승인을 직접 처리하는 SoD 위반과 무관 — 4 항목 정리는 OK
     *
     * 영업·수출통관은 차단. 잔금 N+ 입력은 별개 (canEditVehicleFinancialFields).
     */
    public function canManagePaymentBreakdown(): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return in_array($this->role, ['재무', '관리'], true);
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
     * 관리자 대시보드 (/admin/dashboard) 접근 권한 — admin/super 전용.
     *
     * 회의확장씬 사용자 정정 (2026-05-22):
     *   사용자 의도 "관리자 대시보드는 관리자(admin permission)만 볼 수 있어야"
     *   [관리] role 은 차단. 큐 14-2 의 '관리 read-only 권고' 무효화.
     *
     * 용어 정의 (사용자 명세):
     *   - [관리] = role='관리' (일반 user permission + 중간 관리자)
     *   - 관리자 = permission ∈ {super, admin} (최고관리자)
     *
     * 부수 효과:
     *   - AdminDashboardMiddleware 가 [관리] 차단 → /admin/dashboard 403
     *   - 사이드바 '관리자 대시보드' 메뉴 [관리] 에게 자동 숨김 (show 가 canViewAdminDashboard)
     *   - admin/dashboard.blade.php 의 managerScopeSalesmanIds() 분기는 코드 보존
     *     (defensive — 추후 권한 재확장 시 자동 동작, 현재 dead code 아님 — 영업 role 분기 흐름 유지)
     */
    public function canViewAdminDashboard(): bool
    {
        return $this->isAdmin();
    }

    /**
     * 큐 14-2 보강 — 채권관리(/erp/receivables) 접근 권한.
     * 회의록 14 §누락 보강: 정산 user(미수금 회수 책임)와 관리 user(채권 위험 모니터링)도
     * 접근 가능해야 함. 회의록 §6 결정 #6과 동일 원칙 — 모니터링은 광범위 허용, 편집 권한은 분리.
     */
    public function canViewReceivables(): bool
    {
        return $this->isAdmin() || in_array($this->role, ['재무', '관리'], true);
    }

    /**
     * 큐 2.6 — admin 미입금 우회 승인 권한.
     * admin/super만 가능. 영업/수출통관/재무 role은 차단.
     */
    public function canApproveUnpaidExport(): bool
    {
        return $this->isAdmin();
    }

    /**
     * 큐 7 확장 C7-a — 차량 회계 민감 컬럼 편집 권한.
     * 매입가·판매가·환율·면장금액·비용9개를 변경할 수 있는 role.
     *
     * 차단 대상: 재무·수출통관 role — 회계 조작 위험 + SoD(Segregation of Duties).
     * 허용: admin/super, 영업·관리.
     *
     * 회의확장씬 2026-05-22 — [관리] 추가:
     *   사용자 헤더 명세 "[관리]가 차량등록부터 거래완료까지 모든 씬 진행" →
     *   [영업]/[재무]/[수출통관] 권한 기본 보유. 27bf24f 정신 (중간 관리자) 일치.
     *   기존 SoD 우려는 19-F 사용자 결정으로 무효화됨.
     *
     * (큐 2.5 회의록 §4 C7 + 큐 14 회의록 Security §SoD)
     */
    public function canEditVehicleFinancialFields(): bool
    {
        return $this->isAdmin() || in_array($this->role, ['영업', '관리'], true);
    }

    /**
     * 회의확장씬 2026-05-22 — 항구 마스터 (/admin/ports) 접근/편집 권한.
     * 사용자 명세: 항구 마스터 [관리]도 볼 수 있고 수정 가능하도록.
     * canAccessAdmin 과 별개 (사용자 관리·기능 설정은 admin only 유지).
     */
    public function canManagePorts(): bool
    {
        return $this->isAdmin() || $this->role === '관리';
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

    /**
     * 2026-05-19 풀회의 안건 C — 말소 처리 [everyone] 권한.
     *
     * 사용자 명세: "어떤 부서든 할 수 있음" — 영업 한정 → 4 role 누구나.
     * 단 SoD 보존: 재무는 자금 흐름 담당이라 말소 처리 근거 없음 → 제외.
     *
     * 허용: super / admin / role∈{영업, 수출통관, 관리}.
     * 차단: role='재무'.
     *
     * 말소 처리에는 RRN 입력이 필수(H10 validation)이므로
     * canHandleDeregistration() 사용자는 RRN silent restore 대상에서 제외 (Day 5 보강).
     */
    public function canHandleDeregistration(): bool
    {
        return $this->isAdmin() || in_array($this->role, ['영업', '수출통관', '관리'], true);
    }

    public function salesman(): HasOne
    {
        return $this->hasOne(Salesman::class);
    }

    // 회의확장씬 #11 (2026-05-22) — [관리]별 담당 영업담당자 배정 (1:N).
    // 영업 user.manager_user_id = [관리] user.id. [관리] 솔팅 산정 input.
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_user_id');
    }

    public function subordinates(): HasMany
    {
        return $this->hasMany(User::class, 'manager_user_id');
    }

    /**
     * 회의확장씬 #11 — [관리]가 담당하는 영업의 Salesman.id 배열.
     * vehicles/buyers 목록 SQL + 영업 select 옵션 필터링에 사용.
     *
     * 호출 컨텍스트:
     *   - role='관리' 사용자만 의미 있음 (subordinates 가 영업 user 들)
     *   - admin/super 는 호출 X (전체 노출 분기 별도)
     *   - role='영업' 은 8711e7d restrictToOwnSalesman 가 우선 (본 함수 호출 X)
     *
     * 빈 배열 = 배정된 영업 0명 → 호출자가 whereIn([]) 로 빈 결과 처리.
     *
     * @return array<int>
     */
    public function getSubordinateSalesmanIds(): array
    {
        return Salesman::query()
            ->whereIn('user_id', $this->subordinates()->pluck('id'))
            ->pluck('id')
            ->all();
    }

    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->map(fn (string $name) => Str::of($name)->substr(0, 1))
            ->implode('');
    }
}
