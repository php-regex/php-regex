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

namespace PHPRegex\Tests\Integration\Bridge\Psalm;

use Psalm\Codebase;
use Psalm\Config;
use Psalm\Internal\Analyzer\ProjectAnalyzer;
use Psalm\Internal\Provider\FileProvider;
use Psalm\Internal\Provider\Providers;
use Psalm\Internal\Type\Comparator\UnionTypeComparator;
use Psalm\Type;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Union;

/**
 * Psalm types without a Psalm run: the exact type of a PHP value, a type read
 * from its Psalm notation, and containment as Psalm judges it.
 *
 * Psalm reads and compares types through the project it analyses, a static
 * instance: each call makes this helper's project the current one, so a run
 * of another test in between changes nothing.
 */
final class PsalmTypes
{
    private static ?ProjectAnalyzer $project = null;

    public static function parse(string $type): Union
    {
        self::codebase();

        return Type::parseString($type);
    }

    /**
     * Whether every value of $type is a value of $container.
     */
    public static function isContainedBy(Union $type, Union $container): bool
    {
        return UnionTypeComparator::isContainedBy(self::codebase(), $type, $container);
    }

    /**
     * The exact type of a value preg_match() or preg_match_all() writes:
     * literal strings and ints, null, and sealed array shapes, a list where
     * the keys are 0 to n - 1 in order.
     */
    public static function ofValue(mixed $value): Union
    {
        self::codebase();

        return match (true) {
            null === $value => Type::getNull(),
            \is_string($value) => Type::getString($value),
            \is_int($value) => Type::getInt(false, $value),
            \is_array($value) => [] === $value
                ? Type::getEmptyArray()
                : new Union([new TKeyedArray(array_map(self::ofValue(...), $value), null, null, array_is_list($value))]),
            default => throw new \LogicException(\sprintf('No Psalm type for a %s here.', get_debug_type($value))),
        };
    }

    public static function codebase(): Codebase
    {
        if (null === self::$project) {
            // Psalm reads the global $argv as paths to analyse: PHPUnit's are not.
            $argv = $GLOBALS['argv'] ?? null;
            $GLOBALS['argv'] = [];

            try {
                $config = Config::loadFromXML(PsalmRun::FIXTURES, '<?xml version="1.0"?><psalm><projectFiles><directory name="."/></projectFiles></psalm>', PsalmRun::FIXTURES);
            } finally {
                $GLOBALS['argv'] = $argv;
            }
            $config->cache_directory = null;
            self::$project = new ProjectAnalyzer($config, new Providers(new FileProvider()));
        }

        // Another Psalm run may have taken the static instances since: its
        // configuration (a smaller maxShapedArraySize) would change how types
        // combine.
        (new \ReflectionProperty(ProjectAnalyzer::class, 'instance'))->setValue(null, self::$project);
        (new \ReflectionProperty(Config::class, 'instance'))->setValue(null, self::$project->getConfig());

        return self::$project->getCodebase();
    }
}
