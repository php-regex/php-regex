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

namespace PHPRegex\Tests\Unit\Support\PhpUnitCompat;

use PHPRegex\Tests\Support\PhpUnitCompat\PolyfilledMethods;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Once a method of the newest PHPUnit is polyfilled, no test calls the one
 * it replaces, whatever PHPUnit runs the suite: the static analysis only
 * reports the deprecated call where the installed PHPUnit deprecates it.
 */
final class ReplacedMethodCallsTest extends TestCase
{
    #[Test]
    public function test_no_test_calls_a_method_the_layer_replaces(): void
    {
        $replaced = [];
        foreach (PolyfilledMethods::FEATURES as $feature) {
            foreach ($feature['replaces'] as $method) {
                $replaced[] = $method;
            }
        }

        $tests = \dirname(__DIR__, 3);
        // The polyfills build the new methods on the replaced ones.
        $polyfills = $tests.'/Support/PhpUnitCompat/Polyfill/';

        $calls = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($tests, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension() || str_starts_with($file->getPathname(), $polyfills)) {
                continue;
            }
            foreach (self::callsOf((string) file_get_contents($file->getPathname()), $replaced) as $line => $method) {
                $calls[] = \sprintf('%s:%d %s()', substr($file->getPathname(), \strlen($tests) + 1), $line, $method);
            }
        }

        sort($calls);
        $this->assertSame([], $calls, 'Call the method of PhpUnitForwardCompat instead.');
    }

    /**
     * @param array<int, string> $expected
     */
    #[Test]
    #[DataProvider('provideCode')]
    public function test_the_scan_finds_the_method_calls_only(string $code, array $expected): void
    {
        $this->assertSame($expected, self::callsOf($code, ['expectExceptionMessage']));
    }

    /**
     * @return iterable<string, array{code: string, expected: array<int, string>}>
     */
    public static function provideCode(): iterable
    {
        yield 'an instance call' => [
            'code' => "<?php\n\$this->expectExceptionMessage('a');\n",
            'expected' => [2 => 'expectExceptionMessage'],
        ];
        yield 'a static call, spaced, in another case' => [
            'code' => "<?php\n\nself :: ExpectExceptionMessage ('a');\n",
            'expected' => [3 => 'ExpectExceptionMessage'],
        ];
        yield 'a nullsafe call' => [
            'code' => "<?php\n\$case?->expectExceptionMessage('a');\n",
            'expected' => [2 => 'expectExceptionMessage'],
        ];
        yield 'the method that replaces it' => [
            'code' => "<?php\n\$this->expectExceptionMessageIsOrContains('a');\n",
            'expected' => [],
        ];
        yield 'a name in a string and a comment' => [
            'code' => "<?php\n// \$this->expectExceptionMessage('a');\n\$name = '\$this->expectExceptionMessage(';\n",
            'expected' => [],
        ];
        yield 'a property, a function and a declaration' => [
            'code' => "<?php\n\$this->expectExceptionMessage;\nexpectExceptionMessage('a');\nfunction expectExceptionMessage() {}\n",
            'expected' => [],
        ];
    }

    /**
     * The calls of the given methods on an object or a class, by line.
     *
     * @param list<string> $methods
     *
     * @return array<int, string>
     */
    private static function callsOf(string $code, array $methods): array
    {
        $names = array_map(strtolower(...), $methods);
        $tokens = array_values(array_filter(
            token_get_all($code),
            static fn (array|string $token): bool => !\is_array($token) || !\in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true),
        ));

        $calls = [];
        foreach ($tokens as $i => $token) {
            if (!\is_array($token) || \T_STRING !== $token[0] || !\in_array(strtolower($token[1]), $names, true)) {
                continue;
            }
            $before = $tokens[$i - 1] ?? null;
            $after = $tokens[$i + 1] ?? null;
            if (\is_array($before) && \in_array($before[0], [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON], true) && '(' === $after) {
                $calls[$token[2]] = $token[1];
            }
        }

        return $calls;
    }
}
