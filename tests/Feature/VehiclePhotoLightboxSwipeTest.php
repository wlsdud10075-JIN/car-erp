<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 📷 차량 사진 확대 화면 좌우 넘기기 (jin 2026-10-22).
 *
 * 그리기는 브라우저 Alpine 이라 기능 테스트로는 못 잡는다(§8 #98 부류) — 배선을 정적으로 본다.
 * 확대 화면은 클릭한 칸이 속한 사진 묶음(data-lightbox-group)의 미리보기(data-lb-url)를 모아 넘긴다.
 */
class VehiclePhotoLightboxSwipeTest extends TestCase
{
    private function source(): string
    {
        return file_get_contents(resource_path('views/livewire/erp/vehicles/index.blade.php'));
    }

    public function test_every_preview_click_joins_the_gallery(): void
    {
        $src = $this->source();
        $calls = preg_match_all('/@click="openLightbox\(/', $src);
        $this->assertSame(6, $calls, '기본정보 사진 3곳 + 선박 사진 3곳');
        $this->assertSame($calls, substr_count($src, ', $el)"'), '클릭한 칸을 넘겨야 묶음을 찾는다');
        $this->assertSame($calls, substr_count($src, 'data-lb-url="'), '넘길 목록에 들어갈 표식');
        $this->assertSame(2, substr_count($src, 'sm:grid-cols-5" data-lightbox-group>'), '기본정보 · 선박 사진 칸이 각자 묶음');
    }

    public function test_arrows_keys_and_swipe_are_wired(): void
    {
        $src = $this->source();
        $this->assertStringContainsString('@keydown.arrow-left.window="stepLightbox(-1)"', $src);
        $this->assertStringContainsString('@keydown.arrow-right.window="stepLightbox(1)"', $src);
        $this->assertStringContainsString('@touchend="lbTouchEnd($event)"', $src);
        $this->assertSame(2, substr_count($src, 'x-show="lightbox.items.length > 1" @click="stepLightbox('), '좌우 버튼은 2장 이상일 때만');
        $this->assertStringContainsString('if (! this.lightbox.open || n < 2) return;', $src, '닫혀 있으면 화살표 키가 아무것도 안 한다');
    }

    /** 🔍 Ctrl+휠 = 사진만 확대 (jin 2026-11-19 «뒤쪽 ERP 가 확대된다»). */
    public function test_ctrl_wheel_zooms_the_photo_not_the_page(): void
    {
        $src = $this->source();
        $this->assertStringContainsString('@wheel="lbWheel($event)"', $src, '확대 화면이 휠을 받는다');
        $this->assertStringContainsString("if (! e.ctrlKey || ! this.lightbox.open || this.lightbox.kind !== 'image') return;", $src, 'Ctrl 없는 휠·PDF 는 건드리지 않는다');
        $this->assertMatchesRegularExpression('/lbWheel\(e\) \{[^}]*?return;\s*e\.preventDefault\(\);/s', $src, '브라우저 전체 확대를 막는다');
        $this->assertStringContainsString('data-lb-img draggable="false"', $src);
        $this->assertStringContainsString('@mousedown="lbDragStart($event)" @dblclick="lbZoomReset()"', $src, '끌어서 이동 · 더블클릭 원복');
        // 다음 사진·닫기·ESC 는 확대를 푼다
        $this->assertSame(4, substr_count($src, 'this.lbZoomReset();'), '열기 · 닫기 · 넘기기 · 1배로 돌아올 때');
        $this->assertStringContainsString('if (this.lightbox.open) { this.closeLightbox(); return; }', $src, 'ESC 도 닫기와 같은 길');
    }
}
