# 2026-06-24 작업 세션 요약

> 다음 세션 시작점. 상세는 각 메모리(`~/.claude/.../memory/`) 참조. 운영=`heysellcar.com`.

## ✅ 운영 배포 완료 (master, 순서대로)

| # | 내용 | 트리거/메모리 |
|---|---|---|
| 1 | **정산 월별 드롭다운** — `/erp/settlements` 월(월급 귀속월) 선택. 기준=`created_at`(일한月). 인원별 카드+목록 동일 스코프 | [[project_settlement_payroll_batch]] |
| 2 | **`vehicles:keep-sample-for-test`** — 표본만 남기고 차량도메인 wipe(가드 우회 raw). 복구 런북 | `docs/operations/settlement-test-data-reset.md` |
| 3 | **운영 차량도메인 완전 비움(0/0)** — 실무자 풀테스트 대기. 백업+복구검증 완료(`car_erp-20260624_110442.sql`, S3) | [[feedback_clear_means_empty]] |
| 4 | **`settlements:backfill-missing`** — 거래완료인데 정산 누락(import/CLI 무인증 갭) pending 백필 | — |
| 5 | **정산 `created_at` 백데이트** — CK배치 일한月(4월55/5월46). recreate/backdate 커맨드 | [[project_settlement_payroll_batch]] |
| 6 | **인보이스 통화 + DEPOSIT 더블카운트(11400→5700) 제거** | [[project_document_mapping]] |
| 7 | **전 수출서류 통화 $→통화기호**(컨테이너/RORO 인보이스·계약서·통관SET). `DocumentFiller::applyCurrency` | — |
| 8 | **Final Destination=입력 목적항(영문)** — RORO·컨테이너·통관(`DocValue::dischargeDestination`). 말소계약서 제외 | — |
| 9 | **RORO 인보이스 G12="RORO"** 운송방식 라벨(컨테이너 "CONTAINER" 대응) | — |
| 10 | **NICE 타임아웃 35→55초 + 에러 친절번역**(`humanizeError`). 미들웨어도 30→45(별서버, 백업 `vehicle_api.py.bak_20260624`) | [[reference_nice_middleware]] |
| 11 | **NICE 조회 blur 자동 제거 → 버튼 전용**(의도 안 한 건당 과금 방지) | [[reference_nice_middleware]] |
| 12 | **NICE 코드 5000 = 대상기관 장애** 친절번역(jin 실측). 원천기관(국토부/NIRS) 다운, 우리·NICE 문제 아님 — 실조회로 확정 | [[reference_nice_middleware]] |
| 13 | **alarm-center 토스트** — 폴링30s, 새 알람(board 도착) 시 떴다사라지는 토스트. 배지/벨은 유지 | [[project_cross_system_toast]] |

## 🔵 dev 미배포 — **복구 때 묶어서 배포** (트리거 "복구 진행")
- **정산 `paid_at`(지급일) + `/settlements` API + 계약문서 + backdate paid_at** (`e07d600`) — board가 받은月(5/6월)로 묶도록
- 복구 런북 backdate 단계 (`cfaa24f`)
- 실무자 테스트 끝 → ① master 배포 ② 백업 복구 ③ `settlements:backdate-from-ck --apply` (런북 6단계) [[project_board_settlement_paid_at]]

## ⏸️ 보류 / 다음
- **super 계정**: `wlsdud10074@naver.com`/`WLSDUD102!!`(진 전용) — 기억만, **운영 미적용 보류**. admin 별도. [[reference_super_account]]
- **토스트 A안**(board→car-erp 보낼때): board 세션 작업, 인계문서 `board/meetings/handoff-toast-notify.md` 전달됨
- **토스트 B안**(car-erp→board push): **안 만듦** — car-erp가 능동 push할 게 사실상 없음(board는 PULL로 충분) [[project_cross_system_toast]]
- **서류 매핑 기준표 대조**: 서류작업 끝나면 `Desktop\system\0.매핑한것` 과 코드 대조 [[project_doc_mapping_reconcile]]
- **NICE 대상기관 장애**: 복구 대기(우리 무관). 수기입력 진행. car365 공지/NICE 1588-5659(계정 ssanCar)

## 잔여 큰 작업 (이전부터)
[[project_remaining_tasks]] — 엑셀 Export/Import v1, 통관SET 다중, 연동C(queue worker), 지급자동화, board 화면배선.
