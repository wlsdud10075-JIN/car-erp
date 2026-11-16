---
name: memory-cleanup
description: car-erp 주간 「메모리·문서 정리」. 토요일 자동 측정이 만든 정리 후보 표(storage/logs/weekly/memory-*.md)를 보고 MEMORY.md 색인을 압축(끝난 일·긴 줄 → _ARCHIVE.md·project 파일로 옮기고 포인터)하고, 끝나면 관제센터에 기록한다. jin 이 "메모리 정리", "문서 정리", "/memory-cleanup", "관제에 메모리 할 일 있음" 이라고 할 때 사용. car-erp 전용.
disable-model-invocation: true
---

# 메모리·문서 정리 (주 1회 — jin 2026-11-16)

토요일 10:00 에 `scripts/weekly/memory-measure.php` 가 **측정·후보 표만** 만들고 관제 「우리 루틴」에 「할 일 있음」을 남긴다.
이 스킬은 그 표를 보고 **실제로 옮기는** 단계다. 정본 방법 = 메모리 `feedback_memory_compaction_method.md` — **먼저 읽는다.** 압축 = 「옮기고 포인터」, 손실 0.

## 🚫 안전 규칙
- 메모리 파일(`~/.claude/projects/C--xampp-htdocs-car-erp/memory/`)은 **지우지 않는다.** `project_*.md`·`feedback_*.md` 는 이동·삭제 X. 줄을 옮길 땐 `_ARCHIVE.md` 에 **먼저 쓰고 → 확인 → 색인에서 뺀다.**
- `feedback_*`·🚨·돈/사고급 함정 줄은 옮기지 않는다(색인 본문 유지).
- `SKILLS.md`·`CLAUDE.md` 는 **jin 승인 없이 고치지 않는다** — 이 스킬은 제안만.
- 메모리 파일을 레포에 복사·커밋하지 않는다. 다른 레포(board·ssancar) 문서는 건드리지 않는다. 운영 서버 접속 없음.
- **옮기기 전에 표를 보여 주고 jin 「진행」을 받는다.**

## 1. 측정 (읽기만)
```bash
cd /c/xampp/htdocs/car-erp && php scripts/weekly/memory-measure.php
```
마지막 줄 JSON 의 `report` 가 표 파일이다. 토요일 표가 있으면 그것과 비교해 새로 생긴 후보도 본다.

## 2. 후보 표를 jin 에게
표 그대로 + 처리안: `행 | 지금 내용(앞 60자) | 처리(이관/줄이기/유지) | 옮길 곳`. 끝난 일(✅) 줄에 「남은 것」이 적혀 있으면 그 부분만 색인에 남긴다.
기준 넘음(MEMORY 150줄·24,000B / SKILLS 200KB)·SKILLS §8 순서·CLAUDE.md 「현재 상태」 낡음은 **제안 목록**으로 따로.

## 3. jin 「진행」 뒤
- `_ARCHIVE.md` 맨 위(「찾는 법」 아래)에 `## 📦 MEMORY 색인 이관 YYYY-MM-DD` 절을 만들고 **원문 그대로** 붙인다. 「찾는 법」 키워드 표에 새 주제어가 있으면 한 줄.
- 색인 줄은 지우거나 「전문 = `_ARCHIVE.md` 「…」」 포인터 한 줄로.
- 긴 줄은 세부를 해당 `project_*.md` 로(없으면 `_ARCHIVE.md`), 색인엔 한 줄 + `[[링크]]`.
- 다시 측정해 전/후(줄·바이트)를 보고한다.

## 4. 레포 문서 (승인 받은 것만)
- `CLAUDE.md` 「현재 상태」 SHA 갱신 · `SKILLS.md` §8 트림(09-22 `d6fd4118` 방식 — 전문을 `docs/archive/skills-8-YYYY-MM-DD/` 로, 본문엔 색인표, 내용 한 글자도 안 고침)은 **jin 이 승인했을 때만.**
- 고쳤으면 `dev` 에만 커밋(heredoc `git commit -F - <<'EOF'`), `.md` 는 master 로 안 간다. `docs/operations/aws-deployment-record.md` 는 건드리지 않는다(개발 현황판 원본).

## 5. 관제 기록
```bash
php artisan ops:mark memory-doc-cleanup --push=https://heymancar.com --note="MEMORY 134→110줄·27.6→21KB, 이관 8줄"
```
못 끝냈으면 `--status=todo` 와 남은 것. `OPS_HMAC_SECRET` 없음 오류면 jin 에게 「관제 [했음] 버튼」을 안내한다.
