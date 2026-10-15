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

namespace PHPRegex\Tests\Unit\Node;

use PHPRegex\Explain\AsciiTreeRenderer;
use PHPRegex\Explain\MermaidRenderer;
use PHPRegex\Generator\SampleGenerator;
use PHPRegex\Generator\TestCaseGenerator;
use PHPRegex\Linter\PatternLinter;
use PHPRegex\Linter\Rule\RuleViolation;
use PHPRegex\Optimizer\Modernizer;
use PHPRegex\Optimizer\Rewriter;
use PHPRegex\Parser\Analysis\CaptureShapeAnalyzer;
use PHPRegex\Parser\Analysis\LiteralExtractor;
use PHPRegex\Parser\Exception\ExceptionInterface;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Printer\PatternPrinter;
use PHPRegex\Tests\Support\KeyedTreeCache;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Constraint\IsEqual;
use PHPUnit\Framework\TestCase;

/**
 * A tree built by hand, with keyed arrays given to the sequence and
 * alternation constructors, reads the same to every consumer as the tree the
 * parser builds: the same printed pattern, verdicts, issues, explanations,
 * transpiled patterns, samples, rewrites and shapes.
 *
 * The string-key rows also reach the readers that spread the children into a
 * call, where a string key would turn into a named argument (the capture
 * shape rebuild and the rewriter's sequence and alternation passes); the
 * shifted-integer rows reach every reader that indexes by position.
 */
final class KeyedChildrenSweepTest extends TestCase
{
    #[Test]
    #[DataProvider('provideKeyedPatterns')]
    public function test_keyed_children_give_every_consumer_the_parsed_output(string $pattern, string $scheme): void
    {
        $regex = Regex::create(['cache' => null, 'pcre_version' => '10.49']);
        $regex->clearCaches();
        $parsed = array_map(self::outcome(...), self::consumers($regex, $pattern));

        $keyedRegex = Regex::create(['cache' => new KeyedTreeCache($scheme), 'pcre_version' => '10.49']);
        // The first parse stores the keyed copy; every consumer reads it.
        $keyedRegex->parse($pattern);
        $keyedRegex->clearCaches();

        // Each consumer is judged on its own, so one that breaks on keys
        // does not hide the next.
        $diverging = [];
        foreach (self::consumers($keyedRegex, $pattern) as $name => $consumer) {
            try {
                $output = self::outcome($consumer);
            } catch (\Error $error) {
                $diverging[] = \sprintf('%s: %s: %s at %s:%d', $name, $error::class, $error->getMessage(), basename($error->getFile()), $error->getLine());

                continue;
            }

            if (true !== (new IsEqual($parsed[$name]))->evaluate($output, '', true)) {
                $diverging[] = $name.': another output';
            }
        }

        $this->assertSame([], $diverging, \sprintf('%s with %s keys', $pattern, $scheme));
    }

    /**
     * The copy keeps every node it does not rebuild: with list keys it
     * equals the parsed tree, so the keys are the only thing the sweep moves.
     */
    #[Test]
    #[DataProvider('providePatterns')]
    public function test_the_copy_with_list_keys_is_the_parsed_tree(string $pattern): void
    {
        $tree = Regex::create(['cache' => null, 'pcre_version' => '10.49'])->parse($pattern);

        $this->assertEquals($tree, KeyedTreeCache::rebuild($tree, KeyedTreeCache::LIST_KEYS));
        $this->assertNotSame($tree, KeyedTreeCache::rebuild($tree, KeyedTreeCache::LIST_KEYS));
    }

    /**
     * @return iterable<string, array{pattern: string, scheme: string}>
     */
    public static function provideKeyedPatterns(): iterable
    {
        foreach (self::providePatterns() as $name => ['pattern' => $pattern]) {
            yield $name.' / string keys' => ['pattern' => $pattern, 'scheme' => KeyedTreeCache::STRING_KEYS];
            yield $name.' / shifted int keys' => ['pattern' => $pattern, 'scheme' => KeyedTreeCache::SHIFTED_INT_KEYS];
        }
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function providePatterns(): iterable
    {
        yield 'alternation' => ['pattern' => '/foo|bar|baz/'];
        yield 'comment before an anchored literal' => ['pattern' => '/(?#c)a$/'];
        yield 'nested quantifier' => ['pattern' => '/(a+)+b/'];
        yield 'repeated word group' => ['pattern' => '/(?:\w+\s?)+$/'];
        yield 'quantifier concatenation' => ['pattern' => '/a*a*b/'];
        yield 'alternation of classes' => ['pattern' => '/[0-9]|[a-z]/'];
        yield 'digit class' => ['pattern' => '/[0-9]/'];
        yield 'conditional' => ['pattern' => '/(?(1)a|b)/'];
        yield 'callout' => ['pattern' => '/(?C"x")a/'];
        yield 'mark in an alternation' => ['pattern' => '/(*MARK:m)a|b/'];
        yield 'inline flags' => ['pattern' => '/(?i)a(?-i)b/'];
        yield 'lookbehind alternation' => ['pattern' => '/(?<=ab|c)d/'];
        yield 'quoted run' => ['pattern' => '/x\Qa.b\E/'];
        yield 'anchored alternation' => ['pattern' => '/^(?:a|b)$/'];
        yield 'empty capture and backreference' => ['pattern' => '/()\1/'];
        yield 'capture then group' => ['pattern' => '/(a)(?:b)/'];
        yield 'unicode alternation' => ['pattern' => '/é+|e/u'];
        yield 'anchors split by an alternation' => ['pattern' => '/^a|b$/'];
        yield 'bare branch between anchored ones' => ['pattern' => '/^ab|cd|ef$/'];
        yield 'lookahead the next item contradicts' => ['pattern' => '/(?=ab)ac/'];
        yield 'lazy dot between quotes' => ['pattern' => '/".*?"/'];
        yield 'class of ranges' => ['pattern' => '/[a-zA-Z0-9_]/'];
        yield 'newline group after the end anchor' => ['pattern' => "/a$(?:\n\\s)/"];
        yield 'optional sequence after the end anchor' => ['pattern' => '/a$(?:b?c?)/'];
        yield 'optional alternation of captures' => ['pattern' => '/(?:(a)|(b))?c/'];
        yield 'optional branch with a capture' => ['pattern' => '/x(?:(a)b|c)?/'];
        yield 'caseless pair class after a loop' => ['pattern' => '/(?:a|b)*[cC]/'];
        yield 'start option before an alternation' => ['pattern' => '/(*UTF)foo|bar/'];
        yield 'commented branches under x' => ['pattern' => "/a # one\n|b # two\n/x"];
        yield 'trailing comment in a group under x' => ['pattern' => "/(?:a b # c\n)d/x"];
        yield 'leading comment in a group under x' => ['pattern' => "/(?: # lead\n a)/x"];
    }

    /**
     * @return array<string, \Closure(): mixed>
     */
    private static function consumers(Regex $regex, string $pattern): array
    {
        $tree = $regex->parse($pattern);

        return [
            'printer' => static fn (): string => $tree->accept(new PatternPrinter()),
            'formatter' => static fn (): object => $regex->format($pattern, 8),
            'compactor' => static fn (): object => $regex->compact($pattern),
            'validator' => static fn (): object => $regex->validate($pattern),
            'linter' => static fn (): array => self::issues($tree),
            'redos' => static function () use ($regex, $pattern): array {
                $analysis = $regex->redos($pattern);

                return [$analysis->severity, $analysis->headline()];
            },
            'text explainer' => static fn (): string => $regex->explain($pattern),
            'html explainer' => static fn (): string => $regex->explain($pattern, 'html'),
            'mermaid' => static fn (): string => $tree->accept(new MermaidRenderer()),
            'ascii tree' => static fn (): string => $tree->accept(new AsciiTreeRenderer()),
            'javascript' => static fn (): object => $regex->transpile($pattern, 'javascript'),
            'python' => static fn (): object => $regex->transpile($pattern, 'python'),
            'sample' => static function () use ($regex, $pattern): string {
                $generator = new SampleGenerator();
                $generator->setSeed(42);

                return $generator->generate($pattern, $regex->parser());
            },
            'test cases' => static fn (): array => $tree->accept(new TestCaseGenerator()),
            'optimizer' => static fn (): object => $regex->optimize($pattern),
            'rewriter' => static fn (): string => self::print($tree->accept(new Rewriter(possessive: true, factorize: true))),
            'modernizer' => static fn (): string => self::print($tree->accept(new Modernizer())),
            'capture shape' => static fn (): object => (new CaptureShapeAnalyzer())->analyze($tree),
            'literals' => static fn (): object => $tree->accept(new LiteralExtractor()),
        ];
    }

    /**
     * What a consumer gives, or the library exception it throws: a refusal
     * is an output too, and it must not depend on the keys.
     *
     * @param \Closure(): mixed $consumer
     */
    private static function outcome(\Closure $consumer): mixed
    {
        try {
            return $consumer();
        } catch (ExceptionInterface $exception) {
            return [$exception::class, $exception->getMessage()];
        }
    }

    /**
     * @return list<array{string, ?int, ?int}>
     */
    private static function issues(RegexNode $tree): array
    {
        $linter = new PatternLinter();
        $tree->accept($linter);

        return array_map(static fn (RuleViolation $issue): array => [$issue->id, $issue->offset, $issue->end], $linter->getIssues());
    }

    private static function print(mixed $node): string
    {
        Assert::assertInstanceOf(NodeInterface::class, $node);

        return $node->accept(new PatternPrinter());
    }
}
