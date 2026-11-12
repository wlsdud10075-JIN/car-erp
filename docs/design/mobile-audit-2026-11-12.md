# 모바일(390px) UX 점검 — 2026-11-12 (jin «정산처리·월정산·내수·B2B·신규 탭 폰에서 어긋난 것 점검»)

팬아웃 4갈래(정산처리 / 사내직원·내수직원 월정산 / 바이어·B2B / 신규 탭·관제) 조사 결과를 main 이 취합. 실측 = Playwright 390×844(DPR2, 터치), 로컬 8001 샘플 DB. 기준 = SKILLS §8 #98·#116 부류. 원 보고서·스크린샷 = 세션 scratchpad `pw/` (`settle-mobile-report.md` · `payout-mobile-report.md` · `mobile-nov-report.md` · buyers 스크린샷).

## ✅ 처리 (11-12 밤, 1차 = 🔴 5 + 🟠 14 전부 — jin «오케이 진행해줘. 천천히 제대로»)
- 고침안 그대로 적용(데스크탑 무변경 — `sm:` 되돌리기). 예외 = B8 적립금 거래표 `whitespace-nowrap`(데스크탑도 함께), B9 메모 폰 전문은 `min-w-[160px]` 로 행 높이 억제.
- A2·A3 정산처리 모바일 카드 = 뱃지 줄(`data-mobile-badges`) + 버튼 줄(`data-mobile-actions`, 확정·2차 완료·재조정·예외 등록/해제·삭제) — 데스크탑 작업 칸과 같은 `@if`·같은 메서드. 2차 판정은 카드 `@php` 에서 다시 계산(`$mSecondary*`).
- 실측(390px, 빌드 뒤): 정산 합계 카드 366px 1열·토글 22×25 · 사내직원관리 버튼 줄 접힘(scrollWidth 390) · 월정산 뱃지 한 줄 · 바이어 탭 바 `overflow-x: auto`(424/390) · 적립금 게이지 156px · 관제 루틴 첫 열 180px · 3D `touch-action: pan-y` · 콘솔 오류 0.
- `npm run build` 로 새 유틸(`sm:basis-0`·`sm:col-span-1`·`sm:py-0`·`sm:my-0`·`min-w-[150px]`·`min-w-[160px]`) CSS 확인. 🟡 는 그대로 2차.

## 🔴 기능 불가 (폰에서 할 수 없는 것)

| # | 화면 | 위치 | 증상 | 고침안 |
|---|---|---|---|---|
| A1 | 관제센터 SECOND BRAIN | `admin/control.blade.php:339-340` | 3D 영역(316×460)이 `touch-action: none` 이라 그 위에서 손가락으로 **페이지가 안 내려감**(스크롤 벽, 실측 0px) | `touch-action: pan-y` (가로 끌기 회전 유지) |
| A2 | 정산처리 모바일 카드 | `settlements/index.blade.php:3050-3078` ↔ `:3009-3038` | 카드엔 [확정]뿐 — 데스크탑 작업 칸의 **[삭제]·[🔓 회계 재조정]·[예외 등록/해제]** 가 폰엔 없음 | 카드 버튼 줄에 같은 `@if` 조건으로 3종 추가(폰 전용 블록) |
| A3 | 정산처리 모바일 카드 | `:3050-3078` ↔ `:2955-3008` | 지급보류·게이트 예외·지급 대상 아님·승인 대기/반려·2차 상태 뱃지가 카드에 없어 **필터를 걸어도 이유가 안 보임** | 테이블과 같은 `@if` 로 뱃지 추가 |
| A4 | 사내직원관리 상단 | `salesmen/index.blade.php:972-1000` | 버튼 줄이 601px 라 **[급여대장 업로드] 반 잘림·[평가수수료 업로드] 화면 밖** — main 을 옆으로 밀어야 보임(힌트 없음) | `flex-wrap gap-2` + 버튼 묶음 `flex-wrap` |
| A5 | 관제 ROUTINES 실패 이유 | `control.blade.php:410·423` | 이유가 `title=` 만 — 폰은 hover 없음 | failed 셀 안에 작은 글자로 이유 노출 |

## 🟠 보기 흉함 (글자 세로 쪼개짐·찌그러짐)

| # | 화면 | 위치 | 증상 | 고침안 |
|---|---|---|---|---|
| B1 | 정산처리 담당자별 합계 | `settlements:2708` · `:2728-2733` | 폰에서도 2열(177px) → 이름 14~31px 로 눌려 「[H…」 · 펼침 토글 6×17px | `grid-cols-1 sm:grid-cols-2 …` + 토글 `px-2 py-1 sm:px-0 sm:py-0` (B2 도 같이 해소) |
| B2 | 정산처리 v3 사람 카드 | `components/payout/person-card.blade.php:39` | 143px 안에 그려져 이름 묶음이 테두리 밖, 라벨 2~3줄 | B1 로 해소(남으면 `max-w-full truncate`) |
| B3 | 정산처리 예외 대상 모달 | `settlements:3129·3153` | 사유 칸 39px → 뱃지 세로 쪼개져 행 170px | 사유 뱃지 `whitespace-nowrap` + `<td min-w-[150px]>` |
| B4 | 월정산 카드 헤더 | `payout-batches/index.blade.php:359-370` | 「승인·지급」 뱃지 세로 글자·「✅ 최종 확정」 세로·「10건」 2줄 | 토글 `flex-wrap basis-full sm:basis-0` + 뱃지 `whitespace-nowrap` |
| B5 | 결재 승인 링크 페이지 | `payout-approval/show.blade.php:15` | 긴 라벨 줄에서 금액이 「20,381,250 / 원」 2줄 | `.row .v { white-space:nowrap; flex-shrink:0; margin-left:8px }` |
| B6 | 바이어 드로어 탭 바 | `buyers/index.blade.php:1830` | 5탭 404px > 390 — 「클레임」 끝 글자 잘림 | `overflow-x-auto` |
| B7 | 바이어 적립금 잔액 줄 | `buyers:2487-2495` | 게이지 20px 로 찌그러짐 | ≈원화 span `hidden sm:block` + 폰용 한 줄 |
| B8 | 바이어 적립금 거래표 | `buyers:2577-2585·2607-2608` | 통화·유형 글자 세로 1자씩 — **데스크탑도 같음** | thead·td `whitespace-nowrap`(데스크탑 함께) |
| B9 | 바이어 메모 칸 4곳 | `buyers:2652·2841·2919·2928·3009` | `truncate`+`title` — 폰에서 전문 못 봄 | 페어 렌더(`hidden sm:block` / `sm:hidden whitespace-normal`) |
| B10 | 카톡 할 일 카드 | `kakao/index.blade.php:687-689` | 글 칸 90px → 제목 4줄 | 글 칸 `basis-full sm:basis-0` |
| B11 | 카톡 역할 모달 | `kakao:448-450` | 「등/록」「통/관」 세로 쪼개짐 | 이름 span `shrink-0 whitespace-nowrap` |
| B12 | 차량 수출 탭 통관 체크 | `vehicles/index.blade.php:11224-11228` | 「통관 완/료」 쪼개짐 | 칸 `col-span-2 sm:col-span-1` |
| B13 | 관제 ROUTINES 작업 열 | `control.blade.php:421` | 첫 열 60px → 5줄 | 첫 `td` `min-width:180px` |
| B14 | 사내직원 드로어 보류 패널·급여 머리 | `salesmen:1511-1514·1541-1542·1644-1646` | 제목 3줄·「처리 금/액」·「급여 항/목」 | `shrink-0 whitespace-nowrap`·`flex-wrap` |

## 🟡 사소 (터치 타깃 28px 미만 · 힌트 없음) — 2차 묶음
- 정산처리: 모달 `×` 9×24(4곳)·조정 삭제 `×`·26px 버튼 7곳·▸ 접기 19px·라디오 13px·`title` 전용 정보 6곳(`:2783·2273·2258·2475·2723·2725`)·필터 바 290px·퇴사 표 머리글.
- 월정산: 엑셀 22px·재전송/반려/보류/인센티브 `py-1`·결재선 strip 둘째 줄 왼쪽 선(`border-l-0` → `-ml-px`, 승인 페이지 `:111-117` 도)·공개 범위 표 스크롤 힌트·제출 모달 `×`·추가 버튼 24px.
- 바이어: 적립금 취소 `×` 6×16·컨사이니 수정/삭제 24×16·「+ 컨사이니 추가」 20px·B2B 「교체」 13px·권장값 26px·카드에 담당자 뱃지 없음·컨사이니 긴 이름(추정).
- 카톡·차량·관제: 묶음 머리줄 21px·링크 16~17px·칩 28px·▸ 32px·분야 칩 뜻 `title` 전용·방 라벨 `max-w-full`.

## 곁다리(모바일 밖, 확인 필요)
- ⚠️ **정산처리 「이 달 2차 일괄 마감 (10)」** — 버튼은 10건인데 누르면 「대기 중인 정산이 없습니다」 토스트(로컬 10월). `secondaryPendingCount()`(`:1929`) 와 `closeSecondaryPreview`(`:1949`) 조건이 다른 것으로 보임 — **테스트 경로 탓일 수도(추정)**, 데스크탑에서 한 번 직접 눌러 볼 것.
- 승인 링크 인센티브 추가 버튼 `onclick` 문자열 안 실제 개행(`show.blade.php:220-221`) → 확인창 없이 제출(서버 검증은 그대로). `\n` 으로.
- 정적 가드 3종(Modal/Touch/MobileAction)이 `settlements/index` 를 대상에 안 넣고 있다.
- 고침안의 새 유틸(`sm:py-0`·`sm:h-auto`·`-ml-px`·`min-h-[44px]` 등)은 빌드 CSS 에 없어 `npm run build` 필요(운영은 deploy 가 빌드). 로컬 `public/build` 는 11-05 자로 낡음.

## 문제 없음 확인
- 가로 페이지 넘침 = 전 화면 0(`scrollWidth 390`). 모달 전부 `max-h-[90vh] overflow-y-auto`(아래 버튼 도달). select 글자색 전부 명시. `@mouseenter` 전용 0건. 차량 패널 탭 머리줄 가로 스크롤 정상. 관제 3D 는 §8 #116 자기 증식 없음.
