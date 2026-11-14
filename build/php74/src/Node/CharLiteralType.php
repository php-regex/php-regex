<?php

declare(strict_types=1);

namespace PHPRegex\Parser\Node;

/**
 * The CharLiteralType enum as a class of shared instances: CharLiteralType::Unicode() returns
 * the same instance at each call.
 */
final class CharLiteralType implements \PHPRegex\Parser\BackedEnum
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

    public static function Unicode(): self
    {
        return self::$instances['Unicode'] ??= new self('Unicode', 'unicode');
    }

    public static function UnicodeNamed(): self
    {
        return self::$instances['UnicodeNamed'] ??= new self('UnicodeNamed', 'unicode_named');
    }

    public static function Octal(): self
    {
        return self::$instances['Octal'] ??= new self('Octal', 'octal');
    }

    public static function OctalLegacy(): self
    {
        return self::$instances['OctalLegacy'] ??= new self('OctalLegacy', 'octal_legacy');
    }

    /**
     * @return list<self>
     */
    public static function cases(): array
    {
        return [
            self::Unicode(),
            self::UnicodeNamed(),
            self::Octal(),
            self::OctalLegacy(),
        ];
    }

    public static function from(string $value): self
    {
        $case = self::tryFrom($value);
        if (null === $case) {
            throw new \ValueError('"'.$value.'" is not a valid backing value for enum PHPRegex\\Parser\\Node\\CharLiteralType');
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

    public function label(): string
    {
        $perCase = [
            'Unicode' => 'Unicode',
            'UnicodeNamed' => 'Unicode named',
            'Octal' => 'Octal',
            'OctalLegacy' => 'Legacy Octal',
        ];

        return $perCase[$this->name];
    }
}
