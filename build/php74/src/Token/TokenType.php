<?php

declare(strict_types=1);

namespace PHPRegex\Parser\Token;

/**
 * The TokenType enum as a class of shared instances: TokenType::Literal() returns
 * the same instance at each call.
 */
final class TokenType implements \PHPRegex\Parser\BackedEnum
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

    public static function Literal(): self
    {
        return self::$instances['Literal'] ??= new self('Literal', 'literal');
    }

    public static function CharType(): self
    {
        return self::$instances['CharType'] ??= new self('CharType', 'char_type');
    }

    public static function GroupOpen(): self
    {
        return self::$instances['GroupOpen'] ??= new self('GroupOpen', 'group_open');
    }

    public static function GroupClose(): self
    {
        return self::$instances['GroupClose'] ??= new self('GroupClose', 'group_close');
    }

    public static function GroupModifierOpen(): self
    {
        return self::$instances['GroupModifierOpen'] ??= new self('GroupModifierOpen', 'group_modifier_open');
    }

    public static function CharClassOpen(): self
    {
        return self::$instances['CharClassOpen'] ??= new self('CharClassOpen', 'char_class_open');
    }

    public static function CharClassClose(): self
    {
        return self::$instances['CharClassClose'] ??= new self('CharClassClose', 'char_class_close');
    }

    public static function Quantifier(): self
    {
        return self::$instances['Quantifier'] ??= new self('Quantifier', 'quantifier');
    }

    public static function Alternation(): self
    {
        return self::$instances['Alternation'] ??= new self('Alternation', 'alternation');
    }

    public static function Dot(): self
    {
        return self::$instances['Dot'] ??= new self('Dot', 'dot');
    }

    public static function Anchor(): self
    {
        return self::$instances['Anchor'] ??= new self('Anchor', 'anchor');
    }

    public static function Eof(): self
    {
        return self::$instances['Eof'] ??= new self('Eof', 'eof');
    }

    public static function Range(): self
    {
        return self::$instances['Range'] ??= new self('Range', 'range');
    }

    public static function Negation(): self
    {
        return self::$instances['Negation'] ??= new self('Negation', 'negation');
    }

    public static function Backref(): self
    {
        return self::$instances['Backref'] ??= new self('Backref', 'backref');
    }

    public static function Unicode(): self
    {
        return self::$instances['Unicode'] ??= new self('Unicode', 'unicode');
    }

    public static function PosixClass(): self
    {
        return self::$instances['PosixClass'] ??= new self('PosixClass', 'posix_class');
    }

    public static function Assertion(): self
    {
        return self::$instances['Assertion'] ??= new self('Assertion', 'assertion');
    }

    public static function UnicodeProp(): self
    {
        return self::$instances['UnicodeProp'] ??= new self('UnicodeProp', 'unicode_prop');
    }

    public static function Octal(): self
    {
        return self::$instances['Octal'] ??= new self('Octal', 'octal');
    }

    public static function OctalLegacy(): self
    {
        return self::$instances['OctalLegacy'] ??= new self('OctalLegacy', 'octal_legacy');
    }

    public static function CommentOpen(): self
    {
        return self::$instances['CommentOpen'] ??= new self('CommentOpen', 'comment_open');
    }

    public static function PcreVerb(): self
    {
        return self::$instances['PcreVerb'] ??= new self('PcreVerb', 'pcre_verb');
    }

    public static function GReference(): self
    {
        return self::$instances['GReference'] ??= new self('GReference', 'g_reference');
    }

    public static function Keep(): self
    {
        return self::$instances['Keep'] ??= new self('Keep', 'keep');
    }

    public static function LiteralEscaped(): self
    {
        return self::$instances['LiteralEscaped'] ??= new self('LiteralEscaped', 'literal_escaped');
    }

    public static function QuoteModeStart(): self
    {
        return self::$instances['QuoteModeStart'] ??= new self('QuoteModeStart', 'quote_mode_start');
    }

    public static function QuoteModeEnd(): self
    {
        return self::$instances['QuoteModeEnd'] ??= new self('QuoteModeEnd', 'quote_mode_end');
    }

    public static function Callout(): self
    {
        return self::$instances['Callout'] ??= new self('Callout', 'callout');
    }

    public static function UnicodeNamed(): self
    {
        return self::$instances['UnicodeNamed'] ??= new self('UnicodeNamed', 'unicode_named');
    }

    public static function ControlChar(): self
    {
        return self::$instances['ControlChar'] ??= new self('ControlChar', 'control_char');
    }

    public static function ExtendedClass(): self
    {
        return self::$instances['ExtendedClass'] ??= new self('ExtendedClass', 'extended_class');
    }

    /**
     * @return list<self>
     */
    public static function cases(): array
    {
        return [
            self::Literal(),
            self::CharType(),
            self::GroupOpen(),
            self::GroupClose(),
            self::GroupModifierOpen(),
            self::CharClassOpen(),
            self::CharClassClose(),
            self::Quantifier(),
            self::Alternation(),
            self::Dot(),
            self::Anchor(),
            self::Eof(),
            self::Range(),
            self::Negation(),
            self::Backref(),
            self::Unicode(),
            self::PosixClass(),
            self::Assertion(),
            self::UnicodeProp(),
            self::Octal(),
            self::OctalLegacy(),
            self::CommentOpen(),
            self::PcreVerb(),
            self::GReference(),
            self::Keep(),
            self::LiteralEscaped(),
            self::QuoteModeStart(),
            self::QuoteModeEnd(),
            self::Callout(),
            self::UnicodeNamed(),
            self::ControlChar(),
            self::ExtendedClass(),
        ];
    }

    public static function from(string $value): self
    {
        $case = self::tryFrom($value);
        if (null === $case) {
            throw new \ValueError('"'.$value.'" is not a valid backing value for enum PHPRegex\\Parser\\Token\\TokenType');
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
