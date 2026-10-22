<?php

declare(strict_types=1);

namespace PHPRegex\Parser\Validation;

/**
 * Cached-instance twin of the ValidationErrorCategory enum: GroupType::Capturing()
 * returns one shared instance, so identity comparisons, ->value and
 * native type hints keep the enum's semantics on PHP 7.4.
 */
final class ValidationErrorCategory
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

    public static function Syntax(): self
    {
        if (!isset(self::$instances['Syntax'])) {
            self::$instances['Syntax'] = new self('Syntax', 'syntax');
        }

        return self::$instances['Syntax'];
    }

    public static function Semantic(): self
    {
        if (!isset(self::$instances['Semantic'])) {
            self::$instances['Semantic'] = new self('Semantic', 'semantic');
        }

        return self::$instances['Semantic'];
    }

    public static function PcreRuntime(): self
    {
        if (!isset(self::$instances['PcreRuntime'])) {
            self::$instances['PcreRuntime'] = new self('PcreRuntime', 'pcre-runtime');
        }

        return self::$instances['PcreRuntime'];
    }

    public static function Limit(): self
    {
        if (!isset(self::$instances['Limit'])) {
            self::$instances['Limit'] = new self('Limit', 'limit');
        }

        return self::$instances['Limit'];
    }

    /** @return list<self> */
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
        foreach (self::cases() as $case) {
            if ($case->value === $value) {
                return $case;
            }
        }

        throw new \InvalidArgumentException(sprintf('Unknown value %s for ValidationErrorCategory.', $value));
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
