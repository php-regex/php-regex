<?php

declare(strict_types=1);

namespace PHPRegex\Parser;

/**
 * The NewlineConvention enum as a class of shared instances: NewlineConvention::Cr() returns
 * the same instance at each call.
 */
final class NewlineConvention implements BackedEnum
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

    public static function Cr(): self
    {
        return self::$instances['Cr'] ??= new self('Cr', 'CR');
    }

    public static function Lf(): self
    {
        return self::$instances['Lf'] ??= new self('Lf', 'LF');
    }

    public static function CrLf(): self
    {
        return self::$instances['CrLf'] ??= new self('CrLf', 'CRLF');
    }

    public static function Any(): self
    {
        return self::$instances['Any'] ??= new self('Any', 'ANY');
    }

    public static function AnyCrLf(): self
    {
        return self::$instances['AnyCrLf'] ??= new self('AnyCrLf', 'ANYCRLF');
    }

    public static function Nul(): self
    {
        return self::$instances['Nul'] ??= new self('Nul', 'NUL');
    }

    /**
     * @return list<self>
     */
    public static function cases(): array
    {
        return [
            self::Cr(),
            self::Lf(),
            self::CrLf(),
            self::Any(),
            self::AnyCrLf(),
            self::Nul(),
        ];
    }

    public static function from(string $value): self
    {
        $case = self::tryFrom($value);
        if (null === $case) {
            throw new \ValueError('"'.$value.'" is not a valid backing value for enum PHPRegex\\Parser\\NewlineConvention');
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
