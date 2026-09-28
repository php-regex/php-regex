<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RegexParser\NodeVisitor;

use RegexParser\Internal\InlineFlags;
use RegexParser\Node\AlternationNode;
use RegexParser\Node\AnchorNode;
use RegexParser\Node\AssertionNode;
use RegexParser\Node\BackrefNode;
use RegexParser\Node\CalloutNode;
use RegexParser\Node\CharClassNode;
use RegexParser\Node\CharLiteralNode;
use RegexParser\Node\CharTypeNode;
use RegexParser\Node\ClassOperationNode;
use RegexParser\Node\ClassOperationType;
use RegexParser\Node\CommentNode;
use RegexParser\Node\ConditionalNode;
use RegexParser\Node\ControlCharNode;
use RegexParser\Node\DefineNode;
use RegexParser\Node\DotNode;
use RegexParser\Node\GroupNode;
use RegexParser\Node\GroupType;
use RegexParser\Node\KeepNode;
use RegexParser\Node\LimitMatchNode;
use RegexParser\Node\LiteralNode;
use RegexParser\Node\NodeInterface;
use RegexParser\Node\PcreVerbNode;
use RegexParser\Node\PosixClassNode;
use RegexParser\Node\QuantifierNode;
use RegexParser\Node\QuantifierType;
use RegexParser\Node\RangeNode;
use RegexParser\Node\RegexNode;
use RegexParser\Node\ScriptRunNode;
use RegexParser\Node\SequenceNode;
use RegexParser\Node\SubroutineNode;
use RegexParser\Node\UnicodePropNode;
use RegexParser\Node\VersionConditionNode;

/**
 * Compiler that recompiles regex AST back into optimized strings.
 *
 * This visitor provides compilation with caching and
 * streamlined string building for efficiency while maintaining
 * full PCRE compatibility.
 *
 * @extends AbstractNodeVisitor<string>
 */
final class CompilerNodeVisitor extends AbstractNodeVisitor
{
    /**
     * The flags a non-atomic lookaround carries: "(?*...)" and "(?<*...)".
     */
    private const NON_ATOMIC_FLAG = '*';

    // Optimized meta-character sets for fast lookups
    private const META_CHARACTERS = [
        '\\' => true, '.' => true, '^' => true, '$' => true,
        '[' => true, ']' => true, '(' => true, ')' => true,
        '|' => true, '*' => true, '+' => true, '?' => true, '{' => true, '}' => true,
    ];

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

    public function __construct(
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
    }

    public function resetState(): void
    {
        $this->inCharClass = false;
        $this->delimiter = '/';
        $this->closingDelimiter = '/';
        $this->flags = '';
        $this->utfVerb = false;
        $this->indentLevel = 0;
        $this->source = null;
        $this->quotedSpans = null;
    }

    #[\Override]
    public function visitRegex(RegexNode $node): string
    {
        $this->delimiter = $node->delimiter;
        $this->flags = $node->flags;
        $this->utfVerb = self::startsWithUtfVerb($node->pattern);
        $this->closingDelimiter = $this->getClosingDelimiter($node->delimiter);
        $this->source = $this->pretty || $this->collapseExtendedComments || !$this->preserveSpelling
            ? null
            : $node->source;
        $this->quotedSpans = null;

        $body = $node->pattern->accept($this);

        return $node->delimiter
            .$this->ignorableText(0, $node->pattern->getStartPosition())
            .$body
            .$this->ignorableText($node->pattern->getEndPosition(), null)
            .$this->closingDelimiter
            .$node->flags;
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
            $result = $this->compileCharClassNode($alternatives[0], $alternatives[1] ?? null);
            for ($i = 1, $count = \count($alternatives); $i < $count; $i++) {
                $result .= $this->compileCharClassNode($alternatives[$i], $alternatives[$i + 1] ?? null);
            }

            return $result;
        }

        if ($this->pretty) {
            $result = $alternatives[0]->accept($this);
            for ($i = 1, $count = \count($alternatives); $i < $count; $i++) {
                $this->indentLevel++;
                $alt = $alternatives[$i]->accept($this);
                $this->indentLevel--;
                $result .= "\n".str_repeat(' ', $this->indentLevel * 4).'| '.$alt;
            }

            return $result;
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
            $result = $this->compileCharClassNode($children[0], $children[1] ?? null);
            for ($i = 1, $count = \count($children); $i < $count; $i++) {
                $result .= $this->compileCharClassNode($children[$i], $children[$i + 1] ?? null);
            }

            return $result;
        }

        $result = $this->ignorableText($node->getStartPosition(), $children[0]->getStartPosition());
        $result .= $children[0]->accept($this);

        for ($i = 1, $count = \count($children); $i < $count; $i++) {
            $result .= $this->ignorableTextBetween($children[$i - 1], $children[$i]);
            $result .= $children[$i]->accept($this);
        }

        $last = $children[\count($children) - 1];

        return $result.$this->ignorableText($last->getEndPosition(), $node->getEndPosition());
    }

    #[\Override]
    public function visitGroup(GroupNode $node): string
    {
        $flags = $node->flags ?? '';

        if ($this->pretty) {
            $opening = match ($node->type) {
                GroupType::T_GROUP_CAPTURING => '(',
                GroupType::T_GROUP_NON_CAPTURING => '(?:',
                GroupType::T_GROUP_NAMED => $node->usePythonSyntax
                    ? '(?P<'.$node->name.'>'
                    : '(?<'.$node->name.'>',
                GroupType::T_GROUP_LOOKAHEAD_POSITIVE => self::NON_ATOMIC_FLAG === $flags ? '(?*' : '(?=',
                GroupType::T_GROUP_LOOKAHEAD_NEGATIVE => '(?!',
                GroupType::T_GROUP_LOOKBEHIND_POSITIVE => self::NON_ATOMIC_FLAG === $flags ? '(?<*' : '(?<=',
                GroupType::T_GROUP_LOOKBEHIND_NEGATIVE => '(?<!',
                GroupType::T_GROUP_ATOMIC => '(?>',
                GroupType::T_GROUP_BRANCH_RESET => '(?|',
                GroupType::T_GROUP_INLINE_FLAGS => '(?'.$flags.':',
            };
            $closing = ')';
            $this->indentLevel++;
            $child = $this->compileGroupChild($node, $flags);
            $this->indentLevel--;
            $indent = str_repeat(' ', $this->indentLevel * 4);

            return $indent.$opening."\n".$child."\n".$indent.$closing;
        }

        $child = $this->compileGroupChild($node, $flags);

        if (GroupType::T_GROUP_INLINE_FLAGS === $node->type && '' === $child) {
            return '(?'.$flags.')';
        }

        $opening = match ($node->type) {
            GroupType::T_GROUP_CAPTURING => '(',
            GroupType::T_GROUP_NON_CAPTURING => '(?:',
            GroupType::T_GROUP_NAMED => $node->usePythonSyntax
                ? '(?P<'.$node->name.'>'
                : '(?<'.$node->name.'>',
            GroupType::T_GROUP_LOOKAHEAD_POSITIVE => self::NON_ATOMIC_FLAG === $flags ? '(?*' : '(?=',
            GroupType::T_GROUP_LOOKAHEAD_NEGATIVE => '(?!',
            GroupType::T_GROUP_LOOKBEHIND_POSITIVE => self::NON_ATOMIC_FLAG === $flags ? '(?<*' : '(?<=',
            GroupType::T_GROUP_LOOKBEHIND_NEGATIVE => '(?<!',
            GroupType::T_GROUP_ATOMIC => '(?>',
            GroupType::T_GROUP_BRANCH_RESET => '(?|',
            GroupType::T_GROUP_INLINE_FLAGS => '(?'.$flags.':',
        };

        $opening = $this->openingAsWritten($node, $opening);

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
            QuantifierType::T_LAZY => '?',
            QuantifierType::T_POSSESSIVE => '+',
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
    public function visitCharClass(CharClassNode $node): string
    {
        $wasInCharClass = $this->inCharClass;
        $this->inCharClass = true;

        try {
            $negation = $node->isNegated ? '^' : '';

            return '['.$negation.$node->expression->accept($this).']';
        } finally {
            $this->inCharClass = $wasInCharClass;
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
        $compiled = ctype_digit($node->ref) ? '\\'.$node->ref : $node->ref;

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

    /**
     * @deprecated the parser no longer builds a ClassOperationNode; this method goes in the next major version
     */
    #[\Override]
    public function visitClassOperation(ClassOperationNode $node): string
    {
        return $node->left->accept($this).(ClassOperationType::INTERSECTION === $node->type ? '&&' : '--').$node->right->accept($this);
    }

    #[\Override]
    public function visitControlChar(ControlCharNode $node): string
    {
        return '\\c'.$node->char;
    }

    #[\Override]
    public function visitScriptRun(ScriptRunNode $node): string
    {
        // "(*sr:...)" is the same verb written short.
        $written = $this->writtenText($node);
        if (null !== $written && \in_array($written, ['(*sr:'.$node->script.')', '(*script_run:'.$node->script.')'], true)) {
            return $written;
        }

        return '(*script_run:'.$node->script.')';
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
        // preserved as real /x comments, not rewritten into (?#...) blocks.
        // We still indent them when pretty-printing so they line up with
        // surrounding constructs, but we keep the original "# ..." text and
        // trailing newline intact.
        if ($isExtended && ($node->extended || str_starts_with($node->comment, '#'))) {
            if ($this->pretty) {
                $indent = str_repeat(' ', $this->indentLevel * 4);
                $lines = explode("\n", rtrim($node->comment, "\n"));
                $formatted = [];
                foreach ($lines as $line) {
                    $formatted[] = $indent.$line;
                }

                return implode("\n", $formatted)."\n";
            }

            return $node->comment;
        }

        // Multi-line inline comments from (?# ... ) are rendered as a block of
        // "# "-prefixed lines for readability when pretty-printing. This is
        // only used outside of extended mode so we don't change semantics.
        if ($this->pretty && str_contains($node->comment, "\n")) {
            $indent = str_repeat(' ', $this->indentLevel * 4);
            $lines = explode("\n", rtrim($node->comment, "\n"));
            $formatted = [];
            foreach ($lines as $line) {
                $formatted[] = $indent.'# '.$line;
            }

            return implode("\n", $formatted)."\n";
        }

        // Single-line inline comments that already start with '#' can be
        // indented in pretty mode for nicer alignment.
        if ($this->pretty && str_starts_with($node->comment, '#')) {
            $indent = str_repeat(' ', $this->indentLevel * 4);

            return $indent.$node->comment;
        }

        // Inline comments (?#...) keep their original content without the
        // delimiters and are reconstructed using standard PCRE syntax.
        return '(?#'.$node->comment.')';
    }

    #[\Override]
    public function visitConditional(ConditionalNode $node): string
    {
        if ($node->condition instanceof BackrefNode) {
            $cond = $node->condition->ref;
        } elseif ($node->condition instanceof SubroutineNode) {
            // "(?(R)...)" asks whether the pattern is recursing; it is not a
            // call, so the reference is written on its own. Compiling it as
            // "(?((?R))...)" gives a pattern PCRE refuses.
            $cond = $node->condition->reference;
        } else {
            $cond = $node->condition->accept($this);
        }

        $yes = $node->yes->accept($this);
        $no = $node->no->accept($this);

        // An assertion condition brings its own parentheses: PCRE spells it
        // "(?(?<!x)yes|no)", not "(?((?<!x))yes|no)".
        $condition = $this->isAssertionCondition($node->condition) ? $cond : '('.$cond.')';

        if ($this->pretty) {
            $indent = str_repeat(' ', $this->indentLevel * 4);
            if ('' === $no) {
                return $indent.'(?'.$condition."\n".$yes."\n".$indent.')';
            }

            return $indent.'(?'.$condition."\n".$yes."\n".$indent.'|'.$no."\n".$indent.')';
        }

        if ('' === $no) {
            return '(?'.$condition.$yes.')';
        }

        return '(?'.$condition.$yes.'|'.$no.')';
    }

    #[\Override]
    public function visitSubroutine(SubroutineNode $node): string
    {
        return match ($node->syntax) {
            '&' => '(?&'.$node->reference.')',
            'P>' => '(?P>'.$node->reference.')',
            'g' => '\g<'.$node->reference.'>',
            default => '(?'.$node->reference.')',
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
        if ($this->pretty) {
            $this->indentLevel++;
            $content = $node->content->accept($this);
            $this->indentLevel--;
            $indent = str_repeat(' ', $this->indentLevel * 4);

            return $indent."(?(DEFINE)\n".$content."\n".$indent.')';
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
            && preg_match('/^[A-Z_a-z]\w*+$/', $node->identifier)
        ) {
            return '(?C'.$node->identifier.')';
        }

        return '(?C"'.$node->identifier.'")';
    }

    /**
     * Whether the pattern opens with a (*UTF) or (*UTF8) setting, possibly
     * after other start-of-pattern settings.
     */
    private static function startsWithUtfVerb(NodeInterface $pattern): bool
    {
        $nodes = $pattern instanceof SequenceNode ? $pattern->children : [$pattern];
        foreach ($nodes as $node) {
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
        if (1 !== preg_match('/^\(\*(.*)\)$/s', $written, $matches)) {
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
    private function openingAsWritten(GroupNode $node, string $opening): string
    {
        // Under "n", "(...)" does not capture: it is read as "(?:...)" and
        // written back with the "(" the pattern used.
        if (GroupType::T_GROUP_NON_CAPTURING === $node->type && null !== $this->source
            && str_contains($this->flags, 'n')
            && '(' === ($this->source[$node->getStartPosition()] ?? '')
            && !\in_array($this->source[$node->getStartPosition() + 1] ?? '', ['?', '*'], true)) {
            return '(';
        }

        if (null === $this->source || GroupType::T_GROUP_NAMED !== $node->type || null === $node->name) {
            return $opening;
        }

        $start = $node->getStartPosition();
        $length = $node->child->getStartPosition() - $start;
        if ($length <= 0 || $start < 0 || $start + $length > \strlen($this->source)) {
            return $opening;
        }

        $written = substr($this->source, $start, $length);
        $quoted = preg_quote($node->name, '/');

        // Only a spelling of this very name is taken back; anything else means
        // the offsets no longer line up with the source.
        return 1 === preg_match('/^\(\?P?(?:<'.$quoted.'>|\''.$quoted.'\'|"'.$quoted.'")$/', $written)
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

        return substr($this->source, $start, $length);
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
        return preg_replace('/\\\\([^a-zA-Z0-9])/', '$1', $text) ?? $text;
    }

    /**
     * The group a backreference points at, whatever syntax spells it.
     */
    private function referenceName(string $reference): ?string
    {
        $matches = [];
        $syntax = '/^(?:\\(\\?P=|\\\\k[<{\']?|\\\\g[<{\']?|\\\\)([A-Za-z_][A-Za-z0-9_]*|[0-9]+)/';

        return 1 === preg_match($syntax, $reference, $matches) ? $matches[1] : null;
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
        if (1 === preg_match('/^\\\\[xu]\\{?([0-9a-fA-F]{1,8})\\}?$/', $text, $matches)) {
            return (int) hexdec($matches[1]);
        }

        if (1 === preg_match('/^\\\\(?:o\\{([0-7]+)\\}|([0-7]{1,3}))$/', $text, $matches)) {
            return (int) octdec($matches[1] ?: ($matches[2] ?? ''));
        }

        if (1 === preg_match('/^.$/us', $text)) {
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
            GroupType::T_GROUP_LOOKAHEAD_POSITIVE,
            GroupType::T_GROUP_LOOKAHEAD_NEGATIVE,
            GroupType::T_GROUP_LOOKBEHIND_POSITIVE,
            GroupType::T_GROUP_LOOKBEHIND_NEGATIVE,
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
        if (null === $this->source) {
            return ['', ''];
        }

        $start = $left->getEndPosition();
        $length = $right->getStartPosition() - $start;
        if ($length <= 0 || $start < 0 || $start + $length > \strlen($this->source)) {
            return ['', ''];
        }

        $text = substr($this->source, $start, $length);

        return 1 === preg_match('/^(\s*)\|(\s*)$/', $text, $matches) ? [$matches[1], $matches[2]] : ['', ''];
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
        if (null === $this->source) {
            return '';
        }

        $end ??= \strlen($this->source);
        $length = $end - $start;
        if ($length <= 0 || $start < 0 || $end > \strlen($this->source)) {
            return '';
        }

        $text = substr($this->source, $start, $length);

        return ctype_space($text) ? $text : '';
    }

    /**
     * Compile the body of a group with the modifiers that are in force inside
     * it. "(?x:...)" applies only to its own body, while a bare "(?x)" keeps
     * going until the end of the enclosing group, like PCRE scopes them.
     */
    private function compileGroupChild(GroupNode $node, string $flags): string
    {
        $previousFlags = $this->flags;

        if (GroupType::T_GROUP_INLINE_FLAGS === $node->type) {
            $this->flags = $this->withInlineFlags($previousFlags, $flags);
        }

        $child = $node->child->accept($this);

        if (GroupType::T_GROUP_INLINE_FLAGS !== $node->type || '' !== $child) {
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
        $flags = InlineFlags::read($inline, InlineFlags::LETTERS.'r');

        return null === $flags ? $current : $flags->applyTo($current);
    }

    private function normalizeQuantifier(string $quantifier): string
    {
        return preg_replace('/\\s+/', '', $quantifier) ?? $quantifier;
    }

    /**
     * Intelligent delimiter mapping with caching.
     */
    private function getClosingDelimiter(string $delimiter): string
    {
        self::$delimiterCache[$delimiter] ??= match ($delimiter) {
            '(' => ')',
            '[' => ']',
            '{' => '}',
            '<' => '>',
            default => $delimiter,
        };

        return self::$delimiterCache[$delimiter];
    }

    /**
     * String escaping with minimal allocations.
     */
    private function escapeString(string $value): string
    {
        $meta = $this->inCharClass ? self::CHAR_CLASS_META : self::META_CHARACTERS;
        $escapeExtended = str_contains($this->flags, 'x') && !$this->inCharClass;
        $unicodeMode = $this->utfVerb || str_contains($this->flags, 'u');
        $needsEscape = false;

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
}
