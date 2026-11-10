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

use PhpParser\ParserFactory;
use PHPRegex\Linter\Extraction\ExtractorInterface;
use PHPRegex\Linter\Extraction\PatternFunctionRegistry;
use PHPRegex\Linter\Extraction\PhpParserExtractionStrategy;
use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Linter\Parallel\ForkedWorkerPool;
use PHPRegex\Linter\PatternExtractor;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Tests\Support\ExtractsWithBothStrategies;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Where the patterns an argument composes are reported, and in which
 * order. A pattern composed at the call, from a variable or an
 * interpolated string, stands where the argument starts, at each argument
 * composing it, an arm of a ternary or of a match composed there
 * included; held in a variable, it is reported once, where it is
 * composed. Only a pattern that is one literal stands where the literal
 * is written. The patterns composed at one place come ordered by their
 * bytes, so a report lists them the same way on every run.
 */
final class CandidatePlaceTest extends TestCase
{
    use ExtractsWithBothStrategies;

    /**
     * One assignment gives the values, the arms of a match, in an order
     * that is not that of their bytes, and one composition gives the
     * patterns PHP 8.4 sorts as
     * "/^B$/", "/^a$/", "/^a$/i", "/^ab$/", "/^b$/", "/^é$/u" (sort() with
     * SORT_STRING, and strcmp(): an upper case letter before the lower
     * case ones, a shorter pattern before the one it starts, a multi-byte
     * letter last).
     */
    private const UNORDERED = <<<'PHP'
        <?php

        function run(string $s, int $kind): int
        {
            $body = match ($kind) {
                1 => 'é$/u',
                2 => 'a$/i',
                3 => 'ab$/',
                4 => 'B$/',
                5 => 'a$/',
                default => 'b$/',
            };

            return preg_match('/^' . $body, $s);
        }
        PHP;

    private const ORDERED = ['/^B$/', '/^a$/', '/^a$/i', '/^ab$/', '/^b$/', '/^é$/u'];

    /**
     * @return iterable<string, array{strategy: class-string<ExtractorInterface>, workers: int}>
     */
    public static function provideStrategiesAndWorkers(): iterable
    {
        foreach ([1, 3] as $workers) {
            yield 'tokens, '.$workers.' worker(s)' => ['strategy' => TokenBasedExtractionStrategy::class, 'workers' => $workers];
            if (class_exists(ParserFactory::class)) {
                yield 'php-parser, '.$workers.' worker(s)' => ['strategy' => PhpParserExtractionStrategy::class, 'workers' => $workers];
            }
        }
    }

    /**
     * What a lint run reads of the file: the six patterns, all at the
     * argument, in the order of their bytes, whatever strategy reads the
     * file and however many workers share the files.
     *
     * @param class-string<ExtractorInterface> $strategy
     */
    #[Test]
    #[DataProvider('provideStrategiesAndWorkers')]
    public function test_the_patterns_composed_at_one_place_come_in_byte_order(string $strategy, int $workers): void
    {
        if ($workers > 1 && !ForkedWorkerPool::canFork()) {
            $workers = 1;
        }

        $ordered = self::ORDERED;
        $sorted = $ordered;
        sort($sorted, \SORT_STRING);
        $this->assertSame($sorted, $ordered, 'Oracle: the expected order is the byte order.');

        $project = $this->makeProject([
            'src/a_unordered.php' => self::UNORDERED,
            'src/b_other.php' => "<?php\n\npreg_match('/other-file/', \$s);\n",
            'src/c_other.php' => "<?php\n\npreg_match('/third-file/', \$s);\n",
        ]);

        $read = (new PatternExtractor(new $strategy([], PatternFunctionRegistry::native())))->extract([$project.'/src'], [], null, $workers);

        $argument = strpos(self::UNORDERED, "'/^' . \$body");
        $this->assertIsInt($argument);

        $composed = array_values(array_filter($read, static fn (PatternOccurrence $occurrence): bool => str_ends_with($occurrence->file, 'a_unordered.php')));
        $this->assertSame($ordered, array_map(static fn (PatternOccurrence $occurrence): string => $occurrence->pattern, $composed));
        $this->assertSame([$argument], array_values(array_unique(array_map(static fn (PatternOccurrence $occurrence): ?int => $occurrence->fileOffset, $composed))));
    }

    /**
     * Both strategies give the six patterns at the argument, and the
     * argument is one of several patterns.
     */
    #[Test]
    public function test_the_patterns_composed_at_one_place_stand_at_the_argument(): void
    {
        $expected = [];
        foreach (self::ORDERED as $pattern) {
            $expected[] = ['pattern' => $pattern, 'at' => "'/^' . \$body", 'source' => 'preg_match()', 'location' => null];
        }

        $this->assertExtractedByBoth($expected, self::UNORDERED);
        $this->assertArgumentsReadByBoth([
            ['state' => 'finite_set', 'expression' => "'/^' . \$body", 'source' => 'preg_match()'],
        ], self::UNORDERED);
    }

    /**
     * Two calls compose the same pattern, each in its own argument: it
     * is reported at each. A variable holding a composed pattern, read by
     * two calls, is reported once, where it is composed.
     *
     * PHP 8.4: $part = 'abc'; '/' . $part . '/' is "/abc/", "#$part#" is
     * "#abc#".
     */
    #[Test]
    public function test_a_composed_pattern_is_reported_at_each_argument_composing_it_and_once_through_a_name(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s, string $t): int
            {
                $part = 'abc';
                $held = "#$part#";

                return preg_match('/' . $part . '/', $s)
                    + preg_match('/' . $part . '/', $t)
                    + preg_match($held, $s)
                    + preg_match($held, $t);
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '#abc#', 'at' => '"#$part#"', 'location' => 'via $held'],
            ['pattern' => '/abc/', 'at' => "'/' . \$part . '/', \$s)", 'location' => null],
            ['pattern' => '/abc/', 'at' => "'/' . \$part . '/', \$t)", 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => "'/' . \$part . '/'", 'at' => "'/' . \$part . '/', \$s)"],
            ['state' => 'constant', 'expression' => "'/' . \$part . '/'", 'at' => "'/' . \$part . '/', \$t)"],
            ['state' => 'constant', 'expression' => '$held', 'at' => '$held, $s)'],
            ['state' => 'constant', 'expression' => '$held', 'at' => '$held, $t)'],
        ], $code);
    }

    /**
     * One argument holds both kinds: an arm that is one literal is
     * reported where the literal is written; an arm composed of a
     * variable is composed in the argument, and reported where the
     * argument starts, not where the arm does.
     *
     * PHP 8.4: $part = 'abc'; true ? '/literal/' : '/^' . $part . '$/' is
     * "/literal/", and with false "/^abc$/"; "/^$part+/" is "/^abc+/".
     */
    #[Test]
    public function test_a_literal_arm_stands_at_its_literal_and_a_composed_arm_at_the_argument(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s, bool $flag, int $kind): int
            {
                $part = 'abc';

                return preg_match($flag ? '/literal/' : '/^' . $part . '$/', $s)
                    + preg_match(match ($kind) {
                        1 => '/one/',
                        2 => "/^$part+/",
                        default => sprintf('/^%s{%d}$/', $part, 2),
                    }, $s);
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/literal/', 'at' => "'/literal/'", 'source' => 'preg_match()', 'location' => null],
            ['pattern' => '/^abc$/', 'at' => "\$flag ? '/literal/'", 'source' => 'preg_match()', 'location' => null],
            ['pattern' => '/one/', 'at' => "'/one/'", 'source' => 'preg_match()', 'location' => null],
            ['pattern' => '/^abc+/', 'at' => 'match ($kind) {', 'source' => 'preg_match()', 'location' => null],
            ['pattern' => '/^abc{2}$/', 'at' => 'match ($kind) {', 'source' => 'preg_match()', 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'finite_set', 'expression' => "\$flag ? '/literal/' : '/^' . \$part . '$/'"],
            ['state' => 'finite_set', 'expression' => "match (\$kind) {\n            1 => '/one/',\n            2 => \"/^\$part+/\",\n            default => sprintf('/^%s{%d}$/', \$part, 2),\n        }"],
        ], $code);
    }
}
