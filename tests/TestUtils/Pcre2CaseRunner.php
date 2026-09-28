<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RegexParser\Tests\TestUtils;

use RegexParser\Exception\ParserException;
use RegexParser\Exception\RegexParserExceptionInterface;
use RegexParser\Internal\PatternParser;
use RegexParser\Node\RegexNode;
use RegexParser\NodeVisitor\ValidatorNodeVisitor;
use RegexParser\Regex;

/**
 * Runs one suite case through Regex::validate() on the product path
 * (default options, runtime PCRE validation off) and applies the
 * conformance rules of the harness: the verdict must always match the
 * expected one, and when both sides reject the body-relative offsets must be
 * equal. Error messages are never compared: PCRE2's wording and the
 * library's wording differ by design, so a shared rejection reported at a
 * different offset is an offset defect, whether the library is off by a few
 * bytes or rejected the pattern for a different reason.
 *
 * The expected outcome is the pinned PCRE2 output, unless the case carries a
 * phpOverride: then it is what preg_match observed under PHP's own compile
 * context when the fixture was extracted. When the PCRE2 floor release
 * reported a shared rejection at another offset than the pin, a library
 * offset equal to the floor's passes too (outcome "pass-either-offset");
 * any other offset is an offset defect on both engines.
 */
final readonly class Pcre2CaseRunner
{
    private const BRACKET_CLOSERS = ['(' => ')', '[' => ']', '{' => '}', '<' => '>'];

    private Regex $regex;

    public function __construct()
    {
        // No cache: every case is parsed afresh, never read back from a
        // cached AST of an earlier library build.
        $this->regex = Regex::new(['cache' => null]);
    }

    /**
     * Runs one suite case in the extractor's canonical shape.
     *
     * @param array<string, mixed> $case
     *
     * @return array<string, mixed> outcome keyed id, outcome, verdict,
     *                              offset, error, errorClass
     */
    public function run(array $case): array
    {
        $phpPattern = $this->phpPattern($case);
        $result = $this->regex->validate($phpPattern);

        $verdict = $result->isValid ? 'accept' : 'reject';
        $error = null;
        $offset = null;
        $errorClass = null;

        if (!$result->isValid) {
            $error = null === $result->error ? null : explode("\n", $result->error)[0];
            $errorClass = self::classifyThrowable($this->probeFailure($phpPattern));
            $offset = $this->normalizeOffset($result->offset, $phpPattern);
        }

        $outcome = $this->outcome($case, $verdict, $offset, $errorClass);

        return [
            'id' => self::rowString($case, 'id'),
            'outcome' => $outcome,
            'verdict' => $verdict,
            'offset' => $offset,
            'error' => $error,
            'errorClass' => $errorClass,
        ];
    }

    /**
     * Rebuilds a PHP pattern string from a suite case, keeping the original
     * delimiter when PHP would read the body unchanged between it and its
     * closer and switching to another delimiter otherwise: escaping would
     * insert a byte and shift every later offset the harness compares.
     *
     * @param array<string, mixed> $case
     */
    public function phpPattern(array $case): string
    {
        $delimiter = self::rowString($case, 'delimiter');
        $body = self::rowString($case, 'pattern');
        $flags = self::rowString($case, 'flags');
        [$delimiter, $closer] = $this->safeDelimiterFor($delimiter, $body);

        return $delimiter.$body.$closer.$flags;
    }

    /**
     * A principled reject is one the library signalled with its own
     * exception family; anything else escaping validate() is a crash.
     */
    public static function classifyThrowable(\Throwable $throwable): string
    {
        return $throwable instanceof RegexParserExceptionInterface ? 'principled' : 'crash';
    }

    /**
     * Shifts a full-string-relative offset to the body-relative coordinate
     * the pinned PCRE2 offsets use. Only the delimiter and flag layer of
     * the library reports full-string offsets, and it is the only failure
     * the pattern header can produce, so a throw there marks the coordinate.
     */
    public function normalizeOffset(?int $offset, string $phpPattern): ?int
    {
        if (null === $offset) {
            return null;
        }

        try {
            PatternParser::extractPatternAndFlags($phpPattern);
        } catch (ParserException) {
            return max(0, $offset - 1);
        }

        return $offset;
    }

    /**
     * Reads a string field of a canonical case row.
     *
     * @param array<array-key, mixed> $case
     */
    private static function rowString(array $case, string $key): string
    {
        $value = $case[$key] ?? '';

        return \is_string($value) ? $value : '';
    }

    /**
     * Reads a nullable integer field of a canonical case row.
     *
     * @param array<array-key, mixed> $case
     */
    private static function rowNullableInt(array $case, string $key): ?int
    {
        $value = $case[$key] ?? null;

        return \is_int($value) ? $value : null;
    }

    /**
     * Picks the delimiter pair to rebuild a case with: the original one when
     * PHP would read the whole body between it and its closer, otherwise the
     * first candidate for which it would.
     *
     * @return array{0: string, 1: string} opening delimiter and its closer
     */
    private function safeDelimiterFor(string $delimiter, string $body): array
    {
        $candidates = ['#', '~', '%', '+', ';', ',', ':', '=', '!', '"', "'", '`', '-', '_', '@', '&',
            '$', '^', '?', '*', '(', '[', '{', '<', '/'];

        foreach ([$delimiter, ...$candidates] as $candidate) {
            if (self::delimiterEnclosesBody($candidate, $body)) {
                return [$candidate, self::BRACKET_CLOSERS[$candidate] ?? $candidate];
            }
        }

        throw new \RuntimeException('No delimiter available to rebuild the pattern without escaping.');
    }

    /**
     * Whether PHP, scanning "<opener><body><closer>" for the closing
     * delimiter the way ext/pcre does (a backslash always takes the next
     * byte with it; bracket pairs nest), would stop exactly at the closer
     * appended after the body. An escaped delimiter inside the body is fine:
     * PHP hands it to PCRE2 as written, exactly like pcre2test does.
     */
    private static function delimiterEnclosesBody(string $opener, string $body): bool
    {
        $closer = self::BRACKET_CLOSERS[$opener] ?? $opener;
        $depth = 1;
        $length = \strlen($body);

        for ($i = 0; $i < $length; $i++) {
            $char = $body[$i];

            if ('\\' === $char) {
                if ($i + 1 === $length) {
                    // The trailing backslash would escape the closer.
                    return false;
                }

                $i++;

                continue;
            }

            if ($char === $closer) {
                $depth--;

                if (0 === $depth) {
                    return false;
                }

                continue;
            }

            if ($char === $opener) {
                $depth++;
            }
        }

        return 1 === $depth;
    }

    /**
     * Compares the measured outcome of a case with the expected one.
     *
     * @param array<string, mixed> $case
     */
    private function outcome(array $case, string $verdict, ?int $offset, ?string $errorClass): string
    {
        [$expected, $expectedOffset] = self::expectation($case);

        if ('accept' === $expected) {
            if ('accept' === $verdict) {
                return 'pass';
            }

            return 'crash' === $errorClass ? 'crash' : 'false-reject';
        }

        if ('accept' === $verdict) {
            return 'false-accept';
        }

        if ('crash' === $errorClass) {
            return 'crash';
        }

        // Where the PCRE2 floor release reports the shared rejection at
        // another offset than the pin, no single offset is right on both
        // supported engines: matching either one passes, as its own outcome.
        $floor = $case['floor'] ?? null;
        $floorOffset = \is_array($floor) ? self::rowNullableInt($floor, 'offset') : $expectedOffset;

        if ($floorOffset !== $expectedOffset) {
            return $offset === $expectedOffset || $offset === $floorOffset ? 'pass-either-offset' : 'offset-defect';
        }

        return $expectedOffset === $offset ? 'pass' : 'offset-defect';
    }

    /**
     * The expected verdict and body-relative offset of a case: the
     * phpOverride's when PHP's compile context differs from pcre2test's for
     * this case, the pinned suite output's otherwise.
     *
     * @param array<string, mixed> $case
     *
     * @return array{0: string, 1: int|null}
     */
    private static function expectation(array $case): array
    {
        $override = $case['phpOverride'] ?? null;

        if (\is_array($override)) {
            return [self::rowString($override, 'verdict'), self::rowNullableInt($override, 'offset')];
        }

        return [self::rowString($case, 'verdict'), self::rowNullableInt($case, 'offset')];
    }

    /**
     * Re-derives the throwable validate() swallowed, by replaying the same
     * stages on the paths that throw: the delimiter and flag layer, the
     * lexer and parser, then the AST validators.
     */
    private function probeFailure(string $phpPattern): \Throwable
    {
        try {
            PatternParser::extractPatternAndFlags($phpPattern);
        } catch (\Throwable $patternHeaderFailure) {
            return $patternHeaderFailure;
        }

        try {
            $ast = $this->regex->parse($phpPattern);

            if (!$ast instanceof RegexNode) {
                throw new \RuntimeException(\sprintf('validate() rejected %s but parsing stayed tolerant.', $phpPattern));
            }

            $ast->accept(new ValidatorNodeVisitor());
        } catch (\Throwable $failure) {
            return $failure;
        }

        return new \RuntimeException(\sprintf('validate() rejected %s but no stage threw.', $phpPattern));
    }
}
