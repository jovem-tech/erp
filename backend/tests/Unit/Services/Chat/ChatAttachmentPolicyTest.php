<?php

namespace Tests\Unit\Services\Chat;

use App\Services\Chat\ChatAttachmentPolicy;
use Tests\TestCase;

class ChatAttachmentPolicyTest extends TestCase
{
    public function test_safe_download_name_removes_header_injection_and_preserves_unicode(): void
    {
        $policy = app(ChatAttachmentPolicy::class);
        $unsafe = '../../Relatório "cliente"'."\r\nX-Evil: yes.pdf";

        $safe = $policy->safeDownloadName($unsafe, 'pdf');

        $this->assertStringContainsString('Relatório', $safe);
        $this->assertStringNotContainsString('"', $safe);
        $this->assertStringNotContainsString("\r", $safe);
        $this->assertStringNotContainsString("\n", $safe);
        $this->assertStringNotContainsString('/', $safe);
        $this->assertLessThanOrEqual(180, mb_strlen($safe));
    }

    public function test_safe_download_name_limits_long_unicode_names_and_keeps_extension(): void
    {
        $policy = app(ChatAttachmentPolicy::class);

        $safe = $policy->safeDownloadName(str_repeat('á', 240).'.pdf', 'pdf');

        $this->assertLessThanOrEqual(180, mb_strlen($safe));
        $this->assertStringEndsWith('.pdf', $safe);
    }
}
