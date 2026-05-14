# 📅 회의록: 워크플로우 정책 재설계 + 채널 단순화 + 권한 분리

- 일시: 2026-05-14
- 강도: 풀 × 7 그룹
- 출처: 2026-05-13 3자 회의 (사용자 정리본 `20260513_3자회의내용.txt`)
- 안건 유형: 도메인 룰 / 마이그레이션 / 권한 / UI / 외부 API
- 자동발동: yes — 키워드 `migration` / `role` / `permission` / `sale_unpaid_amount` / `sales_channel` / `progress_status` 다수
- 참조: `decision_protocol.md` / `role기획보안_수정.md` / 큐 9·10·11 (`2026-05-12-workflow-gap-analysis.md`)

---

## 0. 원본 안건 (12건)

1. **적립금으로 미수 마무리 금지** — 무조건 바이어에게 받고 B/L 건넴
2. **선적 단계라도 미수 받고 종료가 원칙**
3. **같은 바이어 1번 미수 + 2번 거래** — 별도 예치금 또는 10% 계약금
4. **차량 한국 보유 + 50% 받은 상태** — 그 50%의 50%로 새 차 거래 가능
5. **판가 50% 룰** — 50% 받기 전 통관/선적 불가 / 선적 후 50% 잔금 전 B/L 제출 불가
6. **상태별 미수금 분류** (매입/판매/통관/선적) — 채권관리·관리자 대시보드 표시
7. **바이어별 + 담당자별 분류** — 선적전 미수금 / 선적후 미수금 / 디파짓(적립금) 별도. **적립금은 별도** (이미 코드 반영).
8. **재무팀 권한 분리** — 정산 담당(부) + 채권 담당(정·결정권자) / 채권→영업 알림톡 → 대표 보고
9. **재고관리 신규 화면** — 영업담당자별. 말소완료까지 = 재고. 선적중 시 재고 제거. 차량번호판 / 차대번호 뒷자리 6자리 필수.
10. **헤이맨·카풀 삭제** — 수출 1개만 남김
11. **동시 편집 락 + 변경 로그** — 차량 편집 동시 진입 시 "현재 누가 사용 중" 모달 + 저장 안 누르면 폐기 + 감사 로그 (`/admin/document-access-logs` 확장)
12. **사이드바 재구성** — 현재 트리 vs 새 트리

## 1. 그룹화

| 그룹 | 안건 | 무게 | 핵심 영향 |
|---|---|---|---|
| G1 | 1·2·5 + 11일부 | 🔴 | 도메인 핵심 룰, DB 플래그, 미들웨어/UI 차단 |
| G2 | 3·4 | 🟠 | 거래 진행 가드, 같은 바이어 신규 거래 |
| G3 | 6·7 | 🟠 | 채권관리·대시보드 SQL/UX 재구성 |
| G4 | 8 | 🟠 | 새 role / 알림 흐름 |
| G5 | 9 | 🟠 | 새 화면 + 컬럼·NICE 연동 |
| G6 | 10 | 🔴 | 마이그레이션·전 코드 분기 정리 |
| G7 | 11·12 | 🟡 | UI 트리·Livewire 락·로그 확장 |

---

## 2. 그룹별 풀회의 (claude 1차 검토)

### G1. 50% 룰 + B/L 잠금

**도메인 의도**: 판가의 50%(선수금)는 통관·선적 진입 게이트, 잔금 50%는 B/L 인도 게이트. 적립금은 별도 (이미 분리).

#### 📋 PO
조건부 GO. 운영 룰을 시스템이 강제하는 것은 큰 가치. 단 기존 운영 데이터에 retroactive 적용은 불가 — 신규 차량부터.

#### ⚙️ Engineer
조건부 GO. `Vehicle::saving` validator 확장 + `Vehicle::guardAttachmentDeps()` 패턴 재사용. **공수**: 6~10시간 (validator + 마이그레이션 X + 단위 테스트). 마이그레이션 불필요 — `sale_unpaid_amount` accessor 기반 단순 비율로 검증 가능.

#### 🧪 QA & Domain Integrity
**NO-GO (조건부 전환 가능)**
- **(a) 차단 사유**: 50%의 분모·분자 정의 모호. ① 분모 = sale_price 단독 vs `sale_total_amount`(부대비용 포함) ② 외화 차량은 통화별 계산 vs KRW 환산 ③ 환율 미입력 외화 차량의 50% 판정 ④ 일부 입금 (예: 계약금 30%만)에서 통관 진입 시도 시 차단 일관성
- **(b) 최소 조건**: ① 분모 = `sale_price` 확정 (방금 미납 게이지 분모와 다름 — 게이지는 `sale_total_amount`, 50% 룰은 `sale_price`로 분리) ② 적립금 제외 (이미 됨) ③ 외화 차량은 통화별 비교, 환율 미입력은 별도 경고 액션
- **(c) 대안**: 50% 게이트는 "입금률" 정의를 사용자 결정으로 명문화. 정의 확정 후 validator 1개로 통일.

도메인 공식 영향: SKILLS.md §13 — 50% 룰 게이트 신규 항목 추가 필요.

#### 🔒 Security & Compliance
특이사항 없음 — B/L 다운로드 라우트는 이미 admin 제한. 50% 미달 차단은 도메인 가드라 보안과 직교.

#### 🚀 Ops & Deploy
조건부 GO. 마이그레이션 없음. 다운타임 0초. 단 운영 차량 중 "50% 미충족이지만 이미 B/L 발행" 사례 있으면 grandfather 처리(`bl_grandfathered_at` 컬럼 or `rule_version`).

#### 🔧 Specialist (데이터 무결성)
조건부 GO. `progress_status_rule_version` 패턴(2026-05-13 회의록) 그대로 적용 — v3 룰로 신규 차량부터 50% 룰. 기존 paid·shipped는 v2 유지. 큐 9 H1·H2와 통합.

**🏁 G1 최종 권고**: **조건부 GO**
- 조건: ① 50% 분모/분자 정의 확정 (분모 sale_price 권장) ② rule_version v3로 신규부터 적용 ③ 큐 9 H1·H2 saving validator와 통합 ④ 외화 환율 미입력 차량 별도 액션 카드 ('환율 미입력 외화 차량')

---

### G2. 같은 바이어 + 적립금 차단

**도메인 의도**: 안건 3 — 1번 차 미수 + 2번 차 거래 시도 시 10% 계약금 또는 별도 예치금 필수. 안건 4 — 차량 한국 보유 + 50% 받은 상태면 그 50%의 50%로 신규 거래 가능 (재투자 자금).

#### 📋 PO
HOLD (정보 부족). 안건 4 "50%의 50%"는 회계상 구체적 동작이 불명확. 차량 1의 미수금이 차량 2 계약금으로 자동 이체되는 것인지, 아니면 회계 장부상 차감일 뿐인지. **사용자 추가 명세 필요.**

#### ⚙️ Engineer
HOLD. 시스템 구현 방식이 두 갈래:
- A) UI에서 경고만 (영업이 판단)
- B) DB에서 잠금 (같은 buyer_id의 미수 잔존 차량 있으면 신규 차량 등록 시 alert)
- C) 자동 회계 처리 (차량 1 미수 → 차량 2 계약금 자동 이체)
**선택 필요.**

#### 🧪 QA & Domain Integrity
NO-GO (지금 결정 불가).
- **(a)**: "50%의 50%" 회계 처리는 SavingsStatus와 별개로 새 거래 타입 (INTER_VEHICLE_TRANSFER) 필요할 수 있음. 도메인 모델 추가.
- **(b)**: 회계담당자 정확한 운영 절차 인터뷰 필요. 자동인지 수동인지.
- **(c)**: 1차로 "같은 바이어 미수 차량 있음" 경고만 (A안), 자동 이체는 별도 안건으로 후행.

#### 🔒 Security & Compliance
특이사항 없음 — 권한 영향 없음.

#### 🚀 Ops & Deploy
GO (1차 A안 한정). 경고만이면 다운타임·마이그레이션 없음.

#### 🔧 Specialist
HOLD — 회계 도메인 명세 추가 필요.

**🏁 G2 최종 권고**: **HOLD**
- 보류 사유: "50%의 50%" 회계 처리 명세 부족
- 필수 선행: 사용자가 회계 자동/수동 여부 확정
- 1차 권고: A안 (같은 바이어 미수 차량 있음 경고 배너) 먼저 → 자동 이체는 별건

---

### G3. 미수금 상태별 분류 + 바이어별 + 담당자별

**도메인 의도**: 안건 6·7 — 미수금을 진행상태(매입/판매/통관/선적)·바이어·담당자로 그룹화. 특히 **선적전 미수 / 선적후 미수 / 디파짓(적립금) 3 분류** 핵심.

#### 📋 PO
GO. 채권관리·관리자 대시보드 핵심 KPI. 큐 4 8-6(미수금 TOP10)에 이미 일부 반영. **이번엔 선적전/선적후/디파짓 3분류 확장**.

#### ⚙️ Engineer
조건부 GO. SQL 그룹화 + 새 카드 4개 추가. `Vehicle::scopeAction()` (큐 1번)에 액션 추가 — `receivable_before_shipping` / `receivable_after_shipping` / `deposit_by_buyer`. **공수**: 4~6시간.
영향 파일: app/Models/Vehicle.php (scope 3개), admin/dashboard.blade.php (카드 3장), receivables/index.blade.php (탭 3개 또는 필터 3개).

#### 🧪 QA & Domain Integrity
조건부 GO.
- "선적전 미수" 정의: `progress_status_cache` ∈ {매입중, 매입완료, 말소완료, 판매중, 판매완료} AND `sale_unpaid_amount > 0`
- "선적후 미수" 정의: `progress_status_cache` ∈ {수출통관중, 수출통관완료, 선적중, 선적완료} AND `sale_unpaid_amount > 0`
- "디파짓": `savings_used > 0` (적립금 사용분) OR `SavingsStatus.balance` 별도 표시
- SKILLS.md §9 (대시보드 카운트↔vehicles 목록 SQL 일치) — 새 액션도 동일 패턴.

#### 🔒 Security & Compliance
특이사항 없음 — 채권관리 admin 제한 유지.

#### 🚀 Ops & Deploy
GO. 마이그레이션 없음.

#### 🔧 Specialist (UX)
조건부 GO. 채권관리 화면을 **탭 구조**로 재구성 — [전체 / 선적전 / 선적후 / 디파짓]. 또는 필터 pill. 모바일 가로 스크롤 대응.

**🏁 G3 최종 권고**: **조건부 GO**
- 조건: ① "선적전/선적후/디파짓" 정의 확정 (위 QA안 권장) ② SKILLS.md §9 action 파라미터 패턴 따라 vehicles 목록 연동 ③ 큐 4 8-6 미수금 TOP10과 정합 유지

---

### G4. 재무팀 권한 분리 + 채권 알림

**도메인 의도**: 안건 8 — 정산 담당자(부) + 채권 담당자(정·결정권자) / 채권→영업 알림톡 → 대표 보고 흐름.

#### 📋 PO
조건부 GO. 1인 개발 + 작은 회사 컨텍스트에서 role 추가는 신중. 일단 admin/sales/clearance/settlement에 **`receivable` role 추가** + 알림 시스템 신규.

#### ⚙️ Engineer
조건부 GO.
- DB: `users.role` enum에 `receivable` 추가 (또는 별도 `is_receivable_lead` 플래그)
- 미들웨어: `receivable` alias 신규
- 알림: 카카오 알림톡 API 연동 (큰 작업) OR Laravel Notifications + DB driver (1차) + 이메일 폴백
- 채권담당자 대시보드 분기

**공수**: 알림톡 제외 8~12시간 / 알림톡 포함 추가 16~24시간 (외부 API 셋업)
영향 파일: User.php (role 확장), middleware, Receivable* 모델 검토, /admin/users (role 부여 UI)

#### 🧪 QA & Domain Integrity
NO-GO → 조건부 전환 가능.
- **(a)**: ① "정산 담당자(부)"가 일반 settlement role과 어떻게 다른지 ② 채권담당자가 admin이라면 별도 role 필요한지 ③ 알림톡 API 키·비용 출처
- **(b)**: ① settlement / receivable role 정의 명확화 ② 채권담당자 = "결정권자"이므로 admin 권한 동등 + 추가 알림 수신자 표식 1개로 충분할 수 있음 ③ 알림톡 API 키 확보 후 진행
- **(c)**: 1차로 DB notifications 테이블 + 화면 내 알림 배지 → 알림톡은 별건

#### 🔒 Security & Compliance
조건부 GO. role 변경은 무게 있는 보안 안건. 미들웨어 라우트 누락 없는지 전수 점검 필수. 알림톡 API 키는 .env + config/services.php만.

#### 🚀 Ops & Deploy
조건부 GO. 알림톡 연동 시 queue worker 의존성. 발송 실패가 트랜잭션에 영향 없게 `Mail::queue()` 패턴 (SKILLS.md §14) 따름.

#### 🔧 Specialist (외부 의존성)
조건부 GO. 알림톡 (Kakao Business / Bizppurio / Aligo 등) 사업자 등록·발신 프로필 사전 필요. 비용 1건 8~15원. 발송량 추정 + 폴백(이메일·SMS) 설계.

**🏁 G4 최종 권고**: **조건부 GO**
- 조건: ① `receivable` role 정의 확정 (admin과 별도인지 아니면 admin + 표식) ② 1차는 in-app 알림 + 이메일 폴백 ③ 알림톡은 별건 (큐 12 외부 연동에 통합)

---

### G5. 재고관리 신규 화면

**도메인 의도**: 안건 9 — 영업담당자별 재고관리 탭. 차량등록 + 매입 + 말소완료까지 = 재고. 선적중 시 제거. 차량번호판/차대번호 뒷자리 6자리 필수.

#### 📋 PO
조건부 GO. 영업담당자 핵심 화면. role 권한 = admin / sales / super.

#### ⚙️ Engineer
조건부 GO. 새 라우트 + Volt 컴포넌트 + 필터/정렬. **공수**: 4~6시간. 캐시 컬럼 추가 불필요 — `progress_status_cache` 활용.
- 라우트: `/erp/inventory` (또는 `/erp/salesmen/{id}/inventory`)
- SQL: `progress_status_cache IN ('매입중','매입완료','말소완료') AND salesman_id = ?`
- 차대번호 뒷자리 6자리 필드 신규 (`nice_reg_vin_last6` 또는 `vin_tail`)

#### 🧪 QA & Domain Integrity
조건부 GO.
- "재고에서 빠지는 조건" = `progress_status_cache` ∈ {판매중 이후} OR `is_disposed=true`
- 차대번호 뒷자리 6자리는 NICE API 응답 `nice_reg_vin` 에서 substring 가능 — 새 컬럼 불필요할 수도. 수동 입력만 가능하면 별도 필드.
- 대시보드 카운트 ↔ 재고 목록 SQL 일치 (SKILLS.md §9 패턴)

#### 🔒 Security & Compliance
특이사항 없음 — admin·sales 권한 미들웨어로 보호.

#### 🚀 Ops & Deploy
GO. 마이그레이션 1개 (vin_tail 컬럼) — nullable이라 기존 row 영향 없음. 다운타임 0초.

#### 🔧 Specialist (외부 의존성 — NICE API)
조건부 GO. NICE API 연동(큐 8) 시 `nice_reg_vin`에서 substring. 미연동 상태에선 수동 입력 칸만.

**🏁 G5 최종 권고**: **조건부 GO**
- 조건: ① 차대번호 뒷자리 신규 컬럼 vs `nice_reg_vin` substring 결정 ② 재고 정의(매입중/매입완료/말소완료) 확정 ③ SKILLS.md §9 패턴 따라 vehicles 목록 연동

---

### G6. 채널 단순화 (헤이맨·카풀 삭제)

**도메인 의도**: 안건 10 — 헤이맨/카풀 제거. 수출 1개만.

#### 📋 PO
조건부 GO. 사용자 명확한 결정. 운영상 사용 빈도 0이라면 즉시 제거 가능.

#### ⚙️ Engineer
**NO-GO (조건부 전환 가능)**
- **(a)**: 채널 분기 코드가 광범위함 — `sales_channel` enum 컬럼 + 거의 모든 actionCounts · 정산 공식 · 문서 생성 · 시드 · 미들웨어. 즉시 enum 컬럼만 변경하면 마이그레이션 정합성 깨짐.
- **(b)**: ① 단계적 폐기: ① 신규 차량 등록 시 헤이맨/카풀 선택지 제거 ② 기존 카풀/헤이맨 차량 처리 결정 (수출로 변환? 폐기?) ③ 코드 분기 정리 ④ enum 변경
- **(c)**: enum 그대로 두고 UI에서만 숨김 (간단). 다만 코드 분기는 그대로 유지.

**공수**: 풀 정리 16~24시간. UI 숨김만은 1시간.
영향 파일: vehicles 마이그레이션, Vehicle.php, vehicles/index.blade.php (탭 + 분기), admin/dashboard·erp/dashboard·receivables (탭), 시드, settings(`heyman_channel_enabled`·`carpul_channel_enabled` 토글 — CLAUDE.md 기능 토글), 문서 생성 컨트롤러

#### 🧪 QA & Domain Integrity
NO-GO.
- **(a)**: ① 기존 카풀/헤이맨 차량 (시드에 9건 존재 추정) 처리 방식 미정 ② SKILLS.md §10 뱃지 매핑 / §13 공식 헤이맨·카풀 특수 항목 (tax_invoice_1/2, agency_fee) 제거 시 차량 편집 패널 영향 ③ 정산 공식의 채널별 분기 (수출만 면장 환산, 헤이맨은 KRW 직접) 통일
- **(b)**: ① 기존 카풀/헤이맨 차량 운영 데이터 확인 — 없으면 즉시 제거 가능 ② 시드 데이터 정리 (수출로 통일) ③ tax_invoice/agency_fee 컬럼 처리(유지 vs drop)
- **(c)**: CLAUDE.md 기능 토글(`heyman_channel_enabled`·`carpul_channel_enabled`) 둘 다 false로 운영 → 1~2주 모니터링 → 코드 제거

#### 🔒 Security & Compliance
특이사항 없음 — 권한 영향 없음.

#### 🚀 Ops & Deploy
조건부 GO. 마이그레이션 시 enum 값 변경은 위험(MySQL ALTER ENUM 락). 대안: enum → 단순 boolean 또는 단순 string + check constraint. **롤백 SQL** 필수.

#### 🔧 Specialist (데이터 무결성)
NO-GO.
- **(a)**: 기존 헤이맨/카풀 차량의 정산·채권 데이터 마이그레이션 경로 불명.
- **(b)**: 운영 DB에서 헤이맨/카풀 차량 수 확인 → 0건이면 그냥 제거. >0건이면 (1) 수출로 변환 (2) 별도 보관 (3) 별도 처리 결정.
- **(c)**: 채널 토글 OFF로 1차 운영 → 데이터 자연 소진 후 코드 정리.

**🏁 G6 최종 권고**: **HOLD → 단계별 진행**
- 1단계 (즉시): UI에서 헤이맨/카풀 선택지 숨김 + 신규 등록 차단 (기능 토글 OFF)
- 2단계 (운영 데이터 확인 후): 기존 차량 처리 결정 + 시드 정리
- 3단계 (수개월 후): 코드 분기 제거 + enum 컬럼 변경

---

### G7. 사이드바 재구성 + 동시 편집 락 + 감사 로그

**도메인 의도**: 안건 11·12 — 사이드바 트리 재검토 / 차량 편집 동시 진입 시 안내 / 저장 안 누르면 폐기 / 감사 로그 확장.

#### 📋 PO
조건부 GO (분리). 사이드바는 **새 화면들(재고관리·미수금 분류)을 다 만든 후 한 번에 재구성**이 효율적. 동시 편집 락은 별건. 감사 로그는 작은 확장.

#### ⚙️ Engineer
조건부 GO.
- **사이드바**: 새 화면 추가 후 재배치. 큰 작업 아님 (1~2시간)
- **동시 편집 락**: Livewire는 stateless라 락은 별도 구현. Redis or DB 기반 lock (`vehicle_edit_locks` 테이블 + heartbeat). 또는 broadcast (Echo + Pusher/Reverb)로 실시간 알림. **공수**: 8~16시간.
- **감사 로그**: 기존 `document_access_logs`에 vehicle_save 액션 추가 vs 별도 `audit_logs` 테이블. **공수**: 4~6시간.

#### 🧪 QA & Domain Integrity
조건부 GO.
- 동시 편집 락: heartbeat 끊기면 자동 해제 (30~60초). 사용자 브라우저 닫음 등 엣지 케이스.
- 저장 안 누르면 폐기: Livewire wire:model이 이미 deferred. 외부 클릭 = 패널 close = 폐기. 명시 검증.
- 감사 로그: 어떤 컬럼 변경을 기록할지 — 모든 컬럼(부담 큼) vs 핵심 컬럼만(sale_price·payment·status)

#### 🔒 Security & Compliance
조건부 GO. 감사 로그는 무단 접근·변경 추적용. 로그 자체 무단 변경 차단 필요 (append-only). RRN 등 민감 컬럼 변경은 평문 로깅 금지 (마스킹).

#### 🚀 Ops & Deploy
조건부 GO. 락 테이블 + 감사 로그 테이블 → DB 부하 모니터링 필요. 로그 회전(주기적 archive).

#### 🔧 Specialist (UX 설계자)
조건부 GO. 동시 편집 락 모달은 "현재 {사용자명}이 편집 중. 잠시 후 다시 시도하세요" 정도. read-only 보기는 허용 옵션.

**🏁 G7 최종 권고**: **조건부 GO (3건 분리)**
- 사이드바: 다른 화면 다 만든 후 한 번에 (낮은 우선순위)
- 동시 편집 락: 별도 큐로 분리. 8~16시간 작업.
- 감사 로그: 별도 큐. 4~6시간 작업. RRN 마스킹 의무.

---

## 3. claude 1차 종합

| 그룹 | 판정 | 즉시 진행 가능? |
|---|---|---|
| G1 (50% 룰) | 조건부 GO | ⚠️ 분모 정의 확정 후 |
| G2 (같은 바이어) | HOLD | ❌ 회계 명세 부족 |
| G3 (상태별 분류) | 조건부 GO | ✅ 정의 확정만 |
| G4 (재무팀 권한) | 조건부 GO | ⚠️ role 정의 + 알림 채널 |
| G5 (재고관리) | 조건부 GO | ✅ 정의 확정만 |
| G6 (채널 단순화) | HOLD → 단계별 | ⚠️ 운영 데이터 확인 |
| G7 (사이드바·락·로그) | 조건부 GO (분리) | ⚠️ 일부 즉시, 일부 후행 |

---

## 4. codex 의견

(원본 한글 인코딩 깨짐 — 의미 추출)

### 전체 판정
조건부 GO. G2/G6 HOLD 유지, G1은 `rule_version v3`로 신규부터 적용 시 안전.

### codex 우선순위 (1~7)
1. **G2 정책 확정** (HOLD) — 50/50 정의, `advance_payment1/2` 의미, B/L 시 필수 입금률 결정 필요
2. **G1 테스트 검증** (조건부 GO) — `sale_unpaid_amount` 기반 + B/L 차단 100% 후속 테스트 추가
3. **G3 채널 분기 시드 테스트** (GO) — `sales_channel='export'` 격리 SQL 테스트 보강
4. **G7 문서 접근 권한 결정** (GO) — 현재 일반 user 다운로드 + 로그 보장 → admin-only로 바꿀지 정책 결정
5. **G4 receivable role 분리** (GO) — admin-only TODO 해소 + 1차 in-app 알림
6. **G5 NICE 불일치 검증** (GO) — VIN/차량번호 불일치 경고 정도
7. **G6 UX 보강** (HOLD/축소 GO) — 11단계 변경 없이 reason tooltip / 다음 단계 안내만

### codex NO-GO 사유
- 즉시 NO-GO 없음
- 단 G1 B/L 차단을 `rule_version v3` 없이 적용하면 이전 진행건 깨짐
- G7 문서 다운로드 admin-only 변경 시 user 다운로드 허용 + 로그 정책 합의 필요

---

## 5. advisor 의견 (gemini quota 소진 대체)

### 5-1. 🔴 가장 큰 사각지대 — "50%의 분모" 시스템 전역 단일 정의 필요

| 곳 | 현재 분모 |
|---|---|
| 미납 게이지 (방금 작업) | `sale_total_amount` (부대비용 포함) |
| G1 50% 룰 (claude 제안) | `sale_price`만 |
| G3 선적전/선적후 분류 | 정의 미정 |
| KPI / 채권관리 / 판매탭 % | `sale_total_amount` 기반 |

→ 분모가 두 종류면 5곳이 어긋남. 사용자가 강조한 "무결점 정합" 깨짐.

**회계담당자 인터뷰 1순위**: "판가 50%의 50%는 `sale_price`만입니까, 부대비용(transport_fee/commission/auto_loading) 포함입니까?"

답이 나오면 `SKILLS.md §13`에 **"미수율 분모 정의 (단일 출처)"** 박고 모든 곳이 그 출처를 참조. G1·G2·G3의 **선행 조건**.

### 5-2. G6 격상 — claude 너무 보수적

`CLAUDE.md` 명시:
> 기능 토글 (Setting 모델 — 구현 예정)
> - `heyman_channel_enabled` / `carpul_channel_enabled`

**1단계 = 토글 OFF + 운영 DB 1쿼리 (1~2h)**. claude의 "UI 숨김"보다 가벼움.
1쿼리: `SELECT sales_channel, COUNT(*) FROM vehicles GROUP BY sales_channel`
- 0건 → enum 변경까지 즉시 가능
- >0건 → 마이그레이션 별건

**G6를 HOLD가 아니라 "1쿼리 확인 후 1단계 즉시 GO"**

### 5-3. G2 안건 4 격상 — claude 과대해석

"그 50%의 50%로 새 차 거래 가능" = **받은 금액의 절반(판가의 25%)이 신규 거래 한도**.

자동 이체가 아니라 **허용 한도 계산**. INTER_VEHICLE_TRANSFER 새 모델 불필요. **UI 경고 배너 + 한도 표시 (1~2h)**.

**G2 격상 — 조건부 GO** (단, §5-1 분모 결정 후).

### 5-4. 사용자가 명시 안 한 시나리오 6건

| 사각지대 | 영향 그룹 | 결정 필요 |
|---|---|---|
| 거래 취소 (B/L 발행 후 무산) 회수·환불 절차 | G1, G3 | 50% 룰과 충돌 |
| 외화 환율 급변 시 50% 기준 재산정 | G1 | 룰 적용 시점 |
| 같은 바이어 다중 통화 (USD + KRW) 합산 | G3 | 분류 SQL |
| 재고에서 도난/사고 차량 (`is_disposed=true`) | G5 | 재고 정의 |
| 감사 로그 보존 기간 | G7 | 1년 / 5년 / 무기한 |
| 알림 거부 의사 바이어 | G4 | 개보법 준수 |

### 5-5. 큐 9·10·11과 통합 매핑

| 그룹 | 흡수 위치 |
|---|---|
| G1 50% 룰 | **큐 9 H1·H2** (첨부 검증)에 흡수 — 검증 조건 추가 |
| G3 미수 분류 | **큐 10 H5** (final_payments↔ReceivableHistory) 데이터 의존 |
| G7 감사 로그 | **큐 11** (운영 안전 가드) 확장 |
| G4 receivable role | **새 큐 14** |
| G5 재고관리 | **새 큐 15** (NICE 큐 8과 의존) |
| G6 채널 단순화 | **새 큐 16** (single-purpose) |
| G7 동시 편집 락 | **별건** (인프라 결정 후) |
| G7 사이드바 | 모든 화면 완성 후 (2h) |

### 5-6. 의존성 그래프

```
[즉시 — 정의 무관]
  G6 1단계 (운영 DB 확인 + 토글 OFF)           1~2h
  G7 감사 로그 (큐 11 흡수)                     6~8h

[1순위 — 분모 정의 인터뷰 후 (= G1·G2·G3 전제)]
  SKILLS.md §13 미수율 분모 정의 박기           0.5h
    ↓
    G1 50% 룰 구현 (큐 9 H1·H2 통합)            10~14h
    G3 미수 분류 (큐 10 H5 데이터)              8~10h
    G2 한도 경고 배너                            2~3h

[병렬 트랙]
  G5 재고관리 (NICE 무관 — 수동 입력 먼저)      6~8h
  G4 receivable role + in-app 알림              16~24h (advisor 재추정)

[후속 별건 — 외부 의존]
  G4 알림톡 (사업자 등록 1~2주)                별도 큐
  G7 동시 편집 락 (인프라 결정)                별도 큐 — 16~32h (advisor 재추정)
  G7 사이드바 (모든 화면 완성 후)               2h
```

### 5-7. 공수 재추정 (claude 낙관 보정)

| 항목 | claude 1차 | advisor 재추정 |
|---|---|---|
| G7 동시 편집 락 | 8~16h | 16~32h (Livewire stateless 본질) |
| G4 알림 시스템 (in-app + 대시보드 + 영업알림 + 대표 일간) | 8~12h | 16~24h |

**현실적 기간**: 1인 25~30h/주 가정 시 **6~7주** (병렬 트랙 활용).

---

## 6. 최종 종합 (3자 통합)

### 그룹별 최종 판정

| 그룹 | claude | codex | advisor | **최종** |
|---|---|---|---|---|
| G1 50% 룰 + B/L 잠금 | 조건부 GO | 조건부 GO | 조건부 GO | **조건부 GO** (§5-1 선행) |
| G2 같은 바이어 + 한도 | HOLD | HOLD | 조건부 GO | **조건부 GO** — 안건 4는 단순 한도 표시 |
| G3 미수 상태별 분류 | 조건부 GO | GO | 조건부 GO | **조건부 GO** (§5-1 선행 + 큐 10 H5 의존) |
| G4 재무팀 권한 분리 + 알림 | 조건부 GO | GO | 조건부 GO | **조건부 GO** (공수 16~24h) |
| G5 재고관리 신규 | 조건부 GO | GO | 조건부 GO | **조건부 GO** (NICE 무관, 수동 입력 우선) |
| G6 채널 단순화 | HOLD 단계별 | HOLD | 1쿼리 후 즉시 GO | **격상 — 1단계 즉시 GO** |
| G7 사이드바 + 락 + 로그 | 조건부 GO (분리) | GO (정책 결정) | 조건부 GO (락 재추정) | **분리: 락은 별건 / 로그·사이드바 즉시** |

### 회계담당자 인터뷰 (사용자가 운영팀과 확정해야 할 결정사항 2개)

| # | 질문 | 영향 |
|---|---|---|
| Q1 | "판가의 50%"의 분모 = `sale_price` 단독인가, 부대비용(transport_fee/commission/auto_loading - tax_dc) 포함인가? | G1·G2·G3 전체 / SKILLS.md §13 / 게이지 분모와 정합 |
| Q2 | 안건 4 "50%의 50%로 새 차 거래 가능"은 (a) 한도 단순 정보 표시인가, (b) 회계상 자동 차감/이체인가? | G2 구현 방식 — UI 배너 vs 새 도메인 모델 |

추가 사각지대 6건(§5-4)도 동시에 짚으면 좋음.

---

## 7. 작업 큐 도출

기존 큐 (`role기획보안_수정.md`):
- 큐 3 — 차량관리 담당자 필터 + 채권관리 검색란 축소 (~1h, 가벼움)
- 큐 4 — 관리자 대시보드 차트 보강 (4~6h)
- 큐 5 — 업무 대시보드 역할별 토글 (3~4h)
- 큐 7 확장 — 컬럼 단위 권한 + RRN 형식·audit (4~5h)
- 큐 8 — NICE API
- 큐 9 — High 도메인 안전 (3~4h)
- 큐 10 — 정산·채권 무결성 (4~5h)
- 큐 11 — 운영 안전 가드 (2~3h)
- 큐 12 — 포워딩 SMTP
- 큐 13 — AWS Lightsail

### 신규 큐 (3자 회의 결과)

| 신규 큐 | 내용 | 공수 | 의존 |
|---|---|---|---|
| 큐 9 확장 | G1 50% 룰 (H1·H2 첨부 검증에 추가) | +10~14h | Q1 결정 |
| 큐 10 확장 | G3 선적전/선적후/디파짓 미수 분류 | +8~10h | Q1 결정, 큐 10 H5 |
| 큐 11 확장 | G7 감사 로그 확장 (`document_access_logs` → `audit_logs`) | +6~8h | - |
| **큐 14 신규** | G4 receivable role + in-app 알림 (알림톡 제외) | 16~24h | - |
| **큐 15 신규** | G5 재고관리 신규 화면 | 6~8h | - |
| **큐 16 신규** | G6 채널 단순화 1단계 (토글 OFF + 1쿼리) | 1~2h | - |
| **큐 17 신규** | G2 같은 바이어 한도 경고 배너 | 2~3h | Q1 결정 |
| 별건 1 | G7 동시 편집 락 (인프라 결정 후) | 16~32h | 인프라 선택 |
| 별건 2 | G4 알림톡 (사업자 등록 1~2주 후) | 16~24h | 사업자 등록 |
| 별건 3 | G7 사이드바 재구성 (모든 화면 완료 후) | 2h | 14·15 완료 |

### 권장 작업 순서

```
[즉시 — 정의 무관, 회계 인터뷰와 병행]
1. 큐 16 (G6 1단계 토글 OFF + 1쿼리)            1~2h
2. 큐 3 (담당자 필터 + 검색란)                  ~1h
3. 큐 11 확장 (감사 로그)                       6~8h

[Q1·Q2 결정 후 — 분모 정의 박은 후]
4. SKILLS.md §13 분모 정의 단일화                0.5h
5. 큐 9 확장 (G1 50% 룰)                         10~14h
6. 큐 17 (G2 한도 배너)                          2~3h
7. 큐 10 (정산·채권 무결성 — 기존)               4~5h
8. 큐 10 확장 (G3 미수 분류)                     8~10h

[병렬 트랙 — Q1 무관]
9. 큐 15 (G5 재고관리)                           6~8h
10. 큐 14 (G4 receivable role + in-app 알림)     16~24h

[후속]
11. 큐 4 (관리자 대시보드 차트)                  4~6h
12. 큐 5 (업무 대시보드 토글)                    3~4h
13. 큐 7 확장 (RRN 형식·audit)                   4~5h
14. 별건 1·2·3 (락·알림톡·사이드바)              상황별

[외부 의존]
15. 큐 8 NICE API
16. 큐 12 포워딩 SMTP
17. 큐 13 AWS Lightsail
```

**총 예상**: 6~7주 (1인 25~30h/주 기준)

---

## 8. 후속 결정 (사용자가 결정해야 할 사각지대 6건)

| # | 질문 | 우선순위 |
|---|---|---|
| S1 | 거래 취소 (B/L 발행 후 무산) 회수·환불 절차 | 운영 룰 — 큐 9 확장 전 |
| S2 | 외화 환율 급변 시 50% 기준 재산정 시점 | 큐 9 확장 |
| S3 | 같은 바이어 다중 통화(USD + KRW) 합산 표시 방식 | 큐 10 확장 |
| S4 | 재고에서 도난/사고 차량(`is_disposed=true`) 처리 | 큐 15 |
| S5 | 감사 로그 보존 기간 (1년 / 5년 / 무기한) | 큐 11 확장 |
| S6 | 알림 거부 의사 바이어 처리 (개보법) | 큐 14 |

---

## 🔗 참조
- `decision_protocol.md`
- `role기획보안_수정.md`
- `2026-05-12-workflow-gap-analysis.md` (큐 9·10·11 도출)
- `2026-05-13-progress-status-integrity.md` (rule_version v3 패턴)
- `SKILLS.md §9` (대시보드 카운트↔vehicles SQL 일치) / `SKILLS.md §13` (도메인 공식)
- `CLAUDE.md` 기능 토글 / 외부 연동 NICE·SMTP
- 원본: `~/Desktop/20260513_3자회의내용.txt` / `~/Desktop/답변.txt`

---

## 9. 사용자 답변 반영 (2026-05-14)

원본: `~/Desktop/답변.txt`

### 9-1. role 시스템 재정의 (가장 큰 변경)

| 항목 | 결정 |
|---|---|
| role '전체' | **삭제** — 구분 명확화 |
| 새 role 4종 | 영업 / 통관 / 정산 / **관리** |
| role '관리' 정의 | **서브관리자** — 일반 user에서 분기된 승인권자 (admin과 구분, admin은 최고관리자) |
| 시드 정책 | DB 더미라 시드 재생성 무방 — '박전체' 사용자 삭제·교체 |

### 9-2. role '관리'의 승인 권한 범위 (확정)

다음 4종 액션은 관리(또는 admin/super)만 진행 가능:

1. **G2 같은 바이어 미수 + 신규 거래** — 한도 표시 + 승인 모달
2. **정산 확정·지급 승인** — `settlement_status: pending → confirmed → paid` 전환
3. **민감 액션** — 차량 폐기 / RRN 수정 / B/L 수동 발행
4. **50% 룰 예외 진행** — 선수금 50% 미달 상태에서 통관/선적 진행

### 9-3. 승인 흐름 (1차 in-app 알림)

```
영업 [신규 거래 등록] 또는 [민감 액션] 시도
  ↓ Vehicle::saving 또는 라우트 미들웨어가 감지
"관리자 승인 필요" 모달 표시
  ↓ 영업 [승인 요청] 클릭
approval_requests 레코드 생성 (requester / action_type / vehicle_id / reason)
  ↓ 관리·admin·super의 알림 배지 표시
관리 [승인 / 거부] 결정 + 사유 입력
  ↓
영업에게 결과 알림 (in-app)
  ↓ 승인 시 영업이 다시 진행 / 거부 시 차단 유지
```

별도 모델 1개 + 알림 1개. 알림톡은 후행 큐.

### 9-4. G4 receivable role 무효화

- 정산 정·부 분리 안 함 → role '정산' 그대로
- 특정 문제 발생 시 role '관리'가 결정
- → **큐 14 (receivable role 신설) 무효** → §9-1 role 시스템 재설계로 흡수

### 9-5. G5 단순화

- 차대번호 컬럼 신규 X — NICE API 연동 시 자동
- 차량번호판은 이미 `vehicle_number` 컬럼 사용
- → 큐 15 공수 **6~8h → 4~6h** (재고 SQL + 새 화면만)

### 9-6. G6 채널 단순화 — 더 과감하게

- DB 더미 → **enum 값 'export'만 남김** (마이그레이션 1개)
- 헤이맨/카풀 데이터 변환 불필요 (시드 재생성)
- **카풀/헤이맨 전용 5컬럼 drop**: `tax_invoice_1_date`, `tax_invoice_1_amount`, `tax_invoice_2_date`, `tax_invoice_2_amount`, `agency_fee`
- 알림톡 별건 유지

→ 공수 4~8h. 시드 재생성 + 모든 분기 코드 정리. 마이그레이션 + Vehicle.php fillable + receivables/index 채널 탭 + vehicles/index 채널 필터 + admin/dashboard 채널 카드 + 정산 공식 채널 분기 등.

### 9-7. G7 confirm 모달 추가

- 차량 편집 창 close 시 변경 사항 폐기 = Livewire 기본 동작 (이미 됨)
- **변경 필드 있을 때만** "정말 닫으시겠습니까? 변경 사항이 사라집니다" 모달 추가
- Alpine `x-data` + dirty 플래그 + `beforeunload` 미사용 (browser tab close는 별개)

공수 1~2h.

---

## 10. 작업 큐 최종본 (v3, 답변 반영)

### 큐 변경 요약

| 큐 | v2 → v3 |
|---|---|
| 큐 14 (receivable role) | **삭제** — role 재설계로 흡수 |
| **큐 14 신규** | **role 시스템 재설계** (전체 삭제 + 관리 승인권 + approval_requests 모델 + in-app 알림) |
| 큐 15 (재고관리) | 차대번호 컬럼 제거 → 공수 4~6h |
| 큐 16 (채널 단순화) | UI 숨김 → enum 변경 + 컬럼 5 drop + 시드 재생성 → 4~8h |
| 큐 17 (G2 한도 배너) | 단순 배너 → **승인 메커니즘 (큐 14에 흡수)** → 큐 17 무효 |
| **큐 18 신규** | G7 confirm 모달 (변경 시만) — 1~2h |

### 최종 작업 큐 (우선순위 + 의존)

```
[즉시 시작 — 회계 인터뷰와 병행]
1. 큐 3 (담당자 필터 + 검색란 축소)              ~1h
2. 큐 16 (G6 채널 단순화 — enum + drop + 시드)   4~8h
3. 큐 11 확장 (G7 감사 로그)                      6~8h
4. 큐 18 (G7 close confirm 모달)                  1~2h

[role 재설계 — 4 액션 승인 메커니즘]
5. 큐 14 신규 (role 시스템 재설계)                12~18h
   - User.php ROLES 상수: '전체' 제거
   - 미들웨어 4종에서 '전체' 분기 제거
   - approval_requests 마이그레이션 + 모델
   - 4 액션(G2 / 정산 확정 / 민감 / 50% 룰 예외)에 승인 게이트
   - in-app 알림 (DB driver)
   - 관리 user 알림함·승인 UI

[Q1 회계담당자 인터뷰 후 — 분모 정의]
6. SKILLS.md §13 분모 정의 박기                   0.5h
7. 큐 9 확장 (G1 50% 룰)                          10~14h
8. 큐 10 + 확장 (G3 미수 분류)                    12~15h

[병렬 트랙 — Q1 무관]
9. 큐 15 (G5 재고관리, 차대번호 NICE 후로)         4~6h

[후속]
10. 큐 4 (관리자 대시보드 차트)                   4~6h
11. 큐 5 (업무 대시보드 토글)                     3~4h
12. 큐 7 확장 (RRN 형식·audit)                    4~5h

[외부 의존]
13. 큐 8 NICE API (차대번호·재고 자동화 통합)
14. 별건 1 G4 알림톡 (워크플로우 완성 후, 사용자 명시)
15. 별건 2 G7 동시 편집 락 (인프라 결정)           16~32h
16. 별건 3 사이드바 재구성                        2h
17. 큐 12 포워딩 SMTP / 큐 13 AWS 배포
```

**총 예상**: 5~6주 (1인 25~30h/주 기준). 큐 14 무효화 + G2 흡수 + G5 단순화로 v2 대비 ~1주 단축.

---

## 11. 사용자 결정 대기 → **v5에서 모두 해소**

### Q1 (분모 정의) — ✅ **확정 (v5, §12)**
- 분모 = `sale_total_amount` (부대비용 포함된 총판매액)
- 분자 = `sale_total_amount - (계약금+중도금+선수금1·2+잔금+채권회수이력)` (적립금 제외, 이미 코드 반영)
- → 회계담당자 인터뷰 **불필요** (이미 코드 정합 상태)
- claude/codex/advisor 셋 다 "sale_price 단독 가능성"을 잘못 제시했으나 사용자가 정정

### Q2 (안건 4 의미) — ✅ **확정 (v5, §13)**
- 옵션 i (이체) 방식
- 1번 차 입금에서 차감 + 2번 차 계약금으로 이체
- 관리자 승인 + 자금 이체 모델 필요

### S1~S6 사각지대 (advisor §5-4)
- S1 거래 취소 (B/L 발행 후) → 큐 9 확장 전 결정
- S2 환율 급변 시 50% 재산정 → 큐 9 확장
- S3 다중 통화 합산 표시 → 큐 10 확장
- S4 폐기 차량 재고 → 큐 15 (사용자 답변에서 G5 단순화로 부분 해소)
- S5 감사 로그 보존 기간 → 큐 11 확장
- S6 알림 거부 바이어 → 별건 1 (알림톡)

---

## 12. 분모 정의 확정 (v5, 2026-05-14)

### 단일 출처

```
sale_total_amount = sale_price + transport_fee + sale_other_costs
                  + commission + auto_loading - tax_dc
                  (부대비용 포함, app/Models/Vehicle.php:503)

sale_unpaid_amount = sale_total_amount
                   - deposit_down_payment          (계약금)
                   - interim_payment               (중도금)
                   - advance_payment1·2            (선수금)
                   - Σ finalPayments.amount        (잔금 N건)
                   - Σ receivableHistories(method≠deposit).amount
                   (savings_used 적립금 제외, app/Models/Vehicle.php:477)

unpaid_ratio = sale_unpaid_amount / sale_total_amount    (0~1 또는 null)
```

### 50% 룰 단일 게이트

```php
// 통관/선적 진입 차단
if ($vehicle->unpaid_ratio > 0.5) { reject; }

// B/L 인도 차단
if ($vehicle->unpaid_ratio > 0) { reject; }
```

### 5곳 정합

| 곳 | 출처 |
|---|---|
| 미납 게이지 | `unpaid_ratio` |
| 판매탭 미납률 % | `unpaid_ratio` |
| 채권관리 KPI | `sale_unpaid_amount` / `sale_unpaid_amount_krw_cache` |
| 관리자 대시보드 미수금 KPI | 동일 |
| **G1 50% 룰** | `unpaid_ratio` 단일 게이트 |

### SKILLS.md §13 갱신 필요 (별도 작업)
"미수율 분모 정의 (단일 출처)" 박스 추가:
- 분모 = `sale_total_amount` (부대비용 포함)
- 분자 = `sale_unpaid_amount` accessor (적립금 제외)
- 50% 룰 / 채권관리 / KPI / 게이지 모두 동일 출처

---

## 13. 안건 4 자금 이체 모델 (v5, 2026-05-14)

### 시나리오 (사용자 확정 의도)

```
[T=0] 바이어 X — 1번 차(1억) 계약, 5천만원(50%) 입금
       1번 차: 입금 5000 / 미수 5000 / ratio 50%

[T=1] 바이어 X — 2번 차 계약 의사. 영업이 관리자에게 승인 요청.

[T=2] 관리 승인 → 2500만원 이체 (1번 → 2번)
       1번 차: 입금 2500 / 미수 7500 / ratio 75%
       2번 차: 입금 2500 (계약금)

[T=3] 1번 차 선적 도착 → 바이어 잔금 7500만원 입금 → 1번 완납 → B/L 인도
```

### 한도

```
이체 금액 ≤ source_vehicle.(sale_total_amount - sale_unpaid_amount) × 0.5
        = source_vehicle 의 (현재까지 받은 금액) × 0.5
```

### 도메인 모델

#### 1) `inter_vehicle_transfers` 테이블 (신규)
```
id, source_vehicle_id, target_vehicle_id, amount, currency,
buyer_id (정합 검증용 — 양 차량 동일 바이어여야 함),
approval_request_id (FK → approval_requests),
status (pending / approved / executed / voided),
executed_at, voided_at, void_reason,
requester_id, approver_id, notes,
created_at, updated_at
```

#### 2) `approval_requests` 테이블 (큐 14에서 신설, 여기서 확장)
- action_type 추가: `inter_vehicle_transfer`
- payload에 source/target vehicle 정보

#### 3) 자금 이체 실행 (트랜잭션)

승인 시점에 트랜잭션 내:
1. 1번 차에 **음수 `final_payment`** 추가
   - amount = -2500만, payment_date = today, note = "→ 2번 차 이체 (관리자 승인 #ID)"
2. 2번 차에 **양수 `final_payment`** 추가 (또는 `deposit_down_payment` 갱신)
   - amount = +2500만, note = "1번 차에서 이체 (관리자 승인 #ID)"
3. `inter_vehicle_transfers` 레코드 status = `executed`
4. 양 차량 `sale_unpaid_amount_krw_cache` 자동 갱신 (Vehicle::saving 훅이 자동 처리)

**음수 final_payment 선택 이유**: 기존 회계 흐름 보존 + 추적 가능. `final_payments` 테이블에 nullable `transfer_id` 컬럼 추가하면 양 거래 짝짓기 가능.

### 안전 가드

- 양 차량 `buyer_id` 동일 검증
- 소스 차량 ratio ≤ 0.5 (= 50% 이상 받은 상태) 검증
- 이체 금액 ≤ 받은 금액 × 0.5 검증
- 한 번 executed 후엔 별도 voided 거래로만 취소 (append-only)
- 1번 차 거래 무산 시 voided 거래 자동 제안 (관리자 별도 승인 후 실행)
- 모든 거래는 감사 로그 (큐 11 확장)

### 큐 19 신규 (안건 4 전용)

|  | 내용 |
|---|---|
| 의존 | 큐 14 (approval_requests 모델·승인 흐름) |
| 작업 | inter_vehicle_transfers 마이그레이션 + 모델 + 한도 검증 + 트랜잭션 실행 + UI |
| UI | 영업 요청 모달 (소스 차 선택·금액 입력·사유) / 관리 승인 페이지 / 영업 결과 알림 |
| 공수 | 8~12h |

### 안건 3 (10% 계약금) vs 안건 4 (이체) 관계

| 케이스 | 룰 |
|---|---|
| **일반** (같은 바이어 미수 있음 + 새 거래) | 안건 3 — 신규 10% 계약금 필요 (실제 입금) |
| **특수** (1번 차 50% 받음 + 1번 차 + 5000만 한국 보유 + 같은 바이어 신뢰) | 안건 4 — 받은 금액의 50% 이체 가능 (관리 승인 필수) |

영업이 둘 중 선택 가능. 일반은 시스템 자동 룰, 특수는 관리 승인 게이트.

---

## 14. 최종 작업 큐 (v5)

### 큐 변경 요약 (v3 → v5)

| 큐 | v3 → v5 |
|---|---|
| Q1 인터뷰 대기 | **삭제** (v5 §12에서 확정) |
| 큐 9 확장 (G1 50% 룰) | **즉시 시작 가능** (Q1 무관) |
| 큐 10 확장 (G3 미수 분류) | **즉시 시작 가능** (Q1 무관) |
| **큐 19 신규** | 안건 4 inter_vehicle_transfers (큐 14 의존, 8~12h) |

### 최종 작업 큐 (우선순위 + 의존)

```
[즉시 시작 — 다른 큐 무관]
1. 큐 3 (담당자 필터 + 검색란)                  ~1h
2. 큐 16 (G6 채널 단순화)                       4~8h
3. 큐 18 (G7 close confirm)                     1~2h
4. 큐 11 확장 (G7 감사 로그)                    6~8h

[role 재설계 — 4 액션 승인 메커니즘]
5. 큐 14 (role 시스템 재설계 + approval_requests + in-app 알림)  12~18h

[Q1 확정 후 즉시 시작 가능 — 분모 sale_total_amount 정합]
6. SKILLS.md §13 분모 단일 출처 박스 추가        0.5h
7. 큐 9 확장 (G1 50% 룰)                         10~14h
8. 큐 10 + 확장 (G3 미수 분류)                   12~15h

[큐 14 의존 — 안건 4 특수 케이스]
9. 큐 19 (안건 4 inter_vehicle_transfers)        8~12h

[병렬 트랙]
10. 큐 15 (G5 재고관리, NICE 후로)                4~6h

[후속]
11. 큐 4 (관리자 대시보드 차트)                  4~6h
12. 큐 5 (업무 대시보드 토글)                    3~4h
13. 큐 7 확장 (RRN 형식·audit)                   4~5h

[외부 의존]
14. 큐 8 NICE API
15. 별건 1 G4 알림톡 (사용자 명시: 워크플로우 완성 후)
16. 별건 2 G7 동시 편집 락 (인프라 결정)
17. 별건 3 사이드바 재구성
18. 큐 12 SMTP / 큐 13 AWS 배포
```

**총 예상**: 5~6주 (1인 25~30h/주 기준)


---

## 🔗 참조
- `decision_protocol.md`
- `role기획보안_수정.md`
- `2026-05-12-workflow-gap-analysis.md` (큐 9·10·11 도출)
- `2026-05-13-progress-status-integrity.md` (rule_version v3 패턴)
- `SKILLS.md §9` (대시보드 카운트↔vehicles SQL 일치)
- `SKILLS.md §13` (도메인 공식)
- `CLAUDE.md` (기능 토글 `heyman_channel_enabled`·`carpul_channel_enabled`)
