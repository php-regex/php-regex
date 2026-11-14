<?php

declare(strict_types=1);

namespace PHPRegex\Parser\Resolution;

/**
 * The ArgumentState enum as a class of shared instances: ArgumentState::Constant() returns
 * the same instance at each call.
 */
final class ArgumentState implements \PHPRegex\Parser\BackedEnum
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

    public static function Constant(): self
    {
        return self::$instances['Constant'] ??= new self('Constant', 'constant');
    }

    public static function FiniteSet(): self
    {
        return self::$instances['FiniteSet'] ??= new self('FiniteSet', 'finite_set');
    }

    public static function QuotedShell(): self
    {
        return self::$instances['QuotedShell'] ??= new self('QuotedShell', 'quoted_shell');
    }

    public static function RawShell(): self
    {
        return self::$instances['RawShell'] ??= new self('RawShell', 'raw_shell');
    }

    public static function Opaque(): self
    {
        return self::$instances['Opaque'] ??= new self('Opaque', 'opaque');
    }

    /**
     * @return list<self>
     */
    public static function cases(): array
    {
        return [
            self::Constant(),
            self::FiniteSet(),
            self::QuotedShell(),
            self::RawShell(),
            self::Opaque(),
        ];
    }

    public static function from(string $value): self
    {
        $case = self::tryFrom($value);
        if (null === $case) {
            throw new \ValueError('"'.$value.'" is not a valid backing value for enum PHPRegex\\Parser\\Resolution\\ArgumentState');
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
