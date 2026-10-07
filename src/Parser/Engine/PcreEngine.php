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

namespace PHPRegex\Parser\Engine;

use PHPRegex\Parser\Internal\LibraryPcre;
use PHPRegex\Parser\Internal\NoJit;

/**
 * The one door a pattern the library was given goes through to the running
 * PCRE2 engine.
 *
 * The engine runs without its JIT, which crashes PHP on some pattern and
 * subject pairs (PCRE2 10.40 to 10.49): "(*NO_JIT)" leads the pattern, right
 * after its opening delimiter. A warning the pattern raises is captured, not
 * silenced, and reported for the pattern as the caller wrote it. Limits asked
 * for are set for the call and the ini is left as it was found. Where ini_set()
 * is disabled, limits asked for are reported as not set, in the answer, and
 * a pattern the verb finds no place in runs with the JIT as the caller left it.
 */
final readonly class PcreEngine
{
    private const CLOSING_BRACKETS = ['(' => ')', '[' => ']', '{' => '}', '<' => '>'];

    /**
     * White space PHP skips before the delimiter: led by it, a pattern is
     * a key of its own in PHP's cache of compiled patterns, so it is
     * compiled again rather than served as the JIT compiled it earlier.
     */
    private const FRESH_CACHE_KEY = "\v";

    private const LIMITS_UNAVAILABLE = 'The PCRE limits could not be set: ini_set() is disabled';

    /**
     * The pattern as the engine runs it: "(*NO_JIT)" right after the opening
     * delimiter, the pattern moved to another delimiter when the verb holds
     * its own, to a bracket pair when its body holds every other one. The
     * pattern as it is when PHP refuses its delimiters, or when no delimiter
     * is left for it; the engine then turns the JIT off for the call.
     */
    public function prepare(string $pattern): string
    {
        return $this->withVerb($pattern) ?? $pattern;
    }

    /**
     * Why the running engine refuses the pattern, or null when it compiles
     * it. An error met while matching the empty subject is no compilation
     * error: the pattern compiles.
     */
    public function compile(string $pattern): ?PcreError
    {
        return $this->run($pattern, static fn (string $prepared): int|false => preg_match($prepared, ''))['error'];
    }

    /**
     * What preg_match() answers for the pattern and the subject, under the
     * limits given (the ini ones when null), the search starting at the
     * offset as preg_match() reads it: a byte offset in the whole subject,
     * counted from its end when negative, so "\b" and a lookbehind still see
     * what lies before it. An offset past the end of the subject is a null
     * verdict, as preg_match() returns false for it.
     */
    public function match(string $pattern, string $subject, ?PcreLimits $limits = null, int $offset = 0): PcreMatch
    {
        $groups = [];
        $outcome = $this->run(
            $pattern,
            static function (string $prepared) use ($subject, $offset, &$groups): int|false {
                return preg_match($prepared, $subject, $groups, 0, $offset);
            },
            $limits,
        );

        if (false === $outcome['result']) {
            return new PcreMatch(null, [], $outcome['error']->message ?? $outcome['lastErrorMessage'], $outcome['lastError']);
        }

        return new PcreMatch(1 === $outcome['result'], $groups);
    }

    /**
     * What preg_match() answers when called without $matches, as most code
     * calls it: PHP then retries an empty match at the same offset with
     * NOTEMPTY_ATSTART | ANCHORED, which match() does not do. It takes no
     * offset: preg_match() takes one only after $matches, and passing
     * $matches, even skipped by a named offset, is what match() does.
     */
    public function test(string $pattern, string $subject, ?PcreLimits $limits = null): PcreMatch
    {
        $outcome = $this->run(
            $pattern,
            static fn (string $prepared): int|false => preg_match($prepared, $subject),
            $limits,
        );

        if (false === $outcome['result']) {
            return new PcreMatch(null, [], $outcome['error']->message ?? $outcome['lastErrorMessage'], $outcome['lastError']);
        }

        return new PcreMatch(1 === $outcome['result']);
    }

    /**
     * Every match of the whole pattern in the subject, in order; null when
     * the engine refuses the pattern or gives up.
     *
     * @return list<string>|null
     */
    public function matchAll(string $pattern, string $subject, ?PcreLimits $limits = null): ?array
    {
        $matches = [];
        $outcome = $this->run(
            $pattern,
            static function (string $prepared) use ($subject, &$matches): int|false {
                return preg_match_all($prepared, $subject, $matches);
            },
            $limits,
        );

        if (false === $outcome['result']) {
            return null;
        }

        return $matches[0];
    }

    private function withVerb(string $pattern): ?string
    {
        $prepared = NoJit::pattern($pattern);
        if ($prepared !== $pattern) {
            return $prepared;
        }

        $parts = NoJit::split($pattern);
        if (null === $parts) {
            return null;
        }

        [, $body, , $flags] = $parts;
        foreach (self::CLOSING_BRACKETS as $open => $close) {
            if (self::closesLast($body, $open, $close)) {
                return $open.NoJit::VERB.$body.$close.$flags;
            }
        }

        return null;
    }

    /**
     * Whether the body, between the brackets, leaves the closing one as the
     * one that closes the opening one: PHP counts the brackets no backslash
     * escapes.
     */
    private static function closesLast(string $body, string $open, string $close): bool
    {
        $depth = 1;
        $length = \strlen($body);
        for ($index = 0; $index < $length; $index++) {
            $character = $body[$index];
            // The body PHP read never ends on a backslash it did not pair.
            if ('\\' === $character) {
                $index++;

                continue;
            }

            if ($close === $character && 0 === --$depth) {
                return false;
            }

            if ($open === $character) {
                $depth++;
            }
        }

        return 1 === $depth;
    }

    /**
     * Runs the call on the prepared pattern, with an error handler catching
     * the warning PHP raises for it, the limits set and the JIT turned off
     * when the verb found no place.
     *
     * Each setting is put back from the value ini_set() returned when it was
     * changed, so a disabled ini_get() changes nothing. Where ini_set() is
     * disabled, a call under limits gets no answer and an error saying so;
     * a pattern that needs the JIT off runs as it is, under the caller's
     * settings.
     *
     * @param \Closure(string): (int|false) $call
     *
     * @return array{result: int|false, error: PcreError|null, lastError: int, lastErrorMessage: string}
     */
    private function run(string $pattern, \Closure $call, ?PcreLimits $limits = null): array
    {
        $canSet = \function_exists('ini_set');
        if (null !== $limits && !$canSet) {
            // Reached only where ini_set() is disabled; the tests run that case in a child PHP process.
            return [
                'result' => false,
                'error' => null,
                'lastError' => \PREG_INTERNAL_ERROR,
                'lastErrorMessage' => self::LIMITS_UNAVAILABLE,
            ];
        }

        $prepared = $this->withVerb($pattern);
        $shift = null === $prepared ? 0 : \strlen(NoJit::VERB);
        $jitOff = $canSet && null === $prepared && null !== NoJit::split($pattern);
        $runnable = $prepared ?? ($jitOff ? self::FRESH_CACHE_KEY.$pattern : $pattern);

        $settings = [];
        if (null !== $limits) {
            $settings['pcre.backtrack_limit'] = (string) $limits->backtrackLimit;
            $settings['pcre.recursion_limit'] = (string) $limits->recursionLimit;
        }
        if ($jitOff) {
            $settings['pcre.jit'] = '0';
        }

        $saved = [];
        $warning = null;

        try {
            foreach ($settings as $key => $value) {
                $previous = ini_set($key, $value);
                if (false !== $previous) {
                    $saved[$key] = $previous;
                }
            }

            set_error_handler(static function (int $errno, string $message) use (&$warning): bool {
                $warning ??= $message;

                return true;
            });

            try {
                $result = $call($runnable);
                $lastError = preg_last_error();
                $lastErrorMessage = preg_last_error_msg();
            } finally {
                restore_error_handler();
            }
        } finally {
            self::restore($saved);
        }

        return [
            'result' => $result,
            'error' => false === $result && null !== $warning ? self::error($warning, $shift) : null,
            'lastError' => $lastError,
            'lastErrorMessage' => $lastErrorMessage,
        ];
    }

    /**
     * Puts each setting back as the caller had it, without a warning about
     * a value PHP reads loosely reaching any error handler: PHP already gave
     * it when the caller set that value.
     *
     * @param array<string, string> $saved
     */
    private static function restore(array $saved): void
    {
        if ([] === $saved) {
            return;
        }

        set_error_handler(static fn (): bool => true, \E_WARNING);

        try {
            foreach ($saved as $key => $value) {
                ini_set($key, $value);
            }
        } finally {
            restore_error_handler();
        }
    }

    /**
     * The warning as PHP words it for the pattern as written: no function
     * name, no "Compilation failed: ", the offset counted without the verb.
     * The warning is read under the library's own limits, so a caller's
     * tiny limit does not blank it.
     */
    private static function error(string $warning, int $shift): PcreError
    {
        $message = (string) LibraryPcre::replace('/^\w+\(\):\s*/', '', $warning);
        $compilation = (string) LibraryPcre::replace('/^Compilation failed:\s*/', '', $message);
        if ($compilation === $message || 1 !== LibraryPcre::match('/ at offset (\d+)$/', $compilation, $matches)) {
            return new PcreError($compilation);
        }

        $offset = max(0, (int) $matches[1] - $shift);

        return new PcreError(substr($compilation, 0, -\strlen($matches[0])).' at offset '.$offset, $offset);
    }
}
