<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Validates the extracted PCRE test cases against native PHP execution.
 *
 * This test class ensures that all test cases extracted from php-src/ext/pcre/tests
 * produce the expected results when executed with PHP's native preg_* functions.
 *
 * @see https://github.com/php/php-src/tree/master/ext/pcre/tests
 */
final class PhpPcreComplianceValidator extends TestCase
{
    /**
     * @param array<int|string, mixed>      $expectedMatches
     * @param array<int|string, mixed>|null $expectedResult
     * @param array<int, string>            $functions
     */
    #[DataProvider('providePhpPcreTestCases')]
    public function test_php_pcre_compliance(
        string $pattern,
        string $subject,
        int $flags,
        int $offset,
        int $expectedReturn,
        array $expectedMatches,
        ?array $expectedResult,
        string $description,
        string $source,
        array $functions,
        string $category,
    ): void {
        $function = $functions[0] ?? 'preg_match';

        match ($function) {
            'preg_match' => $this->assertPregMatch(
                $pattern,
                $subject,
                $flags,
                $offset,
                $expectedReturn,
                $expectedMatches,
                $description,
                $source,
                $category,
            ),
            'preg_match_all' => $this->assertPregMatchAll(
                $pattern,
                $subject,
                $flags,
                $offset,
                $expectedReturn,
                $expectedMatches,
                $description,
                $source,
                $category,
            ),
            'preg_split' => $this->assertPregSplit(
                $pattern,
                $subject,
                $flags,
                $expectedReturn,
                $expectedResult ?? [],
                $description,
                $source,
                $category,
            ),
            default => $this->markTestSkipped(\sprintf('Function %s not yet supported in validator', $function)),
        };
    }

    /**
     * @return iterable<string, array{
     *     pattern: string,
     *     subject: string,
     *     flags: int,
     *     offset: int,
     *     expectedReturn: int,
     *     expectedMatches: array<int|string, mixed>,
     *     expectedResult: array<int|string, mixed>|null,
     *     description: string,
     *     source: string,
     *     functions: array<int, string>,
     *     category: string
     * }>
     */
    public static function providePhpPcreTestCases(): iterable
    {
        $fixtureFile = __DIR__.'/../Fixtures/php_pcre_comprehensive.php';

        if (!file_exists($fixtureFile)) {
            return;
        }

        /** @var array<int, array{pattern: string, subject: string, flags: int, offset?: int, expectedReturn: int, expectedMatches: array<int|string, mixed>, expectedResult?: array<int|string, mixed>|null, description: string, source: string, functions: array<int, string>, category: string}> $fixtures */
        $fixtures = require $fixtureFile;

        foreach ($fixtures as $fixture) {
            $key = \sprintf(
                '[%s] %s - %s',
                $fixture['source'],
                $fixture['category'],
                $fixture['description'],
            );

            yield $key => [
                'pattern' => $fixture['pattern'],
                'subject' => $fixture['subject'],
                'flags' => $fixture['flags'],
                'offset' => $fixture['offset'] ?? 0,
                'expectedReturn' => $fixture['expectedReturn'],
                'expectedMatches' => $fixture['expectedMatches'],
                'expectedResult' => $fixture['expectedResult'] ?? null,
                'description' => $fixture['description'],
                'source' => $fixture['source'],
                'functions' => $fixture['functions'],
                'category' => $fixture['category'],
            ];
        }
    }

    /**
     * @param array<int|string, mixed> $expectedMatches
     */
    private function assertPregMatch(
        string $pattern,
        string $subject,
        int $flags,
        int $offset,
        int $expectedReturn,
        array $expectedMatches,
        string $description,
        string $source,
        string $category,
    ): void {
        $matches = [];
        /** @phpstan-ignore argument.type */
        $result = @preg_match($pattern, $subject, $matches, $flags, $offset);

        $this->assertNotFalse(
            $result,
            \sprintf(
                "preg_match() returned false (error) for: %s\n".
                "Pattern: %s\n".
                "Subject: %s\n".
                "Source: %s\n".
                'Error: %s',
                $description,
                $pattern,
                $subject,
                $source,
                preg_last_error_msg(),
            ),
        );

        $this->assertSame(
            $expectedReturn,
            $result,
            \sprintf(
                "preg_match() returned %d but expected %d for: %s\n".
                "Pattern: %s\n".
                "Subject: %s\n".
                "Source: %s\n".
                'Category: %s',
                $result,
                $expectedReturn,
                $description,
                $pattern,
                $subject,
                $source,
                $category,
            ),
        );

        if (1 === $expectedReturn && !empty($expectedMatches)) {
            $this->assertSame(
                $expectedMatches,
                $matches,
                \sprintf(
                    "Matches structure doesn't match expected for: %s\n".
                    "Pattern: %s\n".
                    "Subject: %s\n".
                    "Source: %s\n".
                    "Category: %s\n".
                    "Expected:\n%s\n".
                    "Got:\n%s",
                    $description,
                    $pattern,
                    $subject,
                    $source,
                    $category,
                    var_export($expectedMatches, true),
                    var_export($matches, true),
                ),
            );
        }
    }

    /**
     * @param array<int|string, mixed> $expectedMatches
     */
    private function assertPregMatchAll(
        string $pattern,
        string $subject,
        int $flags,
        int $offset,
        int $expectedReturn,
        array $expectedMatches,
        string $description,
        string $source,
        string $category,
    ): void {
        $matches = [];
        $result = @preg_match_all($pattern, $subject, $matches, $flags, $offset);

        $this->assertNotFalse(
            $result,
            \sprintf(
                "preg_match_all() returned false (error) for: %s\n".
                "Pattern: %s\n".
                "Subject: %s\n".
                "Source: %s\n".
                'Error: %s',
                $description,
                $pattern,
                $subject,
                $source,
                preg_last_error_msg(),
            ),
        );

        $this->assertSame(
            $expectedReturn,
            $result,
            \sprintf(
                "preg_match_all() returned %d but expected %d for: %s\n".
                "Pattern: %s\n".
                "Subject: %s\n".
                "Source: %s\n".
                'Category: %s',
                $result,
                $expectedReturn,
                $description,
                $pattern,
                $subject,
                $source,
                $category,
            ),
        );

        if ($expectedReturn > 0 && !empty($expectedMatches)) {
            $this->assertSame(
                $expectedMatches,
                $matches,
                \sprintf(
                    "Matches structure doesn't match expected for: %s\n".
                    "Pattern: %s\n".
                    "Subject: %s\n".
                    "Source: %s\n".
                    "Category: %s\n".
                    "Expected:\n%s\n".
                    "Got:\n%s",
                    $description,
                    $pattern,
                    $subject,
                    $source,
                    $category,
                    var_export($expectedMatches, true),
                    var_export($matches, true),
                ),
            );
        }
    }

    /**
     * @param array<int|string, mixed> $expectedResult
     */
    private function assertPregSplit(
        string $pattern,
        string $subject,
        int $flags,
        int $expectedReturn,
        array $expectedResult,
        string $description,
        string $source,
        string $category,
    ): void {
        $result = @preg_split($pattern, $subject, -1, $flags);

        $this->assertNotFalse(
            $result,
            \sprintf(
                "preg_split() returned false (error) for: %s\n".
                "Pattern: %s\n".
                "Subject: %s\n".
                "Source: %s\n".
                'Error: %s',
                $description,
                $pattern,
                $subject,
                $source,
                preg_last_error_msg(),
            ),
        );

        $this->assertCount(
            $expectedReturn,
            $result,
            \sprintf(
                "preg_split() returned %d parts but expected %d for: %s\n".
                "Pattern: %s\n".
                "Subject: %s\n".
                "Source: %s\n".
                'Category: %s',
                \count($result),
                $expectedReturn,
                $description,
                $pattern,
                $subject,
                $source,
                $category,
            ),
        );

        if (!empty($expectedResult)) {
            $this->assertSame(
                $expectedResult,
                $result,
                \sprintf(
                    "Split result doesn't match expected for: %s\n".
                    "Pattern: %s\n".
                    "Subject: %s\n".
                    "Source: %s\n".
                    "Category: %s\n".
                    "Expected:\n%s\n".
                    "Got:\n%s",
                    $description,
                    $pattern,
                    $subject,
                    $source,
                    $category,
                    var_export($expectedResult, true),
                    var_export($result, true),
                ),
            );
        }
    }
}
