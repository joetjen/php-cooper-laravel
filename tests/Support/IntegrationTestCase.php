<?php

declare(strict_types=1);

namespace JOetjen\CooperLaravel\Tests\Support;

use Illuminate\Container\Container;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

/**
 * A test against a scratch Laravel application (`Project`), booted the
 * way `artisan` and `public/index.php` boot one.
 *
 * Each test class says `#[RunTestsInSeparateProcesses]` -- PHPUnit does
 * not inherit it -- because booting Laravel sets process-wide state: the
 * environment Cooper exports, facades, the container instance, error
 * handlers, which one test must not hand the next.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        // The shell running the suite may name an environment; every
        // test starts from none, and says which one it wants.
        foreach (['APP_ENV', 'COOPER_ENV', 'APP_KEY', 'APP_NAME', 'APP_DEBUG'] as $name) {
            self::unsetEnv($name);
        }
        $this->project = Project::create();
    }

    protected function tearDown(): void
    {
        HandleExceptions::flushState($this);
        Facade::clearResolvedInstances();
        Container::setInstance(null);
        $this->project->remove();
        parent::tearDown();
    }

    /**
     * Sets `$name` in the real process environment, everywhere Laravel
     * and Cooper look.
     */
    protected static function setEnv(string $name, string $value): void
    {
        putenv("{$name}={$value}");
        $_ENV[$name] = $_SERVER[$name] = $value;
    }

    protected static function unsetEnv(string $name): void
    {
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);
    }
}
