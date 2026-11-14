<?php

declare(strict_types=1);

namespace PHPRegex\Parser\Node;

/**
 * The QuantifierType enum as a class of shared instances: QuantifierType::Greedy() returns
 * the same instance at each call.
 */
final class QuantifierType implements \PHPRegex\Parser\BackedEnum
{
    /** @var array<string, self> */
    private static array $instances = [];

    /** @var array<int|string, self>|null */
    private static ?array $byValue = null;

    public string $name;

    public string $value;

    private function __construct(string $name, string $value)
    {
        $this->name = $name;
        $this->value = $value;
    }

    public static function Greedy(): self
    {
        return self::$instances['Greedy'] ??= new self('Greedy', 'greedy');
    }

    public static function Lazy(): self
    {
        return self::$instances['Lazy'] ??= new self('Lazy', 'lazy');
    }

    public static function Possessive(): self
    {
        return self::$instances['Possessive'] ??= new self('Possessive', 'possessive');
    }

    /**
     * @return list<self>
     */
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
        $case = self::tryFrom($value);
        if (null === $case) {
            throw new \ValueError('"'.$value.'" is not a valid backing value for enum PHPRegex\\Parser\\Node\\QuantifierType');
        }

        return $case;
    }

    public static function tryFrom(string $value): ?self
    {
        if (null === self::$byValue) {
            self::$byValue = [];
            foreach (self::cases() as $case) {
                self::$byValue[$case->value] = $case;
            }
        }

        return self::$byValue[$value] ?? null;
    }
}
