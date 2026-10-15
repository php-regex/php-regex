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

use PHPRegex\Toolkit\Regex;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * Judges a ValidRegex value with the bundle's Regex service, or with a
 * default one when the validator is built outside the container.
 */
final class ValidRegexValidator extends ConstraintValidator
{
    private readonly Regex $regex;

    public function __construct(?Regex $regex = null)
    {
        $this->regex = $regex ?? Regex::create();
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidRegex) {
            throw new UnexpectedTypeException($constraint, ValidRegex::class);
        }

        if (null === $value || '' === $value) {
            return;
        }

        if (!\is_string($value) && !$value instanceof \Stringable) {
            throw new UnexpectedValueException($value, 'string');
        }

        $value = (string) $value;
        $result = $this->regex->validate($value);

        if (!$result->isValid) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ value }}', $this->formatValue($value))
                ->setParameter('{{ error }}', (string) $result->error)
                ->setParameter('{{ hint }}', (string) $result->hint)
                ->setInvalidValue($value)
                ->setCode(ValidRegex::INVALID_ERROR)
                ->addViolation();

            return;
        }

        if (!$constraint->redos) {
            return;
        }

        $analysis = $this->regex->redos($value);

        if ($analysis->exceedsThreshold($constraint->redosThreshold)) {
            $this->context->buildViolation($constraint->redosMessage)
                ->setParameter('{{ value }}', $this->formatValue($value))
                ->setParameter('{{ severity }}', $analysis->severity->value)
                ->setInvalidValue($value)
                ->setCode(ValidRegex::REDOS_ERROR)
                ->addViolation();
        }
    }
}
