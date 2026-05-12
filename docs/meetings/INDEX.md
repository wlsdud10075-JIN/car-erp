# 라운드테이블 회의록 인덱스

> car-erp 의사결정 회의 결과 1줄 로그. 회의 발생 시 아래 형식으로 추가.
>
> 형식: `- YYYY-MM-DD [판정] 안건 — 핵심 이유 (회의록 파일명)`
> 예: `- 2026-05-12 [HOLD] 정산 VAT 공식 변경 — QA NO-GO, 엑셀 실측 검증 부재 ([2026-05-12-vat-formula.md](2026-05-12-vat-formula.md))`

## 회의 로그

- 2026-05-12 [DONE] 문서 다운로드 권한 + RRN 암호화 — 1·2단계 PR 모두 머지 완료. 정책 D(모든 user + 감사 로그) / encrypted cast(accessor·mutator + encrypted_at 표식) / Unit Test 10/10 통과. 사후 Python ERP 미실재 확인 → CLAUDE/SKILLS 운영 가정 제거 + 부서 프롬프트 v1.1 사전 검증 의무 추가 ([2026-05-12-rrn-encryption-document-permission.md](2026-05-12-rrn-encryption-document-permission.md))
- 2026-05-12 [DONE] 큐 1번 일반사용자 대시보드 role 분기 — MUST 10 + SHOULD 5 + advisor 발견 effectiveSalesmanId 누수 버그 수정 모두 반영. Vehicle::scopeAction(14액션 + 채널 격리) / 토글 pill + localStorage / 정산 user 김진영 시드 / DashboardActionCountsTest 18 통과 / 전체 54 통과. 커밋 8bd7c9e ([2026-05-12-user-dashboard-role-branching.md](2026-05-12-user-dashboard-role-branching.md))

---

## 사용 안내

- 회의 진행 절차: 프로젝트 루트 `decision_protocol.md` 참조
- 부서별 프롬프트: `docs/meetings/departments/{po,engineer,qa,security,ops,specialist}.md`
- 회의록 저장 경로: `docs/meetings/YYYY-MM-DD-{slug}.md`
- `.md` 파일은 dev → master/demo 머지 시 제외 (CLAUDE.md 규칙) — 회의록도 dev 전용
