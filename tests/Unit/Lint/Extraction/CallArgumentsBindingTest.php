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

use PHPRegex\Linter\Extraction\CallArguments;
use PHPRegex\Linter\Extraction\NameResolutionContext;
use PHPRegex\Linter\Extraction\PatternFunction;
use PHPRegex\Linter\Extraction\PhpTokens;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The arguments of a call read from its tokens as PHP binds them: by
 * position or by name, past a spread, through the option arrays a pattern
 * may sit in, and what a replacement argument is whatever its value.
 */
final class CallArgumentsBindingTest extends TestCase
{
    /**
     * @return iterable<string, array{arguments: string, position: int, found: string|null}>
     */
    public static function provideArguments(): iterable
    {
        yield 'the first' => ['arguments' => "'/a/', \$s", 'position' => 0, 'found' => "'/a/'"];
        yield 'the second' => ['arguments' => "'/a/', \$s", 'position' => 1, 'found' => '$s'];
        yield 'past a nested call' => ['arguments' => 'g($a, $b), $c', 'position' => 1, 'found' => '$c'];
        yield 'past an array' => ['arguments' => '[$a, $b], $c', 'position' => 1, 'found' => '$c'];
        yield 'past a closure' => ['arguments' => 'function ($a, $b) { return [$a, $b]; }, $c', 'position' => 1, 'found' => '$c'];
        yield 'by name' => ['arguments' => "subject: \$s, pattern: '/a/'", 'position' => 0, 'found' => "'/a/'"];
        yield 'by a name that is a reserved word' => ['arguments' => "\$s, match: '/a/'", 'position' => 1, 'found' => "'/a/'"];
        yield 'the position before a name' => ['arguments' => "'/a/', pattern: '/b/'", 'position' => 0, 'found' => "'/a/'"];
        yield 'the first of two names' => ['arguments' => "pattern: '/a/', pattern: '/b/'", 'position' => 0, 'found' => "'/a/'"];
        yield 'after a spread' => ['arguments' => '...$a, $b', 'position' => 1, 'found' => null];
        yield 'by name after a spread' => ['arguments' => "...\$a, pattern: '/a/'", 'position' => 0, 'found' => "'/a/'"];
        yield 'missing' => ['arguments' => '$s', 'position' => 2, 'found' => null];
        yield 'none' => ['arguments' => '', 'position' => 0, 'found' => null];
        yield 'after a trailing comma' => ['arguments' => '$a, ', 'position' => 1, 'found' => null];
        yield 'an empty slot is no position' => ['arguments' => '$a, , $b', 'position' => 1, 'found' => '$b'];
        yield 'a name with no value' => ['arguments' => 'pattern:', 'position' => 0, 'found' => ''];
    }

    #[Test]
    #[DataProvider('provideArguments')]
    public function test_find_binds_an_argument_by_position_or_by_name(string $arguments, int $position, ?string $found): void
    {
        [$tokens, $start, $total, $closers] = $this->call($arguments);

        $range = CallArguments::find($tokens, $start, $total, $position, ['pattern', 'match'], $closers);

        $this->assertSame($found, null === $range ? null : $this->text($tokens, $range));
    }

    /**
     * A source cut short ends the call where it ends.
     */
    #[Test]
    public function test_find_reads_a_call_cut_short_to_the_end(): void
    {
        $tokens = token_get_all("<?php f('/a/', \$s");
        $closers = PhpTokens::closers($tokens);
        $range = CallArguments::find($tokens, 3, \count($tokens), 1, ['pattern'], $closers);

        $this->assertNotNull($range);
        $this->assertSame('$s', $this->text($tokens, $range));
        $this->assertNull(CallArguments::find($tokens, 3, \count($tokens), 2, ['pattern'], $closers));
    }

    /**
     * @return iterable<string, array{arguments: string, partial: bool}>
     */
    public static function providePartialApplications(): iterable
    {
        yield 'a placeholder' => ['arguments' => "'/a/', ?", 'partial' => true];
        yield 'a placeholder first' => ['arguments' => '?, $s', 'partial' => true];
        yield 'a variadic placeholder' => ['arguments' => '...', 'partial' => true];
        yield 'a named placeholder' => ['arguments' => 'subject: ?', 'partial' => true];
        yield 'a call' => ['arguments' => "'/a/', \$s", 'partial' => false];
        yield 'no argument' => ['arguments' => '', 'partial' => false];
        yield 'a placeholder in a nested call' => ['arguments' => "g(?), '/a/'", 'partial' => false];
        yield 'a ternary' => ['arguments' => '$a ? $b : $c', 'partial' => false];
        yield 'a spread' => ['arguments' => '...$a', 'partial' => false];
    }

    #[Test]
    #[DataProvider('providePartialApplications')]
    public function test_is_partial_application_holds_a_placeholder_argument_only(string $arguments, bool $partial): void
    {
        [$tokens, $start, $total, $closers] = $this->call($arguments);

        $this->assertSame($partial, CallArguments::isPartialApplication($tokens, $start, $total, $closers));
    }

    /**
     * @return iterable<string, array{arguments: string, utf8: bool|null}>
     */
    public static function provideUtf8Arguments(): iterable
    {
        yield 'left out' => ['arguments' => '$s, $p', 'utf8' => false];
        yield 'true' => ['arguments' => '$s, $p, true', 'utf8' => true];
        yield 'true in capitals, qualified' => ['arguments' => '$s, $p, \TRUE', 'utf8' => true];
        yield 'true in parentheses' => ['arguments' => '$s, $p, (true)', 'utf8' => true];
        yield 'false' => ['arguments' => '$s, $p, false', 'utf8' => false];
        yield 'by name' => ['arguments' => '$s, utf8: true, pattern: $p', 'utf8' => true];
        yield 'the last of a name and a position' => ['arguments' => '$s, $p, false, utf8: true', 'utf8' => true];
        yield 'a variable' => ['arguments' => '$s, $p, $utf8', 'utf8' => null];
        yield 'a namespaced name' => ['arguments' => '$s, $p, \A\true', 'utf8' => null];
        yield 'a spread' => ['arguments' => '$s, ...$rest', 'utf8' => null];
        yield 'past a nested call' => ['arguments' => 'g($a, $b, $c), $p, true', 'utf8' => true];
    }

    #[Test]
    #[DataProvider('provideUtf8Arguments')]
    public function test_utf8_reads_a_literal_true_or_false(string $arguments, ?bool $utf8): void
    {
        [$tokens, $start, $total, $closers] = $this->call($arguments);

        $this->assertSame($utf8, CallArguments::utf8($tokens, $start, $total, [2, 'utf8'], $closers));
    }

    /**
     * @return iterable<string, array{argument: string, required: bool, found: string|null}>
     */
    public static function provideOptionArrays(): iterable
    {
        yield 'the nested item' => ['argument' => "['options' => ['regexp' => '/a/']]", 'required' => true, 'found' => "'/a/'"];
        yield 'array()' => ['argument' => "array('options' => array('regexp' => '/a/'))", 'required' => true, 'found' => "'/a/'"];
        yield 'in parentheses' => ['argument' => "(['options' => (['regexp' => '/a/'])])", 'required' => true, 'found' => "'/a/'"];
        yield 'the last of a repeated key' => ['argument' => "['options' => ['regexp' => '/a/', 'regexp' => '/b/']]", 'required' => true, 'found' => "'/b/'"];
        yield 'a key spelled in two parts' => ['argument' => "['options' => ['reg' . 'exp' => '/a/']]", 'required' => true, 'found' => "'/a/'"];
        yield 'a key in parentheses' => ['argument' => "['options' => [('regexp') => '/a/']]", 'required' => true, 'found' => "'/a/'"];
        yield 'past a nested value' => ['argument' => "['flags' => [1, 2], 'options' => ['regexp' => f('/a/', [1])]]", 'required' => true, 'found' => "f('/a/', [1])"];
        yield 'a trailing comma' => ['argument' => "['options' => ['regexp' => '/a/',],]", 'required' => true, 'found' => "'/a/'"];
        yield 'a spread after the key' => ['argument' => "['options' => ['regexp' => '/a/', ...\$o]]", 'required' => true, 'found' => null];
        yield 'a spread before the key' => ['argument' => "['options' => [...\$o, 'regexp' => '/a/']]", 'required' => true, 'found' => "'/a/'"];
        yield 'an item with no key' => ['argument' => "['options' => ['/b/', 'regexp' => '/a/']]", 'required' => true, 'found' => "'/a/'"];
        yield 'a missing key' => ['argument' => "['options' => ['other' => '/a/']]", 'required' => true, 'found' => null];
        yield 'a key that is no literal' => ['argument' => "['options' => [\$k => '/a/']]", 'required' => true, 'found' => null];
        yield 'a variable' => ['argument' => '$options', 'required' => true, 'found' => null];
        yield 'an array added to' => ['argument' => "['options' => ['regexp' => '/a/']] + \$o", 'required' => true, 'found' => null];
        yield 'an array call' => ['argument' => "array_merge(['options' => ['regexp' => '/a/']])", 'required' => true, 'found' => null];
        yield 'the value itself, when not required' => ['argument' => "'/a/'", 'required' => false, 'found' => "'/a/'"];
        yield 'a nested value that is no array' => ['argument' => "['options' => \$o]", 'required' => false, 'found' => null];
        yield 'nothing' => ['argument' => '', 'required' => true, 'found' => null];
    }

    #[Test]
    #[DataProvider('provideOptionArrays')]
    public function test_option_range_follows_the_option_path(string $argument, bool $required, ?string $found): void
    {
        [$tokens, $start, $total, $closers] = $this->call($argument);
        $end = $total - 2;
        $function = new PatternFunction('f', optionPath: ['options', 'regexp'], optionPathRequired: $required);
        $content = implode('', array_map(static fn (array|string $token): string => \is_array($token) ? $token[1] : $token, $tokens));

        $range = CallArguments::optionRange($tokens, $start, $end, $function, $closers, PhpTokens::offsets($tokens), $content);

        $this->assertSame($found, null === $range ? null : $this->text($tokens, $range));
    }

    #[Test]
    public function test_option_range_is_the_argument_without_a_path(): void
    {
        [$tokens, $start, $total, $closers] = $this->call('$a');

        $this->assertSame([$start, $total - 2], CallArguments::optionRange($tokens, $start, $total - 2, new PatternFunction('f'), $closers, [], ''));
    }

    /**
     * @return iterable<string, array{argument: string, name: string|null}>
     */
    public static function provideSoleNames(): iterable
    {
        yield 'a constant' => ['argument' => 'FILTER_VALIDATE_REGEXP', 'name' => 'FILTER_VALIDATE_REGEXP'];
        yield 'qualified, in parentheses, with a comment' => ['argument' => '( /* c */ \FILTER_VALIDATE_REGEXP)', 'name' => '\FILTER_VALIDATE_REGEXP'];
        yield 'an expression' => ['argument' => 'A | B', 'name' => null];
        yield 'a variable' => ['argument' => '$filter', 'name' => null];
        yield 'nothing' => ['argument' => '', 'name' => null];
    }

    #[Test]
    #[DataProvider('provideSoleNames')]
    public function test_sole_name_is_a_name_alone(string $argument, ?string $name): void
    {
        [$tokens, $start, $total] = $this->call($argument);

        $this->assertSame($name, CallArguments::soleName($tokens, $start, $total - 2));
    }

    /**
     * @return iterable<string, array{argument: string, whole: bool}>
     */
    public static function provideReplacements(): iterable
    {
        yield 'a closure' => ['argument' => 'function ($m) use ($a) { return $a; }', 'whole' => true];
        yield 'a static closure with a return type' => ['argument' => 'static function ($m): string { return ""; }', 'whole' => true];
        yield 'an arrow function' => ['argument' => 'fn ($m) => $m[0]', 'whole' => true];
        yield 'an attributed static arrow function' => ['argument' => '#[Pure] static fn ($m) => $m[0]', 'whole' => true];
        yield 'a closure in parentheses' => ['argument' => '(function () {})', 'whole' => true];
        yield 'an array' => ['argument' => '[$this, "x"]', 'whole' => true];
        yield 'array()' => ['argument' => 'array($a)', 'whole' => true];
        yield '$this' => ['argument' => '$this', 'whole' => true];
        yield 'new' => ['argument' => 'new Upper', 'whole' => true];
        yield 'new with arguments' => ['argument' => 'new Upper(1, [2])', 'whole' => true];
        yield 'new with a class in a bracket' => ['argument' => 'new $classes["a"]', 'whole' => true];
        yield 'new anonymous class' => ['argument' => 'new class (1) extends A { }', 'whole' => true];
        yield 'an object cast' => ['argument' => '(object) $a', 'whole' => true];
        yield 'an array cast of a member chain' => ['argument' => '(array) $a->b[0]::$c', 'whole' => true];
        yield 'a clone' => ['argument' => 'clone $this->a', 'whole' => true];
        yield 'Closure::fromCallable()' => ['argument' => "\\Closure::fromCallable('strtoupper')", 'whole' => true];
        yield 'an imported Closure' => ['argument' => "C::FromCallable('strtoupper')", 'whole' => true];
        yield 'a first-class callable' => ['argument' => 'strtoupper(...)', 'whole' => true];
        yield 'a first-class static method' => ['argument' => 'Text::upper(...)', 'whole' => true];
        yield 'a first-class method' => ['argument' => '$this->upper(...)', 'whole' => true];
        yield 'a variable' => ['argument' => '$callback', 'whole' => false];
        yield 'a string' => ['argument' => "'strtoupper'", 'whole' => false];
        yield 'nothing' => ['argument' => '', 'whole' => false];
        yield 'a fallback to a callable' => ['argument' => '$cb ?? strtoupper(...)', 'whole' => false];
        yield 'an array added to' => ['argument' => '[$a] + $b', 'whole' => false];
        yield 'new, then more' => ['argument' => 'new Upper() ?: $b', 'whole' => false];
        yield 'new left open' => ['argument' => 'new Upper(', 'whole' => false];
        yield 'a closure, then more' => ['argument' => 'function () {} ?: $b', 'whole' => false];
        yield 'a function with no body' => ['argument' => 'function ()', 'whole' => false];
        yield '$this, then more' => ['argument' => '$this ?: $b', 'whole' => false];
        yield 'another variable named this' => ['argument' => '$that', 'whole' => false];
        yield 'an object cast, then more' => ['argument' => '(object) $a or $b', 'whole' => false];
        yield 'a cast of nothing' => ['argument' => '(object)', 'whole' => false];
        yield 'another static method of Closure' => ['argument' => "\\Closure::bind('f')", 'whole' => false];
        yield 'fromCallable of another class' => ['argument' => "Other::fromCallable('f')", 'whole' => false];
        yield 'fromCallable, then more' => ['argument' => "\\Closure::fromCallable('f') ?: \$b", 'whole' => false];
        yield 'a call' => ['argument' => 'strtoupper($a)', 'whole' => false];
        yield 'an ellipsis that is a spread' => ['argument' => 'f(...$a)', 'whole' => false];
        yield 'a static closure, alone' => ['argument' => 'static', 'whole' => false];
    }

    #[Test]
    #[DataProvider('provideReplacements')]
    public function test_is_object_or_array_reads_the_whole_argument(string $argument, bool $whole): void
    {
        [$tokens, $start, $total, $closers] = $this->call($argument);
        $context = new NameResolutionContext();
        $context->importClass('C', 'Closure');

        $this->assertSame($whole, CallArguments::isObjectOrArray($tokens, $start, $total - 2, $closers, $context));
    }

    /**
     * The tokens of a call f(...) holding the arguments, where they start,
     * how many tokens the file has and its brackets.
     *
     * @return array{list<array{int, string, int}|string>, int, int, array<int, int>}
     */
    private function call(string $arguments): array
    {
        $tokens = token_get_all('<?php f('.$arguments.');');

        return [$tokens, 3, \count($tokens), PhpTokens::closers($tokens)];
    }

    /**
     * @param list<array{int, string, int}|string> $tokens
     * @param array{int, int}                      $range
     */
    private function text(array $tokens, array $range): string
    {
        $text = '';
        for ($i = $range[0]; $i < $range[1]; $i++) {
            $text .= \is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
        }

        return trim($text);
    }
}
