<?php

declare(strict_types=1);

namespace PHPRegex\Parser\Node;

/**
 * Cached-instance twin of the QuantifierType enum: GroupType::Capturing()
 * returns one shared instance, so identity comparisons, ->value and
 * native type hints keep the enum's semantics on PHP 7.4.
 */
final class QuantifierType
{
    /** @var array<string, self> */
    private static $instances = [];

    /** @var string */
    public $name;

    /** @var string */
    public $value;

    private function __construct(string $name, string $value)
    {
        $this->name = $name;
        $this->value = $value;
    }

    public static function Greedy(): self
    {
        if (!isset(self::$instances['Greedy'])) {
            self::$instances['Greedy'] = new self('Greedy', 'greedy');
        }

        return self::$instances['Greedy'];
    }

    public static function Lazy(): self
    {
        if (!isset(self::$instances['Lazy'])) {
            self::$instances['Lazy'] = new self('Lazy', 'lazy');
        }

        return self::$instances['Lazy'];
    }

    public static function Possessive(): self
    {
        if (!isset(self::$instances['Possessive'])) {
            self::$instances['Possessive'] = new self('Possessive', 'possessive');
        }

        return self::$instances['Possessive'];
    }

    /** @return list<self> */
    public static function cases(): array
    {
        return [
            self::Greedy(),
            self::Lazy(),
            self::Possessive(),
        ];
    }

    public static function from(string $value): self
    {
        foreach (self::cases() as $case) {
            if ($case->value === $value) {
                return $case;
            }
        }

        throw new \InvalidArgumentException(sprintf('Unknown value %s for QuantifierType.', $value));
    }

    public static function tryFrom(string $value): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->value === $value) {
                return $case;
            }
        }

        return null;
    }
}
