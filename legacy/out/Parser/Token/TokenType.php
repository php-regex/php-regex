<?php

declare(strict_types=1);

namespace PHPRegex\Parser\Token;

/**
 * Cached-instance twin of the TokenType enum: GroupType::Capturing()
 * returns one shared instance, so identity comparisons, ->value and
 * native type hints keep the enum's semantics on PHP 7.4.
 */
final class TokenType
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

    public static function Literal(): self
    {
        if (!isset(self::$instances['Literal'])) {
            self::$instances['Literal'] = new self('Literal', 'literal');
        }

        return self::$instances['Literal'];
    }

    public static function CharType(): self
    {
        if (!isset(self::$instances['CharType'])) {
            self::$instances['CharType'] = new self('CharType', 'char_type');
        }

        return self::$instances['CharType'];
    }

    public static function GroupOpen(): self
    {
        if (!isset(self::$instances['GroupOpen'])) {
            self::$instances['GroupOpen'] = new self('GroupOpen', 'group_open');
        }

        return self::$instances['GroupOpen'];
    }

    public static function GroupClose(): self
    {
        if (!isset(self::$instances['GroupClose'])) {
            self::$instances['GroupClose'] = new self('GroupClose', 'group_close');
        }

        return self::$instances['GroupClose'];
    }

    public static function GroupModifierOpen(): self
    {
        if (!isset(self::$instances['GroupModifierOpen'])) {
            self::$instances['GroupModifierOpen'] = new self('GroupModifierOpen', 'group_modifier_open');
        }

        return self::$instances['GroupModifierOpen'];
    }

    public static function CharClassOpen(): self
    {
        if (!isset(self::$instances['CharClassOpen'])) {
            self::$instances['CharClassOpen'] = new self('CharClassOpen', 'char_class_open');
        }

        return self::$instances['CharClassOpen'];
    }

    public static function CharClassClose(): self
    {
        if (!isset(self::$instances['CharClassClose'])) {
            self::$instances['CharClassClose'] = new self('CharClassClose', 'char_class_close');
        }

        return self::$instances['CharClassClose'];
    }

    public static function Quantifier(): self
    {
        if (!isset(self::$instances['Quantifier'])) {
            self::$instances['Quantifier'] = new self('Quantifier', 'quantifier');
        }

        return self::$instances['Quantifier'];
    }

    public static function Alternation(): self
    {
        if (!isset(self::$instances['Alternation'])) {
            self::$instances['Alternation'] = new self('Alternation', 'alternation');
        }

        return self::$instances['Alternation'];
    }

    public static function Dot(): self
    {
        if (!isset(self::$instances['Dot'])) {
            self::$instances['Dot'] = new self('Dot', 'dot');
        }

        return self::$instances['Dot'];
    }

    public static function Anchor(): self
    {
        if (!isset(self::$instances['Anchor'])) {
            self::$instances['Anchor'] = new self('Anchor', 'anchor');
        }

        return self::$instances['Anchor'];
    }

    public static function Eof(): self
    {
        if (!isset(self::$instances['Eof'])) {
            self::$instances['Eof'] = new self('Eof', 'eof');
        }

        return self::$instances['Eof'];
    }

    public static function Range(): self
    {
        if (!isset(self::$instances['Range'])) {
            self::$instances['Range'] = new self('Range', 'range');
        }

        return self::$instances['Range'];
    }

    public static function Negation(): self
    {
        if (!isset(self::$instances['Negation'])) {
            self::$instances['Negation'] = new self('Negation', 'negation');
        }

        return self::$instances['Negation'];
    }

    public static function Backref(): self
    {
        if (!isset(self::$instances['Backref'])) {
            self::$instances['Backref'] = new self('Backref', 'backref');
        }

        return self::$instances['Backref'];
    }

    public static function Unicode(): self
    {
        if (!isset(self::$instances['Unicode'])) {
            self::$instances['Unicode'] = new self('Unicode', 'unicode');
        }

        return self::$instances['Unicode'];
    }

    public static function PosixClass(): self
    {
        if (!isset(self::$instances['PosixClass'])) {
            self::$instances['PosixClass'] = new self('PosixClass', 'posix_class');
        }

        return self::$instances['PosixClass'];
    }

    public static function Assertion(): self
    {
        if (!isset(self::$instances['Assertion'])) {
            self::$instances['Assertion'] = new self('Assertion', 'assertion');
        }

        return self::$instances['Assertion'];
    }

    public static function UnicodeProp(): self
    {
        if (!isset(self::$instances['UnicodeProp'])) {
            self::$instances['UnicodeProp'] = new self('UnicodeProp', 'unicode_prop');
        }

        return self::$instances['UnicodeProp'];
    }

    public static function Octal(): self
    {
        if (!isset(self::$instances['Octal'])) {
            self::$instances['Octal'] = new self('Octal', 'octal');
        }

        return self::$instances['Octal'];
    }

    public static function OctalLegacy(): self
    {
        if (!isset(self::$instances['OctalLegacy'])) {
            self::$instances['OctalLegacy'] = new self('OctalLegacy', 'octal_legacy');
        }

        return self::$instances['OctalLegacy'];
    }

    public static function CommentOpen(): self
    {
        if (!isset(self::$instances['CommentOpen'])) {
            self::$instances['CommentOpen'] = new self('CommentOpen', 'comment_open');
        }

        return self::$instances['CommentOpen'];
    }

    public static function PcreVerb(): self
    {
        if (!isset(self::$instances['PcreVerb'])) {
            self::$instances['PcreVerb'] = new self('PcreVerb', 'pcre_verb');
        }

        return self::$instances['PcreVerb'];
    }

    public static function GReference(): self
    {
        if (!isset(self::$instances['GReference'])) {
            self::$instances['GReference'] = new self('GReference', 'g_reference');
        }

        return self::$instances['GReference'];
    }

    public static function Keep(): self
    {
        if (!isset(self::$instances['Keep'])) {
            self::$instances['Keep'] = new self('Keep', 'keep');
        }

        return self::$instances['Keep'];
    }

    public static function LiteralEscaped(): self
    {
        if (!isset(self::$instances['LiteralEscaped'])) {
            self::$instances['LiteralEscaped'] = new self('LiteralEscaped', 'literal_escaped');
        }

        return self::$instances['LiteralEscaped'];
    }

    public static function QuoteModeStart(): self
    {
        if (!isset(self::$instances['QuoteModeStart'])) {
            self::$instances['QuoteModeStart'] = new self('QuoteModeStart', 'quote_mode_start');
        }

        return self::$instances['QuoteModeStart'];
    }

    public static function QuoteModeEnd(): self
    {
        if (!isset(self::$instances['QuoteModeEnd'])) {
            self::$instances['QuoteModeEnd'] = new self('QuoteModeEnd', 'quote_mode_end');
        }

        return self::$instances['QuoteModeEnd'];
    }

    public static function Callout(): self
    {
        if (!isset(self::$instances['Callout'])) {
            self::$instances['Callout'] = new self('Callout', 'callout');
        }

        return self::$instances['Callout'];
    }

    public static function UnicodeNamed(): self
    {
        if (!isset(self::$instances['UnicodeNamed'])) {
            self::$instances['UnicodeNamed'] = new self('UnicodeNamed', 'unicode_named');
        }

        return self::$instances['UnicodeNamed'];
    }

    public static function ControlChar(): self
    {
        if (!isset(self::$instances['ControlChar'])) {
            self::$instances['ControlChar'] = new self('ControlChar', 'control_char');
        }

        return self::$instances['ControlChar'];
    }

    public static function ExtendedClass(): self
    {
        if (!isset(self::$instances['ExtendedClass'])) {
            self::$instances['ExtendedClass'] = new self('ExtendedClass', 'extended_class');
        }

        return self::$instances['ExtendedClass'];
    }

    /** @return list<self> */
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
        foreach (self::cases() as $case) {
            if ($case->value === $value) {
                return $case;
            }
        }

        throw new \InvalidArgumentException(sprintf('Unknown value %s for TokenType.', $value));
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
