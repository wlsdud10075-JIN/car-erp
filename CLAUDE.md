# 중고차 수출 ERP (car-erp)

> ⚠️ **세션 시작 시 함께 읽히는 것**: ①이 파일(도메인·권한·환경·배포) ②`CLAUDE_1.md`(코딩 가이드라인) ③`SKILLS.md`(구현 패턴·교훈 색인·UI) ④**핵심 편 7개**(`docs/core/` — 돈 5편 · 락 · 역할. 코드와 대조해 쓴 「지금 규칙」이다)
> @CLAUDE_1.md
> @SKILLS.md
> @docs/core/money-1-settlement.md
> @docs/core/money-2-fx.md
> @docs/core/money-3-receivable.md
> @docs/core/money-4-secondary-lock.md
> @docs/core/money-5-payout-batch.md
> @docs/core/lock.md
> @docs/core/role.md
>
> 🧭 **돈·락·역할에 관한 답은 핵심 편이 정본이다.** 이 파일·SKILLS·메모리의 문장이 핵심 편과 다르면 핵심 편을 따르고, 핵심 편이 코드와 다르면 코드를 확인한 뒤 핵심 편을 고친다(그 규칙을 묻는 질문 한 줄을 `docs/design/md-trim/trim-quiz.md` 에 추가한다 — 주 1회 읽기 시험이 다시 묻는다). 적립금·바이어 현금 원장 편은 아직 없다 — `SKILLS.md §6`·`docs/design/buyer-cash-ledger.md` 를 본다.

> 📚 **이 파일은 「지금 규칙」만 적는다.** 그렇게 된 경위(언제·왜 바뀌었나, 구 규칙, 실측 수치)는 `docs/archive/md-2026-11-23/CLAUDE.md.full`(줄이기 전 원본 통째)에 절 이름 그대로 있다 — 규칙을 바꾸거나 되돌리자는 제안을 하기 전에 그 절을 먼저 읽는다. 더 옛것 = `docs/archive/md-2026-05-29/`.
> 📋 **라운드테이블 회의**(무거운 안건 — 마이그레이션·VAT 공식·RRN·`config/auth.php` 등): 트리거 「회의 돌려줘」·「/회의」. 프로토콜 `docs/archive/md-2026-05-29/decision_protocol.md` · 부서 프롬프트 `docs/meetings/departments/` · 과거 결정 `docs/meetings/INDEX.md`. (전역 에이전트는 2026-11-20 `~/.claude/agents-parked/` 로 옮겨 둠 — 회의를 돌리려면 `car-erp-*.md` 6개를 되돌린다.)

## 🏷️ 명칭 규칙 (항상 이대로)
회사 3사 = **ssancar · heyman · karaba**, 앱 2종 = **erp**(이 레포) · **board**(형제 앞단 앱). 지칭은 **{회사}{앱}** 한 토큰: `ssancarerp`=`heymancar.com` / `heymanerp`=`heysellcar.com` / `karabaerp`=`karaba-erp.com` / `ssancarboard`·`heymanboard`(`karababoard` 는 추후). 막연히 "ssancar"·"board"만 쓰지 않는다. 단일 master → 서버별 `.env` 로 구분.

## 🔗 형제 앱 board · ssancar.com 협업
- **board** = 매입·검차·경매 앞단 앱(`C:\xampp\htdocs\board`, 포트 8002, 별도 repo/DB/APP_KEY/배포). heymanboard↔heymanerp · ssancarboard↔ssancarerp 두 쌍이 살아 있다 — board 영향을 셀 땐 **ssancarerp 데이터도 센다**. 포털 읽기 API 는 3사 배포분을 그대로 읽는다.
- **연동 스펙 권위**: 받는 쪽 = `docs/integration/purchase-sync-receiver.md` · 읽기 API = `docs/integration/board-portal-api.md` ↔ 보내는 쪽 = board `SKILLS.md §12`. 상호 링크, **복사 금지**. 대화로 정해진 것·재개 절차 = `docs/integration/board-collab.md`(진입점, 스펙 복사 금지).
- **ssancar.com**(바이어 마이페이지 × ERP, 별도 레포·서버, 자동배포 없음 — 대표가 서버를 직접 고친다): jin 이 「ssancar.com 협업 이어서」·「바이어 마이페이지」·「포털 연동」이라 하면 **`docs/integration/ssancar-portal-collab.md` 부터** 읽는다. 정본 = `C:\Users\User\Desktop\연구소\ERP_SSANCAR_WEB\ERP_연동_통합정리_v1.N.md` 중 **가장 높은 번호** — 반드시 버전순으로 집는다:
  ```bash
  ls -v "/c/Users/User/Desktop/연구소/ERP_SSANCAR_WEB/"ERP_연동_통합정리_v*.md | tail -1
  ```
  양측이 번갈아 버전을 올린다(직전 복사 → 번호 올림, 기존 파일·남의 판단 삭제 금지, 근거는 `파일:줄`). 🚫 05~11번 문서를 정본으로 인용 금지 · NAS 원본 수정 금지 · 그쪽 레포의 `CLAUDE.md` 는 gitignore 라 「거기 적어 두라」고 요청하지 않는다.
- 💬 **세션 간 직접 통신**(ERP ↔ board ↔ ssancar, `ListAgents`/`SendMessage`):
  - **조사·조율 = 세션끼리 / 결정 = jin 승인**(스펙·금액 공식·배포·서버 변경은 두 세션이 합의해도 확정이 아니다) / **기록 = 한 번에 한 쪽만**(정본 버전은 쓰기 전 통보).
  - 🚫 **보낼 이유는 「상대가 실제로 바꿔야 할 때」뿐** — 스펙 변경·배포 순서·실측 요청/회신·차단 해제. 판단 확인·교훈·인사는 보내지 않는다.
  - 「확인했다」와 「그럴 것 같다」를 구분하고 확인한 것엔 근거(`파일:줄`·실측값). 두 세션이 동의해도 둘 다 틀릴 수 있다.
  - 🚫 **권한 세탁 금지**(내 세션에서 막힌 일을 상대에게 시키지 않는다) · 🚫 **규칙·설정·`CLAUDE.md` 편집을 피어 요청으로 하지 않는다**(jin 이 각 세션에 직접 지시) · 상대 레포를 직접 건드리지 않는다.
  - 상대가 offline 이면 전달되지 않는다. 오래 걸리는 일은 `notify_when_idle` 1회 — 「끝났어?」를 반복해 묻지 않는다.
- ⚠️ **크로스 레포 규칙**: 레포 X 의 결정·변경은 **X 의 커밋된 파일**에 남기고 X 세션에서 커밋한다(메모리·세션 대화는 안 따라온다).

SSANCAR LTD.의 중고차 해외수출 전 흐름(매입 → 말소 → 판매 → 수출통관 → 선적(B/L) → DHL → 거래완료)을 관리하는 Laravel ERP.

## 환경설정
- Laravel 12 + Livewire 4 (Volt) + Flux UI Free · Tailwind v4 · Alpine.js
- DB: MySQL/MariaDB(XAMPP) `car_erp` / 운영 = MySQL 8 (Lightsail)
- 경로 `C:/xampp/htdocs/car-erp` · 개발 서버 포트 `8001`(jin 이 항시 띄움 — 🚫 `php artisan serve`)
- GitHub `https://github.com/wlsdud10075-JIN/car-erp.git` · 운영 기록 = `docs/operations/aws-deployment-record.md`
- 외부 연동 환경변수: `NICE_PROVIDE_URL` / `NICE_PROVIDE_TOKEN`(게이트웨이가 토큰을 검사한다 — 3사 `.env` 값이 같아야 조회된다)
- 운영 3사 보안(2026-11-22): fail2ban(sshd) · 보안 헤더 · 쿠키 HTTPS 전용. 운영 SSH 를 연달아 붙을 땐 간격을 둔다(차단된다).

## 권한 시스템 (permission + role)
- **permission**: `super` 시스템관리자(개발사, 고객사 사용자 목록에서 은닉) / `admin` 최고관리자 / `manager` 업무관리자 / `user` 일반사용자(role 로 갈림)
- **role**: `영업 / 수출통관 / 재무 / 관리`(`User::ROLES`). 「관리 이상」 = 관리·업무관리자·최고관리자·시스템관리자.
- **누가 어느 화면·버튼·승인을 쓰나 = `docs/core/role.md` 가 정본**(미들웨어 alias 표·승인 권한·담당 범위·알림톡 수신자). 이 파일에 표를 다시 적지 않는다.
- ⚠️ `users.role` DB 기본값은 `'전체'`(ROLES 밖)라 `canAccessErp()` 가 false — 우연한 안전망이다. **기본값을 유효 role 로 「정리」하지 말 것**(가드 `PublicRegistrationDisabledTest`). 공개 회원가입 라우트는 없다.
- 🚨 **`verified` 미들웨어는 접근 방어가 아니다**(`User` 가 `MustVerifyEmail` 미구현). 실제 게이트는 permission + role 뿐(SKILLS §8 #6).
- 로그인 뒤 `/dashboard` 는 전원을 `/erp/dashboard` 로 보낸다. 비밀번호는 `password` 해시만.

## 도메인 고정 용어

### 차량 진행상태 10단계 (computed — DB 컬럼 아님, 필터용 캐시 `progress_status_cache`)
`progress_status_rule_version` **v4 = 기본**(v1~v3 는 grandfather 안전망). 실무 순서: 매입 → 판매 → 반입지·신고서 → 선적일 → ETA → 통관 체크 → B/L → 거래완료.

**v4 cascade — 위에서부터 첫 매칭**:
1. `bl_document` → **거래완료**
1-B. `incoterms='FOB'` AND 포워딩사 `buyer_handles_bl`(FOB포워더) AND 판매 완납 → **거래완료** (판정은 이름이 아니라 플래그. 가드 `ProgressRuleFobForwarderTest`)
1-C. 매입취소 차(`cancel_status=cancelled`) AND `sale_price>0` AND 미수 0 → **거래완료**
2. `is_export_cleared` → **통관완료**
3. (반입지 OR 신고서) AND `shipping_date` AND `eta_date` → **통관중**
4. (반입지 OR 신고서) AND `shipping_date` → **선적완료**
5. `bl_loading_location` OR `export_declaration_document` → **선적중**
6. `sale_price > 0` AND 판매미입금 ≤ 0 → **판매완료**
7. `sale_price > 0` → **판매중**
8. `is_deregistered` AND `deregistration_document` → **말소완료**
9. `purchase_price > 0` AND 매입미지급 ≤ 0 → **매입완료**
10. 기본 → **매입중**

- ⚠️ 선적일·ETA 는 **반입지나 신고서가 있을 때만** 단계로 친다(엑셀 적재 차가 판매 단계에서 튀지 않게). 단계 뜻은 `domain.progress_hint` — 규칙을 바꾸면 같이 고친다. 가드 `ProgressRuleV4StagesTest`.
- 🚢 **출항 이후는 진행상태가 아니다** — 운항중·도착예정은 `sailing_status`(선적일+ETA, ETA 당일부터 도착예정) 별도 축.
- 🎯 **「매입중」 필터만 넓다** — 차량관리 목록에서 「매입중」을 고르면 매입 잔금이 남은 차 전부(판매중·선적완료여도). 단일 출처 `Vehicle::scopeProgressStage()`/`scopeNotProgressStages()`. 순수 확대(캐시값이 매입중인 차는 미지급 0 이어도 남는다). 🚫 **대시보드 진행단계 스트립은 넓히지 않는다**(분포표라 합이 총 대수를 넘는다) — 스트립 숫자를 눌러 가면 목록이 더 많이 나오는 것은 의도된 차이.

### 판매채널 — `export` 단일값
`vehicles.sales_channel` 은 `ENUM('export')` 하나다. 🚫 **채널 분기를 새로 만들지 말 것** — enum 밖 값은 SQLite 테스트를 통과하고 운영 MySQL 에서만 죽는다(SKILLS §8 #36). 「국내 거래냐」는 통화(`currency='KRW'`)·진행 단계·내수 바이어(`buyers.is_domestic`)로 본다.

### 입금 상태 · 통화
- 완납(총입금 ≥ 총판매) / 부분입금(XX%) / 미입금 — computed.
- `vehicles.currency`: `USD / JPY / EUR / GBP / CNY / KRW`(`savings_statuses.currency` 동일).

### 돈 — 정산 · 환차 · 미수 · 2차 정산 · 월정산
**정본은 핵심 편이다. 이 파일에는 공식을 다시 적지 않는다**(두 곳에 적으면 한쪽이 낡는다).

| 무엇 | 핵심 편 |
|---|---|
| 정산 공식 · 사내직원 건당 등급 · 서류비 · 발송비 · 회사이익 · karaba | `docs/core/money-1-settlement.md` |
| 환차 포함/제외 · 정산환율 · 2026-12-01 전환(판매잔금 환율 자동) | `docs/core/money-2-fx.md` |
| 세 합계(총판매가·정산 기준액·면장 기준액) · 미수 · 미지급 · 「떠난 차」 · 채권 위험 등급 | `docs/core/money-3-receivable.md` |
| 지급 → 2차 정산 · 이월 · 마감 뒤 잠금 · 회계 재조정 | `docs/core/money-4-secondary-lock.md` |
| 월정산(결재선 · 지급보류 · 조정 · 내수 · 퇴사 · 회사 순이익 표시) | `docs/core/money-5-payout-batch.md` |
| 매입 등록 락 · 수출 진입 · B/L 게이트 · 우회 승인 · 일반재고 상한 | `docs/core/lock.md` |
| 권한 · 역할이 하는 일 · 담당 범위 · 승인 권한 · 알림톡 수신자 | `docs/core/role.md` |

- 🚨 돈·락·권한 코드를 고칠 땐 해당 편을 먼저 읽고, 고친 뒤 **그 편도 같은 작업에서** 고친다(가이드와 같은 규칙).
- 🔎 설정으로 갈리는 값(환차 시작 귀속월 · 락 임계 · 정산 파라미터)이 **지금 얼마인지는 문서가 아니라 실측**으로 답한다.
- 🚫 미수·미지급·마진을 SQL 이나 손으로 다시 계산하지 않는다 — accessor·스코프·캐시 컬럼을 쓴다.

## 뷰 경로 규칙
- Volt 컴포넌트 `resources/views/livewire/erp/...`(관리자 화면은 `resources/views/livewire/admin/...`) · 업무 대시보드 조각 `resources/views/erp/dashboard/` · 공용 부품 `resources/views/components/erp/`

## 핵심 주의사항
1. **Volt 컴포넌트는 `#[Layout('components.layouts.app')]` 필수**(auth 페이지는 `components.layouts.auth`) — 누락 시 500.
2. 사이드바: 데스크탑 `sticky top-0 h-screen` / 모바일(<768px) fixed drawer + backdrop(SKILLS §7).
3. `<x-layouts.app>` 슬롯 내 `@php` 블록에서 `use` 금지 — FQN 직접 호출.
4. **`.md` 는 dev 전용** — master 에는 cherry-pick 으로 코드만.
5. **커밋 전 `vendor/bin/pint --dirty` — `.php` 만. `.blade.php` 엔 절대 금지**(SKILLS §8 #22).
6. 뷰 캐시 문제 시 `php artisan view:clear && php artisan cache:clear`(🚫 병렬 테스트 도는 중엔 금지).
7. Tailwind v4 — 새 유틸 클래스는 빌드된 CSS 에 있어야 먹는다(`npm run build`). 서버는 배포 때 빌드한다.
8. 차량 진행상태는 computed — 목록 필터는 `progress_status_cache`.
9. 비용 입력칸은 10개(`Vehicle::DISPLAY_COST_FIELDS`), 합계 `cost_total` 은 옛 칸 4개를 더한 14칸이다. karaba 는 라벨이 다르다(`Vehicle::costLabel()`).
10~13. (정산 공식 요점 — 지금은 `docs/core/money-1-settlement.md` 에 있다. 번호는 다른 문서가 「핵심 주의사항 14·15번」으로 가리켜서 남겨 둔다.)
14. 🚨 **Volt public 프로퍼티와 메서드에 같은 이름 금지** — `wire:click` 이 요청조차 안 보내고 조용히 죽는다. 메서드는 `searchNow()`/`applyFilters()`. 가드 `VoltPropertyMethodCollisionTest`(SKILLS §8 #32).
15. 🧭 **「느리다」 제보 = 성능으로 단정 금지** — ①어떤 조작 → 몇 초 뒤 무엇이 바뀌나 ②Network 탭에 요청이 뜨나 ③뜬다면 Time. JS/CSS 만 바뀐 배포는 `Ctrl+Shift+R` 전엔 체감 검증 불가.
16. 🧩 **목록 화면은 공용 부품으로** — 검색 `<x-erp.search-bar>`(치는 동안 조회하지 않고 **[조회]·Enter** 로만, 가드 `SearchRequiresButtonTest`) · 고르는 칸 `<x-erp.combobox>`(값이 글자인 목록은 `:options="[]" :extra="[값=>이름]"`) · 필터 칩 `<x-erp.filter-chips>` · 머리글 정렬 `<x-erp.sort-th>`. 새 목록·필터를 만들 때 `<select>`·자체 검색칸을 새로 짜지 않는다. 정본 `docs/design/list-screen-commons.md`.
17. 🇰🇷 **감사로그에 새 액션·대상·칸을 남기면 한글 이름과 구역을 같이 넣는다** — `config/column_labels.php`(actions·models·칸) + `App\Support\AuditArea`(`ACTION_AREA`·`TYPE_AREA`). 가드 `AuditAreaTest`·`LogScreensKoreanCombosTest` · 씬 점검 `audit-log`.

## 📖 가이드 동기화 (개발할 때마다)
> 🚨 **사용자에게 보이는 동작을 추가·변경·삭제했으면 같은 작업에서 가이드도 고친다.** 가이드는 둘이다.

1. **ERP 안 「업무 가이드」**(jin 이 「업무가이드」라 하면 이것) — `resources/guides/{slug}/index.html` + 그림, 목록 `config/guides.php`. **글과 스크린샷 둘 다** 고친다. 이건 **코드다** — 코드 커밋으로 master 에 cherry-pick 한다. 해당 씬이 없으면 새 씬이 필요한지 jin 에게 묻는다. 촬영 = `scripts/scene/README.md`.
2. **Notion 사내 가이드·챗봇 카드** — 원본은 repo, Notion 은 발행물. **Claude = repo / Codex = Notion 발행 / 회사 GPU PC = 색인.** 🚫 Notion 을 손으로 고치지 않는다.

| 무엇 | 소스 |
|---|---|
| 챗봇 기능 카드 | `scripts/notion-cards/cards.json`(카드마다 `audience` 필수) |
| 부서 가이드 4페이지 | `scripts/notion-guide-publish.php` `blocks_*()` |
| 워크플로우·에러 2페이지 | `scripts/notion-workflow-lock-guide.php` |
| 개발 현황판 | `docs/operations/aws-deployment-record.md` 날짜 머리 항목 |

```bash
php scripts/notion-cards/publish.php --verify
php scripts/notion-guide-publish.php --verify
php scripts/notion-workflow-lock-guide.php --verify
php scripts/notion-dev-status-sync.php --verify
```
- 화면 동작이 바뀌면 **①②를 둘 다** 본다(①에 해당 씬이 없고 ②에만 설명이 있을 수 있고 그 반대도 있다 — `grep` 으로 그 화면 용어를 양쪽에서 찾는다). ②의 `scripts/notion-*` 는 dev 전용이라 **docs 커밋으로 따로**(master 에 안 간다).
- 발행(`--apply`)은 Claude 가 하지 않는다 — **회사 PC 야간 배치(매일 04:30)** 가 `--verify` 로 어긋난 것을 찾아 Codex 로 발행하고, 개발 현황판은 ERP·board·ssancar.com 3곳을 직접 올린다(`scripts/nightly/nightly-notion.ps1`, 결과 = 관제센터 「Notion」 줄 · 회사 PC `%USERPROFILE%\nightly\reports\notion-YYYYMMDD.md`). 급하면 jin 이 Codex 에 「Notion 반영해줘」.
- 🚨 `audience` 없이 발행하면 다음 03:00 색인이 통째 멈춘다.
- 🗑️ 기능을 없앴으면 `NotionGuideAudienceTest::RETIRED_TERMS` 에 그 화면 용어를 추가한다. 가드 = `NotionGuideAudienceTest`(master 에선 자동 skip).

## Git 브랜치 전략

> 🚨🚨 **master push 1회 = 3개 라이브 회사 동시배포(heymanerp·ssancarerp·karabaerp). 깨지면 세 회사가 동시에 다운 → 사업 마비.**
> **배포 전 매번**: ①jin 명시승인 ②전체 테스트 통과 ③동작 보존 증명(정산·권한·마이그레이션 특히) ④cherry-pick 은 의도한 커밋 SHA 만 ⑤마이그는 3-DB 검증 ⑥APP_KEY 재생성 절대 금지 ⑦push 후 **deploy 런의 잡 5개**(`tests / ci` · `tests / mysql-check` · `deploy` · `deploy-ssancar` · `deploy-karaba`) 전부 green + 서버 SHA 확인 ⑧위험 배포는 업무시간 외 ⑨가이드도 같이 갔나(위 절). **배포가 나간 뒤** 화면이 깨지면 즉시 revert 커밋 push(3사 동시 원복), 운영 SSH 직접수정 금지 — 테스트 게이트에서 막힌 것은 서버에 안 나갔으니 revert 가 아니라 고쳐서 다시 push. 상세 = 메모리 `feedback_three_company_deploy_safety`.

- **CI 배포 게이트**: `deploy.yml` 이 테스트를 먼저 돌리고 통과해야 3사 배포가 시작된다. master 엔 독립 `tests` 런이 없다 — 확인은 `gh run view <deploy run> --json jobs`. 테스트 실패 시 배포 잡은 skipped(코드는 master 에 있고 서버엔 안 나감 → 고쳐서 다시 push). `linter` 는 게이트 아님.
- `dev` 작업 브랜치(기본, 직접 커밋·푸시 — feature 브랜치·PR 없음) / `master` 프로덕션(push = 자동 배포, `artisan down` 1~3분) / `demo`.
- 한 커밋 = 한 논리적 변경. **설계 문서·`.md`·`scripts/notion-*` 는 코드 커밋에 섞지 않는다**(docs 커밋 따로).

### dev → master 배포 절차 (cherry-pick)
1. **「dev 가 N 커밋 앞섬」은 정상** — 커밋 수가 아니라 코드 diff 로 본다:
   ```bash
   git fetch origin
   git diff --stat origin/master..dev -- . ':(exclude)*.md'
   git log --oneline origin/master..dev
   ```
2. 올릴 커밋이 `.md`·`notion-` 을 건드리는지 먼저 확인: `git show --stat --format= <sha> | grep -E "\.md|notion-"`. 섞였으면 그 커밋만 `git cherry-pick -n` 으로 받아 해당 파일을 `git rm --cached` + 삭제한 뒤 `git commit -C <sha>`.
3. 미커밋 파일(`.claude/*` 등)이 checkout 을 막으면 `git stash push -m "..."` → 끝나고 `git stash pop`.
4. ```bash
   git checkout master && git merge --ff-only origin/master
   git cherry-pick <코드 커밋 SHA…>
   git status --short        # 충돌 0 · 커밋 수 확인 — 🚫 cherry-pick 과 push 를 한 명령에 묶지 않는다(SKILLS §8 #132)
   git push origin master    # 단독·플래그 없이
   git checkout dev
   ```
5. 커밋 메시지는 Bash 에선 heredoc(`git commit -F - <<'EOF'`), PowerShell 에서만 `@'…'@`. 섞지 않는다.

## ⚠️ APP_KEY 영구 손실 경고
**`php artisan key:generate` 금지** — `nice_reg_owner_rrn`(주민등록번호)이 `APP_KEY` 로 암호화돼 있어 키가 바뀌면 **전량 복호화 불가**, DB 백업으로도 복구 안 된다. 다른 PC 는 기존 키를 `.env` 에 직접 입력. DB 백업 시 APP_KEY 도 분리 보관. 상세 = `docs/operations/key-rotation.md`.

## 새 PC · 다른 PC 에서 재개
`docs/operations/new-pc-setup.md` 를 따른다(php.ini `extension=gd`·`zip` · clone · `composer install && npm install` · `.env`(기존 APP_KEY 복사) · `php artisan migrate` · `npm run build`). 재개 시 최소: `git pull origin dev` → `php artisan migrate:status` → 의존성·빌드 → 테스트. 테스트 계정 `admin@car-erp.test / password`(super).

## 자주 쓰는 명령어
```bash
npm run build                       # 프론트 빌드
php artisan migrate                 # 마이그레이션 (🚫 migrate:fresh · db:wipe 는 로컬이라도 금지)
php artisan test --parallel         # 전체 3~4분 (기대 실패 0)
vendor/bin/pint --dirty             # 커밋 전 (.php 만)
```

## AI 크로스 체크 (/cross-verify)
```powershell
cmd /c 'echo. | codex exec "프롬프트" 2>&1'          # Codex — 기본 모델(gpt-5.5)만
gemini -p "프롬프트" --approval-mode yolo 2>&1       # Gemini
```

## 외부 연동 상태
| 연동 | 상태 | 위치 |
|---|---|---|
| NICE API | ✅ ssancar-erp 미들웨어 경유. 제원은 전용 컬럼 우선·`nice_raw` 폴백 | `app/Services/NiceApiService.php` |
| 포워딩사 메일 | ❌ 영구 제거 | - |
| DHL API | ⏸️ 수동 입력만 | - |
| S3 | ✅ 버킷 `heysellcar-erp-docs`, 서명 URL | `config/filesystems.php` |

## 지금 상태 · 남은 일
**여기 적지 않는다(금방 낡는다).** 최신 배포·잔여 안건 = 메모리 `MEMORY.md`(색인 → 필요한 `project_*.md`만). 메모리 파일명은 언더스코어. 전체 배포 기록 = `docs/operations/aws-deployment-record.md`. 날짜를 몰라도 주제어로: `grep -rn "키워드" ~/.claude/projects/C--xampp-htdocs-car-erp/memory --include=*.md`.
- 3사 운영 라이브. 로컬 전체 테스트 기대 실패 0(건너뜀 1 = `MysqlCheckConstraintTest`).
- 🚀 배포 직후 수동 캐시 정리는 하지 않는다(deploy.yml 이 www-data 로 `view:cache`).

## 대시보드 명칭 (대화·주석 모두 이 이름)
| 명칭 | URL | 대상 | 성격 |
|---|---|---|---|
| **업무 대시보드** | `/erp/dashboard` | 로그인하는 전 직원(역할 뷰) | 지금 할 일 — 기간 선택 없음 |
| **관리자 대시보드** | `/admin/dashboard` | admin/super | 결과 — KPI·차트·통계(기간 지정) |

**업무 대시보드 역할 뷰 5개**(데이터 `App\Services\Dashboard\WorkDashboardData`, 뷰 `resources/views/erp/dashboard/*.blade.php`, 가드 `WorkDashboardRoleViewsTest`). 누가 어느 뷰를 여나 = `allowedRoleViews()`(admin·업무관리자 5 / 관리 4 / 그 외 본인 1).
- **담당 영업** — 관리가 자기 담당 영업들의 돈을 묶어 본다. **운영에서 영업은 ERP 에 로그인하지 않는다**(board 를 쓴다) — role=영업 계정·미들웨어는 코드에 남아 있고 지우지 않는다. 범위 `User::getSubordinateSalesmanIds()`. 영업별 카드는 접힌 한 줄이 기본.
- **수출통관** — 서류 칸반 4칸 + 이번 주 선적·ETA 달력 + 서류 기준 표.
- **재무** — 받을 돈·줄 돈 + 정산 진행줄 + 통장 마감잔액 입력 + 채권 위험 표.
- **관리** — 수출통관 + 재무 합본 + 「오늘 먼저」. 🚫 결재·감독 화면으로 만들지 않는다(그건 업무관리자).
- **업무관리자** — 이번 달 올릴 것(월정산 제출 준비) · 퇴사 정산 · 대표 결재 중 · 오래 멈춘 차(단일 출처 `WorkDashboardData::STUCK_QUEUES`, 새 N일 기준을 만들지 않는다) · 보고용 숫자.
- 공통 = 처리 필요 항목 + 맨 아래 10단계 진행 단계 스트립. 관리자 대시보드와 공통인 숫자(미수·미지급·정산)는 같은 scope 로 센다.
