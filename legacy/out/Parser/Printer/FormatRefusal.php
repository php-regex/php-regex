<?php

declare(strict_types=1);

namespace PHPRegex\Parser\Printer;

/**
 * Cached-instance twin of the FormatRefusal enum: GroupType::Capturing()
 * returns one shared instance, so identity comparisons, ->value and
 * native type hints keep the enum's semantics on PHP 7.4.
 */
final class FormatRefusal
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

    public static function NewlineConvention(): self
    {
        if (!isset(self::$instances['NewlineConvention'])) {
            self::$instances['NewlineConvention'] = new self('NewlineConvention', 'newline_convention');
        }

        return self::$instances['NewlineConvention'];
    }

    public static function SpacedQuantifier(): self
    {
        if (!isset(self::$instances['SpacedQuantifier'])) {
            self::$instances['SpacedQuantifier'] = new self('SpacedQuantifier', 'spaced_quantifier');
        }

        return self::$instances['SpacedQuantifier'];
    }

    public static function ExtendedModeSwitchedOff(): self
    {
        if (!isset(self::$instances['ExtendedModeSwitchedOff'])) {
            self::$instances['ExtendedModeSwitchedOff'] = new self('ExtendedModeSwitchedOff', 'extended_mode_switched_off');
        }

        return self::$instances['ExtendedModeSwitchedOff'];
    }

    public static function LineBreakInName(): self
    {
        if (!isset(self::$instances['LineBreakInName'])) {
            self::$instances['LineBreakInName'] = new self('LineBreakInName', 'line_break_in_name');
        }

        return self::$instances['LineBreakInName'];
    }

    public static function ParenthesisInComment(): self
    {
        if (!isset(self::$instances['ParenthesisInComment'])) {
            self::$instances['ParenthesisInComment'] = new self('ParenthesisInComment', 'parenthesis_in_comment');
        }

        return self::$instances['ParenthesisInComment'];
    }

    public static function DelimiterMisread(): self
    {
        if (!isset(self::$instances['DelimiterMisread'])) {
            self::$instances['DelimiterMisread'] = new self('DelimiterMisread', 'delimiter_misread');
        }

        return self::$instances['DelimiterMisread'];
    }

    /** @return list<self> */
    public static function cases(): array
    {
        return [
            self::NewlineConvention(),
            self::SpacedQuantifier(),
            self::ExtendedModeSwitchedOff(),
            self::LineBreakInName(),
            self::ParenthesisInComment(),
            self::DelimiterMisread(),
        ];
    }

    public static function from(string $value): self
    {
        foreach (self::cases() as $case) {
            if ($case->value === $value) {
                return $case;
            }
        }

        throw new \InvalidArgumentException(sprintf('Unknown value %s for FormatRefusal.', $value));
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
