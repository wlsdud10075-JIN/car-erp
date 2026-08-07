# board ↔ erp 요청·확인 신호 (카톡 대체) — 구현 계획

> 상태: **계획만. 코드 0.** (2026-08-07 jin 범위 확정)
> 재개 트리거: "board 요청확인 이어서"

## 0. 한 줄

board 영업이 **"해주세요"** 를 누르면 erp 관리 이상에게 뱃지가 뜨고, 처리하면 꺼진다.
**금액은 오가지 않는다** — 돈 기입은 지금까지처럼 erp 관리 이상이 한다.

## 1. 신호 2종

| 신호 | 단위 | 뜻 | 닫히는 방법 |
|---|---|---|---|
| **[입금요청]** | 차량 1대 | "이 차 입금해주세요" | **자동** — 매입 미지급 0 되면 소멸 |
| **[판매대금확인]** | 바이어 1 + 차량 N대 | "이 바이어 차 N대 대금 넣었으니 확인해주세요" | **수동** — 재무가 차량별 체크 (3/5 부분확인) |

- [입금요청]에 **버튼이 없다**. 누를 사람이 있으면 결국 카톡으로 돌아간다.
- [판매대금확인]은 부분입금이 흔해 기계 판정이 불가 → 차량별 체크. 묶음 통째 확인은 기각(3대만 들어왔을 때 방법이 없다).
- 은행 API 연동 시 [입금요청]이 "계약금/잔금 얼마" 로 확장될 예정(jin 예고). **지금 금액 컬럼을 만들지 않는다**(YAGNI).

## 2. 데이터 — `board_requests` 한 장

선적요청과 같은 방식: **배치 테이블 없이 `batch_id` uuid 컬럼으로 묶는다**
(`shipping_requests` 실측 — `batch_id string(36) nullable index`).

```
id
batch_id             uuid  묶음. 입금요청=혼자 1행 / 판매대금확인=같은 uuid N행
type                 purchase_payment | sale_payment_confirm
vehicle_id           차 1대 = 1행  ← 차량별 체크가 여기서 나온다
buyer_id  nullable   판매대금확인만
status               open | done | cancelled
requested_by_email   board 영업 (선적요청과 동일 방식)
requested_at
confirmed_by_id / confirmed_at   erp 재무가 체크한 사람·시각
note      nullable
timestamps
```

- **금액 컬럼 없음.**
- 멱등 = `(vehicle_id, type, status=open)` 유니크. 같은 차에 열린 요청이 둘 생기지 않게.
- ⚠️ bulk update/delete 는 모델 이벤트가 안 뜬다(SKILLS §2) — 상태 전환은 모델 경유로만.

## 3. API (erp 몫) — 기존 HMAC 그룹에 얹는다

`routes/api.php` 의 `internal/board` 프리픽스 + `VerifyBoardReadHmac` + `throttle:board-read` 재사용.
**웹훅은 만들지 않는다** (근거 = 메모리 `project_board_erp_request_ack`: 3사 배포인데 board 는 heyman 에만 있음 /
queue worker 부재 / board 가 이미 폴링 중).

| 메서드 | 경로 | 용도 |
|---|---|---|
| POST | `internal/board/requests` | board 영업이 요청 생성 (`type` + `vehicle_ids[]` + `buyer_id?`) |
| GET | `internal/board/requests` | board 가 상태 폴링 → 칩 갱신 |

- 차량 스코프 재인가 필수 — board 가 보낸 `vehicle_ids` 를 그대로 믿지 않는다(§8 #26).
- 응답에 금액 없음. `{vehicle_number, status, confirmed_at}` 수준.

## 4. erp 화면 (새 화면 없음 — 기존 딥링크)

| 위치 | 내용 |
|---|---|
| 재무 처리(transfers) **매입 잔금 탭** | [입금요청] 뱃지. 버튼 없음, 지급 기입하면 사라짐 |
| 재무 처리(transfers) **판매 잔금 탭** | [판매대금확인] 묶음 카드 + **차량별 체크박스** + `3/5` 부분 상태 |
| 알람센터 | `target_role='관리'` — 기존 분기를 그대로 타서 `scopeVisibleTo`↔`canSeeAlarm` lockstep 을 안 건드림 |

⚠️ 반복행 체크박스는 **`wire:key` 필수** + **비교는 정수/저장은 문자열**
(2026-08-05 실측 함정 — 없으면 morph 가 체크를 엉뚱한 행에 남긴다. 서버는 정상이라 기능 테스트로 못 잡음).

## 5. 자동 해소 — 이미 있는 것과 겹친다

erp 안쪽 절반은 **이미 돌고 있다. 새로 만들지 말 것.**

- `purchase_arrival` (`PurchaseSyncController:372`) — board 낙찰 시 발화, 계약금 PBP 입력 시 자동 해소
- `purchase_balance_due` (`ScanTaskAlarms:114`) — 계약금일+10일, 매입 미지급 0 시 자동 해소, **매매상 차량만**

[입금요청]은 **사람이 명시적으로 누르는** 것이라 트리거가 다르다(위 둘은 자동 발화).
같은 차량에 뱃지가 둘 뜰 수 있으므로, 구현 시 **합칠지 공존시킬지 결정**한다.
해소 조건은 `purchase_balance_due` 와 동일(`매입 미지급 0`)하므로 **그 스캔에 편승**하는 게 자연스럽다.

## 6. 알림톡 — 추후 (jin 2026-08-07 보류). 조사 결과만 박제

### Q1. "관리가 **본인이 관리하는 영업**의 건만 알림톡 받기" — 지금은 안 된다. 만들 수는 있다.

**현재 동작 (실측)**: `AlimtalkPurchaseUnpaid` 등 관리 6종은 **전사 집계 1건을 관리 전원에게 동일 발송**한다.
`AlimtalkRecipients::forBroadcast()` → `groupPhones('관리')` = role='관리' **전원의 phone**. 차량 스코프와 무관.
⇒ 관리 A 가 관리 B 팀의 미지급 건수까지 합산된 숫자를 받고 있다.

**만드는 법**: 수신자 루프를 "번호 배열"에서 **"User 배열"** 로 바꾸고, 사람마다
`$user->getSubordinateSalesmanIds()` 로 스코프한 목록·집계를 따로 만들어 보낸다.
- 부분 선례가 이미 있다 — `AlimtalkDepositCash` 는 관리에게 `$mgrList`(전체), 담당 영업에게 `$ownList`(본인 것)로 나눠 보낸다.
- 스코프 단일 출처(`getSubordinateSalesmanIds`)가 이미 있어 **로직은 안 새로 만든다**.

**비용·주의**:
- 발송 건수가 늘어난다(관리 N명 = 최대 N배). BizM 과금·발송 로그 증가.
- **팀이 0명인 관리** 는 목록이 비어 발송 skip → "나만 알림이 안 온다" 제보가 나온다. 명시 처리 필요.
- **어느 팀에도 안 속한 영업의 차량**은 아무 관리에게도 안 간다. 지금은 전사 집계라 보이던 것이 사라진다.
  ⇒ admin/업무관리자에게는 **전사 집계를 유지**하고, role='관리' 에게만 스코프판을 보내는 게 안전하다.
- 알림톡 카드 규격(#40) — 목록 길이가 사람마다 달라져 **20자 컷·요약칸 금액만** 규칙을 다시 밟는다.

⇒ **board 신호와 분리된 별건**으로 다루는 게 맞다(알림톡 6종 전체를 건드리는 변경).

### Q2. 휴가 대리 위임이 알림톡에도 반영되나 — **Q1 을 하면 공짜로 따라온다.**

`User::getSubordinateSalesmanIds()` 가 **이미 위임을 흡수한다**(`activeDelegators()` → 위임자의 팀을 합집합, 1단 한정).
알림톡 수신자를 이 함수 기준으로 만들면 **위임 코드를 한 줄도 안 건드리고** 대타가 그 기간 동안 위임자 팀 알림을 받는다.

**단, 정책 결정 1개**: 위임해도 **위임자(휴가 간 사람)의 스코프는 그대로 남는다**(`own...` 을 잃지 않음).
화면에서는 그게 맞지만(돌아와서 봐야 함), 알림톡은 "휴가 중엔 안 받고 싶다"가 자연스러울 수 있다.
⇒ **위임 중 발신 억제 여부**를 jin 이 정해야 한다. 현행 구조 그대로면 **둘 다 받는다.**

## 7. board 몫 (별도 세션·별도 레포)

- 요청을 **만드는** 화면 — 차량 선택 → [입금요청] / 바이어+차량 N대 선택 → [판매대금확인]
- 상태 칩 (GET 폴링)
- ⚠️ 크로스 레포 규칙: board 변경은 **board 세션에서 board 레포에 커밋**한다.
- erp 는 API 를 먼저 열어두고 스펙을 `docs/integration/board-portal-api.md` 에 적는다(권위 문서, 복사 금지).

## 8. 단계 분할 (제안)

| 단계 | 내용 | 검증 |
|---|---|---|
| A | 테이블 + 모델 + 멱등 가드 | 유닛 |
| B | API 2개 (POST/GET) + 차량 스코프 재인가 | Feature (HMAC·IDOR) |
| C | 자동 해소 (`purchase_balance_due` 스캔 편승) | Feature (미지급 0 → done) |
| D | 재무 처리 화면 뱃지 + 차량별 체크 | Volt (부분확인 3/5 · `wire:key`) |
| E | board 스펙 문서화 → board 세션 인계 | `--verify` 대상 아님(연동 문서) |
| F | (추후) 알림톡 — §6 별건 | jin 결정 후 |

A~D 는 board 없이도 **테스트로 완결 검증** 가능하다.

## 9. 이 계획이 무효화한 것

- ~~"board 가 금액을 주장하고 재무가 그 자리에서 기입"~~ — jin 이 금액 전달을 걷어냄(2026-08-07).
  이 안은 **판매 잔금 신규 입력 UI 신설**을 요구했다(transfers 는 판매 잔금을 **확정만** 하고
  신규 입력은 매입에만 있다 — `createNewPbp`). 재요구 시 이 비대칭부터 확인할 것.
- 이로써 회계 지뢰(확정 FP 잠금 §5-6 · C5 게이트 재평가 · 외화 환율 · `board-portal-api §9`)가 **전부 무관**해졌다.
