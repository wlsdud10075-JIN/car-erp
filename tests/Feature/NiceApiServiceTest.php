<?php

namespace Tests\Feature;

use App\Services\NiceApiService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NiceApiServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();   // lookupVehicle 성공 캐시 격리
    }

    public function test_returns_null_when_endpoint_not_configured(): void
    {
        // URL/토큰 미설정 → 수동 입력 모드 (null)
        $this->assertNull((new NiceApiService('', ''))->lookupVehicle('12가1234', '홍길동'));
    }

    public function test_humanize_error_timeout(): void
    {
        // 504 또는 "시간 초과" → 응답 지연 + 한도 무관 안내.
        $byStatus = NiceApiService::humanizeError('API 요청 시간 초과. 잠시 후 다시 시도해주세요.', 504);
        $this->assertStringContainsString('지연', $byStatus);
        $this->assertStringContainsString('한도', $byStatus);

        $byText = NiceApiService::humanizeError('상세제원 조회 실패: 요청 시간 초과');
        $this->assertStringContainsString('지연', $byText);
    }

    public function test_humanize_error_target_institution_failure_5000(): void
    {
        // 실측 메시지(jin 2026-06-24): 코드 5000 = 대상기관 장애.
        $raw = '등록원부 조회 실패:[parts][pe]:대상기관 장애로 조회가 원활하지 않습니다.(코드5000)';
        $r = NiceApiService::humanizeError($raw, 400);
        $this->assertStringContainsString('원천기관', $r);
        $this->assertStringNotContainsString('[parts]', $r);   // 기술 노이즈 제거
    }

    public function test_humanize_error_owner_mismatch(): void
    {
        $r = NiceApiService::humanizeError('등록원부 조회 실패: 소유주명이 일치하지 않습니다 (코드: E901)', 400);
        $this->assertStringContainsString('소유주명', $r);
        $this->assertStringContainsString('상품용', $r);
    }

    public function test_humanize_error_unknown_code_keeps_original(): void
    {
        // 미확인 코드 → NICE 원문 그대로(틀린 안내 방지).
        $raw = '등록원부 조회 실패: 알 수 없는 오류 (코드: 7777)';
        $this->assertSame($raw, NiceApiService::humanizeError($raw, 400));
    }

    public function test_requires_vehicle_and_owner(): void
    {
        $svc = new NiceApiService('https://ssancar.test/provide/api/nice-lookup/', 'tok');

        $r = $svc->lookupVehicle('12가1234', '');
        $this->assertFalse($r['success']);
        $this->assertStringContainsString('소유자명', $r['message']);
    }

    public function test_successful_lookup_maps_fields(): void
    {
        Http::fake(['*' => Http::response([
            'success' => true,
            'message' => 'ok',
            'data' => [
                'resVehicleIdNo' => 'KMHXX123', 'mnfctEntrpsNm' => '현대', 'commCarName' => '쏘나타',
                'resCarYearModel' => '2017', 'resValidDistance' => '120000', 'resFirstDate' => '20170428',
                'useFuelNm' => '경유', 'resFinalOwner' => '홍길동', 'resUserIdentiyNo' => '700101-1234567',
                'engineSpec' => '6/3342', 'vhcleWt' => '1600', 'cbdLt' => '4900',
                'fuelCnsmpRt' => '9.5',   // 연비 — 소수점 보존
                'maxPower' => '190/4000', 'resSpecControlNo' => 'SPEC-1',   // 미매핑 → raw 만
            ],
        ], 200)]);

        $r = (new NiceApiService('https://ssancar.test/provide/api/nice-lookup/', 'tok'))
            ->lookupVehicle('12가1234', '홍길동');

        $this->assertTrue($r['success']);
        // registration + 기본정보
        $this->assertSame('KMHXX123', $r['registration']['nice_reg_vin']);
        $this->assertSame('현대', $r['registration']['brand']);
        $this->assertSame('쏘나타', $r['registration']['model_type']);
        $this->assertSame('120000', $r['registration']['mileage']);
        $this->assertSame('2017-04-28', $r['registration']['nice_reg_first_date']);   // YYYYMMDD → Y-m-d
        $this->assertSame('경유', $r['registration']['nice_reg_fuel_type']);
        $this->assertSame('700101-1234567', $r['registration']['nice_reg_owner_rrn']);
        // spec + 기본정보
        $this->assertSame('3342', $r['spec']['nice_spec_displacement']);   // engineSpec '/' 뒤
        $this->assertSame('3342', $r['spec']['cc']);
        $this->assertSame('1600', $r['spec']['weight_kg']);
        $this->assertSame('4900', $r['spec']['nice_spec_length']);
        $this->assertSame('현대', $r['spec']['nice_spec_maker']);
        $this->assertSame('9.5', $r['spec']['nice_spec_fuel_efficiency']);   // 연비 소수점 보존
        // raw 원본 보존 (미매핑 필드 포함)
        $this->assertSame('190/4000', $r['raw']['maxPower']);
        $this->assertSame('SPEC-1', $r['raw']['resSpecControlNo']);
        // 헤더·본문 검증
        Http::assertSent(fn ($req) => $req->hasHeader('X-SSANCAR-API-KEY', 'tok')
            && $req['vehicle_number'] === '12가1234'
            && $req['owner_name'] === '홍길동');
    }

    public function test_passes_through_ssancar_failure_message(): void
    {
        Http::fake(['*' => Http::response(['success' => false, 'message' => '해당 차량 정보 없음'], 200)]);

        $r = (new NiceApiService('https://ssancar.test/x', 'tok'))->lookupVehicle('99바9999', '아무개');
        $this->assertFalse($r['success']);
        $this->assertSame('해당 차량 정보 없음', $r['message']);
    }

    public function test_http_error_returns_failure_with_status(): void
    {
        Http::fake(['*' => Http::response('server error', 500)]);

        $r = (new NiceApiService('https://ssancar.test/x', 'tok'))->lookupVehicle('11가1111', '아무개');
        $this->assertFalse($r['success']);
        $this->assertStringContainsString('HTTP 500', $r['message']);
    }

    public function test_engine_spec_without_slash(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => ['engineSpec' => '1999cc']], 200)]);

        $r = (new NiceApiService('https://ssancar.test/x', 'tok'))->lookupVehicle('22가2222', '아무개');
        $this->assertSame('1999', $r['spec']['nice_spec_displacement']);
    }

    public function test_successful_result_is_cached(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => ['resVehicleIdNo' => 'VIN1']], 200)]);

        $svc = new NiceApiService('https://ssancar.test/x', 'tok');
        $svc->lookupVehicle('33가3333', '아무개');
        $svc->lookupVehicle('33가3333', '아무개');   // 2번째는 캐시 → HTTP 1회만

        Http::assertSentCount(1);
    }

    /**
     * 실 NICE 형식 박제 (2026-05-26 로컬 실호출로 확인된 포맷):
     * - 숫자는 '주값(보조)' 또는 '주값/보조' → 첫 숫자 그룹만 (4840(0)→4840, 2115(2235)→2115, 4/0→4)
     * - 소유자 주민번호·주소는 NICE 가 마스킹(*) → 미매핑(빈 칸 → 수기)
     */
    public function test_parses_quirky_numeric_and_skips_masked_owner_info(): void
    {
        Http::fake(['*' => Http::response([
            'success' => true,
            'data' => [
                'cbdLt' => '4840(0)', 'cbdBt' => '1860(0)', 'cbdHg' => '1440(0)',
                'vhcleWt' => '2115(2235)', 'tkcarPscapCo' => '4/0', 'engineSpec' => '4/1950',
                'resGarage' => '서울특별시 강서구 *** *******',   // 마스킹
                'resUserIdentiyNo' => '110111-*******',          // 마스킹
            ],
        ], 200)]);

        $r = (new NiceApiService('https://ssancar.test/x', 'tok'))->lookupVehicle('69나3316', '(주)전진');

        // 숫자 — 첫 그룹만 (×10·연결 버그 회귀 방지)
        $this->assertSame('4840', $r['spec']['nice_spec_length']);
        $this->assertSame('1860', $r['spec']['nice_spec_width']);
        $this->assertSame('1440', $r['spec']['nice_spec_height']);
        $this->assertSame('2115', $r['spec']['weight_kg']);
        $this->assertSame('2115', $r['spec']['nice_spec_curb_weight']);
        $this->assertSame('4', $r['registration']['nice_reg_passengers']);
        $this->assertSame('1950', $r['spec']['nice_spec_displacement']);

        // 마스킹 — 미매핑(빈 칸 → 수기). 원본은 raw 보존(서류 파싱용).
        $this->assertArrayNotHasKey('nice_reg_owner_addr', $r['registration']);
        $this->assertArrayNotHasKey('nice_reg_owner_rrn', $r['registration']);
        $this->assertSame('4/1950', $r['raw']['engineSpec']);
    }
}
