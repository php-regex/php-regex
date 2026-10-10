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

use PHPRegex\Parser\Exception\LexerException;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Transpiler\Target\TargetRegistry;

/**
 * Transpiles PCRE regex literals to other target dialects.
 */
final readonly class Transpiler
{
    public function __construct(private RegexParser $regex, private TargetRegistry $targets = new TargetRegistry()) {}

    /**
     * Transpile a PCRE regex into the spelling of a target dialect.
     *
     * The target resolves through the registry before the pattern is parsed,
     * so an unknown dialect name is refused before any syntax fault is read.
     *
     * @param string                $pattern The regex as written, delimiters and flags included ("/[a-z]+/i")
     * @param string                $target  A registered target name or alias: "javascript" or "js",
     *                                       "html-pattern" or "html", "python" or "py"; case and
     *                                       surrounding space are forgiven
     * @param TranspileOptions|null $options What the dialects may do; null reads the defaults
     *
     * @throws TranspileException when $target names no registered dialect, or the dialect refuses a
     *                            construct or a flag it cannot carry without changing what matches
     * @throws ParserException    when $pattern does not parse: a delimiter or flag fault, a syntax error,
     *                            or a parser limit — the thrown subclasses are SyntaxErrorException,
     *                            RecursionLimitException and ResourceLimitException
     * @throws LexerException     when $pattern does not tokenize, e.g. a /u body that is not valid UTF-8
     *
     * @return TranspileResult The dialect's pattern, with its literal and constructor spellings
     */
    public function transpile(string $pattern, string $target, ?TranspileOptions $options = null): TranspileResult
    {
        $options ??= new TranspileOptions();
        $dialect = $this->targets->get($target);
        $ast = $this->regex->parse($pattern);

        $context = new TranspileContext($pattern, $ast->flags, $options);
        $compiled = $dialect->compile($ast, $context);
        $flags = $dialect->mapFlags($ast->flags, $context);

        return new TranspileResult(
            $pattern,
            $dialect->getName(),
            $compiled,
            $flags,
            $dialect->formatLiteral($compiled, $flags, $context),
            $dialect->formatConstructor($compiled, $flags, $context),
            $context->getWarnings(),
            $context->getNotes(),
        );
    }
}
