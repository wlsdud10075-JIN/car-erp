<?php

use App\Models\User;
use App\Support\SearchTerm;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\Hash;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public string $search      = '';
    public string $permFilter  = '';

    /**
     * 🔎 권한 → 하위 묶음 칩 (jin 2026-10-26 «일반사용자에서 관리를 찾을 수 있게») — 일반사용자는 역할(영업·재무·수출통관·관리),
     *   최고관리자는 결재 직급(부장·전무·대표·미지정). 업무관리자·시스템관리자는 하위가 없다. 칩을 누르면 상위 권한까지 같이 잡힌다.
     *   ⚠️ 메서드명을 프로퍼티와 같게 짓지 말 것(§8 #32) — pickPerm/pickRole/pickTitle.
     */
    public string $roleFilter = '';

    public string $titleFilter = '';   // 부장·전무·대표 · '__none' = 직급 미지정
    #[Url] public int $perPage = 10;

    /** 검색 실행 — 버튼·Enter 로만 (jin 2026-09-08). 🚨 `search()` 로 짓지 말 것: 프로퍼티와 겹쳐 버튼이 죽는다(SKILLS §8 #32). */
    public function searchNow(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        if (! in_array($this->perPage, [10, 30, 50, 100], true)) {
            $this->perPage = 10;
        }
        $this->resetPage();
    }

    public bool   $showPanel   = false;
    public ?int   $editingId   = null;

    public string $name        = '';
    public string $email       = '';
    public string $phone       = '';
    public string $password    = '';
    public string $permission  = 'user';
    public string $role        = '영업';
    // 2026-05-21 — 정산 분류 (role='영업' 일 때만 입력). default 빈 값 → role=영업 신규 등록 시 명시 선택 강제.
    public string $type        = '';
    // 회의확장씬 #11 (2026-05-22) — 영업이 어느 [관리] 의 부하인지 배정. role='영업' 일 때만 의미.
    // 2026-06-30 — 영업 1명을 여러 [관리]가 담당(다대다). 선택된 [관리] user id 배열.
    public array $manager_user_ids = [];
    // 월정산 v3 (2026-10-09) — 최고관리자 결재 직급(부장/전무/대표). 빈값 = 종전(최종 승인자).
    public string $approval_title = '';

    public function pickPerm(string $perm): void
    {
        $this->permFilter = ($this->permFilter === $perm && $this->roleFilter === '' && $this->titleFilter === '') ? '' : $perm;
        $this->roleFilter = '';
        $this->titleFilter = '';
        $this->resetPage();
    }

    public function pickRole(string $role): void
    {
        if (! in_array($role, \App\Models\User::ROLES, true)) {
            return;
        }
        $this->permFilter = 'user';
        $this->roleFilter = ($this->roleFilter === $role) ? '' : $role;
        $this->titleFilter = '';
        $this->resetPage();
    }

    public function pickTitle(string $title): void
    {
        if ($title !== '__none' && ! in_array($title, \App\Models\User::APPROVAL_TITLES, true)) {
            return;
        }
        $this->permFilter = 'admin';
        $this->titleFilter = ($this->titleFilter === $title) ? '' : $title;
        $this->roleFilter = '';
        $this->resetPage();
    }

    #[Computed]
    public function users()
    {
        $authUser = auth()->user();
        // 2026-06-30 — role='관리'는 본인 팀 영업만 (escalation 방지). 검색/필터보다 먼저 무조건 스코프.
        //   ⚠️ 업무관리자(permission='manager')는 제외 — admin 등가로 super 외 전체 노출(팀 한정 아님).
        $isTeamManager = ! $authUser->isAdmin() && ! $authUser->isManager() && $authUser->role === '관리';

        return User::query()
            ->with('salesman')   // 🚪 퇴사 뱃지(퇴사일은 명부 칸이다, 2026-10-15)
            ->when(! $authUser->isSuperAdmin(), fn($q) => $q->where('permission', '!=', 'super'))
            ->when($isTeamManager, fn($q) => $q
                ->whereIn('id', $authUser->getManagedSalesmanUserIds())
                ->where('role', '영업')
                ->where('permission', 'user'))
            ->when(SearchTerm::of($this->search), fn($q) => $q->where(fn($q2) =>
                $q2->where('name', 'like', SearchTerm::like($this->search))
                   ->orWhere('email', 'like', SearchTerm::like($this->search))
                   // 전화번호 (jin 2026-10-14) — 저장값은 하이픈 포함(phoneMask)이라 숫자만 쳐도 걸리게 둘 다 본다.
                   ->orWhere('phone', 'like', SearchTerm::like($this->search))
                   ->orWhereRaw("REPLACE(phone, '-', '') like ?", ['%'.str_replace('-', '', SearchTerm::of($this->search)).'%'])
            ))
            ->when($this->permFilter, fn($q) => $q->where('permission', $this->permFilter))
            ->when($this->roleFilter !== '', fn($q) => $q->where('permission', 'user')->where('role', $this->roleFilter))
            ->when($this->titleFilter === '__none', fn($q) => $q->where('permission', 'admin')->whereNull('approval_title'))
            ->when($this->titleFilter !== '' && $this->titleFilter !== '__none', fn($q) => $q->where('permission', 'admin')->where('approval_title', $this->titleFilter))
            // 권한 등급순(시스템관리자 → 최고관리자 → 업무관리자 → 일반사용자) → 역할/직급 → 이름. 목록의 묶음 캡션이 이 순서를 전제한다.
            ->orderByRaw("CASE permission WHEN 'super' THEN 0 WHEN 'admin' THEN 1 WHEN 'manager' THEN 2 ELSE 3 END")
            ->orderByRaw("CASE WHEN permission = 'admin' THEN CASE approval_title WHEN '대표' THEN 0 WHEN '전무' THEN 1 WHEN '부장' THEN 2 ELSE 3 END ELSE 0 END")
            ->orderByRaw("CASE WHEN permission = 'user' THEN CASE role WHEN '관리' THEN 0 WHEN '재무' THEN 1 WHEN '수출통관' THEN 2 WHEN '영업' THEN 3 ELSE 4 END ELSE 0 END")
            ->orderBy('name')
            // 동점 tie-break (jin 2026-09-11) — 정렬키가 같은 행의 순서는 DB 가 안 정해 준다.
            //   페이지네이션에서 같은 행이 두 페이지에 나오거나 통째로 빠질 수 있다(SKILLS §8 #92).
            ->orderBy('id')
            ->paginate($this->perPage);
    }

    public function openCreate(): void
    {
        $this->resetValidation();
        $this->resetForm();
        $this->editingId = null;
        $this->showPanel = true;
    }

    public function openEdit(int $id): void
    {
        $user = User::findOrFail($id);

        // admin은 super 계정 편집 불가
        if (! auth()->user()->isSuperAdmin() && $user->isSuperAdmin()) {
            return;
        }
        // 2026-06-30 — 계정별 가드(IDOR/escalation 단일 출처). 관리는 본인 팀 영업만.
        abort_unless(auth()->user()->canManageUserAccount($user), 403);

        $this->editingId  = $id;
        $this->name       = $user->name;
        $this->email      = $user->email ?? '';
        $this->phone      = $user->phone      ?? '';
        $this->password   = '';
        $this->permission = $user->permission ?? 'user';
        $this->role       = $user->role       ?? '영업';
        $this->type       = $user->type       ?? '';
        $this->approval_title = $user->approval_title ?? '';
        $this->manager_user_ids = $user->managers()->pluck('users.id')->map(fn ($i) => (string) $i)->all();
        $this->showPanel  = true;
    }

    public function close(): void
    {
        $this->resetValidation();
        $this->showPanel = false;
        $this->editingId = null;
    }

    public function save(): void
    {
        // 2026-06-30 — 인가 먼저 (validate 전). jin: 관리는 super/admin 변경·생성 절대 불가(escalation 차단).
        $actor = auth()->user();
        // role='관리'(팀 관리자). permission='manager'(업무관리자)와 구분 — 후자는 클램프 대상 아님.
        $actorIsTeamManager = ! $actor->isAdmin() && ! $actor->isManager() && $actor->role === '관리';
        if ($this->editingId) {
            // 편집 대상 계정별 재인가 — editingId 클라이언트 주입 방어 (SKILLS #26 IDOR).
            $target = \App\Models\User::findOrFail($this->editingId);
            abort_unless($actor->canManageUserAccount($target), 403);
        } else {
            abort_unless($actor->canManageUsers(), 403);   // 신규: role='관리'는 일반 영업만 생성
        }
        if ($actorIsTeamManager) {
            // role='관리'는 일반 영업만 — 권한·role 서버 강제 (폼 변조로 super/admin 승격 무력화).
            $this->permission = 'user';
            $this->role = '영업';
        }

        // 🐛 업무관리자·최고관리자를 **바로** 만들 수 없던 원인 (jin 2026-10-08 제보, 월정산 v3 에 묶어 수정) —
        //   폼 기본값이 role='영업'·type='' 이라 권한만 바꾸면 역할·정산유형 칸은 숨겨지는데 검증(type required_if:role,영업)은
        //   그대로 걸려 저장이 멈췄고, 에러는 숨은 칸 안에 그려졌다. 「일반사용자(관리)로 먼저 바꾸면 된다」가 그 우회였다.
        //   권한이 일반사용자가 아니면 역할·정산유형·담당 배정을 정리한다 — 영업 role 로 저장되면 Salesman 행까지 생길 뻔했다.
        if ($this->permission !== 'user') {
            $this->role = '관리';
            $this->type = '';
            $this->manager_user_ids = [];
        }
        if ($this->permission !== 'admin') {
            $this->approval_title = '';
        }

        // 🔍 검차직원(jin 2026-10-14 «사용자관리에서 한번에 관리») — 로그인하지 않는 사람. 이메일·비밀번호 없이 등록된다.
        //   role 은 ROLES 밖이라 canAccessErp() 가 막고, 저장 시 Salesman(type=inspector)으로 미러된다.
        $isInspector = $this->permission === 'user' && $this->role === \App\Models\User::INSPECTOR_ROLE;
        if ($isInspector) {
            $this->type = '';
            $this->manager_user_ids = [];
        }

        $rules = [
            'name'       => 'required|string|max:100',
            'email'      => ($isInspector ? 'nullable' : 'required').'|email|max:255|unique:users,email' . ($this->editingId ? ",{$this->editingId}" : ''),
            'phone'      => 'nullable|string|max:20',
            'permission' => 'required|in:super,admin,manager,user',
            // 회의확장씬 #11 별건 fix (2026-05-22) — 옛 role 명('전체','통관','정산')이 stale 이었음.
            // ROLES const 변경(2026-05-20 안건 I — 정산→재무 / 통관→수출통관) 동기화 누락 — 재무·수출통관 user 편집 시 validation fail.
            'role'       => 'required|in:'.implode(',', \App\Models\User::ROLE_OPTIONS),   // ROLES + 검차(로그인 없음)
            // 2026-05-21 — role='영업' 일 때만 type 필수. 그 외 role 은 type 무시(null 저장).
            'type'       => 'nullable|in:employee,freelance|required_if:role,영업',
            // 2026-06-30 — 다중 관리 배정 (role='영업' 외엔 비움). pivot sync.
            'manager_user_ids' => 'array',
            'manager_user_ids.*' => 'integer|exists:users,id',
            'approval_title' => 'nullable|in:'.implode(',', \App\Models\User::APPROVAL_TITLES),
        ];

        if (! $this->editingId) {
            $rules['password'] = ($isInspector ? 'nullable' : 'required').'|string|min:8';
        } elseif ($this->password !== '') {
            $rules['password'] = 'string|min:8';
        }

        $this->validate($rules, [], [
            'name'  => __('user.field.name'),
            'email' => __('common.email'),
            'role'  => __('user.field.role'),
            'type'  => __('user.field.settlement_type'),
        ]);

        // admin은 super 권한 부여 불가
        if (! auth()->user()->isSuperAdmin() && $this->permission === 'super') {
            $this->addError('permission', __('user.no_super'));
            return;
        }
        // 업무관리자(manager)는 super/admin 권한 부여 불가 (자기 등급 이하 user/manager 만).
        if ($actor->isManager() && in_array($this->permission, ['super', 'admin'], true)) {
            $this->addError('permission', __('user.no_super'));
            return;
        }

        // 2026-05-21 — role='영업' 일 때만 type 저장. 그 외 role 은 null 로 정규화.
        $typeValue = $this->role === '영업' ? $this->type : null;
        // 2026-06-30 — 다중 관리 배정. pivot 이 스코프 단일 출처(getSubordinateSalesmanIds).
        // manager_user_id 컬럼은 primary(첫 선택)로 레거시 보존 — 호환용.
        $managerIds = $this->role === '영업'
            ? array_values(array_unique(array_map('intval', $this->manager_user_ids)))
            : [];
        $managerValue = $managerIds[0] ?? null;

        $data = [
            'name'              => $this->name,
            'email'             => $this->email !== '' ? $this->email : null,
            'phone'             => $this->phone ?: null,
            'permission'        => $this->permission,
            'role'              => $this->role,
            'type'              => $typeValue,
            'approval_title'    => $this->permission === 'admin' && $this->approval_title !== '' ? $this->approval_title : null,
            'manager_user_id'   => $managerValue,
            'email_verified_at' => now(),
        ];

        if (! $this->editingId) {
            // 검차직원은 비밀번호를 안 받는다 — 로그인 경로를 열지 않도록 무작위 해시를 심는다.
            $data['password'] = Hash::make($this->password !== '' ? $this->password : \Illuminate\Support\Str::random(40));
            $user = User::create($data);
        } else {
            if ($this->password !== '') {
                $data['password'] = Hash::make($this->password);
            }
            $user = User::findOrFail($this->editingId);
            $user->update($data);
        }

        // 2026-05-21 — User-Salesman 자동 미러링 (사용자 결정: 영업 user = 영업담당자).
        // 운영 흐름 자동화: 영업계정 등록 → 자동 영업담당자 row 생성 → 사이드바·차량 목록·캐시플로우에 즉시 노출.
        // 폼 내부에서만 호출 (Vehicle::saved 훅처럼 booted 에 박지 않음) — 테스트의 Salesman::create + user_id 패턴과 충돌 회피.
        // 🔀 2026-10-14 (jin) — 직원 명부 확장. 영업뿐 아니라 검차직원·일반사용자(관리/수출통관/재무)도 Salesman 으로 미러돼
        //   사내직원관리에 올라와 급여를 적는다. 단일 출처 = Salesman::mirrorTypeFor() — 백필 커맨드(salesmen:sync-staff)와 같은 판정.
        $mirrorType = \App\Models\Salesman::mirrorTypeFor($user, $typeValue);
        if ($mirrorType !== null) {
            $mirrorAttrs = [
                'name'      => $user->name,
                'email'     => $user->email,
                'phone'     => $user->phone,
                'type'      => $mirrorType,
            ];
            // 🚪 퇴사자(퇴사일 있음)는 활성을 되살리지 않는다 (jin 2026-10-15) — 전화번호 하나 고쳐도 퇴사가 풀리던 구멍.
            //    퇴사 처리·해제는 사내직원관리 편집 패널 한 곳에서만.
            $existingMirror = \App\Models\Salesman::where('user_id', $user->id)->first();
            if (! $existingMirror?->hasLeft()) {
                $mirrorAttrs['is_active'] = true;
            }
            \App\Models\Salesman::updateOrCreate(['user_id' => $user->id], $mirrorAttrs);
        } else {
            // 미러 대상이 아닌 계정(관리자 등급의 비영업)으로 전환 시: 기존 Salesman 비활성화 (차량 FK 보호 위해 삭제 X).
            \App\Models\Salesman::where('user_id', $user->id)->update(['is_active' => false]);
        }

        // 2026-06-30 — 다중 관리 배정 pivot sync (영업만; 그 외 role 은 비움).
        // 관리가 저장 시 본인은 항상 포함 — 자기 팀 보장 + 실수로 본인 access 제거 방지.
        if ($actorIsTeamManager) {
            $managerIds = array_values(array_unique(array_merge($managerIds, [$actor->id])));
        }
        $user->managers()->sync($managerIds);

        unset($this->users);
        // 2026-05-21 사용자 피드백 — 저장 시 시각 피드백 + 패널 자동 닫기.
        $this->dispatch('notify', message: __('user.saved'), type: 'success');
        $this->close();
    }

    public function delete(int $id): void
    {
        // 2026-06-30 — 삭제는 super/admin (+ 업무관리자 item 1). role='관리'는 팀 영업이라도 삭제 불가.
        $actor = auth()->user();
        abort_unless($actor->isAdmin() || $actor->isManager(), 403);

        $target = User::findOrFail($id);

        // 본인 계정 삭제 불가
        if ($target->id === auth()->id()) {
            session()->flash('error', __('user.self_delete'));
            return;
        }
        // admin/manager는 super 삭제 불가 / 업무관리자는 admin 삭제도 불가.
        if (! $actor->isSuperAdmin() && $target->isSuperAdmin()) {
            return;
        }
        if ($actor->isManager() && in_array($target->permission, ['super', 'admin'], true)) {
            return;
        }

        $target->delete();
        unset($this->users);
        session()->flash('success', __('user.deleted'));
    }

    private function resetForm(): void
    {
        $this->name = $this->email = $this->phone = $this->password = '';
        $this->permission = 'user';
        $this->role = '영업';
        $this->type = '';
        $this->approval_title = '';
        $this->manager_user_ids = [];
    }

    // 회의확장씬 #11 (2026-05-22) — [관리] role 사용자 목록 (영업 사용자 manager 배정 select 옵션).
    #[Computed]
    public function managers()
    {
        return User::where('role', '관리')->orderBy('name')->get(['id', 'name']);
    }

    /* ─── 휴가 대리 위임 (jin 2026-08-07) ───────────────────────────────
     * 목적 하나 — 휴가 갈 때 **담당 영업 N명을 한 번에** 대타에게 넘긴다.
     * 종전엔 영업을 한 명씩 열어 담당 [관리] 체크박스에 대타를 추가해야 했다.
     * 넘어가는 건 스코프뿐 — 승인 계단은 안 넘긴다(며칠이면 다녀와서 처리하면 된다, jin).
     */

    public string $delegateTo = '';
    public string $delegateUntil = '';

    /**
     * 🔀 2026-10-26 (jin «슈퍼 계정에서는 모든 게 다 보이고 다 되어야 해 … 관리 이상은 다 보이고 되어야») —
     *   ① 블록은 **관리 이상**(approvalRank ≥ 1) 전부에게 보인다(담당 영업이 0명이어도). 종전엔 「내 팀」이 있어야만 떴다.
     *   ② 업무관리자·최고관리자·super(rank ≥ 2)는 **남의 팀을 대신** 넘긴다 — 연차 쓴 [관리]가 자리에 없을 때.
     *   ③ 진행 중인 위임 목록을 보여 주고, rank ≥ 2 는 남의 위임도 끌 수 있다(본인 것은 종전대로).
     *   `delegateFrom` = 자리를 비우는 사람(팀 보유자). 비면 나.
     */
    public string $delegateFrom = '';

    /** 남의 팀을 대신 넘길 수 있나 — 업무관리자·최고관리자·super. [관리]는 본인 팀만. */
    public function canDelegateForOthers(): bool
    {
        return auth()->user()->approvalRank() >= 2;
    }

    /** 자리를 비울 수 있는 사람 = 담당 영업이 1명 이상인 관리 이상. 대신 걸 때 고르는 목록. */
    #[Computed]
    public function delegators()
    {
        return User::where('id', '!=', auth()->id())
            ->orderBy('name')->get(['id', 'name', 'permission', 'role'])
            ->filter(fn (User $u) => $u->approvalRank() >= 1 && $u->ownManagedSalesmanUserIds() !== [])
            ->values();
    }

    /** 진행 중인 위임 — rank ≥ 2 는 전부, 그 외는 내 것만. */
    #[Computed]
    public function activeDelegations()
    {
        return \App\Models\UserDelegation::with(['fromUser', 'toUser'])
            ->effective()
            ->when(! $this->canDelegateForOthers(), fn ($q) => $q->where('from_user_id', auth()->id()))
            ->orderBy('ends_at')->get();
    }

    /** 지금 내가 켜 둔 위임 (효력 있는 것 1건). */
    #[Computed]
    public function myDelegation()
    {
        return \App\Models\UserDelegation::with('toUser')
            ->effective()
            ->where('from_user_id', auth()->id())
            ->first();
    }

    /** 내가 넘길 담당 영업 — 0명이면 넘길 게 없으니 카드 자체를 안 보여준다. */
    #[Computed]
    public function myTeamNames(): array
    {
        return User::whereIn('id', auth()->user()->ownManagedSalesmanUserIds())
            ->orderBy('name')->pluck('name')->all();
    }

    /** 대타 후보 = 나 말고 ERP 관리 라인([관리]·업무관리자·대표). 영업은 남의 팀을 받을 자리가 아니다. */
    #[Computed]
    public function delegateCandidates()
    {
        return User::where('id', '!=', auth()->id())
            ->orderBy('name')->get(['id', 'name', 'permission', 'role'])
            ->filter(fn (User $u) => $u->approvalRank() >= 1)
            ->values();
    }

    /**
     * 위임 시작 — 대타 1명 + 복귀 예정일.
     * 복귀일 필수: 없으면 켜둔 채 잊어서 스코프가 무기한 열린다(jin 결정 3).
     */
    public function startDelegation(): void
    {
        $to = (int) $this->delegateTo;
        $from = (int) ($this->delegateFrom ?: auth()->id());
        $until = trim($this->delegateUntil);

        abort_unless(auth()->user()->canManageUsers(), 403);
        // 후보 목록 밖의 id 주입 방어 (SKILLS §8 #26) — 스코프를 넓히는 기능이라 대상 검증이 핵심.
        // 남의 팀을 대신 넘기는 건 rank ≥ 2 만, 그리고 「팀 보유자 목록」 안의 사람만 (SKILLS §8 #26 — id 는 클라이언트가 준다).
        if ($from !== (int) auth()->id()) {
            abort_unless($this->canDelegateForOthers() && $this->delegators->contains('id', $from), 403);
        }
        // 후보 목록 밖의 id 주입 방어 (SKILLS §8 #26) — 스코프를 넓히는 기능이라 대상 검증이 핵심. 자기 자신에게는 못 넘긴다.
        //   대신 걸 때는 나(관리자)도 맡을 수 있다 — 후보 목록은 「나 제외」라 따로 허용한다.
        $toOk = $this->delegateCandidates->contains('id', $to) || ($from !== (int) auth()->id() && $to === (int) auth()->id());
        abort_unless($to !== $from && $toOk, 403);

        if ($until === '' || strtotime($until) === false) {
            session()->flash('error', __('user.delegation.need_end_date'));

            return;
        }
        if ($until < now()->toDateString()) {
            session()->flash('error', __('user.delegation.past_end_date'));

            return;
        }

        $d = \App\Models\UserDelegation::updateOrCreate(
            ['from_user_id' => $from, 'to_user_id' => $to],
            ['is_active' => true, 'ends_at' => $until, 'created_by' => auth()->id()],
        );
        \App\Models\AuditLog::recordEvent($d, 'delegation_activated');

        $this->delegateTo = $this->delegateUntil = $this->delegateFrom = '';
        unset($this->myDelegation, $this->activeDelegations);
        session()->flash('success', __('user.delegation.activated', ['name' => $d->toUser?->name ?? '']));
    }

    /** 위임 종료 — 본인 것만(id 주입 방어). 복귀일 전에 돌아왔을 때. */
    public function stopDelegation(int $id): void
    {
        $d = \App\Models\UserDelegation::findOrFail($id);
        // 본인 것, 또는 rank ≥ 2(남의 것도 — 연차 간 사람 대신). id 주입 방어는 그 둘로.
        abort_unless($d->from_user_id === auth()->id() || $this->canDelegateForOthers(), 403);

        $d->update(['is_active' => false]);
        \App\Models\AuditLog::recordEvent($d, 'delegation_deactivated');

        unset($this->myDelegation, $this->activeDelegations);
        session()->flash('success', __('user.delegation.deactivated'));
    }
}; ?>

<div wire:poll.30s>
@if(session('success'))
<div x-data="{show:true}" x-show="show" x-init="setTimeout(()=>show=false,3000)"
     class="fixed top-4 right-4 z-50 rounded-lg bg-green-600 px-4 py-3 text-sm text-white shadow-lg">
    {{ session('success') }}
</div>
@endif
@if(session('error'))
<div x-data="{show:true}" x-show="show" x-init="setTimeout(()=>show=false,4000)"
     class="fixed top-4 right-4 z-50 rounded-lg bg-red-600 px-4 py-3 text-sm text-white shadow-lg">
    {{ session('error') }}
</div>
@endif

<div class="flex h-full flex-col gap-4 p-3 md:p-6">

{{-- 헤더 --}}
<div class="flex items-center justify-between">
    <div>
        <h1 class="text-xl font-bold text-gray-800">{{ __('user.title') }}</h1>
        <p class="mt-0.5 text-xs text-gray-500">{{ __('user.total', ['count' => $this->users->total()]) }}</p>
    </div>
    <div class="flex items-center gap-2">
        <select wire:model.live="perPage" class="input-filter">
            <option value="10">{{ __('common.per_page', ['count' => 10]) }}</option>
            <option value="30">{{ __('common.per_page', ['count' => 30]) }}</option>
            <option value="50">{{ __('common.per_page', ['count' => 50]) }}</option>
            <option value="100">{{ __('common.per_page', ['count' => 100]) }}</option>
        </select>
        <button wire:click="openCreate" class="btn-primary">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            {{ __('user.create_btn') }}
        </button>
    </div>
</div>

{{-- 휴가 대리 위임 — 관리 이상 전부에게 (jin 2026-10-26). rank ≥ 2 는 남의 팀을 대신 넘기고, 진행 중인 위임을 전부 본다. --}}
@if(auth()->user()->approvalRank() >= 1)
<div class="card-tight">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <div class="section-header !mb-0">
            <span class="section-dot bg-violet-500"></span>
            <span class="section-title">{{ __('user.delegation.title') }}</span>
        </div>
        <span class="text-xs text-gray-400">
            @if(count($this->myTeamNames) > 0){{ __('user.delegation.team', ['names' => implode(', ', $this->myTeamNames)]) }}@else{{ __('user.delegation.no_team') }}@endif
        </span>
    </div>

    {{-- 진행 중 — 누가 → 누구에게 · 언제까지 · [종료] --}}
    @if($this->activeDelegations->isNotEmpty())
    <div class="mt-2 flex flex-wrap gap-1.5">
        @foreach($this->activeDelegations as $d)
        <span class="inline-flex items-center gap-1.5 rounded-full bg-green-50 px-2.5 py-1 text-xs text-green-800">
            <span class="font-semibold">{{ $d->fromUser?->name ?? '-' }}</span> → {{ $d->toUser?->name ?? '-' }}
            <span class="text-green-600">· {{ optional($d->ends_at)->format('Y-m-d') ?? '-' }}</span>
            @if($d->from_user_id === auth()->id() || $this->canDelegateForOthers())
            <button wire:click="stopDelegation({{ $d->id }})" class="ml-1 rounded px-1 text-[11px] text-green-700 hover:bg-green-100">{{ __('user.delegation.stop') }}</button>
            @endif
        </span>
        @endforeach
    </div>
    @endif

    {{-- 걸기 — (대신 걸 사람) · 맡길 사람 · 복귀일 · 시작. 폭은 래퍼 div 로(.input-base 가 width:100%). --}}
    @php $fromId = (int) ($delegateFrom ?: auth()->id()); @endphp
    <div class="mt-2 flex flex-wrap items-center gap-2">
        <span class="w-full text-xs text-gray-500 sm:w-auto sm:flex-1">{{ $this->canDelegateForOthers() ? __('user.delegation.note_for_others') : __('user.delegation.my_note') }}</span>
        @if($this->canDelegateForOthers())
        <div class="w-full sm:w-44">
            <select wire:model.live="delegateFrom" class="input-base">
                <option value="">{{ __('user.delegation.from_me') }}</option>
                @foreach($this->delegators as $f)
                    <option value="{{ $f->id }}">{{ $f->name }}</option>
                @endforeach
            </select>
        </div>
        @endif
        <div class="w-full sm:w-44">
            <select wire:model="delegateTo" class="input-base">
                <option value="">{{ __('user.delegation.to_ph') }}</option>
                @foreach($this->delegateCandidates as $c)
                    @if($c->id !== $fromId)<option value="{{ $c->id }}">{{ $c->name }}</option>@endif
                @endforeach
                @if($fromId !== (int) auth()->id())<option value="{{ auth()->id() }}">{{ auth()->user()->name }} {{ __('user.me') }}</option>@endif
            </select>
        </div>
        <div class="w-full sm:w-40">
            <input type="date" data-date wire:model="delegateUntil" placeholder="{{ __('user.delegation.until_ph') }}" class="input-base" />
        </div>
        <button wire:click="startDelegation" class="btn-primary text-xs">{{ __('user.delegation.start') }}</button>
    </div>
</div>
@endif

{{-- 필터 --}}
<div class="card-tight flex flex-col gap-2">
  <div class="flex flex-wrap items-center gap-2">
    {{-- 🔎 검색은 **버튼(또는 Enter)으로만** 돈다 (jin 2026-09-08).
                 타이핑마다 서버 왕복이면 `LIKE '%…%'` 가 글자 수만큼 돈다 — 이 ERP 의
                 다른 검색칸(차량·재고·바이어·정산 등)이 전부 이 형태다. --}}
    <div class="w-full sm:w-72">
        <input wire:model="search" wire:keydown.enter="searchNow" type="text" placeholder="{{ __('user.search_ph') }}" class="input-base" />
    </div>
    <button wire:click="searchNow" class="btn-search">{{ __('common.search') }}</button>
  </div>
    {{-- 🔎 권한 → 하위 묶음 칩 (jin 2026-10-26). 누르면 그 묶음만, 다시 누르면 해제. --}}
    @php
        $chipOn = 'rounded-full px-2.5 py-0.5 text-xs font-semibold bg-gray-800 text-white';
        $chipOff = 'rounded-full px-2.5 py-0.5 text-xs font-medium bg-gray-100 text-gray-600 hover:bg-gray-200';
        $subOn = 'rounded-full px-2 py-0.5 text-[11px] font-semibold bg-violet-600 text-white';
        $subOff = 'rounded-full px-2 py-0.5 text-[11px] font-medium bg-violet-50 text-violet-700 hover:bg-violet-100';
    @endphp
    <div class="flex w-full flex-wrap items-center gap-x-3 gap-y-1.5 sm:w-auto">
        <button type="button" wire:click="pickPerm('')" class="{{ $permFilter === '' ? $chipOn : $chipOff }}">{{ __('user.all_perm') }}</button>
        <span class="flex flex-wrap items-center gap-1 rounded-lg bg-gray-50 px-1.5 py-1">
            <button type="button" wire:click="pickPerm('user')" class="{{ $permFilter === 'user' && $roleFilter === '' ? $chipOn : $chipOff }}">{{ __('nav.permission.user') }}</button>
            @foreach(\App\Models\User::ROLES as $r)
            <button type="button" wire:click="pickRole('{{ $r }}')" class="{{ $roleFilter === $r ? $subOn : $subOff }}">{{ __('domain.role.'.$r) }}</button>
            @endforeach
        </span>
        <button type="button" wire:click="pickPerm('manager')" class="{{ $permFilter === 'manager' ? $chipOn : $chipOff }}">{{ __('nav.permission.manager') }}</button>
        <span class="flex flex-wrap items-center gap-1 rounded-lg bg-gray-50 px-1.5 py-1">
            <button type="button" wire:click="pickPerm('admin')" class="{{ $permFilter === 'admin' && $titleFilter === '' ? $chipOn : $chipOff }}">{{ __('nav.permission.admin') }}</button>
            @foreach(\App\Models\User::APPROVAL_TITLES as $t)
            <button type="button" wire:click="pickTitle('{{ $t }}')" class="{{ $titleFilter === $t ? $subOn : $subOff }}">{{ $t }}</button>
            @endforeach
            <button type="button" wire:click="pickTitle('__none')" class="{{ $titleFilter === '__none' ? $subOn : $subOff }}">{{ __('user.title_none_chip') }}</button>
        </span>
        @if(auth()->user()->isSuperAdmin())
        <button type="button" wire:click="pickPerm('super')" class="{{ $permFilter === 'super' ? $chipOn : $chipOff }}">{{ __('nav.permission.super') }}</button>
        @endif
    </div>
</div>

{{-- 목록 — 두 열 행 (jin 2026-10-26 «가로로 한 화면 다 차지 → 둘로 나눠서 한 행에 나란히 … 좀 더 이쁘게»).
     권한 등급순으로 정렬돼 오므로 등급이 바뀌는 자리에 캡션 한 줄. 폰은 한 열. --}}
@php
    $permMeta = [
        'super' => ['badge-purple', 'bg-purple-100 text-purple-700'],
        'admin' => ['badge-blue', 'bg-blue-100 text-blue-700'],
        'manager' => ['badge-teal', 'bg-teal-100 text-teal-700'],
        'user' => ['badge-gray', 'bg-gray-100 text-gray-600'],
    ];
    $prevPerm = null;
@endphp
<div class="card-tight !p-0 overflow-hidden">
  <div class="grid grid-cols-1 lg:grid-cols-2 lg:gap-x-6">
    @forelse($this->users as $u)
    @php
        [$permBadge, $avatarCls] = $permMeta[$u->permission ?? 'user'] ?? $permMeta['user'];
        $permLabel = __('nav.permission.'.($u->permission ?? 'user'));
        $sub = $u->permission === 'user' ? ($u->role ? __('domain.role.'.$u->role) : null) : ($u->permission === 'admin' ? $u->approval_title : null);
        $showCaption = $prevPerm !== $u->permission; $prevPerm = $u->permission;
    @endphp
    @if($showCaption)
    <div class="bg-gray-50 px-3 py-1 text-[11px] font-semibold uppercase tracking-wide text-gray-500 lg:col-span-2">{{ $permLabel }}</div>
    @endif
    <div class="flex cursor-pointer items-center gap-3 border-b border-gray-100 px-3 py-2.5 hover:bg-violet-50/40" wire:click="openEdit({{ $u->id }})">
        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-sm font-semibold {{ $avatarCls }}">{{ mb_substr($u->name, 0, 1) }}</span>
        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-x-1.5 text-sm font-medium text-gray-800">
                <span>{{ $u->name }}</span>
                @if($sub)<span class="text-xs font-normal text-gray-500">· {{ $sub }}</span>@endif
                @if($u->id === auth()->id())<span class="text-[10px] text-gray-400">{{ __('user.me') }}</span>@endif
                {{-- 🚪 퇴사 (jin 2026-10-15) — 퇴사 처리·해제는 사내직원관리에서. 여기선 보여만 준다. --}}
                @if($u->salesman?->hasLeft())
                <span class="badge badge-gray text-[10px]" title="{{ __('user.left_hint') }}">{{ __('salesman.left_badge', ['date' => $u->salesman->left_at->format('Y-m-d')]) }}</span>
                @endif
            </div>
            <div class="truncate text-xs text-gray-500" title="{{ $u->email ?? '' }}">
                {{ $u->email ?? '-' }}
                {{-- 전화번호 (jin 2026-08-04) — 비면 알림톡이 로그도 없이 조용히 skip 되므로 미입력을 눈에 띄게 --}}
                <span class="mx-1 text-gray-300">·</span>
                @if(filled($u->phone))<span>{{ $u->phone }}</span>@else<span class="text-amber-600" title="{{ __('user.phone_missing_hint') }}">{{ __('user.phone_missing') }}</span>@endif
            </div>
        </div>
        <div class="flex shrink-0 flex-col items-end gap-0.5">
            <span class="badge {{ $permBadge }}">{{ $permLabel }}</span>
            <span class="text-[11px] text-gray-400" title="{{ __('user.col.last_login') }}">{{ $u->last_login_at ? $u->last_login_at->format('Y-m-d H:i') : __('user.col.last_login').' '.__('user.no_login') }}</span>
            {{-- 삭제는 super/admin + 업무관리자(super/admin 대상 제외). role='관리'는 삭제 불가 --}}
            @if($u->id !== auth()->id() && (auth()->user()->isAdmin() || (auth()->user()->isManager() && ! in_array($u->permission, ['super', 'admin'], true))))
            <button wire:click.stop="delete({{ $u->id }})"
                    wire:confirm="{{ __('user.delete_confirm', ['name' => $u->name]) }}"
                    class="text-[11px] text-red-400 hover:text-red-600">{{ __('common.delete') }}</button>
            @endif
        </div>
    </div>
    @empty
    <div class="py-12 text-center text-sm text-gray-400 lg:col-span-2">{{ __('user.empty') }}</div>
    @endforelse
  </div>
</div>

<div>{{ $this->users->links() }}</div>

</div>

{{-- ══ 슬라이드 패널 ══ --}}
@if($showPanel)
<div class="fixed inset-0 z-40 bg-black/40" wire:click="close"></div>
<div class="fixed inset-y-0 right-0 z-50 flex w-full flex-col bg-white shadow-2xl sm:w-[480px]">

    {{-- 헤더 --}}
    <div class="flex items-center justify-between border-b px-5 py-4">
        <h2 class="text-base font-bold text-gray-800">{{ $editingId ? __('user.edit_title') : __('user.create_title') }}</h2>
        <button wire:click="close" class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>

    {{-- 검증 에러 요약 (2026-07-09 jin — 저장 눌러도 반응 없어 보이던 문제: 실은 검증 실패라 상단에 노출) --}}
    @if($errors->any())
    <div class="border-b border-red-200 bg-red-50 px-5 py-3">
        <p class="text-xs font-semibold text-red-700">{{ __('common.error_box_title') }}</p>
        <ul class="mt-1 space-y-0.5 text-xs text-red-600">
            @foreach($errors->all() as $msg)
            <li>· {{ $msg }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    {{-- 폼 --}}
    <div class="flex-1 overflow-y-auto px-5 py-5 space-y-4">

        {{-- 기본 정보 --}}
        <div class="space-y-3">
            <div>
                <label class="label-base">{{ __('user.field.name') }} <span class="text-red-500">*</span></label>
                <input wire:model="name" type="text" class="input-base" placeholder="{{ __('user.field.name_ph') }}" />
                @error('name')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
            </div>
            @php $formIsInspector = $permission === 'user' && $role === \App\Models\User::INSPECTOR_ROLE; @endphp
            {{-- 검차직원(로그인 없음)은 이메일·비밀번호 칸을 숨긴다 — 2026-10-14 --}}
            @if(! $formIsInspector)
            <div>
                <label class="label-base">{{ __('common.email') }} <span class="text-red-500">*</span></label>
                <input wire:model="email" type="email" class="input-base" placeholder="{{ __('user.field.email_ph') }}" />
                @error('email')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
            </div>
            @endif
            {{-- 2026-05-21 — 전화번호 + Alpine phoneMask 자동 하이픈 (한국 4 패턴) --}}
            <div>
                <label class="label-base">{{ __('user.field.phone') }}</label>
                <input wire:model="phone" type="tel" class="input-base"
                       placeholder="01012345678"
                       maxlength="13"
                       x-on:input="$event.target.value = $store.phoneMask.apply($event.target.value); $wire.phone = $event.target.value" />
                @error('phone')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
            </div>
            @if(! $formIsInspector)
            <div>
                <label class="label-base">
                    {{ __('user.field.password') }}
                    @if($editingId)<span class="text-xs text-gray-400">{{ __('user.field.password_edit_note') }}</span>@else<span class="text-red-500">*</span>@endif
                </label>
                <input wire:model="password" type="password" class="input-base" placeholder="{{ __('user.field.password_ph') }}" autocomplete="new-password" />
                @error('password')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
            </div>
            @endif
        </div>

        {{-- 권한 / 역할 --}}
        <div class="space-y-3 border-t pt-4">
            <div class="section-header">
                <span class="section-dot bg-violet-500"></span>
                <span class="section-title">{{ __('user.field.sec_perm') }}</span>
            </div>
            {{-- 권한·role 드롭다운은 super/admin 만. 관리는 일반 영업만 생성/수정 → 서버에서 user·영업 강제. --}}
            @if(auth()->user()->canAccessAdmin())
            <div>
                <label class="label-base">{{ __('user.field.perm') }} <span class="text-red-500">*</span></label>
                <select wire:model.live="permission" class="input-base">
                    @if(auth()->user()->isSuperAdmin())
                    <option value="super">{{ __('user.perm_opt.super') }}</option>
                    @endif
                    {{-- admin 부여는 super/admin 만 (업무관리자는 admin 못 만듦) --}}
                    @if(auth()->user()->isAdmin())
                    <option value="admin">{{ __('user.perm_opt.admin') }}</option>
                    @endif
                    <option value="manager">{{ __('user.perm_opt.manager') }}</option>
                    <option value="user">{{ __('user.perm_opt.user') }}</option>
                </select>
                @error('permission')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
            </div>
            {{-- 월정산 v3 — 최고관리자만 결재 직급. 비우면 종전(최종 승인자). --}}
            @if($permission === 'admin')
            <div>
                <label class="label-base">{{ __('user.field.approval_title') }}</label>
                <select wire:model="approval_title" class="input-base text-gray-800">
                    <option value="">{{ __('user.field.approval_title_none') }}</option>
                    @foreach(App\Models\User::APPROVAL_TITLES as $t)
                    <option value="{{ $t }}">{{ $t }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-gray-400">{{ __('user.field.approval_title_note') }}</p>
                @error('approval_title')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
            </div>
            @endif
            @endif
            @if($permission === 'user')
            @if(auth()->user()->canAccessAdmin())
            <div>
                <label class="label-base">{{ __('user.field.role') }} <span class="text-red-500">*</span></label>
                <select wire:model.live="role" class="input-base">
                    @foreach(App\Models\User::ROLE_OPTIONS as $r)
                    <option value="{{ $r }}">{{ __('domain.role.'.$r) }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-gray-400">{{ $role === \App\Models\User::INSPECTOR_ROLE ? __('user.field.inspector_note') : __('user.field.role_note') }}</p>
                @error('role')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
            </div>
            @endif
            {{-- 2026-05-21 — role='영업' 일 때만 정산 분류 노출. 거래완료 시 자동 정산의 settlement_type 결정 --}}
            @if($role === '영업')
            <div>
                <label class="label-base">{{ __('user.field.settlement_type') }} <span class="text-red-500">*</span></label>
                <select wire:model="type" class="input-base">
                    <option value="">{{ __('user.field.type_select') }}</option>
                    @foreach(App\Models\User::TYPES as $key => $label)
                    <option value="{{ $key }}">{{ __('salesman.type.'.$key) }} {{ __('salesman.type_suffix.'.$key) }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-gray-400">{{ __('user.field.type_note') }}</p>
                @error('type')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
            </div>
            {{-- 2026-06-30 — 영업을 담당할 [관리] 다수 배정 (다대다). 여러 명 체크 가능 --}}
            <div>
                <label class="label-base">{{ __('user.field.manager') }}</label>
                <div class="mt-1 max-h-40 space-y-1 overflow-y-auto rounded-lg border border-gray-200 p-2">
                    @forelse($this->managers as $m)
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" wire:model="manager_user_ids" value="{{ $m->id }}" class="accent-primary" />
                        {{ $m->name }}
                    </label>
                    @empty
                    <p class="text-xs text-gray-400">{{ __('user.field.manager_none') }}</p>
                    @endforelse
                </div>
                <p class="mt-1 text-xs text-gray-400">{{ __('user.field.manager_note') }}</p>
                @error('manager_user_ids')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
                @error('manager_user_ids.*')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
            </div>
            @endif
            @endif
        </div>

    </div>

    {{-- 푸터 --}}
    <div class="flex items-center justify-end gap-2 border-t px-5 py-4">
        <button wire:click="close" class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">{{ __('common.cancel') }}</button>
        <button wire:click="save" class="btn-primary" wire:loading.attr="disabled" wire:target="save">
            <span wire:loading.remove wire:target="save">{{ __('common.save') }}</span><span wire:loading wire:target="save">{{ __('common.saving') }}</span>
        </button>
    </div>

</div>
@endif

</div>
