<?php

declare(strict_types=1);

namespace PHPRegex\Parser\Validation;

/**
 * The ValidationErrorCategory enum as a class of shared instances: ValidationErrorCategory::Syntax() returns
 * the same instance at each call.
 */
final class ValidationErrorCategory implements \PHPRegex\Parser\BackedEnum
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

    public static function Syntax(): self
    {
        return self::$instances['Syntax'] ??= new self('Syntax', 'syntax');
    }

    public static function Semantic(): self
    {
        return self::$instances['Semantic'] ??= new self('Semantic', 'semantic');
    }

    public static function PcreRuntime(): self
    {
        return self::$instances['PcreRuntime'] ??= new self('PcreRuntime', 'pcre-runtime');
    }

    public static function Limit(): self
    {
        return self::$instances['Limit'] ??= new self('Limit', 'limit');
    }

    /**
     * @return list<self>
     */
    public static function cases(): array
    {
        return [
            self::Syntax(),
            self::Semantic(),
            self::PcreRuntime(),
            self::Limit(),
        ];
    }

    public static function from(string $value): self
    {
        $case = self::tryFrom($value);
        if (null === $case) {
            throw new \ValueError('"'.$value.'" is not a valid backing value for enum PHPRegex\\Parser\\Validation\\ValidationErrorCategory');
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
