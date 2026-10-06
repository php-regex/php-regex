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

namespace PHPRegex\Tests\Documentation;

use PHPRegex\Tests\Support\DocumentationPages;
use PHPRegex\Tests\Support\DocumentedCalls;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every method a PHP example of the documentation calls on a library class
 * exists on that class, is public (or reachable from where the example calls
 * it), and is static when it is called statically. A method called on the
 * array or string a library method returns is a mistake too.
 *
 * DocumentedCalls says which receivers are resolved; calls on anything else
 * (other libraries, untyped variables, methods the example defines) are not
 * checked.
 */
final class DocumentedApiCallsTest extends TestCase
{
    #[Test]
    #[DataProvider('providePagesWithPhpExamples')]
    public function test_documented_api_calls_exist_on_their_class(string $page): void
    {
        $problems = [];
        foreach (DocumentationPages::phpFences($page) as $fence) {
            foreach (DocumentedCalls::inExample($fence['code'], $fence['intro'], $fence['line']) as $call) {
                if (null !== $call['problem']) {
                    $problems[] = $page.':'.$call['line'].': '.$call['problem'];
                }
            }
        }

        $this->assertSame([], $problems, $page.' calls methods the library does not have.');
    }

    #[Test]
    public function test_documented_api_calls_are_resolved_across_the_documentation(): void
    {
        $resolved = 0;
        foreach (DocumentationPages::all() as $page) {
            foreach (DocumentationPages::phpFences($page) as $fence) {
                $resolved += \count(DocumentedCalls::inExample($fence['code'], $fence['intro'], $fence['line']));
            }
        }

        // The documentation makes hundreds of calls on library classes; a
        // resolver that stops resolving them would let every page pass.
        $this->assertGreaterThan(100, $resolved);
    }

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('provideExamples')]
    public function test_documented_api_calls_resolve_the_receiver_of_an_example(string $code, string $intro, array $expected): void
    {
        $found = array_map(
            static fn (array $call): string => $call['class'].'::'.$call['method'].'() '.($call['problem'] ?? 'ok'),
            DocumentedCalls::inExample($code, $intro, 1),
        );

        $this->assertSame($expected, $found);
    }

    /**
     * A library class whose file cannot be loaded is reported, unless what
     * is missing is a class of another library (the Laravel bridge without
     * Laravel installed). The autoloader below throws what PHP throws when
     * it includes such a file.
     *
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('provideClassesThatCannotBeLoaded')]
    public function test_documented_api_calls_report_a_library_class_that_cannot_be_loaded(string $class, \Error $error, array $expected): void
    {
        $autoloader = static function (string $name) use ($class, $error): void {
            if ($name === $class) {
                throw $error;
            }
        };
        spl_autoload_register($autoloader, true, true);

        try {
            $found = array_map(
                static fn (array $call): string => $call['class'].'::'.$call['method'].'() '.($call['problem'] ?? 'ok'),
                DocumentedCalls::inExample('\\'.$class.'::create();', '', 1),
            );
        } finally {
            spl_autoload_unregister($autoloader);
        }

        $this->assertSame($expected, $found);
    }

    /**
     * @return iterable<string, array{class: string, error: \Error, expected: list<string>}>
     */
    public static function provideClassesThatCannotBeLoaded(): iterable
    {
        yield 'a syntax error' => [
            'class' => 'PHPRegex\Toolkit\DocumentedBrokenSyntax',
            'error' => new \ParseError('syntax error, unexpected end of file'),
            'expected' => ['PHPRegex\Toolkit\DocumentedBrokenSyntax::create() PHPRegex\Toolkit\DocumentedBrokenSyntax cannot be loaded: syntax error, unexpected end of file'],
        ];

        yield 'a type error' => [
            'class' => 'PHPRegex\Toolkit\DocumentedBrokenType',
            'error' => new \TypeError('Cannot assign int to property of type string'),
            'expected' => ['PHPRegex\Toolkit\DocumentedBrokenType::create() PHPRegex\Toolkit\DocumentedBrokenType cannot be loaded: Cannot assign int to property of type string'],
        ];

        yield 'a missing library class it depends on' => [
            'class' => 'PHPRegex\Toolkit\DocumentedBrokenParent',
            'error' => new \Error('Interface "PHPRegex\Toolkit\Nope" not found'),
            'expected' => ['PHPRegex\Toolkit\DocumentedBrokenParent::create() PHPRegex\Toolkit\DocumentedBrokenParent cannot be loaded: Interface "PHPRegex\Toolkit\Nope" not found'],
        ];

        yield 'a missing class of another library, not checked' => [
            'class' => 'PHPRegex\Laravel\DocumentedBridge',
            'error' => new \Error('Class "Illuminate\Support\ServiceProvider" not found'),
            'expected' => [],
        ];
    }

    /**
     * @return iterable<string, array{page: string}>
     */
    public static function providePagesWithPhpExamples(): iterable
    {
        foreach (DocumentationPages::all() as $page) {
            if ([] !== DocumentationPages::phpFences($page)) {
                yield $page => ['page' => $page];
            }
        }
    }

    /**
     * @return iterable<string, array{code: string, intro: string, expected: list<string>}>
     */
    public static function provideExamples(): iterable
    {
        yield 'imported class, chained return types' => [
            'code' => "use PHPRegex\\Toolkit\\Regex;\n\$ast = Regex::create()->parse('/a/');\n\$ast->accept(new PHPRegex\\Parser\\Printer\\PatternPrinter());",
            'intro' => '',
            'expected' => [
                'PHPRegex\Toolkit\Regex::create() ok',
                'PHPRegex\Toolkit\Regex::parse() ok',
                'PHPRegex\Parser\Node\RegexNode::accept() ok',
            ],
        ];

        yield 'missing method on an imported class' => [
            'code' => "use PHPRegex\\Toolkit\\Regex;\nRegex::create()->doesNotExist();",
            'intro' => '',
            'expected' => [
                'PHPRegex\Toolkit\Regex::create() ok',
                'PHPRegex\Toolkit\Regex::doesNotExist() PHPRegex\Toolkit\Regex has no method doesNotExist()',
            ],
        ];

        yield 'instance method called statically' => [
            'code' => "use PHPRegex\\Toolkit\\Regex;\nRegex::parse('/a/');",
            'intro' => '',
            'expected' => ['PHPRegex\Toolkit\Regex::parse() PHPRegex\Toolkit\Regex::parse() is not static'],
        ];

        yield 'unique short name without a use line' => [
            'code' => "\$collector = new MetricsCollector();\n\$collector->getMetrics();",
            'intro' => '',
            'expected' => ['PHPRegex\Parser\Analysis\MetricsCollector::getMetrics() PHPRegex\Parser\Analysis\MetricsCollector has no method getMetrics()'],
        ];

        yield 'accept typed by the visitor method of the node' => [
            'code' => "use PHPRegex\\Toolkit\\Regex;\nuse PHPRegex\\Parser\\Analysis\\MetricsCollector;\n\$metrics = Regex::create()->parse('/a/')->accept(new MetricsCollector());\n\$metrics->getTotalNodeCount();",
            'intro' => '',
            'expected' => [
                'PHPRegex\Toolkit\Regex::create() ok',
                'PHPRegex\Toolkit\Regex::parse() ok',
                'PHPRegex\Parser\Node\RegexNode::accept() ok',
                'array::getTotalNodeCount() ->getTotalNodeCount() is called on a value of type array',
            ],
        ];

        yield 'typed parameter' => [
            'code' => "use PHPRegex\\Parser\\Node\\GroupNode;\nfunction f(GroupNode \$node) { return \$node->child->accept(\$v); }",
            'intro' => '',
            'expected' => ['PHPRegex\Parser\Node\NodeInterface::accept() ok'],
        ];

        yield 'this in an example with no class, named by the prose above' => [
            'code' => "\$this->parseAlternation();\n\$this->nextTokenIs();",
            'intro' => 'Add parsing logic in `src/Parser/Syntax/TokenParser.php`:',
            'expected' => [
                'PHPRegex\Parser\Syntax\TokenParser::parseAlternation() ok',
                'PHPRegex\Parser\Syntax\TokenParser::nextTokenIs() PHPRegex\Parser\Syntax\TokenParser has no method nextTokenIs()',
            ],
        ];

        yield 'this in a class of the example, as its library parent' => [
            'code' => "use PHPRegex\\Parser\\AbstractNodeVisitor;\nclass V extends AbstractNodeVisitor {\n  protected function defaultReturn(): mixed { return \$this->helper(); }\n  private function helper(): int { return \$this->nope(); }\n}",
            'intro' => '',
            'expected' => ['PHPRegex\Parser\AbstractNodeVisitor::nope() PHPRegex\Parser\AbstractNodeVisitor has no method nope()'],
        ];

        yield 'class of another library, not checked' => [
            'code' => "class T extends \\PHPUnit\\Framework\\TestCase {\n  public function testIt(): void { \$this->assertSame(1, 1); }\n}",
            'intro' => '',
            'expected' => [],
        ];

        yield 'variable assigned from an expression, not checked' => [
            'code' => "use PHPRegex\\Toolkit\\Regex;\n\$regex = \$flag ? Regex::create() : null;\n\$regex->anything();",
            'intro' => '',
            'expected' => ['PHPRegex\Toolkit\Regex::create() ok'],
        ];

        yield 'imported library class that does not exist' => [
            'code' => "use PHPRegex\\Toolkit\\Nope;\nNope::create();",
            'intro' => '',
            'expected' => ['PHPRegex\Toolkit\Nope::create() PHPRegex\Toolkit\Nope does not exist'],
        ];

        yield 'fully qualified library class that does not exist' => [
            'code' => "\$x = new \\PHPRegex\\Nope();\n\$x->run();",
            'intro' => '',
            'expected' => ['PHPRegex\Nope::run() PHPRegex\Nope does not exist'],
        ];

        yield 'class of another library that does not exist, not checked' => [
            'code' => "use Vendor\\Nope;\nNope::create();",
            'intro' => '',
            'expected' => [],
        ];

        yield 'reassigned variable forgets its class' => [
            'code' => "use PHPRegex\\Toolkit\\Regex;\n\$x = Regex::create();\n\$x = make();\n\$x->anything();",
            'intro' => '',
            'expected' => ['PHPRegex\Toolkit\Regex::create() ok'],
        ];
    }
}
