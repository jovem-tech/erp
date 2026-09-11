<?php

namespace Tests\Unit\Photos;

use App\Services\Photos\VipsCommandRunner;
use ReflectionMethod;
use Tests\TestCase;

class VipsCommandRunnerTest extends TestCase
{
    public function test_thumbnail_cli_options_support_current_and_legacy_vips_names(): void
    {
        $runner = new VipsCommandRunner;
        $outputOption = new ReflectionMethod(VipsCommandRunner::class, 'thumbnailOutputOptionForHelp');
        $profileOption = new ReflectionMethod(VipsCommandRunner::class, 'thumbnailProfileOptionForHelp');

        $currentHelp = <<<'TXT'
            --path=FORMAT                 output to path FORMAT
            --output-profile=PROFILE      export with PROFILE
            TXT;

        $legacyHelp = <<<'TXT'
            -o, --output=FORMAT           output to FORMAT
            -e, --export-profile=PROFILE  export with PROFILE
            TXT;

        $this->assertSame('--path', $outputOption->invoke($runner, $currentHelp));
        $this->assertSame('--output-profile', $profileOption->invoke($runner, $currentHelp));
        $this->assertSame('--output', $outputOption->invoke($runner, $legacyHelp));
        $this->assertSame('--export-profile', $profileOption->invoke($runner, $legacyHelp));
    }

    public function test_codec_process_environment_does_not_inherit_application_secrets(): void
    {
        $originalSecret = getenv('OPERATIONAL_PHOTO_TEST_SECRET');
        $originalPluginPath = getenv('LIBHEIF_PLUGIN_PATH');

        putenv('OPERATIONAL_PHOTO_TEST_SECRET=must-not-reach-codec');
        putenv('LIBHEIF_PLUGIN_PATH=/tmp/test-codec-plugins');

        try {
            $method = new ReflectionMethod(VipsCommandRunner::class, 'isolatedEnvironment');
            $environment = $method->invoke(new VipsCommandRunner);

            $this->assertFalse($environment['OPERATIONAL_PHOTO_TEST_SECRET']);
            $this->assertSame('/tmp/test-codec-plugins', $environment['LIBHEIF_PLUGIN_PATH']);
            $this->assertSame('1', $environment['VIPS_CONCURRENCY']);
            $this->assertSame('64m', $environment['VIPS_DISC_THRESHOLD']);
        } finally {
            $originalSecret === false
                ? putenv('OPERATIONAL_PHOTO_TEST_SECRET')
                : putenv('OPERATIONAL_PHOTO_TEST_SECRET='.$originalSecret);
            $originalPluginPath === false
                ? putenv('LIBHEIF_PLUGIN_PATH')
                : putenv('LIBHEIF_PLUGIN_PATH='.$originalPluginPath);
        }
    }
}
