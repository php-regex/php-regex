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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PHP 8.5 deprecates three methods of SplObjectStorage for the array
 * access each one duplicates, and a deprecation raised under src/ fails
 * the suite there. A run on an older PHP raises none, so the calls are
 * found in the source: a property, a parameter or a variable typed or
 * built as a SplObjectStorage takes none of the three. The scan reads a
 * name as PHP does, in any case and through the imports of the file. It
 * reads no assignment but a build, and gives a variable and a property of
 * the same name, anywhere in the file, the one type: it may name a call
 * that is not deprecated, and leaves a storage reached otherwise to the
 * run on PHP 8.5.
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
            foreach (self::deprecatedCalls($tokens) as $call) {
                $calls[] = $path.':'.$call;
            }
        }

        $this->assertSame([], $calls);
    }

    /**
     * @param list<string> $calls
     */
    #[Test]
    #[DataProvider('provideSources')]
    public function test_the_scan_finds_the_calls_a_storage_makes(string $source, array $calls): void
    {
        $this->assertSame($calls, self::deprecatedCalls(token_get_all($source)));
    }

    /**
     * @return iterable<string, array{source: string, calls: list<string>}>
     */
    public static function provideSources(): iterable
    {
        yield 'a parameter typed as a storage' => [
            'source' => <<<'PHP'
                <?php
                namespace App;
                function f(\SplObjectStorage $s, object $o): bool
                {
                    return $s->contains($o);
                }
                PHP,
            'calls' => ['5 s->contains(), where PHP 8.5 wants isset($storage[$object])'],
        ];
        yield 'a nullable property typed as a storage' => [
            'source' => <<<'PHP'
                <?php
                namespace App;
                final class A
                {
                    private ?\SplObjectStorage $s = null;
                    public function f(object $o): void
                    {
                        $this->s?->detach($o);
                    }
                }
                PHP,
            'calls' => ['8 s->detach(), where PHP 8.5 wants unset($storage[$object])'],
        ];
        yield 'a variable and a property built as storages' => [
            'source' => <<<'PHP'
                <?php
                namespace App;
                final class A
                {
                    public function f(object $o): void
                    {
                        $s = new \SplObjectStorage();
                        $s->attach($o);
                        $this->t = new \SplObjectStorage();
                        $this->t->attach($o);
                    }
                }
                PHP,
            'calls' => [
                '8 s->attach(), where PHP 8.5 wants $storage[$object] = $data',
                '10 t->attach(), where PHP 8.5 wants $storage[$object] = $data',
            ],
        ];
        yield 'the array access PHP 8.5 keeps' => [
            'source' => <<<'PHP'
                <?php
                namespace App;
                function f(\SplObjectStorage $s, object $o): bool
                {
                    $s[$o] = 1;
                    unset($s[$o]);
                    return isset($s[$o]);
                }
                PHP,
            'calls' => [],
        ];
        yield 'a receiver of another class' => [
            'source' => <<<'PHP'
                <?php
                namespace App;
                function f(\SplObjectStorage $s, CharSet $set): bool
                {
                    return $set->contains(65);
                }
                PHP,
            'calls' => [],
        ];
        // PHP reads a method name, and a class name, in any case.
        yield 'a method written in another case' => [
            'source' => <<<'PHP'
                <?php
                namespace App;
                function f(\SplObjectStorage $s, object $o): bool
                {
                    return $s->Contains($o);
                }
                PHP,
            'calls' => ['5 s->Contains(), where PHP 8.5 wants isset($storage[$object])'],
        ];
        yield 'a class written in another case' => [
            'source' => <<<'PHP'
                <?php
                namespace App;
                function f(\splObjectStorage $s, object $o): bool
                {
                    return $s->contains($o);
                }
                PHP,
            'calls' => ['5 s->contains(), where PHP 8.5 wants isset($storage[$object])'],
        ];
        yield 'a class imported under its name' => [
            'source' => <<<'PHP'
                <?php
                namespace App;
                use SplObjectStorage;
                function f(SplObjectStorage $s, object $o): bool
                {
                    return $s->contains($o);
                }
                PHP,
            'calls' => ['6 s->contains(), where PHP 8.5 wants isset($storage[$object])'],
        ];
        yield 'a class imported under an alias' => [
            'source' => <<<'PHP'
                <?php
                namespace App;
                use SplObjectStorage as Storage;
                function f(Storage $s, object $o): bool
                {
                    return $s->contains($o);
                }
                PHP,
            'calls' => ['6 s->contains(), where PHP 8.5 wants isset($storage[$object])'],
        ];
        yield 'a class imported after another in one statement' => [
            'source' => <<<'PHP'
                <?php
                namespace App;
                use DateTime, SplObjectStorage as Storage;
                function f(Storage $s, DateTime $d, object $o): bool
                {
                    return $s->contains($o);
                }
                PHP,
            'calls' => ['6 s->contains(), where PHP 8.5 wants isset($storage[$object])'],
        ];
        yield 'another class of the same short name imported after another, in a file without a namespace' => [
            'source' => <<<'PHP'
                <?php
                use DateTime, Vendor\SplObjectStorage;
                function f(SplObjectStorage $s, DateTime $d, object $o): bool
                {
                    return $s->contains($o);
                }
                PHP,
            'calls' => [],
        ];
        yield 'a parameter passed by reference' => [
            'source' => <<<'PHP'
                <?php
                namespace App;
                function f(\SplObjectStorage &$s, object $o): bool
                {
                    return $s->contains($o);
                }
                PHP,
            'calls' => ['5 s->contains(), where PHP 8.5 wants isset($storage[$object])'],
        ];
        yield 'the class in a file without a namespace' => [
            'source' => <<<'PHP'
                <?php
                function f(SplObjectStorage $s, object $o): bool
                {
                    return $s->contains($o);
                }
                PHP,
            'calls' => ['4 s->contains(), where PHP 8.5 wants isset($storage[$object])'],
        ];
        yield 'another class of the same short name, in a file without a namespace' => [
            'source' => <<<'PHP'
                <?php
                use Vendor\SplObjectStorage;
                function f(SplObjectStorage $s, object $o): bool
                {
                    return $s->contains($o);
                }
                PHP,
            'calls' => [],
        ];
        yield 'another class aliased to the short name, in a file without a namespace' => [
            'source' => <<<'PHP'
                <?php
                use Vendor\Storage as SplObjectStorage;
                function f(SplObjectStorage $s, object $o): bool
                {
                    return $s->contains($o);
                }
                PHP,
            'calls' => [],
        ];
        yield 'another class of the same short name' => [
            'source' => <<<'PHP'
                <?php
                namespace App;
                use Vendor\SplObjectStorage;
                function f(SplObjectStorage $s, object $o): bool
                {
                    return $s->contains($o);
                }
                PHP,
            'calls' => [],
        ];
    }

    /**
     * The calls a file makes to a method PHP 8.5 deprecates on what it
     * types or builds as a SplObjectStorage, with their line.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return list<string>
     */
    private static function deprecatedCalls(array $tokens): array
    {
        $code = array_values(array_filter(
            $tokens,
            static fn (array|string $token): bool => !\is_array($token) || !\in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true),
        ));
        $storages = self::storages($code);
        if ([] === $storages) {
            return [];
        }

        $calls = [];
        foreach ($code as $i => $token) {
            // PHP reads a method name in any case.
            if (!\is_array($token) || \T_STRING !== $token[0] || !isset(self::DEPRECATED[strtolower($token[1])]) || '(' !== ($code[$i + 1] ?? null) || !self::isArrow($code[$i - 1] ?? null)) {
                continue;
            }

            $receiver = self::receiver($code, $i - 2);
            if (null !== $receiver && isset($storages[$receiver])) {
                $calls[] = \sprintf('%d %s->%s(), where PHP 8.5 wants %s', $token[2], $receiver, $token[1], self::DEPRECATED[strtolower($token[1])]);
            }
        }

        return $calls;
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
        $classes = self::storageClassNames($code);
        $names = [];
        foreach ($code as $i => $token) {
            if (!\is_array($token) || !isset($classes[strtolower($token[1])])) {
                continue;
            }

            // A type: the variable it declares follows it, past the "&" of
            // a parameter passed by reference.
            $next = $code[$i + 1] ?? null;
            if (\is_array($next) && \T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG === $next[0]) {
                $next = $code[$i + 2] ?? null;
            }

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
     * The names, lowercased, under which a file reaches SplObjectStorage:
     * "\SplObjectStorage", the name a "use" imports it under, and its own
     * name in a file that declares no namespace, unless the import of
     * another class takes that name. PHP reads a class name in any case;
     * in a namespace, a name nothing imports is a class of that namespace.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $code
     *
     * @return array<string, true>
     */
    private static function storageClassNames(array $code): array
    {
        $names = ['\splobjectstorage' => true, 'splobjectstorage' => true];
        foreach ($code as $i => $token) {
            if (\is_array($token) && \T_NAMESPACE === $token[0]) {
                unset($names['splobjectstorage']);

                continue;
            }

            if (!\is_array($token) || \T_USE !== $token[0]) {
                continue;
            }

            foreach (self::imports($code, $i + 1) as [$class, $bound]) {
                if ('splobjectstorage' === $class) {
                    $names[$bound] = true;
                } elseif ('splobjectstorage' === $bound) {
                    unset($names['splobjectstorage']);
                }
            }
        }

        return $names;
    }

    /**
     * Each import of the "use" statement whose first name stands at $at, up
     * to the last a comma leads to: the class it names and the name it
     * binds, its alias or the last part of the class, both lowercased. A
     * closure's "use (" imports nothing.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $code
     *
     * @return list<array{string, string}>
     */
    private static function imports(array $code, int $at): array
    {
        $imports = [];
        for ($name = $code[$at] ?? null; \is_array($name); $name = $code[$at] ?? null) {
            $class = strtolower(ltrim($name[1], '\\'));
            $as = $code[$at + 1] ?? null;
            $alias = $code[$at + 2] ?? null;
            if (\is_array($as) && \T_AS === $as[0] && \is_array($alias)) {
                $imports[] = [$class, strtolower($alias[1])];
                $at += 2;
            } else {
                $imports[] = [$class, substr((string) strrchr('\\'.$class, '\\'), 1)];
            }

            if (',' !== ($code[$at + 1] ?? null)) {
                break;
            }

            $at += 2;
        }

        return $imports;
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
