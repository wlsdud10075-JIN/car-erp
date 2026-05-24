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
            // ssancar 가 NICE 2단계를 대신 호출하므로 타임아웃을 넉넉히(35초).
            $response = Http::timeout(35)
                ->withHeaders(['X-SSANCAR-API-KEY' => $this->provideToken])
                ->post($this->provideUrl, [
                    'vehicle_number' => $vehicleNumber,
                    'owner_name' => $ownerName,
                ]);
        } catch (ConnectionException $e) {
            Log::warning('NICE provide connection failed', ['vehicle' => $vehicleNumber, 'error' => $e->getMessage()]);

            return ['success' => false, 'message' => 'NICE 조회 서버에 연결할 수 없습니다. 네트워크/도메인 설정을 확인하세요.'];
        } catch (\Throwable $e) {
            Log::warning('NICE provide call failed', ['vehicle' => $vehicleNumber, 'error' => $e->getMessage()]);

            return ['success' => false, 'message' => 'NICE 조회 요청 중 오류가 발생했습니다: '.$e->getMessage()];
        }

        if ($response->failed()) {
            return ['success' => false, 'message' => 'NICE 조회 서버 오류 (HTTP '.$response->status().')'];
        }

        try {
            $body = $response->json();
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'NICE 응답을 해석할 수 없습니다(JSON 파싱 실패).'];
        }

        if (! is_array($body) || ($body['success'] ?? false) !== true) {
            return ['success' => false, 'message' => is_array($body) ? ($body['message'] ?? 'NICE 조회에 실패했습니다.') : 'NICE 조회에 실패했습니다.'];
        }

        return $this->transform(is_array($body['data'] ?? null) ? $body['data'] : []);
    }

    /**
     * ssancar 응답 data(22필드) → CAR-ERP 컴포넌트 기대 형태(registration/spec/raw).
     * 키 이름은 컴포넌트 public 속성의 베이스명(_str 접미사 제외) — lookupNiceApi 가 _str 로 매핑.
     */
    private function transform(array $d): array
    {
        $set = function (array &$arr, string $key, $value): void {
            if ($value !== null && $value !== '') {
                $arr[$key] = $value;
            }
        };

        // ── registration + 기본정보 ──
        $reg = [];
        $set($reg, 'nice_reg_vin', $d['resVehicleIdNo'] ?? null);
        $set($reg, 'nice_reg_engine_no', $d['resMotorType'] ?? null);
        $set($reg, 'nice_reg_use_type', $d['resUseType'] ?? null);
        $set($reg, 'nice_reg_vehicle_form', $d['resCarModelType'] ?? null);
        $set($reg, 'nice_reg_owner_name', $d['resFinalOwner'] ?? null);
        $set($reg, 'nice_reg_owner_addr', $d['resGarage'] ?? null);
        $set($reg, 'nice_reg_owner_rrn', $d['resUserIdentiyNo'] ?? null);   // Vehicle 모델이 저장 시 자동 암호화
        $set($reg, 'nice_reg_first_date', $this->formatYmd($d['resFirstDate'] ?? null));
        $set($reg, 'nice_reg_fuel_type', $d['useFuelNm'] ?? null);
        $set($reg, 'nice_reg_passengers', $d['tkcarPscapCo'] ?? null);
        $set($reg, 'nice_reg_max_load', $d['mxmmLdg'] ?? null);
        $set($reg, 'mileage', $d['resValidDistance'] ?? null);   // 기본정보 주행거리
        $set($reg, 'year', $d['resCarYearModel'] ?? null);       // 기본정보 연식
        $set($reg, 'model_type', $d['commCarName'] ?? null);     // 기본정보 차종
        $set($reg, 'brand', $d['mnfctEntrpsNm'] ?? null);        // 기본정보 제조사

        // ── spec + 기본정보 ──
        $spec = [];
        $set($spec, 'nice_spec_length', $d['cbdLt'] ?? null);
        $set($spec, 'nice_spec_width', $d['cbdBt'] ?? null);
        $set($spec, 'nice_spec_height', $d['cbdHg'] ?? null);
        $set($spec, 'nice_spec_maker', $d['mnfctEntrpsNm'] ?? null);
        $set($spec, 'nice_spec_year', $d['resCarYearModel'] ?? null);
        $set($spec, 'nice_spec_curb_weight', $d['vhcleWt'] ?? null);
        $set($spec, 'weight_kg', $d['vhcleWt'] ?? null);          // 기본정보 중량
        $displacement = $this->parseDisplacement($d['engineSpec'] ?? null);
        $set($spec, 'nice_spec_displacement', $displacement);
        $set($spec, 'cc', $displacement);                        // 기본정보 배기량

        // 대응 컬럼 없음(resValidPeriod·resSpecControlNo·maxPower·mtrsFomNm·fomNm)은 raw 에만 보존.
        // NICE 미제공 컬럼(transmission·drive_type·wheelbase·fuel_efficiency)은 빈 채로 둔다.
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
