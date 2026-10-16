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

namespace PHPRegex\Laravel\Rules;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Facade;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Toolkit\Regex;

/**
 * Validates that a value is a regular expression PCRE accepts, written as
 * preg_match() takes it: delimiters and flags included.
 *
 * The verdict is the one `regex validate` gives, with the application's
 * Regex service and the limits of config/php-regex.php. With $redos, a
 * pattern whose ReDoS severity reaches $redosThreshold is refused too. Like
 * every rule, it leaves an empty value to "required".
 */
final class ValidRegex implements ValidationRule
{
    private readonly RedosSeverity $redosThreshold;

    /**
     * @param RedosSeverity|string $redosThreshold low, medium, high or critical
     * @param Regex|null           $regex          the service to judge with; the application's when null
     *
     * @throws InvalidRegexOptionException when the threshold names no severity to report from
     */
    public function __construct(
        private readonly bool $redos = false,
        RedosSeverity|string $redosThreshold = RedosSeverity::High,
        private readonly ?Regex $regex = null,
    ) {
        $this->redosThreshold = RedosSeverity::fromConfig($redosThreshold instanceof RedosSeverity ? $redosThreshold->value : $redosThreshold);
    }

    public function validate(string $attribute, mixed $value, \Closure $fail): void
    {
        if (!\is_string($value) && !$value instanceof \Stringable) {
            $fail('The :attribute field must be a string.');

            return;
        }

        $value = (string) $value;
        $regex = $this->regex();
        $result = $regex->validate($value);

        if (!$result->isValid) {
            $fail('The :attribute field must be a valid regular expression: '.($result->error ?? ''));

            return;
        }

        if (!$this->redos) {
            return;
        }

        $analysis = $regex->redos($value);

        if ($analysis->exceedsThreshold($this->redosThreshold)) {
            $fail(\sprintf('The :attribute field must not be open to catastrophic backtracking (ReDoS risk: %s).', $analysis->severity->value));
        }
    }

    private function regex(): Regex
    {
        if (null !== $this->regex) {
            return $this->regex;
        }

        $app = Facade::getFacadeApplication();

        if (null !== $app && $app->bound(Regex::class)) {
            return $app->make(Regex::class);
        }

        return Regex::create();
    }
}
