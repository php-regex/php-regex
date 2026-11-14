<?php

declare(strict_types=1);

namespace PHPRegex\Parser\Printer;

/**
 * The FormatRefusal enum as a class of shared instances: FormatRefusal::NewlineConvention() returns
 * the same instance at each call.
 */
final class FormatRefusal implements \PHPRegex\Parser\BackedEnum
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

    public static function NewlineConvention(): self
    {
        return self::$instances['NewlineConvention'] ??= new self('NewlineConvention', 'newline_convention');
    }

    public static function SpacedQuantifier(): self
    {
        return self::$instances['SpacedQuantifier'] ??= new self('SpacedQuantifier', 'spaced_quantifier');
    }

    public static function ExtendedModeSwitchedOff(): self
    {
        return self::$instances['ExtendedModeSwitchedOff'] ??= new self('ExtendedModeSwitchedOff', 'extended_mode_switched_off');
    }

    public static function LineBreakInName(): self
    {
        return self::$instances['LineBreakInName'] ??= new self('LineBreakInName', 'line_break_in_name');
    }

    public static function ParenthesisInComment(): self
    {
        return self::$instances['ParenthesisInComment'] ??= new self('ParenthesisInComment', 'parenthesis_in_comment');
    }

    public static function DelimiterMisread(): self
    {
        return self::$instances['DelimiterMisread'] ??= new self('DelimiterMisread', 'delimiter_misread');
    }

    /**
     * @return list<self>
     */
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
        $case = self::tryFrom($value);
        if (null === $case) {
            throw new \ValueError('"'.$value.'" is not a valid backing value for enum PHPRegex\\Parser\\Printer\\FormatRefusal');
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

    public function note(): string
    {
        $perCase = [
            'NewlineConvention' => 'left as written: under (*CR), (*CRLF) or (*NUL) a # comment does not end at a line feed, so the pattern cannot be laid out over lines.',
            'SpacedQuantifier' => 'left as written: a quantifier written with spaces, such as a{ 1,2 }, means something else before PCRE2 10.43 once x is added or dropped.',
            'ExtendedModeSwitchedOff' => 'left as written: (?-x) or (?^) turns x off at the top level, where a line break would be a literal.',
            'LineBreakInName' => 'left as written: a line break inside a name, such as (*MARK:…), has no escape, so it could not be told from layout.',
            'ParenthesisInComment' => 'left as written: a # comment holds the ")" that balances the delimiter, and no (?#…) comment can hold it.',
            'DelimiterMisread' => 'left as written: a \\c\\ hides a character of the delimiter from PHP, which laid out it would read, ending the pattern elsewhere.',
        ];

        return $perCase[$this->name];
    }
}
