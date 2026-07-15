<?php

namespace Tests\Feature;

use App\Services\CarmodooService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 원부조회(carmodoo) 서비스 — 파서·가드 단위 검증.
 * 실제 carmodoo 호출은 IP 화이트리스트라 CI 불가 → HTML 파싱과 미설정 가드만 테스트(라이브 미의존).
 */
class CarmodooServiceTest extends TestCase
{
    private function svc(string $id = '', string $pw = ''): CarmodooService
    {
        return new CarmodooService('https://sh.carmodoo.com', null, $id, $pw, '89617');
    }

    /** 실제 응답 구조(합성 fixture, 가짜 데이터) — 제원/요약/저당·압류 파싱. */
    private const FIXTURE = <<<'HTML'
<div class="wonbu_info"><div class="info_wrap"><table class="t_form wonbu_td wonbu_big"><tbody>
<tr><th class="subtitle">조회번호</th><td>99999999</td></tr>
<tr><th class="subtitle">자동차번호</th><td><strong>12가3456</strong></td></tr>
<tr><th class="subtitle">차명</th><td>테스트카 3.0</td></tr>
<tr><th class="subtitle">차대번호</th><td>TESTVIN0000000001 <a href="javascript:searchViNumber('X');" class="btn blue">재조회</a></td></tr>
<tr><th class="subtitle">관할시도</th><td>서울&nbsp;</td></tr>
<tr><th class="subtitle">처리불가사유명세</th><td>운행차량&nbsp;</td></tr>
</tbody></table></div>
<span>원부조회 확인결과 - </span><span>압류 <strong>1</strong>건 / 저당 <strong>2</strong>건 / 구조 <strong>0</strong>건</span>
<div class="list_wrap"><table class="t_list data_cont"><tbody>
<tr class="bg"><td>저당</td><td>20250101</td><td>111/테스트캐피탈(주)//채권가액:5,000,000WON</td></tr>
<tr class="bg"><td>압류</td><td>20250202</td><td>222/서울특별시//압류</td></tr>
</tbody></table></div></div>
HTML;

    #[Test]
    public function parses_detail_summary_and_liens(): void
    {
        $r = $this->svc()->parseHtml(self::FIXTURE);

        $this->assertTrue($r['success']);

        // 제원 — 값 정리(nbsp/공백) + td 내 버튼(재조회) 제거
        $this->assertSame('12가3456', $r['detail']['자동차번호']);
        $this->assertSame('TESTVIN0000000001', $r['detail']['차대번호']);
        $this->assertSame('서울', $r['detail']['관할시도']);

        // 요약
        $this->assertSame(['압류' => 1, '저당' => 2, '구조' => 0], $r['summary']);

        // 저당/압류 상세 2행
        $this->assertCount(2, $r['liens']);
        $this->assertSame('저당', $r['liens'][0]['type']);
        $this->assertSame('20250101', $r['liens'][0]['date']);
        $this->assertStringContainsString('테스트캐피탈', $r['liens'][0]['info']);
        $this->assertSame('압류', $r['liens'][1]['type']);

        $this->assertNull($r['note']);
    }

    #[Test]
    public function flags_no_result(): void
    {
        $html = '<div class="wonbu_info"><table><tbody>'
            .'<tr><th class="subtitle">자동차번호</th><td>12가3456</td></tr>'
            .'<tr><th class="subtitle">처리불가사유명세</th><td>조회결과 없음</td></tr>'
            .'</tbody></table></div>';
        $r = $this->svc()->parseHtml($html);

        $this->assertTrue($r['success']);
        $this->assertNotNull($r['note']);
        $this->assertSame([], $r['liens']);
    }

    #[Test]
    public function unconfigured_returns_message_not_exception(): void
    {
        $r = $this->svc(id: '', pw: '')->lookup('12가3456');

        $this->assertFalse($r['success']);
        $this->assertStringContainsString('설정', $r['message']);
    }

    #[Test]
    public function blank_plate_returns_message(): void
    {
        $r = $this->svc(id: 'x', pw: 'y')->lookup('   ');

        $this->assertFalse($r['success']);
        $this->assertStringContainsString('차량번호', $r['message']);
    }

    #[Test]
    public function is_configured_requires_id_and_password(): void
    {
        $this->assertFalse($this->svc(id: 'x', pw: '')->isConfigured());
        $this->assertFalse($this->svc(id: '', pw: 'y')->isConfigured());
        $this->assertTrue($this->svc(id: 'x', pw: 'y')->isConfigured());
    }
}
