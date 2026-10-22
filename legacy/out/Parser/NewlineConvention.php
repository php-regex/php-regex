<?php

declare(strict_types=1);

namespace PHPRegex\Parser;

/**
 * Cached-instance twin of the NewlineConvention enum: GroupType::Capturing()
 * returns one shared instance, so identity comparisons, ->value and
 * native type hints keep the enum's semantics on PHP 7.4.
 */
final class NewlineConvention
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

    public static function Cr(): self
    {
        if (!isset(self::$instances['Cr'])) {
            self::$instances['Cr'] = new self('Cr', 'CR');
        }

        return self::$instances['Cr'];
    }

    public static function Lf(): self
    {
        if (!isset(self::$instances['Lf'])) {
            self::$instances['Lf'] = new self('Lf', 'LF');
        }

        return self::$instances['Lf'];
    }

    public static function CrLf(): self
    {
        if (!isset(self::$instances['CrLf'])) {
            self::$instances['CrLf'] = new self('CrLf', 'CRLF');
        }

        return self::$instances['CrLf'];
    }

    public static function Any(): self
    {
        if (!isset(self::$instances['Any'])) {
            self::$instances['Any'] = new self('Any', 'ANY');
        }

        return self::$instances['Any'];
    }

    public static function AnyCrLf(): self
    {
        if (!isset(self::$instances['AnyCrLf'])) {
            self::$instances['AnyCrLf'] = new self('AnyCrLf', 'ANYCRLF');
        }

        return self::$instances['AnyCrLf'];
    }

    public static function Nul(): self
    {
        if (!isset(self::$instances['Nul'])) {
            self::$instances['Nul'] = new self('Nul', 'NUL');
        }

        return self::$instances['Nul'];
    }

    /** @return list<self> */
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
        foreach (self::cases() as $case) {
            if ($case->value === $value) {
                return $case;
            }
        }

        throw new \InvalidArgumentException(sprintf('Unknown value %s for NewlineConvention.', $value));
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
}
