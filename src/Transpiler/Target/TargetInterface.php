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

namespace PHPRegex\Transpiler\Target;

use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Transpiler\TranspileContext;

/**
 * Contract for transpiling a Regex AST into a target dialect.
 */
interface TargetInterface
{
    public function getName(): string;

    /**
     * @return array<int, string>
     */
    public function getAliases(): array;

    public function getDefaultDelimiter(): string;

    public function compile(RegexNode $ast, TranspileContext $context): string;

    public function mapFlags(string $flags, TranspileContext $context): string;

    public function formatLiteral(string $pattern, string $flags, TranspileContext $context): string;

    public function formatConstructor(string $pattern, string $flags, TranspileContext $context): string;
}
