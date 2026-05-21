# 📅 회의록: 회의확장씬 — 사용자 결정 확정 (12 안건)

- 일시: 2026-05-21
- 강도: 사용자 직접 결정 (라운드테이블 미발동)
- 안건 유형: 워크플로우 + 권한 + UI + 외부 통합 + 정산 (대규모)
- 자동발동 여부: no
- 결정 근거: 바탕화면 `회의확장씬.txt` + 사용자 실시간 답변 + 스크린샷 합의 (2026-05-21 21:20)

## 헤더 — 실무 현재 상황 (사용자 명세)

> "영업이 차량 등록하지 않음 (실수가 많아서). [관리]의 역할자가 차량등록부터 매입·판매·입금·수출통관·서류작업·거래완료까지 전체 운영 중. 실무자 의견 반영하여 [관리]를 전체 내용 아우르고 ERP 사용하게 먼저 만들고, 영업/재무/수출통관/관리는 추후 인원 증가에 따라 차차 균등 배분."

[관리] 권한 검증 결과 (2026-05-21 시점 코드 기준):
- `canConfirmFinanceTransfer()` — `super/admin/재무/관리` (`27bf24f` 확장)
- `canManagePaymentBreakdown()` — `super/admin/재무/관리`
- `canHandleDeregistration()` — `super/admin/영업/수출통관/관리`
- `canAccessClearance()` — `super/admin/수출통관/관리`
- `canAccessSettlement()` — `super/admin/재무/관리`
- `canApprove()` — `super/admin/관리`
- `canViewAdminDashboard()` — `super/admin/관리`
- `canViewReceivables()` — `super/admin/재무/관리`
- `canEditVehicleFinancialFields()` — `super/admin/영업` (관리는 admin 권한자로 자연 통과)

→ [관리] 전권 운영은 기존 권한 체계로 충족. 별도 안건 불필요.

---

## 안건 (12건)

| # | 안건 | 사용자 명세 |
|---|---|---|
| 1 | 워크플로우 v4 | 판매완료까지 같음 → 선적 → 통관 → B/L → 거래완료 |
| 2 | 바이어 사이드바 영업담당자별 솔팅 | [관리]는 본인 담당 영업의 바이어만, [영업]은 본인만 |
| 3 | 차량관리 바이어/영업 검색 | 필터바 select 조합 |
| 4 | 컨사이니 추가 + 선적 진입 가드 | 컨사이니 없으면 선적 진입 불가. 필드: 이름·ID·주소·전화·이메일 |
| 5 | 운임 직접 기입 (1건) | 기존 transport_fee 유지 |
| 6 | 잔금N+ UI 개선 + [관리] 즉시 승인 | 금액 div 축소 + 날짜 옆 + 비고. [관리] 즉시 추가 |
| 7 | 실시간 환율 스크래핑 | 네이버/다음 HTML, [관리] 대시보드 위젯 + 잔금N+ 자동 기입, fallback 수동 |
| 8 | 2차 정산 (status enum 확장) | 1차→2차→최종. settlements.status `secondary_pending`/`closed` |
| 9 | 기타비용 차량 등록 시 기본값 자동 기입 | 말소 24k/면허 11k/탁송 30k. **2차 정산에서 수정 가능** (사용자 정정 2026-05-21) |
| 10 | 차량관리 동적 컬럼 토글 (localStorage) | 모든 컬럼 노출/숨김 + 오름/내림차순 |
| 11 | [관리]별 영업담당자 배정 | users.manager_user_id (1관리:N영업) |
| 12 | 적립금 누적 + 사이드바 게이지 | 바이어별 누적, 클릭 시 상세 |

---

## 사용자 결정 사항 (안건별)

### 안건 1 — 워크플로우 v4 ✅ 완료
v4 cascade 5단계 (우선순위 높→낮):
1. `bl_document` → 거래완료
2. `bl_document AND is_export_cleared` → 통관완료 (실질 도달 불가)
3. `is_export_cleared AND bl_loading_location` → 통관중
4. `bl_loading_location AND export_declaration_document` → 선적완료
5. `bl_loading_location` → 선적중

**완료**: 커밋 `ace2a07`. 마이그 default 4 + Vehicle accessor + pipeline-strip 색 매핑 + dashboard 라벨.

### 안건 11 — [관리]별 영업담당자 배정
- 데이터 모델: `users.manager_user_id` FK (1관리 : N영업)
- 가장 단순 + 기존 User-Salesman 미러링과 자연
- [관리] 로그인 시 본인 담당 영업의 차량/바이어만 조회 (sales scoping)

### 안건 4 — 컨사이니 선적 가드
- `Vehicle::guardStageOrderForExport` 도메인 게이트
- `bl_loading_location` 입력하려면 `consignee_id` 필수
- 컨사이니 모델 필드 5종 (이름·ID(주민·여권·사업자)·주소·전화·이메일) 존재 확인 필요

### 안건 8 — 2차 정산
- `settlements.status` enum 확장 (`secondary_pending` / `closed`)
- 1차 정산 후 status='secondary_pending' 전환
- 2차 수정 후 status='closed' (최종 마무리)
- 사용자 답변: "1차하고, 2차에서 수정해서 최종 마무리로"

### 안건 9 — 기타비용 차량 등록 시 자동 기입 (사용자 정정)
- **차량 등록 시점** 기본값 자동 채움 (Vehicle::saving 훅)
- 말소 24,000원 / 면허 11,000원 / 탁송 30,000원
- 2차 정산 단계에서 변동분만 [관리]/[재무]가 수정
- 사용자 정정: "2차 정산때 자동기입된 금액에 변동이 있을 때 수정"

### 안건 7 — 실시간 환율 스크래핑 (외부 API 제외 대상 X)
- 네이버 또는 다음 환율 HTML 스크래핑
- [관리] 대시보드 위젯에 상시 표시
- 잔금N+ 추가 시 통화별 자동 기입
- HTML 깨지면 수동 입력 fallback
- 사용자 답변: "[관리]대시보드에 띄우고 잔금N+ 할때 그 시간에 떠 있는 환율을 자동으로 기입... 안될 시나 실패시에만 수동 기입"

### 안건 10 — 차량관리 동적 컬럼
- localStorage (브라우저별)
- 모든 컬럼 토글 + 오름/내림차순

### 안건 2 — 바이어 사이드바 영업담당자별 솔팅
- 사이드바 바이어 페이지에서 영업담당자별 솔팅
- [관리]는 본인 담당 영업의 바이어만
- [영업]은 본인 바이어만
- 사용자 답변: "사이드바에서 바이어가 있잖아? 거기에 담당자별 바이어를 솔팅"

### 안건 3 — 차량관리 바이어/영업 검색
- 필터바 select 조합 (기존 패턴 그대로)

### 안건 6 — 잔금N+ UI 개선
- 금액 div 축소 + 날짜 옆 나열 + 비고란 추가
- [관리] 즉시 승인은 `27bf24f`로 부분 충족 (재무 확정 권한)
- UI 개선만 남음

### 안건 5 — 운임 직접 기입
- 기존 `vehicles.transport_fee` 1건 그대로 유지
- UI 검토만

### 안건 12 — 적립금 사이드바 게이지
- 사이드바 바이어 페이지
- 게이지 표시 + 클릭 시 누적 상세
- 기존 `savings_statuses` 모델 활용

---

## 폐기/흡수 안건

| 출처 | 안건 | 처리 |
|---|---|---|
| 개발예정사항 #9 | 안건 J 본격 (v3 rule_version + 5곳 SQL) | 회의확장씬 #1 v4로 흡수 (`ace2a07`) → **폐기** |
| 개발예정사항 #10 | 안건 I manager tier | `27bf24f` 로 [관리]를 '중간 관리자'로 정의 → **폐기** |
| 개발예정사항 #4 | 적립금 명시 UI 분리 | 회의확장씬 #12에 흡수 |
| queue-status §22-B | savings_used 통합 | 회의확장씬 #12에 흡수 (사용자 SKIP 결정과 일치) |

---

## 제외 안건 (사용자 명시)

- NICE API 실연동 (외부 API)
- 알림톡 G4 (외부 API)
- 포워딩사 SMTP 메일 (외부 API)
- 1차/2차 AWS Lightsail 배포 (사용자 제외)

> 외부 API 제외 = NICE/알림톡/SMTP만. **환율 스크래핑(#7)은 포함** — HTML 스크래핑이라 API 키 불필요.

---

## 최종 작업 큐 (Phase 분할)

> /loop 시점: **나중에 어느 정도 진행 후** 사용자 결정. 박제 내용도 그때 갱신.

### Phase 1 — 운영 핵심 (P0) · 4건
| # | 안건 | 진입 위치 |
|---|---|---|
| 1-1 | 회의확장씬 #11 [관리]별 영업담당자 배정 | `app/Models/User.php` + 마이그(users.manager_user_id) + 차량/바이어 scoping |
| 1-2 | 회의확장씬 #4 컨사이니 추가 + 선적 가드 | `Vehicle::guardStageOrderForExport` + Consignee 모델 필드 확인/추가 |
| 1-3 | 회의확장씬 #8 2차 정산 (status enum 확장) | `app/Models/Settlement.php` + 마이그 (status enum 확장) |
| 1-4 | 회의확장씬 #9 기타비용 차량 등록 시 자동 기입 | `Vehicle::saving` 훅 + 마이그 default 값 (cost_deregistration=24000 등) |

### Phase 2 — 환율 + 핵심 UX · 4건
| # | 안건 | 진입 위치 |
|---|---|---|
| 2-1 | 회의확장씬 #7 실시간 환율 스크래핑 | `app/Services/ExchangeRateService.php` 신설 + [관리] 대시보드 위젯 + Scheduler(1시간 1회 정도) |
| 2-2 | 회의확장씬 #6 잔금N+ UI 개선 | `vehicles/index.blade.php` 잔금 영역 layout 재배치 |
| 2-3 | 회의확장씬 #10 차량관리 동적 컬럼 토글 | `vehicles/index.blade.php` + Alpine localStorage |
| 2-4 | 회의확장씬 #3 차량관리 바이어/영업 검색 | 필터바 select 추가 |

### Phase 3 — 사이드바 묶음 + 운임 · 2건
| # | 안건 | 진입 위치 |
|---|---|---|
| 3-1 | 사이드바 4건 묶음 | (a) 회의확장씬 #2 영업담당자별 솔팅 + (b) 회의확장씬 #12 적립금 게이지 + (c) 개발예정사항 #2 미수금 게이지 + (d) 개발예정사항 #12 audit_logs/document-access-logs UI |
| 3-2 | 회의확장씬 #5 운임 1건 유지 (UI 검토만) | `vehicles/index.blade.php` 매입/판매 탭 운임 영역 |

### 💤 보류
- 별건 2 G7 동시 편집 락 (인프라 의존)
- 3-A 그룹셋 정식 (사용자 HOLD)

---

## 체크리스트 + /loop 합의 (스크린샷 2026-05-21 21:20)

**하이브리드 패턴 (A + C) 채택**:
- **A. PHPUnit Feature 박제** — 매입→거래완료 전체 워크플로우 + 1차/2차 정산 + 환차익 시나리오 (자동 회귀)
- **C. Markdown 체크리스트** (`docs/verification/*.md`) — UI 시각·환율 위젯 등 사람 검증
- **B. Laravel Dusk 비추** — 운영 5명 환경 셋업·유지보수 부담 큼. 필요해지면 그때 추가

**/loop 시점**: 어느 정도 큐 진행 후 사용자 결정 (Phase별 자동 X). 박제 내용도 그때 갱신.

---

## 다음 세션 진입 명령

```
프로젝트 .md 4종 + 이 회의록 + project_extension_scene 메모리 자동 로드 확인.

다른 PC면:
  git checkout dev && git pull origin dev    # ace2a07 까지
  php artisan migrate                         # v4 default 마이그
  php artisan view:clear && php artisan cache:clear
  npm run build
  php artisan test                            # v4 회귀 확인

진행:
  Phase 1-1 [관리]별 영업담당자 배정 부터 진입.
  또는 사용자 선호 안건부터 시작.
```

---

## 📋 코드 현황 검증 (2026-05-21 세션 말, Explore agent 2회 + grep)

### 🟢 이미 구현 (변경 불필요)
- 사이드바 `wire:poll.30s` 30초 자동 갱신 (UX #6 완료 시점)
- [관리] 대시보드 위젯 토글 + localStorage 저장 패턴 → **#10 동적 컬럼에 재사용 결정**
- `canManagePaymentBreakdown` [관리] 즉시 확정 분기 → #6의 [관리] 즉시 승인 자동 충족
- **정산 서류비 표시** (`settlements/index.blade.php` L821~826, 2026-05-21 추가) — "서류비 (프리랜서 자동) - ₩50,000" 빨간색. **사용자 브라우저 확인 대기** (2026-05-21 시점 미확인)
- 차량관리 영업담당자 select 필터 이미 존재 (`wire:model.live`) → #11에 통합 (옵션 필터링도 같이)
- 잔금N+ row 비고(note) 필드 이미 존재 → #6은 layout 재배치만
- 바이어 슬라이드 패널 적립금 탭 (통화별 잔액 카드 + 거래내역) 이미 존재 → #12는 카드를 게이지로 변환만

### 🟡 부분 구현 — 공수 감소
| # | 현재 보유 | 남은 작업 |
|---|---|---|
| #3 | 영업담당자 select 있음 | 바이어 select 추가 + (#11과 통합으로 옵션 필터링) |
| #6 | 금액·날짜·비고 grid layout 있음 | div 너비 축소 + 날짜 옆 배치 (layout 재배치) |
| #12 | 적립금 탭 + 통화별 카드 있음 | 카드를 게이지로 변환 + 사이드바 진입 추가 |

### 🔴 신규 구현 필요
| # | 현재 상태 | 작업 |
|---|---|---|
| #11 | `users.manager_user_id` 없음. `vehicles.receivable_manager_id`는 채권 담당자라 별개 | 마이그 + relationship + scoping 가드 (차량/바이어/영업 select 옵션 필터링 통합) |
| #8 | `Settlement.status` 4종 (pending/calculating/confirmed/paid). 2차 정산 status 없음 | enum 확장 마이그 (`secondary_pending`/`closed`) + 1→2→최종 전환 로직 |
| #9 | cost_deregistration/license/towing 모두 `default(0)`. saving 훅 자동 채움 코드 없음 | 마이그 default 변경 (24k/11k/30k) + Vehicle::saving 훅 |
| #7 | `ExchangeRateService` 없음. config·exchange_rates 테이블·대시보드 위젯 모두 없음 | Service 신설 + Scheduler + 마이그 + [관리] 대시보드 위젯 + 잔금N+ 자동 기입 |
| #10 | 컬럼 토글 없음. 7컬럼 고정. 정렬 없음 | [관리] 대시보드 위젯 토글 패턴 재사용 (localStorage) |
| #4 | Consignee 9 필드 있으나 ID 필드 부재. guardStageOrderForExport에 consignee 가드 없음 | **ID 2컬럼 추가** (`id_type` enum: 주민/여권/사업자 + `id_value`) + guard 추가 |
| 사이드바 묶음 | audit_logs/document-access-logs 메뉴 없음 | 신규 메뉴 항목 + 영업담당자별 바이어 정렬 |

### 🆕 신규 발견 (회의 결정과 별개 — 인지만)
- `vehicles.receivable_manager_id` 컬럼 존재 (채권 담당자 배정용) — #11 `users.manager_user_id`와 의미 별개
- 오늘(2026-05-21) 추가된 `vehicles.incoterms` / `discharge_port_id` 컬럼 — CIPL 이식(`596635b`)으로 신규. 회의확장씬 작업과 충돌 없음

### 📌 사용자 결정 (코드 분석 후 추가)
1. **#10 동적 컬럼**: [관리] 대시보드의 기존 localStorage 위젯 토글 패턴 **재사용**
2. **#11 ↔ #3 통합**: **#11에 통합** — manager scoping (데이터+UI 필터+목록 scoping) 한 작업 단위. #3은 순수 바이어 select 추가
3. **#4 Consignee ID 패턴**: **A. 2컬럼** (`id_type` enum + `id_value`)
4. **#4 (정산 서류비)**: 코드는 이미 구현. 사용자 브라우저 확인 대기

---

## 참조

### 관련 과거 회의록
- `2026-05-19-workflow-revision-2week-deployment.md` — 안건 J·I·G NO-GO 원천. 본 회의확장씬으로 일부 사용자 직접 GO 전환
- `2026-05-19-group-revenue-progress-redesign.md` — 3-C NO-GO → 3-C-light 결정. v4는 별개 사용자 결정

### 코드 참조 (커밋)
- `ace2a07` — 회의확장씬 #1 v4 워크플로우 (방금 완료)
- `27bf24f` — [관리] 재무처리 권한 확장 (회의확장씬 헤더 의도 충족)
- `32e2d4c` / `596635b` — CIPL 이식 (오늘 별건 진행)
