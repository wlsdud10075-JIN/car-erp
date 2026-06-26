# ssancar car-erp 이식/배포 계획 (Django ssancar-erp → car-erp)

> ⚠️ **이 문서는 dev 전용**(.md 배포 제외). **트리거: "ssancar 이식 이어서"**
> 2026-06-25 정리 (jin과 방향 확정). 착수 전 이 문서 + 메모리 `project_ssancar_migration` 읽기.

## ★ 확정 결정 (2026-06-26, jin) — board 동시 배포 맥락

> 이번엔 **ssancar ERP 쌍(car-erp + board) 동시 배포**가 목표. board 세션이 인계문서
> `board/meetings/handoff-car-erp-ssancar-deploy.md` 를 줬고, 그걸 이 계획과 합쳐 확정함.

1. **위치 = 기존 NICE 박스 `54.116.7.83`에 ssancar 쌍을 함께 co-locate.** (자원 충분 RAM7.6/디스크154. NICE 화이트리스트 IP 자동 유지.) board handoff 의 "새 인스턴스"는 이 박스로 해석 — Django 와 공존(다른 nginx 블록) 후 정리.
2. **이번 범위 = 쌍 배포만. NICE 게이트웨이 이식(§3,§4-A)은 별도 후속.** (cutover 가 heyman live NICE 경로를 건드려 가장 위험 → 분리. 그때까지 heyman 은 기존 Django 게이트웨이 그대로 사용.)
3. **도메인 = heymancar.com 재활용** (ssancar.com 아님). **Option B(2026-06-26)**: car-erp(ssancar) = **apex `heymancar.com`** 자체 / board(ssancar) = `board.heymancar.com`. NICE `/provide/` 는 apex의 경로(긴 prefix 우선)라 같은 블록서 Django와 공존 → 이식 때 그 location 만 car-erp 로 flip. **배포 = 주말(다운타임 허용)**, 지금은 계획만.
4. **car-erp 코드 변경 0** — master(`ba4d274` 기준) 에 board 수신 4종 전부 LIVE 확인됨: purchase-sync(HMAC·멱등=vehicle_number·salesman_email) / 영업포털 read API(InternalPortalController) / 첨부 v2(attachments[] s3_path) / 금액매핑 v3. **clone 만 하면 끝.**
5. **시크릿 2개(purchase-sync·portal-read HMAC)는 board 세션이 `openssl rand -hex 32` 로 생성** → 양쪽 .env 동일. car-erp 세션은 받아서 car-erp .env 에만.
6. **ssancar 는 master push 자동배포 안 됨** (CI 환경=heyman 1개). ssancar 는 박스에서 수동 `git pull` (또는 추후 deploy.yml 2번째 job — board 세션 §96).
7. **board 회사분리(heyman/ssancar/karaba)** = board 세션 작업. board 는 이미 "같은 코드·다른 데이터" 멀티테넌트 설계(handoff §97)라 car-erp 와 동일 패턴(단일 master + 테넌트별 .env/DB/APP_KEY + DB시드 브랜딩, 코드 브랜치 X).

### 이번 배포 순서 (co-locate 쌍, NICE 이식 제외)
1. `54.116.7.83` 박스 LEMP 설치 (PHP 8.4+확장·php-fpm·MySQL/MariaDB·composer·node·supervisor·certbot). **Django gunicorn/nginx 무중단 공존**(heyman NICE 살아있음).
2. car-erp(ssancar) 배포: master clone → ssancar 전용 신규 APP_KEY(생성·백업)·DB+전용user·S3(버킷 `ssancar-erp-docs`+IAM)·heymancar.com 서브경로 + certbot → migrate → build → 큐워커(supervisor startretries=20)·cron(03:00 db:backup).
3. board(ssancar) 배포 (board 세션·런북 §B): 신규 APP_KEY·DB·시크릿2개·`CAR_ERP_BASE_URL=https://<ssancar car-erp 주소>`·respond.io ssancar 토큰·S3.
4. salesmen.email = board(ssancar) 영업 로그인 이메일 일치하게 ssancar salesmen 시드.
5. e2e: board(ssancar) 차 1대 won → car-erp(ssancar) 자동생성 + board `/audit` 201.

### 아직 jin 확인 (이번 배포 관련)
- [ ] ssancar 전용 NICE 키 (ssancar 계약분 있나? — 단, NICE 게이트웨이 이식 전엔 ssancar car-erp 도 heyman 게이트웨이 URL 을 가리켜도 됨)
- [ ] 바이어/컨사이니 이식(`ssancar_바이어컨사이니_260625.xlsx`) 테스트행 제외 — 배포 후 consignee-import
- [ ] respond.io ssancar 워크스페이스 토큰 (연동 C)

## 0. 한 줄 요약

`54.116.7.83`(고정 IP, NICE 화이트리스트된 박스)에 떠 있는 **구 Django ERP(ssancar-erp)를 car-erp로 교체**한다.
NICE 게이트웨이를 car-erp로 **이식**하고, **heyman은 최대한 안 건드린 채**(URL 그대로) 새 게이트웨이를 쓰게 한다.

## 1. 목표 / 확정 방향 (jin 2026-06-25)

1. **NICE 이식 필수** — Django의 NICE 호출 로직을 car-erp(PHP)로 포팅. ssancar-car-erp가 NICE 게이트웨이가 됨.
2. **heyman 최대한 무변경** — 같은 URL(`https://heymancar.com/provide/api/nice-lookup/`)을 car-erp가 서빙 → heyman `.env` 한 줄도 안 바꿈.
3. **고정 IP 유지** — NICE가 `54.116.7.83` 화이트리스트. **같은 박스에 car-erp를 올려** IP 자동 유지(Lightsail 고정 IP 떼었다 붙이기 X).
4. **Django는 결국 제거** — 단, 백업 + 바이어/컨사이니 이식 후에. (jin: "지금 Django 전혀 안 씀")
5. **분리 원칙**: 계정만 공유, **버킷·IAM·DB·APP_KEY·도메인은 분리** (heyman/karaba와 동일 멀티테넌트 원칙).

## 2. 현재 상태 — Django 박스 실측 (2026-06-25)

| 항목 | 값 |
|---|---|
| IP / 도메인 | `54.116.7.83` / `heymancar.com` |
| SSH | `ubuntu@54.116.7.83`, 키 `C:\Users\User\.ssh\car_erp_key` (등록됨) |
| 앱 경로 | `/ssancar-erp` (Django) |
| 서비스 | `ssancar-erp.service` (gunicorn, unix socket `/ssancar-erp/gunicorn.sock`) + `nginx` |
| nginx 사이트 | `/etc/nginx/sites-enabled/ssancar-erp` |
| DB | **SQLite** `/ssancar-erp/db.sqlite3` (`DATABASE_URL=sqlite:///db.sqlite3`). **최종수정 2026-05-21** (= 사실상 미사용) |
| PHP | **미설치** (Django 전용 박스 → LEMP 신규 설치 필요) |
| 리소스 | **RAM 7.6GB**(여유 6.9GB), **디스크 154GB**(3% 사용) → 두 앱 공존 충분 |
| Django 앱들 | exportstatus·forwardings·salesmen·buyers·mobile·account·employeesapp·provide (풀 ERP, 현재 미사용) |

### 가져올 데이터 (Django SQLite)
- 테이블명: `buyers`(38건) · `consignees`(46건) · `salesmen_salesman`(31건). car-erp Buyer/Consignee와 **거의 1:1 매핑**.
- **이미 엑셀로 추출함**: `바탕화면\ssancar_바이어컨사이니_260625.xlsx` (3시트, country_id→이름 해석 포함). jin 검토 후 테스트행 정리 예정.
- 적재 도구: 기존 `consignee-import` 스킬(바이어+컨사이니 xlsx 일괄) 재사용 가능.

## 3. NICE 게이트웨이 이식 상세

### 3-1. 현재 Django 구현 (= 포팅 대상)
- 라우트: `provide/urls.py` → `path('api/nice-lookup/', nice_vehicle_lookup)`. 핸들러 `exportstatus/vehicle_api.py`.
- 상수 (vehicle_api.py 77~80, **시크릿 = ssancar .env 로 이전**):
  - `API_URL = https://niceab.nicednr.co.kr/carInfos`
  - `API_KEY = ***` (마스킹 — 서버 파일에서 가져옴)
  - `LOGIN_ID = ssanCar`
  - `BUSINESS_NUMBER = 6628100898`
- **인증 알고리즘 (단순 — PHP 포팅 쉬움)**:
  - `chkSec = 현재시각("YYYYMMDDHHmmss")`
  - `chkKey = (int(chkSec) % BUSINESS_NUMBER) % 997` (문자열) — HMAC 등 복잡한 암호화 없음
- **2단계 호출**:
  1. 등록원부 `POST API_URL {apiKey,chkSec,chkKey,loginId,kindOf:"1",ownerNm,vhrNo}` → `resultCode=="0000"` 확인 → `carParts.outB0001.list[0]`에서 `resSpecControlNo` 획득
  2. 상세제원 `kindOf:"400"` + resSpecControlNo → 상세 8필드
  - 합쳐서 `{success, message, data:{ 22필드 }}` 반환 (resultCode 5000 = 대상기관 장애 등 그대로 전달)
- 22필드: resCarModelType·resUseType·commCarName·resCarYearModel·resVehicleIdNo·resMotorType·resGarage·resFinalOwner·resUserIdentiyNo·resSpecControlNo·resValidPeriod·resValidDistance / cbdLt·cbdBt·cbdHg·engineSpec·maxPower·tkcarPscapCo·mxmmLdg·useFuelNm (+ fuelCnsmpRt 연비 — 미들웨어가 버리던 것, 이식 시 포함 검토).

### 3-2. car-erp 측 이식 (코드, opt-in)
- car-erp의 기존 `NiceApiService`는 **클라이언트**(게이트웨이를 호출). 이번에 **서버(게이트웨이)**를 추가:
  - 신규 서비스 `NiceDirectService`(가칭): chkSec/chkKey + 2단계 niceab 호출 + 22필드 파싱 (위 알고리즘 PHP 포팅).
  - 신규 라우트/컨트롤러: heyman이 부르는 `POST /provide/api/nice-lookup/` 규격 그대로 — `X-SSANCAR-API-KEY` 헤더 + `{vehicle_number, owner_name}` 받고 `{success,message,data}` 반환.
  - **opt-in**: ssancar에서만 켜짐(env 플래그/라우트 등록). heyman/karaba 코드·동작 영향 0.
- ⚠️ **보안 개선**: 현재 Django 게이트웨이는 **토큰 검증 안 함**(`X-SSANCAR-API-KEY` 받기만 함 — provide/에서 grep 결과 검증 코드 없음). car-erp 이식 시 **토큰 검증 추가**. heyman은 이미 토큰을 보내므로(`NICE_PROVIDE_TOKEN`) car-erp가 그 값을 기대하게 맞추면 heyman 무영향 + 보안↑.
- ssancar 자기 차량의 NICE는 같은 박스의 게이트웨이(localhost) 또는 직접 호출.

### 3-3. heyman "거의 0 변경" 메커니즘
- heyman 운영 .env: `NICE_PROVIDE_URL=https://heymancar.com/provide/api/nice-lookup/` (그대로 유지).
- 컷오버 = **박스 nginx에서 `/provide/api/nice-lookup/` 경로를 Django(gunicorn) 대신 car-erp(php-fpm)로 라우팅**.
- 같은 URL·같은 규격·같은 IP → heyman은 답하는 앱이 바뀐 걸 모름. 문제 시 **nginx location 한 줄 원복**(Django 살아있음) = 즉시 롤백.
- (옵션: ssancar 도메인으로 옮기면 heyman .env URL 한 줄만 변경 — 기본은 안 바꿈.)

## 4. 단계별 실행 (heyman 안전 보장)

| 단계 | 내용 | heyman 영향 | 비고 |
|---|---|---|---|
| **A. 코드** | car-erp에 NICE 게이트웨이 이식(`NiceDirectService` + 라우트 + 토큰검증) + 테스트 | 0 | **가장 안전, 여기부터 시작** |
| **B. 박스 LEMP** | `54.116.7.83`에 PHP 8.4 + php-fpm + MySQL/MariaDB + composer 설치. ssancar car-erp 배포(.env·migrate·build). **Django 그대로 두고 공존**(다른 nginx 블록/포트) | 0 | RAM 충분 |
| **C. 검증** | 박스에서 car-erp 게이트웨이 호출 → NICE 정상응답 + Django와 결과 일치 대조 | 0 | 같은 IP라 화이트리스트 OK |
| **D. 컷오버** | nginx `/provide/...` → car-erp 전환. heyman 조회 실측 | 거의 0 | 이상 시 nginx 원복 |
| **E. 정리** | ① db.sqlite3·.env 백업 ② 바이어/컨사이니 ssancar 이식 ③ `systemctl stop/disable ssancar-erp` ④ nginx 사이트 car-erp로 교체 ⑤ `/ssancar-erp` → `.bak` 보류 후 삭제 | 0 | Django 제거 |

### "Django 삭제" = 3가지
① gunicorn 서비스 `ssancar-erp.service`  ② 앱 폴더 `/ssancar-erp`  ③ nginx 사이트 `ssancar-erp`.
→ 중지 → 백업 → nginx 교체 → 보류(.bak) 후 삭제. 백업 있으면 가역.

## 5. S3 (karaba 패턴 복제 — 코드 0, .env 만)

- `config/filesystems.php`가 이미 `AWS_*` env 구동(`vehicle_docs_disk` = s3). **코드 수정 없음.**
- ssancar 전용:
  - 버킷 **`ssancar-erp-docs`** (서울·퍼블릭차단·버전관리·SSE). **반드시 분리** — RRN 서류 회사 간 격리(계정 같아도 필수).
  - IAM **`ssancar-erp-s3-user`** — ssancar 버킷 ARN만(PutObject/GetObject/DeleteObject/ListBucket). 키 유출 blast radius 차단.
- ssancar `.env`:
  ```
  VEHICLE_DOCS_DISK=s3
  DB_BACKUP_DISK=s3
  AWS_ACCESS_KEY_ID=***            # ssancar IAM
  AWS_SECRET_ACCESS_KEY=***
  AWS_DEFAULT_REGION=ap-northeast-2
  AWS_BUCKET=ssancar-erp-docs
  ```
- 매일 03:00 `db:backup` cron(로컬+S3) — heyman/karaba와 동일.

## 6. 계정 / 인프라

- **AWS 계정: ssancar = heyman 동일 계정** (karaba만 별도 계정). → 콘솔/자격증명 하나에서 ssancar 버킷·IAM·Lightsail 관리. 크로스계정 권한 불필요.
- **분리 유지(계정 같아도)**: S3 버킷 · IAM · DB · **APP_KEY**(RRN 암호화 키 경계 — ssancar 자체 발급+백업, `key:generate` 후 즉시 백업) · 도메인.

## 7. 미결정 / jin 확인 필요

- [ ] **ssancar 도메인**: car-erp를 어느 도메인으로? (heymancar.com 재활용 vs ssancar 신규 도메인 + certbot)
- [ ] 바이어/컨사이니 엑셀(`ssancar_바이어컨사이니_260625.xlsx`)에서 테스트행("테스트 바이어" 등) 제외 확정
- [ ] 영업담당자 31건도 이식할지 (type=freelance/employee 지정 필요)
- [ ] Django의 다른 데이터(exportstatus 차량 등) 이식 필요 여부 — 현재 "안 씀"이지만 과거 차량 데이터 가져올지

## 8. 참고 (좌표)

- NICE 미들웨어 서버 상세: 메모리 `reference_nice_middleware`
- car-erp 운영 배포(heyman) 기록: `docs/operations/aws-deployment-record.md`
- 멀티테넌트 배포 패턴(karaba): `docs/operations/karaba-deployment-checklist.md` (S3 §L 복제 대상)
- 멀티회사 서류 양식: 메모리 `project_karaba_multicompany`
- 배포 명칭 맵: 메모리 `project_deployment_naming`
