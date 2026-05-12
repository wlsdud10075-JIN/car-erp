# APP_KEY 백업 절차 (필독)

> **⚠️ APP_KEY 분실 = 암호화된 모든 데이터 영구 복호 불가**
>
> car-erp는 `nice_reg_owner_rrn`(주민·법인등록번호)을 Laravel `Crypt`(AES-256-CBC)로 암호화 저장한다. 암호화는 `.env`의 `APP_KEY`를 기반으로 동작하며, **이 키를 분실하면 어떤 방법으로도 복호할 수 없다**.

## 보호 대상

| 데이터 | 위치 | 영향 |
|---|---|---|
| `vehicles.nice_reg_owner_rrn` | DB 테이블 | RRN(주민·법인등록번호) — 망법·개인정보보호법 §24 고유식별정보. 분실 시 말소·등록증·양도 PDF 발급 불가 |
| 향후 추가될 암호화 컬럼 | (현재 없음) | `encrypted` cast 또는 `Crypt` 사용 컬럼 모두 동일 |

## 백업 의무 (모든 운영 환경)

### 1. APP_KEY 다중 보관

`.env`의 `APP_KEY=base64:...` 한 줄을 **최소 2곳에 별도 보관**한다.

- (a) **암호 관리자** (1Password / Bitwarden / vault) — 주력
- (b) **물리 백업** — USB·종이 출력. 1인 개발 컨텍스트에서 권장
- (c) **운영 인프라 비밀저장소** — AWS Lightsail 환경변수 / Secrets Manager (배포 시)

> **금지**: 깃 저장소·공용 드라이브·이메일·메신저 평문 저장

### 2. 변환 전 평문 DB 풀백업 (90일 격리 보관)

암호화 마이그레이션(`2026_05_12_000003_encrypt_existing_rrn_data.php`) 실행 **직전** 평문 상태의 DB 풀백업을 떠두고 **90일 격리 보관 후 폐기**한다.

```bash
# Windows XAMPP (로컬)
C:/xampp/mysql/bin/mysqldump.exe -u root car_erp > storage/backups/pre_rrn_encrypt_$(date +%Y%m%d_%H%M%S).sql

# Linux 운영 (배포 시)
mysqldump -u <user> -p car_erp > /secure/backup/pre_rrn_encrypt_$(date +%Y%m%d_%H%M%S).sql
```

- 보관 경로: 운영 인스턴스 외부 (S3 암호화 버킷 / 별도 백업 디스크)
- 폐기 시점: 변환 후 90일 — 그 이전엔 APP_KEY 분실 시 마지막 복구 수단
- 접근 권한: admin 1인 한정

### 3. git tag (코드 롤백 안전망)

```bash
git tag pre-rrn-encryption
git push origin pre-rrn-encryption  # 운영 시 remote에도
```

코드 레벨 롤백 시 `git checkout pre-rrn-encryption` 후 마이그레이션 `down()` 실행.

## 키 회전 (Key Rotation) — 향후 필요 시

APP_KEY를 바꿀 일이 생기면 (예: 직원 퇴사·키 노출 의심) 아래 절차로 **재암호화**한다.

```bash
# 1. 백업
mysqldump car_erp > backup_pre_rotation.sql
cp .env .env.backup.pre_rotation

# 2. 모든 RRN을 평문으로 복호 (기존 APP_KEY 사용)
php artisan migrate:rollback --step=1  # 2026_05_12_000003_encrypt_existing_rrn_data 롤백

# 3. APP_KEY 교체
php artisan key:generate  # 새 키 발급. 평문 DB 백업 + 이전 APP_KEY는 별도 보관

# 4. 새 키로 재암호화
php artisan migrate  # 2026_05_12_000003 재실행
```

키 회전 중 어떤 단계에서도 **이전 APP_KEY와 평문 백업은 작업 완료 + 검증까지 유지**한다.

## 재해 시나리오별 대응

| 시나리오 | 대응 |
|---|---|
| APP_KEY 분실 (백업 있음) | 백업한 APP_KEY로 `.env` 복원. 데이터 무손실 |
| APP_KEY 분실 + 백업 없음 + 평문 DB 90일 격리 백업 보유 | 평문 DB 복원 → 새 APP_KEY 발급 → 마이그레이션 재실행. **현재 진행 중인 변경 사항은 손실** |
| APP_KEY 분실 + 모든 백업 없음 | **복구 불가능**. 모든 RRN 영구 손실. NICE API 재조회로 부분 복구 가능하나 차량 수동 매칭 필요 |
| 일부 row 복호 실패 (데이터 손상) | Vehicle 모델의 accessor가 `null` 반환 + 로그 경고. NICE API로 재조회·수동 입력 |

## 운영 점검 체크리스트

배포 전·정기 점검 시 확인:

- [ ] `.env`의 APP_KEY가 1Password 또는 vault에 백업되어 있는가
- [ ] 평문 DB 백업이 변환 후 90일 이내인가 (격리 보관 위치 확인)
- [ ] `git tag pre-rrn-encryption`이 origin에 push 되어 있는가
- [ ] 운영 환경 `APP_DEBUG=false` 확인 (디버그 화면이 APP_KEY 노출 가능)
- [ ] 배포 자동화 스크립트가 `.env`를 git에 포함시키지 않는지 (`.gitignore`에 `.env` 명시 확인)

## 관련 파일

- `database/migrations/2026_05_12_000002_extend_rrn_column_and_add_encrypted_flag.php`
- `database/migrations/2026_05_12_000003_encrypt_existing_rrn_data.php`
- `app/Models/Vehicle.php` — `getNiceRegOwnerRrnAttribute` / `setNiceRegOwnerRrnAttribute` (accessor/mutator)
- `docs/meetings/2026-05-12-rrn-encryption-document-permission.md` — 본 작업의 회의 결정 근거
