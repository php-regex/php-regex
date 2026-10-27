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

namespace PHPRegex\Parser\Validation;

use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Exception\SemanticErrorException;
use PHPRegex\Parser\Node\LimitMatchNode;
use PHPRegex\Parser\Node\PcreVerbNode;
use PHPRegex\Parser\PcreFeature;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Syntax\PcreVerb;
use PHPRegex\Parser\Syntax\StartOptions;
use PHPRegex\Parser\Text\Bytes;
use PHPRegex\Parser\Text\Utf8;

/**
 * The checks of a verb, "(*COMMIT)", "(*MARK:name)", and of the settings
 * of the run that opens the pattern, "(*UTF)", "(*LIMIT_MATCH=10)": a name
 * PCRE2 does not know, a value it refuses, a setting written after the
 * run, settings that do not go together.
 *
 * Each needs the text of the verb and where the opening run ends, not the
 * nodes around it.
 *
 * An instance reads one text, the pattern or the payload of a "(*pla:...)"
 * parsed apart, and reports offsets in the whole pattern.
 *
 * @internal
 */
final class VerbChecks
{
    /**
     * The longest (*MARK), (*PRUNE), (*SKIP) or (*THEN) name, in code units.
     */
    private const MAX_VERB_NAME_LENGTH = 255;

    /**
     * The largest (*LIMIT_...=n) value PCRE still multiplies by ten.
     */
    private const MAX_LIMIT_VALUE_BEFORE_DIGIT = 429496728;

    /**
     * The verbs PCRE2 reads anywhere in the pattern; the settings of the
     * opening run are StartOptions::NAMES.
     */
    private const VALID_PCRE_VERBS = [
        // Backtracking control verbs
        'FAIL' => true, 'F' => true, 'ACCEPT' => true, 'COMMIT' => true,
        'PRUNE' => true, 'SKIP' => true, 'THEN' => true,
        // Definition verb
        'DEFINE' => true,
        // Mark verb (with optional :NAME argument)
        'MARK' => true,
        // Script run verbs (also as lowercase aliases)
        'script_run' => true, 'sr' => true,
        'atomic_script_run' => true, 'asr' => true,
    ];

    /**
     * Start-of-pattern settings PCRE2 10.45 added: settings where the PCRE2
     * judged is 10.45 or newer, unknown verbs on an older one, whether that
     * is the library PHP runs with or the one a targeted PHP version bundles.
     */
    private const PCRE_1045_SETTINGS = ['CASELESS_RESTRICT' => true, 'TURKISH_CASING' => true];

    /**
     * The start-of-pattern settings that take a number: "(*LIMIT_MATCH=10)".
     */
    private const LIMIT_VERBS = [
        'LIMIT_MATCH' => true, 'LIMIT_RECURSION' => true, 'LIMIT_DEPTH' => true, 'LIMIT_HEAP' => true,
    ];

    /**
     * @param PcreTarget  $target            the PHP and PCRE2 judged
     * @param string|null $pattern           the whole pattern, which the errors quote
     * @param string|null $source            the text the positions of the verbs point
     *                                       into: the pattern, or the payload of a
     *                                       "(*pla:...)" parsed apart; null when
     *                                       nothing says what was written
     * @param int         $offset            where that text starts in the pattern
     * @param bool        $utf               whether PCRE reads the pattern as UTF-8
     * @param int|null    $startOfPatternEnd where the run of settings that opens the
     *                                       pattern ends, or null when there is no
     *                                       text to read it from
     */
    public function __construct(
        private readonly PcreTarget $target,
        private readonly ?string $pattern = null,
        private readonly ?string $source = null,
        private readonly int $offset = 0,
        private readonly bool $utf = false,
        private readonly ?int $startOfPatternEnd = null,
    ) {}

    /**
     * The checks of the verbs of a whole pattern, $source, which also says
     * where its opening run of settings ends.
     */
    public static function forPattern(PcreTarget $target, ?string $pattern, ?string $source, bool $utf): self
    {
        return new self($target, $pattern, $source, 0, $utf, null === $source ? null : self::startOfPatternEnd($source));
    }

    /**
     * The same checks on another text of the pattern, which starts at
     * $offset in it.
     */
    public function reading(?string $source, int $offset): self
    {
        return new self($this->target, $this->pattern, $source, $offset, $this->utf, $this->startOfPatternEnd);
    }

    /**
     * A verb or a setting, "(*COMMIT)", "(*MARK:name)", "(*UTF)": its name,
     * where it stands, and the value or the name it takes.
     */
    public function verb(PcreVerbNode $node): void
    {
        $verbName = LibraryPcre::split('/[:=]/', $node->verb, 2)[0] ?? $node->verb;

        $setting = isset(StartOptions::NAMES[$verbName]);
        // A script run takes a body: its name closed with no colon is an
        // alphabetic name PCRE does not know.
        $known = isset(self::VALID_PCRE_VERBS[$verbName])
            && (1 !== LibraryPcre::match('/^[a-z]/', $verbName) || '' !== substr($node->verb, \strlen($verbName), 1));
        if (!$known
            && !($setting && (!isset(self::PCRE_1045_SETTINGS[$verbName]) || $this->target->supports(PcreFeature::CasingSettingVerbs)))) {
            // "(*)" is a "*" with nothing to repeat, refused past it from
            // PCRE2 10.47.
            if ('' === $node->verb) {
                $this->raise(
                    'Quantifier "*" does not follow a repeatable item: "(*)" names no verb.',
                    $this->target->faultOffset($node->startPosition + 2),
                    ErrorCode::QuantifierNothingToRepeat,
                );
            }

            // PCRE reports an unknown verb where its name ends, and past the
            // character after it, whole in UTF mode, an alphabetic assertion,
            // whose name starts with a lowercase letter, followed by no colon.
            $nameEnd = $node->startPosition + 2 + (1 === LibraryPcre::match('/^\w*+/', $verbName, $name) ? \strlen($name[0]) : 0);
            $width = $this->utf ? Utf8::width((string) $this->source, $nameEnd) : 1;
            $this->raise(
                \sprintf('Invalid or unsupported PCRE verb: "%s".', $verbName),
                match (true) {
                    1 === LibraryPcre::match('/^[a-z]/', $verbName) && ':' !== ($this->source[$nameEnd] ?? '') => $this->target->faultOffset($nameEnd + $width, $width),
                    default => $nameEnd,
                },
                ErrorCode::VerbInvalid,
            );
        }

        // PCRE reports these at the closing parenthesis.
        $closing = $node->getEndPosition() - 1;

        if ($setting) {
            $this->placement($verbName, $node->startPosition);
        }

        // Only the limits take a value after "=": after any other name PCRE
        // refuses the "=" where it stands. "(*=name)", a mark written short,
        // is refused further down.
        $equals = $node->startPosition + 2 + \strlen($verbName);
        if (!isset(self::LIMIT_VERBS[$verbName]) && '=' === ($this->source[$equals] ?? '') && '=' !== ($this->source[$node->startPosition + 2] ?? '')) {
            $this->raise(
                \sprintf('(*%s) takes no value: only the limits take one, as (*LIMIT_MATCH=10).', $verbName),
                1 === LibraryPcre::match('/^[a-z]/', $verbName) ? $this->target->faultOffset($equals + 1) : $equals,
                ErrorCode::VerbInvalid,
            );
        }

        if (isset(self::LIMIT_VERBS[$verbName]) && 1 !== LibraryPcre::match('/\A[A-Z_]++=\d++\z/', $node->verb)) {
            $this->raise(
                \sprintf('(*%s) needs a number: (*%s=10).', $verbName, $verbName),
                PcreVerb::limitValueErrorOffset((string) $this->source, $node->startPosition, $this->target) ?? $closing,
                ErrorCode::VerbInvalid,
            );
        }

        // A verb name holds at most 255 code units.
        if (1 === LibraryPcre::match('/^[A-Z]*+:(.*)$/s', $node->verb, $name) && \strlen($name[1]) > self::MAX_VERB_NAME_LENGTH) {
            $this->raise(
                \sprintf('The name of (*%s) is too long: PCRE takes at most %d code units.', $verbName, self::MAX_VERB_NAME_LENGTH),
                $closing,
                ErrorCode::VerbNameTooLong,
            );
        }

        if (isset(self::LIMIT_VERBS[$verbName]) && 1 === LibraryPcre::match('/=(\d++)\z/', $node->verb, $digits, \PREG_OFFSET_CAPTURE)) {
            $this->limitValue($digits[1][0], $node->startPosition + 2 + $digits[1][1]);
        }

        // "(*=name)" is read as a mark shorthand, but PCRE only knows "(*:".
        if (str_starts_with($node->verb, 'MARK=')) {
            $this->raise(
                '(*=...) is not a PCRE verb; a mark is written (*MARK:name) or (*:name).',
                $node->startPosition + 2,
                ErrorCode::VerbInvalid,
            );
        }

        if ('MARK' === $verbName && 1 !== LibraryPcre::match('/^MARK:./s', $node->verb)) {
            $this->raise(
                '(*MARK) must have a name: (*MARK:name) or (*:name).',
                $closing,
                ErrorCode::VerbMarkNameMissing,
            );
        }
    }

    /**
     * "(*LIMIT_MATCH=n)" read as a node of its own: where it stands, and
     * its value.
     */
    public function limitMatch(LimitMatchNode $node): void
    {
        $this->placement('LIMIT_MATCH', $node->startPosition);

        // The digits as written, leading zeros included.
        $written = null === $this->source ? '' : substr($this->source, $node->startPosition, $node->getEndPosition() - $node->startPosition);
        if (1 === LibraryPcre::match('/=(\d++)\)$/', $written, $digits, \PREG_OFFSET_CAPTURE)) {
            $this->limitValue($digits[1][0], $node->startPosition + $digits[1][1]);
        }
    }

    /**
     * "(*TURKISH_CASING)" needs UTF mode, and does not go with
     * "(*CASELESS_RESTRICT)": PCRE2 refuses both where the opening run of
     * settings ends, before it reads the rest.
     *
     * @param string $flags the flags of the pattern
     */
    public function casingSettings(string $flags): void
    {
        if (null === $this->source || null === $this->startOfPatternEnd || !$this->target->supports(PcreFeature::CasingSettingVerbs)) {
            return;
        }

        $settings = substr($this->source, 0, $this->startOfPatternEnd);
        if (!str_contains($settings, '(*TURKISH_CASING)')) {
            return;
        }

        if (str_contains($settings, '(*CASELESS_RESTRICT)')) {
            $this->raise(
                '(*TURKISH_CASING) and (*CASELESS_RESTRICT) cannot be used together.',
                $this->startOfPatternEnd,
                ErrorCode::VerbConflictingCasings,
            );
        }

        if (!str_contains($flags, 'u') && 1 !== LibraryPcre::match('/\(\*UTF8?\)/', $settings)) {
            $this->raise(
                str_contains($settings, '(*UCP)')
                    ? '(*TURKISH_CASING) needs UTF mode: UCP alone is not enough.'
                    : '(*TURKISH_CASING) needs UTF mode.',
                $this->startOfPatternEnd,
                ErrorCode::VerbTurkishCasingWithoutUtf,
                'Add the "u" modifier.',
            );
        }
    }

    /**
     * Where the opening run of start-of-pattern settings ends.
     */
    private static function startOfPatternEnd(string $source): int
    {
        $names = implode('|', array_keys(StartOptions::NAMES));

        // The run may be empty, so the pattern always matches: the 0 branch
        // is unreachable and only there for the type.
        return 1 === LibraryPcre::match('/\A(?:\(\*(?:'.$names.')(?:=\d*+)?\))*+/', $source, $matches) ? \strlen($matches[0]) : 0;
    }

    /**
     * A setting PCRE reads only in the run that opens the pattern.
     */
    private function placement(string $verbName, int $start): void
    {
        // One that starts where the run of well-formed settings ends is still
        // read there, and judged on its value; one in the body of "(*pla:...)"
        // stands where that body does.
        if (null === $this->startOfPatternEnd || $start + $this->offset <= $this->startOfPatternEnd) {
            return;
        }

        // Anywhere else PCRE does not know the name, and stops where it ends.
        $this->raise(
            \sprintf('(*%s) is only recognized at the very start of the pattern.', $verbName),
            $start + 2 + \strlen($verbName),
            ErrorCode::VerbMisplaced,
            'Move it before anything else in the pattern.',
        );
    }

    /**
     * PCRE reads a (*LIMIT_...=n) value digit by digit and refuses the digit
     * that would take it past 4294967289, where it stops.
     */
    private function limitValue(string $digits, int $start): void
    {
        $value = 0;
        foreach (Bytes::split($digits) as $index => $digit) {
            if ($value > self::MAX_LIMIT_VALUE_BEFORE_DIGIT) {
                $this->raise(
                    \sprintf('The value %s is too large for a (*LIMIT_...) setting: PCRE takes at most 4294967289.', $digits),
                    // Before PCRE2 10.45, past the digit it refuses.
                    $start + $index + ($this->target->supports(PcreFeature::LimitValueErrorOnFaultingCharacter) ? 0 : 1),
                    ErrorCode::VerbLimitTooLarge,
                );
            }

            $value = $value * 10 + (int) $digit;
        }
    }

    private function raise(string $message, int $position, ErrorCode $code, ?string $hint = null): never
    {
        throw new SemanticErrorException($message, $code, $position + $this->offset, $this->pattern, hint: $hint);
    }
}
