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

namespace PHPRegex\Tests\Unit\Bridge\Infection;

use Infection\Mutator\Mutator;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

/**
 * Runs a mutator the way Infection does: the file parsed by nikic/php-parser,
 * names resolved without replacing the nodes, then canMutate() and mutate()
 * asked of every function call.
 */
final class MutatorHarness
{
    /**
     * The patterns the mutator writes for the first preg_*() call of the
     * code, each as the mutated call passes it, hint included.
     *
     * @param Mutator<Node> $mutator
     *
     * @return list<string>
     */
    public static function patterns(Mutator $mutator, string $code): array
    {
        $patterns = [];
        foreach (self::mutants($mutator, $code) as $mutant) {
            $patterns[] = self::patternOf($mutant);
        }

        return $patterns;
    }

    /**
     * The patterns with their hint taken off, as the engine runs them.
     *
     * @param Mutator<Node> $mutator
     *
     * @return list<string>
     */
    public static function bare(Mutator $mutator, string $code): array
    {
        return array_map(static fn (string $pattern): string => self::split($pattern)[0], self::patterns($mutator, $code));
    }

    /**
     * @param Mutator<Node> $mutator
     */
    public static function canMutate(Mutator $mutator, string $code): bool
    {
        $call = self::call($code);

        return null !== $call && $mutator->canMutate($call);
    }

    /**
     * The mutated calls, as Infection receives them.
     *
     * @param Mutator<Node> $mutator
     *
     * @return list<FuncCall>
     */
    public static function mutants(Mutator $mutator, string $code): array
    {
        $call = self::call($code);
        if (null === $call || !$mutator->canMutate($call)) {
            return [];
        }

        $mutants = [];
        foreach ($mutator->mutate($call) as $mutant) {
            \assert($mutant instanceof FuncCall);
            $mutants[] = $mutant;
        }

        return $mutants;
    }

    /**
     * The file as Infection prints a mutant: format preserving, the first
     * mutated call put in place of the original.
     *
     * @param Mutator<Node> $mutator
     */
    public static function printFirstMutant(Mutator $mutator, string $file): string
    {
        $parser = self::parser();
        $original = $parser->parse($file) ?? [];
        $tokens = $parser->getTokens();
        $traverser = new NodeTraverser(new CloningVisitor(), new NameResolver(null, ['preserveOriginalNames' => true, 'replaceNodes' => false]));
        $statements = $traverser->traverse($original);

        $call = (new NodeFinder())->findFirstInstanceOf($statements, FuncCall::class);
        \assert($call instanceof FuncCall && $mutator->canMutate($call));
        $mutant = null;
        foreach ($mutator->mutate($call) as $candidate) {
            $mutant = $candidate;

            break;
        }
        \assert($mutant instanceof FuncCall);

        $replace = new NodeTraverser(new class($call, $mutant) extends NodeVisitorAbstract {
            public function __construct(private readonly FuncCall $call, private readonly FuncCall $mutant) {}

            public function leaveNode(Node $node): ?Node
            {
                return $node === $this->call ? $this->mutant : null;
            }
        });

        return (new Standard())->printFormatPreserving($replace->traverse($statements), $original, $tokens);
    }

    /**
     * The pattern argument of a mutated call.
     */
    public static function patternOf(FuncCall $call): string
    {
        foreach ($call->getArgs() as $position => $arg) {
            if ((0 === $position && null === $arg->name) || 'pattern' === $arg->name?->toString()) {
                \assert($arg->value instanceof String_);

                return $arg->value->value;
            }
        }

        throw new \LogicException('The mutated call has no pattern argument.');
    }

    /**
     * The pattern without its hint, and the subject the hint names, decoded
     * as PHP reads the double-quoted literal; null when there is no hint.
     *
     * @return array{string, ?string}
     */
    public static function split(string $pattern): array
    {
        if (1 !== preg_match('~^(?<pattern>.*?)(?<newline>\n?)(?:\(\?#kill: (?<comment>"[^"]*")\)|\(\?\(DEFINE\)kill: (?<define>"[^"]*")\))(?<close>[^"]*)$~s', $pattern, $parts)) {
            return [$pattern, null];
        }

        $literal = '' !== $parts['comment'] ? $parts['comment'] : $parts['define'];

        return [$parts['pattern'].$parts['close'], self::decode($literal)];
    }

    /**
     * What PHP reads from a double-quoted literal.
     */
    public static function decode(string $literal): string
    {
        $statements = self::parser()->parse('<?php '.$literal.';') ?? [];
        $node = $statements[0] ?? null;
        \assert($node instanceof Expression && $node->expr instanceof String_);

        return $node->expr->value;
    }

    /**
     * The first function call of the code, names resolved as Infection
     * resolves them.
     */
    public static function call(string $code): ?FuncCall
    {
        $file = str_starts_with($code, '<?php') ? $code : "<?php\nfunction subject(\$s, \$m = null) {\n    return ".$code.";\n}\n";
        $statements = self::parser()->parse($file) ?? [];
        $traverser = new NodeTraverser(new NameResolver(null, ['preserveOriginalNames' => true, 'replaceNodes' => false]));
        $statements = $traverser->traverse($statements);

        $call = (new NodeFinder())->findFirstInstanceOf($statements, FuncCall::class);

        return $call instanceof FuncCall ? $call : null;
    }

    private static function parser(): Parser
    {
        return (new ParserFactory())->createForHostVersion();
    }
}
