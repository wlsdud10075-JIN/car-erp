# ssancarerp 서버 이전 실행계획 (2026-09-24 ~ 28 추석)

> 🎯 **8GB → 4GB 다운사이징.** 화면·데이터·문서·주소 **전부 그대로.** 바뀌는 건 「담는 그릇」뿐.
> 📌 사전 조사 = `ssancarerp-instance-downsize.md` (왜 4GB 인지·왜 1GB 는 안 되는지)
> 🧰 **실행 명령 = `ssancarerp-server-migration-commands.md`** — A~D 를 블록별 「붙여 넣고 검증 한 줄」로 풀어 둔 것(2026-09-24). 실행할 땐 그 문서를 연다.
> 🔧 **2026-09-24 재개 준비 실측 정정 = §2-B** — 런북 가정과 달랐던 것 10개(letsencrypt 복사·방화벽 443·WireGuard 단일 peer 함정·NAS 백업 키·Django sqlite·타임존 UTC 등). **jin 몫 2·3·6 과 A·B·C·D 에 반영 완료.**
> ✅ **2026-09-24 전환 완료** — 고정 IP 재할당(가), 다운타임 ≈6분, 건수 구=신 일치, D 서버 몫 전부 통과, GH deploy-ssancar 재실행 success. 남은 것 = jin 실사용 확인 · 익일 NAS 수신 · **10/1 이후 구 인스턴스 삭제**. 실행 중 잡은 함정 4개 = commands 문서 맨 아래 「실행 후기」.

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
| **DB 백업 cron** | ⚠️ 09-24 실측 정정 — ubuntu crontab 3줄: `0 1 * * 0 weekly_backup_prepare.sh` · `* * * * * schedule:run`(→ Laravel `db:backup` 이 매일 S3 로) · `30 16 * * * erp_db_dump.sh`(=01:30 KST, **09-22 수정본**, `backup_staging/daily` 에 mysql 2 + Django sqlite). `db_backup.sh` 는 crontab 에 **없다**(수동용, `.db_backup.cnf` 필요) | 스크립트 3개 + `.db_backup.cnf`(🚨 DB 비번) 복사 + crontab 3줄 재등록 + `backup_staging` 디렉터리 재생성 |
| **NAS → AWS 백업 키** | `authorized_keys` 에 `nas-to-aws-backup`(ed25519) — 사무실 NAS 가 이 서버로 붙어 `backup_staging` 을 당겨간다(09-24 발견, 런북에 없던 연결) | `authorized_keys` 통째 복사(github-actions ×3 · claude · nas). 👤 NAS 가 **IP 로 붙는지 도메인으로 붙는지**는 jin 확인 — 고정 IP 재할당이면 둘 다 무변경 |
| **SSL** | `heymancar.com`(+www) 만료 11/29 · `board.heymancar.com` 만료 11/24 | ⚠️ 09-24 정정 — **`/etc/letsencrypt` 를 구→신 복사**(nginx 사이트 conf 가 `listen 443 ssl` + 인증서 경로라 파일 없으면 nginx 가 안 뜬다 = B11 전 화면 점검 불가). 새로 발급은 IP 가 넘어간 뒤에나 가능하므로 전환 후엔 **`certbot renew --dry-run` 으로 갱신 경로만 확인** |
| **큐 워커** | supervisor 2개 (board-ssancar-worker=`www-data` · ssancar-car-erp-worker=`ubuntu`) | conf 복사 — **실행 사용자가 다르다**, storage 는 `ubuntu:www-data` setgid |
| **챗봇 색인** | `storage/app/index-erp.json`(3MB) — 회사 GPU PC 가 SSH 로 밀어 넣는다(현재 챗봇 OFF, 09-09 정지) | 파일 1개 복사. 👤 GPU PC 스크립트가 어느 주소(도메인/Tailscale IP)로 붙는지는 챗봇 재가동 때 확인 |

## 2-B. 🔧 2026-09-24 실측 정정 — 런북 가정과 달랐던 것 (구 서버 읽기 전용 인벤토리)

| # | 가정 | 실측 | 반영 |
|---|---|---|---|
| 1 | 전환 후 certbot 재발급 | 사이트 conf 가 인증서 파일을 참조 → **파일 없으면 nginx 기동 실패**. IP 전환 전 발급 불가 | **B11 에서 `/etc/letsencrypt` 구→신 복사**, C6 = `renew --dry-run` |
| 2 | 새 인스턴스 방화벽 | Lightsail 기본 = 22·80 만 | 👤 **jin 2 에 443 추가**. WireGuard·Tailscale 은 outbound 라 inbound 불필요 |
| 3 | 🚨 WireGuard 는 conf 복사로 끝 | **키·peer 가 하나** — 새 서버가 터널을 올리면 공유기 peer endpoint 가 새 서버로 넘어가 **구 서버 원부조회(3사 게이트웨이)가 끊긴다** | B8 = conf 만 넣고 **기동 금지**. C1 구 서버 wg 정지 → C3b 새 서버 wg 기동 |
| 4 | 타임존 설정 | 구 서버 **UTC** (cron `30 16` = 01:30 KST) | B1 = UTC 그대로. KST 로 바꾸면 cron 전부 9시간 밀린다 |
| 5 | PHP 8.4 | Ubuntu 24.04 기본은 8.3 → **ondrej PPA** · 패키지 13개(bcmath cli common curl fpm gd intl mbstring mysql opcache readline xml zip) · node **24** · composer 2.7 · LibreOffice 24.2 + fonts-nanum(배포 스크립트가 없으면 설치하므로 미리) | B2 명령에 실측 목록 그대로 |
| 6 | 백업 = 스크립트 2개 | 3개 + `.db_backup.cnf`(비밀) + `backup_staging/` + **NAS 가 붙는 `nas-to-aws-backup` 키** | §2 표 · A4 · B11 · D7 |
| 7 | Django 는 안 만들면 끝 | `/ssancar-erp/db.sqlite3`(바이어·컨사이니 원본) 을 `erp_db_dump.sh` 가 매일 뜬다 | **A5 = 구 인스턴스 삭제 전 1회 보관**(venv 제외 tar). 새 서버엔 없어도 스크립트는 WARN 후 통과 |
| 8 | Tailscale 가입 | `tailscale up` 이 **로그인 URL 승인**을 요구 · 노드명 `ssancarerp-server` 충돌 | 👤 **jin 3-B**: URL 승인. 새 노드명 `ssancarerp-server-new` |
| 9 | 문서·사진 = S3 | ✅ 맞다 — `VEHICLE_DOCS_DISK=s3` · `DB_BACKUP_DISK=s3` · 버킷 `ssancar-erp-docs`. `.env` 의 `FILESYSTEM_DISK=local` 은 livewire 임시파일(42MB)뿐 | 옮길 로컬 파일 = board `storage/app` 8개(2.6MB) · `index-erp.json` 뿐 |
| 10 | `DEPLOY_HOST` 시크릿 | 값은 못 본다(마스킹). **고정 IP 재할당(가)이면 IP 가 안 바뀌어 어느 쪽이든 무변경**. 동적(나)이면 jin 이 도메인으로 재설정 | 새 서버 `authorized_keys` 에 github-actions 키가 복사되면 끝. D10 = 같은 master 재배포로 경로 확인 |

**모순 정리(둘이 다르게 적혀 있던 것)** — `pm.max_children` = **14**(09-21 PSS 실측이 나중 근거: 워커당 21MB, 14개 300MB. 10 으로 줄이면 NICE 8~14초 점유 때 워커 부족) · `innodb_buffer_pool_size` = **기본 128M 그대로**(구 서버도 override 없음, DB 55.6MB. 아래 B3 의 512M 은 취소).

---

# 👤 jin 이 할 일

### D-7 (~9/23) — 준비

> ✅ **`.env` 대조 완료 (2026-09-18)** — 바탕화면 백업(`Desktop\AWS\`)과 서버를 맞춰봤다.
> - **APP_KEY 지문 일치** `b5a5c4c8cbd99e0b` ⇒ **RRN 복호화 안전**
> - ssancarboard(09-10 백업) **69/69 완전 일치**
> - 🚨 ssancarerp(08-25 백업)는 **2개 빠짐** — `SSANCAR_PORTAL_HMAC_SECRET` · `SSANCAR_PORTAL_SOURCE`
>   (ssancar.com 포털 연동 키, 08-26 이후 추가). 백업본만 쓰면 **바이어 마이페이지 연동이 죽는다.**
> ⇒ **실제 이전에는 서버에서 직접 뜬 것을 쓴다.** 백업은 대조용. 이전 후 백업도 갱신할 것.

- [ ] **1. Lightsail 콘솔에서 확인** — 현재 인스턴스 번들·요금
      🅿️ **고정 IP 여부는 당일 확인**(jin 2026-09-18 «고정 맞을 거야, 우리가 쓰기 훨씬 전부터 한 번도 안 바뀌었다»).
      ⚠️ 어느 쪽이든 그날 막히지 않게 **C4 에 두 갈래를 다 넣어 뒀다.** 원부조회는 **둘 다 영향 없다**
         (WireGuard 가 AWS→공유기 방향이라서). 동적일 경우 **DNS 2건만** 손대면 된다.
- [ ] **2. 새 인스턴스 생성** — Ubuntu 24.04 · **4GB / 2 vCPU** · **같은 리전·가용영역**(ap-northeast-2)
      🚫 스냅샷 복원 금지 — Lightsail 은 **더 작은 번들로 복원이 안 된다.** 빈 인스턴스로 만든다.
      🔥 **네트워킹 탭 방화벽에 HTTPS 443 추가**(기본은 22·80 뿐 — 없으면 B11 점검에서 접속이 안 된다). 그 외 inbound 불필요
- [ ] **3. 새 인스턴스에 SSH 키 등록** — 기존 `car_erp_key` 를 그대로 쓰면 내 작업이 수월하다
- [ ] **3-B. Tailscale 승인** — B9 에서 내가 URL 을 주면 브라우저에서 승인(1분). 새 노드명 `ssancarerp-server-new`
- [ ] **4. APP_KEY 백업 확인** — 🚨 **이게 사라지면 RRN 전량 복구 불가.** 1Password 등에 있는지 확인
- [ ] **5. 전환 시간대 결정** — 다운타임 **10~20분**. 업무 없는 시간으로
- [ ] **6. 알려주기** — 새 인스턴스 접속 정보(공인 IP)
      ➕ **사무실 NAS 백업이 서버에 어떤 주소로 붙는지**(IP `54.116.7.83` / 도메인) — 고정 IP 재할당이면 무관, 동적이면 NAS 쪽도 바꿔야 한다

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
A3  /etc/wireguard/wg-carmodoo.conf · /etc/letsencrypt · ~/.db_backup.cnf   🚨 비밀 — 로컬에 안 뜬다, B 에서 구→신 직결 파이프
A4  nginx 사이트 설정 · php-fpm pool · supervisor conf · cron · systemd 유닛 · authorized_keys(공개키) 수집
A5  /home/ubuntu/*.sh (백업 스크립트 3개) + 구 Django /ssancar-erp (venv 제외 tar — db.sqlite3 원본 보관 1회)
A6  현재 상태 스냅샷 기록 — 차량수·정산수·S3 객체수·마지막 알림톡 (이전 후 대조용)
```
※ A1 덤프는 `mysqldump | gzip` 을 ssh 파이프로 로컬에 받는다 — **구 서버에 파일을 만들지 않는다**(쓰기 0). 명령 = commands 문서 A.

### B. 새 서버 구축 (D-2 ~ D-1, 구 서버 영향 0)

```
B1  스왑 2GB · 타임존 **UTC 그대로**(cron 이 UTC 기준으로 적혀 있다)
B2  ondrej PPA → PHP 8.4 패키지 13개(bcmath cli common curl fpm gd intl mbstring mysql opcache readline xml zip)
    · nginx 1.24 · MySQL 8.0 · node 24 · composer · supervisor · certbot · wireguard-tools · tailscale · libreoffice-calc+fonts-nanum
    🚨 gd · zip 없으면 서류(xlsx)가 통째로 죽는다 · php.ini upload/post 40M
B3  MySQL — DB 2개 + 사용자(값은 .env 에서). 튜닝 **없음**(구 서버도 override 0, DB 55MB — 512M 안은 취소)
B4  php-fpm pm.max_children **14**(구 서버와 동일 — 09-21 PSS 실측으로 4GB 에 충분. 🚫 줄이지 말 것, NICE 점유 때 워커 부족)
B5  코드 배포 — car-erp(master) · board-ssancar(master) — sha 가 구 서버와 같아야 한다
B6  .env 구→신 파이프(로컬 디스크 X) + APP_KEY 지문 대조 + composer --no-dev + npm ci && build + storage:link + 권한(ubuntu:www-data setgid)
B7  WireGuard conf 복사만 — 🚨 **기동 금지**(§2-B #3: 올리는 순간 구 서버 원부조회가 끊긴다). 기동은 C3b
B8  Tailscale 가입 (새 노드 `ssancarerp-server-new`, 👤 jin URL 승인)
B9  DB 복원 2개 → 건수 대조
B10 nginx 사이트 2개 + **/etc/letsencrypt 복사** + supervisor + cron 3줄 + 백업 스크립트 3개 + .db_backup.cnf + authorized_keys + backup_staging/ (🚫 구 Django 는 만들지 않는다)
B11 로컬 hosts(또는 curl --resolve)로 도메인을 새 서버에 물려 **전 화면 점검** (아직 IP 전환 전 — 복사한 인증서라 경고 없이 열린다)
```
※ 블록별 명령·검증 = `ssancarerp-server-migration-commands.md` §B (번호가 조금 다르다 — 실행 순서대로 다시 매겼다).

### C. 전환 (D-day, 다운타임 10~20분)

```
C1  구 서버 점검모드(artisan down ×2) + cron·supervisor 정지 + **WireGuard 정지**   ← 쓰기 차단 + 터널 반납
      🚨 이 순간부터 C3b 까지 3사 원부조회 불통 — 5분 안에
C2  최종 DB 덤프 → 새 서버 복원 (56MB, 3분 — DROP/CREATE 후 통째로)      ← 그 사이 데이터
C3  건수 대조 (차량·정산·잔금·감사로그·알림톡)
C3b 새 서버 기동 — **WireGuard 먼저**(handshake 확인) → supervisor → config:cache → artisan up ×2
C4  IP 넘기기 — **당일 콘솔에서 확인 후 둘 중 하나**
      (가) 고정 IP 다 → 구 인스턴스에서 분리 → 새 인스턴스에 연결. **IP·DNS·공유기 무변경**
      (나) 동적이다   → 새 인스턴스의 IP 로 **DNS 2건** 변경
                       (`heymancar.com`(+www) · `board.heymancar.com`)
                       ⚠️ 전파 대기가 있으니 **TTL 을 전날 300초로 미리 낮춰두면** 빠르다
      🔑 **어느 쪽이든 원부조회는 영향 없다** — WireGuard 가 AWS→공유기 방향이고
         NICE 가 보는 건 공유기 IP 다(§1).
C5  DNS 전파 확인 (고정 IP 면 즉시 / 동적이면 dig 로 확인)
C6  인증서 = 복사본(11/24·11/29 까지 유효). `certbot renew --dry-run` 으로 갱신 경로만 확인(재발급 불필요)
C7  구 서버는 그대로 둔다 (down 상태로 정지만, 삭제 금지) — ⚠️ IP 가 넘어간 뒤엔 `heymancar.com` SSH = 새 서버. 구 서버는 콘솔 브라우저 SSH
```

### D. 검증 (C 직후, jin 과 함께)

```
D1  🚨 원부조회 — 차량 1대 NICE 조회 성공?          ← 제일 중요
D2  로그인 · 차량목록 100개 보기 · 사이드탭 편집
D3  서류 다운로드(xlsx) · 차량 사진 보기            ← S3 연결
D4  board.heymancar.com 접속 · 연동 1건
D5  알림톡 테스트 발송 1건
D6  큐 워커 · schedule:run 동작
D7  DB 백업 수동 1회 — `db:backup`(S3) + `erp_db_dump.sh`(backup_staging) + 👤 **다음 날 NAS 가 당겨갔나**
D8  RRN 복호화 확인 (APP_KEY 정상)                  ← 화면에서 1건
D9  A6 스냅샷과 건수 전량 대조
D10 GitHub 배포 경로 — 직전 deploy 런의 `deploy-ssancar` 잡 재실행(같은 master, 무중단 스크립트) → success
D11 메모리 실측 (`free -m` · php-fpm RSS 합) — used < 2GB 면 4GB 판정 확인
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
