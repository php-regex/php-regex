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

namespace PHPRegex\Mcp;

use PHPRegex\Linter\Config\ProjectTarget;
use PHPRegex\Linter\Config\TargetSpec;
use PHPRegex\Linter\Exception\UnsupportedTargetException;
use PHPRegex\Mcp\Exception\TargetException;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\ParserOptions;
use PHPRegex\Toolkit\Regex;

/**
 * The facade a call runs on: judged for the PHP and PCRE2 the call names,
 * else for the server's target, else for the running PHP. The PHP is a
 * single version or a Composer constraint, judged as lint judges a range:
 * the facade for its floor, the target for every version of the range a
 * rule of the library changes at. No file of the server's working
 * directory is read: neither regex.json nor composer.json names a target
 * here. A PHP that ships PCRE1 is refused as the CLI refuses it: the
 * library judges PCRE2 only.
 *
 * @internal
 *
 * @phpstan-type Target array{php_version?: string, pcre_version?: string, locale?: string}
 */
final class RegexFactory
{
    /**
     * @var array<string, array{Regex, ProjectTarget}> one facade per target, with the target it judges for the floor of, kept for the session
     */
    private array $instances = [];

    /**
     * @param Target $target the server's target, the default of every call
     */
    public function __construct(private readonly array $target = []) {}

    /**
     * The facade judging for the floor of the call's target.
     *
     * @param array<string, mixed> $arguments the call's arguments; php_version and pcre_version, when given, win over the server's
     *
     * @throws TargetException when the target cannot be judged for
     */
    public function for(array $arguments): Regex
    {
        $sources = ['php_version' => [], 'pcre_version' => [], 'locale' => ['--locale' => $this->target['locale'] ?? null]];
        foreach (['php_version' => '--php-version', 'pcre_version' => '--pcre-version'] as $key => $flag) {
            foreach (['"'.$key.'"' => $arguments[$key] ?? null, $flag => $this->target[$key] ?? null] as $source => $value) {
                if (null === $value) {
                    continue;
                }
                if (!\is_string($value) && !\is_int($value)) {
                    throw new TargetException(\sprintf('Invalid option: "%s" must be a string, %s given.', $key, get_debug_type($value)), 'usage');
                }
                $sources[$key][$source] = (string) $value;
            }
        }

        $key = (string) json_encode($sources);

        return ($this->instances[$key] ??= $this->create($sources))[0];
    }

    /**
     * The target a facade of this factory judges for: its floor, and every
     * version of its range.
     *
     * @throws TargetException when the facade was not built here, a bug of the caller
     */
    public function targetOf(Regex $regex): ProjectTarget
    {
        foreach ($this->instances as [$facade, $target]) {
            if ($facade === $regex) {
                return $target;
            }
        }

        throw new TargetException('The facade was not built for a call: this is a bug; please report it.', 'internal');
    }

    /**
     * The facade for the floor of the target the sources name, and the
     * target, the first set winning, read as lint reads a target: a
     * constraint is a range. No file is read: the null project directory
     * reads no composer.json.
     *
     * @param array{php_version: array<string, string>, pcre_version: array<string, string>, locale: array<string, string|null>} $sources source name => value
     *
     * @throws TargetException when the target cannot be judged for
     *
     * @return array{Regex, ProjectTarget}
     */
    private function create(array $sources): array
    {
        try {
            $target = ProjectTarget::fromSources($sources['php_version'], $sources['pcre_version'], null, [], $sources['locale']);
        } catch (InvalidRegexOptionException $e) {
            // A version no reader takes: the message names every form the
            // argument takes, a constraint included.
            $message = ParserOptions::PHP_VERSION_EXPECTED === $e->getMessage() ? TargetSpec::RANGE_EXPECTED : $e->getMessage();

            throw new TargetException('Invalid option: '.$message, 'usage', $e);
        } catch (UnsupportedTargetException $e) {
            throw new TargetException($e->getMessage(), 'target', $e);
        }

        return [Regex::create($target->regexOptions()), $target];
    }
}
