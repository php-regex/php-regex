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

namespace PHPRegex\Tests\Unit\Transpiler;

use PHPRegex\Toolkit\Regex;
use PHPRegex\Transpiler\Exception\TranspileException;
use PHPRegex\Transpiler\Target\TargetRegistry;
use PHPRegex\Transpiler\TranspileOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A pattern PCRE compiles, holding a construct or a flag the target has no
 * spelling for, is refused with the construct named and its offset in the
 * pattern body: never written as something that matches otherwise.
 */
final class TargetRefusalTest extends TestCase
{
    #[Test]
    #[DataProvider('provideConstructsATargetLacks')]
    public function test_transpile_refuses_a_construct_the_target_lacks(string $target, string $pattern, string $message, ?int $position): void
    {
        // Oracle: the engine compiles the pattern, so the refusal is the target's alone.
        $this->assertSame(0, preg_match($pattern, ''), 'Oracle: '.$pattern);

        try {
            Regex::create(['cache' => null])->transpile($pattern, $target);
            $this->fail('Transpiled '.$pattern.' to '.$target.'.');
        } catch (TranspileException $e) {
            $this->assertSame($message, $e->getMessage());
            $this->assertSame($position, $e->position);
        }
    }

    /**
     * @return iterable<string, array{target: string, pattern: string, message: string, position: ?int}>
     */
    public static function provideConstructsATargetLacks(): iterable
    {
        foreach (['javascript' => 'JavaScript', 'python' => 'Python'] as $target => $name) {
            yield $name.': a line break' => ['target' => $target, 'pattern' => '/a\R/', 'message' => 'Unsupported character type in '.$name.': \R.', 'position' => 1];
            yield $name.': a subroutine call' => ['target' => $target, 'pattern' => '/(a)(?1)/', 'message' => 'Subroutine calls are not supported in '.('python' === $target ? 'Python re' : $name).'.', 'position' => 3];
            yield $name.': two flags it has no equivalent for' => ['target' => $target, 'pattern' => '/a/AJ', 'message' => 'Unsupported PCRE flag(s) for '.$name.': A, J.', 'position' => null];
        }

        yield 'JavaScript: a match start reset' => ['target' => 'javascript', 'pattern' => '/a\Kb/', 'message' => '\K is not supported in JavaScript.', 'position' => 1];
        yield 'JavaScript: a script run' => ['target' => 'javascript', 'pattern' => '/a(*sr:b)/', 'message' => 'Script run annotations are not supported in JavaScript.', 'position' => 1];
        yield 'JavaScript: a POSIX class' => ['target' => 'javascript', 'pattern' => '/a[[:alpha:]]/', 'message' => 'POSIX character classes are not supported in JavaScript.', 'position' => 2];
        yield 'JavaScript: a DEFINE group' => ['target' => 'javascript', 'pattern' => '/(?(DEFINE)(?<n>a))b/', 'message' => 'DEFINE subpatterns are not supported in JavaScript.', 'position' => 0];
        yield 'JavaScript: a match limit' => ['target' => 'javascript', 'pattern' => '/(*LIMIT_MATCH=5)a/', 'message' => 'LIMIT_MATCH is not supported in JavaScript.', 'position' => 0];
        yield 'JavaScript: a braced relative backreference' => ['target' => 'javascript', 'pattern' => '/(a)\g{-1}/', 'message' => 'Relative backreferences are not supported in JavaScript: \g{-1}.', 'position' => 3];
        yield 'JavaScript: a bare relative backreference' => ['target' => 'javascript', 'pattern' => '/(a)\g-1/', 'message' => 'Relative backreferences are not supported in JavaScript: \g-1.', 'position' => 3];
        // No group capture switch in JavaScript: the group is refused.
        yield 'JavaScript: an inline n' => ['target' => 'javascript', 'pattern' => '/a(?n)(b)+/', 'message' => 'Inline flags groups are not supported in JavaScript.', 'position' => 1];

        yield 'Python: a match start reset' => ['target' => 'python', 'pattern' => '/a\Kb/', 'message' => '\K is not supported in Python re.', 'position' => 1];
        yield 'Python: a script run' => ['target' => 'python', 'pattern' => '/a(*sr:b)/', 'message' => 'Script runs are not supported in Python re.', 'position' => 1];
        yield 'Python: a POSIX class' => ['target' => 'python', 'pattern' => '/a[[:alpha:]]/', 'message' => 'POSIX character classes are not supported in Python re.', 'position' => 2];
        yield 'Python: a DEFINE group' => ['target' => 'python', 'pattern' => '/(?(DEFINE)(?<n>a))b/', 'message' => 'DEFINE subpatterns are not supported in Python re.', 'position' => 0];
        yield 'Python: a match limit' => ['target' => 'python', 'pattern' => '/(*LIMIT_MATCH=5)a/', 'message' => 'LIMIT_MATCH is not supported in Python re.', 'position' => 0];
        yield 'Python: a Unicode property' => ['target' => 'python', 'pattern' => '/a\pL/u', 'message' => 'Unicode properties (\p) are not supported in Python standard re module.', 'position' => 1];
        yield 'Python: an inline n' => ['target' => 'python', 'pattern' => '/a(?n)(b)+/', 'message' => 'The inline flags (?n) cannot be carried into Python re.', 'position' => 1];
        yield 'Python: a relative backreference' => ['target' => 'python', 'pattern' => '/(a)\g{-1}/', 'message' => 'Unsupported backreference syntax for Python: \g{-1}.', 'position' => 3];
        // Python re needs every branch of a lookbehind to have one length:
        // re.compile() refuses these (Python 3.10, "look-behind requires
        // fixed-width pattern"), which PCRE2 10.43 and later compile.
        yield 'Python: a lookbehind of a range' => ['target' => 'python', 'pattern' => '/(?<=a{1,3})b/', 'message' => 'Python re needs a lookbehind of one length: this branch is 1 to 3 characters long.', 'position' => 0];
        yield 'Python: a lookbehind of an optional letter' => ['target' => 'python', 'pattern' => '/(?<=a??)b/', 'message' => 'Python re needs a lookbehind of one length: this branch is 0 to 1 characters long.', 'position' => 0];
        yield 'Python: a lookbehind of a repeated alternation' => ['target' => 'python', 'pattern' => '/(?<=(?:ab|cd){1,2})x/', 'message' => 'Python re needs a lookbehind of one length: this branch is 2 to 4 characters long.', 'position' => 0];
        yield 'Python: a lookbehind branch of a range' => ['target' => 'python', 'pattern' => '/(?<=ab|c{1,2})x/', 'message' => 'Python re needs a lookbehind of one length: this branch is 1 to 2 characters long.', 'position' => 7];
    }

    /**
     * Lookbehind turned off refuses one, the HTML attribute included: an
     * engine older than ES2018 would throw on it.
     */
    #[Test]
    #[DataProvider('provideTargetsReadingTheLookbehindOption')]
    public function test_transpile_refuses_a_lookbehind_turned_off(string $target): void
    {
        $this->assertSame(1, preg_match('/(?<=a)b/', 'ab'));

        try {
            Regex::create(['cache' => null])->transpile('/(?<=a)b/', $target, new TranspileOptions(allowLookbehind: false));
            $this->fail('Transpiled a lookbehind turned off.');
        } catch (TranspileException $e) {
            $this->assertSame('Lookbehind is disabled for JavaScript targets.', $e->getMessage());
            $this->assertSame(0, $e->position);
        }
    }

    /**
     * @return iterable<string, array{target: string}>
     */
    public static function provideTargetsReadingTheLookbehindOption(): iterable
    {
        yield 'JavaScript' => ['target' => 'javascript'];
        yield 'the HTML pattern attribute' => ['target' => 'html-pattern'];
    }

    #[Test]
    public function test_python_target_names_the_quote_its_literals_open_with(): void
    {
        $target = (new TargetRegistry())->get('python');

        $this->assertSame("'", $target->getDefaultDelimiter());
        $this->assertSame("r'(?a)a'", Regex::create(['cache' => null])->transpile('/a/', 'python')->literal);
    }
}
