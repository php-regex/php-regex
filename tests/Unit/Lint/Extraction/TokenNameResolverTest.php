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

use PHPRegex\Linter\Extraction\NameResolutionContext;
use PHPRegex\Linter\Extraction\TokenNameResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The namespace and the imports a file declares, read from its tokens as
 * PHP resolves the names that follow them.
 */
final class TokenNameResolverTest extends TestCase
{
    /**
     * @return iterable<string, array{source: string, function: string, class: string, constant: list<string>}>
     */
    public static function provideDeclarations(): iterable
    {
        yield 'nothing declared' => ['source' => '', 'function' => 'f', 'class' => 'K', 'constant' => ['C']];
        yield 'a namespace' => ['source' => 'namespace A\B;', 'function' => 'f', 'class' => 'A\B\K', 'constant' => ['A\B\C', 'C']];
        yield 'a braced namespace' => ['source' => 'namespace A { }', 'function' => 'f', 'class' => 'A\K', 'constant' => ['A\C', 'C']];
        yield 'the global namespace braced' => ['source' => 'namespace A; namespace { }', 'function' => 'f', 'class' => 'K', 'constant' => ['C']];
        yield 'a class' => ['source' => 'use X\K;', 'function' => 'f', 'class' => 'X\K', 'constant' => ['C']];
        yield 'a class under an alias' => ['source' => 'use X\Y as K;', 'function' => 'f', 'class' => 'X\Y', 'constant' => ['C']];
        yield 'a function under an alias' => ['source' => 'use function X\g as f;', 'function' => 'X\g', 'class' => 'K', 'constant' => ['C']];
        yield 'a constant' => ['source' => 'use const X\C;', 'function' => 'f', 'class' => 'K', 'constant' => ['X\C']];
        yield 'a group' => ['source' => 'use X\{K, function f, const C};', 'function' => 'X\f', 'class' => 'X\K', 'constant' => ['X\C']];
        yield 'a group of functions' => ['source' => 'use function X\{f, g};', 'function' => 'X\f', 'class' => 'K', 'constant' => ['C']];
        yield 'a group with a trailing comma' => ['source' => 'use X\Y\{Z, K,};', 'function' => 'f', 'class' => 'X\Y\K', 'constant' => ['C']];
        yield 'a list' => ['source' => 'use X\A, Y\K;', 'function' => 'f', 'class' => 'Y\K', 'constant' => ['C']];
        yield 'a leading backslash' => ['source' => 'use \X\K;', 'function' => 'f', 'class' => 'X\K', 'constant' => ['C']];
        yield 'a namespace and its imports' => ['source' => "namespace A;\nuse function X\\f;\nuse X\\K;", 'function' => 'X\f', 'class' => 'X\K', 'constant' => ['A\C', 'C']];
        yield 'imports in a braced namespace' => ['source' => 'namespace A { use X\K; }', 'function' => 'f', 'class' => 'X\K', 'constant' => ['A\C', 'C']];
        yield 'a trait in a class body' => ['source' => 'class Y { use X\K; }', 'function' => 'f', 'class' => 'K', 'constant' => ['C']];
        yield 'a trait in a class of a braced namespace' => ['source' => 'namespace A { class Y { use X\K; } }', 'function' => 'f', 'class' => 'A\K', 'constant' => ['A\C', 'C']];
        yield 'a trait in a class after a string interpolation' => ['source' => 'class Y { function a() { return "{$a}"; } use X\K; }', 'function' => 'f', 'class' => 'K', 'constant' => ['C']];
        yield 'a closure capture' => ['source' => '$a = function () use ($K) {};', 'function' => 'f', 'class' => 'K', 'constant' => ['C']];
        // Outside a class, the items before a conflict block are imports.
        yield 'a trait conflict block' => ['source' => 'use X\K, Y\K { K::a insteadof Y; }', 'function' => 'f', 'class' => 'X\K', 'constant' => ['C']];
        yield 'an import cut short' => ['source' => 'use X\K', 'function' => 'f', 'class' => 'K', 'constant' => ['C']];
        yield 'a namespace cut short' => ['source' => 'namespace', 'function' => 'f', 'class' => 'K', 'constant' => ['C']];
        yield 'a use cut short' => ['source' => 'use', 'function' => 'f', 'class' => 'K', 'constant' => ['C']];
        yield 'a use function cut short' => ['source' => 'use function', 'function' => 'f', 'class' => 'K', 'constant' => ['C']];
        yield 'an empty alias' => ['source' => 'use X\ as;', 'function' => 'f', 'class' => 'K', 'constant' => ['C']];
    }

    /**
     * @param list<string> $constant
     */
    #[Test]
    #[DataProvider('provideDeclarations')]
    public function test_a_declaration_names_what_follows_it(string $source, string $function, string $class, array $constant): void
    {
        $context = $this->read('<?php '.$source)[0];

        // An unqualified call tries the namespace's function first, then
        // this one.
        $this->assertSame($function, $context->resolveFunction('f'));
        $this->assertSame($class, $context->resolveClass('K'));
        $this->assertSame($constant, $context->resolveConstant('C'));
    }

    /**
     * @return iterable<string, array{source: string, resume: list<string>}>
     */
    public static function provideResumePoints(): iterable
    {
        yield 'a namespace resumes after its name' => ['source' => 'namespace A; f();', 'resume' => ['A']];
        yield 'the global namespace resumes at its brace' => ['source' => 'namespace { f(); }', 'resume' => ['{']];
        yield 'an import resumes at its semicolon' => ['source' => 'use X\K; f();', 'resume' => [';']];
        yield 'a trait resumes at its semicolon' => ['source' => 'class Y { use T; }', 'resume' => [';']];
        yield 'a conflict block resumes at the first semicolon' => ['source' => 'class Y { use A, B { B::x insteadof A; } }', 'resume' => [';']];
        yield 'a closure capture resumes at the use' => ['source' => 'function () use ($a) {};', 'resume' => ['use']];
        yield 'a namespace with no name resumes at the keyword' => ['source' => 'namespace;', 'resume' => ['namespace']];
        yield 'an import cut short resumes at its last token' => ['source' => 'use X\K', 'resume' => ['X\K']];
        yield 'a trait cut short resumes at the last token' => ['source' => 'class Y { use T', 'resume' => ['T']];
    }

    /**
     * @param list<string> $resume
     */
    #[Test]
    #[DataProvider('provideResumePoints')]
    public function test_a_declaration_is_read_to_its_end(string $source, array $resume): void
    {
        $this->assertSame($resume, $this->read('<?php '.$source)[1]);
    }

    /**
     * The walk the strategy makes over a file: a namespace and a use read
     * as declarations, every other token stepped over.
     *
     * @return array{NameResolutionContext, list<string>} the context and the token each declaration resumes at
     */
    private function read(string $code): array
    {
        $tokens = token_get_all($code);
        $total = \count($tokens);
        $context = new NameResolutionContext();
        $resumes = [];
        for ($i = 0; $i < $total; $i++) {
            $token = $tokens[$i];
            if (!\is_array($token) || (\T_NAMESPACE !== $token[0] && \T_USE !== $token[0])) {
                continue;
            }

            $i = \T_NAMESPACE === $token[0]
                ? TokenNameResolver::readNamespace($tokens, $i, $total, $context)
                : TokenNameResolver::readUse($tokens, $i, $total, $context);
            $resumes[] = \is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
        }

        return [$context, $resumes];
    }
}
