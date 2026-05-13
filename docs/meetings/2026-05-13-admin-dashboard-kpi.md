# 📅 회의록: 관리자 대시보드 통관·정산·채권 KPI 도출

- **일시**: 2026-05-13
- **강도**: 풀
- **안건 유형**: 대시보드 KPI 정의 + 채널별 분기
- **자동발동 여부**: no (사용자 명시 요청 — "회의 한번 열어서 도출")
- **회의 진행자**: Claude main (인-컨텍스트 진행, Agent subagent 미사용)

## 안건

A. 정산 탭에 인원(담당자)별 정산지급액 월별 토탈 차트 신설
B. 월별 차량대수 차트를 영업 탭 전용으로 이동
C. 채권 탭에 채권 많은 순 — 담당자/바이어/미납금/미납률 한 화면
D. 통관/정산 탭에 회사 대표급 핵심 KPI 도출

## 💬 역할별 발언

### 📋 PO
**판정**: 조건부 GO
**발언**: 대표가 한눈에 보는 게 핵심 — "현금 유출(정산지급)·현금 차단(미수금)·매출 지연(통관 정체)" 3축이 대표급 KPI의 본질. A·C는 사용자가 이미 요구한 명확 가치. D는 통관 정체 차량 수와 정산 마진율 평균이 가장 즉시 가치 ↑. 평균 통관 처리 일수는 정식 컬럼 없어 별건. 큐 5(업무 대시보드 토글)보다 우선 — 대표 시각이 일일 업무보다 결정 무게 위에 있음.
**다음 작업 큐 영향**: 큐 4 보강 (8-5·8-6·8-7·8-8 4커밋). 큐 5 시작 전 완료 권장.

### ⚙️ Engineer
**판정**: 조건부 GO
**발언**: A는 settlements.paid_at + salesman_id로 MONTH 그룹 — Settlement 모델 paid_at 컬럼 이미 존재. C는 vehicles.sale_unpaid_amount_krw_cache + salesman_id/buyer_id group + 환율 0 NULL 필터 — 캐시 컬럼 활용으로 가벼움. B는 tabWidgets 매핑만 조정. D 평균 통관 처리 일수는 `export_cleared_at` 컬럼 부재 → updated_at 추정 부정확. 마이그레이션 별건.
**공수 추정**: A 1h / B 5분 / C 2h / D 즉시 가능 항목 2h, 평균일수 별건
**영향 파일**: `resources/views/livewire/admin/dashboard.blade.php` + `tests/Feature/AdminDashboardTest.php`. 마이그레이션 없음 (D 평균일수 제외).
**캐시 rebuild**: no

### 🧪 QA & Domain Integrity
**판정**: 조건부 GO
**발언**:
- A 정확성: `paid_at NOT NULL` AND `settlement_status=paid`만 집계. confirmed/pending은 제외.
- C 미납률 정의 명확화: **미납률 = 미수금 KRW / 총판매액 KRW**. 환율 0인 외화 차량은 `sale_unpaid_amount_krw_cache=NULL`이라 분자·분모 둘 다 제외 (큐 2.5 C1 정책 일관).
- D 통관 정체: "판매완료(`sale_price>0 AND sale_unpaid<=0`) AND export_declaration_document NULL AND sale_date 30일 경과" — 임계값 30일은 SSANCAR 운영 관행 확인 후 조정 가능 변수로.
- D 평균 통관 처리 일수: 정식 컬럼 없이 updated_at으로 측정 시 다른 컬럼 수정에 오염됨 → **거부**. 별도 `export_cleared_at` 마이그레이션 후 진행.

**도메인 공식 영향**: 없음 (집계만)
**회귀 시나리오**: A·C 각 5분 수동, D 정체 차량 카운트 vs vehicles 목록 일치 검증 5분
**Unit Test**: AdminDashboardTest에 4건+ 신규

### 🔒 Security & Compliance
**판정**: GO
**발언**: 모두 admin 대시보드 노출이라 `admin` 미들웨어로 보호됨. 단 **인원별 정산지급액은 개인 보상 정보** — 추후 settlement role 분리 시점에 별도 권한 게이트 필요 (현재 admin/super만 접근이라 즉시 위험 없음). RRN·API 키 영향 없음.
**개인정보·API키 영향**: 없음

### 🚀 Ops & Deploy
**판정**: GO
**발언**: 다운타임 0초. 마이그레이션 없음 (D 평균일수 제외). 8-2·8-3과 동일 chunk + PHP-side 집계 패턴이라 SQLite/MySQL 양쪽 OK. 차트 4개 동시 노출 시 응답 시간 ↑ 우려 → tabWidgets로 탭별 분리 노출이라 한 화면 4차트 이내. lazy compute는 불필요.
**다운타임**: 0초
**백업 시점**: 코드만 변경 — 롤백 시 git revert

### 🔧 Specialist (UX 설계자)
**판정**: 조건부 GO
**발언**:
- C 채권 탭 "한 화면": 차트보다 **테이블 2개**(담당자 TOP10 / 바이어 TOP10) + 컬럼별 정렬이 명확. 미납률은 `%` 표기 + 미납률 ≥ 임계값(예: 50%) 행에 amber 배경. 위험도 4단(safe/caution/danger/critical) badge는 receivables 화면 패턴 재사용.
- D 카드 수 인지부담 우려: 통관 4개·정산 4개 등 한 탭에 너무 많이 노출하면 대표가 못 봄. **탭당 핵심 3개 + 보조 1~2개** 제한.
- 8-2 월별 차트 이동(B)은 영업 탭 + 전체 탭 둘 다 유지가 자연스러움 — 전체 탭이 "한눈에 모두"인데 차트 빠지면 빈약.

## 🚨 NO-GO 상세

- **QA NO-GO 1건**: 평균 통관 처리 일수 (D-3)
  - **차단 사유**: `export_cleared_at` 정식 컬럼 부재 → updated_at 추정은 다른 컬럼 수정에 오염되어 부정확
  - **수용 가능한 최소 조건**: vehicles 테이블에 `export_cleared_at` 마이그레이션 + `is_export_cleared` true 전환 시 자동 set + 기존 row backfill 전략
  - **대안**: 평균 통관 처리 일수 대신 **"통관 정체 차량 수"**(D-2) 즉시 노출 — 운영 alarm 가치는 거의 동일

## 🏁 최종 권고

**판정**: 조건부 GO
**근거**: A·B·C·D 즉시 가능 항목은 큐 4 보강(8-5·8-6·8-7·8-8 4커밋)으로 완료. 평균 통관 처리 일수는 별건 마이그레이션 안건으로 분리.

### 도출된 KPI 명단

**📦 통관 탭** (대표급)
1. **통관 정체 차량 수** (판매완료 + sale_date 30일 경과 + 수출신고서 NULL) ★ alarm
2. **수출신고서 미업로드 차량 수** (수출통관중 단계)
3. **포워딩사별 진행 차량 수** (상위 5사 horizontal bar)
4. ~~평균 통관 처리 일수~~ — `export_cleared_at` 컬럼 추가 후 별건

**💰 정산 탭** (대표급)
1. **인원별 정산지급액 월별 차트** (사용자 요청 A) ★
2. **정산 지급 대기 총액** (confirmed-not-paid의 actual_payout 합계)
3. **정산 마진율 평균** (총마진 / 판매가 KRW)
4. **채널별 평균 마진** (export / heyman / carpul)

**📊 채권 탭** (대표급, 사용자 요청 C)
1. **미수금 상위 담당자 TOP 10** (테이블: 이름 / 미수금 KRW / 미납률 % / 차량 수)
2. **미수금 상위 바이어 TOP 10** (테이블)
3. **위험도별 차량 수** (safe/caution/danger/critical 카운트 — receivables 화면 패턴 미러)

**📅 위젯 매핑 조정** (사용자 요청 B)
- 월별 차량 대수 차트(w-monthly) → **전체 + 영업** 탭에서만 노출. 통관·정산에서 제거.

### 큐 분할

| 큐 | 작업 | 공수 |
|---|---|---|
| **큐 4 8-5** | 정산 탭 — 인원별 정산지급액 월별 + 정산 KPI 카드 3종 | ~1.5h |
| **큐 4 8-6** | 채권 탭 — 미수금 상위 담당자/바이어 TOP 10 테이블 + 위험도 카운트 | ~2h |
| **큐 4 8-7** | 통관 탭 — 정체 차량 + 수출신고서 미업로드 + 포워딩사 TOP 5 | ~2h |
| **큐 4 8-8** | 위젯 매핑 조정 — 월별 차트 영업/전체 탭 한정 | ~10분 |
| **큐 보류** | 평균 통관 처리 일수 — `export_cleared_at` 마이그레이션 별건 | 별도 |

## 🔗 참조

- 큐 4 8-1 ~ 8-4 완료 (e9785ee·e5fc0c1·02fd855·8d47ce0)
- CLAUDE.md §정산 마진 공식
- `app/Models/Settlement.php` paid_at + confirmed_snapshot
- `app/Models/Vehicle.php` sale_unpaid_amount_krw_cache + receivable_risk
- 큐 9·10 (도메인 안전) 완료 — settlement·receivable 무결성 검증 완료 상태
