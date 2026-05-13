# 라운드테이블 회의록 인덱스

> car-erp 의사결정 회의 결과 1줄 로그. 회의 발생 시 아래 형식으로 추가.
>
> 형식: `- YYYY-MM-DD [판정] 안건 — 핵심 이유 (회의록 파일명)`
> 예: `- 2026-05-12 [HOLD] 정산 VAT 공식 변경 — QA NO-GO, 엑셀 실측 검증 부재 ([2026-05-12-vat-formula.md](2026-05-12-vat-formula.md))`

## 회의 로그

- 2026-05-12 [DONE] 문서 다운로드 권한 + RRN 암호화 — 1·2단계 PR 모두 머지 완료. 정책 D(모든 user + 감사 로그) / encrypted cast(accessor·mutator + encrypted_at 표식) / Unit Test 10/10 통과. 사후 Python ERP 미실재 확인 → CLAUDE/SKILLS 운영 가정 제거 + 부서 프롬프트 v1.1 사전 검증 의무 추가 ([2026-05-12-rrn-encryption-document-permission.md](2026-05-12-rrn-encryption-document-permission.md))
- 2026-05-12 [DONE] 큐 1번 일반사용자 대시보드 role 분기 — MUST 10 + SHOULD 5 + advisor 발견 effectiveSalesmanId 누수 버그 수정 모두 반영. Vehicle::scopeAction(14액션 + 채널 격리) / 토글 pill + localStorage / 정산 user 김진영 시드 / DashboardActionCountsTest 18 통과 / 전체 54 통과. 커밋 8bd7c9e ([2026-05-12-user-dashboard-role-branching.md](2026-05-12-user-dashboard-role-branching.md))
- 2026-05-12 [분석완료] 워크플로우 누락 시나리오 종합 — 6부서 풀회의. Critical 8건(환율 미입력 / 매입잔금 payment_date / 채널 모순 / 단계 건너뛰기 2종 / vehicle_number+soft-delete 충돌 / 컬럼 권한·본인 격리 0 / APP_KEY 재생성 위험) + High 15 + Medium/Low 30+. 큐 2.5(Critical 1차 패치) / 큐 7 확장 / 정산·채권 무결성 / 운영 안전 가드 4개 새 큐 제안. 사용자 결정 대기 ([2026-05-12-workflow-gap-analysis.md](2026-05-12-workflow-gap-analysis.md))
- 2026-05-12 [DONE] 큐 2.5번 Critical 8건 1차 패치 — C1·C2 validation (환율·payment_date) / C3 progress_status 채널 분기 + 채널 변경 confirm / C4·C5 saving validator (말소·미입금 단계 건너뛰기 차단) / C6 vehicle_number unique + soft-delete fix / C7-b 영업 본인 차량 격리 (C7-a 컬럼 권한은 큐 7 확장으로 분리) / C8 APP_KEY 영구 손실 가드 문서 (`docs/operations/key-rotation.md` 신설). WorkflowGapTest 15 신규 / 전체 83/83 통과. 커밋 c38c0a6.
- 2026-05-13 [조건부 GO] 11단계 무결성 정책 재수립 + admin 미입금 우회 통합 — 풀회의 6역할 + Codex/Gemini 크로스체크. 누수 4건(거래완료·선적완료·선적중·수출통관완료) 이중 트리거화 / `unpaid_export_overrides` append-only per-stage / `progress_status_rule_version` + `is_override_active` Flag / 3-tier 이관 (paid·dhl=grandfather, 미마감=수동, 매입=자동) / dry-run 명령 / UI 차단+Helper Text. `stage_transition_logs`는 큐 10 H4 통합 시 재검토. 큐 2.6 신설로 별도 PR 진행 ([2026-05-13-progress-status-integrity.md](2026-05-13-progress-status-integrity.md))

---

## 사용 안내

- 회의 진행 절차: 프로젝트 루트 `decision_protocol.md` 참조
- 부서별 프롬프트: `docs/meetings/departments/{po,engineer,qa,security,ops,specialist}.md`
- 회의록 저장 경로: `docs/meetings/YYYY-MM-DD-{slug}.md`
- `.md` 파일은 dev → master/demo 머지 시 제외 (CLAUDE.md 규칙) — 회의록도 dev 전용
