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

use PHPRegex\Tests\Support\ExtractsWithBothStrategies;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * "$map['word'] ?? '/fallback/'" reads the item alone when the array is
 * there on every path to the read and holds the key: one a statement of
 * the function body assigns, or of the file, of a namespace or of a
 * block of braces written among them. An array assigned in a branch may
 * not be there: the fallback is read beside the item.
 *
 * PHP 8.4 (php -r): in "namespace App { $map = ['word' => '/w/'];
 * var_dump($map['word'] ?? '/fallback/'); }" the value is "/w/"; so it
 * is after "{ { $map = ['word' => '/w/']; } }" in a function. After "if
 * ('' !== $s) { $map = ['word' => '/w/']; }", it is "/fallback/" when $s
 * is empty and "/w/" otherwise.
 *
 * Both strategies read the same.
 */
final class FallbackOnAHeldKeyTest extends TestCase
{
    use ExtractsWithBothStrategies;

    /**
     * @return iterable<string, array{code: string}>
     */
    public static function provideArraysAssignedOnEveryPath(): iterable
    {
        $read = "preg_match(\$map['word'] ?? '/fallback/', 'x');";

        yield 'at the level of the file' => ['code' => "<?php\n\n\$map = ['word' => '/^\\w+$/'];\n".$read."\n"];
        yield 'at the level of a namespace' => ['code' => "<?php\n\nnamespace App;\n\n\$map = ['word' => '/^\\w+$/'];\n".$read."\n"];
        yield 'in the braces of a namespace' => ['code' => "<?php\n\nnamespace App {\n    \$map = ['word' => '/^\\w+$/'];\n    ".$read."\n}\n"];
        yield 'in a function' => ['code' => "<?php\n\nfunction check(): void\n{\n    \$map = ['word' => '/^\\w+$/'];\n    ".$read."\n}\n"];
        yield 'in a block of braces, in a function' => ['code' => "<?php\n\nfunction check(): void\n{\n    {\n        \$map = ['word' => '/^\\w+$/'];\n    }\n\n    ".$read."\n}\n"];
        yield 'in a block of braces in another, in a function' => ['code' => "<?php\n\nfunction check(): void\n{\n    {\n        {\n            \$map = ['word' => '/^\\w+$/'];\n        }\n    }\n\n    ".$read."\n}\n"];
        yield 'in a block of braces, in a function of a namespace' => ['code' => "<?php\n\nnamespace App;\n\nfunction check(): void\n{\n    {\n        \$map = ['word' => '/^\\w+$/'];\n    }\n\n    ".$read."\n}\n"];
    }

    #[Test]
    #[DataProvider('provideArraysAssignedOnEveryPath')]
    public function test_the_item_of_an_array_assigned_on_every_path_is_read_without_its_fallback(string $code): void
    {
        $this->assertSame(['/^\w+$/'], $this->patternsReadByBoth($code));
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => "\$map['word'] ?? '/fallback/'"],
        ], $code);
    }

    /**
     * @return iterable<string, array{code: string}>
     */
    public static function provideArraysAssignedInABranch(): iterable
    {
        $read = "preg_match(\$map['word'] ?? '/fallback/', 'x');";

        yield 'in a branch, in a function' => ['code' => "<?php\n\nfunction check(string \$s): void\n{\n    if ('' !== \$s) {\n        \$map = ['word' => '/^\\w+$/'];\n    }\n\n    ".$read."\n}\n"];
        yield 'in a branch, in a block of braces' => ['code' => "<?php\n\nfunction check(string \$s): void\n{\n    {\n        if ('' !== \$s) {\n            \$map = ['word' => '/^\\w+$/'];\n        }\n    }\n\n    ".$read."\n}\n"];
    }

    #[Test]
    #[DataProvider('provideArraysAssignedInABranch')]
    public function test_the_item_of_an_array_assigned_in_a_branch_is_read_beside_its_fallback(string $code): void
    {
        $patterns = $this->patternsReadByBoth($code);
        sort($patterns, \SORT_STRING);

        $this->assertSame(['/^\w+$/', '/fallback/'], $patterns);
        $this->assertArgumentsReadByBoth([
            ['state' => 'finite_set', 'expression' => "\$map['word'] ?? '/fallback/'"],
        ], $code);
    }
}
