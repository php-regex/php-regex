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

use PhpParser\Node;
use PHPRegex\Linter\Exception\UnsupportedTargetException;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Php\PhpVersion;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;

/**
 * Reports, once per file, each "@regex-target" marker whose constraint
 * cannot be read (regex.target.invalid) and each that keeps no version of
 * PHPStan's range (regex.target.empty), on the marker's line. The calls a
 * marker covers are judged by RegexPatternRule and RegexPatternArgumentRule.
 *
 * @internal registered by extension.neon
 *
 * @phpstan-import-type ConfigArray from RegexPatternRule
 *
 * @implements Rule<FileNode>
 */
final class RegexTargetMarkerRule implements Rule
{
    private readonly PatternChecker $checker;

    /**
     * @param ConfigArray                        $config          the "phpRegex" parameter, as RegexPatternRule reads it
     * @param PhpVersion|null                    $phpVersion      the PHP version PHPStan analyses the project for
     * @param int|array{min: int, max: int}|null $phpVersionRange PHPStan's "phpVersion" parameter
     *
     * @throws InvalidRegexOptionException when "phpVersion", "pcreVersion" or "checks.redos.threshold" cannot be read
     * @throws UnsupportedTargetException  when the PHP to judge for ships PCRE1, as regex lint refuses it
     */
    public function __construct(array $config = [], ?PhpVersion $phpVersion = null, int|array|null $phpVersionRange = null)
    {
        $this->checker = new PatternChecker($config, $phpVersion, $phpVersionRange);
    }

    public function getNodeType(): string
    {
        return FileNode::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        return $this->checker->markerErrors($scope->getFile());
    }
}
