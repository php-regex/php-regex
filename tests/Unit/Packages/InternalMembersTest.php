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

namespace PHPRegex\Tests\Unit\Packages;

use PHPRegex\Automata\Exception\ComplexityException;
use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Automata\Model\Dfa;
use PHPRegex\Automata\Model\DfaState;
use PHPRegex\Generator\Exception\SampleGenerationException;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Parser\Analysis\LiteralSet;
use PHPRegex\Parser\Engine\PcreEngine;
use PHPRegex\Parser\Exception\LexerException;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Exception\RegexException;
use PHPRegex\Parser\Exception\SemanticErrorException;
use PHPRegex\Parser\Printer\PatternPrinter;
use PHPRegex\Transpiler\Exception\TranspileException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Members of public classes that only the library calls: the parts of a
 * result or an exception the library builds, and the plumbing one service
 * hands another. They say @internal, so a minor release may change them.
 */
final class InternalMembersTest extends TestCase
{
    /**
     * @param class-string $class
     */
    #[Test]
    #[DataProvider('provideInternalMembers')]
    public function test_a_member_only_the_library_calls_says_it_is_internal(string $class, string $method): void
    {
        $docComment = (string) (new \ReflectionMethod($class, $method))->getDocComment();

        $this->assertMatchesRegularExpression('/^\s*\*\s*@internal\b/m', $docComment, $class.'::'.$method.'()');
    }

    /**
     * @return iterable<string, array{class: class-string, method: string}>
     */
    public static function provideInternalMembers(): iterable
    {
        $members = [
            PcreEngine::class => ['prepare'],
            PatternPrinter::class => ['forFormatting', 'forCompacting', 'refusal', 'resetState', 'flags', 'setFlags', 'scope', 'restoreScope', 'countCapture', 'groupOpening', 'conditionText', 'quotedRunPieces', 'separator', 'opensConstruct', 'escapeString'],
            LiteralSet::class => ['__construct', 'empty', 'fromString', 'concat', 'unite'],
            Dfa::class => ['__construct'],
            DfaState::class => ['__construct', 'packedRanges', 'byteTransitions'],
            // The checks the optimizer and the linter ask of a rewrite, and
            // the key the solver stores a DFA under: the packages that ask
            // them may not reach an internal class of the solver's package.
            LanguageSolver::class => ['matchEquivalentIn', 'runsOfEveryCall', 'readAlike', 'rewritesAlikeInPlace', 'dfaCacheKey'],
            RegexException::class => ['__construct'],
            ParserException::class => ['__construct', 'withContext'],
            LexerException::class => ['__construct', 'withContext'],
            SemanticErrorException::class => ['__construct'],
            ComplexityException::class => ['__construct'],
            TranspileException::class => ['__construct'],
            SampleGenerationException::class => ['__construct'],
            PatternOccurrence::class => ['__construct'],
        ];

        foreach ($members as $class => $methods) {
            foreach ($methods as $method) {
                yield substr($class, (int) strrpos($class, '\\') + 1).'::'.$method => ['class' => $class, 'method' => $method];
            }
        }
    }
}
