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

namespace PHPRegex\Tests\Unit\Bridge\Symfony\Output;

use PHPRegex\Symfony\Output\ConsoleWriter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The lines the bundle's commands and reports write alike.
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
    public function test_meta_line_pads_the_label(): void
    {
        $this->assertSame('<fg=white;options=bold>Mode    </> : <fg=yellow>full</>', ConsoleWriter::metaLine('Mode', 'full', 8));
    }

    /**
     * A pattern shows as written, its console tags escaped; an empty one as
     * a pair of quotes.
     */
    #[Test]
    public function test_pattern_escapes_console_tags(): void
    {
        $this->assertSame('<fg=cyan>/\\<b\\>a/</>', ConsoleWriter::pattern('/<b>a/'));
        $this->assertSame('<fg=cyan>""</>', ConsoleWriter::pattern(''));
    }

    #[Test]
    public function test_example_quotes_a_subject_or_shows_a_dash(): void
    {
        $this->assertSame('<fg=cyan>"a\\"b\\n"</>', ConsoleWriter::example("a\"b\n"));
        $this->assertSame('-', ConsoleWriter::example(null));
    }

    #[Test]
    public function test_footer_asks_for_a_star_and_leaves_a_blank_line(): void
    {
        $output = new BufferedOutput();

        ConsoleWriter::footer(new SymfonyStyle(new ArrayInput([]), $output));

        $this->assertSame("  If PHPRegex helps, a GitHub star is appreciated: https://github.com/php-regex/php-regex\n\n", $output->fetch());
    }
}
