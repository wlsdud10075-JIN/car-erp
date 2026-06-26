# ssancar car-erp 배포 런북 (54.116.7.83 co-locate, Django 공존)

> ⚠️ **dev 전용 .md**. **트리거: "ssancar 배포 이어서"**. 결정 맥락 = `ssancar-migration-plan.md` ★확정 결정 / 메모리 `project_ssancar_migration`.
> 멀티테넌트 공통 절차는 `karaba-deployment-checklist.md`·`aws-deployment-record.md` 참조 — **이 문서는 ssancar 차이점(co-location + Django 공존)만 상세화.**

> 🗓️ **배포 시점 = 주말(아무도 안 쓸 때), 다운타임 허용** (jin 2026-06-26). → 아래 "무중단/공존" 절차는 **권장이지 필수 아님**. 급하면 apex 잠깐 내리고 편집해도 됨(주말이라 heyman NICE 잠깐 끊겨도 무방). 지금은 **계획만**, 실행은 주말.

## 0. 한 줄 + ⚠️ 최우선 안전원칙

`54.116.7.83` = **heyman 라이브 NICE 게이트웨이(Django/gunicorn)** 서빙 박스. 여기에 car-erp(ssancar) + board(ssancar)를 **공존 추가**. **이번 범위 = 쌍 배포만**(NICE 게이트웨이 이식 X — 후속).

**절대 건드리지 말 것 (heyman NICE 무중단):**
- `ssancar-erp.service`(gunicorn), `/ssancar-erp/`(Django 앱), `/etc/nginx/sites-enabled/ssancar-erp`(기존 nginx 블록의 `/provide/...` location). → **읽기만**. 새 nginx 블록은 **별도 파일**로 추가.
- Django는 SQLite, car-erp는 새 MySQL DB → **DB 충돌 없음**(MySQL 신규 설치=additive).
- 작업 전 항상 `cd /var/www/car-erp` + DB 대상 확인 `php artisan tinker --execute="echo DB::connection()->getDatabaseName();"` (ssancar_erp 여야 함).

## A. 선결 준비물 (jin / board 세션 → 시작 전)

| 항목 | 값 | 출처 |
|---|---|---|
| 도메인 (Option B) | car-erp = **apex `heymancar.com`** (이미 박스 가리킴 → DNS 추가 불필요) / board = `board.heymancar.com` (jin DNS A레코드 추가) | apex 는 Django 와 공존(§I) |
| DB 비번 | ssancar_erp_user 전용 비번 | jin 생성 |
| S3 | 버킷 `ssancar-erp-docs` + IAM `ssancar-erp-s3-user` 키 (heyman과 동일 AWS 계정) | jin (콘솔) |
| 관리자 계정 | ADMIN_*/BOSS_* 이메일·비번 | jin |
| **공유 시크릿 2개** | `CAR_ERP_HMAC_SECRET`·`CAR_ERP_READ_HMAC_SECRET` (`openssl rand -hex 32` 각) | **board 세션 생성** → 양쪽 .env 동일값 |
| NICE | (이번엔 이식 X) 기존 게이트웨이 URL+토큰 그대로 사용 가능 | heyman .env 값 재사용 가능(같은 박스 localhost 도 가능) |

## B. 코드 선결 = 없음

master(`ba4d274`+) 그대로. 확인됨: board 수신 4종 LIVE(purchase-sync HMAC·멱등=vehicle_number·salesman_email / 포털 read API / 첨부 v2 / 금액 v3). ssancar = **싼카 법인 → `system` 양식 공용**(COMPANY_TEMPLATE_SET 미설정/`system`). 템플릿 작업 0.

## C. 박스 LEMP 설치 (Django 공존)

```bash
ssh -i C:/Users/User/.ssh/car_erp_key ubuntu@54.116.7.83
# Django(gunicorn) 살아있는지 먼저 확인 — 건드리지 않음
systemctl is-active ssancar-erp nginx       # active 확인만
# PHP 8.4 (ondrej PPA) — gunicorn과 무관, 공존
sudo add-apt-repository -y ppa:ondrej/php && sudo apt update
sudo apt install -y php8.4-fpm php8.4-mysql php8.4-gd php8.4-zip \
  php8.4-mbstring php8.4-xml php8.4-curl php8.4-bcmath php8.4-intl \
  php8.4-dom php8.4-simplexml
sudo systemctl restart php8.4-fpm
sudo apt install -y mysql-server composer        # MySQL 신규(Django=SQLite라 무충돌)
# node LTS (nvm 또는 nodesource)
```

## D. DB (ssancar 전용 격리)

```sql
CREATE DATABASE ssancar_erp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'ssancar_erp_user'@'127.0.0.1' IDENTIFIED BY '***';
GRANT ALL ON ssancar_erp.* TO 'ssancar_erp_user'@'127.0.0.1';
```

## E. 코드 배포

```bash
cd /var/www && sudo git clone https://github.com/wlsdud10075-JIN/car-erp.git car-erp
cd car-erp && git checkout master
sudo chown -R ubuntu:ubuntu /var/www/car-erp
composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev
cp .env.example .env && npm ci && npm run build
```
(⚠️ `/var/www/car-erp` 가 heyman 앱과 다른 박스이므로 경로 충돌 없음. Django는 `/ssancar-erp/`.)

## F. .env (ssancar 전용 + 연동 시크릿)

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_NAME="SSANCAR ERP"
APP_URL=https://heymancar.com                # ← Option B: car-erp = apex (board=board.heymancar.com)
APP_KEY=                                     # ← G에서 1회 생성 (heyman 키 재사용 절대 X)

DB_DATABASE=ssancar_erp
DB_USERNAME=ssancar_erp_user
DB_PASSWORD=***

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database

VEHICLE_DOCS_DISK=s3
DB_BACKUP_DISK=s3
MYSQLDUMP_PATH=/usr/bin/mysqldump
AWS_ACCESS_KEY_ID=***                        # ssancar IAM
AWS_SECRET_ACCESS_KEY=***
AWS_DEFAULT_REGION=ap-northeast-2
AWS_BUCKET=ssancar-erp-docs                  # ← 분리 버킷 (RRN 격리 필수)

ADMIN_EMAIL=***
ADMIN_PASSWORD=***
BOSS_EMAIL=***
BOSS_PASSWORD=***

# 연동 B/포털 — board(ssancar)와 동일 값 (board 세션 생성). 미설정 시 수신 401(안전밸브).
CAR_ERP_HMAC_SECRET=***
CAR_ERP_READ_HMAC_SECRET=***

# NICE — 이번엔 게이트웨이 이식 X → 기존 Django 게이트웨이 그대로 가리킴(heyman 값 재사용 가능)
NICE_PROVIDE_URL=https://heymancar.com/provide/api/nice-lookup/
NICE_PROVIDE_TOKEN=***
# COMPANY_TEMPLATE_SET 미설정 = system (싼카 법인 공용 양식)
```

## G. APP_KEY (최우선)

```bash
php artisan key:generate                     # ssancar 전용 1회. 즉시 백업, 재실행 금지(RRN 영구손실)
```

## H. 마이그레이션 + 시드 + 권한

```bash
php artisan migrate --force
php artisan db:seed                          # ProductionSeeder(국가·항구·설정·관리자2). 더미 0
php artisan storage:link
sudo usermod -aG www-data ubuntu
sudo chown -R ubuntu:www-data storage bootstrap/cache && sudo chmod -R 775 storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod g+s {} \;     # → SSH 재접속
php artisan config:cache && php artisan route:cache && php artisan view:cache
```
→ ssancar 영업진 31명은 별도 시드/`consignee-import`(바이어38·컨사이니46) — 배포 후. salesmen.email = board(ssancar) 영업 로그인 이메일과 일치.

## 도메인 아키텍처 (Option B 확정 2026-06-26 — car-erp = apex)

| 호스트 | 서빙 | 용도 |
|---|---|---|
| `heymancar.com` **루트 `/`** | car-erp(ssancar) | **ssancar ERP 앱**(로그인·ERP) — apex 자체 |
| `heymancar.com` **`/provide/...`** (apex 경로) | 지금 Django → NICE 이식 후 car-erp | NICE 게이트웨이 = heyman `.env` 고정 URL. **절대 불변** |
| `board.heymancar.com` | board(ssancar) | board(ssancar) 앱 |

- **car-erp 앱 = apex 루트, NICE `/provide/` = 같은 apex의 경로.** nginx 는 긴 prefix 우선 → `/provide/`는 Django, 나머지 `/`는 car-erp. **같은 블록에서 공존**(heyman NICE 무중단).
- **이번 배포**: apex 블록을 **편집**해 root=car-erp 추가 + `/provide/` location 은 Django 그대로 유지(§I). ssancar 자기 차량 NICE 조회는 기존 게이트웨이 URL 호출(`.env` F).
- **NICE 이식(후속)**: apex `location /provide/` 를 Django proxy → car-erp `public/index.php` 로 교체(car-erp 에 포팅 라우트 추가). **heyman `.env` URL 불변, 문제 시 location 한 줄 원복.**
- ⚠️ B 는 apex(=heyman 라이브 NICE 서빙 블록)를 편집하므로 A(서브도메인)보다 신중. §I 안전절차 필수.

## I. Nginx — apex 블록 편집(car-erp root) + board 서브도메인 (⚠️ heyman NICE 무중단)

### I-1. apex `heymancar.com` 블록 편집 (car-erp 를 root 에, /provide/ 는 Django 유지)
> ⚠️ 이 블록이 heyman 라이브 NICE 를 서빙 중. **백업 → 편집 → `nginx -t` → reload → 즉시 NICE 검증** 순서 엄수.
```bash
# 0) 백업
sudo cp /etc/nginx/sites-available/ssancar-erp /etc/nginx/sites-available/ssancar-erp.bak
```
편집 (기존 apex server 블록 안):
```nginx
server {
    server_name heymancar.com;
    # ── heyman NICE: 그대로 Django(gunicorn) — 긴 prefix 라 / 보다 우선 매칭 ──
    location /provide/ {
        proxy_pass http://unix:/ssancar-erp/gunicorn.sock;   # 기존 그대로
        proxy_set_header Host $host; proxy_set_header X-Forwarded-Proto $scheme;
    }
    # ── ssancar car-erp 앱 = apex root ──
    root /var/www/car-erp/public;
    index index.php;
    client_max_body_size 50M;
    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }
    # (기존 certbot listen 443 + ssl_certificate heymancar.com 줄은 유지)
}
```
```bash
sudo nginx -t && sudo systemctl reload nginx
```
**즉시 검증 (heyman NICE 무중단 + 앱):**
```bash
curl -s -o /dev/null -w "provide(Django): %{http_code}\n" https://heymancar.com/provide/api/nice-lookup/   # 기존대로 응답(405/400 등 Django) = 정상
curl -s -o /dev/null -w "root(car-erp):  %{http_code}\n" https://heymancar.com/                            # 302(로그인) = car-erp 정상
```
→ 이상 시 **롤백**: `sudo cp .../ssancar-erp.bak .../ssancar-erp && sudo systemctl reload nginx` (heyman NICE 즉시 원복).
- cert: heymancar.com 은 이미 certbot 됨(Django HTTPS). **새 발급 불필요** — 기존 listen 443/ssl 줄 유지. APP_URL=https://heymancar.com 확인 후 `php artisan config:cache`.

### I-2. board 서브도메인 (board 세션 배포, 참고)
`board.heymancar.com` = **새 블록** + `sudo certbot --nginx -d board.heymancar.com` (신규 cert). DNS A레코드 board → 54.116.7.83.

## J. 큐 워커 (supervisor) + Cron

```ini
# /etc/supervisor/conf.d/ssancar-car-erp-worker.conf  — startretries 높여 MySQL 블립 생존
[program:ssancar-car-erp-worker]
command=php /var/www/car-erp/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true autorestart=true startretries=20 startsecs=5
user=ubuntu numprocs=1 redirect_stderr=true
stdout_logfile=/var/www/car-erp/storage/logs/worker.log
```
```bash
sudo supervisorctl reread && sudo supervisorctl update
crontab -e   # * * * * * cd /var/www/car-erp && php artisan schedule:run >> /dev/null 2>&1
```
→ 매일 03:00 db:backup(로컬+S3). 익일 1건 확인.

## K. S3 (ssancar 전용 버킷)

버킷 `ssancar-erp-docs`(서울·퍼블릭차단·버전관리·SSE) + IAM `ssancar-erp-s3-user`(해당 버킷 ARN만 Put/Get/Delete/List). heyman과 동일 AWS 계정이지만 **버킷·IAM 분리**(RRN 서류 격리). 키 → .env AWS_*.

## L. 검증 + e2e (board 연동)

- [ ] 로그인(관리자) → 대시보드 / 더미 0
- [ ] 서류 출력 → 싼카 법인정보(system 양식)
- [ ] 차량 사진/서류 업로드 → ssancar-erp-docs 버킷
- [ ] **heyman NICE 무영향 확인**: heyman ERP에서 NICE 조회 정상(같은 박스 Django 그대로) — 컷오버 안 했으니 당연하지만 실측
- [ ] 익일 03:00 백업 1건
- [ ] **e2e 연동**: board(ssancar)에서 차 1대 won → car-erp(ssancar) `/api/internal/purchase-sync` 자동 생성 + board `/audit` integration_events 201

## M. 롤백 / 함정

- **롤백(Option B)**: apex 블록 백업 복원 `sudo cp .../ssancar-erp.bak .../ssancar-erp && sudo systemctl reload nginx` → heyman NICE 즉시 원복. (`/provide/` location 만 유지되면 Django NICE 무중단이라, 잘못돼도 그 location 만 남기면 됨.)
- `.github/workflows/deploy.yml`은 heyman 1곳만 자동배포 → **ssancar는 수동 `git pull`**(또는 추후 Production-ssancar job). 매 배포: `git pull && composer install --no-dev && npm ci && npm run build && php artisan migrate --force && php artisan config:cache && supervisorctl restart ssancar-car-erp-worker`.
- APP_KEY 분실 = RRN 복호화 불가. 생성 즉시 백업.
- 시크릿 2개 미설정 시 연동 B/포털 전부 401(의도된 안전밸브) — board와 동일값 확인.
