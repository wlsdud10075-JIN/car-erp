# car-erp AWS Lightsail 배포 기록 (운영)

> 작성일: 2026-05-26 · 최종 갱신: 2026-05-26 (시더 분리 + CHECK 마이그 fix 반영)
> 대상: SSANCAR car-erp ERP 운영 배포. 사용자 ~20명 규모.
> 구성: Lightsail 단일 인스턴스(앱 + MySQL 동거) + S3(업로드 서류 / DB 백업).
>
> 본 문서는 실제 배포를 진행하며 기록한 것(바탕화면 작성분을 저장소로 합병)으로,
> `deployment.md` 런북의 **실행 결과 + 그 과정에서 발견된 이슈/해결**을 담는다.
> 런북(`deployment.md`)은 "어떻게 하는지"(지침), 본 문서는 "실제로 어떻게 됐는지"(기록).
>
> ⚠️ 이 파일은 **dev 전용 .md** — master/운영 트리에는 포함되지 않음(CLAUDE.md 머지 규칙).
> 고정 IP·SSH 키명 등 운영 식별값을 담으므로 private 저장소 dev 브랜치에만 둔다.

---

## 0. 인프라 정보 (확정값)

| 항목 | 값 |
|------|-----|
| 클라우드 | AWS Lightsail |
| 리전 | 서울 (ap-northeast-2), 영역 A |
| 인스턴스 OS | Ubuntu 22.04 LTS |
| 인스턴스 플랜 | 월 $12 (2GB RAM / 2 vCPU / 60GB SSD) |
| 고정 IP | `52.79.200.151` (이름: `new-car-erp-ip`) |
| 프라이빗 IP | `172.26.14.119` |
| SSH 키 이름 | `heymanz` |
| SSH 사용자 | `ubuntu` |
| 프로젝트 경로 | `/var/www/car-erp` |
| 배포 브랜치 | `master` |
| 네트워크 | 듀얼 스택 (IPv4 + IPv6) |

### 방화벽 (Lightsail 네트워킹)

| 애플리케이션 | 프로토콜 | 포트 | 제한 |
|------------|---------|------|------|
| SSH | TCP | 22 | 모든 IPv4 주소 |
| HTTP | TCP | 80 | 모든 IPv4 주소 |
| HTTPS | TCP | 443 | 모든 IPv4 주소 |

- IPv6 방화벽도 동일 규칙 적용("IPv6에 대한 중복 규칙" 체크).
- 자동 스냅샷: 활성화.

---

## 1. 설치된 소프트웨어 스택

| 소프트웨어 | 버전 | 비고 |
|-----------|------|------|
| PHP | 8.4.21 | `ondrej/php` PPA 사용 |
| MySQL | 8.0.45 | 동일 인스턴스에 설치 |
| Nginx | 1.24.0 | |
| Composer | 2.9.8 | |
| Node.js | 22.22.2 | |

### PHP 확장

설치한 확장: `fpm mysql gd zip mbstring xml curl bcmath intl dom simplexml`

> ⚠️ **이슈 기록**: 런북 §1.2는 `php8.4-xml`만 명시했으나, `composer install`이
> `ext-dom` 부족으로 실패했다. `php8.4-dom`·`php8.4-simplexml`을 별도 설치해야 한다.
> 향후 런북 §1.2의 확장 목록에 `dom`, `simplexml`을 추가할 것. (런북 정리 시 반영 — 보류)

```bash
# 확장 설치 (재현용)
sudo apt install -y php8.4-fpm php8.4-mysql php8.4-gd php8.4-zip \
  php8.4-mbstring php8.4-xml php8.4-curl php8.4-bcmath php8.4-intl \
  php8.4-dom php8.4-simplexml
sudo systemctl restart php8.4-fpm
```

---

## 2. 데이터베이스

```sql
CREATE DATABASE car_erp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'car_erp_user'@'localhost' IDENTIFIED BY '***';
GRANT ALL PRIVILEGES ON car_erp.* TO 'car_erp_user'@'localhost';
FLUSH PRIVILEGES;
```

| `.env` 항목 | 값 |
|------------|-----|
| `DB_CONNECTION` | mysql |
| `DB_HOST` | 127.0.0.1 (앱과 MySQL 동거) |
| `DB_PORT` | 3306 |
| `DB_DATABASE` | car_erp |
| `DB_USERNAME` | car_erp_user |
| `DB_PASSWORD` | (별도 보관 — 저장소에 평문 금지) |

> ⚠️ **TODO**: 초기 DB 비밀번호가 단순하다. MySQL이 localhost 전용 접근이라
> 위험도는 낮으나, 운영 안정화 후 강한 비밀번호로 교체 권장:
> `ALTER USER 'car_erp_user'@'localhost' IDENTIFIED BY '새비번';` → `.env` 갱신 → `config:cache`.

---

## 3. APP_KEY (최우선 — RRN 영구 손실 방지)

- `php artisan key:generate`를 **최초 1회만** 실행 완료.
- 생성된 `APP_KEY`(base64:...)는 별도 안전 저장소에 백업 완료.
- `nice_reg_owner_rrn`·`purchase_seller_account`가 이 키로 암호화되므로
  **키 재발급 절대 금지**. 서버 재구축 시 백업해둔 동일 키를 재사용한다.
- deploy.yml은 `.env`를 건드리지 않음(gitignore) → 자동배포 시 키 보존.
- 상세 절차: `docs/operations/key-rotation.md`.

---

## 4. 코드 배포 (런북 §2 수동 1회)

```bash
cd /var/www
sudo git clone https://github.com/wlsdud10075-JIN/car-erp.git
cd car-erp
git checkout master
sudo chown -R ubuntu:ubuntu /var/www/car-erp

composer install --no-dev --optimize-autoloader
npm ci && npm run build

cp .env.example .env
# .env 편집 (§6 참고)
php artisan key:generate          # ★ 최초 1회. 백업 완료.
php artisan migrate --force
php artisan db:seed --force       # ※ 최초엔 더미가 들어갔음 — §9-1 참고 (시더 분리 후 재시딩 필요)
php artisan storage:link
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

> ⚠️ **이슈 기록 (Flux CSS)**: 최초 `npm run build`가
> `Can't resolve '../../vendor/livewire/flux/dist/flux.css'`로 실패.
> 원인은 `composer install`이 `ext-dom` 부족으로 실패해 `vendor/`가
> 아예 생성되지 않은 것. §1의 PHP 확장 설치 → `composer install` 재실행 →
> `npm run build` 순서로 해결. Flux는 무료 버전이라 라이선스 인증 불필요.

---

## 5. 마이그레이션 이슈 — CHECK 제약 (SQLite↔MySQL 차이) ✅ 해결됨

`2026_05_20_000002_add_sale_required_check_to_vehicles` 마이그레이션이
운영 MySQL 8에서 실패하여 이후 20여 개 마이그레이션이 전부 Pending 상태가 됨.

**에러:**
```
SQLSTATE[HY000]: General error: 3823 Column 'buyer_id' cannot be used
in a check constraint 'chk_sale_required': needed in a foreign key
constraint 'vehicles_buyer_id_foreign' referential action.
```

**원인:** MySQL 8은 외래 키에 사용 중인 컬럼(`buyer_id`)을 CHECK 제약에
동시 사용하는 것을 금지한다. 로컬 테스트는 SQLite로 돌았고 SQLite는 이를
허용하므로 그동안 드러나지 않았다. (로컬 dev는 MariaDB라 역시 허용 → 미검출.)

**해결 (완료):** CHECK 제약에서 `buyer_id` 참조 제거, buyer_id 필수 검증은
애플리케이션 레벨(`validateVehicleForm`)로 이관. up() 시작부에 idempotent DROP 추가.
- 커밋: `77b346a`(dev) → `257628d`(master 머지). 서버는 `git pull origin master` →
  `php artisan migrate --force`로 전체 마이그레이션 통과 확인.
- ⚠️ **재마이그 전 데이터 점검**(기존 행이 있을 때): `ADD CONSTRAINT`는 기존 행도 검증 →
  `SELECT id FROM vehicles WHERE sale_price>0 AND (sale_date IS NULL OR exchange_rate<=0);`
  0개여야 안전. (신규 배포라 해당 없음.)

> ✅ **교훈**: 마이그레이션은 SQLite/MariaDB가 아닌 **실제 MySQL 8에서 검증**할 것.
> 외래 키 컬럼을 CHECK 제약에 사용하는 패턴은 MySQL 8에서 금지됨(error 3823).
> 격리 temp DB 검증법은 메모리 `project-db-tier-mismatch` 참조.

---

## 6. .env 운영 설정 (핵심 항목)

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=http://52.79.200.151        # 도메인 연결 후 https 도메인으로 교체 예정 (§9-3)
APP_KEY=base64:***                  # 1회 생성 후 고정 (백업 완료)

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=car_erp
DB_USERNAME=car_erp_user
DB_PASSWORD=***

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
FILESYSTEM_DISK=local

# 운영 관리자 계정 (ProductionSeeder — db:seed 시 생성). §9-1 참고
ADMIN_EMAIL=                        # TODO — 실제 시스템관리자(super) 이메일
ADMIN_PASSWORD=                     # TODO
BOSS_EMAIL=                         # TODO — 최고관리자(admin) 이메일
BOSS_PASSWORD=                      # TODO

# 업로드 서류 → S3 (TODO: 버킷/키 미설정 — §9-2)
VEHICLE_DOCS_DISK=s3
DB_BACKUP_DISK=s3
MYSQLDUMP_PATH=/usr/bin/mysqldump
AWS_ACCESS_KEY_ID=                  # TODO
AWS_SECRET_ACCESS_KEY=              # TODO
AWS_DEFAULT_REGION=ap-northeast-2   # ※ .env.example 기본값 us-east-1 → 서울로 수정함
AWS_BUCKET=                         # TODO

# NICE 차량조회 미들웨어 (§9-6)
NICE_PROVIDE_URL=                   # TODO
NICE_PROVIDE_TOKEN=                 # TODO
```

> `.env.example`에는 `VEHICLE_DOCS_DISK`, `DB_BACKUP_DISK`, `MYSQLDUMP_PATH`,
> `NICE_PROVIDE_*`가 없어 수동 추가했었다. **`ADMIN_*`/`BOSS_*`는 2026-05-26
> `.env.example`에 반영 완료**(시더 분리 작업). 나머지는 런북 정리 시 반영 예정.
> ⚠️ `.env` 변경 후에는 항상 `php artisan config:clear && php artisan config:cache`
> (config:cache 상태에서 시더가 `ADMIN_*`를 못 읽어 관리자 누락되는 함정 방지).

---

## 7. Nginx 설정

`/etc/nginx/sites-available/car-erp`:

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name 52.79.200.151;

    root /var/www/car-erp/public;
    index index.php;

    charset utf-8;
    client_max_body_size 50M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/car-erp /etc/nginx/sites-enabled/
sudo rm /etc/nginx/sites-enabled/default
sudo nginx -t
sudo systemctl reload nginx
sudo systemctl restart php8.4-fpm
```

→ `http://52.79.200.151` 접속 확인 완료.

---

## 8. 파일 권한 이슈 (ubuntu vs www-data)

`sudo chown -R www-data:www-data storage bootstrap/cache` 적용 후,
`ubuntu` 유저로 `php artisan config:cache` 실행 시 권한 거부 발생:

```
"storage/logs/laravel.log" ... Permission denied
"bootstrap/cache/config.php" ... Permission denied
```

**원인:** 웹서버(www-data)용 권한과 CLI 작업(ubuntu)용 권한 충돌.

**해결 (그룹 권한 정리):**
```bash
cd /var/www/car-erp
sudo usermod -aG www-data ubuntu
sudo chown -R ubuntu:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod g+s {} \;
# → SSH 세션 재접속 후 적용됨
```

> ✅ 이후 artisan 명령은 `ubuntu` 유저로 정상 실행 가능.

---

## 9. 미해결 / 다음 작업 (TODO)

### 9-1. 더미 시드 정리 ✅ 코드 완료 / ⬜ 서버 재시딩 필요

**코드 완료 (2026-05-26):** 시더를 환경 분기로 분리. 커밋 `dc47da3`(dev) → `f3feaf8`(master).
- `ProductionSeeder` — 운영 필수만(국가8·항구·설정·관리자 2개). 관리자는 `.env`의
  `ADMIN_*`/`BOSS_*`에서 읽고, 미설정 시 계정별 skip+경고.
- `DemoSeeder` — 더미(바이어/차량50/영업담당자/포워딩/정산 데모 + 로컬 테스트 관리자).
  `app()->environment('local')`일 때만 실행.
- `DatabaseSeeder` = 디스패처: ProductionSeeder 항상 + local에서만 DemoSeeder.
- 상세 계약: 메모리 `project-seeder-contract`.

**서버 작업 (남음):** 최초 `db:seed --force`로 들어간 더미를 제거하고 운영 데이터로 재시딩.
```bash
cd /var/www/car-erp
git pull origin master                 # 시더 분리 코드 받기 (자동배포 미설정 — §9-5)
composer install --no-dev --optimize-autoloader

# .env 에 ADMIN_EMAIL/ADMIN_PASSWORD/BOSS_EMAIL/BOSS_PASSWORD 입력 후:
php artisan config:clear               # ★ env 반영 (config:cache 상태면 필수)

# ⚠️ migrate:fresh 는 전 테이블 DROP. 운영에 실데이터 입력 전(현재)에만 안전.
#    실데이터 입력 후엔 절대 금지 — 대신 더미 행 수동 삭제.
php artisan migrate:fresh --force
APP_ENV=production php artisan db:seed --force   # 더미 없이 마스터+관리자만

php artisan config:cache && php artisan route:cache && php artisan view:cache
# 확인: SELECT COUNT(*) FROM buyers; → 0 / SELECT email,permission FROM users; → 설정한 관리자만
```

### 9-2. S3 설정 (미완)
- S3 버킷 생성 (예: `ssancar-erp-docs`, 서울 리전 ap-northeast-2, 퍼블릭 차단)
- 버킷 lifecycle: `db-backups/` prefix 30~90일 만료
- IAM 사용자 생성 → `s3:PutObject/GetObject/DeleteObject/ListBucket` 권한 (해당 버킷 한정)
- `.env`의 `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` / `AWS_BUCKET` / `AWS_DEFAULT_REGION` 입력
- 입력 후 `php artisan config:clear && php artisan config:cache` 재실행 필수
- 검증: 차량 서류 업로드 → S3 객체 생성 확인 / "기존 파일 보기" URL 정상 (배포후 미검증 항목)

### 9-3. 도메인 + HTTPS (미완)
- 도메인 준비 → 고정 IP `52.79.200.151`로 A 레코드 연결 (IPv6 쓰면 AAAA도)
- Nginx `server_name`을 도메인으로 변경 → `sudo nginx -t && sudo systemctl reload nginx`
- certbot(Let's Encrypt)로 HTTPS 인증서 발급: `sudo certbot --nginx -d 도메인`
- `.env`의 `APP_URL`을 `https://도메인`으로 변경 → `config:clear && config:cache`
- (HTTPS 후) 세션/쿠키 보안 점검

### 9-4. DB 일일 백업 cron (미완)
```bash
crontab -e
# 추가:
* * * * * cd /var/www/car-erp && php artisan schedule:run >> /dev/null 2>&1
```
→ 매일 03:00 자동 `db:backup` (routes/console.php 스케줄). `DB_BACKUP_DISK=s3`면 `db-backups/` 업로드.
- 검증: `php artisan db:backup` 수동 1회 → 백업 파일 + S3 업로드 확인.

### 9-5. GitHub Actions 자동배포 (미완 — 런북 §6)
- GitHub 저장소 → Settings → Environments → "Production" Secrets 등록
  (`DEPLOY_HOST`=52.79.200.151, `DEPLOY_USER`=ubuntu, `DEPLOY_SSH_KEY`(개인키),
  `DEPLOY_PORT`=22, `DEPLOY_PATH`=/var/www/car-erp)
- ⚠️ 최초 수동 배포가 끝난 현재 상태에서 합류 가능. 등록 즉시 master push가 SSH 배포 트리거.
- `deploy.yml`이 `sudo systemctl reload php8.4-fpm` 실행 → ubuntu에 **무패스워드 sudo** 설정 필요:
  `echo 'ubuntu ALL=(ALL) NOPASSWD: /bin/systemctl reload php8.4-fpm, /usr/bin/systemctl reload php8.4-fpm' | sudo tee /etc/sudoers.d/car-erp-deploy`

### 9-6. NICE 차량조회 (미완)
- ssancar-erp 미들웨어 경유 방식(NICE 직접호출 X). `NICE_PROVIDE_URL` / `NICE_PROVIDE_TOKEN` 확보 후 `.env` 입력 → `config:clear && config:cache`.
- 미설정 시 차량 등록 폼은 수동 입력 fallback(정상 동작). 미구현 2건(기통수·검사종료 분할) = `docs/nice-followup-items.md`.

---

## 10. 진행 상태 요약

| 단계 | 상태 |
|------|------|
| Lightsail 인스턴스 + 고정 IP + 방화벽 | ✅ 완료 |
| 소프트웨어 스택 설치 (PHP 8.4 / MySQL 8 / Nginx / Node 22) | ✅ 완료 |
| DB / 유저 생성 | ✅ 완료 |
| 코드 clone + composer + npm build | ✅ 완료 |
| APP_KEY 생성 + 백업 | ✅ 완료 |
| 마이그레이션 (CHECK 제약 이슈 해결 포함) | ✅ 완료 |
| Nginx 설정 + 사이트 접속 (`http://52.79.200.151`) | ✅ 완료 |
| 파일 권한 정리 | ✅ 완료 |
| 시더 운영/더미 분리 (코드) | ✅ 완료 (`f3feaf8`) |
| └ 서버 더미 제거 + 운영 재시딩 | ⬜ 예정 (9-1) |
| S3 설정 | ⬜ 예정 (9-2) |
| 도메인 + HTTPS | ⬜ 예정 (9-3) |
| DB 백업 cron | ⬜ 예정 (9-4) |
| GitHub Actions 자동배포 | ⬜ 예정 (9-5) |
| NICE 차량조회 연동 | ⬜ 예정 (9-6) |

---

## 11. 관련 문서

- 런북(지침): `docs/operations/deployment.md`
- APP_KEY 관리: `docs/operations/key-rotation.md`
- NICE 미구현 2건: `docs/nice-followup-items.md`
- 메모리: `project-deployment` · `project-seeder-contract` · `project-db-tier-mismatch`
