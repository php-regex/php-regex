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

namespace PHPRegex\Tests\TestUtils;

use PHPRegex\Cli\Output;
use PHPUnit\Framework\Assert;

/**
 * Builds console outputs whose error stream is a discarded in-memory sink,
 * so error writes stay off the test process's STDERR. Callers that need to
 * read the error output back manage their own stream instead.
 */
final class OutputFactory
{
    public static function create(bool $ansi = false, bool $quiet = false): Output
    {
        $errorStream = \fopen('php://memory', 'w+');
        Assert::assertNotFalse($errorStream);

        return new Output($ansi, $quiet, errorStream: $errorStream);
    }

    /**
     * What an output built here wrote to its error stream so far; nothing
     * for an output that writes to the process's STDERR.
     */
    public static function errors(Output $output): string
    {
        $stream = (new \ReflectionProperty(Output::class, 'errorStream'))->getValue($output);
        if (!\is_resource($stream)) {
            return '';
        }
        rewind($stream);

        return (string) stream_get_contents($stream);
    }
}
