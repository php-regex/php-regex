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
use PHPRegex\Parser\Engine\CharacterTables;
use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\Engine\NoJit;
use PHPRegex\Parser\Exception\InternalFailureException;
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
 * Under /u some atoms are answered without a scan of their own. A class that
 * is not caseless is the union of its members: each type, property or POSIX
 * class asked as an atom of its own, so scanned once whatever class holds
 * it, each character and range read as written, a negated class the rest of
 * the alphabet, but under the ASCII options. A caseless ASCII character is
 * itself and its other case, but "k" and "s", which Unicode folds with a
 * third character: those are scanned. A negated type or property is the
 * rest of the alphabet, the dot under "s" is all of it, and every general
 * category is read from one scan of all of them. A class the parser does
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

    /**
     * The general categories each character has exactly one of, and the
     * ones they make up.
     */
    private const LEAF_CATEGORIES = [
        'Lu', 'Ll', 'Lt', 'Lm', 'Lo', 'Mn', 'Mc', 'Me', 'Nd', 'Nl', 'No',
        'Pc', 'Pd', 'Ps', 'Pe', 'Pi', 'Pf', 'Po', 'Sm', 'Sc', 'Sk', 'So',
        'Zs', 'Zl', 'Zp', 'Cc', 'Cf', 'Cs', 'Co', 'Cn',
    ];

    private const MAJOR_CATEGORIES = [
        'L' => ['Lu', 'Ll', 'Lt', 'Lm', 'Lo'],
        'M' => ['Mn', 'Mc', 'Me'],
        'N' => ['Nd', 'Nl', 'No'],
        'P' => ['Pc', 'Pd', 'Ps', 'Pe', 'Pi', 'Pf', 'Po'],
        'S' => ['Sm', 'Sc', 'Sk', 'So'],
        'Z' => ['Zs', 'Zl', 'Zp'],
        'C' => ['Cc', 'Cf', 'Cs', 'Co', 'Cn'],
    ];

    /**
     * The POSIX classes UCP reads as general categories (PCRE2's
     * pcre2pattern: [:alpha:] becomes \p{L}, [:alnum:] \p{Xan}, which is
     * L and N, [:digit:] \p{Nd}, [:lower:] \p{Ll}, [:upper:] \p{Lu},
     * [:cntrl:] \p{Cc}).
     */
    private const POSIX_CATEGORIES = [
        '[[:alpha:]]' => ['L'],
        '[[:alnum:]]' => ['L', 'N'],
        '[[:digit:]]' => ['Nd'],
        '[[:lower:]]' => ['Ll'],
        '[[:upper:]]' => ['Lu'],
        '[[:cntrl:]]' => ['Cc'],
    ];

    private const NEGATED_TYPES = ['\\D', '\\S', '\\W', '\\H', '\\V'];

    /**
     * The key the general categories are kept under between processes.
     */
    private const CATEGORIES_KEY = 'general categories';

    private static ?string $unicodeSubject = null;

    private static ?string $byteSubject = null;

    /**
     * @var array<string, CharSet|false>
     */
    private static array $sets = [];

    private static ?RegexParser $parser = null;

    /**
     * The leaf general categories read from one scan, false when the scan
     * could not read them.
     *
     * @var array<string, CharSet>|false|null
     */
    private static array|false|null $categories = null;

    /**
     * Whether a Unicode probe may run with the JIT.
     */
    private static bool $jit = true;

    /**
     * Where the engine's answers are kept between processes, if anywhere.
     */
    private static ?ClassSetStore $store = null;

    /**
     * Keep what the engine answers, its Unicode scans and its general
     * categories, under the directory, for the next process to read
     * instead of scanning again; null, or the empty string, keeps them in
     * memory only. Process-wide, as the answers are; emptying the caches
     * does not change it.
     */
    public static function persistIn(?string $directory): void
    {
        self::$store = null === $directory || '' === $directory ? null : new ClassSetStore($directory);
    }

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
     * @param string  $atom        one character's worth of pattern: a class, an escape, a dot
     * @param string  $modifiers   the options in force at the atom, as above
     * @param string  $startVerbs  the options the pattern opens with, such as "(*UCP)"
     *                             or "(*CR)": they change what a class or a dot matches
     * @param bool    $unicodeFlag whether the pattern's own /u flag is set: this PHP
     *                             passes PCRE2_UCP with it, so the probe carries the flag
     *                             only when the pattern does — a lone "(*UTF)" reads
     *                             UTF-8 without the Unicode properties
     * @param ?string $locale      the LC_CTYPE locale the pattern runs under, whose
     *                             character tables decide the types, the POSIX classes
     *                             and the other case of a character below 256 without
     *                             /u; the one PHP compiles under now when null
     */
    public static function query(string $atom, bool $unicode, string $modifiers, string $startVerbs = '', bool $unicodeFlag = true, ?string $locale = null): ?CharSet
    {
        StaticCaches::register(self::class, self::clear(...));

        $atom = self::spelledOnce($atom);
        $modifiers = self::bearingOn($atom, $modifiers);
        $global = $unicodeFlag && $unicode ? 'u' : '';
        // Under /u the Unicode properties decide; otherwise the tables of
        // the locale, read by a scan under it.
        $tables = '' !== $global ? CharacterTables::BUILT_IN : $locale ?? CharacterTables::current();
        $key = $startVerbs.$global.'|'.$modifiers.':'.$atom.(CharacterTables::BUILT_IN === $tables ? '' : '|'.$tables);
        if (!isset(self::$sets[$key])) {
            // A scan of the 256 bytes costs less than reading the atom: only
            // the Unicode scans, over every code point, are worth saving.
            $set = null;
            $read = static function () use (&$set, $atom, $unicode, $modifiers, $startVerbs, $unicodeFlag, $global, $key): void {
                $set = ($unicode && $unicodeFlag ? self::caselessAscii($atom, $modifiers) : null)
                    ?? ($unicode ? self::derived($atom, $modifiers, $startVerbs, $unicodeFlag) : null)
                    ?? ($unicode ? self::composed($atom, $modifiers, $startVerbs, $unicodeFlag) : null)
                    ?? self::scanned($atom, $unicode, $modifiers, $global, $startVerbs, $key);
            };
            if ('' !== $global) {
                $read();
            } else {
                CharacterTables::under($tables, $read);
            }

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
     * of what it holds, nor for the dot, which holds every character but
     * the newlines, nor for a general category but Lu, Ll and Lt (each
     * holds the same set with "i" and without on PCRE2 10.39 to 10.49).
     * The others stay as given: "r" alone is refused before PCRE2 10.43,
     * and the scan says so.
     */
    private static function bearingOn(string $atom, string $modifiers): string
    {
        if ('.' !== $atom) {
            $modifiers = str_replace('s', '', $modifiers);
        }

        if (\in_array($atom, ['.', '\\d', '\\D', '\\s', '\\S', '\\w', '\\W', '\\h', '\\H', '\\v', '\\V'], true)
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
        self::$categories = null;
        self::$parser = null;
        self::$unicodeSubject = null;
        self::$byteSubject = null;
    }

    /**
     * The set of an atom read from others under /u, null for any other: a
     * negated type, "\P{X}" or "\p{^X}" not caseless is the rest of the
     * alphabet (each pair of types parts it in two, and a property is
     * matched or not), the dot under "s" is all of it, and a general
     * category, or a POSIX class UCP reads as one, comes from the one scan
     * of them all. A category is read so with no option and no start verb
     * only: each changes what some category or POSIX class matches.
     */
    private static function derived(string $atom, string $modifiers, string $startVerbs, bool $unicodeFlag): ?CharSet
    {
        if ('.' === $atom) {
            return str_contains($modifiers, 's') ? CharSet::universe(true) : null;
        }

        $positive = null;
        if (\in_array($atom, self::NEGATED_TYPES, true)) {
            $positive = '\\'.Ascii::lower($atom[1]);
        } elseif (!str_contains($modifiers, 'i') && str_ends_with($atom, '}')) {
            if (str_starts_with($atom, '\\P{') && !str_starts_with($atom, '\\P{^')) {
                $positive = '\\p{'.substr($atom, 3);
            } elseif (str_starts_with($atom, '\\p{^')) {
                $positive = '\\p{'.substr($atom, 4);
            }
        }

        if (null !== $positive) {
            $set = self::query($positive, true, $modifiers, $startVerbs, $unicodeFlag);

            return null === $set ? null : CharSet::universe(true)->subtract($set);
        }

        if ('' !== $modifiers || '' !== $startVerbs || !$unicodeFlag) {
            return null;
        }

        $names = self::POSIX_CATEGORIES[$atom] ?? null;
        if (null === $names && str_starts_with($atom, '\\p{') && str_ends_with($atom, '}')) {
            $name = substr($atom, 3, -1);
            $names = isset(self::MAJOR_CATEGORIES[$name]) || \in_array($name, self::LEAF_CATEGORIES, true) ? [$name] : null;
        }

        $categories = null === $names ? null : self::categories();
        if (null === $names || null === $categories) {
            return null;
        }

        $set = CharSet::empty();
        foreach ($names as $name) {
            foreach (self::MAJOR_CATEGORIES[$name] ?? [$name] as $leaf) {
                $set = $set->union($categories[$leaf]);
            }
        }

        return $set;
    }

    /**
     * The leaf general categories, each the characters the engine reads in
     * it; null when they make no partition the engine reads, or it gave up
     * on them under its limits: each category is then scanned alone. A
     * partition, or its absence, is kept for the process; a scan given up
     * is not.
     *
     * @return array<string, CharSet>|null
     */
    private static function categories(): ?array
    {
        if (null === self::$categories) {
            $kept = self::$store?->load(self::CATEGORIES_KEY);
            $categories = null !== $kept && self::LEAF_CATEGORIES === array_keys($kept) ? $kept : self::partition(self::LEAF_CATEGORIES);
            if (null === $categories) {
                return null;
            }

            if (\is_array($categories) && $categories !== $kept) {
                self::$store?->save(self::CATEGORIES_KEY, $categories);
            }

            self::$categories = $categories;
        }

        return false === self::$categories ? null : self::$categories;
    }

    /**
     * The characters the engine reads in each category, from one scan:
     * each run of characters of one category is a match of the branch that
     * names it. False when the engine refuses a name, or the matches leave
     * characters out (the categories are no partition: a character two of
     * them hold goes to the first only, which only a partition rules out,
     * and every character of the alphabet has one general category); null
     * when the engine gave up under its limits.
     *
     * @param list<string> $names
     *
     * @return array<string, CharSet>|false|null
     */
    private static function partition(array $names): array|false|null
    {
        // Captures, no backreference: the JIT may run it (see probe()).
        $pattern = '/'.(self::$jit ? '' : NoJit::VERB).'(?:('.implode(')|(', array_map(static fn (string $name): string => '\\p{'.$name.'}++', $names)).'))/u';
        $subject = self::unicodeSubject();
        $ranges = array_fill_keys($names, []);
        foreach (self::blocks(true, \strlen($subject)) as [$blockStart, $blockLength]) {
            $runs = self::runs($pattern, substr($subject, $blockStart, $blockLength));
            if (!\is_array($runs)) {
                return $runs;
            }

            // The runs follow each other: they cover the block when their
            // lengths add up to it.
            $covered = 0;
            foreach ($runs as $run) {
                $length = \strlen($run[0][0] ?? '');
                $offset = $run[0][1] ?? 0;
                foreach ($names as $index => $name) {
                    if (null !== ($run[$index + 1][0] ?? null)) {
                        $ranges[$name][] = [self::codePointAt($blockStart + $offset), self::codePointAt($blockStart + $offset + $length) - 1];
                        $covered += $length;

                        break;
                    }
                }
            }

            if ($covered !== $blockLength) {
                return false;
            }
        }

        // A run ending before U+E000 reads as ending at U+DFFF: the
        // surrogates, which the subject leaves out, are taken off.
        return array_map(static fn (array $runs): CharSet => CharSet::fromRanges($runs)->intersect(CharSet::universe(true)), $ranges);
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

        // The library's own regexes giving up under the caller's limits
        // leave the class unread, as the engine giving up on a member does.
        try {
            if (!$parser->validate($regex)->isValid) {
                return null;
            }

            // Valid, the tree is the one the validation read, from the cache.
            $tree = $parser->parse($regex)->pattern;
        } catch (InternalFailureException) {
            return null;
        }

        $class = $tree instanceof SequenceNode ? $tree->children[array_key_last($tree->children)] ?? null : $tree;
        if (!$class instanceof CharClassNode || ($tree instanceof SequenceNode && !self::onlyOptionsBefore($tree, $class))) {
            return null;
        }

        $members = $class->expression instanceof AlternationNode ? $class->expression->alternatives : [$class->expression];
        // A class of one POSIX class is the atom asked, but negated; so is
        // one caseless range, written by code point, which only the engine
        // folds.
        $caseless = str_contains($modifiers, 'i');
        $only = 1 === \count($members) ? $members[0] : null;
        if (($only instanceof PosixClassNode && !$class->isNegated) || ($caseless && $only instanceof RangeNode && $atom === self::rangeAtom($only))) {
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
     * The set the engine answers for the atom: a Unicode scan is read from
     * the store when a former process kept it, and kept there once made. A
     * byte scan, over 256 bytes, costs less than reading a file.
     */
    private static function scanned(string $atom, bool $unicode, string $modifiers, string $global, string $startVerbs, string $key): CharSet|false|null
    {
        $store = $unicode ? self::$store : null;
        $kept = $store?->load($key)['set'] ?? null;
        if (null !== $kept) {
            return $kept;
        }

        $set = self::scan($atom, $unicode, $modifiers, $global, $startVerbs);
        if ($set instanceof CharSet) {
            $store?->save($key, ['set' => $set]);
        }

        return $set;
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

        $pattern = self::probe($atom, $unicode, $modifiers, $global, $startVerbs, $delimiter);
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
     * The pattern that scans for the atom. A Unicode probe runs with the JIT
     * (about seven times faster over the million code points) when its atom
     * holds no parenthesis: the JIT crashes PHP on some pattern and subject
     * pairs, and every known one needs a capture and a backreference, which
     * a probe without a parenthesis cannot hold. A byte probe runs without
     * it: compiling it costs more than reading 256 bytes. No delimiter here
     * is one the verb holds.
     *
     * @return non-empty-string
     */
    private static function probe(string $atom, bool $unicode, string $modifiers, string $global, string $startVerbs, string $delimiter): string
    {
        $jit = self::$jit && $unicode && !str_contains($atom, '(');

        return $delimiter.($jit ? '' : NoJit::VERB).$startVerbs.'(?'.$modifiers.':'.$atom.')++'.$delimiter.$global;
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
        } catch (InternalFailureException $failure) {
            return self::gaveUp($failure);
        } finally {
            restore_error_handler();
        }

        return false === $result ? false : $matches[0] ?? [];
    }

    /**
     * Every run the categories probe matches in the block, each with the
     * group of its branch and their byte offsets, a branch it did not take
     * null; false when PCRE refuses the probe, null when the engine gave up
     * under its limits.
     *
     * @param non-empty-string $pattern
     *
     * @return list<array<array{?string, int}>>|false|null
     */
    private static function runs(string $pattern, string $block): array|false|null
    {
        set_error_handler(static fn (): bool => true);

        try {
            $result = LibraryPcre::matchAll($pattern, $block, $matches, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL);
        } catch (InternalFailureException $failure) {
            return self::gaveUp($failure);
        } finally {
            restore_error_handler();
        }

        return false === $result ? false : $matches;
    }

    /**
     * What a probe that gave no answer says: null when the engine gave up
     * under its limits, false when PCRE refused the probe.
     *
     * @return false|null
     */
    private static function gaveUp(InternalFailureException $failure): ?bool
    {
        return \in_array($failure->pcreError, [\PREG_BACKTRACK_LIMIT_ERROR, \PREG_RECURSION_LIMIT_ERROR, \PREG_JIT_STACKLIMIT_ERROR], true) ? null : false;
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
