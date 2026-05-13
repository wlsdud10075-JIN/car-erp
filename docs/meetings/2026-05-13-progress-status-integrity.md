# 📅 회의록: 진행상태 11단계 무결성 정책 재수립 + admin 미입금 우회 통합

- 일시: 2026-05-13
- 강도: **풀회의 6역할** (PO + Engineer + QA + Security + Ops + Specialist 데이터 무결성) + **Codex/Gemini 크로스체크**
- 안건 유형: 11단계 변경 + 권한 변경 + 마이그레이션 + 데이터 무결성
- 자동발동: `progress_status` 키워드 + `Vehicle::getProgressStatusAttribute()` 변경 (decision_protocol.md §3 자동 풀회의 트리거)
- 트리거: 큐 9(H1·H2) 검증 후 사용자가 "수출통관완료 자동 분류" 누수 직접 발견 → 11단계 전반 단일 트리거 누수 4건 식별

---

## 1. 배경 (사용자 발견 + 확장 분석)

### 1-1. 사용자 발견 케이스 (#5 수출통관완료)

UI에서 `is_export_cleared` 체크박스 **미체크** + `export_declaration_document`만 업로드 → 저장 시 자동으로 `수출통관완료` 분류. 사용자가 의도하지 않은 단계 점프.

### 1-2. 동일 패턴 11단계 전수 매핑 (확장)

| # | 단계 | 트리거 조건 | 분류 | 누수 평가 |
|---|---|---|---|---|
| 1 | 폐기 | `is_disposed=true` | 단일 | OK (의도된 단일) |
| 2 | 거래완료 | `dhl_request=true` | **단일** | H1 saving validator로 부분 보강. progress_status 분류 자체는 여전히 단일 |
| 3 | 선적완료 | `bl_document` 존재 | **단일 ⚠️** | bl_buyer_id / bl_number / bl_issue_date 모두 무시 |
| 4 | 선적중 | `bl_loading_location` 입력 | **단일 ⚠️** | vessel_name / container_number 등 무시 |
| **5** | **수출통관완료** | `export_declaration_document` 존재 | **단일 ⚠️ (사용자 발견)** | `is_export_cleared` 체크박스 평가 안 됨 — H2 saving validator와 이원화 |
| 6 | 수출통관중 | `export_buyer_id && shipping_date` | 이중 | forwarding_company는 안 봄 (수용 가능) |
| 7 | 판매완료 | `sale_price > 0 && sale_unpaid <= 0` | 이중 | OK |
| 8 | 판매중 | `sale_price > 0` | 단일 | OK (자연 의도) |
| 9 | 말소완료 | `is_deregistered && deregistration_document` | 이중 | OK |
| 10 | 매입완료 | `purchase_price > 0 && purchase_unpaid <= 0` | 이중 | OK |
| 11 | 매입중 | default | - | - |

**구조적 문제**:
- 큐 9 H1·H2는 saving validator에서만 차단 → `getProgressStatusAttribute()` 분류 로직 자체는 단일 트리거 그대로
- seed / console / raw create 등 검증 우회 경로로 잘못된 분류 가능
- UI에서도 체크박스 안 한 채로 저장하면 자동 점프

### 1-3. 동시 안건 — admin 미입금 우회

이전 회의에서 옵션 B (승인 흐름 + audit) 4역할 합의했으나 QA가 큐 10 H4와의 충돌·11단계 분류 모순 5건 지적해 HOLD. 본 회의에서 11단계 무결성 정책과 통합 검토.

---

## 2. 역할별 발언

### 📋 PO
**판정: 조건부 GO**

발언: SSANCAR 통관 워크플로우상 `export_declaration_document` 업로드(임시 보관)와 `is_export_cleared=true`(통관 완료 확정)는 사용자가 명확히 구분해 쓰는 두 단계. 단일 트리거(문서만)는 통관 담당자가 사전 검토용 파일을 올렸을 뿐인데 `수출통관완료`로 점프 → 정산·DHL·관리자 대시보드 KPI 모두 거짓 양성. 4건 모두 이중 트리거(체크박스 AND 문서/필드)로 강화 필요. admin 미입금 우회는 별도 `is_shipping_overridden` 플래그 + 라벨 suffix `(우회)`로 정상 분류에서 격리. 큐 2.6 신설 + 별도 PR 권장.

**다음 작업 큐 영향**: 큐 7 확장 / 큐 10 H3·H4·H5·H6 사이에 **신규 큐 2.6** 삽입. 큐 9(saving validator)와 동일 레이어 작업이라 병합 가능.

### ⚙️ Engineer
**판정: 조건부 GO**

발언: 4건 누수는 `getProgressStatusAttribute()` 단일 메서드 수정으로 차단 가능. 단 `guardAttachmentDeps`(H1·H2)와 의미 중첩되므로 **분류 메서드(읽기)는 관대 + UI save 게이트(쓰기)는 엄격** 원칙 유지. saving 이벤트엔 게이트 신규 추가 금지 — 시드/refreshCaches 무한루프 위험. admin 미입금 우회는 `unpaid_export_overrides` 별도 테이블 채택. progress_status는 우회 여부와 무관하게 실제 컬럼값으로만 분류(우회는 진입 허가일 뿐 분류 기준 아님).

- 공수: 4-5h (모델 30분 / 마이그 30분 / save() 게이트 확장 1h / 테스트 1.5h / 캐시 rebuild + 회귀 1h)
- 영향 파일: `app/Models/Vehicle.php` + `app/Models/UnpaidExportOverride.php`(신규) + 마이그 신규 + `vehicles/index.blade.php::save()` + `WorkflowGapTest.php`
- 캐시 rebuild: yes — `php artisan vehicles:rebuild-progress-cache` (이미 chunk 200 구현)
- 롤백 SQL 1줄: yes (`DROP TABLE unpaid_export_overrides;` + git revert + rebuild)

### 🧪 QA & Domain Integrity
**판정: 조건부 GO**

발언: 누수 4건 단일 트리거는 정합성 위험 — `is_export_cleared` 같은 명시 플래그 + 첨부 AND 조건으로 이중화 필요. 우선순위 재정의 시 `progress_status_cache` 전수 backfill + `DashboardActionCountsTest` 18카드 회귀 필수. UI는 차단(저장 거부) — 경고는 H1·H2 우회 재발 위험. admin 우회 차량은 별도 라벨 X, 정상 분류 유지 + `receivable_risk='critical'` flag로 표시. 헤이맨/카풀 거래완료는 별도 정의 필요.

- 도메인 공식 영향: `getProgressStatusAttribute` #2-#5 / `progress_status_cache` 전 row / `scopeAction` 5액션 / `receivable_risk_computed` critical 분기
- 회귀 시나리오: 수동 90분 / 영향 카드 29장 (18 관리자 + 11 파이프라인)
- Unit Test 신규 12: 누수 4건 차단 회귀 4 + 이중 조건 통과 4 + admin override critical 유지 1 + 채널 격리 2 + backfill 멱등성 1

### 🔒 Security & Compliance
**판정: 조건부 GO**

발언: 자동 분류 점프(체크박스 없이 통관완료 진입)는 처리 기록 보존 + 회계 책임 추적성 위반 — `progress_status` 11단계 전환은 RRN audit과 동일 등급. admin 미입금 우회는 별도 append-only `unpaid_export_overrides` (vehicle_id, user_id, reason min:20, ip, created_at) 필수 — `vehicles.is_export_cleared` 직접 우회 금지. UI는 **차단**(저장 거부 + validation 메시지). `canForceStageJump()` super 전용 신설 — admin은 미입금 우회만, 단계 역행/skip은 super만.

- 권한 미들웨어: 신규 불필요 — `canForceStageJump()` 메서드만 User에 추가
- audit log 2종 신규 제안:
  - ① `stage_transition_logs` (id, vehicle_id, from_stage, to_stage, trigger_type, user_id, reason, created_at) — 11단계 전환 전수
  - ② `unpaid_export_overrides` (append-only, update/delete 금지)

### 🚀 Ops & Deploy
**판정: 조건부 GO**

발언: 차량 50건 규모라 `vehicles:rebuild-caches`(이미 chunk 200 + Eager Load 완비)는 1초 내. `unpaid_export_overrides` 단일 테이블 추가는 nullable FK라 무중단. 단 마이그 직전 `mysqldump` + `vehicles` 테이블 스냅샷 의무. AWS Lightsail 미배포(로컬 XAMPP) → 운영 다운타임 0초. `progress_status_cache` 컬럼 값 자체는 enum 변경 없이 분기 로직만 변경이라 컬럼 스키마 무영향.

- 다운타임: 0초
- 백업 시점: 마이그 직전 `mysqldump car_erp > storage/backups/pre-queue26-$(date).sql` + `vehicles` 테이블 별도 CSV
- 캐시 rebuild: `php artisan vehicles:rebuild-caches` — 50건 < 1초
- 환경 의존성: 없음

### 🔧 Specialist (데이터 무결성)
**판정: 조건부 GO**

발언: 11단계 규칙 강화는 retroactive drift 필연. 특히 큐 10 H4 confirmed_snapshot 잠금과 충돌 — paid settlement 차량의 progress_status_cache가 재계산으로 바뀌면 정산 표시 vs 캐시 불일치. `progress_status_rule_version` 컬럼 도입 + 마감 row 고정 필수.

- retroactive risk: 마이그레이션 전 `--dry-run` 신설로 변경 예상 row 카운트 사전 산정
- 이관 정책 3-tier:
  1. `settlement.status=paid` 또는 `dhl_request=true` → **grandfather** (rule_version=1 고정, 재계산 skip)
  2. `sale_price>0` + 미마감 → **수동 검토 큐** (관리자 대시보드 "재분류 대상 N건" 알림)
  3. 매입중/매입완료 단계 → **자동 backfill** (안전)
- 단일 일괄 backfill NO-GO

---

## 3. 외부 모델 크로스체크

### 🌐 Codex
**판정: 부분 동의 (낮은 리스크 전제)**

지적:
1. 기존 row 중 `export_declaration_document`만 있고 `is_export_cleared=false`인 차량의 상태 다운그레이드 위험 (Specialist retroactive risk와 일치)
2. admin override append-only 권한/감사 모델 신설로 테스트 범위 확대
3. `progress_status_rule_version` 백필 dry-run 미실행 시 대시보드 숫자 변화
4. **`stage_transition_logs`는 이번 범위에 과함** — `unpaid_export_overrides` append-only + `progress_status_rule_version` + dry-run 결과 CSV 정도가 적정선
5. "리스크 0"이 아니라 "dry-run + 백업 + CSV 검증 전제의 낮은 리스크"

### 🌐 Gemini
**판정: 부분 동의 (보완 필요)**

지적:
1. **사용자 피드백 부재** — 경고 모달 거부 시 사용자는 '왜 상태가 안 바뀌는지' 직관적 인지 불가. UI에 '미충족 조건' 명시하는 Helper Text 필수
2. **Override 유효 범위** — `unpaid_export_overrides`가 '해당 단계'만인지 '차량 전체' 면제인지 불명확. **단계별 승인(Per-stage)** 으로 제한해야 리스크 전이 방지
3. **`stage_transition_logs`는 ERP 법적/운영적 책임 추적 필수 요소** — Codex와 반대 의견
4. **캐시 불일치** — DB 직접 수정 시 `progress_status_cache` 미갱신. rebuild 명령어 마이그레이션 후반부 강제 배치
5. **Suffix vs Flag** — Suffix는 통계 쿼리 파싱 비용 ↑. **'정상 값 + Flag'** 방식 권장 (데이터 모델링 우수)
6. 큐 2.6 분리(PR 분리) 롤백 안전성 권장

---

## 4. 만장일치 결정사항 (9건)

1. **누수 4건 이중 트리거화** — #2 거래완료 / #3 선적완료 / #4 선적중 / #5 수출통관완료
2. **분류 메서드(읽기) 관대 + save 게이트(쓰기) 엄격** 분리
3. **UI 저장 차단(throw)** — 경고 모달 거부
4. **`unpaid_export_overrides` 별도 append-only 테이블** — `vehicle_id` + `approved_by` + `reason text NOT NULL min:20` + `approved_at` + `ip_address` + `sale_unpaid_amount_snapshot`
5. **`progress_status_rule_version` 컬럼** + **Flag 방식** (`is_override_active`) — 마감 row 보호
6. **3-tier 이관 정책** — paid/dhl=grandfather / 미마감=수동 검토 / 매입=자동 backfill
7. **dry-run 명령** — `php artisan vehicles:rebuild-progress-cache --dry-run`
8. **mysqldump + vehicles CSV 백업 의무**
9. **12 unit test + 29카드 대시보드 회귀**

## 5. 외부 검증으로 새로 드러난 결정 (5건)

| # | 안건 | 권장안 (사용자 결정) |
|---|---|---|
| Q1 | `stage_transition_logs` 도입 | **이번엔 보류** — `unpaid_export_overrides`만. 큐 10 H4 통합 시 재검토 (Codex 입장) |
| Q2 | admin 우회 유효 범위 | **단계별(per-stage)** — `unpaid_export_overrides.stage` enum {`clearance`, `shipping`, `dhl`} (Gemini 신규 지적) |
| Q3 | 우회 차량 분류 | **정상 분류 + `is_override_active` Flag** (QA·Gemini 안) |
| Q4 | UI Helper Text | **필수** — 차단 시 "체크박스 ○○ 미체크 + 첨부 △△ 누락" 명확 메시지 (Gemini 지적) |
| Q5 | 큐 2.6 신설 vs 큐 9 확장 | **큐 2.6 신설** (PO·QA·Gemini 합의 — PR 분리로 롤백 안전성) |

---

## 🏁 최종 권고

**판정: 조건부 GO (Phase 1만 즉시 가능, Phase 2는 큐 10과 통합)**

### Phase 1 — 큐 2.6 (즉시 진행, ~5-6h)

커밋 4분할로 롤백 안전성 확보:

| 커밋 | 범위 | 시간 |
|---|---|---|
| **A1** | 마이그레이션 + 모델 (`unpaid_export_overrides` + `progress_status_rule_version` + `is_override_active` flag) | ~1h |
| **A2** | `getProgressStatusAttribute()` 누수 4건 이중 트리거화 + dry-run artisan 명령 + 3-tier backfill 실행 | ~2h |
| **A3** | UI 차단(throw) Helper Text 강화 + admin override 폼 + per-stage 승인 | ~1.5h |
| **A4** | Unit test 12 + 29카드 회귀 + WorkflowGapTest 확장 | ~1.5h |

### Phase 2 — 큐 10 H4와 통합 (별도 큐)

1. `stage_transition_logs` 도입 여부 (Security·Gemini vs Engineer·Specialist·Codex 의견 분기)
2. `confirmed_snapshot` JSON 잠금과 정책 통일

## 🚨 NO-GO 차단 조건

- backfill 마이그레이션 + 29카드 회귀 + 12 unit test **없이 머지 금지** (QA)
- dry-run **결과 검증 없이** 일괄 backfill 금지 (Specialist + Codex)
- `unpaid_export_overrides` 모델에 `updating`/`deleting` 이벤트 차단 누락 시 NO-GO (Security)

## 🔗 참조

- 관련 과거 회의록:
  - 2026-05-12 큐 2.5번 Critical 8건 1차 패치 (C3·C4·C5 강제 검증) — `2026-05-12-workflow-gap-analysis.md`
  - 2026-05-12 RRN 암호화 + 문서 다운로드 권한 (audit 패턴 참조) — `2026-05-12-rrn-encryption-document-permission.md`
- CLAUDE.md 차량 진행상태 11단계 (computed property)
- SKILLS.md §2 progress_status_cache / §9 action 파라미터 패턴 / §13 핵심 공식
- role기획보안_수정.md 큐 9·10 표 (H4 retroactive drift 관계)
- decision_protocol.md §3 자동 풀회의 트리거 / §6 11단계 변경 의무 점검
