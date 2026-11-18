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

namespace PHPRegex\Tests\Unit;

use PHPRegex\Tests\Support\LibrarySource;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PHP 8.5 deprecates three methods of SplObjectStorage for the array
 * access each one duplicates, and a deprecation raised under src/ fails
 * the suite there. A run on an older PHP raises none, so the calls are
 * found in the source: a property, a parameter or a variable typed or
 * built as a SplObjectStorage takes none of the three.
 */
final class LibrarySplObjectStorageTest extends TestCase
{
    /**
     * The methods PHP 8.5 deprecates, and the array access each one
     * duplicates.
     */
    private const DEPRECATED = [
        'attach' => '$storage[$object] = $data',
        'contains' => 'isset($storage[$object])',
        'detach' => 'unset($storage[$object])',
    ];

    #[Test]
    public function test_no_storage_of_the_library_calls_a_method_php_8_5_deprecates(): void
    {
        $calls = [];
        foreach (LibrarySource::files() as $path => $tokens) {
            $code = array_values(array_filter(
                $tokens,
                static fn (array|string $token): bool => !\is_array($token) || !\in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true),
            ));
            $storages = self::storages($code);
            if ([] === $storages) {
                continue;
            }

            foreach ($code as $i => $token) {
                if (!\is_array($token) || \T_STRING !== $token[0] || !isset(self::DEPRECATED[$token[1]]) || '(' !== ($code[$i + 1] ?? null) || !self::isArrow($code[$i - 1] ?? null)) {
                    continue;
                }

                $receiver = self::receiver($code, $i - 2);
                if (null !== $receiver && isset($storages[$receiver])) {
                    $calls[] = \sprintf('%s:%d %s->%s(), where PHP 8.5 wants %s', $path, $token[2], $receiver, $token[1], self::DEPRECATED[$token[1]]);
                }
            }
        }

        $this->assertSame([], $calls);
    }

    /**
     * The names, without the "$", of the properties, parameters and
     * variables a file types or builds as a SplObjectStorage.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $code
     *
     * @return array<string, true>
     */
    private static function storages(array $code): array
    {
        $names = [];
        foreach ($code as $i => $token) {
            if (!\is_array($token) || 'SplObjectStorage' !== ltrim($token[1], '\\')) {
                continue;
            }

            // A type: the variable it declares follows it.
            $next = $code[$i + 1] ?? null;
            if (\is_array($next) && \T_VARIABLE === $next[0]) {
                $names[substr($next[1], 1)] = true;

                continue;
            }

            // A build: "$name = new SplObjectStorage" or
            // "$this->name = new SplObjectStorage".
            $new = $code[$i - 1] ?? null;
            if (\is_array($new) && \T_NEW === $new[0] && '=' === ($code[$i - 2] ?? null)) {
                $target = $code[$i - 3] ?? null;
                if (\is_array($target) && \in_array($target[0], [\T_VARIABLE, \T_STRING], true)) {
                    $names[ltrim($target[1], '$')] = true;
                }
            }
        }

        return $names;
    }

    /**
     * The name, without the "$", of what the call before $index is made on:
     * "$name" or "$this->name".
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $code
     */
    private static function receiver(array $code, int $index): ?string
    {
        $token = $code[$index] ?? null;
        if (!\is_array($token)) {
            return null;
        }

        if (\T_VARIABLE === $token[0]) {
            return substr($token[1], 1);
        }

        $owner = $code[$index - 2] ?? null;
        if (\T_STRING === $token[0] && self::isArrow($code[$index - 1] ?? null) && \is_array($owner) && \T_VARIABLE === $owner[0] && '$this' === $owner[1]) {
            return $token[1];
        }

        return null;
    }

    /**
     * @param array{0: int, 1: string, 2: int}|string|null $token
     */
    private static function isArrow(array|string|null $token): bool
    {
        return \is_array($token) && \in_array($token[0], [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR], true);
    }
}
