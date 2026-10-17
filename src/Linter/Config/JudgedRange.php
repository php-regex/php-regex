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

namespace PHPRegex\Linter\Config;

use PHPRegex\Parser\ParserOptions;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\RegexParser;

/**
 * The versions a project's patterns are judged at, the floor first, and
 * the PHP versions the project installs on: what a guard on PHP_VERSION_ID
 * or a "@regex-target" marker narrows.
 *
 * @internal
 *
 * @phpstan-import-type OptionsArray from ParserOptions
 */
final class JudgedRange
{
    /**
     * The parser of each version judged, by PHP_VERSION_ID and PCRE2.
     *
     * @var array<string, RegexParser>
     */
    private array $parsers = [];

    /**
     * @param list<PcreTarget>           $points       the versions judged, the floor first
     * @param list<array{int, int|null}> $admitted     the PHP versions the project installs on
     * @param string|null                $pinnedPcre   the PCRE2 release named for every version
     * @param RegexParser                $floor        the parser of the floor
     * @param OptionsArray               $regexOptions what the floor's parser was created with, its target aside
     */
    public function __construct(
        private readonly array $points,
        private readonly array $admitted,
        private readonly ?string $pinnedPcre,
        private readonly RegexParser $floor,
        private readonly array $regexOptions = [],
    ) {}

    /**
     * The versions judged under a constraint: the lowest PHP version of
     * each stretch the project installs on and the constraint admits, the
     * version judged there being the point of the range when there is one,
     * else that PHP with the PCRE2 it bundles or the one named, and each
     * point of the range it admits, in order. A constraint naming PCRE2
     * releases admits a version by the release judged there. Empty when the
     * constraint keeps no version.
     *
     * @return list<PcreTarget>
     */
    public function narrow(TargetConstraint $constraint): array
    {
        // The candidates for the lowest version: where each admitted
        // stretch starts, and every point of the range, in order.
        $candidates = [];
        foreach ($constraint->phpWithin($this->admitted) as [$from]) {
            $candidates[$from] = $this->pointAt($from);
        }
        // Every point of the range is a version the project installs on.
        foreach ($this->points as $point) {
            if ($constraint->admitsPhp($point->phpVersionId)) {
                $candidates[$point->phpVersionId] = $point;
            }
        }
        ksort($candidates);

        // The start of a stretch above the lowest stands for the versions
        // up to the next point, as the lowest version of an OR branch does
        // in a require.php range.
        return array_values(array_filter($candidates, $constraint->admits(...)));
    }

    /**
     * The parser judging for a version: the floor's for the floor, else
     * one with its options and cache.
     */
    public function parserFor(PcreTarget $target): RegexParser
    {
        $floor = $this->floor->target();
        if ($floor->phpVersionId === $target->phpVersionId && $floor->pcreVersion === $target->pcreVersion) {
            return $this->floor;
        }

        // Compiling with the running PHP judges one version: the floor's.
        return $this->parsers[$target->phpVersionId.'/'.$target->pcreVersion] ??= RegexParser::fromOptions(ParserOptions::fromArray([
            'php_version' => $target->phpVersionId,
            'pcre_version' => $target->pcreVersion,
            'cache' => $this->floor->getCache(),
            'runtime_pcre_validation' => false,
        ] + $this->regexOptions));
    }

    /**
     * The PHP versions the project installs on, as a Composer constraint.
     */
    public function describe(): string
    {
        return TargetConstraint::describe($this->admitted);
    }

    /**
     * The versions judged when nothing narrows them, the floor first.
     *
     * @return list<PcreTarget>
     */
    public function points(): array
    {
        return $this->points;
    }

    private function pointAt(int $phpVersionId): PcreTarget
    {
        foreach ($this->points as $point) {
            if ($point->phpVersionId === $phpVersionId) {
                return $point;
            }
        }

        return new PcreTarget($phpVersionId, $this->pinnedPcre ?? PcreTarget::bundledWith($phpVersionId)->pcreVersion);
    }
}
