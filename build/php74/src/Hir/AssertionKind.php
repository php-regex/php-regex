<?php

declare(strict_types=1);

namespace PHPRegex\Parser\Hir;

/**
 * The AssertionKind enum as a class of shared instances: AssertionKind::SubjectStart() returns
 * the same instance at each call.
 */
final class AssertionKind implements \PHPRegex\Parser\UnitEnum
{
    /** @var array<string, self> */
    private static array $instances = [];

    public string $name;

    private function __construct(string $name)
    {
        $this->name = $name;
    }

    public static function SubjectStart(): self
    {
        return self::$instances['SubjectStart'] ??= new self('SubjectStart');
    }

    public static function LineStart(): self
    {
        return self::$instances['LineStart'] ??= new self('LineStart');
    }

    public static function SubjectEnd(): self
    {
        return self::$instances['SubjectEnd'] ??= new self('SubjectEnd');
    }

    public static function EndOrFinalNewline(): self
    {
        return self::$instances['EndOrFinalNewline'] ??= new self('EndOrFinalNewline');
    }

    public static function LineEnd(): self
    {
        return self::$instances['LineEnd'] ??= new self('LineEnd');
    }

    public static function MatchStart(): self
    {
        return self::$instances['MatchStart'] ??= new self('MatchStart');
    }

    public static function WordBoundary(): self
    {
        return self::$instances['WordBoundary'] ??= new self('WordBoundary');
    }

    public static function NotWordBoundary(): self
    {
        return self::$instances['NotWordBoundary'] ??= new self('NotWordBoundary');
    }

    public static function ResetMatchStart(): self
    {
        return self::$instances['ResetMatchStart'] ??= new self('ResetMatchStart');
    }

    /**
     * @return list<self>
     */
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
