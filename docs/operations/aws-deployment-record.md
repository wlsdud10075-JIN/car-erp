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

### 14-1. 도메인 + HTTPS (미완 — 보류 중)
- 이 인스턴스는 `heysellcar.com`(현재 구 HEYMAN_ERP 인스턴스 사용 중)을 대체할 예정.
- **car-erp 안정화 후 전환**: 구 인스턴스에서 도메인 분리 → NEW_CAR_ERP에 할당 →
  certbot HTTPS 발급 → `.env` `APP_URL`을 https 도메인으로 변경 → `config:cache`.
- 구 HEYMAN_ERP 인스턴스는 전환 후에도 한동안 유지(IP 직접 접근 가능, 데이터 보존).
- 안정화 기간 동안은 `http://52.79.200.151`로 운영.

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
| 도메인 + HTTPS | ⬜ 보류 (안정화 후) |
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
