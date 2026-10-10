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

namespace PHPRegex\Linter\Rule;

use PHPRegex\Parser\Node\NodeInterface;

/**
 * Base class for lint rules that only need node-enter checks.
 *
 * @implements LintRuleInterface<NodeInterface>
 *
 * @internal
 */
abstract class AbstractLintRule implements LintRuleInterface
{
    public function begin(LintContext $context): void {}

    public function finish(LintContext $context): array
    {
        return [];
    }
}
