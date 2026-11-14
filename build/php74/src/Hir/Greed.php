<?php

declare(strict_types=1);

namespace PHPRegex\Parser\Hir;

/**
 * The Greed enum as a class of shared instances: Greed::Greedy() returns
 * the same instance at each call.
 */
final class Greed implements \PHPRegex\Parser\UnitEnum
{
    /** @var array<string, self> */
    private static array $instances = [];

    public string $name;

    private function __construct(string $name)
    {
        $this->name = $name;
    }

    public static function Greedy(): self
    {
        return self::$instances['Greedy'] ??= new self('Greedy');
    }

    public static function Lazy(): self
    {
        return self::$instances['Lazy'] ??= new self('Lazy');
    }

    public static function Possessive(): self
    {
        return self::$instances['Possessive'] ??= new self('Possessive');
    }

    /**
     * @return list<self>
     */
    public static function cases(): array
    {
        return [
            self::Greedy(),
            self::Lazy(),
            self::Possessive(),
        ];
    }
}
