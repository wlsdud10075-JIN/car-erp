# 📅 회의록: '관리' role 대시보드 뷰·권한 범위 (큐 14 사양 확정)

- 일시: 2026-05-14
- 강도: 풀 × 6 부서 (자동 풀회의 — role 변경 키워드 트리거)
- 안건 출처: 큐 14 구현 진입 전 사용자 발의 — "회의를 해서 뷰를 어떤걸 보여줄지 파악해보고 서브관리자에 맞는 승인, 결제, 업무파악 정도의 권한이 있어야 할것같아"
- 관련 사전 결정:
  - 큐 14 권한 모델 — **A 채택** (단일 `canApprove()` 권한, 일반 user 화면 + 승인 권한)
  - 회의록 v5.1 §9-1·§9-2·§9-3 (role 재정의 + 4 승인 액션 + 승인 흐름)

---

## 0. 안건

큐 14에서 '관리' role을 신설하면서 결정 필요한 6 사양:

1. 메인 뷰 URL
2. 승인 큐 UI 구조
3. 업무 파악 KPI
4. 재무 컬럼 편집 권한
5. `/admin/users` 접근
6. `/admin/*` 조회 권한

추가 결정:
- 한국어 PDF (RRN 포함) 다운로드 정책
- 큐 5 토글에 '관리' 탭 추가 여부 (2차 결정)

---

## 1. 부서별 의견 요약

### 📋 PO (조건부 GO)
- 메인 뷰: `/erp/dashboard` 재활용 + 헤더 배지
- 승인 큐: **별도 화면 + 위젯 병행** (4 액션이 컨텍스트 다름 — G2는 바이어, 정산은 settlements, 폐기/RRN은 vehicles, 50%는 통관 탭)
- KPI: 승인 대기 / 7일+ 정체 / 회계 위험 / 정산 진행률
- 재무 편집: **차단 유지** (등록자·승인자 분리가 감사상 깨끗)
- `/admin/users`: **차단** (자기참조 위험)
- `/admin/dashboard`: **읽기 전용 허용** (월별 매출 모르고 정산 승인 위험)
- 추가 발견: 운영 실무 누락 1건 — 바이어 신용한도 초과 거래 (큐 10 흡수 권고)

### ⚙️ Engineer (조건부 GO)
- 메인 뷰: `/erp/dashboard` + `roleView='관리'` 신규 탭 (~0.5h)
- 승인 큐: `approval_requests` 테이블 + Volt 화면 (~6-8h) — 큐 14-4 분할 필수
- KPI: 4 카드 (~2h, eager load 필수)
- 재무 편집: 1줄 추가 금지 (0.2h)
- `/admin/users`: 읽기 전용만, `canViewUsers()` 추가 (1h)
- `/admin/dashboard`: 신규 `ManagementMiddleware` (~2h)
- 분할 권고: **14-1 → 14-2 → 14-3 → 14-4** 4커밋
- 의존성: 14-4는 **큐 19 (자금 이체) 정의 후** payload 스키마 확정

### 🧪 QA & Domain Integrity (조건부 GO)
- 메인 뷰: SKILLS.md §9 action 파라미터 패턴 준수 — `pending_approvals` action 추가 후 카드↔SQL count 일치 검증
- 재무 편집: 큐 9 H1·H2 saving validator가 role 체크 없이 컬럼만 보면 우회 가능
- **정산 H4 snapshot 리스크**: 승인 commit 시점에 발동돼야 — 영업 변경 시점이 아닌 승인 트랜잭션 내 `Settlement::saving` 훅
- **audit_logs 2-actor 필요**: 단일 `user_id`만 있으면 영업의 원본 변경이 묻혀 책임소재 추적 불가 → **`requester_id` + `approver_id` 2 컬럼**
- **RRN 평문 시간창 0초**: 승인 대기 중에도 즉시 암호화 — mutator no-op 최적화(큐 11-4)와 일관 유지
- 수동 회귀 시나리오 7건 (15분)

### 🔒 Security & Compliance (조건부 GO)
- **재무 편집 NO-GO**: SoD (Segregation of Duties) 붕괴 — 본인 등록을 본인 승인 가능. 큐 2.5 C7 결정 유지 필수
- **`/admin/users` NO-GO**: 권한 escalation 위험 (자기를 admin으로 변경 가능)
- **`/admin/dashboard` read-only OK** (업무 파악 의도 부합)
- 문서 다운로드 권고: 한국어 PDF 3종 차단, 영문 PDF 2종 + Excel CIPL 허용 → **사용자 결정으로 기각** (큐 7 정책 유지 — 모든 user 다운로드 + 로그)
- `approve` alias 미들웨어 신규 신설 권고
- audit_logs에 permission 변경 이벤트 critical 레벨

### 🚀 Ops & Deploy (조건부 GO)
- 메인 뷰: 기존 캐시(Cache::remember 5분) 재사용, 신규 1-2명 부하 무시 가능
- 승인 알림: **Laravel Notifications DB driver + 동기 발송** (broadcast X — Lightsail 단일 인스턴스, WebSocket 인프라 없음)
- 폴링: `wire:poll.300s` (실시간성 불필요)
- audit_logs COUNT 윈도우: **`created_at >= now()-7d` + `(action, created_at)` 복합 인덱스**
- approval_requests 마이그레이션: MySQL 5.7+ online DDL, 다운타임 0초
- 알림 발송 실패 격리: try-catch + Log::warning (알림 실패가 승인 차단하면 안 됨)
- 큐 13 배포 시 추가 worker 불필요

### 🔧 Specialist (UX 설계자) (조건부 GO)
- 메인 뷰: `/erp/dashboard` + 큐 5 토글에 `[승인 큐]` 제3 토글 (B안 제안)
- 승인 큐: **인라인 카드 리스트** (.card 반복) + **페어 렌더** (§11) — 데스크탑 테이블 / 모바일 카드
- KPI: `grid grid-cols-2 xl:grid-cols-4` (§10) — 4 카드
- 재무 편집: **그레이 처리** (`bg-gray-50 text-gray-500 cursor-not-allowed`) + tooltip
- 알림 배지: 사이드바 메뉴 우측 `.pill-count` 인라인
- 승인 모달: 사유 textarea (거부 시 노출), 승인은 즉시 + 토스트
- 신규 디자인 시스템 추가: `.input-readonly` 유틸 1개

---

## 2. 사용자 최종 결정

### 6 결정 사양

| # | 사양 | 결정 |
|---|---|---|
| 1 | 메인 뷰 URL | `/erp/dashboard` 재활용 — 일반사용자 대시보드 `role='관리'` 분기 신규 |
| 2 | 승인 큐 UI | **별도 화면 `/erp/approvals` + 대시보드 위젯 (카운트 배지) 하이브리드** |
| 3 | KPI 4종 | 승인 대기 / 정산 대기 총액 / 채권 위험 / 정체 차량 (audit_logs 7일 윈도우) |
| 4 | 재무 컬럼 편집 | **차단 유지** (readonly + 그레이 처리, tooltip 안내) |
| 5 | `/admin/users` | **차단** (권한 escalation 차단) |
| 6 | `/admin/*` 조회 | `/admin/dashboard`만 read-only 허용. `/admin/settings`·기능 토글 차단 |

### 추가 결정

| 안건 | 결정 |
|---|---|
| 한국어 PDF (RRN 포함) 다운로드 | **허용** (큐 7 정책 유지 — 모든 user 다운로드 + `document_access_logs` 기록). Security 권고 기각 |
| 큐 5 [역할별] 토글에 '관리' 탭 추가 (2차) | **추가** — admin이 '관리 시각'으로 자유롭게 전환 가능. 영업/통관/정산/관리 4탭 |

### 사용자 의도 명시
- **관리자는 다 봐야** → 큐 5 토글에 '관리' 탭 포함 + admin이 자유롭게 전환
- 한국어 PDF 차단은 운영 정책 변경 부담 큼 → 큐 7 정책 유지

---

## 3. 핵심 도메인 리스크 (QA · Security 합의)

1. **audit_logs 2-actor 확장 필수**: 현재 `user_id` 단일 → `requester_id` + `approver_id` 2 컬럼으로 보강 (큐 11-4와 큐 14-4 사이 정합성)
2. **정산 H4 paid snapshot**: 승인 commit 트랜잭션 내에서 `Settlement::saving` 발동 → snapshot 1회 캡처 보장
3. **RRN 평문 시간창 0초**: 승인 대기 중에도 즉시 암호화. mutator no-op 최적화(큐 11-4) 그대로 사용
4. **SoD 분리**: 등록자 ≠ 승인자 (영업 입력 + 관리 승인). 재무 편집은 영업만 유지
5. **권한 escalation 차단**: `/admin/users` 라우트 미들웨어 `super-admin || admin` 명시 + `User::saving` 훅에서 권한 변경 시 `canManageUsers()` 재검증
6. **승인 큐 카운트 ↔ SQL 정합성**: SKILLS.md §9 action 파라미터 패턴 적용 — 카드 카운트 = `where('status','pending')` SQL count 일치

---

## 4. 큐 14 작업 분할 (4커밋)

### 14-1: role 정리 (~3h)
- '전체' role 삭제 (User.php ROLES 4개)
- 마이그레이션 — `UPDATE users SET role='관리' WHERE role='전체' AND permission='user'` (박전체 1명 → 관리로 변환). admin/super는 role 무관
- `canAccessSales/Clearance/Settlement` 각 role 단일화 ('전체' 분기 제거)
- `canEditVehicleFinancialFields` — 영업만 유지
- 큐 5 `erp/dashboard.blade.php` canToggleView — `'전체', '관리'` → `'관리'`만
- `admin/users/index.blade.php` 기본값 '전체' → '영업'
- 시더 (DatabaseSeeder) — 박전체 → 박관리 (role='관리')
- 테스트 fixture 20곳 (`role => '전체'`) → `role => '관리'` 일괄 변경 (admin은 role 무관이지만 일관성)
- 회귀 테스트 154 통과 유지

### 14-2: '관리' 뷰 + 권한 메서드 (~3h)
- `canApprove()` 메서드 신설 (super/admin/role='관리')
- `canViewAdminDashboard()` 메서드 신설
- 일반사용자 대시보드에 `role='관리'` 분기 추가 (큐 1 영업/통관/정산 패턴 따라)
- KPI 4 카드 (승인 대기 / 정산 대기 / 채권 위험 / 정체 차량)
- 큐 5 [역할별] 토글에 '관리' 4번째 탭 추가 (`roleView='관리'`)
- `/admin/dashboard` 라우트 미들웨어를 `admin` → `canViewAdminDashboard` 게이트로 전환
- 위젯 토글 저장(`saveLayout`) 시 admin 추가 검증

### 14-3: approval_requests 인프라 (~6-8h)
- 마이그레이션: `approval_requests` 테이블 (id, requester_id, approver_id nullable, target_type, target_id, action_type, payload json, status, reason, decided_at, created_at)
- `ApprovalRequest` 모델 (morphTo target) + index
- `/erp/approvals` Volt 화면 (페어 렌더 — 데스크탑 테이블 + 모바일 카드)
- 승인 모달 (사유 textarea, 거부 시 필수)
- 사이드바 알림 배지 (`.pill-count`)
- `approve` 미들웨어 신규 alias (`canApprove()` 게이트)
- Laravel Notifications DB driver (동기 발송)

### 14-4: 4 액션 게이트 + audit 2-actor (~5h, 실제 분할 진행)
- 4 액션 (G2 같은 바이어 / 정산 confirmed→paid / 차량 폐기·RRN 수정·B/L 수동 발행 / 50% 룰 예외)을 `approval_requests` 흐름으로 게이트
- audit_logs 마이그레이션 — `approval_request_id` 컬럼 추가 (FK to approval_requests, nullable)
- 정산 H4 snapshot 캡처가 승인 트랜잭션 내에서 발동 보장
- RRN 즉시 암호화 (승인 대기 중 평문 시간창 0초)
- **큐 19 (자금 이체)와 의존** — payload 스키마 결정 후 진행

**14-4 실제 진행 결과 (사용자 정정 반영)**:
- ✅ 14-4-1 audit 2-actor 링크 (commit `13dc823`)
- ✅ 14-4-2 settlement_pay 게이트 (commit `13dc823`)
- ⛔ 14-4-3 차량 폐기 게이트 — **롤백** (사용자 정정: 폐기는 SSANCAR 워크플로우상 없음. 폐기 컨셉 자체를 큐 17로 전체 제거 예정)
- ⛔ RRN 수정 / B/L 수동 발행 게이트 — 명세 모호로 미구현. 운영 발생 시 재검토
- 🔄 14-4-4 inter_buyer_overlap (G2 같은 바이어 미수 + 신규 거래) — **A안 채택 (사전 차단 + 관리 승인)**
- ⏭️ 50% 룰 예외 (unpaid_export_override) — 기존 큐 2.6 `unpaid_export_overrides` 테이블 시스템 유지. 별건

### 14-4-4 추가 — 같은 바이어 미수 + 신규 거래 흐름 결정

**채택 A안 (사전 차단 + 관리 승인)**:
1. 영업이 같은 buyer로 신규 차량 등록 시도 → 같은 buyer 미수 차량 있으면 ValidationException 차단
2. 영업이 [신규 거래 승인 요청] 버튼 → ApprovalRequest(target=Buyer, action=inter_buyer_overlap) 생성
3. 관리 /erp/approvals에서 승인 → ApprovalRequest.status='approved' + used_at NULL
4. 영업이 차량 등록 재시도 → 시스템이 active(approved + unused) ApprovalRequest 확인 → 통과
5. 등록 완료 시 ApprovalRequest의 used_at = now() 마킹 (1 승인 = 1 차량)

**미래 가능성 — D안 (A+B 혼합)** (회의록 §13 안건 3 결합):
- A안 흐름 유지하되 시스템 자동 룰 추가: `deposit_down_payment ≥ sale_price × 10%`이면 사전 차단 우회 (자동 룰 통과)
- 10% 미달이면 A안처럼 관리 승인 흐름 진입
- 채택 조건: 운영 중 "10% 충분히 받은 케이스도 매번 승인 요청 보내는 게 번거롭다" 피드백 누적 시 도입
- 구현 위치: Vehicle::saving 가드에 `deposit ≥ 10% ? skip approval check : require approval`
- 추정 추가 공수: ~1-2h
- 진행 시점: 큐 19 자금 이체와 함께 또는 별건

---

## 5. 운영·배포 영향

- 다운타임: 0초 (online DDL)
- audit_logs 폭증 대비: `created_at >= now()-7d` 윈도우 + 복합 인덱스
- 백업: 기존 큐 11-3 `db:backup` 자동 포함
- 큐 worker: 추가 불필요 (DB driver + 동기 발송)
- 인덱스: `approval_requests.(status, created_at)`, `approval_requests.(approver_id, status)`

---

## 6. 다음 큐 영향

- **큐 19 (자금 이체)**: payload 스키마 + 실행 권한 (canExecutePayment? — 큐 19에서 결정)
- **큐 10 (채권 무결성)**: PO 발견 — 바이어 신용한도 초과 거래 흡수 권고
- **큐 11-4 audit_logs**: 2-actor 확장 (14-4에서 처리)

---

## 🔗 참조

- `decision_protocol.md`
- `role기획보안_수정.md` §6 (대시보드 명칭) / §10 (구현 우선순위)
- `2026-05-14-3way-workflow-policy.md` §9-1·§9-2·§9-3 (role 재정의)
- CLAUDE.md (권한 3단계 + 대시보드 3종 명칭)
- SKILLS.md §9 (action 파라미터 패턴) / §10 (디자인 시스템) / §11 (모바일 반응형)
