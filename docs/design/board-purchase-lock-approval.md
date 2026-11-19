# board 발 매입 락 해제 요청 → ERP 승인큐 + 알림 벨 「승인 대기」 구역

> 상태: **설계 확정(jin 2026-11-19), 구현 미착수** — 주말(11-21~22) 작업에 포함(jin «이건 주말작업할거고»). 순서 = ERP 연동 규격 문서 → board 통지 → ERP 구현·배포 → board 구현.
> 목업: `docs/design/mock/2026-11-19/approval-bell.html` (노란 줄 버튼으로 상태 전환)
> 관련: `docs/integration/board-collab.md` §3 2026-11-19 · board 쪽 구현은 board 세션 몫.

## 1. 왜

board 사용 전에는 바이어 미수가 높아도 ERP 차량 저장 때 「관리자 사유」를 적고 등록했다(1대 미수 100% 인 바이어의 2·3번째 차).
지금은 board 가 매입 입구라 락 걸린 바이어는 board 에서 구매확정이 그냥 막히고 우회 길이 없다.
⇒ 그 「사유 쓰고 등록」을 **승인 절차**로 바꾼다. 판단하는 것은 하나 — **이 바이어의 락을 이 차 1대에 한해 풀 것인가**(jin). 금액은 판단 대상이 아니다.

## 2. 지금 (확인한 것, dev a1e42bea)

- ERP 인라인 우회: `resources/views/livewire/erp/vehicles/index.blade.php:6419-6468`(게이트) → 모달 `:13287-13349`. 사유 5자 이상, 승인 = `User::canApproveUnpaidExport()`(admin·업무관리자·role 관리, `app/Models/User.php:638`), 그 저장 1회만, 감사 `purchase_gate_override`(`:7390-7398`). 승인큐와 연결 없음.
- purchase-sync 수신부는 매입 락을 안 본다(`app/Http/Controllers/Webhook/PurchaseSyncController.php` 에 게이트 참조 0건).
- 승인큐 = `ApprovalRequest`(`target_type/target_id` 다형 · `payload` JSON · `status` · `used_at`). 화면 `erp/approvals/index` 는 `canApprove()`(관리 이상) 전원이 **전부** 보고, 본인 요청은 본인이 승인 못 한다(`:145`). 담당 영업별로 안 나뉜다.
- §11 `board_requests.vehicle_id` 는 NOT NULL FK — 차량이 아직 없는 이 건은 못 담는다.
- 알림 벨 = `resources/views/livewire/erp/alarm-center.blade.php`(오른쪽 아래, `TaskAlarm`, 목록 높이 `max-h-[60vh]`). 승인큐는 사이드바 숫자 뱃지뿐.
- ssancarerp 실측(11-19): role 관리 7명 중 5명이 영업 42명 전원 담당(차량 5,791대 전부) · 2명만 1명씩. 사실상 전체 공유.

## 3. 확정 (jin 2026-11-19)

| # | 항목 | 결정 |
|---|---|---|
| 1 | 흐름 | **선승인** — board 가 요청 → ERP 승인 → board 에서 구매확정 → 평소대로 purchase-sync. (보류 등록형은 접음: 거절 시 딜러와 확정한 차를 되돌려야 하고 won 인데 ERP 에 차가 없는 구간이 생긴다) |
| 2 | 승인자 | 관리 이상 **전원에게 전부** 보이고 먼저 본 사람이 처리(현 승인큐와 같음). 본인 요청은 본인 승인 불가. 담당 영업별로 나누지 않는다 |
| 3 | 범위 | **차 1대 + 그 바이어, 1회.** 키 = (board `purchase_listing_id`, `buyer_id`) |
| 4 | 유효기간 | 승인 후 **7일** 안에 구매확정 안 하면 만료 |
| 5 | 무효가 되는 경우 | 바이어 변경 · 차 삭제 후 재등록(새 listing id) · 7일 경과. **금액 수정은 무효 사유가 아니다** — 승인 전·후·ERP 등록 뒤 모두 자유 |
| 6 | 요청 버튼(board) | 등록 영업 본인 + 관리 대리. 실제 누른 사람 이름을 같이 남긴다 |
| 7 | 거절 뒤 재요청 | 가능, 횟수 제한 없음(매번 사유 필수, 이력 남음) |
| 8 | 승인 화면 | 차(차량번호·차명) · 바이어 · 요청자(+누른 사람) · 사유 + **ERP 가 승인 시점에 계산한** 바이어 미수·미수율/락 기준·진행 대수. 차값·판매가·통화·환율·첨부는 받지 않는다 |
| 9 | 대기 중 board 값 수정 | 막지 않는다 |
| 10 | 알림 | **알림톡 없음.** ERP 알림 벨 위쪽에 「승인 대기」 고정 구역(아래 §4). 영업은 ERP 를 안 쓴다 — 결과는 board 에서 본다 |

## 4. 알림 벨 「승인 대기」 구역

- 벨 안을 두 구역으로: 위 = 승인 대기(빨강), 아래 = 종전 알림 그대로.
- 위 구역은 **[확인]으로 안 사라진다** — 승인·거절돼야 사라진다. 누르면 승인큐의 그 건으로.
- 접힌 벨에 「승인 N」을 일반 알림 숫자와 따로 표시. 새 건이 오면 보고 있는 사람에게 토스트 1회.
- 보는 사람: 승인권자(관리 이상)만. 본인이 올린 건은 회색 「내가 올린 요청」(승인 불가). 승인권 없는 수출통관·재무는 자기가 ERP 에서 올린 요청의 진행만.
- 올리는 범위 = **승인큐 전체**(이번 매입 락 + 정산 지급·미입금 우회·자금 이체 등 `scopeActionable`). 벨과 승인큐 화면·사이드바 뱃지가 같은 집합을 봐야 한다.
- 🚫 월정산 결재 대기는 이번에 안 올린다. 나중에 붙일 땐 **업무관리자 이상에게만**(jin).
- 벨 목록 세로 높이 60vh → **80vh**(jin «1/3 더»). 폭은 그대로.

## 5. 구현 메모 (착수 때)

- `ApprovalRequest` 새 종류(가칭 `board_purchase_lock`), target = Buyer, payload = { purchase_listing_id, vehicle_number, 차명, salesman_email, requested_by_name, 사유 }. 승인 화면의 미수 숫자는 payload 가 아니라 `PurchaseRegistrationGate`/`Buyer::receivableGauge()` 로 그때 계산.
- 내부 API(읽기 HMAC 틀): 요청 생성 `POST` · 상태 조회 `GET ?purchase_listing_id=`(board 가 구매확정 직전 「유효·미사용인가」 재확인 + 결과 폴링). ERP → board 웹훅 경로는 없다(board 확인).
- 소진: purchase-sync 가 그 `purchase_listing_id` 로 들어오면 `used_at`. 수신부가 락을 새로 검사하지는 않는다(돈이 나간 뒤라 거부해 봐야 늦다) — 승인 id 를 감사에 연결.
- 벨: `TaskAlarm` 에 넣지 않고(차량 FK 전제) 벨 컴포넌트가 pending `ApprovalRequest` 를 직접 읽는 쪽이 단순하다 — 착수 때 확정.
- board 가 바꿀 것: /auction 드로어 🔒 바이어에서 [ERP 승인 요청](사유) → 상태 칩 → 승인된 (차, 바이어)만 구매확정 통과. 요청 때 금액을 미리 저장하던 초안은 불필요해졌다.
