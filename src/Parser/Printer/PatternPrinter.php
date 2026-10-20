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

namespace PHPRegex\Parser\Printer;

use PHPRegex\Parser\AbstractNodeVisitor;
use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\CalloutNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharLiteralType;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\ClassSetOperationNode;
use PHPRegex\Parser\Node\ClassSetOperator;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\ConditionalNode;
use PHPRegex\Parser\Node\ControlCharNode;
use PHPRegex\Parser\Node\DefineNode;
use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\ExtendedCharClassNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\KeepNode;
use PHPRegex\Parser\Node\LimitMatchNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\PcreVerbNode;
use PHPRegex\Parser\Node\PosixClassNode;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\ScriptRunNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\SubroutineNode;
use PHPRegex\Parser\Node\UnicodePropNode;
use PHPRegex\Parser\Node\VersionConditionNode;
use PHPRegex\Parser\Syntax\InlineFlags;
use PHPRegex\Parser\Syntax\PatternParser;
use PHPRegex\Parser\Syntax\StartOptions;
use PHPRegex\Parser\Text\Ascii;
use PHPRegex\Parser\Text\Bytes;

/**
 * Writes a regex tree back as a pattern, delimiters and flags included.
 *
 * Each constructor gives the printer for one way of writing it back:
 *
 * - asWritten() gives the pattern back as its author wrote it: the
 *   escapes, the comments, the white space x skips and the spelling of
 *   each construct come back from the source, and a node the source does
 *   not hold, one an optimizer wrote, is spelled by the library. The rest
 *   of the library prints this way.
 * - normalized() writes the library's spelling whatever the source, so
 *   that two patterns written differently print the same.
 * - pretty() writes the normalized spelling and, under an x the pattern
 *   already has, lays it out over lines as the formatter does.
 * - forFormatting() and forCompacting(), through which PatternFormatter
 *   works, lay a pattern out under x in its author's spelling, adding the
 *   x it lacks, or write it on one line without its global x, its layout
 *   or its comments; where that would change what the pattern matches,
 *   they give it back as written, and refusal() says why.
 *
 * The printer spells each node. Where a laid-out pattern breaks into lines
 * is PatternLayout's to decide, and how a body must be written for PHP to
 * end the pattern where PCRE does is DelimiterBrackets'.
 *
 * @extends AbstractNodeVisitor<string>
 */
final class PatternPrinter extends AbstractNodeVisitor
{
    /**
     * The flags a non-atomic lookaround carries: "(?*...)" and "(?<*...)".
     */
    private const NON_ATOMIC_FLAG = '*';

    /**
     * Stands in the flags in force while "(?xx)" is: a space in a class is
     * then skipped, which no PHP modifier says.
     */
    private const EXTENDED_MORE = '+';

    /**
     * The pattern as the tree and its source spell it, on the lines it was
     * written on: what the library prints everywhere else.
     */
    private const MODE_OFF = 'off';

    /**
     * Laid out over lines under x, in the author's spelling.
     */
    private const MODE_FORMAT = 'format';

    /**
     * On one line, without the global x, its layout or its comments.
     */
    private const MODE_COMPACT = 'compact';

    /**
     * Laid out like the formatter does, in the normalized spelling, and only
     * under an x the pattern already has.
     */
    private const MODE_PRETTY = 'pretty';

    private const PRETTY_WIDTH = 80;

    /**
     * Where pretty printing writes a bracket delimiter, the bytes that may
     * stand for the backslash of a literal bracket until the balance is
     * known (see bracketMark): control bytes that are no white space, which
     * no trailing-comment marker holds.
     */
    private const BRACKET_MARKS = "\x03\x04\x05\x06\x07\x08\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F";

    /**
     * How far back from the end of the text joined so far an escape or a
     * repeat count may start that the next item would extend.
     */
    private const MAX_ESCAPE_LENGTH = 64;

    /**
     * Braces PCRE2 reads as a repeat from 10.43 on, where spaces and tabs may
     * stand around the bounds and the comma: "{ 1,2 }", "{2, }", "{ ,2}".
     * Before 10.43 they are text. A line break in them, "{ }" and "{ ,}" are
     * text on every engine.
     */
    private const SPACED_BRACES = '/\G\{[ \t]*+(?:[0-9]++[ \t]*+(?:,[ \t]*+[0-9]*+[ \t]*+)?|,[ \t]*+[0-9]++[ \t]*+)\}/';

    /**
     * The characters a literal escapes outside a class.
     */
    private const META_CHARACTERS = [
        '\\' => true, '.' => true, '^' => true, '$' => true,
        '[' => true, ']' => true, '(' => true, ')' => true,
        '|' => true, '*' => true, '+' => true, '?' => true, '{' => true, '}' => true,
    ];

    /**
     * The characters besides ASCII white space that "x" skips in UTF mode,
     * each with the escape that keeps it.
     */
    private const UTF_PATTERN_WHITE_SPACE = ["\u{85}" => '\\x{85}', "\u{200E}" => '\\x{200E}', "\u{200F}" => '\\x{200F}', "\u{2028}" => '\\x{2028}', "\u{2029}" => '\\x{2029}'];

    /**
     * The characters a literal escapes in a class.
     */
    private const NAMED_GROUP_SYMBOLS = [
        'plb' => '(?<=', 'positive_lookbehind' => '(?<=',
        'nlb' => '(?<!', 'negative_lookbehind' => '(?<!',
        'naplb' => '(?<*', 'non_atomic_positive_lookbehind' => '(?<*',
        'atomic' => '(?>',
    ];

    private const CHAR_CLASS_META = [
        '\\' => true, ']' => true, '-' => true, '^' => true, '[' => true,
    ];

    /**
     * Inside a character class, where other characters are special.
     */
    private bool $inCharClass = false;

    /**
     * Inside "(?[...])", where a plain character is no operand.
     */
    private bool $inExtendedClass = false;

    /**
     * In a class inside "(?[...])", read under "xx", where a space is skipped.
     */
    private bool $inClassOfExtendedClass = false;

    private string $delimiter = '/';

    private string $closingDelimiter = '/';

    private string $flags = '';

    /**
     * Whether a leading (*UTF) makes the pattern UTF-8, as the u flag does.
     */
    private bool $utfVerb = false;

    /**
     * Pattern body the AST was parsed from, when it is known.
     */
    private ?string $source = null;

    /**
     * Cached \Q...\E regions of $source.
     *
     * @var list<array{int, int}>|null
     */
    private ?array $quotedSpans = null;

    /**
     * The tree of $source, which tells a "\Q" in a comment or a name from
     * one that opens a quote.
     */
    private ?NodeInterface $sourcePattern = null;

    /**
     * The layout of the pattern being formatted or pretty printed. While it
     * is active, the node being visited may break over lines: the formatter
     * or pretty printing lays a pattern out under x, where newlines and
     * indents are ignored. Without x the layout would change what the
     * pattern matches.
     */
    private ?PatternLayout $layout = null;

    /**
     * Capturing groups written so far: whether "\NN" is read as a reference
     * where it is written depends on them.
     */
    private int $capturesOpened = 0;

    /**
     * One of the MODE_* constants.
     */
    private string $mode;

    /**
     * The width a laid-out line aims at, indent included.
     */
    private int $width = self::PRETTY_WIDTH;

    /**
     * Whether widths count code points (u, or a leading (*UTF)) or bytes.
     */
    private bool $countsCodePoints = false;

    /**
     * Why the last pattern formatted was left as written, if it was.
     */
    private ?FormatRefusal $refusal = null;

    /**
     * Whether the formatter adds x to a pattern written without it: a raw
     * no-break space is then escaped, as PHP's locale tables may call it
     * white space.
     */
    private bool $addsExtended = false;

    /**
     * The comments a compacted pattern keeps, by object id, each with the
     * text of the "(?#...)" written for it: each holds a bracket that the
     * delimiter's balance needs.
     *
     * @var array<int, string>
     */
    private array $keptComments = [];

    /**
     * The source offsets of the brackets of "\Q...\E" runs that a rewritten
     * pattern keeps as written: each pairs with a bracket written as is.
     *
     * @var array<int, true>
     */
    private array $keptQuotedBrackets = [];

    /**
     * Where pretty printing writes a bracket delimiter, or a pattern is
     * written again to balance one (see rebalancingMark), the mark that stands
     * for the backslash of a literal bracket until the balance is known: the
     * bracket stays counted when it pairs with one written as is.
     *
     * @var non-empty-string|null
     */
    private ?string $bracketMark = null;

    /**
     * While the pattern is written again, marked, because PHP would not end
     * it where written, the mark (see bracketMark): every literal bracket of
     * the delimiter is marked then, unescaped ones too, and each lookbehind
     * or atomic group whose symbol holds one (see
     * DelimiterBrackets::rebalanced).
     *
     * @var non-empty-string|null
     */
    private ?string $rebalancingMark = null;

    /**
     * The groups holding only comments that a compacted pattern drops, by
     * object id.
     *
     * @var array<int, true>
     */
    private array $droppedGroups = [];

    /**
     * The source span of the last braces joined that hold a space or a tab
     * and read as a repeat from PCRE2 10.43 on, "{ 1,2 }": under x their
     * spaces stay as written.
     *
     * @var array{int, int}|null
     */
    private ?array $spacedBraces = null;

    /**
     * asWritten(), normalized() and pretty() name the printer each flag set
     * here gives. $collapseExtendedComments gives one more: a normalized
     * printer that writes every comment under x as "(?#...)".
     */
    public function __construct(
        /**
         * When true, escapes are normalized and, under x only, the pattern
         * is laid out over lines as the formatter lays it out, at a width
         * of 80; without x it is written on one line, as newlines and
         * indents would change what it matches.
         */
        private readonly bool $pretty = false,
        /**
         * When true, comments in extended (/x) mode are collapsed to a generic
         * "(?#...)" placeholder. This is useful for generating a normalized
         * representation of verbose regexes without leaking full comment text.
         */
        private readonly bool $collapseExtendedComments = false,
        /**
         * When false, escapes and comment syntax are normalized instead of
         * being given back the way the pattern spelled them. Comparing two
         * patterns needs that normalized form.
         */
        private readonly bool $preserveSpelling = true
    ) {
        $this->mode = $this->pretty ? self::MODE_PRETTY : self::MODE_OFF;
    }

    /**
     * A printer that writes a pattern back as its author wrote it. The
     * escapes, the comments, the white space x skips and the spelling of
     * each construct, "(?P<n>" or "\k{n}", come back from the source the
     * tree was parsed from; a node the source does not hold, one an
     * optimizer wrote, is spelled by the library. What the library prints
     * everywhere else.
     */
    public static function asWritten(): self
    {
        return new self();
    }

    /**
     * A printer that writes a pattern in the library's spelling, whatever
     * its source: escapes, references and comments normalized, the white
     * space x skips dropped. Two patterns that differ only in how they are
     * written print the same, which comparing them needs.
     */
    public static function normalized(): self
    {
        return new self(false, false, false);
    }

    /**
     * A printer that writes a pattern in the normalized spelling and, under
     * an x the pattern already has, lays it out over lines as the formatter
     * does, at a width of 80. Without x it writes one line, as a line break
     * would be a literal there; it never adds x, and never refuses.
     */
    public static function pretty(): self
    {
        return new self(true);
    }

    /**
     * A printer that lays a pattern out over lines under x, in its author's
     * spelling, when it does not fit the width.
     *
     * @internal PatternFormatter lays patterns out through it
     */
    public static function forFormatting(int $width): self
    {
        $printer = new self();
        $printer->mode = self::MODE_FORMAT;
        $printer->width = $width;

        return $printer;
    }

    /**
     * A printer that writes a pattern on one line, without the global x, its
     * layout or its comments.
     *
     * @internal PatternFormatter compacts patterns through it
     */
    public static function forCompacting(): self
    {
        $printer = new self();
        $printer->mode = self::MODE_COMPACT;

        return $printer;
    }

    /**
     * Why the last pattern formatted was given back as written, or null when
     * it was not refused.
     *
     * @internal PatternFormatter reports it
     */
    public function refusal(): ?FormatRefusal
    {
        return $this->refusal;
    }

    /**
     * @internal the optimizer reuses one printer
     */
    public function resetState(): void
    {
        $this->inCharClass = false;
        $this->inExtendedClass = false;
        $this->inClassOfExtendedClass = false;
        $this->delimiter = '/';
        $this->closingDelimiter = '/';
        $this->flags = '';
        $this->utfVerb = false;
        $this->source = null;
        $this->quotedSpans = null;
        $this->sourcePattern = null;
        $this->layout = null;
        $this->countsCodePoints = false;
        $this->refusal = null;
        $this->addsExtended = false;
        $this->keptComments = [];
        $this->keptQuotedBrackets = [];
        $this->droppedGroups = [];
        $this->bracketMark = null;
        $this->spacedBraces = null;
    }

    /**
     * The modifiers in force where the printer stands: visiting a node may
     * change them, "(?-x)" for one.
     *
     * @internal PatternLayout reads them as it lays a pattern out
     */
    public function flags(): string
    {
        return $this->flags;
    }

    /**
     * @internal PatternLayout sets the modifiers in force inside a group it lays out
     */
    public function setFlags(string $flags): void
    {
        $this->flags = $flags;
    }

    /**
     * The capturing groups opened so far and the modifiers in force, to put
     * back when a layout tried on one line is given up.
     *
     * @return array{int, string}
     *
     * @internal PatternLayout saves them before it tries a node on one line
     */
    public function scope(): array
    {
        return [$this->capturesOpened, $this->flags];
    }

    /**
     * @param array{int, string} $scope
     *
     * @internal PatternLayout puts them back when a node does not fit on one line
     */
    public function restoreScope(array $scope): void
    {
        [$this->capturesOpened, $this->flags] = $scope;
    }

    /**
     * Count a capturing group as opened: whether "\NN" is read as a
     * reference where it is written depends on how many are.
     *
     * @internal PatternLayout counts the groups it lays out
     */
    public function countCapture(GroupNode $node): void
    {
        if (GroupType::Capturing === $node->type || GroupType::Named === $node->type) {
            $this->capturesOpened++;
        }
    }

    /**
     * The text that opens a group: as the pattern spelled it where the
     * source says so, as the tree spells it otherwise.
     *
     * @internal PatternLayout writes the opening of a group it lays out
     */
    public function groupOpening(GroupNode $node, string $flags): string
    {
        $opening = $this->openingAsWritten($node, $this->treeOpening($node, $flags));

        // Under "<>" the symbol of a lookbehind or an atomic group holds a
        // bracket PHP counts: marked, it may take its name instead.
        return null !== $this->rebalancingMark && '<' === $this->delimiter && isset(DelimiterBrackets::NAMED_OPENINGS[$opening])
            ? $this->rebalancingMark.DelimiterBrackets::MARK_GROUP.$opening
            : $opening;
    }

    #[\Override]
    public function visitRegex(RegexNode $node): string
    {
        if (self::MODE_OFF !== $this->mode) {
            return $this->formatRegex($node);
        }

        $this->capturesOpened = 0;
        $this->delimiter = $node->delimiter;
        $this->flags = $node->flags;
        $this->utfVerb = self::startsWithUtfVerb($node->pattern);
        $this->closingDelimiter = PatternParser::closingDelimiter($node->delimiter);
        $this->source = $this->collapseExtendedComments || !$this->preserveSpelling
            ? null
            : $node->source;
        $this->quotedSpans = null;
        $this->sourcePattern = $node->pattern;
        // A "\Q...\E" run holding a bracket of the delimiter that pairs with
        // one PHP counts stays as written: respelled "\{", PHP would not
        // count it.
        $this->keptQuotedBrackets = $this->delimiter === $this->closingDelimiter
            ? []
            : DelimiterBrackets::asWritten($node, $this->source, $this->quotedSpans(), false)[1];

        $write = function () use ($node): string {
            $this->capturesOpened = 0;
            $this->flags = $node->flags;

            return $this->ignorableText(0, $node->pattern->getStartPosition())
                .$node->pattern->accept($this)
                .$this->ignorableText($node->pattern->getEndPosition(), null);
        };

        return $node->delimiter.$this->endingWhereWritten($write(), $write).$this->closingDelimiter.$node->flags;
    }

    #[\Override]
    public function visitAlternation(AlternationNode $node): string
    {
        $alternatives = $node->alternatives;
        if ([] === $alternatives) {
            return '';
        }

        if ($this->inCharClass) {
            return $this->compileCharClassMembers($alternatives);
        }

        if (null !== $this->layout && $this->layout->isActive()) {
            return $this->layout->alternation($alternatives);
        }

        $separator = '|';
        $result = $this->ignorableText($node->getStartPosition(), $alternatives[0]->getStartPosition());
        $result .= $alternatives[0]->accept($this);

        for ($i = 1, $count = \count($alternatives); $i < $count; $i++) {
            [$before, $after] = $this->ignorableTextAroundSeparator($alternatives[$i - 1], $alternatives[$i]);
            $result .= $before.$separator.$after.$alternatives[$i]->accept($this);
        }

        $last = $alternatives[\count($alternatives) - 1];

        return $result.$this->ignorableText($last->getEndPosition(), $node->getEndPosition());
    }

    #[\Override]
    public function visitSequence(SequenceNode $node): string
    {
        $children = $node->children;
        if ([] === $children) {
            return '';
        }

        if ($this->inCharClass) {
            return $this->compileCharClassMembers($children);
        }

        if (null !== $this->layout && $this->layout->isActive()) {
            return $this->layout->sequence($node);
        }

        $result = $this->ignorableText($node->getStartPosition(), $children[0]->getStartPosition());
        $result .= $children[0]->accept($this);

        for ($i = 1, $count = \count($children); $i < $count; $i++) {
            $next = $children[$i]->accept($this);
            $result .= $this->separator($result, $children[$i - 1], $children[$i], $next).$next;
        }

        $last = $children[\count($children) - 1];

        return $result.$this->ignorableText($last->getEndPosition(), $node->getEndPosition());
    }

    #[\Override]
    public function visitGroup(GroupNode $node): string
    {
        // "[[:<:]]" and "[[:>:]]" stand for "\b(?=\w)" and "\b(?<=\w)".
        $written = $this->writtenText($node);
        if ('[[:<:]]' === $written || '[[:>:]]' === $written) {
            return $written;
        }

        $flags = $node->flags ?? '';

        if (null !== $this->layout && $this->layout->isActive() && !PatternLayout::isEmpty($node->child)) {
            $inner = GroupType::InlineFlags === $node->type ? $this->withInlineFlags($this->flags, $flags) : $this->flags;

            return $this->layout->group($node, $flags, $inner);
        }

        $this->countCapture($node);

        $child = $this->compileGroupChild($node, $flags);

        // A non-capturing group holding nothing but comments matches the
        // empty string once compact drops them, and sets nothing: it goes
        // too. A quantifier on it writes "(?:)", a separator writes one
        // where its neighbours would read as one item.
        if ('' === $child && self::MODE_COMPACT === $this->mode && GroupType::NonCapturing === $node->type && !PatternLayout::isEmpty($node->child)) {
            $this->droppedGroups[spl_object_id($node)] = true;

            return '';
        }

        if ($this->isUnscopedSetting($node, $flags, $child)) {
            return '(?'.$flags.')';
        }

        $opening = $this->groupOpening($node, $flags);

        // Whitespace hugging the parentheses belongs to no node, so it is read
        // back from the source like any other /x filler.
        $start = $node->getStartPosition() + \strlen($opening);

        return $opening
            .$this->ignorableText($start, $node->child->getStartPosition())
            .$child
            .$this->ignorableText($node->child->getEndPosition(), $node->getEndPosition() - 1)
            .')';
    }

    #[\Override]
    public function visitQuantifier(QuantifierNode $node): string
    {
        $nodeCompiled = $node->node->accept($this);

        // A group compact dropped, "(?:#c\n)*", still carries the quantifier.
        if ('' === $nodeCompiled && $node->node instanceof GroupNode) {
            $nodeCompiled = '(?:)';
        }

        if ($node->node instanceof SequenceNode || $node->node instanceof AlternationNode) {
            $nodeCompiled = '(?:'.$nodeCompiled.')';
        }

        $suffix = match ($node->type) {
            QuantifierType::Lazy => '?',
            QuantifierType::Possessive => '+',
            default => '',
        };

        $quantifier = $this->normalizeQuantifier($node->quantifier);

        return $nodeCompiled.$quantifier.$suffix;
    }

    #[\Override]
    public function visitLiteral(LiteralNode $node): string
    {
        $value = $node->value;

        if ('' === $value) {
            return '';
        }

        // Raw literals should not be escaped (used for regex syntax characters)
        if ($node->isRaw) {
            return $value;
        }

        if (null !== $this->layout && $this->layout->isActive() && !$this->inCharClass && !$this->inExtendedClass) {
            return $this->layout->literal($node);
        }

        $quoted = $this->quotedRunAsWritten($node);
        if (null !== $quoted) {
            return $quoted;
        }

        if ($this->inExtendedClass) {
            return $this->writtenSpelling($node, $this->extendedClassOperand($value), $value);
        }

        // Special case for closing bracket outside char class
        if (!$this->inCharClass && ']' === $value && ']' !== $this->closingDelimiter) {
            return $this->writtenSpelling($node, $value, $value);
        }

        return $this->markedWritten($this->writtenSpelling($node, $this->escapeString($value), $value));
    }

    #[\Override]
    public function visitDot(DotNode $node): string
    {
        return '.';
    }

    #[\Override]
    public function visitAnchor(AnchorNode $node): string
    {
        return $node->value;
    }

    #[\Override]
    public function visitAssertion(AssertionNode $node): string
    {
        return '\\'.$node->value;
    }

    #[\Override]
    public function visitCharType(CharTypeNode $node): string
    {
        return '\\'.$node->value;
    }

    #[\Override]
    public function visitKeep(KeepNode $node): string
    {
        return '\K';
    }

    #[\Override]
    public function visitExtendedCharClass(ExtendedCharClassNode $node): string
    {
        // The class as written, where the pattern is: the text has the node's
        // own layout, wherever the class stands.
        if (null !== $this->source && '' !== $node->text) {
            return $node->text;
        }

        [$inCharClass, $inExtendedClass] = [$this->inCharClass, $this->inExtendedClass];
        $this->inCharClass = $this->inExtendedClass = true;

        try {
            return '(?['.$node->expression->accept($this).'])';
        } finally {
            [$this->inCharClass, $this->inExtendedClass] = [$inCharClass, $inExtendedClass];
        }
    }

    /**
     * Without the source, an operation is parenthesised where precedence
     * needs it, and nowhere else: PCRE2 refuses parentheses nested past a
     * depth, and one pair around each operation would double the depth of
     * "a+b&c+(...)".
     */
    #[\Override]
    public function visitClassSetOperation(ClassSetOperationNode $node): string
    {
        $binding = self::classSetBinding($node->operator);
        if (null === $node->left) {
            return '!'.$this->classSetOperand($node->right, $binding, false);
        }

        return $this->classSetOperand($node->left, $binding, false).$node->symbol.$this->classSetOperand($node->right, $binding, true);
    }

    #[\Override]
    public function visitCharClass(CharClassNode $node): string
    {
        [$wasInCharClass, $inExtendedClass, $inClassOfExtendedClass] = [$this->inCharClass, $this->inExtendedClass, $this->inClassOfExtendedClass];
        $this->inCharClass = true;
        $this->inClassOfExtendedClass = $inExtendedClass || $inClassOfExtendedClass;
        $this->inExtendedClass = false;

        try {
            $negation = $node->isNegated ? '^' : '';

            return '['.$negation.$node->expression->accept($this).']';
        } finally {
            [$this->inCharClass, $this->inExtendedClass, $this->inClassOfExtendedClass] = [$wasInCharClass, $inExtendedClass, $inClassOfExtendedClass];
        }
    }

    #[\Override]
    public function visitRange(RangeNode $node): string
    {
        return $node->start->accept($this).'-'.$node->end->accept($this);
    }

    #[\Override]
    public function visitBackref(BackrefNode $node): string
    {
        $compiled = Ascii::isDigit($node->ref) ? '\\'.$node->ref : $node->ref;

        // "(?P=name)", "\k<name>" and "\k{name}" are the same reference, so
        // the pattern keeps the syntax it was written with.
        $written = $this->writtenText($node);
        if (null !== $written && $this->referenceName($written) === $this->referenceName($compiled)) {
            return $written;
        }

        return $compiled;
    }

    #[\Override]
    public function visitCharLiteral(CharLiteralNode $node): string
    {
        $rep = $node->originalRepresentation;
        $unicodeMode = $this->utfVerb || str_contains($this->flags, 'u');

        // "\101" is an octal escape only while fewer than 101 groups open
        // before it; where more have, "\o{101}" still is one. In a class,
        // "\101" is always octal.
        if (!$this->inCharClass && CharLiteralType::OctalLegacy === $node->type
            && 1 === LibraryPcre::match('/^\\\\([1-7][0-7]*+)$/', $rep, $digits)
            && (\strlen($digits[1]) < 2 || (int) $digits[1] <= $this->capturesOpened)) {
            return '\\o{'.$digits[1].'}';
        }

        // A code point can be spelled in many ways — "\a", "\x07", the raw
        // character — and they are all valid where the pattern already used
        // them, so the original spelling wins over a normalized one.
        $written = $this->writtenText($node);
        if (null !== $written && $node->codePoint === $this->codePointOf($written)) {
            return $written;
        }

        // If it's already an escape sequence, return as is
        if (str_starts_with($rep, '\\')) {
            return $rep;
        }

        // If it's a single character, check if it needs escaping
        if (1 === \strlen($rep)) {
            $ord = \ord($rep);
            if ($ord < 32 || 127 === $ord || (!$unicodeMode && $ord >= 128)) {
                // Escape control characters and extended ASCII
                return match ($ord) {
                    9 => '\\t',
                    10 => '\\n',
                    13 => '\\r',
                    12 => '\\f',
                    27 => '\\e',
                    default => '\\x'.Ascii::upper(str_pad(dechex($ord), 2, '0', \STR_PAD_LEFT)),
                };
            }

            return $this->escapeString($rep);
        }

        return $rep;
    }

    #[\Override]
    public function visitControlChar(ControlCharNode $node): string
    {
        // "\c " is control-space under x too, and a line ending in it would
        // end in a space an editor strips: it is spelled as its code point.
        if ($this->laysOut() && !$this->inCharClass && str_contains($this->flags, 'x') && 1 === LibraryPcre::match('/[\s#]/', $node->char)) {
            return \sprintf('\\x%02X', $node->codePoint);
        }

        return '\\c'.$node->char;
    }

    #[\Override]
    public function visitScriptRun(ScriptRunNode $node): string
    {
        // "(*sr:...)" is the same verb written short, "(*asr:...)" its
        // atomic form.
        $spellings = $node->atomic ? ['asr', 'atomic_script_run'] : ['sr', 'script_run'];
        $written = $this->writtenText($node);
        if (null !== $written && \in_array($written, ['(*'.$spellings[0].':'.$node->script.')', '(*'.$spellings[1].':'.$node->script.')'], true)) {
            return $written;
        }

        return '(*'.$spellings[1].':'.$node->script.')';
    }

    #[\Override]
    public function visitVersionCondition(VersionConditionNode $node): string
    {
        // The condition alone: the conditional that holds it writes the
        // parentheses, as it does for every other kind of condition.
        return 'VERSION'.$node->operator.$node->version;
    }

    #[\Override]
    public function visitUnicodeProp(UnicodePropNode $node): string
    {
        $prop = $node->hasBraces ? trim($node->prop, '{}') : $node->prop;

        if ($node->negatedSyntax) {
            // Undo the "^" normalization so \P{L} round-trips byte-identically.
            $inner = str_starts_with($prop, '^') ? substr($prop, 1) : '^'.$prop;

            if ($node->hasBraces || \strlen($inner) > 1 || str_starts_with($inner, '^')) {
                return '\P{'.$inner.'}';
            }

            return '\P'.$inner;
        }

        if ($node->hasBraces || \strlen($prop) > 1 || str_starts_with($prop, '^')) {
            return '\p{'.$prop.'}';
        }

        return '\p'.$prop;

    }

    #[\Override]
    public function visitPosixClass(PosixClassNode $node): string
    {
        // POSIX classes only exist inside a character class, whose visitor
        // already emits the surrounding brackets.
        if ($this->inCharClass) {
            return '[:'.$node->class.':]';
        }

        return '[[:'.$node->class.':]]';
    }

    #[\Override]
    public function visitComment(CommentNode $node): string
    {
        if (null !== $this->layout && $this->layout->skips($node)) {
            return '';
        }

        // A compacted pattern is one line: a comment it keeps for its
        // bracket holds no line break either.
        if (self::MODE_COMPACT === $this->mode) {
            $kept = $this->keptComments[spl_object_id($node)] ?? null;

            return null === $kept ? '' : '(?#'.$kept.')';
        }

        // A "#" comment ends its line: a laid-out line writes it without the
        // line break, which the layout puts back.
        if ($this->laysOut() && $node->extended) {
            $this->layout?->noteLineComment();

            return null !== $this->layout && $this->layout->isActive() ? rtrim($node->comment, "\n") : $node->comment;
        }

        // A comment written as "# ..." is extended-mode whatever the pattern
        // flags say: /x can also be turned on inline with "(?x)".
        $isExtended = $node->extended || str_contains($this->flags, 'x');

        // In normalized mode, collapse all extended (/x) comments to a
        // lightweight inline placeholder so that we preserve structure
        // without leaking (or reflowing) the original comment text.
        if ($this->collapseExtendedComments && $isExtended) {
            return '(?#...)';
        }

        // Extended (/x) mode line comments (starting with '#') should be
        // preserved as real /x comments, not rewritten into (?#...) blocks,
        // with their trailing newline.
        // An inline "(?##c)" keeps its parentheses: printed as "#c" it would
        // run to the end of the line and swallow what follows.
        if ($isExtended && $node->extended) {
            return $node->comment;
        }

        // Inline comments (?#...) keep their original content without the
        // delimiters and are reconstructed using standard PCRE syntax.
        $comment = $node->comment;

        // Laid out, the line breaks of a comment are layout too: each line
        // after the first starts at the indent of the line the comment opens
        // on, its own leading white space dropped. The blank lines that end
        // it are dropped too, so that ")" follows its last text. A carriage
        // return is a line break as a line feed is, and each line break
        // stays as written.
        if (self::MODE_FORMAT === $this->mode && null !== $this->layout && $this->layout->indentLevel() > 0 && false !== strpbrk($comment, "\n\r")) {
            $trimmed = rtrim($comment, " \t\r\n");
            if (false !== strpbrk(substr($comment, \strlen($trimmed)), "\n\r")) {
                $comment = $trimmed;
            }

            $indent = PatternLayout::indent($this->layout->indentLevel());
            $lines = LibraryPcre::split('/(\r\n|\r|\n)/', $comment, -1, \PREG_SPLIT_DELIM_CAPTURE) ?: [$comment];
            $comment = $lines[0];
            for ($i = 1, $count = \count($lines); $i + 1 < $count; $i += 2) {
                $line = ltrim($lines[$i + 1], " \t");
                $comment .= $lines[$i].('' === $line ? '' : $indent.$line);
            }
        }

        return '(?#'.$comment.')';
    }

    #[\Override]
    public function visitConditional(ConditionalNode $node): string
    {
        if (null !== $this->layout && $this->layout->isActive()) {
            return $this->layout->conditional($node);
        }

        // A conditional is a group: "(?-x)" in its "yes" branch carries into
        // its "no" branch, and ends with it.
        $flags = $this->flags;
        $condition = $this->conditionText($node);
        $yes = $node->yes->accept($this);
        $no = $node->no->accept($this);
        $this->flags = $flags;

        if ('' === $no) {
            return '(?'.$condition.$yes.')';
        }

        return '(?'.$condition.$yes.'|'.$no.')';
    }

    #[\Override]
    public function visitSubroutine(SubroutineNode $node): string
    {
        $returned = [] === $node->returnedGroups ? '' : '('.implode(',', $node->returnedGroups).')';

        // "\g'n'" is "\g<n>" written with quotes; the formatter keeps them.
        if ('g' === $node->syntax && self::MODE_OFF !== $this->mode) {
            $written = $this->writtenText($node);
            $quoted = preg_quote($node->reference, '/');
            if (null !== $written && 1 === LibraryPcre::match('/^\\\\g(?:<'.$quoted.'>|\''.$quoted.'\')$/', $written)) {
                return $written;
            }
        }

        return match ($node->syntax) {
            '&' => '(?&'.$node->reference.$returned.')',
            'P>' => '(?P>'.$node->reference.$returned.')',
            'g' => '\g<'.$node->reference.'>',
            default => '(?'.$node->reference.$returned.')',
        };
    }

    #[\Override]
    public function visitPcreVerb(PcreVerbNode $node): string
    {
        $compiled = '(*'.$node->verb.')';

        // "(*:name)" is "(*MARK:name)" written short, and the tree keeps only
        // the long form. A name is read up to ")" under x too.
        $written = $this->writtenText($node, false);
        if (null !== $written && $this->namesTheSameVerb($written, $node->verb)) {
            return $written;
        }

        return $compiled;
    }

    #[\Override]
    public function visitDefine(DefineNode $node): string
    {
        // DEFINE is a group: a "(?-x)" in it ends with it.
        $flags = $this->flags;

        try {
            if (null !== $this->layout && $this->layout->isActive()) {
                return $this->layout->define($node);
            }

            return '(?(DEFINE)'.$node->content->accept($this).')';
        } finally {
            $this->flags = $flags;
        }
    }

    #[\Override]
    public function visitLimitMatch(LimitMatchNode $node): string
    {
        return '(*LIMIT_MATCH='.$node->limit.')';
    }

    #[\Override]
    public function visitCallout(CalloutNode $node): string
    {
        if (null === $node->identifier) {
            return '(?C)';
        }

        if (\is_int($node->identifier)) {
            return '(?C'.$node->identifier.')';
        }

        if (
            !$node->isStringIdentifier
            && \is_string($node->identifier)
            && LibraryPcre::match('/^[A-Z_a-z]\w*+$/', $node->identifier)
        ) {
            return '(?C'.$node->identifier.')';
        }

        // A string callout doubles its delimiter to hold it. The spelling the
        // pattern used is kept when it still carries this text, which x does
        // not read.
        $written = $this->writtenText($node, false);
        if (null !== $written && 1 === LibraryPcre::match('/^\(\?C([`\'"^%#$]|\{)(.*)\)$/s', $written, $matches)) {
            $closing = '{' === $matches[1] ? '}' : $matches[1];
            if (str_ends_with($matches[2], $closing)
                && str_replace($closing.$closing, $closing, substr($matches[2], 0, -1)) === $node->identifier) {
                return $written;
            }
        }

        return '(?C"'.str_replace('"', '""', $node->identifier).'")';
    }

    /**
     * The condition of a conditional with its parentheses, "(1)" or
     * "(?=a)". The formatter keeps "(<n>)" and "('n')" as written.
     *
     * @internal PatternLayout writes the condition of a conditional it lays out
     */
    public function conditionText(ConditionalNode $node): string
    {
        $condition = $node->condition;
        if ($condition instanceof BackrefNode) {
            $text = $condition->ref;
            $written = self::MODE_OFF === $this->mode ? null : $this->writtenText($condition);
            if ('<'.$text.'>' === $written || "'".$text."'" === $written) {
                $text = $written;
            }
        } elseif ($condition instanceof SubroutineNode) {
            // "(?(R)...)" asks whether the pattern is recursing; it is not a
            // call, so the reference is written on its own. Compiling it as
            // "(?((?R))...)" gives a pattern PCRE refuses.
            $text = $condition->reference;
        } else {
            $text = $condition->accept($this);
        }

        // An assertion condition brings its own parentheses: PCRE spells it
        // "(?(?<!x)yes|no)", not "(?((?<!x))yes|no)".
        return $this->isAssertionCondition($condition) ? $text : '('.$text.')';
    }

    /**
     * The pieces of a quoted run kept as written, as the layout reads them
     * back (see quotedRunAsWritten). A line break, a tab, a vertical tab or
     * a form feed, which a laid-out or compacted pattern spells as escapes,
     * parts the run: the part holding a bracket kept counted stays quoted,
     * the others are respelled, "\Q{a\nb\E" written "\Q{a\E\nb".
     *
     * @return list<array{string, int}>|null
     *
     * @internal PatternLayout breaks a quoted run into these pieces
     */
    public function quotedRunPieces(LiteralNode $node): ?array
    {
        $value = $node->value;
        if ([] === $this->keptQuotedBrackets || null === $this->source || $node->isRaw) {
            return null;
        }

        $start = $node->getStartPosition();
        $length = \strlen($value);
        if (!$this->holdsKeptQuotedBracket($start, $value) || substr($this->source, $start, $length) !== $value) {
            return null;
        }

        $pieces = [];
        $offset = 0;
        while ($offset < $length) {
            $part = substr($value, $offset, strcspn($value, "\n\r\t\v\f", $offset));
            if ($this->holdsKeptQuotedBracket($start + $offset, $part)) {
                $pieces[] = ['\\Q'.$part.'\\E', PatternLayout::PIECE_UNIT];
            } else {
                foreach ('' === $part ? [] : ($this->countsCodePoints ? mb_str_split($part, 1, 'UTF-8') : Bytes::split($part)) as $character) {
                    $pieces[] = [$this->escapeString($character), PatternLayout::isWhiteSpaceCharacter($character) ? PatternLayout::PIECE_SPACE : PatternLayout::PIECE_WORD];
                }
            }

            $offset += \strlen($part);
            if ($offset < $length) {
                $pieces[] = [match ($value[$offset]) {
                    "\n" => '\\n',
                    "\r" => '\\r',
                    "\t" => '\\t',
                    "\v" => '\\x0B',
                    default => '\\f',
                }, PatternLayout::PIECE_SPACE];
                $offset++;
            }
        }

        return $pieces;
    }

    /**
     * What goes between two neighbouring items. A "\E" or an empty "\Q\E"
     * between them is dropped like any other no-op, unless the two items
     * would then read as one: "(a)\1\E0" is not "(a)\10", nor "a{\E2}" the
     * repeat "a{2}". There it stays as written. When no source says how two
     * items were separated, a digit escape still needs something after it,
     * and an empty group ("\E" in a class) takes that place; a literal "{" or
     * "[" comes back escaped and needs nothing.
     *
     * Only the end of $compiled is read, so joining n items takes time linear
     * in n.
     *
     * @internal PatternLayout joins the atoms of a run with it
     */
    public function separator(string $compiled, NodeInterface $left, NodeInterface $right, string $next): string
    {
        if (self::MODE_OFF !== $this->mode && $left instanceof LiteralNode && '{' === $left->value) {
            $end = $this->spacedBracesEnd($left);
            $this->spacedBraces = null === $end ? null : [$left->getStartPosition(), $end];
        }

        $between = $this->ignorableTextBetween($left, $right);
        if ('' !== $between || '' === $next) {
            return $between;
        }

        // Under x a rewritten pattern separates the two with a space, which
        // reads as nothing: "\1 0" is the reference, then "0". It stands for
        // a written "\E" too, as a second pass would read it once a line
        // break took its place.
        if (self::takesMoreDigits($compiled, $next[0])) {
            if (!$this->inCharClass && self::MODE_OFF !== $this->mode && str_contains($this->flags, 'x')) {
                return ' ';
            }

            return $this->writtenSeparator($left->getEndPosition(), $right->getStartPosition())
                ?? ($this->inCharClass ? '\\E' : $this->emptyGroup());
        }

        if ($this->opensConstruct($compiled, $next[0])) {
            return $this->writtenSeparator($left->getEndPosition(), $right->getStartPosition())
                ?? $this->spaceInBraces($left, $right)
                ?? ($this->separatesDroppedText($left, $right) ? $this->emptyGroup() : '');
        }

        return '';
    }

    /**
     * Whether the text ends with something the next character would turn
     * into another construct: "\N" before braces, a literal "{" before a
     * repeat count, a literal "[" in a class before ":", "." or "=".
     *
     * @internal PatternLayout keeps such an atom on the line of the one before
     */
    public function opensConstruct(string $compiled, string $next): bool
    {
        $length = \strlen($compiled);
        if ($this->inCharClass) {
            return \in_array($next, [':', '.', '='], true) && str_ends_with($compiled, '[') && self::isUnescapedAt($compiled, $length - 1);
        }

        if ('{' === $next) {
            return str_ends_with($compiled, '\\N') && self::isUnescapedAt($compiled, $length - 2);
        }

        if (!str_contains("0123456789, \t}", $next)) {
            return false;
        }

        // A repeat count is short: past MAX_ESCAPE_LENGTH characters the
        // brace opens none.
        $tail = substr($compiled, -self::MAX_ESCAPE_LENGTH);
        $count = \strlen($tail) - \strlen(rtrim($tail, "0123456789, \t"));
        $at = $length - $count - 1;

        return $count < \strlen($tail) && '{' === $compiled[$at] && self::isUnescapedAt($compiled, $at);
    }

    /**
     * A literal as the pattern must spell it where the printer stands: the
     * delimiter, the meta-characters of the context, what x would skip, and
     * the control and, outside UTF, non-ASCII bytes, escaped.
     *
     * @internal PatternLayout spells the characters of a literal it breaks over lines
     */
    public function escapeString(string $value): string
    {
        $meta = $this->inCharClass ? self::CHAR_CLASS_META : self::META_CHARACTERS;
        $escapeExtended = str_contains($this->flags, 'x') && !$this->inCharClass;
        $unicodeMode = $this->utfVerb || str_contains($this->flags, 'u');
        // In UTF mode "x" also skips these written raw, and a no-break space
        // where the formatter adds x.
        $skipped = $escapeExtended && $unicodeMode ? self::UTF_PATTERN_WHITE_SPACE : [];
        if ([] !== $skipped && $this->addsExtended) {
            $skipped["\u{A0}"] = '\\x{A0}';
        }
        $needsEscape = [] !== $skipped && \strlen($value) !== \strlen(strtr($value, $skipped));

        // Most literals need no escape: a first pass finds out.
        $len = \strlen($value);
        for ($i = 0; $i < $len; $i++) {
            $char = $value[$i];
            $ord = \ord($char);
            if (
                $char === $this->delimiter
                || $char === $this->closingDelimiter
                || isset($meta[$char])
                || ($escapeExtended && (' ' === $char || '#' === $char))
                || (' ' === $char && ($this->inClassOfExtendedClass || ($this->inCharClass && str_contains($this->flags, self::EXTENDED_MORE))))
                || $ord < 32
                || 127 === $ord
                || (!$unicodeMode && $ord >= 128)
            ) {
                $needsEscape = true;

                break;
            }
        }

        if (!$needsEscape) {
            return $value;
        }

        $result = '';
        for ($i = 0; $i < $len; $i++) {
            $char = $value[$i];
            if (
                $char === $this->delimiter
                || $char === $this->closingDelimiter
                || isset($meta[$char])
                || ($escapeExtended && (' ' === $char || '#' === $char))
                || (' ' === $char && ($this->inClassOfExtendedClass || ($this->inCharClass && str_contains($this->flags, self::EXTENDED_MORE))))
            ) {
                $result .= ($this->bracketMarkFor($char) ?? '\\').$char;
            } elseif (\ord($char) < 32 || 127 === \ord($char) || (!$unicodeMode && \ord($char) >= 128)) {
                // Escape control characters and extended ASCII
                $result .= match (\ord($char)) {
                    8 => $this->inCharClass ? '\\b' : '\\x08', // Backspace: \b only valid inside char class
                    9 => '\\t',
                    10 => '\\n',
                    13 => '\\r',
                    12 => '\\f',
                    27 => '\\e',
                    default => '\\x'.Ascii::upper(str_pad(dechex(\ord($char)), 2, '0', \STR_PAD_LEFT)),
                };
            } else {
                $result .= $char;
            }
        }

        return [] === $skipped ? $result : strtr($result, $skipped);
    }

    /**
     * Whether the pattern opens with a (*UTF) or (*UTF8) setting, possibly
     * after other start-of-pattern settings.
     */
    private static function startsWithUtfVerb(NodeInterface $pattern): bool
    {
        $nodes = $pattern instanceof SequenceNode ? $pattern->children : [$pattern];
        foreach ($nodes as $node) {
            // "(*LIMIT_MATCH=n)" is a start-of-pattern setting of its own node.
            if ($node instanceof LimitMatchNode) {
                continue;
            }

            if (!$node instanceof PcreVerbNode) {
                return false;
            }
            if ('UTF' === $node->verb || 'UTF8' === $node->verb) {
                return true;
            }
        }

        return false;
    }

    /**
     * An operand of an extended class operation, in parentheses where it is
     * an operation that binds looser than the one it stands in, or as
     * tightly on its right: the operators of one precedence bind left to
     * right.
     */
    private function classSetOperand(NodeInterface $operand, int $binding, bool $isRight): string
    {
        $text = $operand->accept($this);
        if (!$operand instanceof ClassSetOperationNode) {
            return $text;
        }

        $inner = self::classSetBinding($operand->operator);

        return $inner < $binding || ($isRight && $inner === $binding) ? '('.$text.')' : $text;
    }

    /**
     * How tightly an extended class operator binds: "!" most, then "&",
     * then "+", "|", "-" and "^" alike.
     */
    private static function classSetBinding(ClassSetOperator $operator): int
    {
        return match ($operator) {
            ClassSetOperator::Complement => 3,
            ClassSetOperator::Intersection => 2,
            default => 1,
        };
    }

    /**
     * Whether a piece of source spells this very verb.
     */
    private function namesTheSameVerb(string $written, string $verb): bool
    {
        $matches = [];
        if (1 !== LibraryPcre::match('/^\(\*(.*)\)$/s', $written, $matches)) {
            return false;
        }

        $spelled = $matches[1];

        return $spelled === $verb
            || ('' !== $spelled && ':' === $spelled[0] && 'MARK'.$spelled === $verb)
            || ('' !== $spelled && '=' === $spelled[0] && 'MARK'.$spelled === $verb);
    }

    /**
     * "(*scs:(1,<name>)" or "(*scan_substring:(1,<name>)", as the pattern
     * spelled it.
     */
    private function scanSubstringOpening(GroupNode $node): string
    {
        return '(*'.($node->name ?? 'scan_substring').':('.implode(',', $node->scannedGroups).')';
    }

    /**
     * The text that opens a group, as the tree spells it.
     */
    private function treeOpening(GroupNode $node, string $flags): string
    {
        $name = $node->name ?? '';

        return match ($node->type) {
            GroupType::Capturing => '(',
            GroupType::NonCapturing => '(?:',
            GroupType::Named => $node->usePythonSyntax
                ? '(?P<'.$name.'>'
                : '(?<'.$name.'>',
            GroupType::LookaheadPositive => self::NON_ATOMIC_FLAG === $flags ? '(?*' : '(?=',
            GroupType::LookaheadNegative => '(?!',
            GroupType::LookbehindPositive => self::NON_ATOMIC_FLAG === $flags ? '(?<*' : '(?<=',
            GroupType::LookbehindNegative => '(?<!',
            GroupType::Atomic => '(?>',
            GroupType::BranchReset => '(?|',
            GroupType::InlineFlags => '(?'.$flags.':',
            GroupType::ScanSubstring => $this->scanSubstringOpening($node),
        };
    }

    /**
     * The text that opened a group, as the pattern spelled it.
     *
     * PCRE names a group four ways — "(?<n>", "(?'n'", "(?P<n>", "(?P\"n\"" —
     * and they mean the same thing, so the tree keeps only the name. The
     * source still knows which one was written.
     */
    private function openingAsWritten(GroupNode $node, string $opening): string
    {
        // Under "n", "(...)" does not capture: it is read as "(?:...)" and
        // written back with the "(" the pattern used.
        if (GroupType::NonCapturing === $node->type && null !== $this->source
            && str_contains($this->flags, 'n')
            && '(' === ($this->source[$node->getStartPosition()] ?? '')
            && !\in_array($this->source[$node->getStartPosition() + 1] ?? '', ['?', '*'], true)) {
            return '(';
        }

        // Under "<>", the symbol of a lookbehind or an atomic group holds a
        // bracket PHP counts, which the name it was written with does not:
        // the name stays.
        if (null !== $this->source && '<' === $this->delimiter
            && 1 === LibraryPcre::match('/\G\(\*([a-z_]++):/', $this->source, $matches, 0, $node->getStartPosition())
            && $opening === (self::NAMED_GROUP_SYMBOLS[$matches[1]] ?? null)) {
            return $matches[0];
        }

        if (null === $this->source || GroupType::Named !== $node->type || null === $node->name) {
            return $opening;
        }

        $start = $node->getStartPosition();
        $length = $node->child->getStartPosition() - $start;
        if ($length <= 0 || $start < 0 || $start + $length > \strlen($this->source)) {
            return $opening;
        }

        $written = substr($this->source, $start, $length);
        $quoted = preg_quote($node->name, '/');

        // A rewritten pattern lays out anew the whitespace x skipped after
        // the name, so only the opening itself is taken back.
        if (self::MODE_OFF !== $this->mode) {
            return 1 === LibraryPcre::match('/^\(\?P?(?:<'.$quoted.'>|\''.$quoted.'\'|"'.$quoted.'")/', $written, $matches) ? $matches[0] : $opening;
        }

        // Only a spelling of this very name is taken back; anything else means
        // the offsets no longer line up with the source.
        return 1 === LibraryPcre::match('/^\(\?P?(?:<'.$quoted.'>|\''.$quoted.'\'|"'.$quoted.'")$/', $written)
            ? $written
            : $opening;
    }

    /**
     * Give back the spelling the pattern was written with.
     *
     * Escaping punctuation is optional in many places — "\{" and "{", "\-"
     * and "-" — and normalizing it would rewrite a pattern the author did not
     * ask to have rewritten. The source is only trusted when it says the same
     * thing as the compiled form, escaping aside, which keeps a stale position
     * from ever changing what the pattern matches.
     */
    private function writtenSpelling(NodeInterface $node, string $compiled, ?string $value = null): string
    {
        $written = $this->writtenText($node);
        if (null === $written) {
            return $compiled;
        }

        if ($this->withoutOptionalEscapes($written) === $this->withoutOptionalEscapes($compiled)) {
            return $written;
        }

        // The compiler may also spell a character as an escape — "\x07" for
        // "\a", "\xC2\xAB" for "«" — while the pattern spelled it plainly.
        return null !== $value && $this->spells($written, $value) ? $written : $compiled;
    }

    /**
     * A rewritten literal of a "\Q...\E" run that holds a bracket of the
     * delimiter, quoted as the run writes it: PHP counts that bracket, and
     * would not count it spelled "\{", which a verb name, a lookbehind, a
     * class or a comment may need to balance one of theirs (see
     * DelimiterBrackets::asWritten). Null for any other literal.
     */
    private function quotedRunAsWritten(LiteralNode $node): ?string
    {
        $pieces = $this->quotedRunPieces($node);

        return null === $pieces ? null : implode('', array_column($pieces, 0));
    }

    /**
     * Whether $text, read at $start in the source, holds a bracket of the
     * delimiter kept counted.
     */
    private function holdsKeptQuotedBracket(int $start, string $text): bool
    {
        $brackets = $this->delimiter.$this->closingDelimiter;
        $length = \strlen($text);
        for ($i = strcspn($text, $brackets); $i < $length; $i += 1 + strcspn($text, $brackets, $i + 1)) {
            if (isset($this->keptQuotedBrackets[$start + $i])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a piece of source text stands for exactly this literal.
     */
    private function spells(string $written, string $value): bool
    {
        if ($this->withoutOptionalEscapes($written) === $value) {
            return true;
        }

        $codePoint = $this->codePointOf($written);

        return null !== $codePoint && $codePoint === $this->codePointOf($value);
    }

    /**
     * The source text a node was parsed from, when it is available and its
     * offsets still fit the source.
     *
     * @param bool $readByX false for text x does not read, a verb name or a
     *                      callout string, which a rewrite keeps as written
     */
    private function writtenText(NodeInterface $node, bool $readByX = true): ?string
    {
        if (null === $this->source) {
            return null;
        }

        $start = $node->getStartPosition();
        $length = $node->getEndPosition() - $start;
        if ($length <= 0 || $start < 0 || $start + $length > \strlen($this->source)) {
            return null;
        }

        // Text quoted by \Q...\E means something else once the quoting is
        // gone: "\Q[a-z]\E" compiles to an escaped literal, not to a class.
        // A verb name or a callout string is no quoted text, and the "\Q" it
        // may hold, "(?C{\Q})", opens no quote.
        $opaque = $node instanceof PcreVerbNode || $node instanceof CalloutNode;
        if (!$opaque && $this->overlapsQuotedSpan($start, $start + $length)) {
            return null;
        }

        $written = substr($this->source, $start, $length);

        return self::MODE_OFF === $this->mode || !$readByX || !$this->isSkippedByX($written) ? $written : null;
    }

    /**
     * Whether written text holds what x would skip or read as a comment
     * where the formatter writes it: such text is spelled from the tree
     * instead, with escapes. Every line break the formatter writes is
     * layout, and a compacted pattern is one line, so a raw line feed or
     * carriage return is spelled as an escape wherever it stands; under x a
     * raw tab, vertical tab or form feed in a class or a quoted run too.
     */
    private function isSkippedByX(string $written): bool
    {
        if (false !== strpbrk($written, "\n\r")) {
            return true;
        }

        if (!str_contains($this->flags, 'x')) {
            return false;
        }

        if (self::MODE_FORMAT === $this->mode && false !== strpbrk($written, "\t\v\f")) {
            return true;
        }

        if ($this->inCharClass || $this->inExtendedClass) {
            return false;
        }

        if (1 === LibraryPcre::match('/[\s#]/', $written)) {
            return true;
        }

        // Under UTF, x also skips NEL, LRM, RLM, LS and PS; in byte mode it
        // skips byte 0x85 too, even inside a UTF-8 sequence such as "Å". With
        // the tables PHP builds from a locale's LC_CTYPE, it may skip a
        // no-break space as well: an x the formatter adds escapes it.
        if ($this->utfVerb || str_contains($this->flags, 'u')) {
            return 1 === LibraryPcre::match($this->addsExtended ? '/[\x{85}\x{A0}\x{200E}\x{200F}\x{2028}\x{2029}]/u' : '/[\x{85}\x{200E}\x{200F}\x{2028}\x{2029}]/u', $written);
        }

        return str_contains($written, "\x85") || ($this->addsExtended && str_contains($written, "\xA0"));
    }

    /**
     * A character as an operand of "(?[...])", where only an escape stands on
     * its own: "\!" for punctuation, "\x{e9}" or "\x41" for the rest.
     */
    private function extendedClassOperand(string $value): string
    {
        $unicodeMode = $this->utfVerb || str_contains($this->flags, 'u');
        $characters = $unicodeMode ? mb_str_split($value, 1, 'UTF-8') : Bytes::split($value);

        return implode('', array_map(static function (string $character) use ($unicodeMode): string {
            if (1 === LibraryPcre::match('/^[!-\/:-@\[-`{-~ ]$/', $character)) {
                return '\\'.$character;
            }

            return $unicodeMode
                ? \sprintf('\\x{%x}', mb_ord($character, 'UTF-8'))
                : \sprintf('\\x%02x', \ord($character));
        }, $characters));
    }

    /**
     * Offsets of the \Q...\E regions of the source.
     *
     * @return list<array{int, int}>
     */
    private function quotedSpans(): array
    {
        return $this->quotedSpans ??= DelimiterBrackets::quotedSpans((string) $this->source, $this->sourcePattern);
    }

    /**
     * Whether a \Q...\E region of the source overlaps the offsets from
     * $start to $end. The regions are in order and apart: the first one
     * ending past $start is found by bisection, so that reading every node
     * of a pattern takes time linear in its length, give or take a log.
     */
    private function overlapsQuotedSpan(int $start, int $end): bool
    {
        $spans = $this->quotedSpans();
        $low = 0;
        $high = \count($spans);
        while ($low < $high) {
            $middle = ($low + $high) >> 1;
            if ($spans[$middle][1] <= $start) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return $low < \count($spans) && $spans[$low][0] < $end;
    }

    /**
     * Drop the backslashes that only escape punctuation, leaving escapes such
     * as "\d" or "\n" — which mean something else entirely — alone.
     */
    private function withoutOptionalEscapes(string $text): string
    {
        return LibraryPcre::replace('/\\\\([^a-zA-Z0-9])/', '$1', $text) ?? $text;
    }

    /**
     * The group a backreference points at, whatever syntax spells it.
     */
    private function referenceName(string $reference): ?string
    {
        $matches = [];
        // "\k{ name }" and "\g{ 1 }" may pad the braces; under /u a name may
        // hold non-ASCII letters, read here byte by byte.
        $syntax = '/^(?:\\(\\?P=|\\\\k[<{\']?|\\\\g[<{\']?|\\\\)[ \t]*([A-Za-z_\x80-\xFF][A-Za-z0-9_\x80-\xFF]*|[0-9]+)/';

        return 1 === LibraryPcre::match($syntax, $reference, $matches) ? $matches[1] : null;
    }

    /**
     * Read back the code point a single-character spelling stands for, or null
     * when the text is not one.
     */
    private function codePointOf(string $text): ?int
    {
        $named = ['\\a' => 7, '\\e' => 27, '\\f' => 12, '\\n' => 10, '\\r' => 13, '\\t' => 9];
        if (isset($named[$text])) {
            return $named[$text];
        }

        $matches = [];
        if (1 === LibraryPcre::match('/^\\\\[xu]\\{?([0-9a-fA-F]{1,8})\\}?$/', $text, $matches)) {
            return (int) hexdec($matches[1]);
        }

        if (1 === LibraryPcre::match('/^\\\\(?:o\\{([0-7]+)\\}|([0-7]{1,3}))$/', $text, $matches)) {
            return (int) octdec($matches[1] ?: ($matches[2] ?? ''));
        }

        if (1 === LibraryPcre::match('/^.$/us', $text)) {
            $codePoint = mb_ord($text, 'UTF-8');

            return false === $codePoint ? null : $codePoint;
        }

        return 1 === \strlen($text) ? \ord($text) : null;
    }

    private function isAssertionCondition(NodeInterface $condition): bool
    {
        // "(?(?C1)(?=a)yes|no)": a callout that runs before the assertion is
        // written in front of it, inside the same parentheses.
        if ($condition instanceof SequenceNode && 2 === \count($condition->children)
            && $condition->children[0] instanceof CalloutNode) {
            return $this->isAssertionCondition($condition->children[1]);
        }

        return $condition instanceof GroupNode && \in_array($condition->type, [
            GroupType::LookaheadPositive,
            GroupType::LookaheadNegative,
            GroupType::LookbehindPositive,
            GroupType::LookbehindNegative,
        ], true);
    }

    /**
     * Split the ignorable whitespace that surrounds the "|" between two
     * alternatives, so it can be put back on either side of the separator.
     *
     * @return array{0: string, 1: string}
     */
    private function ignorableTextAroundSeparator(NodeInterface $left, NodeInterface $right): array
    {
        // A rewritten pattern lays its whitespace out anew.
        if (null === $this->source || self::MODE_OFF !== $this->mode) {
            return ['', ''];
        }

        $start = $left->getEndPosition();
        $length = $right->getStartPosition() - $start;
        if ($length <= 0 || $start < 0 || $start + $length > \strlen($this->source)) {
            return ['', ''];
        }

        $text = substr($this->source, $start, $length);

        return 1 === LibraryPcre::match('/^(\s*)\|(\s*)$/', $text, $matches) ? [$matches[1], $matches[2]] : ['', ''];
    }

    /**
     * An item that matches the empty string and separates two others:
     * "(?:)", or "\E", which PCRE ignores outside "\Q", where the delimiter
     * is ":" or "?" and would end the pattern inside "(?:)".
     */
    private function emptyGroup(): string
    {
        return $this->delimiter === $this->closingDelimiter && str_contains('(?:)', $this->delimiter) ? '\\E' : '(?:)';
    }

    /**
     * Whether the character at $offset may stand unescaped: an even run of
     * backslashes stands before it, or an odd run that "\c" opens, as "\c"
     * takes the next character, a backslash included: "\c\{" is the control
     * character "\c\", then "{". Read from the end, a "\c" escaped itself,
     * "\\c\{", cannot be told apart from it; the answer is then yes, which
     * costs a separator at most.
     */
    private static function isUnescapedAt(string $text, int $offset): bool
    {
        $run = 0;
        while ($offset > $run && '\\' === $text[$offset - $run - 1]) {
            $run++;
        }

        return 0 === $run % 2 || ($offset - $run >= 2 && 'c' === $text[$offset - $run - 1] && '\\' === $text[$offset - $run - 2]);
    }

    /**
     * Whether a rewritten pattern dropped something between two items, a
     * comment, a group holding only comments or the whitespace x skipped:
     * "a{(?#c),2}" without its comment would read "{,2}" as a repeat, which
     * PCRE2 does from 10.43 on.
     */
    private function separatesDroppedText(NodeInterface $left, NodeInterface $right): bool
    {
        return self::MODE_OFF !== $this->mode && !$this->inCharClass
            && ($right->getStartPosition() > $left->getEndPosition() || $left instanceof CommentNode || isset($this->droppedGroups[spl_object_id($left)]));
    }

    /**
     * Whether the text ends with an escape the next character would make
     * longer: a reference or octal escape ("\1", "\g-1", "\0") before a
     * digit, or a "\x" with fewer than two digits before a hex digit.
     */
    private static function takesMoreDigits(string $compiled, string $next): bool
    {
        if (!Ascii::isHexDigit($next)) {
            return false;
        }

        // The escape has no backslash in it, and none that PCRE accepts
        // runs past MAX_ESCAPE_LENGTH characters: the last backslash of the
        // tail starts it, if anything does.
        $tail = substr($compiled, -self::MAX_ESCAPE_LENGTH);
        $backslash = strrpos($tail, '\\');
        if (false === $backslash
            || 1 !== LibraryPcre::match('/^(?:[0-9]+|g[+-]?[0-9]+|x[0-9A-Fa-f]?)$/', substr($tail, $backslash + 1), $matches)
            || !self::isUnescapedAt($compiled, \strlen($compiled) - \strlen($tail) + $backslash)) {
            return false;
        }

        return str_starts_with($matches[0], 'x') || Ascii::isDigit($next);
    }

    /**
     * The "\E" and empty "\Q\E" written between two items, with the
     * whitespace "x" ignores around them; null when there are none. Only a
     * digit escape or an unescaped brace or bracket asks for it, and quoted
     * text comes back fully escaped, so the "\E" that closes it never
     * stands between two such items.
     */
    private function writtenSeparator(int $start, int $end): ?string
    {
        if (null === $this->source || $end <= $start) {
            return null;
        }

        $text = substr($this->source, $start, $end - $start);
        if (1 !== LibraryPcre::match('/\A(?:[ \t\n\r\v\f]|\\\\E|\\\\Q\\\\E)++\z/', $text)) {
            return null;
        }

        // A rewritten pattern keeps the "\E" alone: the whitespace around it
        // is a literal once x is dropped, and layout where x is kept.
        if (self::MODE_OFF !== $this->mode) {
            $text = strtr($text, [' ' => '', "\t" => '', "\n" => '', "\r" => '', "\v" => '', "\f" => '']);
        }

        return str_contains($text, '\\') ? $text : null;
    }

    /**
     * The spaces and tabs written between two items inside braces such as
     * "a{ 1,2 }", which a tree read for PCRE2 before 10.43 holds as text
     * under x: kept as written, they are text there and a repeat from 10.43
     * on, as in the source. Null anywhere else, and where x is off, as the
     * spaces are items of their own there. The braces are the last literal
     * "{" joined that opens such a run, so no source is read twice.
     */
    private function spaceInBraces(NodeInterface $left, NodeInterface $right): ?string
    {
        $start = $left->getEndPosition();
        $end = $right->getStartPosition();
        if (null === $this->source || null === $this->spacedBraces || !str_contains($this->flags, 'x')
            || $this->inCharClass || $this->inExtendedClass || $start <= $this->spacedBraces[0] || $end >= $this->spacedBraces[1]) {
            return null;
        }

        $gap = substr($this->source, $start, $end - $start);

        return '' !== $gap && \strlen($gap) === strspn($gap, " \t") ? $gap : null;
    }

    /**
     * Whitespace that /x makes ignorable is not represented in the AST, so it
     * is read back from the source to keep a recompiled pattern identical to
     * the one that was parsed. Anything else than whitespace is ignored: the
     * nodes themselves are the only source of truth for what a pattern matches.
     */
    private function ignorableTextBetween(NodeInterface $left, NodeInterface $right): string
    {
        return $this->ignorableText($left->getEndPosition(), $right->getStartPosition());
    }

    /**
     * @param int|null $end end offset, or null for the end of the source
     */
    private function ignorableText(int $start, ?int $end): string
    {
        if (null === $this->source || self::MODE_OFF !== $this->mode) {
            return '';
        }

        $end ??= \strlen($this->source);
        $length = $end - $start;
        if ($length <= 0 || $start < 0 || $end > \strlen($this->source)) {
            return '';
        }

        $text = substr($this->source, $start, $length);

        return Ascii::isSpace($text) ? $text : '';
    }

    /**
     * Whether a modifier group is a setting, "(?i)", rather than a scope that
     * holds nothing, "(?i:)": both have no body, and only the setting ends
     * right after its letters.
     */
    private function isUnscopedSetting(GroupNode $node, string $flags, string $child): bool
    {
        return '' === $child && PatternLayout::isSetting($node, $flags);
    }

    /**
     * Compile the body of a group with the modifiers that are in force inside
     * it. "(?x:...)" applies only to its own body, while a bare "(?x)" keeps
     * going until the end of the enclosing group, like PCRE scopes them.
     */
    private function compileGroupChild(GroupNode $node, string $flags): string
    {
        $previousFlags = $this->flags;

        if (GroupType::InlineFlags === $node->type) {
            $this->flags = $this->withInlineFlags($previousFlags, $flags);
        }

        $child = $node->child->accept($this);

        // An empty scope, "(?-x:)", switches nothing on or off for what
        // follows it.
        if (!$this->isUnscopedSetting($node, $flags, $child)) {
            $this->flags = $previousFlags;
        }

        return $child;
    }

    /**
     * Apply an inline modifier string such as "x", "-x", "im-sx" or "^i" to
     * the modifiers currently in force.
     */
    private function withInlineFlags(string $current, string $inline): string
    {
        $flags = InlineFlags::read(InlineFlags::withoutAsciiOptions($inline), InlineFlags::LETTERS.'r');
        if (null === $flags) {
            return $current;
        }

        $applied = $flags->applyTo(str_replace(self::EXTENDED_MORE, '', $current));

        return $flags->extendedMoreInForce(str_contains($current, self::EXTENDED_MORE)) ? $applied.self::EXTENDED_MORE : $applied;
    }

    private function normalizeQuantifier(string $quantifier): string
    {
        // The formatter keeps the quantifier as its author wrote it.
        if (self::MODE_FORMAT === $this->mode || self::MODE_COMPACT === $this->mode) {
            return $quantifier;
        }

        return LibraryPcre::replace('/\\s+/', '', $quantifier) ?? $quantifier;
    }

    /**
     * @param non-empty-list<NodeInterface> $members
     */
    private function compileCharClassMembers(array $members): string
    {
        $result = $this->compileCharClassNode($members[0], $members[1] ?? null);
        for ($i = 1, $count = \count($members); $i < $count; $i++) {
            $next = $this->compileCharClassNode($members[$i], $members[$i + 1] ?? null);
            // Under "(?xx)" a skipped space may be what kept a hyphen from
            // the class escape before it, "[\d -z]": written without it,
            // "[\d-z]" is a range every release refuses. An escaped hyphen
            // is a member on every release.
            if ('-' === $next && $i < $count - 1 && $members[$i] instanceof LiteralNode && str_contains($this->flags, self::EXTENDED_MORE)) {
                $next = '\\-';
            }
            $result .= $this->separator($result, $members[$i - 1], $members[$i], $next).$next;
        }

        return $result;
    }

    private function compileCharClassNode(NodeInterface $node, ?NodeInterface $next): string
    {
        // Where "[" is the delimiter, pretty printing marks it as escapeString
        // does: the balance decides. Before ":", "." or "=" it may only come
        // back counted quoted.
        if ($node instanceof LiteralNode && '[' === $node->value) {
            $written = $this->writtenText($node);
            if (null !== $written) {
                return $this->markedWritten($written);
            }

            $mark = $this->bracketMarkFor('[');
            if ($this->shouldEscapeCharClassOpen($next)) {
                return null === $mark ? '\\[' : $mark.'Q[';
            }

            return ($mark ?? '').'[';
        }

        if ($node instanceof RangeNode) {
            $start = $node->start;
            // A "[" before "-" opens no POSIX class: it is written as the
            // pattern wrote it, or unescaped.
            $startCompiled = $start instanceof LiteralNode && '[' === $start->value
                ? $this->markedWritten($this->writtenText($start) ?? ($this->bracketMarkFor('[') ?? '').'[')
                : $start->accept($this);

            return $startCompiled.'-'.$node->end->accept($this);
        }

        return $node->accept($this);
    }

    /**
     * Whether a "[" in a class goes before ":", "." or "=", where unescaped
     * it would open a POSIX class: the next member, or the start of the
     * range it opens, "[\[:-;:]" for one.
     */
    private function shouldEscapeCharClassOpen(?NodeInterface $next): bool
    {
        if ($next instanceof RangeNode) {
            $next = $next->start;
        }

        if (!$next instanceof LiteralNode) {
            return false;
        }

        return \in_array($next->value, [':', '.', '='], true);
    }

    /**
     * Format, compact or pretty-print a whole pattern.
     *
     * A pattern that fits the width and nests at most two groups stays on
     * one line: as written when the formatter has its source, otherwise
     * written from the tree. Anything else is laid out under x, unless a
     * line break would change what it matches; the formatter then gives it
     * back as written with the reason, pretty printing writes it on one
     * line.
     */
    private function formatRegex(RegexNode $node): string
    {
        $this->refusal = null;
        $this->addsExtended = false;
        $this->keptComments = [];
        $this->keptQuotedBrackets = [];
        $this->droppedGroups = [];
        $this->spacedBraces = null;
        $this->delimiter = $node->delimiter;
        $this->closingDelimiter = PatternParser::closingDelimiter($node->delimiter);
        $this->bracketMark = self::MODE_PRETTY === $this->mode && $this->delimiter !== $this->closingDelimiter
            ? self::markAbsentFrom($node->source ?? $node->accept(self::asWritten()), "\x03\x04", "\x04", self::BRACKET_MARKS)
            : null;
        // Pretty printing normalizes the spelling: it writes from the tree.
        $this->source = self::MODE_PRETTY === $this->mode ? null : $node->source;
        $this->quotedSpans = null;
        $this->sourcePattern = $node->pattern;
        $options = self::startOptions($node->pattern);
        $this->utfVerb = \in_array('UTF', $options, true) || \in_array('UTF8', $options, true);
        $this->countsCodePoints = $this->utfVerb || str_contains($node->flags, 'u');
        $written = null === $this->source ? null : $node->delimiter.$this->source.$this->closingDelimiter.$node->flags;

        // A line break in a verb name or a callout string cannot be spelled
        // otherwise. From PCRE2 10.43 "a{ 1,2 }" is a repeat with x or
        // without; before, under x the text "a{1,2}", without x the text
        // "a{ 1,2 }": dropping x changes it on the older engines.
        $refusal = match (true) {
            self::MODE_PRETTY === $this->mode => null,
            self::holdsLineBreakInName($node->pattern) => FormatRefusal::LineBreakInName,
            self::MODE_COMPACT === $this->mode && $this->extendedModeMovesSpacedBraces($node->pattern, $node->flags, str_replace('x', '', $node->flags)) => FormatRefusal::SpacedQuantifier,
            default => null,
        };
        if (null !== $refusal) {
            $this->refusal = $refusal;

            return $written ?? $node->accept(self::asWritten());
        }

        if (self::MODE_COMPACT === $this->mode) {
            [$this->keptComments, $this->keptQuotedBrackets] = DelimiterBrackets::asWritten($node, $this->source, $this->quotedSpans(), true);

            // A "(?#...)" ends at the first ")": a "#" comment holding the
            // one the balance needs cannot become one.
            foreach ($this->keptComments as $kept) {
                if (str_contains($kept, ')')) {
                    $this->keptComments = [];
                    $this->keptQuotedBrackets = [];
                    $this->refusal = FormatRefusal::ParenthesisInComment;

                    return $written ?? $node->accept(self::asWritten());
                }
            }

            return $this->renderRoot($node, str_replace('x', '', $node->flags), false);
        }

        [, $this->keptQuotedBrackets] = DelimiterBrackets::asWritten($node, $this->source, $this->quotedSpans(), false);

        $shallow = PatternLayout::isShallow($node->pattern);
        if (null !== $written && false === strpbrk($written, "\n\r") && $shallow && $this->columns($written) <= $this->width) {
            return $written;
        }

        // A line feed or a carriage return a "(?#...)" holds breaks the
        // line, as a "#" comment does: every line break of the output is
        // layout.
        $inline = $this->renderRoot($node, $node->flags, false);
        $hasLineComment = null !== $this->layout && $this->layout->sawLineComment();
        if (!$hasLineComment && $shallow && false === strpbrk($inline, "\n\r") && $this->columns($inline) <= $this->width) {
            return $inline;
        }

        // Nothing past the start options: no line would hold anything.
        $bare = substr($inline, \strlen($node->delimiter), -\strlen($this->closingDelimiter.$node->flags));
        if ($bare === StartOptions::of($bare)) {
            return $written ?? $inline;
        }

        // Pretty printing never adds x: without it, a line break is a literal.
        if (self::MODE_PRETTY === $this->mode && !str_contains($node->flags, 'x')) {
            return $inline;
        }

        $flags = str_contains($node->flags, 'x') ? $node->flags : $node->flags.'x';
        $refusal = $this->refusalFor($node, $flags, $hasLineComment, $options);
        if (null !== $refusal) {
            if (self::MODE_PRETTY === $this->mode) {
                return $inline;
            }

            $this->refusal = $refusal;

            return $written ?? $inline;
        }

        $trail = self::markAbsentFrom(($this->source ?? '').$inline, PatternLayout::TRAIL, "\x02");
        $this->addsExtended = !str_contains($node->flags, 'x');

        // Under the x it adds, a space may part two tokens a "(?:)" parted
        // without it: the line a second pass would measure may fit.
        if ($this->addsExtended && $shallow) {
            $inline = $this->renderRoot($node, $flags, false, $trail);
            if (false === strpbrk($inline, "\n\r") && $this->columns($inline) <= $this->width) {
                return $inline;
            }
        }

        return PatternLayout::finishLines($this->renderRoot($node, $flags, true, $trail), $trail, $this->countsCodePoints);
    }

    /**
     * Why laying the pattern out under x would change what it matches, if
     * it would.
     *
     * A "#" comment runs on past LF under (*CR), (*CRLF) and (*NUL). Before
     * PCRE2 10.43 "a{ 1,2 }" is the text "a{ 1,2 }", which an added x turns
     * into the text "a{1,2}"; from 10.43 on it is a quantifier either way.
     * After a top-level "(?-x)" or "(?^)" a line break is a literal.
     *
     * @param list<string> $options the start-of-pattern options
     */
    private function refusalFor(RegexNode $node, string $flags, bool $hasLineComment, array $options): ?FormatRefusal
    {
        $newline = 'LF';
        foreach ($options as $option) {
            if (\in_array($option, ['CR', 'LF', 'CRLF', 'ANYCRLF', 'ANY', 'NUL'], true)) {
                $newline = $option;
            }
        }

        return match (true) {
            $this->extendedModeMovesSpacedBraces($node->pattern, $node->flags, $flags) => FormatRefusal::SpacedQuantifier,
            $hasLineComment && \in_array($newline, ['CR', 'CRLF', 'NUL'], true) => FormatRefusal::NewlineConvention,
            self::switchesExtendedOffAtTop($node->pattern, $flags) => FormatRefusal::ExtendedModeSwitchedOff,
            default => null,
        };
    }

    /**
     * The verbs a pattern opens with, "(*UTF)" or "(*CR)", as their names;
     * under a top-level alternation they open its first branch.
     *
     * @return list<string>
     */
    private static function startOptions(NodeInterface $pattern): array
    {
        while ($pattern instanceof AlternationNode && [] !== $pattern->alternatives) {
            $pattern = $pattern->alternatives[0];
        }

        $options = [];
        foreach ($pattern instanceof SequenceNode ? $pattern->children : [$pattern] as $node) {
            if ($node instanceof LimitMatchNode) {
                continue;
            }

            if (!$node instanceof PcreVerbNode) {
                break;
            }

            $options[] = $node->verb;
        }

        return $options;
    }

    private static function switchesExtendedOffAtTop(NodeInterface $node, string $flags): bool
    {
        foreach ($node instanceof AlternationNode ? $node->alternatives : [$node] as $branch) {
            foreach ($branch instanceof SequenceNode ? $branch->children : [$branch] as $item) {
                if ($item instanceof GroupNode && PatternLayout::isSetting($item) && PatternLayout::isEmpty($item->child)
                    && !str_contains(self::applyInlineFlags($flags, $item->flags ?? ''), 'x')) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function applyInlineFlags(string $current, string $inline): string
    {
        $flags = InlineFlags::read(InlineFlags::withoutAsciiOptions($inline), InlineFlags::LETTERS.'r');

        return $flags?->applyTo($current) ?? $current;
    }

    /**
     * Whether braces written with a space or a tab, "a{ 1,2 }", stand where
     * x is on under one of two sets of global flags and off under the other.
     * Such braces are a repeat from PCRE2 10.43 on and text before, where x
     * drops their spaces: adding or dropping x there changes the pattern on
     * one engine or the other, whatever the target its tree was read for.
     * The x of the flags does not reach into "(?-x:...)" or "(?^:...)".
     */
    private function extendedModeMovesSpacedBraces(NodeInterface $pattern, string $flags, string $other): bool
    {
        return str_contains($flags, 'x') !== str_contains($other, 'x') && $this->spacedBracesWhereXDiffers($pattern, $flags, $other);
    }

    /**
     * A setting, "(?-x)", holds for the rest of the group around it, its
     * later branches included: a sequence and an alternation pass the flags
     * on, any other node keeps the changes made inside it.
     */
    private function spacedBracesWhereXDiffers(NodeInterface $node, string &$flags, string &$other): bool
    {
        if ($node instanceof CharClassNode || $node instanceof ExtendedCharClassNode) {
            return false;
        }

        if (str_contains($flags, 'x') !== str_contains($other, 'x') && $this->isSpacedBraces($node)) {
            return true;
        }

        $inner = $flags;
        $innerOther = $other;
        if ($node instanceof GroupNode && GroupType::InlineFlags === $node->type) {
            $inner = self::applyInlineFlags($flags, $node->flags ?? '');
            $innerOther = self::applyInlineFlags($other, $node->flags ?? '');
            if (PatternLayout::isSetting($node) && PatternLayout::isEmpty($node->child)) {
                [$flags, $other] = [$inner, $innerOther];

                return false;
            }
        }

        foreach ($node->getChildren() as $child) {
            if ($this->spacedBracesWhereXDiffers($child, $inner, $innerOther)) {
                return true;
            }
        }

        if ($node instanceof SequenceNode || $node instanceof AlternationNode) {
            [$flags, $other] = [$inner, $innerOther];
        }

        return false;
    }

    /**
     * Whether a node is, or opens, braces holding a space or a tab that
     * PCRE2 reads as a repeat from 10.43 on: a quantifier in a tree read for
     * 10.43 or later, the literal "{" that starts them, as its source writes
     * them, in a tree read for an older engine.
     */
    private function isSpacedBraces(NodeInterface $node): bool
    {
        if ($node instanceof QuantifierNode) {
            return str_starts_with($node->quantifier, '{') && false !== strpbrk($node->quantifier, " \t");
        }

        return $node instanceof LiteralNode && null !== $this->spacedBracesEnd($node);
    }

    /**
     * Where the braces a literal "{" opens end, as its source writes them,
     * when they hold a space or a tab and PCRE2 reads them as a repeat from
     * 10.43 on; null otherwise. A "{" spelled otherwise, "\{" or the operand
     * of "\c{", opens none.
     */
    private function spacedBracesEnd(LiteralNode $node): ?int
    {
        if ('{' !== $node->value || '{' !== $this->writtenText($node, false)
            || 1 !== LibraryPcre::match(self::SPACED_BRACES, (string) $this->source, $matches, 0, $node->getStartPosition())
            || false === strpbrk($matches[0], " \t")) {
            return null;
        }

        return $node->getStartPosition() + \strlen($matches[0]);
    }

    /**
     * Render the whole pattern, on one line or laid out from the first
     * indent level on.
     *
     * @param non-empty-string $trail the marker of a trailing comment
     */
    private function renderRoot(RegexNode $node, string $flags, bool $layout, string $trail = PatternLayout::TRAIL): string
    {
        $write = function () use ($node, $flags, $layout, $trail): string {
            $this->capturesOpened = 0;
            $this->flags = $flags;
            $this->layout = new PatternLayout($this, $this->width, $this->countsCodePoints, $this->source, $trail, $layout);

            return $this->layout->root($node->pattern);
        };
        $body = $write();
        $tail = $layout && str_contains($this->flags, 'x') ? "\n" : '';

        // PHP pairs bracket delimiters by counting them: a dropped comment
        // may leave one alone, a kept one hold the partner of a literal.
        if (null !== $this->bracketMark) {
            $body = DelimiterBrackets::markedBalanced($body, $this->bracketMark, $node->delimiter, $this->closingDelimiter);
        }

        return $node->delimiter.$this->endingWhereWritten($body, $write).$tail.$this->closingDelimiter.$flags;
    }

    /**
     * Whether a verb name or a callout string holds a raw line break: no
     * escape spells one there.
     */
    private static function holdsLineBreakInName(NodeInterface $node): bool
    {
        if (($node instanceof PcreVerbNode && false !== strpbrk($node->verb, "\n\r"))
            || ($node instanceof CalloutNode && \is_string($node->identifier) && false !== strpbrk($node->identifier, "\n\r"))) {
            return true;
        }

        foreach ($node->getChildren() as $child) {
            if (self::holdsLineBreakInName($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The body spelled so that PHP ends the pattern at the closing delimiter
     * written after it. Where the body as written does not, the brackets
     * PHP counts do not pair: a printer that drops a comment, collapses it
     * or writes a literal bracket or a group otherwise than its source may
     * leave one alone. The body is then written again with its literal
     * brackets and its lookbehind and atomic symbols marked, and each is
     * spelled where it pairs (see DelimiterBrackets::rebalanced). Where no
     * spelling pairs them, the body is given back as written.
     *
     * @param \Closure(): string $write writes the body again
     */
    private function endingWhereWritten(string $body, \Closure $write): string
    {
        $spelled = DelimiterBrackets::spelledToEnd($body, $this->delimiter, $this->closingDelimiter);
        if ($this->delimiter === $this->closingDelimiter || DelimiterBrackets::endsWhereWritten($spelled, $this->delimiter, $this->closingDelimiter)) {
            return $spelled;
        }

        $bracketMark = $this->bracketMark;
        $mark = $bracketMark ?? self::markAbsentFrom($body, "\x03\x04", "\x04", self::BRACKET_MARKS);
        $this->bracketMark = $this->rebalancingMark = $mark;

        try {
            $balanced = DelimiterBrackets::rebalanced($write(), $mark, $this->delimiter, $this->closingDelimiter);
        } finally {
            $this->bracketMark = $bracketMark;
            $this->rebalancingMark = null;
        }

        return null === $balanced ? $spelled : DelimiterBrackets::spelledToEnd($balanced, $this->delimiter, $this->closingDelimiter);
    }

    /**
     * A literal's text as written, marked where it is a bracket of the
     * delimiter and the pattern is written again to balance them (see
     * endingWhereWritten): unescaped, or escaped as escapeString marks it.
     */
    private function markedWritten(string $text): string
    {
        if (null === $this->rebalancingMark) {
            return $text;
        }

        if ($text === $this->delimiter || $text === $this->closingDelimiter) {
            return $this->rebalancingMark.DelimiterBrackets::MARK_BARE.$text;
        }

        $bracket = substr($text, 1);
        if (2 === \strlen($text) && '\\' === $text[0] && ($bracket === $this->delimiter || $bracket === $this->closingDelimiter)) {
            return ($this->bracketMarkFor($bracket) ?? '\\').$bracket;
        }

        return $text;
    }

    /**
     * What pretty printing writes for the backslash of a literal bracket of
     * the delimiter, null for any other character: the mark, then "Q" where
     * the bracket cannot stand unescaped, "(", ")" and "[" outside a class,
     * "]" in one. DelimiterBrackets::markedBalanced reads it back.
     */
    private function bracketMarkFor(string $character): ?string
    {
        if (null === $this->bracketMark || $this->inExtendedClass
            || ($character !== $this->delimiter && $character !== $this->closingDelimiter)) {
            return null;
        }

        return $this->bracketMark.(str_contains($this->inCharClass ? ']' : '()[', $character) ? 'Q' : '');
    }

    /**
     * A mark the text does not hold, to stand in it for what can only be
     * written once the whole text is known: the first of $bytes the text
     * does not hold, or else $mark, lengthened by $filler until the text
     * holds no such text. $filler is not the first byte of $mark, so that
     * byte occurs nowhere else in the mark: no text before the mark can end
     * in a piece of it that the mark completes.
     *
     * A mark that stands for one character in a line the layout measures,
     * the backslash of a literal bracket, is one byte wherever it can be:
     * it measures as that character does, and where a line breaks does not
     * depend on the control bytes the pattern holds.
     *
     * @param non-empty-string $mark
     *
     * @return non-empty-string
     */
    private static function markAbsentFrom(string $text, string $mark, string $filler, string $bytes = ''): string
    {
        $absent = '' === $bytes ? '' : str_replace(Bytes::split(count_chars($text, 3)), '', $bytes);
        if ('' !== $absent) {
            return $absent[0];
        }

        while (str_contains($text, $mark)) {
            $mark .= $filler;
        }

        return $mark;
    }

    /**
     * The width of a piece of pattern: code points under UTF, bytes
     * otherwise.
     */
    private function columns(string $text): int
    {
        return PatternLayout::columnsOf($text, $this->countsCodePoints);
    }

    /**
     * Whether the printer lays patterns out: the formatter, and pretty
     * printing.
     */
    private function laysOut(): bool
    {
        return self::MODE_FORMAT === $this->mode || self::MODE_PRETTY === $this->mode;
    }
}
