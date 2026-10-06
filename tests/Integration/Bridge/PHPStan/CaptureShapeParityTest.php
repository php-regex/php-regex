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
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\VerbosityLevel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The parity corpus replayed on the engine: for every pattern and every flag
 * set, the type PHPStan reads from matchShape() holds each $matches the
 * engine writes, key by key.
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
        $shape = (new CaptureShapeAnalyzer())->analyze(RegexParser::create()->parse($pattern));
        $resolver = self::getContainer()->getByType(TypeStringResolver::class);

        $failures = [];
        foreach (EngineMatches::FLAG_SETS as $flags => $flagNames) {
            $written = $shape->matchShape($flags);
            $type = $resolver->resolve($written);
            $this->assertInstanceOf(ConstantArrayType::class, $type, \sprintf('PHPStan reads "%s" as %s.', $written, $type->describe(VerbosityLevel::precise())));

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
        foreach (CaptureShapeAnalyzerTest::provideEngineRows() as $row) {
            $subjects = $corpus[$row['pattern']] ?? [];
            foreach ($row['subjects'] as $subject) {
                if (!\in_array($subject, $subjects, true)) {
                    $missing[] = $row['pattern'].' on '.json_encode($subject);
                }
            }
        }

        $this->assertSame([], $missing);
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
}
