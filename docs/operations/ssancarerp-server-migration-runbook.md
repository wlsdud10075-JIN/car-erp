# ssancarerp 서버 이전 실행계획 (2026-09-24 ~ 28 추석)

> 🎯 **8GB → 4GB 다운사이징.** 화면·데이터·문서·주소 **전부 그대로.** 바뀌는 건 「담는 그릇」뿐.
> 📌 사전 조사 = `ssancarerp-instance-downsize.md` (왜 4GB 인지·왜 1GB 는 안 되는지)
> 🚫 **아직 아무것도 안 바꿨다.**

## 0. 이 서버에 사는 것 — **4개를 통째로 옮긴다**

| | 무엇 | 비고 |
|---|---|---|
| ① | **ssancarerp** `heymancar.com` | DB `ssancar_erp` 48.2MB |
| ② | **ssancarboard** `board.heymancar.com` | DB `board_ssancar` 0.7MB |
| ③ | **NICE 원부조회 게이트웨이** `/provide/api/nice-lookup/` | 🚨 **3사가 전부 여기를 부른다** |
| ④ | 구 Django(gunicorn) `ssancar-erp.service` | **트래픽 0 — 이번에 제거**(jin 확정) |

```
Ubuntu 24.04.3 · PHP 8.4.22 · nginx 1.24.0 · MySQL 8.0.46 · 2 vCPU
```

## 1. 🔑 원부조회가 어떻게 나가는지 — **이게 이전의 핵심**

```
ERP  →  https://niceab.nicednr.co.kr (= 211.174.52.231)
         └ 라우팅: 211.174.52.231 dev wg-carmodoo
            └ WireGuard 터널  →  사무실 공유기 210.99.254.102:51820
                                  └ 여기서 NICE 로 나간다
```

🔑 **NICE 가 보는 IP 는 AWS 공인 IP 가 아니라 「사무실 공유기 IP」다.**
⇒ **AWS 서버를 바꿔도 NICE 쪽 화이트리스트는 손댈 필요가 없다.**

🔑 **AWS 가 공유기를 향해 접속한다**(`persistent keepalive 25s`, 공유기는 수신 측).
⇒ **공유기 설정도 바꿀 필요가 없다** — AWS 쪽 IP 가 바뀌어도 handshake 로 학습된다.
   (게다가 고정 IP 재할당으로 IP 자체가 안 바뀐다.)

⇒ **`/etc/wireguard/wg-carmodoo.conf` (258바이트) 한 개를 그대로 복사하면 원부조회가 그대로 간다.**
   ⚠️ 그 파일에 **개인키**가 있다. 복사 경로를 안전하게(로컬 경유 금지, 서버↔서버 직접).

## 2. 그 외 「보이지 않는 연결」

| | 무엇 | 이전 방법 |
|---|---|---|
| **Tailscale** | `ssancarerp-server` (100.87.123.37). 챗봇이 `gpu-office`(100.110.133.112:11434) 를 부른다 | 새 노드로 가입. **부르는 쪽이라 상대 IP 만 맞으면 된다** |
| **S3** | 차량 문서·사진 · DB 백업 | 🚫 **옮기지 않는다.** `.env` 의 키로 같은 버킷을 보면 끝 |
| **알림톡(BizM)** | 승인 버튼 링크가 `https://heymancar.com/a/payout/…` | 도메인 그대로라 **무변경**. 🚨 `APP_URL` 반드시 동일하게 |
| **DB 백업 cron** | 매일 18:00 UTC(=03:00 KST) `db_backup.sh` + 일요일 01:00 `weekly_backup_prepare.sh` | 스크립트 2개 복사 + crontab 재등록 |
| **SSL** | `heymancar.com`(+www) 만료 11/29 · `board.heymancar.com` 만료 11/24 | 전환 후 certbot 재발급(도메인 동일) |
| **큐 워커** | supervisor 2개 (board-ssancar-worker · ssancar-car-erp-worker) | conf 복사 |

---

# 👤 jin 이 할 일

### D-7 (~9/23) — 준비

- [ ] **1. Lightsail 콘솔에서 확인** ①현재 인스턴스 번들·요금 ②**현재 IP 가 「고정 IP(Static IP)」인지**
      (동적이면 이전 전에 고정 IP 를 먼저 붙여야 한다 — 이게 IP 유지의 전제다)
- [ ] **2. 새 인스턴스 생성** — Ubuntu 24.04 · **4GB / 2 vCPU** · **같은 리전·가용영역**(ap-northeast-2)
      🚫 스냅샷 복원 금지 — Lightsail 은 **더 작은 번들로 복원이 안 된다.** 빈 인스턴스로 만든다.
- [ ] **3. 새 인스턴스에 SSH 키 등록** — 기존 `car_erp_key` 를 그대로 쓰면 내 작업이 수월하다
- [ ] **4. APP_KEY 백업 확인** — 🚨 **이게 사라지면 RRN 전량 복구 불가.** 1Password 등에 있는지 확인
- [ ] **5. 전환 시간대 결정** — 다운타임 **10~20분**. 업무 없는 시간으로
- [ ] **6. 알려주기** — 새 인스턴스 접속 정보(공인 IP)

### D-day (9/24~28 중 하루)

- [ ] **7. 전환 승인** — 내가 「준비 끝, 지금 IP 넘깁니다」 하면 승인
- [ ] **8. 고정 IP 재할당** (콘솔) — 구 인스턴스에서 **분리** → 새 인스턴스에 **연결**
      ※ 이 작업만 콘솔에서 해야 해서 jin 몫이다
- [ ] **9. 눈으로 확인** — 아래 §검증 목록을 같이 본다

### D+7 (10/1 이후)

- [ ] **10. 구 인스턴스 삭제** — 일주일 지켜본 뒤. **그 전엔 절대 삭제 금지**(롤백 수단)

---

# 🤖 내가 할 일 — 순서대로

> ⚠️ **모든 단계는 「구 서버를 살려둔 채」 진행한다.** 구 서버는 마지막까지 서비스 중이다.

### A. 사전 백업 (D-2, 구 서버 무중단)

```
A1  DB 덤프 2개 (ssancar_erp · board_ssancar) → S3 + 로컬 2벌
A2  .env 2개 (car-erp · board) 안전 보관        🚨 APP_KEY 포함
A3  /etc/wireguard/wg-carmodoo.conf 보관        🚨 개인키 포함
A4  nginx 사이트 설정 · php-fpm pool · supervisor conf · cron · systemd 유닛 수집
A5  /home/ubuntu/*.sh (백업 스크립트 2개)
A6  현재 상태 스냅샷 기록 — 차량수·정산수·S3 객체수·마지막 알림톡 (이전 후 대조용)
```

### B. 새 서버 구축 (D-2 ~ D-1, 구 서버 영향 0)

```
B1  Ubuntu 24.04 기본 · 타임존 · 스왑 2GB
B2  nginx 1.24 · PHP 8.4 (+확장 전량: bcmath calendar ctype curl dom exif ffi fileinfo
    ftp gd gettext iconv intl mbstring mysqli opcache pcntl pdo_mysql posix shmop
    simplexml sockets sodium sysv* tokenizer xml xsl zip) · MySQL 8.0
    🚨 gd · zip 없으면 서류(xlsx)가 통째로 죽는다
B3  MySQL 튜닝 — 4GB 에 맞춰 innodb_buffer_pool_size 512M · max_connections 100
B4  php-fpm pm.max_children **10** (heymanerp 와 동일. 8GB 때의 14 를 그대로 쓰면 안 된다)
B5  코드 배포 — car-erp(master) · board-ssancar(master)
B6  .env 복사 + composer install --no-dev + npm ci && npm run build + storage:link
B7  WireGuard 설치 + conf 복사 + 기동 → **`wg show` 로 handshake 확인**
B8  Tailscale 가입 (새 노드)
B9  DB 복원 2개 → 건수 대조
B10 cron · supervisor · systemd 등록 (🚫 구 Django 는 만들지 않는다)
B11 로컬 hosts 로 도메인을 새 서버에 물려 **전 화면 점검** (아직 IP 전환 전)
```

### C. 전환 (D-day, 다운타임 10~20분)

```
C1  구 서버 점검모드(artisan down) + cron·supervisor 정지          ← 쓰기 차단
C2  최종 DB 덤프 → 새 서버 복원 (48MB, 3분)                        ← 그 사이 데이터
C3  건수 대조 (차량·정산·잔금·감사로그·알림톡)
C4  jin 에게 「IP 넘겨주세요」 → 고정 IP 재할당
C5  DNS 전파 확인 (IP 그대로면 즉시)
C6  certbot 재발급 (heymancar.com +www · board.heymancar.com)
C7  구 서버는 그대로 둔다 (정지만, 삭제 금지)
```

### D. 검증 (C 직후, jin 과 함께)

```
D1  🚨 원부조회 — 차량 1대 NICE 조회 성공?          ← 제일 중요
D2  로그인 · 차량목록 100개 보기 · 사이드탭 편집
D3  서류 다운로드(xlsx) · 차량 사진 보기            ← S3 연결
D4  board.heymancar.com 접속 · 연동 1건
D5  알림톡 테스트 발송 1건
D6  큐 워커 · schedule:run 동작
D7  DB 백업 cron 수동 1회 실행
D8  RRN 복호화 확인 (APP_KEY 정상)                  ← 화면에서 1건
D9  A6 스냅샷과 건수 전량 대조
```

### E. 사후 (D+1 ~ D+7)

```
E1  메모리·응답시간 관찰 (4GB 에서 여유가 실제로 얼마인지)
E2  로그 정리(logrotate) · /var/log 1.4GB 였다
E3  aws-deployment-record.md · 메모리 갱신 (IP·경로·번들)
E4  이상 없으면 jin 이 구 인스턴스 삭제
```

---

# 🚨 실패 대비

| 무엇 | 대비 |
|---|---|
| 전환 후 문제 발생 | **고정 IP 를 구 인스턴스로 되돌린다.** 구 서버는 그대로 살아 있다 → **5분 내 롤백** |
| DB 손실 | 덤프 3벌(S3·로컬·구서버) + 구 서버 DB 원본 |
| **APP_KEY 분실** | 🚨 **RRN 복구 불가.** A2 에서 보관 + jin 백업 이중 확인 |
| 원부조회 불통 | wg conf 복사 + `wg show` handshake 확인(B7). 실패 시 롤백 |
| S3 문서 | 🚫 **건드리지 않으므로 손실 경로가 없다** |

## 🅿️ 이전과 무관 — 따로 볼 것

- **NICE 조회가 워커를 8~14초 점유** → 3초 초과 248건의 주범. 이전으로 안 고쳐진다(별건)
- **구 Django 제거**는 이번 이전으로 자연히 끝난다(새 서버에 안 만든다)
