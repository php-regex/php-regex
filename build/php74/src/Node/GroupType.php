<?php

declare(strict_types=1);

namespace PHPRegex\Parser\Node;

/**
 * The GroupType enum as a class of shared instances: GroupType::Capturing() returns
 * the same instance at each call.
 */
final class GroupType implements \PHPRegex\Parser\BackedEnum
{
    /** @var array<string, self> */
    private static array $instances = [];

    /** @var array<int|string, self>|null */
    private static ?array $byValue = null;

    public string $name;

    public string $value;

    private function __construct(string $name, string $value)
    {
        $this->name = $name;
        $this->value = $value;
    }

    public static function Capturing(): self
    {
        return self::$instances['Capturing'] ??= new self('Capturing', 'capturing');
    }

    public static function NonCapturing(): self
    {
        return self::$instances['NonCapturing'] ??= new self('NonCapturing', 'non_capturing');
    }

    public static function Named(): self
    {
        return self::$instances['Named'] ??= new self('Named', 'named');
    }

    public static function LookaheadPositive(): self
    {
        return self::$instances['LookaheadPositive'] ??= new self('LookaheadPositive', 'lookahead_positive');
    }

    public static function LookaheadNegative(): self
    {
        return self::$instances['LookaheadNegative'] ??= new self('LookaheadNegative', 'lookahead_negative');
    }

    public static function LookbehindPositive(): self
    {
        return self::$instances['LookbehindPositive'] ??= new self('LookbehindPositive', 'lookbehind_positive');
    }

    public static function LookbehindNegative(): self
    {
        return self::$instances['LookbehindNegative'] ??= new self('LookbehindNegative', 'lookbehind_negative');
    }

    public static function InlineFlags(): self
    {
        return self::$instances['InlineFlags'] ??= new self('InlineFlags', 'inline_flags');
    }

    public static function Atomic(): self
    {
        return self::$instances['Atomic'] ??= new self('Atomic', 'atomic');
    }

    public static function BranchReset(): self
    {
        return self::$instances['BranchReset'] ??= new self('BranchReset', 'branch_reset');
    }

    public static function ScanSubstring(): self
    {
        return self::$instances['ScanSubstring'] ??= new self('ScanSubstring', 'scan_substring');
    }

    /**
     * @return list<self>
     */
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
        $case = self::tryFrom($value);
        if (null === $case) {
            throw new \ValueError('"'.$value.'" is not a valid backing value for enum PHPRegex\\Parser\\Node\\GroupType');
        }

        return $case;
    }

    public static function tryFrom(string $value): ?self
    {
        if (null === self::$byValue) {
            self::$byValue = [];
            foreach (self::cases() as $case) {
                self::$byValue[$case->value] = $case;
            }
        }

        return self::$byValue[$value] ?? null;
    }
}
