<?php

declare(strict_types=1);

namespace PHPRegex\Parser\Hir;

/**
 * Cached-instance twin of the LookKind enum: GroupType::Capturing()
 * returns one shared instance, so identity comparisons, ->value and
 * native type hints keep the enum's semantics on PHP 7.4.
 */
final class LookKind
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

    public static function Ahead(): self
    {
        if (!isset(self::$instances['Ahead'])) {
            self::$instances['Ahead'] = new self('Ahead', 'Ahead');
        }

        return self::$instances['Ahead'];
    }

    public static function NegativeAhead(): self
    {
        if (!isset(self::$instances['NegativeAhead'])) {
            self::$instances['NegativeAhead'] = new self('NegativeAhead', 'NegativeAhead');
        }

        return self::$instances['NegativeAhead'];
    }

    public static function Behind(): self
    {
        if (!isset(self::$instances['Behind'])) {
            self::$instances['Behind'] = new self('Behind', 'Behind');
        }

        return self::$instances['Behind'];
    }

    public static function NegativeBehind(): self
    {
        if (!isset(self::$instances['NegativeBehind'])) {
            self::$instances['NegativeBehind'] = new self('NegativeBehind', 'NegativeBehind');
        }

        return self::$instances['NegativeBehind'];
    }

    /** @return list<self> */
    public static function cases(): array
    {
        return [
            self::Ahead(),
            self::NegativeAhead(),
            self::Behind(),
            self::NegativeBehind(),
        ];
    }
}
