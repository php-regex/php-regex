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

namespace PHPRegex\Tests\Unit\Parser\Validation;

use PHPRegex\Parser\Exception\SemanticErrorException;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Validation\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A walk of the validator starts from the state a new instance has: one
 * that judged other patterns before, some of them stopped half-way by an
 * error, judges the next as a new one would.
 */
final class ValidatorReuseTest extends TestCase
{
    /**
     * Patterns that leave the walk deep in some state when they fail: a
     * class with a quote open, a lookbehind, a payload parsed apart, a
     * repeat, a reference to a missing group, a conditional of three
     * branches, a pattern too large.
     */
    private const PATTERNS = [
        '/[\Qa\E\x{110000}]/u',
        '/(?<=a+)b/',
        '/(*pla:\k<x>)(?<x>a)/',
        '/(?:a\K){2}(?=\Kb)/',
        '/\2(a)/',
        '/(a)(?(1)b|c|d)/',
        '/(?:a{65535}){2}/',
        '/(?<=(?1))(a)/',
        '/(*sr:[[:foo:]])/',
        '/(?<=\C)/u',
        '/\p{Foo}/u',
        '/(?<n>a)\k<m>/',
        '/a{3,2}/',
        '/(*LIMIT_MATCH=99999999999)a/',
        '/(?(VERSION>=10.4)a|b)/',
        '/[z-a]/',
        '/(?<=a|bc)d/',
        '/(?|(a)|(b))\1/',
        '/x/',
    ];

    #[Test]
    #[DataProvider('provideTargets')]
    public function test_a_validator_that_judged_other_patterns_judges_the_next_as_a_new_one(string $php, string $pcre): void
    {
        $parser = RegexParser::create(['cache' => null, 'php_version' => $php, 'pcre_version' => $pcre, 'runtime_pcre_validation' => false]);
        $target = $parser->target();
        $reused = new Validator(255, null, $target);

        foreach ([...self::PATTERNS, ...array_reverse(self::PATTERNS)] as $pattern) {
            $tree = $parser->parse($pattern);

            $this->assertSame(self::outcome($tree, new Validator(255, null, $target)), self::outcome($tree, $reused), $pattern);
        }
    }

    /**
     * @return iterable<string, array{php: string, pcre: string}>
     */
    public static function provideTargets(): iterable
    {
        yield 'PHP 7.4, PCRE2 10.33' => ['php' => '7.4', 'pcre' => '10.33'];
        yield 'PHP 8.2, PCRE2 10.40' => ['php' => '8.2', 'pcre' => '10.40'];
        yield 'PHP 8.5, PCRE2 10.49' => ['php' => '8.5', 'pcre' => '10.49'];
    }

    /**
     * @return array{0: string, 1: int|null, 2: string}|null
     */
    private static function outcome(RegexNode $tree, Validator $validator): ?array
    {
        try {
            $tree->accept($validator);
        } catch (SemanticErrorException $error) {
            return [$error->errorCode->value, $error->position, $error->getMessage()];
        }

        return null;
    }
}
