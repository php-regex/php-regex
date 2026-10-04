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

namespace PHPRegex\Tests\Unit\Automata;

use PHPRegex\Automata\TrivialMatch;
use PHPRegex\Automata\TrivialMatchClassifier;
use PHPRegex\Automata\TrivialMatchKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A preg_match() a string function answers alike: the classifier names the
 * function only once the automata prove that preg_match() returns 1 for
 * exactly the subjects the function says true for.
 */
final class TrivialMatchClassifierTest extends TestCase
{
    private const SUBJECTS = ['', 'foo', 'xfoo', 'foox', "foo\n", "xfoo\n", 'FOO', 'GET', 'POST', 'GETX', 'a', 'b', 'ab', 'f.o', 'fxo', "it's", "\n", 'color', 'colour', 'colouur'];

    /**
     * @param list<string> $literals
     */
    #[Test]
    #[DataProvider('provideTrivialPatterns')]
    public function test_a_trivial_pattern_is_named_with_its_function(string $pattern, TrivialMatchKind $kind, array $literals, string $expression): void
    {
        $match = (new TrivialMatchClassifier())->classify($pattern);

        $this->assertInstanceOf(TrivialMatch::class, $match);
        $this->assertSame($kind, $match->kind);
        $this->assertSame($literals, $match->literals);
        $this->assertSame($expression, $match->phpExpression('$subject'));

        foreach (self::SUBJECTS as $subject) {
            $this->assertSame(1 === preg_match($pattern, $subject), $match->accepts($subject), \sprintf('%s and %s disagree on %s.', $pattern, $expression, json_encode($subject)));
        }
    }

    #[Test]
    #[DataProvider('provideOtherPatterns')]
    public function test_a_pattern_no_function_answers_alike_is_not_named(string $pattern): void
    {
        $this->assertNull((new TrivialMatchClassifier())->classify($pattern));
    }

    #[Test]
    public function test_a_dollar_also_takes_a_final_newline(): void
    {
        $this->assertSame(1, preg_match('/^foo$/', "foo\n"));

        $match = (new TrivialMatchClassifier())->classify('/^foo$/');
        $this->assertInstanceOf(TrivialMatch::class, $match);
        $this->assertNotSame(TrivialMatchKind::Equals, $match->kind);
    }

    /**
     * @return iterable<string, array{pattern: string, kind: TrivialMatchKind, literals: list<string>, expression: string}>
     */
    public static function provideTrivialPatterns(): iterable
    {
        yield 'contains' => ['pattern' => '/foo/', 'kind' => TrivialMatchKind::Contains, 'literals' => ['foo'], 'expression' => "str_contains(\$subject, 'foo')"];
        yield 'starts with' => ['pattern' => '/^foo/', 'kind' => TrivialMatchKind::StartsWith, 'literals' => ['foo'], 'expression' => "str_starts_with(\$subject, 'foo')"];
        yield 'ends with' => ['pattern' => '/foo\z/', 'kind' => TrivialMatchKind::EndsWith, 'literals' => ['foo'], 'expression' => "str_ends_with(\$subject, 'foo')"];
        yield 'equals' => ['pattern' => '/^foo\z/', 'kind' => TrivialMatchKind::Equals, 'literals' => ['foo'], 'expression' => "'foo' === \$subject"];
        yield 'equals under D' => ['pattern' => '/^foo$/D', 'kind' => TrivialMatchKind::Equals, 'literals' => ['foo'], 'expression' => "'foo' === \$subject"];
        yield 'dollar takes a final newline' => ['pattern' => '/^foo$/', 'kind' => TrivialMatchKind::OneOf, 'literals' => ['foo', "foo\n"], 'expression' => "in_array(\$subject, ['foo', \"foo\\n\"], true)"];
        yield 'one of two words' => ['pattern' => '/^(?:GET|POST)\z/', 'kind' => TrivialMatchKind::OneOf, 'literals' => ['GET', 'POST'], 'expression' => "in_array(\$subject, ['GET', 'POST'], true)"];
        yield 'one of a class' => ['pattern' => '/^[ab]\z/', 'kind' => TrivialMatchKind::OneOf, 'literals' => ['a', 'b'], 'expression' => "in_array(\$subject, ['a', 'b'], true)"];
        yield 'empty' => ['pattern' => '/^\z/', 'kind' => TrivialMatchKind::IsEmpty, 'literals' => [], 'expression' => "'' === \$subject"];
        yield 'a group changes nothing' => ['pattern' => '/^(foo)\z/', 'kind' => TrivialMatchKind::Equals, 'literals' => ['foo'], 'expression' => "'foo' === \$subject"];
        yield 'escaped dot' => ['pattern' => '/f\.o/', 'kind' => TrivialMatchKind::Contains, 'literals' => ['f.o'], 'expression' => "str_contains(\$subject, 'f.o')"];
        yield 'optional letter' => ['pattern' => '/^colou?r\z/', 'kind' => TrivialMatchKind::OneOf, 'literals' => ['color', 'colour'], 'expression' => "in_array(\$subject, ['color', 'colour'], true)"];
        yield 'multiline without an anchor' => ['pattern' => '/foo/m', 'kind' => TrivialMatchKind::Contains, 'literals' => ['foo'], 'expression' => "str_contains(\$subject, 'foo')"];
        yield 'quote' => ['pattern' => "/it's/", 'kind' => TrivialMatchKind::Contains, 'literals' => ["it's"], 'expression' => "str_contains(\$subject, 'it\\'s')"];
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideOtherPatterns(): iterable
    {
        yield 'caseless' => ['pattern' => '/foo/i'];
        yield 'utf mode fails on invalid subjects' => ['pattern' => '/foo/u'];
        yield 'multiline anchor' => ['pattern' => '/^foo/m'];
        yield 'repeat' => ['pattern' => '/fo+/'];
        yield 'dollar at the end only' => ['pattern' => '/foo$/'];
        yield 'either of two, anywhere' => ['pattern' => '/a|b/'];
        yield 'anchor on one branch' => ['pattern' => '/^foo|bar/'];
        yield 'matches every subject' => ['pattern' => '//'];
        yield 'backreference' => ['pattern' => '/(a)\1/'];
        yield 'invalid' => ['pattern' => '/(foo/'];
        yield 'class of too many letters' => ['pattern' => '/^[a-z]\z/'];
        yield 'optional class of too many letters' => ['pattern' => '/^\w?\z/'];
        yield 'too many combinations' => ['pattern' => '/^[ab][ab][ab][ab][ab]\z/'];
    }
}
