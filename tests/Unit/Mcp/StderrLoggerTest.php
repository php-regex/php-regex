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

namespace PHPRegex\Tests\Unit\Mcp;

use PHPRegex\Mcp\StderrLogger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

final class StderrLoggerTest extends TestCase
{
    /**
     * PSR-3 leaves the level untyped: a name the logger does not know is read
     * as the lowest level, so it is written when the lowest level is written
     * and dropped when a higher floor is set.
     */
    #[Test]
    public function test_an_unknown_level_is_read_as_the_lowest_one(): void
    {
        $this->assertSame("[whatever] said\n", self::written(LogLevel::DEBUG, 'whatever', 'said'));
        $this->assertSame('', self::written(LogLevel::INFO, 'whatever', 'said'));
        $this->assertSame("[info] said\n", self::written(LogLevel::INFO, LogLevel::INFO, 'said'));
        $this->assertSame('', self::written(LogLevel::WARNING, LogLevel::INFO, 'said'));
        // A floor that is no level name is the lowest floor: everything is written.
        $this->assertSame("[debug] said\n", self::written('bogus', LogLevel::DEBUG, 'said'));
    }

    #[Test]
    public function test_the_exception_of_the_context_is_appended(): void
    {
        $this->assertSame("[error] stopped: broken pipe\n", self::written(LogLevel::WARNING, LogLevel::ERROR, 'stopped', ['exception' => new \RuntimeException('broken pipe')]));
        $this->assertSame("[error] stopped\n", self::written(LogLevel::WARNING, LogLevel::ERROR, 'stopped', ['exception' => 'not a throwable']));
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function written(string $lowest, string $level, string $message, array $context = []): string
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);
        (new StderrLogger($stream, $lowest))->log($level, $message, $context);
        rewind($stream);

        return (string) stream_get_contents($stream);
    }
}
