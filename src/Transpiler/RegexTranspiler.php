<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RegexParser\Transpiler;

use RegexParser\RegexParser;
use RegexParser\Transpiler\Target\TargetRegistry;

/**
 * Transpiles PCRE regex literals to other target dialects.
 */
final readonly class RegexTranspiler
{
    public function __construct(private RegexParser $regex, private TargetRegistry $targets = new TargetRegistry()) {}

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
