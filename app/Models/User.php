<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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

    // item 1 (jin 2026-07-07) — 권한 등급. super > admin(대표) > manager(업무관리자) > user.
    //   manager = admin 등가 권한에서 [기능설정·단계강제·super/admin 계정관리]만 제외.
    //   Phase 2 정산지급 월배치 승인 사다리의 중간 계단([관리]→manager→대표).
    public const PERMISSIONS = ['super', 'admin', 'manager', 'user'];

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
        'locale',
        'manager_user_id',
        'last_login_at',
    ];

    // i18n Phase 0 — 지원 언어. 'ko' 기본(항상), 'en'은 super가 기능설정에서 활성화해야 노출.
    public const LOCALES = ['ko', 'en'];

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

    // item 1 — 업무관리자(중간 관리 등급). isAdmin(super|admin)과 별개.
    //   canX() 그랜트 위치에 admin과 함께 들어가 관리자대시보드·로그·사용자관리·ERP 전반을 얻는다.
    //   예외(manager 제외): canToggleFeatures·canForceStageJump(super 전용), super/admin 계정관리.
    public function isManager(): bool
    {
        return $this->permission === 'manager';
    }

    public function canAccessAdmin(): bool
    {
        return $this->isAdmin() || $this->isManager();
    }

    public function canAccessErp(): bool
    {
        if ($this->isAdmin() || $this->isManager()) {
            return true;
        }

        return $this->permission === 'user' && in_array($this->role, self::ROLES, true);
    }

    public function canAccessSales(): bool
    {
        if ($this->isAdmin() || $this->isManager()) {
            return true;
        }

        // 큐 14-1 — '전체' 분기 제거. '관리'도 영업 화면 조회 가능 (업무 파악 의도).
        return in_array($this->role, ['영업', '관리'], true);
    }

    public function canAccessClearance(): bool
    {
        if ($this->isAdmin() || $this->isManager()) {
            return true;
        }

        return in_array($this->role, ['수출통관', '관리'], true);
    }

    public function canAccessSettlement(): bool
    {
        if ($this->isAdmin() || $this->isManager()) {
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
        if ($this->isAdmin() || $this->isManager()) {
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
        if ($this->isAdmin() || $this->isManager()) {
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
        return $this->isAdmin() || $this->isManager() || $this->role === '관리';
    }

    /**
     * 큐 21 — 차량 Ledger(회계 영향 필드: 환율·판매가 등 21컬럼) 잠금 해제 권한.
     *
     * 2026-06-22 jin 결정: 등록 후 환율·판매가 정정이 비일비재해 admin/super 외 role='관리'도 허용
     *   (2026-05-18 회의의 "admin/super 전용" 결정을 override). 단 관리는 **본인 팀 차량**(canScopeVehicle)만.
     *   해제 사유 + 필드 값 변경(old→new) 모두 AuditLog 자동 기록(ledger_field_unlocked + recordChange).
     */
    public function canUnlockLedger(Vehicle $vehicle): bool
    {
        if ($this->canAccessAdmin()) {
            return true;   // super/admin — 전체 차량
        }

        return $this->role === '관리' && $this->canScopeVehicle($vehicle);   // 관리 — 본인 팀 차량만 (IDOR 방지)
    }

    /**
     * 사용자 관리(/admin/users) 진입 권한 — super/admin(전체) + 관리(본인 팀 영업만).
     * 2026-06-30 jin: 2026-05-14 "super/admin 전용" 결정을 팀 영업 한정으로 완화.
     *   ⚠️ 관리는 super/admin 계정 생성·변경 절대 불가 — escalation 차단(canManageUserAccount + save 강제).
     */
    public function canManageUsers(): bool
    {
        return $this->isAdmin() || $this->isManager() || $this->role === '관리';
    }

    /**
     * 특정 사용자 계정 변경(편집·저장·삭제) 권한 — IDOR/escalation 단일 출처(SKILLS #26).
     *   super/admin = 전체. 관리 = 본인 팀 영업(영업 role + user permission)만.
     *   ⚠️ 관리는 super/admin/타 관리 계정을 절대 못 건드림 — editingId 클라이언트 주입 방어용으로
     *   openEdit·save(편집)·delete 모든 mutating 경로에서 매번 호출.
     */
    public function canManageUserAccount(User $target): bool
    {
        if ($this->isAdmin()) {
            return true;   // super/admin — 전체 (단 admin은 super 편집 불가 가드는 컴포넌트에서 별도)
        }

        // 업무관리자(manager) — super/admin 계정만 제외, 그 외 전부 관리 (jin item 1 "super/admin만 못 건드림").
        //   ⚠️ manager 가 다른 manager 계정도 관리 가능 — jin 명세. admin/super 로의 승격은 컴포넌트 검증에서 차단.
        if ($this->isManager()) {
            return ! in_array($target->permission, ['super', 'admin'], true);
        }

        if ($this->role !== '관리') {
            return false;
        }

        // 관리 — 대상이 본인 팀 영업(영업 role + 일반 user permission)일 때만.
        return $target->permission === 'user'
            && $target->role === '영업'
            && in_array($target->id, $this->getManagedSalesmanUserIds(), true);
    }

    /** 이 관리가 담당하는 영업 user id 배열 (pivot ∪ 레거시 manager_user_id — getSubordinateSalesmanIds 와 동일 출처군). */
    public function getManagedSalesmanUserIds(): array
    {
        return $this->managedSalesmanUsers()->pluck('users.id')
            ->merge($this->subordinates()->pluck('id'))
            ->unique()
            ->values()
            ->all();
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
        return $this->isAdmin() || $this->isManager();
    }

    /**
     * 큐 14-2 보강 — 채권관리(/erp/receivables) 접근 권한.
     * 회의록 14 §누락 보강: 정산 user(미수금 회수 책임)와 관리 user(채권 위험 모니터링)도
     * 접근 가능해야 함. 회의록 §6 결정 #6과 동일 원칙 — 모니터링은 광범위 허용, 편집 권한은 분리.
     */
    public function canViewReceivables(): bool
    {
        return $this->isAdmin() || $this->isManager() || in_array($this->role, ['재무', '관리'], true);
    }

    /**
     * 큐 2.6 — 미입금 우회 승인 권한.
     * admin/super + 관리 role 가능. 영업/수출통관/재무 role은 차단.
     *
     * 2026-05-26 외부리뷰 감사 회의 결정 — B/L 100% 게이트 부족분 발급 승인을
     * 관리 role 도 수행. (회의록 §사용자결정 1: "[관리] 및 관리자의 승인")
     */
    public function canApproveUnpaidExport(): bool
    {
        return $this->isAdmin() || $this->isManager() || $this->role === '관리';
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
        return $this->isAdmin() || $this->isManager() || in_array($this->role, ['영업', '관리'], true);
    }

    /**
     * 회의확장씬 2026-05-22 — 항구 마스터 (/admin/ports) 접근/편집 권한.
     * 사용자 명세: 항구 마스터 [관리]도 볼 수 있고 수정 가능하도록.
     * canAccessAdmin 과 별개 (사용자 관리·기능 설정은 admin only 유지).
     */
    public function canManagePorts(): bool
    {
        return $this->isAdmin() || $this->isManager() || $this->role === '관리';
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
        return $this->isAdmin() || $this->isManager() || in_array($this->role, ['영업', '수출통관', '관리'], true);
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

    // 2026-06-30 — 관리↔영업 다대다 (영업 1명을 여러 [관리]가 함께 담당). manager_salesman pivot.
    /** 이 [관리] user 가 담당하는 영업 user 들. */
    public function managedSalesmanUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'manager_salesman', 'manager_user_id', 'salesman_user_id');
    }

    /** 이 영업 user 를 담당하는 [관리] user 들 (사용자관리 다중 배정 UI). */
    public function managers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'manager_salesman', 'salesman_user_id', 'manager_user_id');
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
        // 2026-06-30 — 다대다 pivot(주 출처) ∪ 레거시 단일 manager_user_id(이관 전·구 코드 호환).
        // UI 저장 시 manager_user_id = pivot 첫 멤버로 유지 → 항상 pivot ⊇ {manager_user_id} →
        // 합집합 = pivot (드리프트·제거 누락 없음). 영업 1명을 여러 [관리]가 담당.
        // 스코프 단일 출처라 차량/buyers/재고/알람/export 전부 자동 적용.
        $userIds = $this->managedSalesmanUsers()->pluck('users.id')
            ->merge($this->subordinates()->pluck('id'))
            ->unique();

        return Salesman::query()
            ->whereIn('user_id', $userIds)
            ->pluck('id')
            ->all();
    }

    /**
     * Review.md #3/#4 (2026-06-09) — 차량 접근 스코프 단일 출처.
     *
     * 영업 = 본인 담당 차량 / 관리 = 본인 팀(subordinate 영업) 차량 / admin·super·수출통관·재무 = 전체.
     * openEdit 의 인라인 가드와 동일 의미 — 변경(delete·save 편집)·문서 다운로드 등
     * "mutating·열람 엔드포인트"에서 매번 재인가하도록 공통 사용 (IDOR 차단).
     */
    public function canScopeVehicle(Vehicle $vehicle): bool
    {
        if ($this->isAdmin() || $this->isManager()) {
            return true;   // 업무관리자도 전 차량 (admin 등가 broad access)
        }

        if ($this->role === '영업') {
            return $vehicle->salesman_id === $this->salesman?->id;
        }

        if ($this->role === '관리') {
            return in_array($vehicle->salesman_id, $this->getSubordinateSalesmanIds(), true);
        }

        // 수출통관·재무 등은 전 차량 대상 업무 → 전체 허용.
        return true;
    }

    /**
     * 업무 알람 [확인]·열람 재인가 단일 출처 (IDOR 차단 — Review.md #26 패턴).
     * v1 = eta_clearance(target_role 수출통관). 볼 수 있는 사람 = admin·수출통관·관리(본인 팀).
     */
    public function canSeeAlarm(TaskAlarm $alarm): bool
    {
        // 매입 도착 알람(purchase_arrival, target_role='관리') — admin 전체 / 관리 본인 팀.
        if ($alarm->target_role === '관리') {
            if ($this->isAdmin() || $this->isManager()) {
                return true;
            }
            if ($this->role === '관리') {
                return $alarm->vehicle ? $this->canScopeVehicle($alarm->vehicle) : false;
            }

            return false;
        }

        // 수출통관 알람 (eta_clearance·shipping_requested).
        if ($alarm->target_role !== '수출통관' || ! $this->canAccessClearance()) {
            return false;
        }
        if ($this->isAdmin() || $this->isManager() || $this->role === '수출통관') {
            return true;
        }

        // 관리 — 본인 팀(subordinate 영업) 차량 알람만.
        return $alarm->vehicle ? $this->canScopeVehicle($alarm->vehicle) : false;
    }

    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->map(fn (string $name) => Str::of($name)->substr(0, 1))
            ->implode('');
    }
}
