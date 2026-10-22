<?php

declare(strict_types=1);

namespace PHPRegex\Parser;

/**
 * Cached-instance twin of the BsrConvention enum: GroupType::Capturing()
 * returns one shared instance, so identity comparisons, ->value and
 * native type hints keep the enum's semantics on PHP 7.4.
 */
final class BsrConvention
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

    public static function AnyCrLf(): self
    {
        if (!isset(self::$instances['AnyCrLf'])) {
            self::$instances['AnyCrLf'] = new self('AnyCrLf', 'ANYCRLF');
        }

        return self::$instances['AnyCrLf'];
    }

    public static function Unicode(): self
    {
        if (!isset(self::$instances['Unicode'])) {
            self::$instances['Unicode'] = new self('Unicode', 'UNICODE');
        }

        return self::$instances['Unicode'];
    }

    /** @return list<self> */
    public static function cases(): array
    {
        return [
            self::AnyCrLf(),
            self::Unicode(),
        ];
    }

    public static function from(string $value): self
    {
        foreach (self::cases() as $case) {
            if ($case->value === $value) {
                return $case;
            }
        }

        throw new \InvalidArgumentException(sprintf('Unknown value %s for BsrConvention.', $value));
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
