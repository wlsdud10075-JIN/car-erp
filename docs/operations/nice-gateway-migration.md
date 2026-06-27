# NICE 게이트웨이 car-erp 이식 (Django → PHP)

> dev 전용 .md. **트리거: "NICE 이식 이어서"**. 메모리 [[project_nice_gateway_migration]].
> Django 원본 = 박스 `/ssancar-erp/exportstatus/vehicle_api.py` (326줄). 배포박스 = `54.116.7.83`(ssancarerp/heymancar.com).

## 배경

NICE 차량조회는 **이 박스(54.116.7.83)만 NICE IP 화이트리스트**에 등록돼 있다. 그래서:
- **ssancarerp**(이 박스): NICE 직접 호출 가능.
- **heymanerp**(52.79.200.151) 등 다른 박스: 직접 불가 → 이 박스의 `/provide/api/nice-lookup/` 게이트웨이를 **영구 경유**. `.env NICE_PROVIDE_URL=https://heymancar.com/provide/api/nice-lookup/` 그대로.

이식 = 이 게이트웨이의 **백엔드를 Django → car-erp 로 교체**. URL·heymanerp 설정 불변, 속만 바뀜. heymanerp 입장에선 무변경.

## 빌드 (dev `f66a254`, 2026-06-27) — heyman 무영향

| 파일 | 내용 |
|---|---|
| `app/Services/NiceDirectClient.php` | NICE 2단계 직접 호출. chkKey=`MOD(MOD(chkSec, 6628100898), 997)`, chkSec=`date('YmdHis')`. 1단계 kindOf=1(등록원부)→resSpecControlNo, 2단계 kindOf=400(상세제원). 제조사 보정 + 26필드 result_data (Django 동일) |
| `app/Http/Controllers/ProvideNiceLookupController.php` | `POST /provide/api/nice-lookup` — Django 동일 입출력 `{success,message,data}` |
| `config/services.php` | `nice.direct.*` (api_url/api_key/login_id/business_number) |
| `bootstrap/app.php` | `validateCsrfTokens(except: ['provide/*'])` — Django @csrf_exempt 동일 |
| `resources/nice/manufacturer_mapping.json` | 제조사 보정 테이블 (Django data/ 그대로 복사, 10KB) |
| `.env.example` | `NICE_DIRECT_*` 4개 |

### 크레덴셜 (`.env`, **ssancarerp만** 설정 — Django vehicle_api.py 하드코딩서 추출)
```
NICE_DIRECT_API_URL=https://niceab.nicednr.co.kr/carInfos
NICE_DIRECT_API_KEY=3F98928D7187A79DECF097430D94C989
NICE_DIRECT_LOGIN_ID=ssanCar
NICE_DIRECT_BUSINESS_NUMBER=6628100898
```
heymanerp/karabaerp 는 비워둠(이 게이트웨이 경유).

## 검증 통과 (2026-06-27)

- **체크1** (직접 호출): PHP tinker 로 NICE 2단계 직접 호출 성공. `234조6163`/`김장표(상품용)` → Audi/CLA250 4Matic/2017/1991. **TZ=Asia/Seoul (Django datetime.now() 와 동일)** = chkKey 일치.
- **체크2a** (서비스): `NiceDirectClient->lookup()` == 게이트웨이 출력 (26필드 동일).
- **체크2b** (HTTP): `POST /provide/api/nice-lookup` 라우트/CSRF제외/컨트롤러 동작. Django 형식 `{success:true,message,data}` 반환. slash·noslash 둘 다 라우트 매칭(route:cache 클리어 필요했음).

> ⚠️ 검증 중 NICE 코드 5000(원천기관 일시장애) 간헐 발생 — 우리 코드 무관, NICE 불안정(제조사 Audi↔Benz 흔들림과 동일 맥락). 연속 호출 시 더 자주.

## 컷오버 (nginx flip) — ✅ 완료 (2026-06-27)

> **✅ 적용됨**: 백업 `.bak.20260627-071700`. 검증 = 빈-body probe(`-d '{}'` → JSON 400 "차량번호와 소유자명…", NICE 안 건드리고 plumbing 증명) + 실차 success:true(234조6163=CLA250/2017). 롤백 트리거 = 비-JSON(HTML/502)만 — code 5000은 NICE 일시장애라 정상. exact 블록은 `try_files`만(fastcgi는 기존 `~\.php$` 재사용 = apex 검증된 체인).

현재(컷오버 전) nginx `/etc/nginx/sites-available/ssancar-erp`: `location ^~ /provide/` → Django gunicorn.
**Django provide 앱은 `api/nice-lookup/` 하나만 서빙** (provide/urls.py). 컷오버 = 이 경로만 car-erp 로:

```nginx
# NICE 게이트웨이 = car-erp (php-fpm). exact + slash 변종 모두.
location = /provide/api/nice-lookup  { try_files $uri /index.php?$query_string; fastcgi... }
location = /provide/api/nice-lookup/ { try_files $uri /index.php?$query_string; fastcgi... }
# 그 외 /provide/ (혹시 모를 잔여)는 Django 유지
location ^~ /provide/ { proxy_pass http://unix:/ssancar-erp/gunicorn.sock; ... }
```

절차: `.bak` 백업 → `nginx -t` → reload → **즉시 검증**:
- `curl https://heymancar.com/provide/api/nice-lookup/` + 테스트쌍 → `success:true` + Django 동일 필드 (= heymanerp 실제 경로)
- ssancarerp 화면에서도 NICE 조회 정상
- 이상 시 **롤백**: `.bak` 복원 + reload (Django 즉시 원복)

## 롤백 / soak

- nginx `/provide/` → Django 복원 한 줄. **Django(ssancar-erp.service)는 컷오버 후에도 안 지움** — soak 기간 후 제거.
- 박스 현재 **dev 브랜치**(테스트용). 정식 컷오버 시 master 머지 + route:cache.

## 남은 작업

1. **컷오버** (nginx flip, 위 절차)
2. **동시 대조** — NICE 안정 시 Django 경로 vs car-erp 경로 같은 차 동시 조회 → 필드별 일치 (주말 여러 차량, jin)
3. **master 정리** — dev → master 머지 후 박스 master 복귀 + route:cache (단 master push = heymanerp 자동배포 → NICE_DIRECT_* 미설정이라 heymanerp 무영향, 단 jin 승인)
4. Django 게이트웨이 제거 (soak 후)
