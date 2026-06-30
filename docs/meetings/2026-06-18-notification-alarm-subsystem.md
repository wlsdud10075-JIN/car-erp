# 📅 회의록: ETA 영구 알람을 범용 알림(notification) 서브시스템으로 도입할지 + 재사용 범위
- 일시: 2026-06-18
- 강도: 풀회의 (/회의 명령어 호출)
- 안건 유형: 마이그레이션 + 신규화면 + 대시보드 정합 + 권한 (복합)
- 자동발동 여부: yes (/회의 슬래시, 사용자 명시 요청)
- 발동 부서: PO + Engineer + QA + Security + Ops + Specialist [A.UX설계자 + B.데이터무결성 + E.승인·권한정책] + 사외이사 Codex/Gemini

## 배경
헤이맨 김혜진 의뢰(2026-06-18): 선적 배 도착(ETA=`vehicles.eta_date`) N일(기본10일, 설정값) 전 "통관서류 작업 + 이 차량 미수금 얼마" **영구 알람**. 현재 토스트(`dispatch('notify')`, sidebar.blade.php:457)는 4.5초 후 소멸이라 놓치면 끝 → 부적합. jin 1차 결정: 비차단 상주카드 + 사이드바 벨 안읽음 뱃지 + [확인] / 자동해소+수동확인 / v1=ETA-10 통관서류 + 'ETA없음' 보조 / 리드데이 설정값. 핵심 회의 질문 = 이걸 **범용 알림 인프라**로 설계할지 + 어디까지 재사용할지 + 데이터 모델 경계.

---

## 💬 부서별 발언 요약 (Sonnet 4.6 — 전원 조건부 GO)

### 📋 PO — 조건부 GO
v1은 단발(ETA 1종) 즉시, 범용 확장은 v2 이후. 막힌 곳=수출통관(김혜진)+관리: `eta_date` 있어도 들여다볼 트리거 없어 카톡/수동 의존, 통관 지연=차단급. **흡수 금지 목록 명확화**: 기존 사이드바 뱃지 3종(clearanceBadge/pendingApprovals/pendingFinanceConfirmations)·토스트·B/L게이트·정산대기 = 이미 실시간 카운트라 알림 흡수 시 이중출처 노이즈. 재사용 우선순위: ①ETA 통관서류 → ②판매 미수독촉(`sale_unpaid_amount_krw_cache` 재활용) → ③매입미지급. **별건3과 sidebar.blade.php 파일 충돌** 주의(순서 조정). 지급자동화·통합 로드맵과 병렬 가능.

### ⚙️ Engineer — 조건부 GO (공수 12~16h)
`eta_date` **인덱스 없음 → 마이그에서 추가 필수**(전차량 풀스캔 방지). 멱등=scan 시 `whereNull('resolved_at')->exists()` 체크. Rule 클래스 배열(`app/Services/Notifications/Rules/`)로 추상화하면 v2 추가 시 커맨드/마이그 무수정. 사이드바 벨 뱃지=기존 @php 카운트 패턴 4번째로 삽입. 상주카드=레이아웃 내 Volt. 자동해소 트리거를 `Vehicle::saved` 훅에(기존 거래완료 감지 훅과 정합). `Setting::get()`로 리드데이 즉시 읽기. 롤백=`DROP TABLE notifications`.

### 🧪 QA & Domain Integrity — 조건부 GO
**단일출처 강제(최중요)**: 알림 판정이 `Vehicle::scopeAction('clearance_needed')`/`('sale_unpaid')`를 **직접 호출**해야 함. raw SQL 재정의 시 같은 차량이 알림엔 뜨고 목록엔 없는 이중출처 발생. **채널 격리**: scopeAction에 `where sales_channel='export'`가 없으므로 ETA 알림엔 명시 필수(헤이맨/카풀 통관 흐름 없음). 환율0 외화차량 `sale_unpaid_amount_krw_cache=null`은 `exchange_rate_missing` 별도 type 분기. **'ETA없음' 보조알람 v1 제외 권고**(eta_date NULL 차량 다수 → 노이즈). 멱등/자동해소 회귀 ~25분 + Unit Test 신규 필수. 자동해소는 cron 24h gap 두지 말고 `Vehicle::saved`에서 즉시 `resolved_at`.

### 🔒 Security & Compliance — 조건부 GO (NO-GO 조건 동반)
**message_meta whitelist**: `[vehicle_id, vehicle_number, eta_date, unpaid_amount_krw]`만. RRN(`nice_reg_owner_rrn`, 암호화+MASKED_COLUMNS)·소유자 성명·바이어 연락처·계좌 평문 저장 금지(개인정보보호법 §29). **IDOR 재인가**: 알림 목록 조회·[확인] mutating 모두 `canScopeVehicle` 매번 재인가(읽기 1회 인가 의존=Review #26 재발). `confirmed_by=auth()->id()` 서버지정, Livewire id는 action 파라미터/`#[Locked]`. [확인]·자동해소 → `audit_logs` 기록(auto_resolved는 user_id=null). 통관탭 문서 다운로드는 기존 컨트롤러 경유라 `document_access_logs` 우회 없음.

### 🚀 Ops & Deploy — 조건부 GO (다운타임 0초 추가)
notifications CREATE는 단독 테이블이라 lock 없음, 자동배포 `artisan down` 1~3분 내 수납. json 컬럼 MySQL8 선례 있음(`confirmed_snapshot`·`approval_requests.payload`). `routes/console.php`에 `Schedule::command('notifications:scan')->dailyAt('06:00')->withoutOverlapping()` 필수(중첩 방지). scan 예외 시 `Log::error` 필수(cron 무음실패 전례 2026-05-26). queue worker 무관(동기 cron). 환경의존성·캐시 rebuild 없음. 3DB 주의: SQLite는 json→TEXT fallback이라 `->where('meta->key')` JSON 경로 쿼리 금지.

### 🔧 Specialist [A.UX 설계자] — 조건부 GO
상주카드 **wire:navigate 리스너 유실(§8 #21)** 위험 → `document.body` append 금지, **레이아웃 파일 내 Livewire 컴포넌트로 고정**. 토스트(z-50)와 상주카드(z-40) z-index 분리. 알림 4건+ "N건 더" 접기. 모바일=§11 페어렌더(`hidden sm:block`/`block sm:hidden`), 벨 뱃지=기존 `badge-amber` 재사용, 카드 미수금=K/M/B 축약. [확인]→`confirmed_at`, 본문클릭→`?openVehicle={id}` 통관탭 이동.

### 🔧 Specialist [B.데이터 무결성] — 조건부 GO
`type VARCHAR(60)` + `message_meta json`(**표시 전용, WHERE 조건에 json 쓰지 말 것** — 인덱스 미적용+MariaDB/MySQL JSON 함수차). 멱등=`updateOrCreate(['type','vehicle_id','resolved_at'=>null], [...])`. 인덱스 `(type,vehicle_id,resolved_at)`+`(target_role,resolved_at,due_date)`. 알림은 회계 원장 아니라 retroactive 무관. 캐시 null=환율미입력 → 알림 생성조건은 `eta_date` 기준만, 미수금은 보조표시. **자동해소는 cron scan에서만**(saved 훅 복잡도·N+1 우려).

### 🔧 Specialist [E.승인·권한] — 조건부 GO
[확인]=단순 ack(Gemini Lock/paid immutable 무관). `target_role` 필터만으론 동일 role 타인 알림 [확인] 가능 → `vehicle_id` 경유 `canScopeVehicle` 재인가 필수. 영업은 target_role에 미포함이라 알림함 빈 목록. [확인]="읽음"으로 한정, 실제 통관작업은 통관탭에서(미래 "착수 승인"으로 확장 시 SoD 이슈 → ApprovalRequest 유지). `canAccessClearance()` 재사용으로 일관성.

---

## 🧩 중간 회의 결과 (Opus 4.7 1차 취합)
전원 조건부 GO. 합의 6건(단일출처·채널격리·흡수금지·whitelist·IDOR재인가·운영가드). **충돌 3건**: ①자동해소 위치(saved 즉시 vs cron만) ②ETA없음 보조알람 v1포함 여부(jin 포함 vs QA 제외) ③테이블 신설 vs 경량대안(user_settings json).

---

## 🌐 사외이사 의견 (Codex / Gemini — 2인 모두 응답 성공)

### [Codex]
조건부 GO이나 "범용 알림" 명명은 과하다 — v1은 ETA 통관 리마인더로 한정. **놓친 리스크**: ①알림 신뢰성 SLA 부재(누락·중복·지연 책임 불명) ②감사로그 약함(확인자·해소사유·시각 남겨야) ③알림 피로도 기준 없음(배지 숫자만 늘면 ERP 신뢰 하락). **충돌 판정**: 자동해소=**saved 즉시+cron 보정**, ETA없음=**v1 제외 대시보드 필터로**, 테이블=**신설하되 범용 확장 금지·type 1개만**. SaaS 알림 표준=inbox+read state+audit trail+idempotent resolver, 토스트는 보조.

### [Gemini]
기술 완결성에 매몰돼 비즈니스 실무 효율을 놓침. **놓친 리스크**: ①**ETA 변동성**(선박 ETA 빈번 변경 → 단순 cron은 변경분 재발행/due_date 갱신 누락 시 실무 혼선, Stateful 관리 필요) ②알림 피로도·책임소재(중요도 가중치/그룹화 없으면 Dead Alert). **충돌 판정**: 자동해소=**Hybrid(saved 즉시+cron 최종 보정)**, ETA없음=**v1 제외**('알림' 아닌 '데이터 품질' 문제 → 대시보드 '데이터 보정 필요' 섹션으로 순도 유지), 테이블=**신설 압승**(JSON은 통계 시 병목이니 핵심 필드는 컬럼). 패턴=Event-driven(상태변화→독립 생명주기 알림). **자체 NO-GO**: (a)ETA없음 포함 시 노이즈 폭주로 안착 실패 (b)최소조건=**ETA 변경 시 발행 알림 due_date 자동 갱신** (c)대안=긴급도(P1~P3) 속성으로 사이드바 시각 분리.

---

## 🚨 NO-GO 상세
부서 6/6 전원 조건부 GO(명시 NO-GO 0). Gemini 자체 NO-GO는 'ETA없음 v1 포함' 한정 — (a)(b)(c) 충족, 그러나 최종 권고가 'ETA없음 v1 제외'로 수렴하므로 차단 사유 자연 해소. Security NO-GO 조건(whitelist+재인가)은 (a)(b)(c) 충족 → 필수 선행 조건으로 격상.

---

## 🏁 최종 권고 (Opus 4.7 최종 취합)
**판정: 조건부 GO** — v1을 **범용 알림 엔진의 첫 적용 사례**로 보되, **스키마만 확장형·코드는 ETA type 1개로 좁게**. 경량대안(테이블 미신설)은 부결(이력·확장·감사 위해 테이블 필수 — 사외이사 2인 일치).

### 충돌 3건 최종 판정
1. **자동해소 위치 → Hybrid (확정)**: `Vehicle::saved`에서 즉시 `resolved_at` 기록(24h gap 동안 obsolete 알림 노출 방지) **+** 매일 `notifications:scan`에서 최종 보정(reconciliation). 사외이사 2인 독립 수렴 + QA(gap)·Specialist B(훅복잡도) 우려 동시 흡수. *Specialist B의 N+1 우려는 해당 차량 1건 notifications만 `where->update`라 비해당.*
2. **'ETA없음' 보조알람 → v1 제외 (회의 권고, jin 최종 결정 필요)**: QA + Codex + Gemini 3자 일치 — 'ETA 없음'은 알림이 아니라 **데이터 품질 문제**. 알림 채널 순도 유지 위해 **대시보드 '데이터 보정 필요(ETA 미입력)' 섹션** + **선적 단계 저장 시 ETA 입력칸 노랑 처리**로 분리. (단 ETA 공백은 여전히 최대 실무 리스크 — 이 분리안으로 반드시 같이 처리.)
3. **테이블 신설 → 확정, 단 범용 확장 자제**: v1은 `type='eta_clearance'` 1개만. Rule 클래스 배열·target_role 라우팅 일반화는 v2(미수·매입미지급 흡수 시점)로 이연. 스키마는 확장형(아래)으로 동결.

### 신규 채택 조건 (사외이사 발)
- **ETA 변경 시 due_date 자동 갱신** (Gemini NO-GO 최소조건): `updateOrCreate(['type','vehicle_id', resolved_at=null], ['due_date'=>eta-lead, 'message_meta'=>...])`로 매 scan 시 open row의 due_date 갱신 → ETA 변동 자동 반영.
- **감사추적 강화** (Codex): `confirmed_at/by` + `resolved_at/reason`이 audit trail 역할. [확인]·자동해소를 `audit_logs` 연동(auto_resolved는 user_id=null).
- **알림 신뢰성**: scan 예외 `Log::error` + 익일 첫 실행 laravel.log 수동 확인(Ops).

### 필수 선행 작업 (배포 전)
1. 알림 판정 = `Vehicle::scopeAction()` 직접 호출 (raw SQL 금지) + `where sales_channel='export'` 명시
2. `message_meta` whitelist 상수 + 저장 시 외 필드 strip
3. 알림 목록/[확인] 매번 `canScopeVehicle` 재인가 + `confirmed_by=auth()->id()`
4. `eta_date` 인덱스 마이그 동시 배포
5. `notifications:scan` `->withoutOverlapping()` + `Log::error`
6. 멱등 `updateOrCreate(open row)` + ETA 변경 시 due_date 갱신
7. 상주카드 wire:navigate 리스너 유실 방지(레이아웃 내 Livewire)
8. [확인]·자동해소 audit_logs 연동

---

## 🛠 car-erp 영향 분석 (Opus 4.7 산출)

### 취약점 (Vulnerabilities)
- **IDOR**: target_role만 필터 시 동일 role 타인 알림 [확인] 가능 → canScopeVehicle 재인가 필수 (Review #26)
- **개인정보 §29**: message_meta 평문 json에 RRN/성명 유입 위험 → whitelist
- **이중출처 drift**: 알림 판정이 scopeAction과 다른 SQL이면 대시보드 카운트와 어긋남
- **obsolete 알림**: 자동해소를 cron에만 의존 시 24h gap 동안 처리완료 건이 알림으로 남음

### 보완사항 (Improvements)
- 단일출처 강제(scopeAction 재사용) / 채널 격리 명시 / 환율0 null → exchange_rate_missing 분기
- ETA 변동 대응 due_date 자동 갱신 / 알림 피로도 대비 due_date 정렬·긴급도(v2)
- ETA 데이터 공백 = 대시보드 보정 섹션 + 선적 저장 시 ETA 노랑 처리

### 코드 수정 (Code Changes)
- `database/migrations/[신규]_create_notifications_table.php` — type/vehicle_id(FK)/target_role/due_date/message_meta(json)/confirmed_at·by/resolved_at·reason + 인덱스 2종
- `database/migrations/[신규]_add_eta_date_index_to_vehicles_table.php` — `index('eta_date')`
- `app/Models/Notification.php` (신규) — scopes: open/forRole, resolve 헬퍼
- `app/Console/Commands/ScanNotifications.php` (신규) — `notifications:scan`, scopeAction 재사용 + export 격리 + updateOrCreate 멱등 + due_date 갱신 + 자동해소 보정
- `routes/console.php` — `Schedule::command('notifications:scan')->dailyAt('06:00')->withoutOverlapping()`
- `app/Models/Vehicle.php` — `saved` 훅에 즉시 자동해소(서류 업로드·거래완료·미수0)
- `resources/views/components/layouts/app/sidebar.blade.php` — @php 벨 뱃지 카운트 + 메뉴 항목 + 상주카드 Livewire
- `resources/views/livewire/erp/notifications/` (신규) — 알림함 index(페어렌더) + 상주카드 컴포넌트(confirm + canScopeVehicle 재인가)
- `routes/web.php` — `erp.notifications.index` (`erp` 미들웨어)
- `app/Models/Setting.php` 경유 `alarm_eta_lead_days`(기본10) + 기능설정(super) 입력칸
- 대시보드 '데이터 보정 필요(ETA 미입력)' 섹션 + 선적탭 ETA 노랑(ETA없음 알람 대체)

### 신규 추가 (New Additions)
- `tests/Feature/EtaNotificationScanTest.php` — 멱등(2회 실행 동일)·채널격리(heyman 0건)·자동해소·환율0 null·ETA변경 due_date 갱신·IDOR 재인가

### 모순·NO-GO 처리 로그
- Gemini 자체 NO-GO('ETA없음 포함') → 최종 권고가 'v1 제외'로 수렴, 차단 사유 해소. (a)(b)(c) 충족했으나 안건에서 제외되어 무효화 아님(권고 반영).
- 충돌 ①(자동해소): 사외이사 2인 독립 Hybrid 수렴으로 부서 양측 입장 통합.
- Specialist B 'saved 훅 N+1' → 1차량 단일 update라 비해당, Hybrid 채택에 지장 없음.

## 🔗 참조
- 관련 과거 회의: 2026-05-21 extension-scene(알림톡/SMTP 외부API 제외 — 인앱 알람과 별개), 2026-05-26 external-review(IDOR·document_access_logs)
- 메모리: project_notification_alarm, project_db_tier_mismatch(3DB json), project_payment_automation(병렬)
- SKILLS §8 #21(wire:navigate 리스너)·#26(IDOR), §9(action 단일출처), §13(미수 단일출처) / CLAUDE.md 권한 미들웨어 6종
