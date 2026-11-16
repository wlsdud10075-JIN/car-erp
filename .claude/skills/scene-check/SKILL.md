---
name: scene-check
description: car-erp 주간 「씬 점검(화면 회귀)」. 업무 가이드 씬을 로컬 8001 에서 Playwright 로 끝까지 눌러 가이드가 인용한 화면 문구·DB 값이 맞는지 본다. 토요일 10:00 자동 실행이 실패했을 때("씬 점검 실패 봐줘"), 손으로 돌릴 때("씬 점검 돌려줘", "/scene-check"), 특정 씬만("documents 씬만") 사용. car-erp 전용 · 🚫 배포 게이트 아님.
disable-model-invocation: true
---

# 씬 점검 (주 1회 — jin 2026-11-14 결정 · 11-16 토요일 자동)

정본 = `docs/design/scene-walkthrough.md §7-1·§8` · 사용법 = `scripts/scene/check/README-check.md`.
토요일 10:00 jin PC 작업 스케줄러 「car-erp weekend routines」(`scripts/weekly/weekend.ps1`)가 러너를 돌리고 관제 「우리 루틴」에 기록, 실패면 텔레그램.

## 🚫 지킬 것
- 로컬 8001 은 jin 이 띄운다 — 🚫 `php artisan serve` 를 띄우지 않는다. 안 떠 있으면 jin 에게 말한다.
- 러너가 알림톡 마스터 스위치를 먼저 본다(켜져 있으면 멈춘다 — 로컬 BizM 은 실발송 계정). 우회하지 말 것.
- 클릭 가드(NICE 조회·원부조회 · 알림톡 발송 · [이 PC 연결하기])와 127.0.0.1:47321 차단을 끄지 않는다.
- 로컬 DB 만 바꾼다. 되돌리기 = `GuideSceneFixtureSeeder`(차량번호·이름으로 찾음). 🚫 `migrate:fresh`·`db:wipe`.
- 운영 서버 접속 없음. 결과 화면 텍스트를 통째로 대화에 붙이지 않는다(로컬 DB 에 실명이 섞여 있다).

## 1. 돌리기
```bash
cd /c/xampp/htdocs/car-erp && node scripts/scene/check/run.js               # 전부(시더 → 씬 순서대로, 약 3분)
node scripts/scene/check/run.js --only=documents                            # 한 씬
```
결과 표 + `storage/logs/scene-check/result-*.json`. 토요일 자동 실행 결과 = `storage/logs/weekly/scene-YYYYMMDD.txt`.

## 2. 실패를 가른다 (§7-1 표)
| 분류 | 판단 | 할 일 |
|---|---|---|
| **버그** | 화면 동작·저장 값이 가이드·의도와 다르다 | 코드 수정(가이드 그대로) → 다시 돌림 |
| **의도한 변경** | 최근 커밋이 화면 문구·동작을 바꿨다(`git log` 로 확인) | 점검 스크립트 기대값 → 재실행 → 그 결과로 가이드 씬도 고침 |
| **스크립트 문제** | 선택자·대기·예시 데이터(시더) 문제 | 스크립트·시더 수정 |
jin 에게 「씬 · 단계 · 무엇이 없음 · 분류 · 고칠 곳」으로 보고하고, 코드·가이드를 고치는 건 jin 확인 뒤.

## 3. 관제 기록
러너를 손으로 돌렸으면 끝에:
```bash
php artisan ops:mark scene-check --push=https://heymancar.com --note="통과 5 / 실패 0 / 전체 5"      # 실패가 있으면 --status=failed
```
토요일 자동 실행은 실행기가 남긴다. 실패를 고친 뒤 다시 돌려 통과했으면 위 명령으로 ok 를 남긴다(관제가 초록으로 바뀐다).

## 4. 씬 늘리기 (§8 2~4단계)
1단계 = bank-balance · documents · bulk-shipping-dates · shipment-fee · buyer-account.
다음 = 한 바퀴(vehicle-register·purchase-payment·sale-register·sale-payment·export-tab·monthly-payout) → 나머지 ERP 단독 → board 연동·카톡.
재료 = `scripts/scene/cap/`(촬영 스크립트). 새 씬은 `scripts/scene/check/{slug}.js` 에 `run(ctx)` 를 두고 `run.js` 의 `SCENES` 에 넣는다. 되돌릴 데이터는 시더에.
🚫 `scripts/scene/` 은 master 로 안 간다(cherry-pick 제외 — `.md`·notion 필터에 안 걸린다).
