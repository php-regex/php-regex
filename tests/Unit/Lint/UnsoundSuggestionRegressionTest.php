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

namespace PHPRegex\Tests\Unit\Lint;

use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\LintRequest;
use PHPRegex\Linter\LintService;
use PHPRegex\Linter\PatternLinter;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Linter\Source\PatternSourceCollection;
use PHPRegex\Optimizer\OptimizationResult;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The linter must never hand out a rewrite that changes what matches.
 */
final class UnsoundSuggestionRegressionTest extends TestCase
{
    /**
     * Wrapping the operand and leaving the quantifier outside — X{n,} to
     * (?>X){n,} — takes away the backtracking between iterations, which the
     * engine needs: oracle, `/(\n\s*){2,}/` on "\n\n:" matches, while
     * `/(?>(\n\s*)){2,}/` does not. No automatic rewrite is emitted for
     * these rules; the hint says to verify any manual one.
     *
     * @return iterable<string, array{pattern: string, issueId: string}>
     */
    public static function provideNestedQuantifierPatterns(): iterable
    {
        yield 'nested quantifier' => [
            'pattern' => '/(a+)+/',
            'issueId' => 'regex.lint.quantifier.nested',
        ];

        yield 'nested dot star' => [
            'pattern' => '/(?:.*)+/',
            'issueId' => 'regex.lint.dotstar.nested',
        ];

        yield 'nested quantifier needing cross-iteration backtracking' => [
            'pattern' => '/^(.*)+;$/',
            'issueId' => 'regex.lint.quantifier.nested',
        ];
    }

    #[Test]
    #[DataProvider('provideNestedQuantifierPatterns')]
    public function test_nested_quantifier_warnings_carry_no_automatic_rewrite(string $pattern, string $issueId): void
    {
        /** @var array<int, array<string, mixed>> $issues */
        $issues = $this->analyze($pattern)['issues'] ?? [];
        $matching = array_values(array_filter(
            $issues,
            static fn (array $issue): bool => ($issue['issueId'] ?? '') === $issueId,
        ));

        $this->assertNotSame([], $matching, $pattern);
        foreach ($matching as $issue) {
            $this->assertArrayNotHasKey('suggestedPattern', $issue, $pattern);
            $hint = $issue['hint'] ?? '';
            $this->assertStringContainsStringIgnoringCase('verify', \is_string($hint) ? $hint : '', $pattern);
        }
    }

    #[Test]
    public function test_unicode_property_message_states_the_real_limit(): void
    {
        // The engine compiles and matches \p{L} without /u (oracle:
        // preg_match('/\p{L}/', "\xC3\xA9") is 1); what /u actually buys is
        // coverage beyond the first 256 code points.
        $visitor = new PatternLinter();
        Regex::create()->parse('/\pL/')->accept($visitor);

        $messages = array_map(static fn (object $issue): string => $issue->message, $visitor->getIssues());
        $message = implode("\n", $messages);

        $this->assertStringContainsString('first 256 code points', $message);
        $this->assertStringNotContainsString('requires /u', $message);
    }

    #[Test]
    public function test_unicode_property_message_keeps_the_braced_spelling(): void
    {
        $visitor = new PatternLinter();
        Regex::create()->parse('/\p{Greek}/')->accept($visitor);

        $messages = array_map(static fn (object $issue): string => $issue->message, $visitor->getIssues());

        $this->assertStringContainsString('"\\p{Greek}"', implode("\n", $messages));
    }

    /**
     * Every rewrite the pipeline emits — as an issue suggestion or as an
     * optimization — must preserve the language on an exhaustive subject
     * set: the property that would have caught the atomic-group rewrite.
     *
     * @return iterable<string, array{pattern: string, alphabet: array<int, string>}>
     */
    public static function provideSuggestingPatterns(): iterable
    {
        yield 'digit class to shorthand' => [
            'pattern' => '/[0-9]+/',
            'alphabet' => ['a', '0', '9'],
        ];

        yield 'group unwrap' => [
            'pattern' => '/(?:abc)/',
            'alphabet' => ['a', 'b', 'c'],
        ];

        yield 'literal repetition' => [
            'pattern' => '/aaaa/',
            'alphabet' => ['a', 'b'],
        ];

        yield 'quantified digit classes' => [
            'pattern' => '/[0-9]{4}-[0-9]{2}/',
            'alphabet' => ['0', '-'],
        ];

        yield 'useless flag drop' => [
            'pattern' => '/x/s',
            'alphabet' => ['x', "\n"],
        ];
    }

    /**
     * @param array<int, string> $alphabet
     */
    #[Test]
    #[DataProvider('provideSuggestingPatterns')]
    public function test_every_suggestion_preserves_the_language(string $pattern, array $alphabet): void
    {
        $result = $this->analyze($pattern);

        $pairs = [];
        /** @var array<int, array<string, mixed>> $resultIssues */
        $resultIssues = $result['issues'] ?? [];
        foreach ($resultIssues as $issue) {
            if (isset($issue['suggestedPattern']) && \is_string($issue['suggestedPattern'])) {
                $pairs[] = [$pattern, $issue['suggestedPattern']];
            }
        }

        /** @var array<int, array<string, mixed>> $resultOptimizations */
        $resultOptimizations = $result['optimizations'] ?? [];
        foreach ($resultOptimizations as $optimization) {
            $carrier = $optimization['optimization'] ?? null;
            $optimized = $carrier instanceof OptimizationResult ? $carrier->optimized : null;
            if (\is_string($optimized) && '' !== $optimized) {
                $pairs[] = [$pattern, $optimized];
            }
        }

        $this->assertNotSame([], $pairs, $pattern);

        foreach ($pairs as [$original, $suggested]) {
            $witness = $this->firstLanguageDivergence($original, $suggested, $alphabet, 5);
            $this->assertNull($witness, sprintf(
                '%s vs %s diverge on %s',
                $original,
                $suggested,
                var_export($witness, true),
            ));
        }
    }

    #[Test]
    public function test_the_language_check_detects_the_old_atomic_rewrite(): void
    {
        // Self-test of the guard: the atomic-group rewrite this change
        // removed really does change the language, and the exhaustive
        // check sees it (oracle witness: ';' — the original matches,
        // the rewrite does not).
        $witness = $this->firstLanguageDivergence(
            '/^(.*)+;$/',
            '/^(?>(.*))+;$/',
            ['a', ';', "\n"],
            5,
        );

        $this->assertNotNull($witness);
    }

    #[Test]
    public function test_the_language_check_detects_a_shifted_match(): void
    {
        // A rewrite that keeps matching but moves or resizes the match
        // changes behavior too: on "ab", '/a|ab/' matches "a" at 0 (len 1)
        // while '/ab|a/' matches "ab" at 0 (len 2). A boolean comparator
        // would pass; the offset-and-extent one must not.
        $witness = $this->firstLanguageDivergence(
            '/a|ab/',
            '/ab|a/',
            ['a', 'b'],
            3,
        );

        $this->assertSame('ab', $witness);
    }

    #[Test]
    public function test_every_corpus_suggestion_preserves_the_match(): void
    {
        // The guard runs over the committed corpus fixture, not a handful
        // of hand-picked shapes: any optimizer pass that shifts a match
        // fails here, on real-world patterns.
        $fixture = \dirname(__DIR__, 2).'/Fixtures/Corpus/lint-expectations.json';
        $entries = json_decode((string) file_get_contents($fixture), true);
        $this->assertIsArray($entries);

        $checked = 0;
        foreach ($entries as $entry) {
            if (!\is_array($entry)) {
                continue;
            }

            $pattern = $entry['pattern'] ?? null;
            if (!\is_string($pattern)) {
                continue;
            }

            foreach ($this->suggestionsFor($pattern) as $suggested) {
                $witness = $this->firstLanguageDivergence($pattern, $suggested, $this->alphabetFrom($pattern), 3);
                $this->assertNull($witness, sprintf(
                    '%s vs %s diverge on %s',
                    $pattern,
                    $suggested,
                    var_export($witness, true),
                ));
                $checked++;
            }
        }

        $this->assertGreaterThan(0, $checked);
    }

    /**
     * @return array<int, string>
     */
    private function suggestionsFor(string $pattern): array
    {
        $pairs = [];
        $result = $this->analyze($pattern);

        /** @var array<int, array<string, mixed>> $resultOptimizations */
        $resultOptimizations = $result['optimizations'] ?? [];
        foreach ($resultOptimizations as $optimization) {
            $carrier = $optimization['optimization'] ?? null;
            $optimized = $carrier instanceof OptimizationResult ? $carrier->optimized : null;
            if (\is_string($optimized) && '' !== $optimized && $optimized !== $pattern) {
                $pairs[] = $optimized;
            }
        }

        return $pairs;
    }

    /**
     * A small alphabet per pattern: its own literal characters plus a
     * filler, so the exhaustive walk stays cheap and relevant.
     *
     * @return array<int, string>
     */
    private function alphabetFrom(string $pattern): array
    {
        $alphabet = [];
        foreach (str_split(substr($pattern, 1)) as $char) {
            if (\ctype_alnum($char) && !isset($alphabet[$char])) {
                $alphabet[$char] = $char;
            }
        }

        $alphabet = array_values($alphabet);
        if (\count($alphabet) > 3) {
            $alphabet = \array_slice($alphabet, 0, 3);
        }

        if (!\in_array('a', $alphabet, true)) {
            $alphabet[] = 'a';
        }

        return $alphabet;
    }

    /**
     * @param array<int, string> $alphabet
     */
    /**
     * The comparison covers the match offset and extent, not just whether
     * a match exists: a rewrite that keeps matching but moves the match
     * changes behavior all the same.
     *
     * @param array<int, string> $alphabet
     */
    private function firstLanguageDivergence(string $original, string $suggested, array $alphabet, int $maxLength): ?string
    {
        foreach ($this->allStringsOver($alphabet, $maxLength) as $subject) {
            $before = @preg_match($original, $subject, $beforeMatches, \PREG_OFFSET_CAPTURE);
            $after = @preg_match($suggested, $subject, $afterMatches, \PREG_OFFSET_CAPTURE);

            $beforeSpan = (false === $before || 0 === $before || !isset($beforeMatches[0][1])) ? null : [$beforeMatches[0][1], \strlen((string) $beforeMatches[0][0])];
            $afterSpan = (false === $after || 0 === $after || !isset($afterMatches[0][1])) ? null : [$afterMatches[0][1], \strlen((string) $afterMatches[0][0])];

            if ($beforeSpan !== $afterSpan) {
                return $subject;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function analyze(string $pattern): array
    {
        $analysis = new AnalysisService(RegexParser::create());
        $service = new LintService($analysis, new PatternSourceCollection([]));
        $result = $service->analyze(
            [new PatternOccurrence($pattern, 'test.php', 1, 'preg_match')],
            new LintRequest(['.'], [], 0),
            null,
        );

        return $result->results[0] ?? [];
    }

    /**
     * @param array<int, string> $alphabet
     *
     * @return iterable<string>
     */
    private function allStringsOver(array $alphabet, int $maxLength): iterable
    {
        yield '';

        $frontier = [''];
        for ($length = 1; $length <= $maxLength; $length++) {
            $next = [];
            foreach ($frontier as $prefix) {
                foreach ($alphabet as $char) {
                    $next[] = $prefix.$char;
                }
            }

            foreach ($next as $string) {
                yield $string;
            }

            $frontier = $next;
        }
    }
}
