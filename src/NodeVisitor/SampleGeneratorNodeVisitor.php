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

use Random\Engine\Mt19937;
use Random\Randomizer;
use RegexParser\Node;
use RegexParser\Node\AlternationNode;
use RegexParser\Node\AnchorNode;
use RegexParser\Node\AssertionNode;
use RegexParser\Node\BackrefNode;
use RegexParser\Node\CalloutNode;
use RegexParser\Node\CharClassNode;
use RegexParser\Node\CharLiteralNode;
use RegexParser\Node\CharTypeNode;
use RegexParser\Node\ClassOperationNode;
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
use RegexParser\Node\QuantifierBounds;
use RegexParser\Node\QuantifierNode;
use RegexParser\Node\RangeNode;
use RegexParser\Node\RegexNode;
use RegexParser\Node\ScriptRunNode;
use RegexParser\Node\SequenceNode;
use RegexParser\Node\SubroutineNode;
use RegexParser\Node\UnicodePropNode;
use RegexParser\Node\VersionConditionNode;

/**
 * A visitor that generates a random sample string that matches the AST.
 *
 * @extends AbstractNodeVisitor<string>
 */
final class SampleGeneratorNodeVisitor extends AbstractNodeVisitor
{
    private const MAX_RECURSION_DEPTH = 2;

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
     * @var array<int, Node\GroupNode>
     */
    private array $groupIndexMap = [];

    /**
     * @var array<string, Node\GroupNode>
     */
    private array $namedGroupMap = [];

    /**
     * @var array<int, int>
     */
    private array $groupNumbers = [];

    private int $groupDefinitionCounter = 1;

    private int $totalGroupCount = 0;

    private bool $unicode = false;

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
     * @param int $maxRepetition Maximum repetitions for quantifiers like `*` or `+`
     */
    public function __construct(private readonly int $maxRepetition = 3)
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
        $this->groupDefinitionCounter = 1;
        $this->requiredPrefixes = [];
        $this->requiredSuffixes = [];
        $this->collectGroups($node->pattern);
        $this->totalGroupCount = $this->groupDefinitionCounter - 1;
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

        // Pick one of the alternatives at random
        $randomKey = $this->randomInt(0, \count($node->alternatives) - 1);
        $chosenAlt = $node->alternatives[$randomKey];

        return $chosenAlt->accept($this);
    }

    #[\Override]
    public function visitSequence(SequenceNode $node): string
    {
        $parts = array_map(fn (NodeInterface $child): string => $child->accept($this), $node->children);

        return implode('', $parts);
    }

    #[\Override]
    public function visitGroup(GroupNode $node): string
    {
        // Lookarounds are zero-width assertions and should not generate text
        if (\in_array($node->type, [
            GroupType::T_GROUP_LOOKAHEAD_POSITIVE,
            GroupType::T_GROUP_LOOKAHEAD_NEGATIVE,
            GroupType::T_GROUP_LOOKBEHIND_POSITIVE,
            GroupType::T_GROUP_LOOKBEHIND_NEGATIVE,
        ], true)) {
            if (GroupType::T_GROUP_LOOKBEHIND_POSITIVE === $node->type) {
                $prefix = $node->child->accept($this);
                if ('' !== $prefix) {
                    $this->requiredPrefixes[] = $prefix;
                }
            } elseif (GroupType::T_GROUP_LOOKAHEAD_POSITIVE === $node->type) {
                $suffix = $node->child->accept($this);
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
            $this->captures[$groupIndex] = $result;
            $this->groupCounter = max($this->groupCounter, $groupIndex + 1);
        } elseif (GroupType::T_GROUP_NAMED === $node->type) {
            $groupIndex = $this->groupNumbers[spl_object_id($node)] ?? $this->groupCounter++;
            $this->captures[$groupIndex] = $result;
            if ($node->name) {
                $this->captures[$node->name] = $result;
            }
            $this->groupCounter = max($this->groupCounter, $groupIndex + 1);
        }

        // For non-capturing, etc., just return the child's result
        return $result;
    }

    #[\Override]
    public function visitQuantifier(QuantifierNode $node): string
    {
        [$min, $max] = $this->parseQuantifierRange($node->quantifier);

        // Pick a random number of repetitions
        // $min and $max are guaranteed to be in the correct order
        // by parseQuantifierRange()
        $repeats = ($min === $max) ? $min : $this->randomInt($min, $max);

        $parts = [];
        for ($i = 0; $i < $repeats; $i++) {
            $parts[] = $node->node->accept($this);
        }

        return implode('', $parts);
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
    public function visitDot(DotNode $node): string
    {
        // Generate a random, simple, printable ASCII char
        return $this->getRandomChar(['a', 'b', 'c', '1', '2', '3', ' ']);
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
            // e.g., [] which can never match
            throw new \RuntimeException('Cannot generate sample for empty character class');
        }

        // Pick one of the parts at random
        $randomKey = $this->randomInt(0, \count($parts) - 1);

        return $parts[$randomKey]->accept($this);
    }

    #[\Override]
    public function visitRange(RangeNode $node): string
    {
        if (!$node->start instanceof LiteralNode || !$node->end instanceof LiteralNode) {
            // Should be caught by Validator, but good to check
            return $node->start->accept($this);
        }

        // Generate a random character within the ASCII range
        try {
            // The first byte of each end, as ord() read it before PHP 8.5
            // deprecated passing it anything but one byte.
            $ord1 = '' === $node->start->value ? 0 : \ord($node->start->value[0]);
            $ord2 = '' === $node->end->value ? 0 : \ord($node->end->value[0]);

            return \chr($this->randomInt($ord1, $ord2) & 0xFF);
        } catch (\Throwable) {
            // Fallback if ord() fails
            return $node->start->value;
        }
    }

    #[\Override]
    public function visitBackref(BackrefNode $node): string
    {
        $ref = $node->ref;

        // Check numeric reference first
        if (ctype_digit($ref)) {
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

            // "\NN" that names no group is an octal escape, then digits.
            if ($key >= 10 && $key > $this->totalGroupCount && 1 === preg_match('/^([0-7]{1,3})(\d*)$/', $matches[1], $octal)) {
                $value = (int) octdec($octal[1]);

                return ($this->unicode ? (string) mb_chr($value, 'UTF-8') : \chr($value & 0xFF)).$octal[2];
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

        // Handle named \k<name> or \k{name} backrefs
        // $ref is guaranteed to be a string here.
        if (preg_match('/^\\\\k<(\w++)>$/', $ref, $m) || preg_match('/^\\\\k\{(\w++)\}$/', $ref, $m)) {
            return $this->captures[$m[1]] ?? '';
        }

        // Backreference to a group that hasn't matched yet
        // (or doesn't exist). In a real engine, this fails the match.
        // For generation, we must return empty string.
        return '';
    }

    /**
     * @deprecated the parser no longer builds a ClassOperationNode; this method goes in the next major version
     */
    #[\Override]
    public function visitClassOperation(ClassOperationNode $node): string
    {
        // Best-effort: a member of the left operand usually satisfies the operation.
        return $node->left->accept($this);
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
            throw new \LogicException('Sample generation for subroutines is not supported.');
        }

        if ($this->recursionDepth >= self::MAX_RECURSION_DEPTH) {
            return '';
        }

        $target = $this->resolveSubroutineTarget($node);
        if (null === $target) {
            throw new \LogicException('Sample generation for subroutines is not supported.');
        }

        $this->recursionDepth++;
        $result = $target instanceof GroupNode ? $target->child->accept($this) : $target->accept($this);
        $this->recursionDepth--;

        return $result;
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

    /**
     * Up to eight characters the escape "\p{...}" matches: common ones first,
     * then the whole range, one chunk at a time; bytes without UTF mode.
     *
     * @return list<string>
     */
    private function charactersWithProperty(string $escape): array
    {
        $key = ($this->unicode ? 'u' : 'b').$escape;
        if (isset(self::$propertySamples[$key])) {
            return self::$propertySamples[$key];
        }

        $pattern = '/'.$escape.'/'.($this->unicode ? 'u' : '');
        $found = [];
        foreach ($this->unicode ? self::codePointChunks() : [implode('', array_map(\chr(...), range(0, 255)))] as $chunk) {
            if (false === @preg_match_all($pattern, $chunk, $matches)) {
                break;
            }

            foreach ($matches[0] as $character) {
                $found[$character] = true;
                if (\count($found) >= 8) {
                    break 2;
                }
            }
        }

        return self::$propertySamples[$key] = array_map(strval(...), array_keys($found));
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
            $compiled = $node->accept(new CompilerNodeVisitor());
        } catch (\Throwable) {
            return '!';
        }

        foreach (['/', '~', '#', '%', '@', ';'] as $delimiter) {
            if (str_contains($compiled, $delimiter)) {
                continue;
            }

            $classPattern = $delimiter.$compiled.$delimiter;
            foreach ($candidates as $candidate) {
                if (1 === @preg_match($classPattern, $candidate)) {
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
            if (\in_array($condition->type, [
                GroupType::T_GROUP_LOOKAHEAD_POSITIVE,
                GroupType::T_GROUP_LOOKBEHIND_POSITIVE,
            ], true)) {
                return true;
            }

            if (\in_array($condition->type, [
                GroupType::T_GROUP_LOOKAHEAD_NEGATIVE,
                GroupType::T_GROUP_LOOKBEHIND_NEGATIVE,
            ], true)) {
                return false;
            }

            return '' !== $condition->accept($this);
        }

        if ($condition instanceof AssertionNode) {
            return true;
        }

        return 1 === $this->randomInt(0, 1);
    }

    private function hasCaptureForReference(string $reference): bool
    {
        if (ctype_digit($reference)) {
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
            if (\in_array($node->type, [GroupType::T_GROUP_CAPTURING, GroupType::T_GROUP_NAMED], true)) {
                $index = $this->groupDefinitionCounter++;
                $this->groupIndexMap[$index] = $node;
                $this->groupNumbers[spl_object_id($node)] = $index;
                if (null !== $node->name) {
                    $this->namedGroupMap[$node->name] = $node;
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

        if (ctype_digit($ref)) {
            $index = (int) $ref;

            return $this->groupIndexMap[$index] ?? null;
        }

        if (str_starts_with($ref, '-') && ctype_digit(substr($ref, 1))) {
            $resolvedIndex = $this->totalGroupCount + (int) $ref + 1;
            if ($resolvedIndex >= 1) {
                return $this->groupIndexMap[$resolvedIndex] ?? null;
            }

            return null;
        }

        return $this->namedGroupMap[$ref] ?? null;
    }

    private function resetRandomizer(?int $seed = null): void
    {
        $engine = null === $seed ? new Mt19937() : new Mt19937($seed);
        $this->randomizer = new Randomizer($engine);
    }
}
