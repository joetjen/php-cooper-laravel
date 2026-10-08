<?php

declare(strict_types=1);

namespace JOetjen\CooperLaravel\Tests\Command;

use JOetjen\CooperLaravel\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * php-cooper-config's `cooper:init` and `cooper:check` on artisan,
 * rooted at the application and checking the document the way it is
 * loaded.
 */
#[RunTestsInSeparateProcesses]
final class CooperConfigCommandsTest extends IntegrationTestCase
{
    public function testInitScaffoldsTheDocumentInTheApplication(): void
    {
        [$status] = $this->project->artisan('cooper:init');

        self::assertSame(0, $status);
        self::assertTrue($this->project->has('config/config.casc'));
        self::assertTrue($this->project->has('config/prod/app.casc'));
    }

    public function testCheckLoadsTheDocumentWithThisIntegrationsTags(): void
    {
        $this->project->casc("app {\n  log = !storage_path(\"logs\")\n  name = !php(\"\"\"'x'\"\"\")\n}");

        [$status, $output] = $this->project->artisan('cooper:check');

        self::assertSame(0, $status, $output);
        self::assertStringContainsString("loaded {$this->project->dir}/config/config.casc", $output);
        self::assertStringContainsString('top-level keys: app', $output);
    }

    public function testCheckFailsOnAPhpTagThatIsNotLiteral(): void
    {
        self::setEnv('COOPER_LARAVEL_CODE', '1');
        // A cached configuration lets the application boot to run the check.
        $this->project->artisan('config:cache');
        $this->project->casc('app.name = !php(${COOPER_LARAVEL_CODE})');

        [$status, $output] = $this->project->artisan('cooper:check');

        self::assertSame(1, $status);
        self::assertStringContainsString('takes only a string literal', $output);
    }
}
