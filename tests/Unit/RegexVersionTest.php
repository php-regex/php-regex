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

namespace PhpRegex\Tests\Unit;

use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The version the library reports, the id derived from it and the branch
 * Composer aliases the development line to move together.
 */
final class RegexVersionTest extends TestCase
{
    #[Test]
    public function test_the_version_id_is_read_from_the_version(): void
    {
        $this->assertSame(1, preg_match('/^(\d++)\.(\d++)\.(\d++)(?:-[A-Z]++\d*+)?$/', Regex::VERSION, $parts));
        $this->assertSame(Regex::VERSION_ID, (int) $parts[1] * 10000 + (int) $parts[2] * 100 + (int) $parts[3]);
    }

    #[Test]
    public function test_the_development_branch_is_aliased_to_the_major_version(): void
    {
        /** @var array{extra: array{'branch-alias': array<string, string>}} $composer */
        $composer = json_decode((string) file_get_contents(__DIR__.'/../../composer.json'), true, 512, \JSON_THROW_ON_ERROR);
        $major = explode('.', Regex::VERSION)[0];

        $this->assertSame([\sprintf('dev-%s.x', $major) => \sprintf('%s.x-dev', $major)], $composer['extra']['branch-alias']);
        $this->assertStringStartsWith('2.', Regex::VERSION);
    }
}
