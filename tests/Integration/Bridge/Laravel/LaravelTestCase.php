<?php

declare(strict_types=1);

/*
 * This file is part of the PHPRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PHPRegex\Tests\Integration\Bridge\Laravel;

use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\PendingCommand;
use Orchestra\Testbench\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * The application, its config and its commands, typed: Testbench gives
 * the application as nullable, its config through an untyped offset, a
 * command run as a pending command or its exit code, and the registered
 * commands untyped.
 */
abstract class LaravelTestCase extends TestCase
{
    protected function application(): Application
    {
        $app = $this->app;
        self::assertNotNull($app, 'The application is booted.');

        return $app;
    }

    protected static function configOf(Application $app): Repository
    {
        $config = $app['config'];
        self::assertInstanceOf(Repository::class, $config);

        return $config;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    protected function command(string $command, array $parameters = []): PendingCommand
    {
        $pending = $this->artisan($command, $parameters);
        self::assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }

    protected function registeredCommand(string $name): Command
    {
        $command = Artisan::all()[$name] ?? null;
        self::assertInstanceOf(Command::class, $command, $name.' is registered.');

        return $command;
    }
}
