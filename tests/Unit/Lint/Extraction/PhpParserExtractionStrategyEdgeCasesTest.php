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

namespace PhpRegex\Tests\Unit\Lint\Extraction;

use PhpParser\ErrorHandler;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Parser;
use PhpRegex\Linter\Extraction\PatternFunction;
use PhpRegex\Linter\Extraction\PatternFunctionRegistry;
use PhpRegex\Linter\Extraction\PhpParserExtractionStrategy;
use PHPUnit\Framework\TestCase;

final class PhpParserExtractionStrategyEdgeCasesTest extends TestCase
{
    public function test_analyze_file_returns_empty_when_parser_missing(): void
    {
        $strategy = $this->newStrategyWithParser(null);
        $method = $this->getPrivateMethod($strategy, 'analyzeFileWithPhpStan');

        $this->assertSame([], $method->invoke($strategy, __FILE__));
    }

    public function test_analyze_file_returns_empty_for_non_array_ast(): void
    {
        $parser = new class implements Parser {
            public function parse(string $code, ?ErrorHandler $errorHandler = null): ?array
            {
                return null;
            }

            public function getTokens(): array
            {
                return [];
            }
        };
        $strategy = $this->newStrategyWithParser($parser);
        $method = $this->getPrivateMethod($strategy, 'analyzeFileWithPhpStan');

        $file = __DIR__.'/../../../Fixtures/Extractor/phpstan_coverage.php';

        $this->assertSame([], $method->invoke($strategy, $file));
    }

    public function test_extract_from_func_call_handles_invalid_nodes(): void
    {
        $strategy = new PhpParserExtractionStrategy();
        $method = $this->getPrivateMethod($strategy, 'extractFromFuncCall');

        $funcCall = new FuncCall(new Variable('preg_match'), []);
        $this->assertSame([], $method->invoke($strategy, $funcCall, __FILE__, ''));

        $funcCall = new FuncCall(new Name('strpos'), []);
        $this->assertSame([], $method->invoke($strategy, $funcCall, __FILE__, ''));

        $funcCall = new FuncCall(new Name('preg_match'), []);
        $this->assertSame([], $method->invoke($strategy, $funcCall, __FILE__, ''));
    }

    public function test_concat_extraction_handles_empty_and_nested_values(): void
    {
        $strategy = new PhpParserExtractionStrategy();

        $extractPatternFromExpr = $this->getPrivateMethod($strategy, 'extractPatternFromExpr');
        $emptyConcat = new Concat(new String_(''), new String_(''));
        $this->assertNull($extractPatternFromExpr->invoke($strategy, $emptyConcat, new PatternFunction('preg_match'), __FILE__, ''));

        $extractStringValue = $this->getPrivateMethod($strategy, 'extractStringValue');
        $nested = new Concat(new String_('a'), new String_('b'));
        $this->assertSame('ab', $extractStringValue->invoke($strategy, $nested));
    }

    private function newStrategyWithParser(?Parser $parser): PhpParserExtractionStrategy
    {
        $ref = new \ReflectionClass(PhpParserExtractionStrategy::class);
        $strategy = $ref->newInstanceWithoutConstructor();
        $ref->getProperty('parser')->setValue($strategy, $parser);
        $ref->getProperty('registry')->setValue($strategy, PatternFunctionRegistry::defaults());

        return $strategy;
    }

    private function getPrivateMethod(object $object, string $method): \ReflectionMethod
    {
        $ref = new \ReflectionClass($object);

        return $ref->getMethod($method);
    }
}
