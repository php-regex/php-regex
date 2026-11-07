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

namespace PHPRegex\Tests\Unit\Bridge\Infection;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\String_;
use PHPRegex\Infection\Mutator\RegexAnchor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Which calls the mutators read: a string literal written as the pattern of
 * a native preg_*() function, nothing else, and never an exception.
 */
final class CallSiteTest extends TestCase
{
    #[Test]
    #[DataProvider('provideNativeCalls')]
    public function test_can_mutate_accepts_a_literal_pattern_of_each_native_preg_function(string $code): void
    {
        $this->assertTrue(MutatorHarness::canMutate(new RegexAnchor(), $code));
        $this->assertNotSame([], MutatorHarness::patterns(new RegexAnchor(), $code));
    }

    /**
     * @return iterable<string, array{code: string}>
     */
    public static function provideNativeCalls(): iterable
    {
        yield 'preg_match' => ['code' => "preg_match('/^a/', \$s)"];
        yield 'preg_match with matches' => ['code' => "preg_match('/^a/', \$s, \$m)"];
        yield 'preg_match_all' => ['code' => "preg_match_all('/^a/', \$s, \$m)"];
        yield 'preg_replace' => ['code' => "preg_replace('/^a/', 'b', \$s)"];
        yield 'preg_replace_callback' => ['code' => "preg_replace_callback('/^a/', static fn (array \$m): string => 'b', \$s)"];
        yield 'preg_split' => ['code' => "preg_split('/^a/', \$s)"];
        yield 'preg_grep' => ['code' => "preg_grep('/^a/', [\$s])"];
        yield 'preg_filter' => ['code' => "preg_filter('/^a/', 'b', \$s)"];
        yield 'named pattern argument' => ['code' => "preg_match(subject: \$s, pattern: '/^a/')"];
        yield 'fully qualified' => ['code' => "\\preg_match('/^a/', \$s)"];
        yield 'upper-case function name' => ['code' => "PREG_MATCH('/^a/', \$s)"];
        yield 'double-quoted literal' => ['code' => 'preg_match("/^a/", $s)'];
        yield 'nowdoc' => ['code' => "preg_match(<<<'RE'\n/^a/\nRE, \$s)"];
        yield 'unqualified in a namespace, no use function' => ['code' => "<?php\nnamespace App;\nfunction f(\$s) { return preg_match('/^a/', \$s); }\n"];
    }

    #[Test]
    #[DataProvider('provideRefusedCalls')]
    public function test_can_mutate_refuses_what_is_not_a_literal_pattern_of_a_native_call(string $code): void
    {
        $this->assertFalse(MutatorHarness::canMutate(new RegexAnchor(), $code));
        $this->assertSame([], MutatorHarness::patterns(new RegexAnchor(), $code));
    }

    /**
     * @return iterable<string, array{code: string}>
     */
    public static function provideRefusedCalls(): iterable
    {
        yield 'variable' => ['code' => 'preg_match($p, $s)'];
        yield 'global constant' => ['code' => 'preg_match(PATTERN, $s)'];
        yield 'class constant' => ['code' => 'preg_match(self::PATTERN, $s)'];
        yield 'concatenation' => ['code' => "preg_match('/^'.\$p.'/', \$s)"];
        yield 'interpolated string' => ['code' => 'preg_match("/^$p/", $s)'];
        yield 'array of patterns' => ['code' => "preg_replace(['/^a/'], 'b', \$s)"];
        yield 'preg_replace_callback_array' => ['code' => "preg_replace_callback_array(['/^a/' => static fn (): string => ''], \$s)"];
        yield 'first-class callable' => ['code' => 'preg_match(...)'];
        yield 'unpacked arguments' => ['code' => 'preg_match(...$args)'];
        yield 'unpacked arguments after the pattern' => ['code' => "preg_match('/^a/', ...\$rest)"];
        yield 'pattern not first and not named' => ['code' => "preg_match(\$s, '/^a/')"];
        yield 'other function' => ['code' => "str_contains('/^a/', \$s)"];
        yield 'preg_quote' => ['code' => "preg_quote('/^a/')"];
        yield 'use function Safe' => ['code' => "<?php\nnamespace App;\nuse function Safe\\preg_match;\nfunction f(\$s) { return preg_match('/^a/', \$s); }\n"];
        yield 'qualified namespaced function' => ['code' => "<?php\nnamespace App;\nfunction f(\$s) { return Regex\\preg_match('/^a/', \$s); }\n"];
        yield 'fully qualified namespaced function' => ['code' => "\\Safe\\preg_match('/^a/', \$s)"];
        yield 'dynamic function name' => ['code' => "\$f('/^a/', \$s)"];
        yield 'pattern the engine refuses' => ['code' => "preg_match('/^(a/', \$s)"];
        yield 'no delimiter' => ['code' => "preg_match('^a', \$s)"];
        yield 'pattern given twice' => ['code' => "preg_match('/^a/', \$s, pattern: '/^b/')"];
        yield 'more arguments than parameters' => ['code' => "preg_split('/^a/', \$s, -1, 0, 9)"];
        yield 'empty string' => ['code' => "preg_match('', \$s)"];
        yield 'unknown modifier' => ['code' => "preg_match('/^a/g', \$s)"];
    }

    #[Test]
    public function test_can_mutate_refuses_a_node_that_is_not_a_call(): void
    {
        $this->assertFalse((new RegexAnchor())->canMutate(new String_('/^a/')));
        $this->assertFalse((new RegexAnchor())->canMutate(new Variable('s')));
        $this->assertSame([], [...(new RegexAnchor())->mutate(new String_('/^a/'))]);
    }

    #[Test]
    public function test_a_call_read_without_name_resolution_is_judged_by_its_written_name(): void
    {
        $call = static fn (Name $name): FuncCall => new FuncCall($name, [new Arg(new String_('/^a/')), new Arg(new Variable('s'))]);

        $this->assertTrue((new RegexAnchor())->canMutate($call(new Name('preg_match'))));
        $this->assertTrue((new RegexAnchor())->canMutate($call(new FullyQualified('preg_match'))));
        $this->assertFalse((new RegexAnchor())->canMutate($call(new FullyQualified('Safe\\preg_match'))));
        $this->assertFalse((new RegexAnchor())->canMutate($call(new Name('Safe\\preg_match'))));
    }

    #[Test]
    #[DataProvider('provideTroublesomeLiterals')]
    public function test_never_throws_on_a_literal_it_cannot_read(string $code): void
    {
        $mutator = new RegexAnchor();

        $this->assertSame([], MutatorHarness::patterns($mutator, $code));
        $this->assertNull(error_get_last());
        $this->assertSame(\PREG_NO_ERROR, preg_last_error());
    }

    /**
     * @return iterable<string, array{code: string}>
     */
    public static function provideTroublesomeLiterals(): iterable
    {
        yield 'unbalanced group' => ['code' => "preg_match('/^(a/', \$s)"];
        yield 'bad escape' => ['code' => "preg_match('/^\\\\/', \$s)"];
        yield 'unclosed delimiter' => ['code' => "preg_match('/^a', \$s)"];
        yield 'alphanumeric delimiter' => ['code' => "preg_match('a^ba', \$s)"];
        yield 'invalid UTF-8 under u' => ['code' => 'preg_match("/^\\xff/u", $s)'];
        yield 'NUL byte delimiter' => ['code' => 'preg_match("\\0^a\\0", $s)'];
    }
}
