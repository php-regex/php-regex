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

namespace PHPRegex\Tests\Unit\Lint\Extraction;

use PHPRegex\Linter\Extraction\IgnoreMarker;
use PHPRegex\Linter\Extraction\InlineIgnores;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The lines a comment silences: "@regex-ignore" its own, the last one of
 * the comment; "@regex-ignore-next-line" the one after it.
 */
final class InlineIgnoresTest extends TestCase
{
    private string|false $memoryLimit = false;

    protected function tearDown(): void
    {
        if (false !== $this->memoryLimit) {
            ini_set('memory_limit', $this->memoryLimit);
        }
    }

    /**
     * Only a comment holds a marker: the same words in a string, and the
     * comments around without them, silence nothing.
     */
    #[Test]
    public function test_only_a_comment_holding_a_marker_silences_a_line(): void
    {
        $content = <<<'CODE'
            <?php

            // A note with no marker.
            $help = 'Write @regex-ignore after a call to silence it.';
            preg_match('/same-line/', $s); // @regex-ignore
            /*
             * Silences the line after the comment.
             * @regex-ignore-next-line
             */
            preg_match('/next-line/', $s);
            preg_match('/not-silenced/', $s); # an unrelated comment
            CODE;

        $this->assertSame([5 => true, 10 => true], InlineIgnores::suppressedLines($content));
    }

    /**
     * A marker may name the issues it silences, comma-separated, right after
     * it; anything else after it is a note. Each marker keeps the line it is
     * written on, the line it covers and where it starts in the source.
     */
    #[Test]
    public function test_markers_read_their_identifiers_and_lines(): void
    {
        $content = <<<'CODE'
            <?php
            preg_match('/a/', $s); // @regex-ignore regex.lint.flag.useless.i, regex.lint.quantifier.nested
            /*
             * @regex-ignore-next-line regex.lint.redos legacy input, see #12
             */
            preg_match('/b/', $s);
            preg_match('/c/', $s); // @regex-ignore kept for the old parser
            preg_match('/d/', $s); # @regex-ignore regex.lint.a,regex.lint.b , not.an.identifier
            CODE;

        $markers = array_map(static fn (IgnoreMarker $marker): array => [$marker->marker, $marker->line, $marker->coveredLine, $marker->identifiers, substr($content, $marker->offset, \strlen($marker->marker))], InlineIgnores::markers($content));

        $this->assertSame([
            ['@regex-ignore', 2, 2, ['regex.lint.flag.useless.i', 'regex.lint.quantifier.nested'], '@regex-ignore'],
            ['@regex-ignore-next-line', 4, 6, ['regex.lint.redos'], '@regex-ignore-next-line'],
            ['@regex-ignore', 7, 7, [], '@regex-ignore'],
            ['@regex-ignore', 8, 8, ['regex.lint.a', 'regex.lint.b'], '@regex-ignore'],
        ], $markers);
    }

    /**
     * A marker starts its comment, or a line of a block comment: one quoted
     * or met in the middle of a sentence is prose about the markers, and
     * neither silences nor is reported.
     */
    #[Test]
    public function test_a_marker_starts_its_comment_or_a_line_of_it(): void
    {
        $content = <<<'CODE'
            <?php
            preg_match('/a/', $s); // "@regex-ignore" silences the line it ends
            preg_match('/b/', $s); // a note, then @regex-ignore
            /**
             * Write @regex-ignore-next-line above a call.
             *
             * @regex-ignore-next-line
             */
            preg_match('/c/', $s);
            preg_match('/d/', $s); /* @regex-ignore */
            preg_match('/e/', $s); #@regex-ignore
            preg_match('/f/', $s); // @regex-ignored is no marker
            CODE;

        $this->assertSame([9 => true, 10 => true, 11 => true], InlineIgnores::suppressedLines($content));
        $this->assertSame([7, 10, 11], array_map(static fn (IgnoreMarker $marker): int => $marker->line, InlineIgnores::markers($content)));
    }

    /**
     * Only a marker naming no identifier silences a whole line: one that
     * names some leaves the other issues of its line reported.
     */
    #[Test]
    public function test_only_a_bare_marker_silences_a_whole_line(): void
    {
        $content = "<?php\npreg_match('/a/', \$s); // @regex-ignore regex.lint.redos\npreg_match('/b/', \$s); // @regex-ignore\n";

        $this->assertSame([3 => true], InlineIgnores::suppressedLines($content));
        $this->assertCount(2, InlineIgnores::markers($content));
    }

    /**
     * A source too large to tokenize under memory_limit keeps no marker,
     * rather than stopping the process.
     */
    #[Test]
    public function test_a_source_over_the_memory_budget_silences_nothing(): void
    {
        // A megabyte of source needs some 60 MB of tokens.
        $content = "<?php\npreg_match('/a+/', \$s); // @regex-ignore\n".str_repeat("// padding\n", 100_000);
        $this->memoryLimit = ini_get('memory_limit');
        ini_set('memory_limit', '-1');
        $this->assertSame([2 => true], InlineIgnores::suppressedLines($content), 'Under no limit, the marker counts.');

        ini_set('memory_limit', (string) (memory_get_usage(true) + 8 * 1024 * 1024));

        $this->assertSame([], InlineIgnores::suppressedLines($content));
    }
}
