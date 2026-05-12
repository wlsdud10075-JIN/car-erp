# 🔑 APP_KEY 관리 가이드 — 집/회사/배포

> 작성: 2026-05-12 (큐 2.5번 C8)
> 대상: 1인 개발자(집-회사 양쪽 작업) + 운영 배포자

---

## 핵심 개념 — 왜 APP_KEY가 중요한가

`APP_KEY`는 Laravel이 **RRN(주민·법인등록번호)을 암호화·복호화하는 마스터 키**입니다 (큐 7번 RRN 보호 작업).

- 키가 다르면 같은 RRN도 못 읽음
- 키가 바뀌면 **암호화된 모든 RRN이 영구 복호화 불가**
- DB 백업 있어도 RRN만 복구 안 됨 (백업 당시 키 필요)

`.env` 파일은 **`.gitignore`에 등록되어 git에 안 올라감**. 그래서 `git pull`로는 동기화 안 됨 — 직접 양쪽 PC에 같은 값을 넣어야 함.

---

## 🏢 회사 PC에서 지금 해야 할 일 (퇴근 전)

### 1. 현재 APP_KEY 백업

```powershell
# 방법 A — tinker
php artisan tinker
>>> config('app.key')

# 방법 B — .env 파일 열기
notepad .env
# APP_KEY=base64:xxxxxxxxxxxxxxxxx... 줄 통째로 복사
```

### 2. 안전한 곳에 저장

권장 (우선순위 순):
1. **1Password / Bitwarden** 같은 password manager (가장 안전)
2. **클라우드 메모** (iCloud Notes / Google Keep) — 본인만 접근
3. **USB / 종이** (오프라인 백업)

⚠️ 슬랙·이메일·git에 절대 저장 금지.

---

## 🏠 집 PC에 도착 후

### 시나리오 A — 처음 세팅하는 경우

```powershell
git clone https://github.com/wlsdud10075-JIN/car-erp.git
cd car-erp
composer install
npm install
copy .env.example .env

# ⚠️ 여기서 php artisan key:generate 실행하지 말 것!
# 대신 .env 파일 열어서 회사 PC와 동일한 APP_KEY 값 붙여넣기
notepad .env
# APP_KEY=base64:회사PC와_동일한_값  ← 직접 입력

# DB 준비
# MySQL: CREATE DATABASE car_erp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
php artisan migrate
php artisan db:seed
npm run build
php artisan serve --port=8001
```

### 시나리오 B — 이미 집 PC가 세팅돼 있고 git pull만 받는 경우

```powershell
git pull origin dev
composer install   # 새 패키지 있을 수 있음
php artisan migrate   # 새 마이그레이션 있을 수 있음
# .env는 손대지 말 것 (회사 PC와 동일 키 유지)
npm run build
php artisan serve --port=8001
```

### ⚠️ 절대 금지 행위

| 행위 | 결과 |
|---|---|
| `php artisan key:generate` (키 이미 있을 때) | 모든 RRN 영구 손실 |
| `.env` 파일 삭제 후 `.env.example` 복사 + key:generate | 동일 결과 |
| 회사 ≠ 집 APP_KEY (다른 값) | 한쪽에서 입력한 RRN을 다른 쪽에서 못 읽음 |
| `.env` 파일을 git에 commit | 키 노출 |

---

## 📦 DB 백업·복원 (집↔회사 데이터 이동)

### 회사 → 집 데이터 이동

```powershell
# 회사 PC: DB dump
mysqldump -u root car_erp > car_erp_$(date +%Y%m%d).sql

# (.env 파일도 별도 안전한 곳에 백업)
copy .env env_backup.txt

# USB 또는 클라우드로 .sql 파일 이동

# 집 PC: import
mysql -u root car_erp < car_erp_20260512.sql
```

**중요**: APP_KEY가 같아야 RRN 정상 복호화. 다르면 RRN 컬럼이 깨진 문자열로 보이고 accessor는 null 반환.

---

## 🚀 운영 배포 시 (AWS Lightsail 등)

### 최초 배포

```bash
# 운영 서버에서 1회만 실행
git clone https://github.com/wlsdud10075-JIN/car-erp.git
cd car-erp
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate    # ⚠️ 운영 키 발급 — 단 1회

# .env의 APP_KEY 값을 즉시 백업 (필수!)
# → AWS Secrets Manager / 회사 password manager / 종이

# 나머지 설정
nano .env   # DB 정보 / APP_DEBUG=false / HTTPS 설정 등
php artisan migrate --force
php artisan storage:link
php artisan config:cache
php artisan view:cache
```

### 재배포 (이미 운영 중)

```bash
git pull origin master
composer install --no-dev --optimize-autoloader
# ⚠️ key:generate 실행 금지 — 기존 .env 유지
php artisan migrate --force
php artisan config:cache
php artisan view:cache
sudo systemctl restart php8.x-fpm
```

### 배포 자동화 스크립트 가드

```bash
#!/bin/bash
# deploy.sh - 운영 배포 가드
set -e

# APP_KEY 존재 검증
if ! grep -q '^APP_KEY=base64:' .env; then
    echo "ERROR: APP_KEY가 .env에 설정되지 않았습니다. 배포 중단."
    exit 1
fi

# APP_DEBUG 차단
if grep -q '^APP_DEBUG=true' .env; then
    echo "ERROR: 운영 환경에서 APP_DEBUG=true는 금지. 배포 중단."
    exit 1
fi

# 이후 배포 절차...
```

---

## 🆘 사고 대응

### Case 1: APP_KEY가 바뀌었지만 RRN 데이터는 그대로

증상: 차량 편집 패널 RRN 칸이 빈값. PDF에 RRN 빈칸.

복구 절차:
1. **이전 APP_KEY 복구** — 백업에서 찾아 `.env`에 다시 입력
2. `php artisan config:clear`
3. 화면 새로고침 → RRN 정상 표시 확인

### Case 2: 이전 APP_KEY 백업 없음

증상: 위와 동일하지만 키 복구 불가.

대응:
1. **RRN 데이터 영구 손실 확정** — 차량 소유자에게 RRN 재요청 필요
2. 영향 받은 차량 목록 추출:
   ```sql
   SELECT id, vehicle_number FROM vehicles 
   WHERE nice_reg_owner_rrn_encrypted_at IS NOT NULL 
     AND nice_reg_owner_rrn != '';
   ```
3. 해당 차량 소유자에게 연락 → RRN 재입력
4. 재입력 후 자동으로 새 키로 암호화 저장됨

### Case 3: 안전한 Key Rotation (운영 키 교체 필요 시)

⚠️ **권장하지 않음** — 운영 키는 한 번 발급하면 영구 유지. 다만 정말 필요한 경우:

```php
// 안전한 rotation 절차 (의사 코드)
DB::transaction(function () use ($newKey) {
    // 1. 모든 RRN 평문 복호화 (현재 키로)
    $vehicles = Vehicle::whereNotNull('nice_reg_owner_rrn_encrypted_at')
        ->get(['id', 'nice_reg_owner_rrn']);
    $plaintexts = $vehicles->mapWithKeys(fn($v) => [$v->id => $v->nice_reg_owner_rrn]);
    
    // 2. APP_KEY 교체 (.env 변경)
    // ... 
    config(['app.key' => $newKey]);
    
    // 3. 평문을 새 키로 재암호화
    foreach ($plaintexts as $id => $plain) {
        Vehicle::where('id', $id)->update([
            'nice_reg_owner_rrn' => $plain,  // mutator가 새 키로 자동 암호화
        ]);
    }
});
```

→ 이 작업 전 **반드시 DB 전체 백업** + **이전 APP_KEY 별도 보관** (24시간 롤백 가능 윈도우).

---

## 체크리스트

배포 전 확인:
- [ ] 운영 APP_KEY 별도 위치에 백업됨 (1Password 등)
- [ ] APP_DEBUG=false
- [ ] HTTPS 설정 (Let's Encrypt 등)
- [ ] DB 자동 백업 cron 등록 (mysqldump + S3)
- [ ] `php artisan storage:link` 실행
- [ ] `extension=zip`, `extension=gd` 활성화 확인
- [ ] Noto Sans KR 서브셋 폰트 `storage/fonts/*.subset.ttf` 존재

새 PC 세팅 시:
- [ ] 다른 PC의 APP_KEY 값 미리 확보
- [ ] `.env` 직접 입력으로 APP_KEY 세팅 (key:generate 사용 안 함)
- [ ] DB 마이그레이션·시드 완료
- [ ] RRN 입력된 차량 1대로 복호화 정상 확인
