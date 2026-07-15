<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 자동차 원부조회 — 경기도자동차매매사업조합 제시매도관리시스템(sh.carmodoo.com, Java/Tomcat).
 *
 * 차량번호(또는 차대번호)로 등록원부를 실시간 조회해 **제원 + 압류/저당/구조**를 돌려준다.
 * 저장하지 않는 on-demand 조회(채권·소유 정보라 PII 최소보유). 실패/미조회 시 조용히 실패 배열(throw 안 함).
 *
 * 로그인 함정(실측 2026-07-15):
 *  - `POST /member/loginPost.do` 는 헤더 `AJAX: true` 없으면 {"success":false,"commonMsg":"다시 시도하세요"},
 *    `User-Agent` 비면 거부. 둘 다 있어야 {"success":true} + JSESSIONID.
 *  - ⚠️ 짧은 간격 재로그인도 "다시 시도하세요"로 거부됨 → 세션(JSESSIONID) 캐시 재사용으로 회피
 *    (정상 운영은 세션 만료(~25분) 시에만 재로그인이라 문제 없음).
 *  - 조회 `POST /wonbu/searchPost.do` 는 세션쿠키 + `carNum|viNumber|dNo|simpleFlag` → HTML 조각(UTF-8).
 *
 * ⚠️ IP 화이트리스트: 조합 등록 IP(사무실 회선)에서만 조회. 운영서버 직접 호출은 계정 정지 위험 →
 *    config('services.carmodoo.proxy') 로 등록 IP에 둔 포워드 프록시 경유(빈값=직접, dev/등록회선).
 */
class CarmodooService
{
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36';

    private const SESSION_CACHE_KEY = 'carmodoo_jsessionid';

    private const SESSION_TTL = 1500;   // 25분 (Tomcat 기본 세션 만료 여유)

    public function __construct(
        private string $baseUrl,
        private ?string $proxy,
        private string $id,
        private string $passwd,
        private string $dNo,
    ) {}

    public static function fromConfig(): self
    {
        $passwd = '';
        if ($enc = Setting::get('carmodoo_passwd')) {
            try {
                $passwd = Crypt::decryptString($enc);
            } catch (\Throwable) {
                $passwd = '';
            }
        }

        return new self(
            baseUrl: rtrim((string) config('services.carmodoo.base_url', 'https://sh.carmodoo.com'), '/'),
            proxy: (string) config('services.carmodoo.proxy', '') ?: null,
            id: (string) (Setting::get('carmodoo_id', '') ?: ''),
            passwd: $passwd,
            dNo: (string) (Setting::get('carmodoo_dno', '') ?: ''),
        );
    }

    /** 조회 계정이 설정됐는지(아이디+비밀번호). 담당사원(dNo)은 선택. */
    public function isConfigured(): bool
    {
        return $this->id !== '' && $this->passwd !== '';
    }

    /**
     * 원부조회 — 차량번호(우선) 또는 차대번호로.
     *
     * @return array 성공: ['success'=>true,'detail'=>[라벨=>값],'summary'=>['압류'=>n,'저당'=>n,'구조'=>n],'liens'=>[[type,date,info]],'note'=>?]
     *               실패: ['success'=>false,'message'=>'...']
     */
    public function lookup(string $carNum, string $viNumber = ''): array
    {
        if (! $this->isConfigured()) {
            return ['success' => false, 'message' => '원부조회 계정이 설정되지 않았습니다. 기능설정에서 아이디/비밀번호를 등록하세요.'];
        }

        $carNum = preg_replace('/\s+/u', '', trim($carNum));
        $viNumber = trim($viNumber);
        if ($carNum === '' && $viNumber === '') {
            return ['success' => false, 'message' => '차량번호 또는 차대번호를 입력하세요.'];
        }

        $sid = $this->session();
        if ($sid === null) {
            return ['success' => false, 'message' => '원부조회 로그인에 실패했습니다. 계정/비밀번호(또는 프록시 설정)를 확인하세요.'];
        }

        $html = $this->query($sid, $carNum, $viNumber);
        if ($html === null) {
            // 세션 만료 추정 → 1회 재로그인 후 재시도
            Cache::forget(self::SESSION_CACHE_KEY);
            $sid = $this->login();
            if ($sid === null) {
                return ['success' => false, 'message' => '원부조회 세션을 갱신하지 못했습니다.'];
            }
            $html = $this->query($sid, $carNum, $viNumber);
        }
        if ($html === null) {
            return ['success' => false, 'message' => '원부조회 요청에 실패했습니다. 잠시 후 다시 시도하세요.'];
        }

        return $this->parseHtml($html);
    }

    /** 캐시된 세션(JSESSIONID) 재사용, 없으면 로그인. */
    private function session(): ?string
    {
        $sid = Cache::get(self::SESSION_CACHE_KEY);

        return $sid ?: $this->login();
    }

    /** 로그인 → JSESSIONID. 실패 null. AJAX:true + User-Agent 필수. */
    private function login(): ?string
    {
        try {
            // ① 초기 세션 획득 (login.do GET)
            $g = Http::withOptions($this->httpOptions())
                ->withHeaders(['User-Agent' => self::UA])
                ->get($this->baseUrl.'/member/login.do');
            $sid = $this->extractJsession($g);

            // ② loginPost.do POST
            $headers = ['User-Agent' => self::UA, 'AJAX' => 'true'];
            if ($sid) {
                $headers['Cookie'] = "JSESSIONID={$sid}";
            }
            $r = Http::withOptions($this->httpOptions())
                ->withHeaders($headers)
                ->asForm()
                ->post($this->baseUrl.'/member/loginPost.do', [
                    'id' => $this->id,
                    'passwd' => $this->passwd,
                    'idSave' => 'Y',
                ]);

            $newSid = $this->extractJsession($r) ?: $sid;
            if ($r->json('success') !== true || ! $newSid) {
                Log::warning('carmodoo login failed', ['msg' => $r->json('commonMsg')]);

                return null;
            }

            Cache::put(self::SESSION_CACHE_KEY, $newSid, self::SESSION_TTL);

            return $newSid;
        } catch (\Throwable $e) {
            Log::warning('carmodoo login error', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /** searchPost.do → HTML 본문. 세션만료/오류 시 null(호출측이 재로그인 판단). */
    private function query(string $sid, string $carNum, string $viNumber): ?string
    {
        try {
            $r = Http::withOptions($this->httpOptions())
                ->withHeaders([
                    'User-Agent' => self::UA,
                    'Cookie' => "JSESSIONID={$sid}",
                    'X-Requested-With' => 'XMLHttpRequest',
                    'Referer' => $this->baseUrl.'/wonbu/search.do',
                ])
                ->asForm()
                ->post($this->baseUrl.'/wonbu/searchPost.do', [
                    'carNum' => $carNum,
                    'viNumber' => $viNumber,
                    'dNo' => $this->dNo,
                    'simpleFlag' => '0',
                ]);

            if ($r->failed()) {
                return null;
            }
            $body = $r->body();
            // 세션 만료 시 로그인 페이지/리다이렉트가 옴 → 원부 마커 없으면 null(재로그인 트리거)
            if (! str_contains($body, 'wonbu_') && ! str_contains($body, '조회번호')) {
                return null;
            }

            return $body;
        } catch (\Throwable $e) {
            Log::warning('carmodoo query error', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /** 응답 HTML(UTF-8) → 제원/요약/저당·압류 상세. (테스트 가능하도록 public) */
    public function parseHtml(string $html): array
    {
        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        $xp = new \DOMXPath($dom);

        // ── 제원: .wonbu_info 안의 th(라벨) → td(값) ──
        $detail = [];
        foreach ($xp->query("//div[contains(@class,'wonbu_info')]//tr") as $tr) {
            $th = $xp->query('.//th', $tr)->item(0);
            $td = $xp->query('.//td', $tr)->item(0);
            if (! $th || ! $td) {
                continue;
            }
            // td 안의 버튼/링크(재조회 등) 제거 후 값만
            foreach (iterator_to_array($xp->query('.//a|.//button', $td)) as $node) {
                $node->parentNode->removeChild($node);
            }
            $label = $this->clean($th->textContent);
            $value = $this->clean($td->textContent);
            if ($label !== '') {
                $detail[$label] = $value;
            }
        }

        // ── 요약: "압류 N건 / 저당 N건 / 구조 N건" ──
        $text = $this->clean($dom->textContent);
        $summary = ['압류' => 0, '저당' => 0, '구조' => 0];
        foreach ($summary as $k => $_) {
            if (preg_match('/'.$k.'\s*(\d+)\s*건/u', $text, $m)) {
                $summary[$k] = (int) $m[1];
            }
        }

        // ── 저당/압류/구조 상세: table.data_cont 의 데이터 행(3열) ──
        $liens = [];
        foreach ($xp->query("//table[contains(@class,'data_cont')]//tr") as $tr) {
            $tds = $xp->query('.//td', $tr);
            if ($tds->length < 3) {
                continue;   // 헤더행(th) 스킵
            }
            $liens[] = [
                'type' => $this->clean($tds->item(0)->textContent),
                'date' => $this->clean($tds->item(1)->textContent),
                'info' => $this->clean($tds->item(2)->textContent),
            ];
        }

        // 미조회(결과 없음) — 처리불가사유명세가 '조회결과 없음'
        $note = null;
        $reason = $detail['처리불가사유명세'] ?? '';
        if ($reason !== '' && str_contains($reason, '없음')) {
            $note = '해당 차량번호로 조회된 원부가 없습니다.';
        }

        return [
            'success' => true,
            'detail' => $detail,
            'summary' => $summary,
            'liens' => $liens,
            'note' => $note,
        ];
    }

    /** Set-Cookie 헤더에서 JSESSIONID 추출 (Laravel Http ->cookies() 는 jar 미부착 시 비어서 헤더 직접 파싱). */
    private function extractJsession($response): ?string
    {
        $cookies = $response->headers()['Set-Cookie'] ?? [];
        foreach ((array) $cookies as $c) {
            if (preg_match('/JSESSIONID=([^;]+)/', $c, $m)) {
                return $m[1];
            }
        }

        return null;
    }

    private function httpOptions(): array
    {
        $opt = ['timeout' => 15, 'verify' => false];
        if ($this->proxy) {
            $opt['proxy'] = $this->proxy;
        }

        return $opt;
    }

    /** 공백/nbsp 정리. */
    private function clean(string $s): string
    {
        $s = str_replace("\xc2\xa0", ' ', $s);   // nbsp

        return trim(preg_replace('/\s+/u', ' ', $s));
    }
}
