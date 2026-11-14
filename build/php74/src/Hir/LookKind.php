<?php

declare(strict_types=1);

namespace PHPRegex\Parser\Hir;

/**
 * The LookKind enum as a class of shared instances: LookKind::Ahead() returns
 * the same instance at each call.
 */
final class LookKind implements \PHPRegex\Parser\UnitEnum
{
    /** @var array<string, self> */
    private static array $instances = [];

    public string $name;

    private function __construct(string $name)
    {
        $this->name = $name;
    }

    public static function Ahead(): self
    {
        return self::$instances['Ahead'] ??= new self('Ahead');
    }

    public static function NegativeAhead(): self
    {
        return self::$instances['NegativeAhead'] ??= new self('NegativeAhead');
    }

    public static function Behind(): self
    {
        return self::$instances['Behind'] ??= new self('Behind');
    }

    public static function NegativeBehind(): self
    {
        return self::$instances['NegativeBehind'] ??= new self('NegativeBehind');
    }

    /**
     * @return list<self>
     */
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
