<?php

declare(strict_types=1);

namespace PHPRegex\Parser;

/**
 * Cached-instance twin of the TraversalAction enum: GroupType::Capturing()
 * returns one shared instance, so identity comparisons, ->value and
 * native type hints keep the enum's semantics on PHP 7.4.
 */
final class TraversalAction
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

    public static function SkipChildren(): self
    {
        if (!isset(self::$instances['SkipChildren'])) {
            self::$instances['SkipChildren'] = new self('SkipChildren', 'SkipChildren');
        }

        return self::$instances['SkipChildren'];
    }

    public static function Stop(): self
    {
        if (!isset(self::$instances['Stop'])) {
            self::$instances['Stop'] = new self('Stop', 'Stop');
        }

        return self::$instances['Stop'];
    }

    /** @return list<self> */
    public static function cases(): array
    {
        return [
            self::SkipChildren(),
            self::Stop(),
        ];
    }
}
