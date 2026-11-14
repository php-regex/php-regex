<?php

declare(strict_types=1);

namespace PHPRegex\Parser\Analysis;

/**
 * The Participation enum as a class of shared instances: Participation::Always() returns
 * the same instance at each call.
 */
final class Participation implements \PHPRegex\Parser\BackedEnum
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

    public static function Always(): self
    {
        return self::$instances['Always'] ??= new self('Always', 'always');
    }

    public static function MayBeUnset(): self
    {
        return self::$instances['MayBeUnset'] ??= new self('MayBeUnset', 'may_be_unset');
    }

    public static function Never(): self
    {
        return self::$instances['Never'] ??= new self('Never', 'never');
    }

    /**
     * @return list<self>
     */
    public static function cases(): array
    {
        return [
            self::Always(),
            self::MayBeUnset(),
            self::Never(),
        ];
    }

    public static function from(string $value): self
    {
        $case = self::tryFrom($value);
        if (null === $case) {
            throw new \ValueError('"'.$value.'" is not a valid backing value for enum PHPRegex\\Parser\\Analysis\\Participation');
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
