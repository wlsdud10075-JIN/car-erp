<?php

namespace Tests\Feature;

use App\Models\Vehicle;
use Tests\TestCase;

/**
 * i18n — **ko 에 있는 키는 en 에도 있어야 한다** (2026-08-27).
 *
 * 🚨 왜 이게 조용한 사고인가 — `config/app.php` 의 `fallback_locale` 이 **`en`** 이다.
 *    영어 화면에서 en 키가 없으면 폴백도 en 이라 **키 문자열이 그대로 렌더된다**
 *    (`settlement.batch.modal_title` 이 버튼에 찍힌다). 예외도 로그도 없다.
 *
 * 실제로 그렇게 새 있었다 — jin 제보(*"운항중·도착예정 토글이 영어화가 안 된 것 같다"*)로 훑어보니
 * **누락 27개가 전부 최근 개발분**이었다(월배치 제출 모달 16 · 마감차 과입금 4 · 전자서명 무르기 6 ·
 * 배치 조정 안내 1). 기능을 만들 때 ko 만 채우고 en 을 안 채운 것이다.
 * ⇒ 사람 기억으로는 못 막는다. 새 키를 ko 에만 넣으면 **여기서 실패한다.**
 *
 * ⚠️ 반대 방향(en 에만 있는 키)은 검사하지 않는다 — 영문 전용 문구가 있을 수 있고,
 *    그건 화면을 깨뜨리지 않는다. 깨지는 건 «ko 에 있는데 en 에 없는» 쪽뿐이다.
 */
class LocaleKeyParityTest extends TestCase
{
    /** `validation.php` 는 Laravel 기본 번역이라 대상이 아니다(부팅된 config 를 읽어 require 도 안 된다). */
    private const SKIP = ['validation.php'];

    public function test_every_korean_key_exists_in_english(): void
    {
        $missing = [];

        foreach (glob(lang_path('ko/*.php')) as $koPath) {
            $name = basename($koPath);
            if (in_array($name, self::SKIP, true)) {
                continue;
            }

            $enPath = lang_path('en/'.$name);
            $this->assertFileExists($enPath, "lang/en/{$name} 이 없다 — 영어 화면이 키를 그대로 찍는다");

            $ko = $this->flatten(require $koPath);
            $en = $this->flatten(require $enPath);

            foreach (array_keys(array_diff_key($ko, $en)) as $key) {
                $missing[] = "{$name}: {$key}";
            }
        }

        $this->assertSame([], $missing, sprintf(
            "영어 번역이 빠진 키 %d개 — fallback_locale 이 en 이라 **키 문자열이 화면에 그대로 뜬다**.\n%s",
            count($missing),
            implode("\n", array_slice($missing, 0, 40)),
        ));
    }

    /**
     * en 값에 한글이 남아 있으면 미번역이다.
     *
     * ⚠️ **키가 한글인 건 정상**이다 — `domain.progress.거래완료` 처럼 저장·비교값을 키로 쓰고
     *    값만 번역하는 게 이 프로젝트 방식이다(`lang/en/domain.php` 머리말).
     *    그래서 **값만** 본다. 그리고 값에 한글이 남아도 되는 것들이 실제로 있다
     *    (언어 전환 버튼의 `한국어`, 역할 키를 예시로 든 안내문 등) → 예외 목록으로 명시한다.
     */
    public function test_english_values_are_not_left_in_korean(): void
    {
        // 값에 한글이 남아도 되는 것 — 이유를 적는다. 늘리려면 이유가 있어야 한다.
        $allowed = [
            // 언어 전환 버튼 자체가 「한국어」다.
            'nav.php' => ['lang.ko'],
            // 안내문이 그 버튼 이름을 그대로 인용한다.
            'feature_settings.php' => ['locale_enabled_flash'],
            // 역할 키(관리·영업·수출통관·재무)는 **저장값**이라 안내문에 원문으로 나온다 — 옮기면 못 고른다.
            'alimtalk_catalog.php' => ['rule_to_ph', 'rule_help', 'rule_bad_target'],
            'vehicle.php' => [
                // 서류 양식의 실제 시트·칸 이름 — 옮기면 사람이 그 칸을 못 찾는다.
                'field.registration_number_hint', 'field.reg_cert_number_hint', 'field.deregistration_date_hint',
                // 붙여넣기 예시의 **한국 차량번호** — 실제 데이터 모양이라 그대로 둔다.
                'cost_import.paste_ph',
            ],
        ];

        $left = [];
        foreach (glob(lang_path('en/*.php')) as $enPath) {
            $name = basename($enPath);
            if (in_array($name, self::SKIP, true)) {
                continue;
            }
            foreach ($this->flatten(require $enPath) as $key => $value) {
                if (! is_string($value) || ! preg_match('/[가-힣]/u', $value)) {
                    continue;
                }
                if (in_array($key, $allowed[$name] ?? [], true)) {
                    continue;
                }
                $left[] = "{$name}: {$key} = ".mb_substr($value, 0, 40);
            }
        }

        $this->assertSame([], $left, sprintf(
            "영어 값에 한글이 남았다 %d개 — 번역하거나, 남겨야 할 이유가 있으면 이 테스트의 \$allowed 에 적을 것.\n%s",
            count($left),
            implode("\n", array_slice($left, 0, 20)),
        ));
    }

    /** 운항 pill — jin 이 제보한 그 자리. 라벨이 상수(한글)가 아니라 번역을 거쳐야 한다. */
    public function test_sailing_phase_labels_are_translated(): void
    {
        // ⚠️ 전역 locale 을 바꾸므로 **반드시 되돌린다.** 실패로 빠져나가도 되돌아가게 try/finally 다 —
        //    안 그러면 이 테스트가 죽는 날 뒤따르는 테스트들이 영문 화면을 보고 엉뚱하게 깨진다
        //    (MEMORY 「static 캐시는 테스트 클래스 간에 샌다」와 같은 부류).
        $original = app()->getLocale();

        try {
            foreach (['ko', 'en'] as $locale) {
                app()->setLocale($locale);
                foreach ([Vehicle::SAILING_IN_TRANSIT, Vehicle::SAILING_ARRIVED] as $phase) {
                    $label = __('domain.sailing.'.$phase);
                    $this->assertNotSame('domain.sailing.'.$phase, $label, "[$locale] 운항 라벨이 없다");
                    if ($locale === 'en') {
                        $this->assertDoesNotMatchRegularExpression('/[가-힣]/u', $label, '영어 운항 라벨에 한글이 남았다');
                    }
                }
            }
        } finally {
            app()->setLocale($original);
        }
    }

    /** @return array<string,mixed> */
    private function flatten(array $rows, string $prefix = ''): array
    {
        $out = [];
        foreach ($rows as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($value)) {
                $out += $this->flatten($value, $path);
            } else {
                $out[$path] = $value;
            }
        }

        return $out;
    }
}
