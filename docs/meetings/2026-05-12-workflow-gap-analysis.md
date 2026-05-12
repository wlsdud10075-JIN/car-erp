# 📅 회의록: 차량 워크플로우 누락 시나리오 종합 분석

- 일시: 2026-05-12
- 강도: 풀회의 (6부서 — PO + Engineer + QA + Security + Ops + Specialist UX)
- 안건 유형: 도메인 안전성·UX·보안·운영 횡단 진단
- 자동발동 여부: no (사용자 명시 요청 — "사용자 누락 시나리오 회의 통한 파악")

## 1. 안건 요약

차량 등록 → 매입 → 말소 → 판매 → 입금 → 통관 → 선적 → DHL → 정산 → 채권 회수까지 **사용자가 누락할 가능성이 있는 단계·필드·작업을 6부서 관점에서 종합 분석**. 큐 1번(role 분기) + 큐 2번(파이프라인 스트립) 완료 시점 기준.

## 2. 6부서 발언 핵심 요약

### 📋 PO
- Critical 3건: 환율 미입력 / 매입 잔금 payment_date NULL / 채널 모순
- role 인계 누락: 영업→통관(환율·export_buyer), 통관→정산(forwarding·면장금액)
- 권장: 큐 3.5번 — "차량 저장 validation 강화" 신설

### ⚙️ Engineer
- save() validation에 단계 간 일관성 검증 0 (단순 numeric/date 형식만)
- `vehicle_number` unique + soft-delete 조합 → 운영 첫 중복 시도 500
- Vehicle::saving 이벤트는 캐시만, soft-delete 시 캐시 stale
- N+1 위험 — receivableHistories eager load 누락 잠재
- 총 공수 12시간 (Critical 3h + High 5h + Medium 4h)

### 🧪 QA & Domain Integrity
- 11단계 평가 로직이 `sales_channel` 무관 → 채널 전환/단계 건너뛰기에서 Critical 5건
- 정산 retroactive drift — paid 후 입력값 변경 시 표시값 변함
- 채권 단방향 미러링 — final_payments → ReceivableHistory 자동 생성 안 됨
- Unit Test 신규 필요: ProgressStatus / SettlementMargin / SaleUnpaid / ReceivableMirroring / ChannelTransition (5개)

### 🔒 Security & Compliance
- RRN 형식 검증 0 (`123` 입력해도 저장됨) — PDF 무효 발급 가능
- 차량 편집에 컬럼 단위 권한 0 — 정산이 매입가·환율 임의 변경 가능
- 영업 role 본인 차량 격리 0 — URL `?id=N` 조작 가능
- 문서 다운로드 type별 role 매핑 부재 (admin·일반 user만 분기, type↔role 매핑 X)

### 🚀 Ops & Deploy
- **APP_KEY 재생성 시 RRN 영구 손실** — `php artisan key:generate` 무심코 실행 위험
- 자동 DB 백업 부재 (현재 storage/backups/에 수동 백업 3건만)
- forceDelete 시 storage 백업 없이 즉시 삭제
- 동기 PDF/Excel 생성 — Lightsail $5/mo 환경에서 동시 3건 OOM 위험
- queue worker 미가동 (큐 12 도입 시 동시 PR 머지 필요)

### 🔧 Specialist [UX 설계자]
- 신규 등록 직후 next-step 동선 zero (저장 후 토스트만)
- 흐름도 노드 warn/pending 사유 안내 부재 (왜 노란불인지 모름)
- 채널 변경 confirm 0 — export→heyman 변경 시 orphan 데이터 침묵
- 모바일 7탭 nav sticky 아님 → 탭 내 스크롤 시 탭 헤더 사라짐
- 환율 입력 옆 KRW 환산 미리보기 0

## 3. 🚨 Critical 통합 (8건 — 즉시 차단 필요)

| # | 시나리오 | 출처 | 영향 | 차단 제안 |
|---|---|---|---|---|
| **C1** | **외화 판매 + 환율 0/NULL** | PO·Eng·QA·UX | `sale_unpaid_amount_krw_cache=NULL` → KPI 침묵 누락 / 정산 공식 0원 / 채권 KRW 합산 빠짐 | 판매 탭 저장 시 `'exchange_rate' => required_if(currency!=KRW) + gt:0`. 통화 select `wire:model.live`로 KRW면 환율 disabled |
| **C2** | **매입 잔금 `payment_date` NULL** | PO | `getPurchaseUnpaidAmount` 필터에서 제외 → 미지급 0 표시 → 매입완료 오인 → 후속 단계 부정확 진행 | `purchase_balance_payments.payment_date` `required` 승격, 또는 "예정일자 입력 안 하면 미지급 계산 제외" 명시 |
| **C3** | **채널 모순 (export 필드가 heyman/carpul에 잔존)** | PO·QA·Eng·UX | progress_status가 채널 무관이라 헤이맨인데 `bl_document` 있으면 `선적완료`로 잘못 분류 → 카운트는 안 잡혀도 캐시 컬럼 dirty | (a) `getProgressStatusAttribute`에 `sales_channel==='export'` 분기 (코드 1줄 수정으로 가능) + (b) 채널 변경 시 confirm + 잔여 필드 reset |
| **C4** | **단계 건너뛰기 — 말소 안 됐는데 수출통관 진입** | QA | `is_deregistered=false`인데 export 필드 채워서 `수출통관완료`로 점프 → **불법 수출** 가능 | Vehicle saving에서 export 필드 채울 때 `is_deregistered=true && deregistration_document` 검증 → ValidationException |
| **C5** | **단계 건너뛰기 — 미입금 잔존인데 통관 진입** | QA | 11단계 우선순위가 통관(6)을 판매완료(7) 위로 → `판매중` 차량이 `수출통관중`으로 점프 | export 정보 입력 시 saving validator에서 `sale_unpaid_amount_krw_cache <= 0` 강제 또는 경고 모달 |
| **C6** | **`vehicle_number` unique + soft-delete 충돌** | Eng | 운영 첫 중복 등록 시 1062 IntegrityError → 사용자에 500 노출 | `Rule::unique('vehicles')->where(fn($q)=>$q->whereNull('deleted_at'))` 또는 마이그레이션 unique 인덱스를 `(vehicle_number, deleted_at)` 복합으로 |
| **C7** | **컬럼 단위 권한 0 + 영업 본인 차량 격리 0** | Security | (a) 정산 role이 매입가/환율 변경 가능 → 회계 조작 (b) 영업 user가 URL 조작으로 타 영업 차량 편집 가능 → 실적 가로채기 | (a) save() rules에 role별 화이트리스트 분기 (b) `openEdit()`에서 비-admin은 `where('salesman_id', $self)` 강제 |
| **C8** | **APP_KEY 재생성 시 RRN 영구 손실** | Ops | `php artisan key:generate` 무심코 실행 시 RRN 암호화 컬럼 영원히 복호화 불가 → 차량 소유자 RRN 영구 소실 | (a) `.env.example` 헤더 + README + CLAUDE.md에 "RRN 도입 후 key:generate 금지" 경고 (b) APP_KEY rotation 절차 문서화 |

## 4. ⚠️ High 통합 (15건 — 1주 내 패치 권장)

| # | 시나리오 | 출처 | 차단 제안 |
|---|---|---|---|
| H1 | `dhl_request=true` 체크하면서 B/L 미업로드 → 거래완료 점프 | Eng·QA | `'dhl_request' => declined_if(empty(bl_document))` |
| H2 | `is_export_cleared=true` 체크하면서 수출신고서 미업로드 | Eng | 동일 패턴 |
| H3 | 정산 settlement_type=ratio인데 settlement_ratio NULL | QA | confirm 시 ratio/per_unit 둘 중 하나 > 0 강제 |
| H4 | 정산 retroactive drift — paid 후 sale_price/cost/환율 변경 시 표시값 바뀜 | Eng·QA | settlement에 `confirmed_snapshot` JSON 컬럼 (sales_amount_krw, exchange_rate, purchase_price 등) — 변경 잠금 |
| H5 | 채권 단방향 미러링 — final_payments → ReceivableHistory 자동 생성 안 됨 | QA | FinalPayment saved에서 ReceivableHistory(method=deposit) auto-create |
| H6 | savings_used > 0인데 SavingsStatus(method=USED) 거래 미생성 → 이중 사용 가능 | QA | Vehicle saving에서 savings_used delta 감지 → SavingsStatus 자동 생성 |
| H7 | Vehicle soft-delete 시 캐시 stale → 대시보드 카운트 오염 | Eng | `static::deleted` 추가해 캐시 컬럼 NULL 또는 scope에 `whereNull('deleted_at')` 명시 |
| H8 | N+1 — receivableHistories eager load 누락 잠재 | Eng | 대시보드/목록 query에 `with(['finalPayments','receivableHistories','purchaseBalancePayments'])` 통일 |
| H9 | RRN 형식 검증 0 (정규식 없음) | Security | `'nice_reg_owner_rrn' => regex:/^\d{6}-\d{7}$/` |
| H10 | 말소 단계 진입 시 RRN 미입력 차단 0 → PDF 빈칸 발급 | Security | saving 이벤트에서 `is_deregistered=true && RRN empty` 차단 |
| H11 | RRN input 평문 `type="text"` — 어깨너머 노출 | Security | password type + Alpine 토글 / 마스킹 표시 |
| H12 | 자동 DB 백업 부재 | Ops | Lightsail Automatic Snapshot 일 단위 + mysqldump cron → S3 30d retention |
| H13 | 흐름도 노드 warn/pending 사유 안내 부재 | UX | `progressFlow()` 반환에 `reason` 키 추가 → tooltip 표시 |
| H14 | 신규 등록 직후 next-step 동선 zero | UX | 저장 후 토스트에 "매입 입력 →" 버튼 + tab 자동 전환 |
| H15 | 모바일 7탭 nav sticky 아님 | UX | `sticky top-0 z-10 bg-white` 적용 |

## 5. ⚪ Medium/Low 요약 (30+건)

- 동기 PDF 생성 OOM 위험 (Ops): Lightsail Bundle Plus 권장 + 추후 Queue Job 분리
- 카풀 채널 `tax_invoice_1_date`/`agency_fee` 필수 미강제 (QA)
- `export_declaration_amount`/`transport_fee` NULL 시 정산 음수 (QA·Eng)
- 환율 입력 옆 KRW 환산 미리보기 부재 (UX)
- 폐기 차량 흐름도 disabled 상태에서 해제 방법 안내 부재 (UX)
- 흐름도 ↔ 11단계 진행상태 매핑 명시 부재 (UX)
- 대시보드 → 편집 패널 시 탭 자동 전환 부재 (`?tab=sale` 같은 URL param) (UX)
- ... 외 다수

## 6. 🏁 종합 권고 — 큐 우선순위 조정 제안

### 새 큐 2.5번 신설 — **"워크플로우 누락 차단 1차 패치 (Critical 8건)"**

**근거**: Critical 8건 중 5건(C1·C2·C3·C4·C5)은 **데이터 무결성·도메인 정합성 직결**. C6(unique+soft-delete)는 운영 직후 사고 1건만 나도 사용자 신뢰 큰 손상. C7(컬럼 권한)은 회계 조작 가능성. C8(APP_KEY)는 RRN 영구 손실 — 비가역 데이터 사고.

**범위**:
1. **C1·H1·H2** — `vehicles/index.blade.php::validationRules()`에 단계 의존성 검증 추가
   - `exchange_rate` required_if(currency!=KRW) + gt:0
   - `dhl_request` declined_if(empty bl_document)
   - `is_export_cleared` declined_if(empty export_declaration_document)
2. **C2** — `purchase_balance_payments.payment_date` `required` 마이그레이션 또는 UI에서 강제
3. **C3** — `Vehicle::getProgressStatusAttribute`에 `sales_channel==='export'` 분기 (export/bl/dhl 단계는 export 채널만 평가) + 채널 변경 시 confirm 모달
4. **C4·C5** — Vehicle saving 이벤트에서 단계 간 의존성 검증 throw
5. **C6** — `vehicle_number` 마이그레이션을 `(vehicle_number, deleted_at)` 복합 unique로 변경
6. **C7** — `openEdit()`에서 영업 role 본인 차량 격리 + save()에서 role별 컬럼 화이트리스트
7. **C8** — CLAUDE.md / README / .env.example에 "RRN 도입 후 APP_KEY 변경 금지" 경고 + 운영 체크리스트

**공수 추정**: 6~8시간 (Critical만, High 미포함)
**Unit Test 신규**: VehicleProgressStatusTest / ChannelTransitionTest / VehicleValidationTest 3종

### 큐 7번 확장 (이미 권한 세분화 안건 — 잔여 작업 통합)
- 컬럼 단위 권한 (C7-a)
- 영업 본인 차량 격리 (C7-b)
- RRN 형식 검증 (H9)
- RRN 입력·수정 audit log

### 새 큐 — **"정산·채권 무결성 보강"** (High 4건 묶음)
- H3 정산 type validation
- H4 retroactive drift 잠금
- H5 final_payments ↔ ReceivableHistory 양방향 미러링
- H6 savings_used 자동 거래 생성

### 새 큐 — **"운영 안전 가드"**
- C8 APP_KEY 가드 문서
- H12 자동 DB 백업
- forceDelete storage 백업
- queue worker 가동 (큐 12와 동시)

### 큐 6번 확장 (이미 흐름도 완료 — 인터랙션 보강)
- H13 노드 reason tooltip
- H14 next-step 동선
- H15 모바일 sticky

## 7. 다음 단계 결정 필요

사용자가 결정해야 할 사항:
1. **큐 2.5번 신설 + 즉시 착수** — Critical 8건 1차 패치
2. **Critical 중 일부만 우선** — 예: C1·C3·C4·C5만 (도메인 정합성 5건) 먼저, C6·C7·C8은 별도
3. **회의록만 자산화 + 큐 3번(차량관리 담당자 필터)부터 계속** — 워크플로우 누락은 알면서 진행
4. **다른 우선순위 조합**

## 🔗 참조

- 직전 회의: `docs/meetings/2026-05-12-rrn-encryption-document-permission.md` / `docs/meetings/2026-05-12-user-dashboard-role-branching.md`
- 패턴: `SKILLS.md` §2 캐시 / §5 정산 마진 / §9 action 파라미터 / §10 디자인 시스템 / §11 모바일 / §13 핵심 공식
- 도메인: `CLAUDE.md` 11단계 / 정산 공식 / 채널 3종 / 권한·role
- 기획: `role기획보안_수정.md` §10 작업 큐
