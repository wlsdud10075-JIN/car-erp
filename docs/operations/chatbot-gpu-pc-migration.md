# 사내 챗봇 GPU 호스트 이관 런북 (내 노트북 → 회사 GPU PC)

> 대상: 로컬 LLM 챗봇(A=Notion 가이드 RAG / B=DB 조회)의 **Ollama 호스트**를 회사 데스크톱(RTX 2070 8GB)으로 옮긴다.
> 작성 2026-07-27. 관련 = 메모리 `project_local_llm_chatbot`, `docs/operations/carerp-infra-2026-07-26.md`.

## ⏱️ 크리티컬 패스 (여기서 막히면 며칠 샌다)

**1-4의 Tailscale 계정 로그인**과 **key expiry 비활성화** 두 가지. 둘 다 사람 손이 필요하고
브라우저 인증이라 대신 못 한다. 나머지는 전부 자동화되거나 되돌릴 수 있으니, 이 둘을
"5번 항목"이 아니라 **가장 먼저 처리할 것**으로 취급한다.

## 핵심 원칙 — 역할 2개를 분리해서 순서대로

| 역할 | 내용 | GPU 필요 | 컷오버 방법 |
|---|---|---|---|
| **(a) 추론 호스트** | Ollama + qwen3:8b + bge-m3 + Tailscale | ✅ | 서버 `.env ASSISTANT_OLLAMA_URL` 1줄 (되돌리기 쉬움) |
| **(b) 색인기** | llm-poc(Notion 재색인) + PHP CLI + car_erp_key + 작업스케줄러 | ❌ | 스케줄러 등록/해제 |

**(a) 먼저 옮기고 검증한 뒤 (b)를 옮긴다.** 한꺼번에 하면 컷오버 실패 시 원인이 섞인다.
**B(미수·채권·자금 조회)는 이관 내내 무영향** — DB만 쓰고 LLM을 안 거친다. 영향받는 건 A뿐.

---

## 0. 이관 전 베이스라인 (현재 노트북에서 측정 — 2026-07-27 실측)

```
ollama ps
  bge-m3:latest   664 MB   100% GPU   CONTEXT 4096   Forever
  (qwen3:8b 미상주 — keep_alive=-1 인데도 언로드된 상태)

nvidia-smi
  RTX 2070 with Max-Q Design / 8192 MiB total / 1651 MiB used
```

⚠️ **회사 PC 세팅 후 같은 2개 명령을 실행해 비교한다. 합격 기준 = A 질문 1회 던진 뒤 `ollama ps`에 qwen3:8b·bge-m3 둘 다 `100% GPU` + `Forever`.**
`PROCESSOR` 에 `x% CPU` 가 섞이면 VRAM 부족 신호.

**VRAM이 부족할 때 하면 안 되는 것**: bge-m3 의 `keep_alive=-1` 을 푸는 것.
(임베딩 콜드로딩이 A 행(hang)의 원인이었음 — 메모리에 박제된 기존 사고.)
대신 이 순서로: ① 회사 PC 모니터를 내장그래픽에 물려 2070 VRAM 비우기 → ② qwen3 컨텍스트 축소 → ③ 더 작은 양자화(q4 → q3) 순.

---

## 1단계 — (a) 회사 PC 를 추론 호스트로 세팅 [jin 직접, 회사 PC 앞에서]

### 1-1. Ollama 설치 + 모델
```powershell
winget install Ollama.Ollama
ollama pull qwen3:8b
ollama pull bge-m3
```

### 1-2. User 환경변수 3개 (시스템 속성 → 환경 변수 → 사용자 변수)
| 이름 | 값 | 용도 |
|---|---|---|
| `OLLAMA_HOST` | `0.0.0.0` | Tailscale 로 외부(서버) 수신 허용 |
| `OLLAMA_KEEP_ALIVE` | `-1` | 모델 상주(콜드로딩 방지) |
| `NOTION_TOKEN` | (노트북 값 그대로) | 4단계 색인기용. **파일로 옮기지 말고 직접 입력** |

설정 후 **Ollama 트레이 앱 완전 종료 → 재시작** (환경변수는 시작 시점에만 상속).

### 1-3. 방화벽
인바운드 규칙: TCP **11434** 허용.

### 1-4. Tailscale
설치 → **같은 계정 `wlsdud10070@` 로 로그인** → `tailscale ip -4` 로 새 IP 확인 (예: `100.x.x.x`).
- 호스트명을 알아보기 쉽게: `tailscale up --hostname=gpu-office`
- ⚠️ **Tailscale 관리 콘솔에서 이 노드의 key expiry 를 비활성화**(Disable key expiry).
  기본 ~180일 만료라 그냥 두면 **몇 달 뒤 A가 조용히 죽는다.** 이제 인프라 취급.

### 1-5. 상주 워밍업 + **외부 바인딩 확인**
```powershell
ollama run qwen3:8b "안녕" ; ollama ps        # → §0 합격 기준과 비교
curl http://<이 PC의 TS IP>:11434/api/tags    # ⚠️ localhost 아님, 자기 Tailscale IP 로
```
**두 번째 줄이 이 이관의 핵심 검증이다.** `localhost` 로만 응답하고 TS IP 로는 안 되면
`OLLAMA_HOST=0.0.0.0` 이 안 먹은 것(= 트레이 앱 완전 재시작 누락). 여기서 잡지 않으면
2단계에서 서버가 못 붙는 걸로 나타나 원인이 헷갈린다.

---

## 2단계 — 컷오버 전 검증 [Claude 또는 jin]

**구 호스트의 Ollama 는 아직 켜둔 채로** 진행한다(롤백 경로 보존).
⚠️ 이 런북을 **다음 호스트 이전 때 다시 쓸 경우** — 2026-07-29 부로 노트북엔 Ollama 가 없다.
그때의 "구 호스트"는 회사 GPU PC(`100.110.133.112`)다.

서버 3사에서 새 PC 도달 확인 (⚠️ 운영 SSH 연속 접속은 차단됨 — **한 대씩 간격 두고**):
```bash
curl -s -m 10 http://<새PC_TS_IP>:11434/api/tags | head -c 200
```
→ 3사 전부 200 + 모델 목록이 나와야 다음 단계. 하나라도 실패하면 컷오버 중단.

---

## 3단계 — 컷오버: 서버 3사 .env 전환 [🚨 jin 명시 승인 필요]

운영 3사(heymanerp·ssancarerp·karabaerp) 실서버 변경이다. **jin 승인 후에만.**

각 서버에서:
```bash
sudo nano /var/www/car-erp/.env      # ASSISTANT_OLLAMA_URL=http://<새PC_TS_IP>:11434
cd /var/www/car-erp && php artisan config:cache
```
- **`config:cache` 를 빼먹으면 값이 안 먹는다**(캐시된 옛 IP 유지).
- 서버 간 간격을 둔다(SSH rate limit).
- **롤백** = 옛 IP(`100.75.178.19`)로 되돌리고 `config:cache`. 노트북이 켜져 있는 한 즉시 복구.
  - ⚠️ **2026-07-29 부로 이 롤백은 더 이상 안 된다** — 노트북 Ollama·모델을 제거했다(5단계 완료).
    지금 회사 PC가 죽으면 A(가이드)는 3사 전부 degrade 되고, 복구하려면 어느 PC든 Ollama·모델을
    다시 설치해 그 Tailscale IP를 넣어야 한다. **B(DB 조회)는 영향 없음.**

### 검증 (브라우저, jin)
회사별 1건씩: **A 질문**("정산은 누가 확정해?" 류) → 답변 + 출처 표기 확인 / **B 질문**("이번달 미수금") → 숫자 확인.
> 동시에 여러 회사에서 A를 던지지 말 것 — GPU 1장을 3사가 공유해서 큐잉된다.

---

## 4단계 — (b) 색인기 이관 [(a) 검증 완료 후]

### 4-1. 준비물 확인
- **PHP CLI 필요** (`sync.php` 실행용). 회사 PC에 XAMPP 없으면 → XAMPP 설치 or PHP 단독 설치.
- **SSH 키** `car_erp_key` 를 회사 PC `%USERPROFILE%\.ssh\` 로 복사 (3사 서버 scp 용).

### 4-2. llm-poc 폴더 통째 복사
`C:\Users\User\llm-poc\` → 회사 PC. **git 미추적이라 이 복사가 유일한 이관 경로.**
포함: `config.php` · `sync.php` · `rag.php` · `index.php` · `index-erp.json` · `index-board.json` · `index.json` · `sync-and-push.ps1` · `run.bat`

### 4-3. ⚠️ `sync-and-push.ps1` 경로
현재 `$php` / `$dir` / `$key` 가 `C:\Users\User\...` 로 **하드코딩**되어 있다.
회사 PC의 Windows 사용자명이 다르면 그대로 깨진다. 이관 전에 자기경로 파생으로 바꿔두는 것을 권장:
```powershell
$dir = $PSScriptRoot
$key = Join-Path $env:USERPROFILE '.ssh\car_erp_key'
$php = 'C:\xampp\php\php.exe'   # PHP 설치 위치에 맞게
```

### 4-4. 작업 스케줄러 등록
- 작업명: `SSANCAR LLM Notion Sync`
- 트리거 2개: **매일 03:00** + **로그온 시(2분 지연)**
- 동작: `powershell.exe -ExecutionPolicy Bypass -File <llm-poc>\sync-and-push.ps1`
- **실행 계정 = NOTION_TOKEN 을 넣은 그 사용자**(User 스코프 환경변수를 읽어야 함)

### 4-5. 🚨 이중 푸시 금지
새 스케줄러 1회 수동 실행 → `pushed -> ...` 3줄 확인 →
**그 다음에 노트북의 `SSANCAR LLM Notion Sync` 를 사용 안 함(Disable)** 으로.
둘 다 켜져 있으면 두 PC가 같은 서버 3대에 `index-erp.json` 을 각자 밀어넣는다.

---

## 5단계 — 구 호스트(노트북) 정리 ✅ 완료 (2026-07-29)

**착수 전제 = 회사 PC 색인기의 자동(수동 아님) 실행 실증.** 서버 3사 `index-erp.json` 의 mtime 이
`2026-07-28 18:01:17 / :19 / :20 UTC`(= 07-29 03:01 KST, scp 순차 흔적)로 찍혔고 크기가
`2758338` bytes 로 셋 다 일치 — 부분 푸시(한 대만 최신)가 아님까지 확인한 뒤 실행했다.
크기 일치는 청크수 검증도 겸한다(board 오배포면 다르고, 통합본이면 훨씬 크다).

실행한 것:
1. 작업 스케줄러 `SSANCAR LLM Notion Sync` **삭제**(Disable 아님 — 대상 경로를 지우므로 함께 정리)
2. Ollama 제거 + `~/.ollama` 삭제 (**5.94GB** 회수). ⚠️ winget 이 관리자 권한에서 user-scope 패키지를
   못 지운다(`exit 125`) → `%LOCALAPPDATA%\Programs\Ollama\unins000.exe /VERYSILENT` 로 우회.
3. User 환경변수 `OLLAMA_HOST` / `OLLAMA_KEEP_ALIVE` 제거
4. `C:\Users\User\llm-poc\` 삭제 — **바탕화면 `llm-poc.zip`(소스 전량 포함) 확인 후에만.** git 미추적이라
   이 zip 과 회사 PC 사본이 유일본이다.

**남긴 것**: `NOTION_TOKEN` 환경변수(car-erp `scripts/notion-*.php` 가 같은 토큰을 쓴다 — 지우면 무관한
Notion 발행 작업이 깨진다) · Tailscale 노드 `laptop-8egn35ec` · `car_erp_key`.

**노트북 로컬 개발 `.env`** 는 회사 PC를 그대로 보도록 전환했다 —
`ASSISTANT_OLLAMA_URL=http://100.110.133.112:11434`,
`ASSISTANT_INDEX_PATH=...\car-erp\storage\app\index-erp.json`(llm-poc 사본을 옮겨둠, 07-27 스냅샷).
제거 **후** `OllamaClient::embed()` → `dim=1024` + A(RAG) 실질의 응답까지 재확인했다
(제거 전 테스트는 로컬 Ollama 가 살아있어 결정적이지 않다 — 반드시 제거 후 다시 볼 것).

---

## 이 이관의 함정 정리

- **Ollama(Windows)는 로그인 세션 트레이 앱이다.** 화면 잠금은 괜찮지만 **로그아웃·재부팅 후 로그인 안 하면 A가 죽는다.** 회사 PC를 상시 로그인 상태로 둘지, 자동 로그인을 걸지는 jin 판단 사항.
- **PC가 꺼지면 A만 degrade**("Ollama 확인" 에러), **B는 계속 작동**(DB 조회).
- **GPU 1장 = 3사 공용.** A는 동시 1쿼리. 규모가 커지면 큐잉.
- 서버측 **php-curl** 은 3사 모두 설치 완료(heyman 07-24 해결). 이관과 무관하지만 새 서버가 생기면 다시 확인할 것.
- 색인 스코프: car-erp 는 `ASSISTANT_INDEX_PATH=...\index-erp.json`(board 물리 분리). 이 값은 **서버 로컬 경로**라 이관해도 안 바뀐다.

## 이관 후 갱신할 것

메모리 `project_local_llm_chatbot.md` 의 호스트/Tailscale IP 섹션 — **이 토폴로지의 유일한 기록이다.**
