<?php

declare(strict_types=1);

namespace PHPRegex\Parser\Node;

/**
 * The ClassSetOperator enum as a class of shared instances: ClassSetOperator::Union() returns
 * the same instance at each call.
 */
final class ClassSetOperator implements \PHPRegex\Parser\BackedEnum
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

    public static function Union(): self
    {
        return self::$instances['Union'] ??= new self('Union', 'union');
    }

    public static function Intersection(): self
    {
        return self::$instances['Intersection'] ??= new self('Intersection', 'intersection');
    }

    public static function Difference(): self
    {
        return self::$instances['Difference'] ??= new self('Difference', 'difference');
    }

    public static function SymmetricDifference(): self
    {
        return self::$instances['SymmetricDifference'] ??= new self('SymmetricDifference', 'symmetric_difference');
    }

    public static function Complement(): self
    {
        return self::$instances['Complement'] ??= new self('Complement', 'complement');
    }

    /**
     * @return list<self>
     */
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
        $case = self::tryFrom($value);
        if (null === $case) {
            throw new \ValueError('"'.$value.'" is not a valid backing value for enum PHPRegex\\Parser\\Node\\ClassSetOperator');
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
