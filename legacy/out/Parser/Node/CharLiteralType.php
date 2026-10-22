<?php

declare(strict_types=1);

namespace PHPRegex\Parser\Node;

/**
 * Cached-instance twin of the CharLiteralType enum: GroupType::Capturing()
 * returns one shared instance, so identity comparisons, ->value and
 * native type hints keep the enum's semantics on PHP 7.4.
 */
final class CharLiteralType
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

    public static function Unicode(): self
    {
        if (!isset(self::$instances['Unicode'])) {
            self::$instances['Unicode'] = new self('Unicode', 'unicode');
        }

        return self::$instances['Unicode'];
    }

    public static function UnicodeNamed(): self
    {
        if (!isset(self::$instances['UnicodeNamed'])) {
            self::$instances['UnicodeNamed'] = new self('UnicodeNamed', 'unicode_named');
        }

        return self::$instances['UnicodeNamed'];
    }

    public static function Octal(): self
    {
        if (!isset(self::$instances['Octal'])) {
            self::$instances['Octal'] = new self('Octal', 'octal');
        }

        return self::$instances['Octal'];
    }

    public static function OctalLegacy(): self
    {
        if (!isset(self::$instances['OctalLegacy'])) {
            self::$instances['OctalLegacy'] = new self('OctalLegacy', 'octal_legacy');
        }

        return self::$instances['OctalLegacy'];
    }

    /** @return list<self> */
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
        foreach (self::cases() as $case) {
            if ($case->value === $value) {
                return $case;
            }
        }

        throw new \InvalidArgumentException(sprintf('Unknown value %s for CharLiteralType.', $value));
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

    public function label(): string
    {
        $perCase = array (
  'Unicode' => 'Unicode',
  'UnicodeNamed' => 'Unicode named',
  'Octal' => 'Octal',
  'OctalLegacy' => 'Legacy Octal',
);

        return $perCase[$this->name];
    }
}
