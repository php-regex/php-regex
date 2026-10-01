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

namespace PHPRegex\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use PHPRegex\Optimizer\OptimizationResult;
use PHPRegex\Parser\Analysis\LiteralExtractionResult;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\TolerantParseResult;
use PHPRegex\Parser\Validation\ValidationResult;
use PHPRegex\Redos\ConfirmationOptions;
use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Toolkit\AnalysisReport;
use PHPRegex\Transpiler\TranspileOptions;
use PHPRegex\Transpiler\TranspileResult;

/**
 * Laravel Facade for the PHPRegex library.
 *
 * @method static RegexNode               parse(string $regex)
 * @method static TolerantParseResult     parseTolerant(string $regex)
 * @method static AnalysisReport          analyze(string $regex)
 * @method static ValidationResult        validate(string $regex)
 * @method static RedosAnalysis           redos(string $regex, ?RedosSeverity $threshold = null, RedosMode $mode = RedosMode::Theoretical, ?ConfirmationOptions $confirmOptions = null)
 * @method static OptimizationResult      optimize(string $regex, array $options = [])
 * @method static TranspileResult         transpile(string $regex, string $target, ?TranspileOptions $options = null)
 * @method static string                  explain(string $regex, string $format = 'text')
 * @method static string                  highlight(string $regex, string $format = 'console')
 * @method static LiteralExtractionResult literals(string $regex)
 * @method static string                  generate(string $regex)
 * @method static RegexNode               parsePattern(string $pattern, string $flags = '', string $delimiter = '/')
 *
 * @see \PHPRegex\Toolkit\Regex
 */
final class Regex extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return \PHPRegex\Toolkit\Regex::class;
    }
}
