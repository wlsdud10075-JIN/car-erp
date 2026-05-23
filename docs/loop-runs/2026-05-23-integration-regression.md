# Loop Run — 운영 통합 회귀 (2026-05-23)

> Plan: `docs/loop-plans/2026-05-23-integration-regression.md`
> 모드: 자율 진행 (한 turn batch)
> 시작: 2026-05-23

## 진행 로그

- iter 1: case01 매입~거래완료 v4 cascade → ✅ pass
- iter 2: case02 거래완료 자동 Settlement + 프리랜서 default 50 → 🔧 retry (G1 가드 + decimal cast) → ✅ pass
  - 발견 1: `update(['bl_loading_location', 'bl_document'])` 한 번에 두 컬럼 update 시 finalPayments relation cache stale → G1 미수 100% 오인. case01 패턴(단계 분리 + `$v = $v->fresh()`)으로 수정.
  - 발견 2: `settlement_ratio` decimal cast 형식 차이 — `'50.00'` 문자열 정확 매칭 실패. `assertEqualsWithDelta(50.0, ..., 0.01)` 로 완화.
- iter 3: case03 paid 진입 시 secondary_status='pending' 자동 → ✅ pass
- iter 4: case04 외화 환차익 (입금 1300, close 1380, +diff) → ✅ pass
- iter 5: case05 프리랜서 actual_payout 환차 1:1 가산 → ✅ pass
- iter 6: case06 사내직원(per_unit) 환차 미반영 → ✅ pass
- iter 7: case07 관리자 대시보드 발생/회수/미수 정합 → ✅ pass
- iter 8: case08 SKILLS §13 단일 출처 (sale_unpaid_amount_krw_cache + unpaid_ratio) → ✅ pass
- iter 9: case09 관리 role 본인 부하 영업의 차량만 → ✅ pass
- iter 10: case10 영업 role 본인 차량만 + 다른 영업 차량 비노출 → ✅ pass

## 사용자 운영 시나리오 추가 (iter 11~17, 2026-05-23)

- iter 11: case11 관리 2 × 영업 5명 조직 격리 (관리A 부하 5명 차량만 / 관리B 격리) → ✅ pass
- iter 12: case12 USD/EUR 혼합 환차 (USD +40,000 / EUR -20,000) → ✅ pass
- iter 13: case13 2차 정산 기타비용 7개 변경 → cost_total +220,000 → actual_payout 감소 → ✅ pass
- iter 14: case14 외화 + 1차 환차 + 2차 기타비용 변경 통합 (USD 10,000, 환차 +800,000) → ✅ pass
- iter 15: case15 선적 전 영업별 재고 (관리A 부하 재고 + 선적중 제외 + 관리B 격리) → ✅ pass
- iter 16: case16 차량 sale_price 변경 → audit_logs row 자동 생성 (column_name='sale_price') → ✅ pass
- iter 17: case17 정산 status confirmed→paid 전환 → audit_logs 기록 → ✅ pass

## 종료 (2026-05-23, 17 case 완료)

- 총 17 case 시도 / **17 pass** / 0 skip / 0 fail
- 기존 회귀 419 → **436 passed** (+17 누적, 1060 assertions)
- production 코드 무수정 (A안)
- 1 신규 파일 (tests/Feature/IntegrationRegressionTest.php)
- 1 신규 로그 파일 (docs/loop-runs/2026-05-23-integration-regression.md)

## 캐리오버 박제 추가 (iter 18~20, 2026-05-23 — 새회의 #8 구현 후)

- iter 18: case18 환차익 +50,000 이월 → 다음 정산 +50,000 가산 → ✅ pass
- iter 19: case19 환차손 -30,000 음수 이월 → 다음 정산 -30,000 차감 → ✅ pass
- iter 20: case20 영업별 격리 (A 이월이 B에 영향 X) → ✅ pass

## 정합성·무결성 추가 (iter 21~25, 2026-05-23)

- iter 21: case21 다중 closed 누적 — #2가 #1 흡수 (30k) + #3가 잔액 흡수 (20k) → ✅ pass
- iter 22: case22 흡수 후 재흡수 X — 동일 영업담당자 후속 정산 잔액 0 → ✅ pass
- iter 23: case23 영업담당자 변경 후 격리 — 60k 이월이 영업 B에 영향 X → ✅ pass
- iter 24: case24 confirmed_snapshot 없는 closed → NULL 안전 fallback → ✅ pass
- iter 25: case25 누적 합 정확도 — 100k 흡수 + 25k 추가 closed → 25k만 신규 흡수 → ✅ pass

## 최종 종료 (2026-05-23, 25 case 완료)

- 총 25 case 시도 / **25 pass** / 0 skip / 0 fail
- 기존 회귀 → **444 passed** (+25 누적, ~1085 assertions)
- production 코드 무수정 외 캐리오버 마이그·모델·UI는 별도 안건 (`commit 0259055`)
- 정합성: SKILLS §13 단일 출처 / 발생·회수·미수 KPI / 환차 자동 반영 / 캐리오버 영업별 격리 — 모두 검증
- 무결성: 권한 scoping / audit_logs 추적 / 흡수 후 재흡수 X / 영업별 잔액 독립 — 모두 검증

### 통과 의미
**누적 11 commits 안정성 검증 완료**:
- 회의확장씬 12 안건 (Phase 1~3) + 별건3 흡수
- 새회의.txt #1 한글화 + #7 KPI 분리
- 큐 15 재고관리 (사용자 정정 포함)
- 이번 세션 KRW snapshot / 환차 자동 반영 / 환율 수동 입력 / 정산 화면 보강

세션 내 누적 작업이 통합 환경에서 깨짐 없음. **다음 단계 (NICE API → AWS 배포) 진행 자신감 확보.**

### 검토 권장 (사용자와 함께)
1. ✅ **전 케이스 통과** — 추가 시나리오 발견 시에만 plan §7 확장
2. **C 트랙 (Markdown 체크리스트)** — 사용자 직접 브라우저 클릭 검증 별도 필요
   - UI 시각·반응형·운영 직관성은 PHPUnit 검증 범위 밖
3. **미실측 영역**:
   - 운영 MySQL 환경 (테스트는 SQLite — DB grammar 차이 가능)
   - 실제 운영 데이터 정합 (마이그 후 tinker)
   - 외부 연동 (NICE / 알림톡 / SMTP — 모두 mock 또는 제외)

### 다음 단계
- 4순위 — 3-A 그룹셋 정식 (사용자 진행 요청)
- 그 후 NICE API (사용자 키 정보 받은 후)
- 마지막 AWS Lightsail 배포
