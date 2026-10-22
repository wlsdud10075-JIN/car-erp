# car-erp AWS Lightsail 배포 기록 (운영)

> 최초 작성: 2026-05-26 / 최종 갱신: 2026-06-09 (deploy #7 — §17)
> 대상: car-erp ERP 운영 배포 — **heysellcar 회사용** 인스턴스. 사용자 ~20명 규모.
> 구성: Lightsail 단일 인스턴스(앱 + MySQL 동거) + S3(업로드 서류 / DB 백업).
> 본 문서는 실제 배포를 진행하며 기록한 것으로, `deployment.md` 런북의 실행 결과 + 그 과정에서 발견된 이슈/해결을 담는다.

> **운영 구조 메모**: car-erp는 회사별로 인스턴스·DB·도메인을 개별 운영하는 구조다(멀티테넌트 아님 — 코드 틀만 공유). 이 인스턴스(NEW_CAR_ERP)는 heysellcar 회사용이며, 기존 HEYMAN_ERP 인스턴스를 대체한다. 다른 회사로 확산 시 이 문서가 배포 템플릿이 된다.

> ⚠️ **이 파일은 dev 전용**: CLAUDE.md 규칙대로 `.md`는 dev 브랜치에만 두고 master(운영 서버)에선 제외한다. dev→master 머지 때마다 modify/delete 충돌로 나오면 **삭제 유지**로 해소. 인프라 식별값(IP·SSH 키명 등)을 담으므로 운영 트리에 두지 않는다. 실제 비밀값은 모두 `***` 마스킹.

---

## 0. 인프라 정보 (확정값)

| 항목 | 값 |
|------|-----|
| 클라우드 | AWS Lightsail |
| 리전 | 서울 (ap-northeast-2), 영역 A |
| 인스턴스 이름 | NEW_CAR_ERP |
| 인스턴스 OS | Ubuntu 22.04 LTS |
| 인스턴스 플랜 | 4GB RAM / 2 vCPU / 80GB SSD |
| 고정 IP | `52.79.200.151` (이름: `new-car-erp-ip`) |
| 프라이빗 IP | `172.26.14.119` |
| SSH 키 이름 | `heymanz` |
| SSH 사용자 | `ubuntu` |
| 프로젝트 경로 | `/var/www/car-erp` |
| 배포 브랜치 | `master` |
| 네트워크 | 듀얼 스택 (IPv4 + IPv6) |
| 현재 접속 | `http://52.79.200.151` (도메인/HTTPS 미연결) |

### 방화벽 (Lightsail 네트워킹)

| 애플리케이션 | 프로토콜 | 포트 | 제한 |
|------------|---------|------|------|
| SSH | TCP | 22 | 모든 IPv4 주소 |
| HTTP | TCP | 80 | 모든 IPv4 주소 |
| HTTPS | TCP | 443 | 모든 IPv4 주소 |

- IPv6 방화벽도 동일 규칙 적용. 자동 스냅샷: 활성화.

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

> ⚠️ **이슈 기록 — 런북 §1.2 확장 목록 불충분**: 런북 §1.2는 `php8.4-xml`만 명시했으나,
> `composer install`이 `ext-dom` 부족으로 실패. 이후 S3 패키지 설치 시 `ext-zip`도 CLI에
> 반영 안 돼 실패. 향후 런북 §1.2 확장 목록에 `dom`, `simplexml`, `zip`을 명시할 것.

```bash
# 확장 설치 (재현용)
sudo apt install -y php8.4-fpm php8.4-mysql php8.4-gd php8.4-zip \
  php8.4-mbstring php8.4-xml php8.4-curl php8.4-bcmath php8.4-intl \
  php8.4-dom php8.4-simplexml
sudo systemctl restart php8.4-fpm
# zip 이 CLI 에 안 잡히면: sudo phpenmod zip && sudo systemctl restart php8.4-fpm
```

---

## 2. 데이터베이스

```sql
CREATE DATABASE car_erp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'car_erp_user'@'localhost' IDENTIFIED BY '***';
GRANT ALL PRIVILEGES ON car_erp.* TO 'car_erp_user'@'localhost';
-- ⚠️ mysqldump 백업용 — PROCESS 권한 필수 (§12 이슈 참고)
GRANT PROCESS ON *.* TO 'car_erp_user'@'localhost';
FLUSH PRIVILEGES;
```

| `.env` 항목 | 값 |
|------------|-----|
| `DB_CONNECTION` | mysql |
| `DB_HOST` | 127.0.0.1 (앱과 MySQL 동거) |
| `DB_PORT` | 3306 |
| `DB_DATABASE` | car_erp |
| `DB_USERNAME` | car_erp_user |
| `DB_PASSWORD` | (별도 보관) |

> ⚠️ **TODO**: 초기 DB 비밀번호가 단순. localhost 전용 접근이라 위험도는 낮으나,
> 운영 안정화 후 강한 비밀번호로 교체 권장:
> `ALTER USER 'car_erp_user'@'localhost' IDENTIFIED BY '새비번';`

> 💡 **다른 회사 배포 시**: DB 유저 생성할 때 `GRANT PROCESS ON *.*`를 처음부터
> 함께 줄 것. 안 주면 mysqldump 백업에서 PROCESS privilege 에러가 난다.

---

## 3. APP_KEY (최우선 — RRN 영구 손실 방지)

- `php artisan key:generate`를 **최초 1회만** 실행 완료. 생성된 키 별도 안전 저장소에 백업 완료.
- `nice_reg_owner_rrn`·`purchase_seller_account`가 이 키로 암호화되므로 **키 재발급 절대 금지**.
- deploy.yml은 `.env`를 건드리지 않음(gitignore) → 자동배포 시 키 보존.

---

## 4. 코드 배포 (런북 §2 수동 1회)

```bash
cd /var/www && sudo git clone https://github.com/wlsdud10075-JIN/car-erp.git
cd car-erp && git checkout master
sudo chown -R ubuntu:ubuntu /var/www/car-erp
composer install --no-dev --optimize-autoloader
npm ci && npm run build
cp .env.example .env
# .env 편집 (§6) → php artisan key:generate (1회) → migrate --force
# → db:seed → storage:link → config/route/view cache
```

> ⚠️ **이슈 기록 (Flux CSS 빌드 실패)**: 최초 `npm run build`가
> `Can't resolve '.../vendor/livewire/flux/dist/flux.css'`로 실패. 원인은 `composer install`이
> `ext-dom` 부족으로 실패해 `vendor/`가 생성 안 된 것. PHP 확장 설치 → `composer install`
> 재실행 → `npm run build` 순으로 해결. Flux는 무료 버전, 라이선스 인증 불필요.

---

## 5. 마이그레이션 이슈 — CHECK 제약 (SQLite↔MySQL 차이)

`2026_05_20_000002_add_sale_required_check_to_vehicles`가 운영 MySQL 8에서 실패,
이후 20여 개 마이그레이션이 전부 Pending 상태가 됨.

**에러:** `Column 'buyer_id' cannot be used in a check constraint ... needed in a foreign key constraint`

**원인:** MySQL 8은 외래 키에 사용 중인 컬럼을 CHECK 제약에 동시 사용 금지. 로컬 테스트는
SQLite로 돌아 그동안 미검출. (런북 §7 "테스트는 SQLite라 미검증분" 경고가 현실화.)

**해결:** 로컬에서 마이그레이션 수정 — CHECK 제약에서 `buyer_id` 참조 제거(+ idempotent DROP),
buyer_id 필수 검증은 애플리케이션 레벨로 이관. dev→master 머지 후 서버 재실행하여 전체 통과.
(commit `77b346a`)

> ✅ **교훈**: 마이그레이션은 SQLite가 아닌 실제 MySQL 환경에서 검증할 것.

---

## 6. .env 운영 설정 (핵심 항목)

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=http://52.79.200.151        # 도메인 연결 후 https 도메인으로 교체 예정
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

# 업로드 서류 → S3 / DB 백업 → S3
VEHICLE_DOCS_DISK=s3
DB_BACKUP_DISK=s3
MYSQLDUMP_PATH=/usr/bin/mysqldump
AWS_ACCESS_KEY_ID=***
AWS_SECRET_ACCESS_KEY=***
AWS_DEFAULT_REGION=ap-northeast-2   # ※ .env.example 기본값 us-east-1 → 서울로 수정함
AWS_BUCKET=heysellcar-erp-docs
AWS_USE_PATH_STYLE_ENDPOINT=false

# 운영 관리자 계정 (ProductionSeeder — db:seed 시 생성, §9 코드 분리 반영)
ADMIN_EMAIL=***
ADMIN_PASSWORD=***
BOSS_EMAIL=***
BOSS_PASSWORD=***

# NICE 차량조회 미들웨어 (연동 완료)
NICE_PROVIDE_URL=***
NICE_PROVIDE_TOKEN=***
```

> `.env.example`에는 `VEHICLE_DOCS_DISK`, `DB_BACKUP_DISK`, `MYSQLDUMP_PATH`,
> `NICE_PROVIDE_*`가 없어 수동 추가했다. **`ADMIN_*`/`BOSS_*`는 2026-05-26 `.env.example`에 반영 완료**(§9 시더 분리). 나머지는 향후 반영 권장.
> `.env` 수정 후에는 반드시 `php artisan config:clear && php artisan config:cache` 재실행해야 반영됨.

---

## 7. Nginx 설정

`/etc/nginx/sites-available/car-erp` — document root `/var/www/car-erp/public`,
`php8.4-fpm.sock` 연결, `client_max_body_size 50M`. 기본 사이트(`default`) 비활성화.
→ `http://52.79.200.151` 접속 확인 완료.

---

## 8. 파일 권한 이슈 (ubuntu vs www-data)

`chown www-data` 후 `ubuntu` 유저로 artisan 실행 시 권한 거부 발생.

**해결 (그룹 권한 정리):**
```bash
cd /var/www/car-erp
sudo usermod -aG www-data ubuntu
sudo chown -R ubuntu:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod g+s {} \;
# → SSH 세션 재접속 후 적용
```

---

## 9. 시드 정리 (더미 데이터 제거) — 완료

`db:seed`의 `DatabaseSeeder.php`가 더미 데이터(가짜 바이어/차량/영업담당자/포워딩사)와
운영 필수 데이터(국가/항구/설정/관리자 계정)를 함께 주입하던 문제를 정리.

**운영 결과**: 현재 운영 DB에는 관리자 계정 2개(super 1, boss 1)만 존재. 더미 차량·바이어·
영업담당자 모두 제거됨. 관리자 계정 정보는 운영용으로 교체 완료.

**코드 분리 — ✅ 완료** (로컬 작업, commit `dc47da3` → master `bfddb1b`):
- `ProductionSeeder` — 운영 필수만(국가8·항구·설정·관리자 2개). 관리자는 `.env`의
  `ADMIN_*`/`BOSS_*`에서 읽음(`config/seeding.php` 경유, config:cache 안전). 미설정 시 skip+경고.
- `DemoSeeder` — 더미 일체 + 로컬 테스트 관리자. `app()->environment('local')`일 때만.
- `DatabaseSeeder` = 디스패처: ProductionSeeder 항상 + local에서만 DemoSeeder.
- ⇒ 이제 운영에서 `db:seed` 해도 더미 미유입. 다른 회사 배포 시 `.env` ADMIN_*/BOSS_* 만 채우면 됨.
  (상세 = SKILLS/메모리 `project-seeder-contract`)

---

## 10. NICE 차량조회 연동 — 완료

`.env`의 `NICE_PROVIDE_URL` / `NICE_PROVIDE_TOKEN` 입력 완료. ssancar-erp 미들웨어
(`heymancar.com/provide/api/nice-lookup/`) 경유로 차량조회 연동됨. 인증=토큰 헤더(IP 화이트리스트 불필요 확인).

**⚠️ 로컬 작업 — NICE 응답 정제 fix** (commit `7d095a0`): NICE 조회 성공해도 차량 저장이
폼 검증에 막히던 문제 해결.
- **숫자 제원**(전장/전폭/전고/중량/공차중량/승차인원): NICE가 공백·단위 등 비숫자로 보내
  "숫자여야 함" 검증 위반 → 들여올 때 **숫자만 추출, 없으면 빈 칸**으로 정제.
- **주민(법인)등록번호**: ssancar가 **마스킹**(`XXXXXX-*******`)해 전달 → 형식 검증 실패.
  실제 13자리 형식일 때만 기입, 마스킹이면 **미입력(빈 칸 → 서류엔 수기 입력)**.
  ※ 자동 기입 원하면 ssancar(provide 앱)가 마스킹 없이 반환하도록 그쪽 수정 필요.

---

## 11. S3 연동 — 완료

### 11-1. S3 버킷
- 버킷: `heysellcar-erp-docs` (서울 리전, 퍼블릭 액세스 전체 차단, 버전 관리 활성화, SSE-S3)
- 라이프사이클 규칙 `db-backup-expire`: 접두사 `db-backups/`, 현재 버전 90일 후 만료
  (업로드 서류 `vehicles/`는 규칙 미적용 → 영구 보관)

### 11-2. IAM
- IAM 사용자 `car-erp-s3-user` 생성 (콘솔 액세스 없음, 프로그램 접근 전용)
- 인라인 정책 `car-erp-s3-policy`: `s3:PutObject/GetObject/DeleteObject/ListBucket`,
  리소스는 `heysellcar-erp-docs` 버킷 ARN 한정
- 액세스 키 발급 → `.env`의 `AWS_*`에 입력. 키 CSV 별도 보관.

> Lightsail 인스턴스는 EC2 IAM Role을 못 붙이므로, IAM 사용자 액세스 키 방식 사용 (런북 §4 방식).

### 11-3. 코드 쪽 선결 과제 (해결됨, 로컬 작업)
- **S3 드라이버 패키지 미설치**(`league/flysystem-aws-s3-v3`)였음 — 업로드 시 "driver not found".
  로컬에서 `composer require league/flysystem-aws-s3-v3 "^3.0"` → dev→master → 서버 `composer install`. (commit `41dcefa`)
- **표시 URL = 서명 URL**: 버킷이 퍼블릭 차단이라 `->url()` 은 403. `App\Support\VehicleDocUrl::for()`
  단일 헬퍼로 **private S3면 15분 임시 서명 URL(temporaryUrl), 로컬 public이면 일반 URL** 분기. (commit `41dcefa`)
- ⚠️ 서버에서 직접 `composer require` 하면 deploy.yml의 `git reset --hard`에 덮여 사라짐.
  반드시 git(dev→master)을 거쳐야 함.

### 11-4. 검증 완료
- tinker로 S3 put/exists/delete 테스트 → 전부 `true`
- car-erp 화면에서 차량 등록 → 사진·서류 업로드 → S3 `vehicles/{id}/` 객체 생성 확인
- 사진 표시·우클릭 저장·서류 다운로드 모두 정상 동작 확인

> ⚠️ **TODO (선택)**: S3 폴더 구조가 `vehicles/{차량id}/` 라 S3 콘솔에서 직접 보면
> 어느 차량인지 식별 어려움. 동작상 문제는 없음(DB가 차량↔경로 매핑). 필요 시
> `vehicles/{id}_{차량번호}/` 형태로 코드 변경 가능.

---

## 12. DB 일일 백업 — 완료

### 12-1. cron 등록
```bash
crontab -e
# 추가:
* * * * * cd /var/www/car-erp && php artisan schedule:run >> /dev/null 2>&1
```
→ `db:backup`이 매일 03:00 실행 (스케줄은 `routes/console.php`에 등록됨).

### 12-2. PROCESS 권한 이슈 (해결됨)
- 최초 `db:backup` 시 `mysqldump: Error: ... PROCESS privilege` 발생.
- 원인: `car_erp_user`에 `PROCESS` 권한 없음 (mysqldump tablespaces 덤프 시 필요).
- 해결: `GRANT PROCESS ON *.* TO 'car_erp_user'@'localhost';` → 에러 해소.

### 12-3. 검증
- `php artisan db:backup` 수동 실행 → 로컬 `storage/backups/db/`(약 71KB .sql) +
  S3 `db-backups/` 양쪽 생성 확인.
- ⬜ cron 자동 실행 검증: 익일 03:00 이후 `ls -la storage/backups/db/`로 자동 생성분 확인 필요.

---

## 13. GitHub Actions 자동배포 — 설정 완료

### 13-1. 배포용 SSH 키
- 서버에서 `ssh-keygen -t ed25519 -f ~/deploy_key` 생성.
- 공개키를 `~/.ssh/authorized_keys`에 등록, 개인키는 GitHub Secret에 등록.

### 13-2. GitHub Secrets (저장소 → Settings → Environments → Production)
| Secret | 값 |
|--------|-----|
| `DEPLOY_HOST` | `52.79.200.151` |
| `DEPLOY_USER` | `ubuntu` |
| `DEPLOY_SSH_KEY` | 배포용 개인키 (BEGIN~END 전체) |
| `DEPLOY_PORT` | `22` |
| `DEPLOY_PATH` | `/var/www/car-erp` |

### 13-3. 서버 sudo 설정
deploy.yml의 `sudo systemctl reload php8.4-fpm` 무패스워드 실행을 위해:
```bash
echo "ubuntu ALL=(ALL) NOPASSWD: /usr/bin/systemctl reload php8.4-fpm" \
  | sudo tee /etc/sudoers.d/deploy-fpm
sudo chmod 440 /etc/sudoers.d/deploy-fpm
```

### 13-4. deploy.yml 확인
- `.github/workflows/deploy.yml` 검토 완료. environment `Production`, php-fpm 서비스명
  `php8.4-fpm`, 경로 `/var/www/car-erp` 모두 우리 환경과 일치 — 수정 불필요.
- 배포 스크립트: `artisan down → git reset --hard → composer install → npm ci →
  npm run build → migrate --force → 캐시 3종 → storage:link → php-fpm reload →
  queue:restart → artisan up`.
- ⚠️ 배포 중 `artisan down`으로 1~3분 점검 화면 노출됨(무중단 아님). 업무 시간 외 배포 권장.

### 13-5. 첫 자동배포 — ✅ 검증 완료 (2026-05-26)
- NICE fix 코드(`3b8b051`)를 dev→master 머지(`8f82448`)·push 한 것이 **첫 자동배포**였고
  **성공**: GitHub Actions `deploy #6`, 커밋 `8f82448`, **20초** 만에 초록 완료.
- 즉 master push → GitHub Actions → 서버 SSH 배포(`git reset --hard` → composer/npm →
  migrate → 캐시 → fpm reload → up) 파이프라인 **정상 작동 확인**.
- 이후로는 **dev→master 머지만 하면 자동 배포** (수동 `git pull` 불필요).

---

## 14. 미해결 / 다음 작업 (TODO)

### 14-1. 도메인 + HTTPS — ✅ 완료 (2026-06-11)
`https://heysellcar.com` + `https://www.heysellcar.com` 운영 라이브.

**전환 절차 (실제 수행)**:
1. **Lightsail 콘솔에서 도메인 이전** (heysellcar.com이 Lightsail DNS 관리 → 등록대행사 작업 불필요):
   - 구 **HEYMAN_ERP**(`3.34.215.235`) 도메인탭 → `heysellcar.com`·`www` 할당 🗑 해제.
   - **NEW_CAR_ERP**(`52.79.200.151` 고정IP) 도메인탭 → 도메인 할당: apex + www, **고정 IP 주소(IPv4)** 선택. (IPv6 AAAA는 미설정 — certbot IPv6 검증 함정 회피)
   - 방화벽 IPv4·IPv6 모두 80·443 열림 확인.
2. **서버**: nginx `server_name`에 `heysellcar.com www.heysellcar.com` 추가(IP 유지) → reload → `apt install certbot python3-certbot-nginx` → `certbot --nginx -d heysellcar.com -d www.heysellcar.com --redirect` (Let's Encrypt, 만료 2026-09-09, **자동갱신**, http→https 301 자동) → `.env` `APP_URL=https://heysellcar.com` + `SESSION_SECURE_COOKIE=true` → `php artisan config:cache`.

**교훈/주의**:
- **리디렉트 루프 없음** = nginx가 fastcgi 직결(`location ~ \.php` 에 `include fastcgi_params` → `fastcgi_param HTTPS $https`)이라 Laravel이 https 자동인식. **TrustProxies/forceScheme 불필요**(코드 무변경). 단 추후 **Cloudflare/LB를 앞에 두면 그땐 TrustProxies + forceScheme 필요**.
- 순서 = DNS→cert→APP_URL/secure쿠키. **cert 동작 확인 후에** APP_URL을 https로(전에 바꾸면 깨짐). SESSION_SECURE_COOKIE도 cert 후(전에 true면 http 로그인 깨짐).
- 자동배포(dev→master)는 `.env`/nginx 안 건드리고 `config:cache`만 → **https 유지**. APP_KEY 불변(RRN 안전).
- 구 HEYMAN_ERP(`3.34.215.235`)는 도메인에서 떨어짐(사용자: 죽어도 OK). `http://52.79.200.151` 직접접속도 계속 가능.
- nginx 설정 `.bak.YYYYMMDD_HHMMSS` 백업 보존.

### 14-2. 런북 §7 기능 점검 (일부 미완)
- ✅ 차량 등록, 서류·사진 S3 업로드/표시/다운로드
- ✅ NICE 조회 후 저장(숫자 제원·마스킹 RRN 정제 fix 적용)
- ⬜ 차량 force-delete 시 `vehicle_photos` FK cascade (런북이 "SQLite 미검증분"으로 지목)
- ⬜ 서류 9종 + 다중차량 선적 다운로드
- ⬜ 익일 03:00 후 자동 백업 1건 생성 확인 (cron 동작 검증)

### 14-3. 시더 코드 분리 — ✅ 완료 (§9 참조)
ProductionSeeder / DemoSeeder 분리 + 환경 분기 코드 완료(commit `dc47da3`). 운영 재시딩 절차는 §9.

### 14-4. 기타 보안 정리 (선택)
- MySQL 비밀번호 강화 (현재 단순, localhost 전용이라 위험도 낮음)
- 루트 계정 액세스 키 비활성화/삭제 (IAM 대시보드 보안 경고)
- 자격증명(AWS 키·DB 비번·NICE 토큰)을 비밀번호 관리자에 통합 보관
- 채팅 등에 노출된 NICE 토큰은 운영 안정화 후 ssancar에서 재발급 고려

---

## 15. 진행 상태 요약

| 단계 | 상태 |
|------|------|
| Lightsail 인스턴스 + 고정 IP + 방화벽 | ✅ 완료 |
| 소프트웨어 스택 (PHP 8.4 / MySQL 8 / Nginx / Node 22) | ✅ 완료 |
| DB / 유저 생성 (+ PROCESS 권한) | ✅ 완료 |
| 코드 clone + composer + npm build | ✅ 완료 |
| APP_KEY 생성 + 백업 | ✅ 완료 |
| 마이그레이션 (CHECK 제약 이슈 해결 포함) | ✅ 완료 |
| Nginx 설정 + 사이트 접속 (`http://52.79.200.151`) | ✅ 완료 |
| 파일 권한 정리 | ✅ 완료 |
| 더미 시드 정리 (+ 코드 분리 ProductionSeeder/DemoSeeder) | ✅ 완료 |
| NICE 차량조회 연동 (+ 응답 정제 fix) | ✅ 완료 |
| S3 연동 (버킷·IAM·드라이버·서명 URL·검증) | ✅ 완료 |
| DB 백업 cron | ✅ 완료 (자동 실행 검증은 익일) |
| GitHub Actions 자동배포 | ✅ 완료·검증됨 (deploy #9, `f6028ef` — §19. 마이그 동반 첫 배포, SoftDeletes deleted_at 운영 적용) |
| 도메인 + HTTPS | ✅ 완료 (2026-06-11, `https://heysellcar.com` + www, Let's Encrypt 자동갱신) — §14-1 |
| 런북 §7 기능 점검 (잔여분) | ⬜ 예정 |

---

## 16. 관련 문서

- 런북(지침): `docs/operations/deployment.md`
- APP_KEY 관리: `docs/operations/key-rotation.md`
- NICE 미구현 2건: `docs/nice-followup-items.md`
- 메모리: `project-deployment` · `project-seeder-contract` · `project-db-tier-mismatch` · `project-review-md-remediation`

---

## 17. deploy #7 — Review.md 멀티에이전트 리뷰 보안·회계 수정 (2026-06-09)

바탕화면 `Review.md`(7부서 멀티에이전트 코드리뷰) 검증 후 보안·자금 직결 항목 수정 → 운영 배포.

**반영 항목** (master `b4ec780`→`91595bf`):
- #3 문서 다운로드 RRN IDOR — `VehicleDocumentController` 소유권 가드 (`User::canScopeVehicle` 단일출처)
- #4 차량 `delete`/`save(편집)` 스코프 재인가
- #2 영업 cashflow `$salesmanId` `#[Locked]`
- #1 paid 정산 무가드 삭제 차단 (`Settlement::deleting`)
- #6 매입미지급 KPI `confirmed_at` 필터 누락 보정
- #7 `deploy.yml` 안전화 — 빌드를 `down` 이전 + `trap 'artisan up' EXIT`(점검모드 갇힘 방지). **이번 배포부터 적용**
- #8 CI 트리거 브랜치명 `develop/main`→`dev/master` 교정

**배포 방식**: master가 dev와 분기(`.md` 제외 관리)돼 있어, 격리 `git worktree`에서 7커밋 **cherry-pick(`.md` 0건 누출, code-only)** 후 `deploy-tmp:master` fast-forward push. 단순 merge는 그동안 제외한 `.md` 수십 개를 운영 트리로 끌고 오므로 금지 — cherry-pick이 `.md` 제외를 자동 보장.

**검증**: GitHub Actions **deploy/tests/lint 3개 워크플로우 전부 success** (tests=SQLite 풀스위트 통과, 2m52s / lint=pint 통과, Flux secrets 이미 설정됨). 배포 직후 `/up`=200·루트=302. 로컬 544 테스트 통과.

**남은 보류**: #5 환율0(운영 CHECK가 이미 방어, 미수정) / #8 후속 CI MySQL 8 서비스 컨테이너 / #1 SoftDeletes(글로벌 스코프 영향, 추후).

---

## 18. deploy #8 — Review2(2차검증) 항목 A감사·D·C (2026-06-09)

바탕화면 `Review2.md`(2차 검증 + 사외이사 교차검증) 반영. 검증리포트2 = `car-erp-검증리포트2.html`.

**반영 항목** (master `91595bf`→`22a2d3e`):
- **A(감사 편입)** — `Settlement::AUDITED_COLUMNS`에 `settlement_ratio`·`per_unit_amount`·`other_deduction` 추가. paid 이후 금액 수동 조정을 audit_logs에 기록. ⚠️ **immutable hard-lock은 미채택** — 사내직원 차등정산(총마진 ≤100만=10만/≥100만=20만/>1억=25%)을 수동 운용하는 게 의도된 정책이라 잠그면 충돌(잠그지 않고 추적만).
- **D(배포 안전화 정제)** — `deploy.yml`: trap 제거 → 단계별 명시 처리. 빌드 실패=사이트 유지 / **migrate 실패=점검모드 유지+에러+exit**(half-migrated 서빙 방지) / 성공=up. **배포 직전 `db:backup` 스냅샷**(복구점). 실측: 이번 배포에서 백업 `car_erp-20260609_165618.sql`(241KB) 생성 확인.
- **C(MySQL CI)** — 전체 스위트는 SQLite 전용 코드(PRAGMA 30파일)라 유지하고, `tests.yml`에 **별도 `mysql-check` 잡** 추가(MySQL 8 서비스 → `migrate` 운영 동형 검증 + `MysqlCheckConstraintTest`로 chk_sale_required 검증, #5 방어 CI 보증). mysql-check 잡 success 확인.

**검증**: deploy/tests(ci+mysql-check)/lint 3워크플로우 success. `/up`=200. 로컬 545 통과+1 skip(MySQL 전용).

**남은(낮음)**: B(Settlement SoftDeletes) / E(이월 stranding·환율실패 close 강행) / 조사2건(권한변경 세션무효화·종결상태 동시성). #5는 C(mysql-check)가 검증 커버.

---

## 19. deploy #9 — Review2 항목 B·E (2026-06-09)

**반영 항목** (master `22a2d3e`→`f6028ef`, **마이그레이션 동반 첫 배포**):
- **B(SoftDeletes)** — `settlements.deleted_at` 마이그(`2026_06_09_000001`) + `Settlement use SoftDeletes`. pending/calculating 삭제는 복구 가능(withTrashed), deleting 가드는 confirmed/paid/closed 여전히 차단. 데모·임포트는 forceDelete() 사용이라 무영향.
- **E1(외화 close 가드)** — `closeSecondarySettlement`: 외화 차량 환율 조회 실패 시(calculateExchangeDifference null) 마감 차단. null 환차로 잠겨 환차익/손 영구 누락되던 문제 해소. KRW는 환율 없이도 마감.
- **E2(이월 리포트)** — `settlements:carryover-report {--stranded}` 명령. 영업담당자별 미흡수 이월 가시화(퇴사자 stranding ⚠ 마킹). 미흡수분 처리(상계/지급/소멸)는 운영 정책 결정(읽기 전용).

**D 경로 실증** (마이그 동반 첫 배포): db:backup `car_erp-20260609_205111.sql`(244KB) → down → migrate `add_soft_deletes_to_settlements 33.43ms DONE` → up → /up=200. mysql-check 잡이 새 마이그를 MySQL 8 적용 사전 검증.

**검증**: deploy/tests(ci+mysql-check)/lint 3워크플로우 success. 로컬 550 통과+1 skip. 테스트 5건 추가.

**Review/Review2 잔여**: A hard-lock(차등 수동운용 정책상 미채택) · E2 auto-sweep(정책 미정) · 조사2건(세션무효화·동시성, 낮음)뿐. 실행 항목 사실상 전부 완료.

## 20. deploy #10 — 연동 B 수신측 (board → car-erp purchase-sync) (2026-06-15)

**반영** (master `53f2cd1`→`5d127d2`, cherry-pick 3커밋 `5f97271`·`17edf53`·`5d127d2`, 마이그레이션 동반):
- `POST /api/internal/purchase-sync` — board(매입보드)가 won 낙찰차를 car-erp 매입 재고로 단방향 push 수신. HMAC(`X-Board-Signature`) + vehicle_number 멱등 + 영업 매칭 + NICE(vehicle_number+owner_name)로 VIN 채움 + payee→매입탭 정산계좌(암호화) + 기본 기타비용 자동기입.
- 신규 컬럼 마이그 `2026_06_15_000001_add_purchase_sync_columns_to_vehicles`(purchase_source·c_no, additive nullable).
- 수신 스펙(권위) = `docs/integration/purchase-sync-receiver.md`. 구현·계약·확정사항 전부 거기.

**시크릿 세팅** (2026-06-15): 운영 car-erp `.env CAR_ERP_HMAC_SECRET` 교체 + `config:cache` 완료 (값은 .env/AWS 폴더에만 보관 — 문서에 평문 금지). `.env.bak.hmac-20260615-124804` 백업 존재. APP_KEY 미변경.

**안전밸브 상태**: car-erp 수신측은 켜졌으나 **board 운영 `.env`(CAR_ERP_BASE_URL + CAR_ERP_HMAC_SECRET) 세팅 전까지 데이터 안 흐름**(board Job no-op). 양쪽 시크릿 일치 시 활성.

**⏭️ 다음 세션 (내일 이어서)**:
1. **board 운영 서버 `.env`** 세팅 (board 세션·board repo) — `CAR_ERP_BASE_URL=https://heysellcar.com` + `CAR_ERP_HMAC_SECRET`(car-erp와 동일) + `config:cache`.
2. **운영 end-to-end**: board에서 차 하나 won → 운영 car-erp 자동 생성 확인 (vehicle_id 응답·담당자 매칭·기본비용).
3. board가 보내는 `salesman_email`은 **car-erp 등록 영업 이메일**이어야 매칭(아니면 `car_erp_salesman_id`). board 계정 이메일(admin@board.test 등)과 다름 — 로컬 e2e에서 확인된 식별자 정책.
4. (선택) 시크릿을 랜덤 강한 값으로 교체 — 양쪽 동시 + 각자 config:cache.

## 21. 운영 PHP 업로드 한도 2M→40M (2026-06-19, 배포 아님·서버 설정)

**증상**: 운영(heysellcar.com)에서 차량 사진 업로드 시 **1.5MB는 되고 2MB·3MB는 "photoUpload 업로드에 실패" / 조용히 누락**. 로컬(8001)은 40M라 정상. 앱 검증은 `max:10240`(10MB)·nginx `client_max_body_size 50M`라 둘 다 원인 아님.

**원인**: 운영 PHP-FPM `php.ini`가 **기본값 `upload_max_filesize=2M`·`post_max_size=8M`**. 정확히 2M 선에서 잘림. (사진뿐 아니라 2MB↑ 모든 첨부·서류 동일 차단이었음.)

**조치**: `/etc/php/8.4/fpm/php.ini` → `upload_max_filesize=40M`·`post_max_size=40M`(로컬과 동일) 후 php-fpm 재시작.

**⚠️ 함정(실측)**: `sudo systemctl restart php*-fpm` 의 **glob 이 실제 유닛 `php8.4-fpm.service` 를 매칭 못 해 no-op**(그런데 exit 0·"OK" 출력). php.ini 는 40M인데 FPM 마스터는 부팅 시점 2M 유지 → "파일은 40M·동작은 2M". **정확 유닛명 `sudo systemctl restart php8.4-fpm.service` 로 재시작**해야 반영. 검증 = webroot 임시 `phpinfo` 프로브를 **https 경로(`curl -k --resolve heysellcar.com:443:127.0.0.1`)**로 찍어 `ini_get` 확인 후 즉시 삭제(http 는 301 리다이렉트라 값 못 봄).

**교훈**: ① ubuntu 사용자(Bitnami 아님), PHP 8.4. ② 서버 ini 변경 후 **반드시 실 HTTP 경로로 적용값 검증**(파일 grep 만으론 마스터 캐시 못 잡음). ③ glob 유닛 재시작 금지 — 정확 유닛명.

## 22. deploy #11 — board 영업포털 (Phase A/B/C + 선적요청 후단) (2026-06-19)

**반영** (master `8d4dc98`→`4d0ccac`→`8471990`, 자동배포 2회 success):
- board 읽기 API(`VerifyBoardReadHmac`·`SalesmanResolver`·`InternalPortalController` finance/receivables/sales/purchases/settlements/**by-buyer**) + 선적요청(`ShippingRequestController` shippable/store/cancel) + 서류 프록시(선적4종) + **선적요청 화면**·차량관리 ids 딥링크·**사이드바 7그룹 트리**.
- 마이그 3종 Ran: `create_shipping_requests_table`·`add_board_source_to_document_access_logs`·`add_batch_id_to_shipping_requests`(additive, 운영 데이터 없음).
- 권위 스펙 = `docs/integration/board-portal-api.md`.

**⚠️ 배포 함정(실측, 재발방지)**: 1차 push 가 **stale `origin/dev`** 머지라 세션 9커밋 누락(by-buyer·사이드바·batch_id·선적요청 화면 전부 빠짐) → 운영 검증서 발견(`git show origin/master:...|grep`) → `git push origin dev` 후 재머지·재배포(`8471990`)로 복구. **교훈: dev→master 전에 반드시 `git push origin dev` 먼저(origin/dev 최신화) — 로컬 dev가 origin보다 앞서면 머지가 옛 코드 배포.**

**시크릿**(2026-06-19): 운영 `.env CAR_ERP_READ_HMAC_SECRET` 설정 + `config:cache`. 서명요청 검증 = 403(HMAC통과·영업없음)로 동작 확인. `.env.bak.readsecret-*` 백업. ⚠️ **값이 약함 → 강한 랜덤으로 교체 권장**(board 세팅과 동시). board 운영 `.env`에 동일값 + `CAR_ERP_BASE_URL=https://heysellcar.com` 설정 시 연동 활성.

## 23. deploy #12 — 통관SET 서류 보강 (2026-06-19)

**반영** (master `8471990`→`169707e`, 자동배포 success):
- ① 차량등록증 자동차등록번호 `reg_cert_number` 컬럼 + 기본정보 탭 2열 입력 → ClearanceSet G3(한글/영문등록증).
- ③ 구매리스트 I6 차종 영문변환 수식 교정(템플릿 "중형승용"→"승용 중형" 실데이터 매칭, system+karaba 바이너리).
- ⑤ 차량인보이스 A3 상호 첫 줄 = 기능설정 브랜드 대문자(DocumentFiller brandHeader).
- 마이그 `add_reg_cert_number_to_vehicles` [14] Ran. 사이트 302 정상.
- ④ H13 연비는 미반영(jin 미들웨어 연비 조사중). ⑤ 다른서류 확장 보류.

## 24. deploy #13 — 양식 유령열 + 도장/서명 오버레이 + 연동 B v2 첨부 + 연비 (2026-06-22)

**반영** (master `169707e`→`0f6b657`, 자동배포 트리거). dev→master = **.md 제외 path cherry**(23 코드파일, `git checkout origin/dev -- . ':(exclude)*.md'`).

- **매입 3종 양식 유령 열 제거**: 말소신청서·말소계약서·위임장이 256열마다 반복되는 `<col>` 잔재(→16384열)라 다운로드 시 PhpSpreadsheet 가 펼쳐 시트범위 폭발(A1:WVX)→엑셀 "복구"→열너비 리셋→**정렬 흐트러짐**. `scripts/strip-phantom-columns.py`(zipfile 로 `<cols>`만 외과수술, 이미지·서식 byte 보존) system+karaba 6파일.
- **도장·서명 업로드 오버레이**: 기능설정 "도장·서명"(super)에서 회사(`template_set`)별 서명/직인 업로드 → `DocumentFiller::applyStamps` 가 생성 시 양식 앵커에 오버레이(원본비율 fit·PNG 투명 보존·선적 removeRow 동반이동). 미업로드=양식 기본 유지. 직인 목표 160pt(jin 실측). 6서류(말소계약서 A60·판매인보이스 B36·컨/로로 계약서 B59·선적인보이스 H115/H55). 회사정보는 이미 텍스트 셀이라 합성블록 제거해도 유지. ⚠️ karaba 가 SSANCAR 직인 찍던 버그 → karaba 직인 업로드로 해소.
- **연동 B v2 — 차량 첨부(사진/서류) 수신**: board 가 `won→synced` 시 `attachments[]`(공유 S3 키) 전송 → `vehicle_photos`(기본정보탭, 최대10·결정적 target dedup) 연결. S3=(B)서버사이드 복사. **소스디스크 분리**(`config filesystems.purchase_sync_inbound_disk`, 운영 미설정=같은 s3 서버복사 / 로컬 `board_inbound`+`BOARD_STORAGE_PATH` 브리지). `contract_version` 1·2 수용(전방호환). 승인=purchase-sync 위 신규변경(대표영역)이나 대표부재+근거로 **Jin 권한 진행**(문서 명시). 권위=`docs/integration/purchase-sync-receiver.md`. ⚠️ **board 송신(v2)은 board 세션에서 배포 필요**(car-erp 받는쪽만 완료). 로컬 e2e 확인됨(board 브리지).
- **연비**(NICE `fuelCnsmpRt`) 매핑 — 보류분 함께 배포(저위험: NICE 미전송 시 빈값 graceful).
- **마이그레이션 없음**(스키마 무변경 — vehicle_photos 재사용). 전체 **673 통과**.

## 25. deploy #14 — 매입/판매 잔금 검증 메시지 한글화 (2026-06-22)

**반영** (master `0f6b657`→`cc7eb96`, 자동배포). 운영서 매입 잔금 입금일 누락 시
검증 메시지가 `purchaseBalancePayments.0.payment_date은(는) 필수…` 로 **영어 필드명 노출**.
원인 = `:attribute` 한글 라벨 미매핑. `$attributes` 에 매입/판매 잔금 4개(amount·payment_date)
라벨 + `vehicle.attr` 키(ko/en) 추가 → "매입 잔금 입금일은(는) 필수 입력 항목입니다."
마이그 없음. (검증 동작 자체는 정상 — 입금일 필수는 미지급 계산 정확성 C2.)

## 26. deploy #15 — 정산 데이터 정리 (import 일괄생성 146건 삭제) (2026-06-22)

**반영** (master `cc7eb96`→`7c2b4f8`, 자동배포, 코드 2파일만). 운영 정산 146건이 전부
`paid+closed`(ratio 50%)로 잘못 적재된 문제 정리. 원인 = `vehicles:import --with-payments`
가 판매가>0 전 차량에 CK(정산여부) 무시하고 정산 일괄 생성 → 진행중 차량까지 회계잠금
(96더5119 등 환율/판매가 수정 불가). 마이그 없음.
- `settlements:purge-import [--apply]` 신설 — import 정산(note=`import — %`) forceDelete +
  refreshCaches, dry-run 기본. 입금이력 보존. `scripts/audit-settlement-ck.php`(xlsx CK 대조).
- 실행: 사전 `db:backup`(car_erp-20260622_171207.sql 799KB + S3) → dry-run 146건 →
  `--apply` 146 삭제·캐시 146대 재계산·정산 잔여 0. **96더5119 회계잠금 해제 검증 완료.**
- 다음: Part B(사내직원 차등 tier) 구현 후 CK='정산' 101대(G2 71+G4 30) 올바른 금액 재산정.
  핸드오프 = `docs/operations/settlement-data-remediation-2026-06-22.md`.

## 27. deploy #16 — 서류/통관 보강 + 회사 양식 토글 + 도장 XY 씬 (2026-06-23)

**반영** (master `7430179`→`4eb9777`, 자동배포 success 31초, heysellcar.com 200). dev 7커밋
cherry-pick(.md 제외). 마이그 없음.
- 차량 첨부 미리보기 라이트박스(이미지·PDF).
- 통관SET: 컨사이니 블록에 이메일·전화·담당자 추가, 구매리스트 D14=B14(컨사이니 미러),
  연비 H13 매핑(NICE fuelCnsmpRt→nice_spec_fuel_efficiency→등록증 F31 cascade), 정부직인 보존
  (등록증/말소증 빨간직인=대한민국 공인직인, 회사도장 슬롯에서 제외), 대한민국/Republic of Korea
  텍스트 복원 + 등록증/말소증 Gridlines OFF.
- **회사 양식 세트 토글**(기능설정 super): SSANCAR/HEYMAN/KARABA. `Setting::companyTemplateSet()`
  단일출처(DB 토글>.env fallback). `resources/templates/heyman/` 9종 신설(jin _HEYMAN rebrand 11셀+
  A1 HETMAN오타교정, 은행정보 HEYMAN/180-012-458342). `scripts/generate-heyman-templates.php`.
- **도장/서명/로고 업로드 + 슬롯별 XY위치·크기 조정 씬**: `StampSlots` 레지스트리(서류별 슬롯·서브시트),
  `applyStamps` 재작성(role 이미지 재사용+`stamp_pos_*` offset), 로고 역할 추가, 도장 미리보기 S3
  서명URL(`VehicleDocUrl`). 선적 도장이 fillMulti removeRow 트림 따라 이동 검증(단일/다중).
- NICE 조회 성공/실패 토스트 + 미들웨어 실제 사유 노출(E901 소유주명 불일치 등). 기존 "HTTP 400"만 뜨던 것.

## 28. deploy #17 — 미입금 우회 선적/B/L 분리 + 도장 미리보기 S3 수정 (2026-06-23)

**반영** (master 자동배포, dev `3f362c5`·`911863c` cherry-pick). **마이그 있음**(enum 'bl' 추가).
- 미입금 우회 stage 분리: `'bl'`(B/L 발행 G1 100%) 신설, `'dhl'` 폐기. 선적 우회('shipping', C5 50%)
  만으론 B/L 발행 안 뚫림 → 별도 'bl' 승인. enum 비파괴 마이그('dhl' 보존, 운영 override 0건 확인).
  승인 UI=통관 진입/선적 진입/B/L 발행. 상세=메모리 `project-bl-gate-100-decision`.
- 도장 미리보기 운영 S3 깨짐 수정(`VehicleDocUrl` 임시 서명URL). 업로드·서류 반영은 원래 정상이었음.
- 전체 684 테스트 통과(분리 검증 신규 포함).

> ⚠️ heyman 운영 적용 잔여: 기능설정에서 회사=HEYMAN 선택 + heyman 직인/서명/로고 업로드·XY조정
>   (회사 토글·도장은 DB 데이터라 서버별 — 로컬 설정 운영 미전파).

## 29. ssancar·karaba PHP 업로드 한도 2M→40M 소급 (2026-07-18, 배포 아님·서버 설정)

**증상**: karaba(karaba-erp.com)에서 수출신고서·차량사진 업로드가 될 때 안 될 때 반복 + "미첨부" 모달. §21(heyman 2026-06-19)과 **동일 원인**.

**원인**: §21 fix가 heyman 서버에만 수동 적용됐고 **ssancar·karaba 로 전파 안 됨**(karaba 2026-06-14·ssancar 2026-06-27 구축, §21은 6/19). 읽기전용 확인 결과 **ssancar·karaba 둘 다 `upload_max_filesize=2M`·`post_max_size=8M`**(우분투 기본). heyman 은 40M(정상). 2MB↑ 파일이 PHP-FPM 레벨에서 잘려 Livewire 훅 미발동 → 앱은 "미첨부"로 정직 표시(코드 버그 아님).

**조치 (2대)**: `/etc/php/8.4/fpm/php.ini` → `40M/40M` (백업 `.bak-20260718`) → `sudo systemctl restart php8.4-fpm.service`(정확 유닛명) → **HTTPS phpinfo 프로브로 런타임 실측 검증**(`upload=40M post=40M` 확인) → 프로브 삭제. 두 대 모두 성공.

**재발방지**: `scripts/karaba-1-stack.sh` 에 php.ini 40M sed 추가(신규 서버 자동) + 본 체크리스트에 항목 추가. ssancar(대물량) 정식 배포 시에도 이 스크립트 경로면 자동 반영.

## 2026-09-24 — ssancarerp 서버 이전 (8GB → 4GB, 배포 아님·인프라)

구 인스턴스(8GB, ip-172-26-0-226) → 새 `New_Ssancar_Erp`(4GB/2vCPU/80GB, ip-172-26-11-155). **고정 IP `54.116.7.83` 재할당**이라 도메인·DNS·NAS·`DEPLOY_HOST`·NICE 화이트리스트 무변경. 다운타임 ≈6분. WireGuard(`wg-carmodoo`)·Tailscale(`ssancarerp-server-new`)·letsencrypt·supervisor 2·cron 3·백업 스크립트 3 이식. 구 Django(`ssancar-erp.service`)는 새 서버에 없음(`/provide/` 잔여 경로 → 404, nice-lookup 은 PHP). GH `deploy-ssancar` 재실행 success(sha `7bf9279`). 계획·정정·실행 = `ssancarerp-server-migration-runbook.md` §2-B · `ssancarerp-server-migration-commands.md` 「실행 후기」. 구 인스턴스는 점검모드 정지 → **10/1 이후 삭제**.

## 2026-10-06 (2차) — 정산씬 개편 9커밋 배포 `3405967d` (3사 동시)

- **내용**: 기본급만 줄(`a09a0e8a`) · 귀속월 드롭박스(`c33c795d`) · 미수 있어도 2차 마감 + 마감 차량 미수 남은 동안 신규 잔금 열림(`985bc06a`) · 「2차 가능/비용 대기」 + 매입취소 정산 단계(`575ef506`) · 2차 차액 분해·엑셀(`05bd7a9c`) · 목록 「상태/2차 정산/작업」 3칸(`f55934c0`) · 메모·문구(`573ff9b2`) · **미청산 이월 → 월배치 자동 조정 줄**(`1c574257`, 후속 `0d2d4e3e`). master cherry-pick 9개, dev 와의 앱 코드 잔차 = 미승인 `NotifySend.php` 1건.
- **검증**: 로컬 전체 3,190 통과·실패 7 = GD. deploy 런 `37434431147` 잡 5개 success(tests/ci · mysql-check · deploy · deploy-ssancar · deploy-karaba). 3사 sha `3405967d` 일치 · 마이그 3개 Ran · maintenance OFF · login 200 ×3(외부 curl; ssancarerp 서버 내부 자기 도메인 curl 은 000 = 루프백) · 배포 후 ERROR 0.
- **배포 뒤 작업**: `settlements:backfill-secondary-ready --apply` — ssancarerp 431 → **2차 가능 46 · 비용 대기 385**(재실행 0건) · heymanerp 61 → 0 · karabaerp 0 → 생략.
- **운영 수동(같은 날, 승인·감사로그)**: ssancarerp 정산 #6628(14더3753)·#6676(383오6259) 귀속월 07→09 · 66더1784 매입취소 손실 반영 도장(9/10 배치 #2 승인시각).
- **다음 배치(10/10)에서 볼 것**: 제출 모달 「미청산 이월 자동 반영」 줄(ssancarerp 6명, −460,679 중 − 는 그 달 지급액까지만). 실무자에게 **이월 수동 조정 중복 금지** 안내.

## 2026-10-07 — 월배치 승인요청 재전송 버튼 + 발송 뱃지 `e5350d88` (3사 동시)

- **내용**: 월배치 승인 대기 카드에 「📨 승인요청 재전송」(관리·업무관리자만, 현재 승인 계단에게만, 링크 새로 생성, 10분 잠금, 감사로그 `payout_request_resent`) + 버튼 옆 뱃지 초록 「발송성공」/빨강 「발송실패」. 알림톡 템플릿 무변경. 컬럼 `settlement_payout_batches.request_notified_at` 1개. cherry-pick 5(`945d330c` `da993efd` `bce717de` `bc74d84f` `3f981436`).
- **검증**: deploy 런 `37565138200` 잡 5개 success · 3사 sha `e5350d88` · 마이그 Ran · maintenance OFF · login 200 ×3 · 오늘 ERROR 0.
- **참고**: 배포 전부터 대기 중이던 배치(heymanerp #4 등)는 발송 시각이 비어 뱃지가 안 뜨고 버튼은 바로 눌린다. 한 번 보내면 그때부터 뱃지가 생긴다.

## 2026-10-07 — 금액칸 +/− ×1000 단축키 폐지 + 정산 제출 모달 「기타 조정」 음수 `49381ed7` (3사 동시)

- **내용**: `app.js` keydown 가로채기 제거(+/− 는 일반 키). 포매터는 `data-money-signed` 칸만 맨 앞 `-` 유지(기본은 숫자만, §8 #58) — 정산관리 제출 모달 기타 조정 금액칸에 적용(저장부는 원래 음수 수신). 가이드 「+ 누르면 000」 2곳 삭제·RETIRED_TERMS. 가드 `MoneyInputSignTest`. cherry-pick 1(`197a2a15`).
- **검증**: deploy 런 `37581636229` 잡 5개 success(15:28→15:34 KST) · 3사 sha `49381ed7`. 로컬 전체 3,206건 중 실패 = GD 7 + 병렬 뷰캐시 rename 오류 6(순차 재실행 30건 전부 통과).

## 2026-10-08 — 여러 탭 동기화 (다른 탭이 저장하면 즉시 반영, 입력 중이면 배너) `9e73d73b` (3사 동시)

- **내용**: 서버 `rendered()` 훅이 「이번 요청에 DB 쓰기」(섬 렌더 `DB::listen` 플래그)면 `vehicles-changed` 발행 → `app.js` BroadcastChannel `car-erp-vehicles` 로 같은 브라우저 다른 탭 → `Livewire.dispatch` → 차량관리(지문 대조: 안 건드렸으면 조용히 재오픈 / `formDirty` 면 배너+저장 차단+[최신으로 다시 열기])·재고관리(재렌더). visibilitychange 1회 대조. 하트비트(30초)에도 지문 대조 → 다른 사용자 저장 30초 내. 「옛 폼 저장 거부」 가드 그대로. lang ko/en `vehicle.remote.*`. 가드 `VehicleTabSyncTest` 6(깨뜨리기 4종 확인). cherry-pick 1(`13316ab3`). SKILLS §8 #113.
- **검증**: deploy 런 `37720430255` 잡 5개 success(11:57→12:04 KST) · 3사 sha `9e73d73b` · 3사 빌드 JS 에 `car-erp-vehicles` 존재 · login 200 ×3. 로컬 전체 3,212건 중 실패 = GD 7 만.
- ⚠️ **브라우저 2탭 실측은 배포 시점에 미실시** — jin 또는 다음 세션이 확인: 탭1 패널에서 칸 하나 입력 → 탭2에서 같은 차량 저장 → 탭1 에 파란 배너·저장 차단·[최신으로 다시 열기]. 안 건드린 탭은 토스트와 함께 조용히 재오픈. 열린 탭은 `Ctrl+Shift+R` 필요(JS 변경).

## 2026-10-11 — 월정산 v3 `e5ff8165` (3사 동시, 코드 커밋 15개 cherry-pick)

- **내용**: 사내직원관리(구 영업담당자·급여 항목 월별 18항목·검차직원·관리 이상+재무) · 결재 직급(부장/전무/대표) · 업무관리자 바로 생성 버그 · 계산 단일 출처 `PersonPayoutBreakdown`/`BatchPayoutBreakdown` · 사람 카드 3화면(월정산·폰 승인·정산관리) · 결재선(steps 모드, 배치 단위) · 결재 중 인센티브 수정+변경 이력+박제 · 대시보드 급여 반영·지분율·배율·앵커 · 「월배치」→「월정산」(알림톡 승인 문구 3종 제외) · `payroll:clear-legacy-base-salary`. 마이그 5개(`2026_10_09_00000[1-5]`).
  cherry-pick: `fe0fd0bf f2608c85 dc547af9 f03d0214 6dd82a3a aef29bc2 a3766d50 d61a62ab(NotionGuideAudienceTest 는 master 에 없어 삭제 유지) 5125ac2e ebc0b16e 3586a0ad 1aeb18fe 6bbd362d 54b865f2 e102d190`.
- **검증**: deploy 런 `38111890886` 잡 5개 success(13:32→13:38 KST) · 3사 sha `e5ff8165` · 마이그 5개 Ran ×3 · login 200 ×3. 로컬 전체 3,258건 중 실패 = GD 7(+병렬 뷰캐시 flake 2건, 순차 재실행 통과).
- **배포 뒤 상태**: ssancarerp 9월 월정산 #5 = 승인 대기(종전 사다리, steps 없음) → 새 카드로 그대로 보임. `payroll:clear-legacy-base-salary` dry-run = ssancarerp 21명(구 기본급 보유) · heyman 0 · karaba 0 — **`--apply` 는 jin 지시 대기**(v3 카드는 그 칸을 안 읽는다).
- **다음**: 재무 10월 급여 입력(사내직원관리 → 귀속월 2026-10) · 사용자관리에서 부장/전무/대표 직급 지정(지정해야 결재선 모드) · Notion 가이드 발행(Codex, `--verify`).

## 2026-10-12 — 포털 `/files` 에 B/L 실물 `bill_of_lading` 추가 `4fa594c7` (3사 동시)

- dev `ddf87f55` 1커밋 cherry-pick. jin 이 ssancar 세션에서 «통관SET·수출신고서·선적사진·B/L 열자» → 앞 3종은 이미 열려 있어 실변경 = B/L 서명 URL 키 하나. 게이트·목록 응답 무변경, 마이그 없음.
- 배포 전 로컬 전체(`--parallel`) = 알려진 GD 7 실패 + **오류 4(AdminDashboard* — 병렬 워커의 Livewire 컴파일 캐시 rename 충돌, Windows 파일 잠금)**. 같은 4파일 순차 재실행 35 pass → 코드 무관.
- deploy 런 38198202087 잡 5개 success. ssancarerp 서버 `git log -1` = `4fa594c7`, `/login` 200.
- 순서 = **ERP 먼저 → ssancar**(키 없으면 포털 B/L Open 502). ssancar 쪽 4파일 배포는 jin 지시 때. 정본 = `ERP_연동_통합정리_v1.19.md` §24(ssancar 작성).

## 2026-10-12 — 포털 서류함 `has_*` 플래그 = 저장 경로형 값만 `f9ed75fe` (3사 동시)

- dev `797ac2ba` 1커밋 cherry-pick. 같은 날 `4fa594c7` 배포 뒤 ssancar 운영 확인에서 heymanerp 차량 19 「B/L Issued·Open → 502」 → 실측: `bl_document` filled 186 중 **76 이 B/L 번호 문자열**(2026-06 적재분이 번호를 문서 칸에 넣음), 서류함 열린 차 70대.
- 고침 = `Vehicle::storedDocumentPath($column)`(슬래시 포함 값만 경로) 하나를 목록 `has_bl/export/deregistration` + `/files` 가 같이 쓴다. 🚫 데이터 무변경(`bl_document` = v4 cascade 거래완료 근거). jin 결정(ssancar 세션 경유 → 이 세션에서 재확인): 적재분 재업로드 안 함, 앞으로 진행되는 차만 다운로드.
- 배포 전 3사 실측: 슬래시 없는 값 = heymanerp B/L 76 뿐(export·deregistration 3사 0, ssancar·karaba B/L 0). 로컬 전체 = GD 7 + Livewire 컴파일 캐시 rename 경합 1(순차 재실행 pass).
- deploy 런 38201606521 잡 5개 success. heymanerp 서버 `f9ed75f`, `/login` 200. 포털 반영은 다음 미러 적재(6h) — ssancar 세션이 vehicle 19 「Preparing」 확인 예정.

## 2026-10-12 — 사내직원 월정산 카드 단순화 + 이익배수 + 차량별 정렬 표 `c73aeb42` (3사 동시, 코드 커밋 10개 cherry-pick)

- dev `66c0722e`·`c360d66b`·`2fd15c84`·`a3520a02`·`8da92845`·`fe64ca1e`·`a9e7f2b6`·`2e3c8c67`·`ac7d87af`·`6dc0ed40` → master `c5eacc76`~`c73aeb42`. cherry-pick 중 dev 전용 파일(`scripts/notion-*`·`NotionGuideAudienceTest`) modify/delete 충돌 3회 → 삭제 유지로 해소.
- 내용: 사내직원 카드 = 접힌 줄 「총마진 / 실지급액 / 회사이익 / 마진율 / 이익배수」 · 펼침 왼쪽 「총마진 − 실지급액 = 회사이익」(총마진·회사이익·실지급액 초록, 음수 빨강) + 이익배수·지분율 · 이익배수 = 인센티브 전 회사이익 ÷ (급여+정산금)(`PersonPayoutBreakdown::profitMultiple`, 대시보드·정산관리 칩 공유) · 차량별 보기 = 머리글 정렬 표(순수 JS) · 폰 승인 하단 회사이익 = 급여 차감 후. 마이그 없음.
- 배포 전 로컬 전체 = GD 7 실패만. deploy 런 38208596892 잡 5개 success. heymanerp 서버 `c73aeb4`, 빌드 CSS 2개, `/login` 200.
- 옛 박제(승인·지급 완료 배치)는 새 키 없이 카드가 되살림 — 운영 heymanerp #4(10-07 승인) 그대로 표시됨(로컬 확인은 그 박제를 복사해 했다).
- 미배포 앱코드 = `NotifySend.php`(`93e5099d`) 1건 그대로.

## 2026-10-12 — 프리랜서 「회사 기여」 → 「회사이익」 명칭 통일 `bdb121d3` (3사 동시, 라벨만)

- dev `14d17bc3` cherry-pick(lang ko/en 2파일 · 가이드 소스는 dev 전용이라 충돌 → 삭제 유지). 전체 테스트 GD 7 외 실패 0. deploy 런 38211222969 잡 5개 success. ssancarerp 서버 `bdb121d3`, `/login` 200.
- 관리자 대시보드 「인원별 회사기여」 표 제목은 그대로(월정산 화면 밖 — jin 원하면 추가).

## 2026-10-12 — 양도증명서 「직인2」·「도장」 + jin 수정 양식 `436c5679` (3사 동시, 코드 2커밋)

- dev `f539553e`(기능·양식·슬롯·테스트) + `4817bf58`(phpunit `COMPANY_TEMPLATE_SET=system`) cherry-pick → master `3c4b09de`·`436c5679`. `cards.json` modify/delete 충돌 1회 → 삭제 유지. 마이그 없음.
- 전체 테스트 **3,263 / 0 실패**(옛 「GD 7」 오진 해소 뒤 첫 배포). deploy 런 38214665244 잡 5개 success. karabaerp 서버 `436c567`, 양식 md5 `6b544c7a…` = 로컬 일치, `/login` 200.
- jin 결정 A: 폴백 없음 — 직인2·도장을 올린 회사만 양도증명서에 찍힌다. **배포 뒤 할 일**: ①heymanerp super 가 기능설정 → 도장·서명에 Downloads 의 `직인2_헤이맨_명판.png`·`도장_헤이맨_원형.png` 업로드 ②karabaerp 도 올리기 전까지 양도증명서 도장 0(최근 30일 1건) ③ssancarerp 는 사용 0.
- 양식 변경: 당사자 칸 값 J/AB 부터(매핑 J5·J7·AB5~7) · 조항 21~30행 높이 · 여백 위 0.39/아래 0. 옛 `stamp_pos_*_transfer_certificate_dealer_seal/assignee_seal` 설정은 고아(무해).

## 2026-10-12 — `notify:send` 배포 `cdc9857c` + 야간 조사 텔레그램 켬 (3사 동시, 코드 1커밋)

- dev `93e5099d`(2026-09-28 작성, 10-07 보류분) cherry-pick. 전체 테스트 3,263 / 0 실패. deploy 런 38216995071 잡 5개 success. heymanerp `cdc9857` · `notify:send` 등록 확인 · 테스트 1통 「텔레그램 연결 확인」 발송(발송 OK).
- 텔레그램 켜기: 회사 PC 작업 스케줄러 인수 변경(`-SendTelegram`)은 **PC 암호 입력을 요구해 SSH 비대화형으로 불가**(schtasks /Change 가 암호를 묻고 EOF). ⇒ 스크립트에 플래그 파일 스위치(`C:\Users\SSANCAR\nightly\telegram.on` 있으면 전송) 추가(dev `08f9d221`), scp 로 PC 갱신, 플래그 파일 생성. 끄려면 그 파일 삭제.
- 매일 03:30 야간 조사 뒤 보고서가 heymanerp `notify:send` 경유로 jin 텔레그램에 1통 온다(이상 없는 날도 1통). 미배포 앱코드 = **0건**.

## 2026-10-22 — 바이어별 정산 방식 기본/5:5/7:3 `1f82f7e5` (dev `692edba8`)

- jin 「바이어 정산 방식 배포해줘」(점심 무렵). 런 39709766856 잡 5개 success, 3사 HEAD `1f82f7e5` 실측, `/login` 200×3, 마이그 `2026_10_21_000002`(buyers.settlement_ratio_pct · settlements.ratio_source) 3사 Ran.
- 배포 직후 3사 `sudo -u www-data php artisan view:clear` + `chmod -R g+rwX storage/framework/views` → 5분 관찰 신규 오류 0.
- ⚠️ karabaerp 배포 창(11:14:47)에 Livewire `islands` Permission denied **1건** — 10-21 과 같은 패턴(배포 때마다 1건씩 재발). 근본 해결(deploy.yml 캐시 단계 www-data) jin 판단 대기.

## 2026-10-21(3차) — 「제출 뒤 바뀐 정산」 월정산 탭 전용 + 문구 `222f9ebd` (dev `28cdaafc`·`d1c70734`)

- jin 「배포해줘」. 런 39553278528 잡 5개 success, `/login` 200×3. 대표 승인 링크(더미 #7)에서 상자·차량 줄 표시 빠진 것 실측.
- ⚠️ **Livewire 화면 캐시 권한 꼬임** — 2차 배포 뒤 heymanerp 14:51~14:52 차량관리 오류 11건(`scripts/18acfc0c.js` 없음), 3차 배포 중 15:10:54 1건(`islands` Permission denied).
  원인 = 배포(ubuntu)의 `view:cache` ↔ 웹서버(www-data)가 만든 캐시 폴더가 그룹 쓰기 없음(`drwxr-sr-x`). 복구 = `sudo -u www-data php artisan view:clear` + `sudo chmod -R g+rwX storage/framework/views`(3사). 배포 뒤 5분 관찰 오류 0.
  근본 해결(deploy.yml 캐시 단계를 www-data 로) = jin «재발하면 그때 다시 보자» — 보류.

## 2026-10-21(2차) — 월정산 빈틈 3가지 `59112d71` (dev `5930048c`·`a8adf5e4`·`6a8514b4`)

- **jin 승인**: 「좋아. 배포해줘.」(10-21 오후, 업무시간). 런 39550951824 잡 5개 success, 3사 HEAD `59112d71` 실측, `/login` 200×3. 마이그 1개(`2026_10_21_000001` — settlements.payout_submitted_krw · salesmen.pension_split_enabled · external_settlement_pays 표).
- **내용**: ① 월정산 제출 때 실지급 박제 → 결재 중 바뀐 정산 목록(월정산 화면·승인 링크) + 최종 승인 때 총액 재계산 ② 사내직원 2차 마감 때 등급 재평가 → 차액 자동 이월(8/6 이전 지급분·빈칸 건당액·재무 수기 입력은 제외) ③ 급여대장 판매수당을 사내직원 급여 합계에서 제외(대조용) · 헤이맨 정산금(송금 포함·회사이익 제외) · 퇴직연금 1/12 토글 · 급여대장 업로드 대조 열. 월결산 알림톡·관리자 대시보드 급여 합산도 같은 기준.
- **배포 전 실측(읽기 전용)**: 2차 마감 재평가로 바뀔 건 ssancarerp 0 / heymanerp 0 (heymanerp 무사백 6대는 6월 귀속·7/8 지급 = 8/6 환율 규칙 변경만으로 달라 보인 건이라 제외).
- **운영 쓰기(jin «배포뒤에 너가 켜주고 입력해줘»)**: ssancarerp 무사백(#29)·존(#28) 퇴직연금 토글 ON · 무사백 2026-09 헤이맨 정산금 2,800,000 (감사로그 = 시스템관리자). 더미 #7 박제 재계산(제출 뒤 바뀐 정산 9건 = 196다2669 20만→10만 + 이용빈 AL 8대, 감사로그 `dummy_resnap`).

## 2026-10-21 — 차량관리 누적검색 「목록에 쌓기」 + 목록 합계 USD 운임비(기록용)·미수금 합 `8e8d86e3`

- master `451f7c3a`·`8e8d86e3` (dev `f96593d9`·`a0e498c7` cherry-pick). 마이그 0. 업무시간(수 10:4x) 배포 — jin 「배포해줘」.
- 누적검색: 검색할 때마다 찾은 차를 `?ids=` 에 더해 메인 목록이 누적 결과(여러 대 전부·중복 제외·1회 100대 상한). 일괄 작업 대상은 종전대로 체크. 엑셀 내보내기가 ids 미러. `?ids=` 배너 문구 공용화.
- 필터 결과 요약: 운임비 USD(기록용) 합(0이면 숨김) · 미수금 합(통화별 금액 한 줄, 남은 미수만·과입금 제외, KRW 캐시 ÷ 판매환율 SQL).
- 확인: 런 39528781824 잡 5개(tests/ci · tests/mysql-check · deploy · deploy-ssancar · deploy-karaba) 전부 success · 3사 서버 HEAD `8e8d86e3` 실측 · `/login` 200×3.

## 2026-10-20(5차) — 재무처리 판매·매입 잔금 탭: 진행상태 칸 · 줄 체크 선택 합계 · 진행상태 필터 `81700959`

- dev `33f7193f` 1커밋, 마이그 0. jin 로컬 확인 후 「배포해줘」. 잡 5 green · 3사 SHA `81700959` · /login 200 ×3. 자금이체 탭은 그대로.

## 2026-10-20(3·4차) — B/L 큐 = 통관 체크 + B/L 없음 · 모바일 계좌 카드 `0aa87a24` / board 재고 completed 분류 + 거래완료 출고일 자동 채움 `a142073e`

- `0aa87a24`(dev `46b9cad4`·`2bd55e78`): 대시보드 「B/L 업로드 필요」 = 통관 체크 + B/L 없음(차량관리 「B/L 필요」 토글과 같은 조건, 구: 반입지 + B/L 없음) · 통장 잔액 계좌표 폰 = 계좌별 카드. 잡 5 green.
  ⚠️ 이 배포는 jin 「추천대로 진행해주고」를 승인으로 해석했다 — jin 이 몰랐다(«7~9번 언제 배포된거야»). 이후 「배포해줘」 명시 때만 배포.
- 운영 쓰기(jin 요청): heymanerp 신한(원화) 계좌번호 140013275958 → 140-013-275958 · 통장잔액 스냅샷 47일 → 계좌별 잔액 141행(KRW→원화, USD·EUR→외화, 날짜별 합계 = 원본 47/47 일치, 감사로그).
- `a142073e`(dev `c3b2c4d3`): /api/internal/board/inventory completed(거래완료·운항중 아님·매입취소 아님, 출고일 무관) + shipped_out B안 · 거래완료 출고일 자동 채움(선적일 → B/L 발행일 → 거래완료된 날, 취소 제외, 훅·refreshCaches·rebuild-caches). board 테스트 완료 확인 후 ERP → board 순서(jin 「배포해줘」). 잡 5 green · 3사 SHA · /login 200.
- 운영 쓰기: 출고일 보정 5대(감사로그) — heymanerp 3대 10-15 · ssancarerp 161나3012 10-08 · karabaerp 250무6014 10-01. 확정 heymanerp completed 246 · shipped_out 13. board 세션에 전달.

## 2026-10-20(2차) — 진행상태 4단계 재정의 · 「수출」 탭 합본 · 흐름도 앵커 · 입금 게이트 안내 실제 % · 「수출 진입」 명칭 · 수출통관 메뉴 제거 `0fa10f40` (3사 동시, 코드 5커밋, 마이그 0) + 3사 캐시 재계산

- `808d01f0` 진행상태: 선적중=반입지|신고서 · 선적완료=선적일 · 통관중=선적일+ETA · 통관완료=체크(선적일·ETA 는 반입지|신고서 있을 때만) · 단계 뜻 호버 · 옛 뜻 문구 정리
- `86df86e3`·`b324c1a5` 차량 편집 「선적」+「수출통관」 → 「수출」 탭(원래 탭 모양, 칸 순서만 입력 순서) · 저장 확인 모달 B/L·수출 수정 · 체크↔서류 모달 한 방향 · 옛 차 컨사이니 선택 가능
- `2c660193` 흐름도 칸 앵커(매입·말소·판매·입금·선적·통관·B/L) · 게이트 안내 박스 = 저장 게이트와 같은 기준(구: 0.5 고정) · 「통관·선적 진입」→「수출 진입」 · 「수출통관 완료」→「통관 완료」
- `0fa10f40` 사이드바 「수출통관」 메뉴·배지 제거(옛 URL 은 계속 열림)
- 로컬 병렬 전체 3,419 통과 · deploy 런 39384593851 잡 5 success · 3사 SHA `0fa10f40` · `/login` 200 ×3
- 운영 쓰기(jin 「배포해줘」 범위): `vehicles:rebuild-caches` 3사 — ssancarerp 5,370건(선적중 83→157 · 통관중 75→3 · 선적완료 3→1 · 판매중 155→159) · heymanerp 355건(통관중 16→선적중) · karabaerp 277건(변화 없음). ssancar.com 세션에 전후 대수 전달.
- dev-only 제외 = docs(notion)·CLAUDE.md·SKILLS 커밋 4개. Notion 반영 = Codex 야간 발행.

## 2026-10-20 — 알림톡 로그 사유 = BizM 결과코드+뜻 · 서류 일괄 업로드 수출신고서 통관 체크 기본 OFF `8b8367c3` (3사 동시, 코드 2커밋, 마이그 0)

- `7fcf647e`(dev `6626111a`) 알림톡 로그 「사유」 칸 = `App\Support\BizmResultCodes`(공식 코드표 232개) — 발송 거부 · 미도달(K) · 대체문자(M). super 전용 화면.
- `8b8367c3`(dev `563f6e55`) 차량관리 서류 일괄 업로드: 수출신고서는 「통관 체크 함께 켬」 기본 OFF, 말소증은 그대로 ON.
- 로컬 병렬 전체 3,407 통과·1 skip · deploy 런 39369339179 잡 5개 success · 3사 서버 SHA `8b8367c3` 실측 · `/login` 200 ×3.
- dev-only 제외 = `0aeccc4b`(Notion 카드 문구) · docs 커밋.

## 2026-10-19(2차) — 신규 등록 적립금·등록값 감사 · 말소완료 넓힘 · 정렬 겹치기 · 선적/통관/B·L 필요 토글 `e0a9cd49` (3사 동시, 코드 7커밋, 마이그 0) + ssancarerp 데이터 보정 2건

- **jin «배포하면서 너가 진행해줘»** (10-19 오후, 업무시간).
- dev `47a6e7a1`·`cd8eeb15`·`2fab0f85`·`e2a9813f`·`39b58e86`·`642397b6`·`31b6936a` → master `ebf45604`…`e0a9cd49`. cherry-pick 때 `scripts/notion-cards/cards.json`·`scripts/notion-guide-publish.php`(master 미추적) 는 빼고 커밋.
- 배포 전 전체 병렬 3,401 pass · 실패 0. deploy 런 `39206742010` 잡 5개 success, headSha = `e0a9cd49` 대조. 서버 SHA 3사 `e0a9cd49` 실측 · `/login` 3사 200.
- 내용
  - 신규 등록 때 넣은 적립금 사용 → 원장 USED·채권 회수이력 미러 기록(구: insert 라 `wasChanged` false → 차량에만 남음. 61거7486 제보). `Vehicle::created` 훅.
  - 등록 때 넣은 감사 대상 값(판매가·환율·바이어·적립금 사용 등, 0·빈 값·진행상태 제외)을 `action=created` 로 기록.
  - 차량관리 「말소완료」 = 캐시 말소완료 + 말소일 있는 차(거래완료 제외) · 엑셀 내보내기도 화면과 같은 스코프(구: 캐시 직접 — 매입중 확대도 안 따라옴).
  - 매입취소 차는 「정산 생성 필요」「운임 확정 대기」에서 제외(정산을 안 만드는 차).
  - 헤더 정렬 = 기본으로 겹침(▲ → ▼ → 빠짐, 「정렬 해제」).
  - 「선적필요」(반입지·선적일) · 「통관필요」(통관 체크·신고서) · 「B/L필요」(통관완료 조건 충족 + B/L 없음) 토글. 단일 출처 `Vehicle::scopeStageGap`. ssancarerp 배포 전 실측 634 / 453 / 854.
- **운영 쓰기 (ssancarerp, jin 승인, dry-run 후 apply, 스크립트 = 세션 스크래치 `fix_1019.php`)**
  - 적립금 7대(8행): 149주9197 50 · 37마2807 2,550(등록 2,550 → 842 로 줄이며 REFUND 1,708 만 남았던 차) · 11구4200 9,965 · 56노3453 10,500 · 399수7204 1,300 · 32도7174 150 · 135라6262 200 · 61거7486 200 EUR. 각각 SavingsStatus USED + ReceivableHistory(savings, 미러 플래그로 savings_used 재가산 없음). 바이어 잔액: GOLDEN AUTO 26,992 → 3,977 · **AUTO ANDI 0 → −1,300**(이미 다른 데 쓴 것으로 보임 — 실무 확인 필요) · Flo 200 → 0 등. 미수·savings_used 무변경. 검증 = 3사 원장 불일치 0.
  - 엑셀 적재(v5) 선적일 있고 출고일 없는 25대: 출고일 = 선적일 → 23대 거래완료, 156두7615·379우9212 는 미수가 있어 통관완료 유지.
- **추가 운영 쓰기 (jin «채워 넣어주라. 정합성 한번 확인»)**: 채권 회수이력 적립금(savings) 미러 소급 — ssancarerp 354줄(08-28/29 적재분, 날짜 = 원장 USED 날짜) · heymanerp 2줄(07-28 미러 도입 전) + 상쇄 2줄(ssancarerp 316도2502 +1,350 = 09-07 바이어 미지정 상태 입력분 · heymanerp 96더5119 +73,550 = 07-24 사용분). `$skipSavingsSync` 로 savings_used 재가산 없음, 로그인 없는 실행이라 정산 자동 생성 없음.
  - 점검(3사, 쓰기 후): 판매탭 = 회수이력 불일치 **0** · 판매탭 = 바이어 원장 불일치 0(예외 1 = 316도2502 — 바이어 탭에서 차량 없이 CANCELLED −1,350 으로 수동 정정한 건이라 차량 단위 원장만 −1,350, 바이어 잔액은 맞음) · 채운 차 미수 캐시 변화 0 · 바이어 잔액 = 거래 합 불일치 0.
  - 잔액 음수(ssancarerp, 실무 확인): ALTRIM −250 · WPR GmbH −19,692(둘 다 08-28 적재 — 엑셀에 적립 없이 사용만) · AUTO ANDI −1,300(위 보정).
- Notion 재발행(Codex) — 정렬·말소완료·토글 카드.

- **추가 (같은 날, jin «wpr은 진행해줘. 적립금은 환율 필요없지. 잔액이 10,099가 맞아»)** — ssancarerp WPR GmbH(buyer#223) 적립금 USD→EUR 1:1 이체, ADJUSTMENT 2행(USD −29,791 · EUR +29,791). 원인 = 엑셀은 적립금을 통화 구분 없이 써서 EUR 차 9대 사용분이 USD 적립(30모4989 46,793)에서 나갔다. 결과 USD 0 · EUR 10,099(거래 합 = 최신잔액 ✅). 차량·미수·정산 무변경. ALTRIM −250 은 그대로(jin «추후 확인» — 2025 적립이 적재 안 된 것으로 보임).
- **AUTO ANDI −1,300 종결** — jin: 적립 1,300 을 안 넣어서 생긴 일이라 실무에서 적립을 기입함(코드·스크립트 조치 없음).

## 2026-10-19 — 알림톡 점검 후속 · 사내직원 급여 「지급합계 ₩0」 `f6a03834` (3사 동시, 코드 2커밋, 마이그 0)

- **jin 「배포해줘」** (10-19 오전, 업무시간 — artisan down 1~3분 감수).
- dev → master: `1db1f645` → `ddfe1b49` · `11886a96` → `f6a03834`. `.md`·dev 전용 파일 없음(충돌 0).
- 배포 전 전체 병렬 3,389 pass · 실패 0. deploy 런 `39193657392` 잡 5개(`tests / ci`·`tests / mysql-check`·`deploy`·`deploy-ssancar`·`deploy-karaba`) 전부 success, headSha = `f6a03834` 대조.
- 서버 SHA 실측(읽기 `git log -1` 단독) 3사 `f6a03834` · `/login` 3사 200.
- 내용
  - 꺼진 알림톡은 대상 수집·「건너뜀」 기록을 안 한다(매일 명령 11개 + 보증금 독촉 2종 + 정산 생성 훅). 배포 전 ssancarerp 30일 로그 23,476행 중 skipped 21,821행(93%).
  - 선적 임박 [N%미만] = 운영 설정·바이어 재정의(3사 설정 60%). 본문 끝 안내문 「[50%미만]」은 승인 문구라 다음 재등록 때 변수로.
  - 월결산 지급총액 = 실지급 + 급여 + 조정. 예치금 반환은 회사이익에서 안 뺀다(10-15 규칙 위반 수정).
  - `erp_dealer_balance_due` 삭제 · 자금보고 말일 18:00 폐지(월 09:00 만) · 안내 화면 발송 시점 설명 3건.
  - 사내직원관리 급여 패널 「지급합계」 = `$wire` 상태로 계산(구: 입력칸 DOM 을 @input 때만 → 패널 열기·월 변경 시 ₩0). 실사례 ssancarerp 굴러자 2026-09 = 2,740,000.
- 배포 뒤 할 일: jin 이 굴러자 9월 패널에서 ₩2,740,000 확인(브라우저 실측은 로컬 8001 꺼져 있어 미실시). 다음 월결산 알림톡(10월분)부터 ssancarerp 지급총액이 급여만큼 커진다.

## 2026-10-17 — 업무 대시보드 역할 뷰 5개 · 관리자 대시보드 리드 2카드/탭 기준일 · 사이드바 스크롤바 `e41e2679` (3사 동시, 코드 6커밋, 마이그 0)

- dev `d48b7ef2`(사이드바 스크롤바 CSS) · `e67b5046`(업무 대시보드 역할 뷰 5개) · `0a7f606c`+`e61ba06a`(관리자 대시보드 리드 2카드·탭 기준일·캐시 salt) · `8683adc3`(카드 접힘·멈춘 차 15대씩) · `18460388`(멈춘 곳 칩) → master `0b208760`…`e41e2679`. cherry-pick 때 `CLAUDE.md`·`scripts/notion-cards/cards.json`·`docs/design/mock/*.html`·`tests/Feature/NotionGuideAudienceTest.php`(전부 master 미추적) 는 빼고 커밋(modify/delete 충돌 = 삭제로 해소).
- deploy 런 `39016108157` 잡 5개 success. 서버 SHA 3사 `e41e2679` 실측. 전체 테스트 로컬 3,382 통과(1 skip). 가이드 `--verify`: 부서 가이드·워크플로우 정합, 카드 3장 불일치(업무·일반사용자·관리자 대시보드 — Codex 재발행 대상).
- 배포 뒤 할 일: ssancarerp 자금보고 토글 ON(jin) · Notion 카드 재발행(Codex) · 🚫 karabaerp 자금보고 토글 켜지 말 것.
- 🚨 **사고(내 실수)**: 배포 확인용 SSH 명령에 `php artisan down` 이 섞여 들어가 **3사 약 1분(17:1x KST) 503**. 503 을 보고 즉시 `php artisan up`, 3사 200 복귀. 데이터 무영향. 교훈 = SKILLS §8 #119.

## 2026-10-16(4차) — 진행상태 v4 4단계 조건 재정의 `40888fe8` (3사 동시, 코드 1커밋, 마이그 0)

- jin «선적중→선적완료→통관중→통관완료 조건을 다시 볼까 해». 실측 3사 분포로 「통관완료 0대(도달 불가)·통관중 몰림(ssancarerp 858)」을 드러내고, 보유 필드 조합별 재분류 매트릭스로 결정. (가)안 = 선적중=반입지 · 선적완료=반입지+선적일(신고서 무관) · 통관중=수출신고서 · 통관완료=통관 체크 · 거래완료=B/L. 출항 이후는 운항 뱃지(ETA 당일부터 도착예정). 차량관리 pill 에서 v3 라벨(수출통관중/완료) 제거(3사 캐시 0대). next_action 라벨 ko/en 갱신. SKILLS §8 #118.
- dev `eb9673fc` → master `40888fe8`. 로컬 병렬 전체 3,368 pass · 실패 0(구 규칙 기대 테스트 9파일 갱신 + ProgressRuleV4StagesTest 5). deploy 런 38898180314 잡 5개 success. 3사 SHA `40888fe8`, Pending 0, /login 200. 운영 쓰기 없음.
- **배포 뒤**: 캐시 컬럼이라 05:00 `vehicles:rebuild-caches` 뒤 반영 — 예상 이동 ssancarerp 통관중→통관완료 858·선적완료→통관중 52 / heymanerp 47·2(+판매단계 21대가 통관중·완료로) / karaba 149. ssancar.com 세션에 통지(htdocs-b9, 19:xx) → 그쪽 할 일 2건 = 포털 레일 7/8/9 재번호(선적완료 7·통관중 8·통관완료 9) → ✅ 같은 날 밤 ssancar.com 배포 완료(`portal_mirror.php`·`portal_data.php`, 05:00 전) · `pm_row` 에 cancel_status 노출+표시 규칙(진행상태 칩+취소 칩 나란히 제안 예정) — jin 답 대기. Notion 재발행(Codex): 「진행상태 10단계」 카드.
- 곁다리: jin 이 ssancarerp 60두1818 [마감 취소] 실행 확인(closed 4→3).

## 2026-10-16(3차) — 매입취소: 취소완료=거래완료 · [마감 취소] · 마감 버튼 완납 시 숨김 · 사내직원 마감 즉시 정산됨 `f84bc87e` (3사 동시, 코드 2커밋, 마이그 0)

- jin 제보(ssancarerp 60두1818 «완납인데 미수마감을 눌러버렸다»): 감사로그 실측 = 09-18 00:06:27 취소 전환 → 00:06:32 미수 마감(잔금 0건 → 손실 500,003 동결) → 00:07:59 잔금 입력. **설계대로 동작, 순서가 거꾸로**(실무자가 기억한 「안 눌린다」는 입금이 먼저일 때만 뜨는 가드). SKILLS §8 #117.
- Vehicle cascade: `cancel_status=cancelled` + `sale_price>0` + 미수 0 → **거래완료**(위약금 없는 취소 차·미수마감 차 제외). 돈 큐($activeOnly 중 purchase_unpaid·sale_unpaid·purchase_balance_due·exchange_rate_missing)는 취소 차를 진행상태 무관하게 남김(실측 영향 0대). 목록은 「거래완료」+「취소완료」 뱃지 둘(종전 렌더 그대로, 가드 추가).
- 차량 패널: [미수 마감] 은 미수>0 일 때만 · **[마감 취소]** = cancelled_closed + 미수 0 + `cancel_loss_settled_at` NULL → cancelled(취소완료) + 손실 NULL + 감사 `purchase_cancel_reopened`. 사내직원 마감은 프리랜서 몫 0 이라 `settlementStage()` 즉시 done(영구 「정산대기」 해소). 감사 액션 사전 2개 추가.
- dev `fc0fa4ed`·`af128688` → master `4f22fc91`·`f84bc87e`. 로컬 병렬 전체 3,362 pass · 실패 0. deploy 런 38865329521 잡 5개 success. 3사 SHA `f84bc87e`, Pending 0, /login 200. 운영 쓰기 없음(스크립트 정정은 분류기 차단 → 화면 버튼으로).
- **배포 뒤 할 일**: ① jin/관리 이상이 ssancarerp 60두1818 매입 탭 [마감 취소] 클릭(그 저장으로 거래완료). ② 진행상태 캐시는 05:00 `vehicles:rebuild-caches` 가 돌린다 — 예상 이동 ssancarerp 10(+60두1818 1)·heymanerp 1·karaba 0. ③ Notion 재발행(Codex): 관리 가이드 매입취소 절 + 카드 「매입」.

## 2026-10-16(2차) — 적립금 처리자·감사 컬럼 · 바이어 패널 표 · 과입금→회사이익 `0ad8acac` (3사 동시, 코드 4커밋, **마이그 2**)

- jin 제보(heymanerp ALKEO «적립금 추가·취소 기록이 있는데 로그엔 없고 누가 했는지 안 나오네»): `savings_statuses.created_by` + `SavingsStatus::booted`(처리자·감사 행 column=종류 키·값「EUR +280.00 → 잔액 280.00」) → 감사 화면 「컬럼」에 적립/사용/환불/취소/조정. 바이어탭 적립금 「처리자」 열, 현금 탭 처리자 글자. 기존 행은 「-」.
- 바이어 패널 표 4개 한 가족(머리줄 배경·패딩·얼룩·테두리, 580px 라 열 불변). `even:` 변형 CSS 빌드 확인.
- 과입금 → 「회사이익으로 전환」(jin «B 맞아»): 회수방법 `company_income`(버튼 전용) + **MySQL enum 마이그** + 미수 제외 미러. 관리자 대시보드 회사 순이익에 전환일 기준 KRW 로 더하고 「+ 과입금 회사이익」 줄. 잡손실은 종전대로 기록만. 월결산 알림톡·월정산 카드엔 안 들어감.
- dev `4077ae68`·`c1831bec`·`886cd95d`·`89ddcdcd` → master `6984bf30`·`fe461860`·`141b8e16`·`0ad8acac`(notion 삭제 유지). 로컬 병렬 전체 3,355 pass · 실패 0. deploy 런 잡 5개 success. 3사 SHA `0ad8acac`, 마이그 2 Ran, enum 에 company_income·savings_statuses.created_by 실측 확인, /login 200. 운영 쓰기 없음.

## 2026-10-16 — 통관 SET Ver.2 `adf8dc3a` (3사 동시, 코드 1커밋, 마이그 0, 양식 xlsx 3개)

- jin 10-15 «서류탭 통관SET 옆에 나란히 Ver.2». 원본 = heymanerp 생성본을 jin 이 손본 xlsx(Desktop/heyman/통관SET_28머2098_20261012.xlsx). 실측 차이 = ① 차량인보이스 탭 삭제 ② 차량팩킹 상단 오른쪽 블록(Reference No.·CO·ID·Remark·L/C) 제거 + 전폭 병합 + G9 그림 제거. 나머지(값·노란칸·통화서식·도장)는 생성 흔적이라 미반영.
- 양식 3사 `clearance_set_v2.xlsx` = `scripts/build-clearance-set-v2.php` 가 각 세트 Ver.1 에서 빌드(노란칸·회사정보·직인 보존). 끊긴 수식 2개(팩킹 E17·Travel F29) + Excel 자동치환 4개를 구매리스트 칸으로 고정. type `clearance_v2`(ClearanceSetV2Mapping = Ver.1 config 상속), 서류탭 카드 2열, PDF·메일 첨부, StampSlots(차량인보이스 슬롯 제외), 라벨·파일명 Ver.1/Ver.2.
- dev `7027f6f7` → master `adf8dc3a`(SKILLS·notion·karaba 파생 스크립트 삭제 유지). 로컬 병렬 전체 3,350 pass · 실패 0. deploy 런 잡 5개 success. 3사 SHA `adf8dc3a`, /login 200, 3사에서 `build-clearance-set-v2.php --verify` ✅. 운영 쓰기 없음.

## 2026-10-15(5차) — 지급 결재 결과(승인완료·반려) 알림톡 수신 확장 `fcb1114f` (3사 동시, 코드 1커밋, 마이그 0)

- jin «승인완료·반려도 최고관리자에서 역할 나누었듯이 — 체크한 역할에 알림톡» → 2번안. `erp_payout_done`·`erp_payout_rejected` 가 역할 선택형(선택지 = 결재선 전원/재무/관리, 기본 결재선 전원, 제출자 항상). 결재선 전원 = steps 승인자 ∪ 실제 서명자(`payoutNoticePhones`). 요청은 종전대로 차례 1명. 템플릿 문안 무변경(BizM 재승인 불필요).
- dev `1cbe4a1b` → master `fcb1114f`(docs·notion 삭제 유지). 로컬 병렬 전체 3,347 pass · 실패 0. deploy 런 잡 5개 success. 3사 SHA `fcb1114f`, /login 200. 운영 쓰기 없음. 알림톡 안내 화면에 체크박스가 새로 뜬다(기본값 그대로면 설정 불필요).

## 2026-10-15(4차) — 통장 마감잔액 입력 = 재무·업무관리자(+대표)만 `7fc64cc6` (3사 동시, 코드 1커밋, 마이그 0)

- jin «업무대시보드에 통장잔액 입력하는게 전부 오픈될 필요없어서 — 일반사용자 재무와 업무관리자만». `User::canEnterCashBalance()` 에서 일반사용자 [관리] 제거(관리 이상 기본값의 명시적 예외). 업무·관리자 대시보드 입력 카드와 저장 액션이 같은 게이트.
- dev `048c0704` → master `7fc64cc6`(notion 2파일 삭제 유지). 로컬 병렬 전체 3,344 중 **1 실패 = 알려진 흔들림 `BuyerAccountScreenTest::test_cash_usage_is_capped_and_pages`**(순차 재실행 34/34 통과, 변경과 무관). deploy 런 38690573748 잡 5개 success. 3사 SHA `7fc64cc6`, /login 200. jin 이 잔액 입력 중이라 보류했다가 «괜찮아» 로 진행.
- 🔧 **운영 쓰기 1건(jin)**: ssancarerp 신한 원화 계좌 #8 번호 정정 `014012305310 → 140012305310`(감사로그 1줄, 잔액 이력은 계좌 id 라 무영향).

## 2026-10-15(3차) — 퇴사 정산 예치금 반환 줄 · super 제출 가능 `e4376cce` (3사 동시, 코드 3커밋, 마이그 0)

- jin «퇴사자에 예치금이 있다면 그 예치금까지 다 포함되어서 최종금액으로» → ssancarerp 실측 이승훈(#22, 10-15 퇴사, 예치금 1,000만, 확정 3건 합 19,153). 모달이 보유액 전액을 「예치금 반환」 줄(`kind=deposit`)로 선입력(지우거나 줄일 수 있음), 지급 총액·알림톡 총액엔 포함, 회사이익 3곳(profitStats·사람 카드·관리자 대시보드)에선 제외, **최종 승인 때 `salesmen.deposit_krw` 차감 + 감사로그**(반려 무변경).
- jin «시스템관리자도 버튼 보이게» → `canSubmitPayoutBatch()` 에 super 추가(재촉 [재전송]은 종전대로 제외).
- 가드 테스트 1건 추가(직원은 그 달 급여 항목이 있어야 월정산에 오른다 — jin 확인 요청에 대한 답).
- dev `16f0f69c`·`bebd04aa`·`156e098d` → master `6a3843cc`·`5456e05b`·`e4376cce`(docs·notion 삭제 유지). 로컬 병렬 전체 **3,343 pass · 실패 0**. deploy 런 38685480654 잡 5개 success. 3사 SHA `e4376cce`, Pending 0, /login 200.
- 🔧 **배포 뒤 운영 쓰기 2건(jin «배포하면서 넣어줘»·«황진영은 지워줬으면»)**:
  - heymanerp 테스트 계정 **황진영(#10) 삭제** — 6월 승인 배치 #1(2,770,750원) 제출자 10→6(이새롬)·조정 #1 작성자 이관 뒤 하드 삭제(감사로그 3줄). 소프트삭제된 담당자 행 #5 는 계정 연결만 끊김.
  - **직원 명부 백필 `salesmen:sync-staff --apply`** — ssancarerp 7명(김다정·김희연·박수정·유재숙·이솔·이정아·최현방, 관리→직원) · heymanerp 1명(이다은) · karabaerp 0. 재실행 dry-run 0명.

## 2026-10-15(2차) — 자금보고 알림톡 손익 아이템 줄(BizM 재등록본 승인 뒤) `373e9d95` (3사 동시, 코드 2커밋, 마이그 0)

- jin «자금보고 알림톡 승인 났는데 배포해도 될 것 같은데» → «KARABA 제외 2사만 변경». 순서 = BizM 승인 → 코드 배포(10-13 메모 그대로).
- dev `d22a9a36`+`8b45da57` → master `8236f7af`·`373e9d95`. 변경 4파일(`AlimtalkCapitalWeekly`·`AlimtalkTemplates`·재등록 xlsx 스크립트·`CapitalStatusTest`).
- 토글 실측(배포 전): heymanerp 켜짐(신 등록본) · ssancarerp 꺼짐(신 등록본, jin 이 켜면 발송) · **karabaerp 꺼짐(구 등록본 — 켜지 말 것, 켜면 K140류 반려)**.
- deploy 런 38674820089 잡 5개 success. 3사 SHA `373e9d95`, /login 200.

## 2026-10-15 — 퇴사자 정산 · 판매탭 적립금 1회성 · 「입금 외 정리」 줄 · 일괄 서류 업로드 체크 함께 켬 `fb72563a` (3사 동시, 코드 11커밋, **마이그 2**)

- jin 결정(10-15): 퇴사일은 **사내직원관리**(`salesmen.left_at`) · **프리랜서만** 즉시 정산 배치(`settlement_payout_batches.kind=leaver`+`salesman_id`, 달 안 닫음, 미확정·미수 보류 있으면 제출 불가, 일반 목록·일괄확정에서 제외, 퇴사자 표 안에서 확정·예외) · 사내직원은 월정산 그대로(비활성이어도 급여 줄) · 알림톡은 `erp_payout_request` 「제출」 칸 변수로만 · 승계·예치금·로그인은 수동.
- 적립금(41소7691 제보 — 판매탭 칸만 누적 편집칸): 「이번에 쓸 금액」 1회성 + 바이어×통화 잔액 초과 거부 + 잔금 위 「입금 외 정리」 색 뱃지 줄(적립금 보라·기타 노랑·합계 초록). 이력·되돌리기는 판매탭에 안 둔다(바이어 적립금 탭).
- 일괄 서류 업로드(말소증·수출신고서): 「체크박스도 함께 켬」 옵션(기본 ON) — 파일과 같은 `update()` 배열에 플래그 → 단건과 같은 훅.
- dev `b2dcb962`~`c4cd0c94` → master `6cb0c724`~`fb72563a`(docs·notion 삭제 유지). 로컬 병렬 전체 **3,341 pass · 실패 0**(HEAD). deploy 런 38673925041 잡 5개 success. 3사 SHA `fb72563a`, 마이그 2 Ran, /login 200.
- 🔧 **배포 뒤 운영 쓰기 2건(jin 승인)**:
  - heymanerp **17대 거래완료 소급** — B/L 파일 없이 출고·완납·정산 끝난 차(id 91·92·95·123·129·132·133·144·148·151·153·164·165·166·176·180·184)에 `progress_status_rule_version=5`(08-27 소급 전용 규칙) + 캐시 갱신 + 감사로그 `progress_rule_v5_backfill`. 결과 17/17 거래완료, 도착완료∧비거래완료∧B/L없음 **0대**. 정산 신규 생성 0.
  - 3사 **서류 체크 백필**(서류 있음+짝 체크 없음 → 체크 ON, 모델 save 로 cascade·감사, 감사로그 `bulk_document_flag_backfill`): ssancarerp 수출신고서 **290**(진행상태 선적완료→통관중 **263**) · heymanerp 수출신고서 **179**(→통관중 **54**) · karabaerp 말소증 1(변화 0). 바뀐 차 = 전부 「반입지+수출신고서+거래완료 아님」 — 규칙상 한 단계 앞.
- 교훈: 병렬 로그는 `⨯` 를 안 찍는다 — 판정은 `Failures:`/`exit=` 줄(2차 전체에서 1건을 놓친 채 푸시했다가 다음 커밋으로 고침). 백그라운드 테스트 래퍼에 `tail` 을 붙이면 셸이 안 끝난다(20분 잔존).

## 2026-10-14(밤 3) — FOB + 「FOB포워더」 + 완납 → 거래완료 · 병렬 테스트 rename 충돌 해결 `696a85d6` (3사 동시, 코드 1커밋, **마이그 1**)

- jin «인코텀즈 FOB + 포워딩사 FOB포워더면 판매완료 = 거래완료(B/L 은 바이어가 발급)» + «배포 하나에 20분? 둘 다 적용해서 실적용».
- dev `5628ece4` → master `696a85d6`(.md·notion 삭제 유지). `forwarding_companies.buyer_handles_bl`(기존 「FOB포워더」 행 켬 — 3사 전부 플래그 1건, 차량 0대 → 배포 직후 변화 0) · 포워딩사 관리 체크박스 · `Vehicle::progress_status` v4 에 bl_document 다음 규칙 · 거래완료 진입 훅이 정산 자동 생성.
- ⚡ **배포 절차 전환**: `AppServiceProvider::register` 가 `TEST_TOKEN` 별 `view.compiled` 분리 → 로컬 병렬 전체 **3분 45초 · 3,315 pass · rename 충돌 0**. 순차 14분 대신 이걸 게이트로 쓴다(CI 가 전체를 다시 돈다). deploy 런 38532090780 잡 5개 success(7분 11초). 배포 1회 = cherry-pick 1분 + 로컬 4분 + CI 7분 ≈ **12분**(종전 22분+).
- 효과: 실무자가 FOB 차량의 포워딩사를 「FOB포워더」로 고르면 완납 시점에 거래완료 + 정산 생성. 포워딩사 관리에서 다른 회사도 체크 가능.

## 2026-10-14(밤 2) — board sync 변경 없는 requested 행 no-op `60a5a3e2` (3사 동시, 코드 1커밋, 마이그 0)

- board 세션 3번째 요청(«선언형 sync 가 기존 묶음을 그대로 실어 와도 갱신·알람 재발사·updated 집계를 하지 않게» — heymanerp 무사백 requested 35대: 새 차 1대에 알람 35건 재발사 + 「갱신 35」). jin 「배포해줘」.
- dev `f3bcd16a` → master `60a5a3e2`(docs 삭제 유지). `ShippingRequestController::sync()` requested 분기에 `sameBundleAttrs()`(null·''·숫자 표기 동일시) → 전부 같으면 continue. 응답 모양 그대로, 어느 배열에도 안 셈. 문서 §5-2.
- 로컬 master 순차 전체 **3,308 pass · 실패 0**(815초). deploy 런 38528843204 잡 5개 success. 3사 SHA `60a5a3e2`, /login 200.
- 효과: 다음 board 선적요청부터. board 측 「안 묶인 차만」 화면(board dev `aa5c6cb`) 배포 순서 무관.

## 2026-10-14(밤) — board 포털 정산 행 `margin_rate`(마진율) 동봉 `fe21493d` (3사 동시, 코드 1커밋, 마이그 0)

- board 세션 요청(«정산 응답 3곳 차량 행에 margin_rate — jin 이 board 요약 화면 「차량번호·마진율·건당금액」 표 지시»). 마진 노출 = 대표 승인 사안이라 dev 에만 두고 jin 에게 되물음 → jin 「배포해줘」.
- dev `0545749b` → master `fe21493d`(docs 는 삭제 유지). `/settlements` · `/payout-batches` settlements[] · unbatched_paid[] 에 `Settlement::margin_rate`(승인 카드 「차량별 보기」와 같은 값, null 그대로). 마진 raw 는 여전히 없음 — 가드 `BoardPayoutBatchApiTest::test_rows_carry_margin_rate_only_and_never_raw_margin`.
- 로컬 master 순차 전체 **3,307 pass · 실패 0**(830초). deploy 런 38526320702 잡 5개 success. 3사 SHA `fe21493d`, /login 200.
- board 는 값이 없으면 「—」(board dev `e196415`) → 배포 순서 무관, board 재배포 없이 채워진다.

## 2026-10-14(저녁) — 계좌별 잔액 정정(세 통화·− 직접 입력) · 월정산 급여 항목 줄 · board /bundles 미수 4필드 `38bc1a22` (3사 동시, 코드 4커밋, 마이그 0)

- jin 지적 두 건(«신한이라고 한화만?» · «토글로 할 필요가 있나») + «기입된 항목만 펼침에» + board 세션 요청(«/bundles 차량 행에 /shippable 의 미수 4필드») → «board 쪽이랑 같이 묶어서 배포».
- dev `b449dba5`·`3c0d9639`·`80a38b20`·`f72ddf60` → master `b3fa1869`·`3a4d1e92`·`fd353ae6`·`38bc1a22`(dev 전용 notion·docs·NotionGuideAudienceTest 는 삭제 유지).
- 로컬 master 순차 전체 **3,306 pass · 실패 0**(823초). deploy 런 38521580160 잡 5개 success. 3사 SHA `38bc1a22`, /login 200.
- 효과: 업무 대시보드 잔액 = 어느 계좌든 KRW/USD/EUR 세 칸, 원화 음수는 `-` 직접(토글 없음, 계좌 0건인 heyman·karaba 의 3칸 폼도 동일). 월정산 카드 펼침에 기입된 급여 항목만 줄. board 선적 계획의 「환율 미입력」 거짓 칩은 board 가 /bundles 를 다시 읽는 순간 사라진다(board dev `cf1cebc`·`32c5de9` 배포 순서 무관).
- 같은 날 운영 쓰기(jin 지시): ssancarerp `payroll:clear-legacy-base-salary --apply` 21명 비움(heyman·karaba 0). `salesmen:sync-staff --apply` 는 아직 승인 대기(ssancarerp 7 · heymanerp 1).

## 2026-10-14 — 매입총액 컬럼 · 전화번호 검색 · 전 컬럼 정렬 · 직원 명부 확장(검차직원 사용자관리 등록) · 급여대장 xls 업로드 · 계좌별 통장 잔액 `d17d9a22` (3사 동시, 코드 10커밋, **마이그 4**)

- jin «한번에 하면 어디서부터 어디까지?» → master `12f9e720` 이후 dev 전부에서 BizM 승인 대기 2건(`d22a9a36`·`8b45da57`)만 제외. dev→master cherry-pick 10건(`e2b07003`·`297a9003`·`6d8c0ed5`·`c4d4c66a`·`3bd85983`·`3da0c0cb`·`c702a712`·`90e8374b`·`55897eda`·`1bbbec1e`; `6cbe08a0` 은 settings.local.json 되돌림이라 빈 커밋 → skip). 충돌 = `.claude/settings.local.json`(master 판 유지) · dev 전용 `scripts/notion-*`·`docs/*`·`tests/Feature/NotionGuideAudienceTest.php`(삭제 유지).
- 마이그 4건: `salesmen.type` enum +staff · `users.email` nullable(검차직원 = 로그인 없음, unique 인덱스 유지 확인) · `salesmen.employee_no` · `cash_accounts`+`cash_account_balances`. 3사 `migrate:status` 전부 Ran(heymancar 배치 69 · karaba 78 · heysellcar 84). 라운드테이블은 컬럼·테이블 추가뿐이라 생략(jin 에게 고지).
- 로컬 master 순차 전체 **3,303 pass · 실패 0**(771초). deploy 런 38515803228 잡 5개 success. 3사 SHA `d17d9a22`, /login 200.
- 배포 뒤 운영 쓰기(jin 「SSANCARERP에 계좌등록은 너가 해줘」): ssancarerp `cash_accounts` 7건 생성(잔액계좌정보.txt — KRW 6 + 하나 외화 USD/EUR 1, 감사로그 `cash_account`). heyman·karaba 는 계좌 0 → 종전 3칸 폼.
- `salesmen:sync-staff` dry-run: **ssancarerp 7명**(김다정·김희연·박수정·유재숙·이솔·이정아·최현방, 전부 user/관리 → staff) · heymanerp 1명(이다은 user/관리) · karaba 0. **apply 는 jin 승인 대기.**
- 효과 시점: 컬럼·정렬·검색 = 즉시. 명부 탭·검차직원 역할 = 즉시(기존 관리직은 apply 뒤). 급여대장 업로드 = 실무자가 09월 파일 올릴 때(이름 다른 2명 수동 연결 1회). 계좌별 잔액 = ssancarerp 다음 입력부터 — 첫날 7개 전부 넣어야 합계가 맞다(미입력 경고 띠).

## 2026-10-13(저녁) — 알림톡 재시도·09:30 따라잡기 + ko 누수 + board 라벨 ko + 연동 판매값 감사 `12f9e720` (3사 동시, 코드 4커밋, 마이그 0)

- jin «지금 바로 올릴 건들 추려봐, BizM 만 빼고». dev `987321ea`(알림톡 ConnectionException 3회 재시도 + 일간 9종 09:30 재스케줄, `once_per_day` 코드·번호·차량 단위 중복 방지, 로그 안 남김) · `f92a482f`(lang/ko auth·pagination·passwords + ko.json + 설정/인증 화면 `__()`, 가드 2건) · `560509ef`(board 요청 알림톡 라벨 `__(…,'ko')` — 운영 heymanerp·ssancarerp `.env APP_LOCALE=en` 실측, `.env.example` ko) · `4fa56225`(AUDITED_COLUMNS sale_date·currency + 연동 생성 시 sale_price/currency/exchange_rate/sale_date/buyer_id/consignee_id 값 있는 칸만 inbound_purchase_sync 행) → master `f13a784a`·`a9d9d84d`·`8808b6d0`·`12f9e720`.
- 건너뜀: `13bbf6a8`(scripts/nightly ps1 BOM — master 에 그 폴더 없음, 회사 PC 사본은 직접 교체됨) · `d22a9a36`+`8b45da57`(자금보고 손익 아이템 줄 — **BizM 재승인 전 배포 금지**, dev 대기).
- 로컬 전체(master 조합) 3,278건: 병렬 rename 경합 7 Error → 7개 클래스 순차 59 pass, 실패 0. deploy 런 잡 5개 success. 3사 `12f9e720`, `lang/ko/pagination.php` 존재, 09:30 스케줄 반영.
- 효과 시점: 한글 누수·감사로그 = 즉시. 알림톡 재시도 = 다음 발송부터. 09:30 따라잡기 = 10-14 평일부터. board 라벨 = 다음 board 요청부터.

## 2026-10-13 — 모바일: 자금추이 스파크라인 DPR 자기증식 + 폰 줄바꿈 6곳 + 알람 카드 벨 아이콘 `62638f24` (3사 동시, 코드 4커밋, 마이그 0)

- jin «모바일에서 관리자대시보드 그래프 깨지던데». 원인 = `app.js drawCapitalTrend` 가 캔버스 자신의 clientWidth 를 재서 `width = w×dpr` 로만 키우고 CSS 크기를 안 잡아, 다음 측정이 그 값을 읽어 30초 폴링·resize 마다 배로 커짐(Playwright 실측 DPR 3: 900→2700→8100px, DPR 2: 600→1200→2400). 데스크탑 DPR 1 은 무증상. 고침 = 부모 폭 측정 + `cv.style.width/height` 고정 + canvas `block w-full`. 가드 `CapitalTrendCanvasSizingTest`(정적, 깨뜨려 확인). SKILLS §8 #116.
- 폰 390px 로 22개 화면 전수 캡처(`playwright-core` + 설치된 Chrome, 스크래치패드): main 가로 넘침 = 차량관리 하단 도구줄(459)·선적요청 액션 묶음(485) 2곳, 글자 세로 쪼개짐 = 월정산 승인줄 [반려]·정산관리 제목/[예외 대상]·회사이익 순위표·자금현황 머리줄. 전부 `flex-wrap`/`whitespace-nowrap`/`min-w-[560px]` 로, 데스크탑은 `sm:` 로 종전 유지. 도구줄 nowrap 은 컨테이너가 아니라 버튼 5개에(팝오버 상속 차단, advisor 지적). 재스캔 넘침 0.
- 통관서류 알람 고정 카드(330px) = jin A안: 폰(sm 미만)은 새 알람이 있어도 접힌 벨로 시작, 글자 없이 🔔 + 건수 배지만(jin «그냥 벨 아이콘으로»). 데스크탑 자동 펼침 그대로. 가드 `AlarmCardPhoneCollapsedTest`.
- 재무탭 「인원별 정산지급액」 빈 차트는 fullPage 캡처 도구 영향 — 탭 전환·새 진입 모두 정상(2번째 버그 아님).
- dev `3647830e`·`0c096117`·`d9339257`·`abbf5787` → master `9e5abaaa`·`ab9d9bff`·`e0a43f99`·`62638f24`(충돌 0). 로컬 전체 3,274 pass(병렬, 실패 0). deploy 런 38340231717 잡 5개 success. 3사 서버 `62638f24`, 번들 `app-BgGEadUn.js` 에 `parentElement.clientWidth` 1·`style.width=…+"px"` 2 확인.
- 실기기 확인 = heymanerp 관리자 대시보드 자금현황(실데이터)에서 폰으로. 로컬 샘플 DB 에 재현용 통장 마감잔액 3건(10-08·10-10·10-13) 추가됨. 가이드 소스 `notion-guide-publish.php` 통관서류 알람 callout 에 폰 한 줄 추가(dev `154a449e`, Notion 발행은 Codex). 미배포 앱코드 = **0건**.

## 2026-10-13 — board 요청 「거절」 씬 + 기본정보탭 등록정보 배치 `46627df1` (3사 동시, 코드 4커밋, 마이그 1)

- dev `508b6b2a`(거절 씬) · `2463fd40`(since tz) · `0c86e032`(감사로그 한글 라벨) · `408c0dea`(기본정보 배치) → master `3bb5d887`·`9d4bb6ef`·`53322e66`·`46627df1`. `cards.json` 충돌 → 삭제 유지.
- 1차 push `53322e66` 은 **CI `tests/ci` 실패로 3사 배포 skipped**(게이트 정상 작동) — `BuyerAccountScreenTest::test_cash_usage_is_capped_and_pages`(「더 보기」 뒤 R3 미표시). 로컬 전체 2회 통과·코드 무관, 재실행 대신 배치 커밋을 얹어 다시 push → 같은 테스트 포함 전부 success ⇒ **flaky**(원인 미확정 — 재발하면 그 테스트의 `set('buyerId')` 뒤 렌더 타이밍을 볼 것).
- 배포 전 로컬 전체: GD 7 오진 해소 뒤라 실패 0(병렬 rename 경합 1 → 순차 통과). deploy 런 38332091561 잡 5개 success. 3사 서버 `46627df1`, `add_reject_to_board_requests` Ran, `reject_reason` 컬럼 존재.
- board 쪽: 칩 수정 `42c92be` 가 10-13 10:02 선배포(거절 → 빨간 「거절」+사유). ERP 가 뒤따름(순서 준수). 웹푸시는 board VAPID 미설정이라 아직 no-op.
- 거절 흐름 운영 확인 = 첫 실제 거절 건이 생기면 board 칩까지 이어지는지 본다(heymanerp open 요청 현황은 위 open= 값).
