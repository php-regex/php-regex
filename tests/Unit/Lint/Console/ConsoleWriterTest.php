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
use PHPRegex\Tests\Support\Newlines;
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

        $this->assertSame("{\"pattern\":\"/<b>a/\"}\n", Newlines::lf($output->fetch()));
    }

    #[Test]
    public function test_footer_asks_for_a_star_and_leaves_a_blank_line(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);

        ConsoleWriter::footer($output);

        $this->assertSame("  If PHPRegex helps, a GitHub star is appreciated: https://github.com/php-regex/php-regex\n\n", Newlines::lf($output->fetch()));
    }

    #[Test]
    public function test_footer_is_for_verbose_runs_only(): void
    {
        $output = new BufferedOutput();

        ConsoleWriter::footer($output);

        $this->assertSame('', Newlines::lf($output->fetch()));
    }

    #[Test]
    public function test_title_names_the_author_under_verbose_only(): void
    {
        $plain = new BufferedOutput();
        $verbose = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);

        ConsoleWriter::title($plain, '9.9.9');
        ConsoleWriter::title($verbose, '9.9.9');

        $this->assertSame("PHPRegex 9.9.9\n", Newlines::lf($plain->fetch()));
        $this->assertSame("PHPRegex 9.9.9 by Younes ENNAJI\n", Newlines::lf($verbose->fetch()));
    }

    #[Test]
    public function test_title_colors_the_name_and_the_version(): void
    {
        $output = new BufferedOutput(decorated: true);

        ConsoleWriter::title($output, '9.9.9');

        $this->assertSame("\e[36;1mPHPRegex\e[39;22m \e[33m9.9.9\e[39m\n", Newlines::lf($output->fetch()));
    }
}
