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
 */
trait PcreGates
{
    /**
     * Skip unless the running PCRE2 reads the release the row is verified
     * against.
     */
    private function requiresPcre2(string $release, string $subject): void
    {
        if (!PcreTarget::runtime()->pcreAtLeast($release)) {
            $this->markTestSkipped(\sprintf('%s is verified against PCRE2 %s and later; PCRE2 %s reports it differently.', $subject, $release, \PCRE_VERSION));
        }
    }

    /**
     * Skip unless the running PHP is the floor or newer.
     *
     * @param non-empty-string $floor
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
     * @param non-empty-string $through the last PHP the row holds on, two parts, as "8.4"
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
