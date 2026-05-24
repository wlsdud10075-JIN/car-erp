# AWS Lightsail 배포 런북 (큐 13)

> 대상: SSANCAR ERP 운영 배포. 사용자 ~20명 규모 → **Lightsail 단일 인스턴스(앱+MySQL) + db:backup S3 업로드 + Lightsail 스냅샷** 권장.
> 코드/설정 준비(S3 디스크 전환·백업 스케줄·deploy.yml)는 완료. 아래는 **사용자가 AWS에서 실행**할 절차.

---

## 0. ⚠️ APP_KEY — 최우선 (RRN 영구 손실 방지)

`nice_reg_owner_rrn`·`purchase_seller_account` 는 `APP_KEY` 로 암호화 저장된다. **키가 바뀌면 전 데이터 복호화 불가(백업으로도 복구 불가).**
- 최초 1회만 `php artisan key:generate` → **즉시 값 백업**(1Password 등) → 이후 절대 재발급 금지.
- deploy.yml 은 `.env` 를 건드리지 않음(gitignore) → 재배포 시 키 보존.
- 상세: `docs/operations/key-rotation.md`.

---

## 1. 인스턴스 + 소프트웨어

1. Lightsail 인스턴스 생성: Ubuntu 22.04+, 2GB RAM(최소) ~ 4GB(권장). 고정 IP 할당.
2. 설치: Nginx, PHP 8.4(+ `fpm gd zip mbstring xml curl mysql bcmath intl`), MySQL 8, Composer, Node 22, git, unzip.
   - `extension=gd`·`extension=zip` 필수(PhpSpreadsheet 서류 생성).
3. MySQL: `CREATE DATABASE car_erp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;` + 전용 유저.
4. Nginx: document root `/{path}/public`, PHP-FPM 소켓 연결. (Laravel 표준 vhost)
5. HTTPS: certbot(Let's Encrypt)로 도메인 인증서.

---

## 2. 최초 배포 (수동 1회)

```bash
cd /var/www                      # 예시 경로
git clone https://github.com/wlsdud10075-JIN/car-erp.git
cd car-erp
git checkout master              # 배포 브랜치
composer install --no-dev --optimize-autoloader
npm ci && npm run build
cp .env.example .env
# .env 편집 (아래 §3) — DB·APP_URL·S3·NICE·디스크
php artisan key:generate          # ★ 최초 1회. 값 즉시 백업. 재발급 금지.
php artisan migrate --force
php artisan db:seed               # 최초 관리자 계정 등 (운영 데이터 없을 때만)
php artisan storage:link
php artisan config:cache && php artisan route:cache && php artisan view:cache
sudo chown -R www-data:www-data storage bootstrap/cache
```

---

## 3. .env (운영) 핵심 항목

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://erp.example.com
APP_KEY=                          # key:generate 1회 후 고정 (백업 필수)

DB_CONNECTION=mysql
DB_DATABASE=car_erp
DB_USERNAME=...
DB_PASSWORD=...

# 업로드 서류(말소·수출신고서·B/L) → S3 (인스턴스 용량 확보)
FILESYSTEM_DISK=local
VEHICLE_DOCS_DISK=s3
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=ap-northeast-2
AWS_BUCKET=ssancar-erp-docs
# AWS_URL=https://...           # CloudFront/커스텀 도메인 쓸 때만

# DB 백업도 S3 로 (단일 인스턴스 유실 대비)
DB_BACKUP_DISK=s3
MYSQLDUMP_PATH=/usr/bin/mysqldump

# NICE — ssancar-erp 미들웨어 경유
NICE_PROVIDE_URL=https://{ssancar}/provide/api/nice-lookup/
NICE_PROVIDE_TOKEN=...
```

> `VEHICLE_DOCS_DISK` 와 `DB_BACKUP_DISK` 가 같은 버킷이어도 됨(경로 분리: 업로드=`vehicles/`, 백업=`db-backups/`). 별도 버킷 권장.
> 기존 로컬 업로드 파일은 자동 이전되지 않음 — 신규 배포(데이터 없음)면 무관.

---

## 4. S3 버킷

1. 버킷 생성(예: `ssancar-erp-docs`). 퍼블릭 접근은 정책에 맞게(업로드 문서는 보통 비공개 → presigned 또는 비공개 유지).
2. IAM 사용자: 해당 버킷 `s3:PutObject/GetObject/DeleteObject/ListBucket` 권한 → 그 키를 `.env` AWS_* 에.
3. **백업 보관주기**: 버킷 lifecycle 규칙으로 `db-backups/` prefix 30~90일 후 만료 설정(로컬 `--keep` 와 별개로 S3 측 정리).

---

## 5. DB 일일 백업 cron

`db:backup` 은 매일 03:00 으로 스케줄 등록됨(`routes/console.php`). 서버 cron 에 스케줄러 1줄:

```bash
crontab -e
# ↓ 추가
* * * * * cd /var/www/car-erp && php artisan schedule:run >> /dev/null 2>&1
```

→ 매일 mysqldump → `storage/backups/db/` (로컬 30일) + `DB_BACKUP_DISK=s3` 면 `s3://.../db-backups/` 업로드.
수동 즉시 백업: `php artisan db:backup`.

---

## 6. 자동 배포 (deploy.yml)

`master` 브랜치 push 시 GitHub Actions(`.github/workflows/deploy.yml`)가 서버에 SSH 배포.

> ⚠️ **순서 절대 준수**: §2 최초 수동 배포(git clone + `key:generate` + `.env` 완성 + 마이그)를 **반드시 먼저** 끝낸 뒤에 `master` push 자동배포에 합류한다. deploy.yml 은 `git reset --hard` + `migrate --force` 를 돌리므로, 서버가 git clone 상태가 아니거나 `.env`/APP_KEY 가 없으면 첫 자동배포가 깨진다.

**GitHub 저장소 → Settings → Environments → "Production" → Secrets 등록**:
| Secret | 값 |
|---|---|
| `DEPLOY_HOST` | 인스턴스 고정 IP/도메인 |
| `DEPLOY_USER` | SSH 유저(예: ubuntu) |
| `DEPLOY_SSH_KEY` | 배포용 SSH 개인키(서버 authorized_keys 에 공개키 등록) |
| `DEPLOY_PORT` | SSH 포트(보통 22) |
| `DEPLOY_PATH` | 서버 프로젝트 경로(예: /var/www/car-erp) |

배포 스텝(자동): `down → git reset --hard origin/master → composer --no-dev → npm build → migrate --force → 캐시 → php-fpm reload → up`.

**사전 준비**:
- 서버에서 `git reset --hard` 가 동작하도록 최초 배포가 git clone 으로 돼 있어야 함(§2).
- `sudo systemctl reload php8.4-fpm` 무패스워드 sudo 또는 PHP-FPM 서비스명 조정(deploy.yml 의 해당 줄).
- ⚠️ `migrate --force` 가 매 배포 자동 실행 — 위험 마이그레이션은 dev 에서 충분히 검증 후 master 머지.

**워크플로우(dev→master)**: 로컬 dev 개발 → push dev → `master` 로 머지(.md 제외 — CLAUDE.md 규칙) → deploy.yml 자동 발동.

---

## 7. 배포 후 점검
- [ ] `https://도메인` 로그인 (admin@... )
- [ ] 차량 등록 → 서류 업로드 → S3 버킷에 `vehicles/{id}/` 객체 생성 확인 + "기존 파일 보기" 링크 동작
- [ ] 차량 사진 5~10장 업로드 → S3 `vehicles/{id}/photos/` 객체 생성 + 갤러리 썸네일·개별 삭제 동작
- [ ] 사진 있는 차량 force-delete → `vehicle_photos` row 자동 삭제(MySQL FK cascade 검증 — 테스트는 SQLite라 미검증분)
- [ ] `php artisan db:backup` 수동 1회 → `storage/backups/db/` + S3 `db-backups/` 확인
- [ ] NICE 조회(차량번호+소유자명) — `.env` 2변수 채운 경우
- [ ] 서류 9종 + 다중차량 선적 다운로드
- [ ] 다음날 03:00 후 자동 백업 1건 생성 확인 (cron 동작)
