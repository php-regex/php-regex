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
 * Compiler that recompiles regex AST back into optimized strings.
 *
 * This visitor provides compilation with caching and
 * streamlined string building for efficiency while maintaining
 * full PCRE compatibility.
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

    /**
     * What a piece of a run of atoms is to the layout: a character of a
     * word, white space, any other unit, a start or an end anchor, a
     * start-of-pattern option.
     */
    private const PIECE_WORD = 0;

    private const PIECE_SPACE = 1;

    private const PIECE_UNIT = 2;

    private const PIECE_START = 3;

    private const PIECE_END = 4;

    private const PIECE_OPTION = 5;

    private const PRETTY_WIDTH = 80;

    private const INDENT = '    ';

    /**
     * Where a trailing comment starts on a line, until the comments of
     * consecutive lines are aligned on one column.
     */
    private const TRAIL = "\x01TRAILING-COMMENT\x02";

    /**
     * Past this many indent levels the rest of a construct stays on one line:
     * a deep nest laid out one group per line would grow past the length the
     * parser reads back.
     */
    private const MAX_INDENT = 10;

    /**
     * A construct nesting more groups than this breaks, even when it fits.
     */
    private const MAX_DEPTH = 2;

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

    // Optimized meta-character sets for fast lookups
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

    private const CHAR_CLASS_META = [
        '\\' => true, ']' => true, '-' => true, '^' => true, '[' => true,
    ];

    // Minimal state tracking
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
     * @var array<array{0: int, 1: int}>|null
     */
    private ?array $quotedSpans = null;

    /**
     * The tree of $source, which tells a "\Q" in a comment or a name from
     * one that opens a quote.
     */
    private ?NodeInterface $sourcePattern = null;

    private int $indentLevel;

    /**
     * Whether the node being visited may break over lines: while the
     * formatter or pretty printing lays a pattern out under x, where
     * newlines and indents are ignored. Without x the layout would change
     * what the pattern matches.
     */
    private bool $layout;

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
     * Whether the last rendering wrote a "#" comment, which ends its line.
     */
    private bool $sawLineComment = false;

    /**
     * Why the last pattern formatted was left as written, if it was.
     */
    private ?FormatRefusal $refusal = null;

    /**
     * Whether the sequence about to be visited is the body of a construct
     * that already broke: it breaks too, rather than trying one line again.
     */
    private bool $parentBroke = false;

    /**
     * A comment already written as the trailing comment of the line before,
     * which its own place in the tree must not write again.
     */
    private ?NodeInterface $skipNode = null;

    /**
     * The marker of a trailing comment in the pattern being laid out: TRAIL,
     * lengthened until the pattern holds no such text.
     *
     * @var non-empty-string
     */
    private string $trail = self::TRAIL;

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
     * Where pretty printing writes a bracket delimiter, the mark that stands
     * for the backslash of a literal bracket until the balance is known: the
     * bracket stays counted when it pairs with one written as is.
     *
     * @var non-empty-string|null
     */
    private ?string $bracketMark = null;

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
        $this->indentLevel = 0;
        $this->layout = false;
        $this->mode = $this->pretty ? self::MODE_PRETTY : self::MODE_OFF;
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
        $this->indentLevel = 0;
        $this->source = null;
        $this->quotedSpans = null;
        $this->sourcePattern = null;
        $this->layout = false;
        $this->countsCodePoints = false;
        $this->sawLineComment = false;
        $this->refusal = null;
        $this->parentBroke = false;
        $this->skipNode = null;
        $this->trail = self::TRAIL;
        $this->addsExtended = false;
        $this->keptComments = [];
        $this->keptQuotedBrackets = [];
        $this->droppedGroups = [];
        $this->bracketMark = null;
        $this->spacedBraces = null;
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

        $body = $node->delimiter.self::controlBackslashesSpelled(
            $this->ignorableText(0, $node->pattern->getStartPosition())
            .$node->pattern->accept($this)
            .$this->ignorableText($node->pattern->getEndPosition(), null),
            $node->delimiter,
            $this->closingDelimiter,
        );

        // A body ending in an odd run of backslashes, "\c\" for one, would
        // escape the closing delimiter: PHP pairs backslashes as it looks for
        // it. An empty "\E", which PCRE ignores, closes the run.
        if (1 === (\strlen($body) - \strlen(rtrim($body, '\\'))) % 2) {
            $body .= '\\E';
        }

        return $body.$this->closingDelimiter.$node->flags;
    }

    #[\Override]
    public function visitAlternation(AlternationNode $node): string
    {
        // Optimized: direct compilation without array_map overhead
        $alternatives = $node->alternatives;
        if ([] === $alternatives) {
            return '';
        }

        if ($this->inCharClass) {
            return $this->compileCharClassMembers($alternatives);
        }

        if ($this->layout) {
            return $this->layoutAlternation($alternatives);
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
        // Optimized: direct compilation without array_map overhead
        $children = $node->children;
        if ([] === $children) {
            return '';
        }

        if ($this->inCharClass) {
            return $this->compileCharClassMembers($children);
        }

        if ($this->layout) {
            return $this->layoutSequence($node);
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

        if ($this->layout && !self::isEmpty($node->child)) {
            return $this->layoutGroup($node, $flags);
        }

        if (GroupType::Capturing === $node->type || GroupType::Named === $node->type) {
            $this->capturesOpened++;
        }

        $child = $this->compileGroupChild($node, $flags);

        // A non-capturing group holding nothing but comments matches the
        // empty string once compact drops them, and sets nothing: it goes
        // too. A quantifier on it writes "(?:)", a separator writes one
        // where its neighbours would read as one item.
        if ('' === $child && self::MODE_COMPACT === $this->mode && GroupType::NonCapturing === $node->type && !self::isEmpty($node->child)) {
            $this->droppedGroups[spl_object_id($node)] = true;

            return '';
        }

        if ($this->isUnscopedSetting($node, $flags, $child)) {
            return '(?'.$flags.')';
        }

        $opening = $this->openingAsWritten($node, $this->groupOpening($node, $flags));

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

        // Fast path for empty strings
        if ('' === $value) {
            return '';
        }

        // Raw literals should not be escaped (used for regex syntax characters)
        if ($node->isRaw) {
            return $value;
        }

        // A "\Q...\E" run standing alone, a whole branch, reads back as a
        // sequence of literals: it is laid out as one.
        if ($this->layout && !$this->inCharClass && !$this->inExtendedClass) {
            $text = $this->forceInline($node);
            $current = '';
            $pieces = [];
            $this->joinRun($current, $pieces, null, $node, $text);

            return implode("\n".self::indent($this->indentLevel), $this->runLines($pieces, $current, !str_contains($this->flags, 'x')));
        }

        $quoted = $this->quotedRunAsWritten($node);
        if (null !== $quoted) {
            return $quoted;
        }

        if ($this->inExtendedClass) {
            return $this->asWritten($node, $this->extendedClassOperand($value), $value);
        }

        // Special case for closing bracket outside char class
        if (!$this->inCharClass && ']' === $value && ']' !== $this->closingDelimiter) {
            return $this->asWritten($node, $value, $value);
        }

        // Intelligent escaping with optimized character processing
        return $this->asWritten($node, $this->escapeString($value), $value);
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
     * Without the source, every operation is parenthesised: the precedence
     * of "&" over the others needs no reader to remember it.
     */
    #[\Override]
    public function visitClassSetOperation(ClassSetOperationNode $node): string
    {
        if (null === $node->left) {
            return '!'.$node->right->accept($this);
        }

        return '('.$node->left->accept($this).$node->symbol.$node->right->accept($this).')';
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
        if ($node === $this->skipNode) {
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
            $this->sawLineComment = true;

            return $this->layout ? rtrim($node->comment, "\n") : $node->comment;
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
        if (self::MODE_FORMAT === $this->mode && $this->indentLevel > 0 && false !== strpbrk($comment, "\n\r")) {
            $trimmed = rtrim($comment, " \t\r\n");
            if (false !== strpbrk(substr($comment, \strlen($trimmed)), "\n\r")) {
                $comment = $trimmed;
            }

            $indent = self::indent($this->indentLevel);
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
        if ($this->layout) {
            return $this->layoutConditional($node);
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
            if ($this->layout && str_contains($flags, 'x')) {
                $inline = $this->tryInline($node);
                if (null !== $inline) {
                    return $inline;
                }

                $level = $this->indentLevel;
                $this->indentLevel++;
                $this->parentBroke = true;
                $content = $node->content->accept($this);
                $this->parentBroke = false;
                $this->indentLevel--;

                // Once x is off, a line break would be a literal.
                $closing = str_contains($this->flags, 'x') ? "\n".self::indent($level).')' : ')';

                return '(?(DEFINE)'.$this->bodyLine($content, $level + 1).$content.$closing;
            }

            return '(?(DEFINE)'.($this->layout ? $this->forceInline($node->content) : $node->content->accept($this)).')';
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
     * The text that opened a group, as the pattern spelled it.
     *
     * PCRE names a group four ways — "(?<n>", "(?'n'", "(?P<n>", "(?P\"n\"" —
     * and they mean the same thing, so the tree keeps only the name. The
     * source still knows which one was written.
     */
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
    private function groupOpening(GroupNode $node, string $flags): string
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
     * The condition of a conditional with its parentheses, "(1)" or
     * "(?=a)". The formatter keeps "(<n>)" and "('n')" as written.
     */
    private function conditionText(ConditionalNode $node): string
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
    private function asWritten(NodeInterface $node, string $compiled, ?string $value = null): string
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
     * bracketsAsWritten). Null for any other literal.
     */
    private function quotedRunAsWritten(LiteralNode $node): ?string
    {
        $pieces = $this->quotedRunPieces($node);

        return null === $pieces ? null : implode('', array_column($pieces, 0));
    }

    /**
     * The pieces of a quoted run kept as written, as the layout reads them
     * back (see quotedRunAsWritten). A line break, a tab, a vertical tab or
     * a form feed, which a laid-out or compacted pattern spells as escapes,
     * parts the run: the part holding a bracket kept counted stays quoted,
     * the others are respelled, "\Q{a\nb\E" written "\Q{a\E\nb".
     *
     * @return list<array{string, int}>|null
     */
    private function quotedRunPieces(LiteralNode $node): ?array
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
                $pieces[] = ['\\Q'.$part.'\\E', self::PIECE_UNIT];
            } else {
                foreach ('' === $part ? [] : ($this->countsCodePoints ? mb_str_split($part, 1, 'UTF-8') : Bytes::split($part)) as $character) {
                    $pieces[] = [$this->escapeString($character), self::isWhiteSpaceCharacter($character) ? self::PIECE_SPACE : self::PIECE_WORD];
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
                }, self::PIECE_SPACE];
                $offset++;
            }
        }

        return $pieces;
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
     * @return array<array{0: int, 1: int}>
     */
    private function quotedSpans(): array
    {
        if (null !== $this->quotedSpans) {
            return $this->quotedSpans;
        }

        // A comment, a verb name or a callout string holds text: a "\Q" in
        // one opens no quote.
        $texts = [];
        $unused = [];
        if (null !== $this->sourcePattern) {
            self::bracketSpans($this->sourcePattern, '', $texts, $unused);
            usort($texts, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        }

        $spans = [];
        $source = (string) $this->source;
        $length = \strlen($source);
        $offset = 0;
        $text = 0;
        $count = \count($texts);

        // Only a backslash that starts an escape opens a quote: "\\Q" is an
        // escaped backslash, then "Q", and "\c\Q" is control-backslash.
        while (false !== ($start = strpos($source, '\\', $offset))) {
            while ($text < $count && $texts[$text][1] <= $start) {
                $text++;
            }

            if ($text < $count && $texts[$text][0] <= $start) {
                $offset = $texts[$text][1];

                continue;
            }

            // "\c" at the end of the pattern, the letter "c" on PHP 7.3 and
            // 7.4, takes no character after it.
            $escape = $source[$start + 1] ?? '';
            if ('Q' !== $escape) {
                $offset = min($length, $start + ('c' === $escape ? 3 : 2));

                continue;
            }

            $end = strpos($source, '\\E', $start + 2);
            $stop = false === $end ? $length : $end + 2;
            $spans[] = [$start, $stop];
            $offset = $stop;
        }

        return $this->quotedSpans = $spans;
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
     */
    private function separator(string $compiled, NodeInterface $left, NodeInterface $right, string $next): string
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
     * Whether the text ends with something the next character would turn
     * into another construct: "\N" before braces, a literal "{" before a
     * repeat count, a literal "[" in a class before ":", "." or "=".
     */
    private function opensConstruct(string $compiled, string $next): bool
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
        return '' === $child && self::isSetting($node, $flags);
    }

    /**
     * Whether a group is a setting, "(?-x)", which holds for the rest of the
     * group around it, rather than a scope, "(?-x:...)", empty or not.
     */
    private static function isSetting(GroupNode $node, ?string $flags = null): bool
    {
        return GroupType::InlineFlags === $node->type
            && $node->getEndPosition() - $node->getStartPosition() === \strlen($flags ?? $node->flags ?? '') + 3;
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
     * String escaping with minimal allocations.
     */
    private function escapeString(string $value): string
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

        // Fast pre-scan to check if escaping is needed
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

        // Fast path: no escaping needed
        if (!$needsEscape) {
            return $value;
        }

        // Optimized escaping with single pass
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
        // does: the balance decides.
        if ($node instanceof LiteralNode && '[' === $node->value) {
            return $this->writtenText($node) ?? ($this->shouldEscapeCharClassOpen($next) ? '\\[' : ($this->bracketMarkFor('[') ?? '').'[');
        }

        if ($node instanceof RangeNode) {
            $start = $node->start;
            $startCompiled = $start instanceof LiteralNode && '[' === $start->value
                ? ($this->bracketMarkFor('[') ?? '').'['
                : $start->accept($this);

            return $startCompiled.'-'.$node->end->accept($this);
        }

        return $node->accept($this);
    }

    private function shouldEscapeCharClassOpen(?NodeInterface $next): bool
    {
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
        $this->skipNode = null;
        $this->addsExtended = false;
        $this->trail = self::TRAIL;
        $this->keptComments = [];
        $this->keptQuotedBrackets = [];
        $this->droppedGroups = [];
        $this->spacedBraces = null;
        $this->delimiter = $node->delimiter;
        $this->closingDelimiter = PatternParser::closingDelimiter($node->delimiter);
        $this->bracketMark = self::MODE_PRETTY === $this->mode && $this->delimiter !== $this->closingDelimiter
            ? self::markAbsentFrom($node->source ?? $node->accept(new self()))
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

            return $written ?? $node->accept(new self());
        }

        if (self::MODE_COMPACT === $this->mode) {
            [$this->keptComments, $this->keptQuotedBrackets] = $this->bracketsAsWritten($node, true);

            // A "(?#...)" ends at the first ")": a "#" comment holding the
            // one the balance needs cannot become one.
            foreach ($this->keptComments as $kept) {
                if (str_contains($kept, ')')) {
                    $this->keptComments = [];
                    $this->keptQuotedBrackets = [];
                    $this->refusal = FormatRefusal::ParenthesisInComment;

                    return $written ?? $node->accept(new self());
                }
            }

            return $this->renderRoot($node, str_replace('x', '', $node->flags), false);
        }

        [, $this->keptQuotedBrackets] = $this->bracketsAsWritten($node, false);

        $shallow = self::groupDepth($node->pattern) <= self::MAX_DEPTH;
        if (null !== $written && false === strpbrk($written, "\n\r") && $shallow && $this->columns($written) <= $this->width) {
            return $written;
        }

        // A line feed or a carriage return a "(?#...)" holds breaks the
        // line, as a "#" comment does: every line break of the output is
        // layout.
        $inline = $this->renderRoot($node, $node->flags, false);
        $hasLineComment = $this->sawLineComment;
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

        $this->trail = self::markerAbsentFrom(($this->source ?? '').$inline);
        $this->addsExtended = !str_contains($node->flags, 'x');

        // Under the x it adds, a space may part two tokens a "(?:)" parted
        // without it: the line a second pass would measure may fit.
        if ($this->addsExtended && $shallow) {
            $inline = $this->renderRoot($node, $flags, false);
            if (false === strpbrk($inline, "\n\r") && $this->columns($inline) <= $this->width) {
                return $inline;
            }
        }

        return $this->finishLines($this->renderRoot($node, $flags, true));
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
                if ($item instanceof GroupNode && self::isSetting($item) && self::isEmpty($item->child)
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
            if (self::isSetting($node) && self::isEmpty($node->child)) {
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
     * How many groups the deepest path through a node nests. Lookarounds,
     * conditionals, "(?|", atomic groups, DEFINE and a scope such as
     * "(?i:)", empty or not, count; a setting such as "(?i)" and a class do
     * not.
     */
    private static function groupDepth(NodeInterface $node): int
    {
        if ($node instanceof CharClassNode || $node instanceof ExtendedCharClassNode) {
            return 0;
        }

        $own = ($node instanceof GroupNode && !(self::isSetting($node) && self::isEmpty($node->child)))
            || $node instanceof ConditionalNode || $node instanceof DefineNode ? 1 : 0;

        $deepest = 0;
        foreach ($node->getChildren() as $child) {
            $deepest = max($deepest, self::groupDepth($child));
        }

        return $own + $deepest;
    }

    /**
     * Render the whole pattern, on one line or laid out from the first
     * indent level on.
     */
    private function renderRoot(RegexNode $node, string $flags, bool $layout): string
    {
        $this->capturesOpened = 0;
        $this->flags = $flags;
        $this->sawLineComment = false;
        $this->layout = $layout;
        $this->indentLevel = $layout ? 1 : 0;
        $this->parentBroke = $layout;

        try {
            $body = $node->pattern->accept($this);
        } finally {
            $this->layout = false;
            $this->parentBroke = false;
        }

        $tail = '';
        if ($layout) {
            // PCRE reads start-of-pattern options at offset 0 only: they stay
            // on the line of the opening delimiter. A comment after them goes
            // on a line of its own, and an empty first branch gets no line.
            $options = StartOptions::of($body);
            $rest = substr($body, \strlen($options));
            if (str_starts_with($rest, $this->trail)) {
                $rest = substr($rest, \strlen($this->trail));
            }

            if ('' !== $rest && !str_starts_with($rest, "\n")) {
                $rest = "\n".self::INDENT.$rest;
            }

            $body = $options.$rest;
            $tail = str_contains($this->flags, 'x') ? "\n" : '';
        }

        // PHP pairs bracket delimiters by counting them: a dropped comment
        // may leave one alone, a kept one hold the partner of a literal.
        if (null !== $this->bracketMark) {
            $body = $this->literalBracketsBalanced($body, $this->bracketMark);
        } elseif (self::MODE_PRETTY !== $this->mode && $node->delimiter !== $this->closingDelimiter) {
            $body = self::balancedBrackets($body, $node->delimiter, $this->closingDelimiter);
        }

        $body = $node->delimiter.self::controlBackslashesSpelled($body, $node->delimiter, $this->closingDelimiter);
        if (1 === (\strlen($body) - \strlen(rtrim($body, '\\'))) % 2) {
            $body .= '\\E';
        }

        return $body.$tail.$this->closingDelimiter.$flags;
    }

    /**
     * A node on one line, when it fits from the current indent, holds no
     * "#" comment and nests at most two groups; past the indent cap, on one
     * line whatever its width. Null when it has to break.
     */
    private function tryInline(NodeInterface $node): ?string
    {
        [$captures, $flags, $sawLineComment] = [$this->capturesOpened, $this->flags, $this->sawLineComment];
        $this->sawLineComment = false;
        $text = $this->forceInline($node);

        if (!$this->sawLineComment && (self::MAX_INDENT <= $this->indentLevel
            || (false === strpbrk($text, "\n\r") && $this->fits($text) && self::groupDepth($node) <= self::MAX_DEPTH))) {
            $this->sawLineComment = $sawLineComment;

            return $text;
        }

        [$this->capturesOpened, $this->flags, $this->sawLineComment] = [$captures, $flags, $sawLineComment];

        return null;
    }

    /**
     * Whether a line holding $text fits the width from the current indent.
     * An escaped space ending it counts as "\x20", the spelling it takes at
     * the end of a line.
     */
    private function fits(string $text): bool
    {
        return \strlen(self::INDENT) * $this->indentLevel + $this->columns($text) + (self::endsInEscapedSpace($text) ? 2 : 0) <= $this->width;
    }

    /**
     * A node on one line, whatever its width.
     *
     * @phpstan-impure
     */
    private function forceInline(NodeInterface $node): string
    {
        $this->layout = false;

        try {
            return $node->accept($this);
        } finally {
            $this->layout = true;
        }
    }

    /**
     * Items on one line, one after the other.
     *
     * @param non-empty-list<NodeInterface> $items
     *
     * @phpstan-impure
     */
    private function inlineRun(array $items): string
    {
        $this->layout = false;

        try {
            $text = $items[0]->accept($this);
            for ($i = 1, $count = \count($items); $i < $count; $i++) {
                $next = $items[$i]->accept($this);
                $text .= $this->separator($text, $items[$i - 1], $items[$i], $next).$next;
            }

            return $text;
        } finally {
            $this->layout = true;
        }
    }

    /**
     * Whether a node takes lines of its own once its parent breaks: a group
     * with a body, a conditional, DEFINE, quantified or not.
     */
    private static function isBlock(NodeInterface $node): bool
    {
        if ($node instanceof QuantifierNode) {
            return self::isBlock($node->node);
        }

        if ($node instanceof GroupNode) {
            return !self::isEmpty($node->child);
        }

        return $node instanceof ConditionalNode || $node instanceof DefineNode;
    }

    /**
     * @param list<NodeInterface> $alternatives
     */
    private function layoutAlternation(array $alternatives): string
    {
        $this->parentBroke = false;
        // "| " hangs in the indent, so every branch starts in one column.
        $bar = str_repeat(' ', max(0, $this->indentLevel * \strlen(self::INDENT) - 2)).'|';
        $result = $alternatives[0]->accept($this);

        for ($i = 1, $count = \count($alternatives); $i < $count; $i++) {
            // Once x is off, the rest of the group stays on its line.
            if (!str_contains($this->flags, 'x')) {
                $result .= '|'.$alternatives[$i]->accept($this);

                continue;
            }

            $previous = $alternatives[$i - 1];
            $separator = "\n".$bar;

            // A comment written after "|" trails the branch before it.
            $lead = $this->leadingSameLineComment($alternatives[$i], $previous->getEndPosition());
            $previousLast = $previous instanceof SequenceNode ? ($previous->children[\count($previous->children) - 1] ?? null) : $previous;
            if (null !== $lead && !$previousLast instanceof CommentNode
                && !str_contains(substr($result, (int) strrpos($result, "\n")), $this->trail)) {
                $this->skipNode = $lead;
                $result .= $this->trail.rtrim($lead->comment, "\n");
            }

            $first = $alternatives[$i] instanceof SequenceNode ? ($alternatives[$i]->children[0] ?? null) : $alternatives[$i];
            if (substr_count($this->gap($previous, $alternatives[$i]), "\n") >= 2 && $first !== $this->skipNode) {
                $separator = "\n".$separator;
            }

            $alternative = $alternatives[$i]->accept($this);
            $this->skipNode = null;
            $result .= $separator.('' === $alternative ? '' : ' '.$alternative);
        }

        return $result;
    }

    /**
     * A broken sequence: each run of atoms on a line when it fits, broken
     * over lines when it does not (see runLines), each group on lines of its
     * own, each "#" comment where its author put it.
     */
    private function layoutSequence(SequenceNode $node): string
    {
        $broke = $this->parentBroke;
        $this->parentBroke = false;
        if (!$broke) {
            $inline = $this->tryInline($node) ?? $this->inlineWithTrailingComment($node);
            if (null !== $inline) {
                return $inline;
            }
        }

        $lines = [];
        // The run of atoms being joined, on one line and piece by piece.
        $current = '';
        $pieces = [];
        $previous = null;
        $glued = false;
        foreach (self::flatChildren($node) as $child) {
            if ($child === $this->skipNode) {
                $this->skipNode = null;
                $previous = $child;

                continue;
            }

            // Once x is off, nothing may be split any more.
            if ($glued || !str_contains($this->flags, 'x')) {
                $glued = true;
                $this->joinRun($current, $pieces, $previous, $child, $this->forceInline($child));
                $previous = $child;

                continue;
            }

            $gap = null === $previous ? '' : $this->gap($previous, $child);
            $blank = null !== $previous && substr_count($gap, "\n") + ($previous instanceof CommentNode && str_ends_with($previous->comment, "\n") ? 1 : 0) >= 2;

            if ($child instanceof CommentNode && $child->extended) {
                $text = $child->accept($this);
                $trailing = null !== $previous && !$previous instanceof CommentNode && !str_contains($gap, "\n");
                if ('' !== $current) {
                    array_push($lines, ...$this->runLines($pieces, $current, false));
                    $current = '';
                    $pieces = [];
                }

                if ($blank && [] !== $lines) {
                    $lines[] = '';
                }

                if ($trailing && [] !== $lines && '' !== $lines[\count($lines) - 1]) {
                    $lines[\count($lines) - 1] .= $this->trail.$text;
                } else {
                    $lines[] = $text;
                }

                $previous = $child;

                continue;
            }

            $isBlock = self::isBlock($child);
            if ($isBlock || $blank || ($previous instanceof CommentNode && '' === $current)) {
                if ('' !== $current) {
                    array_push($lines, ...$this->runLines($pieces, $current, false));
                    $current = '';
                    $pieces = [];
                }

                if ($blank && [] !== $lines) {
                    $lines[] = '';
                }

                if ($isBlock) {
                    $lines[] = $child->accept($this);
                    $previous = $child;

                    continue;
                }
            }

            $this->joinRun($current, $pieces, $previous, $child, $this->forceInline($child));
            $previous = $child;
        }

        if ('' !== $current) {
            array_push($lines, ...$this->runLines($pieces, $current, $glued));
        }

        $indent = self::indent($this->indentLevel);
        $out = '';
        foreach ($lines as $i => $line) {
            $out .= 0 === $i ? $line : ('' === $line ? "\n" : "\n".$indent.$line);
        }

        return $out;
    }

    /**
     * The children of a sequence, those of a sequence nested in it in its
     * place: "\1234" reads as "\123" then "4", one sequence, and the output
     * reads back as two atoms of the sequence around it.
     *
     * @return list<NodeInterface>
     */
    private static function flatChildren(SequenceNode $node): array
    {
        $children = [];
        foreach ($node->children as $child) {
            if ($child instanceof SequenceNode) {
                array_push($children, ...self::flatChildren($child));
            } else {
                $children[] = $child;
            }
        }

        return $children;
    }

    /**
     * Join an atom to the run: to its text on one line, after what separates
     * it from the atom before, and to its pieces, the first of which carries
     * that separator, and whether no line break may stand before it.
     *
     * @param list<array{string, int, string, bool}> $pieces
     */
    private function joinRun(string &$current, array &$pieces, ?NodeInterface $previous, NodeInterface $child, string $text): void
    {
        $joined = '' !== $current && null !== $previous;
        $separator = $joined ? $this->separator($current, $previous, $child, $text) : '';

        // A "(?:)" written to keep two atoms apart reads back as an atom of
        // its own: so it is laid out, and formatting the output again
        // changes nothing. Anything else that would extend a construct the
        // run ends with, "9" after "{2", stays on its line: read back across
        // a line break, the two would ask for a "(?:)" between them.
        $glued = $joined && '' !== $text && '(?:)' !== $separator && $this->opensConstruct($current, $text[0]);
        $current .= $separator.$text;
        if ('(?:)' === $separator) {
            $pieces[] = [$separator, self::PIECE_UNIT, '', false];
            $separator = '';
        }

        foreach ($this->runPieces($child, $text) as $index => [$piece, $kind]) {
            $pieces[] = [$piece, $kind, 0 === $index ? $separator : '', 0 === $index && $glued];
        }
    }

    /**
     * What an atom of a run is to the layout, as the output writes it, each
     * piece with its kind (one of the PIECE_* constants): a literal written
     * as escapes, a "\Q...\E" run respelled, is one piece per character.
     *
     * @return list<array{string, int}>
     */
    private function runPieces(NodeInterface $node, string $text): array
    {
        if ('' === $text) {
            return [];
        }

        if ($node instanceof LimitMatchNode || ($node instanceof PcreVerbNode && $text === StartOptions::of($text))) {
            return [[$text, self::PIECE_OPTION]];
        }

        if (self::isWhiteSpaceAtom($node)) {
            return [[$text, self::PIECE_SPACE]];
        }

        if (($node instanceof AnchorNode && '^' === $node->value) || ($node instanceof AssertionNode && \in_array($node->value, ['A', 'G'], true))) {
            return [[$text, self::PIECE_START]];
        }

        if (($node instanceof AnchorNode && '$' === $node->value) || ($node instanceof AssertionNode && \in_array($node->value, ['z', 'Z'], true))) {
            return [[$text, self::PIECE_END]];
        }

        if ($node instanceof CharLiteralNode || $node instanceof ControlCharNode) {
            return [[$text, self::PIECE_WORD]];
        }

        $quoted = $node instanceof LiteralNode ? $this->quotedRunPieces($node) : null;
        if (null !== $quoted) {
            return $quoted;
        }

        // A "\Q...\E" run kept as written is one unit, as is a raw literal.
        if (!$node instanceof LiteralNode || $node->isRaw || str_starts_with($text, '\\Q')) {
            return [[$text, self::PIECE_UNIT]];
        }

        $characters = $this->countsCodePoints ? mb_str_split($node->value, 1, 'UTF-8') : Bytes::split($node->value);
        if (1 === \count($characters)) {
            return [[$text, self::PIECE_WORD]];
        }

        $pieces = [];
        $written = '';
        $spaced = false;
        foreach ($characters as $character) {
            $piece = $this->escapeString($character);
            $written .= $piece;
            $spaced = $spaced || self::isWhiteSpaceCharacter($character);
            $pieces[] = [$piece, self::isWhiteSpaceCharacter($character) ? self::PIECE_SPACE : self::PIECE_WORD];
        }

        // Written otherwise, "é" raw in byte mode, it still reads back as
        // literal characters: a piece of a word.
        if ($written === $text) {
            return $pieces;
        }

        return [[$text, $spaced ? self::PIECE_UNIT : self::PIECE_WORD]];
    }

    /**
     * Whether an atom is white space, quantified or not: "\s", "\h", "\v",
     * "\R", or a literal space, tab, line feed, carriage return, vertical
     * tab or form feed, however it is spelled.
     */
    private static function isWhiteSpaceAtom(NodeInterface $node): bool
    {
        if ($node instanceof QuantifierNode) {
            $node = $node->node;
        }

        return match (true) {
            $node instanceof CharTypeNode => \in_array($node->value, ['s', 'h', 'v', 'R'], true),
            $node instanceof LiteralNode => !$node->isRaw && self::isWhiteSpaceCharacter($node->value),
            $node instanceof CharLiteralNode, $node instanceof ControlCharNode => $node->codePoint < 0x80 && self::isWhiteSpaceCharacter(\chr($node->codePoint)),
            default => false,
        };
    }

    private static function isWhiteSpaceCharacter(string $character): bool
    {
        return 1 === \strlen($character) && str_contains(" \t\n\r\v\f", $character);
    }

    /**
     * A run of atoms on the lines it takes. A run that fits its line, or
     * that holds a part where x is off, stays on it. Otherwise the start
     * options it opens with keep a line of their own, which goes up to the
     * delimiter's, as do a leading "^", "\A" or "\G" and a trailing "$",
     * "\z" or "\Z"; the rest breaks after each white-space separator, one
     * field per line (see fieldLines).
     *
     * @param list<array{string, int, string, bool}> $pieces
     *
     * @return list<string>
     */
    private function runLines(array $pieces, string $text, bool $glued): array
    {
        if ($glued || $this->fitsFrom($this->indentColumns(), $text)) {
            return [$text];
        }

        $lines = [];
        $first = 0;
        $last = \count($pieces) - 1;
        while ($first <= $last && self::PIECE_OPTION === $pieces[$first][1]) {
            $first++;
        }

        if ($first > 0) {
            $lines[] = self::joinPieces($pieces, 0, $first - 1);
            $rest = $first <= $last ? self::joinPieces($pieces, $first, $last) : '';
            if ('' === $rest || $this->fitsFrom($this->indentColumns(), $rest)) {
                return '' === $rest ? $lines : [...$lines, $rest];
            }
        }

        if ($first < $last && self::PIECE_START === $pieces[$first][1]) {
            $lines[] = $pieces[$first][0];
            $first++;
        }

        $end = null;
        if ($first < $last && self::PIECE_END === $pieces[$last][1]) {
            $end = $pieces[$last][0];
            $last--;
        }

        // White space in a row is one separator: the field ends after it.
        $from = $first;
        for ($i = $first; $i <= $last; $i++) {
            if (self::PIECE_SPACE === $pieces[$i][1] && ($i === $last || self::PIECE_SPACE !== $pieces[$i + 1][1])) {
                array_push($lines, ...$this->fieldLines($pieces, $from, $i));
                $from = $i + 1;
            }
        }

        if ($from <= $last) {
            array_push($lines, ...$this->fieldLines($pieces, $from, $last));
        }

        if (null !== $end) {
            $lines[] = $end;
        }

        return $lines;
    }

    /**
     * A field of a run on one line when it fits, filled up to the width
     * otherwise: a line takes units while they fit and breaks only between
     * two of them. Unquantified literal characters in a row are one unit, a
     * word; any other atom is one. The white space ending the field goes
     * with the unit before it, past the width if need be. A unit wider than
     * the width has a line of its own. A line break between two units is
     * layout under x, where it parts them as the space a separator would
     * write does: "\1" ending a line and "0" opening the next stay a
     * reference and a digit. An atom that would extend a construct the
     * unit before ends with goes with that unit.
     *
     * @param list<array{string, int, string, bool}> $pieces
     *
     * @return list<string>
     */
    private function fieldLines(array $pieces, int $from, int $to): array
    {
        $text = self::joinPieces($pieces, $from, $to);
        if ($this->fitsFrom($this->indentColumns(), $text)) {
            return [$text];
        }

        // Each unit, and the separator written before it.
        $units = [];
        $separators = [];
        $inWord = false;
        for ($i = $from; $i <= $to; $i++) {
            [$piece, $kind, $separator, $glued] = $pieces[$i];
            $last = \count($units) - 1;
            if ($last >= 0 && ($glued || self::PIECE_SPACE === $kind || ($inWord && self::PIECE_WORD === $kind))) {
                $units[$last] .= $separator.$piece;
            } else {
                $units[] = $piece;
                $separators[] = $separator;
            }

            $inWord = self::PIECE_WORD === $kind;
        }

        $lines = [];
        $line = null;
        $column = 0;
        foreach ($units as $index => $unit) {
            $separator = $separators[$index];
            if (null !== $line) {
                if ($this->fitsFrom($column, $separator.$unit)) {
                    $line .= $separator.$unit;
                    $column = $this->columnAfter($column, $separator.$unit);

                    continue;
                }

                $lines[] = $line;
            }

            $line = $unit;
            $column = $this->columnAfter($this->indentColumns(), $unit);
        }

        if (null !== $line) {
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * Pieces $from to $to on one line, each after its separator.
     *
     * @param list<array{string, int, string, bool}> $pieces
     */
    private static function joinPieces(array $pieces, int $from, int $to): string
    {
        $text = $pieces[$from][0];
        for ($i = $from + 1; $i <= $to; $i++) {
            $text .= $pieces[$i][2].$pieces[$i][0];
        }

        return $text;
    }

    /**
     * Whether text written from $column on fits the width, each line of it
     * when it holds line breaks (those a "(?#...)" holds, after which a line
     * starts with its indent). An escaped space ending it counts as "\x20",
     * the spelling it takes at the end of a line.
     */
    private function fitsFrom(int $column, string $text): bool
    {
        $lines = self::physicalLines($text);
        $last = \count($lines) - 1;
        foreach ($lines as $index => $line) {
            $end = (0 === $index ? $column : 0) + $this->columns($line) + ($index === $last && self::endsInEscapedSpace($line) ? 2 : 0);
            if ($end > $this->width) {
                return false;
            }
        }

        return true;
    }

    /**
     * The column text written from $column on ends at.
     */
    private function columnAfter(int $column, string $text): int
    {
        $lines = self::physicalLines($text);

        return 1 === \count($lines) ? $column + $this->columns($text) : $this->columns($lines[\count($lines) - 1]);
    }

    /**
     * The lines of a text, whatever line break ends each.
     *
     * @return non-empty-list<string>
     */
    private static function physicalLines(string $text): array
    {
        return false === strpbrk($text, "\n\r") ? [$text] : explode("\n", strtr(str_replace("\r\n", "\n", $text), "\r", "\n"));
    }

    private function indentColumns(): int
    {
        return \strlen(self::INDENT) * $this->indentLevel;
    }

    /**
     * A sequence whose only comment closes it, on the line of what precedes
     * it: the rest stays on one line when it fits, the comment trailing it.
     */
    private function inlineWithTrailingComment(SequenceNode $node): ?string
    {
        $children = $node->children;
        $last = $children[\count($children) - 1] ?? null;
        if (!$last instanceof CommentNode || !$last->extended || \count($children) < 2 || $last === $this->skipNode) {
            return null;
        }

        $rest = array_values(array_filter(\array_slice($children, 0, -1), fn (NodeInterface $child): bool => $child !== $this->skipNode));
        if ([] === $rest || str_contains($this->gap($rest[\count($rest) - 1], $last), "\n")) {
            return null;
        }

        $deepest = 0;
        foreach ($rest as $child) {
            if ($child instanceof CommentNode) {
                return null;
            }

            $deepest = max($deepest, self::groupDepth($child));
        }

        [$captures, $flags, $sawLineComment] = [$this->capturesOpened, $this->flags, $this->sawLineComment];
        $this->sawLineComment = false;
        $text = $this->inlineRun($rest);

        if (!$this->sawLineComment && false === strpbrk($text, "\n\r") && $this->fits($text) && $deepest <= self::MAX_DEPTH) {
            $this->sawLineComment = true;

            return $text.$this->trail.rtrim($last->comment, "\n");
        }

        [$this->capturesOpened, $this->flags, $this->sawLineComment] = [$captures, $flags, $sawLineComment];

        return null;
    }

    /**
     * A group that does not fit on one line: its opening on a line, its body
     * indented, its ")" and quantifier on a line of their own.
     */
    private function layoutGroup(GroupNode $node, string $flags): string
    {
        $inline = $this->tryInline($node);
        if (null !== $inline) {
            return $inline;
        }

        // Without x inside, a line break would be a literal.
        $inner = GroupType::InlineFlags === $node->type ? $this->withInlineFlags($this->flags, $flags) : $this->flags;
        if (!str_contains($inner, 'x')) {
            return $this->forceInline($node);
        }

        if (GroupType::Capturing === $node->type || GroupType::Named === $node->type) {
            $this->capturesOpened++;
        }

        $opening = $this->openingAsWritten($node, $this->groupOpening($node, $flags));
        $level = $this->indentLevel;
        $outer = $this->flags;
        $this->flags = $inner;

        // A comment written on the opening line trails it.
        $trailing = '';
        $lead = $this->leadingSameLineComment($node->child, $node->getStartPosition() + \strlen($opening));
        if (null !== $lead) {
            $this->skipNode = $lead;
            $trailing = $this->trail.rtrim($lead->comment, "\n");
        }

        $this->indentLevel++;
        $this->parentBroke = true;
        $child = $node->child->accept($this);
        $this->parentBroke = false;
        $this->indentLevel--;
        $this->skipNode = null;
        $closing = str_contains($this->flags, 'x') ? "\n".self::indent($level).')' : ')';
        $this->flags = $outer;

        return $opening.$trailing.$this->bodyLine($child, $level + 1).$child.$closing;
    }

    private function layoutConditional(ConditionalNode $node): string
    {
        // Without x, a line break would be a literal. "(?-x)" in the "yes"
        // branch carries into the "no" branch, which then stays on its line,
        // and ends with the conditional.
        $flags = $this->flags;
        if (!str_contains($flags, 'x')) {
            // Not reached today: a sequence, an alternation and a group each
            // glue what follows a "(?-x)" before a conditional is laid out.
            // Kept so that a new caller cannot break a line where x is off.
            return $this->forceInline($node);
        }

        $inline = $this->tryInline($node);
        if (null !== $inline) {
            return $inline;
        }

        $this->layout = false;
        $condition = $this->conditionText($node);
        $this->layout = true;

        $level = $this->indentLevel;
        $this->indentLevel++;
        $yes = $node->yes->accept($this);
        $yesKeepsX = str_contains($this->flags, 'x');
        $hasNo = !($node->no instanceof LiteralNode && '' === $node->no->value);
        $no = $yesKeepsX ? $node->no->accept($this) : $this->forceInline($node->no);
        $this->indentLevel--;
        $closesOnItsLine = str_contains($this->flags, 'x');
        $this->flags = $flags;

        $text = '(?'.$condition.$this->bodyLine($yes, $level + 1).$yes;
        if ('' !== $no || $hasNo) {
            $text .= $yesKeepsX
                ? "\n".str_repeat(' ', ($level + 1) * \strlen(self::INDENT) - 2).'|'.('' === $no ? '' : ' '.$no)
                : '|'.$no;
        }

        return $text.($closesOnItsLine ? "\n".self::indent($level) : '').')';
    }

    /**
     * What goes between an opening and its body: nothing when the body is
     * empty or starts on a line of its own already (an empty first branch),
     * as an empty branch gets no line.
     */
    private function bodyLine(string $body, int $level): string
    {
        return '' === $body || str_starts_with($body, "\n") ? '' : "\n".self::indent($level);
    }

    private static function indent(int $level): string
    {
        return str_repeat(self::INDENT, $level);
    }

    /**
     * The source text between two nodes, or '' without a source.
     */
    private function gap(NodeInterface $left, NodeInterface $right): string
    {
        if (null === $this->source) {
            return '';
        }

        $start = $left->getEndPosition();
        $length = $right->getStartPosition() - $start;

        return $length > 0 ? substr($this->source, $start, $length) : '';
    }

    /**
     * The comment a node starts with, when the source writes it on the line
     * of what comes before: it trails that line in the output too.
     */
    private function leadingSameLineComment(NodeInterface $node, int $after): ?CommentNode
    {
        $first = $node;
        while ($first instanceof AlternationNode || $first instanceof SequenceNode) {
            $children = $first instanceof AlternationNode ? $first->alternatives : $first->children;
            $first = $children[0] ?? null;
        }

        if (!$first instanceof CommentNode || !$first->extended || $first === $node || null === $this->source) {
            return null;
        }

        $length = $first->getStartPosition() - $after;
        if ($length < 0 || str_contains(substr($this->source, $after, $length), "\n")) {
            return null;
        }

        return $first;
    }

    private static function isEmpty(NodeInterface $node): bool
    {
        return ($node instanceof LiteralNode && '' === $node->value) || ($node instanceof SequenceNode && [] === $node->children);
    }

    /**
     * The last pass over a laid-out pattern. Trailing comments on
     * consecutive lines line up on one column, the way gofmt aligns them.
     * A line ending in an escaped space ends in "\x20" instead, which an
     * editor stripping trailing spaces leaves alone.
     */
    private function finishLines(string $text): string
    {
        $lines = explode("\n", $text);
        $count = \count($lines);
        for ($i = 0; $i < $count; $i++) {
            if (!str_contains($lines[$i], $this->trail)) {
                if (self::endsInEscapedSpace($lines[$i])) {
                    $lines[$i] = substr($lines[$i], 0, -2).'\\x20';
                }

                continue;
            }

            $column = 0;
            for ($j = $i; $j < $count && str_contains($lines[$j], $this->trail); $j++) {
                $column = max($column, $this->columns(explode($this->trail, $lines[$j], 2)[0]));
            }

            for ($k = $i; $k < $j; $k++) {
                [$code, $comment] = explode($this->trail, $lines[$k], 2) + [1 => ''];
                $lines[$k] = $code.str_repeat(' ', $column - $this->columns($code) + 2).$comment;
            }

            $i = $j - 1;
        }

        return implode("\n", $lines);
    }

    /**
     * Whether a line ends in an escaped space, "\ ", read escape by escape:
     * "\\ " is an escaped backslash, then a space, while "\c\\ " is the
     * control character "\c\", then an escaped space. The laid-out and the
     * compacted patterns hold no "\Q...\E" run but one holding a bracket of
     * the delimiter, which ends in "\E": the printer writes any other quoted
     * text with escapes.
     */
    private static function endsInEscapedSpace(string $line): bool
    {
        if (!str_ends_with($line, '\\ ')) {
            return false;
        }

        $last = \strlen($line) - 2;
        $i = 0;
        while ($i < $last) {
            // "\c" takes the character after it, a backslash included.
            $i += '\\' !== $line[$i] ? 1 : ('c' === $line[$i + 1] ? 3 : 2);
        }

        return $i === $last;
    }

    /**
     * A marker for trailing comments that the text does not hold. Its first
     * byte occurs nowhere else in it, so no text before it can end in a
     * piece of it that the marker completes.
     *
     * @return non-empty-string
     */
    private static function markerAbsentFrom(string $text): string
    {
        $marker = self::TRAIL;
        while (str_contains($text, $marker)) {
            $marker .= "\x02";
        }

        return $marker;
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
     * The comments a compacted pattern keeps for its bracket delimiter, by
     * object id, each with the text its "(?#...)" holds. PHP finds where
     * such a pattern ends by counting its brackets, skipping the escaped
     * ones, and some brackets take no backslash: those of a verb name or a
     * callout string, "(*MARK:\{)" names "\{", the "<" of "(?<=" and "(?<!",
     * the "[" that opens a class. A comment holding the bracket that pairs,
     * in the source, with one of those stays: a "(?#...)" as written, a "#"
     * comment as a "(?#...)" holding only its brackets, as the rest of its
     * text, a ")" included, may not stand in one; a bracket of either left
     * pairing with nothing goes. Should dropping the other comments still
     * leave such a bracket alone, every bracket of every comment stays,
     * which gives back the balance of the source.
     *
     * A bracket of a "\Q...\E" run is counted too, and would not be once
     * respelled "\{": the run is kept as written where the bracket pairs,
     * among those the output holds, with one that is not quoted; elsewhere
     * it is respelled. Laid out, the pattern keeps every comment.
     *
     * Returns the comments compact keeps, by object id, each with the text
     * its "(?#...)" holds, and the offsets of the quoted brackets kept as
     * written.
     *
     * @return array{array<int, string>, array<int, true>}
     */
    private function bracketsAsWritten(RegexNode $node, bool $dropsComments): array
    {
        $opening = $node->delimiter;
        $closing = $this->closingDelimiter;
        $spans = [];
        if ($opening === $closing || null === $this->source) {
            return [[], []];
        }

        $quotedSpans = $this->quotedSpans();
        if (!$dropsComments && [] === $quotedSpans) {
            return [[], []];
        }

        $literals = [];
        self::bracketSpans($node->pattern, $opening.$closing, $spans, $literals);

        // The brackets PHP counts, each with the comment that holds it, if
        // any, and whether a "\Q...\E" run holds it. A plain literal bracket
        // may take a backslash, which balancedBrackets adds where PHP would
        // not pair it: it has no owner. Any other bracket, a name's, a
        // group's, a class's, a repeat's, is written as is: its owner is
        // true.
        usort($spans, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $tokens = [];
        $span = 0;
        $quote = 0;
        $found = false;
        $count = \count($spans);
        $quotes = \count($quotedSpans);
        $length = \strlen($this->source);
        // A line break, a tab, a vertical tab or a form feed parts a quoted
        // run the output writes (see quotedRunPieces): each part is a run.
        $stops = "\\\n\r\t\v\f".$opening.$closing;
        $break = -1;
        for ($i = strcspn($this->source, $stops); $i < $length; $i += 1 + strcspn($this->source, $stops, $i + 1)) {
            if (str_contains("\n\r\t\v\f", $this->source[$i])) {
                $break = $i;

                continue;
            }

            if ('\\' === $this->source[$i]) {
                $i++;

                continue;
            }

            while ($span < $count && $spans[$span][1] <= $i) {
                $span++;
            }

            while ($quote < $quotes && $quotedSpans[$quote][1] <= $i) {
                $quote++;
            }

            $owner = $span < $count && $spans[$span][0] <= $i ? $spans[$span][2] : null;
            $quoted = null === $owner && $quote < $quotes && $quotedSpans[$quote][0] <= $i;
            if (null === $owner && !$quoted && !isset($literals[$i])) {
                $owner = true;
            }

            $found = $found || true === $owner;
            $tokens[] = [$opening === $this->source[$i], $owner, $i, $quoted, $quoted ? max($quotedSpans[$quote][0], $break) : -1];
        }

        if ($dropsComments && !$found) {
            return [[], []];
        }

        // The comments that stay: those holding the partner of a bracket
        // written as is. A comment keeps all of its brackets, a "#" one as a
        // "(?#...)" does: those it would drop could leave the others paired
        // elsewhere.
        $whole = [];
        $partners = self::bracketPartners(array_column($tokens, 0));
        foreach ($tokens as $index => [, $owner]) {
            $partner = $partners[$index];
            $comment = null === $partner ? null : $tokens[$partner][1];
            if (true === $owner && $comment instanceof CommentNode) {
                $whole[spl_object_id($comment)] = true;
            }
        }

        $all = !$dropsComments;
        $stays = static function (CommentNode|bool|null $owner) use (&$all, $whole): bool {
            return !$owner instanceof CommentNode || $all || isset($whole[spl_object_id($owner)]);
        };

        // Paired as PHP pairs the brackets the output holds, a quoted one is
        // respelled where it stands alone or with another quoted one: taking
        // away pairs whose two brackets go leaves every other pair as it
        // was. Kept, it pairs with a bracket written as is, or with a literal
        // one balancedBrackets leaves alone. A run is written whole: kept,
        // it keeps each of its brackets, and each one's quoted partner keeps
        // its own run.
        $quotedKept = static function () use ($tokens, $stays): array {
            $present = [];
            foreach ($tokens as $token) {
                if ($token[3] || $stays($token[1])) {
                    $present[] = $token;
                }
            }

            $runs = [];
            $pending = [];
            $partners = self::bracketPartners(array_column($present, 0));
            foreach ($present as $index => [, , , $quoted, $run]) {
                if ($quoted) {
                    $runs[$run][] = $index;
                    $partner = $partners[$index];
                    if (null !== $partner && !$present[$partner][3]) {
                        $pending[] = $run;
                    }
                }
            }

            $keptRuns = [];
            while (null !== ($run = array_pop($pending))) {
                if (isset($keptRuns[$run])) {
                    continue;
                }

                $keptRuns[$run] = true;
                foreach ($runs[$run] as $index) {
                    $partner = $partners[$index];
                    if (null !== $partner && $present[$partner][3]) {
                        $pending[] = $present[$partner][4];
                    }
                }
            }

            $kept = [];
            foreach ($present as [, , $offset, $quoted, $run]) {
                if ($quoted && isset($keptRuns[$run])) {
                    $kept[$offset] = true;
                }
            }

            return $kept;
        };

        $quoted = $quotedKept();
        $alone = [];
        $trimmed = [];
        if (!$all) {
            $left = [];
            foreach ($tokens as $index => [$isOpening, $owner, $offset, $isQuoted]) {
                if ($isQuoted ? isset($quoted[$offset]) : $stays($owner)) {
                    $left[] = [$isOpening, $owner, $index, $isQuoted];
                }
            }

            // A bracket written as is, or one of a quoted run kept whole,
            // left alone: every comment bracket stays.
            $leftPartners = self::bracketPartners(array_column($left, 0));
            foreach ($left as $index => [, $owner, , $isQuoted]) {
                if ((true === $owner || $isQuoted) && null === $leftPartners[$index]) {
                    $all = true;
                    $quoted = $quotedKept();

                    break;
                }
            }

            // A comment bracket left alone pairs with nothing PHP counts:
            // it goes, which leaves every other pair as it was, and its
            // comment keeps only the brackets that pair.
            foreach ($all ? [] : $left as $index => [, $owner, $token]) {
                if ($owner instanceof CommentNode && null === $leftPartners[$index]) {
                    $alone[$token] = true;
                    $trimmed[spl_object_id($owner)] = true;
                }
            }
        }

        $kept = [];
        foreach ($dropsComments ? $tokens : [] as $index => [$isOpening, $owner]) {
            if (!$owner instanceof CommentNode || !$stays($owner) || isset($alone[$index])) {
                continue;
            }

            $id = spl_object_id($owner);
            if (!$owner->extended && !isset($trimmed[$id])) {
                $kept[$id] = strtr($owner->comment, "\r\n", '  ');
            } else {
                $kept[$id] = ($kept[$id] ?? '').($isOpening ? $opening : $closing);
            }
        }

        return [$kept, $quoted];
    }

    /**
     * Collect, in the order of the tree, the spans of the comments, with the
     * comment, and of the verbs and string callouts, with true: a "\Q" in a
     * name opens no quote. Collect too the offsets of the plain literals
     * that are one of $brackets.
     *
     * @param list<array{int, int, CommentNode|true}> $spans
     * @param array<int, true>                        $literals
     */
    private static function bracketSpans(NodeInterface $node, string $brackets, array &$spans, array &$literals): void
    {
        if ($node instanceof CommentNode) {
            $spans[] = [$node->getStartPosition(), $node->getEndPosition(), $node];

            return;
        }

        if ($node instanceof PcreVerbNode || ($node instanceof CalloutNode && \is_string($node->identifier))) {
            $spans[] = [$node->getStartPosition(), $node->getEndPosition(), true];

            return;
        }

        if ($node instanceof LiteralNode && 1 === \strlen($node->value) && str_contains($brackets, $node->value)) {
            $literals[$node->getStartPosition()] = true;
        }

        if (!$node instanceof ExtendedCharClassNode) {
            foreach ($node->getChildren() as $child) {
                self::bracketSpans($child, $brackets, $spans, $literals);
            }
        }
    }

    /**
     * Pair brackets as balancedBrackets does, a closing one with the last
     * opening one still open: for each, the index of its partner, or null.
     *
     * @param array<int, bool> $opens whether each bracket opens
     *
     * @return array<int, int|null>
     */
    private static function bracketPartners(array $opens): array
    {
        $partners = [];
        $open = [];
        foreach ($opens as $index => $isOpening) {
            $partners[$index] = null;
            if ($isOpening) {
                $open[] = $index;
            } elseif ([] !== $open) {
                $partner = array_pop($open);
                $partners[$index] = $partner;
                $partners[$partner] = $index;
            }
        }

        return $partners;
    }

    /**
     * Escape the delimiter brackets PHP would not pair once comments are
     * dropped, "{a(?#{)b}c}" compacted for one: PHP counts the brackets of
     * a body, skipping the escaped ones, to find where it ends.
     */
    private static function balancedBrackets(string $body, string $opening, string $closing): string
    {
        $open = [];
        $unpaired = [];
        $length = \strlen($body);
        for ($i = 0; $i < $length; $i++) {
            $character = $body[$i];
            if ('\\' === $character) {
                $i += self::escapeLength($body, $i) - 1;
            } elseif ($closing === $character) {
                if ([] === $open) {
                    $unpaired[] = $i;
                } else {
                    array_pop($open);
                }
            } elseif ($opening === $character) {
                $open[] = $i;
            }
        }

        $unpaired = array_merge($unpaired, $open);
        rsort($unpaired);
        foreach ($unpaired as $offset) {
            $body = substr_replace($body, '\\', $offset, 0);
        }

        return $body;
    }

    /**
     * The length of the escape a backslash at $offset opens, as PCRE reads
     * it: two characters, or three for "\c", which takes the next one, a
     * backslash included.
     */
    private static function escapeLength(string $body, int $offset): int
    {
        return 'c' === ($body[$offset + 1] ?? '') ? 3 : 2;
    }

    /**
     * PHP finds the end of a pattern pairing each backslash with the
     * character after it, where PCRE reads "\c\" as one control character:
     * after it, PHP reads "\\" where PCRE reads a backslash escaping the
     * next character, and "\)" where PCRE reads ")". When the two readings
     * disagree on where the pattern ends, each "\c\" before a backslash or
     * a delimiter is spelled "\x1C", and a delimiter that would then end
     * the pattern is escaped, as PCRE read it as a literal.
     */
    private static function controlBackslashesSpelled(string $body, string $opening, string $closing): string
    {
        if (self::endsWhereWritten($body, $opening, $closing)) {
            return $body;
        }

        $spelled = '';
        $length = \strlen($body);
        for ($i = 0; $i < $length; $i++) {
            if ('\\' !== $body[$i]) {
                $spelled .= $body[$i];

                continue;
            }

            $next = $body[$i + 3] ?? '';
            if ('c' === ($body[$i + 1] ?? '') && '\\' === ($body[$i + 2] ?? '') && '' !== $next && str_contains('\\'.$opening.$closing, $next)) {
                $spelled .= '\\x1C'.($opening === $closing && '\\' !== $next ? '\\' : '');
                $i += 2;

                continue;
            }

            $escape = self::escapeLength($body, $i);
            $spelled .= substr($body, $i, $escape);
            $i += $escape - 1;
        }

        return $spelled;
    }

    /**
     * Whether PHP, pairing each backslash with the character after it, reads
     * the whole body before the closing delimiter: no closing delimiter
     * stands bare in it, or, for brackets, every one pairs.
     */
    private static function endsWhereWritten(string $body, string $opening, string $closing): bool
    {
        $depth = 0;
        $length = \strlen($body);
        for ($i = 0; $i < $length; $i++) {
            $character = $body[$i];
            if ('\\' === $character) {
                $i++;
            } elseif ($closing === $character) {
                if ($opening === $closing || 0 === $depth) {
                    return false;
                }

                $depth--;
            } elseif ($opening === $character) {
                $depth++;
            }
        }

        return 0 === $depth;
    }

    /**
     * What pretty printing writes for the backslash of a literal bracket of
     * the delimiter, null for any other character: the mark, then "Q" where
     * the bracket cannot stand unescaped, "(", ")" and "[" outside a class,
     * "]" in one. literalBracketsBalanced reads it back.
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
     * Pretty printing: PHP counts the brackets of the delimiter written as
     * is, a comment's, a name's, a group's or a class's, wherever they
     * stand. Those pair among themselves first; a literal bracket marked by
     * bracketMarkFor then comes back counted where it pairs with one left
     * alone, and escaped otherwise, which PHP does not count. Counted, it
     * stands unescaped, or quoted, "\Q(\E", where unescaped it would mean
     * something else: a "(", a ")" or a "[" outside a class, a "]" in one,
     * a "{" that would open a repeat, "{2}", "{,3}" or "{ 1, 2 }", or end
     * an escape, "\x{", a "[" before ":", "." or "=", which would open a
     * POSIX class.
     *
     * @param non-empty-string $mark
     */
    private function literalBracketsBalanced(string $body, string $mark): string
    {
        $tokens = [];
        $bare = [];
        $length = \strlen($body);
        $markLength = \strlen($mark);
        $repeat = '/\G\{[ \t]*+(?:[0-9]++[ \t]*+(?:,[ \t]*+[0-9]*+[ \t]*+)?|,[ \t]*+[0-9]++[ \t]*+)(?:'.preg_quote($mark, '/').'Q?)?\}/';
        $i = 0;
        while ($i < $length) {
            if (0 === substr_compare($body, $mark, $i, $markLength)) {
                $at = $i + $markLength;
                $quoted = 'Q' === $body[$at];
                $bracket = $body[$quoted ? $at + 1 : $at];
                $bare[$i] = !$quoted && match ($bracket) {
                    '{' => 1 !== LibraryPcre::match($repeat, $body, $matches, 0, $at)
                        && !($i >= 2 && '\\' === $body[$i - 2] && Ascii::isAlpha($body[$i - 1])),
                    '[' => !str_contains(':.=', $body[$at + 1] ?? ':'),
                    default => true,
                };
                $tokens[] = [$i, $this->delimiter === $bracket, true];
                $i = $quoted ? $at + 2 : $at + 1;

                continue;
            }

            // A backslash and the character it escapes are one step.
            $character = $body[$i];
            if ('\\' === $character) {
                $i += self::escapeLength($body, $i);

                continue;
            }

            if ($this->delimiter === $character || $this->closingDelimiter === $character) {
                $tokens[] = [$i, $this->delimiter === $character, false];
            }
            $i++;
        }

        // The brackets written as is, paired among themselves.
        $fixed = array_keys(array_filter($tokens, static fn (array $token): bool => !$token[2]));
        $fixedPartners = self::bracketPartners(array_map(static fn (int $index): bool => $tokens[$index][1], $fixed));
        $alone = [];
        foreach ($fixed as $position => $index) {
            if (null === $fixedPartners[$position]) {
                $alone[$index] = true;
            }
        }

        // A literal opening one goes with a closing one left alone after
        // it, a literal closing one with an opening one left alone before.
        $unescaped = [];
        $literalOpenings = [];
        $openingsAlone = 0;
        foreach ($tokens as $index => [$offset, $isOpening, $isLiteral]) {
            if ($isLiteral && $isOpening) {
                $literalOpenings[] = $offset;
            } elseif ($isLiteral && $openingsAlone > 0) {
                $openingsAlone--;
                $unescaped[$offset] = true;
            } elseif (isset($alone[$index]) && $isOpening) {
                $openingsAlone++;
            } elseif (isset($alone[$index]) && [] !== $literalOpenings) {
                $unescaped[array_pop($literalOpenings)] = true;
            }
        }

        $result = '';
        $from = 0;
        while (false !== ($at = strpos($body, $mark, $from))) {
            $bracketAt = $at + $markLength + ('Q' === $body[$at + $markLength] ? 1 : 0);
            $bracket = $body[$bracketAt];
            $result .= substr($body, $from, $at - $from).match (true) {
                !isset($unescaped[$at]) => '\\'.$bracket,
                $bare[$at] => $bracket,
                default => '\\Q'.$bracket.'\\E',
            };
            $from = $bracketAt + 1;
        }

        return $result.substr($body, $from);
    }

    /**
     * A mark the text does not hold: a control byte, or when the text holds
     * them all, "\x03" followed by as many "\x04" as it takes. Its first
     * byte occurs nowhere else in it, so no text before it can end in a
     * piece of it that the mark completes.
     *
     * @return non-empty-string
     */
    private static function markAbsentFrom(string $text): string
    {
        $absent = str_replace(Bytes::split(count_chars($text, 3)), '', "\x03\x04\x05\x06\x07\x08\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F");
        if ('' !== $absent) {
            return $absent[0];
        }

        $mark = "\x03\x04";
        while (str_contains($text, $mark)) {
            $mark .= "\x04";
        }

        return $mark;
    }

    /**
     * The width of a piece of pattern: code points under UTF, bytes
     * otherwise.
     */
    private function columns(string $text): int
    {
        return $this->countsCodePoints ? mb_strlen($text, 'UTF-8') : \strlen($text);
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
