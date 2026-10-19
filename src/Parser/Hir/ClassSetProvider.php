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

namespace PHPRegex\Parser\Hir;

use PHPRegex\Parser\Cache\StaticCaches;
use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\Engine\NoJit;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\ControlCharNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\PosixClassNode;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\UnicodePropNode;
use PHPRegex\Parser\PcreFeature;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Syntax\PropertyNames;
use PHPRegex\Parser\Text\Ascii;

/**
 * The exact set of characters one atom matches, asked of the running PCRE:
 * the atom runs over a subject holding every character of the alphabet
 * once, in order, and each run of matches is a range of the set. The answer
 * is kept for the process, keyed by the atom and its options, and emptied
 * with the library's other caches. The probe is the library's own regex: it
 * runs under the library's floor of PCRE limits, whatever the caller set.
 *
 * Two kinds of atom are answered without a scan of their own. A class that
 * is not caseless is the union of its members: each type, property or POSIX
 * class asked as an atom of its own, so scanned once whatever class holds
 * it, each character and range read as written, a negated class the rest of
 * the alphabet, but under the ASCII options. Under /u a caseless ASCII
 * character is itself and its other case, but "k" and "s", which Unicode
 * folds with a third character: those are scanned. A class the parser does
 * not read as members it knows is scanned whole.
 *
 * @internal
 */
final class ClassSetProvider
{
    /**
     * Where each UTF-8 length starts in the Unicode subject: one byte up to
     * U+007F, two up to U+07FF, three up to U+FFFF but the surrogates, four
     * beyond.
     */
    private const TWO_BYTES_AT = 0x80;

    private const THREE_BYTES_AT = 0x80 + 0x780 * 2;

    private const AFTER_SURROGATES_AT = self::THREE_BYTES_AT + (0xD800 - 0x800) * 3;

    private const FOUR_BYTES_AT = self::AFTER_SURROGATES_AT + (0x10000 - 0xE000) * 3;

    private const DELIMITERS = ['/', '#', '~', '%', '!', '@', ';', "\x01"];

    /**
     * How many characters one engine call scans.
     */
    private const BLOCK_SIZE = 16384;

    /**
     * The general categories caseless matching leaves as they are: all but
     * Lu, Ll and Lt, which it reads otherwise from PCRE2 10.45.
     */
    private const CASE_FREE_CATEGORIES = [
        'L', 'Lm', 'Lo', 'M', 'Mn', 'Mc', 'Me', 'N', 'Nd', 'Nl', 'No',
        'P', 'Pc', 'Pd', 'Ps', 'Pe', 'Pi', 'Pf', 'Po', 'S', 'Sm', 'Sc', 'Sk', 'So',
        'Z', 'Zs', 'Zl', 'Zp', 'C', 'Cc', 'Cf', 'Cs', 'Co', 'Cn',
    ];

    private static ?string $unicodeSubject = null;

    private static ?string $byteSubject = null;

    /**
     * @var array<string, CharSet|false>
     */
    private static array $sets = [];

    private static ?RegexParser $parser = null;

    /**
     * Whether a bare script name, "\p{Han}", means the script alone for the
     * target, as before PCRE2 10.40, while the running engine reads it as
     * the script extensions: the atoms are then asked with "sc:" (see
     * scriptsAlone()).
     */
    public static function readsScriptsAlone(?PcreTarget $target): bool
    {
        return null !== $target
            && !$target->supports(PcreFeature::LoosePropertyNames)
            && PcreTarget::runtime()->supports(PcreFeature::LoosePropertyNames);
    }

    /**
     * The atom with each "\p{Script}" and "\P{^Script}" a script table
     * lookup, "\p{sc:Script}": what a script name meant before PCRE2 10.40.
     * A backslash escaped by another is left alone.
     */
    public static function scriptsAlone(string $atom): string
    {
        return LibraryPcre::replaceCallback(
            '/(?<!\\\\)((?:\\\\\\\\)*+\\\\[pP]\{)(\^?)([A-Za-z_]++)\}/',
            static fn (array $match): string => PropertyNames::isScriptBefore1040($match[3])
                ? $match[1].$match[2].'sc:'.$match[3].'}'
                : $match[0],
            $atom,
        ) ?? $atom;
    }

    /**
     * The characters the atom matches, or null when PCRE refuses it or
     * gives up on it.
     *
     * The options are the ones in force where the atom stands, written as
     * an inline group writes them, letters set only: "i", "s", "x" or
     * "xx", "r", and the ASCII options "aD", "aS", "aW", "aP" (which holds
     * "aT"), "aT", or "a" for all of them. They are the whole scope: the
     * probe sets no pattern-wide /r, so an "(?-r)" under /r is the options
     * without "r".
     *
     * A scan the engine gave up under its limits answers null without
     * being kept: the next query scans again. A refusal to compile the
     * probe, or an atom that leaves no delimiter free, is kept.
     *
     * @param string $atom        one character's worth of pattern: a class, an escape, a dot
     * @param string $modifiers   the options in force at the atom, as above
     * @param string $startVerbs  the options the pattern opens with, such as "(*UCP)"
     *                            or "(*CR)": they change what a class or a dot matches
     * @param bool   $unicodeFlag whether the pattern's own /u flag is set: this PHP
     *                            passes PCRE2_UCP with it, so the probe carries the flag
     *                            only when the pattern does — a lone "(*UTF)" reads
     *                            UTF-8 without the Unicode properties
     */
    public static function query(string $atom, bool $unicode, string $modifiers, string $startVerbs = '', bool $unicodeFlag = true): ?CharSet
    {
        StaticCaches::register(self::class, self::clear(...));

        $atom = self::spelledOnce($atom);
        $modifiers = self::bearingOn($atom, $modifiers);
        $global = $unicodeFlag && $unicode ? 'u' : '';
        $key = $startVerbs.$global.'|'.$modifiers.':'.$atom;
        if (!isset(self::$sets[$key])) {
            // A scan of the 256 bytes costs less than reading the atom: only
            // the Unicode scans, over every code point, are worth saving.
            $set = ($unicode && $unicodeFlag ? self::caselessAscii($atom, $modifiers) : null)
                ?? ($unicode ? self::composed($atom, $modifiers, $startVerbs, $unicodeFlag) : null)
                ?? self::scan($atom, $unicode, $modifiers, $global, $startVerbs);
            if (null === $set) {
                return null;
            }

            self::$sets = StaticCaches::makeRoom(self::$sets);
            self::$sets[$key] = $set;
        }

        $set = self::$sets[$key];

        return false === $set ? null : $set;
    }

    /**
     * One spelling of a general category: "\pL" and "\p{L}" are one atom,
     * "\p{^L}" and "\P{L}" another.
     */
    private static function spelledOnce(string $atom): string
    {
        // Read without a regex: the key is worked out before the cache is.
        if (!str_starts_with($atom, '\\p') && !str_starts_with($atom, '\\P')) {
            return $atom;
        }

        $name = substr($atom, 2);
        $caret = false;
        if (str_starts_with($name, '{') && str_ends_with($name, '}')) {
            $name = substr($name, 1, -1);
            $caret = str_starts_with($name, '^');
            $name = $caret ? substr($name, 1) : $name;
        } elseif (1 !== \strlen($name)) {
            return $atom;
        }

        $category = '' !== $name && Ascii::isAlpha($name) && Ascii::upper($name[0]) === $name[0] && (1 === \strlen($name) || (2 === \strlen($name) && Ascii::lower($name[1]) === $name[1]));
        if (!$category) {
            return $atom;
        }

        return (('P' === $atom[1]) !== $caret ? '\\P{' : '\\p{').$name.'}';
    }

    /**
     * The options that can change what the atom matches: "s" only for a
     * dot, "x" only where the atom holds a space or a "#", "xx" only where
     * it holds a space or a tab, "i" not for a type, which holds every case
     * of what it holds, nor for a general category but Lu, Ll and Lt (each
     * holds the same set with "i" and without on PCRE2 10.39 to 10.49).
     * The others stay as given: "r" alone is refused before PCRE2 10.43,
     * and the scan says so.
     */
    private static function bearingOn(string $atom, string $modifiers): string
    {
        if ('.' !== $atom) {
            $modifiers = str_replace('s', '', $modifiers);
        }

        if (\in_array($atom, ['\\d', '\\D', '\\s', '\\S', '\\w', '\\W', '\\h', '\\H', '\\v', '\\V'], true)
            || (str_starts_with($atom, '\\p{') || str_starts_with($atom, '\\P{')) && \in_array(substr($atom, 3, -1), self::CASE_FREE_CATEGORIES, true)) {
            $modifiers = str_replace('i', '', $modifiers);
        }

        $extendedMore = str_contains($modifiers, 'xx');
        if (($extendedMore && false === strpbrk($atom, " \t")) || (!$extendedMore && false === strpbrk($atom, " \t\n\r\f\v#"))) {
            $modifiers = str_replace('x', '', $modifiers);
        }

        return $modifiers;
    }

    private static function clear(): void
    {
        self::$sets = [];
        self::$parser = null;
        self::$unicodeSubject = null;
        self::$byteSubject = null;
    }

    /**
     * The set of "\x{HH}", an ASCII character, under "i" or "ir", with
     * Unicode case folding (UTF and UCP, as /u gives them): itself and its
     * other case, whatever the release, as no other character folds to one;
     * null for "k" and "s", which Unicode folds with the Kelvin sign and the
     * long s (but under "r"), and for any other atom. Without UCP the
     * locale's tables fold the characters, and the atom is scanned.
     */
    private static function caselessAscii(string $atom, string $modifiers): ?CharSet
    {
        // "r" is refused before PCRE2 10.43: the scan says so.
        $restrictOk = 'ir' !== $modifiers || PcreTarget::runtime()->supports(PcreFeature::CaselessRestrictModifier);
        if (('i' !== $modifiers && 'ir' !== $modifiers) || !$restrictOk || 1 !== LibraryPcre::match('/^\\\\x\{([0-7]?[0-9A-F])\}$/', $atom, $digits)) {
            return null;
        }

        $character = \chr((int) hexdec($digits[1]));
        $lower = Ascii::lower($character);
        if ('k' === $lower || 's' === $lower) {
            return null;
        }

        return CharSet::single(\ord($lower))->union(CharSet::single(\ord(Ascii::upper($character))));
    }

    /**
     * The set of a class that is not caseless, from its members; null when
     * the class is caseless, under "xx" with a space or a tab, read by the
     * parser as anything else than a class of known members, or holding a
     * member the engine does not answer for: the class is then scanned.
     */
    private static function composed(string $atom, string $modifiers, string $startVerbs, bool $unicodeFlag): ?CharSet
    {
        // Under the ASCII options PCRE2 10.44 reads a negated type next to
        // other members otherwise ("[\S\D]" under "(?a)" leaves the Arabic
        // digits out): such a class is scanned whole.
        if ('[' !== ($atom[0] ?? '') || str_contains($modifiers, 'a')
            || (str_contains($modifiers, 'xx') && false !== strpbrk($atom, " \t"))) {
            return null;
        }

        $delimiter = self::delimiterFor($atom.$startVerbs);
        if (null === $delimiter) {
            return null;
        }

        // Read for the running engine, which scans the members: an atom it
        // refuses is scanned, and refused there.
        $prefix = $delimiter.$startVerbs.('' === $modifiers ? '' : '(?'.$modifiers.')');
        $regex = $prefix.$atom.$delimiter.($unicodeFlag ? 'u' : '');
        $parser = self::$parser ??= RegexParser::create();

        if (!$parser->validate($regex)->isValid) {
            return null;
        }

        // Valid, the tree is the one the validation read, from the cache.
        $tree = $parser->parse($regex)->pattern;

        $class = $tree instanceof SequenceNode ? $tree->children[array_key_last($tree->children)] ?? null : $tree;
        if (!$class instanceof CharClassNode || ($tree instanceof SequenceNode && !self::onlyOptionsBefore($tree, $class))) {
            return null;
        }

        $members = $class->expression instanceof AlternationNode ? $class->expression->alternatives : [$class->expression];
        // A class of one POSIX class is the atom asked; so is one caseless
        // range, written by code point, which only the engine folds.
        $caseless = str_contains($modifiers, 'i');
        $only = 1 === \count($members) ? $members[0] : null;
        if ($only instanceof PosixClassNode || ($caseless && $only instanceof RangeNode && $atom === self::rangeAtom($only))) {
            return null;
        }

        $set = CharSet::empty();
        foreach ($members as $member) {
            $part = self::memberSet($member, $regex, $modifiers, $startVerbs, $unicodeFlag);
            if (null === $part) {
                return null;
            }

            $set = $set->union($part);
        }

        return $class->isNegated ? CharSet::universe(true)->subtract($set) : $set->intersect(CharSet::universe(true));
    }

    private static function onlyOptionsBefore(SequenceNode $sequence, CharClassNode $class): bool
    {
        foreach ($sequence->children as $child) {
            if ($child !== $class && !($child instanceof GroupNode && GroupType::InlineFlags === $child->type)) {
                return false;
            }
        }

        return true;
    }

    private static function memberSet(NodeInterface $member, string $regex, string $modifiers, string $startVerbs, bool $unicodeFlag): ?CharSet
    {
        // Caseless, each character and range is folded by the engine: asked
        // as an atom of its own, so once whatever class holds it.
        if (str_contains($modifiers, 'i')) {
            return self::caselessMemberSet($member, $regex, $modifiers, $startVerbs, $unicodeFlag);
        }

        if ($member instanceof RangeNode) {
            $from = self::codePoints($member->start);
            $to = self::codePoints($member->end);

            return null === $from || null === $to || 1 !== \count($from) || 1 !== \count($to) ? null : CharSet::range($from[0], $to[0]);
        }

        $codePoints = self::codePoints($member);
        if (null !== $codePoints) {
            $set = CharSet::empty();
            foreach ($codePoints as $codePoint) {
                $set = $set->union(CharSet::single($codePoint));
            }

            return $set;
        }

        // The types a class holds: "\N", "\R" and "\X" have no meaning in
        // one, and a member read so is not one the engine reads. A negated
        // POSIX class next to other members is read otherwise by PCRE2
        // before 10.49 under UCP ("[[:^ascii:][:ascii:]]" holds U+0000 to
        // U+00FF only on 10.44): the class is scanned whole.
        $type = $member instanceof CharTypeNode && \in_array($member->value, ['d', 'D', 's', 'S', 'w', 'W', 'h', 'H', 'v', 'V'], true);
        $posix = $member instanceof PosixClassNode && !str_starts_with($member->class, '^');
        if (!$type && !$posix && !$member instanceof UnicodePropNode) {
            return null;
        }

        $text = substr($regex, $member->getStartPosition() + 1, $member->getEndPosition() - $member->getStartPosition());

        return self::query($member instanceof PosixClassNode ? '['.$text.']' : $text, true, $modifiers, $startVerbs, $unicodeFlag);
    }

    private static function caselessMemberSet(NodeInterface $member, string $regex, string $modifiers, string $startVerbs, bool $unicodeFlag): ?CharSet
    {
        $codePoints = self::codePoints($member);
        if (null !== $codePoints) {
            $set = CharSet::empty();
            foreach ($codePoints as $codePoint) {
                $folded = self::query(\sprintf('\\x{%X}', $codePoint), true, $modifiers, $startVerbs, $unicodeFlag);
                if (null === $folded) {
                    return null;
                }

                $set = $set->union($folded);
            }

            return $set;
        }

        if ($member instanceof RangeNode) {
            $atom = self::rangeAtom($member);
            $from = self::codePoints($member->start)[0] ?? null;
            $to = self::codePoints($member->end)[0] ?? null;
            // An ASCII range under Unicode folding is its characters, each
            // folded as an atom of its own.
            if (null !== $atom && null !== $from && null !== $to && $unicodeFlag && $to <= 0x7F && $from <= $to) {
                $set = CharSet::empty();
                for ($codePoint = $from; $codePoint <= $to; $codePoint++) {
                    $folded = self::query(\sprintf('\\x{%X}', $codePoint), true, $modifiers, $startVerbs, $unicodeFlag);
                    if (null === $folded) {
                        return null;
                    }

                    $set = $set->union($folded);
                }

                return $set;
            }

            return null === $atom ? null : self::query($atom, true, $modifiers, $startVerbs, $unicodeFlag);
        }

        $known = ($member instanceof CharTypeNode && \in_array($member->value, ['d', 'D', 's', 'S', 'w', 'W', 'h', 'H', 'v', 'V'], true))
            || ($member instanceof PosixClassNode && !str_starts_with($member->class, '^'))
            || $member instanceof UnicodePropNode;
        if (!$known) {
            return null;
        }

        $text = substr($regex, $member->getStartPosition() + 1, $member->getEndPosition() - $member->getStartPosition());

        return self::query($member instanceof PosixClassNode ? '['.$text.']' : $text, true, $modifiers, $startVerbs, $unicodeFlag);
    }

    /**
     * A class of the range alone, written by code point: "^-~" in brackets
     * would be a negation.
     */
    private static function rangeAtom(RangeNode $range): ?string
    {
        $from = self::codePoints($range->start);
        $to = self::codePoints($range->end);

        return null === $from || null === $to || 1 !== \count($from) || 1 !== \count($to) ? null : \sprintf('[\\x{%X}-\\x{%X}]', $from[0], $to[0]);
    }

    /**
     * The characters a literal member stands for, each one a member.
     *
     * @return list<int>|null
     */
    private static function codePoints(NodeInterface $member): ?array
    {
        if ($member instanceof CharLiteralNode || $member instanceof ControlCharNode) {
            return $member->codePoint <= 0x10FFFF ? [$member->codePoint] : null;
        }

        if (!$member instanceof LiteralNode) {
            return null;
        }

        $codePoints = Utf8::decode($member->value, true);

        return null === $codePoints || [] === $codePoints ? null : $codePoints;
    }

    /**
     * The set, false when the probe cannot be written or PCRE refuses it,
     * null when the engine gave up under its limits.
     */
    private static function scan(string $atom, bool $unicode, string $modifiers, string $global, string $startVerbs): CharSet|false|null
    {
        $delimiter = self::delimiterFor($atom.$startVerbs);
        if (null === $delimiter) {
            return false;
        }

        // Without the JIT, which crashes PHP on some pattern and subject pairs:
        // no delimiter here is one the verb holds.
        $pattern = $delimiter.NoJit::VERB.$startVerbs.'(?'.$modifiers.':'.$atom.')++'.$delimiter.$global;
        $subject = $unicode ? self::unicodeSubject() : self::byteSubject();
        $ranges = [];

        // A block at a time: a run over the whole subject (".") would pass the
        // engine's backtrack limit with the JIT off. Runs cut at a block's
        // edge join again in the set.
        foreach (self::blocks($unicode, \strlen($subject)) as [$blockStart, $blockLength]) {
            $block = substr($subject, $blockStart, $blockLength);
            $runs = self::matchAll($pattern, $block);
            if (!\is_array($runs)) {
                return $runs;
            }

            $position = 0;
            foreach ($runs as $text) {
                // Every character stands once in the subject: a run is found
                // where the previous one ended, or further on.
                $offset = '' === $text ? false : strpos($block, $text, $position);
                if (false === $offset) {
                    continue;
                }

                $position = $offset + \strlen($text);
                $from = $blockStart + $offset;
                $after = $blockStart + $position;
                $ranges[] = $unicode
                    ? [self::codePointAt($from), self::codePointAt($after) - 1]
                    : [$from, $after - 1];
            }
        }

        return CharSet::fromRanges($ranges)->intersect(CharSet::universe($unicode));
    }

    /**
     * The first delimiter the text does not hold; null when it holds them
     * all.
     */
    private static function delimiterFor(string $text): ?string
    {
        foreach (self::DELIMITERS as $candidate) {
            if (!str_contains($text, $candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Every match of the probe in the block; false when PCRE refuses the
     * probe, null when the engine gave up under its limits. The probe runs
     * under the library's floor, the warning of a refusal caught.
     *
     * @param non-empty-string $pattern
     *
     * @return list<string>|false|null
     */
    private static function matchAll(string $pattern, string $block): array|false|null
    {
        set_error_handler(static fn (): bool => true);

        try {
            $result = LibraryPcre::matchAll($pattern, $block, $matches);
            $error = LibraryPcre::lastError();
        } finally {
            restore_error_handler();
        }

        if (false !== $result) {
            return $matches[0] ?? [];
        }

        return \in_array($error, [\PREG_BACKTRACK_LIMIT_ERROR, \PREG_RECURSION_LIMIT_ERROR, \PREG_JIT_STACKLIMIT_ERROR], true) ? null : false;
    }

    /**
     * The byte offset and length of each block of the subject, cut on
     * character boundaries.
     *
     * @return list<array{int, int}>
     */
    private static function blocks(bool $unicode, int $length): array
    {
        if (!$unicode) {
            return [[0, $length]];
        }

        $blocks = [];
        $characters = 0x110000 - 0x800;
        for ($first = 0; $first < $characters; $first += self::BLOCK_SIZE) {
            $start = self::offsetOf(self::codePointOfIndex($first));
            $last = min($first + self::BLOCK_SIZE, $characters);
            $end = $last >= $characters ? $length : self::offsetOf(self::codePointOfIndex($last));
            $blocks[] = [$start, $end - $start];
        }

        return $blocks;
    }

    /**
     * The code point at a position in the subject's order: the surrogates
     * are left out.
     */
    private static function codePointOfIndex(int $index): int
    {
        return $index < 0xD800 ? $index : $index + 0x800;
    }

    /**
     * Where a code point starts in the Unicode subject.
     */
    private static function offsetOf(int $codePoint): int
    {
        return match (true) {
            $codePoint < 0x80 => $codePoint,
            $codePoint < 0x800 => self::TWO_BYTES_AT + ($codePoint - 0x80) * 2,
            $codePoint < 0xD800 => self::THREE_BYTES_AT + ($codePoint - 0x800) * 3,
            $codePoint < 0x10000 => self::AFTER_SURROGATES_AT + ($codePoint - 0xE000) * 3,
            default => self::FOUR_BYTES_AT + ($codePoint - 0x10000) * 4,
        };
    }

    private static function codePointAt(int $offset): int
    {
        return match (true) {
            $offset < self::TWO_BYTES_AT => $offset,
            $offset < self::THREE_BYTES_AT => 0x80 + intdiv($offset - self::TWO_BYTES_AT, 2),
            $offset < self::AFTER_SURROGATES_AT => 0x800 + intdiv($offset - self::THREE_BYTES_AT, 3),
            $offset < self::FOUR_BYTES_AT => 0xE000 + intdiv($offset - self::AFTER_SURROGATES_AT, 3),
            default => 0x10000 + intdiv($offset - self::FOUR_BYTES_AT, 4),
        };
    }

    private static function byteSubject(): string
    {
        return self::$byteSubject ??= implode('', array_map(chr(...), range(0, 0xFF)));
    }

    /**
     * Every code point but the surrogates, in order, as one UTF-8 string.
     */
    private static function unicodeSubject(): string
    {
        if (null !== self::$unicodeSubject) {
            return self::$unicodeSubject;
        }

        $text = '';
        foreach ([[0, 0xD7FF], [0xE000, 0x10FFFF]] as [$from, $to]) {
            for ($start = $from; $start <= $to; $start += 0x10000) {
                $codePoints = range($start, min($start + 0xFFFF, $to));
                $text .= (string) mb_convert_encoding(pack('N*', ...$codePoints), 'UTF-8', 'UTF-32BE');
            }
        }

        return self::$unicodeSubject = $text;
    }
}
