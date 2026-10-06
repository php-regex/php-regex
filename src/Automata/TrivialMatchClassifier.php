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
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\LimitMatchNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\PcreVerbNode;
use PHPRegex\Parser\Node\PosixClassNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\NodeFinder;
use PHPRegex\Parser\RegexParser;

/**
 * Tells when a preg_match() is a string function in disguise: "/^foo/" is
 * str_starts_with(), "/^(?:GET|POST)\z/" an in_array(). The function is
 * named only once the automata prove that preg_match() returns 1 for exactly
 * the subjects it says true for; "/^foo$/" is no ===, as "$" also takes a
 * final newline.
 *
 * Patterns in UTF mode are left alone: on a subject that is not UTF-8,
 * preg_match() fails where a string function answers. So are patterns whose
 * answer moves with the locale (see refuses()), patterns that hold a verb
 * anywhere: "(*CRLF)" at the start as much as "(*SKIP)" in the middle, and
 * patterns that reach one string along two paths: "(?:a|a){20}b" makes the
 * engine try both branches at every step and fail on its backtrack limit.
 */
final readonly class TrivialMatchClassifier
{
    /**
     * The most paths through the pattern the classifier reads, one per
     * string: two paths reaching one string are refused, not counted once.
     */
    private const MAX_LITERALS = 16;

    /**
     * The longest literal matchedLiteral() builds: past it, the solver's
     * default budget (one NFA state per byte, 5000 states) cannot prove it
     * anyway, and a nested repetition such as "(?:a{1000}){1000}" would
     * otherwise be spelled out in memory first.
     */
    private const MAX_LITERAL_BYTES = 5000;

    /**
     * The shorthand classes PHP reads from PCRE's character tables (and "\h",
     * "\v", refused alike by policy).
     */
    private const SHORTHANDS = ['d', 'D', 's', 'S', 'w', 'W', 'h', 'H', 'v', 'V'];

    public function __construct(private ?RegexParser $parser = null, private ?LanguageSolver $solver = null) {}

    /**
     * The string function the pattern behaves as under preg_match(), or null
     * when there is none, or none the automata could prove.
     */
    public function classify(string $pattern): ?TrivialMatch
    {
        $ast = $this->parse($pattern);
        if (null === $ast) {
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
     * The one non-empty string the pattern's full-match language holds, or
     * null when it holds another, none, or the automata could not prove it.
     *
     * Then preg_replace() and preg_split() answer as str_replace() and
     * explode() with that string: they scan left to right and take
     * non-overlapping matches alike. The full-match language is the one that
     * counts: "/ab?/" finds what "/a/" finds, yet replaces "ab" whole.
     *
     * Only literals, concatenation, groups, one-member classes and fixed
     * repetitions are read; an alternation of two branches or more (two
     * strings, or one string along two paths the engine backtracks
     * through), an anchor, a lookaround, "\K", a verb, a backreference, a
     * recursion or a conditional is refused before any proof, as are the
     * patterns classify() refuses.
     */
    public function matchedLiteral(string $pattern): ?string
    {
        $ast = $this->parse($pattern);
        if (null === $ast) {
            return null;
        }

        $literal = self::literal((new HirTranslator())->translate($ast));
        if (null === $literal || '' === $literal) {
            return null;
        }

        try {
            return ($this->solver ?? new LanguageSolver($this->parser))
                ->equivalent($pattern, '/'.self::quote($literal).'/', new SolverOptions(matchMode: MatchMode::Full))
                ->isEquivalent ? $literal : null;
        } catch (ComplexityException) {
            return null;
        }
    }

    /**
     * The pattern's tree, or null when it does not compile or when a string
     * function cannot answer as preg_*() does whatever the subject and the
     * environment.
     */
    private function parse(string $pattern): ?RegexNode
    {
        try {
            $ast = ($this->parser ?? RegexParser::create())->parse($pattern);
        } catch (ExceptionInterface) {
            return null;
        }

        return self::refuses($ast) || (self::isExtended($ast) && !mb_check_encoding($pattern, 'ASCII')) ? null : $ast;
    }

    /**
     * Whether "x" or "xx" is on anywhere in the pattern. PCRE then skips the
     * pattern bytes its character tables call white space, and PHP builds
     * those tables from LC_CTYPE once a program calls setlocale(): under
     * nl_NL.UTF-8 (macOS), "/prix\xA0eur/x" holding a raw 0xA0 matches
     * "prixeur". A raw byte above 0x7F is refused there; "\xa0" is not one.
     */
    private static function isExtended(RegexNode $ast): bool
    {
        return str_contains($ast->flags, 'x') || null !== NodeFinder::findFirst(
            $ast,
            static fn (NodeInterface $node): bool => $node instanceof GroupNode && null !== $node->flags && str_contains(explode('-', $node->flags)[0], 'x'),
        );
    }

    /**
     * Whether the pattern is left alone before any proof.
     *
     * - UTF mode: on a subject that is not UTF-8, preg_*() fails where a
     *   string function answers.
     * - "/A": the match is tried at offset 0 only.
     * - Caseless matching, shorthand classes and POSIX classes: once a
     *   program calls setlocale(), PHP builds PCRE's character and case
     *   tables from LC_CTYPE, so "[[:alpha:]]", "\w" or "/\xe9/i" take bytes
     *   the automata, which read the C tables, do not (glibc Turkish even
     *   pairs "i" with another letter). "/i" is refused in every form.
     * - A verb, wherever it stands: "(*LIMIT_MATCH=1)" makes preg_match()
     *   fail without the JIT, where a string function answers; the newline
     *   and "\R" conventions and "(*UCP)" are refused by policy.
     */
    private static function refuses(RegexNode $ast): bool
    {
        if ($ast->isUnicode() || false !== strpbrk($ast->flags, 'iA')) {
            return true;
        }

        return null !== NodeFinder::findFirst($ast, static fn (NodeInterface $node): bool => match (true) {
            $node instanceof PcreVerbNode, $node instanceof LimitMatchNode, $node instanceof PosixClassNode => true,
            $node instanceof CharTypeNode => \in_array($node->value, self::SHORTHANDS, true),
            // "(?i)", "(?i:…)", "(?^i)": the options it turns on.
            $node instanceof GroupNode => null !== $node->flags && str_contains(explode('-', $node->flags)[0], 'i'),
            default => false,
        });
    }

    /**
     * The one string the node matches along one path, read through the
     * structure alone, or null when it may match another, reach it along
     * two paths, or the structure is not one it reads.
     */
    private static function literal(Hir $hir): ?string
    {
        if ($hir instanceof AlternationHir) {
            // Two branches are two paths: two strings, or one string the
            // engine backtracks through twice.
            return 1 === \count($hir->branches) ? self::literal($hir->branches[0]) : null;
        }

        if ($hir instanceof ConcatHir) {
            $literal = '';
            foreach ($hir->parts as $part) {
                $next = self::literal($part);
                if (null === $next || \strlen($literal) + \strlen($next) > self::MAX_LITERAL_BYTES) {
                    return null;
                }
                $literal .= $next;
            }

            return $literal;
        }

        if ($hir instanceof RepetitionHir) {
            $body = $hir->min === $hir->max ? self::literal($hir->body) : null;

            return null === $body || \strlen($body) * $hir->min > self::MAX_LITERAL_BYTES ? null : str_repeat($body, $hir->min);
        }

        if ($hir instanceof ClassHir) {
            $ranges = $hir->set->ranges;

            return 1 === \count($ranges) && $ranges[0][0] === $ranges[0][1] ? \chr($ranges[0][0]) : null;
        }

        return match (true) {
            $hir instanceof LiteralHir => implode('', array_map(\chr(...), $hir->codePoints)),
            $hir instanceof EmptyHir => '',
            $hir instanceof CaptureHir => self::literal($hir->body),
            default => null,
        };
    }

    /**
     * Each byte as a hex escape: exact in byte mode, whatever it is.
     */
    private static function quote(string $literal): string
    {
        return implode('', array_map(static fn (string $byte): string => \sprintf('\x%02x', \ord($byte)), str_split($literal)));
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
        $quoted = array_map(self::quote(...), $match->literals);
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
     * Every string the node can match, one per path through it, when they
     * are few and read from literals; null as well when two paths reach one
     * string, as in "(?:a|a)" or "a?a?": the engine backtracks through both,
     * and repeated, past its limit.
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

            $strings = self::paths($next);
            if (null === $strings) {
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

        return self::paths($strings);
    }

    /**
     * The strings, one per path, or null past the cap or when two paths
     * reach one string.
     *
     * @param list<string> $strings
     *
     * @return list<string>|null
     */
    private static function paths(array $strings): ?array
    {
        return \count($strings) > self::MAX_LITERALS || \count(array_unique($strings)) !== \count($strings) ? null : $strings;
    }
}
