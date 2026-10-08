<?php

declare(strict_types=1);

namespace JOetjen\CooperLaravel\Tests\Bootstrap;

use JOetjen\CooperLaravel\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Cooper reads `.env` in place of Laravel, and puts what it read where
 * Laravel and its packages look: `env()`, `$_ENV`, `$_SERVER`,
 * `getenv()`.
 */
#[RunTestsInSeparateProcesses]
final class LoadEnvironmentVariablesTest extends IntegrationTestCase
{
    public function testDotenvValuesReachEnvGetenvAndTheSuperglobals(): void
    {
        $this->project->write('.env', "COOPER_LARAVEL_PROBE=from-dotenv\n");

        $this->project->console();

        self::assertSame('from-dotenv', env('COOPER_LARAVEL_PROBE'));
        self::assertSame('from-dotenv', getenv('COOPER_LARAVEL_PROBE'));
        self::assertSame('from-dotenv', $_ENV['COOPER_LARAVEL_PROBE']);
        self::assertSame('from-dotenv', $_SERVER['COOPER_LARAVEL_PROBE']);
    }

    public function testTheCascDocumentReadsTheSameValues(): void
    {
        $this->project->write('.env', "COOPER_LARAVEL_PROBE=from-dotenv\n");
        $this->project->casc('app { name = ${COOPER_LARAVEL_PROBE} }');

        self::assertSame('from-dotenv', $this->project->console()['config']->get('app.name'));
    }

    public function testTheRealEnvironmentOutranksTheDotenvFile(): void
    {
        $this->project->write('.env', "COOPER_LARAVEL_PROBE=from-dotenv\n");
        self::setEnv('COOPER_LARAVEL_PROBE', 'from-the-environment');

        $this->project->console();

        self::assertSame('from-the-environment', env('COOPER_LARAVEL_PROBE'));
    }

    public function testCooperEnvironmentsDotenvFileIsReadNotLaravels(): void
    {
        $this->project->write('.env', "APP_ENV=testing\nCOOPER_LARAVEL_PROBE=base\n");
        $this->project->write('.env.test', "COOPER_LARAVEL_PROBE=from-dotenv-test\n");
        $this->project->write('.env.testing', "COOPER_LARAVEL_PROBE=from-dotenv-testing\n");

        $this->project->console();

        self::assertSame('from-dotenv-test', env('COOPER_LARAVEL_PROBE'));
        self::assertSame('test', env('COOPER_ENV'));
    }

    public function testDotenvLocalIsReadLast(): void
    {
        $this->project->write('.env', "COOPER_LARAVEL_PROBE=base\n");
        $this->project->write('.env.local', "COOPER_LARAVEL_PROBE=local\n");

        $this->project->console();

        self::assertSame('local', env('COOPER_LARAVEL_PROBE'));
    }

    public function testNoDotenvFileIsReadOnceTheConfigurationIsCached(): void
    {
        $this->project->casc('app.name = "cached"');
        $this->project->artisan('config:cache');
        $this->project->write('.env', "COOPER_LARAVEL_PROBE=from-dotenv\n");

        $app = $this->project->console();

        self::assertSame('cached', $app['config']->get('app.name'));
        self::assertNull(env('COOPER_LARAVEL_PROBE'));
    }

    public function testAMalformedDotenvFileFailsTheBoot(): void
    {
        $this->project->write('.env', "NOT A NAME=1\n");

        $this->expectException(\JOetjen\Cooper\CooperError::class);

        $this->project->console();
    }
}
