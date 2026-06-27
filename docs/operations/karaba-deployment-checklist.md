# karaba 배포 체크리스트 (2번째 회사 온보딩)

> **구조 결정 (2026-06-13)**: 멀티테넌트 아님. **단일 master 코드 + 회사별 인스턴스/DB/S3/도메인/APP_KEY 분리.**
> 분기는 코드가 아니라 **설정(.env)·프로파일(전략클래스)** 로 처리. karaba가 직접 써보고 변경요청 → 정산 등 *국지적*이면 프로파일로 흡수, *광범위*해지면 그때 **별도 repo(fork)**. 브랜치는 안 만듦.
> 상세 배포 절차/이슈는 `aws-deployment-record.md`(heyman 기준) 참조 — 이 문서는 **karaba 차이점 + 순서**만.
>
> **서버 명칭(2026-06-13)**: heyman=현재 운영서버(구 "ssancar/현재서버") / karaba=이 문서 대상(2번째 회사) / ssancar=미래 대물량 싼카 서버. 회사(법인)는 싼카·카라바 2개 — heyman·ssancar 서버는 같은 싼카 법인이라 `system/` 양식 공용, karaba만 `karaba/` 양식. "SSANCAR 상호/계좌"는 싼카 법인 정보(양식에 인쇄).

---

## A. 시작 전 준비물 (jin → Claude)

| 항목 | 필요 값 | 비고 |
|---|---|---|
| AWS 계정 | karaba 계정 접근 (받음 ✓) | 인스턴스 생성 권한 |
| 도메인 | karaba 운영 도메인 | certbot HTTPS용 (없으면 IP 임시) |
| **회사정보** ✓받음 | 상호(국/영)·주소·사업자번호·대표·**매매업등록번호**·은행계좌(SWIFT) | 서류 출력용 — `templates/karaba/` 셀에 기입 완료(B-2). 회사정보 .env 키 불필요 |
| 관리자 계정 | ADMIN_*/BOSS_* 이메일·비번 | 시드 생성 |
| NICE 연동 | karaba 매매업 기준 토큰 (쓸 경우) | 안 쓰면 수기 입력 fallback |

---

## B. 코드 선결 과제 (배포 전, dev에서 — 양쪽 공통 이득)

### B-1. (선택) 없음 — 첫 배포는 현재 master 그대로 가능

### B-2. ⚠️ karaba용 서류 템플릿 세트 (karaba 서류 전에 필수)

**실태 (2026-06-13 확인)**: `config/company.php` 는 **코드 어디서도 안 읽힘**(dead config, 옛 dompdf 잔재). 회사정보(상호·주소·사업자번호·**계좌·SWIFT·매매업번호**)는 **`resources/templates/system/*.xlsx` 9개 템플릿에 인쇄**돼 있음. DocumentFiller 는 노란셀(차량데이터)만 채우고 회사정보는 템플릿 정적텍스트 그대로 출력.

**해결**: 테넌트별 템플릿 폴더 분기.
1. (코드, 1회) `DocumentFiller` 가 `config('company.template_set', 'system')` 기준으로 `resources/templates/{set}/` 에서 로드하도록 — `.env COMPANY_TEMPLATE_SET=karaba`. heyman 등 싼카 서버는 default 'system' → 무영향.
2. (데이터) `resources/templates/karaba/` 에 9개 복사 + **회사정보 셀만 karaba 값으로 교체** (상호·주소·사업자번호·계좌·SWIFT·매매업번호·대표). ← **karaba 회사정보 회신 필요**.
→ karaba가 인보이스/계약서 출력 *전* 완료 (안 하면 karaba 서류에 SSANCAR 상호·계좌 찍힘).
→ **코드 분기(1)는 지금 가능 / 템플릿 교체(2)는 karaba 정보 후.**

---

## C. 인프라 (karaba AWS)

1. **인스턴스**: Ubuntu (Lightsail 단일 또는 EC2). 고정 IP 할당.
2. **방화벽**: 22(SSH)·80·443 오픈.
3. **소프트웨어 스택** (heyman 배포기록 §1 동일):
   ```bash
   # PHP 8.4 (ondrej PPA)
   sudo apt install -y php8.4-fpm php8.4-mysql php8.4-gd php8.4-zip \
     php8.4-mbstring php8.4-xml php8.4-curl php8.4-bcmath php8.4-intl \
     php8.4-dom php8.4-simplexml          # ← dom/zip 누락 주의(heyman 배포 실패 사례)
   sudo systemctl restart php8.4-fpm
   # zip CLI 미인식 시: sudo phpenmod zip && sudo systemctl restart php8.4-fpm
   sudo apt install -y nginx mysql-server composer
   # node: nvm 또는 nodesource로 LTS
   ```

## D. DB (karaba 전용 — 격리)

```sql
CREATE DATABASE karaba_erp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'karaba_erp_user'@'127.0.0.1' IDENTIFIED BY '***';   -- 별도 비번
GRANT ALL ON karaba_erp.* TO 'karaba_erp_user'@'127.0.0.1';
```

## E. 코드 배포

```bash
cd /var/www && sudo git clone https://github.com/wlsdud10075-JIN/car-erp.git
cd car-erp && git checkout master
sudo chown -R ubuntu:ubuntu /var/www/car-erp
composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev
npm ci && npm run build
```

## F. .env (karaba 전용 — ⚠️ 회사별 분리 항목 강조)

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_NAME="karaba ERP"
APP_URL=https://(karaba 도메인)
APP_KEY=                              # ← G단계에서 1회 생성 (절대 heyman 키 재사용 X)

DB_DATABASE=karaba_erp                # ← 분리
DB_USERNAME=karaba_erp_user           # ← 분리
DB_PASSWORD=***                       # ← 분리

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database

VEHICLE_DOCS_DISK=s3
DB_BACKUP_DISK=s3
MYSQLDUMP_PATH=/usr/bin/mysqldump
AWS_ACCESS_KEY_ID=***                 # ← karaba IAM 키
AWS_SECRET_ACCESS_KEY=***
AWS_DEFAULT_REGION=ap-northeast-2
AWS_BUCKET=karaba-erp-docs            # ← 분리 버킷 (RRN 서류 격리 필수)
AWS_USE_PATH_STYLE_ENDPOINT=false

ADMIN_EMAIL=***                       # ← karaba 관리자
ADMIN_PASSWORD=***
BOSS_EMAIL=***
BOSS_PASSWORD=***

# 서류 양식 세트 — karaba 회사정보는 templates/karaba/ 에 인쇄됨(B-2). 별도 회사정보 .env 키 불필요.
COMPANY_TEMPLATE_SET=karaba

# ⚠️ NICE는 ssancarerp(heymancar.com 박스) 게이트웨이 경유 — karaba IP는 NICE 화이트리스트 밖이라 직접 호출 불가!
NICE_PROVIDE_URL=https://heymancar.com/provide/api/nice-lookup/   # ssancarerp 게이트웨이 경유 (직접 X)
NICE_PROVIDE_TOKEN=***                # 게이트웨이 토큰 (현재 게이트웨이는 미검증이나 설정 권장)
# 🚫 NICE_DIRECT_* 절대 설정 금지 — 설정하면 karaba가 NICE 직접 호출 시도→IP 화이트리스트 밖이라 실패.
#    NICE_DIRECT_*(API_KEY/LOGIN_ID/BUSINESS_NUMBER) 는 ssancarerp(54.116.7.83)만. 상세=docs/operations/nice-gateway-migration.md
```

> **🏷️ NICE 분리 요약** (2026-06-27 게이트웨이 이식 후): NICE 직접 호출은 `NICE_DIRECT_*` .env 로만 켜짐. **ssancarerp만 설정**(IP 화이트리스트=그 박스). heymanerp·karabaerp 는 `NICE_DIRECT_*` **비우고** `NICE_PROVIDE_URL=https://heymancar.com/provide/api/nice-lookup/` 로 ssancarerp 경유. master 단일 코드라도 .env 가 동작을 가름 — karaba 배포 시 `NICE_DIRECT_*` 건드리지 말 것.

## G. ⚠️ APP_KEY (최우선 — RRN 영구 손실 방지)

```bash
php artisan key:generate              # ← karaba 전용, 최초 1회만
```
→ 생성 즉시 **별도 안전저장소(1Password 등)에 백업**. 절대 재실행 금지(`nice_reg_owner_rrn` 복호화 불가 = 영구손실). heyman 키와 **완전 별개**.

## H. 마이그레이션 + 시드

```bash
php artisan migrate --force           # CHECK 제약 이슈는 코드에 이미 반영됨
php artisan db:seed                    # ProductionSeeder만(국가·항구·설정·관리자2). 더미 미유입
php artisan storage:link
```
→ 검증: 관리자 계정 2개만, 더미 차량/바이어 0건.

## I. 권한 (heyman 배포기록 §8 — ubuntu vs www-data 함정)

```bash
cd /var/www/car-erp
sudo usermod -aG www-data ubuntu
sudo chown -R ubuntu:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod g+s {} \;
# → SSH 재접속
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

## J. Nginx + HTTPS

1. `/etc/nginx/sites-available/car-erp` — root `/var/www/car-erp/public`, php8.4-fpm.sock, `client_max_body_size 50M`. default 사이트 비활성.
2. `sudo certbot --nginx -d (karaba 도메인) -d www.(karaba 도메인)` → APP_URL https 확인 → `config:cache`.

## K. Cron (백업 + 스케줄)

```bash
crontab -e
* * * * * cd /var/www/car-erp && php artisan schedule:run >> /dev/null 2>&1
```
→ 매일 03:00 `db:backup` 자동(로컬 + S3). 첫날 익일 03:00 백업 1건 생성 확인.

## L. S3 (karaba 전용 버킷 — RRN 격리)

1. **버킷** `karaba-erp-docs` (서울, 퍼블릭 차단, 버전관리, SSE-S3). 라이프사이클 `db-backups/` 90일.
2. **IAM** 사용자 `karaba-erp-s3-user` (프로그램 전용) + 인라인 정책(PutObject/GetObject/DeleteObject/ListBucket, 리소스 = karaba 버킷 ARN 한정).
3. 액세스 키 → `.env` AWS_*. 키 CSV 별도 보관.

## M. 자동배포 타깃 추가 (브랜치 아님 — 배포 대상 목록)

현재 `.github/workflows/deploy.yml` 은 단일 타깃(Secrets `DEPLOY_HOST` 등). karaba 추가 방법:
- **GitHub Environments**: `Production`(heyman) + `Production-karaba`(karaba) 각자 DEPLOY_* 시크릿 → deploy.yml 에 **matrix/job 2개**로 양쪽 배포.
- 또는 karaba는 **수동배포**(초기): karaba 서버에서 `git pull && composer install --no-dev && npm ci && npm run build && php artisan migrate --force && ... && php artisan up`.
- karaba 서버 `~/.ssh/authorized_keys` 에 배포용 공개키 등록 필요.
- ⚠️ 양쪽 동시 자동배포면 master 푸시 1번에 둘 다 배포됨. 단계적 롤아웃 원하면 karaba는 수동/태그배포로.

## N. 검증

- [ ] 로그인(관리자) → 대시보드
- [ ] NICE 차량조회(쓸 경우) 정상
- [ ] **서류 출력 → karaba 상호/사업자번호 찍히는지** (B-2 확인)
- [ ] 차량 사진/서류 업로드 → karaba S3 버킷 적재
- [ ] 익일 03:00 백업 1건(로컬+S3)

---

## O. 향후 분기 (요청 들어올 때)

| 요청 유형 | 처리 |
|---|---|
| 정산 로직 다름 | `SettlementProfile` 전략클래스(ssancar/karaba) + `config('company.profile')` |
| 회사정보·브랜딩 | .env (B-2 패턴 확장) |
| UI 일부(필드·메뉴·토글) | Setting 토글 / 조건분기 |
| UI 전체·대시보드 전반 갈아엎기 | 누적되면 **fork(별도 repo)** 신호 — 브랜치 아님 |

**판단 룰**: "이 변경, heyman(싼카)에도 좋은가?" → 응=공통코드 / 아니=karaba격리(작으면 프로파일, 크고 계속 쌓이면 fork).
