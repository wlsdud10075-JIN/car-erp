# 📅 회의록: 워크플로우수정.txt 2주 배포 총망라 (10건)

- 일시: 2026-05-19
- 강도: 풀회의 (/회의 슬래시)
- 안건 유형: 마이그 + 외부 API + 권한·role + 진행상태 + 대시보드 (대규모)
- 자동발동 여부: yes (사용자 명령어 호출)
- 발동 부서: PO + Engineer + QA + Security + Ops + Specialist [B 데이터 무결성 + E 승인·권한 + F 회계·정산]

## 안건

사용자가 바탕화면 `워크플로우수정.txt` + `Q1~5.txt`로 정리한 SSANCAR 개정 워크플로우 — **2주 후 배포** 총망라.

| # | 안건 | 사용자 명세 |
|---|---|---|
| **A** | NICE API 실연동 | "차량 조회 → 제원 자동 기입" (사용자 명시: "워크플로우 안정화 후 AWS에서 가져옴") |
| **B** | 마스터 데이터 신규 등록 | "(없으면 생성해야함)" — Q1 답변: 마스터 데이터 신규 등록 (해석 B) |
| **C** | 말소 [everyone] 권한 | "어떤 부서든 할 수 있음" — 영업만 → 4 role 누구나 |
| **D** | 진행상태 정의 정정 | Q2 답변: "앞전 상황 끝나야 판매중·판매완료" = 현행 우선순위 그대로. 문서 정정만 |
| **E** | 판매 필수 항목 강화 | "판매일, 바이어, 통화, 판매가, 환율은 반드시 기입" |
| **F** | 입금 흐름 분리 | 정상 잔금 = 재무 전용 입력 (해석 B) + "추가 입금" = 영업 요청→재무 승인 (Q3 분리 채택) |
| **G** | 50% 룰 H1 게이트 완화 | Q4 해석 A: 입금률 ≥ 50% → 통관 자유, < 50% → admin 승인 |
| **H** | 수출통관 사이드바 메뉴 | "사이드바에 따로 없는데 만들어서 연결" |
| **I** | role 재구성 | 정산→재무 / 통관→수출통관 + [관리] 권한 대폭 확장 (admin 잠금 해제 + 50% 룰 락 해제 + 모든 일반사용자 권리). Q5 권한 계층 확정 |
| **J** | 거래완료 = B/L 단독 | "B/L 서류 업로드되면 거래완료로 마무리" (3-C 재요청) |

알림톡(G4·별건 1)은 본 회의 제외 (사용자 명시 추후 재회의).

---

## 💬 부서별 발언 (Sonnet 4.6) — 요약

### 📋 PO
**판정**: 종합 조건부 GO (A HOLD / B·C·E·F·G·H·I 조건부 GO / D GO / J NO-GO)

핵심: 변경 1·4(매입처 계좌 영업 + 매입완료 자동 트리거)는 이미 코드 구현. 안건 I·C·G·H는 I 선행 시 공수 절감. **NICE API 안정 연동 시점**: 큐 22-A~C + I 완료 + AWS 배포 후 1~2일 안정화 → **배포 후 1~2주**.

### ⚙️ Engineer
**판정**: 종합 조건부 GO (A·F·I HOLD / J NO-GO / 나머지 조건부 GO)

핵심: **role 재구성(I) 영향 범위 정량화** — `'정산'`·`'통관'` 문자열 사용처 = 15개 파일 66건 (테스트 11 케이스). 명칭 변경만 4~6h + grep-replace + 마이그. 공수 종합표:
- 즉시 가능 (D 0.5h + C 1h + H 0.5~3h + E 1~2h + B 0~4h) = 3~10h
- 큐 22-A 15h
- G 2~4h + 22-B 6~8h
- I 명칭만 4~6h
- 합계 30~50h (2주 내 가능)

### 🧪 QA & Domain Integrity
**판정**: 종합 조건부 GO (J NO-GO / I·E·F·G HOLD / 나머지 GO 또는 조건부)

핵심: **깨질 자동 테스트 표 정량화**
- I role 재구성: **11 케이스** (`WorkflowGapTest` L189·L602, `DashboardActionCountsTest` L186·L200·L210, `PaymentConfirmationServiceTest` L42, `InterVehicleTransferServiceTest` L46, `InterVehicleTransferVoidTest` L31, `TransfersIndexTest` L32, `PipelineStripTest` L91·L120)
- J 거래완료: **5~8 케이스** (`WorkflowGapTest` L417~444, `DashboardActionCountsTest` L74~79, `G3ReceivableClassificationTest` L102~112)
- G 50% 룰: **G1BlLockTest 전체 8건** + WorkflowGapTest C5 3건
- E 판매 required: **makeVehicle 6파일** 헬퍼 선행 PR 필수

회귀: 자동 3분 + 브라우저 30분 + G 20분 = **55분**

### 🔒 Security & Compliance
**판정**: 종합 HOLD (G·I 양쪽 NO-GO, 2건 선결)

**3 NO-GO 시나리오**:
- 안건 G NO-GO: 큐 22-A 미완료 상태에서 50% 룰 완화 시 `deposit_down_payment` 등 4컬럼 confirmed 필터 없이 즉시 분자 차감 → 부정 입금 조작 + 통관 우회 경로 확장
- 안건 I NO-GO: [관리] role에 `canAccessAdmin()` 범위 확장 시 큐 21 unlock 권한 + `canApprove()` 동시 보유 → 단일 사용자가 승인 + unlock + 회계 컬럼 수정 + 재무 확정 모두 가능 → 큐 19-F~21 SoD collapse

해소 조건: 4건 — AUDITED_COLUMNS 4컬럼 추가 + canConfirmFinance 가드 + 매입 탭 read-only + 큐 22-C 착수 전 처리

### 🚀 Ops & Deploy
**판정**: 종합 조건부 GO (다운타임 합산 < 1초 DB + 5~10분 수동 회귀 점검창)

**2주 일정 시뮬레이션**:
```
Day 1~2: D(0.5h) + C(2h) + H(3h) + E(3h) = 8.5h
Day 3~7: 큐 22-A(15h)
Day 8~9: G(5h) + 22-B(8h)
Day 10~11: I role 재구성(8h + 회귀)
Day 12~13: J(보류 → 3-C-light 1~2h) + B(3h)
Day 14: F(5h) 마무리
```

**NICE API 권고 시점**: ① 큐 22 시리즈 완료 ② AWS 배포 완료 ③ 운영 1~2일 클릭 안정화 ④ NICE_API_KEY 발급 → `.env` 주입 → `config:cache` → 실연동. **배포 직전 NO**.

### 🔧 Specialist [B. 데이터 무결성]
**판정**: 종합 조건부 GO (J NO-GO 재확인)

**retroactive 위험 순위**:
1. J — v1 grandfather row 자동 강등 → paid Settlement 진행상태 소급 변경 + confirmed_snapshot 회계 오류 잠금
2. F — 기존 `confirmed_at=NULL + payment_date IS NOT NULL` PBP row가 신규 분자 A안 필터에서 제외 → 미지급액 급증
3. I — `canAccessSettlement()` 문자열 불일치 시 재무 role 사용자 접근 전면 차단
4. E — DB CHECK 적용 시 기존 nullable row 마이그 실패

**cache rebuild 필요**: J만 필수, F 1회 권장, 나머지 불필요

### 🔧 Specialist [E. 승인·권한 정책]
**판정**: **NO-GO** (안건 I 큐 14 SoD 핵심 위반)

**4개 SoD 차단선 동시 위반 매핑**:
| 사용자 요청 | 코드 현황 | 위반 |
|---|---|---|
| admin 잠금 해제 | `canAccessAdmin()` super+admin 전용 | 위반 |
| 50% 룰 락 해제 | `canApproveUnpaidExport()` admin 전용 | 위반 |
| 모든 일반사용자 권리 | `canConfirmFinanceTransfer()` 관리 명시 차단 | **위반 (핵심)** |
| 모든 일반사용자 권리 | `canEditVehicleFinancialFields()` 관리 명시 차단 | 위반 |

**대안**: `permission=manager` tier 신설 (admin과 user 사이) — SoD 유지하며 사용자 의도 충족. `requester_id === approver_id` 차단으로 self-approve 별도 가드

**신규 발견 결함**: `nice_reg_owner_rrn` `canEditVehicleFinancialFields()` silent restore 미포함 → 4 role 모두 RRN 입력 가능

### 🔧 Specialist [F. 회계·정산 감사]
**판정**: 안건 F·G 조건부 GO / 안건 I·J NO-GO

**I NO-GO 사유**: 관리 role에 `canConfirmFinance()` 허용 시 `canApprove()` 동시 보유 → 승인 요청 본인 + 본인 승인 + 본인 재무 확정 경로 완성. 큐 19-F SoD 구조적 차단 단일 role에서 재현. LEDGER_LOCK 전체 무력화 위험.

**F 조건**: paid Settlement 이후 추가 입금 발생 시 snapshot 재캡처 vs 별도 `post_paid_entries` 필드 정책 명시 필요.

**G 조건**: `unpaid_ratio` null (환율 0 외화) 시 **게이트 강제 차단** (통과 불가). admin 우회 경로 신설 시 `bl_unlock_approved_by` snapshot 캡처 필수.

---

## 🧩 중간 회의 결과 (Opus 4.7 1차 취합)

### 부서별 판정 매트릭스

| 안건 | PO | Eng | QA | Sec | Ops | B | E | F |
|---|---|---|---|---|---|---|---|---|
| **A** NICE API | HOLD | HOLD | 조건부 | 조건부 | 조건부 | GO | (간략) | GO |
| **B** 마스터 | 조건부 | 조건부 | GO | GO | GO | GO | (간략) | GO |
| **C** 말소 everyone | 조건부 | 조건부 | 조건부 | 조건부 | GO | GO | 조건부 | GO |
| **D** 정의 정정 | GO | 조건부 | GO | GO | GO | GO | (간략) | GO |
| **E** 판매 required | 조건부 | GO | **HOLD** | GO | GO | 조건부 | (간략) | GO |
| **F** 입금 분리 | 조건부 | HOLD | **HOLD** | 조건부 | 조건부 | 조건부 | 조건부 | 조건부 |
| **G** 50% 룰 | 조건부 | 조건부 | HOLD | **NO-GO** | 조건부 | 조건부 | (간략) | 조건부 |
| **H** 사이드바 | 조건부 | GO | GO | 조건부 | GO | GO | (간략) | (간략) |
| **I** role 재구성 | 조건부 | HOLD | **HOLD** | **NO-GO** | 조건부 | 조건부 | **NO-GO** | **NO-GO** |
| **J** 거래완료 | **NO-GO** | **NO-GO** | **NO-GO** | GO | 조건부 | **NO-GO** | (간략) | **NO-GO** |

### 합의된 핵심 결정

**🔴 NO-GO 다수 합의**:
- **안건 I 권한 확장** (Security + Spec-E + Spec-F NO-GO + QA HOLD) — 큐 14·19-F·21 SoD 4개 차단선 동시 위반
- **안건 J 거래완료=B/L** (PO + Engineer + QA + Spec-B + Spec-F 5부서 NO-GO) — 직전 회의 결정 유지
- **안건 G 50% 룰 완화** (Security NO-GO) — 큐 22-A 선행 필수

**🟢 GO/조건부 GO 합의**:
- 안건 D 문서 정정 (CLAUDE.md·SKILLS.md v2 갱신)
- 안건 B 마스터 등록 (이미 코드 가능, 문서 정정)
- 안건 H 사이드바 (차량 목록 필터 링크 권장)
- 안건 C 말소 [everyone] (`canHandleDeregistration()` 신설 + RRN silent restore 보강)
- 안건 E 판매 required (`makeVehicle()` 헬퍼 6파일 선행 PR)
- 안건 I 명칭만 (정산→재무 / 통관→수출통관, 권한 확장 X)
- 안건 F 입금 분리 (큐 22-A 완료 후 `TYPE_DEPOSIT_ADD_REQUEST` 신설)

**🟡 배포 후 별도 큐**:
- 안건 A NICE API — 큐 22 완료 + AWS 안정화 1~2주 후
- 안건 J → 3-C-light (라벨 조정만)
- 안건 I 권한 확장 → manager tier 별도 회의 (사외이사 Gemini "2주 전 코드 반영 금지")

### 신규 발견 결함 4건

| # | 결함 | Codex P | Gemini P |
|---|---|---|---|
| 1 | `nice_reg_owner_rrn` silent restore 미포함 (RRN 4 role 입력 가능) | P0 must-fix | P4 |
| 2 | `purchase_seller_*` 4컬럼 `AUDITED_COLUMNS` 미포함 (직전 회의 발견) | P1 (금액 컬럼 시 P0) | P2 |
| 3 | `scopeAction('purchase_unpaid')` SQL `confirmed_at IS NOT NULL` 누락 | P0 must-fix | **P1 최우선** |
| 4 | `is_deregistered` `audit_logs` 추적 부재 | P0 must-fix | P3 |

### 부서 간 충돌

1. **안건 I 대안**: Spec-E "permission=manager tier 신설" vs 다수 "단순 NO-GO 또는 명칭만" → 사외이사 시각 필요
2. **안건 E validation**: Spec-B "application layer만 (DB CHECK 미적용)" vs Gemini 권고 "DB CHECK 함께" → 사외이사 시각 필요

### 안건의 본질 3줄
1. 사용자가 인식한 워크플로우 변경 중 **안건 1·4(매입처 계좌 + 매입완료 자동 트리거)는 이미 코드 구현** — 문서 정정 + 신규 결함 4건 보강이 본 회의 핵심 작업
2. **안건 I "관리 권한 확장" 사용자 의도**가 큐 14·19-F·21 SoD 4 차단선 동시 위반 — `permission=manager` tier 대안이 SoD 보존하며 의도 충족하나 2주 일정 컨텍스트에서 신중 결정 필요
3. **2주 내 처리 가능**: D + B + H + C + E + F + G + I 명칭만 + 큐 22-A·B + 신규 결함 4건 = 약 40~50h. **J·NICE API·I 권한 확장은 배포 후 별도**

---

## 🌐 사외이사 의견 (Codex / Gemini)

### [Codex]

**결론: Item I 현안 그대로 Self NO-GO**.

**1. 놓친 리스크**:
- Manager가 admin unlock + 50% override를 가지면 사고 시 "승인자 = 수정자 = 예외처리자"가 되어 감사 추적 방어 불가
- Queue 14/19-F/21 기준 무너지면 이후 기능마다 예외 role 분기 폭증
- NICE/RRN 안정화 전 권한 확대는 개인정보 변경 책임 소재를 흐림

**2. 안건 I 외부 판단**: SAP/NetSuite/Odoo/QuickBooks식 권한 패턴은 보통 **role명보다 permission/approval matrix 중심**. 따라서 Spec-E의 `permission=manager` 중간 tier가 맞음. **단, manager는 "업무 범위 확대"이지 `canAccessAdmin` + `canEditVehicleFinancialFields` + `canConfirmFinanceTransfer` + `canApproveUnpaidExport` 동시 보유가 아니어야 함**.

**3. 2주 우선순위**:
- **P0**: NICE 배포 안정화, RRN 잠금, 감사로그, 결제 confirmed_at SoD
- **P1**: Item J 3-C-light, B/D 문서, I rename
- **P2**: manager tier 설계만 반영, 확장은 배포 후

**4. Latent defects must-fix**: **1·3·4 P0**. 1 RRN editable은 개인정보 치명. 3 unpaid SQL은 재무 승인 우회. 4 deregistered audit 부재는 책임 추적 실패. 2 AUDITED_COLUMNS는 P1 (금액/채무 컬럼이면 P0 승격).

**5. Self NO-GO 조건**:
- (a) Item I role 확장 강행
- (b) Item E를 DB CHECK 없이 앱 검증만으로 처리
- (c) Queue 22-A 전 50% rule H1 gate 완화

### [Gemini]

**1. 내부 실기 리스크**: 1인 개발 체제에서 배포 2주 전 'Manager' 티어 신설은 검증되지 않은 보안 구멍(Security Hole) 리스크 매우 큼. SoD 4개 차단선 동시 건드는 시스템 복잡도를 기하급수적으로 높여 배포 초기 금융 사고 유발 가능. **안건 E의 DB 제약 조건 생략은 데이터 오염 시 사후 복구 불가능한 치명적 사각지대**.

**2. 안건 I 외부 시각**: SAP/NetSuite 글로벌 standard는 '계층(Tier)'이 아닌 **'직무(Task)' 기반 RBAC**. 특정 직책에 모든 권한 몰아주는 것은 현대 감사 표준에 반함. **Manager는 '기안'과 '승인' 중 하나만 수행**하거나, 최소한 본인이 기안한 건을 직접 승인하지 못하게 하는 'Self-Approval' 차단 로직이 반드시 동반.

**3. 2주 전 우선순위**: 신규 기능(Manager) 추가를 **전면 중단**하고 '데이터 무결성'과 '감사 추적' 안정화에 집중. 1인 개발 환경에서 검증되지 않은 권한 계층 신설은 향후 유지보수의 재앙.

**4. 신규 결함 우선순위**:
- ① scopeAction SQL 누락 (미지급금 산출 오류 직결 — **최우선**)
- ② 판매자 정보 감사 누락 (자금 투명성)
- ③ 말소 상태 추적
- ④ RRN 입력 복구

**5. Self NO-GO**:
- (a) **배포 전 Manager 티어 코드 반영 금지**: 기존 큐-SoD 설계 논리적 붕괴 위험
- (b) **DB 제약 없는 Application 검증 단독 채택 금지**: 버그 발생 시 데이터 정합성 최후 보루 사라짐
- (c) **NICE API 즉시 실무 적용 금지**: 안정화 기간 없는 자동화는 대량 오입력 사고

### 사외이사 합의
- **안건 I 권한 확장 NO-GO 유지** (양쪽 합의)
- **manager tier 자체는 배포 후로 이연** (Gemini 강조, Codex P2 동일)
- **신규 결함 P0 = ①③④** (Codex 동일, Gemini 우선순위 비슷)
- **안건 E DB CHECK 함께 적용** (Gemini 강조, Codex Self NO-GO (b))
- **안건 G NO-GO 유지** (양쪽 합의 — 큐 22-A 선행)
- **NICE API 배포 후로 이연** (양쪽 합의)

### 사외이사 차이
- **manager tier 시점**: Codex(설계만 반영, 확장은 배포 후) vs Gemini(2주 전 코드 반영 자체 금지) → **2주 일정 컨텍스트에서 Gemini 손 채택** (배포 전 코드 반영 0)
- **신규 결함 ②(`purchase_seller_*` AUDITED_COLUMNS) 우선순위**: Codex P1 vs Gemini P2 → 큐 22-C 범위에 통합 (직전 회의 결정 그대로)

---

## 🚨 NO-GO 상세

### 안건 I 권한 확장 (Security + Spec-E + Spec-F + Codex + Gemini 5부서 NO-GO)
- **차단 사유**: 4개 SoD 차단선(`canConfirmFinanceTransfer`/`canEditVehicleFinancialFields`/`canAccessAdmin`/`canApproveUnpaidExport`) 동시 위반. 큐 14·19-F·21 설계 collapse
- **수용 조건**: ① **2주 일정에서는 명칭 변경만 채택** (정산→재무 / 통관→수출통관) ② manager tier는 배포 후 별도 회의 ③ 사용자가 의도한 "현장 관리자" 권한은 admin permission으로 흡수하거나 manager tier 신설 (배포 후)
- **대안**: 사용자가 의도한 [관리]를 admin tier에 흡수 (admin 사용자 추가로 생성) — 즉시 가능, 코드 변경 0

### 안건 J 거래완료=B/L 단독 (5부서 + 사외이사 NO-GO)
- **차단**: 직전 회의(`2026-05-19-group-revenue-progress-redesign.md`) 결정 그대로. 5곳 SQL 패키지 + v1 grandfather + DHL 이후 비용 확정 정책 부재
- **대안**: **3-C-light 채택** — UI 라벨 조정만 (1~2h)
- 본격 변경은 큐 24+ 별도 (배포 후)

### 안건 G 50% 룰 완화 (Security NO-GO)
- **차단**: 큐 22-A 미완료 상태에서 `deposit_down_payment` 등 4컬럼 confirmed 필터 없이 즉시 분자 차감 → 부정 입금 조작 후 통관 우회
- **수용 조건**: 큐 22-A 완료 후 진입 (Day 8~9)

---

## 🏁 최종 권고 (Opus 4.7 최종 취합)

### 판정: **조건부 GO 패키지** (안건 6건 2주 내 + 4건 배포 후)

### 근거 (1줄)
**사외이사 양쪽 + 부서 8개 합의: 사용자 의도 "manager tier" 코드 반영은 배포 후 별도 + 신규 결함 4건 운영 전 처리 + 큐 22-A 선행 필수.** SAP/NetSuite '직무 기반 RBAC' 표준 + 1인 개발 2주 컨텍스트 일치.

### 필수 선행 작업
1. **신규 결함 P0 fix 3건 즉시** (RRN silent restore + scopeAction SQL + is_deregistered audit) — 운영 전 차단 요건
2. **사용자 의도 확정**:
   - 안건 I 권한 확장 → manager tier 배포 후 별도 회의 OK?
   - 안건 G 완화 범위 (a/b/c) → 큐 22-A 완료 후 결정 OK?
   - "추가 입금" 운영 정의 → 큐 22-A 완료 후 결정 OK?

### 조건 (조건부 GO — 2주 일정)

**Week 1**:
- **Day 1 (8h)**: D 문서 정정 (0.5h) + B 문서 정정 (0.5h) + H 사이드바 링크 (0.5h) + **신규 결함 P0 fix 4건** (4~5h) — 최우선 차단 해소
- **Day 2 (4~6h)**: I 명칭만 (정산→재무 / 통관→수출통관 마이그 + grep-replace + 11 테스트 재작성)
- **Day 3~4 (4~6h)**: `makeVehicle()` 헬퍼 6파일 선행 + E 판매 required (application + DB CHECK 함께)
- **Day 5 (3~4h)**: C 말소 [everyone] + `canHandleDeregistration()` + RRN silent restore 보강 + 3-C-light 라벨 조정

**Week 2**:
- **Day 6~10 (14~18h)**: 큐 22-A (판매 입금 4컬럼 → `final_payments.type` enum + UI 권한 분리)
- **Day 11~12 (8~10h)**: 큐 22-B (savings_used 통합)
- **Day 13 (5~8h)**: G 50% 룰 완화 (22-A 완료 후, 입금률 ≥ 50% / < 50% admin) + F 입금 분리 (`TYPE_DEPOSIT_ADD_REQUEST` 신설)
- **Day 14**: 회귀 + 브라우저 검증 + AWS Lightsail 배포

**총 공수**: 약 **40~55h** (1인 개발 2주 = 80h 작업시간 기준 50~70% 활용)

**배포 후**:
- 1~2일 안정화 → NICE API 연동 (큐 8 본격)
- 큐 22-C (매입 측 통합) — 별도 회의(2026-05-19-purchase-flow) 결정 그대로
- 안건 I 권한 확장 / manager tier — 별도 회의
- 안건 J 본격 (큐 24+)

### 보류 사유 (2주 일정 외)
- 안건 I 권한 확장: manager tier 자체도 배포 전 코드 반영 금지 (Gemini)
- 안건 J: 5곳 SQL 패키지 + v3 rule_version 2주 내 불가
- 안건 A NICE API: 배포 후 1~2일 안정화 후

### 사용자 의도 vs 회의 결정 매핑

| 사용자 의도 | 회의 결정 | 차이 |
|---|---|---|
| 매입처 계좌 영업 입력 + 자동 PBP Draft | 이미 코드 구현 + 큐 22-C 통합 | 일치 (직전 회의 결정) |
| 말소 [everyone] | `canHandleDeregistration()` 신설 (영업·통관·관리, 정산 제외) | 일치 + 4 role 중 정산 제외 보강 |
| 판매 필수항목 강화 | DB CHECK + application layer 둘 다 (Gemini 강조) | 일치 + DB CHECK 추가 |
| 입금 분리 (추가 입금 영업 요청) | `TYPE_DEPOSIT_ADD_REQUEST` 신설, 큐 22-A 완료 후 | 일치 |
| 50% 룰 입금률 ≥ 50% 자유 | 큐 22-A 완료 후 진입 (Day 8~9) | 일치 + 시점 명시 |
| 수출통관 사이드바 | 차량 목록 필터 링크 권장 (0.5h) | 일치 + 신규 화면 X |
| **[관리] 권한 대폭 확장** | **명칭만 (정산→재무 / 통관→수출통관)**, 권한 확장 배포 후 | **차이**: SoD 보호. **admin 권한 부여 또는 manager tier로 흡수 (배포 후)** |
| **거래완료 = B/L 단독** | **3-C-light** (라벨만) | **차이**: 본격 변경은 큐 24+ |
| NICE API | 배포 후 1~2주 안정화 후 | 일치 + 시점 명시 |

---

## 🛠 car-erp 영향 분석 (Opus 4.7 산출)

### 취약점 (Vulnerabilities) — 운영 전 차단 요건

**P0 즉시 fix (사외이사 Codex+Gemini 합의)**:
1. `nice_reg_owner_rrn` `canEditVehicleFinancialFields()` silent restore 미포함 → 통관/정산/관리 role도 RRN 입력 가능 (개인정보 치명)
2. `Vehicle::scopeAction('purchase_unpaid')` SQL `confirmed_at IS NOT NULL` 누락 (L986~990 vs L856~860 비대칭) → 재무 승인 우회 가능 (미지급금 산출 오류)
3. `is_deregistered` `audit_logs` 추적 부재 → 말소 처리 actor 책임 추적 실패

**P1 (큐 22-C에 통합)**:
4. `purchase_seller_*` 4컬럼 `AUDITED_COLUMNS` 미포함 (직전 회의 발견, 미해결)

**구조적 위험 (회의 결정으로 해소)**:
5. 안건 I 강행 시 4개 SoD 차단선 동시 무력화 → **NO-GO 채택으로 해소**
6. 안건 J 강행 시 v1 grandfather row 자동 강등 + DHL 이후 비용 회계 오류 잠금 → **NO-GO 채택으로 해소**

### 보완사항 (Improvements)
1. **신규 결함 P0 3건 즉시 fix** (Day 1, 4~5h)
2. **명칭 변경 마이그** (정산→재무 / 통관→수출통관) + 11 테스트 케이스 동시 갱신
3. **`canHandleDeregistration()` 신설** — 영업·통관·관리만 (정산 제외)
4. **`TYPE_DEPOSIT_ADD_REQUEST` ACTION_TYPE 신설** (큐 22-A 완료 후)
5. **`unpaid_ratio` null 가드** — 환율 0 외화 차량 시 G1 게이트 강제 차단
6. **3-C-light** — UI 라벨 조정만 (`dhl_needed` "DHL 발송 대기")
7. **수출통관 사이드바 링크** — 차량 목록 필터 (`?progressFilter=수출통관중`)

### 코드 수정 (Code Changes) — Week 1 (40~50h)

**Day 1 (P0 신규 결함 + 가벼움)**:
- `resources/views/livewire/erp/vehicles/index.blade.php` `canEditVehicleFinancialFields()` silent restore 분기에 `nice_reg_owner_rrn` 추가
- `app/Models/Vehicle.php` L986~990 `scopeAction('purchase_unpaid')`에 `->whereNotNull('confirmed_at')` 추가
- `app/Models/Vehicle.php` `booted()` saving 훅 — `is_deregistered` 변경 시 `AuditLog::recordEvent` 호출
- `resources/views/components/layouts/app/sidebar.blade.php` ERP 그룹에 "수출통관" 메뉴 항목 (링크: `route('erp.vehicles.index') . '?progressFilter=수출통관중'`)
- `CLAUDE.md` §차량 진행상태 10단계 — v2 이중 트리거 기준 갱신
- `SKILLS.md` §2 progress_status — v2 코드 일치 갱신
- `docs/workflow-checklist.md` A-0 + B-1 + A-3·A-4 stale 정정

**Day 2 (I 명칭만)**:
- 마이그: `users.role` 값 `'정산'` → `'재무'`, `'통관'` → `'수출통관'`
- `app/Models/User.php` L19 `ROLES` 상수 + L89·L98·L116·L166 등 role 문자열 grep-replace
- `app/Http/Middleware/SettlementMiddleware.php` (→ `FinanceMiddleware.php`) + `ClearanceMiddleware.php` (→ `ExportClearanceMiddleware.php`) — middleware alias 갱신 (코드 내부)
- `database/seeders/DatabaseSeeder.php` role 시드 갱신
- 11 테스트 케이스 grep-replace (`'정산'`·`'통관'`)
- `resources/views/livewire/erp/dashboard.blade.php` L80·L85 role 배열 갱신
- `php artisan route:cache` (미들웨어 alias 체인)

**Day 3~4 (E 판매 required + makeVehicle 헬퍼)**:
- 6 테스트 파일(`WorkflowGapTest`/`G1BlLockTest`/`G3ReceivableClassificationTest`/`DashboardActionCountsTest`/`VehicleLedgerLockTest`/`PaymentConfirmationServiceTest`) `makeVehicle()` 헬퍼 필수 필드 추가
- `resources/views/livewire/erp/vehicles/index.blade.php` `buildRules()` L1041~1090 — `sale_date`·`buyer_id_str`·`sale_price_str` Rule::when `sale_price > 0` 시 required
- 마이그: `sale_date`·`buyer_id` 등 DB CHECK constraint (Gemini 강조) — `php artisan migrate --pretend` 후 기존 nullable row 카운트 확인

**Day 5 (C 말소 [everyone] + 3-C-light)**:
- `app/Models/User.php` `canHandleDeregistration()` 신설 — `isAdmin() || in_array(role, ['영업','수출통관','관리'])` (재무 제외)
- `resources/views/livewire/erp/vehicles/index.blade.php` 말소 체크/서류 업로드 영역에 `canHandleDeregistration()` 가드
- 3-C-light: `dhl_needed` 라벨 변경 + pipeline-strip 두 노드 묶음 표시

**Week 2 (큐 22-A·B 본격 + G + F)**:
- 큐 22-A: 메모리 `project_queue_status.md` §22-A-1~9 (14~18h)
- 큐 22-B: savings_used 통합 (8~10h)
- G 50% 룰 완화: `Vehicle::guardStageOrderForExport` L621 변경 + `unpaid_ratio` 분기 + `unpaid_ratio_cache` 컬럼 추가 검토 (2~4h)
- F `TYPE_DEPOSIT_ADD_REQUEST` ACTION_TYPE 신설 + `ApprovalRequest::execute()` 분기 (5~8h)

### 신규 추가 (New Additions)

**마이그**:
- `update_users_role_values_rename` (정산→재무, 통관→수출통관)
- `add_unpaid_ratio_cache_to_vehicles` (G 50% 룰 완화 — 선택)
- `add_required_constraints_to_sale_columns` (E DB CHECK)
- 큐 22-A·B 마이그 (별도 결정사항)

**신규 메서드**:
- `User::canHandleDeregistration()`
- `ApprovalRequest::TYPE_DEPOSIT_ADD_REQUEST` + `executeDepositAddRequest()`

**신규 테스트** (약 15건):
- P0 결함 fix 검증 4건
- `canHandleDeregistration()` 권한 분기 4건 (영업·통관·관리·정산 거부)
- E 판매 required 5건
- G unpaid_ratio null 가드 1건
- F `TYPE_DEPOSIT_ADD_REQUEST` 1건

### 모순·NO-GO 처리 로그

- **PO 종합 조건부 GO** → 안건별 분리 채택. 안건 I·J 부분 NO-GO 유지
- **Engineer "I HOLD"** → 명칭 변경만 GO, 권한 확장 NO-GO
- **QA "E HOLD (헬퍼 6파일 선행)"** → 선행 PR 명시 채택
- **Security "G·I NO-GO"** → 양쪽 채택 (G 큐 22-A 후, I 명칭만)
- **Spec-E "I NO-GO + manager tier 대안"** → manager tier 자체도 배포 후로 이연 (Gemini 권고 채택)
- **Spec-F "I·J NO-GO"** → 양쪽 채택
- **Spec-B "J NO-GO"** → 3-C-light 대안 채택
- **Codex "manager tier 설계만 반영"** vs **Gemini "2주 전 코드 반영 금지"** → **Gemini 손** (2주 일정 컨텍스트, 더 보수적)
- **Spec-E 발견 "RRN silent restore 결함"** → P0 must-fix 채택
- **QA 발견 "scopeAction SQL 결함"** → P0 must-fix 채택 (직전 회의에도 발견)
- **QA 신규 "is_deregistered audit 부재"** → P0 must-fix 채택

---

## 🔗 참조

### 관련 과거 회의록
- `2026-05-19-purchase-flow-redesign.md` — 매입 흐름 재설계 (큐 22-C 범위 결정)
- `2026-05-19-group-revenue-progress-redesign.md` — 3-A·3-B·3-C 결정 (J NO-GO 원천)
- `2026-05-18-deposit-confirm-gate.md` — 큐 22 옵션 B 단계적 채택
- `2026-05-18-vehicle-ledger-field-lock.md` — 큐 21 ledger lock + `canAccessAdmin`
- `2026-05-16-finance-gate-roundtable.md` — 큐 19-F SoD self-confirm 차단
- `2026-05-14-3way-workflow-policy.md` — 큐 14 role 정의 + '관리' role 4액션

### 코드 참조
- `app/Models/User.php` L19 (ROLES), L73~199 (canXXX 9 메서드)
- `app/Models/Vehicle.php` L208~253 (guardBlFiftyPercentRuleOnSaving), L381~397 (AUDITED_COLUMNS), L406~415 (LEDGER_LOCK_FIELDS), L598~633 (guardStageOrderForExport), L764~817 (getProgressStatusAttribute v1/v2), L836~847 (sale_unpaid 분자), L986~990 (scopeAction purchase_unpaid — 결함 위치), L1042 (settlement_create_needed)
- `app/Models/ApprovalRequest.php` L28~47 (ACTION_TYPE 6종 → 7종 신설 예정), L109~118 (execute match), L160~169 (executeSettlementPay)
- `app/Models/AuditLog.php` L21~25 (MASKED_COLUMNS)
- `app/Services/NiceApiService.php` L26~46 (스텁 — 큐 8 본격 진입 위치)
- `app/Services/PaymentConfirmationService.php` L41~104
- `resources/views/livewire/erp/vehicles/index.blade.php` L1041~1090 (buildRules), L1152~1155 (FINANCIAL_FIELD_MAP — RRN 미포함 결함), L2608~2634 (매입처 계좌 영업 입력)
- `resources/views/components/layouts/app/sidebar.blade.php` L71~190 (menuGroups)
- `tests/Feature/WorkflowGapTest.php` L189·L602 (role 문자열), L417~444 (J 깨질 케이스)
- `tests/Feature/DashboardActionCountsTest.php` L43~79·L186·L200·L210
- `tests/Feature/G1BlLockTest.php` 전체 (G 깨질 8건)
- `database/migrations/2026_05_06_120935_add_permission_role_to_users_table.php` L13 (users.role string(20))

### 부서 프롬프트 (v1.2)
- `docs/meetings/departments/{po,engineer,qa,security,ops,specialist}.md`

### 신규 결함 fix 우선순위 (Codex + Gemini 합의)
- **P0 must-fix**: ① RRN silent restore + ③ scopeAction SQL + ④ is_deregistered audit
- **P1 (큐 22-C 통합)**: ② AUDITED_COLUMNS 계좌 4컬럼
