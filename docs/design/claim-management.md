# 클레임(파손보상) 관리 — 기획안

> 상태: **기획만** (2026-08-04, jin). 코드 착수 안 함. 미결 1건 있음(§6).
> 발단: "클레임 인보이스가 오는데 ERP에 저장해서 정산 때 차감하고, 부위·명목·바이어로 통계를 뽑을 수 있지 않나?"

## 1. 실무 흐름 (실물 서류 2종 실측)

바탕화면 실물 2건을 확인해 도출. 클레임은 **판정 → 지급** 2단계로 굴러간다.

### ① CLAIM REPORT (docx) — 판정 문서

`CLAIM DISCOVERY SPORT SALCA2BN3HH682215.docx` 실측 구조:

| 섹션 | 실제 값 |
|---|---|
| 바이어 | VILLA KOHA · SALES AGENT MUSA · 총구매 **60대** / 1년 **30대** / 1년 클레임 **1건** / PRIORITY **HIGH** |
| 차량 | DISCOVERY SPORT + VIN · 169,516km · FOB €4,677 · **PROFIT(4.5%) 383,259원** |
| 검수 | `Neither buyer nor sales agent were able to provide the inspection report video or documents` |
| 사유 | oil pan 다중 누유 + injectors·transmission 불량 |
| 증빙 | 정비소 영상 + 사진 + 인보이스 (**VERIFIED**) |
| 금액 | **청구 €1,500 → REFUNDABLE 33.33% → 확정 €500** |
| 결과 | `ACCEPTED` · `COMPENSATION TYPE = NEXT SHIPPING UNPAIDMENT` |
| 결재 | CLAIM MANAGER(LORENA LEE) → SALES MANAGER(MUSA) → **REVIEWED BY CEO** |

문서 NOTES 에 "그동안의 보상 요청은 전부 거절해 왔다"가 명시 — **기각 케이스가 실재**한다.

### ② 해외 외화 송금 요청서 (xlsx) — 지급 실행

`파손보상요청서 KOHA 유로-무사백.xlsx`: 목적 `파손 보상`, **€500**, 수취인 은행정보(성명·주소·은행·SWIFT·계좌),
통화 체크박스(USD/EUR/GBP/KRW), 차량 `07버5628` + 모델 + VIN, 선적일, 판매금액 `FOB 4667`,
내용 `Injectors, Mission is also broken but they couldn't take the videos. INVOICE 1500유로 500유로 보상 협의`, 증빙자료.

## 2. jin 확정 사항 (2026-08-04)

| 질문 | 답 |
|---|---|
| 클레임 도착 시점 | 한 달 안에는 아님. **"언제 걸리든 기입한 그 달 정산에서 차감"** |
| 비용 부담 주체 | **전액 담당자 부담** (회사/담당자 분담 없음) |
| 입력·승인 | **재무·관리 입력, ERP 승인 흐름 없음**(감사로그만). 결재는 문서로 CEO. 추후 대표에게 간략 보고 |

> ⚠️ 「승인 흐름 없음」은 **Phase 1 스코프 판단이지 결재가 없다는 뜻이 아니다.** docx 에 실제로 3단 결재
> (CLAIM MANAGER → SALES MANAGER → **REVIEWED BY CEO**)와 `APPROVAL DATE: WAITING CONFIRMATION` 이 살아 있다.
> Phase 1 은 **결재자 이름·일자를 기록 필드로만** 두고, ERP 승인 사다리는 Phase 2 이후 재논의한다.
| 미수 반영 | **Phase 1 은 기록만**, 실제 미수 차감은 재무가 채권관리에서 수동 |

## 3. 차감 경로 — 소급 없음, 단일 경로

```
클레임 인보이스 도착 → 재무·관리 기입(확정 보상액 포함) → 미차감 상태로 대기
        ↓
다음 지급배치 제출(SettlementPayoutBatch::submitForMonth) 시 미차감분을 담당자별로 흡수
        ↓
SettlementPayoutAdjustment 음수 조정 생성(claim 연결) → 배치 총액 차감
        ↓
클레임에 deducted_batch_id 기록 = 이중 차감 불가
```

- **차량 정산(Settlement)은 건드리지 않는다.** 클레임은 대개 거래완료(B/L) 후 도착 → 그 정산은 이미
  `paid` → `closed`(회계 잠금)이고, post-close 재조정은 **record-only**(지급 재정산 없음)라 소급이 불가능하다.
- 이월(carryover)이 `Settlement::creating` 에서 미적용분을 흡수하는 것과 **같은 검증된 패턴**을 쓴다.
- 🚫 **클레임 금액을 `cost_extra1/2` 등 차량 비용 컬럼에 넣지 말 것** — `cost_total` → 마진 → 정산액
  경로라, 마감된 정산의 *화면 마진만* 조용히 바뀌고 실지급은 안 바뀐다. `BulkVehicleCostService` 도 closed 는 skip 한다.
- ⚠️ 담당자의 그 달 지급액보다 클레임이 크면 마이너스 지급이 된다 → **초과 경고를 띄우고 재무가 조정
  금액을 줄여 잔액을 다음 달로** 넘기게 한다(자동 분할 안 함).

## 4. 스키마 초안 (`vehicle_claims`)

| 묶음 | 필드 | 비고 |
|---|---|---|
| 대상 | `vehicle_id`, `salesman_id`, `buyer_id` + **당시 이름 스냅샷** | 바이어·담당자는 나중에 바뀐다. 통계 보존용 |
| 금액 | `currency`, **`claim_amount`**(청구), **`approved_amount`**(확정), `exchange_rate`, `approved_amount_krw`(캐시) | **환급률은 계산값 — 저장 금지**. 차감은 반드시 `approved_amount` 기준 |
| 분류 | `parts`(**다중**), `part_detail`(자유텍스트), `reason_type`, `fault` | 전부 **모델 상수 + string, enum 금지** |
| 증빙 | `invoice_no`, `invoice_date`, `evidence_verified`(bool), `evidence_types`, 파일(S3) | |
| 판정 | `status`, `decided_by`(결재자 이름), `decided_at`, `compensation_type`, `note` | |
| 차감 | `deducted_batch_id`, `adjustment_id`, `deducted_at` | 이중 차감 방지 |

### 상수 목록 (초안)

- **부위**(다중 선택 — 실물이 오일팬+인젝터+미션 3개였다):
  `엔진(원동기)` `변속기` `연료·인젝터` `동력전달` `조향` `제동` `전기` `외판·판금` `내·외장` `휠·타이어` `유리` `기타`
  → 세부는 자유 텍스트(`oil pan 누유`). 실물이 계통 수준 영문이라 국내 성능점검 세부 19부위는 **채택하지 않음**(§7 참조).
- **명목**: `성능 불량(고장)` `사고이력 상이` `침수이력 미고지` `주행거리 상이` `사양·옵션 상이`
  `부품 누락` `운송 중 손상` `서류 지연·오류` `통관 문제` `기타`
- **귀책**: `검수 리포트 없음` `검수 누락` `매입처 고지 누락` `운송사` `포워딩·통관` `바이어 과실` `불가항력` `불명`
- **상태**: `접수` `검토중` `승인(ACCEPTED)` `기각(DECLINED)` `차감완료`
  ⚠️ **기각 건도 저장**해야 클레임율 분모(청구 건수)가 성립한다.
- **보상방식**: `다음 선적 미수 차감` `해외 송금` `적립금` — §6 미결

## 5. 산출(분석) 축

바이어별 · 담당자별 · 부위별 · 명목별 · 귀책별 · **매입처별** · 브랜드/차종별.
매입처는 매입 시점에 행동으로 옮길 수 있는 유일한 축이라 값어치가 가장 크다("이 경매장 차가 클레임이 잦다").

⚠️ **클레임율 분모를 한 번만 정의하고 전 화면이 그것만 쓸 것**(미수율 분모 단일출처 §13 과 같은 규율).
분모 후보 = 거래완료 대수 / 판매 대수 — 미정.

## 6. 미결 — jin 확인 필요

**바이어 보상을 실제로 어떻게 지급하나?** 두 문서가 다르게 말한다:
- docx `COMPENSATION TYPE = NEXT SHIPPING UNPAIDMENT`(다음 선적 미수 차감)
- xlsx = €500 **해외 송금 요청서**

같은 건인데 방식이 둘이라, 실제로 어느 쪽이 집행됐는지 / 두 방식이 다 쓰이는지 확인 필요.
이건 **바이어 보상 축**이고, jin 이 답한 「전액 담당자 부담」은 **비용 부담 축**이라 서로 다르다.
둘 다 처리해야 "€500 보상했다고 기록됐는데 바이어 미수는 그대로"인 상태를 피한다.

## 7. 조사 기록 — ssancar 성능점검 분류 (채택 보류)

`C:\xampp\htdocs\ssancar\htdocs\lib\stock_check_parse.php` 에 **자동차관리법 시행규칙 별지 제82호서식**
(성능·상태점검기록부) 표준 분류가 파서로 구현돼 있다:

- **외판·골격 19부위**(`$PANELS`): 외판1랭크(후드·프론트휀더·도어·트렁크리드·라디에이터서포트) /
  외판2랭크(쿼터패널·루프패널·사이드실) / 주요골격(프론트패널·크로스멤버·인사이드패널·사이드멤버·
  휠하우스·필러·대쉬·플로어·트렁크플로어·리어패널·패키지트레이)
- **손상코드 6종**(`$CODES`): `X 교환` `W 판금/용접` `A 흠집` `U 요철` `C 부식` `T 손상`
- **주요장치 8계통**(`$DC`): 자기진단·원동기·변속기·동력전달·조향·제동·전기·고전원·연료
- **종합상태**(`$BC`): 주행거리·계기·차대번호표기·튜닝·**특별이력(침수/화재)**·용도변경·도색·리콜
- **수리필요**(`$EAC`): 외장·내장·광택·룸크리닝·휠·타이어·유리
- `lib/nice_dnr.lib.php` `nice_is_body_repair()` — 정비이력에서 판금·도색 판정 키워드 목록

**보류 사유**: 실물 클레임은 `oil pan` / `injectors` / `transmission` 처럼 **기계 계통 수준 영문**이다.
82호서식은 국내 성능점검 축(외판·골격 중심)이라 수출 클레임과 축이 다르다.
다만 **Phase 3(검수 대조)에서 다시 쓸 값어치가 있다** — "우리가 양호로 표기했는데 클레임이 걸린 항목"
통계를 뽑으려면 이 분류가 필요하다.

## 8. 단계 구분

- **Phase 1**: 테이블·모델·상수 / `/erp/claims` 목록+등록 패널(재무·관리) / 배치 제출 시 자동 흡수 + 초과 경고 /
  목록 상단 요약(부위·바이어·담당자·매입처)
- **Phase 2**: 바이어 미수 자동 반영(§6 답변 후) / 판정 보조 데이터 자동 산출(바이어 총구매·1년구매·
  1년클레임·그 차량 마진 — **전부 ERP 가 이미 가진 값인데 지금 수기로 채우고 있다**) /
  서류 자동 생성(docx·xlsx) / 대표 보고 / 분석 대시보드
- **Phase 3**: 검수·성능점검 데이터 대조(§7)

## 9. 착수 시 밟을 함정

1. 🚨 **`ReceivableHistory.method` 에 값을 추가하는 방향이 되면**(미수 차감 자동화) — 테스트는 SQLite 라
   100% 통과하고 **운영 MySQL 에서만 `1265` 로 죽는다**. 07-28 적립금 사고와 글자 그대로 같은 지점.
   모델 상수 단일출처 + **같은 커밋에 `ALTER TABLE … MODIFY COLUMN … ENUM(…)`** + 마이그레이션 문자열을
   정적 파싱해 대조하는 가드(`ReceivableMethodEnumTest` 형태). → **Phase 1 을 "기록만"으로 두면 이 리스크가 통째로 사라진다.**
2. 📄 서류 자동 생성 시 **`[$€-2]` 로케일 통화서식**(SKILLS §8 #37) — 이 건이 EUR 이라 정확히 사정거리 안.
   금액칸은 `\$\ #,##0` 형태로 통일해야 `applyCurrency` 치환이 정상 동작한다.
3. 새 모델을 감사 대상으로 삼으면 `config/column_labels.php` **models + `ColumnLabel::resolveTable()` 표 매핑
   + 컬럼 사전** 3곳 + 새 action 라벨. 가드 = `AdminUiKoreanLabelTest`.
4. Volt 화면: `public $search` + `search()` 이름 충돌 금지(§8 #32). 금액칸 `data-money`, 날짜칸 `data-date`.
5. 사용자 가시 기능이므로 같은 커밋에 `scripts/notion-cards/cards.json` + 부서 가이드(`audience` 필수).
