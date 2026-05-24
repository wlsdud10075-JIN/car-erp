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
}
