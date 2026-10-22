<?php

declare(strict_types=1);

namespace PHPRegex\Parser\Hir;

/**
 * Cached-instance twin of the AssertionKind enum: GroupType::Capturing()
 * returns one shared instance, so identity comparisons, ->value and
 * native type hints keep the enum's semantics on PHP 7.4.
 */
final class AssertionKind
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

    public static function SubjectStart(): self
    {
        if (!isset(self::$instances['SubjectStart'])) {
            self::$instances['SubjectStart'] = new self('SubjectStart', 'SubjectStart');
        }

        return self::$instances['SubjectStart'];
    }

    public static function LineStart(): self
    {
        if (!isset(self::$instances['LineStart'])) {
            self::$instances['LineStart'] = new self('LineStart', 'LineStart');
        }

        return self::$instances['LineStart'];
    }

    public static function SubjectEnd(): self
    {
        if (!isset(self::$instances['SubjectEnd'])) {
            self::$instances['SubjectEnd'] = new self('SubjectEnd', 'SubjectEnd');
        }

        return self::$instances['SubjectEnd'];
    }

    public static function EndOrFinalNewline(): self
    {
        if (!isset(self::$instances['EndOrFinalNewline'])) {
            self::$instances['EndOrFinalNewline'] = new self('EndOrFinalNewline', 'EndOrFinalNewline');
        }

        return self::$instances['EndOrFinalNewline'];
    }

    public static function LineEnd(): self
    {
        if (!isset(self::$instances['LineEnd'])) {
            self::$instances['LineEnd'] = new self('LineEnd', 'LineEnd');
        }

        return self::$instances['LineEnd'];
    }

    public static function MatchStart(): self
    {
        if (!isset(self::$instances['MatchStart'])) {
            self::$instances['MatchStart'] = new self('MatchStart', 'MatchStart');
        }

        return self::$instances['MatchStart'];
    }

    public static function WordBoundary(): self
    {
        if (!isset(self::$instances['WordBoundary'])) {
            self::$instances['WordBoundary'] = new self('WordBoundary', 'WordBoundary');
        }

        return self::$instances['WordBoundary'];
    }

    public static function NotWordBoundary(): self
    {
        if (!isset(self::$instances['NotWordBoundary'])) {
            self::$instances['NotWordBoundary'] = new self('NotWordBoundary', 'NotWordBoundary');
        }

        return self::$instances['NotWordBoundary'];
    }

    public static function ResetMatchStart(): self
    {
        if (!isset(self::$instances['ResetMatchStart'])) {
            self::$instances['ResetMatchStart'] = new self('ResetMatchStart', 'ResetMatchStart');
        }

        return self::$instances['ResetMatchStart'];
    }

    /** @return list<self> */
    public static function cases(): array
    {
        return [
            self::SubjectStart(),
            self::LineStart(),
            self::SubjectEnd(),
            self::EndOrFinalNewline(),
            self::LineEnd(),
            self::MatchStart(),
            self::WordBoundary(),
            self::NotWordBoundary(),
            self::ResetMatchStart(),
        ];
    }
}
