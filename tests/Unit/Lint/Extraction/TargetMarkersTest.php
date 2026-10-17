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

use PHPRegex\Linter\Config\TargetConstraint;
use PHPRegex\Linter\Extraction\TargetMarker;
use PHPRegex\Linter\Extraction\TargetMarkers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * "@regex-target" names the PHP and PCRE2 versions the code it covers runs
 * on: its own last line, the line after it for "@regex-target-next-line",
 * the statement or declaration a doc comment opening its line stands
 * before.
 */
final class TargetMarkersTest extends TestCase
{
    #[Test]
    public function test_each_placement_covers_its_lines(): void
    {
        $content = <<<'CODE'
            <?php
            preg_match('/a/', $s); // @regex-target php>=8.4
            // @regex-target-next-line pcre>=10.43
            preg_match('/b/', $s);

            /**
             * Runs on 8.4 only.
             *
             * @regex-target php>=8.4 pcre>=10.43
             */
            #[\Deprecated]
            final class Matcher
            {
                /** @regex-target php^8.5 */
                private const RE = '/c/';

                /**
                 * @regex-target php<8.4
                 */
                public function run(string $s): bool
                {
                    return 1 === preg_match(self::RE, $s);
                }
            }
            $x = 1; /** @regex-target php>=8.3 */
            CODE;

        $this->assertSame([
            ['@regex-target', 2, 2, 2, 'php>=8.4'],
            ['@regex-target-next-line', 3, 4, 4, 'pcre>=10.43'],
            ['@regex-target', 9, 11, 24, 'php>=8.4 pcre>=10.43'],
            ['@regex-target', 14, 15, 15, 'php^8.5'],
            ['@regex-target', 18, 20, 23, 'php<8.4'],
            ['@regex-target', 25, 25, 25, 'php>=8.3'],
        ], array_map(static fn (TargetMarker $marker): array => [$marker->marker, $marker->line, $marker->fromLine, $marker->toLine, $marker->text], TargetMarkers::read($content)));
    }

    /**
     * @return iterable<string, array{text: string, php: list<array{int, int|null}>|null, pcre: list<array{int, int|null}>|null}>
     */
    public static function provideConstraints(): iterable
    {
        yield 'a floor' => ['text' => 'php>=8.4', 'php' => [[80400, null]], 'pcre' => null];
        yield 'a ceiling' => ['text' => 'php<8.4', 'php' => [[0, 80400]], 'pcre' => null];
        yield 'both bounds' => ['text' => 'php>=8.2 <8.4', 'php' => [[80200, 80400]], 'pcre' => null];
        yield 'a caret' => ['text' => 'php ^8.4', 'php' => [[80400, 90000]], 'pcre' => null];
        yield 'an or' => ['text' => 'php~8.2.0 || >=8.4', 'php' => [[80200, 80300], [80400, null]], 'pcre' => null];
        yield 'a release' => ['text' => 'pcre>=10.43', 'php' => null, 'pcre' => [[104300, null]]];
        yield 'both' => ['text' => 'php>=8.4 pcre>=10.43', 'php' => [[80400, null]], 'pcre' => [[104300, null]]];
    }

    /**
     * @param list<array{int, int|null}>|null $php
     * @param list<array{int, int|null}>|null $pcre
     */
    #[Test]
    #[DataProvider('provideConstraints')]
    public function test_the_constraint_is_read_with_the_composer_interval_parser(string $text, ?array $php, ?array $pcre): void
    {
        $markers = TargetMarkers::read("<?php\npreg_match('/a/', \$s); // @regex-target ".$text."\n");

        $this->assertCount(1, $markers);
        $this->assertNull($markers[0]->error);
        $this->assertInstanceOf(TargetConstraint::class, $markers[0]->constraint);
        $this->assertSame($php, $markers[0]->constraint->php);
        $this->assertSame($pcre, $markers[0]->constraint->pcre);
    }

    /**
     * @return iterable<string, array{text: string}>
     */
    public static function provideMalformedConstraints(): iterable
    {
        yield 'nothing' => ['text' => ''];
        yield 'no keyword' => ['text' => '8.4'];
        yield 'a keyword alone' => ['text' => 'php'];
        yield 'no version' => ['text' => 'php>=eight'];
        yield 'a note after it' => ['text' => 'php>=8.4 because of (?a)'];
        yield 'pcre before php' => ['text' => 'pcre>=10.43 php>=8.4'];
        yield 'twice' => ['text' => 'php>=8.4 php<8.6'];
    }

    /**
     * A constraint that cannot be read is never a full-range judgement in
     * silence: the marker carries an error, and no constraint.
     */
    #[Test]
    #[DataProvider('provideMalformedConstraints')]
    public function test_a_malformed_constraint_is_an_error_naming_the_marker(string $text): void
    {
        $markers = TargetMarkers::read("<?php\npreg_match('/a/', \$s); // @regex-target ".$text."\n");

        $this->assertCount(1, $markers);
        $this->assertNull($markers[0]->constraint);
        $this->assertSame(\sprintf('The @regex-target marker holds "%s", which names no PHP or PCRE2 version constraint: write "@regex-target php>=8.4", "@regex-target pcre>=10.43" or "@regex-target php>=8.4 pcre>=10.43".', trim($text)), $markers[0]->error);
    }

    /**
     * Prose about the marker, quoted or in the middle of a sentence, is no
     * marker, as for "@regex-ignore".
     */
    #[Test]
    public function test_a_marker_starts_its_comment_or_a_line_of_it(): void
    {
        $content = "<?php\npreg_match('/a/', \$s); // \"@regex-target php>=8.4\" narrows\npreg_match('/b/', \$s); // see @regex-target php>=8.4\n";

        $this->assertSame([], TargetMarkers::read($content));
    }

    /**
     * A doc comment glued to the code before it covers its own line; one
     * that ends a block covers the closing brace alone; one before a
     * statement the file leaves open covers it to the end of the file.
     */
    #[Test]
    public function test_a_doc_comment_away_from_a_statement_covers_what_it_can(): void
    {
        $content = "<?php\n\$x = 1;/** @regex-target php>=8.3 */\nfunction f(): void\n{\n    preg_match('/a/', \$s);\n    /** @regex-target php>=8.4 */\n}\n/** @regex-target php>=8.5 */\npreg_match('/b/', \$s)\n";

        $this->assertSame([[2, 2, 2], [6, 7, 7], [8, 9, 9]], array_map(static fn (TargetMarker $marker): array => [$marker->line, $marker->fromLine, $marker->toLine], TargetMarkers::read($content)));
    }

    /**
     * A source too large to tokenize under memory_limit keeps no marker,
     * rather than stopping the process, as for the ignore markers.
     */
    #[Test]
    public function test_a_source_over_the_memory_budget_keeps_no_marker(): void
    {
        $content = "<?php\npreg_match('/a/', \$s); // @regex-target php>=8.4\n".str_repeat("// padding\n", 100_000);
        $limit = ini_get('memory_limit');

        try {
            ini_set('memory_limit', '-1');
            $this->assertCount(1, TargetMarkers::read($content), 'Under no limit, the marker counts.');

            ini_set('memory_limit', (string) (memory_get_usage(true) + 8 * 1024 * 1024));
            $this->assertSame([], TargetMarkers::read($content));
        } finally {
            ini_set('memory_limit', (string) $limit);
        }
    }

    /**
     * A doc comment holds as many markers as it has lines; the open tag's
     * line counts as a line the comment opens; prose after the marker's
     * line is no part of its constraint; the text of a string is no marker.
     */
    #[Test]
    public function test_every_marker_of_a_doc_comment_and_none_of_a_string(): void
    {
        $content = "<?php\n/**\n * @regex-target php>=8.3\n * @regex-target-next-line php<8.5\n * Runs on 8.3 and 8.4.\n */\nfunction f(): void\n{\n}\n\$s = <<<'TXT'\n@regex-target php>=8.4\nTXT;\n";

        $this->assertSame(
            [['@regex-target', 3, 7, 9, 'php>=8.3', 4], ['@regex-target-next-line', 4, 7, 7, 'php<8.5', 4]],
            array_map(static fn (TargetMarker $marker): array => [$marker->marker, $marker->line, $marker->fromLine, $marker->toLine, $marker->text, $marker->column], TargetMarkers::read($content)),
        );
        $this->assertSame('>=8.3 <8.5', TargetMarker::constraintAt(TargetMarkers::read($content), 7)?->phpConstraint());
        $this->assertSame('>=8.3', TargetMarker::constraintAt(TargetMarkers::read($content), 8)?->phpConstraint());
        $this->assertNull(TargetMarker::constraintAt(TargetMarkers::read($content), 10));
    }

    /**
     * A doc comment right after the open tag covers the declaration after
     * it; a statement goes on through its "else" and its "catch"; one the
     * file leaves open runs to its last token.
     */
    #[Test]
    public function test_a_statement_runs_through_its_branches(): void
    {
        $content = "<?php /** @regex-target php>=8.4 */\nif (\$x) {\n    f();\n} else {\n    g();\n}\n/** @regex-target php>=8.4 */\ntry {\n    f();\n} catch (\\Exception \$e) {\n}\n/** @regex-target php>=8.4 */\npreg_match(\n    '/a/', \$s)\n";

        $this->assertSame(
            [[1, 2, 6, 11], [7, 8, 11, 5], [12, 13, 14, 5]],
            array_map(static fn (TargetMarker $marker): array => [$marker->line, $marker->fromLine, $marker->toLine, $marker->column], TargetMarkers::read($content)),
        );
    }

    #[Test]
    public function test_a_source_without_a_marker_is_not_tokenized(): void
    {
        $this->assertSame([], TargetMarkers::read("<?php\n// @regex-ignore\n"));
        $this->assertSame([], TargetMarkers::read(''));
    }
}
