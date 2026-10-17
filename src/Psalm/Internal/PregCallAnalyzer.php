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

namespace PHPRegex\Psalm\Internal;

use PhpParser\Error;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\BinaryOp\BitwiseOr;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\BinaryOp\Equal;
use PhpParser\Node\Expr\BinaryOp\Greater;
use PhpParser\Node\Expr\BinaryOp\GreaterOrEqual;
use PhpParser\Node\Expr\BinaryOp\Identical;
use PhpParser\Node\Expr\BinaryOp\NotEqual;
use PhpParser\Node\Expr\BinaryOp\NotIdentical;
use PhpParser\Node\Expr\BinaryOp\Smaller;
use PhpParser\Node\Expr\BinaryOp\SmallerOrEqual;
use PhpParser\Node\Expr\BinaryOp\Spaceship;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\ParserFactory;
use PHPRegex\Linter\Config\JudgedRange;
use PHPRegex\Linter\Config\ProjectTarget;
use PHPRegex\Linter\Config\UnsupportedTargetException;
use PHPRegex\Linter\Extraction\GuardRegions;
use PHPRegex\Linter\Extraction\PhpParserGuards;
use PHPRegex\Linter\Extraction\TargetMarker;
use PHPRegex\Linter\Extraction\TargetMarkers;
use PHPRegex\Parser\Analysis\CaptureShape;
use PHPRegex\Parser\Analysis\CaptureShapeAnalyzer;
use PHPRegex\Parser\Engine\PcreEngine;
use PHPRegex\Parser\Exception\ExceptionInterface;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\Internal\Ascii;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\KeepNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\PosixClassNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\ParserOptions;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Validation\ValidationErrorCategory;
use PHPRegex\Parser\Validation\ValidationResult;
use PHPRegex\Psalm\Plugin;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\Config;
use Psalm\Context;
use Psalm\Internal\Analyzer\Statements\ExpressionAnalyzer;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Internal\Provider\NodeDataProvider;
use Psalm\IssueBuffer;
use Psalm\NodeTypeProvider;
use Psalm\Plugin\EventHandler\AfterExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;
use Psalm\StatementsSource;
use Psalm\Storage\Assertion\InArray;
use Psalm\Storage\Assertion\IsType;
use Psalm\Storage\Possibilities;
use Psalm\Type;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\TFalse;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Atomic\TLiteralString;
use Psalm\Type\Atomic\TNonEmptyString;
use Psalm\Type\Atomic\TString;
use Psalm\Type\Union;

/**
 * Reads every call of a global preg_* function once Psalm analysed it.
 *
 * It reports the constant pattern the targeted PHP and PCRE2 refuse. For a
 * pattern they accept and the running engine compiles, it types $matches:
 * where preg_match() returned 1, the pattern's shape, where it did not, []
 * (no match writes [], and so does a match error), unless the call is
 * compared with something; after preg_match_all(), its shape, for a pattern
 * without \K and an offset absent or a constant <= 0. Where preg_match()
 * returned 1 and $matches is not typed, a string subject is narrowed to what
 * the pattern can match (SubjectType). preg_quote() of literals is typed as
 * the literal it returns; around preg_quote() of a text not known,
 * preg_match() types $matches from the rest of the pattern (QuotedPattern).
 * preg_split() under PREG_SPLIT_NO_EMPTY or PREG_SPLIT_OFFSET_CAPTURE
 * returns what the flags say; the callback of preg_replace_callback() is
 * typed by CallbackParameters. Everything else is left to Psalm's stubs,
 * whose taint flows, purity and parameter checks all stay: a type the plugin
 * narrows keeps the data flow of the stub's.
 *
 * The narrowing goes through Psalm's internal node data, read behind an
 * instanceof check: should it move, the calls are left to the stubs.
 *
 * @internal
 *
 * @phpstan-type PatternVerdict array{error: ?string, shape: ?CaptureShape, noAutoCapture: bool, keepsOut: bool, fewStrings: bool}
 * @phpstan-type CachedType array{type: Union, keys: int}
 */
final class PregCallAnalyzer implements AfterExpressionAnalysisInterface
{
    /**
     * The first PHP that reads the n modifier.
     */
    private const NO_AUTO_CAPTURE_FLOOR = 80200;

    /**
     * The parameters of each function, in order: PHP binds the arguments to
     * them by position, then by name. The pattern comes first in each but
     * preg_quote(), which builds one.
     */
    private const PARAMETERS = [
        'preg_match' => ['pattern', 'subject', 'matches', 'flags', 'offset'],
        'preg_match_all' => ['pattern', 'subject', 'matches', 'flags', 'offset'],
        'preg_replace' => ['pattern', 'replacement', 'subject', 'limit', 'count'],
        'preg_replace_callback' => ['pattern', 'callback', 'subject', 'limit', 'count', 'flags'],
        'preg_replace_callback_array' => ['pattern', 'subject', 'limit', 'count', 'flags'],
        'preg_split' => ['pattern', 'subject', 'limit', 'flags'],
        'preg_grep' => ['pattern', 'array', 'flags'],
        'preg_filter' => ['pattern', 'replacement', 'subject', 'limit', 'count'],
        'preg_quote' => ['str', 'delimiter'],
    ];

    /**
     * The functions that take an array of patterns, each element one.
     */
    private const PATTERN_LISTS = ['preg_replace', 'preg_replace_callback', 'preg_filter'];

    /**
     * The comparisons Psalm reads a call's assertions through.
     */
    private const COMPARISONS = [
        Identical::class,
        NotIdentical::class,
        Equal::class,
        NotEqual::class,
        Smaller::class,
        SmallerOrEqual::class,
        Greater::class,
        GreaterOrEqual::class,
        Spaceship::class,
    ];

    private static ?string $phpVersion = null;

    private static ?string $pcreVersion = null;

    /**
     * @var array<string, RegexParser> by target options
     */
    private static array $parsers = [];

    /**
     * What the library says of a pattern, read once per process.
     *
     * @var array<string, PatternVerdict> by target and pattern
     */
    private static array $verdicts = [];

    /**
     * @var array<string, CachedType> by target, pattern, function, flags and Psalm's literal length
     */
    private static array $types = [];

    /**
     * The PHP each PHP below 7.3 Psalm analyses for is judged for, by the
     * version and the directory of the composer.json Psalm read it from.
     *
     * @var array<string, int>
     */
    private static array $belowPcre2 = [];

    /**
     * The "@regex-target" markers and the guards of each file read, by
     * path, with the hash of the source they were read from.
     *
     * @var array<string, array{string, list<TargetMarker>, GuardRegions}>
     */
    private static array $markers = [];

    /**
     * The versions a marker narrows, by target.
     *
     * @var array<string, JudgedRange>
     */
    private static array $judgedRanges = [];

    /**
     * The plugin's <phpVersion> and <pcreVersion>, null when not given. A
     * PHP below 7.3 is refused as regex lint refuses it: it ships PCRE1.
     *
     * @throws InvalidRegexOptionException when either cannot be read
     * @throws UnsupportedTargetException  when <phpVersion> names a PHP that ships PCRE1
     */
    public static function configure(?string $phpVersion, ?string $pcreVersion): void
    {
        self::check('phpVersion', 'php_version', $phpVersion, 'a version like "8.4", a PHP_VERSION_ID like 80400, or "runtime"');
        self::check('pcreVersion', 'pcre_version', $pcreVersion, 'a PCRE2 release like "10.44"');

        if (null !== $phpVersion && 'runtime' !== $phpVersion) {
            $named = ParserOptions::fromArray(['php_version' => $phpVersion])->target->phpVersionId;
            if ($named < PcreTarget::phpVersionBoundaries()[0]) {
                ProjectTarget::belowPcre2($named, '<phpVersion>', null, []);
            }
        }

        self::$phpVersion = $phpVersion;
        self::$pcreVersion = $pcreVersion;
    }

    public static function afterExpressionAnalysis(AfterExpressionAnalysisEvent $event): ?bool
    {
        $call = $event->getExpr();
        $source = $event->getStatementsSource();
        $codebase = $event->getCodebase();
        if ($call instanceof BinaryOp && \in_array($call::class, self::COMPARISONS, true)) {
            self::dropAssertions($call, $source, $codebase);

            return null;
        }

        if (!$call instanceof FuncCall || !$call->name instanceof Name || $call->isFirstClassCallable()) {
            return null;
        }

        $function = self::globalFunction($call->name, $source, $codebase);
        $arguments = null === $function ? null : self::bind(array_values($call->getArgs()), self::PARAMETERS[$function]);
        if ('preg_quote' === $function && null !== $arguments) {
            self::quote($call, $arguments, $source->getNodeTypeProvider());

            return null;
        }

        if (null === $function || null === $arguments || !isset($arguments['pattern'])) {
            return null;
        }

        try {
            self::analyse($event, $call, $function, $arguments);
        } catch (UnsupportedTargetException $e) {
            // A target on PCRE1 is no call the library cannot read: the
            // run stops on it, as regex lint does.
            throw $e;
        } catch (ExceptionInterface) {
            // The library could not read the call: Psalm's stub decides.
        }

        return null;
    }

    /**
     * What the callback of a preg_replace_callback() call receives on each
     * match: what preg_match() writes for the call's pattern and flags, a
     * pattern built around preg_quote() of a text not known included, which
     * matches only where it compiles. Null when the plugin cannot read the
     * call, which leaves the callback to Psalm's stub.
     *
     * Psalm asks for the parameters before it analyses the arguments: an
     * argument is analysed here when it is a literal, a constant, a variable,
     * or a concatenation or an | of those and of preg_quote() calls, none of
     * which writes anything.
     *
     * @internal for CallbackParameters
     *
     * @param list<Arg> $args
     */
    public static function callbackMatchesType(array $args, StatementsSource $source, Context $context): ?Union
    {
        $codebase = $source->getCodebase();
        $arguments = self::bind($args, self::PARAMETERS['preg_replace_callback']);
        $nodeTypes = $source->getNodeTypeProvider();
        if (null === $arguments || !isset($arguments['pattern']) || !self::analysedInPlace($arguments['pattern'], $source, $context) || (isset($arguments['flags']) && !self::analysedInPlace($arguments['flags'], $source, $context))) {
            return null;
        }

        $parser = self::parser($codebase);

        try {
            $pattern = self::literal($arguments['pattern'], $nodeTypes) ?? self::patternAroundQuotes($arguments['pattern'], $source, $codebase, $parser);
            $verdict = null === $pattern ? null : self::verdict($parser, $pattern);
            if (null === $pattern || null === $verdict || null !== $verdict['error'] || ($verdict['noAutoCapture'] && $codebase->analysis_php_version_id < self::NO_AUTO_CAPTURE_FLOOR)) {
                return null;
            }

            return self::matchesType($parser, $pattern, $verdict, 'preg_replace_callback', $arguments, $nodeTypes, $codebase);
        } catch (ExceptionInterface) {
            return null; // The library could not read the call: Psalm's stub decides.
        }
    }

    /**
     * Why a "@regex-target" marker is reported: it cannot be read, or it
     * keeps no version of the series of Psalm's PHP. Null when it is not.
     */
    public static function markerError(TargetMarker $marker, Codebase $codebase): ?string
    {
        if (null === $marker->constraint) {
            return $marker->error;
        }

        $judged = self::judgedRange(self::parser($codebase));

        return [] === $judged->narrow($marker->constraint)
            ? \sprintf('The %s marker "%s" keeps no version the project is judged for (PHP %s): the patterns it covers are not judged.', $marker->marker, $marker->text, $judged->describe())
            : null;
    }

    /**
     * @param array<string, Expr> $arguments the argument bound to each parameter, by name
     */
    private static function analyse(AfterExpressionAnalysisEvent $event, FuncCall $call, string $function, array $arguments): void
    {
        $source = $event->getStatementsSource();
        $codebase = $event->getCodebase();
        $nodeTypes = $source->getNodeTypeProvider();
        $parser = self::parser($codebase);
        // A guard on PHP_VERSION_ID and a "@regex-target" marker narrow
        // the versions the call is judged for; the types stay those of
        // Psalm's PHP.
        $judgedBy = self::judgedBy($parser, $codebase, $source->getFilePath(), $call->getStartLine(), $call->getStartFilePos());

        $judged = null;
        foreach (self::constantPatterns($function, $arguments['pattern'], $nodeTypes) as [$pattern, $node]) {
            $verdict = self::verdict($parser, $pattern);
            $judged = [$pattern, $verdict];
            $error = null === $judgedBy ? null : ($judgedBy === $parser ? $verdict : self::verdict($judgedBy, $pattern))['error'];
            if (null !== $error) {
                IssueBuffer::maybeAdd(new InvalidRegexPattern($error, new CodeLocation($source, $node)), $source->getSuppressedIssues());
            }
        }

        if ('preg_split' === $function) {
            self::splitType($call, $arguments, $nodeTypes);
        }

        if (null === $judged) {
            // A pattern built around preg_quote() of a text not known types $matches from its groups.
            $quoted = 'preg_match' === $function ? self::patternAroundQuotes($arguments['pattern'], $source, $codebase, $parser) : null;
            if (null !== $quoted) {
                self::typeAroundQuotes($call, $quoted, $arguments, $nodeTypes, $parser, $codebase);
            }

            return;
        }

        [$pattern, $verdict] = $judged;
        // PHP refuses the n modifier below 8.2: such a pattern does not compile there.
        if (null === $verdict['shape'] || ($verdict['noAutoCapture'] && $codebase->analysis_php_version_id < self::NO_AUTO_CAPTURE_FLOOR)) {
            return;
        }

        $matches = $arguments['matches'] ?? null;
        $type = $matches instanceof Variable && \is_string($matches->name) ? self::matchesType($parser, $pattern, $verdict, $function, $arguments, $nodeTypes, $codebase) : null;
        if ('preg_match_all' === $function) {
            if (null !== $type && $matches instanceof Variable && \is_string($matches->name)) {
                // Psalm assigned the stub's type to the variable: the shape replaces it, with its data flow.
                $context = $event->getContext();
                $current = $context->vars_in_scope['$'.$matches->name] ?? null;
                if (null !== $current) {
                    $context->vars_in_scope['$'.$matches->name] = $type->setParentNodes($current->parent_nodes);
                }

                // Where it returned a count of 1 or more, no list is empty.
                $matched = self::matchesType($parser, $pattern, $verdict, $function, $arguments, $nodeTypes, $codebase, true);
                if (null !== $matched && $matched->isSingle()) {
                    self::assertOnResult($nodeTypes, $call, [self::assertion($call, $matches, $matched->getSingleAtomic())], []);
                }
            }

            return;
        }

        if ('preg_match' !== $function) {
            return;
        }

        // Psalm reads a false condition as the negation of what holds where it
        // is true: on two variables that negation says nothing of either, and
        // $matches would lose its [] where nothing matched. A call that types
        // $matches leaves its subject as it is.
        if (null !== $type && null !== $matches && $type->isSingle()) {
            self::assertOnResult($nodeTypes, $call, [self::assertion($call, $matches, $type->getSingleAtomic())], [self::assertion($call, $matches, Type::getEmptyArrayAtomic())]);

            return;
        }

        $subject = self::subjectType($pattern, $verdict, $arguments, $nodeTypes, $parser, $codebase);
        if (null !== $subject && isset($arguments['subject'])) {
            self::assertOnResult($nodeTypes, $call, [self::assertion($call, $arguments['subject'], $subject)], []);
        }
    }

    /**
     * preg_quote() of literals is the literal it returns, as the running PHP
     * quotes it: Psalm then folds a pattern built from it into a literal, as
     * it folds any concatenation of literals. The call keeps its data flow.
     *
     * @param array<string, Expr> $arguments
     */
    private static function quote(FuncCall $call, array $arguments, NodeTypeProvider $nodeTypes): void
    {
        $text = isset($arguments['str']) ? self::literal($arguments['str'], $nodeTypes) : null;
        $delimiterType = isset($arguments['delimiter']) ? $nodeTypes->getType($arguments['delimiter']) : Type::getNull();
        $current = $nodeTypes->getType($call);
        if (null === $text || null === $delimiterType || null === $current || !($delimiterType->isNull() || $delimiterType->isSingleStringLiteral())) {
            return;
        }

        $quoted = preg_quote($text, $delimiterType->isSingleStringLiteral() ? $delimiterType->getSingleStringLiteral()->value : null);
        // Psalm keeps a literal only below maxStringLength bytes.
        if (\strlen($quoted) < Config::getInstance()->max_string_length) {
            $nodeTypes->setType($call, (new Union([TLiteralString::make($quoted)]))->setParentNodes($current->parent_nodes));
        }
    }

    /**
     * The pattern standing for a concatenation of literals and preg_quote()
     * calls (QuotedPattern), or null for any other expression.
     */
    private static function patternAroundQuotes(Expr $argument, StatementsSource $source, Codebase $codebase, RegexParser $parser): ?string
    {
        $nodeTypes = $source->getNodeTypeProvider();
        $parts = [];
        $pending = [$argument];
        while ([] !== $pending) {
            $part = array_shift($pending);
            $text = self::literal($part, $nodeTypes);
            if (null !== $text) {
                $parts[] = $text;
            } elseif ($part instanceof Concat) {
                array_unshift($pending, $part->left, $part->right);
            } elseif ($part instanceof FuncCall && $part->name instanceof Name && !$part->isFirstClassCallable() && 'preg_quote' === self::globalFunction($part->name, $source, $codebase)) {
                $parts[] = null;
            } else {
                return null;
            }
        }

        return $argument instanceof Concat && [] !== $parts ? QuotedPattern::of($parts, $parser) : null;
    }

    /**
     * Where preg_match() returned 1, $matches is the shape of the pattern
     * standing for the quoted texts. Where it did not, $matches is left as
     * it is: a quoted text holding the delimiter makes the pattern fail to
     * compile, and the call then leaves $matches as it was.
     *
     * @param array<string, Expr> $arguments
     */
    private static function typeAroundQuotes(FuncCall $call, string $pattern, array $arguments, NodeTypeProvider $nodeTypes, RegexParser $parser, Codebase $codebase): void
    {
        $verdict = self::verdict($parser, $pattern);
        $matches = $arguments['matches'] ?? null;
        // Never reported: the pattern stands for the groups, not for what the call compiles.
        if (null !== $verdict['error'] || null === $verdict['shape'] || ($verdict['noAutoCapture'] && $codebase->analysis_php_version_id < self::NO_AUTO_CAPTURE_FLOOR) || !$matches instanceof Variable) {
            return;
        }

        $type = self::matchesType($parser, $pattern, $verdict, 'preg_match', $arguments, $nodeTypes, $codebase);
        if (null !== $type && $type->isSingle()) {
            self::assertOnResult($nodeTypes, $call, [self::assertion($call, $matches, $type->getSingleAtomic())], []);
        }
    }

    /**
     * Whether the argument was analysed, which it is only when it writes
     * nothing: analysing it again, in its turn, changes nothing either.
     */
    private static function analysedInPlace(Expr $argument, StatementsSource $source, Context $context): bool
    {
        if (!$source instanceof StatementsAnalyzer || !self::writesNothing($argument, $source)) {
            return false; // A source of another kind never: Psalm asks while it analyses a call.
        }

        return false !== ExpressionAnalyzer::analyze($source, $argument, $context);
    }

    /**
     * Whether analysing the expression changes nothing: a literal, a
     * constant, a variable, or a concatenation or an | of those and of
     * preg_quote() calls.
     */
    private static function writesNothing(Expr $expr, StatementsSource $source): bool
    {
        if ($expr instanceof FuncCall) {
            if (!$expr->name instanceof Name || $expr->isFirstClassCallable() || 'preg_quote' !== self::globalFunction($expr->name, $source, $source->getCodebase())) {
                return false;
            }

            foreach ($expr->getArgs() as $argument) {
                if ($argument->unpack || $argument->byRef || !self::writesNothing($argument->value, $source)) {
                    return false;
                }
            }

            return true;
        }

        return match (true) {
            $expr instanceof String_, $expr instanceof Int_, $expr instanceof ConstFetch, $expr instanceof Variable => true,
            $expr instanceof ClassConstFetch => $expr->class instanceof Name && $expr->name instanceof Identifier,
            $expr instanceof Concat, $expr instanceof BitwiseOr => self::writesNothing($expr->left, $source) && self::writesNothing($expr->right, $source),
            default => false,
        };
    }

    /**
     * preg_split() leaves out the empty pieces under PREG_SPLIT_NO_EMPTY,
     * and writes each piece with its offset in the subject under
     * PREG_SPLIT_OFFSET_CAPTURE; it writes at least one piece otherwise, the
     * whole subject when the pattern never matches. Psalm's stub says the
     * list may be empty under PREG_SPLIT_OFFSET_CAPTURE, and says nothing of
     * the pieces under PREG_SPLIT_NO_EMPTY or of the offsets. The call keeps
     * its false, which Psalm ignores as its stub does, and its data flow.
     *
     * @param array<string, Expr> $arguments
     */
    private static function splitType(FuncCall $call, array $arguments, NodeTypeProvider $nodeTypes): void
    {
        $flagsType = isset($arguments['flags']) ? $nodeTypes->getType($arguments['flags']) : null;
        $current = $nodeTypes->getType($call);
        // PHP reads the three low bits of the flags and ignores the others.
        $flags = null !== $flagsType && $flagsType->isSingleIntLiteral() ? $flagsType->getSingleIntLiteral()->value & (\PREG_SPLIT_NO_EMPTY | \PREG_SPLIT_DELIM_CAPTURE | \PREG_SPLIT_OFFSET_CAPTURE) : 0;
        if (null === $current || 0 === ($flags & (\PREG_SPLIT_NO_EMPTY | \PREG_SPLIT_OFFSET_CAPTURE))) {
            return; // The stub says it: a non-empty-list<string>.
        }

        $noEmpty = 0 !== ($flags & \PREG_SPLIT_NO_EMPTY);
        $piece = new Union([$noEmpty ? new TNonEmptyString() : new TString()]);
        if (0 !== ($flags & \PREG_SPLIT_OFFSET_CAPTURE)) {
            $piece = new Union([TKeyedArray::make([$piece, Type::getIntRange(0, null)], null, null, true)]);
        }

        $type = new Union([($noEmpty ? Type::getList($piece) : Type::getNonEmptyList($piece))->getSingleAtomic(), new TFalse()]);
        $nodeTypes->setType($call, $type->setProperties(['ignore_falsable_issues' => $current->ignore_falsable_issues])->setParentNodes($current->parent_nodes));
    }

    /**
     * The type of $matches the call writes, or null when the plugin leaves
     * it to Psalm.
     *
     * @param PatternVerdict      $verdict
     * @param array<string, Expr> $arguments
     */
    private static function matchesType(RegexParser $parser, string $pattern, array $verdict, string $function, array $arguments, NodeTypeProvider $nodeTypes, Codebase $codebase, bool $matched = false): ?Union
    {
        $shape = $verdict['shape'];
        $matchAll = 'preg_match_all' === $function;
        if (null === $shape || ($matchAll && ($verdict['keepsOut'] || !self::offsetNeverPastTheSubject($arguments['offset'] ?? null, $nodeTypes)))) {
            // preg_match_all() returns false and leaves [] for an offset past the
            // subject, and for a match ending before it starts (\K in a lookahead).
            return null;
        }

        $flags = 0;
        if (isset($arguments['flags'])) {
            $flagsType = $nodeTypes->getType($arguments['flags']);
            if (null === $flagsType || !$flagsType->isSingleIntLiteral()) {
                return null;
            }
            $flags = $flagsType->getSingleIntLiteral()->value;
        }

        ['type' => $type, 'keys' => $keys] = self::type($parser, $pattern, $shape, $flags, $matchAll, $codebase, $matched);

        // Past this many keys Psalm keeps no array shape.
        return $keys > $codebase->config->max_shaped_array_size ? null : $type;
    }

    /**
     * What a string subject holds where preg_match() returned 1, or null
     * when it is not narrowed: a subject that may be another type is read as
     * a string, and a subject read from $matches is gone once the call
     * wrote it.
     *
     * From offset 0, a pattern that matches only a few strings makes the
     * subject one of them. Not for a subject that holds literals already:
     * where the call is false, Psalm would take those strings out of it,
     * and a match error returns false on them too.
     *
     * @param PatternVerdict      $verdict
     * @param array<string, Expr> $arguments
     */
    private static function subjectType(string $pattern, array $verdict, array $arguments, NodeTypeProvider $nodeTypes, RegexParser $parser, Codebase $codebase): Atomic|Union|null
    {
        $subject = $arguments['subject'] ?? null;
        $type = null === $subject ? null : $nodeTypes->getType($subject);
        if (null === $subject || null === $type || !$type->isString() || self::readsFromMatches($subject, $arguments['matches'] ?? null)) {
            return null;
        }

        $offset = 0;
        if (isset($arguments['offset'])) {
            $offsetType = $nodeTypes->getType($arguments['offset']);
            $offset = null !== $offsetType && $offsetType->isSingleIntLiteral() ? $offsetType->getSingleIntLiteral()->value : null;
        }

        $strings = $verdict['fewStrings'] && !isset($arguments['offset']) && !$type->hasLiteralString() ? SubjectType::stringsOfMatch($pattern, $parser, $codebase->config->max_string_length) : null;
        if (null !== $strings) {
            return new Union(array_map(TLiteralString::make(...), $strings));
        }

        return SubjectType::ofMatch($pattern, $offset);
    }

    /**
     * Whether the subject may be read from the variable $matches is written
     * to: the same variable, an element or a property of it. When $matches
     * is not a variable, only a variable subject is known to be apart.
     */
    private static function readsFromMatches(Expr $subject, ?Expr $matches): bool
    {
        if (null === $matches) {
            return false;
        }

        $root = $subject;
        while ($root instanceof ArrayDimFetch || $root instanceof PropertyFetch || $root instanceof NullsafePropertyFetch) {
            $root = $root->var;
        }

        if (!$matches instanceof Variable || !\is_string($matches->name)) {
            return !$subject instanceof Variable;
        }

        return !$root instanceof Variable || $root->name === $matches->name;
    }

    /**
     * The assertion that the argument holds the type, or null when the
     * argument cannot be found among the call's.
     */
    private static function assertion(FuncCall $call, Expr $argument, Atomic|Union $type): ?Possibilities
    {
        // An assertion names the argument by its index in the call.
        $index = array_search($argument, array_map(static fn ($argument): Expr => $argument->value, $call->getArgs()), true);

        // A union of literals is asserted as in_array() asserts it.
        $assertion = $type instanceof Union ? new InArray($type) : new IsType($type);

        return \is_int($index) ? new Possibilities($index, [$assertion]) : null; // Never null: the argument is the call's.
    }

    /**
     * Where the call returned 1, $matches is the shape, or the subject is
     * narrowed; where it returned 0 or false, $matches is []. Psalm reads
     * these where the call is the condition or its negation, and where it is
     * compared with a boolean, which dropAssertions() undoes. Where the call
     * is false, Psalm also reads the negation of what holds where it is
     * true: "not a non-empty-string" leaves a string as it is.
     *
     * @param list<Possibilities|null> $ifTrue
     * @param list<Possibilities|null> $ifFalse
     */
    private static function assertOnResult(NodeTypeProvider $nodeTypes, FuncCall $call, array $ifTrue, array $ifFalse): void
    {
        if (!$nodeTypes instanceof NodeDataProvider || !method_exists($nodeTypes, 'setIfTrueAssertions') || !method_exists($nodeTypes, 'setIfFalseAssertions')) {
            return; // Only on a Psalm release that moved its node data: none the tests run has.
        }

        $nodeTypes->setIfTrueAssertions($call, array_values(array_filter($ifTrue)));
        if ([] !== $ifFalse) {
            $nodeTypes->setIfFalseAssertions($call, array_values(array_filter($ifFalse)));
        }
    }

    /**
     * Psalm reads a call's assertions through a comparison as if the call
     * returned a boolean: "preg_match(…) !== false" would type $matches as
     * the shape where no match left [] (0 !== false). Where a preg_match()
     * call is an operand, its assertions go: Psalm analyses the comparison
     * before it reads them. The stub asserts nothing on the call.
     */
    private static function dropAssertions(BinaryOp $comparison, StatementsSource $source, Codebase $codebase): void
    {
        $nodeTypes = $source->getNodeTypeProvider();
        if (!$nodeTypes instanceof NodeDataProvider || !method_exists($nodeTypes, 'setIfTrueAssertions') || !method_exists($nodeTypes, 'setIfFalseAssertions')) {
            return; // Only on a Psalm release that moved its node data: none the tests run has.
        }

        foreach ([$comparison->left, $comparison->right] as $operand) {
            if ($operand instanceof FuncCall && $operand->name instanceof Name && \in_array(self::globalFunction($operand->name, $source, $codebase), ['preg_match', 'preg_match_all'], true)) {
                $nodeTypes->setIfTrueAssertions($operand, []);
                $nodeTypes->setIfFalseAssertions($operand, []);
            }
        }
    }

    /**
     * Whether the offset argument is absent or a constant <= 0: PHP clamps
     * a negative offset to the start of the subject.
     */
    private static function offsetNeverPastTheSubject(?Expr $offset, NodeTypeProvider $nodeTypes): bool
    {
        if (null === $offset) {
            return true;
        }

        $type = $nodeTypes->getType($offset);

        return null !== $type && $type->isSingleIntLiteral() && $type->getSingleIntLiteral()->value <= 0;
    }

    /**
     * The global preg_* function the call reaches, lowercase, or null. An
     * unqualified call in a namespace reaches the namespace's function of
     * that name when there is one, as PHP resolves it.
     */
    private static function globalFunction(Name $name, StatementsSource $source, Codebase $codebase): ?string
    {
        $resolved = $name->getAttribute('resolvedName');
        $namespaced = $name->getAttribute('namespacedName');
        $function = match (true) {
            $name instanceof FullyQualified => $name->toString(),
            // "use function" imported it, or the name is qualified.
            \is_string($resolved) => $resolved,
            \is_string($namespaced) && self::functionExists($namespaced, $source, $codebase) => null,
            default => $name->toString(),
        };

        $function = null === $function ? null : Ascii::lower(ltrim($function, '\\'));

        return null !== $function && isset(self::PARAMETERS[$function]) ? $function : null;
    }

    /**
     * Whether Psalm knows a function of that name; true when it cannot be
     * asked, so the call is left alone.
     */
    private static function functionExists(string $function, StatementsSource $source, Codebase $codebase): bool
    {
        if (!$source instanceof StatementsAnalyzer || !method_exists($codebase->functions, 'functionExists')) {
            return true; // Only on a Psalm release that moved these: none the tests run has.
        }

        // Psalm keys its functions by strtolower(), locale and all: the name
        // is lowered the same way to be found.
        return $codebase->functions->functionExists($source, strtolower($function));
    }

    /**
     * The argument of each parameter, as PHP binds them: by position, then
     * by name. Null when the call unpacks arguments or names a parameter
     * the function does not have.
     *
     * @param list<Arg>    $arguments
     * @param list<string> $parameters
     *
     * @return array<string, Expr>|null
     */
    private static function bind(array $arguments, array $parameters): ?array
    {
        $bound = [];
        foreach ($arguments as $position => $argument) {
            if ($argument->unpack) {
                return null;
            }

            $parameter = $argument->name?->toString() ?? $parameters[$position] ?? null;
            if (null === $parameter || !\in_array($parameter, $parameters, true)) {
                return null;
            }

            $bound[$parameter] = $argument->value;
        }

        return $bound;
    }

    /**
     * Each constant pattern of the argument, with the node an issue points
     * at: the argument itself when its type is one literal string, else
     * each element of an array of patterns (each key for
     * preg_replace_callback_array()).
     *
     * @return list<array{string, Expr}>
     */
    private static function constantPatterns(string $function, Expr $argument, NodeTypeProvider $nodeTypes): array
    {
        $pattern = self::literal($argument, $nodeTypes);
        if (null !== $pattern) {
            return 'preg_replace_callback_array' === $function ? [] : [[$pattern, $argument]];
        }

        $keys = 'preg_replace_callback_array' === $function;
        if (!$argument instanceof Array_ || (!$keys && !\in_array($function, self::PATTERN_LISTS, true))) {
            return [];
        }

        $patterns = [];
        foreach ($argument->items as $item) {
            $node = $item instanceof ArrayItem && !$item->unpack ? ($keys ? $item->key : $item->value) : null;
            $pattern = null === $node ? null : self::literal($node, $nodeTypes);
            if (null !== $node && null !== $pattern) {
                $patterns[] = [$pattern, $node];
            }
        }

        return $patterns;
    }

    /**
     * The value of an expression whose type is one literal string: what the
     * plugin calls a constant pattern.
     */
    private static function literal(Expr $node, NodeTypeProvider $nodeTypes): ?string
    {
        $type = $nodeTypes->getType($node);

        return null !== $type && $type->isSingleStringLiteral() ? $type->getSingleStringLiteral()->value : null;
    }

    /**
     * The parser judging patterns for the PHP Psalm analyses for, with the
     * PCRE2 it bundles, or for what the options name. A PHP below 7.3 is
     * judged as regex lint judges it: from the lowest PHP on PCRE2 of the
     * composer.json Psalm read it from, refused when Psalm was told it.
     *
     * @throws UnsupportedTargetException when the PHP Psalm analyses for ships PCRE1
     */
    private static function parser(Codebase $codebase): RegexParser
    {
        $phpVersion = self::$phpVersion ?? $codebase->analysis_php_version_id;
        if (\is_int($phpVersion) && $phpVersion < PcreTarget::phpVersionBoundaries()[0]) {
            $composer = 'composer' === $codebase->php_version_source ? $codebase->config->base_dir : null;
            $phpVersion = self::$belowPcre2[$phpVersion.'@'.($composer ?? '')] ??= ProjectTarget::belowPcre2($phpVersion, 'Psalm phpVersion', $composer, getenv())->target()->phpVersionId;
        }

        $options = ['php_version' => $phpVersion];
        if (null !== self::$pcreVersion) {
            $options['pcre_version'] = self::$pcreVersion;
        }

        return self::$parsers[serialize($options)] ??= RegexParser::create($options);
    }

    /**
     * The parser judging a call the guards around it and the
     * "@regex-target" markers of its line cover: that of the lowest version
     * they keep, Psalm's when they keep it or none covers the call; null
     * when they keep no version.
     */
    private static function judgedBy(RegexParser $parser, Codebase $codebase, string $file, int $line, int $offset): ?RegexParser
    {
        // Read again when the file changed, as under the language server.
        $content = $codebase->getFileContents($file);
        $hash = hash('xxh128', $content);
        if ($hash !== (self::$markers[$file][0] ?? null)) {
            self::$markers[$file] = [$hash, TargetMarkers::read($content), self::guards($content)];
        }
        [, $markers, $guards] = self::$markers[$file];
        $constraint = TargetMarker::constraintAt($markers, $line);
        $guard = $guards->at($offset);
        $constraint = null === $guard ? $constraint : ($constraint?->intersect($guard) ?? $guard);
        if (null === $constraint) {
            return $parser;
        }

        $lowest = self::judgedRange($parser)->narrow($constraint)[0] ?? null;

        return null === $lowest ? null : self::judgedRange($parser)->parserFor($lowest);
    }

    /**
     * The guards on PHP_VERSION_ID of a file, read from its tree as `regex
     * lint` reads them. Psalm's own narrowing of PHP_VERSION_ID is not
     * read: it narrows the constant under function_exists() too, which a
     * polyfill makes true on any version. A file PHP cannot parse holds
     * none: the call is judged for the whole range.
     */
    private static function guards(string $content): GuardRegions
    {
        if (!str_contains($content, 'PHP_VERSION')) {
            return new GuardRegions();
        }

        try {
            $ast = (new ParserFactory())->createForHostVersion()->parse($content);
        } catch (Error|\RangeException) {
            // A RangeException is the parser meeting a token of a PHP newer
            // than itself, as for the lint.
            return new GuardRegions();
        }

        return PhpParserGuards::regions($ast ?? [], $content);
    }

    /**
     * The series of Psalm's PHP: the versions a marker narrows.
     */
    private static function judgedRange(RegexParser $parser): JudgedRange
    {
        $target = $parser->target();

        return self::$judgedRanges[self::targetKey($parser)] ??= new JudgedRange([$target], [[$target->phpVersionId, (intdiv($target->phpVersionId, 100) + 1) * 100]], self::$pcreVersion, $parser);
    }

    /**
     * @return PatternVerdict
     */
    private static function verdict(RegexParser $parser, string $pattern): array
    {
        return self::$verdicts[self::targetKey($parser)."\0".$pattern] ??= self::judge($parser, $pattern);
    }

    /**
     * @return PatternVerdict
     */
    private static function judge(RegexParser $parser, string $pattern): array
    {
        try {
            $regex = $parser->parse($pattern);
        } catch (ExceptionInterface) {
            $regex = null;
        }

        $validation = $parser->validate($pattern);
        // The library's own limits, not PCRE's: it cannot judge the pattern, which PCRE may well accept.
        if (ValidationErrorCategory::Limit === $validation->category) {
            return ['error' => null, 'shape' => null, 'noAutoCapture' => false, 'keepsOut' => false, 'fewStrings' => false];
        }

        if (!$validation->isValid) {
            return ['error' => self::message($parser, $validation), 'shape' => null, 'noAutoCapture' => false, 'keepsOut' => false, 'fewStrings' => false];
        }

        // A pattern the running engine does not compile leaves $matches as it was: nothing is asserted on it.
        if (null === $regex || null !== (new PcreEngine())->compile($pattern)) {
            return ['error' => null, 'shape' => null, 'noAutoCapture' => false, 'keepsOut' => false, 'fewStrings' => false]; // Only on a running PCRE2 older than the target's, as the 10.40 and 10.42 CI images.
        }

        $shape = (new CaptureShapeAnalyzer())->analyze($regex);

        return [
            'error' => null,
            'shape' => $shape,
            'noAutoCapture' => str_contains($regex->flags, 'n'),
            'keepsOut' => self::keepsOut($regex),
            // Only a match of bounded length, from the start of the subject, may leave a few strings to read.
            'fewStrings' => null !== $shape->whole->maxLength && self::startsAtTheStart($regex->pattern) && (str_contains($regex->flags, 'u') || !self::readsTheLocale($regex)),
        ];
    }

    /**
     * Whether every match starts at the start of the subject, as far as the
     * tree shows it: ^ or \A first in each branch.
     */
    private static function startsAtTheStart(NodeInterface $node): bool
    {
        if ($node instanceof AlternationNode) {
            foreach ($node->alternatives as $alternative) {
                if (!self::startsAtTheStart($alternative)) {
                    return false;
                }
            }

            return [] !== $node->alternatives;
        }

        return match (true) {
            $node instanceof AnchorNode => '^' === $node->value,
            $node instanceof AssertionNode => 'A' === $node->value,
            $node instanceof SequenceNode => [] !== $node->children && self::startsAtTheStart($node->children[0]),
            $node instanceof GroupNode => \in_array($node->type, [GroupType::Capturing, GroupType::Named, GroupType::NonCapturing, GroupType::Atomic], true) && self::startsAtTheStart($node->child),
            default => false,
        };
    }

    /**
     * Whether, without /u, the pattern reads PCRE's character tables, which
     * PHP builds from the locale setlocale() sets: a caseless part, a
     * shorthand class, a POSIX class or a word boundary. After
     * setlocale(LC_CTYPE, 'fr_FR.ISO8859-1'), preg_match('/^\w$/', "\xE9")
     * returns 1, where it returns 0 under the C locale the solver reads.
     */
    private static function readsTheLocale(NodeInterface $node): bool
    {
        if ($node instanceof RegexNode && str_contains($node->flags, 'i')) {
            return true;
        }

        if ($node instanceof CharTypeNode || $node instanceof PosixClassNode
            || ($node instanceof AssertionNode && \in_array($node->value, ['b', 'B'], true))
            || ($node instanceof GroupNode && str_contains((string) $node->flags, 'i'))) {
            return true;
        }

        foreach ($node->getChildren() as $child) {
            if (self::readsTheLocale($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a \K stands anywhere in the tree.
     */
    private static function keepsOut(NodeInterface $node): bool
    {
        if ($node instanceof KeepNode) {
            return true;
        }

        foreach ($node->getChildren() as $child) {
            if (self::keepsOut($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * "Regex pattern is invalid for PHP 8.4 with PCRE2 10.44: <the
     * library's error> (offset 3).", as the PHPStan extension words it.
     */
    private static function message(RegexParser $parser, ValidationResult $validation): string
    {
        $target = $parser->target();
        $reason = rtrim(explode("\n", $validation->error ?? 'Invalid regex.')[0], '.');

        return \sprintf(
            'Regex pattern is invalid for PHP %d.%d with PCRE2 %s: %s%s.',
            intdiv($target->phpVersionId, 10000),
            intdiv($target->phpVersionId, 100) % 100,
            $target->pcreVersion,
            $reason,
            null === $validation->offset ? '' : ' (offset '.$validation->offset.')',
        );
    }

    /**
     * @return CachedType
     */
    private static function type(RegexParser $parser, string $pattern, CaptureShape $shape, int $flags, bool $matchAll, Codebase $codebase, bool $matched = false): array
    {
        $key = implode("\0", [self::targetKey($parser), $pattern, $matchAll ? ($matched ? 'all matched' : 'all') : 'one', $flags, $codebase->config->max_string_length]);

        return self::$types[$key] ??= [
            'type' => $matchAll ? MatchesType::ofMatchAll($shape, $flags, $matched) : MatchesType::ofMatch($shape, $flags),
            'keys' => MatchesType::keyCount($shape, $flags, $matchAll),
        ];
    }

    /**
     * The PHP release and the PCRE2 judged: a rule may depend on a patch release.
     */
    private static function targetKey(RegexParser $parser): string
    {
        return $parser->target()->phpVersionId.'/'.$parser->target()->pcreVersion;
    }

    /**
     * @throws InvalidRegexOptionException when the library cannot read the value
     */
    private static function check(string $element, string $option, ?string $value, string $expected): void
    {
        if (null === $value) {
            return;
        }

        try {
            ParserOptions::fromArray([$option => $value]);
        } catch (InvalidRegexOptionException $e) {
            throw new InvalidRegexOptionException(\sprintf('The <%s> option of %s must be %s; got "%s".', $element, Plugin::class, $expected, $value), 0, $e);
        }
    }
}
