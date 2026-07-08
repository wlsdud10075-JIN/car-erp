<?php

namespace App\Services;

use App\Models\AlimtalkLog;
use App\Support\AlimtalkConfig;
use App\Support\AlimtalkTemplates;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * 카카오 알림톡(BizM/스윗트래커) 발송 — 스윗트래커+비즈엠 게이트웨이 경유.
 *
 * ⚠️ fire-and-forget: 이 서비스는 호출측(차량 저장·정산 확정 훅 등)으로 **절대 예외를 던지지 않는다**.
 *    알림톡 실패(네트워크·미설정·BizM 오류)가 업무 저장을 깨면 안 되므로, 모든 결과를 AlimtalkLog 로만 남긴다.
 *    게이트(enabled)·미설정·개별 off 는 status='skipped' 로 기록하고 발송하지 않는다(배포 ≠ 작동, ScanTaskAlarms 패턴).
 *
 * BizM v2 발송: POST /v2/sender/send, 헤더 userid, 바디 = 배열[ {profile,tmplId,phn,msg,(title)} ].
 *   응답 배열의 msgid 를 성공 신호 겸 결과조회 키로 저장. (message_type='AT'·title 은 BizM v2 관례 —
 *   확정 코어 필드는 profile/tmplId/phn/msg, 나머지는 테스트 발송으로 검증 후 조정.)
 */
class BizmAlimtalkService
{
    private const SEND_URL = 'https://alimtalk-api.bizmsg.kr/v2/sender/send';

    public function __construct(private AlimtalkConfig $config) {}

    public static function active(): self
    {
        return new self(AlimtalkConfig::active());
    }

    /**
     * 알림톡 1건 발송.
     *
     * @param  string  $code  템플릿 코드(erp_*)
     * @param  string  $phone  수신 번호(하이픈 무관 — 숫자만 정규화)
     * @param  array  $vars  `#{변수}` 치환값 (예: ['차량번호' => '19더9065'])
     * @param  array  $context  ['vehicle_id'=>, 'user_id'=>] 로그 맥락(선택)
     * @param  array  $buttons  웹링크 버튼 배열(선택) — [['name'=>,'type'=>'WL','url_mobile'=>,'url_pc'=>], ...].
     *                          BizM 템플릿에 등록된 버튼과 일치해야 발송됨(발송 시 URL 만 주입). 정산 승인 링크 등.
     * @return AlimtalkLog status: sent|failed|skipped
     */
    public function send(string $code, string $phone, array $vars = [], array $context = [], array $buttons = []): AlimtalkLog
    {
        $phone = $this->normalizePhone($phone);
        $base = [
            'vehicle_id' => $context['vehicle_id'] ?? null,
            'user_id' => $context['user_id'] ?? null,
            'template_code' => $code,
            'phone' => $phone,
        ];

        if (! isset(AlimtalkTemplates::TEMPLATES[$code])) {
            return AlimtalkLog::create($base + ['status' => 'skipped', 'error' => 'unknown_template']);
        }
        if ($phone === '') {
            return AlimtalkLog::create($base + ['status' => 'skipped', 'error' => 'no_phone']);
        }
        if (! $this->config->canSend($code)) {
            return AlimtalkLog::create($base + ['status' => 'skipped', 'error' => 'disabled_or_unconfigured']);
        }

        $message = AlimtalkTemplates::render($code, $vars);
        $base['message'] = $message;

        try {
            // 1차 롤아웃 = 전부 기본형(선택안함)으로 BizM 등록 → emphasis title 은 안 보낸다.
            // (강조표기형은 강조문구+보조문구 필수라 검수 마찰. 추후 특정 알림만 승격 시 title 재도입.)
            $item = [
                'message_type' => 'AT',
                'phn' => $phone,
                'profile' => $this->config->profile,
                'tmplId' => $this->config->tmplId($code),
                'msg' => $message,
            ];
            // 웹링크 버튼(정산 승인 링크 등) — 등록된 버튼에 발송 시점 URL 주입.
            if (! empty($buttons)) {
                $item['button'] = array_values($buttons);
            }

            $response = Http::timeout(15)
                ->withHeaders(['userid' => $this->config->userid])
                ->post(self::SEND_URL, [$item]);

            if ($response->failed()) {
                return AlimtalkLog::create($base + [
                    'status' => 'failed',
                    'error' => Str::limit('HTTP '.$response->status().' '.$response->body(), 480, ''),
                ]);
            }

            $body = $response->json();
            $first = is_array($body) ? ($body[0] ?? $body) : [];
            // BizM v2 실응답(2026-07-07 실측): [{"code":"success","data":{"msgid":"WEB..."},"message":"K000"}].
            // msgid 는 data 하위 → data.msgid 우선, 최상위 msgid 는 fallback(테스트 fake 호환).
            $msgid = is_array($first) ? ($first['data']['msgid'] ?? $first['msgid'] ?? null) : null;

            if ($msgid) {
                return AlimtalkLog::create($base + ['status' => 'sent', 'msgid' => (string) $msgid]);
            }

            // msgid 없음 = BizM 이 접수 실패(코드/사유 body 에). 원문 일부를 error 로 남겨 진단.
            return AlimtalkLog::create($base + [
                'status' => 'failed',
                'error' => Str::limit('no msgid — '.json_encode($body, JSON_UNESCAPED_UNICODE), 480, ''),
            ]);
        } catch (\Throwable $e) {
            // cron/훅 무음 실패 방지 — 반드시 로그. 예외는 여기서 흡수(호출측 안 깨짐).
            Log::warning('alimtalk send failed', ['code' => $code, 'error' => $e->getMessage()]);

            return AlimtalkLog::create($base + ['status' => 'failed', 'error' => Str::limit($e->getMessage(), 480, '')]);
        }
    }

    /**
     * 테스트 발송 — 일일요약 템플릿을 지정 번호로 보낸다(크리덴셜/승인 검증용, 기능설정 버튼).
     * 마스터/개별 게이트가 꺼져 있어도 테스트는 확인이 목적이라, 계정 설정 + 해당 tmplId 만 있으면 보낸다.
     */
    public function sendTest(string $phone): AlimtalkLog
    {
        $phone = $this->normalizePhone($phone);
        $base = [
            'user_id' => auth()->id(),
            'template_code' => 'erp_daily_summary',
            'phone' => $phone,
        ];

        if ($phone === '') {
            return AlimtalkLog::create($base + ['status' => 'skipped', 'error' => 'no_phone']);
        }
        if (! $this->config->isConfigured()) {
            return AlimtalkLog::create($base + ['status' => 'skipped', 'error' => 'unconfigured']);
        }
        if ($this->config->tmplId('erp_daily_summary') === '') {
            return AlimtalkLog::create($base + ['status' => 'skipped', 'error' => 'no_test_tmplid']);
        }

        // 게이트를 일시 우회한 임시 config 로 실제 발송 (테스트 목적).
        $testConfig = new AlimtalkConfig(
            $this->config->set, $this->config->userid, $this->config->profile,
            $this->config->userkey, true,
            $this->config->tmplIds,
            ['erp_daily_summary' => true] + $this->config->toggles,
        );

        return (new self($testConfig))->send('erp_daily_summary', $phone, [
            '날짜' => now()->toDateString(),
            '판매건수' => '0',
            '매출액' => '0원',
            '선적전건수' => '0',
            '선적전금액' => '0원',
            '선적후건수' => '0',
            '선적후금액' => '0원',
            '미수합계' => '0원',
        ], ['user_id' => auth()->id()]);
    }

    private function normalizePhone(string $phone): string
    {
        return (string) preg_replace('/[^0-9]/', '', $phone);
    }
}
