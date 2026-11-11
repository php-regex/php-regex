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

namespace PHPRegex\Tests\Support;

use PHPRegex\Parser\PcreTarget;

/**
 * The one idiom for "this row only holds from PCRE2 X, or from PHP Y": one
 * call per gate, the message composed here so every skip reads the same.
 * The release comparison is PcreTarget's alone — the date-suffixed
 * PCRE_VERSION and the pre-release spellings are its to parse — and the
 * releases a cutoff may name stop at PcreGateLimits::MAX_KNOWN_RELEASE.
 * The versions a gate takes are typed as PcreGateLimits' lists, so the
 * analysis refuses a literal outside them at the call site.
 */
trait PcreGates
{
    /**
     * Skip unless the running PCRE2 reads the release the row is verified
     * against.
     *
     * @param value-of<PcreGateLimits::KNOWN_RELEASES> $release
     */
    private function requiresPcre2(string $release, string $subject): void
    {
        if (!PcreTarget::runtime()->pcreAtLeast($release)) {
            $this->markTestSkipped(\sprintf('%s is verified against PCRE2 %s and later; PCRE2 %s reports it differently.', $subject, $release, \PCRE_VERSION));
        }
    }

    /**
     * Skip unless the running PCRE2 is the very release a measurement was
     * taken on: a length, a count of steps, read on one release, may differ
     * on another.
     */
    private function requiresPcre2Release(string $release, string $subject): void
    {
        if (!str_starts_with(\PCRE_VERSION, $release.' ')) {
            $this->markTestSkipped(\sprintf('%s is verified against PCRE2 %s; PCRE2 %s may answer otherwise.', $subject, $release, \PCRE_VERSION));
        }
    }

    /**
     * The gate above for a data-provided pattern, read from the map the test
     * keeps of the rows verified against a newer release than the floor:
     * each release lists its patterns, and a pattern no release lists runs
     * on every engine.
     *
     * @param array<value-of<PcreGateLimits::KNOWN_RELEASES>, list<string>> $releases
     */
    private function requiresPcre2For(string $pattern, array $releases): void
    {
        foreach ($releases as $release => $patterns) {
            if (\in_array($pattern, $patterns, true)) {
                $this->requiresPcre2($release, $pattern);

                return;
            }
        }
    }

    /**
     * Skip unless the running PHP is the floor or newer.
     *
     * @param value-of<PcreGateLimits::KNOWN_PHP_VERSIONS> $floor
     */
    private function requiresPhp(string $floor, string $subject, string $reason): void
    {
        if (\PHP_VERSION_ID < self::pcreGatePhpVersionId($floor)) {
            $this->markTestSkipped(\sprintf('%s is verified against PHP %s and later; PHP %s %s.', $subject, $floor, \PHP_VERSION, $reason));
        }
    }

    /**
     * Skip once the running PHP is newer than the last one the row is
     * verified against.
     *
     * @param value-of<PcreGateLimits::KNOWN_PHP_VERSIONS> $through the last PHP the row holds on, two parts, as "8.4"
     */
    private function forbidsPhp(string $through, string $subject, string $reason): void
    {
        // The minor after the named one is the first the row no longer
        // holds on: PHP_VERSION_ID carries the patch level, the named
        // version does not, and 8.4.26 is still "8.4 and earlier".
        if (\PHP_VERSION_ID >= self::pcreGatePhpVersionId($through) + 100) {
            $this->markTestSkipped(\sprintf('%s is verified against PHP %s and earlier; PHP %s %s.', $subject, $through, \PHP_VERSION, $reason));
        }
    }

    /**
     * Skip a row PHP 8.1 refuses and PHP 8.2 reads: a pattern with the n
     * modifier, or with a NUL byte anywhere.
     */
    private function requiresPhpReading(string $pattern): void
    {
        $subject = addcslashes($pattern, "\0..\37");
        if (str_contains($pattern, "\0")) {
            $this->requiresPhp('8.2', $subject, 'refuses a NUL byte in a pattern');
        }

        // The modifiers are the letters past the closing delimiter.
        if (1 === \preg_match('/[A-Za-z]*+$/', $pattern, $modifiers) && str_contains($modifiers[0], 'n') && 1 !== \preg_match('/^\s*+[A-Za-z]/', $pattern)) {
            $this->requiresPhp('8.2', $subject, 'does not read the n modifier');
        }
    }

    /**
     * The gate above for every pattern PHP code passes to a preg_* call as
     * a string literal.
     */
    private function requiresPhpReadingCode(string $code): void
    {
        \preg_match_all('/\bpreg_\w+\(\s*+(?:\'((?:[^\'\\\\]|\\\\.)*+)\'|"((?:[^"\\\\]|\\\\.)*+)")/s', $code, $calls, \PREG_SET_ORDER);
        foreach ($calls as $call) {
            $this->requiresPhpReading(isset($call[2]) ? stripcslashes($call[2]) : str_replace(['\\\\', "\\'"], ['\\', "'"], $call[1] ?? ''));
        }
    }

    /**
     * Skip when the running engine refuses to compile the pattern — the
     * probe, never a release: engines diverge where no table says so. The
     * label, when given, names the family the pattern stands for, beside
     * the pattern itself in the message.
     */
    private function requiresCompile(string $pattern, ?string $label = null): void
    {
        if (false === @preg_match($pattern, '')) {
            if (null === $label) {
                $this->markTestSkipped(\sprintf('%s does not run on PCRE2 %s.', $pattern, \PCRE_VERSION));
            }

            $this->markTestSkipped(\sprintf('%s (%s) does not run on PCRE2 %s.', $pattern, $label, \PCRE_VERSION));
        }
    }

    /**
     * Skip when the interpreter of the running engine keeps, after a
     * subroutine call, what the groups inside the called one captured — the
     * probe, never a release. PCRE2 gives them their earlier values back;
     * the interpreter of the PCRE2 10.42 that Ubuntu 24.04 packages keeps
     * them, where its own JIT, the 10.42 PHP bundles and the later releases
     * do not. The probe asks the interpreter, so that the answer does not
     * turn on whether PHP ran the pattern through the JIT.
     */
    private function requiresCallCapturesGivenBack(string $pattern): void
    {
        preg_match('/(*NO_JIT)(?(DEFINE)(?<pair>(\d)(\d)))((?&pair)(?&pair))/', '1234', $written);
        if (['', ''] !== [$written[2] ?? null, $written[3] ?? null]) {
            $this->markTestSkipped(\sprintf('%s reads the groups inside a called one, and the interpreter of PCRE2 %s keeps what a call captured in them.', $pattern, \PCRE_VERSION));
        }
    }

    /**
     * Skip unless the running engine checks the size limit of a pattern as
     * the library judges its release to — the probe, never a release. From
     * PCRE2 10.48 each branch is checked on its own, and PCRE2 gives that
     * fix to the distributors of 10.39 to 10.47: a build that carries it
     * still names the release tagged without it, as the PCRE2 10.46 of
     * Ubuntu 26.04 does from its package 10.46-1ubuntu0.1, and refuses a
     * pattern too large that is wrong elsewhere too with another error, or
     * at another offset, than the release as tagged. The library judges a
     * release as PCRE2 tagged it, the 10.40 PHP bundles from 8.2.35 aside.
     *
     * The probe holds two branches too large only together, then a
     * reference to no group: checked together, the pattern is too large;
     * checked apart, the reference is met first.
     */
    private function requiresSizeLimitCheckedAsReleased(string $pattern): void
    {
        $apart = !str_contains((string) EngineProbe::warning('/(?:[^a]){5000}|(?:[^a]){4000}\\k<n>(?:[^a]){1000}/'), 'regular expression is too large');
        if ($apart !== PcreTarget::runtime()->measuresBranchesApart()) {
            $this->markTestSkipped(\sprintf('%s is verified against the size limit check of PCRE2 %s as released; this build checks %s.', $pattern, \PCRE_VERSION, $apart ? 'each branch on its own, as PCRE2 10.48 does' : 'a branch with those before it, as the releases before PCRE2 10.48 do'));
        }
    }

    /**
     * Named for the trait, never for the class it lands in: a class's own
     * helper of another name must not silently replace the gate arithmetic.
     *
     * @param non-empty-string $version a PHP version as exactly two parts, as "8.4"
     */
    private static function pcreGatePhpVersionId(string $version): int
    {
        $parts = \explode('.', $version);
        if (2 !== \count($parts)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a PHP version as two parts, as "8.4".', $version));
        }

        [$major, $minor] = \array_map(intval(...), $parts);

        return $major * 10000 + $minor * 100;
    }
}
