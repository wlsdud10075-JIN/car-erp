# NICE 연동 — 사용자 확인/조사 사항 (2026-05-24, 갱신 2026-06-19)

NICE 연동(ssancar-erp 미들웨어 경유)은 완료됐고, **표준 필드(VIN·차명·소유자·주행거리·배기량 등)는 저장·문서 로드까지 정상**이다.

> 참고: 미매핑 NICE 필드도 **미들웨어가 car-erp로 보낸 범위 한정**으로 응답 원본이 `vehicles.nice_raw`(JSON)에 보존된다(조회 후 저장 시).
> 통관 SET 서류는 이미 `nice_raw`에서 형식·제원관리번호·출력·검사기간을 자동 로드한다.

---

## ★ 2026-06-19 실측 — NICE 원본 직접 덤프로 핵심 의문 해소

미들웨어(ssancar-erp) 서버에서 NICE를 직접 호출해 **whitelist 적용 *전* 원본 응답 전체**를 확인했다(차량 `11무8205`). 결론:

### 구조 (중요)
```
NICE 원본 ──①──▶ ssancar-erp 미들웨어 ──②──▶ car-erp (nice_raw)
```
- 미들웨어 핸들러 `exportstatus/vehicle_api.py :: nice_vehicle_lookup`가 NICE 응답에서 **`result_data = {...}`로 약 25개 키만 손으로 골라** car-erp에 전달한다(whitelist).
- car-erp는 받은 건 하나도 안 버리고 `nice_raw`에 보존하지만, **미들웨어가 안 꺼낸 키는 애초에 car-erp까지 오지 않는다.**
- 즉 **"car-erp가 받는 25개 = NICE 전부"가 아니다.** NICE 상세제원(`carDetailSpec.dtlSpecInfo` 등)에는 더 많은 필드가 있다.

### 미들웨어가 버리는(=car-erp 미수신) 유용 필드
| NICE 키 (위치) | 실측값(11무8205) | 의미 |
|---|---|---|
| **`fuelCnsmpRt`** (dtlSpecInfo) | **`9.5`** | **연비(공인연비 km/L)** ← 핵심 |
| `gearBox` (dtlSpecInfo) | `8/1` | 변속기(8단) |
| `whlb` (dtlSpecInfo) | `3050` | 축거(wheelbase) |
| `drvnMthdNm` (dtlSpecInfo) | `""` | 구동방식명 (이 차는 빈값, 필드는 존재) |
| `mnfctNationNm` (dtlSpecInfo) | `캐나다` | 제조국 |
| `ufrmCbdFrmNm` (dtlSpecInfo) | `세단형` | 차체형상 |
| `tireInfo` (블록) | `245/45ZR20` | 타이어 규격 |
| `axleLoadInfo`·`note` 등 | | 축중·비고 |

→ 메모리/문서에 "죽은칸"으로 적혀 있던 **변속기·구동·축거·연비가 사실 NICE엔 다 들어있다.** 미들웨어 whitelist만 안 꺼낼 뿐.

### 연비를 실제로 받아 기록하려면 (2곳 수정 필요 — 미착수)
1. **ssancar-erp 미들웨어** (`exportstatus/vehicle_api.py`의 `result_data`에 한 줄):
   ```python
   'fuelCnsmpRt': dtl_spec_info.get('fuelCnsmpRt', ''),
   ```
   ⚠️ **별도 repo/서버**라 ssancar-erp 쪽에 커밋해야 전파됨(크로스레포 규칙). 운영 코드 변경 → 명시 승인 필요.
2. **car-erp** `App\Services\NiceApiService::transform()` — 받은 `fuelCnsmpRt`를 `nice_spec_fuel_efficiency`로 매핑(필요 시 통관서류 연비칸 자동기입).

### 미들웨어 서버 접근 (2026-06-19 등록 완료)
- 고정 IP **`54.116.7.83`** (도메인 `heymancar.com`), Django+gunicorn, 앱 경로 `/ssancar-erp`, venv `/ssancar-erp/venv/bin/python`, user `ubuntu`.
- NICE 직접 호출 = `https://niceab.nicednr.co.kr/carInfos`, **2단계**(① `kindOf=1` 등록원부 → `resSpecControlNo` → ② `kindOf=400` 상세제원 `spmnno=`). loginId=`ssanCar`, chkKey=`MOD(MOD(chkSec,사업자번호),997)`.
- SSH: `ssh -i C:\Users\User\.ssh\car_erp_key ubuntu@54.116.7.83` (우리 `car_erp_key.pub`를 그 서버 `~/.ssh/authorized_keys`에 등록 완료). AWS Lightsail 인스턴스 — 리전 기본키로는 인증 안 됐었음.

---

## 1. 기통수 (통관 SET 구매리스트 G12 "기통") — 형식 확정

**현재 상태**: 공란. **2026-06-19 형식 확정**: `engineSpec` = `"6/3604"` (= **기통수 6 / 배기량 3604**). `"기통수/배기량"` 순서 맞음.

- 이번 구현은 `engineSpec`에서 **배기량(`/` 뒤 숫자)만** 추출 → 배기량/cc 칸은 자동.
- **기통수(`/` 앞 숫자)는 미추출.** 통관 서류 G12는 `nice_spec_cylinders` 컬럼을 읽는데 그 컬럼이 안 채워짐.

**확정 시 작업(빠름)**: 통관 G12를 `nice_raw`의 `engineSpec` 앞 숫자에서 뽑도록 `DocValue` 헬퍼로 변경(컬럼 채우기 불필요) — 약 10분. (사용자 "기통수 자동기입 할지" 결정만 남음)

---

## 2. 검사 종료일 (통관 SET 구매리스트 I11 "종료") — 형식 확정

**현재 상태**: 공란. (검사 시작 I10에는 `resValidPeriod` 전체가 들어감)

**2026-06-19 형식 확정**: `resValidPeriod` = `"2025-05-29 ~ 2027-05-28  주행거리:108032"`
- 구분자 ` ~ ` (공백 포함), 날짜 **Y-m-d**.
- ⚠️ 뒤에 `  주행거리:NNNN`가 **덧붙어 옴** — 분할 시 종료일 뒤 꼬리를 잘라내야 함.

**확정 시 작업**: `resValidPeriod`를 ` ~ `로 split → 앞=I10(시작), 뒤에서 `주행거리:` 앞까지=I11(종료). 약 15분. (별도 `Start/End` 필드는 없음 — 단일 문자열)

---

## 다음 행동
- 1·2는 **형식이 실측 확정**됐으므로 사용자 "자동기입 할지" 결정만 나면 즉시 마감 가능(각 10~15분).
- 연비(item ★)는 **미들웨어 1줄 + car-erp 매핑** 두 곳 수정이 전제 — 사용자 승인 시 진행. 미들웨어는 별도 repo/세션에서 커밋.
