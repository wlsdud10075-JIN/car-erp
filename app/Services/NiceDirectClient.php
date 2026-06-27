<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * NICE 차량정보 직접 2단계 조회 (게이트웨이 이식).
 *
 * 기존 ssancar-erp(Django) 의 exportstatus/vehicle_api.py::nice_vehicle_lookup 을 PHP 로 포팅.
 * ssancarerp(heymancar.com 박스, 54.116.7.83)에서만 동작 — NICE IP 화이트리스트가 이 박스라
 * 같은 박스의 car-erp 가 NICE 를 직접 호출할 수 있다. 다른 박스(heymanerp 등)는 이 박스의
 * /provide/api/nice-lookup/ 게이트웨이를 경유(ProvideNiceLookupController)한다.
 *
 * 1단계 kindOf=1 (등록원부) → resSpecControlNo 획득
 * 2단계 kindOf=400 (상세제원) → 상세 제원
 * 반환 형식(data 25필드)은 Django 응답과 동일 — NiceApiService::transform() 이 그대로 소비.
 */
class NiceDirectClient
{
    private string $apiUrl;

    private string $apiKey;

    private string $loginId;

    private int $businessNumber;

    private ?array $manufacturerMap = null;

    public function __construct()
    {
        $this->apiUrl = (string) config('services.nice.direct.api_url');
        $this->apiKey = (string) config('services.nice.direct.api_key');
        $this->loginId = (string) config('services.nice.direct.login_id');
        $this->businessNumber = (int) config('services.nice.direct.business_number');
    }

    /**
     * @return array{success:bool,message:string,data?:array,status?:int}
     *                                                                    status 는 HTTP 상태 힌트(컨트롤러가 사용). 성공 시 생략.
     */
    public function lookup(string $vehicleNumber, string $ownerName): array
    {
        $vehicleNumber = trim($vehicleNumber);
        $ownerName = trim($ownerName);

        if ($vehicleNumber === '' || $ownerName === '') {
            return ['success' => false, 'message' => '차량번호와 소유자명을 모두 입력해주세요.', 'status' => 400];
        }

        if ($this->apiKey === '' || $this->loginId === '' || $this->businessNumber === 0) {
            return ['success' => false, 'message' => 'NICE 직접 호출 설정(NICE_DIRECT_*)이 누락되었습니다.', 'status' => 500];
        }

        try {
            // ── 1단계: 등록원부 조회 (kindOf=1) ──
            [$sec1, $key1] = $this->chk();
            $r1 = Http::timeout(45)->post($this->apiUrl, [
                'apiKey' => $this->apiKey, 'chkSec' => $sec1, 'chkKey' => $key1,
                'loginId' => $this->loginId, 'kindOf' => '1',
                'ownerNm' => $ownerName, 'vhrNo' => $vehicleNumber,
            ]);
            $r1->throw();
            $d1 = $r1->json();

            if (($d1['resultCode'] ?? '') !== '0000') {
                return ['success' => false, 'message' => '등록원부 조회 실패: '.($d1['resultMsg'] ?? '').' (코드: '.($d1['resultCode'] ?? '').')', 'status' => 400];
            }
            $reg = $d1['carParts']['outB0001']['list'][0] ?? null;
            if (! is_array($reg)) {
                return ['success' => false, 'message' => '등록원부 데이터를 찾을 수 없습니다.', 'status' => 404];
            }
            $spmn = (string) ($reg['resSpecControlNo'] ?? '');
            if ($spmn === '') {
                return ['success' => false, 'message' => '제원관리번호를 찾을 수 없습니다.', 'status' => 404];
            }

            // ── 2단계: 상세제원 조회 (kindOf=400, spmnno) ──
            [$sec2, $key2] = $this->chk();
            $r2 = Http::timeout(45)->post($this->apiUrl, [
                'apiKey' => $this->apiKey, 'chkSec' => $sec2, 'chkKey' => $key2,
                'loginId' => $this->loginId, 'kindOf' => '400', 'spmnno' => $spmn,
            ]);
            $r2->throw();
            $d2 = $r2->json();

            if (($d2['resultCode'] ?? '') !== '0000') {
                return ['success' => false, 'message' => '상세제원 조회 실패: '.($d2['resultMsg'] ?? '알 수 없는 오류').' (코드: '.($d2['resultCode'] ?? '').')', 'status' => 400];
            }
            $detailCode = (string) ($d2['carDetailSpec']['dtlSpecRsltCode'] ?? '');
            if ($detailCode !== '100') {
                return ['success' => false, 'message' => '상세제원 조회 실패: '.($d2['carDetailSpec']['dtlSpecRsltMsg'] ?? '알 수 없는 오류').' (코드: '.$detailCode.')', 'status' => 400];
            }
            $dtl = $d2['carDetailSpec']['dtlSpecInfo'] ?? null;
            $dim = $d2['carDetailSpec']['dimensionInfo'] ?? null;
            if (! is_array($dtl) || ! is_array($dim)) {
                return ['success' => false, 'message' => '상세제원 데이터를 찾을 수 없습니다.', 'status' => 404];
            }

            $commCarName = (string) ($reg['commCarName'] ?? '');
            $manufacturer = $this->correctManufacturer($commCarName, (string) ($dtl['mnfctEntrpsNm'] ?? ''));

            // Django result_data 와 동일한 25필드 (registration 13 + detail 12)
            $data = [
                'resCarModelType' => $reg['resCarModelType'] ?? '',
                'resUseType' => $reg['resUseType'] ?? '',
                'commCarName' => $commCarName,
                'resCarYearModel' => $reg['resCarYearModel'] ?? '',
                'resVehicleIdNo' => $reg['resVehicleIdNo'] ?? '',
                'resMotorType' => $reg['resMotorType'] ?? '',
                'resGarage' => $reg['resGarage'] ?? '',
                'resFinalOwner' => $reg['resFinalOwner'] ?? '',
                'resUserIdentiyNo' => $reg['resUserIdentiyNo'] ?? '',
                'resSpecControlNo' => $spmn,
                'resValidPeriod' => $reg['resValidPeriod'] ?? '',
                'resValidDistance' => $reg['resValidDistance'] ?? '',
                'resFirstDate' => $reg['resFirstDate'] ?? '',
                'cbdLt' => $dim['cbdLt'] ?? '',
                'cbdBt' => $dim['cbdBt'] ?? '',
                'cbdHg' => $dim['cbdHg'] ?? '',
                'engineSpec' => $dtl['engineSpec'] ?? '',
                'maxPower' => $dtl['maxPower'] ?? '',
                'tkcarPscapCo' => $dtl['tkcarPscapCo'] ?? '',
                'mxmmLdg' => $dtl['mxmmLdg'] ?? '',
                'useFuelNm' => $dtl['useFuelNm'] ?? '',
                'mnfctEntrpsNm' => $manufacturer,           // 제조사명 (보정됨)
                'vhcleWt' => $dtl['vhcleTotWt'] ?? '',       // 차량총중량 (Django 와 동일: vhcleTotWt 값)
                'mtrsFomNm' => $dtl['mtrsFomNm'] ?? '',
                'fomNm' => $dtl['fomNm'] ?? '',
                'fuelCnsmpRt' => $dtl['fuelCnsmpRt'] ?? '',  // 공인연비
            ];

            return ['success' => true, 'message' => '차량 정보 조회 성공', 'data' => $data];
        } catch (ConnectionException $e) {
            Log::warning('NICE direct connection failed', ['vehicle' => $vehicleNumber, 'error' => $e->getMessage()]);

            return ['success' => false, 'message' => 'API 요청 시간 초과. 잠시 후 다시 시도해주세요.', 'status' => 504];
        } catch (\Throwable $e) {
            Log::warning('NICE direct call failed', ['vehicle' => $vehicleNumber, 'error' => $e->getMessage()]);

            return ['success' => false, 'message' => 'API 요청 오류: '.$e->getMessage(), 'status' => 500];
        }
    }

    /** chkSec(현재시각 YmdHis) + chkKey = MOD(MOD(chkSec, 사업자번호), 997). 박스 TZ=Asia/Seoul (Django 와 동일). */
    private function chk(): array
    {
        $sec = date('YmdHis');
        $key = (string) (((int) $sec % $this->businessNumber) % 997);

        return [$sec, $key];
    }

    /** 차량명(commCarName)으로 제조사 보정. 매핑에 없으면 NICE 원본 그대로 (Django get_corrected_manufacturer 동일). */
    private function correctManufacturer(string $commCarName, string $original): string
    {
        if ($commCarName === '') {
            return $original;
        }
        $map = $this->manufacturerMap();

        return $map[$commCarName] ?? $map[trim($commCarName)] ?? $original;
    }

    private function manufacturerMap(): array
    {
        if ($this->manufacturerMap === null) {
            $path = resource_path('nice/manufacturer_mapping.json');
            $this->manufacturerMap = is_file($path)
                ? (json_decode((string) file_get_contents($path), true) ?: [])
                : [];
        }

        return $this->manufacturerMap;
    }
}
