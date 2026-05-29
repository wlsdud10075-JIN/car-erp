# MD Archive — 2026-05-29 트림

자동 로드 .md 파일 토큰을 51k → ~24k 로 줄이기 위해 **완료된 큐 표·grandfather 코드·폐기된 패턴**을 이 폴더로 이동.

> ⚠️ 이 폴더의 파일은 더 이상 자동 로드되지 않음. 옛 결정 맥락이 필요할 때만 grep / 직접 Read.

## 파일

| 파일 | 출처 | 보존 이유 |
|---|---|---|
| `CLAUDE.md.full` | 프로젝트 루트 `CLAUDE.md` (2026-05-29 시점 원본) | 완료된 큐(2~20-D) 표·다음 추천 순서·단계 9 채권관리 설계·1차 배포 day-by-day 플랜 — 운영 배포 완료 후 의미 적음 |
| `SKILLS.md.full` | 프로젝트 루트 `SKILLS.md` (2026-05-29 시점 원본) | v1/v2/v3 grandfather progress_status 코드·dompdf 한글 PDF 버그 #16~#18(서류 시스템 xlsx 자동기입 전환으로 폐기)·NICE/포워딩 이메일 상세 구현 코드 |
| `role기획보안_수정.md` | 프로젝트 루트 (2026-05-11 작성) | 대시보드 3종 명칭·role별 책임 구간·1~8단계 구현 우선순위 — 모두 코드에 반영 완료. 핵심 결정은 트림된 `CLAUDE.md` "대시보드 명칭" 섹션에 보존됨 |
| `decision_protocol.md` | 프로젝트 루트 | 라운드테이블 회의 프로토콜. 자동 로드 안 됐던 운영 가이드. 루트 정리용으로 이동 |
| `최종결과보고.md` | 프로젝트 루트 | 보고용 문서. 루트 정리용으로 이동 |

## 트림 후 자동 로드 구성 (2026-05-29 기준)

```
CLAUDE.md         ~8k  토큰 (env / permission / 도메인 / 정산 공식 / 13 주의사항 / 대시보드 명칭)
CLAUDE_1.md       ~1k  토큰 (LLM 코딩 가이드라인 — 변경 없음)
SKILLS.md         ~13k 토큰 (§1~§14, grandfather·폐기 패턴 제거)
MEMORY.md         ~2k  토큰 (변경 없음)
─────────────────────────────────────
합계              ~24k 토큰  (이전 ~51k 에서 53% 절감)
```

## 옛 결정 맥락 검색하는 법

```bash
# 예: "큐 19-F" 가 어디서 결정됐는지
grep -r "19-F" docs/archive/md-2026-05-29/

# 예: dompdf 한글 폰트 버그 회피 패턴
grep -A 5 "format('truetype')" docs/archive/md-2026-05-29/SKILLS.md.full

# 예: 단계 9 채권관리 설계 결정
grep -B 2 -A 30 "단계 9" docs/archive/md-2026-05-29/CLAUDE.md.full
```

## 복원하는 법

만약 트림이 과했다고 판단되면 archive에서 원본을 꺼내 덮어쓰면 됨:

```bash
cp docs/archive/md-2026-05-29/CLAUDE.md.full CLAUDE.md
cp docs/archive/md-2026-05-29/SKILLS.md.full SKILLS.md
mv docs/archive/md-2026-05-29/role기획보안_수정.md ../../../
# CLAUDE.md 상단 import 줄에 @role기획보안_수정.md 다시 추가
```
