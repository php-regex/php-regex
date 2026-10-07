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

namespace PHPRegex\Tests\Integration\Bridge\PHPStan;

use PHPRegex\Parser\Analysis\CaptureShapeAnalyzer;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Unit\Parser\CaptureShapeAnalyzerTest;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\Testing\PHPStanTestCase;
use PHPStan\Type\VerbosityLevel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The parity corpus replayed on the engine: for every pattern and every flag
 * set, the type PHPStan reads from matchShape() holds each $matches the
 * engine writes, key by key; so does the type read from matchAllShape() for
 * each preg_match_all() result, and the type read from matchShape() for the
 * array each replace callback receives.
 */
final class CaptureShapeParityTest extends PHPStanTestCase
{
    /**
     * @param non-empty-list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideCorpus')]
    public function test_parity_corpus_every_subject_matches(string $source, string $pattern, array $subjects): void
    {
        self::skipWhenTheEngineCannotRun($pattern, $source);

        foreach ($subjects as $subject) {
            $this->assertSame(1, @preg_match($pattern, $subject), \sprintf('%s (%s) does not match %s: the row is wrong.', $pattern, $source, json_encode($subject)));
        }
    }

    /**
     * @param non-empty-list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideCorpus')]
    public function test_parity_corpus_shape_holds_every_engine_result(string $source, string $pattern, array $subjects): void
    {
        self::skipWhenTheEngineCannotRun($pattern, $source);

        $shape = (new CaptureShapeAnalyzer())->analyze(self::parityParser()->parse($pattern));
        $resolver = self::getContainer()->getByType(TypeStringResolver::class);

        $failures = [];
        foreach (EngineMatches::FLAG_SETS as $flags => $flagNames) {
            $written = $shape->matchShape($flags);
            $type = $resolver->resolve($written);
            // One array shape, or a union of shapes when the pattern splits.
            $this->assertTrue($type->isConstantArray()->yes(), \sprintf('PHPStan reads "%s" as %s.', $written, $type->describe(VerbosityLevel::precise())));

            foreach ($subjects as $subject) {
                $matches = [];
                preg_match($pattern, $subject, $matches, $flags);

                foreach (EngineMatches::refusals($type, $matches) as $refusal) {
                    $failures[] = \sprintf(
                        '%s with %s on %s: the engine writes %s; %s refuses it: %s.',
                        $pattern,
                        $flagNames,
                        json_encode($subject, \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE),
                        json_encode($matches, \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE),
                        $written,
                        $refusal,
                    );
                }
            }
        }

        $this->assertSame([], $failures, $source);
    }

    /**
     * The corpus replayed through preg_match_all(): for every pattern and
     * every order and flag set, the type PHPStan reads from matchAllShape()
     * holds the $matches the engine writes on the concatenation of the
     * row's subjects, on each subject alone, and on a subject the pattern
     * does not match (an empty list under PREG_SET_ORDER, a list per key
     * under PREG_PATTERN_ORDER). Oracle (PHP 8.4.26, PCRE2 10.49):
     *   preg_match_all('/(a)(b)?(c)?/', 'a ab', $m)                 -> [["a","ab"],["a","a"],["","b"],["",""]]
     *   preg_match_all('/(a)(b)?(c)?/', 'x', $m)                    -> [[],[],[],[]]
     *   preg_match_all('/(a)(b)?(c)?/', 'x', $m, PREG_SET_ORDER)    -> []
     *   preg_match_all('/(*MARK:m)(a)|(b)/', 'ba', $m)              -> {..., "MARK":{"1":"m"}}
     *   preg_match_all('/(?J)(?<n>a)(?<n>z)?(c)/', 'acazc', $m)     -> n => ["","z"], the list of group 2
     *   preg_match_all('/(?<MARK>a)|(*MARK:x)b/', 'ab', $m)         -> "MARK" => {"1":"x"}, the marks over the group's list
     *
     * @param non-empty-list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideCorpus')]
    public function test_parity_corpus_match_all_shape_holds_every_engine_result(string $source, string $pattern, array $subjects): void
    {
        self::skipWhenTheEngineCannotRun($pattern, $source);

        $shape = (new CaptureShapeAnalyzer())->analyze(self::parityParser()->parse($pattern));
        $resolver = self::getContainer()->getByType(TypeStringResolver::class);
        $replayed = EngineMatches::matchAllSubjects($pattern, $subjects);

        $failures = [];
        foreach (EngineMatches::MATCH_ALL_FLAG_SETS as $flags => $flagNames) {
            $written = $shape->matchAllShape($flags);
            $type = $resolver->resolve($written);

            foreach ($replayed as $subject) {
                $matches = [];
                $this->assertNotFalse(preg_match_all($pattern, $subject, $matches, $flags), \sprintf('%s on %s with %s fails in the engine.', $pattern, json_encode($subject), $flagNames));

                foreach (EngineMatches::matchAllRefusals($type, $matches, $flags) as $refusal) {
                    $failures[] = \sprintf(
                        '%s with %s on %s: the engine writes %s; %s refuses it: %s.',
                        $pattern,
                        $flagNames,
                        json_encode($subject, \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE),
                        json_encode($matches, \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE),
                        $written,
                        $refusal,
                    );
                }
            }
        }

        $this->assertSame([], $failures, $source);
    }

    /**
     * The callback of preg_replace_callback() receives what preg_match()
     * writes at the same match, trailing unset groups left out unless
     * PREG_UNMATCHED_AS_NULL: the array of each call is held by
     * matchShape($flags & (PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL)),
     * and so is each call of preg_replace_callback_array(). Both ignore every
     * other bit, the low byte included, so the corpus is also replayed with
     * the orders and the low byte set, and a bit above it.
     * Oracle (PHP 8.4.26, PCRE2 10.49):
     *   preg_replace_callback('/(a)(b)?(c)?/', $cb, 'a')                                  -> $cb(["a","a"])
     *   preg_replace_callback('/(a)(b)?(c)?/', $cb, 'a', -1, $c, PREG_UNMATCHED_AS_NULL)  -> $cb(["a","a",null,null])
     *   preg_replace_callback('/(*MARK:m)(a)|(b)/', $cb, 'ab')                            -> $cb({"0":"a","1":"a","MARK":"m"}), $cb(["b","","b"])
     *   preg_replace_callback_array(['/(*MARK:m)(a)|(b)/' => $cb], 'ab', -1, $c, PREG_OFFSET_CAPTURE)
     *     -> $cb({"0":["a",0],"1":["a",0],"MARK":"m"}), $cb([["b",1],["",-1],["b",1]])
     *   preg_replace_callback('/(a)(b)?(c)?/', $cb, 'a ab', -1, $c, PREG_SET_ORDER | 4 | 255 | 1024)
     *     -> $cb(["a","a"]), $cb(["ab","a","b"]), as with 0
     *   preg_replace_callback_array(['/(a)(b)?(c)?/' => $cb], 'a ab', -1, $c, 3 | PREG_OFFSET_CAPTURE)
     *     -> $cb([["a",0],["a",0]]), $cb([["ab",2],["a",2],["b",3]]), as with PREG_OFFSET_CAPTURE
     *
     * @param non-empty-list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideCorpus')]
    public function test_parity_corpus_callback_receives_the_match_shape(string $source, string $pattern, array $subjects): void
    {
        self::skipWhenTheEngineCannotRun($pattern, $source);

        $shape = (new CaptureShapeAnalyzer())->analyze(self::parityParser()->parse($pattern));
        $resolver = self::getContainer()->getByType(TypeStringResolver::class);
        $replayed = array_values(array_unique([implode('', $subjects), ...$subjects]));

        // The flags passed to preg_replace_callback(), then to preg_replace_callback_array().
        $replays = [];
        foreach (EngineMatches::FLAG_SETS as $flags => $flagNames) {
            $replays[$flagNames] = [$flags, $flags];
        }
        $replays['PREG_SET_ORDER | 4 | 255 | 1024, then 3 | PREG_OFFSET_CAPTURE'] = [\PREG_SET_ORDER | 4 | 255 | 1024, 3 | \PREG_OFFSET_CAPTURE];
        $replays['255 | 1024 | PREG_UNMATCHED_AS_NULL, then 3 | 1024 | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL'] = [255 | 1024 | \PREG_UNMATCHED_AS_NULL, 3 | 1024 | \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL];

        $failures = [];
        foreach ($replays as $flagNames => [$flags, $arrayFlags]) {
            $written = [
                $shape->matchShape($flags & (\PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL)),
                $shape->matchShape($arrayFlags & (\PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL)),
            ];
            $types = array_map($resolver->resolve(...), $written);

            foreach ($replayed as $subject) {
                $received = [];
                $collect = static function (array $matches) use (&$received): string {
                    $received[] = $matches;

                    return '';
                };

                $this->assertNotNull(preg_replace_callback($pattern, $collect, $subject, -1, $count, $flags), \sprintf('%s on %s fails in the engine.', $pattern, json_encode($subject)));
                $calls = \count($received);
                $this->assertNotNull(preg_replace_callback_array([$pattern => $collect], $subject, -1, $arrayCount, $arrayFlags));
                // Each subject matches; their concatenation may not, under an anchor.
                if (\in_array($subject, $subjects, true)) {
                    $this->assertGreaterThan(0, $count, \sprintf('%s does not match %s: the row is wrong.', $pattern, json_encode($subject)));
                    $this->assertGreaterThan(0, $arrayCount, \sprintf('%s does not match %s in preg_replace_callback_array().', $pattern, json_encode($subject)));
                }
                $this->assertSame($count, $arrayCount, \sprintf('%s on %s: preg_replace_callback_array() replaces as often as preg_replace_callback().', $pattern, json_encode($subject)));
                $this->assertSame($calls, \count($received) - $calls, \sprintf('%s on %s: preg_replace_callback_array() calls back as often as preg_replace_callback().', $pattern, json_encode($subject)));

                foreach ($received as $call => $matches) {
                    $array = (int) ($call >= $calls);
                    foreach (EngineMatches::refusals($types[$array], $matches) as $refusal) {
                        $failures[] = \sprintf(
                            '%s with %s on %s, %s call %d: the callback receives %s; %s refuses it: %s.',
                            $pattern,
                            $flagNames,
                            json_encode($subject, \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE),
                            1 === $array ? 'preg_replace_callback_array()' : 'preg_replace_callback()',
                            $call - $array * $calls,
                            json_encode($matches, \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE),
                            $written[$array],
                            $refusal,
                        );
                    }
                }
            }
        }

        $this->assertSame([], $failures, $source);
    }

    #[Test]
    public function test_parity_corpus_lists_each_pattern_once(): void
    {
        $patterns = array_column(EngineMatches::corpus(), 'pattern');

        $this->assertSame([], array_keys(array_filter(array_count_values($patterns), static fn (int $count): bool => $count > 1)));
    }

    #[Test]
    public function test_parity_corpus_holds_every_engine_row_of_the_analyzer_test(): void
    {
        $corpus = array_column(EngineMatches::corpus(), 'subjects', 'pattern');

        $missing = [];
        $rows = [...CaptureShapeAnalyzerTest::provideEngineRows(), ...CaptureShapeAnalyzerTest::provideFactEngineRows(), ...CaptureShapeAnalyzerTest::provideCaseEngineRows()];
        foreach ($rows as $row) {
            $subjects = $corpus[$row['pattern']] ?? [];
            foreach ($row['subjects'] as $subject) {
                if (!\in_array($subject, $subjects, true)) {
                    $missing[] = $row['pattern'].' on '.json_encode($subject, \JSON_UNESCAPED_UNICODE);
                }
            }
        }

        $this->assertSame([], $missing);
    }

    /**
     * The written string and the text PHPStan prints for the type it reads
     * name the same type: a baseline written from either resolves to what an
     * adapter resolves from matchShape(). The text may differ (PHPStan drops
     * the keys of a list shape and orders unions its own way), the type may
     * not.
     *
     * @param non-empty-list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideCorpus')]
    public function test_parity_corpus_shape_is_the_type_phpstan_prints(string $source, string $pattern, array $subjects): void
    {
        $shape = (new CaptureShapeAnalyzer())->analyze(self::parityParser()->parse($pattern));
        $resolver = self::getContainer()->getByType(TypeStringResolver::class);

        $differences = [];
        foreach (EngineMatches::FLAG_SETS as $flags => $flagNames) {
            $written = $shape->matchShape($flags);
            $type = $resolver->resolve($written);
            $printed = $type->describe(VerbosityLevel::precise());
            $reread = $resolver->resolve($printed);
            if (!$type->isSuperTypeOf($reread)->yes() || !$reread->isSuperTypeOf($type)->yes()) {
                $differences[] = \sprintf('%s writes %s, PHPStan prints %s, which reads as another type', $flagNames, $written, $printed);
            }
        }

        $this->assertSame([], $differences, $source);
    }

    /**
     * @return iterable<string, array{source: string, pattern: string, subjects: non-empty-list<string>}>
     */
    public static function provideCorpus(): iterable
    {
        foreach (EngineMatches::corpus() as $row) {
            yield $row['pattern'] => $row;
        }
    }

    /**
     * The corpus holds rows only a newer engine can run — the "r" flag needs
     * PHP 8.4 and PCRE2 10.43 — and a pattern the running engine refuses has
     * no engine result to hold: the row is replayed where it compiles.
     */
    private static function skipWhenTheEngineCannotRun(string $pattern, string $source): void
    {
        if (false === @preg_match($pattern, '')) {
            self::markTestSkipped(\sprintf('%s (%s) does not run on PCRE2 %s.', $pattern, $source, \PCRE_VERSION));
        }
    }

    /**
     * A fixed target, as the digests are read with one: the shapes this
     * parity holds must not change with the PCRE2 running the tests.
     */
    private static function parityParser(): RegexParser
    {
        return RegexParser::create(['php_version' => '8.4', 'pcre_version' => '10.44']);
    }
}
