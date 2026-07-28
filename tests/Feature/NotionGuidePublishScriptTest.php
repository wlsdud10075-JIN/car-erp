<?php

namespace Tests\Feature;

use Tests\TestCase;

class NotionGuidePublishScriptTest extends TestCase
{
    public function test_업무_가이드_재발행은_이미_보관된_블록을_다시_삭제하지_않는다(): void
    {
        $script = file_get_contents(base_path('scripts/notion-guide-publish.php'));

        $this->assertStringContainsString(
            "if ((\$blk['archived'] ?? false) || (\$blk['in_trash'] ?? false))",
            $script
        );
    }
}
