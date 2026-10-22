<?php

declare(strict_types=1);

namespace PHPRegex\Parser\Node;

/**
 * Cached-instance twin of the GroupType enum: GroupType::Capturing()
 * returns one shared instance, so identity comparisons, ->value and
 * native type hints keep the enum's semantics on PHP 7.4.
 */
final class GroupType
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

    public static function Capturing(): self
    {
        if (!isset(self::$instances['Capturing'])) {
            self::$instances['Capturing'] = new self('Capturing', 'capturing');
        }

        return self::$instances['Capturing'];
    }

    public static function NonCapturing(): self
    {
        if (!isset(self::$instances['NonCapturing'])) {
            self::$instances['NonCapturing'] = new self('NonCapturing', 'non_capturing');
        }

        return self::$instances['NonCapturing'];
    }

    public static function Named(): self
    {
        if (!isset(self::$instances['Named'])) {
            self::$instances['Named'] = new self('Named', 'named');
        }

        return self::$instances['Named'];
    }

    public static function LookaheadPositive(): self
    {
        if (!isset(self::$instances['LookaheadPositive'])) {
            self::$instances['LookaheadPositive'] = new self('LookaheadPositive', 'lookahead_positive');
        }

        return self::$instances['LookaheadPositive'];
    }

    public static function LookaheadNegative(): self
    {
        if (!isset(self::$instances['LookaheadNegative'])) {
            self::$instances['LookaheadNegative'] = new self('LookaheadNegative', 'lookahead_negative');
        }

        return self::$instances['LookaheadNegative'];
    }

    public static function LookbehindPositive(): self
    {
        if (!isset(self::$instances['LookbehindPositive'])) {
            self::$instances['LookbehindPositive'] = new self('LookbehindPositive', 'lookbehind_positive');
        }

        return self::$instances['LookbehindPositive'];
    }

    public static function LookbehindNegative(): self
    {
        if (!isset(self::$instances['LookbehindNegative'])) {
            self::$instances['LookbehindNegative'] = new self('LookbehindNegative', 'lookbehind_negative');
        }

        return self::$instances['LookbehindNegative'];
    }

    public static function InlineFlags(): self
    {
        if (!isset(self::$instances['InlineFlags'])) {
            self::$instances['InlineFlags'] = new self('InlineFlags', 'inline_flags');
        }

        return self::$instances['InlineFlags'];
    }

    public static function Atomic(): self
    {
        if (!isset(self::$instances['Atomic'])) {
            self::$instances['Atomic'] = new self('Atomic', 'atomic');
        }

        return self::$instances['Atomic'];
    }

    public static function BranchReset(): self
    {
        if (!isset(self::$instances['BranchReset'])) {
            self::$instances['BranchReset'] = new self('BranchReset', 'branch_reset');
        }

        return self::$instances['BranchReset'];
    }

    public static function ScanSubstring(): self
    {
        if (!isset(self::$instances['ScanSubstring'])) {
            self::$instances['ScanSubstring'] = new self('ScanSubstring', 'scan_substring');
        }

        return self::$instances['ScanSubstring'];
    }

    /** @return list<self> */
    public static function cases(): array
    {
        return [
            self::Capturing(),
            self::NonCapturing(),
            self::Named(),
            self::LookaheadPositive(),
            self::LookaheadNegative(),
            self::LookbehindPositive(),
            self::LookbehindNegative(),
            self::InlineFlags(),
            self::Atomic(),
            self::BranchReset(),
            self::ScanSubstring(),
        ];
    }

    public static function from(string $value): self
    {
        foreach (self::cases() as $case) {
            if ($case->value === $value) {
                return $case;
            }
        }

        throw new \InvalidArgumentException(sprintf('Unknown value %s for GroupType.', $value));
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
