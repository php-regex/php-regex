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

namespace PHPRegex\Tests\Integration\Transpiler\Oracle;

use PHPRegex\Generator\SampleGenerator;
use PHPRegex\Parser\Engine\NoJit;
use PHPRegex\Parser\Engine\PcreEngine;
use PHPRegex\Parser\Exception\ExceptionInterface;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\PosixClassNode;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\UnicodePropNode;
use PHPRegex\Toolkit\Regex;
use PHPRegex\Transpiler\Exception\TranspileException;
use PHPRegex\Transpiler\TranspileOptions;

/**
 * Matches each pattern with preg_match() and its transpiled form with the
 * target engine, subject by subject, and lists where they differ. PCRE runs
 * without its JIT, as the library's engine runs it, and without its
 * start-up optimizations: the JIT of PCRE2 10.49 misses matches the
 * interpreter finds, as "/\e?A#\p{Greek}{2}3/u" in "A#\u{308}\u{304}3",
 * and the start-up optimizations of PCRE2 10.44 miss "/(?=a)b?a/" in "a".
 *
 * A search target (JavaScript, Python) is compared on the first match from
 * the start of the subject and from its second character: whether there is
 * one, where it starts and ends, and where each group starts and ends, or
 * that it is unset. Offsets are compared in characters: PHP gives bytes,
 * JavaScript UTF-16 code units, Python code points. The HTML pattern
 * attribute is compared on what it accepts: the value is valid when
 * preg_match() finds the pattern in it. A value holds no line break, so a
 * subject with one is not a value.
 *
 * A pattern the transpiler refuses is no divergence; a transpiled pattern
 * the target cannot compile is one.
 *
 * @phpstan-import-type EngineCase from TargetEngine
 */
final class DifferentialRun
{
    public int $checked = 0;

    public int $transpiled = 0;

    public int $refused = 0;

    public int $comparisons = 0;

    public int $timedOut = 0;

    public int $insidePairs = 0;

    /**
     * Comparisons left out because the subject holds a character whose
     * property PCRE and the engine read from Unicode tables of different
     * versions.
     */
    public int $tableSkews = 0;

    /**
     * Comparisons left out because the running PCRE2 compiles a class of
     * the pattern without some of its characters, and the subject holds
     * one of them.
     */
    public int $classDefects = 0;

    /**
     * @var list<string>
     */
    public array $divergences = [];

    private readonly PcreEngine $pcre;

    /**
     * For each case sent to the engine: the pattern, its transpiled form,
     * the flags, and what PCRE found on each subject from each start.
     *
     * @var list<array{string, string, string, list<array{list<?array{int, int}>|null, list<string>, int}>, list<string>, list<array{string, string, bool}>}>
     */
    private array $expectations = [];

    /**
     * The characters on which PCRE and the engine read a property atom
     * otherwise, as their Unicode tables differ: atom => character => true.
     *
     * @var array<string, array<string, true>>
     */
    private array $tableSkew = [];

    /**
     * Whether the running PCRE2 reads a class whole as it reads its members
     * one by one, on a character: class => character => whether it does.
     *
     * @var array<string, array<string, bool>>
     */
    private array $classReadings = [];

    private static ?bool $dropsClassMembers = null;

    /**
     * @param list<string>          $patterns
     * @param TranspileOptions|null $options  what the target may write, as for transpile()
     */
    public function __construct(
        private readonly string $target,
        private readonly TargetEngine $engine,
        private readonly OracleCorpus $corpus,
        private readonly array $patterns,
        private readonly ?TranspileOptions $options = null,
    ) {
        $this->pcre = new PcreEngine();
    }

    public function run(): self
    {
        $regex = Regex::create();
        $generator = new SampleGenerator();
        $whole = 'html-pattern' === $this->target;

        $cases = [];
        $this->expectations = [];
        foreach ($this->patterns as $pattern) {
            $this->checked++;

            try {
                $result = $regex->transpile($pattern, $this->target, $this->options);
            } catch (TranspileException) {
                $this->refused++;

                continue;
            } catch (ExceptionInterface) {
                continue;
            }

            $this->transpiled++;
            $ast = $regex->parse($pattern);
            $unicode = str_contains($ast->flags, 'u');
            $subjects = [];
            $expected = [];
            foreach ($this->corpus->subjects($pattern, $unicode, $generator) as $subject) {
                if ($whole && 1 === preg_match('/[\r\n]/', $subject)) {
                    continue;
                }

                $characters = preg_split('//u', $subject, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
                foreach ($whole || \count($characters) < 2 ? [0] : [0, 1] as $start) {
                    $php = $this->php($pattern, $subject, $characters, $start, $whole);
                    if (false === $php) {
                        continue;
                    }

                    $subjects[] = [$subject, 'node' === $this->engine->name ? \strlen((string) mb_convert_encoding(implode('', \array_slice($characters, 0, $start)), 'UTF-16LE', 'UTF-8')) >> 1 : $start];
                    $expected[] = [$php, $characters, $start];
                }
            }

            if ([] === $subjects) {
                continue;
            }

            $cases[] = ['pattern' => $result->pattern, 'flags' => $whole ? 'v' : $result->flags, 'whole' => $whole, 'subjects' => $subjects];
            $this->expectations[] = [$pattern, $result->pattern, $result->flags, $expected, $this->atoms($ast, $unicode), $this->classes($ast, $unicode)];
        }

        $this->probeTables($regex);

        foreach ($this->engine->run($cases) as $index => $outcome) {
            [$pattern, $transpiled, $flags, $expected, $atoms, $classes] = $this->expectations[$index];
            if ($outcome['timedOut'] ?? false) {
                $this->timedOut++;

                continue;
            }

            if (null !== $outcome['error']) {
                $this->divergences[] = \sprintf("%s\n    -> %s (flags \"%s\") does not compile in %s: %s", $pattern, $transpiled, $flags, $this->engine->name, $outcome['error']);

                continue;
            }

            foreach ($expected as $i => [$php, $characters, $start]) {
                if ($outcome['unassigned'][$i]) {
                    continue;
                }

                if ($this->skewed($atoms, $characters)) {
                    $this->tableSkews++;

                    continue;
                }

                if ($this->miscompiled($classes, $characters)) {
                    $this->classDefects++;

                    continue;
                }

                $theirs = $this->toCharacters($outcome['results'][$i], $characters);
                if (null !== $theirs && \in_array(-1, array_merge(...array_map(static fn (?array $span): array => $span ?? [], $theirs)), true)) {
                    // V8 tries the position inside a surrogate pair under u
                    // when the pattern holds a lookbehind, which the
                    // specification never does: an engine defect.
                    $this->insidePairs++;

                    continue;
                }

                $this->comparisons++;
                if ($theirs !== $php) {
                    $this->divergences[] = \sprintf(
                        "%s\n    -> %s (flags \"%s\")\n    subject %s from character %d: PHP %s, %s %s",
                        $pattern,
                        $transpiled,
                        $flags,
                        json_encode(implode('', $characters), \JSON_UNESCAPED_UNICODE),
                        $start,
                        self::describe($php),
                        $this->engine->name,
                        self::describe($theirs),
                    );
                }
            }
        }

        return $this;
    }

    /**
     * The atoms of the pattern whose characters come from a Unicode table:
     * its properties and, under u, "\w" and "\d". Python writes none of
     * the properties, and "\w" under u is refused there.
     *
     * @return list<string>
     */
    private function atoms(RegexNode $ast, bool $unicode): array
    {
        $atoms = $unicode ? ('python' === $this->target ? ['\\d'] : ['\\w', '\\d']) : [];
        $nodes = [$ast->pattern];
        while ([] !== $nodes) {
            $node = array_pop($nodes);
            if ($node instanceof UnicodePropNode) {
                $atoms[] = substr((string) $ast->source, $node->getStartPosition(), $node->getEndPosition() - $node->getStartPosition());
            }

            array_push($nodes, ...$node->getChildren());
        }

        return array_values(array_unique($atoms));
    }

    /**
     * Ask PCRE and the engine, character by character, what each atom
     * matches: where their Unicode tables are of different versions, a
     * character's script or category may differ ("\p{Greek}" takes U+0301
     * from Unicode 16), which is no fault of the transpiler, and the
     * subjects holding it are left out for the patterns using the atom. An
     * atom read otherwise on more than a few characters is a wrong
     * transpilation, and a divergence.
     */
    private function probeTables(Regex $regex): void
    {
        $characters = [];
        foreach ($this->expectations as [, , , $expected, $atoms]) {
            foreach ($atoms as $atom) {
                foreach ($expected as [, $subjectCharacters]) {
                    foreach ($subjectCharacters as $character) {
                        if (\strlen($character) > 1) {
                            $characters[$atom][$character] = true;
                        }
                    }
                }
            }
        }

        $cases = [];
        $probed = [];
        foreach ($characters as $atom => $set) {
            try {
                $result = $regex->transpile('/'.$atom.'/u', 'python' === $this->target ? 'python' : 'javascript');
            } catch (TranspileException|ExceptionInterface) {
                continue;
            }

            $list = array_map(strval(...), array_keys($set));
            $cases[] = ['pattern' => $result->pattern, 'flags' => $result->flags, 'whole' => false, 'subjects' => array_map(static fn (string $character): array => [$character, 0], $list)];
            $probed[] = [$atom, $list];
        }

        foreach ($this->engine->run($cases) as $index => $outcome) {
            [$atom, $list] = $probed[$index];
            if (null !== $outcome['error'] || ($outcome['timedOut'] ?? false)) {
                continue;
            }

            $differing = [];
            foreach ($list as $i => $character) {
                $theirs = null !== ($outcome['results'][$i] ?? null);
                if ($theirs !== (1 === preg_match('/'.$atom.'/u', $character)) && !$outcome['unassigned'][$i]) {
                    $differing[$character] = true;
                }
            }

            if (\count($differing) > max(3, intdiv(\count($list), 100))) {
                $this->divergences[] = \sprintf('%s is read otherwise by %s on %d of the %d characters probed, as %s', $atom, $this->engine->name, \count($differing), \count($list), implode(' ', \array_slice(array_map(strval(...), array_keys($differing)), 0, 10)));
            }

            $this->tableSkew[$atom] = $differing;
        }
    }

    /**
     * @param list<string> $atoms
     * @param list<string> $characters
     */
    private function skewed(array $atoms, array $characters): bool
    {
        foreach ($atoms as $atom) {
            foreach ($characters as $character) {
                if (isset($this->tableSkew[$atom][$character])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The classes of a pattern under u on an engine that compiles some
     * classes without all their characters, none on any other engine: each
     * class as the pattern writes it, and the same class written as its
     * members one by one, "(?:[a]|[b-c])", which the engine compiles whole.
     *
     * PCRE2 10.45 to 10.47 compile a class that holds characters up to
     * U+00FF beside characters from U+0100 and from U+8000 without some of
     * them: "[\x{ff}-\x{104}\x{8000}\x{8002}\x{8004}\x{8006}\x{8008}\x{800a}\x{800c}\x{800e}]"
     * no longer takes U+0100 to U+0104, which the target takes. PCRE2 10.48
     * fixed it (ChangeLog, version 10.48, item 6, pull request 841). The
     * engine is asked, never its release: a build may carry the fix.
     *
     * A class with a member that cannot be written alone is left out, and
     * the comparison is made.
     *
     * @return list<array{string, string, bool}> the class, its members one by one, and whether it is negated
     */
    private function classes(RegexNode $ast, bool $unicode): array
    {
        if (!$unicode || !self::dropsClassMembers()) {
            return [];
        }

        $closing = ['(' => ')', '[' => ']', '{' => '}', '<' => '>'][$ast->delimiter] ?? $ast->delimiter;
        $classes = [];
        $nodes = [$ast->pattern];
        while ([] !== $nodes) {
            $node = array_pop($nodes);
            array_push($nodes, ...$node->getChildren());
            if (!$node instanceof CharClassNode) {
                continue;
            }

            $members = [];
            foreach ($node->expression instanceof AlternationNode ? $node->expression->getChildren() : [$node->expression] as $member) {
                $members[] = self::alone($member, (string) $ast->source);
            }

            if (\count($members) < 2 || \in_array(null, $members, true)) {
                continue;
            }

            $classes[] = [
                $ast->delimiter.substr((string) $ast->source, $node->getStartPosition(), $node->getEndPosition() - $node->getStartPosition()).$closing.$ast->flags,
                $ast->delimiter.'(?:['.implode(']|[', $members).'])'.$closing.$ast->flags,
                $node->isNegated,
            ];
        }

        return $classes;
    }

    /**
     * A member of a class written so that it stands alone between "[" and
     * "]": a character or a range by its code points, an escape, a POSIX
     * name or a property as the pattern writes it. Null for anything else.
     */
    private static function alone(NodeInterface $member, string $source): ?string
    {
        if ($member instanceof RangeNode) {
            $start = self::codePoint($member->start);
            $end = self::codePoint($member->end);

            return null === $start || null === $end ? null : \sprintf('\\x{%x}-\\x{%x}', $start, $end);
        }

        if ($member instanceof CharTypeNode || $member instanceof PosixClassNode || $member instanceof UnicodePropNode) {
            return substr($source, $member->getStartPosition(), $member->getEndPosition() - $member->getStartPosition());
        }

        $codePoint = self::codePoint($member);

        return null === $codePoint ? null : \sprintf('\\x{%x}', $codePoint);
    }

    private static function codePoint(NodeInterface $node): ?int
    {
        if ($node instanceof CharLiteralNode) {
            return $node->codePoint;
        }

        if ($node instanceof LiteralNode && 1 === mb_strlen($node->value, 'UTF-8')) {
            return mb_ord($node->value, 'UTF-8');
        }

        return null;
    }

    /**
     * Whether the subject holds a character the running PCRE2 reads in one
     * of the classes otherwise than in its members: the engine's own two
     * answers differ, and neither can be held against the target.
     *
     * @param list<array{string, string, bool}> $classes
     * @param list<string>                      $characters
     */
    private function miscompiled(array $classes, array $characters): bool
    {
        foreach ($classes as [$class, $members, $negated]) {
            foreach ($characters as $character) {
                $this->classReadings[$class][$character] ??= self::readsAlike($class, $members, $negated, $character);
                if (!$this->classReadings[$class][$character]) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function readsAlike(string $class, string $members, bool $negated, string $character): bool
    {
        $whole = @preg_match($class, $character);
        $apart = @preg_match($members, $character);

        // A probe the engine does not compile says nothing of the class.
        return false === $whole || false === $apart || (1 === $whole) === ($negated xor 1 === $apart);
    }

    /**
     * Whether the running PCRE2 drops characters from the class its own
     * suite holds for that defect.
     */
    private static function dropsClassMembers(): bool
    {
        return self::$dropsClassMembers ??= 1 !== preg_match('/[\x{ff}-\x{104}\x{8000}\x{8002}\x{8004}\x{8006}\x{8008}\x{800a}\x{800c}\x{800e}]/u', "\u{101}");
    }

    /**
     * What preg_match() finds from the character, in characters, or false
     * when PCRE gives up (a backtracking or depth limit).
     *
     * @param list<string> $characters
     *
     * @return list<?array{int, int}>|false|null
     */
    private function php(string $pattern, string $subject, array $characters, int $start, bool $whole): array|false|null
    {
        $offset = \strlen(implode('', \array_slice($characters, 0, $start)));
        $prepared = $this->pcre->prepare($pattern);
        $verb = strpos($prepared, NoJit::VERB);
        if (false === $verb) {
            return false;
        }

        $prepared = substr_replace($prepared, NoJit::VERB.'(*NO_START_OPT)', $verb, \strlen(NoJit::VERB));
        $found = @preg_match($prepared, $subject, $groups, \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL, $offset);
        if (false === $found) {
            return false;
        }

        if (0 === $found) {
            return null;
        }

        if ($whole) {
            return [[0, \count($characters)]];
        }

        $spans = [];
        foreach ($groups as $key => [$text, $at]) {
            if (\is_int($key)) {
                $spans[] = null === $text ? null : [mb_strlen(substr($subject, 0, $at), 'UTF-8'), mb_strlen(substr($subject, 0, $at + \strlen($text)), 'UTF-8')];
            }
        }

        return $spans;
    }

    /**
     * The engine's spans, in characters. A UTF-16 offset that falls inside
     * a surrogate pair is no character offset, and becomes -1.
     *
     * @param list<?array{int, int}>|null $spans
     * @param list<string>                $characters
     *
     * @return list<?array{int, int}>|null
     */
    private function toCharacters(?array $spans, array $characters): ?array
    {
        if (null === $spans || 'node' !== $this->engine->name) {
            return $spans;
        }

        $map = [0 => 0];
        $units = 0;
        foreach ($characters as $index => $character) {
            $units += \strlen((string) mb_convert_encoding($character, 'UTF-16LE', 'UTF-8')) >> 1;
            $map[$units] = $index + 1;
        }

        return array_map(static fn (?array $span): ?array => null === $span ? null : [$map[$span[0]] ?? -1, $map[$span[1]] ?? -1], $spans);
    }

    /**
     * @param list<?array{int, int}>|null $spans
     */
    private static function describe(?array $spans): string
    {
        if (null === $spans) {
            return 'no match';
        }

        return implode(' ', array_map(static fn (?array $span): string => null === $span ? 'unset' : '['.$span[0].','.$span[1].')', $spans));
    }
}
