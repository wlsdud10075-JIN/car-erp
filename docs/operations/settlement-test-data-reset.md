# [관리] 정산 테스트용 데이터 리셋 + 복구 런북 (2026-06-24)

> **목적**: 운영 차량 데이터를 백업해 두고, [관리] 1인이 정산(월별 드롭다운 포함)을 테스트할 수 있게
> **표본(거래완료 ~10대 + 완납 진행중 ~3대)만 남기고** 차량 도메인을 비운다. 테스트 후 **백업으로 원복**.
>
> **트리거**: "정산 테스트 데이터 리셋" / "테스트 표본 만들어"
> **실행 주체**: jin 또는 실무자. 운영(`52.79.200.151` / `https://heysellcar.com`)에서 **업무시간 외**에.
> ⚠️ **나(클로드)는 운영 데이터를 직접 안 건드림** — 도구만 제공. 실제 실행은 사람이 명시적으로.

---

## ⚠️ 절대 규칙
1. **백업 없이 wipe 금지.** 복구는 오직 백업 SQL뿐. 백업이 곧 복구다.
2. **백업은 "복구 검증"까지 끝나야 백업이다.** 덤프만 뜨고 복원을 안 해보면 백업이 아니다.
3. **백업 파일을 인스턴스 밖으로 내린다.** Lightsail 단일 인스턴스 = 단일 장애점.
4. **APP_KEY 동일 인스턴스에서 복원** → 암호화된 `nice_reg_owner_rrn` 정상 복호화. (다른 키로 복원하면 RRN 영구 손실 — `CLAUDE.md` 경고 참조.)
5. 테스트 + 복구 창 동안 운영 ERP엔 **표본 13대만** 보인다 → 업무시간 외 수행.

---

## 절차

### 0) 사전 — 현재 상태 기록
```bash
php artisan tinker --execute='echo "vehicles=".\App\Models\Vehicle::count()." settlements=".\App\Models\Settlement::count().PHP_EOL;'
```
복구 후 이 숫자와 대조한다.

### 1) 백업 (+ 오프인스턴스 보관)
```bash
php artisan db:backup                 # storage/backups/db/car_erp-YYYYMMDD_HHMMSS.sql
                                      # filesystems.db_backup_disk 설정 시 S3 자동 업로드
```
- S3 업로드 줄(`✓ 원격 업로드: ...`)이 떴는지 확인. 안 떴으면 **수동으로 파일을 로컬 PC로 scp**:
```bash
scp -i C:\Users\User\.ssh\car_erp_key ubuntu@52.79.200.151:/var/www/.../storage/backups/db/car_erp-*.sql ./
```

### 2) 🔴 복구 검증 (wipe 전 필수) — scratch DB에 복원해 행수 대조
```bash
mysql -u root -p -e "CREATE DATABASE car_erp_restore_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p car_erp_restore_test < storage/backups/db/car_erp-YYYYMMDD_HHMMSS.sql

# 행수 일치 확인 (운영과 같아야 함)
mysql -u root -p -e "SELECT
  (SELECT COUNT(*) FROM car_erp.vehicles)              AS v_live,
  (SELECT COUNT(*) FROM car_erp_restore_test.vehicles) AS v_bak,
  (SELECT COUNT(*) FROM car_erp.settlements)              AS s_live,
  (SELECT COUNT(*) FROM car_erp_restore_test.settlements) AS s_bak,
  (SELECT COUNT(*) FROM car_erp.final_payments)              AS fp_live,
  (SELECT COUNT(*) FROM car_erp_restore_test.final_payments) AS fp_bak,
  (SELECT COUNT(*) FROM car_erp.purchase_balance_payments)              AS pbp_live,
  (SELECT COUNT(*) FROM car_erp_restore_test.purchase_balance_payments) AS pbp_bak;"

# _live == _bak 전부 일치하면 백업 유효. scratch 정리.
mysql -u root -p -e "DROP DATABASE car_erp_restore_test;"
```
**숫자가 하나라도 어긋나면 여기서 멈추고 wipe하지 않는다.**

### 3) wipe — 표본만 남김
```bash
# 먼저 드라이런 (삭제 안 함, 계획만 출력)
php artisan vehicles:keep-sample-for-test --completed=10 --in-progress=3 --spread-months=5

# 계획 확인 후 실제 실행
php artisan vehicles:keep-sample-for-test --completed=10 --in-progress=3 --spread-months=5 --force
```
- `--completed` : 남길 거래완료 차량 수 (랜덤)
- `--in-progress` : 남길 완납 진행중 차량 수 — **정산 수동추가 테스트 대상** (선적/통관 진행중인데 완납된 차량)
- `--spread-months` : 남은 정산 `created_at` 을 최근 N개월에 분산 → **월별 드롭다운 첫날부터 시연 가능** (테스트용 가공)

남는 것: 표본 차량 + 종속행(잔금/정산/적립/서류로그/알람/선적요청).
지우는 것: 그 외 차량 도메인 전체 + 이체/승인요청 전체.
유지: 바이어·컨사이니·영업담당자·유저·국가·포워딩사 (마스터).

### 4) 테스트 (실무자)
- `/erp/settlements` → 인원별 카드 + **월(月) 드롭다운**(전체 월 / `2026-06 → 2026-07-10 지급` …).
- 월 선택 시 목록·카드가 그 달 정산만으로 묶임.
- 완납 진행중 차량 → 정산 패널에서 **차량 검색 후 수동 추가** (거래완료 아니어도 추가됨).

### 5) 복구 — 백업 SQL 재적재
```bash
php artisan down                      # 점검 모드 (선택)
mysql -u root -p car_erp < storage/backups/db/car_erp-YYYYMMDD_HHMMSS.sql
php artisan view:clear && php artisan cache:clear
php artisan vehicles:rebuild-progress-cache   # 캐시 컬럼 재계산(안전망)
php artisan up
```
- 0)에서 기록한 vehicles/settlements 숫자와 복구 후 숫자가 같은지 확인.

---

## 비파괴 대안 (참고)
삭제가 부담되면 차량을 "비활성 플래그"로 숨기는 방식도 가능(복구 = 플래그만 해제, 무삭제).
단 대시보드·정산·목록 전반에 스코프 분기가 들어가야 해서 코드 변경이 더 넓다.
jin이 **삭제 + 백업복구**로 결정(2026-06-24) → 본 런북은 삭제 방식 기준.

## 관련
- 백업 커맨드: `app/Console/Commands/BackupDatabase.php` (`db:backup`)
- 표본 커맨드: `app/Console/Commands/KeepSampleForTest.php` (`vehicles:keep-sample-for-test`)
- 월별 정산 솔팅: `resources/views/livewire/erp/settlements/index.blade.php` (`monthFilter`, 기준=`created_at`)
- APP_KEY/RRN 경고: `CLAUDE.md` · `docs/operations/key-rotation.md`
