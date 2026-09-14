<?php

namespace Tests;

use App\Models\Setting;
use App\Models\Settlement;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * 🚨 **정적 메모는 테스트 사이로 샌다.**
     *
     * `Settlement` 은 회사 프로파일·정산 파라미터를 static 으로 메모한다(요청 단위 캐시).
     * 그런데 `RefreshDatabase` 의 롤백은 **모델 이벤트를 안 태우므로**, 앞 테스트가 만든
     * `company_template_set=karaba` 가 DB 에서 사라져도 메모에는 `true` 가 남는다.
     * 그러면 뒤 테스트가 karaba 분기를 타 **정산액이 0 으로 나온다**(실측: CI 에서
     * `ManagerPanelLifecycleE2ETest` 가 405,000 대신 0).
     *
     * 개별 테스트가 `setUp` 에서 부르는 것에 의존하지 않는다 — 여기서 매번 버린다.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Settlement::flushParamMemo();
        // 💰 채권 위험도 파라미터(게이트 임계·오래됨·유예)도 같은 이유로 버린다 (2026-09-14).
        //    설정을 `DB::table()->insert` 로 심는 시더·테스트는 모델 이벤트를 안 태워 메모가 남는다.
        Setting::flushRiskParamMemo();
    }
}
