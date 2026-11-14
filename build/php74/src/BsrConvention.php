<?php

declare(strict_types=1);

namespace PHPRegex\Parser;

/**
 * The BsrConvention enum as a class of shared instances: BsrConvention::AnyCrLf() returns
 * the same instance at each call.
 */
final class BsrConvention implements BackedEnum
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

    public static function AnyCrLf(): self
    {
        return self::$instances['AnyCrLf'] ??= new self('AnyCrLf', 'ANYCRLF');
    }

    public static function Unicode(): self
    {
        return self::$instances['Unicode'] ??= new self('Unicode', 'UNICODE');
    }

    /**
     * @return list<self>
     */
    public static function cases(): array
    {
        return [
            self::AnyCrLf(),
            self::Unicode(),
        ];
    }

    public static function from(string $value): self
    {
        $case = self::tryFrom($value);
        if (null === $case) {
            throw new \ValueError('"'.$value.'" is not a valid backing value for enum PHPRegex\\Parser\\BsrConvention');
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
