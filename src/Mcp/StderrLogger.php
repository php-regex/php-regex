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

namespace PHPRegex\Mcp;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * The server's log: warnings and worse on stderr, one line each, the
 * channel a client never reads as protocol. Stdout is the protocol, and a
 * byte of log on it would end the session.
 *
 * @internal
 */
final class StderrLogger extends AbstractLogger
{
    private const LEVELS = [
        LogLevel::DEBUG => 0,
        LogLevel::INFO => 1,
        LogLevel::NOTICE => 2,
        LogLevel::WARNING => 3,
        LogLevel::ERROR => 4,
        LogLevel::CRITICAL => 5,
        LogLevel::ALERT => 6,
        LogLevel::EMERGENCY => 7,
    ];

    /**
     * @param resource|null $stream where the lines go; stderr when null
     */
    public function __construct(private $stream = null, private readonly string $lowest = LogLevel::WARNING) {}

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        // PSR-3 leaves the level untyped; a level that is no name is read as
        // the lowest one, never dropped.
        $name = \is_string($level) ? $level : LogLevel::DEBUG;
        if ((self::LEVELS[$name] ?? 0) < (self::LEVELS[$this->lowest] ?? 0)) {
            return;
        }

        $line = \sprintf('[%s] %s', $name, $message);
        $exception = $context['exception'] ?? null;
        if ($exception instanceof \Throwable) {
            $line .= ': '.$exception->getMessage();
        }

        if (null === $this->stream) {
            $stream = \defined('STDERR') ? \STDERR : fopen('php://stderr', 'w');
            if (!\is_resource($stream)) {
                // Not reached by a test: a PHP that opens no standard error.
                return;
            }
            $this->stream = $stream;
        }

        fwrite($this->stream, $line."\n");
    }
}
