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

namespace PHPRegex\Build\Verify;

use PHPRegex\Parser\Analysis\CaptureShapeAnalyzer;
use PHPRegex\Parser\Analysis\CharSetAnalyzer;
use PHPRegex\Parser\Analysis\ComplexityScorer;
use PHPRegex\Parser\Analysis\LengthRangeCalculator;
use PHPRegex\Parser\Analysis\LiteralExtractor;
use PHPRegex\Parser\Analysis\MetricsCollector;
use PHPRegex\Parser\Analysis\PatternInfoAnalyzer;
use PHPRegex\Parser\Analysis\RequiredLiteralAnalyzer;
use PHPRegex\Parser\Cache\AstSerializer;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Printer\JsonDumper;
use PHPRegex\Parser\Printer\NodeDumper;
use PHPRegex\Parser\Printer\PatternPrinter;
use PHPRegex\Parser\Printer\StructuralFingerprint;
use PHPRegex\Parser\RegexParser;

/**
 * What the parser package says about one pattern for one target, written
 * the same way whichever copy of the package answers: the original on PHP
 * 8.x or the twin on PHP 7.4 and later. Every public reading a consumer
 * makes is taken: the tokens, the tree, the tolerant tree and its errors,
 * the validation, the capture shapes under each flag set, the pattern's
 * settings, the literals, the lengths, the complexity, the metrics, the
 * character set, every printer and dumper, the fingerprint, and the tree a
 * cache gives back. A reading that throws is written as what it throws.
 *
 * The file is PHP 7.4 code: it runs against the twin.
 */
final class Witness
{
    /**
     * How deep a witness goes in JSON: the readings stop at 200 levels of
     * the tree, and each level is two or three levels of JSON.
     */
    public const JSON_DEPTH = 1024;
    private const MATCH_FLAGS = [0, \PREG_OFFSET_CAPTURE, \PREG_UNMATCHED_AS_NULL, \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL];

    private const MATCH_ALL_FLAGS = [
        \PREG_PATTERN_ORDER,
        \PREG_SET_ORDER,
        \PREG_PATTERN_ORDER | \PREG_OFFSET_CAPTURE,
        \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE,
        \PREG_PATTERN_ORDER | \PREG_UNMATCHED_AS_NULL,
        \PREG_SET_ORDER | \PREG_UNMATCHED_AS_NULL,
    ];

    /**
     * @param array{php: int, pcre: string, locale: string} $target
     *
     * @return array<string, mixed>
     */
    public static function of(string $pattern, array $target): array
    {
        $parser = RegexParser::create([
            'php_version' => $target['php'],
            'pcre_version' => $target['pcre'],
            'locale' => $target['locale'],
            'cache' => null,
            'runtime_pcre_validation' => false,
        ]);

        $witness = [
            'tokens' => self::read(static fn () => $parser->tokenize($pattern)),
            'tolerant' => self::read(static fn () => $parser->parseTolerant($pattern)),
            'validation' => self::read(static fn () => $parser->validate($pattern)),
        ];

        try {
            $ast = $parser->parse($pattern);
        } catch (\Throwable $exception) {
            $witness['parse'] = self::normalize($exception);

            return $witness;
        }

        $witness['parse'] = self::normalize($ast);
        $witness += self::readings($ast, $parser);

        $restored = AstSerializer::unserialize(AstSerializer::serialize($ast));
        $witness['cache'] = null === $restored
            ? 'miss'
            : (self::normalize($restored) === $witness['parse'] && self::readings($restored, $parser) === self::readings($ast, $parser) ? 'same' : 'different');

        return $witness;
    }

    /**
     * The md5 of each reading of a witness, the form the reference keeps.
     *
     * @param array<string, mixed> $witness
     *
     * @return array<string, string>
     */
    public static function hashes(array $witness): array
    {
        $hashes = [];
        foreach ($witness as $reading => $value) {
            $hashes[$reading] = md5(self::encode($value));
        }

        return $hashes;
    }

    public static function encode($value): string
    {
        return json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION, self::JSON_DEPTH);
    }

    /**
     * A value written so the two copies of the package write the same thing
     * for the same value: an enum case as its class and name (a native case
     * on PHP 8.1, the twin's class of cases elsewhere), an object as its
     * class and public properties, an exception as its class, message and
     * public properties, bytes that are not UTF-8 in base64.
     */
    public static function normalize($value, int $depth = 0)
    {
        if ($depth > 200) {
            return ['deeper than' => 200];
        }

        if (\is_object($value) && self::isEnumCase($value)) {
            return ['case' => $value::class.'::'.$value->name];
        }

        if ($value instanceof \Error) {
            // an error PHP itself raises is worded by each PHP version its
            // own way, and names the file of each copy of the package
            return ['throws' => $value::class];
        }

        if ($value instanceof \Throwable) {
            return ['throws' => $value::class, 'message' => self::normalize($value->getMessage()), 'code' => $value->getCode(), 'properties' => self::properties($value, $depth)];
        }

        if ($value instanceof \Closure) {
            return ['closure' => true];
        }

        if (\is_object($value)) {
            return ['object' => $value::class, 'properties' => self::properties($value, $depth)];
        }

        if (\is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[] = [self::normalize($key), self::normalize($item, $depth + 1)];
            }

            return ['array' => $normalized];
        }

        if (\is_string($value) && 1 !== preg_match('//u', $value)) {
            return ['bytes' => base64_encode($value)];
        }

        if (\is_float($value)) {
            return ['float' => var_export($value, true)];
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private static function readings(RegexNode $ast, RegexParser $parser): array
    {
        $readings = [];
        $readings['shape'] = self::read(static function () use ($ast) {
            $shape = (new CaptureShapeAnalyzer())->analyze($ast);
            $read = ['shape' => $shape];
            foreach (self::MATCH_FLAGS as $flags) {
                $read['match'.$flags] = $shape->matchShape($flags);
                $read['scan'.$flags] = $shape->scanShape($flags);
            }

            foreach (self::MATCH_ALL_FLAGS as $flags) {
                $read['all'.$flags] = $shape->matchAllShape($flags);
            }

            return $read;
        });
        $readings['info'] = self::read(static fn () => (new PatternInfoAnalyzer($parser->target()))->analyze($ast));
        $readings['literals'] = self::read(static fn () => $ast->accept(new LiteralExtractor()));
        $readings['required'] = self::read(static fn () => (new RequiredLiteralAnalyzer())->analyze($ast));
        $readings['lengths'] = self::read(static fn () => [$ast->accept(new LengthRangeCalculator()), $ast->accept(new LengthRangeCalculator(true))]);
        $readings['complexity'] = self::read(static fn () => $ast->accept(new ComplexityScorer()));
        $readings['metrics'] = self::read(static function () use ($ast) {
            $metrics = new MetricsCollector();
            $ast->accept($metrics);

            return $metrics;
        });
        $readings['charset'] = self::read(static fn () => CharSetAnalyzer::forRegex($ast));
        $readings['json'] = self::read(static fn () => $ast->accept(new JsonDumper()));
        $readings['dump'] = self::read(static fn () => $ast->accept(new NodeDumper()));
        $readings['printed'] = self::read(static fn () => [
            $ast->accept(PatternPrinter::asWritten()),
            $ast->accept(PatternPrinter::normalized()),
            $ast->accept(PatternPrinter::pretty()),
            $ast->accept(PatternPrinter::forCompacting()),
        ]);
        $readings['fingerprint'] = self::read(static fn () => StructuralFingerprint::of($ast));

        return $readings;
    }

    private static function read(\Closure $reading)
    {
        try {
            return self::normalize($reading());
        } catch (\Throwable $exception) {
            return self::normalize($exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function properties(object $object, int $depth): array
    {
        $properties = [];
        foreach ((new \ReflectionObject($object))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $properties[$property->getName()] = $property->isInitialized($object)
                ? self::normalize($property->getValue($object), $depth + 1)
                : ['uninitialized' => true];
        }

        ksort($properties);

        return $properties;
    }

    private static function isEnumCase(object $value): bool
    {
        if ($value instanceof \PHPRegex\Parser\UnitEnum) {
            return true;
        }

        return \PHP_VERSION_ID >= 80100 && \interface_exists('UnitEnum', false) && is_a($value, 'UnitEnum');
    }
}
