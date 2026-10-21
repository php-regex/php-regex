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

namespace PHPRegex\PHPStan;

use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PHPRegex\Linter\Config\JudgedRange;
use PHPRegex\Linter\Config\ProjectTarget;
use PHPRegex\Linter\Config\TargetConstraint;
use PHPRegex\Linter\Config\UnsupportedTargetException;
use PHPRegex\Linter\Extraction\TargetMarker;
use PHPRegex\Linter\Extraction\TargetMarkers;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\ParserOptions;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\RegexParser;
use PHPStan\Analyser\Scope;
use PHPStan\Php\PhpVersion;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\IntegerRangeType;
use PHPStan\Type\NeverType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

/**
 * The versions a pattern is judged for: the target of the "phpVersion",
 * "pcreVersion" and "locale" settings or of PHPStan's own phpVersion, the
 * later PHPs of PHPStan's range, and, at one call, the versions a guard on
 * PHP_VERSION_ID and the "@regex-target" markers covering its line leave.
 *
 * @internal
 *
 * @phpstan-import-type ConfigArray from RegexPatternRule
 */
final class TargetScope
{
    /**
     * Judges patterns for the target; its cache stays in memory.
     */
    private readonly RegexParser $regex;

    /**
     * "PHP 8.2 with PCRE2 10.40", or null when the target is the running
     * engine: what it refuses is reported apart.
     */
    private readonly ?string $targetLabel;

    /**
     * The later PHP versions of PHPStan's range a pattern is validated at
     * too, each with its label ("PHP 8.5 with PCRE2 10.44").
     *
     * @var list<array{RegexParser, string}>
     */
    private readonly array $range;

    /**
     * The PHP versions of PHPStan's range, the lowest judged and the
     * highest, when a guard on PHP_VERSION_ID may narrow them: null when
     * one version is judged.
     *
     * @var array{int, int}|null
     */
    private readonly ?array $guardable;

    /**
     * The PCRE2 release "pcreVersion" names for every version judged.
     */
    private readonly ?string $pinnedPcre;

    /**
     * The versions judged and those PHPStan's range admits, built once.
     */
    private ?JudgedRange $judged = null;

    /**
     * The "@regex-target" markers of the files last read, by path and time
     * of change.
     *
     * @var array<string, list<TargetMarker>>
     */
    private array $markers = [];

    /**
     * @param ConfigArray                        $config             the "phpRegex" parameter: "phpVersion", "pcreVersion" and
     *                                                               "locale" are read here, every key optional
     * @param PhpVersion|null                    $phpVersion         the PHP version PHPStan analyses the project for: patterns are
     *                                                               judged for it, with the PCRE2 it bundles, unless "phpVersion"
     *                                                               is "runtime" or names a version, and "pcreVersion" a release
     * @param int|array{min: int, max: int}|null $phpVersionRange    PHPStan's "phpVersion" parameter: with {min, max}, a pattern is
     *                                                               also validated at each later PHP up to max where a rule of the
     *                                                               library changes, unless "phpVersion" names one version; a guard
     *                                                               on PHP_VERSION_ID narrows the versions a call is judged for
     * @param ReflectionProvider|null            $reflectionProvider tells whether PHP_VERSION_ID names the global constant in a
     *                                                               namespace, for a guard written without a leading backslash
     *
     * @throws InvalidRegexOptionException when "phpVersion", "pcreVersion" or "locale" cannot be read
     * @throws UnsupportedTargetException  when the PHP to judge for ships PCRE1, as regex lint refuses it
     */
    public function __construct(
        array $config = [],
        ?PhpVersion $phpVersion = null,
        int|array|null $phpVersionRange = null,
        private readonly ?ReflectionProvider $reflectionProvider = null
    ) {
        // Built now, so that an unreadable version stops PHPStan before any file is read.
        $this->regex = RegexParser::fromOptions(ParserOptions::fromArray(self::targetOptions($config, $phpVersion, $phpVersionRange)));
        $target = $this->regex->target();
        $this->range = self::rangeParsers($config, $this->regex, $phpVersionRange);
        $this->guardable = null === ($config['phpVersion'] ?? null) && \is_array($phpVersionRange) && $phpVersionRange['max'] >= $target->phpVersionId
            ? [$target->phpVersionId, $phpVersionRange['max']]
            : null;
        $pcreVersion = $config['pcreVersion'] ?? null;
        $this->pinnedPcre = \is_string($pcreVersion) ? $pcreVersion : null;
        $this->targetLabel = $target->isRunningEngine() ? null : \sprintf(
            'PHP %d.%d with PCRE2 %s%s',
            intdiv($target->phpVersionId, 10000),
            intdiv($target->phpVersionId, 100) % 100,
            $target->pcreVersion,
            'C' === $target->locale ? '' : ' under the locale '.$target->locale,
        );
    }

    /**
     * The parser that judges patterns for the target.
     */
    public function parser(): RegexParser
    {
        return $this->regex;
    }

    /**
     * Whether a pattern is judged for anything but the engine running
     * PHPStan, whose refusals PHPStan reports itself: another target, a
     * later PHP of the range, or a range a guard may narrow.
     */
    public function judgesBeyondTheRunningEngine(): bool
    {
        return null !== $this->targetLabel || [] !== $this->range || null !== $this->guardable;
    }

    /**
     * The versions a call is judged for, as a guard on PHP_VERSION_ID and
     * the "@regex-target" markers covering its line say them, both at once:
     * the parser and label of each, in order, the lowest first (left out
     * when the running engine stands for it), and the parser of the lowest,
     * which the other checks read the pattern with. Null when what narrows
     * the range keeps no version of it, the call never running on the
     * versions judged.
     *
     * @return array{list<array{RegexParser, string}>, RegexParser}|null
     */
    public function targetsAt(Scope $scope, int $lineNumber): ?array
    {
        $guarded = $this->guardedTargets($scope, $lineNumber);
        if ([] === $guarded) {
            return null;
        }

        [$floor, $floorLabel, $later] = $guarded ?? [$this->regex, $this->targetLabel, $this->range];

        return [null === $floorLabel ? $later : [[$floor, $floorLabel], ...$later], $floor];
    }

    /**
     * The versions the extension judges, and those a marker or a guard may
     * keep of them: PHPStan's range, or the series of its one version.
     */
    public function judged(): JudgedRange
    {
        if (null === $this->judged) {
            $floor = $this->regex->target();
            [$lowest, $highest] = $this->guardable ?? [$floor->phpVersionId, (intdiv($floor->phpVersionId, 100) + 1) * 100 - 1];
            $this->judged = new JudgedRange(
                [$floor, ...array_map(static fn (array $target): PcreTarget => $target[0]->target(), $this->range)],
                [[$lowest, $highest + 1]],
                $this->pinnedPcre,
                $this->regex,
            );
        }

        return $this->judged;
    }

    /**
     * The "@regex-target" markers of a file, read once per version of it.
     *
     * @return list<TargetMarker>
     */
    public function markersOf(string $file): array
    {
        $modified = @filemtime($file);
        $key = $file."\0".(false === $modified ? '' : (string) $modified);
        if (!\array_key_exists($key, $this->markers)) {
            $content = @file_get_contents($file);
            $this->markers = [$key => false === $content ? [] : TargetMarkers::read($content)] + \array_slice($this->markers, 0, 63, true);
        }

        return $this->markers[$key];
    }

    /**
     * The "php_version", "pcre_version" and "locale" options of the target:
     * PHPStan's PHP version with the PCRE2 it bundles by default, the
     * running PHP and the PCRE2 it links when that version is the running
     * one or the setting is "runtime", else the version the setting names;
     * the locale "locale" names, else the runtime's.
     *
     * A PHP below the first on PCRE2 is judged as regex lint judges it:
     * refused when "phpVersion" or PHPStan names it, judged from the lowest
     * PHP on PCRE2 of a range of PHPStan or of the composer.json PHPStan
     * read it from, refused when they allow none.
     *
     * @param ConfigArray                        $config
     * @param int|array{min: int, max: int}|null $phpVersionRange
     *
     * @throws InvalidRegexOptionException when "phpVersion" names no version, or "locale" a locale the machine
     *                                     cannot set
     * @throws UnsupportedTargetException  when the PHP to judge for ships PCRE1
     *
     * @return array{php_version?: string|int, pcre_version?: string, locale?: string}
     */
    private static function targetOptions(array $config, ?PhpVersion $phpVersion, int|array|null $phpVersionRange): array
    {
        $options = [];
        $locale = $config['locale'] ?? null;
        if (null !== $locale) {
            $options['locale'] = $locale;
        }

        $firstOnPcre2 = PcreTarget::phpVersionBoundaries()[0];
        $setting = $config['phpVersion'] ?? null;
        if (null === $setting) {
            if (null !== $phpVersion && $phpVersion->getVersionId() < $firstOnPcre2) {
                $target = \is_array($phpVersionRange)
                    ? ProjectTarget::fromRange($phpVersionRange['min'], $phpVersionRange['max'], 'phpVersion', ['phpRegex.locale' => $locale])
                    : ProjectTarget::belowPcre2($phpVersion->getVersionId(), 'phpVersion', PhpVersion::SOURCE_COMPOSER_PLATFORM_PHP === $phpVersion->getSource() ? (getcwd() ?: null) : null, getenv(), ['phpRegex.locale' => $locale]);
                $options['php_version'] = $target->target()->phpVersionId;
            } elseif (null !== $phpVersion && \PHP_VERSION_ID !== $phpVersion->getVersionId()) {
                $options['php_version'] = $phpVersion->getVersionId();
            }
        } elseif ('runtime' !== $setting) {
            $named = ParserOptions::fromArray(['php_version' => $setting])->target->phpVersionId;
            if ($named < $firstOnPcre2) {
                ProjectTarget::belowPcre2($named, 'phpRegex.phpVersion', null, []);
            }
            $options['php_version'] = $setting;
        }

        $pcreVersion = $config['pcreVersion'] ?? null;
        if (null !== $pcreVersion) {
            $options['pcre_version'] = $pcreVersion;
        }

        return $options;
    }

    /**
     * A parser and its label for each PHP where a rule of the library
     * changes, above the floor and up to the max of PHPStan's range; none
     * when "phpVersion" names the version patterns are judged for, or when
     * PHPStan names one version.
     *
     * @param ConfigArray                        $config
     * @param int|array{min: int, max: int}|null $phpVersionRange
     *
     * @return list<array{RegexParser, string}>
     */
    private static function rangeParsers(array $config, RegexParser $floorParser, int|array|null $phpVersionRange): array
    {
        $floor = $floorParser->target();
        $max = \is_array($phpVersionRange) ? $phpVersionRange['max'] : null;
        if (null !== ($config['phpVersion'] ?? null) || !\is_int($max)) {
            return [];
        }

        $pcreVersion = $config['pcreVersion'] ?? null;
        $parsers = [];
        foreach (PcreTarget::phpVersionBoundaries() as $phpVersionId) {
            if ($phpVersionId <= $floor->phpVersionId || $phpVersionId > $max) {
                continue;
            }

            $pcre = \is_string($pcreVersion) ? $pcreVersion : PcreTarget::bundledWith($phpVersionId)->pcreVersion;
            $target = new PcreTarget($phpVersionId, $pcre, $floor->locale);
            $parsers[] = [$floorParser->withTarget($target), self::label($target)];
        }

        return $parsers;
    }

    /**
     * The versions of PHPStan's range a call runs on, as a guard on
     * PHP_VERSION_ID and the "@regex-target" markers covering its line say
     * them, both at once: the lowest, judged with the PCRE2 it bundles or
     * the one "pcreVersion" names, its label (null for the target of the
     * extension, when the running engine stands for it), and the later PHPs
     * of the range above it. Null when nothing narrows the range; an empty
     * list when what narrows it keeps no version of it, the call never
     * running on the versions judged.
     *
     * @return array{RegexParser, string|null, list<array{RegexParser, string}>}|array{}|null
     */
    private function guardedTargets(Scope $scope, int $lineNumber): ?array
    {
        $constraint = TargetMarker::constraintAt($this->markersOf($scope->getFile()), $lineNumber);
        if (null !== $this->guardable) {
            [$lowest, $highest] = $this->guardable;
            $narrowed = self::admitted($this->versionType($scope), $lowest, $highest);
            if (null === $narrowed) {
                return [];
            }

            if ($narrowed !== [$lowest, $highest]) {
                $guard = TargetConstraint::phpFrom($narrowed[0], $narrowed[1] + 1);
                $constraint = $constraint?->intersect($guard) ?? $guard;
            }
        }

        if (null === $constraint) {
            return null;
        }

        $judged = $this->judged();
        $targets = $judged->narrow($constraint);
        if ([] === $targets) {
            return [];
        }

        $key = static fn (PcreTarget $target): string => $target->key();
        if (array_map($key, $targets) === array_map($key, $judged->points())) {
            return null;
        }

        $later = [];
        foreach (\array_slice($targets, 1) as $target) {
            $later[] = [$judged->parserFor($target), self::label($target)];
        }

        $floor = $targets[0];
        if ($key($floor) === $key($this->regex->target())) {
            return [$this->regex, $this->targetLabel, $later];
        }

        return [$judged->parserFor($floor), self::label($floor), $later];
    }

    /**
     * "PHP 8.4 with PCRE2 10.44", and the locale when it is not "C".
     */
    private static function label(PcreTarget $target): string
    {
        return \sprintf('PHP %s with PCRE2 %s%s', ProjectTarget::phpLabel($target->phpVersionId), $target->pcreVersion, 'C' === $target->locale ? '' : ' under the locale '.$target->locale);
    }

    /**
     * The type of PHP_VERSION_ID at the call: of "\PHP_VERSION_ID", and of
     * "PHP_VERSION_ID" too when it names the global constant, PHPStan
     * narrowing each as it is written. In a namespace, the unqualified
     * name is read only when no constant of the namespace takes it, neither
     * declared in the file nor known to PHPStan elsewhere: a constant of
     * the namespace is no PHP version.
     */
    private function versionType(Scope $scope): Type
    {
        $type = $scope->getType(new ConstFetch(new FullyQualified('PHP_VERSION_ID')));
        $unqualified = new Name('PHP_VERSION_ID');
        $namespace = $scope->getNamespace();
        if (null === $namespace
            || ($scope->hasExpressionType(new ConstFetch(new FullyQualified($namespace.'\\PHP_VERSION_ID')))->no()
                && 'PHP_VERSION_ID' === $this->reflectionProvider?->resolveConstantName($unqualified, $scope))) {
            $type = TypeCombinator::intersect($type, $scope->getType(new ConstFetch($unqualified)));
        }

        return $type;
    }

    /**
     * The lowest and the highest version of $lowest to $highest an integer
     * type admits, or null when it admits none. A type that is no integer
     * admits every version: it says nothing of them.
     *
     * @return array{int, int}|null
     */
    private static function admitted(Type $type, int $lowest, int $highest): ?array
    {
        if (self::isNever($type)) {
            return null;
        }

        // Any other type says nothing of the versions, and keeps them all.
        if (!$type->isInteger()->yes()) {
            return [$lowest, $highest]; // never taken: PHPStan types PHP_VERSION_ID as an integer
        }

        $admits = static fn (int $from, int $to): bool => !self::isNever(TypeCombinator::intersect($type, IntegerRangeType::fromInterval($from, $to)));
        if (!$admits($lowest, $highest)) {
            return null;
        }

        // The lowest version admitted, then the highest, each by halving.
        [$low, $high] = [$lowest, $highest];
        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            if ($admits($lowest, $middle)) {
                $high = $middle;
            } else {
                $low = $middle + 1;
            }
        }
        $from = $low;

        [$low, $high] = [$from, $highest];
        while ($low < $high) {
            $middle = intdiv($low + $high + 1, 2);
            if ($admits($middle, $highest)) {
                $low = $middle;
            } else {
                $high = $middle - 1;
            }
        }

        return [$from, $low];
    }

    /**
     * Whether a type admits no value: dead code, for a version.
     */
    private static function isNever(Type $type): bool
    {
        return (new NeverType())->isSuperTypeOf($type)->yes();
    }
}
