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

namespace PHPRegex\Tests\Unit\Lint\Console;

use PHPRegex\Linter\Console\ConsoleWriter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The lines the bridges' commands write alike.
 */
final class ConsoleWriterTest extends TestCase
{
    /**
     * A JSON document is the output asked for: written under --quiet, its
     * text never read for console tags.
     */
    #[Test]
    public function test_document_is_written_raw_even_under_quiet(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_QUIET);

        ConsoleWriter::document($output, "{\"pattern\":\"/<b>a/\"}\n");

        $this->assertSame("{\"pattern\":\"/<b>a/\"}\n", $output->fetch());
    }

    #[Test]
    public function test_footer_asks_for_a_star_and_leaves_a_blank_line(): void
    {
        $output = new BufferedOutput();

        ConsoleWriter::footer($output);

        $this->assertSame("  If PHPRegex helps, a GitHub star is appreciated: https://github.com/php-regex/php-regex\n\n", $output->fetch());
    }
}
