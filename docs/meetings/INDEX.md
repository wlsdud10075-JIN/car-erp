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
- 2026-05-13 [조건부 GO] 관리자 대시보드 통관·정산·채권 대표급 KPI 도출 — 풀회의 6역할. 정산 인원별 월별 지급액(A)·채권 담당자/바이어 TOP10 미수금 한 화면(C)·통관 정체 차량+미업로드+포워딩사 TOP5(D)·월별 차량 대수 영업/전체 탭 한정(B). 평균 통관 처리 일수는 `export_cleared_at` 컬럼 부재로 보류 (QA NO-GO → 컬럼 마이그레이션 별건). 큐 4 8-5·8-6·8-7·8-8 4커밋 분할 ([2026-05-13-admin-dashboard-kpi.md](2026-05-13-admin-dashboard-kpi.md))
- 2026-05-14 [GO + v5 완료] 워크플로우 정책 재설계 (3자 회의 12안건) — 풀회의 7그룹 × claude/codex/advisor + 사용자 답변 v3·v5. **v5 확정**: ① 분모 정의 = `sale_total_amount` + 적립금 제외 분자 (이미 코드 정합 — claude/codex/advisor 셋 다 sale_price 단독 가능성 잘못 제기) ② 안건 4 (50%의 50%) = 옵션 i 이체 방식 — inter_vehicle_transfers 모델 + 음수 final_payment 짝 + 관리 승인 ③ role '전체' 삭제 + role '관리'=서브관리자 4액션(G2/정산 확정·지급/민감 액션/50% 룰 예외) ④ G6 enum+5컬럼 drop+시드 재생성 ⑤ G5 차대번호 NICE 후 ⑥ G7 close confirm. 회계담당자 인터뷰 불필요. 큐 14·15·16·18·19 + 큐 9·10·11 확장. 1인 5~6주 추정 ([2026-05-14-3way-workflow-policy.md](2026-05-14-3way-workflow-policy.md))
- 2026-05-16 [조건부 GO] 큐 19-F 자금 이체 재무 확정 게이트 (관리≠재무 분리) — 풀회의 6부서 + Codex/Gemini 사외이사. 6/6 모두 조건부 GO. 합의 7건(settlement 재사용·finance_note 선택·H4 양쪽 재검증·void 동일·TTL 별건·다운타임 0초·`/erp/transfers` 이중 가드). 사용자 결정 4건: ① UI=`/erp/transfers` 신규 ② 재무 거부 불가(void 재사용) ③ status=`approved_awaiting_finance`(string(20)→string(30) ALTER) ④ self-confirm 동일 user_id 차단. Gemini 신규 지적 `DB::transaction()` 원자성 의무 수용. 4단계 구현 큐 19-F-A/B/C/D 분할 (총 9.5~10h). 코드 검증: Security가 `canAccessSettlement()`에 관리 role 포함 SoD 결손 정확히 식별, Engineer가 status 컬럼 length ALTER 필요성 정확히 지적 ([2026-05-16-finance-gate-roundtable.md](2026-05-16-finance-gate-roundtable.md))
- 2026-05-17 [HOLD] 큐 20 SSANCAR 매입·판매 전 흐름 재무 확정 게이트 + 매입처 계좌 정보 — 풀회의 6부서 + Codex/Gemini. QA HOLD (분자 정의 미결정). 합의 10건(SoD 패턴 재사용·옵션 ii 단순 컬럼·별도 PaymentConfirmationService·B1 vehicles 3컬럼·계좌 암호화·backfill α·paid Settlement 가드·self-confirm 차단·다운타임 0초·19-F-D 먼저). **사용자 4 결정 보류** (집에서 재검토 예정): ① 분자 정의 A안(confirmed 필터, Gemini+Specialist[F] 권장) vs B안(분자 불변, Codex+QA 권장) ② 우선순위 19-F-D vs 큐 20 ③ 적용 매입 먼저 vs 전체 통합 ④ 별도 Service vs saving 훅. 두 패키지 P1(보수)/P2(정석)으로 정리. Codex/Gemini 정반대 패키지 권장 ([2026-05-17-purchase-sale-finance-gate.md](2026-05-17-purchase-sale-finance-gate.md))
- 2026-05-18 [GO + 사용자 정정] 차량 본체 ledger 영향 필드 잠금 범위·해제 정책 — 풀회의 6부서 + Codex/Gemini 만장일치 조건부 GO 후 사용자 3건 정정. 옵션 A 트리거(confirmed FinalPayment OR PurchaseBalancePayment 1건) 6/6 합의. **사용자 최종 결정 (2026-05-18)**: ① Tier 1·2 단일 정책 통합 — admin+super 둘 다 풀 권한(회의 권고 super-only에서 격상) ② 사유 10자 이상(회의 권고 255자에서 완화) ③ 저장 1회 완료 즉시 자동 재잠금(회의 권고 60초 TTL에서 동작 기반 race-window-0으로 강화). 잠금 대상: FINANCIAL_FIELD_MAP 11컬럼 + buyer_id + salesman_id 동일 정책. 모델 레이어 가드 격상(Vehicle::saving) + VehicleLedgerUnlockService 신규(cache token 1회 소비 패턴) + 부수 fix 4건(decide self-approve · execute TYPE_SENSITIVE_ACTION · confirmed 차량 soft-delete 가드 · AUDITED_COLUMNS 확장). Security 2-actor 워크플로우는 별건 보류(6대1). 큐 21 신설, 공수 7.5~8.5h, 다운타임 0초 ([2026-05-18-vehicle-ledger-field-lock.md](2026-05-18-vehicle-ledger-field-lock.md))

---

## 사용 안내

- 회의 진행 절차: 프로젝트 루트 `decision_protocol.md` 참조
- 부서별 프롬프트: `docs/meetings/departments/{po,engineer,qa,security,ops,specialist}.md`
- 회의록 저장 경로: `docs/meetings/YYYY-MM-DD-{slug}.md`
- `.md` 파일은 dev → master/demo 머지 시 제외 (CLAUDE.md 규칙) — 회의록도 dev 전용
