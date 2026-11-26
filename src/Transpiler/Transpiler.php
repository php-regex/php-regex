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

namespace PHPRegex\Transpiler;

use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\Exception\LexerException;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Exception\RegexException;
use PHPRegex\Parser\PcreFeature;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Transpiler\Exception\TranspileException;
use PHPRegex\Transpiler\Target\TargetRegistry;

/**
 * Transpiles PCRE regex literals to other target dialects.
 */
final class Transpiler
{
    private readonly RegexParser $parser;

    private readonly TargetRegistry $targets;

    /**
     * @param RegexParser|null $parser Reads every pattern; null builds RegexParser::create()
     */
    public function __construct(?RegexParser $parser = null)
    {
        $this->parser = $parser ?? RegexParser::create();
        $this->targets = new TargetRegistry();
    }

    /**
     * Transpile a PCRE regex into the spelling of a target dialect.
     *
     * A dialect given by name resolves before the pattern is parsed, so an
     * unknown name is refused before any syntax fault is read.
     *
     * @param string                $regex   The regex as written, delimiters and flags included ("/[a-z]+/i")
     * @param Dialect|string        $dialect The dialect, or a name Dialect::fromName() takes: "javascript"
     *                                       or "js", "html-pattern" or "html", "python" or "py"
     * @param TranspileOptions|null $options What the dialects may do; null reads the defaults
     *
     * @throws InvalidRegexOptionException when $dialect is a name no dialect answers to
     * @throws TranspileException          when the dialect refuses a construct or a flag it cannot carry
     *                                     without changing what matches
     * @throws ParserException             when $regex does not parse: a delimiter or flag fault, a syntax
     *                                     error, or a parser limit — the thrown subclasses are
     *                                     SyntaxErrorException, RecursionLimitException and
     *                                     ResourceLimitException
     * @throws LexerException              when $regex does not tokenize, e.g. a /u body that is not valid UTF-8
     *
     * @return TranspileResult The dialect's pattern, with its literal and constructor spellings
     */
    public function transpile(string $regex, Dialect|string $dialect, ?TranspileOptions $options = null): TranspileResult
    {
        $options ??= new TranspileOptions();
        $dialect = \is_string($dialect) ? Dialect::fromName($dialect) : $dialect;
        $target = $this->targets->get($dialect);
        $ast = $this->parser->parse($regex);

        // The pattern means what the PCRE2 it is read for makes of it: a
        // bare script name is the script alone before 10.40.
        $context = new TranspileContext($regex, $ast->flags, $options, !$this->parser->target()->supports(PcreFeature::LoosePropertyNames), $ast->source, function () use ($regex, $dialect, $options): ?string {
            // Asked only once the engine compiles the pattern with u: the run
            // with u refuses as the target does, and that refusal is the
            // advice. A run the parser cannot make, the pattern one byte past
            // a max_pattern_length, tells nothing of the target: the advice
            // is then PCRE's, and the caller's pattern still transpiles. A
            // run with u never asks again, as no byte is refused under u.
            try {
                $this->transpile($regex.'u', $dialect, $options);

                return null;
            } catch (TranspileException $e) {
                return $e->getMessage();
            } catch (RegexException) {
                return null;
            }
        });
        $compiled = $target->compile($ast, $context);
        $flags = $target->mapFlags($ast->flags, $context);

        return new TranspileResult(
            $regex,
            $dialect->value,
            $compiled,
            $flags,
            $target->formatLiteral($compiled, $flags, $context),
            $target->formatConstructor($compiled, $flags, $context),
            $context->getWarnings(),
            $context->getNotes(),
        );
    }
}
