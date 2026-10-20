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

use PHPRegex\Generator\SampleGenerationException;
use PHPRegex\Generator\SampleGenerator;
use PHPRegex\Parser\Exception\ExceptionInterface;

/**
 * The patterns and subjects the target engines are checked on.
 *
 * Patterns: every delimited pattern the transpiler's unit tests write, the
 * pattern fixtures of the suite (the php-src and PCRE2 test suites
 * included), and a seeded draw of random patterns. Subjects: samples the
 * library's SampleGenerator draws for the pattern, those samples changed at
 * their edges, and a fixed set of strings that tell engines apart — line
 * terminators, case pairs that only Unicode folds, digits and spaces
 * outside ASCII, astral characters.
 *
 * Without u, PCRE reads bytes and the targets read characters: the two
 * only agree on ASCII text, so a pattern without u is checked on ASCII
 * subjects alone.
 */
final class OracleCorpus
{
    /**
     * The longest subject, in characters: short enough that a pattern that
     * backtracks exponentially in a target stays quick.
     */
    private const MAX_LENGTH = 40;

    private const EDGES = [
        '', 'a', 'b', 'ab', 'abc', 'aB', 'A', 'x', 'aaa', 'abab', 'a1_', '1', '12', '_', '-', '.', ' ', 'a b', "\t", "\x0B", "\f", '#', '/', 'k', 'K', 's', 'S',
        "\n", "a\n", "\na", "a\nb", "a\n\n", "\r", "a\r", "a\rb", "\r\n", "a\r\nb", "a\nb\n", "\x00", "a\x1Cb",
        'é', 'É', 'café', 'ß', 'ẞ', 'ſ', "\u{212A}", '٣', '١٢', "\u{A0}", "\u{85}", "\u{2028}", "a\u{2028}b", "\u{2029}", "\u{3000}", "\u{FEFF}", "\u{1680}",
        '😀', 'a😀b', "e\u{301}", 'ǅ', 'İ', 'ı', 'Σσς', 'αβγ', 'Жж', '中文', 'ﬀ', 'Ａ', "\u{200B}",
    ];

    /**
     * @var array<string, list<string>>
     */
    private array $fixtureSubjects = [];

    /**
     * @var array<string, list<string>> the subjects drawn for each pattern, kept for the next target
     */
    private array $drawn = [];

    public function __construct(private readonly string $root) {}

    /**
     * @return list<string> the delimited patterns PCRE compiles, once each
     */
    public function patterns(int $generated): array
    {
        $patterns = [];
        foreach ([...$this->unitTestPatterns(), ...$this->fixturePatterns(), ...$this->suitePatterns(), ...(new PatternGenerator(20261020))->generate($generated)] as $pattern) {
            if (!isset($patterns[$pattern]) && false !== @preg_match($pattern, '')) {
                $patterns[$pattern] = true;
            }
        }

        return array_map(strval(...), array_keys($patterns));
    }

    /**
     * @return list<string> the subjects the pattern is checked on, once each
     */
    public function subjects(string $regex, bool $unicode, SampleGenerator $generator): array
    {
        return $this->drawn[$regex] ??= $this->draw($regex, $unicode, $generator);
    }

    /**
     * @return list<string>
     */
    private function draw(string $regex, bool $unicode, SampleGenerator $generator): array
    {
        $subjects = [...self::EDGES, ...$this->fixtureSubjects[$regex] ?? []];
        for ($seed = 1; $seed <= 3; $seed++) {
            $generator->setSeed($seed);

            try {
                $sample = $generator->generate($regex);
            } catch (SampleGenerationException|ExceptionInterface) {
                continue;
            }

            $subjects[] = $sample;
            $subjects[] = $sample."\n";
            $subjects[] = "\n".$sample;
            $subjects[] = 'x'.$sample.'x';
            $subjects[] = $sample.$sample;
            $subjects[] = strtoupper($sample);
            $subjects[] = substr($sample, 0, -1);
            $subjects[] = substr($sample, 1);
        }

        $kept = [];
        foreach ($subjects as $subject) {
            if (!mb_check_encoding($subject, 'UTF-8') || mb_strlen($subject, 'UTF-8') > self::MAX_LENGTH) {
                continue;
            }

            if ($unicode ? 1 === preg_match('/[\p{Cn}\p{Cs}]/u', $subject) : 1 === preg_match('/[^\x00-\x7F]/', $subject)) {
                continue;
            }

            $kept[$subject] = true;
        }

        return array_map(strval(...), array_keys($kept));
    }

    /**
     * Every single-quoted delimited pattern in the transpiler's unit tests.
     *
     * @return list<string>
     */
    private function unitTestPatterns(): array
    {
        $patterns = [];
        foreach (glob($this->root.'/tests/Unit/Transpiler/*.php') ?: [] as $file) {
            foreach (token_get_all((string) file_get_contents($file)) as $token) {
                if (!\is_array($token) || \T_CONSTANT_ENCAPSED_STRING !== $token[0] || !str_starts_with($token[1], "'")) {
                    continue;
                }

                $value = str_replace(['\\\\', "\\'"], ['\\', "'"], substr($token[1], 1, -1));
                if (1 === preg_match('/^([\/#~]).+\1[imsxuUDnJ]*$/s', $value)) {
                    $patterns[] = $value;
                }
            }
        }

        return $patterns;
    }

    /**
     * The pattern fixtures: the suite's collection and the cases taken from
     * php-src, whose subjects come along.
     *
     * @return list<string>
     */
    private function fixturePatterns(): array
    {
        /** @var array<int, string> $collection */
        $collection = require $this->root.'/tests/Fixtures/pcre_patterns.php';
        /** @var list<array{pattern: string, subject?: mixed}> $phpSrc */
        $phpSrc = require $this->root.'/tests/Fixtures/php_pcre_comprehensive.php';

        $patterns = array_values($collection);
        foreach ($phpSrc as $case) {
            $patterns[] = $case['pattern'];
            if (isset($case['subject']) && \is_string($case['subject'])) {
                $this->fixtureSubjects[$case['pattern']][] = $case['subject'];
            }
        }

        return $patterns;
    }

    /**
     * The patterns of the PCRE2 test suite that PHP compiles.
     *
     * @return list<string>
     */
    private function suitePatterns(): array
    {
        /** @var array<string, mixed> $suite */
        $suite = json_decode((string) file_get_contents($this->root.'/tests/Fixtures/Pcre2/suite-cases.json'), true, 512, \JSON_THROW_ON_ERROR);
        $closing = ['(' => ')', '[' => ']', '{' => '}', '<' => '>'];

        $patterns = [];
        foreach ($suite as $file => $cases) {
            if ('meta' === $file || !\is_array($cases)) {
                continue;
            }

            /** @var array{pattern: string, delimiter: string, flags: string, verdict: ?string, skipCategory: ?string} $case */
            foreach ($cases as $case) {
                if ('accept' === $case['verdict'] && null === $case['skipCategory']) {
                    $patterns[] = $case['delimiter'].$case['pattern'].($closing[$case['delimiter']] ?? $case['delimiter']).$case['flags'];
                }
            }
        }

        return $patterns;
    }
}
