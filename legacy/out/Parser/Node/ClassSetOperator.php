<?php

declare(strict_types=1);

namespace PHPRegex\Parser\Node;

/**
 * Cached-instance twin of the ClassSetOperator enum: GroupType::Capturing()
 * returns one shared instance, so identity comparisons, ->value and
 * native type hints keep the enum's semantics on PHP 7.4.
 */
final class ClassSetOperator
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

    public static function Union(): self
    {
        if (!isset(self::$instances['Union'])) {
            self::$instances['Union'] = new self('Union', 'union');
        }

        return self::$instances['Union'];
    }

    public static function Intersection(): self
    {
        if (!isset(self::$instances['Intersection'])) {
            self::$instances['Intersection'] = new self('Intersection', 'intersection');
        }

        return self::$instances['Intersection'];
    }

    public static function Difference(): self
    {
        if (!isset(self::$instances['Difference'])) {
            self::$instances['Difference'] = new self('Difference', 'difference');
        }

        return self::$instances['Difference'];
    }

    public static function SymmetricDifference(): self
    {
        if (!isset(self::$instances['SymmetricDifference'])) {
            self::$instances['SymmetricDifference'] = new self('SymmetricDifference', 'symmetric_difference');
        }

        return self::$instances['SymmetricDifference'];
    }

    public static function Complement(): self
    {
        if (!isset(self::$instances['Complement'])) {
            self::$instances['Complement'] = new self('Complement', 'complement');
        }

        return self::$instances['Complement'];
    }

    /** @return list<self> */
    public static function cases(): array
    {
        return [
            self::Union(),
            self::Intersection(),
            self::Difference(),
            self::SymmetricDifference(),
            self::Complement(),
        ];
    }

    public static function from(string $value): self
    {
        foreach (self::cases() as $case) {
            if ($case->value === $value) {
                return $case;
            }
        }

        throw new \InvalidArgumentException(sprintf('Unknown value %s for ClassSetOperator.', $value));
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
