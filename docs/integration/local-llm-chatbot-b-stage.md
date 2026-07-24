# 로컬 LLM 챗봇 — B단계(매출·미수금 조회) 설계 (car-erp 소유)

> 작성: car-erp 세션, 2026-07-24. 원 인계장 = `board/meetings/handoff-car-erp-local-llm-chatbot.md`(board 발행).
> 이 문서 = **car-erp가 소유하는 B단계 설계 + Jin 결정 대기 항목**. 아직 **미구현**(설계 제안). Jin이 배치·인프라·범위 확정 후 착수.
> ⚠️ 크로스레포: car-erp 결정은 이 커밋된 문서에 남긴다. board 파일/DB는 안 건드린다.

## 0. 배경 (board PoC에서 확인된 것)
- 완전 로컬 챗봇 PoC = `C:\Users\User\llm-poc\`. Ollama(`localhost:11434`) + `qwen3:8b`(LLM) + `bge-m3`(임베딩). 데이터 외부전송 0.
- **A단계(업무 가이드 Q&A = RAG)** 완료·실동작. Notion "사내 업무 가이드" → `index.json` → 질문 임베딩 → 코사인 top-3 → LLM "참고자료 근거로만 답". ERP 가이드도 이미 색인됨.
- **B단계(매출·미수금 등 DB 숫자 조회)** = 미착수. **성격이 A와 완전히 다름** — 문서 검색이 아니라 **원장에서 실제 숫자 조회**. 숫자 틀리면 경영판단 오류 → 정확도가 생명. 데이터는 car-erp 원장 소유 → car-erp가 설계·소유.

## 1. ⭐ 최상위 불변식 — 숫자는 LLM을 거치지 않는다
B의 정확도를 지키는 단 하나의 규칙:
```
LLM 역할 = "무엇을 묻는지"(의도) 라우팅만.  ❌ SQL 작성 금지  ❌ 숫자 생성/재기술 금지
car-erp 역할 = 검증된 조회 함수 실행 → 결과 숫자를 서버에서 문장 템플릿에 삽입.
```
- 8B 모델이 `1,299,999`를 다시 타이핑하면 언젠가 `1,300,000`으로 흘리거나 자릿수를 떨어뜨린다. **원시 숫자를 모델에 되먹이지 않는다.**
- 자연스러운 문장이 필요하면: (a) 생성 후 **플레이스홀더에 서버가 숫자 주입**, 또는 (b) LLM 출력의 모든 숫자가 쿼리 결과와 **정확히 일치하는지 검증**하고 불일치면 폐기/재시도. 기본은 (a) 서버 템플릿.
- **해석된 질의를 사용자에게 되비춘다**("이번 달 전체 미수금 총액을 조회합니다") → 라우팅 오류가 조용히 숨지 않고 눈에 보이게.

## 2. 배치 결정 (권장 = 하이브리드, car-erp가 B 소유)
| 안 | 내용 | 판정 |
|---|---|---|
| (a) 독립 어플라이언스 | PoC 그대로 standalone PHP, 위젯만 임베드 | A엔 OK. **B엔 부적합** — car-erp 권한(본인격리·SoD)·DB·감사를 재구현해야 함(중복·위험) |
| (b) car-erp 내장 | 챗봇을 car-erp 컴포넌트/엔드포인트로 | **B에 적합** — car-erp User·권한·Eloquent·AuditLog 그대로 활용 |
| **하이브리드(권장)** | **LLM 엔진=GPU 호스트 공용. A=standalone 유지(board 공용). B=car-erp 엔드포인트, 위젯이 라우팅** | board가 A를 계속 공유 + B는 car-erp 권한/DB로 안전. 인증 중복 없음 |

**권장**: A는 standalone(board·erp 공용) 유지, **B는 car-erp가 제공하는 서버 엔드포인트**로. 위젯이 질문 성격에 따라 A(문서)/B(DB)로 라우팅. (A도 car-erp로 옮길지는 Jin 결정 — 아래 §7.)

## 3. B 파이프라인 (function-calling)
```
사용자 질문 (car-erp 로그인 세션)
  → ① 권한 게이트 (super/admin/관리만, v1)
  → ② 의도 라우팅: 질문 + [조회함수 스키마들] → Ollama(qwen3:8b tools) → {함수명, 인자}
  → ③ 검증: 함수명 화이트리스트 확인 + 인자 타입/범위 검증 (실패=사용자에 재질문, 추측 금지)
  → ④ 실행: car-erp가 그 함수를 DB에 실행 (기존 서비스/스코프 재사용, LLM SQL 절대 아님)
  → ⑤ 서버 템플릿에 숫자 삽입 → 답 (+ 해석된 질의 되비춤)
  → ⑥ AuditLog 기록 (누가·질문·라우팅된 함수·시각)
```
- A(RAG)와 **분리된 파이프라인**. 같은 위젯에서 라우팅만.
- LLM tool-calling 실패/모호 시 = **추측 금지**, "무엇을 조회할지 명확히" 되물음.

## 4. 조회 함수 카탈로그 (v1 초안 — 기존 코드 재사용)
LLM은 아래 중 하나를 고르고 인자만 채운다. 함수 본문은 **대시보드와 같은 truth**를 써서 챗봇↔대시보드 불일치 방지.

| 함수 | 반환 | 재사용 소스 |
|---|---|---|
| `receivable_total(scope=all\|before_shipping\|after_shipping)` | 미수금 총액(KRW) | `Vehicle::scopeAction('receivable_*')` + `sale_unpaid_amount_krw_cache` |
| `sales_this_month()` | 이번 달 매출/대수 | 대시보드 `buildSalesKpis` 로직 |
| `inventory_status()` | 재고 대수·원가총액 | `scopeInStock`/`scopeGeneralStock`/`scopePreShippingStock` |
| `capital_status()` | 통장현금·굴리는자금·(손익) | `CapitalStatusService::derive(latest())` |
| `settlement_pending()` | 정산 대기 건수·금액 | settlements 쿼리 |
| `salesman_performance(name)` | 담당자별 실적/미수 | SalesmanResolver + KPI |

v1은 **집계(aggregate)만** — 개별 차량/바이어 목록 반환 X (IDOR 표면 최소화).

## 5. 권한·감사·보안
- **v1 = super/admin/관리 + aggregate-only.** 새 read 표면이므로 SKILLS #26(IDOR) 교훈 그대로. 목록·개별건 반환 안 함.
- **영업 확장은 나중에** — 그때 조회함수는 반드시 `User::canScopeVehicle`/본인격리를 태운다(지금 박아둠, 나중 소급 금지).
- **감사**: `AuditLog::recordEvent`로 누가·무슨 함수·언제. 질문 원문도 기록.
- 완전 로컬 유지: LLM 호출은 Ollama(사내 GPU)만. 외부 API 0.

## 6. ⚠️ 인프라 결정 (배치를 가르는 포크 — Jin 결정 필요)
car-erp는 Lightsail(GPU 없음), Ollama는 GPU 데스크톱에만. **B가 프로덕션 사용자에게 돌려면 car-erp(클라우드)가 사내 GPU의 Ollama에 닿아야 함.**
- **선례 있음** = `project_wonbu_lookup`의 **WireGuard 분리 터널**(사무실 ASUS 공유기). car-erp가 이미 사내 머신을 터널로 조회함. 같은 패턴 적용 가능.
- **트레이드오프**: 상시 GPU 박스 + 상시 터널 필요. GPU 꺼지면 챗봇 다운. → **이걸 감수할지가 (b) 프로덕션 내장의 전제.** Jin 결정.
- 대안: 초기엔 **사내(GPU 박스 닿는 곳)에서만** B 사용(프로덕션 미노출) → 검증 후 터널로 확장.

## 7. 단계화 — 한 함수 수직 슬라이스 먼저
전체 카탈로그를 짜기 전에 **위험 경로 1개**부터 증명:
```
이번달_미수금_총액 1개 함수 → 의도라우팅 → 검증쿼리 → 서버포맷 답 → AuditLog → super/관리 게이트
```
이게 두 미지수를 싸게 검증: (1) **qwen3:8b tool-calling 라우팅 신뢰도**, (2) **터널**. 8B 라우팅이 불안하면 → LLM tools 대신 **키워드/의도 분류기**로 폴백(카탈로그 다 짜기 전에 피벗 가능).

## 8. Jin이 결정할 것 (착수 전)
1. **배치**: 하이브리드(권장, B=car-erp 엔드포인트) vs 완전 car-erp 내장 vs standalone.
2. **인프라**: 프로덕션 노출(→ WireGuard 터널 + 상시 GPU) vs 사내 전용 먼저.
3. **A 이동 여부**: A는 standalone 유지(board 공용) vs car-erp로 흡수.
4. **v1 함수 범위**: §4 6개 중 무엇부터. (권장: 미수금 1개 슬라이스.)
5. **위젯 위치**: car-erp 어느 레이아웃/권한에 띄울지.

→ 결정되면 §7 슬라이스부터 car-erp에 구현.

## 참조
- 원 인계장: `board/meetings/handoff-car-erp-local-llm-chatbot.md`
- PoC 코드: `C:\Users\User\llm-poc\{config,sync,rag,index}.php`
- 터널 선례: 메모리 `project_wonbu_lookup` (WireGuard 사내 분리터널)
- 재사용: `CapitalStatusService`, `AuditLog::recordEvent`, Vehicle scopes, 대시보드 KPI 빌더
