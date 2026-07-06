# 알림톡 자동발송 트리거 배선 스펙 (다음 세션 집중용)

> 2026-07-06 jin "트리거 지금 만들기(turnkey)" 선택 → 이 문서가 **권위 스펙**. 다음 세션 시작 트리거 = "알림톡 트리거 이어서".
> 파운데이션·11 템플릿·게이트는 이미 배포됨(master 5c34099, inert). 이 문서는 **자동발송 배선**만 다룸.
> 관련: [[project_alimtalk_notifications]] 메모리 · `docs/operations/alimtalk-templates-draft.md`(문구) · `app/Support/AlimtalkTemplates.php`(코드 단일출처).

## 전제 (이미 완료)
- `BizmAlimtalkService::send($code, $phone, $vars, $context)` = **fire-and-forget**(예외 안 던짐) + 게이트(`alimtalk_enabled`·per-template toggle·isConfigured) 내장. 트리거는 그냥 `send()` 호출만 하면 됨 → 게이트 off면 자동 skip. **그래서 트리거는 inert 로 안전하게 배포 가능.**
- 11 템플릿 코드·vars 는 `AlimtalkTemplates::TEMPLATES` 가 단일출처. 딜러 말소증(erp_deregistration_notice)은 **수동 버튼 이미 구현·배포** → 트리거 대상 아님.
- 발송 title 미전송(전부 기본형). 실발송은 jin BizM 승인 + 프로필키 입력 + `alimtalk_enabled` ON 후.

## 빌드 순서
1. **수신자 resolver** (공통 기반) → 2. **cron 배치 커맨드**(대부분) → 3. **이벤트 훅 2개** → 4. **스케줄 등록** → 5. **테스트**(Http::fake) → 6. **2차 배포**(inert) → jin 스위치 ON = turnkey.

---

## 1. 수신자 resolver (신설 — 예: `App\Support\AlimtalkRecipients`)
역할기반 하이브리드 (`users.phone` 존재 확인됨):
- **대표(admin)** = `User::where('permission','admin')` + phone 있는 사람. (super=진 제외)
- **관리** = `User::where('role','관리')` + phone.
- **영업(픽업 전용)** = **per-vehicle 담당 영업** = `$vehicle->salesman?->user?->phone` (전체 영업 아님 — 그 차 담당자에게만).
- **기능설정 override**: 회사(set)별 추가/override 번호 (메일 config 패턴). Setting 키 예: `alimtalk_recipients_admin_{set}` 등. 없으면 역할기반 fallback.
- 전화 없으면 skip(로그). 여러 명이면 각자 send.

## 2. cron 배치 커맨드 (스캐너 + send)

### 대표 요약 3
- **erp_daily_summary** — `alimtalk:daily-summary`, **매일 09:00**.
  - vars: `날짜`(어제/오늘), `판매건수`(이번달), `매출액`(이번달 원화), `선적전건수`·`선적전금액`, `선적후건수`·`선적후금액`, `미수합계`.
  - 정의(단일출처): **매출** = 관리자 대시보드 방식(currency=KRW→sale_price / 외화→sale_price×exchange_rate, sale_date ∈ 이번달). **선적전 미수** = 채권관리 `before_shipping`(진행상태 매입중~판매완료 & 미수>0 & **판매일+10일 경과=grace 제외**). **선적후 미수** = `after_shipping`(선적중~통관완료 & 미수>0, 즉시). **미수합계** = 선적전+후(grace 제외). 금액은 콤마 문자열.
- **erp_weekly_summary** — `alimtalk:weekly-summary`, **매주 금 18:00** (`->weeklyOn(5,'18:00')`).
  - vars: `주간`, `판매건수`(이번주), `매출액`(이번주), `선적전/후 건수·금액`, `담당자실적`(가변 여러 줄 — 담당자별 이번주 판매대수·매출. BizM 검수 반려 시 상위3명 고정).
- **erp_monthly_closing** — `alimtalk:monthly-closing`, **매월 10일**(`->monthlyOn(10,'09:00')`). 전월 귀속.
  - vars: `대상월`(전월, 예 "2026년 6월분"), `총매출`(전월), `총마진`(전월 정산 총마진 합), `지급총액`(전월 정산 실지급 합), `인원별지급`(가변, 영업담당자별 실지급). 정의: 정산 confirmed/paid 전월분. (귀속월 앵커 = confirmed_at+10일 배치와 정합 — [[project_settlement_payroll_batch]] 참고.)

### 관리 배치
- **erp_purchase_unpaid** — `alimtalk:purchase-unpaid`, **매일 아침**(예 08:00). 요약 1건.
  - vars: `건수`, `총액`. 정의: PBP `payment_date <= today` & 매입 미완납(`purchase_unpaid_amount`). (요약형 — 차량별 개별 발송 X.)
- **erp_sale_unpaid** — `alimtalk:sale-unpaid`, **매일 아침**. per-vehicle.
  - vars: `차량번호`, `바이어`, `미수금액`. 정의: **`Vehicle::action('sale_unpaid')`** 스코프 사용(= grace 10일 제외 이미 반영됨). 차량 단위 발송.
- **erp_eta_balance_due** — `alimtalk:eta-balance`(또는 기존 `ScanTaskAlarms` 확장), **매일**. ETA(`eta_date`) 7일 전 & 미완납(잔금 100% 미달, `sale_unpaid_amount>0`).
  - vars: `차량번호`, `바이어`, `도착일`, `남은일수`, `미수금액`. (도착 전 마지막 100% 채우기 — jin 선적일 알림과 별개 유지.)
- **erp_shipping_due** — `alimtalk:shipping-due`, **매일**. 선적일(`shipping_date`) 5일 전 & 미완납. **목록형**.
  - vars: `선적미수목록`(가변 한 변수 여러 줄). 각 차량 줄 예: `▶ 12가3456 · 선적 D-3 · 미수 40% [50%미만·사유: xxx]`. **<50%(입금우회 진행)** 판정 = `unpaid_ratio > 0.5` + `UnpaidExportOverride`(stage∈clearance/shipping) 존재 → `[50%미만·사유: {reason}]` 강조. reason = `UnpaidExportOverride.reason`(text).

### 영업 배치
- **erp_pickup_reminder** — `alimtalk:pickup`, **매일**. per-vehicle → 담당 영업.
  - vars: `차량번호`, `구입처`(purchase_from), `미지급금액`, `매입일`, `경과일`. 정의: **`purchase_date + 2일` 경과 & 매입 미완납**(`purchase_unpaid_amount > 0`, 계약금/잔금 필드 무관). 해소 = 매입 완납.

> cron 은 **하나의 통합 커맨드**(`alimtalk:scan-daily`)로 아침 것들을 묶어도 되고 개별로 나눠도 됨. 요약(일일 09:00)·주간(금18)·월결산(10일)은 시각 달라 개별.

## 3. 이벤트 훅 2개 (모델 afterCommit, fire-and-forget)
- **erp_vehicle_new** — `Vehicle::created` afterCommit → 관리에게. vars: `차량번호`,`바이어`,`매입가`.
  - ⚠️ 결정필요: **모든 신규 차량**이면 수동 등록도 매번 발송(스팸). jin 맥락은 "board 자동등록" → **board 경유 생성만** 트리거하는 게 나을 수 있음(source 플래그로 분기). 다음 세션 확인.
- **erp_settle_pending** — `Settlement::created`(status=pending) afterCommit → 관리에게. vars: `건수`(현재 pending 수). (건별보다 "확정 대기 N건" 요약이 나음 — created 훅에서 pending count.)

> ⚠️ 회계 민감 훅(`Settlement`·`Vehicle`)에 붙이므로 **반드시 `DB::afterCommit` + try/catch(서비스가 이미 fire-and-forget)**. 알림톡 실패가 저장을 절대 못 깨게.

## 4. 스케줄 (`routes/console.php`)
기존: db:backup 03:00 · **vehicles:rebuild-caches 05:00** · alarms:scan 06:00. 추가:
```
Schedule::command('alimtalk:pickup')->dailyAt('08:00');        // 등 아침 배치
Schedule::command('alimtalk:purchase-unpaid')->dailyAt('08:00');
Schedule::command('alimtalk:sale-unpaid')->dailyAt('08:10');
Schedule::command('alimtalk:eta-balance')->dailyAt('08:20');
Schedule::command('alimtalk:shipping-due')->dailyAt('08:20');
Schedule::command('alimtalk:daily-summary')->dailyAt('09:00');
Schedule::command('alimtalk:weekly-summary')->weeklyOn(5, '18:00');
Schedule::command('alimtalk:monthly-closing')->monthlyOn(10, '09:00');
```
(캐시가 05:00 재계산되므로 08:00+ 스캔이 최신 grace/미수 캐시를 봄.)

## 5. 테스트 (Http::fake)
각 커맨드: 조건 맞는 차량/집계 세팅 → 커맨드 실행 → `Http::assertSent`(tmplId·phn·vars) 또는 alimtalk_logs 검증. 게이트 off 시 skip 검증. 수신자 resolver 단위 테스트(역할별 phone).

## 6. 배포
전부 게이트 잠긴 채(inert) 2차 master 배포 → jin BizM 승인 + 기능설정 프로필키 입력 + `alimtalk_enabled` ON = **자동발송 turnkey 완성**.

## 미해결 결정 (다음 세션 초 확인)
1. **erp_vehicle_new** = 모든 신규 vs board 경유만? (스팸 방지)
2. **일일요약 선적전 미수** = grace 제외(현 스펙) 확정? 아니면 grace 포함?
3. cron 통합 커맨드 vs 개별? (운영 로그 가독성)
4. 수신자 override Setting 키 스키마 확정.
