<?php

declare(strict_types=1);

namespace PHPRegex\Parser;

/**
 * The TraversalAction enum as a class of shared instances: TraversalAction::SkipChildren() returns
 * the same instance at each call.
 */
final class TraversalAction implements UnitEnum
{
    /** @var array<string, self> */
    private static array $instances = [];

    public string $name;

    private function __construct(string $name)
    {
        $this->name = $name;
    }

    public static function SkipChildren(): self
    {
        return self::$instances['SkipChildren'] ??= new self('SkipChildren');
    }

    public static function Stop(): self
    {
        return self::$instances['Stop'] ??= new self('Stop');
    }

    /**
     * @return list<self>
     */
    public static function cases(): array
    {
        return [
            self::SkipChildren(),
            self::Stop(),
        ];
    }
}
