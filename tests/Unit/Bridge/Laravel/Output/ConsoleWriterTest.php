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

namespace PHPRegex\Tests\Unit\Bridge\Laravel\Output;

use PHPRegex\Laravel\Output\ConsoleWriter;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The patterns the package's commands write alike.
 */
final class ConsoleWriterTest extends TestCase
{
    /**
     * Highlighting is for a decorated output only: piped or under
     * --no-ansi, the pattern is plain text, its console tags escaped.
     */
    #[Test]
    public function test_pattern_is_highlighted_on_a_decorated_output_only(): void
    {
        $regex = Regex::create();

        $this->assertSame('<fg=yellow>/\\<b\\>a/</>', ConsoleWriter::pattern($regex, new BufferedOutput(decorated: false), '/<b>a/'));
        $this->assertSame($regex->highlight('/<b>a/', 'console'), ConsoleWriter::pattern($regex, new BufferedOutput(decorated: true), '/<b>a/'));
    }
}
