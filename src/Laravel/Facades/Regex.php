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
use PHPRegex\Optimizer\OptimizerOptions;
use PHPRegex\Parser\Analysis\CaptureShape;
use PHPRegex\Parser\Analysis\LiteralExtractionResult;
use PHPRegex\Parser\Analysis\PatternInfo;
use PHPRegex\Parser\Cache\CacheInterface;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Token\TokenStream;
use PHPRegex\Parser\TolerantParseResult;
use PHPRegex\Parser\Validation\PatternCompatibility;
use PHPRegex\Parser\Validation\ValidationResult;
use PHPRegex\Redos\ConfirmationOptions;
use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Toolkit\AnalysisReport;
use PHPRegex\Toolkit\OutputFormat;
use PHPRegex\Toolkit\Regex as ToolkitRegex;
use PHPRegex\Transpiler\TranspileOptions;
use PHPRegex\Transpiler\TranspileResult;

/**
 * Laravel Facade for the PHPRegex library.
 *
 * @method static ToolkitRegex                  create(array $options = [])
 * @method static RegexNode                     parse(string $regex)
 * @method static RegexParser                   parser()
 * @method static TolerantParseResult           parseTolerant(string $regex)
 * @method static ValidationResult              validate(string $regex)
 * @method static RegexNode                     parsePattern(string $pattern, string $flags = '', string $delimiter = '/')
 * @method static AnalysisReport                analyze(string $regex)
 * @method static RedosAnalysis                 redos(string $regex, ?RedosSeverity $threshold = null, RedosMode $mode = RedosMode::Theoretical, ?ConfirmationOptions $confirmOptions = null)
 * @method static OptimizationResult            optimize(string $regex, OptimizerOptions|array $options = [])
 * @method static TranspileResult               transpile(string $regex, string $target, ?TranspileOptions $options = null)
 * @method static string                        explain(string $regex, string|OutputFormat $format = OutputFormat::Text)
 * @method static string                        highlight(string $regex, string|OutputFormat $format = OutputFormat::Console)
 * @method static LiteralExtractionResult       literals(string $regex)
 * @method static CaptureShape                  captureShape(string $regex)
 * @method static PatternInfo                   info(string $regex)
 * @method static PatternCompatibility          compatibility(string $regex)
 * @method static string                        generate(string $regex)
 * @method static TokenStream                   tokenize(string $regex, ?PcreTarget $target = null)
 * @method static PcreTarget                    target()
 * @method static CacheInterface                getCache()
 * @method static array{hits: int, misses: int} getCacheStats()
 * @method static void                          clearCaches()
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
