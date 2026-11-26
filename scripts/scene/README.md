# scripts/scene — 업무 가이드 씬 촬영 스크립트 (dev 전용, master 제외)

가이드 씬 35장(`resources/guides/{slug}/`)을 찍을 때 쓴 스크립트 원본. **주 1회 씬 점검(화면 회귀) 스킬의 재료**다
— 기획·재개 절차 = `docs/design/scene-walkthrough.md §7·§8`.

⚠️ 지금은 **사진을 찍는 스크립트**다. 점검용으로 쓰려면 「사진 저장」을 「DB 값 · 화면 문구 확인」으로 바꿔야 한다(§8 1단계).
⚠️ 경로가 촬영 당시 scratchpad 기준으로 박힌 곳이 있다(`board_send.php` 경로, `__dirname + '/xx/'` 출력 폴더) — 옮겨 쓸 때 고칠 것.

## 실행 조건 (전부 로컬)
- 로컬 8001(jin 이 띄움 · 🚫 `php artisan serve`) · `playwright-core` + 설치된 Chrome(`C:/Program Files/Google/Chrome/Application/chrome.exe`).
- 계정(전부 비밀번호 `password`): 관리 `manage@` · 재무 `finance@` · 수출통관 `clear@` · 대표 `boss@` · 업무관리자 `guide.manager@`(김업무, 로컬 생성) · 영업 신원(board 쪽) `sales@`. 🚫 시스템관리자·실무자 실명 계정으로 찍지 않는다.
- 🚫 **127.0.0.1:47321 은 반드시 막는다** — jin PC 에 실제 카톡 에이전트가 떠 있다(`ctx.route(/127\.0\.0\.1:47321/, r => r.abort())`). [이 PC 연결하기]·[이 글 열기]·[지금 읽기] 누르지 않는다.
- 🚫 알림톡 발송 버튼 · NICE [조회]/[원부조회] 누르지 않는다(로컬 BizM 은 실발송 계정). `altoggle.php` 는 버튼 캡처용으로 잠깐 켜고 끄는 도구.
- Bash 히어독으로 `\\:` 같은 백슬래시 선택자를 쓰면 깨진다 → 파일은 Write/Edit 로. Git Bash 에서 `/api/...` 인자는 경로로 바뀐다 → `MSYS_NO_PATHCONV=1`.

## board_send.php
board 가 ERP 로 보내는 요청을 로컬에서 **실제 수신 경로에 서명 요청으로** 흉내 낸다(시크릿은 설정에서 읽기만, 출력 안 함, `APP_ENV=local` 가드).
```
MSYS_NO_PATHCONV=1 php board_send.php POST /api/internal/board/requests '{"salesman_email":"sales@car-erp.test","type":"purchase_balance","vehicle_ids":[300],"amount_krw":13500000}'
```
- 매입 확정(`/api/internal/purchase-sync`)은 시크릿·서명 방식이 달라 스크립트가 알아서 가른다. 소유자명(`owner_name`)을 보내면 NICE 가 불리니 빼고 보낸다.
- 🚨 선적 묶음 sync 는 **선언형**이다 — 같은 영업의 다른 열린 요청이 자동 취소된다. desired 에 다 넣거나 끝나고 원복.

## 스크립트 ↔ 씬 (접두어)
| 접두어 | 씬 slug |
|---|---|
| scene1* · money_check* | vehicle-register (00가1113) |
| pp_* | purchase-payment |
| sr_b | sale-register |
| es_* | sales-contract-esign |
| ex_* | export-tab |
| mp_* · st_probe | monthly-payout |
| gate_* · mk_gate | unpaid-gate-override |
| bl_* | bl-issue |
| rv_* · mk_rv | receivables |
| ss_* · mk_ss2 · cost_hdr | secondary-settlement |
| dr_* | deregistration-notice |
| ba_* | buyer-account |
| cancel_* · mk_cancel | purchase-cancel |
| sv_* · cashtoggle | savings |
| bu_* · mk_undecided | buyer-undecided |
| sh_* | shipment-fee |
| doc_* | documents |
| bd_* | bulk-shipping-dates |
| inv_* | inventory-location (inv_board = board 위치 단계) |
| ra* · unlock.php | ledger-readjust |
| lv_* | leaver-domestic-payout |
| bk_* | bank-balance |
| kk_* | kakao-todo (먼저 `KakaoTodoDemoSeeder` — 로컬 전용·미커밋) |
| br_* | board-payment-request |
| sp_* | board-sale-payment-check |
| ps_* | board-purchase-sync |
| sr_scene · sr_explore · sr_reject | shipping-requests |
| cl_* | buyer-claim |
| bank_* | bank-documents (연습용 전신문 = `bank_make_tif.py` · 촬영 중 받는 사람을 가짜 예시로 잠깐 바꾸고 되돌린다 · 🚫 [은행으로 보내기] 누르지 않음) |
| b2bm_scene.cjs | b2b-partner-memo (데모 바이어 「B2B데모 파트너 LLC」 · 촬영 전 원래 메모를 백업하고 끝나면 되돌린다) |
| fxc_scene.cjs | exchange-rate-closing (재무 · [받아오기]는 로컬 마감환율만 다시 넣음 · 아래 카드는 샘플 DB 이름이 있어 위쪽만 자른다) |
| bankbatch_scene.cjs | bank-documents 05~09 (담아 두기 · 수정본 올리기 · 모아 보내기 — 재무 · 🚫 보내기는 안 누름 · 🔐 찍기 전 로컬의 예시 아닌 은행 기록을 차에서 떼고 받는 사람 목록을 가짜로, 찍은 뒤 되돌린다) |
| view.js · view2.js | 씬 렌더 확인(`node view2.js <slug>`) |

⚠️ sale-payment 씬 스크립트는 따로 이름이 없다(scene1 계열에서 이어 찍음) — 점검으로 만들 때 새로 쓴다.
⚠️ 여러 스크립트가 **같은 예시 차를 바꿔 놓은 상태**를 전제한다(00가1113 은 이미 거래완료). 점검 전에 되돌리기 시더가 먼저다(§8 1단계).
