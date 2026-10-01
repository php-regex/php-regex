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

namespace PhpRegex\Generator;

use PhpRegex\Parser\AbstractNodeVisitor;
use PhpRegex\Parser\Analysis\GroupNumberingCollector;
use PhpRegex\Parser\Analysis\LengthRangeCalculator;
use PhpRegex\Parser\Engine\PcreEngine;
use PhpRegex\Parser\Internal\Ascii;
use PhpRegex\Parser\Internal\StaticCaches;
use PhpRegex\Parser\Node\AlternationNode;
use PhpRegex\Parser\Node\AnchorNode;
use PhpRegex\Parser\Node\AssertionNode;
use PhpRegex\Parser\Node\BackrefNode;
use PhpRegex\Parser\Node\CalloutNode;
use PhpRegex\Parser\Node\CharClassNode;
use PhpRegex\Parser\Node\CharLiteralNode;
use PhpRegex\Parser\Node\CharTypeNode;
use PhpRegex\Parser\Node\ClassSetOperationNode;
use PhpRegex\Parser\Node\CommentNode;
use PhpRegex\Parser\Node\ConditionalNode;
use PhpRegex\Parser\Node\ControlCharNode;
use PhpRegex\Parser\Node\DefineNode;
use PhpRegex\Parser\Node\DotNode;
use PhpRegex\Parser\Node\ExtendedCharClassNode;
use PhpRegex\Parser\Node\GroupNode;
use PhpRegex\Parser\Node\GroupType;
use PhpRegex\Parser\Node\KeepNode;
use PhpRegex\Parser\Node\LimitMatchNode;
use PhpRegex\Parser\Node\LiteralNode;
use PhpRegex\Parser\Node\NodeInterface;
use PhpRegex\Parser\Node\PcreVerbNode;
use PhpRegex\Parser\Node\PosixClassNode;
use PhpRegex\Parser\Node\QuantifierBounds;
use PhpRegex\Parser\Node\QuantifierNode;
use PhpRegex\Parser\Node\RangeNode;
use PhpRegex\Parser\Node\RegexNode;
use PhpRegex\Parser\Node\ScriptRunNode;
use PhpRegex\Parser\Node\SequenceNode;
use PhpRegex\Parser\Node\SubroutineNode;
use PhpRegex\Parser\Node\UnicodePropNode;
use PhpRegex\Parser\Node\VersionConditionNode;
use PhpRegex\Parser\Printer\PatternPrinter;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * A visitor that generates a random sample string that matches the AST.
 *
 * @extends AbstractNodeVisitor<string>
 */
final class SampleGenerator extends AbstractNodeVisitor
{
    private const MAX_RECURSION_DEPTH = 2;

    /**
     * The longest sample built: past it, repeats of references or calls
     * would outgrow any subject worth testing with.
     */
    private const MAX_SAMPLE_LENGTH = 1048576;

    /**
     * How many times, for one sample, lookaheads nothing fits are drawn again.
     */
    private const MAX_LOOKAHEAD_DRAWS = 32;

    /**
     * How many times the body of a scan is drawn to fit the group it reads.
     */
    private const MAX_SCAN_DRAWS = 8;

    /**
     * Characters from the common scripts and categories, tried first.
     */
    private const COMMON_CHARACTERS = "aZ09_ .,!+\t\n\x00\u{7f}éÀÿ×αΩЖжאب٣कক中あアク한ก\u{301}\u{200b}\u{2028}\u{a0}\u{3000}€∑«»¿\u{e000}\u{378}😀Ａ\u{ff10}";

    private ?int $seed = null;

    private Randomizer $randomizer;

    /**
     * Stores generated text from capturing groups.
     * Keyed by both numeric index and name (if available).
     *
     * @var array<int|string, string>
     */
    private array $captures = [];

    private int $groupCounter = 1;

    private int $recursionDepth = 0;

    private ?NodeInterface $rootPattern = null;

    /**
     * @var array<int, \PhpRegex\Parser\Node\GroupNode>
     */
    private array $groupIndexMap = [];

    /**
     * @var array<string, \PhpRegex\Parser\Node\GroupNode>
     */
    private array $namedGroupMap = [];

    /**
     * @var array<int, int>
     */
    private array $groupNumbers = [];

    /**
     * @var array<string, array<int>> the numbers of the groups each name is given, as they open
     */
    private array $groupNumbersByName = [];

    /**
     * @var list<\PhpRegex\Parser\Node\GroupNode> the substring scans of the pattern
     */
    private array $scans = [];

    private int $lookaheadDraws = 0;

    /**
     * Whether a "(*ACCEPT)" ended what is being generated, up to the call or
     * the assertion it stands in.
     */
    private bool $accepted = false;

    /**
     * @var array<int, true> the groups being fitted to the scans that read them
     */
    private array $fitting = [];

    /**
     * @var array<int, list<\PhpRegex\Parser\Node\NodeInterface>> the bodies each group's capture is scanned with, by group number
     */
    private array $scansByGroup = [];

    private int $groupDefinitionCounter = 1;

    private bool $unicode = false;

    /**
     * Whether the pattern ignores case, which changes what a set holds.
     */
    private bool $caseless = false;

    /**
     * Whether "$" ends a line, under "m", rather than the subject.
     */
    private bool $dollarEndsLine = false;

    /**
     * Whether a dot takes a newline, under "s".
     */
    private bool $dotAll = false;

    /**
     * The text each sequence being generated holds so far, outermost first,
     * held by reference: what stands before the node being generated.
     *
     * @var array<int, string>
     */
    private array $textsBefore = [];

    /**
     * The groups the calls being generated run, innermost last: 0 for the
     * whole pattern. "(?(R)" and its like read it.
     *
     * @var list<int>
     */
    private array $calls = [];

    /**
     * The fewest characters the pattern still adds after the node being
     * generated, which an alternative ending the subject leaves no room for.
     */
    private int $textAhead = 0;

    /**
     * @var array<int, array{0: int, 1: int|null}> the fewest and most characters each node matches, by node id
     */
    private array $lengthRanges = [];

    /**
     * Characters found to have a property, by the escape and the mode.
     *
     * @var array<string, list<string>>
     */
    private static array $propertySamples = [];

    /**
     * @var array<int, string>
     */
    private static array $codePointChunks = [];

    /**
     * @var array<int, string>
     */
    private array $requiredPrefixes = [];

    /**
     * @var array<int, string>
     */
    private array $requiredSuffixes = [];

    /**
     * @param int                                $maxRepetition Maximum repetitions for quantifiers like `*` or `+`
     * @param \PhpRegex\Parser\Engine\PcreEngine $engine        Asks the running engine what a class or a property holds
     */
    public function __construct(private readonly int $maxRepetition = 3, private readonly PcreEngine $engine = new PcreEngine())
    {
        $this->resetRandomizer();
    }

    public function setSeed(int $seed): void
    {
        $this->seed = $seed;
        $this->resetRandomizer($seed);
    }

    public function resetSeed(): void
    {
        $this->seed = null;
        $this->resetRandomizer();
    }

    #[\Override]
    public function visitRegex(RegexNode $node): string
    {
        // Reset state for this run
        $this->captures = [];
        $this->groupCounter = 1;
        $this->recursionDepth = 0;
        $this->rootPattern = $node->pattern;
        $this->groupIndexMap = [];
        $this->namedGroupMap = [];
        $this->groupNumbers = [];
        $this->scans = [];
        $this->lookaheadDraws = 0;
        $this->accepted = false;
        $this->groupDefinitionCounter = 1;
        $this->requiredPrefixes = [];
        $this->requiredSuffixes = [];
        $this->collectGroups($node->pattern);
        $this->numberGroupsAsPcre($node);
        $this->caseless = str_contains($node->flags, 'i');
        $this->dollarEndsLine = str_contains($node->flags, 'm');
        $this->dotAll = str_contains($node->flags, 's');
        $this->textsBefore = [];
        $this->calls = [];
        $this->textAhead = 0;
        $this->lengthRanges = [];
        $this->unicode = str_contains($node->flags, 'u')
            || 1 === preg_match('/^(?:\(\*[A-Z_=0-9]+\))*\(\*UTF8?\)/', $node->source ?? '');

        // Ensure we are seeded if the user expects it
        if (null !== $this->seed) {
            $this->resetRandomizer($this->seed);
        }

        // Note: Flags (like /i) are ignored, as we generate the sample
        // from the literal pattern.
        $sample = $node->pattern->accept($this);

        return $this->applyLookaroundHints($sample);
    }

    #[\Override]
    public function visitAlternation(AlternationNode $node): string
    {
        if (empty($node->alternatives)) {
            return '';
        }

        // Pick one of the alternatives at random, among those that leave
        // room for what the pattern still adds.
        $alternatives = array_values(array_filter(
            $node->alternatives,
            fn (NodeInterface $alternative): bool => $this->textAhead <= $this->roomAfter($alternative),
        ));
        if ([] === $alternatives) {
            $alternatives = $node->alternatives;
        }
        $chosenAlt = $alternatives[$this->randomInt(0, \count($alternatives) - 1)];

        return $chosenAlt->accept($this);
    }

    #[\Override]
    public function visitSequence(SequenceNode $node): string
    {
        return $this->generateSequence($node->children);
    }

    #[\Override]
    public function visitGroup(GroupNode $node): string
    {
        // "(?s)" sets its flags for the rest of the group it stands in,
        // "(?s:...)" for its own content: the group around them ends them.
        if (GroupType::T_GROUP_INLINE_FLAGS === $node->type && $node->child instanceof LiteralNode && '' === $node->child->value) {
            $this->applyFlags($node->flags ?? '');

            return '';
        }

        $modes = [$this->dotAll, $this->dollarEndsLine];
        if (GroupType::T_GROUP_INLINE_FLAGS === $node->type) {
            $this->applyFlags($node->flags ?? '');
        }

        try {
            return $this->generateGroup($node);
        } finally {
            [$this->dotAll, $this->dollarEndsLine] = $modes;
        }
    }

    #[\Override]
    public function visitDot(DotNode $node): string
    {
        // Generate a random, simple, printable ASCII char, or a newline
        // where "s" lets the dot take one.
        $characters = ['a', 'b', 'c', '1', '2', '3', ' '];

        return $this->getRandomChar($this->dotAll ? [...$characters, "\n"] : $characters);
    }

    #[\Override]
    public function visitQuantifier(QuantifierNode $node): string
    {
        [$min, $max] = $this->parseQuantifierRange($node->quantifier);

        // What takes no text, optional, need not hold: it adds nothing. It
        // stays in its sequence, where a call may name a group it holds.
        if (0 === $min && 0 === $this->lengthRange($node->node)[1]) {
            return '';
        }

        // Pick a random number of repetitions
        // $min and $max are guaranteed to be in the correct order
        // by parseQuantifierRange()
        $repeats = ($min === $max) ? $min : $this->randomInt($min, $max);

        $sample = '';
        $ahead = $this->textAhead;
        $each = $this->minLength($node->node);
        for ($i = 0; $i < $repeats; $i++) {
            $this->textAhead = $ahead + ($repeats - 1 - $i) * $each;
            $sample .= $node->node->accept($this);
            $this->textAhead = $ahead;
            if ($this->accepted) {
                break;
            }
            // References and calls can double a sample at each repeat.
            if (\strlen($sample) > self::MAX_SAMPLE_LENGTH) {
                throw new SampleGenerationException(\sprintf('No sample was built: it would pass %d bytes.', self::MAX_SAMPLE_LENGTH));
            }
        }

        return $sample;
    }

    #[\Override]
    public function visitLiteral(LiteralNode $node): string
    {
        return $node->value;
    }

    #[\Override]
    public function visitCharType(CharTypeNode $node): string
    {
        return $this->generateForCharType($node->value);
    }

    #[\Override]
    public function visitAnchor(AnchorNode $node): string
    {
        // Anchors do not generate text
        return '';
    }

    #[\Override]
    public function visitAssertion(AssertionNode $node): string
    {
        // Assertions do not generate text
        return '';
    }

    #[\Override]
    public function visitKeep(KeepNode $node): string
    {
        // \K does not generate text
        return '';
    }

    #[\Override]
    public function visitCharClass(CharClassNode $node): string
    {
        if ($node->isNegated) {
            return $this->sampleForNegatedClass($node);
        }

        $parts = $node->expression instanceof AlternationNode ? $node->expression->alternatives : [$node->expression];
        if (empty($parts)) {
            // Only an AST built by hand holds one: "[]" opens a class whose first member is "]".
            throw new SampleGenerationException('Cannot generate sample for empty character class');
        }

        // Pick one of the parts at random
        $randomKey = $this->randomInt(0, \count($parts) - 1);

        return $parts[$randomKey]->accept($this);
    }

    #[\Override]
    public function visitRange(RangeNode $node): string
    {
        $first = $this->codePointOf($node->start);
        $last = $this->codePointOf($node->end);
        if (null === $first || null === $last || $first > $last) {
            $sample = $node->start->accept($this);

            return '' !== $sample ? $sample : $node->end->accept($this);
        }

        // A code point of the range, the surrogates aside, which UTF-8 cannot
        // hold; without UTF mode, a byte.
        $codePoint = $this->randomInt($first, $last);
        if ($codePoint >= 0xD800 && $codePoint <= 0xDFFF) {
            $codePoint = $first;
        }

        return $this->unicode ? (string) mb_chr($codePoint, 'UTF-8') : \chr($codePoint & 0xFF);
    }

    #[\Override]
    public function visitBackref(BackrefNode $node): string
    {
        $ref = $node->ref;

        // Check numeric reference first
        if (Ascii::isDigit($ref)) {
            $key = (int) $ref;
            if (isset($this->captures[$key])) {
                return $this->captures[$key];
            }
        }

        // Check numeric reference with \
        if (preg_match('/^\\\\(\d++)$/', $ref, $matches)) {
            $key = (int) $matches[1];
            if (isset($this->captures[$key])) {
                return $this->captures[$key];
            }
        }

        // "\g1", "\g{1}", and relative "\g-1", "\g{-1}", "\g{+1}": relative
        // ones count the groups opened before the reference.
        if (preg_match('/^\\\\g\{?([+-]?)(\d++)\}?$/', $ref, $matches)) {
            $number = (int) $matches[2];
            if ('' !== $matches[1]) {
                $opened = \count(array_filter($this->groupIndexMap, static fn (GroupNode $group): bool => $group->startPosition < $node->startPosition));
                $number = '-' === $matches[1] ? $opened - $number + 1 : $opened + $number;
            }

            return $this->captures[$number] ?? '';
        }

        // Check string/named reference (e.g. for (?&name) conditionals)
        if (isset($this->captures[$ref])) {
            return $this->captures[$ref];
        }

        // "\k<name>", "\k{name}", "\k'name'": several groups may share the
        // name, and the reference matches the first of them that captured.
        if (1 === preg_match('/^\\\\k[<{\']([^>}\']++)[>}\']$/', $ref, $m)) {
            foreach ($this->groupNumbersByName[$m[1]] ?? [] as $number) {
                if (isset($this->captures[$number])) {
                    return $this->captures[$number];
                }
            }

            return '';
        }

        // Backreference to a group that hasn't matched yet
        // (or doesn't exist). In a real engine, this fails the match.
        // For generation, we must return empty string.
        return '';
    }

    #[\Override]
    public function visitExtendedCharClass(ExtendedCharClassNode $node): string
    {
        // PCRE knows which characters are in the set, under "i" too: ask it,
        // when it reads "(?[".
        $class = $node->accept(new PatternPrinter());
        $samples = $this->charactersWithProperty($this->caseless ? '(?i)'.$class : $class);
        if ([] !== $samples) {
            return $this->getRandomChar($samples);
        }

        return $node->expression->accept($this);
    }

    #[\Override]
    public function visitClassSetOperation(ClassSetOperationNode $node): string
    {
        // A guess for an engine that cannot tell: a member of the left operand.
        return $node->left?->accept($this) ?? '!';
    }

    #[\Override]
    public function visitScriptRun(ScriptRunNode $node): string
    {
        return $node->content?->accept($this) ?? '';
    }

    #[\Override]
    public function visitVersionCondition(VersionConditionNode $node): string
    {
        return '';
    }

    #[\Override]
    public function visitControlChar(ControlCharNode $node): string
    {
        if ($node->codePoint < 0 || $node->codePoint > 0xFF) {
            return '?';
        }

        return \chr($node->codePoint);
    }

    #[\Override]
    public function visitCharLiteral(CharLiteralNode $node): string
    {
        if ($node->codePoint < 0 || $node->codePoint > 0x10FFFF) {
            return '?';
        }

        // Without UTF mode, a character up to 255 is a byte.
        if (!$this->unicode && $node->codePoint <= 0xFF) {
            return \chr($node->codePoint);
        }

        try {
            $char = mb_chr($node->codePoint, 'UTF-8');
            if (false === $char) {
                return '?';
            }

            return $char;
        } catch (\Throwable) {
            return '?';
        }
    }

    #[\Override]
    public function visitUnicodeProp(UnicodePropNode $node): string
    {
        // PCRE knows which characters have the property: ask it.
        $escape = $node->hasBraces
            ? '\\p'.$node->prop
            : ((\strlen($node->prop) > 1 || str_starts_with($node->prop, '^')) ? '\\p{'.$node->prop.'}' : '\\p'.$node->prop);
        $samples = $this->charactersWithProperty($escape);
        if ([] !== $samples) {
            return $this->getRandomChar($samples);
        }

        // Nothing encodable has it (the surrogates): a guess.
        if (str_contains($node->prop, 'L')) { // 'L' (Letter)
            return $this->getRandomChar(['a', 'b', 'c']);
        }
        if (str_contains($node->prop, 'N')) { // 'N' (Number)
            return $this->getRandomChar(['1', '2', '3']);
        }
        if (str_contains($node->prop, 'P')) { // 'P' (Punctuation)
            return $this->getRandomChar(['.', ',', '!']);
        }

        return $this->getRandomChar(['a', '1', '.']); // Generic fallback
    }

    #[\Override]
    public function visitPosixClass(PosixClassNode $node): string
    {
        // PCRE knows the members, negated classes and UTF mode included.
        $samples = $this->charactersWithProperty('[[:'.$node->class.':]]');
        if ([] !== $samples) {
            return $this->getRandomChar($samples);
        }

        return match (strtolower($node->class)) {
            'alpha' => $this->getRandomChar(['a', 'b', 'C', 'Z']),
            'alnum' => $this->getRandomChar(['a', 'Z', '1', '9']),
            'digit' => $this->getRandomChar(['0', '1', '2', '3', '4', '5', '6', '7', '8', '9']),
            'xdigit' => $this->getRandomChar(['0', '9', 'a', 'f', 'A', 'F']),
            'space' => $this->getRandomChar([' ', "\t", "\n"]),
            'lower' => $this->getRandomChar(['a', 'b', 'c', 'z']),
            'upper' => $this->getRandomChar(['A', 'B', 'C', 'Z']),
            'punct' => $this->getRandomChar(['.', '!', ',', '?']),
            'word' => $this->getRandomChar(['a', 'Z', '0', '9', '_']),
            'blank' => $this->getRandomChar([' ', "\t"]),
            'cntrl' => "\x00", // Control character
            'graph', 'print' => $this->getRandomChar(['!', '@', '#']),
            default => $this->getRandomChar(['a', '1', ' ']),
        };
    }

    #[\Override]
    public function visitComment(CommentNode $node): string
    {
        // Comments do not generate text
        return '';
    }

    #[\Override]
    public function visitConditional(ConditionalNode $node): string
    {
        if ($this->isConditionSatisfied($node->condition)) {
            return $node->yes->accept($this);
        }

        return $node->no->accept($this);
    }

    #[\Override]
    public function visitSubroutine(SubroutineNode $node): string
    {
        if (null === $this->rootPattern || $this->rootPattern instanceof SubroutineNode) {
            throw new SampleGenerationException('Sample generation for subroutines is not supported.');
        }

        if ($this->recursionDepth >= self::MAX_RECURSION_DEPTH) {
            return '';
        }

        $target = $this->resolveSubroutineTarget($node);
        if (null === $target) {
            throw new SampleGenerationException('Sample generation for subroutines is not supported.');
        }

        $this->recursionDepth++;
        $this->calls[] = \in_array($node->reference, ['R', '0'], true) || !$target instanceof GroupNode
            ? 0
            : $this->groupNumbers[spl_object_id($target)] ?? -1;

        try {
            return $this->acceptedIn($target instanceof GroupNode ? $target->child : $target, inPlace: true);
        } finally {
            array_pop($this->calls);
            $this->recursionDepth--;
        }
    }

    #[\Override]
    public function visitPcreVerb(PcreVerbNode $node): string
    {
        // Verbs do not generate text
        return '';
    }

    #[\Override]
    public function visitDefine(DefineNode $node): string
    {
        // DEFINE blocks do not generate text, they only define subpatterns
        return '';
    }

    #[\Override]
    public function visitLimitMatch(LimitMatchNode $node): string
    {
        return '';
    }

    #[\Override]
    public function visitCallout(CalloutNode $node): string
    {
        // Callouts do not match characters, so they generate no sample text.
        return '';
    }

    private function generateGroup(GroupNode $node): string
    {
        // Lookarounds are zero-width assertions and should not generate text
        if (\in_array($node->type, [
            GroupType::T_GROUP_LOOKAHEAD_POSITIVE,
            GroupType::T_GROUP_LOOKAHEAD_NEGATIVE,
            GroupType::T_GROUP_LOOKBEHIND_POSITIVE,
            GroupType::T_GROUP_LOOKBEHIND_NEGATIVE,
            GroupType::T_GROUP_SCAN_SUBSTRING,
        ], true)) {
            if (GroupType::T_GROUP_LOOKBEHIND_POSITIVE === $node->type) {
                $prefix = $this->acceptedIn($node->child);
                if ('' !== $prefix) {
                    $this->requiredPrefixes[] = $prefix;
                }
            } elseif (GroupType::T_GROUP_LOOKAHEAD_POSITIVE === $node->type) {
                $suffix = $this->acceptedIn($node->child);
                if ('' !== $suffix) {
                    $this->requiredSuffixes[] = $suffix;
                }
            }

            return '';
        }

        $result = $node->child->accept($this);

        // Store the result if it's a capturing group
        if (GroupType::T_GROUP_CAPTURING === $node->type) {
            $groupIndex = $this->groupNumbers[spl_object_id($node)] ?? $this->groupCounter++;
            $result = $this->fitScans($groupIndex, $node, $result);
            $this->captures[$groupIndex] = $result;
            $this->groupCounter = max($this->groupCounter, $groupIndex + 1);
        } elseif (GroupType::T_GROUP_NAMED === $node->type) {
            $groupIndex = $this->groupNumbers[spl_object_id($node)] ?? $this->groupCounter++;
            $result = $this->fitScans($groupIndex, $node, $result);
            $this->captures[$groupIndex] = $result;
            if ($node->name) {
                $this->captures[$node->name] = $result;
            }
            $this->groupCounter = max($this->groupCounter, $groupIndex + 1);
        }

        // For non-capturing, etc., just return the child's result
        return $result;
    }

    /**
     * The code point a range end stands for: a character, a byte without UTF
     * mode, or an escape.
     */
    private function codePointOf(NodeInterface $node): ?int
    {
        if ($node instanceof CharLiteralNode) {
            return $node->codePoint >= 0 && $node->codePoint <= 0x10FFFF ? $node->codePoint : null;
        }

        if (!$node instanceof LiteralNode || '' === $node->value) {
            return null;
        }

        $codePoint = $this->unicode ? mb_ord($node->value, 'UTF-8') : \ord($node->value[0]);

        return false === $codePoint ? null : $codePoint;
    }

    /**
     * Up to eight characters the escape "\p{...}", or an extended class,
     * matches: common ones first, then the whole range, one chunk at a time;
     * bytes without UTF mode. None when the engine refuses it.
     *
     * @return list<string>
     */
    private function charactersWithProperty(string $escape): array
    {
        $key = ($this->unicode ? 'u' : 'b').$escape;
        if (isset(self::$propertySamples[$key])) {
            return self::$propertySamples[$key];
        }

        // A written class escapes its "/", so "/" delimits it.
        $pattern = '/'.$escape.'/'.($this->unicode ? 'u' : '');
        $found = [];
        foreach ($this->unicode ? self::codePointChunks() : [implode('', array_map(\chr(...), range(0, 255)))] as $chunk) {
            $matches = $this->engine->matchAll($pattern, $chunk);
            if (null === $matches) {
                break;
            }

            foreach ($matches as $character) {
                $found[$character] = true;
                if (\count($found) >= 8) {
                    break 2;
                }
            }
        }

        self::$propertySamples = StaticCaches::makeRoom(self::$propertySamples);
        StaticCaches::register(self::class, self::clearCaches(...));

        return self::$propertySamples[$key] = array_map(strval(...), array_keys($found));
    }

    /**
     * The chunks are bounded by the code points, 272 of them; the property
     * samples by StaticCaches::MAX_ENTRIES.
     */
    private static function clearCaches(): void
    {
        self::$propertySamples = [];
        self::$codePointChunks = [];
    }

    /**
     * Common characters first, then every code point but the surrogates,
     * 4096 at a time, built once.
     *
     * @return \Generator<int, string>
     */
    private static function codePointChunks(): \Generator
    {
        yield self::COMMON_CHARACTERS;

        for ($start = 0; $start <= 0x10FFFF; $start += 4096) {
            if (!isset(self::$codePointChunks[$start])) {
                $chunk = '';
                for ($codePoint = $start; $codePoint < $start + 4096 && $codePoint <= 0x10FFFF; $codePoint++) {
                    if ($codePoint < 0xD800 || $codePoint > 0xDFFF) {
                        $chunk .= mb_chr($codePoint, 'UTF-8');
                    }
                }
                StaticCaches::register(self::class, self::clearCaches(...));
                self::$codePointChunks[$start] = $chunk;
            }

            yield self::$codePointChunks[$start];
        }
    }

    /**
     * Picks a character that is actually outside the negated set by testing
     * candidates against the compiled character class.
     */
    private function sampleForNegatedClass(CharClassNode $node): string
    {
        $candidates = [
            'a', 'b', 'c', 'd', 'e',
            'A', 'B', 'C', 'D', 'E',
            '0', '1', '2', '3', '4',
            '!', '?', '_', '-', ' ', "\t", 'é', '☃',
        ];

        try {
            $compiled = $node->accept(new PatternPrinter());
        } catch (\Throwable) {
            return '!';
        }

        foreach (['/', '~', '#', '%', '@', ';'] as $delimiter) {
            if (str_contains($compiled, $delimiter)) {
                continue;
            }

            $classPattern = $delimiter.$compiled.$delimiter.($this->unicode ? 'u' : '');
            foreach ($candidates as $candidate) {
                if (true === $this->engine->match($classPattern, $candidate)->matched) {
                    return $candidate;
                }
            }

            break;
        }

        // No printable candidate matched (or the class could not be compiled
        // into a testable pattern): keep the historical fallback.
        return '!';
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function parseQuantifierRange(string $q): array
    {
        $bounds = QuantifierBounds::parse($q);
        if (null === $bounds) {
            return [0, 0];
        }

        $max = $bounds->max ?? $bounds->min + $this->maxRepetition;

        // Ensure min <= max, as Validator may not have run.
        // This handles invalid cases like {5,2} and silences PHPStan
        return [$bounds->min, max($max, $bounds->min)];
    }

    /**
     * A positive lookahead holds the text that follows it in the sequence,
     * and a positive lookbehind the text before it: what either generates is
     * laid over that text, not added at the ends of the sample.
     *
     * @param array<\PhpRegex\Parser\Node\NodeInterface> $children
     */
    private function generateSequence(array $children): string
    {
        $text = '';
        $children = $this->withPlainGroupsOpened($children);
        $ahead = $this->textAhead;
        // What the children after each one add, at the fewest.
        $after = [];
        $rest = 0;
        for ($index = \count($children) - 1; $index >= 0; $index--) {
            $after[$index] = $rest;
            $rest += $this->minLength($children[$index]);
        }

        $this->textsBefore[] = &$text;

        try {
            return $this->generateChildren($children, $after, $ahead, $text);
        } finally {
            $this->textAhead = $ahead;
            array_pop($this->textsBefore);
        }
    }

    /**
     * @param list<\PhpRegex\Parser\Node\NodeInterface> $children
     * @param array<int, int>                           $after    the fewest characters the children after each one add
     */
    private function generateChildren(array $children, array $after, int $ahead, string &$text): string
    {
        foreach ($children as $index => $child) {
            $this->textAhead = $ahead;
            if ($child instanceof GroupNode && GroupType::T_GROUP_LOOKAHEAD_POSITIVE === $child->type) {
                // Its text laid over what follows, or before it, or after it:
                // the first that what is left of the sequence matches, as
                // lookaheads in a row each ask something of the same text.
                // None does: the whole is drawn again, while tries are left.
                $left = new SequenceNode(\array_slice($children, $index), $child->getStartPosition(), $child->getEndPosition());
                do {
                    // The lookahead first: what it captures holds after it.
                    $ahead = $this->acceptedIn($child->child);
                    $rest = $this->generateSequence(\array_slice($children, $index + 1));
                    if ($this->holds($child->child, $rest, '\\A', '')) {
                        return $text.$rest;
                    }

                    // The rest alone again, read with the groups it defines: a
                    // call in the lookahead may name one of them.
                    $laidOver = $ahead.$this->textFrom($rest, $this->textLength($ahead));
                    foreach ([$rest, $laidOver, $ahead.$rest, $rest.$ahead] as $candidate) {
                        if ($this->holds($left, $candidate, '\\A', '')) {
                            return $text.$candidate;
                        }
                    }
                } while ($this->lookaheadDraws++ < self::MAX_LOOKAHEAD_DRAWS);

                return $text.$laidOver;
            }

            // "(*ACCEPT)" ends what it stands in: what follows it is not read.
            if ($child instanceof PcreVerbNode && 1 === preg_match('/^ACCEPT(?::|$)/', $child->verb)) {
                $this->accepted = true;

                return $text;
            }

            if ($child instanceof GroupNode && GroupType::T_GROUP_LOOKBEHIND_POSITIVE === $child->type) {
                // What the groups around hold before this one counts too.
                if ($this->holds($child->child, implode('', $this->textsBefore), '', '\\z')) {
                    continue;
                }

                $behind = $this->acceptedIn($child->child);
                $kept = max(0, $this->textLength($text) - $this->textLength($behind));
                $text = $this->textTo($text, $kept).$behind;

                continue;
            }

            $this->textAhead = $ahead + $after[$index];
            $text .= $child->accept($this);
            if ($this->accepted) {
                return $text;
            }
        }

        return $text;
    }

    /**
     * How many characters may follow an alternative: none after one that
     * ends the subject, any number after the others. "$" and "\Z" let a
     * final newline follow, which what the pattern still adds seldom is.
     */
    private function roomAfter(NodeInterface $alternative): int
    {
        $last = $alternative instanceof SequenceNode ? ($alternative->children[\count($alternative->children) - 1] ?? null) : $alternative;

        return match (true) {
            $last instanceof AssertionNode && 'z' === $last->value => 0,
            $last instanceof AssertionNode && 'Z' === $last->value => 0,
            $last instanceof AnchorNode && '$' === $last->value && !$this->dollarEndsLine => 0,
            default => \PHP_INT_MAX,
        };
    }

    /**
     * The fewest characters a node matches. A call counts what it runs; a
     * reference, or a call inside another node, counts none.
     */
    private function minLength(NodeInterface $node): int
    {
        if ($node instanceof SubroutineNode) {
            $target = null === $this->rootPattern ? null : $this->resolveSubroutineTarget($node);
            $node = $target ?? $node;
        }

        return $this->lengthRange($node)[0];
    }

    /**
     * @return array{0: int, 1: int|null}
     */
    private function lengthRange(NodeInterface $node): array
    {
        return $this->lengthRanges[spl_object_id($node)] ??= $node->accept(new LengthRangeCalculator($this->unicode));
    }

    /**
     * Whether "(?(VERSION...)" holds on a PCRE2 release.
     */
    private static function versionConditionHolds(VersionConditionNode $condition, int $major, int $minor): bool
    {
        [$wantedMajor, $wantedMinor] = explode('.', $condition->version.'.0');
        $tens = 1 === \strlen($wantedMinor) && [$major, $minor] < [10, 47] ? 10 : 1;
        $running = [$major, $minor] <=> [(int) $wantedMajor, (int) $wantedMinor * $tens];

        return '=' === $condition->operator ? 0 === $running : $running >= 0;
    }

    /**
     * The text of a call or an assertion: a "(*ACCEPT)" in it ends it, and
     * no more. Laid out as a sequence, a lookahead in it gives its text.
     */
    private function acceptedIn(NodeInterface $node, bool $asSequence = false, bool $inPlace = false): string
    {
        // A lookaround's or a scan's text stands over the subject, not
        // before what follows it: nothing it holds is refused for lack of
        // room. A call's text stands in the call's place.
        $ahead = $this->textAhead;
        $this->textAhead = $inPlace ? $ahead : 0;

        try {
            $text = $asSequence ? $this->generateSequence($node instanceof SequenceNode ? $node->children : [$node]) : $node->accept($this);
        } finally {
            $this->textAhead = $ahead;
        }
        $this->accepted = false;

        return $text;
    }

    /**
     * A capture a substring scan reads must start with what the scan's body
     * matches: the body's sample is put before the text, or in its place,
     * where the group still matches the whole of it.
     */
    private function fitScans(int $number, GroupNode $group, string $text): string
    {
        // A group inside the body of a scan that reads it is fitted once.
        if (isset($this->fitting[$number])) {
            return $text;
        }

        $this->fitting[$number] = true;

        try {
            return $this->fitScansOnce($number, $group, $text);
        } finally {
            unset($this->fitting[$number]);
        }
    }

    private function fitScansOnce(int $number, GroupNode $group, string $text): string
    {
        foreach ($this->scansByGroup[$number] ?? [] as $body) {
            if ($this->holds($body, $text, '\\A', '')) {
                continue;
            }

            // Laid out as a sequence, so a lookahead in it gives its text;
            // drawn again where the group takes none of it.
            for ($draw = 0; $draw < self::MAX_SCAN_DRAWS; $draw++) {
                $prefix = $this->acceptedIn($body, true);
                foreach ([$prefix.$text, $prefix] as $candidate) {
                    if ($this->holds($group->child, $candidate, '\\A', '\\z') && $this->holds($body, $candidate, '\\A', '')) {
                        $text = $candidate;

                        break 2;
                    }
                }
            }
        }

        return $text;
    }

    /**
     * Whether the text already satisfies a lookaround's body where it stands,
     * so it is left as it is: "red" before "\b(?<=\w)".
     */
    private function holds(NodeInterface $body, string $text, string $before, string $after): bool
    {
        $compiled = $body->accept(new PatternPrinter());

        // Checked by the engine, as generate() checks its samples.
        return true === $this->engine->match("\x01".$before.'(?:'.$compiled.')'.$after."\x01".($this->unicode ? 'u' : ''), $text)->matched;
    }

    /**
     * A plain "(?:...)" in a sequence is laid out inside it, so a lookahead
     * that closes the group holds the text after it: "(?:\b(?=\w))red".
     *
     * @param array<\PhpRegex\Parser\Node\NodeInterface> $children
     *
     * @return list<\PhpRegex\Parser\Node\NodeInterface>
     */
    private function withPlainGroupsOpened(array $children): array
    {
        $opened = [];
        foreach ($children as $child) {
            // What takes no text, repeated, is generated once: each turn
            // stands at the same place.
            if ($child instanceof QuantifierNode && 0 === $this->lengthRange($child->node)[1]
                && $this->parseQuantifierRange($child->quantifier)[0] > 0) {
                array_push($opened, ...$this->withPlainGroupsOpened([$child->node]));

                continue;
            }

            if ($child instanceof GroupNode && GroupType::T_GROUP_NON_CAPTURING === $child->type && null === $child->flags
                && !$this->setsFlags($child->child)) {
                $inner = $child->child instanceof SequenceNode ? $child->child->children : [$child->child];
                array_push($opened, ...$this->withPlainGroupsOpened($inner));

                continue;
            }

            $opened[] = $child;
        }

        return $opened;
    }

    /**
     * Whether "(?s)" or its like stands directly in a node: laid out in the
     * sequence around, it would set its flags past the group that ends them.
     */
    private function setsFlags(NodeInterface $node): bool
    {
        foreach ($node instanceof SequenceNode ? $node->children : [$node] as $child) {
            if ($child instanceof GroupNode && GroupType::T_GROUP_INLINE_FLAGS === $child->type
                && $child->child instanceof LiteralNode && '' === $child->child->value) {
                return true;
            }
        }

        return false;
    }

    /**
     * The "s" and "m" flags an inline setting turns on or off, as "s-m", or
     * "^" to turn them all off first.
     */
    private function applyFlags(string $flags): void
    {
        [$on, $off] = explode('-', $flags, 2) + [1 => ''];
        if (str_starts_with($on, '^')) {
            $this->dotAll = false;
            $this->dollarEndsLine = false;
        }

        $this->dotAll = (str_contains($on, 's') || $this->dotAll) && !str_contains($off, 's');
        $this->dollarEndsLine = (str_contains($on, 'm') || $this->dollarEndsLine) && !str_contains($off, 'm');
    }

    private function textLength(string $text): int
    {
        return $this->unicode ? mb_strlen($text, 'UTF-8') : \strlen($text);
    }

    private function textFrom(string $text, int $start): string
    {
        return $this->unicode ? mb_substr($text, $start, null, 'UTF-8') : substr($text, $start);
    }

    private function textTo(string $text, int $length): string
    {
        return $this->unicode ? mb_substr($text, 0, $length, 'UTF-8') : substr($text, 0, $length);
    }

    /**
     * Generates a random integer using the local RNG.
     */
    private function randomInt(int $min, int $max): int
    {
        if ($max < $min) {
            $max = $min;
        }

        try {
            return $this->randomizer->getInt($min, $max);
        } catch (\Throwable) {
            return $min;
        }
    }

    private function applyLookaroundHints(string $sample): string
    {
        foreach ($this->requiredPrefixes as $prefix) {
            if ('' === $prefix) {
                continue;
            }

            if (!str_starts_with($sample, $prefix)) {
                $sample = $prefix.$sample;
            }
        }

        foreach ($this->requiredSuffixes as $suffix) {
            if ('' === $suffix) {
                continue;
            }

            // A lookbehind constrains what the sample must END with.
            if (!str_ends_with($sample, $suffix)) {
                $sample .= $suffix;
            }
        }

        return $sample;
    }

    private function isConditionSatisfied(NodeInterface $condition): bool
    {
        if ($condition instanceof BackrefNode) {
            return $this->hasCaptureForReference($condition->ref);
        }

        if ($condition instanceof GroupNode) {
            // Whether a lookaround holds depends on the text around the
            // sample: either branch may be the one that matches, so each
            // attempt takes one at random.
            if (\in_array($condition->type, [
                GroupType::T_GROUP_LOOKAHEAD_POSITIVE,
                GroupType::T_GROUP_LOOKBEHIND_POSITIVE,
                GroupType::T_GROUP_LOOKAHEAD_NEGATIVE,
                GroupType::T_GROUP_LOOKBEHIND_NEGATIVE,
            ], true)) {
                return 1 === $this->randomInt(0, 1);
            }

            return '' !== $condition->accept($this);
        }

        if ($condition instanceof AssertionNode) {
            return true;
        }

        // Samples are checked by the running engine: its version decides. From
        // PCRE2 10.47 the minor is read as a number, "10.5" below "10.49";
        // before, one digit counts tens, "10.5" is "10.50".
        if ($condition instanceof VersionConditionNode) {
            [$major, $minor] = array_map(intval(...), explode('.', explode(' ', \PCRE_VERSION)[0].'.0'));

            return self::versionConditionHolds($condition, $major, $minor);
        }

        // "(?(R)" holds inside any call, "(?(R2)" and "(?(R&name)" inside a
        // call to that group, the latest call.
        if ($condition instanceof SubroutineNode && 1 === preg_match('/^R(?:(\d++)|&(.++))?$/', $condition->reference, $matches, \PREG_UNMATCHED_AS_NULL)) {
            $latest = [] === $this->calls ? null : $this->calls[\count($this->calls) - 1];

            return match (true) {
                null !== $matches[1] => (int) $matches[1] === $latest,
                null !== $matches[2] => \in_array($latest, $this->groupNumbersByName[$matches[2]] ?? [], true),
                default => null !== $latest,
            };
        }

        return 1 === $this->randomInt(0, 1);
    }

    private function hasCaptureForReference(string $reference): bool
    {
        if (Ascii::isDigit($reference)) {
            return isset($this->captures[(int) $reference]);
        }

        if (isset($this->captures[$reference])) {
            return true;
        }

        if (preg_match('/^\\\\(\d++)$/', $reference, $matches)) {
            return isset($this->captures[(int) $matches[1]]);
        }

        return false;
    }

    /**
     * @param array<string> $chars
     */
    private function getRandomChar(array $chars): string
    {
        if (empty($chars)) {
            return '?'; // Safe fallback
        }
        $key = $this->randomInt(0, \count($chars) - 1);

        return $chars[$key];
    }

    private function generateForCharType(string $type): string
    {
        try {
            return match ($type) {
                'd' => (string) $this->randomInt(0, 9),
                'D' => $this->getRandomChar(['a', ' ', '!']), // Not a digit
                's' => $this->getRandomChar([' ', "\t", "\n"]),
                'S' => $this->getRandomChar(['a', '1', '!']), // Not whitespace
                'w' => $this->getRandomChar(['a', 'Z', '5', '_']),
                'W' => $this->getRandomChar(['!', ' ', '@']), // Not word
                'h' => $this->getRandomChar([' ', "\t"]),
                'H' => $this->getRandomChar(['a', '1', "\n"]), // Not horiz space
                'v' => "\n", // vertical space
                'V' => $this->getRandomChar(['a', '1', ' ']), // Not vert space
                'R' => $this->getRandomChar(["\r\n", "\r", "\n"]),
                default => '?',
            };
        } catch (\Throwable) {
            return '?'; // Fallback for random generation failure
        }
    }

    private function collectGroups(NodeInterface $node): void
    {
        if ($node instanceof GroupNode) {
            if (GroupType::T_GROUP_SCAN_SUBSTRING === $node->type) {
                $this->scans[] = $node;
            }

            if (\in_array($node->type, [GroupType::T_GROUP_CAPTURING, GroupType::T_GROUP_NAMED], true)) {
                $index = $this->groupDefinitionCounter++;
                $this->groupIndexMap[$index] = $node;
                $this->groupNumbers[spl_object_id($node)] = $index;
                if (null !== $node->name) {
                    // A call to a name several groups share runs the first.
                    $this->namedGroupMap[$node->name] ??= $node;
                }
            }

            $this->collectGroups($node->child);

            return;
        }

        if ($node instanceof SequenceNode) {
            foreach ($node->children as $child) {
                $this->collectGroups($child);
            }

            return;
        }

        if ($node instanceof AlternationNode) {
            foreach ($node->alternatives as $alt) {
                $this->collectGroups($alt);
            }

            return;
        }

        if ($node instanceof QuantifierNode) {
            $this->collectGroups($node->node);

            return;
        }

        if ($node instanceof ConditionalNode) {
            $this->collectGroups($node->condition);
            $this->collectGroups($node->yes);
            $this->collectGroups($node->no);

            return;
        }

        if ($node instanceof DefineNode) {
            $this->collectGroups($node->content);
        }

        if ($node instanceof ScriptRunNode && null !== $node->content) {
            $this->collectGroups($node->content);
        }
    }

    /**
     * The groups take the numbers PCRE gives them: in "(?|(b)|(q))" both
     * are group 2, and a call to 2 runs the first.
     */
    private function numberGroupsAsPcre(RegexNode $node): void
    {
        // Both walk the tree in the same order, a group before what it holds.
        $numbering = (new GroupNumberingCollector())->collect($node);
        $numbers = $numbering->captureSequence;
        $this->groupNumbersByName = $numbering->namedGroups;
        $this->scansByGroup = [];
        foreach ($this->scans as $scan) {
            foreach ($scan->scannedGroups as $group) {
                $number = Ascii::isDigit($group) ? [(int) $group] : ($this->groupNumbersByName[trim($group, "<>'")] ?? []);
                foreach ($number as $scanned) {
                    $this->scansByGroup[$scanned][] = $scan->child;
                }
            }
        }
        $groups = array_values($this->groupIndexMap);

        $this->groupIndexMap = [];
        foreach ($groups as $order => $group) {
            $number = $numbers[$order] ?? $order + 1;
            $this->groupNumbers[spl_object_id($group)] = $number;
            $this->groupIndexMap[$number] ??= $group;
        }
    }

    private function resolveSubroutineTarget(SubroutineNode $node): ?NodeInterface
    {
        $ref = $node->reference;

        if ('R' === $ref || '0' === $ref) {
            return $this->rootPattern;
        }

        if (str_starts_with($ref, 'R')) {
            $ref = substr($ref, 1);
            if ('' === $ref) {
                return $this->rootPattern;
            }
        }

        if (Ascii::isDigit($ref)) {
            $index = (int) $ref;

            return $this->groupIndexMap[$index] ?? null;
        }

        // "(?-1)" and "(?+1)" count the groups opened before the call.
        if (1 === preg_match('/^([+-])(\d++)$/', $ref, $matches)) {
            $opened = \count(array_filter($this->groupIndexMap, static fn (GroupNode $group): bool => $group->startPosition < $node->startPosition));
            $number = '-' === $matches[1] ? $opened - (int) $matches[2] + 1 : $opened + (int) $matches[2];

            return $this->groupIndexMap[$number] ?? null;
        }

        return $this->namedGroupMap[$ref] ?? null;
    }

    private function resetRandomizer(?int $seed = null): void
    {
        $engine = null === $seed ? new Mt19937() : new Mt19937($seed);
        $this->randomizer = new Randomizer($engine);
    }
}
