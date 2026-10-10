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

namespace PHPRegex\Tests\Unit\Parser;

use PHPRegex\Parser\AbstractNodeVisitor;
use PHPRegex\Parser\AbstractTraversingVisitor;
use PHPRegex\Parser\NodeVisitorInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The visitor bases are generic over TReturn, and a subclasser that only
 * collects (returns null everywhere) has no TReturn of their own to bind:
 * today the bare `@template-covariant TReturn` puts a missingType.generics
 * wall in front of every collector visitor, the documented way to write one.
 *
 * The default `= null` takes the wall down: a subclass with no binding is
 * read as NodeVisitorInterface<null> instead of as an error. The price, on
 * purpose: an override with an empty body is no longer silently untyped —
 * PHPStan points at it (return.missing) until it says `return null;`.
 *
 * Both sides are sounded with the repository's real PHPStan, the binary
 * `composer phpstan` runs, on a fixture written to a temporary directory and
 * analysed with the repository's own phpstan.dist.neon — level max,
 * treatPhpDocTypesAsCertain off, the strictest reading a consumer gets. The
 * in-process RuleTestCase harness cannot sound this: it analyses a file with
 * the one rule under test alone, so no core rule (missingType.generics,
 * return.missing among them) ever fires through it.
 *
 * @phpstan-type ProbeError array{line: int, message: string, identifier: ?string}
 */
final class VisitorGenericDefaultTest extends TestCase
{
    /**
     * The identifier of the wall this change takes down, and of the guidance
     * it puts in its place.
     */
    private const MISSING_GENERICS = 'missingType.generics';

    private const MISSING_RETURN = 'return.missing';

    /**
     * @var list<ProbeError>|null
     */
    private static ?array $analysis = null;

    /**
     * @param class-string $class
     */
    #[Test]
    #[DataProvider('provideVisitorBases')]
    public function test_visitor_base_declares_null_as_the_treturn_default(string $class): void
    {
        $docComment = (string) (new \ReflectionClass($class))->getDocComment();

        $this->assertSame(
            1,
            preg_match('/@template-covariant\s+TReturn\s*=\s*null\b/', $docComment),
            sprintf(
                '%s must declare its template as `@template-covariant TReturn = null`: without the default, every'
                .' subclass that binds nothing — a collector visitor, the documented simplest one — is reported'
                .' missingType.generics, and the traversing base redeclares the template itself, so the default'
                .' must be restated there too: it does not flow through @extends.',
                $class,
            ),
        );
    }

    #[Test]
    public function test_unbound_collector_visitor_passes_analysis(): void
    {
        $errors = self::fixtureErrors();

        $onCollector = array_values(array_filter(
            $errors,
            static fn (array $error): bool => $error['line'] === self::fixtureLine('collectorDeclaration')
                || $error['line'] === self::fixtureLine('collectorVisitRegex'),
        ));

        $this->assertSame(
            [],
            $onCollector,
            'A collector visitor that extends AbstractNodeVisitor with no generic binding, and whose visitRegex()'
            .' says `return null;`, must analyse clean: the default `TReturn = null` is its binding. The errors'
            .' PHPStan reports on it today are the wall this change removes.',
        );
    }

    #[Test]
    public function test_empty_body_override_is_told_to_return_null(): void
    {
        $errors = self::fixtureErrors();

        $genericsWall = array_values(array_filter(
            $errors,
            static fn (array $error): bool => $error['line'] === self::fixtureLine('emptyBodyDeclaration')
                && self::MISSING_GENERICS === $error['identifier'],
        ));
        $this->assertSame(
            [],
            $genericsWall,
            'The default `TReturn = null` removes the missingType.generics wall for the empty-body subclass too:'
            .' with no binding to demand, nothing is missing.',
        );

        $pointedAtReturn = array_values(array_filter(
            $errors,
            static fn (array $error): bool => self::MISSING_RETURN === $error['identifier']
                && str_contains($error['message'], 'visitLiteral'),
        ));
        $this->assertCount(
            1,
            $pointedAtReturn,
            'With the default landed, an override whose body is empty no longer slips through untyped: PHPStan'
            .' reports it (identifier '.self::MISSING_RETURN.') until it says `return null;`. The wall becoming'
            .' guidance instead of silence is the other half of what the default buys.',
        );
    }

    /**
     * @return iterable<string, array{class: class-string}>
     */
    public static function provideVisitorBases(): iterable
    {
        yield 'the interface' => ['class' => NodeVisitorInterface::class];

        yield 'the abstract base' => ['class' => AbstractNodeVisitor::class];

        yield 'the traversing base' => ['class' => AbstractTraversingVisitor::class];
    }

    /**
     * The lines of the fixture the assertions point at.
     *
     * @return array<string, int>
     */
    private static function fixtureLines(): array
    {
        $lines = [];
        foreach (explode("\n", self::fixtureCode()) as $index => $line) {
            if (str_contains($line, 'final class CollectorVisitor')) {
                $lines['collectorDeclaration'] = $index + 1;
            }
            if (str_contains($line, 'public function visitRegex')) {
                $lines['collectorVisitRegex'] = $index + 1;
            }
            if (str_contains($line, 'final class EmptyBodyVisitor')) {
                $lines['emptyBodyDeclaration'] = $index + 1;
            }
        }

        return $lines;
    }

    private static function fixtureLine(string $name): int
    {
        $lines = self::fixtureLines();

        return $lines[$name];
    }

    /**
     * Both sides of the fixture, one collector and one empty-body subclass,
     * whose line numbers the assertions above read.
     */
    private static function fixtureCode(): string
    {
        return <<<'PHP_WRAP'
            <?php
        
            declare(strict_types=1);
        
            namespace RegexVisitorDefaultProbe;
        
            use PHPRegex\Parser\AbstractNodeVisitor;
            use PHPRegex\Parser\Node\LiteralNode;
            use PHPRegex\Parser\Node\RegexNode;
        
            final class CollectorVisitor extends AbstractNodeVisitor
            {
                public function visitRegex(RegexNode $node)
                {
                    return null;
                }
            }
        
            final class EmptyBodyVisitor extends AbstractNodeVisitor
            {
                public function visitLiteral(LiteralNode $node)
                {
                }
            }
            PHP_WRAP;
    }

    /**
     * The messages PHPStan's JSON names for the analysed fixture.
     *
     * @param array<mixed> $json
     *
     * @return list<mixed>
     */
    private static function fixtureMessages(array $json, string $fixture): array
    {
        $files = $json['files'] ?? [];
        if (!\is_array($files)) {
            return [];
        }

        foreach ($files as $path => $file) {
            if ($path === $fixture && \is_array($file)) {
                $messages = $file['messages'] ?? [];

                return \is_array($messages) ? array_values($messages) : [];
            }
        }

        return [];
    }

    /**
     * The repository's PHPStan on the fixture, analysed once and memoized:
     * whichever sounding runs first pays the ~5s, the other reads the same
     * deterministic result — no test depends on the other's side effects.
     *
     * @return list<ProbeError>
     */
    private static function fixtureErrors(): array
    {
        if (null !== self::$analysis) {
            return self::$analysis;
        }

        $root = \dirname(__DIR__, 3);
        $directory = sys_get_temp_dir().'/regex-visitor-generic-default-'.bin2hex(random_bytes(4));
        mkdir($directory);
        $fixture = $directory.'/VisitorDefaultProbe.php';
        file_put_contents($fixture, self::fixtureCode());

        try {
            // The exit code is read but not asserted: the fixture IS expected
            // to carry errors while the wall stands — the assertions below
            // read which errors, not whether there are any.
            $process = proc_open(
                [
                    \PHP_BINARY,
                    $root.'/tools/phpstan/vendor/bin/phpstan',
                    'analyse',
                    $fixture,
                    '--error-format=json',
                    '--no-progress',
                ],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $root,
            );
            self::assertIsResource($process, 'The repository PHPStan binary must run: tools/phpstan is installed with the dev tools.');

            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);

            $json = null;
            foreach (array_reverse(explode("\n", $stdout)) as $line) {
                if (str_starts_with($line, '{')) {
                    $json = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);

                    break;
                }
            }
            if (!\is_array($json)) {
                self::fail('PHPStan must answer one JSON object; stdout: '.$stdout.' stderr: '.$stderr);
            }

            $errors = [];
            foreach (self::fixtureMessages($json, $fixture) as $error) {
                self::assertIsArray($error);
                $line = $error['line'] ?? null;
                $message = $error['message'] ?? null;
                self::assertIsInt($line);
                self::assertIsString($message);
                $identifier = $error['identifier'] ?? null;
                $errors[] = [
                    'line' => $line,
                    'message' => $message,
                    'identifier' => \is_string($identifier) ? $identifier : null,
                ];
            }

            return self::$analysis = $errors;
        } finally {
            unlink($fixture);
            rmdir($directory);
        }
    }
}
