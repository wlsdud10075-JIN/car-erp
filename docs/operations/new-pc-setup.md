# 새 PC 세팅 · 다른 PC 에서 재개

> 2026-11-23 `CLAUDE.md` 에서 옮겨 왔다(매 세션 읽을 필요가 없는 절차). 내용은 그대로다.

## ⚠️ APP_KEY 영구 손실 경고

**`php artisan key:generate` 사용 시 RRN(주민등록번호) 전체 영구 손실 위험**

- `nice_reg_owner_rrn` 컬럼은 `APP_KEY`로 암호화 저장. 키가 바뀌면 **모든 RRN 데이터 복호화 불가**, DB 백업으로도 복구 안 됨.
- **집/회사 양쪽 PC**: 한 PC에서 발급한 APP_KEY 값을 1Password 등에 백업 → 다른 PC `.env`에 동일 값 직접 입력. `key:generate` 절대 실행 금지.
- **운영 배포 시**: 최초 1회만 `key:generate` → 즉시 백업 → 재배포 시 동일 키 유지.
- **DB 백업 시**: APP_KEY도 별도 위치에 함께 백업 (분리 보관, 한쪽 유실해도 다른 쪽 보존).
- 상세 가이드 + 사고 복구 절차: `docs/operations/key-rotation.md`

## 새 PC 세팅
```bash
# 1. PHP 확장 활성화 — XAMPP php.ini (C:\xampp\php\php.ini)에서 주석 제거 필수
#    extension=gd        # PhpSpreadsheet 의존성
#    extension=zip       # PhpSpreadsheet 의존성
#    (Apache 사용 시 Apache 재시작)

git clone https://github.com/wlsdud10075-JIN/car-erp.git
cd car-erp
composer install && npm install
cp .env.example .env && php artisan key:generate    # ⚠️ 다른 PC면 기존 키 복사 (위 경고 참조)
# .env DB_DATABASE=car_erp 확인
# MySQL: CREATE DATABASE car_erp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
php artisan migrate && php artisan db:seed
npm run build
php artisan serve --port=8001
# 다른 PC 접속: --host=0.0.0.0
# 테스트 계정: admin@car-erp.test / password (시스템관리자, permission=super)
```

### 다른 PC에서 작업 재개 절차

```powershell
# 1. dev 브랜치 최신화
git checkout dev && git pull origin dev

# 2. 마이그 누락 확인 (필수)
php artisan migrate:status
php artisan migrate     # Pending 있으면 실행

# 3. 의존성 + 자산 (composer.lock / package-lock.json 변경 있으면)
composer install
npm install
npm run build

# 4. 캐시 클리어
php artisan view:clear && php artisan cache:clear

# 5. 자동 테스트 회귀
php artisan test

# 6. 개발 서버
php artisan serve --port=8001
```
