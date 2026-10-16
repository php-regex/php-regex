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

namespace PHPRegex\Tests\Unit\Lint;

use PhpParser\ParserFactory;
use PHPRegex\Linter\Extraction\ExtractorInterface;
use PHPRegex\Linter\Extraction\PhpParserExtractionStrategy;
use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Linter\PatternExtractor;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A resolved pattern is reported at its declaration, so the marker that
 * silences it sits on the declaration's line: one on a call that uses it
 * does nothing for it.
 */
final class ResolvedPatternIgnoreTest extends TestCase
{
    use TemporaryProject;

    /**
     * @return iterable<string, array{strategy: string}>
     */
    public static function provideStrategies(): iterable
    {
        yield 'tokenizer' => ['strategy' => TokenBasedExtractionStrategy::class];
        yield 'nikic/php-parser' => ['strategy' => PhpParserExtractionStrategy::class];
    }

    /**
     * @param class-string<ExtractorInterface> $strategy
     */
    #[Test]
    #[DataProvider('provideStrategies')]
    public function test_regex_ignore_on_the_declaration_suppresses_a_resolved_pattern(string $strategy): void
    {
        $this->assertSame([
            '/ignored-next-line/' => true,
            '/ignored-same-line/' => true,
            '/ignored-variable/' => true,
        ], $this->ignoredByPattern($strategy, <<<'PHP'
            <?php

            final class Matcher
            {
                private const SAME = '/ignored-same-line/'; // @regex-ignore
                // @regex-ignore-next-line
                private const NEXT = '/ignored-next-line/';

                public function run(string $s): bool
                {
                    $re = '/ignored-variable/'; // @regex-ignore

                    return 1 === preg_match(self::SAME, $s) && 1 === preg_match(self::NEXT, $s) && 1 === preg_match($re, $s);
                }
            }
            PHP));
    }

    /**
     * @param class-string<ExtractorInterface> $strategy
     */
    #[Test]
    #[DataProvider('provideStrategies')]
    public function test_regex_ignore_on_the_call_does_not_suppress_a_resolved_pattern(string $strategy): void
    {
        $this->assertSame([
            '/kept/' => false,
            '/literal-at-the-call/' => true,
        ], $this->ignoredByPattern($strategy, <<<'PHP'
            <?php

            final class Matcher
            {
                private const RE = '/kept/';

                public function run(string $s): bool
                {
                    preg_match('/literal-at-the-call/', $s); // @regex-ignore

                    return 1 === preg_match(self::RE, $s); // @regex-ignore
                }
            }
            PHP));
    }

    /**
     * @param class-string<ExtractorInterface> $strategy
     *
     * @return array<string, bool>
     */
    private function ignoredByPattern(string $strategy, string $code): array
    {
        if (PhpParserExtractionStrategy::class === $strategy && !class_exists(ParserFactory::class)) {
            $strategy = TokenBasedExtractionStrategy::class;
        }

        $directory = $this->makeProject(['src/Matcher.php' => $code]);

        $ignored = [];
        foreach ((new PatternExtractor(new $strategy()))->extract([$directory.'/src'], []) as $occurrence) {
            $ignored[$occurrence->pattern] = $occurrence->isIgnored;
        }
        ksort($ignored);

        return $ignored;
    }
}
