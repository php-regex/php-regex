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

namespace PHPRegex\Symfony\Validator\Constraints;

use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Redos\RedosSeverity;
use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint;

/**
 * Validates that a value is a regular expression PCRE accepts, written as
 * preg_match() takes it: delimiters and flags included.
 *
 * The verdict is the one `regex validate` gives, with the bundle's limits.
 * With $redos, a pattern whose ReDoS severity reaches $redosThreshold is
 * refused too. Null and the empty string are valid: use NotBlank to refuse
 * them.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class ValidRegex extends Constraint
{
    public const INVALID_ERROR = '510ac2d1-0769-45d2-a1f7-2a40fa8ceb07';
    public const REDOS_ERROR = '427bd7c7-0a12-44d3-918a-71d7eef8e007';

    protected const ERROR_NAMES = [
        self::INVALID_ERROR => 'INVALID_ERROR',
        self::REDOS_ERROR => 'REDOS_ERROR',
    ];

    /**
     * Parameters: {{ value }}, {{ error }} (the library's message) and
     * {{ hint }} (how to fix it, or an empty string).
     */
    public string $message = 'This value is not a valid regular expression: {{ error }}';

    /**
     * Parameters: {{ value }} and {{ severity }}.
     */
    public string $redosMessage = 'This regular expression is open to catastrophic backtracking (ReDoS risk: {{ severity }}).';

    public RedosSeverity $redosThreshold;

    /**
     * @param RedosSeverity|string $redosThreshold low, medium, high or critical
     * @param list<string>|null    $groups
     *
     * @throws InvalidRegexOptionException when the threshold names no severity to report from
     */
    #[HasNamedArguments]
    public function __construct(
        public bool $redos = false,
        RedosSeverity|string $redosThreshold = RedosSeverity::High,
        ?string $message = null,
        ?string $redosMessage = null,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(null, $groups, $payload);
        $this->redosThreshold = RedosSeverity::fromConfig($redosThreshold instanceof RedosSeverity ? $redosThreshold->value : $redosThreshold);
        $this->message = $message ?? $this->message;
        $this->redosMessage = $redosMessage ?? $this->redosMessage;
    }
}
