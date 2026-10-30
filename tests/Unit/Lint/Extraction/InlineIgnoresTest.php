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
use PHPUnit\Framework\Attributes\DataProvider;
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
     * Each line of a doc comment may hold a marker; a marker on the line of
     * the open tag is at its column there; the text of a string is never a
     * marker, whatever line it starts.
     */
    #[Test]
    public function test_every_marker_of_a_comment_and_none_of_a_string(): void
    {
        $content = "<?php // @regex-ignore\n/**\n * @regex-ignore regex.lint.a\n * @regex-ignore-next-line\n */\n\$s = <<<'TXT'\n@regex-ignore\nTXT;\n";

        $this->assertSame(
            [['@regex-ignore', 1, 10], ['@regex-ignore', 3, 4], ['@regex-ignore-next-line', 4, 4]],
            array_map(static fn (IgnoreMarker $marker): array => [$marker->marker, $marker->line, $marker->column], InlineIgnores::markers($content)),
        );
    }

    /**
     * An identifier named twice is reported once.
     */
    #[Test]
    public function test_an_identifier_named_twice_is_reported_once(): void
    {
        $markers = InlineIgnores::markers("<?php\n\$x = 1; // @regex-ignore regex.lint.a, regex.lint.a\n");

        $this->assertCount(1, $markers);
        $this->assertSame(['The @regex-ignore marker names regex.lint.a, which no issue reports on line 2. Remove the identifier.'], $markers[0]->unmatched([]));
        $this->assertSame([], $markers[0]->unmatched(['regex.lint.a' => true]));
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
     * A marker reads an issue of the lint as the configuration names it,
     * without "regex.lint.", as the issue in full: it silences that issue
     * only, and is reported when no such issue is on its line. A word the
     * lint knows no issue by is a note, as before.
     *
     * @param list<string> $identifiers
     */
    #[Test]
    #[DataProvider('provideShortIdentifiers')]
    public function test_a_marker_reads_a_short_identifier_as_the_issue_in_full(string $comment, array $identifiers): void
    {
        $markers = InlineIgnores::markers("<?php\npreg_match('/a{1}/', \$s); ".$comment."\n");

        $this->assertCount(1, $markers);
        $this->assertSame($identifiers, $markers[0]->identifiers);
        $this->assertSame([] === $identifiers ? [2 => true] : [], InlineIgnores::suppressedLines("<?php\npreg_match('/a{1}/', \$s); ".$comment."\n"));
    }

    /**
     * @return iterable<string, array{comment: string, identifiers: list<string>}>
     */
    public static function provideShortIdentifiers(): iterable
    {
        yield 'a lint rule' => ['comment' => '// @regex-ignore quantifier.useless', 'identifiers' => ['regex.lint.quantifier.useless']];
        yield 'a lint rule off by default' => ['comment' => '// @regex-ignore charclass.single', 'identifiers' => ['regex.lint.charclass.single']];
        yield 'an analysis issue' => ['comment' => '// @regex-ignore redos', 'identifiers' => ['regex.lint.redos']];
        yield 'an analysis issue of two words' => ['comment' => '// @regex-ignore redos.search', 'identifiers' => ['regex.lint.redos.search']];
        yield 'both spellings in a list' => ['comment' => '// @regex-ignore quantifier.useless, regex.lint.redos', 'identifiers' => ['regex.lint.quantifier.useless', 'regex.lint.redos']];
        yield 'a short identifier then a note' => ['comment' => '// @regex-ignore flag.useless.s legacy input', 'identifiers' => ['regex.lint.flag.useless.s']];
        yield 'a note after a full identifier' => ['comment' => '// @regex-ignore regex.lint.redos quantifier.useless', 'identifiers' => ['regex.lint.redos']];
        yield 'a note that starts with a word' => ['comment' => '// @regex-ignore legacy input', 'identifiers' => []];
        yield 'a short identifier inside a note' => ['comment' => '// @regex-ignore legacy quantifier.useless', 'identifiers' => []];
        yield 'a misspelt rule is a note' => ['comment' => '// @regex-ignore quantifier.uselss', 'identifiers' => []];
        yield 'a rule glued to a word' => ['comment' => '// @regex-ignore redos-prone, see #12', 'identifiers' => []];
        yield 'another case is a note' => ['comment' => '// @regex-ignore Quantifier.Useless', 'identifiers' => []];
        yield 'a known short identifier after an unknown one' => ['comment' => '// @regex-ignore regex.lint.a, quantifier.useless', 'identifiers' => ['regex.lint.a', 'regex.lint.quantifier.useless']];
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

    /**
     * One comment, given with its opener, is read on its own: a Twig
     * template hands its "{# #}" comments over this way.
     */
    #[Test]
    public function test_the_markers_of_one_comment_are_read_from_its_opener(): void
    {
        $content = "<p>\n{# @regex-ignore-next-line redos, quantifier.nested a note #}\n";
        $markers = InlineIgnores::markersOfComment('# @regex-ignore-next-line redos, quantifier.nested a note ', 2, 5, $content);

        $this->assertCount(1, $markers);
        $this->assertSame('@regex-ignore-next-line', $markers[0]->marker);
        $this->assertSame(2, $markers[0]->line);
        $this->assertSame(3, $markers[0]->coveredLine);
        $this->assertSame(['regex.lint.redos', 'regex.lint.quantifier.nested'], $markers[0]->identifiers);
        $this->assertSame(7, $markers[0]->offset);
        $this->assertSame(4, $markers[0]->column);
        $this->assertSame([], InlineIgnores::markersOfComment('# see @regex-ignore', 1, 0, '# see @regex-ignore'));
    }
}
