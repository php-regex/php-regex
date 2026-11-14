<?php

declare(strict_types=1);

namespace PHPRegex\Parser\Analysis;

/**
 * The LiteralConfidence enum as a class of shared instances: LiteralConfidence::Low() returns
 * the same instance at each call.
 */
final class LiteralConfidence implements \PHPRegex\Parser\BackedEnum
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

    public static function Low(): self
    {
        return self::$instances['Low'] ??= new self('Low', 'low');
    }

    public static function Medium(): self
    {
        return self::$instances['Medium'] ??= new self('Medium', 'medium');
    }

    public static function High(): self
    {
        return self::$instances['High'] ??= new self('High', 'high');
    }

    /**
     * @return list<self>
     */
    public static function cases(): array
    {
        return [
            self::Low(),
            self::Medium(),
            self::High(),
        ];
    }

    public static function from(string $value): self
    {
        $case = self::tryFrom($value);
        if (null === $case) {
            throw new \ValueError('"'.$value.'" is not a valid backing value for enum PHPRegex\\Parser\\Analysis\\LiteralConfidence');
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
