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

namespace PHPRegex\Linter\Exception;

use PHPRegex\Parser\Exception\ExceptionInterface;

/**
 * A rules map given to PatternLinter names a rule the linter does not run:
 * a typo would otherwise turn nothing on or off without a word. The message
 * names the rule it most likely means.
 */
final class UnknownLintRuleException extends \InvalidArgumentException implements ExceptionInterface
{
    /**
     * @param string $ruleId the identifier the map names, as written
     */
    public function __construct(public readonly string $ruleId, ?string $suggestion = null)
    {
        parent::__construct(\sprintf('Unknown lint rule "%s".%s', $ruleId, null === $suggestion ? '' : \sprintf(' Did you mean "%s"?', $suggestion)));
    }
}
