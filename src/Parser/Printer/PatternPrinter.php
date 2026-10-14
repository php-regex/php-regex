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
use PHPRegex\Parser\Internal\Ascii;
use PHPRegex\Parser\Internal\InlineFlags;
use PHPRegex\Parser\Internal\LibraryPcre;
use PHPRegex\Parser\Internal\StartOptions;
use PHPRegex\Parser\Internal\StaticCaches;
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

    private const INDENT = '    ';

    /**
     * Where a trailing comment starts on a line, until the comments of
     * consecutive lines are aligned on one column.
     */
    private const TRAIL = "\x01TRAILING-COMMENT\x01";

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

    // Intelligent delimiter mapping cache
    /**
     * @var array<string, string>
     */
    private static array $delimiterCache = [];

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
        $this->layout = false;
        $this->countsCodePoints = false;
        $this->sawLineComment = false;
        $this->refusal = null;
        $this->parentBroke = false;
        $this->skipNode = null;
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
        $this->closingDelimiter = $this->getClosingDelimiter($node->delimiter);
        $this->source = $this->collapseExtendedComments || !$this->preserveSpelling
            ? null
            : $node->source;
        $this->quotedSpans = null;

        $body = $node->delimiter
            .$this->ignorableText(0, $node->pattern->getStartPosition())
            .$node->pattern->accept($this)
            .$this->ignorableText($node->pattern->getEndPosition(), null);

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
            $result = $this->joinItems($result, $children[$i - 1], $children[$i], $children[$i]->accept($this));
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
                    default => '\\x'.strtoupper(str_pad(dechex($ord), 2, '0', \STR_PAD_LEFT)),
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
        if (self::MODE_COMPACT === $this->mode || $node === $this->skipNode) {
            return '';
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
        return '(?#'.$node->comment.')';
    }

    #[\Override]
    public function visitConditional(ConditionalNode $node): string
    {
        if ($this->layout) {
            return $this->layoutConditional($node);
        }

        $condition = $this->conditionText($node);
        $yes = $node->yes->accept($this);
        $no = $node->no->accept($this);

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
        // the long form.
        $written = $this->writtenText($node);
        if (null !== $written && $this->namesTheSameVerb($written, $node->verb)) {
            return $written;
        }

        return $compiled;
    }

    #[\Override]
    public function visitDefine(DefineNode $node): string
    {
        if ($this->layout) {
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

            return '(?(DEFINE)'.$this->bodyLine($content, $level + 1).$content."\n".self::indent($level).')';
        }

        return '(?(DEFINE)'.$node->content->accept($this).')';
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
        // pattern used is kept when it still carries this text.
        $written = $this->writtenText($node);
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
     */
    private function writtenText(NodeInterface $node): ?string
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
        foreach ($this->quotedSpans() as [$from, $to]) {
            if ($start < $to && $from < $start + $length) {
                return null;
            }
        }

        $written = substr($this->source, $start, $length);

        return self::MODE_OFF === $this->mode || !$this->isSkippedByX($written) ? $written : null;
    }

    /**
     * Whether written text holds what x would skip or read as a comment
     * where the formatter writes it: such text is spelled from the tree
     * instead, with escapes. In a laid-out pattern every line break is
     * layout, so a raw one written in a class or a quoted run is spelled as
     * an escape too.
     */
    private function isSkippedByX(string $written): bool
    {
        if (!str_contains($this->flags, 'x')) {
            return false;
        }

        if (self::MODE_FORMAT === $this->mode && false !== strpbrk($written, "\n\r\t\v\f")) {
            return true;
        }

        if ($this->inCharClass || $this->inExtendedClass) {
            return false;
        }

        if (1 === LibraryPcre::match('/[\s#]/', $written)) {
            return true;
        }

        // Under UTF, x also skips NEL, LRM, RLM, LS and PS; in byte mode it
        // skips byte 0x85 too, even inside a UTF-8 sequence such as "Å".
        return $this->utfVerb || str_contains($this->flags, 'u')
            ? 1 === LibraryPcre::match('/[\x{85}\x{200E}\x{200F}\x{2028}\x{2029}]/u', $written)
            : str_contains($written, "\x85");
    }

    /**
     * A character as an operand of "(?[...])", where only an escape stands on
     * its own: "\!" for punctuation, "\x{e9}" or "\x41" for the rest.
     */
    private function extendedClassOperand(string $value): string
    {
        $unicodeMode = $this->utfVerb || str_contains($this->flags, 'u');
        $characters = $unicodeMode ? mb_str_split($value, 1, 'UTF-8') : str_split($value);

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

        $spans = [];
        $source = (string) $this->source;
        $offset = 0;

        while (false !== ($start = strpos($source, '\\Q', $offset))) {
            $end = strpos($source, '\\E', $start + 2);
            $stop = false === $end ? \strlen($source) : $end + 2;
            $spans[] = [$start, $stop];
            $offset = $stop;
        }

        return $this->quotedSpans = $spans;
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
     * Two neighbouring items. A "\E" or an empty "\Q\E" between them is
     * dropped like any other no-op, unless the two items would then read as
     * one: "(a)\1\E0" is not "(a)\10", nor "a{\E2}" the repeat "a{2}". There
     * it stays as written. When no source says how two items were separated,
     * a digit escape still needs something after it, and an empty group ("\E"
     * in a class) takes that place; a literal "{" or "[" comes back escaped
     * and needs nothing.
     */
    private function joinItems(string $compiled, NodeInterface $left, NodeInterface $right, string $next): string
    {
        $between = $this->ignorableTextBetween($left, $right);
        if ('' !== $between || '' === $next) {
            return $compiled.$between.$next;
        }

        // Under x a rewritten pattern separates the two with a space, which
        // reads as nothing: "\1 0" is the reference, then "0".
        if (self::takesMoreDigits($compiled, $next[0])) {
            $between = $this->writtenSeparator($left->getEndPosition(), $right->getStartPosition())
                ?? ($this->inCharClass ? '\\E' : (self::MODE_OFF !== $this->mode && str_contains($this->flags, 'x') ? ' ' : '(?:)'));
        } elseif ($this->opensConstruct($compiled, $next[0])) {
            $between = $this->writtenSeparator($left->getEndPosition(), $right->getStartPosition())
                ?? ($this->separatesDroppedText($left, $right) ? '(?:)' : '');
        }

        return $compiled.$between.$next;
    }

    /**
     * Whether a rewritten pattern dropped something between two items, a
     * comment or the whitespace x skipped: "a{(?#c),2}" without its comment
     * would read "{,2}" as a repeat, which PCRE2 does from 10.43 on.
     */
    private function separatesDroppedText(NodeInterface $left, NodeInterface $right): bool
    {
        return self::MODE_OFF !== $this->mode && !$this->inCharClass
            && ($right->getStartPosition() > $left->getEndPosition() || $left instanceof CommentNode);
    }

    /**
     * Whether the text ends with an escape the next character would make
     * longer: a reference or octal escape ("\1", "\g-1", "\0") before a
     * digit, or a "\x" with fewer than two digits before a hex digit.
     */
    private static function takesMoreDigits(string $compiled, string $next): bool
    {
        if (1 !== LibraryPcre::match('/(?<!\\\\)(?:\\\\\\\\)*+\\\\(?<escape>[0-9]+|g[+-]?[0-9]+|x[0-9A-Fa-f]?)\z/', $compiled, $matches)) {
            return false;
        }

        return str_starts_with($matches['escape'], 'x') ? Ascii::isHexDigit($next) : Ascii::isDigit($next);
    }

    /**
     * Whether the text ends with something the next character would turn
     * into another construct: "\N" before braces, a literal "{" before a
     * repeat count, a literal "[" in a class before ":", "." or "=".
     */
    private function opensConstruct(string $compiled, string $next): bool
    {
        if ($this->inCharClass) {
            return \in_array($next, [':', '.', '='], true) && 1 === LibraryPcre::match('/(?<!\\\\)(?:\\\\\\\\)*+\[\z/', $compiled);
        }

        if ('{' === $next) {
            return 1 === LibraryPcre::match('/(?<!\\\\)(?:\\\\\\\\)*+\\\\N\z/', $compiled);
        }

        return str_contains("0123456789, \t}", $next) && 1 === LibraryPcre::match('/(?<!\\\\)(?:\\\\\\\\)*+\{[0-9, \t]*+\z/', $compiled);
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
        return GroupType::InlineFlags === $node->type
            && '' === $child
            && $node->getEndPosition() - $node->getStartPosition() === \strlen($flags) + 3;
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

        if (GroupType::InlineFlags !== $node->type || '' !== $child) {
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

        return $flags?->applyTo($current) ?? $current;
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
     * Intelligent delimiter mapping with caching.
     */
    private function getClosingDelimiter(string $delimiter): string
    {
        // One entry per delimiter byte at most.
        if (!isset(self::$delimiterCache[$delimiter])) {
            StaticCaches::register(self::class, self::clearCaches(...));
            self::$delimiterCache[$delimiter] = match ($delimiter) {
                '(' => ')',
                '[' => ']',
                '{' => '}',
                '<' => '>',
                default => $delimiter,
            };
        }

        return self::$delimiterCache[$delimiter];
    }

    private static function clearCaches(): void
    {
        self::$delimiterCache = [];
    }

    /**
     * String escaping with minimal allocations.
     */
    private function escapeString(string $value): string
    {
        $meta = $this->inCharClass ? self::CHAR_CLASS_META : self::META_CHARACTERS;
        $escapeExtended = str_contains($this->flags, 'x') && !$this->inCharClass;
        $unicodeMode = $this->utfVerb || str_contains($this->flags, 'u');
        // In UTF mode "x" also skips these written raw.
        $skipped = $escapeExtended && $unicodeMode ? self::UTF_PATTERN_WHITE_SPACE : [];
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
                || ($this->inClassOfExtendedClass && ' ' === $char)
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
                || ($this->inClassOfExtendedClass && ' ' === $char)
            ) {
                $result .= '\\'.$char;
            } elseif (\ord($char) < 32 || 127 === \ord($char) || (!$unicodeMode && \ord($char) >= 128)) {
                // Escape control characters and extended ASCII
                $result .= match (\ord($char)) {
                    8 => $this->inCharClass ? '\\b' : '\\x08', // Backspace: \b only valid inside char class
                    9 => '\\t',
                    10 => '\\n',
                    13 => '\\r',
                    12 => '\\f',
                    27 => '\\e',
                    default => '\\x'.strtoupper(str_pad(dechex(\ord($char)), 2, '0', \STR_PAD_LEFT)),
                };
            } else {
                $result .= $char;
            }
        }

        return [] === $skipped ? $result : strtr($result, $skipped);
    }

    /**
     * @param non-empty-array<NodeInterface> $members
     */
    private function compileCharClassMembers(array $members): string
    {
        $members = array_values($members);
        $result = $this->compileCharClassNode($members[0], $members[1] ?? null);
        for ($i = 1, $count = \count($members); $i < $count; $i++) {
            $next = $this->compileCharClassNode($members[$i], $members[$i + 1] ?? null);
            $result = $this->joinItems($result, $members[$i - 1], $members[$i], $next);
        }

        return $result;
    }

    private function compileCharClassNode(NodeInterface $node, ?NodeInterface $next): string
    {
        if ($node instanceof LiteralNode && '[' === $node->value) {
            return $this->writtenText($node) ?? ($this->shouldEscapeCharClassOpen($next) ? '\\[' : '[');
        }

        if ($node instanceof RangeNode) {
            $start = $node->start;
            $startCompiled = $start instanceof LiteralNode && '[' === $start->value
                ? '['
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
        $this->delimiter = $node->delimiter;
        $this->closingDelimiter = $this->getClosingDelimiter($node->delimiter);
        // Pretty printing normalizes the spelling: it writes from the tree.
        $this->source = self::MODE_PRETTY === $this->mode ? null : $node->source;
        $this->quotedSpans = null;
        $options = self::startOptions($node->pattern);
        $this->utfVerb = \in_array('UTF', $options, true) || \in_array('UTF8', $options, true);
        $this->countsCodePoints = $this->utfVerb || str_contains($node->flags, 'u');

        if (self::MODE_COMPACT === $this->mode) {
            return $this->renderRoot($node, str_replace('x', '', $node->flags), false);
        }

        $shallow = self::groupDepth($node->pattern) <= self::MAX_DEPTH;
        $written = null === $this->source ? null : $node->delimiter.$this->source.$this->closingDelimiter.$node->flags;
        if (null !== $written && !str_contains($written, "\n") && $shallow && $this->columns($written) <= $this->width) {
            return $written;
        }

        $inline = $this->renderRoot($node, $node->flags, false);
        $hasLineComment = $this->sawLineComment;
        if (!$hasLineComment && $shallow && $this->columns($inline) <= $this->width) {
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
        $refusal = self::refusalFor($node, $flags, $hasLineComment, $options);
        if (null !== $refusal) {
            if (self::MODE_PRETTY === $this->mode) {
                return $inline;
            }

            $this->refusal = $refusal;

            return $written ?? $inline;
        }

        return $this->finishLines($this->renderRoot($node, $flags, true));
    }

    /**
     * Why laying the pattern out under x would change what it matches, if
     * it would.
     *
     * A "#" comment runs on past LF under (*CR), (*CRLF) and (*NUL). Before
     * PCRE2 10.43 "a{ 1,2 }" is a literal that an added x would turn into a
     * quantifier. After a top-level "(?-x)" or "(?^)" a line break is a
     * literal.
     *
     * @param list<string> $options the start-of-pattern options
     */
    private static function refusalFor(RegexNode $node, string $flags, bool $hasLineComment, array $options): ?FormatRefusal
    {
        $newline = 'LF';
        foreach ($options as $option) {
            if (\in_array($option, ['CR', 'LF', 'CRLF', 'ANYCRLF', 'ANY', 'NUL'], true)) {
                $newline = $option;
            }
        }

        return match (true) {
            !str_contains($node->flags, 'x') && self::hasSpacedQuantifier($node->pattern) => FormatRefusal::SpacedQuantifier,
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
            $pattern = array_values($pattern->alternatives)[0];
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
                if ($item instanceof GroupNode && GroupType::InlineFlags === $item->type && self::isEmpty($item->child)
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

    private static function hasSpacedQuantifier(NodeInterface $node): bool
    {
        if ($node instanceof QuantifierNode && 1 === LibraryPcre::match('/\s/', $node->quantifier)) {
            return true;
        }

        foreach ($node->getChildren() as $child) {
            if (self::hasSpacedQuantifier($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * How many groups the deepest path through a node nests. Lookarounds,
     * conditionals, "(?|", atomic groups and DEFINE count; a setting such as
     * "(?i)" and a class do not.
     */
    private static function groupDepth(NodeInterface $node): int
    {
        if ($node instanceof CharClassNode || $node instanceof ExtendedCharClassNode) {
            return 0;
        }

        $own = ($node instanceof GroupNode && !(GroupType::InlineFlags === $node->type && self::isEmpty($node->child)))
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
            // on the line of the opening delimiter.
            $options = StartOptions::of($body);
            if ('' !== $options) {
                $rest = substr($body, \strlen($options));
                $body = $options."\n".self::INDENT.(str_starts_with($rest, "\n".self::INDENT) ? substr($rest, 1 + \strlen(self::INDENT)) : $rest);
            } elseif (!str_starts_with($body, "\n")) {
                $body = "\n".self::INDENT.$body;
            }

            $tail = str_contains($this->flags, 'x') ? "\n" : '';
        }

        $body = $node->delimiter.$body;
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
            || (!str_contains($text, "\n") && $this->fits($text) && self::groupDepth($node) <= self::MAX_DEPTH))) {
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
        $endsInEscapedSpace = 1 === LibraryPcre::match('/(?<!\\\\)(?:\\\\\\\\)*+\\\\ \z/', $text);

        return \strlen(self::INDENT) * $this->indentLevel + $this->columns($text) + ($endsInEscapedSpace ? 2 : 0) <= $this->width;
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
                $text = $this->joinItems($text, $items[$i - 1], $items[$i], $items[$i]->accept($this));
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
     * @param array<NodeInterface> $alternatives
     */
    private function layoutAlternation(array $alternatives): string
    {
        $alternatives = array_values($alternatives);
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
            if (null !== $lead && !$previousLast instanceof CommentNode && !str_ends_with(rtrim($result), "\n")
                && !str_contains(substr($result, (int) strrpos($result, "\n")), self::TRAIL)) {
                $this->skipNode = $lead;
                $result .= self::TRAIL.rtrim($lead->comment, "\n");
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
     * A broken sequence: runs of atoms together on a line, each group on
     * lines of its own, each "#" comment where its author put it.
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
        $current = '';
        $previous = null;
        $glued = false;
        foreach ($node->children as $child) {
            if ($child === $this->skipNode) {
                $this->skipNode = null;
                $previous = $child;

                continue;
            }

            // Once x is off, nothing may be split any more.
            if ($glued || !str_contains($this->flags, 'x')) {
                $glued = true;
                $text = $this->forceInline($child);
                $current = '' === $current || null === $previous ? $text : $this->joinItems($current, $previous, $child, $text);
                $previous = $child;

                continue;
            }

            $gap = null === $previous ? '' : $this->gap($previous, $child);
            $blank = null !== $previous && substr_count($gap, "\n") + ($previous instanceof CommentNode && str_ends_with($previous->comment, "\n") ? 1 : 0) >= 2;

            if ($child instanceof CommentNode && $child->extended) {
                $text = $child->accept($this);
                $trailing = null !== $previous && !$previous instanceof CommentNode && !str_contains($gap, "\n");
                if ('' !== $current) {
                    $lines[] = $current;
                    $current = '';
                }

                if ($blank && [] !== $lines) {
                    $lines[] = '';
                }

                if ($trailing && [] !== $lines && '' !== $lines[\count($lines) - 1]) {
                    $lines[\count($lines) - 1] .= self::TRAIL.$text;
                } else {
                    $lines[] = $text;
                }

                $previous = $child;

                continue;
            }

            $isBlock = self::isBlock($child);
            if ($isBlock || $blank || ($previous instanceof CommentNode && '' === $current)) {
                if ('' !== $current) {
                    $lines[] = $current;
                    $current = '';
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

            $text = $this->forceInline($child);
            $current = '' === $current || null === $previous ? $text : $this->joinItems($current, $previous, $child, $text);
            $previous = $child;
        }

        if ('' !== $current) {
            $lines[] = $current;
        }

        $indent = self::indent($this->indentLevel);
        $out = '';
        foreach ($lines as $i => $line) {
            $out .= 0 === $i ? $line : ('' === $line ? "\n" : "\n".$indent.$line);
        }

        return $out;
    }

    /**
     * A sequence whose only comment closes it, on the line of what precedes
     * it: the rest stays on one line when it fits, the comment trailing it.
     */
    private function inlineWithTrailingComment(SequenceNode $node): ?string
    {
        $children = array_values($node->children);
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

        if (!$this->sawLineComment && !str_contains($text, "\n") && $this->fits($text) && $deepest <= self::MAX_DEPTH) {
            $this->sawLineComment = true;

            return $text.self::TRAIL.rtrim($last->comment, "\n");
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
            $trailing = self::TRAIL.rtrim($lead->comment, "\n");
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
        $hasNo = !($node->no instanceof LiteralNode && '' === $node->no->value);
        $no = $node->no->accept($this);
        $this->indentLevel--;

        $text = '(?'.$condition.$this->bodyLine($yes, $level + 1).$yes;
        if ('' !== $no || $hasNo) {
            $text .= "\n".str_repeat(' ', ($level + 1) * \strlen(self::INDENT) - 2).'|'.('' === $no ? '' : ' '.$no);
        }

        return $text.(str_contains($this->flags, 'x') ? "\n".self::indent($level) : '').')';
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
            $first = array_values($children)[0] ?? null;
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
            if (!str_contains($lines[$i], self::TRAIL)) {
                if (1 === LibraryPcre::match('/(?<!\\\\)(?:\\\\\\\\)*+\\\\ \z/', $lines[$i])) {
                    $lines[$i] = substr($lines[$i], 0, -2).'\\x20';
                }

                continue;
            }

            $column = 0;
            for ($j = $i; $j < $count && str_contains($lines[$j], self::TRAIL); $j++) {
                $column = max($column, $this->columns(explode(self::TRAIL, $lines[$j], 2)[0]));
            }

            for ($k = $i; $k < $j; $k++) {
                [$code, $comment] = explode(self::TRAIL, $lines[$k], 2) + [1 => ''];
                $lines[$k] = $code.str_repeat(' ', $column - $this->columns($code) + 2).$comment;
            }

            $i = $j - 1;
        }

        return implode("\n", $lines);
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
