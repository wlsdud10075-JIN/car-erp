<?php

namespace Tests\Feature;

use App\Models\Vehicle;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * 📦 차량 서류 업로드 상한 100MB (jin 2026-11-17 «100MB 넘으면 줄여서 올리라고 하는 게 맞다»).
 *
 * 걸리는 곳이 넷이다 — 공용 업로드 칸(고르는 순간) · Livewire 임시 업로드 · 앱 검증 · 서버(nginx·PHP 110M).
 * 앞 셋은 같은 값(Vehicle::DOCUMENT_MAX_KB)을 봐야 한다 — 하나만 낮으면 「이유 없는 실패」가 된다(구 12MB 가 그랬다).
 * 고르는 순간 막는 동작 자체는 브라우저 JS 라 여기선 마크업만 본다(실브라우저 확인 = 2026-11-17 101MB 파일 차단·안내).
 */
class UploadSizeLimitTest extends TestCase
{
    public function test_every_layer_uses_the_same_limit(): void
    {
        $this->assertSame(102400, Vehicle::DOCUMENT_MAX_KB);
        $this->assertContains('max:'.Vehicle::DOCUMENT_MAX_KB, config('livewire.temporary_file_upload.rules'), 'Livewire 임시 업로드가 앱 상한보다 낮으면 그게 먼저 걸린다');

        $html = Blade::render('<x-erp.file-drop model="blDocFile" />');
        $this->assertStringContainsString('x-on:change.capture', $html, '고르는 순간 크기 확인');
        $this->assertStringContainsString('100 * 1024 * 1024', $html);
    }

    public function test_size_error_tells_people_to_shrink_the_pdf(): void
    {
        app()->setLocale('ko');
        $big = UploadedFile::fake()->create('bl.pdf', Vehicle::DOCUMENT_MAX_KB + 1, 'application/pdf');
        $cases = [
            '저장 검증(B/L 칸)' => [['blDocFile' => $big], ['blDocFile' => 'file|max:'.Vehicle::DOCUMENT_MAX_KB]],
            'Livewire 임시 업로드' => [['files' => [$big]], ['files.*' => 'file|max:'.Vehicle::DOCUMENT_MAX_KB]],
        ];
        foreach ($cases as $label => [$data, $rules]) {
            $msg = Validator::make($data, $rules)->errors()->first();
            $this->assertStringContainsString('100MB', $msg, $label);
            $this->assertStringContainsString('압축하거나 나눠서', $msg, $label);
        }
    }
}
