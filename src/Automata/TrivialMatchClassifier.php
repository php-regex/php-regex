<?php

declare(strict_types=1);

/*
 * This file is part of the PHPRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PHPRegex\Automata;

use PHPRegex\Automata\Exception\ComplexityException;
use PHPRegex\Automata\Options\MatchMode;
use PHPRegex\Automata\Options\SolverOptions;
use PHPRegex\Parser\Exception\ExceptionInterface;
use PHPRegex\Parser\Hir\AlternationHir;
use PHPRegex\Parser\Hir\AssertionHir;
use PHPRegex\Parser\Hir\AssertionKind;
use PHPRegex\Parser\Hir\CaptureHir;
use PHPRegex\Parser\Hir\ClassHir;
use PHPRegex\Parser\Hir\ConcatHir;
use PHPRegex\Parser\Hir\EmptyHir;
use PHPRegex\Parser\Hir\Hir;
use PHPRegex\Parser\Hir\HirTranslator;
use PHPRegex\Parser\Hir\LiteralHir;
use PHPRegex\Parser\Hir\RepetitionHir;
use PHPRegex\Parser\RegexParser;

/**
 * Tells when a preg_match() is a string function in disguise: "/^foo/" is
 * str_starts_with(), "/^(?:GET|POST)\z/" an in_array(). The function is
 * named only once the automata prove that preg_match() returns 1 for exactly
 * the subjects it says true for; "/^foo$/" is no ===, as "$" also takes a
 * final newline.
 *
 * Patterns in UTF mode are left alone: on a subject that is not UTF-8,
 * preg_match() fails where a string function answers.
 */
final readonly class TrivialMatchClassifier
{
    private const MAX_LITERALS = 16;

    public function __construct(private ?RegexParser $parser = null, private ?LanguageSolver $solver = null) {}

    /**
     * The string function the pattern behaves as under preg_match(), or null
     * when there is none, or none the automata could prove.
     */
    public function classify(string $pattern): ?TrivialMatch
    {
        $parser = $this->parser ?? RegexParser::create();

        try {
            $ast = $parser->parse($pattern);
        } catch (ExceptionInterface) {
            return null;
        }

        if ($ast->isUnicode()) {
            return null;
        }

        $hir = (new HirTranslator())->translate($ast);
        $parts = $hir instanceof ConcatHir ? $hir->parts : [$hir];

        $first = $parts[0] ?? null;
        $starts = $first instanceof AssertionHir && AssertionKind::SubjectStart === $first->kind;
        if ($starts) {
            array_shift($parts);
        }

        $last = [] === $parts ? null : $parts[\count($parts) - 1];
        $end = $last instanceof AssertionHir && \in_array($last->kind, [AssertionKind::SubjectEnd, AssertionKind::EndOrFinalNewline], true) ? $last->kind : null;
        if (null !== $end) {
            array_pop($parts);
        }

        $literals = $this->product(array_map($this->literals(...), $parts));
        $candidate = null === $literals ? null : self::candidate($literals, $starts, $end);

        return null !== $candidate && $this->proves($pattern, $candidate) ? $candidate : null;
    }

    /**
     * @param list<string> $literals
     */
    private static function candidate(array $literals, bool $starts, ?AssertionKind $end): ?TrivialMatch
    {
        $anchoredEnd = AssertionKind::SubjectEnd === $end;
        // "$" also matches before a newline that ends the subject.
        $orFinalNewline = AssertionKind::EndOrFinalNewline === $end;

        if ($starts && ($anchoredEnd || $orFinalNewline)) {
            $whole = $orFinalNewline ? [...$literals, ...array_map(static fn (string $literal): string => $literal."\n", $literals)] : $literals;

            return match (true) {
                [''] === $whole => new TrivialMatch(TrivialMatchKind::IsEmpty, []),
                1 === \count($whole) => new TrivialMatch(TrivialMatchKind::Equals, $whole),
                default => new TrivialMatch(TrivialMatchKind::OneOf, $whole),
            };
        }

        if (1 !== \count($literals) || '' === $literals[0] || $orFinalNewline) {
            return null;
        }

        return match (true) {
            $starts => new TrivialMatch(TrivialMatchKind::StartsWith, $literals),
            $anchoredEnd => new TrivialMatch(TrivialMatchKind::EndsWith, $literals),
            default => new TrivialMatch(TrivialMatchKind::Contains, $literals),
        };
    }

    /**
     * Whether the automata prove the pattern and the function's own pattern
     * match the same subjects.
     */
    private function proves(string $pattern, TrivialMatch $match): bool
    {
        // Each byte as a hex escape: exact in byte mode, whatever it is.
        $quoted = array_map(static fn (string $literal): string => implode('', array_map(static fn (string $byte): string => \sprintf('\x%02x', \ord($byte)), str_split($literal))), $match->literals);
        $canonical = match ($match->kind) {
            TrivialMatchKind::Contains => '/'.$quoted[0].'/',
            TrivialMatchKind::StartsWith => '/^'.$quoted[0].'/',
            TrivialMatchKind::EndsWith => '/'.$quoted[0].'\z/',
            TrivialMatchKind::Equals => '/^'.$quoted[0].'\z/',
            TrivialMatchKind::OneOf => '/^(?:'.implode('|', $quoted).')\z/',
            TrivialMatchKind::IsEmpty => '/^\z/',
        };

        try {
            return ($this->solver ?? new LanguageSolver($this->parser))
                ->equivalent($pattern, $canonical, new SolverOptions(matchMode: MatchMode::Partial))
                ->isEquivalent;
        } catch (ComplexityException) {
            return false;
        }
    }

    /**
     * Every string the node can match, when they are few and read from
     * literals.
     *
     * @return list<string>|null
     */
    private function literals(Hir $hir): ?array
    {
        return match (true) {
            $hir instanceof LiteralHir => [implode('', array_map(\chr(...), $hir->codePoints))],
            $hir instanceof EmptyHir => [''],
            $hir instanceof CaptureHir => $this->literals($hir->body),
            $hir instanceof ConcatHir => $this->product(array_map($this->literals(...), $hir->parts)),
            $hir instanceof AlternationHir => $this->union(array_map($this->literals(...), $hir->branches)),
            $hir instanceof ClassHir => self::members($hir),
            $hir instanceof RepetitionHir => $this->repetition($hir),
            default => null,
        };
    }

    /**
     * @return list<string>|null
     */
    private static function members(ClassHir $hir): ?array
    {
        $members = [];
        foreach ($hir->set->ranges as [$from, $to]) {
            if (\count($members) + $to - $from >= self::MAX_LITERALS) {
                return null;
            }

            foreach (range($from, $to) as $byte) {
                $members[] = \chr($byte);
            }
        }

        return $members;
    }

    /**
     * @return list<string>|null
     */
    private function repetition(RepetitionHir $hir): ?array
    {
        $body = $this->literals($hir->body);
        if (null === $body) {
            return null;
        }

        if (0 === $hir->min && 1 === $hir->max) {
            return $this->union([[''], $body]);
        }

        return $hir->min === $hir->max && $hir->min <= 4 ? $this->product(array_fill(0, $hir->min, $body)) : null;
    }

    /**
     * @param array<list<string>|null> $parts
     *
     * @return list<string>|null
     */
    private function product(array $parts): ?array
    {
        $strings = [''];
        foreach ($parts as $part) {
            if (null === $part) {
                return null;
            }

            $next = [];
            foreach ($strings as $prefix) {
                foreach ($part as $suffix) {
                    $next[] = $prefix.$suffix;
                }
            }

            $strings = array_values(array_unique($next));
            if (\count($strings) > self::MAX_LITERALS) {
                return null;
            }
        }

        return $strings;
    }

    /**
     * @param array<list<string>|null> $parts
     *
     * @return list<string>|null
     */
    private function union(array $parts): ?array
    {
        $strings = [];
        foreach ($parts as $part) {
            if (null === $part) {
                return null;
            }
            $strings = [...$strings, ...$part];
        }

        $strings = array_values(array_unique($strings));

        return \count($strings) > self::MAX_LITERALS ? null : $strings;
    }
}
