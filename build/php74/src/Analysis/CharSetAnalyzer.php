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

namespace PHPRegex\Parser\Analysis;

use PHPRegex\Parser\Engine\CharacterTables;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\ClassSetOperationNode;
use PHPRegex\Parser\Node\ClassSetOperator;
use PHPRegex\Parser\Node\ControlCharNode;
use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\ExtendedCharClassNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\LimitMatchNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\PcreVerbNode;
use PHPRegex\Parser\Node\PosixClassNode;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\ScriptRunNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Syntax\InlineFlags;
use PHPRegex\Parser\Syntax\StartOptions;
use PHPRegex\Parser\Text\Ascii;
use PHPRegex\Parser\Text\Bytes;
use PHPRegex\Parser\Text\Utf8;

/**
 * Approximates leading/trailing character sets for AST nodes.
 *
 * Used to detect mutually exclusive boundaries that avoid catastrophic backtracking.
 *
 * The sets stop at ASCII unless the analyzer reads above it, as the ReDoS
 * analysis asks: the linter's rules ask the engine and the tables of the
 * target's locale there. Read above ASCII, without UTF a character is a
 * byte, and a set holds the bytes the node may start or end with. Under
 * UTF a character above ASCII is two to four bytes, and a set knows it by
 * the byte it starts with, the last character of a node as its first: "é"
 * is 0xC3 at either end, so a node that ends with it meets one that starts
 * with it. Under another locale's tables than PCRE2's own, a set still
 * holds the bytes a literal writes and those a complement takes, and what
 * those tables decide, read from the engine: the bytes of "\d", "\s",
 * "\w" and the POSIX classes without UCP, and the other case of a
 * character. Neither is known where the tables cannot be read, nor a case
 * the engine's paths read otherwise.
 *
 * @internal
 */
final class CharSetAnalyzer
{
    /**
     */
    private string $flags;

    /**
     * Whether the engine compiles with PCRE2's own tables, as under "C".
     *
     */
    private bool $ownTables;

    /**
     * Under another locale's tables, those read: they give the bytes of
     * each type and the other case of each byte. Null where they are not
     * known.
     *
     */
    private ?CharacterTables $tables;
    /**
     * The POSIX names PCRE2 gives, whose bytes the tables hold.
     */
    private const POSIX_NAMES = ['alpha', 'lower', 'upper', 'alnum', 'ascii', 'blank', 'cntrl', 'digit', 'graph', 'print', 'punct', 'space', 'word', 'xdigit'];

    /**
     * What the start options set, read once for the pattern: the newline
     * convention, whether "\R" takes CR and LF alone, and whether they turn
     * UTF, (*UCP), the caseless restriction or Turkish casing on.
     *
     * @var array{newline: string, anyCrlfBreaks: bool, utf: bool, ucp: bool, restricted: bool, turkish: bool}
     *
     */
    private array $options;

    /**
     */
    private bool $unicodeMode;

    /**
     * Whether (*UCP) gives a byte above ASCII without UTF the case of the
     * character of its value, as PCRE2 does from 10.35: before, the tables
     * give it, as without (*UCP).
     *
     */
    private bool $ucpCases;

    /**
     */
    private bool $utf;

    /**
     */
    private bool $caseless;

    /**
     * Whether r keeps a character of ASCII and one above it from matching
     * each other caselessly: "k" and the Kelvin sign.
     *
     */
    private bool $restricted;

    /**
     * Whether "i" and "I" match the Turkish letters above ASCII caselessly
     * rather than each other: (*TURKISH_CASING), unless r holds.
     *
     */
    private bool $turkish;

    /**
     */
    private ByteCharSet $dot;
    /**
     */
    private string $startOptions = '';
    /**
     */
    private bool $readsAboveAscii = false;
    /**
     */
    private bool $asciiSpaces = false;

    /**
     * @param string      $startOptions      the options the pattern opens with, such
     *                                       as "(*CR)(*UTF)": the newline convention
     *                                       they set decides where the dot stops
     * @param bool        $readsAboveAscii   whether the sets hold the characters above
     *                                       ASCII, read under the tables of $locale
     * @param self|null   $samePattern       an analyzer of the same pattern, whose
     *                                       reading of the start options this one
     *                                       takes rather than read them again
     * @param bool        $asciiSpaces       whether "(?aS)" or "(?a)" holds "\s" to
     *                                       ASCII under (*UCP), and "\S" takes the
     *                                       spaces above it
     * @param string|null $locale            the LC_CTYPE locale whose tables the
     *                                       engine compiles with, "C" for PCRE2's
     *                                       own: another locale's are read for the
     *                                       bytes of a type and the other case of a
     *                                       character, which are not known where
     *                                       the machine cannot set it, nor under
     *                                       tables not known, null
     * @param bool        $ucpFoldsPastAscii whether the release gives a byte above
     *                                       ASCII under (*UCP) the case of the
     *                                       character of its value, as PCRE2 does
     *                                       from 10.35; true where it is not known,
     *                                       as the releases PHP bundles from 8.0
     *                                       on do
     */
    public function __construct(
        string $flags = '',
        string $startOptions = '',
        bool $readsAboveAscii = false,
        ?self $samePattern = null,
        bool $asciiSpaces = false,
        ?string $locale = CharacterTables::BUILT_IN,
        bool $ucpFoldsPastAscii = true
    ) {
        $this->startOptions = $startOptions;
        $this->readsAboveAscii = $readsAboveAscii;
        $this->asciiSpaces = $asciiSpaces;
        $this->ownTables = $samePattern->ownTables ?? CharacterTables::BUILT_IN === $locale;
        $this->tables = null === $samePattern ? self::tablesOf($locale) : $samePattern->tables;
        $this->options = $samePattern->options ?? self::readStartOptions($startOptions);
        $this->flags = null === $samePattern ? $this->flagsAtTheStart($flags) : $flags;
        $this->unicodeMode = false !== strpos($flags, 'u') || $this->options['ucp'];
        $this->ucpCases = $samePattern->ucpCases ?? ($this->options['ucp'] && $ucpFoldsPastAscii);
        $this->utf = false !== strpos($flags, 'u') || $this->options['utf'];
        $this->caseless = false !== strpos($flags, 'i');
        $this->restricted = false !== strpos($this->flags, 'r');
        $this->turkish = $this->options['turkish'] && !$this->restricted;
        $this->dot = $this->dotSet(false !== strpos($flags, 's'), $this->options['newline']);
    }

    /**
     * An analyzer for the pattern as a whole: its flags and the newline
     * convention its start options set.
     */
    public static function forRegex(RegexNode $regex, bool $readsAboveAscii = false, ?string $locale = CharacterTables::BUILT_IN, bool $ucpFoldsPastAscii = true): self
    {
        return new self($regex->flags, StartOptions::of($regex->source ?? ''), $readsAboveAscii, null, false, $locale, $ucpFoldsPastAscii);
    }

    /**
     * The analyzer in force at each node under $root, $root included, this
     * one at its start: an option setting changes the flags inside its
     * group, "(?i:...)", or after it up to the end of the group around it,
     * "(?i)", the alternatives after its own included.
     *
     * @return \SplObjectStorage<NodeInterface, self>
     */
    public function inForce(NodeInterface $root): \SplObjectStorage
    {
        /** @var \SplObjectStorage<NodeInterface, self> $inForce */
        $inForce = new \SplObjectStorage();
        $this->mapInForce($root, $inForce);

        return $inForce;
    }

    /**
     * The same pattern under other flags, such as the inline ones in effect
     * at a node, read from flagsAtTheStart(): r holds the caseless
     * restriction where it is in them, and only there, as "(?-r)" lifts it.
     */
    public function withFlags(string $flags): self
    {
        return new self($flags, $this->startOptions, $this->readsAboveAscii, $this);
    }

    public function firstChars(NodeInterface $node): ByteCharSet
    {
        return $this->walk($node, true);
    }

    public function lastChars(NodeInterface $node): ByteCharSet
    {
        return $this->walk($node, false);
    }

    /**
     * The flags a pattern whose modifiers are $flags opens under, those an
     * option setting changes: (*CASELESS_RESTRICT) holds from the start as
     * r does, and "(?-r)" or "(?^)" turns it off after.
     */
    public function flagsAtTheStart(string $flags): string
    {
        return $this->options['restricted'] && false === strpos($flags, 'r') ? $flags.'r' : $flags;
    }

    private function walk(NodeInterface $node, bool $fromStart): ByteCharSet
    {
        if ($node instanceof RegexNode) {
            return $this->walk($node->pattern, $fromStart);
        }

        if ($node instanceof LiteralNode) {
            $at = \strlen($node->value) - 1;
            if (-1 === $at) {
                return ByteCharSet::empty();
            }

            if ($fromStart) {
                $at = 0;
            }

            while (!$fromStart && $this->readsAboveAscii && $this->utf && $at > 0 && Utf8::continues($node->value[$at])) {
                --$at;
            }

            $set = ByteCharSet::fromChar($node->value[$at]);

            return $this->caseless ? $set->union($this->otherCasesOfLiteral($node->value, $at)) : $set;
        }

        if ($node instanceof CharLiteralNode || $node instanceof ControlCharNode) {
            return $this->fromCodePoints($node->codePoint, $node->codePoint);
        }

        if ($node instanceof CharTypeNode) {
            return $this->fromCharType($node->value);
        }

        if ($node instanceof PosixClassNode) {
            return $this->fromPosixClass($node->class);
        }

        if ($node instanceof DotNode) {
            return $this->dot;
        }

        if ($node instanceof QuantifierNode) {
            return $this->walk($node->node, $fromStart);
        }

        if ($node instanceof RangeNode) {
            $start = $this->codePoint($node->start);
            $end = $this->codePoint($node->end);

            if (null === $start || null === $end) {
                return ByteCharSet::unknown();
            }

            return $this->fromCodePoints(min($start, $end), max($start, $end));
        }

        if ($node instanceof CharClassNode) {
            $set = ByteCharSet::empty();

            $parts = $node->expression instanceof AlternationNode ? $node->expression->alternatives : [$node->expression];
            foreach ($parts as $part) {
                // Without UTF a class reads each byte of a character
                // written in several apart: "[é]" is "[\xC3\xA9]".
                $candidate = $this->readsAboveAscii && !$this->utf && $part instanceof LiteralNode ? $this->eachByte($part->value) : $this->walk($part, $fromStart);
                $set = $set->union($candidate);
            }

            return $node->isNegated ? $this->complement($set) : $set;
        }

        // A lookaround reads nothing, whatever its body reads: "(?!x)a"
        // starts with "a", and "a(?=x)" ends with it.
        if ($node instanceof GroupNode) {
            return self::readsNothing($node) ? ByteCharSet::empty() : $this->inside($node)->walk($node->child, $fromStart);
        }

        if (self::isStartOption($node)) {
            return ByteCharSet::empty();
        }

        // A script run matches what its body matches, in one script.
        if ($node instanceof ScriptRunNode) {
            return null === $node->content ? ByteCharSet::unknown() : $this->walk($node->content, $fromStart);
        }

        if ($node instanceof ExtendedCharClassNode) {
            return $this->walk($node->expression, $fromStart);
        }

        if ($node instanceof ClassSetOperationNode) {
            $right = $this->walk($node->right, $fromStart);
            if (null === $node->left) {
                return $this->complement($right);
            }

            $left = $this->walk($node->left, $fromStart);

            switch (true) {
                case $node->operator === ClassSetOperator::Intersection():
                    return $left->intersect($right);
                case $node->operator === ClassSetOperator::Difference():
                    return $left->intersect($this->complement($right));
                case $node->operator === ClassSetOperator::SymmetricDifference():
                    return $left->union($right)->intersect($this->complement($left->intersect($right)));
                default:
                    return $left->union($right);
            }
        }

        if ($node instanceof AlternationNode) {
            $set = ByteCharSet::empty();
            $analyzer = $this;
            foreach ($node->alternatives as $alt) {
                $set = $set->union($analyzer->walk($alt, $fromStart));
                $analyzer = $analyzer->after($alt);
            }

            return $set;
        }

        if ($node instanceof SequenceNode) {
            // From the start, the options an item sets carry to the next as
            // the walk goes; from the end, they are read up front.
            $analyzers = $fromStart ? [] : $this->acrossChildren($node->children);
            $children = $fromStart ? $node->children : array_reverse($node->children, true);
            $analyzer = $this;
            $set = ByteCharSet::empty();

            foreach ($children as $index => $child) {
                $candidate = ($analyzers[$index] ?? $analyzer)->walk($child, $fromStart);
                $set = $set->union($candidate);

                if (!$this->isOptionalNode($child, $fromStart)) {
                    break;
                }

                if ($fromStart && ($child instanceof GroupNode || $child instanceof SequenceNode)) {
                    $analyzer = $analyzer->after($child);
                }
            }

            return $set;
        }

        return ByteCharSet::unknown();
    }

    /**
     * The characters of a type. Under UCP "\d" and "\w" take Unicode's,
     * which the sets do not hold; without it the three types read the
     * tables, which another locale's fill as its C library says.
     */
    private function fromCharType(string $type): ByteCharSet
    {
        if ($this->unicodeMode && \in_array($type, ['d', 'D', 'w', 'W'], true)) {
            return ByteCharSet::unknown();
        }

        if (!$this->ownTables && !$this->unicodeMode && \in_array($type, ['d', 'D', 's', 'S', 'w', 'W'], true)) {
            return $this->typeInTables($type);
        }

        switch (true) {
            case 'd' === $type:
                return ByteCharSet::fromRange(\ord('0'), \ord('9'));
            case 'D' === $type:
                return $this->complement(ByteCharSet::fromRange(\ord('0'), \ord('9')));
            case 'w' === $type:
                return self::wordCharacters();
            case 'W' === $type:
                return $this->complement(self::wordCharacters());
            case 's' === $type:
                return $this->spaces();
            case 'S' === $type:
                return $this->complement($this->spaces());
            case 'v' === $type:
                return $this->verticalSpaces();
            case 'V' === $type:
                return $this->complement($this->verticalSpaces());
            case 'R' === $type:
                return $this->options['anyCrlfBreaks'] ? self::bytes("\n\r") : $this->verticalSpaces();
            case 'h' === $type:
                return $this->horizontalSpaces();
            case 'H' === $type:
                return $this->complement($this->horizontalSpaces());
            default:
                return ByteCharSet::unknown();
        }
    }

    /**
     * "\d", "\s" or "\w" as another locale's tables fill it, and "\D",
     * "\S" or "\W" as the bytes they leave out: not known where the tables
     * are not. Under UTF the tables name the code points below 256, which
     * the engine's paths read apart above ASCII: a single "\w" takes those
     * the tables hold, the interpreter's repeat of it none (pcre2_match.c),
     * and its repeat of "\W" every one. The set holds what any path takes.
     */
    private function typeInTables(string $type): ByteCharSet
    {
        $set = $this->inTables(Ascii::lower($type));

        return Ascii::lower($type) === $type ? $set : $this->complement($set);
    }

    /**
     * A POSIX class, "[:alpha:]", as the tables fill it without UCP, and
     * its negation, "[:^alpha:]", as the bytes it leaves out. Under i
     * "[:lower:]" and "[:upper:]" take every letter, as "[:alpha:]" does,
     * and no class takes the other case of its bytes beyond
     * (pcre2_compile_class.c). Under UCP several read Unicode's
     * properties, which the sets do not hold: not known, as a name PCRE2
     * does not give.
     */
    private function fromPosixClass(string $class): ByteCharSet
    {
        $negated = 0 === strncmp($class, '^', strlen('^'));
        $name = $negated ? (string) substr($class, 1) : $class;
        if ($this->unicodeMode || !\in_array($name, self::POSIX_NAMES, true)) {
            return ByteCharSet::unknown();
        }

        $set = $this->inTables($this->caseless && \in_array($name, ['lower', 'upper'], true) ? 'alpha' : $name);

        return $negated ? $this->complement($set) : $set;
    }

    /**
     * The bytes the tables give a type or a POSIX name: PCRE2's own, or
     * another locale's, not known where those are not. Under UTF they name
     * code points, known by the byte each starts with.
     */
    private function inTables(string $name): ByteCharSet
    {
        $tables = $this->ownTables ? CharacterTables::builtIn() : $this->tables;
        if (null === $tables) {
            return ByteCharSet::unknown();
        }

        $set = ByteCharSet::empty();
        foreach ($tables->bytesOf($name) as [$low, $high]) {
            $set = $set->union($this->characters($low, $high));
        }

        return $set;
    }

    private static function wordCharacters(): ByteCharSet
    {
        return ByteCharSet::fromRange(\ord('0'), \ord('9'))
            ->union(ByteCharSet::fromRange(\ord('A'), \ord('Z')))
            ->union(ByteCharSet::fromRange(\ord('a'), \ord('z')))
            ->union(ByteCharSet::fromChar('_'));
    }

    /**
     * "\s": the white space of ASCII, and under (*UCP) the next line and
     * the no-break space, unless "(?aS)" holds it to ASCII.
     */
    private function spaces(): ByteCharSet
    {
        return self::bytes("\t\n\v\f\r ")->union($this->spacesAboveAscii($this->unicodeMode && !$this->asciiSpaces ? "\x85\xA0" : ''));
    }

    /**
     * "\v", and the line breaks "\R" takes, "\r\n" ending as "\n", unless
     * (*BSR_ANYCRLF) holds it to CR and LF.
     */
    private function verticalSpaces(): ByteCharSet
    {
        return self::bytes("\n\v\f\r")->union($this->spacesAboveAscii("\x85"));
    }

    private function horizontalSpaces(): ByteCharSet
    {
        return self::bytes("\t ")->union($this->spacesAboveAscii("\xA0"));
    }

    /**
     * The spaces above ASCII a class of spaces takes: without UTF the bytes
     * given, the next line 0x85 or the no-break space 0xA0. Under UTF each
     * one, from U+0085 to U+3000, starts with 0xC2, 0xE1, 0xE2 or 0xE3.
     */
    private function spacesAboveAscii(string $bytes): ByteCharSet
    {
        if (!$this->readsAboveAscii) {
            return ByteCharSet::empty();
        }

        return self::bytes($this->utf ? "\xC2\xE1\xE2\xE3" : $bytes);
    }

    /**
     * Without dotall, the dot matches every character but a newline, which
     * the convention defines. Under (*CRLF) only the pair is a newline, so
     * a single byte of it is an ordinary character. (*ANY) adds the next
     * line, 0x85 without UTF; under UTF, U+0085 starts with 0xC2 as other
     * characters the dot takes do. Read in ASCII, the newlines above it lie
     * outside the sets.
     */
    private function dotSet(bool $dotAll, string $newline): ByteCharSet
    {
        switch (true) {
            case true === $dotAll:
            case true === ('CRLF' === $newline):
                $newlines = '';
                break;
            case true === ('CR' === $newline):
                $newlines = "\r";
                break;
            case true === ('NUL' === $newline):
                $newlines = "\0";
                break;
            case true === ('ANYCRLF' === $newline):
                $newlines = "\n\r";
                break;
            case true === ('ANY' === $newline):
                $newlines = $this->readsAboveAscii && !$this->utf ? "\n\v\f\r\x85" : "\n\v\f\r";
                break;
            default:
                $newlines = "\n";
                break;
        }

        return $this->complement(self::bytes($newlines));
    }

    /**
     * The characters outside the set, in ASCII unless the analyzer reads
     * above it. Under UTF a byte above ASCII stands for every character
     * that starts with it, and some may be outside the set while others
     * are in: those bytes stay.
     */
    private function complement(ByteCharSet $set): ByteCharSet
    {
        if (!$this->readsAboveAscii) {
            return $set->complement();
        }

        $complement = $set->complement(0xFF);

        return $this->utf ? $complement->union(ByteCharSet::fromBytes(0x80, 0xFF)) : $complement;
    }

    /**
     * The characters from $start to $end, and under i their other case.
     */
    private function fromCodePoints(int $start, int $end): ByteCharSet
    {
        $set = $this->characters($start, $end);

        return $this->caseless && !$set->isUnknown() ? $set->union($this->otherCases($start, $end)) : $set;
    }

    /**
     * The characters from $start to $end; above ASCII, not known unless
     * the analyzer reads there. Without UTF a character is a byte, and a
     * code point above 0xFF none. Under UTF one above ASCII is known by the
     * byte it starts with, which UTF-8 orders as the code points.
     */
    private function characters(int $start, int $end): ByteCharSet
    {
        if ($end <= 0x7F) {
            $set = ByteCharSet::fromRange($start, $end);
        } elseif (!$this->readsAboveAscii || ($this->utf && $end > 0x10FFFF)) {
            return ByteCharSet::unknown();
        } elseif (!$this->utf) {
            $set = ByteCharSet::fromBytes($start, $end);
        } else {
            $ascii = $start <= 0x7F ? ByteCharSet::fromRange($start, 0x7F) : ByteCharSet::empty();
            $set = $ascii->union(ByteCharSet::fromBytes(self::leadByte(max($start, 0x80)), self::leadByte($end)));
        }

        return $set;
    }

    /**
     * The characters the characters from $start to $end match besides
     * themselves under i. An ASCII letter matches its other case. Above
     * ASCII, read under PCRE2's own tables, a byte has no other case; under
     * (*UCP) from PCRE2 10.35 a Latin-1 letter has the other one where it
     * is a byte, "\xC9" for "\xE9". Under UTF a character above ASCII may
     * match characters that start with any byte, and "k" and "s" match the
     * Kelvin sign and the long s, both ways, unless r keeps ASCII apart.
     * Under Turkish casing "i" and "I" are no pair: "i" matches the capital
     * dotted I, U+0130, and "I" the small dotless i, U+0131, both ways. The
     * tables give the other case of a byte, and under UTF, or UCP from
     * 10.35, of an ASCII character: another locale's give it as
     * casesInTables() reads them.
     */
    private function otherCases(int $start, int $end): ByteCharSet
    {
        $set = ByteCharSet::empty();
        if (!$this->ownTables) {
            $set = $this->casesInTables($start, $this->utf || $this->ucpCases ? min($end, 0x7F) : $end);
            if ($set->isUnknown()) {
                return $set;
            }
        } elseif ($start === $end && $end <= 0x7F) {
            // One ASCII character, the case of most literals.
            $set = Ascii::isAlpha(\chr($start)) ? ByteCharSet::fromChar(\chr($start ^ 0x20)) : $set;
        } else {
            foreach ([[\ord('A'), \ord('Z'), 0x20], [\ord('a'), \ord('z'), -0x20]] as [$first, $last, $shift]) {
                if (max($start, $first) <= min($end, $last)) {
                    $set = $set->union(ByteCharSet::fromRange(max($start, $first) + $shift, min($end, $last) + $shift));
                }
            }
        }

        if ($this->turkish) {
            $set = $set->intersect(self::bytes('iI')->complement(0xFF));
        }

        if (!$this->readsAboveAscii) {
            return $set;
        }

        if ($this->utf) {
            if (!$this->restricted) {
                foreach ([['k', "\u{212A}"], ['s', "\u{17F}"]] as [$letter, $partner]) {
                    if (self::holdsALetter($start, $end, $letter)) {
                        $set = $set->union(ByteCharSet::fromChar($partner));
                    }

                    if ($start <= mb_ord($partner, 'UTF-8') && mb_ord($partner, 'UTF-8') <= $end) {
                        $set = $set->union(self::bytes($letter.Ascii::upper($letter)));
                    }
                }
            }

            if ($this->turkish) {
                foreach ([['i', 0x130], ['I', 0x131]] as [$letter, $partner]) {
                    if ($start <= \ord($letter) && \ord($letter) <= $end) {
                        $set = $set->union(ByteCharSet::fromBytes(self::leadByte($partner), self::leadByte($partner)));
                    }

                    if ($start <= $partner && $partner <= $end) {
                        $set = $set->union(ByteCharSet::fromChar($letter));
                    }
                }
            }

            return $end > 0x7F ? $set->union(ByteCharSet::fromBytes(0x80, 0xFF)) : $set;
        }

        if ($this->ucpCases) {
            for ($byte = max($start, 0x80); $byte <= min($end, 0xFF); ++$byte) {
                $char = (string) mb_chr($byte, 'UTF-8');
                foreach ([\MB_CASE_UPPER_SIMPLE, \MB_CASE_LOWER_SIMPLE] as $case) {
                    $other = mb_ord(mb_convert_case($char, $case, 'UTF-8'), 'UTF-8');
                    if (false !== $other && $other <= 0xFF) {
                        $set = $set->union(ByteCharSet::fromBytes($other, $other));
                    }
                }
            }
        }

        return $set;
    }

    /**
     * The other case of each character from $start to $end, a byte or an
     * ASCII character, as another locale's tables give it: none where the
     * range holds no such character. Under UTF the case of an ASCII
     * character is a code point to a class, and a byte to the interpreter,
     * which compares the first byte of a character with it: the set holds
     * both. Not known where the tables are not, where the engine's paths or
     * its releases read a case otherwise, under Turkish casing, which reads
     * "i" and "I" apart from the tables, and in ASCII alone, where a case
     * above it has no place.
     */
    private function casesInTables(int $start, int $end): ByteCharSet
    {
        if ($start > $end) {
            return ByteCharSet::empty();
        }

        if (null === $this->tables || $this->turkish || !$this->readsAboveAscii) {
            return ByteCharSet::unknown();
        }

        $set = ByteCharSet::empty();
        for ($byte = $start; $byte <= $end; ++$byte) {
            $other = $this->tables->settledOtherCase($byte);
            if (null === $other) {
                return ByteCharSet::unknown();
            }

            $set = $set->union(ByteCharSet::fromBytes($other, $other))->union($this->utf && $other > 0x7F ? ByteCharSet::fromBytes(self::leadByte($other), self::leadByte($other)) : ByteCharSet::empty());
        }

        return $set;
    }

    /**
     * Under i, the other case of the character a literal starts at $at,
     * the byte itself without UTF.
     */
    private function otherCasesOfLiteral(string $value, int $at): ByteCharSet
    {
        $codePoint = \ord($value[$at]);
        if ($codePoint > 0x7F && $this->readsAboveAscii && $this->utf) {
            $codePoint = mb_ord((string) substr($value, $at, Utf8::width($value, $at)), 'UTF-8');
        }

        return false === $codePoint ? ByteCharSet::unknown() : $this->otherCases($codePoint, $codePoint);
    }

    /**
     * Each byte of a literal a class reads without UTF, and under i its
     * other case.
     */
    private function eachByte(string $value): ByteCharSet
    {
        $set = ByteCharSet::empty();
        foreach (Bytes::split($value) as $byte) {
            $set = $set->union($this->fromCodePoints(\ord($byte), \ord($byte)));
        }

        return $set;
    }

    private static function holdsALetter(int $start, int $end, string $letter): bool
    {
        foreach ([$letter, Ascii::upper($letter)] as $case) {
            if ($start <= \ord($case) && \ord($case) <= $end) {
                return true;
            }
        }

        return false;
    }

    /**
     * The byte UTF-8 starts a code point above ASCII with.
     */
    private static function leadByte(int $codePoint): int
    {
        switch (true) {
            case true === $codePoint < 0x800:
                return 0xC0 | $codePoint >> 6;
            case true === $codePoint < 0x10000:
                return 0xE0 | $codePoint >> 12;
            default:
                return 0xF0 | $codePoint >> 18;
        }
    }

    /**
     * The analyzer inside the group: a scoped option setting, "(?i:...)",
     * changes the flags there.
     */
    private function inside(GroupNode $group): self
    {
        return GroupType::InlineFlags() === $group->type && null !== $group->flags && !self::setsOptionsOnly($group)
            ? $this->with($group->flags)
            : $this;
    }

    /**
     * The analyzer after the node: an option setting alone, "(?i)", changes
     * the flags for what follows it, as one at the top of a sequence does.
     */
    private function after(NodeInterface $node): self
    {
        if ($node instanceof GroupNode && self::setsOptionsOnly($node)) {
            return $this->with((string) $node->flags);
        }

        $analyzer = $this;
        if ($node instanceof SequenceNode) {
            foreach ($node->children as $child) {
                $analyzer = $analyzer->after($child);
            }
        }

        return $analyzer;
    }

    /**
     * The analyzer at each child of a sequence whose start reads this one,
     * where an option setting before it changed the flags.
     *
     * @param array<int|string, NodeInterface> $children
     *
     * @return array<int|string, self>
     */
    private function acrossChildren(array $children): array
    {
        $analyzers = [];
        $analyzer = $this;
        foreach ($children as $index => $child) {
            if ($analyzer !== $this) {
                $analyzers[$index] = $analyzer;
            }

            // Only a group or a sequence may set options for what follows.
            if ($child instanceof GroupNode || $child instanceof SequenceNode) {
                $analyzer = $analyzer->after($child);
            }
        }

        return $analyzers;
    }

    /**
     * @param \SplObjectStorage<NodeInterface, self> $inForce
     */
    private function mapInForce(NodeInterface $node, \SplObjectStorage $inForce): void
    {
        $inForce[$node] = $this;
        if ($node instanceof GroupNode) {
            $this->inside($node)->mapInForce($node->child, $inForce);

            return;
        }

        $analyzer = $this;
        foreach ($node->getChildren() as $child) {
            $analyzer->mapInForce($child, $inForce);
            $analyzer = $analyzer->after($child);
        }
    }

    /**
     * The analyzer under the flags an option setting writes: "i", "s-i",
     * "^i". Of PCRE2's ASCII options, "aS" and "a" alone hold "\s" to ASCII
     * under (*UCP), and after the hyphen let it go; "^" leaves them as they
     * are. The others narrow "\d", "\w" and the POSIX classes, which the
     * sets do not read under (*UCP).
     */
    private function with(string $options): self
    {
        [$on, $off] = explode('-', $options, 2) + [1 => ''];
        $asciiSpaces = !self::holdsSpacesToAscii($off) && ($this->asciiSpaces || self::holdsSpacesToAscii($on));
        $setting = InlineFlags::read(InlineFlags::withoutAsciiOptions($options), InlineFlags::LETTERS.'r');

        if (null === $setting && $asciiSpaces === $this->asciiSpaces) {
            return $this;
        }

        return new self((($nullsafeVariable1 = $setting) ? $nullsafeVariable1->applyTo($this->flags) : null) ?? $this->flags, $this->startOptions, $this->readsAboveAscii, $this, $asciiSpaces);
    }

    /**
     * Whether the option letters name "aS", or "a" alone, which stands for
     * every ASCII option: "a" followed by "D", "P", "T" or "W" names that
     * one only.
     */
    private static function holdsSpacesToAscii(string $letters): bool
    {
        for ($at = strpos($letters, 'a'); false !== $at; $at = strpos($letters, 'a', $at + 1)) {
            if (!\in_array($letters[$at + 1] ?? '', ['D', 'P', 'T', 'W'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The tables of another locale than "C", as the engine compiles with
     * them: null for tables not known, and where the machine cannot set the
     * locale.
     */
    private static function tablesOf(?string $locale): ?CharacterTables
    {
        if (null === $locale || CharacterTables::BUILT_IN === $locale) {
            return null;
        }

        try {
            return CharacterTables::forLocale($locale);
        } catch (InvalidRegexOptionException $exception) {
            return null;
        }
    }

    /**
     * What the start options set, read once for the pattern: the analyzers
     * an option setting makes take it as read.
     *
     * @return array{newline: string, anyCrlfBreaks: bool, utf: bool, ucp: bool, restricted: bool, turkish: bool}
     */
    private static function readStartOptions(string $startOptions): array
    {
        $options = StartOptions::items($startOptions);
        // The last of (*BSR_ANYCRLF) and (*BSR_UNICODE) decides.
        $breaks = array_values(array_intersect($options, ['BSR_ANYCRLF', 'BSR_UNICODE']));

        return [
            'newline' => StartOptions::newline($startOptions),
            'anyCrlfBreaks' => 'BSR_ANYCRLF' === ($breaks[\count($breaks) - 1] ?? null),
            'utf' => \in_array('UTF', $options, true) || \in_array('UTF8', $options, true),
            'ucp' => \in_array('UCP', $options, true),
            'restricted' => \in_array('CASELESS_RESTRICT', $options, true),
            'turkish' => \in_array('TURKISH_CASING', $options, true),
        ];
    }

    /**
     * Whether the group is an option setting alone, "(?i)", rather than one
     * holding a body, "(?i:)", whose span its flags and three bytes tell.
     */
    private static function setsOptionsOnly(GroupNode $group): bool
    {
        return GroupType::InlineFlags() === $group->type
            && null !== $group->flags
            && $group->getEndPosition() - $group->getStartPosition() === \strlen($group->flags) + 3;
    }

    /**
     * Whether the group is a lookaround, or the scan of a captured
     * substring, which the engine reads without taking a character.
     */
    private static function readsNothing(GroupNode $group): bool
    {
        return \in_array($group->type, [GroupType::LookaheadPositive(), GroupType::LookaheadNegative(), GroupType::LookbehindPositive(), GroupType::LookbehindNegative(), GroupType::ScanSubstring()], true);
    }

    private static function bytes(string $bytes): ByteCharSet
    {
        $set = ByteCharSet::empty();
        foreach (Bytes::split($bytes) as $byte) {
            $set = $set->union(ByteCharSet::fromChar($byte));
        }

        return $set;
    }

    /**
     * The code point a range starts or ends with: a letter of several
     * bytes is one under UTF, and without it the class reads its bytes
     * apart, so the range is not known.
     */
    private function codePoint(NodeInterface $node): ?int
    {
        if ($node instanceof CharLiteralNode || $node instanceof ControlCharNode) {
            return $node->codePoint;
        }

        if (!$node instanceof LiteralNode || '' === $node->value) {
            return null;
        }

        if (1 === \strlen($node->value)) {
            return \ord($node->value);
        }

        $codePoint = $this->utf ? mb_ord($node->value, 'UTF-8') : false;

        return false === $codePoint ? null : $codePoint;
    }

    /**
     * The tree already reads "{,5}" as the target does: a quantifier from
     * PCRE2 10.43, text before.
     */
    private function isOptional(QuantifierNode $node): bool
    {
        return 0 === (($nullsafeVariable2 = $node->bounds) ? $nullsafeVariable2->min : null);
    }

    private function isOptionalNode(NodeInterface $node, bool $fromStart): bool
    {
        if ($node instanceof LiteralNode) {
            return '' === $node->value;
        }

        // A repeat of an item that may match empty may too, whatever its
        // count: "(?=a){1}" reads nothing, and "(a?)+" may.
        if ($node instanceof QuantifierNode) {
            return $this->isOptional($node) || $this->isOptionalNode($node->node, $fromStart);
        }

        if ($node instanceof GroupNode) {
            return self::readsNothing($node) || $this->isOptionalNode($node->child, $fromStart);
        }

        if ($node instanceof ScriptRunNode) {
            return null !== $node->content && $this->isOptionalNode($node->content, $fromStart);
        }

        // An alternation may match empty through any of its branches:
        // "(?:|b)a" matches "a".
        if ($node instanceof AlternationNode) {
            foreach ($node->alternatives as $alternative) {
                if ($this->isOptionalNode($alternative, $fromStart)) {
                    return true;
                }
            }

            return false;
        }

        if ($node instanceof SequenceNode) {
            $children = $fromStart ? $node->children : array_reverse($node->children);
            foreach ($children as $child) {
                if ($this->isOptionalNode($child, $fromStart)) {
                    continue;
                }

                return false;
            }

            return true;
        }

        return self::isStartOption($node);
    }

    /**
     * Whether the node is an option of the run a pattern opens with,
     * "(*UCP)" or "(*LIMIT_MATCH=5)": it reads nothing, and the analyzer
     * reads its effect from that run.
     */
    private static function isStartOption(NodeInterface $node): bool
    {
        return $node instanceof LimitMatchNode || ($node instanceof PcreVerbNode && isset(StartOptions::NAMES[$node->name]));
    }
}
