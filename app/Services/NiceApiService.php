<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * NICE 차량정보 조회 — ssancar-erp 의 /provide/api/nice-lookup/ 미들웨어 경유.
 *
 * CAR-ERP 는 NICE 를 직접 호출하지 않는다. ssancar-erp 가 NICE 2단계 호출·인증·파싱·
 * IP 화이트리스트를 모두 책임지고, 단일 엔드포인트로 결과를 돌려준다.
 * 따라서 여기서는 ssancar-erp 엔드포인트에 X-SSANCAR-API-KEY 헤더로 POST 만 한다.
 *
 * 응답(ssancar 가 그대로 전달): { success, message, data:{ NICE 22필드 } }
 * 도메인/토큰은 .env(NICE_PROVIDE_URL / NICE_PROVIDE_TOKEN) → config('services.nice.*'). 하드코딩 금지.
 */
class NiceApiService
{
    public function __construct(
        private string $provideUrl = '',
        private string $provideToken = '',
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            provideUrl: (string) config('services.nice.provide_url', ''),
            provideToken: (string) config('services.nice.provide_token', ''),
        );
    }

    /**
     * 차량번호 + 소유자명으로 조회 (NICE 1단계가 소유자명 필수).
     *
     * @return array|null 성공: ['success'=>true,'registration'=>[...],'spec'=>[...],'raw'=>[...]]
     *                    실패: ['success'=>false,'message'=>'...']
     *                    미설정(URL/토큰 없음): null  → 수동 입력 모드(기존 동작)
     */
    public function lookupVehicle(string $vehicleNumber, string $ownerName): ?array
    {
        $vehicleNumber = trim($vehicleNumber);
        $ownerName = trim($ownerName);

        if ($vehicleNumber === '' || $ownerName === '') {
            return ['success' => false, 'message' => '차량번호와 소유자명을 모두 입력해주세요.'];
        }

        if ($this->provideUrl === '' || $this->provideToken === '') {
            return null;   // 엔드포인트 미설정 → 수동 입력 모드
        }

        // 성공 결과만 5분 캐시 (NICE 는 건당 과금 → 동일 조회 재호출 방지).
        // 실패는 캐시하지 않음 — 일시적 네트워크 오류가 5분 고정되는 것 방지(입력 수정 후 즉시 재시도 가능).
        $cacheKey = "nice_vehicle_{$vehicleNumber}_{$ownerName}";
        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        $result = $this->fetch($vehicleNumber, $ownerName);
        if (($result['success'] ?? false) === true) {
            Cache::put($cacheKey, $result, 300);
        }

        return $result;
    }

    private function fetch(string $vehicleNumber, string $ownerName): array
    {
        try {
            // ssancar 가 NICE 2단계(등록원부+상세제원)를 순차 호출하므로 타임아웃을 넉넉히.
            // car-erp(55초) > 미들웨어 단계당(45초) — 느린 NICE 정상응답이 504 로 끊기지 않게.
            $response = Http::timeout(55)
                ->withHeaders(['X-SSANCAR-API-KEY' => $this->provideToken])
                ->post($this->provideUrl, [
                    'vehicle_number' => $vehicleNumber,
                    'owner_name' => $ownerName,
                ]);
        } catch (ConnectionException $e) {
            Log::warning('NICE provide connection failed', ['vehicle' => $vehicleNumber, 'error' => $e->getMessage()]);
            // cURL 28(타임아웃)이면 "지연" 안내, 아니면 연결 오류.
            $isTimeout = str_contains(strtolower($e->getMessage()), 'timed out') || str_contains($e->getMessage(), '28');

            return ['success' => false, 'message' => $isTimeout
                ? self::humanizeError('API 요청 시간 초과', 504)
                : 'NICE 조회 서버에 연결할 수 없습니다. 네트워크/도메인 설정을 확인하세요.'];
        } catch (\Throwable $e) {
            Log::warning('NICE provide call failed', ['vehicle' => $vehicleNumber, 'error' => $e->getMessage()]);

            return ['success' => false, 'message' => 'NICE 조회 요청 중 오류가 발생했습니다: '.$e->getMessage()];
        }

        if ($response->failed()) {
            // 미들웨어가 4xx/5xx 에도 사유 메시지(JSON body)를 줌 (예: "소유주명이 일치하지 않습니다 E901", "API 요청 시간 초과").
            $detail = null;
            try {
                $b = $response->json();
                if (is_array($b) && ! empty($b['message'])) {
                    $detail = $b['message'];
                }
            } catch (\Throwable $e) {
                // body 파싱 실패 시 status 만
            }

            return ['success' => false, 'message' => $detail
                ? self::humanizeError($detail, $response->status())
                : self::humanizeError('NICE 조회 서버 오류 (HTTP '.$response->status().')', $response->status())];
        }

        try {
            $body = $response->json();
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'NICE 응답을 해석할 수 없습니다(JSON 파싱 실패).'];
        }

        if (! is_array($body) || ($body['success'] ?? false) !== true) {
            $raw = is_array($body) ? ($body['message'] ?? 'NICE 조회에 실패했습니다.') : 'NICE 조회에 실패했습니다.';

            return ['success' => false, 'message' => self::humanizeError($raw)];
        }

        return $this->transform(is_array($body['data'] ?? null) ? $body['data'] : []);
    }

    /** NICE resultCode → 사용자 친화 문구. 실제 코드 의미 확인되는 대로 추가(미확인은 원문 노출). */
    private const NICE_CODE_MESSAGES = [
        // '4000' => 'NICE 에 해당 차량 등록 정보가 없습니다',  // 예시 — 확인 후 채움
    ];

    /**
     * 미들웨어/NICE 원문 에러를 사용자 친화 메시지로 변환 (jin 2026-06-24).
     * 확신하는 케이스(응답지연·소유주명불일치)만 다듬고, 미확인 코드는 NICE 원문 그대로 노출
     * (틀린 안내 방지). 코드 의미가 확인되면 NICE_CODE_MESSAGES 에 추가.
     */
    public static function humanizeError(string $raw, ?int $status = null): string
    {
        $raw = trim($raw);

        // ① 응답 지연(타임아웃, 504/408 또는 "시간 초과") — NICE 혼잡, 일일 한도와 무관.
        if ($status === 504 || $status === 408 || preg_match('/시간\s*초과|시간요청초과|timed?\s*out/iu', $raw)) {
            return 'NICE 응답이 지연되고 있습니다. 1~2분 후 다시 시도해 주세요. (NICE 서버 혼잡 — 일일 조회 한도와는 무관합니다)';
        }

        // ② 소유주명 불일치 — 등록원부 소유주와 입력값이 다름. '(상품용)' 등 표기 차이가 흔한 원인.
        if (preg_match('/소유[주자]|일치하지\s*않|E901/u', $raw)) {
            return "소유주명이 등록원부와 일치하지 않습니다. '(상품용)'·'(주)' 같은 표기를 등록원부 그대로 맞춰 다시 시도해 주세요.\n(원문: {$raw})";
        }

        // ③ NICE resultCode(코드: XXXX) — 확인된 코드만 친절 문구로 치환, 그 외는 원문 유지.
        if (preg_match('/코드:?\s*([0-9A-Za-z]+)/u', $raw, $m)) {
            $code = strtoupper($m[1]);
            if (isset(self::NICE_CODE_MESSAGES[$code])) {
                return self::NICE_CODE_MESSAGES[$code]." (코드 {$code})";
            }
        }

        return $raw;   // 미확인 코드·기타 — NICE 원문 그대로(이미 한글)
    }

    /**
     * ssancar 응답 data(22필드) → CAR-ERP 컴포넌트 기대 형태(registration/spec/raw).
     * 키 이름은 컴포넌트 public 속성의 베이스명(_str 접미사 제외) — lookupNiceApi 가 _str 로 매핑.
     */
    private function transform(array $d): array
    {
        // 모든 값 trim 후 빈 값은 skip — NICE 가 앞뒤 공백/패딩을 섞어 보내는 경우 방어
        // (날짜·코드 칸 포함). 비숫자 칸은 그대로, 숫자 칸은 아래 $digits 로 별도 정제.
        $set = function (array &$arr, string $key, $value): void {
            if (is_string($value)) {
                $value = trim($value);
            }
            if ($value !== null && $value !== '') {
                $arr[$key] = $value;
            }
        };

        // 숫자 칸 정제 — NICE 는 '주값(보조값)' 또는 '주값/보조값' 형식으로 줌:
        //   전장 "4840(0)"(=4840.0) · 차량중량 "2115(2235)"(=공차2115/총2235) · 승차 "4/0"(=4명).
        //   ⇒ **첫 숫자 그룹만** 추출(보조값·소수점 분리). "4840(0)"→4840 / "2115(2235)"→2115 / "4/0"→4.
        //   (이전 '비숫자 전부 제거'는 보조값을 붙여 48400·21152235·40 처럼 틀어졌음)
        //   숫자 없으면 null → $set skip → 빈 칸(폼 검증 '0 이상의 숫자' 통과).
        $digits = function ($value): ?string {
            return preg_match('/\d+/', (string) ($value ?? ''), $m) ? $m[0] : null;
        };

        // ── registration + 기본정보 ──
        $reg = [];
        $set($reg, 'nice_reg_vin', $d['resVehicleIdNo'] ?? null);
        $set($reg, 'nice_reg_engine_no', $d['resMotorType'] ?? null);
        $set($reg, 'nice_reg_use_type', $d['resUseType'] ?? null);
        $set($reg, 'nice_reg_vehicle_form', $d['resCarModelType'] ?? null);
        $set($reg, 'nice_reg_owner_name', $d['resFinalOwner'] ?? null);
        // 소유자주소 — NICE 가 상세부를 마스킹(예: "서울특별시 강서구 *** *******")해 보냄.
        //   마스킹 주소를 기입하면 말소신청서 등 서류에 '***'가 찍히므로, 마스킹(`*` 포함) 시
        //   미입력(빈 칸 → 수기 입력). 마스킹 안 된 전체 주소일 때만 기입. (RRN 과 동일 정책)
        $addr = trim((string) ($d['resGarage'] ?? ''));
        if ($addr !== '' && ! str_contains($addr, '*')) {
            $reg['nice_reg_owner_addr'] = $addr;
        }
        // RRN(주민/법인등록번호) — ssancar 가 마스킹(예: XXXXXX-*******) 해 보내면 숫자 형식 불가.
        //   실제 13자리(숫자6-숫자7) 형식일 때만 기입. 마스킹/형식 불일치 → 미입력(빈 칸 → 수기 입력).
        //   (Vehicle 모델이 저장 시 APP_KEY 로 자동 암호화)
        $rrn = trim((string) ($d['resUserIdentiyNo'] ?? ''));
        if (preg_match('/^\d{6}-\d{7}$/', $rrn)) {
            $reg['nice_reg_owner_rrn'] = $rrn;
        }
        $set($reg, 'nice_reg_first_date', $this->formatYmd($d['resFirstDate'] ?? null));
        $set($reg, 'nice_reg_fuel_type', $d['useFuelNm'] ?? null);
        $set($reg, 'nice_reg_passengers', $digits($d['tkcarPscapCo'] ?? null));
        $set($reg, 'nice_reg_max_load', $digits($d['mxmmLdg'] ?? null));
        $set($reg, 'mileage', $digits($d['resValidDistance'] ?? null));   // 기본정보 주행거리
        $set($reg, 'year', $digits($d['resCarYearModel'] ?? null));       // 기본정보 연식
        $set($reg, 'model_type', $d['commCarName'] ?? null);     // 기본정보 차종
        $set($reg, 'brand', $d['mnfctEntrpsNm'] ?? null);        // 기본정보 제조사

        // ── spec + 기본정보 ──
        $spec = [];
        $set($spec, 'nice_spec_length', $digits($d['cbdLt'] ?? null));
        $set($spec, 'nice_spec_width', $digits($d['cbdBt'] ?? null));
        $set($spec, 'nice_spec_height', $digits($d['cbdHg'] ?? null));
        $set($spec, 'nice_spec_maker', $d['mnfctEntrpsNm'] ?? null);
        $set($spec, 'nice_spec_year', $digits($d['resCarYearModel'] ?? null));
        $set($spec, 'nice_spec_curb_weight', $digits($d['vhcleWt'] ?? null));
        $set($spec, 'weight_kg', $digits($d['vhcleWt'] ?? null));          // 기본정보 중량
        $displacement = $this->parseDisplacement($d['engineSpec'] ?? null);
        $set($spec, 'nice_spec_displacement', $displacement);
        $set($spec, 'cc', $displacement);                        // 기본정보 배기량

        // 연비(공인연비) — NICE fuelCnsmpRt (예: "9.5"). 정수 추출($digits)이 아니라 소수점 보존.
        $fuelEff = preg_match('/\d+(?:\.\d+)?/', (string) ($d['fuelCnsmpRt'] ?? ''), $mFuel) ? $mFuel[0] : null;
        $set($spec, 'nice_spec_fuel_efficiency', $fuelEff);

        // 대응 컬럼 없음(resValidPeriod·resSpecControlNo·maxPower·mtrsFomNm·fomNm)은 raw 에만 보존.
        // NICE 미제공 컬럼(transmission·drive_type·wheelbase)은 빈 채로 둔다.
        return [
            'success' => true,
            'registration' => $reg,
            'spec' => $spec,
            'raw' => $d,
        ];
    }

    /** YYYYMMDD → Y-m-d. 8자리 아니면 원본 그대로. */
    private function formatYmd(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $digits = preg_replace('/\D/', '', $value);

        return strlen($digits) === 8
            ? substr($digits, 0, 4).'-'.substr($digits, 4, 2).'-'.substr($digits, 6, 2)
            : $value;
    }

    /** engineSpec "6/3342"(기통수/배기량) → '/' 뒤 숫자(배기량). '/' 없으면 전체 숫자. 실패 시 null. */
    private function parseDisplacement(?string $engineSpec): ?string
    {
        if ($engineSpec === null || trim($engineSpec) === '') {
            return null;
        }
        $s = trim($engineSpec);
        $target = str_contains($s, '/') ? substr($s, strpos($s, '/') + 1) : $s;
        $num = preg_replace('/[^0-9]/', '', $target);

        return $num !== '' ? $num : null;
    }
}
