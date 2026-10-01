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

namespace PHPRegex\Tests\Unit\Exception;

use PHPRegex\Automata\Model\Nfa;
use PHPRegex\Cli\CliException;
use PHPRegex\Cli\Graph\GraphGenerator;
use PHPRegex\Generator\SampleGenerationException;
use PHPRegex\Generator\SampleGenerator;
use PHPRegex\Linter\Formatter\FormatterRegistry;
use PHPRegex\Linter\Formatter\JsonFormatter;
use PHPRegex\Linter\LintException;
use PHPRegex\Linter\LintReport;
use PHPRegex\Parser\Cache\NullCache;
use PHPRegex\Parser\Exception\ExceptionInterface;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Token\Token;
use PHPRegex\Parser\Token\TokenStream;
use PHPRegex\Parser\Token\TokenType;
use PHPRegex\Redos\Confirmation;
use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A caller's mistake throws an exception implementing
 * ExceptionInterface, each of its package; a library bug, such
 * as a token stream walked past its end, stays a plain \LogicException.
 */
final class CallerMistakeExceptionsTest extends TestCase
{
    #[Test]
    public function test_an_unknown_explain_format_is_an_invalid_option(): void
    {
        $this->expectException(InvalidRegexOptionException::class);

        Regex::create(['cache' => new NullCache()])->explain('/a/', 'pdf');
    }

    /**
     * "10.4" is no PCRE2 release: the caller wrote it.
     */
    #[Test]
    public function test_a_release_spelled_short_is_an_invalid_option(): void
    {
        $this->expectException(InvalidRegexOptionException::class);

        (new PcreTarget(80400, '10.40'))->pcreAtLeast('10.4');
    }

    #[Test]
    public function test_an_unknown_formatter_is_a_lint_exception(): void
    {
        $this->expectException(LintException::class);
        $this->expectExceptionMessage('Formatter "nope" not found.');

        (new FormatterRegistry())->get('nope');
    }

    /**
     * An infinite timeout cannot be written as JSON: the report cannot be
     * encoded, and the linter says so with its own exception.
     */
    #[Test]
    public function test_a_report_json_cannot_encode_is_a_lint_exception(): void
    {
        $confirmation = new Confirmation(false, [], null, null, null, 0, \INF);
        $analysis = new RedosAnalysis(RedosSeverity::Safe, 0, confirmation: $confirmation);
        $report = new LintReport([[
            'file' => 'a.php',
            'line' => 1,
            'pattern' => '/a/',
            'issues' => [['type' => 'error', 'message' => 'm', 'file' => 'a.php', 'line' => 1, 'analysis' => $analysis]],
            'optimizations' => [],
            'problems' => [],
        ]], ['errors' => 1, 'warnings' => 0, 'optimizations' => 0]);

        $this->expectException(LintException::class);

        (new JsonFormatter())->format($report);
    }

    #[Test]
    public function test_the_lint_and_cli_exceptions_implement_the_library_interface(): void
    {
        $this->assertTrue((new \ReflectionClass(LintException::class))->implementsInterface(ExceptionInterface::class));
        $this->assertTrue((new \ReflectionClass(CliException::class))->implementsInterface(ExceptionInterface::class));
    }

    #[Test]
    public function test_an_unknown_graph_format_is_a_cli_exception(): void
    {
        $this->expectException(CliException::class);

        (new GraphGenerator())->generate(new Nfa(0, []), 'nope');
    }

    /**
     * Oracle: PCRE2 compiles "(?R)" (it fails at match time, a recursion
     * loop); the generator has nothing to recurse into and gives up.
     */
    #[Test]
    public function test_a_pattern_the_generator_cannot_sample_is_a_sample_generation_exception(): void
    {
        $this->expectException(SampleGenerationException::class);

        Regex::create(['cache' => new NullCache()])->generate('/(?R)/');
    }

    /**
     * The visitor runs on an AST nobody validated: a subroutine to a group
     * that does not exist (PCRE refuses the pattern) is the caller's.
     */
    #[Test]
    #[DataProvider('provideUnresolvedSubroutines')]
    public function test_an_unresolved_subroutine_is_a_sample_generation_exception(string $pattern): void
    {
        $ast = RegexParser::create(['cache' => new NullCache()])->parse($pattern);

        $this->expectException(SampleGenerationException::class);

        $ast->accept(new SampleGenerator());
    }

    /**
     * Through the facade the same pattern may be refused before any sample
     * is drawn; either way the caller catches it with the interface.
     */
    #[Test]
    #[DataProvider('provideUnresolvedSubroutines')]
    public function test_generating_from_an_unresolved_subroutine_throws_a_library_exception(string $pattern): void
    {
        $this->expectException(ExceptionInterface::class);

        Regex::create(['cache' => new NullCache()])->generate($pattern);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideUnresolvedSubroutines(): iterable
    {
        yield 'numbered' => ['/(?1)a/'];
        yield 'relative' => ['/(?-1)a/'];
        yield 'named' => ['/(?&x)a/'];
    }

    /**
     * PCRE has no empty class ("[]" opens a class whose first member is
     * "]"): only an AST built by hand holds one.
     */
    #[Test]
    public function test_an_empty_class_built_by_hand_is_a_sample_generation_exception(): void
    {
        $class = new CharClassNode(new AlternationNode([], 0, 0), false, 0, 0);

        $this->expectException(SampleGenerationException::class);

        $class->accept(new SampleGenerator());
    }

    /**
     * @param \Closure(TokenStream):mixed $walk
     */
    #[Test]
    #[DataProvider('provideWalksPastTheBounds')]
    public function test_a_token_stream_walked_past_its_bounds_is_a_logic_exception(\Closure $walk): void
    {
        $stream = new TokenStream([
            new Token(TokenType::Literal, 'a', 0),
            new Token(TokenType::Eof, '', 1),
        ], 'a');

        try {
            $walk($stream);
        } catch (\Throwable $thrown) {
            $this->assertSame(\LogicException::class, $thrown::class);

            return;
        }

        $this->fail('Walking past the bounds threw nothing.');
    }

    /**
     * @return iterable<string, array{\Closure(TokenStream):mixed}>
     */
    public static function provideWalksPastTheBounds(): iterable
    {
        yield 'next past the end' => [static function (TokenStream $stream): void {
            $stream->next();
            $stream->next();
            $stream->next();
        }];
        yield 'current past the end' => [static function (TokenStream $stream): mixed {
            $stream->next();
            $stream->next();

            return $stream->current();
        }];
        yield 'rewind before the start' => [static function (TokenStream $stream): void {
            $stream->rewind(1);
        }];
        yield 'position before the start' => [static function (TokenStream $stream): void {
            $stream->setPosition(-1);
        }];
        yield 'position past the end' => [static function (TokenStream $stream): void {
            $stream->setPosition(3);
        }];
    }
}
