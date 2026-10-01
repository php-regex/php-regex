<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Integration;

use PhpRegex\Parser\Printer\PatternPrinter;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Validates that the library behaves identically to PHP's native PCRE engine.
 *
 * @see https://github.com/php/php-src/tree/master/ext/pcre/tests
 */
final class OfficialPcreComplianceTest extends TestCase
{
    #[DataProvider('providePcrePatterns')]
    public function test_pattern_compliance(string $pattern): void
    {
        // 1. Determine native validity (Ground Truth)
        $nativeValid = false;

        try {
            // Silence warnings as we expect many patterns to be invalid during stress testing
            @preg_match($pattern, '');
            if (\PREG_NO_ERROR === preg_last_error()) {
                $nativeValid = true;
            }
        } catch (\Throwable) {
            $nativeValid = false;
        }

        // 2. Validate with runtime PCRE checks enabled
        $regex = Regex::create(['runtime_pcre_validation' => true]);
        $validation = $regex->validate($pattern);

        // Scenario A: Pattern is natively invalid
        if (!$nativeValid) {
            $this->assertFalse(
                $validation->isValid,
                \sprintf('Pattern "%s" is invalid in PHP but was accepted by the parser/runtime validation.', $pattern),
            );

            return;
        }

        // Scenario B: Pattern is natively valid, but Validation failed
        if (!$validation->isValid) {
            $this->fail(\sprintf(
                'Validation failed on a valid pattern: "%s". Error: %s',
                $pattern,
                $validation->error ?? 'unknown error',
            ));
        }

        // 3. Parse with the library (should succeed if validation passed)
        $ast = null;

        try {
            $ast = $regex->parse($pattern);
        } catch (\Throwable $e) {
            $this->fail(\sprintf(
                'Parser failed on a valid pattern: "%s". Error: %s',
                $pattern,
                $e->getMessage(),
            ));
        }

        // Scenario C: Pattern is valid, verify behavioral consistency
        $compiler = new PatternPrinter();

        try {
            $compiledPattern = $ast->accept($compiler);
        } catch (\Throwable $e) {
            $this->fail(\sprintf('Compilation failed for pattern: "%s". Error: %s', $pattern, $e->getMessage()));
        }

        $subject = 'test string 123 abc !@#';

        // 3. Compare execution results
        $nativeResult = @preg_match($pattern, $subject, $nativeMatches);
        $nativeErrorCode = preg_last_error();

        $compiledResult = @preg_match($compiledPattern, $subject, $compiledMatches);
        $compiledErrorCode = preg_last_error();

        $this->assertSame(
            $nativeErrorCode,
            $compiledErrorCode,
            \sprintf('Error code mismatch between original ("%s") and compiled ("%s")', $pattern, $compiledPattern),
        );

        $this->assertSame(
            $nativeResult,
            $compiledResult,
            \sprintf('Match result mismatch (0/1) between original ("%s") and compiled ("%s")', $pattern, $compiledPattern),
        );

        $this->assertSame(
            $nativeMatches,
            $compiledMatches,
            \sprintf('Captured groups mismatch between original ("%s") and compiled ("%s")', $pattern, $compiledPattern),
        );
    }

    /**
     * @return iterable<array{0: string}>
     */
    public static function providePcrePatterns(): iterable
    {
        $file = __DIR__.'/../Fixtures/pcre_patterns.php';

        if (!file_exists($file)) {
            return [];
        }

        /** @var array $patterns */
        $patterns = require $file;

        foreach ($patterns as $pattern) {
            if (!\is_string($pattern) || '' === $pattern) {
                continue;
            }

            yield [$pattern];
        }
    }
}
