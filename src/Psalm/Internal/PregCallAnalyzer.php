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

use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\BinaryOp\Equal;
use PhpParser\Node\Expr\BinaryOp\Greater;
use PhpParser\Node\Expr\BinaryOp\GreaterOrEqual;
use PhpParser\Node\Expr\BinaryOp\Identical;
use PhpParser\Node\Expr\BinaryOp\NotEqual;
use PhpParser\Node\Expr\BinaryOp\NotIdentical;
use PhpParser\Node\Expr\BinaryOp\Smaller;
use PhpParser\Node\Expr\BinaryOp\SmallerOrEqual;
use PhpParser\Node\Expr\BinaryOp\Spaceship;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PHPRegex\Parser\Analysis\CaptureShape;
use PHPRegex\Parser\Analysis\CaptureShapeAnalyzer;
use PHPRegex\Parser\Engine\PcreEngine;
use PHPRegex\Parser\Exception\ExceptionInterface;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\Exception\RecursionLimitException;
use PHPRegex\Parser\Exception\ResourceLimitException;
use PHPRegex\Parser\Node\KeepNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Validation\ValidationResult;
use PHPRegex\Psalm\Plugin;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Internal\Provider\NodeDataProvider;
use Psalm\IssueBuffer;
use Psalm\NodeTypeProvider;
use Psalm\Plugin\EventHandler\AfterExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;
use Psalm\StatementsSource;
use Psalm\Storage\Assertion\IsType;
use Psalm\Storage\Possibilities;
use Psalm\Type;
use Psalm\Type\Union;

/**
 * Reads every call of a global preg_* function once Psalm analysed it.
 *
 * It reports the constant pattern the targeted PHP and PCRE2 refuse. For a
 * pattern they accept and the running engine compiles, it types $matches:
 * where preg_match() returned 1, the pattern's shape, where it did not, []
 * (no match writes [], and so does a match error), unless the call is
 * compared with something; after preg_match_all(), its shape, for a pattern
 * without \K and an offset absent or a constant <= 0. Everything else is
 * left to Psalm's stubs, which the plugin never replaces: their taint flows,
 * purity and parameter checks all stay.
 *
 * The narrowing goes through Psalm's internal node data, read behind an
 * instanceof check: should it move, the calls are left to the stubs.
 *
 * @internal
 */
final class PregCallAnalyzer implements AfterExpressionAnalysisInterface
{
    /**
     * The lowest PHP the library judges, and the first that reads the n modifier.
     */
    private const PHP_FLOOR = 80200;

    /**
     * The parameters of each function, in order: PHP binds the arguments to
     * them by position, then by name. The pattern comes first in each.
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
     * @var array<string, array{error: ?string, shape: ?CaptureShape, noAutoCapture: bool, keepsOut: bool}> by target and pattern
     */
    private static array $verdicts = [];

    /**
     * @var array<string, array{type: Union, keys: int}> by target, pattern, function, flags and Psalm's literal length
     */
    private static array $types = [];

    /**
     * The plugin's <phpVersion> and <pcreVersion>, null when not given.
     *
     * @throws InvalidRegexOptionException when either cannot be read
     */
    public static function configure(?string $phpVersion, ?string $pcreVersion): void
    {
        self::check('phpVersion', 'php_version', $phpVersion, 'a version like "8.4", a PHP_VERSION_ID like 80400, or "runtime"');
        self::check('pcreVersion', 'pcre_version', $pcreVersion, 'a PCRE2 release like "10.44"');

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
        $arguments = null === $function ? null : self::bind($call, self::PARAMETERS[$function]);
        if (null === $function || null === $arguments || !isset($arguments['pattern'])) {
            return null;
        }

        try {
            self::analyse($event, $call, $function, $arguments);
        } catch (ExceptionInterface) {
            // The library could not read the call: Psalm's stub decides.
        }

        return null;
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

        $judged = null;
        foreach (self::constantPatterns($function, $arguments['pattern'], $nodeTypes) as [$pattern, $node]) {
            $verdict = self::verdict($parser, $pattern);
            $judged = [$pattern, $verdict];
            if (null !== $verdict['error']) {
                IssueBuffer::maybeAdd(new InvalidRegexPattern($verdict['error'], new CodeLocation($source, $node)), $source->getSuppressedIssues());
            }
        }

        $matchAll = 'preg_match_all' === $function;
        $matches = $arguments['matches'] ?? null;
        if (null === $judged || !$matches instanceof Variable || !\is_string($matches->name)) {
            return;
        }

        [$pattern, $verdict] = $judged;
        $shape = $verdict['shape'];
        // PHP refuses the n modifier below 8.2: such a pattern does not compile there.
        if (null === $shape || ($verdict['noAutoCapture'] && $codebase->analysis_php_version_id < self::PHP_FLOOR)) {
            return;
        }

        // preg_match_all() returns false and leaves [] for an offset past the
        // subject, and for a match ending before it starts (\K in a lookahead).
        if ($matchAll && ($verdict['keepsOut'] || !self::offsetNeverPastTheSubject($arguments['offset'] ?? null, $nodeTypes))) {
            return;
        }

        $flags = 0;
        if (isset($arguments['flags'])) {
            $flagsType = $nodeTypes->getType($arguments['flags']);
            if (null === $flagsType || !$flagsType->isSingleIntLiteral()) {
                return;
            }
            $flags = $flagsType->getSingleIntLiteral()->value;
        }

        ['type' => $type, 'keys' => $keys] = self::type($parser, $pattern, $shape, $flags, $matchAll, $codebase);
        // Past this many keys Psalm keeps no array shape.
        if ($keys > $codebase->config->max_shaped_array_size) {
            return;
        }

        if ($matchAll) {
            // Psalm assigned the stub's type to the variable: the shape replaces it, with its data flow.
            $context = $event->getContext();
            $current = $context->vars_in_scope['$'.$matches->name] ?? null;
            if (null !== $current) {
                $context->vars_in_scope['$'.$matches->name] = $type->setParentNodes($current->parent_nodes);
            }

            return;
        }

        self::assertOnResult($nodeTypes, $call, $matches, $type);
    }

    /**
     * Where the call returned 1, $matches is the shape; where it returned 0
     * or false, []. Psalm reads these where the call is the condition or its
     * negation, and where it is compared with a boolean, which
     * dropAssertions() undoes.
     */
    private static function assertOnResult(NodeTypeProvider $nodeTypes, FuncCall $call, Variable $matches, Union $type): void
    {
        if (!$nodeTypes instanceof NodeDataProvider || !method_exists($nodeTypes, 'setIfTrueAssertions') || !method_exists($nodeTypes, 'setIfFalseAssertions') || !$type->isSingle()) {
            return; // Only on a Psalm release that moved its node data: none the tests run has.
        }

        // An assertion names the argument by its index in the call.
        $index = array_search($matches, array_map(static fn ($argument): Expr => $argument->value, $call->getArgs()), true);
        if (!\is_int($index)) {
            return; // Never: $matches is an argument of the call.
        }

        $nodeTypes->setIfTrueAssertions($call, [new Possibilities($index, [new IsType($type->getSingleAtomic())])]);
        $nodeTypes->setIfFalseAssertions($call, [new Possibilities($index, [new IsType(Type::getEmptyArrayAtomic())])]);
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
            if ($operand instanceof FuncCall && $operand->name instanceof Name && 'preg_match' === self::globalFunction($operand->name, $source, $codebase)) {
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

        $function = null === $function ? null : strtolower(ltrim($function, '\\'));

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

        return $codebase->functions->functionExists($source, strtolower($function));
    }

    /**
     * The argument of each parameter, as PHP binds them: by position, then
     * by name. Null when the call unpacks arguments or names a parameter
     * the function does not have.
     *
     * @param list<string> $parameters
     *
     * @return array<string, Expr>|null
     */
    private static function bind(FuncCall $call, array $parameters): ?array
    {
        $bound = [];
        foreach ($call->getArgs() as $position => $argument) {
            if ($argument->unpack) {
                return null;
            }

            $parameter = null === $argument->name ? ($parameters[$position] ?? null) : $argument->name->toString();
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
     * The parser judging patterns for max(the PHP Psalm analyses for, 8.2)
     * with the PCRE2 it bundles, or for what the options name.
     */
    private static function parser(Codebase $codebase): RegexParser
    {
        $options = ['php_version' => self::$phpVersion ?? max($codebase->analysis_php_version_id, self::PHP_FLOOR)];
        if (null !== self::$pcreVersion) {
            $options['pcre_version'] = self::$pcreVersion;
        }

        return self::$parsers[serialize($options)] ??= RegexParser::create($options);
    }

    /**
     * @return array{error: ?string, shape: ?CaptureShape, noAutoCapture: bool, keepsOut: bool}
     */
    private static function verdict(RegexParser $parser, string $pattern): array
    {
        return self::$verdicts[self::targetKey($parser)."\0".$pattern] ??= self::judge($parser, $pattern);
    }

    /**
     * @return array{error: ?string, shape: ?CaptureShape, noAutoCapture: bool, keepsOut: bool}
     */
    private static function judge(RegexParser $parser, string $pattern): array
    {
        try {
            $regex = $parser->parse($pattern);
        } catch (ResourceLimitException|RecursionLimitException) {
            // The library's own limits, not PCRE's: it cannot judge the pattern, which PCRE may well accept.
            return ['error' => null, 'shape' => null, 'noAutoCapture' => false, 'keepsOut' => false];
        } catch (ExceptionInterface) {
            $regex = null;
        }

        $validation = $parser->validate($pattern);
        if (!$validation->isValid) {
            return ['error' => self::message($parser, $validation), 'shape' => null, 'noAutoCapture' => false, 'keepsOut' => false];
        }

        // A pattern the running engine does not compile leaves $matches as it was: nothing is asserted on it.
        if (null === $regex || null !== (new PcreEngine())->compile($pattern)) {
            return ['error' => null, 'shape' => null, 'noAutoCapture' => false, 'keepsOut' => false]; // Only on a running PCRE2 older than the target's, as the 10.40 and 10.42 CI images.
        }

        return ['error' => null, 'shape' => (new CaptureShapeAnalyzer())->analyze($regex), 'noAutoCapture' => str_contains($regex->flags, 'n'), 'keepsOut' => self::keepsOut($regex)];
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
     * @return array{type: Union, keys: int}
     */
    private static function type(RegexParser $parser, string $pattern, CaptureShape $shape, int $flags, bool $matchAll, Codebase $codebase): array
    {
        $key = implode("\0", [self::targetKey($parser), $pattern, $matchAll ? 'all' : 'one', $flags, $codebase->config->max_string_length]);

        return self::$types[$key] ??= [
            'type' => $matchAll ? MatchesType::ofMatchAll($shape, $flags) : MatchesType::ofMatch($shape, $flags),
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
            RegexParser::create([$option => $value]);
        } catch (InvalidRegexOptionException $e) {
            throw new InvalidRegexOptionException(\sprintf('The <%s> option of %s must be %s; got "%s".', $element, Plugin::class, $expected, $value), 0, $e);
        }
    }
}
