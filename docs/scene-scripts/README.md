# 씬 캡처 스크립트 (dev 전용 · 운영 배포 안 함)

업무 가이드 씬(`resources/guides/*`)을 찍을 때 쓴 Playwright·PHP 스크립트 원본. 나중에 **주 1회 씬 점검**(설계 `docs/design/scene-walkthrough.md §7`)으로 바꿀 재료다.

- 실행 전제: 로컬 8001(jin 이 띄움) · `playwright-core` + 설치된 Chrome · 로컬 계정 `*@car-erp.test` / `password`(로컬 시드 전용).
- `*.php` 는 로컬 전용 준비 스크립트(`APP_ENV=local` 가드) — 예시 차량 만들기·잠금 해제·현금 원장 토글.
- 경로가 스크래치패드 기준으로 적혀 있다(`__dirname`). 점검으로 바꿀 때 이 폴더 기준으로 정리한다.
- 🚫 알림톡 발송 버튼 · NICE [조회]/[원부조회] 는 누르지 않는다(로컬 BizM 은 실발송).
