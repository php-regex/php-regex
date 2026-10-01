<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use PhpRegex\Optimizer\OptimizationResult;
use PhpRegex\Parser\Analysis\LiteralExtractionResult;
use PhpRegex\Parser\Node\RegexNode;
use PhpRegex\Parser\TolerantParseResult;
use PhpRegex\Parser\Validation\ValidationResult;
use PhpRegex\Redos\ConfirmationOptions;
use PhpRegex\Redos\RedosAnalysis;
use PhpRegex\Redos\RedosMode;
use PhpRegex\Redos\RedosSeverity;
use PhpRegex\Toolkit\AnalysisReport;
use PhpRegex\Transpiler\TranspileOptions;
use PhpRegex\Transpiler\TranspileResult;

/**
 * Laravel Facade for the PhpRegex library.
 *
 * @method static RegexNode|TolerantParseResult parse(string $regex, bool $tolerant = false)
 * @method static AnalysisReport                analyze(string $regex)
 * @method static ValidationResult              validate(string $regex)
 * @method static RedosAnalysis                 redos(string $regex, ?RedosSeverity $threshold = null, RedosMode $mode = RedosMode::Theoretical, ?ConfirmationOptions $confirmOptions = null)
 * @method static OptimizationResult            optimize(string $regex, array $options = [])
 * @method static TranspileResult               transpile(string $regex, string $target, ?TranspileOptions $options = null)
 * @method static string                        explain(string $regex, string $format = 'text')
 * @method static string                        highlight(string $regex, string $format = 'console')
 * @method static LiteralExtractionResult       literals(string $regex)
 * @method static string                        generate(string $regex)
 * @method static RegexNode                     parsePattern(string $pattern, string $flags = '', string $delimiter = '/')
 *
 * @see \PhpRegex\Toolkit\Regex
 */
final class Regex extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return \PhpRegex\Toolkit\Regex::class;
    }
}
