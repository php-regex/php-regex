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

namespace PHPRegex\Tests\TestUtils;

/**
 * Cross-checks extracted suite cases against the preg_match of the running
 * PHP, at extraction time only (the conformance test itself never consults
 * the live engine).
 *
 * Every preg_match call yields a verdict: a "Compilation failed: ... at
 * offset N" warning is a reject at body offset N; any other preg_match
 * warning (missing delimiter, unknown modifier) is a reject without a PCRE2
 * offset; no warning at all is an accept, including a false return, because
 * preg_match only returns false without a warning when the pattern compiled
 * and the match itself failed (backtrack or depth limit). A JIT compilation
 * warning is not a verdict either: ext/pcre only tries JIT after the
 * pattern compiled, and matches without JIT when that fails.
 *
 * A row whose expected verdict or offset differs from the live observation
 * is explained only by a known difference between PHP's compile context and
 * pcre2test's. An explained row gets a phpOverride that records what
 * preg_match observed; any other difference is reported as a disagreement.
 *
 * @phpstan-type Observation = array{verdict: string, offset: int|null, message: string|null}
 * @phpstan-type Override = array{reason: string, verdict: string, offset: int|null, pcre2Code: int|null}
 */
final class Pcre2LiveCrossCheck
{
    /**
     * Known differences between PHP's compile context and pcre2test's
     * default one, keyed by a stable reason id, mapped to the PCRE2 error
     * number pcre2test reports because of the difference.
     *
     * php-src (ext/pcre/php_pcre.c) sets PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK in
     * the compile context by default since PHP 8.1.0, so \K inside a
     * lookaround compiles in PHP while pcre2test rejects it with error 199.
     */
    private const CONTEXT_DIFFERENCES = [
        'allow-lookaround-bsk' => 199,
    ];

    /**
     * Human-readable description of each context difference, for the
     * conformance page.
     */
    private const CONTEXT_DIFFERENCE_NOTES = [
        'allow-lookaround-bsk' => 'PHP compiles every pattern with `PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK` (php-src default since PHP 8.1.0), so `\K` inside a lookaround compiles in PHP where pcre2test reports error 199.',
    ];

    /**
     * The known context differences, reason id to the PCRE2 error number
     * pcre2test reports for the cases they explain.
     *
     * @return array<string, int>
     */
    public static function contextDifferences(): array
    {
        return self::CONTEXT_DIFFERENCES;
    }

    /**
     * One-line description of each known context difference.
     *
     * @return array<string, string>
     */
    public static function contextDifferenceNotes(): array
    {
        return self::CONTEXT_DIFFERENCE_NOTES;
    }

    /**
     * Whether a PCRE_VERSION string ("10.48 2026-08-31") names the pinned
     * release: its leading version must equal the pin exactly.
     */
    public static function engineMatchesPin(string $pcreVersion, string $pin): bool
    {
        $leading = explode(' ', trim($pcreVersion), 2)[0];

        return $leading === $pin;
    }

    /**
     * Compiles one full PHP pattern string with preg_match on an empty
     * subject and reports what the engine did. The warning is consumed: no
     * error is left behind for the caller.
     *
     * @return Observation
     */
    public static function observe(string $pattern): array
    {
        $warnings = [];

        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        try {
            preg_match($pattern, '');
        } finally {
            restore_error_handler();
        }

        $messages = [];

        foreach ($warnings as $warning) {
            $message = str_starts_with($warning, 'preg_match(): ')
                ? substr($warning, \strlen('preg_match(): '))
                : $warning;

            if (1 === preg_match('/^Compilation failed: (.*) at offset (\d+)$/s', $message, $matches)) {
                return ['verdict' => 'reject', 'offset' => (int) $matches[2], 'message' => $matches[1]];
            }

            // ext/pcre only attempts JIT compilation once pcre2_compile has
            // succeeded, and falls back to the interpreter when it fails
            // (preg_match('/^(?(?C25)(?=abc)abcd|xyz)/', 'abcd') warns
            // "JIT compilation failed: feature is not supported by the JIT
            // compiler" and still returns 1): such a warning proves the
            // pattern compiled.
            if (str_starts_with($message, 'JIT compilation failed') || str_starts_with($message, 'Allocation of JIT memory failed')) {
                continue;
            }

            $messages[] = $message;
        }

        if ([] !== $messages) {
            return ['verdict' => 'reject', 'offset' => null, 'message' => $messages[0]];
        }

        return ['verdict' => 'accept', 'offset' => null, 'message' => null];
    }

    /**
     * Runs every assertable row through preg_match and compares the live
     * verdict and offset with the row's expected ones.
     *
     * @param list<array<string, mixed>> $rows rows in the extractor's canonical shape
     *
     * @return array{overrides: array<string, Override>, disagreements: list<string>}
     */
    public static function check(array $rows): array
    {
        $runner = new Pcre2CaseRunner();
        $overrides = [];
        $disagreements = [];

        foreach ($rows as $row) {
            $verdict = $row['verdict'] ?? null;

            if (null !== ($row['skipCategory'] ?? null) || !\is_string($verdict)) {
                continue;
            }

            $offset = \is_int($row['offset'] ?? null) ? $row['offset'] : null;
            $observed = self::observe($runner->phpPattern($row));

            if ($observed['verdict'] === $verdict && ('accept' === $verdict || $observed['offset'] === $offset)) {
                continue;
            }

            $id = \is_string($row['id'] ?? null) ? $row['id'] : '?';
            $code = \is_int($row['pcre2Code'] ?? null) ? $row['pcre2Code'] : null;
            $reason = null === $code ? false : array_search($code, self::CONTEXT_DIFFERENCES, true);

            if (\is_string($reason)) {
                // The live warning carries no PCRE2 error number, so the
                // override records the observed verdict and offset only.
                $overrides[$id] = [
                    'reason' => $reason,
                    'verdict' => $observed['verdict'],
                    'offset' => $observed['offset'],
                    'pcre2Code' => null,
                ];

                continue;
            }

            $disagreements[] = \sprintf(
                '%s %s: suite %s, preg_match %s',
                $id,
                $runner->phpPattern($row),
                self::describe($verdict, $offset, null === $code ? null : 'error '.$code),
                self::describe($observed['verdict'], $observed['offset'], $observed['message']),
            );
        }

        return ['overrides' => $overrides, 'disagreements' => $disagreements];
    }

    private static function describe(string $verdict, ?int $offset, ?string $detail): string
    {
        $text = $verdict;

        if (null !== $offset) {
            $text .= ' at offset '.$offset;
        }

        if (null !== $detail) {
            $text .= ' ('.$detail.')';
        }

        return $text;
    }
}
