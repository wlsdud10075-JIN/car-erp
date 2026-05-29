# car-erp 의사결정 프로토콜 (Round-Table Review)

> 작성: 2026-05-12 (1인 개발자가 Claude / Codex / Gemini와 라운드테이블로 협의하기 위한 운영 가이드)
> 대상: SSANCAR LTD. 중고차 해외수출 사내 ERP (`C:/xampp/htdocs/car-erp`)
> 기반 자료
>   - 구버전 `decision_protocol.md` (외부 판매 SaaS 컨텍스트 — 본 문서로 폐기·덮어쓰기)
>   - Gemini 메타 비평 (프로토콜 자체 결함 진단)
>   - Codex car-erp 실증 시뮬레이션
>   - Claude 컨텍스트 어댑테이션 (CLAUDE.md / CLAUDE_1.md / SKILLS.md / role기획보안_수정.md 풀 로드)
>   - 2026-05-11 3-model 크로스 체크 최종결과보고 — 실증된 재발 패턴을 §6·§7에 반영

---

## 0. 이 프로토콜의 존재 이유

car-erp는 **1인 개발자가 SSANCAR 사내 ERP를 운영 중인 Python ERP에서 Laravel로 이관 중인 프로젝트**다. 코드·기획·검수·배포 한 사람이 다 한다.
→ 단독 판단의 사각지대가 누적되면 회계·개인정보·도메인 공식이 한 번에 깨진다.
→ Claude / Codex / Gemini를 **각자 다른 시각의 가상 부서 구성원**으로 두고, **결정 무게가 큰 안건만** 라운드테이블로 돌리는 게 본 프로토콜의 목적이다.

구버전(외부 판매 SaaS · dothome · EcountERP · AS/풀번들 단가) 전제는 car-erp와 어긋났다. 본 문서는 그 v1을 폐기하고 car-erp 현실(AWS Lightsail · NICE API · 포워딩 SMTP · 사내 직원 role · RRN 개인정보)에 맞춰 재구성한 정식 버전이다.

---

## 1. 역할 구성 — 코어 5 + 가변 1

> 재무 역할은 의도적으로 제외. car-erp는 사내 ERP라 단가·패키징 관점이 의사결정에 영향 없음. 외부 비용(NICE API 호출비, AWS Lightsail 인스턴스비)은 Ops 역할의 점검 항목으로 흡수.

### 코어 5 (풀회의 발언 의무)

#### 📋 PO — 사용자·범위
- 관점: SSANCAR 내부 사용자(영업/통관/정산/관리) 가치 / 우선순위 / `role기획보안_수정.md`의 다음 작업 큐와의 충돌 여부
- 핵심 질문
  - "이걸 안 하면 어느 role에서 누가 막히는가?"
  - "지금이 아니어도 되는가? 그렇다면 언제?"
  - "다음 작업 큐 중 어디에 끼워 넣을지?"

#### ⚙️ Engineer — Laravel · Volt · MySQL
- 관점: 구현 난이도 / 마이그레이션 / Volt `#[Layout]` / Flux 재사용 / `progress_status_cache` 영향 / 캐시 컬럼 재계산 / 예상 공수
- 핵심 질문
  - "롤백 SQL 1줄로 쓸 수 있는가?"
  - "bulk update/delete가 끼면 `refreshProgressCache()` 명시 호출 자리 있는가? (`SKILLS.md §2`)"
  - "my-crm에 같은 패턴이 있는가? 재사용 가능한가?"
  - "예상 공수 시간 단위로 추정"
  - "Eager Load(N+1) 누락 없는가? — 특히 `receivableHistories`·`finalPayments`·`purchaseBalancePayments`"

#### 🧪 QA & Domain Integrity (car-erp 특수)
- 관점: 회귀 / 엣지 / **도메인 정합성**
- car-erp 의무 점검
  - **대시보드 카운트 ↔ vehicles 목록 SQL where 100% 일치** (`SKILLS.md §9`의 action 파라미터 패턴)
  - **차량 10단계 분기** — 우선순위 평가 / `progress_status_cache` 동기화 / 판매완료 vs 수출통관중 충돌
  - **정산 마진 공식** — VAT = `purchase_price × 0.09` (Python의 ×0.1 아님, 엑셀 실측)
  - **다중통화 / 환율 미입력** — `sale_unpaid_amount_krw_cache` 0이 완납으로 오판될 위험
  - **채권 미수금 / 회수 이력 ↔ final_payments 양방향 미러링**
  - **채널 분기** — 헤이맨/카풀은 수출통관·B/L·DHL 흐름 없음. action·문서 생성·정산 공식 모두 `sales_channel='export'` 격리 필요
  - **SQL ↔ Collection 필터 일치** — 같은 카운트를 두 방식으로 계산할 때 동일 결과 보장
- 핵심 질문
  - "어느 도메인 공식·캐시가 깨질 수 있는가? (`SKILLS.md §13` 공식 영향 분석)"
  - "수동 회귀 테스트 시나리오 몇 분?"
  - "다중통화 / 환율 0 케이스는?"
  - "정산·10단계·미수금 등 핵심 공식 변경 시 Unit Test 있는가?"

#### 🔒 Security & Compliance
- 관점: 개인정보 / API 키 / 권한 미들웨어 / 망법·개인정보보호법
- car-erp 의무 점검
  - **`nice_reg_owner_rrn`** (주민·법인등록번호) — `encrypted` cast 또는 별도 암호화. 평문 저장 시 무조건 NO-GO
  - **문서 다운로드** (`/erp/vehicles/{id}/documents/{type}`) — admin 이상 제한. PDF에 RRN 포함되므로 일반 user URL 직접 접근 차단
  - **`NICE_API_KEY` / SMTP 비밀번호** — `.env`만, `config/services.php` 경유, 로그에 평문 노출 금지
  - **권한 미들웨어** (super-admin / admin / erp / sales / clearance / settlement 6종) — 라우트 노출 누락 여부
  - **export 채널 문서 격리** — Invoice / Sales Contract / RO·con CIPL은 `sales_channel='export'` 차량만 허용
- 핵심 질문
  - "이번 변경이 개인정보 컬럼을 만들거나 노출하는가?"
  - "API 키·비밀번호가 코드·로그·git에 들어가는가?"
  - "권한 미들웨어 누락 라우트 있는가?"

#### 🚀 Ops & Deploy
- 관점: AWS Lightsail / queue worker / 백업 / Python ERP 병행 / 캐시 컬럼 / 환경 의존성
- car-erp 의무 점검
  - **AWS Lightsail** 전제 (dothome 무관)
  - **queue worker** — 포워딩 SMTP · NICE API · PDF/Excel 비동기 생성 시 `queue:work` 상시 실행 필요
  - **Python ERP 병행 운영** — 마이그레이션 기간 동안 양쪽 인스턴스 운영 + 데이터 이관 절차
  - **`progress_status_cache` rebuild** — bulk SQL 직후 `php artisan vehicles:rebuild-progress-cache` 필요 여부
  - **환경 의존성** — `extension=zip` / `extension=gd` (PhpSpreadsheet) / `extension=bcmath` (환율 × 외화 소수 정밀) / Noto Sans KR 사전 서브셋 (`SKILLS.md §8 #16~#20`)
  - **APP_DEBUG=false / HTTPS / DB 자동 백업 / 파일 업로드 백업**
- 핵심 질문
  - "다운타임 몇 초?"
  - "롤백 백업 시점?"
  - "Python ERP와 데이터 충돌·중복 입력 없는가?"
  - "동기 처리가 다수 요청에 병목인가? (PDF/Excel은 Queue로 빼야 하는가)"

### 가변 1 (안건 따라 발동)

#### Specialist — 안건별 교체

| 안건 키워드 | 가변 슬롯 | 역할 |
|---|---|---|
| 신규 화면 / 모바일 / 슬라이드 패널 | **UX 설계자** | `SKILLS.md §11` 페어 렌더 / `md:768px` 분기 / Searchable Select 검토 |
| 마이그레이션 / 정산 공식 / 캐시 컬럼 | **데이터 무결성** | 기존 row 영향 / Python ERP 이관 / 회계 일치 / retroactive risk |
| NICE API / SMTP / DHL | **외부 의존성** | API 폴백 / 키 관리 / 비용 / 캐시 |
| my-crm 패턴 재사용 | **참조 일관성** | my-crm 출처 검증 / 차이점 명시 / SKILLS.md 등록 |

---

## 2. 회의 강도 — 라이트 / 풀 2단계

모든 안건을 풀회의로 돌리면 라이트한 결정에도 부담이 커진다.

| 강도 | 대상 안건 | 참여 역할 | 발언 길이 |
|---|---|---|---|
| **라이트** | 단일 화면 추가 / 단일 컬럼 추가 / UI 위치 변경 / 단일 API 호출(단방향) / 뱃지 색 매핑 추가 | PO + Engineer + Security (3역할) | 2줄 |
| **풀** | 마이그레이션 / 정산 공식 변경 / 10단계 우선순위 변경 / NICE API·SMTP 연동 / 권한·role 변경 / Python ERP 데이터 이관 / AWS Lightsail 배포 | 코어 5 + 가변 1 (6역할) | 3~5줄 |

### 풀회의 강제 키워드 (사용자 메시지·변경 파일에 등장 시 자동 풀로 격상)

- `migration`, `schema`, `up()`, `down()`
- `progress_status`, `cost_total`, `vat_margin`, `sale_unpaid_amount`
- `permission`, `role`, `middleware`
- `NICE`, `SMTP`, `Mail::`, `forwarding_email_sent`
- `nice_reg_owner_rrn`, RRN
- `config/services.php`, `config/auth.php`

---

## 3. 자동 발동 트리거

### 사용자 키워드 (수동)
"회의 돌려줘" / "라운드테이블" / "/회의" / "부서별로 검토해줘"

### AI 자동 제안 — 2단계 (선제적, 단 강도 차등)

1인 개발자 짜증 방지를 위해 자동 제안은 **무게에 따라 라이트/풀로 차등 발동**한다.

**🔴 자동 풀회의 제안** (Claude가 먼저 "라운드테이블 돌려야 합니다" 발언)
- 마이그레이션 파일 신규 작성
- `Vehicle::getProgressStatusAttribute()` 변경
- 정산 마진 공식 (VAT 0.09 / cost_total 9개 컬럼) 변경
- `nice_reg_owner_rrn` 등 개인정보 컬럼 추가/변경
- `permission`·`role` 모델 변경
- `config/auth.php` 변경

**🟡 자동 라이트 제안** (Claude가 "3역할 라이트 한 번 짚어볼게요" 정도 발언)
- `.env`에 새 키 추가
- 라우트에 미들웨어 등록/제거
- `config/services.php` 변경
- 채권관리 권한 미세 조정 (admin 유지 범위 내)
- my-crm 코드 복붙

→ 사용자가 "그냥 가" 한 마디면 즉시 통과. 풀회의도 사용자가 거부하면 라이트로 격하 가능.

### 제외 (남용 방지)
단순 버그 / 오타 / CSS / 로그 / 변수명 / 주석 / `pint --dirty`

---

## 4. NO-GO 단일 거부권 — 대안 의무화

이전 버전의 약점: "한 역할이라도 NO-GO면 종합 GO 불가" → 1인 개발자가 자기 NO-GO를 그냥 무시하게 됨.

본 버전: NO-GO를 내는 역할은 **반드시 세 가지를 함께 제시**한다.

1. **(a) 차단 사유** — car-erp 맥락 기반 (단계·공식·캐시·권한 등 구체적으로)
2. **(b) 수용 가능한 최소 조건** — "이거 셋이 갖춰지면 조건부 GO로 전환 가능"
3. **(c) 대안 1개** — 같은 사용자 문제를 다른 방식으로 푸는 안

→ 셋 중 하나라도 빠지면 NO-GO는 자동 무효, "우려" 한 줄로 격하.
→ 진짜로 막아야 할 NO-GO만 발동되도록 강제.

---

## 5. 응답 포맷

```markdown
# 📅 회의록: [안건명]
- 일시: YYYY-MM-DD
- 강도: 라이트 / 풀
- 안건 유형: [migration / 외부API / 권한 / 정산공식 / 10단계 / UI / 기타]
- 자동발동 여부: yes/no (트리거 키워드)

## 💬 역할별 발언

### PO
판정: GO / 조건부 GO / HOLD / NO-GO
발언: (라이트 2줄 / 풀 3~5줄)
다음 작업 큐 영향: (있다면 순위·일정)

### Engineer
판정: ...
발언: ...
공수 추정: ?시간
영향 파일: (예: app/Models/Vehicle.php, resources/views/livewire/erp/...)
캐시 rebuild 필요: yes/no

### QA & Domain Integrity
판정: ...
발언: ...
도메인 공식 영향: (해당 시 — VAT 9% / 10단계 / 다중통화 / 채권 미수금 등 어느 공식)
회귀 시나리오: (수동 테스트 N분)
Unit Test: (있는지 / 신규 필요 여부)

### Security & Compliance
판정: ...
발언: ...
개인정보·API키 영향: (해당 시 1줄)

### Ops & Deploy
판정: ...
발언: ...
다운타임: ?초
백업 시점: (롤백 가능 시점)

### Specialist [가변 슬롯명] (해당 시)
판정: ...
발언: ...

## 🚨 NO-GO 상세 (해당 시)
- 차단 사유:
- 수용 가능한 최소 조건:
- 대안:

## 🏁 최종 권고
판정: GO / 조건부 GO / HOLD / NO-GO
근거: (1줄, car-erp 맥락)
필수 선행 작업:
  -
조건(조건부 GO):
  -
보류 사유(HOLD/NO-GO):
  -

## 🔗 참조
- 관련 과거 회의록: meeting-YYYY-MM-DD-{slug}
- CLAUDE.md / SKILLS.md / role기획보안_수정.md 참조 섹션
```

---

## 6. 안건 타입별 의무 체크리스트 (재발 방지 통합)

형식적 GO를 막기 위해 안건 타입별로 **특정 역할이 무조건 짚어야 할 항목**을 명시한다. 4열 마지막 칸의 "재발 방지 점검"은 2026-05-11 3-model 크로스체크에서 실제 발견된 13개 이슈를 패턴화한 것으로, 해당 안건 회의 시 이 줄을 명시적으로 인용해서 점검한다. 빠뜨리고 GO를 내면 자동 조건부 GO로 격하.

| 안건 타입 | 의무 역할 | 의무 점검 항목 | 재발 방지 점검 (2026-05-11 보고 기반) |
|---|---|---|---|
| 마이그레이션 | Engineer + Ops | 롤백 SQL / 기존 row default / Python ERP 영향 | `progress_status_cache` rebuild 필요 여부 / 캐시 0 vs NULL 혼재 / `refreshCaches()` `DB::table()` 유지 (Eloquent `save()` 교체 금지) |
| 외부 API (NICE / SMTP / DHL) | QA + Security + Ops | API 실패 시 수동 입력 fallback / 재시도 정책 / queue worker 가동 | API 키·비밀번호 로그·git 평문 노출 / 캐시 TTL 5분 / queue 실패 시 저장 트랜잭션 영향 차단 |
| 개인정보 컬럼 추가/노출 | Security | `encrypted` cast / 접근 권한 미들웨어 / 로그·에러 마스킹 | **RRN 평문 저장 금지** (`nice_reg_owner_rrn`) / **문서 다운로드 admin 제한** (RRN 포함 PDF) |
| 권한·role 모델 변경 | Security + Engineer | 기존 사용자 영향 / 마이그레이션 시 default role / 미들웨어 누락 라우트 | 채권관리 admin 유지 |
| 정산 마진 공식 변경 | QA + 데이터 무결성 | VAT 9% 엑셀 실측 일치 / 기존 paid 건 retroactive / 신규 vs 전수 재계산 | 핵심 공식 Unit Test 필수 (`tests/Unit/`) / **채널별 분기** — `export_declaration_amount × exchange_rate`는 수출 전용, 헤이맨/카풀은 별도 공식 |
| 진행상태 10단계 변경 | QA + Engineer | `progress_status_cache` 동기화 / 대시보드 SQL where 일치 / 우선순위 평가 | 판매완료 vs 수출통관중 충돌 (수출 채널에서 `export_buyer_id + shipping_date` 입력 시 우선순위 기획 의도 명시) / 거래완료 일자 추적 시 `dhl_requested_at` 별도 컬럼 검토 |
| 채널별 분기 로직 (수출/헤이맨/카풀) | QA + Engineer | actionCounts에 `sales_channel='export'` 필터 / 문서 생성 라우트 격리 / 정산 공식 적용 가능 여부 | **export 채널 문서 격리** — Invoice / Sales Contract / RO·con CIPL은 `sales_channel='export'`만 허용 / clearanceNeeded·shippingNeeded·dhlNeeded 채널 미구분 버그 |
| 대시보드 카운트·필터 변경 | QA | 카드 카운트 ↔ vehicles 목록 SQL where 100% 일치 / SQL ↔ Collection 결과 동일 | 환율 0 외화 차량 NULL 처리 (`sale_unpaid_amount_krw_cache=0`이 완납으로 오판되지 않는지) / Eager Load 누락 (`receivableHistories`·`finalPayments`·`purchaseBalancePayments` with()) |
| my-crm 패턴 재사용 | PO + Engineer | 출처 검증 / 차이점 명시 / SKILLS.md 등록 | — |
| PDF/Excel 문서 생성 | Ops + Security | 채널 격리 / 폰트·PHP 확장 의존성 / RRN 포함 여부 | 동기 처리 병목 → Queue Job 분리 검토 (동시 사용자 증가 시 PHP 프로세스 점유) |
| AWS Lightsail 배포 | Ops + Security | HTTPS / APP_DEBUG=false / 백업 / queue worker / Python ERP 병행 | `bcmath`·`zip`·`gd` 확장 확인 / Noto Sans KR 사전 서브셋 |

**"특이사항 없음" 사용 규칙**: 사용 가능. 단 **이유 1줄 첨부 의무**.
예: "특이사항 없음 — 새 컬럼 nullable이라 기존 row 영향 없음"

---

## 7. 횡단 점검 (안건 무관 — 매 풀회의 시 1초 체크)

§6 표의 안건 타입에 매핑되지 않지만 회의마다 짚어야 할 항목.

- **음수 마진 처리** (UX): `total_margin < 0` 시 정산액·실지급액 음수 표시 → `max(0, ...)` guard 또는 경고 뱃지
- **대용량 드롭다운** (UX): 바이어/컨사이니 수천 건 시 Searchable Select 패턴
- **타임존** (Ops): `config/app.php` `Asia/Seoul` 유지 확인
- **`pint --dirty`** (Engineer): 커밋 전 포매팅 — 회의 결과 코드 작업 시 누락 금지

---

## 8. 회의록 저장 + 자산화

### 저장
- 경로: `docs/meetings/YYYY-MM-DD-{slug}.md`
- 풀회의 완료 시 AI가 **"저장할까요?" 자동 제안** (사용자 yes 시 저장)

### 인덱스
- `docs/meetings/INDEX.md`에 1줄 결정 로그 자동 추가
  ```
  - 2026-05-12 [HOLD] 정산 VAT 공식 변경 — QA NO-GO (엑셀 실측 검증 부재)
  ```
- 다음 회의 시작 시 INDEX.md를 먼저 grep해 **관련 과거 결정 자동 인용**

### CLAUDE.md 연동
- CLAUDE.md import 체인에는 본 프로토콜을 직접 import하지 않는다 (회의 진행 가이드는 코드 가이드와 성격이 다름)
- 대신 CLAUDE.md에 1줄만 추가 권장
  > "라운드테이블 안건 발생 시 `decision_protocol.md` 참조. 과거 결정은 `docs/meetings/INDEX.md`에서 검색."

### dev 브랜치 직접 커밋 컨텍스트
- `.md` 파일은 dev → master/demo 머지 시 제외 (CLAUDE.md 규칙)
- 회의록도 동일 — 운영/개발용. 데모 브랜치에는 안 들어감.

---

## 9. 시뮬레이션 예시

### 9-1. 라이트 예시 — 관리자 대시보드 카운트 카드 추가

**안건**: "관리자 대시보드에 '환율 미입력 외화 차량' 카운트 카드 추가. 클릭 시 차량 목록 이동."

**자동 발동**: 키워드 없음. 새 컬럼·공식·권한 변경 없음. → **라이트 회의** (PO + Engineer + Security 3역할, 2줄씩).

---

#### 📋 PO
조건부 GO. role기획보안_수정.md 정산 role 대시보드에도 동일 카드 예정이라 정합성 맞춰야 함.
**다음 작업 큐 영향**: 정산 role 대시보드 분기 시 같은 SQL 재사용 가능

#### ⚙️ Engineer
GO. `admin/dashboard.blade.php`에 SQL 1개 추가 — `where('currency','!=','KRW')->where('sale_price','>',0)->whereNull('exchange_rate')`. 공수 15분.
**캐시 rebuild**: no (단순 집계)

#### 🔒 Security
특이사항 없음 — 데이터 노출·권한 영향 없음. 카드 자체는 admin 대시보드라 권한 게이트 이미 통과.

#### 🧪 QA (라이트지만 자발적 발언)
조건부 GO. 클릭 시 vehicles 목록 이동한다면 `applyActionFilter()`에 `exchange_rate_missing` 액션 키 추가 필요. SQL where와 카드 카운트 100% 일치 검증 — §6 "대시보드 카운트·필터 변경" 행 인용.

---

#### 🏁 최종 권고
**조건부 GO**
**근거**: 카드 표시만은 OK. 클릭 → 목록 이동 추가 시 `applyActionFilter()` SQL where 정합성 검증 필수 (SKILLS.md §9 action 파라미터 패턴).
**조건**:
  - `applyActionFilter()`에 `exchange_rate_missing` 케이스 추가
  - 카드 카운트와 vehicles 목록 결과 건수 동일 확인 (수동 1회)

> **시간 추정**: 회의 1~2분 + 구현 15분 + 검증 2분. 1인 개발자 부담 최소.

---

### 9-2. 풀 예시 — 정산 VAT 공식 변경

**안건**: "정산 마진 부가세 공식을 `purchase_price × 0.09` → `(purchase_price + selling_fee) × 0.10`으로 변경"

**자동 발동**: 키워드 `vat_margin` + `정산 공식 변경` → **풀회의** 강제. 안건 유형: `정산공식`. 의무 발언: QA + 데이터 무결성.

---

### 📋 PO
조건부 GO. 정산 정확도가 SSANCAR 회계와 직결되므로 변경 사유가 명확해야 한다.
"왜 지금?"에 대한 답이 없으면 NO-GO. 다음 작업 큐(role 대시보드 분기 등)보다 우선 정당화 필요.
**다음 작업 큐 영향**: 없음 (병렬 진행 가능)

### ⚙️ Engineer
조건부 GO. `app/Models/Settlement.php`의 `getVatMarginAttribute()` 한 줄 변경. settlements 테이블 캐시 없음 (computed only).
**공수 추정**: 30분 (accessor + 테스트)
**영향 파일**: `app/Models/Settlement.php` + 단위 테스트 신규
**캐시 rebuild 필요**: no (computed)

### 🧪 QA & Domain Integrity
**NO-GO.**
- **(a) 차단 사유**: ① 엑셀 실측 검증된 `× 0.09` 공식(`CLAUDE.md`)이 새 공식과 일치한다는 증거 부재 ② 기존 `settlement_status=paid` 건 N건의 정산액이 변경되어 회계 불일치 ③ 다중통화 차량(USD/JPY)의 KRW 환산 후 부가세 적용 순서 미정의
- **(b) 수용 가능한 최소 조건**: ① SSANCAR 회계담당자 검증 + 엑셀 실측 재확인 ② 신규 차량만 적용하는 토글 (`settlement.vat_formula_version` 컬럼) ③ 기존 confirmed/paid 건은 잠금
- **(c) 대안**: 공식을 변경하지 않고 별도 컬럼 `vat_margin_alt` 추가해 두 값을 병행 표시 → 회계 검증 후 1년 뒤 전환

**도메인 공식 영향**: VAT 마진 (`SKILLS.md §5`) + 총마진 + 정산액 + 실지급액
**회귀 시나리오**: 거래완료 차량 20건 정산 계산 수동 검증 (15분)
**Unit Test**: 현재 없음 — 신규 작성 필수

### 🔒 Security & Compliance
특이사항 없음 — 데이터 노출·API 키 영향 없음. 단 회계담당자 검증 자료에 정산 raw 데이터 포함 시 RRN 마스킹 확인.

### 🚀 Ops & Deploy
조건부 GO. 캐시 없음. 단 settlement 인덱스 페이지 결과가 변하므로 사용자에게 사전 공지 필요. 다운타임 0초.

### 🔧 Specialist (데이터 무결성)
**NO-GO.**
- **(a)**: 기존 `settlement_status=paid` 건은 마감된 데이터. 공식 변경 시 과거 정산액이 retroactively 변하면 회계감사 불가.
- **(b)**: confirmed/paid 상태 settlement는 변경 잠금 + 신규 차량에만 새 공식 적용 + 마이그레이션 시점 명확 표기
- **(c)**: settlement 모델에 `vat_formula_version` 컬럼 추가 (default=1, 신규 차량은 2). accessor에서 version별 분기.

---

### 🚨 NO-GO 상세 종합
- **차단 사유**: 엑셀 실측 검증 부재 + 기존 paid 건 retroactive 변경 위험
- **수용 가능한 최소 조건**: ① SSANCAR 회계 검증 ② 기존 paid 건 잠금 ③ `vat_formula_version` 컬럼으로 신규만 적용
- **대안**: Specialist 제안 채택 — version 컬럼 + accessor 분기

### 🏁 최종 권고
**판정**: **HOLD**
**근거**: QA + Specialist 양쪽 NO-GO. car-erp 도메인 공식은 엑셀 실측·기존 데이터 회계 일치 둘 다 검증돼야 변경 가능.
**보류 사유**
  - 엑셀 실측 검증 미완료
  - 기존 settlement_status=paid 건 retroactive 변경 회계 risk
**필수 선행 작업**
  - SSANCAR 회계담당자에게 `(purchase_price + selling_fee) × 0.10` 공식 출처 확인
  - 기존 paid 건 N건 추출 + 변경 시 변화액 시뮬레이션
  - 결과에 따라 `vat_formula_version` 마이그레이션 안건으로 재상정

### 🔗 참조
- `CLAUDE.md` "부가세마진 = `purchase_price × 0.09` — Python ERP의 `sales_margin × 0.1`과 다름"
- `SKILLS.md §5` 정산 마진 computed 패턴
- `SKILLS.md §13` 핵심 비즈니스 로직 공식

---

## 10. 기존 문서와의 관계

### CLAUDE.md
- import 체인에는 추가하지 않음 (본 문서는 회의 진행 가이드라인이고 코드 가이드 아님)
- 1줄만 안내 추가 권장
  > "의사결정 무게가 큰 안건은 `decision_protocol.md` 프로토콜로 라운드테이블 회의. 과거 결정은 `docs/meetings/INDEX.md`."

### SKILLS.md
- 충돌 없음. SKILLS.md는 구현 패턴, 본 프로토콜은 의사결정 절차. 직교.
- SKILLS.md §8의 검증된 버그 패턴(#16~#20 dompdf 한글 / PhpSpreadsheet zip 등)은 **Ops 역할의 환경 의존성 점검 체크리스트로 자동 참조**

### CLAUDE_1.md
- 충돌 없음. 오히려 강화 관계 — "Think Before Coding" / "Simplicity First" / "Surgical Changes" 4원칙이 본 프로토콜 회의의 발언 기준과 호환

### role기획보안_수정.md
- 충돌 없음. PO 역할이 다음 작업 큐와 충돌 여부를 의무 점검

---

## 11. 적용 시작 체크리스트

- [x] 구버전 `decision_protocol.md`를 본 문서로 덮어쓰기 (외부 SaaS 컨텍스트 폐기)
- [ ] `docs/meetings/INDEX.md` 빈 파일 생성
- [ ] CLAUDE.md에 1줄 안내 추가 (§10 참조)
- [ ] 첫 풀회의 1건 실시 — 권장 안건: **"문서 다운로드 권한 admin 제한 + RRN 암호화"** (2026-05-11 보고에서 3-model 합의로 가장 시급한 보안 핫픽스)
- [ ] 1주일 후 §6 의무 발언 체크리스트 실효성 점검
- [ ] 1개월 후 가변 슬롯 활용 빈도 검토 — 사용 안 되는 슬롯은 코어로 격상 또는 제거

---

## 12. 출처 기여 요약

| 항목 | 주 기여 |
|---|---|
| Security & Compliance 신설 | Gemini (메타 비평) + Codex (car-erp 실증) 양쪽 합의 |
| 코어 5 + 가변 1 구조 | Gemini 4역할 제안 → Claude가 car-erp 컨텍스트로 확장 |
| NO-GO 시 (a)(b)(c) 의무화 | Gemini 제안 |
| Infra → Ops & Deploy 재정의 (dothome 제거) | Claude (car-erp 컨텍스트) |
| QA에 Domain Integrity 통합 | Claude (`SKILLS.md §9` 정합성 패턴 기반) |
| 자동 발동 키워드 (NICE/SMTP/VAT/10단계 등) | Claude (CLAUDE.md / SKILLS.md 기반) |
| 안건 타입별 의무 발언 체크리스트 (§6) | Claude (Gemini "형식적 GO 방지 강제력" 지적 반영) |
| 재발 방지 체크리스트 §7 | 2026-05-11 3-model 크로스 체크 최종결과보고 13개 이슈 패턴화 |
| 시뮬레이션 안건 (VAT 공식 변경) | Codex 실증 + Claude 재구성 |
| 회의록 자산화 + INDEX.md 인용 흐름 | Gemini 제안 + Claude (CLAUDE.md import 체인 컨텍스트) |

> 본 프로토콜은 1인 개발자가 Claude / Codex / Gemini를 가상 부서원으로 활용해 단독 판단의 사각지대를 메우기 위한 운영 가이드다. 코드 가이드(CLAUDE.md · SKILLS.md)와는 직교하며, 회의 결과만 `docs/meetings/`에 자산화된다.
