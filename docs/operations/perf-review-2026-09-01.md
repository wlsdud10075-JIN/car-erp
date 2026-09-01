# ERP 웹 성능 검토 — ssancar `PERF_SURVEY` 대조 (2026-09-01)

> jin 요청: *"ssancar.com 쪽 웹 성능 작업을 ERP에도 적용할 게 있을지 검토해줘.
> ssancarerp도 많이 적재되니까 좀 느려지는 것 같고 미리 준비를 해야 할 것 같아서."*
>
> 원본 = `C:\xampp\htdocs\ssancar\htdocs\_ops\dev\PERF_SURVEY.md` (그누보드 · Apache 기준).
> **스택이 달라 그대로 옮기지 않았다.** 항목마다 ERP 실측으로 적용/부적용을 갈랐다.
>
> ✅ **2026-09-01 적용 완료 (3사).** 1~3순위를 적용했고 운영 트래픽에서 −78% 를 확인했다 — §10.
> 4순위(폰트)는 화면이 바뀌므로 보류. 5순위(검색)는 다음 대량 적재 전으로 미뤘다.
>
> 🔧 **초판에 회사를 거꾸로 봤다** — 「ssancarerp 는 트래픽이 적다」고 썼는데 **가장 많다**. §10-1 참조.

---

## 0. 한 장 요약 — 측정한 순서대로

| | 항목 | 실측 근거 | 비용 | 판정 |
|---|---|---|---|---|
| **1** | **nginx `gzip_types`** | livewire update **54.5MB/7시간 = 전 트래픽의 86%**, 전부 비압축 | **설정 1줄** | 🔴 **즉시** |
| **2** | 정적 자산 캐시 헤더 | 해시 파일명인데 `Cache-Control` 이 **아예 없다** | 설정 3줄 | 🟠 같이 |
| **3** | HTTP/2 | 운영이 **HTTP/1.1**. nginx 1.24 는 지원한다 | 설정 1줄 | 🟡 같이 |
| **4** | `fonts.bunny.net` | 외부 오리진 2개 · 렌더블로킹 · **한글 글리프 없음** | 코드 | 🟡 판단 필요 |
| **5** | 검색 쿼리 스캔 | ssancarerp 검색 **99.7ms** (무필터는 33ms) — 행 수에 비례 | 코드 | 🔵 **미리 준비** |
| — | Brotli | 모듈 미설치. gzip 먼저 하고 판단 | 컴파일 | ⚪ 보류 |
| — | 번들링 · jQuery · 이미지CDN · YouTube · 서버렌더 · `.htaccess` | §7 | — | ⚪ **부적용** |

**한 줄 결론**: 원본 문서에서 가장 크게 먹힐 것은 **압축**인데 ERP는 그걸 **안 하고 있었다.**
🔧 **§0~§6 은 초판이다.** 「데이터량은 병목이 아니다」는 **DB 시간에 대해서만 맞다** —
응답 크기 축을 놓쳤고 회사도 거꾸로 봤다. **§10 을 먼저 읽을 것.**

---

## 1. 측정 범위

```
ssancarerp  heymancar.com    차량 4,706대   ← 데이터가 많은 쪽 (DB 측정)
heymanerp   52.79.200.151    차량   264대   ← 로그를 잰 쪽 (⚠️ §10-1 — 실제로는 여기가 더 한가하다)
karabaerp                                    ← 미측정. 같은 배포라 설정은 같을 것으로 보나 확인 필요
```
전부 **읽기 전용**이다(SELECT · 로그 조회 · 외부 curl). 운영에 쓴 것 없음.

---

## 2. 🔴 1순위 — nginx 가 **JSON 을 압축하지 않는다**

### 2-1. 무엇이 문제인가

```nginx
# /etc/nginx/nginx.conf
gzip on;
# gzip_types text/plain text/css application/json application/javascript ...   ← ★주석★
```

`gzip_types` 가 주석이라 nginx 는 **기본값 `text/html` 만** 압축한다.
그런데 **Livewire 응답은 `application/json`** 이다
(`vendor/livewire/livewire/src/Mechanisms/HandleRequests/HandleRequests.php:234`).

⇒ **화면 조작 응답이 전부 비압축으로 나간다.**

**증명** — 같은 서버 · 같은 PHP-FPM 경로인데 content-type 만으로 갈린다:

```
GET  /login                     → text/html        → Content-Encoding: gzip   ✅
POST /livewire-…/update (404)   → text/html        → Content-Encoding: gzip   ✅
POST /livewire-…/update (정상)  → application/json → ★압축 없음★
```

### 2-2. 규모 — 실측 (heymanerp · 2026-09-01 09:00~16:14 KST)

```
livewire update   459건   합계 54.5 MB   평균 124 KB · 중앙값 101 KB · 최대 366 KB
전체 트래픽                     63.1 MB   ⇒ ★livewire 가 86%★
```

**ERP 자체 마크업의 실측 압축률 = −75%** (운영 로그인 페이지 17,024B → 4,106B).
Blade + Tailwind 는 클래스 문자열이 반복돼 압축이 잘 먹는다.

⇒ **54.5MB → 약 14MB.** 체감으로는 **클릭 1회당 124KB → 약 31KB.**

⚠️ 이 −75%는 **로그인 페이지 HTML** 로 잰 값이다. livewire 응답은 그 HTML 에
스냅샷·체크섬 JSON 봉투가 더 붙으므로 **정확히 같지는 않다.** HTML/JSON 계열의
통상 범위(−70~85%) 안에 들어올 것으로 본다.

### 2-3. 정적 자산도 같이 빠져 있다

| 자산 | 현재 | gzip 시 | |
|---|---|---|---|
| `app-DvnWz_nO.css` | 233,299 B | 32,645 B | −86% |
| `livewire.min.js` | 238,305 B | 78,897 B | −66% |
| `flux.min.js` | 128,135 B | 31,766 B | −75% |
| `app-tIlBf2xE.js` | 60,787 B | 18,287 B | −69% |
| `app-CksuuEqD.css` | 15,738 B | 2,983 B | −81% |
| **합계** | **676,264 B** | **164,578 B** | **−75%** |

**첫 방문 1회당 499KB.** 실사용 로그에서 자산 요청은 7시간에 10~14회였다 —
브라우저가 대부분 캐시하므로 총량보다 **「첫 방문·캐시 만료 시의 첫 화면 지연」**이 실제 영향이다.

### 2-4. 고치는 법

`nginx.conf` 의 주석 4줄을 살리면 된다(`gzip_types` · `gzip_vary` · `gzip_comp_level` · `gzip_proxied`).
**설정만 바뀌고 앱 코드는 안 바뀐다. 되돌리기는 주석 복원.**

---

## 3. 🟠 2순위 — 정적 자산에 **캐시 헤더가 없다**

```
GET /build/assets/app-tIlBf2xE.js
   Cache-Control : ★없음★
   Expires       : ★없음★
```

Vite 가 **콘텐츠 해시를 파일명에 박아 준다**(`app-tIlBf2xE.js`) — 내용이 바뀌면 이름이 바뀐다.
즉 `max-age=31536000, immutable` 이 **안전한 조건이 이미 갖춰져 있는데 안 쓰고 있다.**
지금은 브라우저 휴리스틱 캐시에 맡겨져 있어 세션마다 재검증·재수신이 생긴다.

⇒ `location /build/` 에 `expires 1y; add_header Cache-Control "public, immutable";`
(원본 문서 §2-4 와 같은 판단이고, **해시가 있어서 1년이 안전하다**는 근거도 같다.)

---

## 4. 🟡 3순위 — 운영이 **HTTP/1.1** 이다

```
curl https://heymancar.com/login → http=1.1     Server: nginx/1.24.0 (Ubuntu)
sites-enabled 에 http2 지시어 없음
```

nginx 1.24 는 `listen 443 ssl; http2 on;` 한 줄이면 된다.
ERP 로그인 화면 기준 서브리소스가 **6개**(css 2 · js 3 · 폰트 CSS 1)라 다중화 이득이 극적이진 않지만,
**Livewire 는 조작마다 요청이 나가므로** 연결 재사용·헤더 압축(HPACK) 이득이 누적된다.

📌 **KeepAlive 는 손댈 필요 없다.** 원본 문서가 30초로 올린 건 **Apache 기본값이 5초**여서인데,
**nginx 기본은 75초**다. 이미 문서가 목표한 값보다 길다.

---

## 5. 🟡 4순위 — `fonts.bunny.net` (스캐폴딩 기본값)

```html
<!-- resources/views/partials/head.blade.php:30-31 — ★로그인 포함 전 화면★ -->
<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />
```

- **렌더 블로킹 + 크로스오리진.** CSS 왕복(실측 96ms) 후 그 CSS 를 파싱해야 woff2 요청이 시작된다.
- **`Instrument Sans` 는 라틴 전용**이다(`instrument-sans-latin-*.woff2`).
  ERP 화면은 대부분 한글이라 **한글은 어차피 `ui-sans-serif, system-ui` 로 떨어진다.**
  효과가 있는 건 숫자·영문 라벨뿐이다.
- 출처 = **Laravel 스타터킷 기본값.** 우리가 골라 넣은 게 아니다.
  (SKILLS §8 #57 의 `coverage: xdebug` 와 같은 형태 — *"첫 커밋에 들어와 아무도 안 건드린
  설정은 검토된 것이 아니다"*.)

**선택지 둘** — 어느 쪽이든 외부 오리진 2개가 사라진다:

```
(가) 자체 호스팅   woff2 를 public/ 에 두고 @font-face 인라인   → 모양 그대로, 왕복만 제거
(나) 시스템 스택   --font-sans 에서 'Instrument Sans' 제거       → 코드 1줄, 모양이 약간 바뀐다
```

📌 **원본 문서의 「유럽 RTT」 논리는 ERP에 그대로 오지 않는다** — ERP 사용자는 국내 직원이다.
그래서 1~3순위보다 아래다. **화면 모양이 바뀌는 변경이라 jin 판단이 필요하다.**

---

## 6. 🔵 5순위 — 데이터량 (⚠️ **§10-2 가 이 절을 보강한다 — 응답 크기 축을 놓쳤다**)

### 6-1. 목록 렌더는 데이터량과 거의 무관하다

| | ssancarerp 4,706대 | heymanerp 264대 |
|---|---|---|
| 목록 20행 + eager 9종 + accessor | **32.9ms** (쿼리 7) | 37.5ms (쿼리 10) |
| 헤더 합계(필터 전체 집계) | 10.7ms | 3.5ms |
| `COUNT(*)` 페이지네이터 | 6.9ms | 1.3ms |
| 진행상태 groupBy | 12.4ms | 1.5ms |

**18배 데이터인데 목록 렌더가 오히려 비슷하다** — 페이지네이션이 있고 eager load 9종이
이미 걸려 있어서다(`vehicles/index.blade.php:2038`). **전형적 N+1 은 없다.**

⇒ **jin 이 느낀 「느려짐」은 DB 때문이 아닐 가능성이 높다.** §2 의 비압축 응답(평균 124KB)이
더 그럴듯한 원인이다. (CLAUDE.md #15 — *"느리다 제보를 성능 문제로 단정하지 말 것"*.)

### 6-2. 다만 **검색 경로는 스캔이다** — 여기가 「미리 준비」할 자리

ssancarerp(4,706대)에서 화면이 실제로 쓰는 경로:

```
검색 LIKE (차량번호·선명·컨테이너 OR 3컬럼)   ★99.7ms★   ← 선행 % 라 인덱스 못 씀
차대번호 끝 6자리  RIGHT(vin,6) LIKE          45.6ms     ← 함수라 인덱스 못 씀
컨사이니 COALESCE 정렬                         46.5ms     ← 표현식 정렬(filesort)
진행상태 + 기간 + sale_date 정렬               15.5ms     ← 인덱스 탐 ✅
미수 정렬 (캐시 컬럼)                           9.3ms     ← 인덱스 탐 ✅
```

인덱스는 **31개**가 잘 깔려 있고(진행상태 · 미수 · 날짜 전부 커버) **스캔하는 셋만 남는다.**
지금은 100ms라 체감되지 않지만 **행 수에 비례한다** — 4,700 → 47,000 이면 **약 1초**다.

**지금 할 일은 없다.** 다음 대량 적재 전에 셋 중 하나를 고르면 된다:

```
(가) 검색 최소 글자수 2~3자 강제 + 차량번호 우선 매칭   ← 코드만, 제일 싸다
(나) 차대번호 끝 6자리를 컬럼으로 뽑아 인덱스           ← 저장 시 채움, RIGHT() 제거
(다) FULLTEXT 인덱스                                     ← 검색 의미가 바뀌므로 신중
```

⚠️ **미리 만들지 않았다.** 지금 넣으면 안 쓰는 최적화가 하나 늘고, 그건 나중에 낡는다.

---

## 7. ⚪ 부적용 — 원본 문서에 있으나 ERP엔 해당 없음

| 원본 항목 | ERP 판정 |
|---|---|
| CSS/JS 수동 번들링 (`build_*_bundle.php`) | **Vite 가 이미 한다.** 해시 파일명까지 포함 |
| jQuery 자체호스팅 · defer 판단 | **jQuery 가 없다.** Alpine + Livewire |
| `.htaccess` 캐시 규칙 | **Apache 아님.** nginx `location` 블록 (§3) |
| 첫 페이지 서버 렌더 (AJAX 왕복 제거) | **Livewire 가 이미 서버 렌더**다. 첫 페이지에 AJAX 왕복이 없다 |
| YouTube 파사드 | ERP에 iframe 임베드 없음 |
| 이미지 CDN 포맷 협상 (webp/avif) | 차량 사진은 **S3 서명 URL**이고 목록에 안 뜬다. 바이어 대면도 아님 → 효과 작음 |
| 첫 화면 lazy 해제 · `fetchpriority=high` | LCP 요소가 히어로 이미지가 아니라 **표**다. 해당 없음 |
| `preconnect` 4개 이하 | 현재 **1개**(bunny). §5 를 하면 **0개**가 된다 |
| PHP OPcache | 이미 켜져 있다 — `enable=On · 128MB · 10,000 files · validate_timestamps=On` |
| Brotli 사전압축 | **모듈 미설치**(`nginx -V` 확인). gzip 먼저 적용하고 그 뒤에 판단 |

---

## 8. 적용 시 주의

- **1~3순위는 nginx 설정만**이다. 앱 코드 · DB · 배포 파이프라인과 무관하고
  **각각 독립적으로 되돌릴 수 있다.**
- 🚨 **3사 웹 티어를 각각 고쳐야 한다** — 서버 설정이라 **git 배포로 안 나간다.**
  한 대만 고치면 회사마다 체감이 갈린다.
- **적용 후 확인**: `curl -sI --compressed <자산>` 에 `Content-Encoding: gzip` 과
  `Cache-Control: … immutable` 이 뜨는지. livewire 는 access.log 의 평균 바이트가 떨어지는지.
- 4순위(폰트)는 **화면 모양이 바뀐다** — jin 판단 사항.
- 5순위는 **지금 하지 않는다.** 다음 대량 적재 전에 다시 꺼낸다.
- 📌 nginx 는 **`$body_bytes_sent`(전송 바이트)를 기록**하므로, 압축을 켜면 로그의 평균이
  그대로 내려간다. 이게 적용 전후를 대조하는 가장 싼 방법이다.
  (`log_format` 에 `$request_time` 이 없어 **서버 처리 시간은 기록되지 않는다** —
  나중에 추적이 필요하면 그것부터 켜야 한다.)

---

## 9. 실측 부록 (2026-09-01 · 읽기 전용)

```
트래픽   heymanerp 09:00~16:14 KST · nginx access.log 1,016줄
         livewire update 459건 / 54.5MB (전체 63.1MB 의 86%) · 평균 124,488B · 최대 365,647B
         전체 페이지 로드 약 188건 · 정적 자산 요청 10~14건

압축     ERP 로그인 HTML 17,024B → gzip 4,106B (−75%)
         자산 5종 676,264B → 164,578B (−75%)
         현재 Content-Encoding : HTML=gzip / CSS·JS·JSON=★없음★

인프라   nginx/1.24.0 · HTTP/1.1 · gzip on 이나 gzip_types 주석 · brotli 모듈 없음
         정적 자산 Cache-Control·Expires 없음
         OPcache On / 128MB / 10,000 files / validate_timestamps=On

DB       ssancarerp 4,706대 : 목록 32.9ms(쿼리 7) · 검색 99.7ms · vin끝6 45.6ms
                              COALESCE정렬 46.5ms · 진행상태+기간 15.5ms · 미수정렬 9.3ms
         heymanerp    264대 : 목록 37.5ms(쿼리 10)
         vehicles 인덱스 31개 / 컬럼 159개

폰트     fonts.bunny.net CSS 왕복 96ms(3,216B) → 그 뒤 woff2 2차 왕복 · latin 전용
```


---

## 10. 🔧 적용 결과 · 초판 정정 (2026-09-01)

### 10-1. 🔴 초판이 회사를 거꾸로 봤다

초판은 `/var/log/nginx/access.log` 를 읽고 **"ssancarerp 는 트래픽이 거의 없다"** 고 판단했다.
**틀렸다.** ssancarerp 의 ERP vhost 는 로그를 **다른 파일에 쓴다**:

```nginx
# /etc/nginx/sites-available/ssancar-erp
access_log /ssancar-erp/logs/nginx-access.log;   ← ★여기★
```

기본 경로에는 board 와 봇 스캔만 남아 있었다. 진짜 로그를 열어 보니 정반대였다:

| 2026-09-01 (07:37 기준) | livewire update | 합계 | 평균 응답 |
|---|---|---|---|
| **ssancarerp** (4,706대) | **2,473건** | **1,052 MB** | **446,175 B** |
| heymanerp (264대) | 459건 | 54.5 MB | 124,488 B |

⇒ ssancarerp 가 요청 **5배** · 바이트 **19배** · **평균 응답이 3.6배** 크다.

**🧭 교훈**: nginx 는 vhost 마다 `access_log` 를 따로 잡을 수 있다.
**기본 경로만 보고 「트래픽이 없다」고 판단하지 말 것** — `nginx -T | grep access_log` 로 먼저 확인한다.
(카나리아를 「제일 한가한 서버」로 고른다고 골랐는데 실제로는 **제일 바쁜 서버**였다. 결과는 정상이었지만
근거가 틀렸다.)

### 10-2. 🔧 §6 의 결론을 보강한다 — jin 의 직감이 맞았다

초판은 *"데이터량은 병목이 아니다 — 목록 렌더가 33ms 대 38ms 로 비슷하다"* 라고 썼다.
**DB 시간에 대해서는 맞다.** 그런데 **놓친 축이 있었다 — 응답 크기다.**

```
heymanerp    264대  →  livewire 평균 124 KB
ssancarerp 4,706대  →  livewire 평균 446 KB   ★3.6배★
```

데이터가 늘면 목록·필터·집계가 그려내는 **HTML 자체가 커진다.** DB 는 20줄만 꺼내니 빠른데,
**그려서 내보내는 양이 커지는 것**이다. 그게 전부 비압축으로 나가고 있었다.

⇒ **jin 이 「적재되니까 느려지는 것 같다」고 한 것은 맞았다.** 다만 원인이 «DB 가 느려서» 가 아니라
**«응답이 커져서»** 였다. 초판의 §6 은 방향은 맞았지만 **한 회사(heymanerp)의 숫자로 일반화**했다.

### 10-3. 적용한 것 (3사 동일)

```nginx
# /etc/nginx/nginx.conf — 주석 해제 (우분투 기본값 복원)
gzip_vary on;  gzip_proxied any;  gzip_comp_level 6;
gzip_types text/plain text/css application/json application/javascript ... ;

# vhost — car-erp server 블록
location /build/ { add_header Cache-Control "public, max-age=31536000, immutable"; }
listen 443 ssl http2;        # nginx 1.24 는 `http2 on;` 문법이 없다
```

백업 = 각 서버 `*.perfbak.20260901-0737~0740`. `nginx -t` 통과 시에만 reload 하고,
실패하면 자동 원복하도록 스크립트를 짰다(3사 모두 첫 시도에 통과).

### 10-4. 검증 — 운영 실측

```
3사 공통   /login 200 · app.js 60,787B → 18,422B · Cache-Control: public, max-age=31536000, immutable
           gzip_types 에 application/json 포함 확인 · listen 443 ssl http2 확인

★ssancarerp 실트래픽 대조★
   적용 전 00:00~07:36   2,473건 · 평균 446,175 B · 합계 1,052.3 MB
   적용 후 07:37~            14건 · 평균  96,968 B · 합계     1.3 MB
                                        ★ 평균 −78% ★
```

### 10-5. 남은 것

```
4순위 폰트 (fonts.bunny.net)   화면 모양이 바뀐다 → jin 판단 대기
5순위 검색 스캔                다음 대량 적재 전에. 지금은 100ms 라 안 만든다
Brotli                         gzip 효과를 며칠 보고 나서 판단
log_format $request_time       ★서버 처리 시간이 기록되지 않는다★ — 앞으로 추적하려면 이것부터
```

⚠️ **certbot 갱신이 `listen` 줄을 다시 쓸 수 있다.** 갱신 후 http2 가 빠졌으면 다시 넣으면 된다
(압축·캐시는 각각 다른 파일/블록이라 영향 없다).
