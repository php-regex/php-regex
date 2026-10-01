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

namespace PhpRegex\Linter\Config;

use PhpParser\ParserFactory;
use PhpRegex\Linter\Extraction\PatternFunctionRegistry;
use PhpRegex\Linter\Extraction\PhpParserExtractionStrategy;
use PhpRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PhpRegex\Linter\PatternExtractor;

final class LintExtractorFactory
{
    public function create(?LintArguments $arguments = null): PatternExtractor
    {
        $registry = null === $arguments
            ? PatternFunctionRegistry::defaults()
            : PatternFunctionRegistry::create($arguments->interop, $arguments->patternFunctions);

        $parserFactoryClass = ParserFactory::class;

        if (class_exists($parserFactoryClass)) {
            return new PatternExtractor(new PhpParserExtractionStrategy([], $registry));
        }

        return new PatternExtractor(new TokenBasedExtractionStrategy([], $registry));
    }
}
